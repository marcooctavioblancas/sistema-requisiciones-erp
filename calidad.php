<?php
require_once 'includes/config.php';
require_once 'includes/generar_requisicion.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}

if (!isLoggedIn() || (!hasAcceso('calidad') && !isAdmin())) {
    header('Location: index.php');
    exit();
}

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;
$db      = getDB();
$seccion = $_GET['seccion'] ?? 'dashboard';
$message = '';
$error   = '';

// ── POST handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // Registrar inspección
    if ($accion === 'registrar_inspeccion') {
        $oc_id     = !empty($_POST['orden_compra_id']) ? intval($_POST['orden_compra_id']) : null;
        $proy_id   = !empty($_POST['proyecto_id'])     ? intval($_POST['proyecto_id'])     : null;
        $mat_id    = !empty($_POST['material_id'])      ? intval($_POST['material_id'])     : null;
        $tipo      = sanitize($_POST['tipo_inspeccion']  ?? 'recepcion');
        $resultado = sanitize($_POST['resultado']         ?? 'pendiente');
        // El dropdown de "Inspector" (línea ~913) sí permite elegir a cualquier usuario
        // activo -- es una función real (registrar a nombre de otro inspector físico
        // sin acceso al sistema), no un bug. Pero el backend aceptaba CUALQUIER entero
        // sin validar que corresponda a un usuario activo real; se valida contra el
        // mismo universo que ya ofrece el propio dropdown.
        $inspector = intval($_POST['inspector_id'] ?? $_SESSION['user_id']);
        $chk_insp  = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND activo = 1");
        $chk_insp->execute([$inspector]);
        if (!$chk_insp->fetch()) {
            $inspector = (int)$_SESSION['user_id'];
        }
        $fecha_i   = sanitize($_POST['fecha_inspeccion']  ?? date('Y-m-d H:i:s'));
        $qty_rec   = floatval($_POST['cantidad_recibida']  ?? 0);
        $qty_apr   = floatval($_POST['cantidad_aprobada']  ?? 0);
        $qty_rech  = floatval($_POST['cantidad_rechazada'] ?? 0);
        $obs       = sanitize($_POST['observaciones']      ?? '');
        $nc_num    = sanitize($_POST['nc_numero']           ?? '');

        if ($resultado === 'rechazado' && empty($nc_num)) {
            $error = 'El Número de NC es obligatorio cuando el resultado es "Rechazado".';
        } else {
            try {
                $ruta_ev = null;
                if (!empty($_FILES['evidencia']['name']) && $_FILES['evidencia']['error'] === 0) {
                    $up = uploadPlano($_FILES['evidencia']);
                    if ($up['success']) $ruta_ev = $up['file'];
                }
                $db->prepare("INSERT INTO calidad_inspecciones
                    (orden_compra_id, proyecto_id, material_id, tipo_inspeccion, resultado,
                     inspector_id, fecha_inspeccion, cantidad_recibida, cantidad_aprobada,
                     cantidad_rechazada, observaciones, nc_numero, ruta_evidencia, creado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$oc_id, $proy_id, $mat_id, $tipo, $resultado,
                       $inspector, $fecha_i, $qty_rec ?: null, $qty_apr ?: null,
                       $qty_rech ?: null, $obs ?: null, $nc_num ?: null, $ruta_ev, $_SESSION['user_id']]);
                if ($resultado === 'rechazado' && $oc_id) {
                    $stmt_oc = $db->prepare("SELECT folio FROM ordenes_compra WHERE id=?");
                    $stmt_oc->execute([$oc_id]);
                    $folio_oc = $stmt_oc->fetchColumn() ?: "OC-$oc_id";
                    notificarRoles(['almacen'],'inspeccion_rechazada',"Material rechazado: $folio_oc",
                        "Inspección de $folio_oc resultó en RECHAZO. NC: $nc_num.",'calidad.php');
                }
                $message = "Inspección registrada correctamente.";
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    }

    // Aprobación OT (Producción)
    elseif ($accion === 'calidad_ot') {
        $ot_id     = intval($_POST['ot_id']);
        $resultado = sanitize($_POST['resultado']);
        $nota      = sanitize($_POST['nota'] ?? '');
        if (!in_array($resultado, ['aprobado','rechazado'])) { $error = 'Resultado inválido.'; }
        else {
            // Orden: Calidad solo puede actuar si Producción ya aprobó esta OT primero — no basta con
            // que la lista de la UI la filtre, se valida también aquí (defensa en profundidad).
            $stmtChk = $db->prepare("SELECT produccion_aprobacion FROM ordenes_trabajo WHERE id = ?");
            $stmtChk->execute([$ot_id]);
            if ($stmtChk->fetchColumn() !== 'aprobado') { $error = 'Esta OT todavía no ha sido aprobada por Producción.'; }
            else {
            try {
                $db->beginTransaction();
                if ($resultado === 'aprobado') {
                    $db->prepare("UPDATE ordenes_trabajo SET calidad_aprobacion='aprobado', calidad_fecha=NOW(), calidad_nota=? WHERE id=?")
                       ->execute([$nota ?: null, $ot_id]);

                    // Si la OT corresponde a una página de plano (PAGINA = PIEZA DE PLANO: las OTs de
                    // una misma página son los procesos/pasos para fabricar esa única pieza, no
                    // unidades físicas distintas), la serie de esa pieza se genera UNA sola vez, hasta
                    // que ya no queda ningún proceso pendiente de esa página — no en cada OT aprobada.
                    $stmtPg = $db->prepare("SELECT ot.plano_pagina_id, ot.proyecto_id, bl.pieza_nombre, pp.nombre AS pagina_nombre
                                             FROM ordenes_trabajo ot
                                             LEFT JOIN boom_listas bl ON bl.id = ot.boom_lista_id
                                             LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
                                             WHERE ot.id = ?");
                    $stmtPg->execute([$ot_id]);
                    $otPieza = $stmtPg->fetch();

                    if ($otPieza && $otPieza['plano_pagina_id']) {
                        $stmtPend = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo
                            WHERE plano_pagina_id = ? AND NOT (produccion_aprobacion='aprobado' AND calidad_aprobacion='aprobado')");
                        $stmtPend->execute([$otPieza['plano_pagina_id']]);
                        $procesosPendientes = (int)$stmtPend->fetchColumn();

                        $stmtExiste = $db->prepare("SELECT COUNT(*) FROM series_lotes WHERE plano_pagina_id = ?");
                        $stmtExiste->execute([$otPieza['plano_pagina_id']]);
                        $serieYaExiste = (int)$stmtExiste->fetchColumn() > 0;

                        if ($procesosPendientes === 0 && !$serieYaExiste) {
                            $stmtPagSerie = $db->prepare("SELECT numero_serie FROM plano_paginas WHERE id = ?");
                            $stmtPagSerie->execute([$otPieza['plano_pagina_id']]);
                            $numeroSerie = $stmtPagSerie->fetchColumn() ?: generarFolioSerieFabricada();

                            $db->prepare("INSERT INTO series_lotes
                                    (material_id, numero_serie, origen, proyecto_id, ot_id, plano_pagina_id, estado, fecha_ingreso, usuario_entrada_id, notas)
                                VALUES (NULL, ?, 'fabricado', ?, ?, ?, 'Fabricado', NOW(), ?, ?)")
                               ->execute([$numeroSerie, $otPieza['proyecto_id'], $ot_id, $otPieza['plano_pagina_id'],
                                          $_SESSION['user_id'], 'Pieza: ' . ($otPieza['pagina_nombre'] ?: $otPieza['pieza_nombre'])]);

                            $nombrePiezaNotif = $otPieza['pagina_nombre'] ?: $otPieza['pieza_nombre'] ?: 'pieza';
                            notificarRoles(['almacen'], 'pieza_fabricada', "Pieza fabricada: $nombrePiezaNotif",
                                "Se terminó de fabricar \"$nombrePiezaNotif\" con serie $numeroSerie.",
                                'almacen2.php?seccion=equipos_fabricados&proyecto_id=' . $otPieza['proyecto_id'], $_SESSION['user_id']);
                        }
                    }
                } else {
                    // Rechazo: la OT vuelve a la cola desde 0 — se resetean estado y los dos candados de
                    // aprobación para que se tenga que rehacer y volver a pasar por Producción y Calidad.
                    // Se conservan el tiempo ya trabajado y la bitácora (ot_logs) para trazabilidad, la
                    // nota del rechazo solo viaja en la notificación (el campo no se deja marcado).
                    $db->prepare("UPDATE ordenes_trabajo SET
                            estado = 'pendiente', en_problema = 0, problema_descripcion = NULL,
                            produccion_aprobacion = 'pendiente', produccion_fecha = NULL, produccion_nota = NULL,
                            calidad_aprobacion = 'pendiente', calidad_fecha = NULL, calidad_nota = NULL
                        WHERE id = ?")->execute([$ot_id]);
                }
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, ?, 'ordenes_trabajo', ?, ?)")
                   ->execute([$_SESSION['user_id'], $resultado === 'aprobado' ? 'calidad_aprobar_ot' : 'calidad_rechazar_ot', $ot_id, $nota ?: null]);

                $db->commit();

                $stmt_ot = $db->prepare("SELECT titulo FROM ordenes_trabajo WHERE id=?");
                $stmt_ot->execute([$ot_id]);
                $titulo_ot = $stmt_ot->fetchColumn() ?: "OT-$ot_id";
                if ($resultado === 'aprobado') {
                    notificarRoles(['manufactura'],'calidad_ot_aprobada',"OT aprobada: $titulo_ot",
                        "La OT fue aprobada por Calidad.",'produccion.php?seccion=ordenes',$_SESSION['user_id']);
                } else {
                    notificarRoles(['manufactura'],'calidad_ot_rechazada',"OT rechazada: $titulo_ot",
                        "Calidad rechazó la OT y volvió a la cola para rehacerse. Nota: $nota",'produccion.php?seccion=ordenes',$_SESSION['user_id']);
                }
                $message = "OT " . ($resultado === 'aprobado' ? 'aprobada' : 'rechazada y regresada a la cola') . " correctamente.";
            } catch(Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $error = 'Error: ' . $e->getMessage();
            }
            }
        }
    }

    // Aprobación de material recibido (2026-09-17, reemplaza "Aprobación OC (Almacén)" --
    // Calidad ya no es filtro para recibir, ahora revisa cada material por separado después
    // de que Almacén ya lo recibió, con certificado opcional/obligatorio según categoría.
    elseif ($accion === 'calidad_material') {
        $item_id   = intval($_POST['item_id'] ?? 0);
        $resultado = sanitize($_POST['resultado'] ?? '');
        $nota      = sanitize($_POST['nota'] ?? '');
        $aplicaCert = sanitize($_POST['aplica_certificado'] ?? '');
        if (!in_array($resultado, ['aprobado', 'rechazado'])) { $error = 'Resultado inválido.'; }
        elseif (!in_array($aplicaCert, ['si', 'no'])) { $error = 'Indica si aplica certificado.'; }
        else {
            $stmtItem = $db->prepare("SELECT ai.*, m.descripcion AS mat_desc, c.nombre AS categoria, p.nombre AS proyecto_nombre
                FROM almacen_items ai
                LEFT JOIN materiales m ON m.id = ai.material_id
                LEFT JOIN categorias c ON c.id = m.categoria_id
                LEFT JOIN proyectos p ON p.id = ai.proyecto_id
                WHERE ai.id = ?");
            $stmtItem->execute([$item_id]);
            $item = $stmtItem->fetch();
            $esPrioritario = $item && in_array($item['categoria'], ['Equipo', 'Instrumento', 'Componente Principal'], true);

            if (!$item) { $error = 'Material no encontrado.'; }
            elseif ($resultado === 'rechazado' && !$nota) { $error = 'Escribe el motivo del rechazo.'; }
            elseif ($esPrioritario && $aplicaCert !== 'si') { $error = 'Este material (' . $item['categoria'] . ') requiere certificado obligatorio.'; }
            else {
                $certRuta = $item['certificado_ruta'];
                $certNombre = $item['certificado_nombre'];
                if ($aplicaCert === 'si' && !empty($_FILES['certificado']['tmp_name']) && $_FILES['certificado']['error'] === UPLOAD_ERR_OK) {
                    $extCert = strtolower(pathinfo($_FILES['certificado']['name'], PATHINFO_EXTENSION));
                    if (!in_array($extCert, ['pdf', 'png', 'jpg', 'jpeg'])) {
                        $error = 'Formato de certificado no válido. Usa PDF o imagen.';
                    } else {
                        $dirCert = __DIR__ . '/uploads/certificados_material/';
                        if (!is_dir($dirCert)) mkdir($dirCert, 0755, true);
                        $fnameCert = 'cert_' . $item_id . '_' . time() . '.' . $extCert;
                        if (move_uploaded_file($_FILES['certificado']['tmp_name'], $dirCert . $fnameCert)) {
                            if ($certRuta && file_exists(__DIR__ . '/uploads/' . $certRuta)) @unlink(__DIR__ . '/uploads/' . $certRuta);
                            $certRuta = 'certificados_material/' . $fnameCert;
                            $certNombre = $_FILES['certificado']['name'];
                        }
                    }
                }
                if (!$error && $aplicaCert === 'si' && !$certRuta) {
                    $error = 'Sube el certificado o marca "No aplica".';
                }
                if (!$error) {
                    $db->prepare("UPDATE almacen_items SET calidad_aprobacion=?, calidad_fecha=NOW(), calidad_nota=?, aplica_certificado=?, certificado_ruta=?, certificado_nombre=?, certificado_subido_por=?, certificado_fecha=IF(? IS NOT NULL, NOW(), certificado_fecha) WHERE id=?")
                       ->execute([$resultado, $nota ?: null, $aplicaCert, $certRuta, $certNombre, $_SESSION['user_id'], $certRuta, $item_id]);
                    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, ?, 'almacen_items', ?, ?)")
                       ->execute([$_SESSION['user_id'], $resultado === 'aprobado' ? 'calidad_aprobar_material' : 'calidad_rechazar_material', $item_id, $nota ?: null]);
                    if ($resultado === 'aprobado') {
                        notificarRoles(['almacen'], 'calidad_material_aprobado', "Material aprobado: {$item['mat_desc']}",
                            "Calidad aprobó \"{$item['mat_desc']}\"" . ($item['proyecto_nombre'] ? " del proyecto \"{$item['proyecto_nombre']}\"" : '') . ".",
                            'calidad.php?seccion=aprobacion_materiales', $_SESSION['user_id']);
                    } else {
                        notificarRoles(['almacen'], 'calidad_material_rechazado', "Material rechazado: {$item['mat_desc']}",
                            "Calidad rechazó \"{$item['mat_desc']}\"" . ($item['proyecto_nombre'] ? " del proyecto \"{$item['proyecto_nombre']}\"" : '') . ". Motivo: $nota",
                            'calidad.php?seccion=aprobacion_materiales', $_SESSION['user_id']);
                    }
                    $message = 'Revisión de material guardada correctamente.';
                }
            }
        }
    }

    // Actualizar inspección
    elseif ($accion === 'actualizar_resultado') {
        $insp_id   = intval($_POST['inspeccion_id']);
        $resultado = sanitize($_POST['resultado'] ?? 'pendiente');
        $nc_num    = sanitize($_POST['nc_numero'] ?? '');
        $obs       = sanitize($_POST['observaciones'] ?? '');
        if ($resultado === 'rechazado' && empty($nc_num)) {
            $error = 'El NC es obligatorio para resultado "Rechazado".';
        } else {
            $db->prepare("UPDATE calidad_inspecciones SET resultado=?, nc_numero=?, observaciones=? WHERE id=?")
               ->execute([$resultado, $nc_num ?: null, $obs ?: null, $insp_id]);
            $message = "Resultado actualizado.";
        }
    }

    // Eliminar inspección
    elseif ($accion === 'eliminar_inspeccion') {
        // Borraba evidencia de no-conformidad sin restricción de autor ni admin --
        // mismo patrón que eliminar_fat: solo quien la creó, o un admin.
        $insp_id = intval($_POST['inspeccion_id']);
        if (isAdmin()) {
            $db->prepare("DELETE FROM calidad_inspecciones WHERE id=?")->execute([$insp_id]);
        } else {
            $db->prepare("DELETE FROM calidad_inspecciones WHERE id=? AND creado_por=?")->execute([$insp_id, $_SESSION['user_id']]);
        }
        $message = "Inspección eliminada.";
    }

    elseif ($accion === 'crear_requisicion_area') {
        $resultado = procesarCrearRequisicionArea($db, 'Calidad');
        if ($resultado['ok']) { $message = $resultado['mensaje']; } else { $error = $resultado['mensaje']; }
    }

    // Crear Auditoría SGI: una fecha -> un número autonumerado (YYYY-NN) + un renglón por cada
    // uno de los 11 departamentos (ver includes/auditoria_sgi.php).
    elseif ($accion === 'crear_auditoria_sgi') {
        require_once 'includes/auditoria_sgi.php';
        $auditoria_fecha = sanitize($_POST['fecha'] ?? '');
        if (!$auditoria_fecha) {
            $error = 'La fecha es requerida.';
        } else {
            $auditoria_numero = auditoriaSgiCalcularNumero($db, $auditoria_fecha);
            $db->beginTransaction();
            $db->prepare("INSERT INTO auditorias_sgi (numero, fecha, creado_por) VALUES (?, ?, ?)")
               ->execute([$auditoria_numero, $auditoria_fecha, $_SESSION['user_id']]);
            $nuevaAuditoriaId = (int)$db->lastInsertId();
            $stmtInsDepto = $db->prepare("INSERT INTO auditorias_sgi_departamentos (auditoria_id, departamento) VALUES (?, ?)");
            foreach (array_keys(AUDITORIA_SGI_DEPARTAMENTOS) as $depto) {
                $stmtInsDepto->execute([$nuevaAuditoriaId, $depto]);
            }
            $db->commit();
            header("Location: calidad.php?seccion=auditoria_sgi&id=$nuevaAuditoriaId");
            exit();
        }
    }

    if (empty($error)) {
        // tab=mis: tras generar una requisición, abre directo en "Mis Requisiciones"
        // para que el mensaje de éxito (que vive en esa pestaña) se vea de una vez.
        $tab_extra = $accion === 'crear_requisicion_area' ? '&tab=mis' : '';
        header("Location: calidad.php?seccion=$seccion$tab_extra" . ($message ? "&msg=".urlencode($message) : ''));
        exit();
    }
}

if (!empty($_GET['msg'])) $message = htmlspecialchars($_GET['msg']);

// ── Datos por sección ───────────────────────────────────────────────────────
$datos = [];

if ($seccion === 'aprobacion_produccion') {
    // Calidad solo ve las OTs que Producción ya aprobó primero (produccion_aprobacion='aprobado') —
    // si Producción todavía no la revisa, o la rechazó, Calidad no la puede ver ni actuar sobre ella.
    $stmt = $db->query("SELECT ot.*, p.nombre as proyecto_nombre, bl.pieza_nombre
        FROM ordenes_trabajo ot
        LEFT JOIN proyectos p ON p.id = ot.proyecto_id
        LEFT JOIN boom_listas bl ON bl.id = ot.boom_lista_id
        WHERE ot.estado = 'completado' AND ot.calidad_aprobacion = 'pendiente' AND ot.produccion_aprobacion = 'aprobado'
        ORDER BY ot.fecha_creacion DESC");
    $datos = $stmt->fetchAll();
}

elseif ($seccion === 'aprobacion_materiales') {
    // Reemplaza "Aprobación OC (Almacén)" (2026-09-17) -- Calidad ya no aprueba antes de que
    // Almacén reciba, ahora revisa cada material por separado después de recibido (numero_serie
    // capturado en almacen2.php?seccion=recepcion_pedidos), agrupado por proyecto para no perder
    // esa vista (pedido explícito del usuario).
    $mat_filtro_proy = intval($_GET['proyecto_id'] ?? 0);
    $mat_proyectos_sel = $db->query("SELECT DISTINCT p.id, p.nombre
        FROM proyectos p JOIN almacen_items ai ON ai.proyecto_id = p.id
        WHERE ai.cantidad_asignada > 0 ORDER BY p.nombre")->fetchAll();

    $sqlMatPend = "SELECT ai.*, m.descripcion AS mat_desc, m.codigo AS mat_codigo, m.marca, m.modelo,
                          c.nombre AS categoria, p.nombre AS proyecto_nombre
                   FROM almacen_items ai
                   LEFT JOIN materiales m ON m.id = ai.material_id
                   LEFT JOIN categorias c ON c.id = m.categoria_id
                   LEFT JOIN proyectos p ON p.id = ai.proyecto_id
                   WHERE ai.cantidad_asignada > 0 AND ai.calidad_aprobacion = 'pendiente'";
    if ($mat_filtro_proy) $sqlMatPend .= " AND ai.proyecto_id = $mat_filtro_proy";
    $sqlMatPend .= " ORDER BY (c.nombre IN ('Equipo','Instrumento','Componente Principal')) DESC, ai.fecha_agregado DESC";
    $materiales_pendientes = $db->query($sqlMatPend)->fetchAll();
}

elseif ($seccion === 'fat') {
    // Flujo 2026-09-17 (reemplaza el de aprobar/rechazar por Producción/Calidad/Proyectos):
    // Ingeniería genera el Protocolo FAT, Calidad es quien sube el documento realmente firmado
    // -- ya no hay gate de aprobación de por medio, así que el JOIN es incondicional (antes solo
    // mostraba el documento una vez "aprobado por Producción").
    $stmt = $db->query("SELECT pf.*, p.nombre as proyecto_nombre,
        ot.titulo as ot_titulo, bl.pieza_nombre as ot_pieza,
        upr.nombre_completo as firma_proyectos_nombre,
        fp.archivo_subido_ruta as fp_archivo_ruta, fp.archivo_subido_nombre as fp_archivo_nombre,
        fp.orden_produccion_id as fp_orden_produccion_id,
        fp.revision_estado as fp_revision_estado
        FROM pruebas_fat pf
        LEFT JOIN proyectos p ON p.id = pf.proyecto_id
        LEFT JOIN ordenes_trabajo ot ON ot.id = pf.orden_trabajo_id
        LEFT JOIN boom_listas bl ON bl.id = ot.boom_lista_id
        LEFT JOIN usuarios upr ON upr.id = pf.firma_proyectos_por
        -- Enlazado por Orden de Producción del proyecto, NO por pruebas_fat_id -- ese campo solo
        -- se fija al guardar datos de Etapa 2 en fat_protocolo.php, así que un FAT subido antes
        -- de guardar esos datos se quedaba invisible aquí (bug real reportado 2026-09-20, ver
        -- produccion.php para el detalle).
        LEFT JOIN fat_protocolos fp ON fp.orden_produccion_id = (
            SELECT op.id FROM ordenes_produccion op WHERE op.proyecto_id = pf.proyecto_id ORDER BY op.id DESC LIMIT 1
        )
        WHERE pf.estado IN ('pendiente_calidad','completado') OR fp.archivo_subido_ruta IS NOT NULL
        ORDER BY FIELD(pf.estado,'pendiente_calidad','completado'), pf.fecha_creacion DESC");
    $datos = $stmt->fetchAll();
}

elseif ($seccion === 'auditoria_sgi') {
    // Repositorio de Auditorías SGI (2026-09-21, reemplaza por completo el scaffold de la Fase
    // 59 -- ver includes/auditoria_sgi.php para el detalle de los 11 departamentos y la
    // generación de documentos).
    require_once 'includes/auditoria_sgi.php';
    $auditoria_sgi_id = intval($_GET['id'] ?? 0);
    $auditoria_sgi_actual = null;
    $auditoria_sgi_deptos = [];
    $auditorias_sgi_lista = [];
    if ($auditoria_sgi_id) {
        $stmtAudSgi = $db->prepare("SELECT * FROM auditorias_sgi WHERE id = ?");
        $stmtAudSgi->execute([$auditoria_sgi_id]);
        $auditoria_sgi_actual = $stmtAudSgi->fetch();
        if ($auditoria_sgi_actual) {
            $stmtAudSgiDep = $db->prepare("SELECT ad.*, u.nombre_completo AS subido_por_nombre
                FROM auditorias_sgi_departamentos ad
                LEFT JOIN usuarios u ON u.id = ad.subido_por
                WHERE ad.auditoria_id = ? ORDER BY ad.id ASC");
            $stmtAudSgiDep->execute([$auditoria_sgi_id]);
            $auditoria_sgi_deptos = $stmtAudSgiDep->fetchAll();
        }
    } else {
        $auditorias_sgi_lista = $db->query("SELECT a.*, u.nombre_completo AS creado_por_nombre,
                (SELECT COUNT(*) FROM auditorias_sgi_departamentos WHERE auditoria_id = a.id AND archivo_escaneado_ruta IS NOT NULL) AS completados,
                (SELECT COUNT(*) FROM auditorias_sgi_departamentos WHERE auditoria_id = a.id) AS total_deptos
            FROM auditorias_sgi a
            LEFT JOIN usuarios u ON u.id = a.creado_por
            ORDER BY a.fecha DESC")->fetchAll();
    }
}

elseif ($seccion === 'trazabilidad') {
    // Movido de Almacén a Calidad, 2026-08-05, a petición del usuario. Historia
    // completa de UN material (comprado -> recibido -> asignado/usado), armada
    // solo con datos que ya existen -- no requiere ningún campo nuevo. Junta 3
    // fuentes: 1) OCs que lo incluyeron (aunque no se haya recibido todavía),
    // 2) sus entradas/salidas del ledger unificado (mismas resoluciones de
    // origen/destino que ya usan Entradas/Salidas en Almacén), 3) series/lote
    // individuales cuando el material sí tiene ese seguimiento.
    $traz_material_id = intval($_GET['material_id'] ?? 0);

    // Landing: navegación por proyecto (mismo patrón que "Inventario por Proyecto" de
    // Producción, 2026-09-17 a petición del usuario) en vez de buscar entre los ~3700
    // materiales del catálogo completo -- Calidad ya sabe de qué proyecto quiere
    // trazabilidad, no de qué material suelto.
    $traz_proyectos_con_inventario = [];
    $traz_items_por_proyecto       = [];
    if (!$traz_material_id) {
        $traz_proyectos_con_inventario = $db->query("SELECT DISTINCT p.id, p.nombre
            FROM proyectos p
            INNER JOIN almacen_items ai ON ai.proyecto_id = p.id
            ORDER BY p.nombre")->fetchAll();

        $traz_items_raw = $db->query("SELECT ai.*, m.codigo as material_codigo, m.unidad_medida
            FROM almacen_items ai
            LEFT JOIN materiales m ON m.id = ai.material_id
            WHERE ai.material_id IS NOT NULL
            ORDER BY ai.proyecto_id, ai.item")->fetchAll();

        foreach ($traz_items_raw as $it) {
            $traz_items_por_proyecto[$it['proyecto_id']][] = $it;
        }
    }

    $traz_material       = null;
    $traz_eventos        = [];
    $traz_ocs            = [];
    $traz_series         = [];
    $traz_requisiciones  = [];
    $traz_proyectos      = [];
    $traz_incidencias    = [];

    if ($traz_material_id) {
        $stmtTMat = $db->prepare("SELECT * FROM materiales WHERE id = ?");
        $stmtTMat->execute([$traz_material_id]);
        $traz_material = $stmtTMat->fetch();

        if ($traz_material) {
            $stmtTEv = $db->prepare("SELECT h.*,
                    p.nombre as proveedor_nombre, oc.folio as oc_folio,
                    u.nombre_completo as usuario_nombre,
                    ot.folio as ot_folio, ot.titulo as ot_titulo, py.nombre as proyecto_nombre,
                    rq_directa.folio as req_folio_directa,
                    py_ai.nombre as ai_proyecto_nombre, rq_ai.folio as ai_req_folio,
                    py_oc.nombre as oc_proy_nombre, rq_oc.folio as oc_req_folio,
                    py_sync.nombre as sync_proy_nombre,
                    GROUP_CONCAT(DISTINCT sl.numero_serie SEPARATOR ', ') as series
                FROM historial_movimientos h
                LEFT JOIN proveedores p ON p.id = h.proveedor_id
                LEFT JOIN ordenes_compra oc ON oc.id = h.referencia_id AND h.referencia_tipo IN ('entrada_almacen', 'orden_compra', 'orden_compra_rechazada')
                LEFT JOIN usuarios u ON u.id = h.usuario_id
                LEFT JOIN ordenes_trabajo ot ON ot.id = h.referencia_id AND h.referencia_tipo = 'ot'
                LEFT JOIN proyectos py ON py.id = h.referencia_id AND h.referencia_tipo = 'proyecto'
                LEFT JOIN requisiciones rq_directa ON rq_directa.id = h.referencia_id AND h.referencia_tipo = 'requisicion'
                LEFT JOIN almacen_items ai ON ai.id = h.referencia_id AND h.referencia_tipo = 'almacen_item'
                LEFT JOIN proyectos py_ai ON py_ai.id = ai.proyecto_id
                LEFT JOIN requisiciones rq_ai ON rq_ai.id = ai.requisicion_id
                LEFT JOIN proyectos py_oc ON py_oc.id = h.referencia_id AND h.referencia_tipo = 'orden_compra_proyecto'
                LEFT JOIN requisiciones rq_oc ON rq_oc.id = h.referencia_id AND h.referencia_tipo = 'orden_compra_requisicion'
                LEFT JOIN proyectos py_sync ON py_sync.id = h.referencia_id AND h.referencia_tipo = 'sync_bom_removido'
                LEFT JOIN series_lotes sl ON (sl.movimiento_entrada_id = h.id OR sl.movimiento_salida_id = h.id)
                WHERE h.material_id = ?
                GROUP BY h.id
                ORDER BY h.fecha_movimiento ASC");
            $stmtTEv->execute([$traz_material_id]);
            $traz_eventos = $stmtTEv->fetchAll();

            $stmtTOC = $db->prepare("SELECT oc.id, oc.folio, oc.estado, oc.fecha_creacion,
                    pv.nombre as proveedor_nombre, ocd.cantidad, ocd.precio_unitario
                FROM orden_compra_detalle ocd
                JOIN ordenes_compra oc ON oc.id = ocd.orden_id
                LEFT JOIN proveedores pv ON pv.id = oc.proveedor_id
                WHERE ocd.material_id = ?
                ORDER BY oc.fecha_creacion ASC");
            $stmtTOC->execute([$traz_material_id]);
            $traz_ocs = $stmtTOC->fetchAll();

            $stmtTSL = $db->prepare("SELECT sl.*, py.nombre as proyecto_nombre, ot.folio as ot_folio
                FROM series_lotes sl
                LEFT JOIN proyectos py ON py.id = sl.proyecto_id
                LEFT JOIN ordenes_trabajo ot ON ot.id = sl.ot_id
                WHERE sl.material_id = ?
                ORDER BY sl.fecha_ingreso DESC");
            $stmtTSL->execute([$traz_material_id]);
            $traz_series = $stmtTSL->fetchAll();

            // Requisiciones que pidieron este material -- quién (usuario/área) y cuándo.
            $stmtTReq = $db->prepare("SELECT r.folio, r.area, r.solicitante, r.proyecto, r.estado,
                    r.fecha_creacion, u.nombre_completo as usuario_nombre, rd.cantidad
                FROM requisicion_detalle rd
                JOIN requisiciones r ON r.id = rd.requisicion_id
                LEFT JOIN usuarios u ON u.id = r.usuario_id
                WHERE rd.material_id = ?
                ORDER BY r.fecha_creacion ASC");
            $stmtTReq->execute([$traz_material_id]);
            $traz_requisiciones = $stmtTReq->fetchAll();

            // En qué proyectos se ha requerido, con el desglose por departamento
            // (cantidad_ingenieria/cantidad_produccion) que ya arma "Items del proyecto".
            $stmtTProy = $db->prepare("SELECT ai.*, py.nombre as proyecto_nombre
                FROM almacen_items ai
                JOIN proyectos py ON py.id = ai.proyecto_id
                WHERE ai.material_id = ?
                ORDER BY ai.fecha_agregado ASC");
            $stmtTProy->execute([$traz_material_id]);
            $traz_proyectos = $stmtTProy->fetchAll();

            // Incidencias de recepción (mal estado, entrega incompleta, etc.) de las OCs que
            // trajeron este material -- vía orden_compra_detalle, no aproximado por fecha.
            $stmtTInc = $db->prepare("SELECT pi.*, pv.nombre as proveedor_nombre, py.nombre as proyecto_nombre, oc.folio as oc_folio
                FROM proveedor_incidencias pi
                JOIN ordenes_compra oc ON oc.id = pi.orden_compra_id
                JOIN orden_compra_detalle ocd ON ocd.orden_id = oc.id AND ocd.material_id = ?
                LEFT JOIN proveedores pv ON pv.id = pi.proveedor_id
                LEFT JOIN proyectos py ON py.id = pi.proyecto_id
                GROUP BY pi.id
                ORDER BY pi.fecha DESC");
            $stmtTInc->execute([$traz_material_id]);
            $traz_incidencias = $stmtTInc->fetchAll();
        }
    }
}

// Contadores para badges del dashboard
$cnt_ot  = (int)$db->query("SELECT COUNT(*) FROM ordenes_trabajo WHERE estado='completado' AND calidad_aprobacion='pendiente'")->fetchColumn();
$cnt_oc  = (int)$db->query("SELECT COUNT(*) FROM almacen_items WHERE cantidad_asignada > 0 AND calidad_aprobacion='pendiente'")->fetchColumn();
$cnt_fat = (int)$db->query("SELECT COUNT(*) FROM pruebas_fat WHERE estado='pendiente_calidad'")->fetchColumn();
// Departamentos de Auditoría SGI todavía sin documento escaneado subido (2026-09-21, reemplaza
// el badge viejo de "historial de actividad por proyecto").
$cnt_ins = (int)$db->query("SELECT COUNT(*) FROM auditorias_sgi_departamentos WHERE archivo_escaneado_ruta IS NULL")->fetchColumn();

// Variables globales para el modal Nueva Inspección (disponible en todas las secciones)
if (!isset($ocs_disponibles)) {
    $ocs_disponibles = $db->query("SELECT oc.id, oc.folio, p.nombre as proveedor_nombre FROM ordenes_compra oc LEFT JOIN proveedores p ON p.id = oc.proveedor_id ORDER BY oc.fecha_creacion DESC LIMIT 100")->fetchAll();
}
if (!isset($proyectos_sel)) {
    $proyectos_sel = $db->query("SELECT id, nombre FROM proyectos ORDER BY nombre")->fetchAll();
}
if (!isset($inspectores)) {
    $inspectores = $db->query("SELECT id, nombre_completo FROM usuarios WHERE activo=1 ORDER BY nombre_completo")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<link rel="icon" href="favicon.ico?v=3">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calidad — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
input,textarea,select { -webkit-user-select:text; user-select:text; }
body { font-family:'Montserrat',sans-serif; background:#f4f7fb; }

.top-header {
    position:fixed; top:0; left:0; width:100%; height:80px;
    background:#2E5CAE;
    box-shadow:0 2px 10px rgba(46,92,174,.25);
    border-bottom:2px solid #c9a227;
    z-index:1000; display:flex; align-items:center;
    justify-content:space-between; padding:0 30px;
}
/* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
.header-logo { height:66px; width:auto; object-fit:contain; }
.header-right { display:flex; align-items:center; gap:20px; }
.back-button-header { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3);
    border-radius:40px; padding:8px 20px; font-weight:600; color:#fff; font-size:13px;
    text-decoration:none; transition:all .2s; }
.back-button-header:hover { background:rgba(255,255,255,.25); }

/* User dropdown — igual a ingenieria.php */
.user-dropdown { position:relative; display:inline-block; }
.user-trigger { display:flex; align-items:center; gap:12px; background:rgba(255,255,255,.15);
    padding:5px 15px 5px 5px; border-radius:40px; cursor:pointer;
    border:1px solid rgba(255,255,255,.3); transition:all .2s; }
.user-trigger:hover { background:rgba(255,255,255,.25); border-color:rgba(255,255,255,.5); }
.user-avatar { width:35px; height:35px; border-radius:50%; background:#FF9800;
    display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px; color:#fff; }
.user-info-text { display:flex; flex-direction:column; }
.user-name { font-weight:600; color:#fff; font-size:13px; line-height:1.2; }
.user-role { font-size:10px; color:rgba(255,255,255,.8); text-transform:uppercase; }
.user-dropdown-menu { position:absolute; top:calc(100% + 5px); right:0; width:200px;
    background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.15);
    opacity:0; visibility:hidden; transform:translateY(-5px);
    transition:all .2s; border:1px solid #e0e0e0; overflow:hidden; z-index:1000; }
.user-dropdown:hover .user-dropdown-menu { opacity:1; visibility:visible; transform:translateY(0); }
.dropdown-item { padding:12px 20px; display:flex; align-items:center; gap:10px;
    color:#333; transition:all .2s; font-size:13px; text-decoration:none; }
.dropdown-item:hover { background:#f5f5f5; color:#f44336; }
.dropdown-item i { width:18px; color:#666; }


/* Cards grid */
.cards-grid { display:flex; gap:24px; justify-content:center; align-items:stretch;
    flex-wrap:wrap; max-width:880px; margin:0 auto; }
.nav-card { width:190px; cursor:pointer; transition:all .3s ease; text-align:center;
    position:relative; background:#fff; border-radius:20px; padding:24px 16px 20px;
    box-shadow:0 4px 18px rgba(0,0,0,.08); border:2px solid #e8ecf4; }
.nav-card:hover { transform:translateY(-8px); box-shadow:0 12px 32px rgba(0,86,134,.18); border-color:#c8daf0; }
.nav-card:active { transform:scale(.96); }
.nav-card:hover .card-title { color:#FF9800; }
/* Badge — z-index alto para que no quede detrás del ícono */
.nav-badge { position:absolute; top:12px; right:12px; background:#e53935; color:#fff;
    border-radius:20px; font-size:.68rem; font-weight:800; padding:3px 9px;
    min-width:24px; text-align:center; z-index:20; box-shadow:0 2px 6px rgba(229,57,53,.35); }
/* Ícono — tamaño fijo centrado, no ocupa todo el ancho */
.card-icon { width:90px; height:90px; background:linear-gradient(135deg,#f0f4f9,#e8eef6);
    border-radius:18px; display:flex; align-items:center; justify-content:center;
    margin:0 auto 16px; border:2px solid #dde6f0; transition:all .3s ease;
    position:relative; overflow:hidden; }
.card-icon::before { content:''; position:absolute; top:0; left:-100%; width:100%; height:100%;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent); transition:left .5s ease; }
.nav-card:hover .card-icon::before { left:100%; }
.nav-card:hover .card-icon { border-color:#FF9800; background:linear-gradient(135deg,#fff8f0,#fff3e0);
    box-shadow:0 6px 18px rgba(255,152,0,.2); }
.card-icon i { font-size:2.6rem; color:#1B2D4E; transition:all .3s ease; z-index:2; }
.nav-card:hover .card-icon i { color:#FF9800; transform:scale(1.1); }
.card-title { font-size:1rem; font-weight:700; color:#1B2D4E; transition:color .3s ease; margin-bottom:5px; }
.card-desc  { font-size:.76rem; color:#888; line-height:1.4; }

/* Footer */
.dashboard-footer { position:fixed; bottom:0; left:0; right:0; display:flex;
    justify-content:space-between; align-items:center; padding:15px 40px;
    border-top:2px solid #e0e0e0; background:#fff; height:60px; z-index:1000; }
.footer-left { display:flex; gap:30px; color:#555; font-size:13px; font-weight:500; }
.footer-left i { color:#003d5c; width:16px; margin-right:5px; }
.footer-right img { height:28px; }

.app-container { padding-top:80px; padding-bottom:70px; min-height:100vh; }
.section-content { padding:24px 30px; }

/* Page header */
.main-center { min-height:calc(100vh - 140px); display:flex; flex-direction:column;
    justify-content:flex-start; align-items:center; padding:20px 20px 100px 20px; }
.page-header { text-align:center; margin-bottom:40px; }
.page-title { font-size:2.2rem; color:#003d5c; font-weight:300; margin-bottom:10px; }
.page-title strong { font-weight:800; background:linear-gradient(135deg,#003d5c,#0077b3);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
    background-clip:text; font-size:2.4rem; }
.page-subtitle { color:#666; font-size:1rem; }

/* Dashboard cards */
.dash-grid { display:flex; flex-wrap:wrap; gap:20px; justify-content:center; padding:40px 20px; }
.dash-card {
    background:#fff; border-radius:14px; padding:28px 24px; width:220px; text-align:center;
    cursor:pointer; box-shadow:0 2px 12px rgba(0,0,0,.07); border:2px solid #e8ecf4;
    transition:all .2s; position:relative;
}
.dash-card:hover { transform:translateY(-4px); box-shadow:0 8px 24px rgba(0,86,134,.15); border-color:#005686; }
.dash-card-icon { font-size:2.8rem; color:#1B2D4E; margin-bottom:14px; }
.dash-card-title { font-size:1rem; font-weight:700; color:#1B2D4E; margin-bottom:4px; }
.dash-card-desc  { font-size:.75rem; color:#888; }
.dash-badge { position:absolute; top:12px; right:12px; background:#e53935; color:#fff;
    border-radius:20px; font-size:.7rem; font-weight:800; padding:2px 8px; min-width:22px; text-align:center; }
.dash-badge.ok { background:#2e7d32; }

/* Secciones internas — mismo patrón que produccion.php */
.section-content { padding:0 30px 30px; }
.sticky-container { position:sticky; top:0; background:#fff; z-index:90;
    padding:20px 0; margin-bottom:20px; border-bottom:2px solid #e0e0e0; }
.header-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.header-row h2 { font-size:1.4rem; color:#003d5c; display:flex; align-items:center; gap:10px; font-weight:700; }

/* Cards de items — estilo OT de produccion.php */
.item-card { background:#fafcff; border:1.5px solid #e0e8f0; border-radius:12px;
    padding:16px 20px; margin-bottom:14px; display:flex; justify-content:space-between;
    align-items:flex-start; flex-wrap:wrap; gap:14px;
    border-left:4px solid #1565c0; transition:box-shadow .15s; }
.item-card:hover { box-shadow:0 4px 16px rgba(0,86,134,.1); }
.item-card.tipo-oc { border-left-color:#FF9800; }
.item-title { font-weight:700; font-size:.95rem; color:#003d5c; margin-bottom:6px; }
.item-meta  { font-size:.76rem; color:#666; display:flex; flex-wrap:wrap; gap:10px; }
.item-meta span { display:flex; align-items:center; gap:4px; }
.item-meta i { color:#1B2D4E; width:13px; }
.item-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; flex-shrink:0; }
.nota-input { border:1.5px solid #d5e0ea; border-radius:8px; padding:7px 12px;
    font-size:.8rem; font-family:'Montserrat',sans-serif; color:#333; width:180px;
    transition:border-color .15s; }
.nota-input:focus { outline:none; border-color:#005686; background:#f8fbff; }

.btn-aprobar  { background:#2e7d32; color:#fff; border:none; border-radius:8px;
    padding:8px 18px; font-size:.8rem; font-weight:700; cursor:pointer;
    display:inline-flex; align-items:center; gap:6px; transition:background .15s; white-space:nowrap; }
.btn-aprobar:hover { background:#1b5e20; }
.btn-rechazar { background:#c62828; color:#fff; border:none; border-radius:8px;
    padding:8px 18px; font-size:.8rem; font-weight:700; cursor:pointer;
    display:inline-flex; align-items:center; gap:6px; transition:background .15s; white-space:nowrap; }
.btn-rechazar:hover { background:#7f0000; }
.btn-volver { background:#fff; border:1.5px solid #d0dce8; border-radius:8px;
    padding:8px 18px; font-size:.8rem; font-weight:600; color:#555; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.btn-volver:hover { background:#f0f4f8; }

/* Tags de materiales en OC */
.mat-tag { display:inline-block; background:#f0f4f8; border-radius:4px;
    padding:2px 8px; margin:2px; font-size:.73rem; color:#444; }

/* Alert */
.alert { padding:10px 16px; border-radius:8px; margin-bottom:16px; font-size:.83rem; font-weight:600; }
.alert-success { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
.alert-error   { background:#ffebee; color:#b71c1c; border:1px solid #ef9a9a; }
.alert-warning { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }

/* Inspecciones */
.stats-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.stat-box { background:#fff; border:1.5px solid #e0e8f0; border-radius:10px;
    padding:12px 18px; display:flex; align-items:center; gap:12px; flex:1; min-width:140px; }
.stat-val { font-size:1.4rem; font-weight:800; color:#1B2D4E; }
.stat-lbl { font-size:.72rem; color:#888; }
.badge-res { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.badge-aprobado    { background:#e8f5e9; color:#2e7d32; }
.badge-rechazado   { background:#ffebee; color:#b71c1c; }
.badge-condicionado{ background:#fff3e0; color:#e65100; }
.badge-pendiente   { background:#f5f5f5; color:#757575; }
.insp-card { background:#fff; border:1.5px solid #e0e8f0; border-radius:10px;
    padding:12px 16px; margin-bottom:10px; }
.insp-card.resultado-rechazado   { border-left:4px solid #b71c1c; }
.insp-card.resultado-aprobado    { border-left:4px solid #2e7d32; }
.insp-card.resultado-condicionado{ border-left:4px solid #e65100; }

/* FAT */
.fat-card { background:#fff; border:1.5px solid #e0e8f0; border-radius:10px;
    padding:14px 18px; margin-bottom:12px; }
.fat-firma-box { border:1.5px solid #e0e0e0; border-radius:8px; padding:10px 14px; margin-top:10px; }
.fat-firma-box.activo { border-color:#ff9800; background:#fff8e1; }
.fat-firma-box.firmado { border-color:#a5d6a7; background:#f1f8e9; }

/* Modal */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2000; justify-content:center; align-items:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:24px; width:90%; max-width:560px; max-height:90vh; overflow-y:auto; }
.modal-title { font-size:1rem; font-weight:800; color:#1B2D4E; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
.form-group { margin-bottom:14px; }
.form-label { display:block; font-size:.78rem; font-weight:700; color:#555; margin-bottom:5px; text-transform:uppercase; letter-spacing:.3px; }
.form-input { width:100%; border:1.5px solid #d5e0ea; border-radius:8px; padding:8px 12px;
    font-family:'Montserrat',sans-serif; font-size:.83rem; color:#333; }
.form-input:focus { outline:none; border-color:#005686; }
.btn-primary { background:#005686; color:#fff; border:none; border-radius:8px; padding:9px 20px; font-size:.83rem; font-weight:700; cursor:pointer; }
.btn-outline  { background:#fff; color:#555; border:1.5px solid #ccc; border-radius:8px; padding:8px 18px; font-size:.83rem; font-weight:600; cursor:pointer; }

/* ── Trazabilidad (movido de Almacén, 2026-08-05) ── */
.btn { display:inline-flex; align-items:center; gap:8px; text-decoration:none; transition:all .2s; }
.btn-sm { padding:6px 12px; font-size:12px; }
.info-table { width:100%; border-collapse:collapse; margin-bottom:20px; border:2px solid #333; }
.info-table td, .info-table th { border:1px solid #333; padding:8px 12px; font-size:14px; }
.info-table td:first-child { background:#f0f0f0; font-weight:normal; width:30%; }
.content-panel { height:calc(100vh - 280px); overflow-y:auto; padding-right:10px; }
.stock-badge { display:inline-block; padding:3px 8px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-success { background:#d4edda; color:#155724; }
.badge-warning { background:#fff3cd; color:#856404; }

/* Carpetas por proyecto (mismo patrón que "Inventario por Proyecto" en produccion.php) */
.inventario-layout { display:flex; height:calc(100vh - 280px); overflow:hidden; }
.carpetas-panel { width:220px; min-width:220px; border-right:1px solid #e0e0e0; background:#f8f9fa; display:flex; flex-direction:column; overflow:hidden; }
.carpetas-header { padding:12px 15px; background:#003d5c; color:white; font-size:13px; font-weight:600; display:flex; justify-content:space-between; align-items:center; }
.carpetas-list { flex:1; overflow-y:auto; padding:8px 0; }
.carpeta-item { padding:8px 15px; cursor:pointer; display:flex; align-items:center; gap:8px; font-size:13px; color:#333; border-left:3px solid transparent; transition:all .15s; }
.carpeta-item:hover { background:#e9ecef; }
.carpeta-item.active { background:#e3f2fd; border-left-color:#003d5c; color:#003d5c; font-weight:600; }
.carpeta-item i { color:#FF9800; width:16px; }
.carpetas-inventario-content { flex:1; overflow:hidden; display:flex; }

/* Filtros */
.filtros { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.filtro-btn { padding:5px 14px; border-radius:20px; border:1.5px solid #ddd; background:#fff;
    color:#555; font-size:.75rem; font-weight:700; cursor:pointer; font-family:'Montserrat',sans-serif; }
.filtro-btn.active, .filtro-btn:hover { background:#1B2D4E; color:#fff; border-color:#1B2D4E; }

/* Empty */
.empty-state { text-align:center; padding:60px 20px; color:#aaa; }
.empty-state i { font-size:3rem; display:block; margin-bottom:12px; color:#ccc; }
/* Campana de notificaciones — mismo patrón/clases que produccion.php */
.empty-chat { text-align:center; padding:40px 20px; color:#999; }
.empty-chat i { font-size:48px; margin-bottom:15px; color:#ddd; }
.notification-item { background:#e8f4fc; border-left:4px solid #FF9800; padding:12px; margin-bottom:10px; border-radius:8px; cursor:pointer; }
.notification-item:hover { background:#e0eef8; transform:translateX(3px); }
.notif-unread { background:#fff8e1 !important; border-left-color:#FF9800 !important; font-weight:600; }
.notif-unread::after { content:''; display:inline-block; width:8px; height:8px; background:#FF9800; border-radius:50%; float:right; margin-top:4px; }
.notification-title { font-weight:600; font-size:13px; color:#003d5c; margin-bottom:5px; }
.notification-message { font-size:12px; color:#666; }
.notification-time { font-size:10px; color:#999; margin-top:5px; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="top-header">
    <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
    <div class="header-right">
        <?php if ($seccion !== 'dashboard'): ?>
        <a href="calidad.php" class="back-button-header"><i class="fas fa-arrow-left"></i> Volver al Menú</a>
        <?php endif; ?>

        <div style="position:relative;">
            <div id="notifBellBtn" onclick="toggleNotifHeaderDropdown()" style="position:relative;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer;flex-shrink:0;transition:background .2s;" onmouseenter="this.style.background='rgba(255,255,255,.28)'" onmouseleave="this.style.background='rgba(255,255,255,.15)'">
                <i class="fas fa-bell"></i>
                <span id="notifBellBadge" style="position:absolute;top:-4px;right:-4px;background:#e53935;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:.7rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #005686;">0</span>
            </div>
            <div id="notifHeaderDropdown" style="display:none;position:absolute;top:52px;right:0;z-index:9999;width:380px;max-height:70vh;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.3);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #eee;">
                    <span style="font-size:12px;font-weight:700;color:#005686;"><i class="fas fa-check-double"></i> Notificaciones de Calidad</span>
                    <div style="display:flex;gap:6px;">
                        <button onclick="marcarTodasLeidasCalidad()" class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                            <i class="fas fa-check-double"></i> Marcar leído
                        </button>
                        <button onclick="borrarNotificacionesCalidad()" class="btn btn-sm" style="background:#ffebee;color:#c62828;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                            <i class="fas fa-trash"></i> Borrar
                        </button>
                    </div>
                </div>
                <div id="notifHeaderList" style="padding:6px;">
                    <div class="empty-chat">
                        <i class="fas fa-bell-slash"></i>
                        <p>No hay notificaciones</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="user-dropdown">
            <div class="user-trigger">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'],0,1)) ?></div>
                <div class="user-info-text">
                    <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                    <div class="user-role">Calidad</div>
                </div>
            </div>
            <div class="user-dropdown-menu">
                <?php if ($mostrar_boton_menu): ?>
                <a href="Menu.php" class="dropdown-item" style="color:#4CAF50;">
                    <i class="fas fa-arrow-left"></i> Volver al Menú de Perfiles
                </a>
                <div style="height:1px;background:#e0e0e0;margin:4px 0;"></div>
                <?php endif; ?>
                <a href="?logout=1" class="dropdown-item">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            </div>
        </div>
    </div>
</div>

<div class="app-container">

<?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<!-- ══ DASHBOARD ══════════════════════════════════════════════════════════ -->
<?php if ($seccion === 'dashboard'): ?>
<div class="main-center">
    <div class="page-header">
        <h1 class="page-title"><strong>Calidad</strong><br>Panel de Control</h1>
        <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
    </div>
    <div class="cards-grid">
        <div class="nav-card" onclick="location.href='?seccion=aprobacion_produccion'">
            <?php if ($cnt_ot > 0): ?><span class="nav-badge"><?= $cnt_ot ?></span><?php endif; ?>
            <div class="card-icon"><i class="fas fa-industry"></i></div>
            <div class="card-title">Aprobación Producción</div>
            <div class="card-desc">OTs completadas pendientes de revisión</div>
        </div>
        <div class="nav-card" onclick="location.href='?seccion=aprobacion_materiales'">
            <?php if ($cnt_oc > 0): ?><span class="nav-badge"><?= $cnt_oc ?></span><?php endif; ?>
            <div class="card-icon"><i class="fas fa-boxes"></i></div>
            <div class="card-title">Aprobación de Materiales</div>
            <div class="card-desc">Materiales recibidos pendientes de revisión</div>
        </div>
        <div class="nav-card" onclick="location.href='?seccion=fat'">
            <?php if ($cnt_fat > 0): ?><span class="nav-badge"><?= $cnt_fat ?></span><?php endif; ?>
            <div class="card-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="card-title">Pruebas FAT</div>
            <div class="card-desc">Firma de Calidad en pruebas FAT</div>
        </div>
        <div class="nav-card" onclick="location.href='?seccion=auditoria_sgi'">
            <?php if ($cnt_ins > 0): ?><span class="nav-badge"><?= $cnt_ins ?></span><?php endif; ?>
            <div class="card-icon"><i class="fas fa-microscope"></i></div>
            <div class="card-title">Auditoría</div>
            <div class="card-desc">Auditorías SGI por departamento</div>
        </div>
        <?php require_once 'includes/hoja_viajera.php'; renderHojaViajeraNavCard('calidad.php'); ?>
        <?php renderGenerarRequisicionNavCard('calidad.php'); ?>
        <div class="nav-card" onclick="location.href='?seccion=trazabilidad'">
            <div class="card-icon"><i class="fas fa-route"></i></div>
            <div class="card-title">Trazabilidad</div>
            <div class="card-desc">Historia completa de un material: comprado, recibido, usado</div>
        </div>
    </div>
</div>

<!-- ══ HOJA VIAJERA (Vo.Bo. de Calidad) ═══════════════════════════════════ -->
<?php elseif ($seccion === 'hoja_viajera'): ?>
<?php require_once 'includes/hoja_viajera.php'; renderHojaViajeraSeccion($db, 'calidad.php', 'calidad'); ?>

<?php elseif ($seccion === 'generar_requisicion'): ?>
<?php renderRequisicionesSeccion($db, 'calidad.php', 'accion', 'Calidad', 'calidad', $message); ?>

<!-- ══ APROBACIÓN PRODUCCIÓN ══════════════════════════════════════════════ -->
<?php elseif ($seccion === 'aprobacion_produccion'): ?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-industry"></i> Aprobación Producción</h2>
            <a href="calidad.php" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>
    <?php if (empty($datos)): ?>
    <div class="empty-state"><i class="fas fa-check-circle" style="color:#2e7d32;"></i><p>No hay OTs pendientes de aprobación.</p></div>
    <?php else: foreach ($datos as $ot): ?>
    <div class="item-card">
        <div>
            <div class="item-title"><?= htmlspecialchars($ot['folio'] ?? 'OT-'.$ot['id']) ?> — <?= htmlspecialchars($ot['titulo']) ?></div>
            <div class="item-meta">
                <?php if ($ot['proyecto_nombre']): ?><span><i class="fas fa-folder"></i><?= htmlspecialchars($ot['proyecto_nombre']) ?></span><?php endif; ?>
                <?php if ($ot['pieza_nombre']): ?><span><i class="fas fa-drafting-compass"></i><?= htmlspecialchars($ot['pieza_nombre']) ?></span><?php endif; ?>
                <?php if ($ot['maquina']): ?><span><i class="fas fa-cog"></i><?= htmlspecialchars($ot['maquina']) ?></span><?php endif; ?>
                <span><i class="fas fa-calendar"></i><?= date('d/m/Y', strtotime($ot['fecha_creacion'])) ?></span>
            </div>
        </div>
        <form method="POST" class="item-actions">
            <input type="hidden" name="accion" value="calidad_ot">
            <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
            <input type="text" name="nota" class="nota-input" placeholder="Nota (opcional)">
            <button type="submit" name="resultado" value="aprobado" class="btn-aprobar"
                onclick="return confirm('¿Aprobar esta OT?')"><i class="fas fa-check"></i> Aprobar</button>
            <button type="submit" name="resultado" value="rechazado" class="btn-rechazar"
                onclick="return confirm('¿Rechazar? Producción será notificada.')"><i class="fas fa-times"></i> Rechazar</button>
        </form>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ══ APROBACIÓN DE MATERIALES ════════════════════════════════════════════ -->
<?php elseif ($seccion === 'aprobacion_materiales'): ?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-boxes"></i> Aprobación de Materiales</h2>
            <a href="calidad.php" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <!-- Filtro por proyecto -- se conserva la vista separada por proyecto, pedido explícito
         del usuario ("no podemos dejar que se pierdan estas partes importantes"). -->
    <div style="margin-bottom:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <label style="font-size:.82rem;font-weight:700;color:#555;">Filtrar por proyecto:</label>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="seccion" value="aprobacion_materiales">
            <select name="proyecto_id" class="form-input" style="width:auto;min-width:200px;padding:6px 12px;font-size:.82rem;" onchange="this.form.submit()">
                <option value="">— Todos los proyectos —</option>
                <?php foreach ($mat_proyectos_sel as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $mat_filtro_proy==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (empty($materiales_pendientes)): ?>
    <div class="empty-state"><i class="fas fa-check-circle" style="color:#2e7d32;"></i><p>No hay materiales pendientes de aprobación<?= $mat_filtro_proy ? ' en este proyecto' : '' ?>.</p></div>
    <?php else: foreach ($materiales_pendientes as $mit):
        $esPrio = in_array($mit['categoria'], ['Equipo', 'Instrumento', 'Componente Principal'], true);
        $formId = 'matform_' . $mit['id'];
    ?>
    <div class="item-card tipo-oc">
        <div style="flex:1;">
            <div class="item-title">
                <?= htmlspecialchars($mit['mat_desc'] ?: $mit['item']) ?>
                <?php if ($esPrio): ?><span class="stock-badge badge-warning" style="background:#ffebee;color:#c62828;margin-left:6px;"><i class="fas fa-certificate"></i> Certificado obligatorio</span><?php endif; ?>
            </div>
            <div class="item-meta">
                <?php if ($mit['proyecto_nombre']): ?><span><i class="fas fa-folder"></i><?= htmlspecialchars($mit['proyecto_nombre']) ?></span><?php endif; ?>
                <?php if ($mit['mat_codigo']): ?><span><i class="fas fa-barcode"></i><?= htmlspecialchars($mit['mat_codigo']) ?></span><?php endif; ?>
                <?php if ($mit['categoria']): ?><span><i class="fas fa-tag"></i><?= htmlspecialchars($mit['categoria']) ?></span><?php endif; ?>
                <span><i class="fas fa-cubes"></i>Recibido: <?= (int)$mit['cantidad_asignada'] ?></span>
                <?php if ($mit['numero_serie']): ?><span><i class="fas fa-hashtag"></i>Serie: <?= htmlspecialchars($mit['numero_serie']) ?></span><?php endif; ?>
            </div>
        </div>
        <form method="POST" enctype="multipart/form-data" class="item-actions" style="flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="accion" value="calidad_material">
            <input type="hidden" name="item_id" value="<?= $mit['id'] ?>">
            <div style="display:flex;flex-direction:column;gap:2px;">
                <label style="font-size:.7rem;font-weight:700;color:#555;">¿Aplica certificado?</label>
                <div style="display:flex;gap:8px;">
                    <?php if ($esPrio): ?>
                    <input type="hidden" name="aplica_certificado" value="si">
                    <span style="font-size:.78rem;color:#c62828;font-weight:600;">Sí (obligatorio)</span>
                    <?php else: ?>
                    <label style="font-size:.78rem;"><input type="radio" name="aplica_certificado" value="si" onchange="document.getElementById('<?= $formId ?>_certfile').style.display='block'"> Sí</label>
                    <label style="font-size:.78rem;"><input type="radio" name="aplica_certificado" value="no" checked onchange="document.getElementById('<?= $formId ?>_certfile').style.display='none'"> No</label>
                    <?php endif; ?>
                </div>
            </div>
            <input type="file" id="<?= $formId ?>_certfile" name="certificado" accept=".pdf,.png,.jpg,.jpeg" class="nota-input"
                style="<?= $esPrio ? '' : 'display:none;' ?>">
            <?php if ($mit['certificado_ruta']): ?>
            <a href="uploads/<?= htmlspecialchars($mit['certificado_ruta']) ?>" target="_blank" class="btn-outline btn-sm"><i class="fas fa-paperclip"></i> Ver certificado actual</a>
            <?php endif; ?>
            <input type="text" name="nota" class="nota-input" placeholder="Nota (opcional)">
            <button type="submit" name="resultado" value="aprobado" class="btn-aprobar"
                onclick="return confirm('¿Aprobar este material?')"><i class="fas fa-check"></i> Aprobar</button>
            <button type="submit" name="resultado" value="rechazado" class="btn-rechazar"
                onclick="return confirm('¿Rechazar? Almacén y Compras serán notificados.')"><i class="fas fa-times"></i> Rechazar</button>
        </form>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ══ PRUEBAS FAT ═════════════════════════════════════════════════════════ -->
<?php elseif ($seccion === 'fat'): ?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-clipboard-check"></i> Pruebas FAT — Firma Calidad</h2>
            <a href="calidad.php" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>
<?php if (empty($datos)): ?>
<div class="empty-state"><i class="fas fa-clipboard-check"></i><p>No hay pruebas FAT pendientes de firma de Calidad.</p></div>
<?php else:
// pf.estado (cadena de firma digital vieja) ya no avanza -- dejó de ser el criterio real
// desde que las firmas son en físico (2026-08-30). El resto de $estado_label solo cubre
// datos legacy que todavía pudieran quedar en ese estado.
$estado_label = ['pendiente_calidad'=>'Pendiente firma Calidad','completado'=>'Completo'];
foreach ($datos as $fat):
    $checklist = json_decode($fat['checklist'] ?? '[]', true) ?? [];
    $ok_ch = count(array_filter($checklist, fn($i) => !empty($i['resultado'])));
    $es_turno = ($fat['estado'] === 'pendiente_calidad');
    $fat_disponible = !empty($fat['fp_archivo_ruta']);
    $fat_firmado    = $fat['fp_revision_estado'] === 'aprobado';
    $badge_texto = $fat_firmado ? 'Protocolo FAT firmado' : ($fat_disponible ? 'Protocolo FAT por firmar' : ($estado_label[$fat['estado']] ?? 'En proceso'));
    // Botón "PDF": solo si el Protocolo FAT subido es efectivamente un PDF -- ya no exporta
    // el checklist de Etapa 5 (código muerto quitado, ver api/vista_pdf_fat.php).
    $esPdfSubido = $fat_disponible && strtolower(pathinfo($fat['fp_archivo_ruta'], PATHINFO_EXTENSION)) === 'pdf';
?>
<div class="fat-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
        <div>
            <span style="font-size:.72rem;padding:2px 10px;border-radius:20px;font-weight:700;
                background:<?= $fat_disponible?'#e8f5e9':($es_turno?'#fff3e0':'#f5f5f5') ?>;
                color:<?= $fat_disponible?'#2e7d32':($es_turno?'#e65100':'#757575') ?>;">
                <?= $badge_texto ?>
            </span>
            <strong style="margin-left:8px;"><?= htmlspecialchars($fat['folio']) ?></strong> — <?= htmlspecialchars($fat['titulo']) ?>
            <div class="item-meta" style="margin-top:4px;">
                <?php if ($fat['ot_titulo']): ?><span><i class="fas fa-tasks"></i><?= htmlspecialchars($fat['ot_titulo']) ?><?= $fat['ot_pieza'] ? ' — '.$fat['ot_pieza'] : '' ?></span><?php endif; ?>
                <?php if ($fat['proyecto_nombre']): ?><span><i class="fas fa-folder"></i><?= htmlspecialchars($fat['proyecto_nombre']) ?></span><?php endif; ?>
                <?php if (count($checklist)>0): ?><span><i class="fas fa-list-check"></i><?= $ok_ch ?>/<?= count($checklist) ?> ítems</span><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <?php if ($fat['firma_proyectos_nombre']): ?>
            <span style="font-size:.72rem;padding:4px 10px;border-radius:20px;font-weight:700;background:#e8f5e9;color:#2e7d32;">
                <i class="fas fa-check"></i> Proyectos firmó — <?= htmlspecialchars($fat['firma_proyectos_nombre']) ?>
            </span>
            <?php endif; ?>
            <?php if ($esPdfSubido): ?>
            <button type="button" class="btn-aprobar" style="background:#666;" onclick="descargarPdfFat(<?= htmlspecialchars(json_encode($fat['fp_archivo_ruta']), ENT_QUOTES) ?>)"><i class="fas fa-file-pdf"></i> PDF</button>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($fat['fp_archivo_ruta']):
        $extFp = strtolower(pathinfo($fat['fp_archivo_ruta'], PATHINFO_EXTENSION));
        $esVisualFp = in_array($extFp, ['pdf', 'png', 'jpg', 'jpeg']);
    ?>
    <!-- Protocolo FAT -- Calidad ya no aprueba/rechaza (2026-09-17, reemplaza el flujo de
         Fase 57): sube directo el documento realmente firmado en la prueba física, que
         reemplaza el borrador de Ingeniería y queda fijo para Producción/Proyectos. -->
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e8ecf0;">
        <div style="font-size:.78rem;font-weight:700;color:#1B2D4E;margin-bottom:6px;"><i class="fas fa-file-shield"></i> Protocolo FAT<?= $fat_firmado ? ' (firmado)' : ' (borrador de Ingeniería)' ?></div>
        <?php if ($extFp === 'pdf'): ?>
        <iframe src="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" style="width:100%;height:85vh;border:1px solid #e0e8f0;border-radius:8px;margin-bottom:8px;"></iframe>
        <?php elseif (in_array($extFp, ['png','jpg','jpeg'])): ?>
        <img src="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" style="max-width:100%;max-height:85vh;display:block;border:1px solid #e0e8f0;border-radius:8px;margin-bottom:8px;">
        <?php else: ?>
        <p style="font-size:.8rem;color:#888;"><i class="fas fa-file-word"></i> Documento Word — vista previa no disponible todavía.</p>
        <?php endif; ?>
        <a href="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" target="_blank" class="btn-aprobar" style="background:#666;display:inline-flex;text-decoration:none;margin-bottom:10px;"><i class="fas fa-<?= $esVisualFp ? 'up-right-from-square' : 'download' ?>"></i> <?= htmlspecialchars($fat['fp_archivo_nombre'] ?? 'Ver archivo') ?></a>
        <div>
            <?php if ($fat_firmado): ?>
            <span style="font-size:.75rem;color:#2e7d32;font-weight:600;"><i class="fas fa-check-circle"></i> Documento firmado ya subido</span>
            <?php else: ?>
            <label class="btn-aprobar" style="background:#2e7d32;cursor:pointer;">
                <i class="fas fa-upload"></i> Subir documento firmado
                <input type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" style="display:none;" onchange="subirProtocoloFATCal(<?= (int)$fat['fp_orden_produccion_id'] ?>, this)">
            </label>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; endif; ?>
</div><!-- /section-content FAT -->

<!-- ══ AUDITORÍA SGI ═══════════════════════════════════════════════════════ -->
<?php elseif ($seccion === 'auditoria_sgi'): ?>
<div class="section-content">
<div class="sticky-container">
    <div class="header-row">
        <h2><i class="fas fa-microscope"></i> Auditoría SGI<?= $auditoria_sgi_actual ? ' — ' . htmlspecialchars($auditoria_sgi_actual['numero']) : '' ?></h2>
        <?php if ($auditoria_sgi_actual): ?>
        <a href="?seccion=auditoria_sgi" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver a la lista</a>
        <?php else: ?>
        <a href="calidad.php" class="btn-volver"><i class="fas fa-arrow-left"></i> Volver</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$auditoria_sgi_actual): ?>
<!-- ── Lista de auditorías creadas ── -->
<div style="margin-bottom:18px;">
    <button type="button" class="btn-outline" onclick="openModal('modalCrearAuditoriaSgi')"><i class="fas fa-plus"></i> Crear Auditoría</button>
</div>
<?php if (empty($auditorias_sgi_lista)): ?>
<div class="empty-state"><i class="fas fa-microscope"></i><p>Todavía no hay auditorías SGI creadas.</p></div>
<?php else: foreach ($auditorias_sgi_lista as $aud): ?>
<a href="?seccion=auditoria_sgi&id=<?= (int)$aud['id'] ?>" class="item-card" style="text-decoration:none;color:inherit;border-left-color:<?= $aud['completados']==$aud['total_deptos']?'#2e7d32':'#e65100' ?>;">
    <div>
        <div class="item-title">Auditoría <?= htmlspecialchars($aud['numero']) ?></div>
        <div class="item-meta">
            <span><i class="fas fa-calendar"></i><?= date('d/m/Y', strtotime($aud['fecha'])) ?></span>
            <?php if ($aud['creado_por_nombre']): ?><span><i class="fas fa-user"></i><?= htmlspecialchars($aud['creado_por_nombre']) ?></span><?php endif; ?>
        </div>
    </div>
    <span class="badge-res badge-<?= $aud['completados']==$aud['total_deptos']?'aprobado':'pendiente' ?>"><?= (int)$aud['completados'] ?>/<?= (int)$aud['total_deptos'] ?> completados</span>
</a>
<?php endforeach; endif; ?>

<?php else: ?>
<!-- ── Detalle: recuadros por departamento (mismo patrón visual que Indicadores) ── -->
<div style="font-size:.82rem;color:#666;margin-bottom:16px;">
    <i class="fas fa-calendar"></i> Fecha de la auditoría: <strong><?= date('d/m/Y', strtotime($auditoria_sgi_actual['fecha'])) ?></strong>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
    <?php foreach ($auditoria_sgi_deptos as $depto): $completo = !empty($depto['archivo_escaneado_ruta']); ?>
    <div style="background:#fff;border:1.5px solid #e0e8f0;border-radius:12px;padding:16px 18px;border-top:4px solid <?= $completo?'#2e7d32':'#e65100' ?>;">
        <div style="font-weight:700;color:#003d5c;font-size:.95rem;margin-bottom:4px;"><?= htmlspecialchars($depto['departamento']) ?></div>
        <div style="font-size:.72rem;color:#888;margin-bottom:12px;">
            <?php if ($completo): ?>
            <i class="fas fa-check-circle" style="color:#2e7d32;"></i> Subido<?= $depto['subido_por_nombre'] ? ' por ' . htmlspecialchars($depto['subido_por_nombre']) : '' ?><?= $depto['fecha_subida'] ? ' el ' . date('d/m/Y', strtotime($depto['fecha_subida'])) : '' ?>
            <?php else: ?>
            <i class="fas fa-hourglass-half"></i> Pendiente de documento escaneado
            <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <a href="api/auditoria_sgi_generar.php?depto_id=<?= (int)$depto['id'] ?>" class="btn-outline btn-sm" style="text-align:center;"><i class="fas fa-file-word"></i> Generar documento</a>
            <?php if ($completo): ?>
            <a href="uploads/<?= htmlspecialchars($depto['archivo_escaneado_ruta']) ?>" target="_blank" class="btn-outline btn-sm" style="text-align:center;"><i class="fas fa-eye"></i> Ver escaneado</a>
            <?php endif; ?>
            <label class="btn-outline btn-sm" style="text-align:center;cursor:pointer;">
                <i class="fas fa-upload"></i> <?= $completo ? 'Reemplazar escaneado' : 'Subir escaneado' ?>
                <input type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" style="display:none;" onchange="subirEscaneadoAuditoriaSgi(<?= (int)$depto['id'] ?>, this)">
            </label>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- /section-content auditoria_sgi -->

<?php elseif ($seccion === 'trazabilidad'): ?>
<!-- ── TRAZABILIDAD DE MATERIAL (movido de Almacén, 2026-08-05) ── -->
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-route"></i> Trazabilidad de Material</h2>
            <?php if ($traz_material): ?>
            <a href="?seccion=trazabilidad" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Volver a proyectos</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-panel">

        <?php if (!$traz_material_id || !$traz_material): ?>
        <!-- ── Navegación por proyecto (mismo patrón que Inventario por Proyecto de Producción) ── -->
        <div class="inventario-layout">
            <div class="carpetas-panel">
                <div class="carpetas-header">
                    <span><i class="fas fa-folder"></i> Proyectos</span>
                </div>
                <div style="padding:6px 8px;">
                    <input type="text" id="searchCarpetasTraz" placeholder="Buscar proyecto..." oninput="filtrarCarpetasTraz(this.value)"
                           style="width:100%;padding:5px 8px;border:1.5px solid #dde3ea;border-radius:6px;font-size:12px;font-family:'Montserrat',sans-serif;outline:none;">
                </div>
                <div class="carpetas-list" id="carpetasListTraz">
                    <?php if (empty($traz_proyectos_con_inventario)): ?>
                    <div style="padding:15px;color:#999;font-size:12px;">Ningún proyecto tiene inventario asignado todavía</div>
                    <?php else: foreach ($traz_proyectos_con_inventario as $tpci): ?>
                    <div class="carpeta-item" data-id="<?= (int)$tpci['id'] ?>" onclick="verInventarioTraz(<?= (int)$tpci['id'] ?>, this)">
                        <i class="fas fa-folder"></i> <?= htmlspecialchars($tpci['nombre']) ?>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="carpetas-inventario-content">
                <div style="flex:1;overflow-y:auto;padding:15px;">
                    <div id="trazProyectoVacio" style="text-align:center;color:#999;padding:60px 20px;">
                        <i class="fas fa-hand-pointer" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                        Selecciona un proyecto para ver sus materiales y su trazabilidad
                    </div>
                    <table class="info-table" id="trazProyectoTabla" style="display:none;">
                        <thead>
                            <tr><th>Código</th><th>Material</th><th>Marca</th><th>Modelo</th><th>Número de serie</th><th></th></tr>
                        </thead>
                        <tbody id="trazProyectoBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            var trazItemsPorProyecto = <?= json_encode($traz_items_por_proyecto ?? new stdClass(), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

            function filtrarCarpetasTraz(q) {
                q = q.toLowerCase().trim();
                document.querySelectorAll('#carpetasListTraz .carpeta-item').forEach(function(el) {
                    el.style.display = (!q || el.textContent.toLowerCase().includes(q)) ? '' : 'none';
                });
            }

            function verInventarioTraz(proyectoId, el) {
                document.querySelectorAll('#carpetasListTraz .carpeta-item').forEach(function(i) { i.classList.remove('active'); });
                if (el) el.classList.add('active');

                var items = trazItemsPorProyecto[proyectoId] || [];
                var body = document.getElementById('trazProyectoBody');
                body.innerHTML = '';

                function tdTexto(valor) {
                    var td = document.createElement('td');
                    td.textContent = valor;
                    return td;
                }

                items.forEach(function(it) {
                    if (!it.material_id) return;
                    var tr = document.createElement('tr');
                    tr.appendChild(tdTexto(it.material_codigo || '-'));
                    tr.appendChild(tdTexto(it.descripcion || it.item || '-'));
                    tr.appendChild(tdTexto(it.marca || '-'));
                    tr.appendChild(tdTexto(it.modelo || '-'));

                    var tdSerie = document.createElement('td');
                    var inpSerie = document.createElement('input');
                    inpSerie.type = 'text';
                    inpSerie.value = it.numero_serie || '';
                    inpSerie.placeholder = 'Sin asignar';
                    inpSerie.style.cssText = 'width:120px;padding:4px 6px;border:1px solid #ccc;border-radius:5px;font-size:.8rem;';
                    inpSerie.onchange = function() { guardarNumeroSerieTraz(it.id, inpSerie.value.trim(), inpSerie); };
                    tdSerie.appendChild(inpSerie);
                    tr.appendChild(tdSerie);

                    var tdBtn = document.createElement('td');
                    var a = document.createElement('a');
                    a.href = '?seccion=trazabilidad&material_id=' + it.material_id;
                    a.className = 'btn btn-outline btn-sm';
                    a.innerHTML = '<i class="fas fa-route"></i> Ver trazabilidad';
                    tdBtn.appendChild(a);
                    tr.appendChild(tdBtn);
                    body.appendChild(tr);
                });

                document.getElementById('trazProyectoVacio').style.display = items.length ? 'none' : 'block';
                document.getElementById('trazProyectoTabla').style.display = items.length ? 'table' : 'none';
                if (!items.length) {
                    document.getElementById('trazProyectoVacio').innerHTML = 'Este proyecto no tiene materiales asignados';
                }
            }

            async function guardarNumeroSerieTraz(itemId, numeroSerie, inputEl) {
                inputEl.disabled = true;
                const fd = new FormData();
                fd.append('item_id', itemId);
                fd.append('numero_serie', numeroSerie);
                try {
                    const r = await fetch('api/actualizar_numero_serie.php', { method: 'POST', body: fd });
                    const d = await r.json();
                    if (!d.success) alert('Error: ' + (d.error || 'desconocido'));
                } catch (e) {
                    alert('Error de conexión');
                } finally {
                    inputEl.disabled = false;
                }
            }
        </script>

        <?php else: ?>
        <!-- ── Encabezado del material ── -->
        <div style="background:#f0f4f8;border:1px solid #dce6f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
                <div style="font-size:1.05rem;font-weight:700;color:#003d5c;"><?= htmlspecialchars($traz_material['descripcion'] ?? '') ?></div>
                <div style="font-size:.8rem;color:#666;margin-top:2px;">
                    Código: <?= htmlspecialchars($traz_material['codigo'] ?: '-') ?> ·
                    Marca: <?= htmlspecialchars($traz_material['marca'] ?: '-') ?> ·
                    Modelo: <?= htmlspecialchars($traz_material['modelo'] ?: '-') ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:1.4rem;font-weight:700;color:#00838F;"><?= (int)$traz_material['stock_disponible'] ?></div>
                <div style="font-size:.72rem;color:#888;text-transform:uppercase;">Stock actual</div>
            </div>
        </div>

        <?php if (!empty($traz_requisiciones)): ?>
        <h3 style="font-size:.9rem;color:#003d5c;margin-bottom:8px;"><i class="fas fa-file-alt"></i> Requisiciones que lo pidieron</h3>
        <table class="info-table" style="margin-bottom:24px;">
            <thead><tr><th>Folio</th><th>Área</th><th>Solicitó</th><th>Proyecto</th><th>Cantidad</th><th>Estado</th><th>Fecha</th></tr></thead>
            <tbody>
                <?php foreach ($traz_requisiciones as $treq): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($treq['folio']) ?></strong></td>
                    <td><?= htmlspecialchars($treq['area'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($treq['usuario_nombre'] ?: $treq['solicitante'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($treq['proyecto'] ?: '-') ?></td>
                    <td><?= $treq['cantidad'] ?></td>
                    <td><span class="stock-badge badge-warning"><?= htmlspecialchars($treq['estado']) ?></span></td>
                    <td><?= date('d/m/Y', strtotime($treq['fecha_creacion'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($traz_proyectos)): ?>
        <h3 style="font-size:.9rem;color:#003d5c;margin-bottom:8px;"><i class="fas fa-folder-tree"></i> Uso por proyecto</h3>
        <table class="info-table" style="margin-bottom:24px;">
            <thead><tr><th>Proyecto</th><th>Requerido</th><th>Origen</th><th>Asignado</th></tr></thead>
            <tbody>
                <?php foreach ($traz_proyectos as $tpy):
                    $tp_req  = max(1, (int)$tpy['cantidad_requerida']);
                    $tp_ing  = (int)($tpy['cantidad_ingenieria'] ?? 0);
                    $tp_prod = (int)($tpy['cantidad_produccion'] ?? 0);
                    $tp_pct_ing  = round($tp_ing  / $tp_req * 100, 1);
                    $tp_pct_prod = round($tp_prod / $tp_req * 100, 1);
                    $tp_pct_sin  = max(0, 100 - $tp_pct_ing - $tp_pct_prod);
                ?>
                <tr>
                    <td><?= htmlspecialchars($tpy['proyecto_nombre']) ?></td>
                    <td><?= $tpy['cantidad_requerida'] ?></td>
                    <td>
                        <div title="Ingeniería: <?= $tp_ing ?> · Producción: <?= $tp_prod ?>"
                             style="display:flex;width:70px;height:9px;border-radius:5px;overflow:hidden;background:#eee;">
                            <?php if ($tp_pct_ing  > 0): ?><span style="width:<?= $tp_pct_ing ?>%;background:#005686;"></span><?php endif; ?>
                            <?php if ($tp_pct_prod > 0): ?><span style="width:<?= $tp_pct_prod ?>%;background:#BE893B;"></span><?php endif; ?>
                            <?php if ($tp_pct_sin  > 0): ?><span style="width:<?= $tp_pct_sin ?>%;background:#ccc;"></span><?php endif; ?>
                        </div>
                    </td>
                    <td><?= $tpy['cantidad_asignada'] ?? 0 ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($traz_ocs)): ?>
        <h3 style="font-size:.9rem;color:#003d5c;margin-bottom:8px;"><i class="fas fa-file-invoice-dollar"></i> Órdenes de compra que lo incluyeron</h3>
        <table class="info-table" style="margin-bottom:24px;">
            <thead><tr><th>OC</th><th>Proveedor</th><th>Cantidad</th><th>Precio unit.</th><th>Estado</th><th>Fecha</th></tr></thead>
            <tbody>
                <?php foreach ($traz_ocs as $toc): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($toc['folio']) ?></strong></td>
                    <td><?= htmlspecialchars($toc['proveedor_nombre'] ?: '-') ?></td>
                    <td><?= $toc['cantidad'] ?></td>
                    <td>$<?= number_format($toc['precio_unitario'] ?? 0, 2) ?></td>
                    <td><span class="stock-badge <?= $toc['estado'] === 'recibida' ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars($toc['estado']) ?></span></td>
                    <td><?= date('d/m/Y', strtotime($toc['fecha_creacion'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h3 style="font-size:.9rem;color:#003d5c;margin-bottom:8px;"><i class="fas fa-stream"></i> Línea de tiempo (entradas y salidas)</h3>
        <?php if (empty($traz_eventos)): ?>
        <p style="color:#999;padding:16px 0;">Este material no tiene movimientos registrados todavía.</p>
        <?php else: ?>
        <div style="border-left:2px solid #dce6f0;padding-left:18px;margin-left:6px;">
            <?php foreach ($traz_eventos as $tev):
                $es_entrada = in_array($tev['tipo_movimiento'], ['entrada', 'reingreso'], true);
                if ($es_entrada) {
                    if ($tev['oc_folio']) {
                        $origen_txt = 'Compra: OC ' . $tev['oc_folio'] . ($tev['proveedor_nombre'] ? ' — ' . $tev['proveedor_nombre'] : '');
                    } elseif ($tev['oc_proy_nombre']) {
                        $origen_txt = 'Recepción de OC — Proyecto: ' . $tev['oc_proy_nombre'];
                    } elseif ($tev['oc_req_folio']) {
                        $origen_txt = 'Recepción de OC — Requisición: ' . $tev['oc_req_folio'];
                    } elseif ($tev['sync_proy_nombre']) {
                        $origen_txt = 'Liberado del BOM — Proyecto: ' . $tev['sync_proy_nombre'];
                    } elseif ($tev['tipo_movimiento'] === 'reingreso') {
                        $origen_txt = 'Reingreso de material no utilizado';
                    } elseif ($tev['proveedor_nombre']) {
                        $origen_txt = 'Proveedor: ' . $tev['proveedor_nombre'];
                    } else {
                        $origen_txt = 'Entrada manual';
                    }
                } else {
                    if ($tev['ot_folio']) {
                        $origen_txt = 'OT ' . $tev['ot_folio'] . ($tev['ot_titulo'] ? ' — ' . $tev['ot_titulo'] : '');
                    } elseif ($tev['proyecto_nombre']) {
                        $origen_txt = 'Proyecto: ' . $tev['proyecto_nombre'];
                    } elseif ($tev['req_folio_directa']) {
                        $origen_txt = 'Requisición: ' . $tev['req_folio_directa'];
                    } elseif ($tev['ai_proyecto_nombre']) {
                        $origen_txt = 'Proyecto: ' . $tev['ai_proyecto_nombre'];
                    } elseif ($tev['ai_req_folio']) {
                        $origen_txt = 'Requisición: ' . $tev['ai_req_folio'];
                    } elseif (stripos($tev['observaciones'] ?? '', 'Destino:') !== false) {
                        $origen_txt = trim(substr($tev['observaciones'], stripos($tev['observaciones'], 'Destino:') + 8));
                    } else {
                        $origen_txt = 'Sin especificar';
                    }
                }
            ?>
            <div style="position:relative;padding-bottom:20px;">
                <div style="position:absolute;left:-24px;top:2px;width:12px;height:12px;border-radius:50%;background:<?= $es_entrada ? '#4CAF50' : '#f44336' ?>;border:2px solid #fff;box-shadow:0 0 0 1px <?= $es_entrada ? '#4CAF50' : '#f44336' ?>;"></div>
                <div style="font-size:.72rem;color:#888;"><?= date('d/m/Y H:i', strtotime($tev['fecha_movimiento'])) ?></div>
                <div style="font-size:.88rem;font-weight:600;color:<?= $es_entrada ? '#2e7d32' : '#c62828' ?>;">
                    <i class="fas fa-<?= $es_entrada ? 'arrow-down' : 'arrow-up' ?>"></i>
                    <?= $es_entrada ? 'Entrada' : 'Salida' ?> — <?= abs($tev['cantidad']) ?> <?= htmlspecialchars($traz_material['unidad_medida'] ?? '') ?>
                </div>
                <div style="font-size:.82rem;color:#333;"><?= htmlspecialchars($origen_txt) ?></div>
                <div style="font-size:.75rem;color:#888;">
                    <?= htmlspecialchars($tev['usuario_nombre'] ?: '-') ?>
                    <?php if ($tev['series']): ?> · Serie/lote: <?= htmlspecialchars($tev['series']) ?><?php endif; ?>
                    <?php if ($tev['observaciones']): ?> · <?= htmlspecialchars($tev['observaciones']) ?><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($traz_series)): ?>
        <h3 style="font-size:.9rem;color:#003d5c;margin:24px 0 8px;"><i class="fas fa-barcode"></i> Series / lotes individuales</h3>
        <table class="info-table">
            <thead><tr><th>Serie/Lote</th><th>Estado</th><th>Proyecto</th><th>OT</th><th>Ingreso</th><th>Salida</th></tr></thead>
            <tbody>
                <?php foreach ($traz_series as $tsl): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($tsl['numero_serie']) ?></strong><?= $tsl['lote'] ? ' (' . htmlspecialchars($tsl['lote']) . ')' : '' ?></td>
                    <td><span class="stock-badge <?= $tsl['estado'] === 'En Almacén' ? 'badge-success' : 'badge-warning' ?>"><?= htmlspecialchars($tsl['estado']) ?></span></td>
                    <td><?= htmlspecialchars($tsl['proyecto_nombre'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($tsl['ot_folio'] ?: '-') ?></td>
                    <td><?= $tsl['fecha_ingreso'] ? date('d/m/Y', strtotime($tsl['fecha_ingreso'])) : '-' ?></td>
                    <td><?= $tsl['fecha_salida'] ? date('d/m/Y', strtotime($tsl['fecha_salida'])) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($traz_incidencias)): ?>
        <h3 style="font-size:.9rem;color:#c62828;margin:24px 0 8px;"><i class="fas fa-exclamation-triangle"></i> Incidencias en recepción</h3>
        <table class="info-table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>OC</th><th>Proveedor</th><th>Proyecto</th><th>Descripción</th></tr></thead>
            <tbody>
                <?php
                $tipo_inc_labels = [
                    'llegada_tardia'     => ['Llegada tardía', '#f57c00'],
                    'entrega_incompleta' => ['Entrega incompleta', '#ef6c00'],
                    'mal_estado'         => ['Material en mal estado', '#c62828'],
                    'otro'               => ['Otro problema', '#616161'],
                ];
                foreach ($traz_incidencias as $tinc):
                    [$tinc_label, $tinc_color] = $tipo_inc_labels[$tinc['tipo']] ?? [$tinc['tipo'], '#616161'];
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($tinc['fecha'])) ?></td>
                    <td><span style="background:<?= $tinc_color ?>;color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;"><?= htmlspecialchars($tinc_label) ?></span></td>
                    <td><?= htmlspecialchars($tinc['oc_folio'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($tinc['proveedor_nombre'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($tinc['proyecto_nombre'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($tinc['descripcion'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
</div><!-- /app-container -->

<div class="dashboard-footer">
    <div class="footer-left">
        <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
        <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
    </div>
    <div class="footer-right">
        <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO">
    </div>
</div>

<!-- Modal: Nueva Inspección -->
<div class="modal-overlay" id="modalNuevaInspeccion">
<div class="modal-box" style="max-width:600px;">
    <div class="modal-title"><i class="fas fa-plus-circle"></i> Nueva Inspección</div>
    <!-- Modal huérfano -- nada en la UI actual llama a openModal('modalNuevaInspeccion'), pero se
         deja el formulario intacto por si se reactiva. El destino ya no puede ser
         "?seccion=inspecciones" (esa clave ahora es Auditoría SGI, 2026-09-21). -->
    <form method="POST" enctype="multipart/form-data" action="calidad.php">
        <input type="hidden" name="accion" value="registrar_inspeccion">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label class="form-label">Tipo</label>
                <select name="tipo_inspeccion" class="form-input">
                    <option value="recepcion">Recepción</option>
                    <option value="proceso">Proceso</option>
                    <option value="final">Final</option>
                    <option value="fat">Prueba FAT</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Resultado</label>
                <select name="resultado" class="form-input" id="sel_resultado" onchange="toggleNC()">
                    <option value="pendiente">Pendiente</option>
                    <option value="aprobado">Aprobado</option>
                    <option value="condicionado">Condicionado</option>
                    <option value="rechazado">Rechazado</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Orden de Compra</label>
                <select name="orden_compra_id" class="form-input">
                    <option value="">— Sin OC —</option>
                    <?php foreach ($ocs_disponibles as $oc): ?>
                    <option value="<?= $oc['id'] ?>"><?= htmlspecialchars($oc['folio']) ?><?= $oc['proveedor_nombre'] ? ' — '.$oc['proveedor_nombre'] : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Proyecto</label>
                <select name="proyecto_id" class="form-input">
                    <option value="">— Sin proyecto —</option>
                    <?php foreach ($proyectos_sel as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Cant. recibida</label>
                <input type="number" name="cantidad_recibida" class="form-input" step="any" min="0" placeholder="0">
            </div>
            <div class="form-group">
                <label class="form-label">Cant. aprobada</label>
                <input type="number" name="cantidad_aprobada" class="form-input" step="any" min="0" placeholder="0">
            </div>
            <div class="form-group">
                <label class="form-label">Inspector</label>
                <select name="inspector_id" class="form-input">
                    <?php foreach ($inspectores as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $u['id']==$_SESSION['user_id']?'selected':'' ?>><?= htmlspecialchars($u['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Fecha</label>
                <input type="datetime-local" name="fecha_inspeccion" class="form-input" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
        </div>
        <div class="form-group" id="nc_group" style="display:none;">
            <label class="form-label">Número de No Conformidad (NC) *</label>
            <input type="text" name="nc_numero" class="form-input" placeholder="Ej. NC-2026-001">
        </div>
        <div class="form-group">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-input" rows="2" placeholder="Observaciones opcionales..."></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Evidencia (imagen/PDF)</label>
            <input type="file" name="evidencia" class="form-input" accept="image/*,.pdf">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeModal('modalNuevaInspeccion')">Cancelar</button>
            <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Registrar</button>
        </div>
    </form>
</div>
</div>

<!-- Modal: Crear Auditoría SGI -->
<div class="modal-overlay" id="modalCrearAuditoriaSgi">
<div class="modal-box" style="max-width:420px;">
    <div class="modal-title"><i class="fas fa-plus-circle"></i> Crear Auditoría</div>
    <form method="POST" action="calidad.php?seccion=auditoria_sgi">
        <input type="hidden" name="accion" value="crear_auditoria_sgi">
        <div class="form-group">
            <label class="form-label">Fecha de la auditoría *</label>
            <input type="date" name="fecha" class="form-input" required>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeModal('modalCrearAuditoriaSgi')">Cancelar</button>
            <button type="submit" class="btn-primary"><i class="fas fa-check"></i> Crear</button>
        </div>
    </form>
</div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function toggleNC() {
    const v = document.getElementById('sel_resultado')?.value;
    const g = document.getElementById('nc_group');
    if (g) g.style.display = v === 'rechazado' ? '' : 'none';
}

// La firma digital de Pruebas FAT se quitó (2026-08-30, mismo criterio que produccion.php --
// las firmas ya son solo en físico, ver [[project_fat_protocolo]]). api/fat_firma.php y las
// columnas firma_*_ruta de pruebas_fat se dejan intactos por si algo más los usa.
// api/vista_pdf_fat.php (el checklist de Etapa 5 con casillas de firma que nunca se llenaban)
// se borró por completo (2026-09-21, código muerto) -- este botón ahora abre/imprime
// directamente el Protocolo FAT subido, solo se muestra si es un PDF real.
function descargarPdfFat(rutaArchivo) {
    let frame = document.getElementById('fatIframeImprimir');
    if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'fatIframeImprimir';
        frame.style.display = 'none';
        document.body.appendChild(frame);
    }
    frame.onload = function () { frame.contentWindow.focus(); frame.contentWindow.print(); };
    frame.src = 'uploads/' + rutaArchivo;
}

// Auditoría SGI: subir el documento escaneado/firmado en papel de un departamento (2026-09-21).
async function subirEscaneadoAuditoriaSgi(deptoId, inputEl) {
    if (!inputEl.files.length) return;
    inputEl.disabled = true;
    const fd = new FormData();
    fd.append('depto_id', deptoId);
    fd.append('archivo', inputEl.files[0]);
    try {
        const r = await fetch('api/auditoria_sgi_subir_escaneado.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) window.location.reload();
        else { alert('Error: ' + (d.error || 'desconocido')); inputEl.disabled = false; }
    } catch (e) {
        alert('Error de conexión');
        inputEl.disabled = false;
    }
}

// Calidad sube el documento realmente firmado (2026-09-17, reemplaza el aprobar/rechazar de
// Fase 57) -- mismo endpoint que antes usaba Producción para subir su archivo.
async function subirProtocoloFATCal(ordenProduccionId, inputEl) {
    if (!inputEl.files.length) return;
    inputEl.disabled = true;
    const fd = new FormData();
    fd.append('orden_produccion_id', ordenProduccionId);
    fd.append('depto', 'calidad');
    fd.append('fat_archivo', inputEl.files[0]);
    try {
        const r = await fetch('api/fat_protocolo_subir_archivo.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) window.location.reload();
        else { alert('Error: ' + (d.error || 'desconocido')); inputEl.disabled = false; }
    } catch (e) {
        alert('Error de conexión');
        inputEl.disabled = false;
    }
}

// ── Campana de notificaciones de Calidad — mismo patrón que produccion.php:
// lee la tabla `notificaciones` compartida y filtra solo los tipos que ya se
// mandan con notificarRoles(['calidad'], ...) en el resto de la app.
const CALIDAD_NOTIF_TIPOS = ['produccion_ot_aprobada', 'proyecto_cerrado', 'equipo_fabricado_completado', 'fat_firma', 'oc_recibida_calidad', 'fat_protocolo_generado', 'material_prioritario_recibido', 'orden_produccion_difundida', 'requisicion_actualizada'];

// Scaffold de Auditoría (2026-09-17) -- botón por sección, la generación real del .docx
// queda pendiente hasta que el usuario mande las especificaciones de cada documento (lo
// dejó explícitamente pendiente por ahora).
function generarDocumentoAuditoria(seccion) {
    alert('Generación de documento pendiente de especificación para "' + seccion + '".');
}

let _notifHeaderAbierta = false;

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function cargarNotificaciones() {
    fetch('includes/ajax_chat.php?action=obtener_notificaciones')
    .then(response => response.json())
    .then(data => {
        if (data.success && data.notificaciones) {
            const lista = data.notificaciones.filter(n => CALIDAD_NOTIF_TIPOS.includes(n.tipo));
            renderizarNotificaciones(lista);
        }
    });
}

function renderizarNotificaciones(notificaciones) {
    const container = document.getElementById('notifHeaderList');
    if (!container) return;

    if (!notificaciones || notificaciones.length === 0) {
        container.innerHTML = `
            <div class="empty-chat">
                <i class="fas fa-bell-slash"></i>
                <p>No hay notificaciones</p>
            </div>
        `;
        return;
    }

    const iconos = {
        'produccion_ot_aprobada':      '<i class="fas fa-clipboard-check"     style="color:#2e7d32"></i>',
        'proyecto_cerrado':            '<i class="fas fa-lock"                style="color:#2e7d32"></i>',
        'equipo_fabricado_completado': '<i class="fas fa-microchip"           style="color:#00695c"></i>',
        'fat_firma':                   '<i class="fas fa-clipboard-check"     style="color:#1565c0"></i>',
        'oc_recibida_calidad':         '<i class="fas fa-dolly"               style="color:#e65100"></i>',
        'fat_protocolo_generado':      '<i class="fas fa-file-shield"         style="color:#1565c0"></i>',
        'material_prioritario_recibido': '<i class="fas fa-triangle-exclamation" style="color:#c62828"></i>',
        'orden_produccion_difundida': '<i class="fas fa-broadcast-tower" style="color:#003d5c"></i>',
        'requisicion_actualizada': '<i class="fas fa-file-alt" style="color:#1565c0"></i>',
    };

    let html = '';
    notificaciones.forEach(notif => {
        const fecha = new Date(notif.fecha_creacion).toLocaleString();
        const icono = iconos[notif.tipo] || '<i class="fas fa-bell" style="color:#999"></i>';
        const leido = notif.leido == 1;
        html += `
            <div class="notification-item${leido ? '' : ' notif-unread'}" data-id="${Number(notif.id)}" data-link="${escapeHtml(notif.link || '')}">
                <div class="notification-title">${icono} ${escapeHtml(notif.titulo)}</div>
                <div class="notification-message">${escapeHtml(notif.mensaje)}</div>
                <div class="notification-time">${fecha}</div>
            </div>
        `;
    });
    container.innerHTML = html;
    container.querySelectorAll('.notification-item').forEach(el => {
        el.addEventListener('click', () => marcarNotificacion(el.dataset.id, el.dataset.link));
    });
}

function marcarNotificacion(id, link) {
    fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })
    .then(() => {
        if (link) {
            window.location.href = link;
        } else {
            cargarNotificaciones();
            actualizarBadgeNotifHeader();
        }
    });
}

async function marcarTodasLeidasCalidad() {
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const ids = res.notificaciones.filter(n => CALIDAD_NOTIF_TIPOS.includes(n.tipo) && n.leido == 0).map(n => n.id);
    await Promise.all(ids.map(id => fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificaciones();
    actualizarBadgeNotifHeader();
}

async function borrarNotificacionesCalidad() {
    if (!confirm('¿Borrar todas las notificaciones de Calidad? Esta acción no se puede deshacer.')) return;
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const ids = res.notificaciones.filter(n => CALIDAD_NOTIF_TIPOS.includes(n.tipo)).map(n => n.id);
    await Promise.all(ids.map(id => fetch('includes/ajax_chat.php?action=borrar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificaciones();
    actualizarBadgeNotifHeader();
}

function toggleNotifHeaderDropdown() {
    _notifHeaderAbierta = !_notifHeaderAbierta;
    const dd = document.getElementById('notifHeaderDropdown');
    dd.style.display = _notifHeaderAbierta ? 'block' : 'none';
    if (_notifHeaderAbierta) cargarNotificaciones();
}
document.addEventListener('click', function(e) {
    const dd = document.getElementById('notifHeaderDropdown');
    const btn = document.getElementById('notifBellBtn');
    if (_notifHeaderAbierta && dd && !dd.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
        _notifHeaderAbierta = false;
        dd.style.display = 'none';
    }
});

function actualizarBadgeNotifHeader() {
    fetch('includes/ajax_chat.php?action=obtener_notificaciones')
    .then(r => r.json())
    .then(data => {
        const badge = document.getElementById('notifBellBadge');
        if (!badge || !data.success) return;
        const noLeidas = (data.notificaciones || []).filter(n => n.leido == 0 && CALIDAD_NOTIF_TIPOS.includes(n.tipo)).length;
        if (noLeidas > 0) {
            badge.textContent = noLeidas > 99 ? '99+' : noLeidas;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    });
}

actualizarBadgeNotifHeader();
setInterval(actualizarBadgeNotifHeader, 15000);
</script>

</body>
</html>
