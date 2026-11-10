<?php
/**
 * Endpoint AJAX para gestión de cotizaciones
 * Acciones: enviar_solicitud, guardar_datos_cotizacion, generar_ordenes, confirmar_recepcion
 */
require_once 'config.php';
require_once 'mail_config.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$db = getDB();
$action = $_POST['action'] ?? '';

/**
 * Valida los ítems que manda el navegador en "seleccion" contra lo realmente
 * guardado en cotizaciones.datos_ia (lo que Compras capturó al recibir la
 * cotización). El precio SIEMPRE sale de aquí, nunca de lo que mande el
 * request de generar_ordenes -- ese request es una llamada aparte de cuando
 * se guardó datos_ia, así que nada obliga a que digan lo mismo si alguien
 * modifica el body. La cantidad puede ser igual o menor a lo cotizado
 * (selección parcial, uso normal), nunca mayor. Ítems que no correspondan a
 * nada realmente cotizado se descartan en vez de aceptarse tal cual.
 */
function validarItemsContraCotizacion(array $itemsSel, ?string $datosIaJson): array {
    $reales = json_decode($datosIaJson ?? '', true)['productos'] ?? [];
    if (!$reales) return [];

    $porMaterial = [];
    $porDescripcion = [];
    foreach ($reales as $r) {
        $matId = intval($r['material_id'] ?? 0) ?: null;
        if ($matId) $porMaterial[$matId] = $r;
        $key = mb_strtolower(trim($r['descripcion'] ?? '')) . '|' . mb_strtolower(trim($r['unidad'] ?? ''));
        $porDescripcion[$key] = $r;
    }

    $validados = [];
    foreach ($itemsSel as $it) {
        $matId = intval($it['material_id'] ?? 0) ?: null;
        $real = ($matId && isset($porMaterial[$matId])) ? $porMaterial[$matId] : null;
        if (!$real) {
            $key = mb_strtolower(trim($it['descripcion'] ?? '')) . '|' . mb_strtolower(trim($it['unidad'] ?? ''));
            $real = $porDescripcion[$key] ?? null;
        }
        if (!$real) continue;

        $cantidadReal   = floatval($real['cantidad'] ?? 0);
        $precioReal     = floatval($real['precio_unitario'] ?? 0);
        $cantidadPedida = floatval($it['cantidad'] ?? 0);
        if ($cantidadReal <= 0 || $precioReal <= 0 || $cantidadPedida <= 0) continue;

        $validados[] = [
            'descripcion' => $real['descripcion'] ?? ($it['descripcion'] ?? ''),
            'unidad'      => $real['unidad'] ?? ($it['unidad'] ?? 'pza'),
            'material_id' => $matId,
            'cantidad'    => min($cantidadPedida, $cantidadReal),
            'precio_unit' => $precioReal,
        ];
    }
    return $validados;
}

switch ($action) {

    // ── 1. ENVIAR SOLICITUD DE COTIZACIÓN A PROVEEDORES ─────────────────────────
    case 'enviar_solicitud':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $proyecto_id    = intval($_POST['proyecto_id'] ?? 0);
        $proveedor_ids  = $_POST['proveedor_ids'] ?? [];
        $mensaje        = sanitize($_POST['mensaje'] ?? '');
        $requisicion_id = intval($_POST['requisicion_id'] ?? 0);

        if (!$proyecto_id || empty($proveedor_ids)) {
            echo json_encode(['success' => false, 'error' => 'Selecciona al menos un proveedor']);
            exit;
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM proyectos WHERE id = ?");
            $stmt->execute([$proyecto_id]);
            $proyecto = $stmt->fetch();
            if (!$proyecto) throw new Exception("Proyecto no encontrado");

            // Items del proyecto — intentar las mismas fuentes que usa la vista
            // 1. requisicion_detalle
            $stmt_items = $db->prepare("
                SELECT rd.descripcion as item, rd.descripcion, rd.cantidad as cantidad_requerida,
                       m.marca, m.modelo
                FROM requisicion_detalle rd
                JOIN requisiciones r ON rd.requisicion_id = r.id
                LEFT JOIN materiales m ON rd.material_id = m.id
                WHERE r.proyecto_id = ?
            ");
            $stmt_items->execute([$proyecto_id]);
            $items_proyecto = $stmt_items->fetchAll();

            // 2. almacen_items
            if (empty($items_proyecto)) {
                $stmt_items = $db->prepare("
                    SELECT ai.item, ai.descripcion, ai.cantidad_requerida, ai.marca, ai.modelo
                    FROM almacen_items ai
                    WHERE ai.proyecto_id = ?
                ");
                $stmt_items->execute([$proyecto_id]);
                $items_proyecto = $stmt_items->fetchAll();
            }

            // 3. boom_items — aggregate across all BOMs, applying num_equipos multiplier
            if (empty($items_proyecto)) {
                $stmt_items = $db->prepare("
                    SELECT bi.item, bi.descripcion, bi.marca, bi.modelo,
                           SUM(bi.cantidad * bl.num_equipos) AS cantidad_requerida
                    FROM boom_items bi
                    JOIN boom_listas bl ON bi.boom_id = bl.id
                    WHERE bl.proyecto_id = ? AND bl.guardado = 1 AND bi.guardado = 1
                    GROUP BY bi.material_id, bi.item, bi.descripcion, bi.marca, bi.modelo
                    ORDER BY bi.item
                ");
                $stmt_items->execute([$proyecto_id]);
                $items_proyecto = $stmt_items->fetchAll();
            }

            $emails_ok = [];
            $emails_fail = [];
            $sin_email = [];

            foreach ($proveedor_ids as $prov_id) {
                $prov_id = intval($prov_id);
                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ? AND activo = 1");
                $stmt->execute([$prov_id]);
                $prov = $stmt->fetch();
                if (!$prov) continue;

                // Evitar duplicados — si ya existe, reusar el registro en lugar de crear uno nuevo
                $stmt = $db->prepare("SELECT id FROM cotizaciones WHERE proyecto_id = ? AND proveedor_id = ? AND estado = 'pendiente'");
                $stmt->execute([$proyecto_id, $prov_id]);
                $existe = $stmt->fetch();
                if (!$existe) {
                    // Crear registro solo si no existe
                    $stmt = $db->prepare("INSERT INTO cotizaciones (proyecto_id, requisicion_id, proveedor_id, email_enviado_a, notas) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$proyecto_id, $requisicion_id ?: null, $prov_id, $prov['email'], $mensaje]);
                }

                // Intentar enviar email
                if (!empty($prov['email'])) {
                    $nombre_emisor = $_SESSION['user_name'] ?? 'Departamento de Compras';
                    $email_data = generarDatosEmail($prov, $proyecto, $items_proyecto, $mensaje, $nombre_emisor);
                    $enviado = enviarEmailSolicitud($email_data);
                    if ($enviado) {
                        $emails_ok[] = $prov['nombre'] . ' <' . $prov['email'] . '>';
                    } else {
                        $emails_fail[] = [
                            'nombre'   => $prov['nombre'],
                            'email'    => $prov['email'],
                            'asunto'   => $email_data['subject_plain'],
                            'cuerpo'   => $email_data['text'],
                        ];
                    }
                } else {
                    $sin_email[] = $prov['nombre'];
                }
            }

            // Actualizar estado del proyecto
            $db->prepare("UPDATE proyectos SET estado = 'cotizacion_enviada', fecha_estado = NOW() WHERE id = ?")->execute([$proyecto_id]);

            $db->commit();

            // Notificación
            $stmt_pn = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
            $stmt_pn->execute([$proyecto_id]);
            $proy_nombre_notif = $stmt_pn->fetchColumn() ?: "ID $proyecto_id";
            $n_provs = count($emails_ok) + count($emails_fail);
            notificarRoles(
                ['ingenieria'],
                'cotizacion_enviada',
                "Cotización solicitada: $proy_nombre_notif",
                "Se solicitó cotización a $n_provs proveedor(es) para \"$proy_nombre_notif\".",
                'compras.php',
                $_SESSION['user_id'] ?? 0
            );

            echo json_encode([
                'success'      => true,
                'emails_ok'    => $emails_ok,
                'emails_fail'  => $emails_fail,
                'sin_email'    => $sin_email,
                'message'      => 'Solicitudes registradas. El proyecto está en espera de cotizaciones.'
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 1b. SALTAR FASE — Registrar proveedores sin enviar email ─────────────────
    case 'saltar_fase':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $proyecto_id    = intval($_POST['proyecto_id'] ?? 0);
        $requisicion_id = intval($_POST['requisicion_id'] ?? 0);
        $proveedor_ids  = $_POST['proveedor_ids'] ?? [];

        if (!$proyecto_id || empty($proveedor_ids)) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }
        try {
            $db->beginTransaction();
            foreach ($proveedor_ids as $prov_id) {
                $prov_id = intval($prov_id);
                // Evitar duplicados
                $existe = $db->prepare("SELECT id FROM cotizaciones WHERE proyecto_id = ? AND proveedor_id = ? AND estado = 'pendiente'");
                $existe->execute([$proyecto_id, $prov_id]);
                if (!$existe->fetch()) {
                    $stmt = $db->prepare("INSERT INTO cotizaciones (proyecto_id, requisicion_id, proveedor_id, notas) VALUES (?, ?, ?, 'Registrado manualmente')");
                    $stmt->execute([$proyecto_id, $requisicion_id ?: null, $prov_id]);
                }
            }
            $db->prepare("UPDATE proyectos SET estado = 'cotizacion_enviada', fecha_estado = NOW() WHERE id = ?")->execute([$proyecto_id]);
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 2. GUARDAR DATOS AI EN COTIZACIÓN (llamado desde analizar_cotizacion.php) ─
    case 'guardar_datos_cotizacion':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $cotizacion_id = intval($_POST['cotizacion_id'] ?? 0);
        $datos_ia      = $_POST['datos_ia'] ?? '';
        $archivo_ruta  = sanitize($_POST['archivo_ruta'] ?? '');

        if (!$cotizacion_id) {
            echo json_encode(['success' => false, 'error' => 'cotizacion_id requerido']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE cotizaciones SET datos_ia = ?, archivo_ruta = ?, estado = 'recibida', fecha_respuesta = NOW() WHERE id = ?");
            $stmt->execute([$datos_ia, $archivo_ruta, $cotizacion_id]);

            // Obtener proyecto/requisición para actualizar estado
            $stmt = $db->prepare("SELECT proyecto_id, requisicion_id FROM cotizaciones WHERE id = ?");
            $stmt->execute([$cotizacion_id]);
            $row = $stmt->fetch();

            if ($row) {
                if ($row['proyecto_id']) {
                    $db->prepare("UPDATE proyectos SET estado = 'cotizacion_recibida' WHERE id = ? AND estado = 'cotizacion_enviada'")->execute([$row['proyecto_id']]);
                }
                if ($row['requisicion_id']) {
                    $db->prepare("UPDATE requisiciones SET estado_compra = 'cotizacion_recibida' WHERE id = ?")->execute([$row['requisicion_id']]);
                    registrarEventoRequisicion($db, (int) $row['requisicion_id'], 'cotizacion_recibida');
                }
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 3. GENERAR ÓRDENES DE COMPRA DESDE COTIZACIONES ────────────────────────
    case 'generar_ordenes':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $proyecto_id      = intval($_POST['proyecto_id'] ?? 0);
        $requisicion_id   = intval($_POST['requisicion_id'] ?? 0);
        $fecha_entrega    = sanitize($_POST['fecha_entrega'] ?? '');
        $condiciones_pago = sanitize($_POST['condiciones_pago'] ?? 'Crédito');
        $notas_oc         = sanitize($_POST['notas'] ?? '');

        $raw_sel   = $_POST['seleccion'] ?? '';
        $seleccion = json_decode($raw_sel, true);
        if ($raw_sel !== '' && json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'seleccion JSON inválido: ' . json_last_error_msg()]);
            exit;
        }
        $seleccion = $seleccion ?: [];

        if (!$proyecto_id || empty($seleccion)) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }

        if (!$requisicion_id) {
            $stmt_rq = $db->prepare("SELECT r.id FROM requisiciones r JOIN proyectos p ON p.id = ? WHERE r.proyecto_id = p.id OR r.proyecto = p.nombre ORDER BY r.fecha_creacion DESC LIMIT 1");
            $stmt_rq->execute([$proyecto_id]);
            $rq_row = $stmt_rq->fetch();
            if ($rq_row) $requisicion_id = $rq_row['id'];
        }

        try {
            $db->beginTransaction();
            $ordenes_creadas = [];

            foreach ($seleccion as $cot_id => $items_sel) {
                $cot_id = intval($cot_id);
                if (empty($items_sel)) continue;

                $stmt = $db->prepare("SELECT c.*, p.nombre as proveedor_nombre FROM cotizaciones c JOIN proveedores p ON c.proveedor_id = p.id WHERE c.id = ? AND c.proyecto_id = ?");
                $stmt->execute([$cot_id, $proyecto_id]);
                $cot = $stmt->fetch();
                if (!$cot || $cot['estado'] !== 'recibida') continue;

                $items_sel = validarItemsContraCotizacion($items_sel, $cot['datos_ia']);
                if (empty($items_sel)) continue;

                $subtotal = 0;
                foreach ($items_sel as $it) {
                    $subtotal += floatval($it['cantidad']) * floatval($it['precio_unit']);
                }
                $subtotal = round($subtotal, 2);
                $iva      = round($subtotal * 0.16, 2);
                $total    = round($subtotal + $iva, 2);

                $folio = generarFolioOC($proyecto_id ?? null);
                $stmt  = $db->prepare("INSERT INTO ordenes_compra (folio, requisicion_id, proyecto_id, proveedor_id, usuario_id, subtotal, iva, total, notas, condiciones_pago, moneda, tipo_cambio, fecha_entrega_estimada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MXN', 1, ?)");
                $stmt->execute([
                    $folio,
                    $requisicion_id ?: null,
                    $proyecto_id,
                    $cot['proveedor_id'],
                    $_SESSION['user_id'],
                    $subtotal, $iva, $total,
                    $notas_oc ?: null,
                    $condiciones_pago,
                    $fecha_entrega ?: null
                ]);
                $orden_id = $db->lastInsertId();

                $item_num = 1;
                foreach ($items_sel as $it) {
                    $desc     = sanitize($it['descripcion'] ?? '');
                    $qty      = floatval($it['cantidad'] ?? 0);
                    $unidad   = sanitize($it['unidad'] ?? 'pza');
                    $precio   = floatval($it['precio_unit'] ?? 0);
                    $sub_item = round($qty * $precio, 2);
                    // validarItemsContraCotizacion() ya devuelve material_id -- faltaba guardarlo
                    // aquí (su gemelo generar_ordenes_req sí lo hace). Sin esto, la recepción de
                    // la OC (confirmar_recepcion) nunca podía llamar a registrarMovimientoAlmacen()
                    // porque items_detalle siempre traía material_id null, aunque el JS ya lo mandaba
                    // bien -- materiales.stock_disponible nunca subía de verdad para OCs de proyecto.
                    $mat_id   = intval($it['material_id'] ?? 0) ?: null;
                    if (!$desc || $qty <= 0) continue;

                    $stmt = $db->prepare("INSERT INTO orden_compra_detalle (orden_id, item_numero, material_id, cantidad, unidad_medida, descripcion, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$orden_id, $item_num++, $mat_id, $qty, $unidad, $desc, $precio, $sub_item]);
                    sincronizarPrecioMaterial($db, $mat_id, $precio);
                }

                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'ordenes_compra', ?, ?)")
                   ->execute([$_SESSION['user_id'], $orden_id, "OC $folio generada desde cotización ID $cot_id (selección parcial)"]);

                $ordenes_creadas[] = ['folio' => $folio, 'id' => $orden_id, 'proveedor' => $cot['proveedor_nombre']];
            }

            if (empty($ordenes_creadas)) {
                throw new Exception("No se pudo generar ninguna orden. Verifica que los items seleccionados tengan precio y cantidad válidos.");
            }

            $db->prepare("UPDATE proyectos SET estado = 'orden_generada', fecha_estado = NOW() WHERE id = ?")->execute([$proyecto_id]);

            // Marcar almacen_items como fincados para este proyecto
            if (!empty($ordenes_creadas)) {
                $oc_id_principal = $ordenes_creadas[0]['id'];
                try {
                    $db->prepare("UPDATE almacen_items SET fincado = 1, fincado_oc_id = ? WHERE proyecto_id = ? AND diferencia < 0")
                       ->execute([$oc_id_principal, $proyecto_id]);
                } catch (Exception $ef) { /* columna puede no existir en instalaciones antiguas */ }
            }

            $db->commit();

            $stmt_pn = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
            $stmt_pn->execute([$proyecto_id]);
            $proy_nombre_notif = $stmt_pn->fetchColumn() ?: "ID $proyecto_id";
            $n_oc = count($ordenes_creadas);
            notificarRoles(
                ['ingenieria', 'almacen'],
                'orden_generada',
                "Órdenes generadas: $proy_nombre_notif",
                "Se generaron $n_oc orden(es) de compra para \"$proy_nombre_notif\".",
                'compras.php',
                $_SESSION['user_id'] ?? 0
            );
            // api/aprobar_oc.php exige revision_proyectos_ok=1 antes de que Gerencia pueda
            // aprobar la OC (candado real, ver CandadoProyectosOcTest) -- pero nunca se avisaba
            // a Proyectos que había una OC nueva esperando esa confirmación de fecha.
            notificarRoles(
                ['proyectos'],
                'oc_pendiente_confirmar',
                "OC pendiente de confirmar fecha: $proy_nombre_notif",
                "Se generaron $n_oc orden(es) de compra para \"$proy_nombre_notif\". Confirma la fecha de entrega para que Gerencia pueda aprobarlas.",
                'proyecto.php?seccion=proyectos',
                $_SESSION['user_id'] ?? 0
            );

            echo json_encode(['success' => true, 'ordenes' => $ordenes_creadas]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 4. CONFIRMAR RECEPCIÓN (desde almacén) ──────────────────────────────────
    case 'confirmar_recepcion':
        if (!hasAcceso('almacen')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
        $completo    = intval($_POST['completo'] ?? 0); // 1=todo recibido, 0=parcial

        if (!$proyecto_id) {
            echo json_encode(['success' => false, 'error' => 'proyecto_id requerido']);
            exit;
        }

        try {
            $stmt_pn = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
            $stmt_pn->execute([$proyecto_id]);
            $proy_nombre_recep = $stmt_pn->fetchColumn() ?: "ID $proyecto_id";

            if ($completo) {
                $db->prepare("UPDATE proyectos SET estado = 'realizado', realizada = 1, fecha_realizada = NOW() WHERE id = ?")->execute([$proyecto_id]);
                $db->prepare("UPDATE ordenes_compra SET estado = 'recibida' WHERE requisicion_id IN (SELECT id FROM requisiciones WHERE proyecto = (SELECT nombre FROM proyectos WHERE id = ?))")->execute([$proyecto_id]);
                // Marcar items como recibidos y actualizar cantidad_asignada = cantidad_requerida
                try {
                    $db->prepare("UPDATE almacen_items SET recibido_almacen = 1, cantidad_asignada = cantidad_requerida, diferencia = 0 WHERE proyecto_id = ?")
                       ->execute([$proyecto_id]);
                } catch (Exception $er) { /* columna puede no existir */ }
                // También marcar OCs por proyecto_id directo (no solo via requisicion)
                try {
                    $db->prepare("UPDATE ordenes_compra SET estado = 'recibida' WHERE proyecto_id = ? AND estado != 'recibida'")
                       ->execute([$proyecto_id]);
                } catch (Exception $er) {}
                $msg = 'Proyecto marcado como completado';
                notificarRoles(
                    ['almacen', 'inventario', 'ingenieria'],
                    'mercancia_recibida',
                    "Mercancía recibida: $proy_nombre_recep",
                    "Todo el pedido del proyecto \"$proy_nombre_recep\" fue recibido. El proyecto está completado.",
                    'almacen2.php?seccion=recepcion_pedidos'
                );
                // Calidad revisa cada material por separado, no la OC completa (2026-09-17,
                // ver cnt_oc en calidad.php -- cuenta contra almacen_items ahora).
                notificarRoles(
                    ['calidad'],
                    'oc_recibida_calidad',
                    "Materiales recibidos pendientes de revisar: $proy_nombre_recep",
                    "Se recibió el pedido completo del proyecto \"$proy_nombre_recep\". Los materiales quedaron pendientes de revisión de Calidad.",
                    'calidad.php?seccion=aprobacion_materiales'
                );
                // Notificar a manufactura: los materiales del proyecto ya están disponibles
                $planos_stmt = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND tipo_lista = 'plano'");
                $planos_stmt->execute([$proyecto_id]);
                $tiene_planos = $planos_stmt->fetchColumn() > 0;
                if ($tiene_planos) {
                    notificarRoles(
                        ['manufactura'],
                        'materiales_disponibles',
                        "Materiales listos: $proy_nombre_recep",
                        "Se recibió el pedido completo del proyecto \"$proy_nombre_recep\". Los materiales para los planos de manufactura ya están disponibles en almacén.",
                        'produccion.php?seccion=subproyectos'
                    );
                }
            } else {
                $db->prepare("UPDATE proyectos SET estado = 'recepcion_parcial' WHERE id = ?")->execute([$proyecto_id]);
                $msg = 'Recepción parcial registrada';
                notificarRoles(
                    ['almacen', 'inventario', 'compras'],
                    'mercancia_recibida',
                    "Recepción parcial: $proy_nombre_recep",
                    "Llegó parte del pedido del proyecto \"$proy_nombre_recep\". Queda mercancía pendiente de recibir.",
                    'almacen2.php?seccion=recepcion_pedidos'
                );
                // Notificar a manufactura sobre recepción parcial
                $planos_stmt2 = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND tipo_lista = 'plano'");
                $planos_stmt2->execute([$proyecto_id]);
                $tiene_planos2 = $planos_stmt2->fetchColumn() > 0;
                if ($tiene_planos2) {
                    notificarRoles(
                        ['manufactura'],
                        'mercancia_recibida',
                        "Recepción parcial: $proy_nombre_recep",
                        "Llegó parte del pedido del proyecto \"$proy_nombre_recep\". Algunos materiales ya están disponibles, pero queda mercancía pendiente.",
                        'produccion.php?seccion=subproyectos'
                    );
                }
            }

            $items_detalle_raw = $_POST['items_detalle'] ?? '';
            $notas_recepcion = sanitize($_POST['notas_recepcion'] ?? '');
            $incidencias_raw = $_POST['incidencias'] ?? '[]';
            $incidencias_tipos = json_decode($incidencias_raw, true) ?? [];
            $detalle_audit = "Recepción " . ($completo ? "completa" : "parcial") . " por almacén.";
            if ($notas_recepcion) $detalle_audit .= " Notas: $notas_recepcion";
            if ($items_detalle_raw) {
                $items_arr = json_decode($items_detalle_raw, true) ?? [];
                $resumen = [];
                $materiales_prioritarios = [];
                foreach ($items_arr as $it) {
                    $resumen[] = "{$it['descripcion']}: {$it['cantidad_recibida']}/{$it['cantidad_ordenada']} {$it['unidad']} ({$it['estado']})";
                    // Actualizar cantidad_asignada en almacen_items para reflejar lo recibido
                    $cant_rec = intval($it['cantidad_recibida'] ?? 0);
                    if ($cant_rec > 0 && !empty($it['material_id'])) {
                        try {
                            $db->prepare("UPDATE almacen_items SET cantidad_asignada = LEAST(cantidad_requerida, cantidad_asignada + ?), diferencia = cantidad_requerida - LEAST(cantidad_requerida, cantidad_asignada + ?) WHERE proyecto_id = ? AND material_id = ?")
                               ->execute([$cant_rec, $cant_rec, $proyecto_id, intval($it['material_id'])]);
                        } catch(Exception $eu) {}
                        // Número de serie -- campo simple editable por renglón, capturado al recibir
                        // (2026-09-17, reemplaza el filtro viejo de Calidad antes de recibir). Se
                        // resetea calidad_aprobacion a 'pendiente' porque es material recién llegado
                        // que Calidad todavía no ha visto.
                        $numSerie = trim($it['numero_serie'] ?? '');
                        $db->prepare("UPDATE almacen_items SET numero_serie = IF(? <> '', ?, numero_serie), calidad_aprobacion = 'pendiente', calidad_fecha = NULL, calidad_nota = NULL WHERE proyecto_id = ? AND material_id = ?")
                           ->execute([$numSerie, $numSerie, $proyecto_id, intval($it['material_id'])]);
                        // Categorías que a fuerza deben traer certificado (pedido explícito del
                        // usuario) -- se juntan para una sola notificación a Calidad, no una por
                        // material.
                        $catStmt = $db->prepare("SELECT c.nombre FROM materiales m LEFT JOIN categorias c ON c.id = m.categoria_id WHERE m.id = ?");
                        $catStmt->execute([intval($it['material_id'])]);
                        if (in_array($catStmt->fetchColumn(), ['Equipo', 'Instrumento', 'Componente Principal'], true)) {
                            $materiales_prioritarios[] = $it['descripcion'];
                        }
                        // Ledger unificado: esta recepción nunca había tocado el stock real del
                        // catálogo (materiales.stock_disponible) ni dejaba rastro — se corrige aquí,
                        // es la Entrada real de material físico al almacén.
                        try {
                            registrarMovimientoAlmacen($db, intval($it['material_id']), 'entrada', $cant_rec, 'orden_compra_proyecto', $proyecto_id, "Recepción de OC — proyecto \"$proy_nombre_recep\"");
                        } catch (Exception $eu2) {}
                    }
                }
                if ($resumen) $detalle_audit .= " | " . implode(' | ', $resumen);
                if ($materiales_prioritarios) {
                    notificarRoles(['calidad'], 'material_prioritario_recibido', "Material prioritario recibido: $proy_nombre_recep",
                        "Requiere certificado obligatorio -- " . implode(', ', $materiales_prioritarios) . " (proyecto \"$proy_nombre_recep\").",
                        'calidad.php?seccion=aprobacion_materiales', $_SESSION['user_id']);
                }
            }
            $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'actualizar', 'proyectos', ?, ?)")->execute([$_SESSION['user_id'], $proyecto_id, $detalle_audit]);

            // Guardar incidencias reportadas
            $items_arr_inc = $items_detalle_raw ? (json_decode($items_detalle_raw, true) ?? []) : [];
            // Auto-detectar entrega_incompleta desde items
            $tiene_incompleto = false;
            foreach ($items_arr_inc as $it) {
                if (($it['estado'] ?? '') === 'parcial' || ($it['estado'] ?? '') === 'faltante') {
                    $tiene_incompleto = true;
                    break;
                }
            }
            if ($tiene_incompleto && !in_array('entrega_incompleta', $incidencias_tipos)) {
                $incidencias_tipos[] = 'entrega_incompleta';
            }

            if (!empty($incidencias_tipos)) {
                // Se guarda por OC (no solo por proveedor) para poder rastrear, vía
                // orden_compra_detalle, qué MATERIALES específicos venían en la entrega
                // que tuvo el problema -- antes solo quedaba proveedor+proyecto+fecha,
                // sin forma de saber a qué material se refería la incidencia.
                $stmt_ocs_inc = $db->prepare("SELECT id, proveedor_id FROM ordenes_compra WHERE proyecto_id = ? OR requisicion_id IN (SELECT id FROM requisiciones WHERE proyecto_id = ? OR proyecto = (SELECT nombre FROM proyectos WHERE id = ?))");
                $stmt_ocs_inc->execute([$proyecto_id, $proyecto_id, $proyecto_id]);
                $ocs_para_inc = $stmt_ocs_inc->fetchAll();
                $fecha_hoy = date('Y-m-d');
                $stmt_inc = $db->prepare("INSERT INTO proveedor_incidencias (proveedor_id, proyecto_id, orden_compra_id, fecha, tipo, descripcion, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($ocs_para_inc as $oc_inc) {
                    if (!$oc_inc['proveedor_id']) continue;
                    foreach ($incidencias_tipos as $tipo_inc) {
                        if (in_array($tipo_inc, ['llegada_tardia','entrega_incompleta','mal_estado','otro'])) {
                            $stmt_inc->execute([$oc_inc['proveedor_id'], $proyecto_id, $oc_inc['id'], $fecha_hoy, $tipo_inc, $notas_recepcion ?: null, $_SESSION['user_id'] ?? null]);
                        }
                    }
                }
            }

            // ── Re-compra automática: entrega parcial o material en mal estado/otro
            // problema. Bloquea al/los proveedor(es) responsables PARA ESTE PROYECTO
            // (desbloqueable a mano, ver proveedor_bloqueado) y genera una requisición
            // nueva solo con lo que quedó pendiente o en duda, para que Compras la
            // vuelva a cotizar con alguien más. Mismo patrón que "rechazar OC" (Proyectos).
            $hay_problema_calidad = array_intersect($incidencias_tipos, ['mal_estado', 'otro']);
            if (!$completo || $hay_problema_calidad) {
                $provs_raw = $_POST['proveedores_incompletos'] ?? '[]';
                $provs_incompletos = array_map('intval', json_decode($provs_raw, true) ?: []);

                $items_recompra = [];
                foreach ($items_arr_inc as $it) {
                    $estado_it = $it['estado'] ?? '';
                    if (!$completo && $estado_it === 'completo') continue; // parcial: solo lo que faltó
                    $pendiente = $completo
                        ? floatval($it['cantidad_ordenada'] ?? 0)           // completo+mal_estado: se desconfía de TODO el lote
                        : floatval($it['cantidad_ordenada'] ?? 0) - floatval($it['cantidad_recibida'] ?? 0);
                    if ($pendiente <= 0) continue;
                    $items_recompra[] = [
                        'material_id'   => !empty($it['material_id']) ? intval($it['material_id']) : null,
                        'descripcion'   => $it['descripcion'] ?? '',
                        'cantidad'      => $pendiente,
                        'unidad_medida' => $it['unidad'] ?? 'pza',
                    ];
                }

                foreach ($provs_incompletos as $prov_id_bloq) {
                    $motivo_bloq = !$completo ? 'Entrega parcial en proyecto ' . $proy_nombre_recep : 'Material en mal estado/otro problema en proyecto ' . $proy_nombre_recep;
                    bloquearProveedorProyecto($db, $prov_id_bloq, $proyecto_id, $motivo_bloq, $_SESSION['user_id'] ?? 0);
                }

                if (!empty($items_recompra)) {
                    $req_recompra = crearRequisicion($db, [
                        'usuario_id'      => $_SESSION['user_id'] ?? 0,
                        'proyecto'        => $proy_nombre_recep,
                        'proyecto_id'     => $proyecto_id,
                        'area'            => 'Compras',
                        'solicitante'     => 'Recepción de pedidos',
                        'prioridad'       => 'alta',
                        'notas_generales' => ($completo ? 'Re-cotización por material en mal estado/otro problema' : 'Re-cotización por recepción parcial') . " — Proyecto: $proy_nombre_recep.",
                    ], $items_recompra, null);
                    if (!empty($req_recompra['duplicados'])) {
                        $folios_dup = implode(', ', array_unique(array_column($req_recompra['duplicados'], 'folio')));
                        $msg .= " Aviso: ya existe(n) otra(s) requisición(es) activa(s) de este mismo proyecto con material igual, pedida(s) por otro departamento (folio(s) $folios_dup) — revisa si es necesario duplicar.";
                    }
                    notificarRoles(
                        ['compras'],
                        'oc_recotizacion',
                        "Re-cotización necesaria: $proy_nombre_recep",
                        "La recepción de \"$proy_nombre_recep\" quedó " . ($completo ? 'con material en mal estado/otro problema' : 'parcial') . ". Se generó la requisición {$req_recompra['folio']} para volver a cotizar " . count($items_recompra) . " ítem(s)" . (!empty($provs_incompletos) ? ', el/los proveedor(es) involucrado(s) quedaron bloqueados para este proyecto' : '') . ".",
                        'compras.php',
                        $_SESSION['user_id'] ?? 0
                    );
                }
            }

            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 5. CAMBIAR ESTADO DEL PROYECTO (para regresar pasos) ───────────────────
    case 'cambiar_estado':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
        $nuevo_estado = sanitize($_POST['estado'] ?? '');
        $estados_permitidos = ['espera', 'activo', 'cotizacion_enviada', 'cotizacion_recibida', 'orden_generada'];
        if (!$proyecto_id || !in_array($nuevo_estado, $estados_permitidos)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
            exit;
        }
        try {
            $db->prepare("UPDATE proyectos SET estado = ?, fecha_estado = NOW() WHERE id = ?")->execute([$nuevo_estado, $proyecto_id]);
            $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'actualizar', 'proyectos', ?, ?)")->execute([$_SESSION['user_id'], $proyecto_id, "Estado cambiado a: $nuevo_estado"]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 6. ENVIAR SOLICITUD DE COTIZACIÓN (requisición sin proyecto) ────────────
    // Espejo de 'enviar_solicitud' pero keyeado por requisicion_id en vez de
    // proyecto_id — no toca las cotizaciones/estado de proyectos.
    case 'enviar_solicitud_req':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $requisicion_id = intval($_POST['requisicion_id'] ?? 0);
        $proveedor_ids  = $_POST['proveedor_ids'] ?? [];
        $mensaje        = sanitize($_POST['mensaje'] ?? '');

        if (!$requisicion_id || empty($proveedor_ids)) {
            echo json_encode(['success' => false, 'error' => 'Selecciona al menos un proveedor']);
            exit;
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM requisiciones WHERE id = ?");
            $stmt->execute([$requisicion_id]);
            $requisicion = $stmt->fetch();
            if (!$requisicion) throw new Exception("Requisición no encontrada");

            $stmt_items = $db->prepare("
                SELECT rd.descripcion as item, rd.descripcion, (rd.cantidad - rd.cantidad_entregada) as cantidad_requerida,
                       rd.marca, rd.modelo
                FROM requisicion_detalle rd
                WHERE rd.requisicion_id = ? AND (rd.cantidad - rd.cantidad_entregada) > 0
            ");
            $stmt_items->execute([$requisicion_id]);
            $items_req = $stmt_items->fetchAll();

            $emails_ok = [];
            $emails_fail = [];
            $sin_email = [];

            foreach ($proveedor_ids as $prov_id) {
                $prov_id = intval($prov_id);
                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ? AND activo = 1");
                $stmt->execute([$prov_id]);
                $prov = $stmt->fetch();
                if (!$prov) continue;

                $stmt = $db->prepare("SELECT id FROM cotizaciones WHERE requisicion_id = ? AND proveedor_id = ? AND estado = 'pendiente'");
                $stmt->execute([$requisicion_id, $prov_id]);
                $existe = $stmt->fetch();
                if (!$existe) {
                    $stmt = $db->prepare("INSERT INTO cotizaciones (proyecto_id, requisicion_id, proveedor_id, email_enviado_a, notas) VALUES (NULL, ?, ?, ?, ?)");
                    $stmt->execute([$requisicion_id, $prov_id, $prov['email'], $mensaje]);
                }

                if (!empty($prov['email'])) {
                    $nombre_emisor = $_SESSION['user_name'] ?? 'Departamento de Compras';
                    $email_data = generarDatosEmail($prov, ['id' => $requisicion_id], $items_req, $mensaje, $nombre_emisor);
                    $enviado = enviarEmailSolicitud($email_data);
                    if ($enviado) {
                        $emails_ok[] = $prov['nombre'] . ' <' . $prov['email'] . '>';
                    } else {
                        $emails_fail[] = [
                            'nombre' => $prov['nombre'],
                            'email'  => $prov['email'],
                            'asunto' => $email_data['subject_plain'],
                            'cuerpo' => $email_data['text'],
                        ];
                    }
                } else {
                    $sin_email[] = $prov['nombre'];
                }
            }

            $db->prepare("UPDATE requisiciones SET estado_compra = 'cotizacion_enviada' WHERE id = ?")->execute([$requisicion_id]);
            registrarEventoRequisicion($db, $requisicion_id, 'cotizacion_enviada');

            $db->commit();

            $n_provs = count($emails_ok) + count($emails_fail);
            notificarRoles(
                ['almacen'],
                'cotizacion_enviada',
                "Cotización solicitada: {$requisicion['folio']}",
                "Se solicitó cotización a $n_provs proveedor(es) para la requisición \"{$requisicion['folio']}\".",
                'compras.php?seccion=requisiciones_compra&detalle_id=' . $requisicion_id,
                $_SESSION['user_id'] ?? 0
            );

            echo json_encode([
                'success'     => true,
                'emails_ok'   => $emails_ok,
                'emails_fail' => $emails_fail,
                'sin_email'   => $sin_email,
                'message'     => 'Solicitudes registradas. La requisición está en espera de cotizaciones.'
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 6b. SALTAR COTIZACIÓN (requisición sin proyecto) — registrar proveedor
    // sin enviar email, para cuando ya se conoce el costo y no hace falta cotizar ──
    case 'saltar_fase_req':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $requisicion_id = intval($_POST['requisicion_id'] ?? 0);
        $proveedor_ids  = $_POST['proveedor_ids'] ?? [];

        if (!$requisicion_id || empty($proveedor_ids)) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }
        try {
            $db->beginTransaction();
            foreach ($proveedor_ids as $prov_id) {
                $prov_id = intval($prov_id);
                $existe = $db->prepare("SELECT id FROM cotizaciones WHERE requisicion_id = ? AND proveedor_id = ? AND estado = 'pendiente'");
                $existe->execute([$requisicion_id, $prov_id]);
                if (!$existe->fetch()) {
                    $stmt = $db->prepare("INSERT INTO cotizaciones (proyecto_id, requisicion_id, proveedor_id, notas) VALUES (NULL, ?, ?, 'Registrado manualmente')");
                    $stmt->execute([$requisicion_id, $prov_id]);
                }
            }
            $db->prepare("UPDATE requisiciones SET estado_compra = 'cotizacion_enviada' WHERE id = ?")->execute([$requisicion_id]);
            registrarEventoRequisicion($db, $requisicion_id, 'cotizacion_enviada');
            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 7. GENERAR ÓRDENES DE COMPRA (requisición sin proyecto) ─────────────────
    case 'generar_ordenes_req':
        if (!hasAcceso('compras')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $requisicion_id   = intval($_POST['requisicion_id'] ?? 0);
        $fecha_entrega    = sanitize($_POST['fecha_entrega'] ?? '');
        $condiciones_pago = sanitize($_POST['condiciones_pago'] ?? 'Crédito');
        $notas_oc         = sanitize($_POST['notas'] ?? '');

        $raw_sel   = $_POST['seleccion'] ?? '';
        $seleccion = json_decode($raw_sel, true);
        if ($raw_sel !== '' && json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'seleccion JSON inválido: ' . json_last_error_msg()]);
            exit;
        }
        $seleccion = $seleccion ?: [];

        if (!$requisicion_id || empty($seleccion)) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }

        try {
            $db->beginTransaction();
            $ordenes_creadas = [];

            $stmt_req = $db->prepare("SELECT folio, proyecto_id FROM requisiciones WHERE id = ?");
            $stmt_req->execute([$requisicion_id]);
            $req_row  = $stmt_req->fetch();
            $folio_req = $req_row['folio'] ?? "REQ-$requisicion_id";
            // Si la requisición sí tiene proyecto (ej. la generó Producción desde un plano),
            // la OC hereda ese proyecto_id -- antes quedaba SIEMPRE en NULL sin importar el
            // origen, lo que hacía que la recepción no supiera a qué proyecto pertenecía.
            $req_proyecto_id = $req_row['proyecto_id'] ?? null;

            foreach ($seleccion as $cot_id => $items_sel) {
                $cot_id = intval($cot_id);
                if (empty($items_sel)) continue;

                $stmt = $db->prepare("SELECT c.*, p.nombre as proveedor_nombre FROM cotizaciones c JOIN proveedores p ON c.proveedor_id = p.id WHERE c.id = ? AND c.requisicion_id = ?");
                $stmt->execute([$cot_id, $requisicion_id]);
                $cot = $stmt->fetch();
                if (!$cot || $cot['estado'] !== 'recibida') continue;

                $items_sel = validarItemsContraCotizacion($items_sel, $cot['datos_ia']);
                if (empty($items_sel)) continue;

                $subtotal = 0;
                foreach ($items_sel as $it) {
                    $subtotal += floatval($it['cantidad']) * floatval($it['precio_unit']);
                }
                $subtotal = round($subtotal, 2);
                $iva      = round($subtotal * 0.16, 2);
                $total    = round($subtotal + $iva, 2);

                $folio = generarFolioOC($req_proyecto_id, $req_proyecto_id ? null : ('REQ' . $requisicion_id));
                $stmt  = $db->prepare("INSERT INTO ordenes_compra (folio, requisicion_id, proyecto_id, proveedor_id, usuario_id, subtotal, iva, total, notas, condiciones_pago, moneda, tipo_cambio, fecha_entrega_estimada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MXN', 1, ?)");
                $stmt->execute([
                    $folio,
                    $requisicion_id,
                    $req_proyecto_id,
                    $cot['proveedor_id'],
                    $_SESSION['user_id'],
                    $subtotal, $iva, $total,
                    $notas_oc ?: null,
                    $condiciones_pago,
                    $fecha_entrega ?: null
                ]);
                $orden_id = $db->lastInsertId();

                $item_num = 1;
                foreach ($items_sel as $it) {
                    $desc     = sanitize($it['descripcion'] ?? '');
                    $qty      = floatval($it['cantidad'] ?? 0);
                    $unidad   = sanitize($it['unidad'] ?? 'pza');
                    $precio   = floatval($it['precio_unit'] ?? 0);
                    $sub_item = round($qty * $precio, 2);
                    $mat_id   = intval($it['material_id'] ?? 0) ?: null;
                    if (!$desc || $qty <= 0) continue;

                    $stmt = $db->prepare("INSERT INTO orden_compra_detalle (orden_id, item_numero, material_id, cantidad, unidad_medida, descripcion, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$orden_id, $item_num++, $mat_id, $qty, $unidad, $desc, $precio, $sub_item]);
                    sincronizarPrecioMaterial($db, $mat_id, $precio);
                }

                $db->prepare("UPDATE cotizaciones SET orden_generada_id = ? WHERE id = ?")->execute([$orden_id, $cot_id]);

                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'ordenes_compra', ?, ?)")
                   ->execute([$_SESSION['user_id'], $orden_id, "OC $folio generada desde cotización ID $cot_id para requisición $folio_req"]);

                $ordenes_creadas[] = ['folio' => $folio, 'id' => $orden_id, 'proveedor' => $cot['proveedor_nombre']];
            }

            if (empty($ordenes_creadas)) {
                throw new Exception("No se pudo generar ninguna orden. Verifica que los items seleccionados tengan precio y cantidad válidos.");
            }

            $db->prepare("UPDATE requisiciones SET estado_compra = 'orden_generada' WHERE id = ?")->execute([$requisicion_id]);
            registrarEventoRequisicion($db, $requisicion_id, 'orden_generada');

            try {
                $oc_id_principal = $ordenes_creadas[0]['id'];
                $db->prepare("UPDATE almacen_items SET fincado = 1, fincado_oc_id = ? WHERE requisicion_id = ? AND diferencia < 0")
                   ->execute([$oc_id_principal, $requisicion_id]);
            } catch (Exception $ef) { /* columna puede no existir en instalaciones antiguas */ }

            $db->commit();

            $n_oc = count($ordenes_creadas);
            notificarRoles(
                ['almacen'],
                'orden_generada',
                "Órdenes generadas: $folio_req",
                "Se generaron $n_oc orden(es) de compra para la requisición \"$folio_req\".",
                'almacen2.php?seccion=surtir_requisicion&id=' . $requisicion_id,
                $_SESSION['user_id'] ?? 0
            );
            if ($req_proyecto_id) {
                // Misma lógica que en el flujo "por proyecto" de arriba -- esta requisición
                // sí está ligada a un proyecto, así que la OC también necesita que Proyectos
                // confirme la fecha antes de que Gerencia pueda aprobarla.
                notificarRoles(
                    ['proyectos'],
                    'oc_pendiente_confirmar',
                    "OC pendiente de confirmar fecha: $folio_req",
                    "Se generaron $n_oc orden(es) de compra para la requisición \"$folio_req\". Confirma la fecha de entrega para que Gerencia pueda aprobarlas.",
                    'proyecto.php?seccion=proyectos',
                    $_SESSION['user_id'] ?? 0
                );
            }

            echo json_encode(['success' => true, 'ordenes' => $ordenes_creadas]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── 8. CONFIRMAR RECEPCIÓN (requisición sin proyecto, desde almacén) ────────
    case 'confirmar_recepcion_req':
        if (!hasAcceso('almacen')) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }

        $requisicion_id = intval($_POST['requisicion_id'] ?? 0);
        if (!$requisicion_id) {
            echo json_encode(['success' => false, 'error' => 'requisicion_id requerido']);
            exit;
        }

        try {
            $stmt_r = $db->prepare("SELECT * FROM requisiciones WHERE id = ?");
            $stmt_r->execute([$requisicion_id]);
            $requisicion = $stmt_r->fetch();
            if (!$requisicion) throw new Exception("Requisición no encontrada");

            $items_detalle_raw = $_POST['items_detalle'] ?? '';
            $notas_recepcion   = sanitize($_POST['notas_recepcion'] ?? '');
            $items_arr         = $items_detalle_raw ? (json_decode($items_detalle_raw, true) ?? []) : [];
            $orden_id          = intval($_POST['orden_id'] ?? 0);
            $completo_oc       = intval($_POST['completo'] ?? 0);

            // Recibir la OC solo mete el material físico a stock (entrada real). NO marca los
            // ítems de la requisición como entregados — eso sigue siendo un paso manual aparte
            // en Surtir, aunque el material que llegó sea justo el que faltaba, para no dar por
            // hecho que Almacén ya lo entregó sin haberlo confirmado ahí.
            $materiales_prioritarios = [];
            foreach ($items_arr as $it) {
                $cant_rec = floatval($it['cantidad_recibida'] ?? 0);
                if ($cant_rec <= 0 || empty($it['material_id'])) continue;
                registrarMovimientoAlmacen($db, intval($it['material_id']), 'entrada', $cant_rec, 'orden_compra_requisicion', $requisicion_id, "Recepción de OC — requisición \"{$requisicion['folio']}\"");
                // Categorías que a fuerza deben traer certificado (pedido explícito del usuario) --
                // sin proyecto no hay almacen_items donde revisarlas en Calidad, pero igual se
                // notifica para que alguien dé seguimiento manual.
                $catStmt = $db->prepare("SELECT m.descripcion, c.nombre AS categoria FROM materiales m LEFT JOIN categorias c ON c.id = m.categoria_id WHERE m.id = ?");
                $catStmt->execute([intval($it['material_id'])]);
                $matRow = $catStmt->fetch();
                if ($matRow && in_array($matRow['categoria'], ['Equipo', 'Instrumento', 'Componente Principal'], true)) {
                    $materiales_prioritarios[] = $matRow['descripcion'];
                }
            }
            if ($materiales_prioritarios) {
                notificarRoles(['calidad'], 'material_prioritario_recibido', "Material prioritario recibido: {$requisicion['folio']}",
                    "Requiere certificado obligatorio -- " . implode(', ', $materiales_prioritarios) . " (requisición \"{$requisicion['folio']}\").",
                    'calidad.php', $_SESSION['user_id']);
            }

            if ($orden_id) {
                $db->prepare("UPDATE ordenes_compra SET estado = 'recibida', recibida = 1, recibida_parcial = ? WHERE id = ? AND requisicion_id = ?")
                   ->execute([$completo_oc ? 0 : 1, $orden_id, $requisicion_id]);
                // Sin proyecto no hay almacen_items para revisar por material (2026-09-17) -- solo
                // se avisa que llegó, el seguimiento real queda en $materiales_prioritarios arriba.
                notificarRoles(['calidad'], 'oc_recibida_calidad', "OC recibida",
                    "La orden de compra de la requisición \"{$requisicion['folio']}\" fue recibida.",
                    'calidad.php', $_SESSION['user_id']);
            }

            $nuevo_estado_compra = $completo_oc ? 'recibido' : 'recepcion_parcial';
            $db->prepare("UPDATE requisiciones SET estado_compra = ? WHERE id = ?")->execute([$nuevo_estado_compra, $requisicion_id]);
            registrarEventoRequisicion($db, $requisicion_id, $nuevo_estado_compra);

            $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'actualizar', 'requisiciones', ?, ?)")
               ->execute([$_SESSION['user_id'], $requisicion_id, "Recepción de OC registrada." . ($notas_recepcion ? " Notas: $notas_recepcion" : '')]);

            if (!empty($requisicion['usuario_id'])) {
                $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, link) VALUES (?, 'requisicion_surtida', ?, ?, ?)")
                   ->execute([
                       $requisicion['usuario_id'],
                       "Material recibido: {$requisicion['folio']}",
                       "Llegó material de la orden de compra para tu requisición {$requisicion['folio']}. Almacén debe surtirlo.",
                       'almacen2.php?seccion=ver_requisicion&id=' . $requisicion_id
                   ]);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── DESBLOQUEAR PROVEEDOR EN UN PROYECTO (manual, tras bloqueo automático
    // por recepción parcial o material en mal estado) ──────────────────────────
    case 'desbloquear_proveedor':
        if (!hasAcceso('compras') && !isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Sin permiso']);
            exit;
        }
        $bloqueo_id = intval($_POST['bloqueo_id'] ?? 0);
        if (!$bloqueo_id) {
            echo json_encode(['success' => false, 'error' => 'bloqueo_id requerido']);
            exit;
        }
        try {
            $db->prepare("UPDATE proveedor_bloqueado SET activo = 0, desbloqueado_por = ?, fecha_desbloqueo = NOW() WHERE id = ?")
               ->execute([$_SESSION['user_id'] ?? null, $bloqueo_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
}

// ── HELPER: Construir datos del email ──────────────────────────────────────────
function generarDatosEmail(array $proveedor, array $proyecto, array $items, string $mensaje_extra, string $nombre_emisor = 'Departamento de Compras'): array {
    $atencion      = htmlspecialchars($proveedor['atencion'] ?? $proveedor['nombre']);
    $folio_sc      = 'SC-' . date('Y') . '-' . str_pad($proyecto['id'], 4, '0', STR_PAD_LEFT);
    $fecha_hoy     = date('d/m/Y');
    $extra_text    = $mensaje_extra ? "\n$mensaje_extra\n" : '';
    $extra_html    = $mensaje_extra
        ? '<p style="margin:0 0 15px;padding:12px 15px;background:#fff8e1;border-left:3px solid #FF9800;color:#333;font-size:14px;line-height:1.6;">'
          . nl2br(htmlspecialchars($mensaje_extra)) . '</p>'
        : '';

    // Filas de la tabla
    $items_html = '';
    $items_text = '';
    foreach ($items as $i => $item) {
        $num    = $i + 1;
        $desc   = htmlspecialchars($item['item'] ?? $item['descripcion'] ?? '-');
        $detalle = htmlspecialchars($item['descripcion'] ?? '');
        $cant   = $item['cantidad_requerida'] ?? '-';
        $marca  = htmlspecialchars($item['marca'] ?? '');
        $modelo = htmlspecialchars($item['modelo'] ?? '');
        $bg     = ($i % 2 === 0) ? '#ffffff' : '#f4f7fb';
        $items_html .= "
          <tr style='background:{$bg};'>
            <td style='padding:9px 8px;border-bottom:1px solid #e0e0e0;text-align:center;color:#003d5c;font-weight:bold;'>{$num}</td>
            <td style='padding:9px 8px;border-bottom:1px solid #e0e0e0;'><strong>{$desc}</strong>" . ($detalle && $detalle !== $desc ? "<br><span style='font-size:11px;color:#666;'>{$detalle}</span>" : '') . "</td>
            <td style='padding:9px 8px;border-bottom:1px solid #e0e0e0;text-align:center;'>{$cant}</td>
            <td style='padding:9px 8px;border-bottom:1px solid #e0e0e0;color:#555;'>{$marca}</td>
            <td style='padding:9px 8px;border-bottom:1px solid #e0e0e0;color:#555;'>{$modelo}</td>
          </tr>";
        $items_text .= "  {$num}. " . ($item['item'] ?? $item['descripcion'] ?? '-') . " — Cant: {$cant}\n";
    }
    if (!$items_html) {
        $items_html = "<tr><td colspan='5' style='padding:15px;text-align:center;color:#999;'>Sin artículos registrados</td></tr>";
    }

    $subject_plain = 'Solicitud de Cotización ' . $folio_sc;
    $nombre_emisor_esc = htmlspecialchars($nombre_emisor);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f5;">
<tr><td align="center" style="padding:25px 10px;">

  <table width="660" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:6px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.12);">

    <!-- ═══ ENCABEZADO ═══ -->
    <tr>
      <td style="padding:20px 28px;border-bottom:3px solid #003d5c;background:#ffffff;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="vertical-align:middle;">
              <img src="cid:logo_main" alt="EMPRESA EJEMPLO" style="height:75px;width:auto;display:block;">
            </td>
            <td align="right" style="vertical-align:middle;">
              <div style="font-size:18px;font-weight:bold;color:#003d5c;letter-spacing:1px;text-transform:uppercase;">Solicitud de Cotización</div>
              <div style="font-size:12px;color:#666;margin-top:5px;line-height:1.6;">
                Folio: <strong style="color:#003d5c;">{$folio_sc}</strong><br>
                Fecha: {$fecha_hoy}
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- ═══ CUERPO ═══ -->
    <tr>
      <td style="padding:28px;background:#ffffff;">

        <!-- Marca de agua (isotipo centrado, muy tenue) -->
        <div style="text-align:center;margin:0 0 -120px 0;line-height:0;">
          <img src="cid:isotipo" alt="" style="height:220px;width:auto;opacity:0.04;display:inline-block;">
        </div>

        <!-- Saludo -->
        <p style="margin:0 0 14px;font-size:14px;color:#333;">
          Estimado(a) proveedor(a) <strong style="color:#003d5c;">{$atencion}</strong>:
        </p>
        <p style="margin:0 0 18px;font-size:14px;color:#333;line-height:1.7;">
          Por medio de la presente, nos permitimos dirigirnos a usted con la finalidad de solicitar su amable cotización para los artículos que se describen en el presente documento. Le agradeceremos considerar sus mejores precios, condiciones de entrega y tiempo de respuesta disponibles.
        </p>

        {$extra_html}

        <p style="margin:0 0 10px;font-size:14px;font-weight:bold;color:#003d5c;">Artículos requeridos:</p>

        <!-- Tabla de artículos -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;border:1px solid #dde3ea;border-radius:4px;overflow:hidden;">
          <thead>
            <tr style="background:#003d5c;color:#ffffff;">
              <th style="padding:10px 8px;text-align:center;width:35px;">#</th>
              <th style="padding:10px 8px;text-align:left;">Descripción</th>
              <th style="padding:10px 8px;text-align:center;width:75px;">Cantidad</th>
              <th style="padding:10px 8px;text-align:left;width:110px;">Marca</th>
              <th style="padding:10px 8px;text-align:left;width:110px;">Modelo</th>
            </tr>
          </thead>
          <tbody>{$items_html}</tbody>
        </table>

        <!-- Cierre -->
        <p style="margin:22px 0 8px;font-size:14px;color:#333;line-height:1.7;">
          Le solicitamos amablemente remitir su cotización a la brevedad posible, adjuntando su propuesta en formato <strong>PDF o Excel</strong>, indicando precios unitarios, tiempo de entrega y condiciones de pago.
        </p>
        <p style="margin:0 0 22px;font-size:14px;color:#333;line-height:1.7;">
          Agradecemos de antemano su atención y quedamos a sus órdenes para cualquier aclaración.
        </p>

        <!-- Firma -->
        <table cellpadding="0" cellspacing="0">
          <tr>
            <td style="border-left:3px solid #003d5c;padding:4px 12px;">
              <div style="font-size:13px;color:#555;">Atentamente,</div>
              <div style="font-size:15px;font-weight:bold;color:#003d5c;margin-top:3px;">{$nombre_emisor_esc}</div>
              <div style="font-size:12px;color:#777;margin-top:2px;">Departamento de Compras &mdash; EMPRESA EJEMPLO</div>
            </td>
          </tr>
        </table>

      </td>
    </tr>

    <!-- ═══ PIE DE PÁGINA ═══ -->
    <tr>
      <td style="background:#f8f9fa;border-top:2px solid #003d5c;padding:14px 28px;">
        <div style="font-size:10px;color:#666;margin-bottom:10px;line-height:1.6;">
          <strong>DOC-COM-FO-01B, Rev. 4, 20-dic-21</strong> &nbsp;|&nbsp;
          Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización.
        </div>
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="font-size:11px;color:#555;line-height:1.8;vertical-align:bottom;">
              Calle Ejemplo No. 123<br>
              Col. Centro. C.P. 00000<br>
              Ciudad de M&eacute;xico<br>
              <a href="http://www.ejemplo.com" style="color:#003d5c;text-decoration:none;">www.ejemplo.com</a>
            </td>
            <td style="font-size:11px;color:#555;line-height:1.8;vertical-align:bottom;text-align:center;">
              Tel. 52 55 0000 0000<br>
              <a href="mailto:contacto@ejemplo.com" style="color:#003d5c;text-decoration:none;">contacto@ejemplo.com</a><br>
              <strong>Sistema de Gesti&oacute;n de Calidad</strong>
            </td>
            <td style="text-align:right;vertical-align:bottom;">
              <img src="cid:isotipo" alt="EMPRESA EJEMPLO" style="height:42px;width:auto;">
            </td>
          </tr>
        </table>
      </td>
    </tr>

  </table>
</td></tr>
</table>
</body>
</html>
HTML;

    $text = implode("\n", [
        "SOLICITUD DE COTIZACIÓN {$folio_sc}",
        str_repeat('-', 50),
        "Estimado(a) proveedor(a) {$atencion}:",
        "",
        "Por medio de la presente, solicitamos su cotización para los artículos descritos a continuación.",
        $extra_text,
        "ARTÍCULOS REQUERIDOS:",
        $items_text,
        "Le solicitamos remitir su propuesta en PDF o Excel indicando precios unitarios,",
        "tiempo de entrega y condiciones de pago.",
        "",
        "Atentamente,",
        $nombre_emisor,
        "Departamento de Compras — EMPRESA EJEMPLO",
        "contacto@ejemplo.com | Tel. 52 55 0000 0000",
    ]);

    return [
        'to'            => $proveedor['email'],
        'subject_plain' => $subject_plain,
        'html'          => $html,
        'text'          => $text,
        'images'        => [
            'logo_main' => __DIR__ . '/../uploads/logo/logo.png',
            'isotipo'   => __DIR__ . '/../uploads/logo/logo_horizontal.png',
        ],
    ];
}

// ── HELPER: Enviar email via PHPMailer + Brevo SMTP ───────────────────────────
function enviarEmailSolicitud(array $d): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($d['to']);
        $mail->Subject = $d['subject_plain'];
        $mail->isHTML(true);
        $mail->Body    = $d['html'];
        $mail->AltBody = $d['text'];

        // Imágenes embebidas (CID)
        foreach ($d['images'] ?? [] as $cid => $path) {
            if (file_exists($path)) {
                $mail->addEmbeddedImage($path, $cid, basename($path));
            }
        }

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
