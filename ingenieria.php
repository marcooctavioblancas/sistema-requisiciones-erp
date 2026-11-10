<?php
require_once 'includes/config.php';
require_once 'includes/equipos_fabricados.php';
require_once 'includes/generar_requisicion.php';

$marcas_imagenes = [
    'adalet'          => 'uploads/logo/Marcas/ADALET.png',
    'adsen'           => 'uploads/logo/Marcas/Adsens.png',
    'adsens'          => 'uploads/logo/Marcas/Adsens.png',
    'ashcroft'        => 'uploads/logo/Marcas/ashcroft.png',
    'cmp'             => 'uploads/logo/Marcas/CMP (1).png',
    'dewit'           => 'uploads/logo/Marcas/dewit.png',
    'ds-lok by pmt'   => 'uploads/logo/Marcas/ds-lok.png',
    'ds-lok'          => 'uploads/logo/Marcas/ds-lok.png',
    'eaton'           => 'uploads/logo/Marcas/Eaton.png',
    'famaga'          => 'uploads/logo/Marcas/famaga.png',
    'farris'          => 'uploads/logo/Marcas/farris-logo.png',
    'hanson'          => 'uploads/logo/Marcas/HANSON2.png',
    'hy-lok'          => 'uploads/logo/Marcas/HYLOK.png',
    'hylok'           => 'uploads/logo/Marcas/HYLOK.png',
    'idec'            => 'uploads/logo/Marcas/IDEC.png',
    'klemsan'         => 'uploads/logo/Marcas/KLEMSAN.png',
    'power&genex'     => 'uploads/logo/Marcas/POWERGENEX.png',
    'powergenex'      => 'uploads/logo/Marcas/POWERGENEX.png',
    'ruelco'          => 'uploads/logo/Marcas/Ruelco.png',
    'seter'           => 'uploads/logo/Marcas/Seter.png',
    'superlok'        => 'uploads/logo/Marcas/superlok-logo.png',
    'topworx'         => 'uploads/logo/Marcas/topworks-logo.png',
    'valcontrols'     => 'uploads/logo/Marcas/VALCONTROLS.png',
    'warom'           => 'uploads/logo/Marcas/WAROM.png',
    'weldfit'         => 'uploads/logo/Marcas/Weldfit.png',
    'crouse-hinds'    => 'uploads/logo/Marcas/Eaton.png',
    'versa'           => 'uploads/logo/Marcas/VERSA.png',
    'wedmüller'       => 'uploads/logo/Marcas/weidmueller.png',
    'wedmuller'       => 'uploads/logo/Marcas/weidmueller.png',
];
function getMarcaImagen($marca, $map) {
    if (empty($marca)) return null;
    $key = mb_strtolower(trim($marca), 'UTF-8');
    return $map[$key] ?? null;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}

if (!isLoggedIn()) {
    redirect('index.php');
}

if (!hasAcceso('ingenieria') && !hasAcceso('admin')) {
    redirect('sin_acceso.php');
}

// Verificar si viene del menú de perfiles
$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;

$db = getDB();
$message = '';
$error = '';

// Crear tablas de plantillas si no existen
try {
    $db->exec("CREATE TABLE IF NOT EXISTS bom_plantillas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(255) NOT NULL,
        descripcion TEXT,
        creado_por INT,
        fecha_creacion DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bom_plantilla_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plantilla_id INT NOT NULL,
        material_id INT,
        descripcion TEXT,
        marca VARCHAR(100),
        modelo VARCHAR(100),
        cantidad DECIMAL(10,2) DEFAULT 1,
        certificados TEXT,
        observacion TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// Feature 6: helper para registrar cambios en boom_items_log
function logBoomItemIng($db, $boom_item_id, $boom_id, $proyecto_id, $usuario_id, $accion, $campo = null, $valor_anterior = null, $valor_nuevo = null) {
    try {
        $db->prepare("INSERT INTO boom_items_log (boom_item_id,boom_id,proyecto_id,usuario_id,accion,campo,valor_anterior,valor_nuevo) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$boom_item_id,$boom_id,$proyecto_id,$usuario_id,$accion,$campo,$valor_anterior,$valor_nuevo]);
    } catch (Exception $e) { /* tabla no migrada aún */ }
}

$categorias = getCategoriasActivas($db);
$proveedores = $db->query("SELECT * FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        
        case 'crear_proyecto':
            $nombre      = sanitize($_POST['nombre_proyecto']);
            $descripcion = sanitize($_POST['descripcion_proyecto'] ?? '');
            $fecha_inicio    = sanitize($_POST['fecha_inicio']    ?? '');
            $fecha_finiquito = sanitize($_POST['fecha_finiquito'] ?? '');

            try {
                $nuevo_id = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM proyectos")->fetchColumn();
                $stmt = $db->prepare(
                    "INSERT INTO proyectos (id, nombre, descripcion, creado_por, tipo, fecha_inicio, fecha_finiquito, fecha_creacion)
                     VALUES (?, ?, ?, ?, 'ingenieria', ?, ?, NOW())"
                );
                $stmt->execute([
                    $nuevo_id,
                    $nombre,
                    $descripcion,
                    $_SESSION['user_id'],
                    $fecha_inicio    ?: null,
                    $fecha_finiquito ?: null,
                ]);
            } catch (Exception $e) {
                $error = showMessage("Error al crear proyecto: " . $e->getMessage(), 'error');
                break;
            }

            try { registrarEventoCalendario($nuevo_id, $nombre, 'ingenieria'); } catch (Exception $e) {}
            try {
                notificarRoles(
                    ['almacen', 'inventario'],
                    'nuevo_proyecto',
                    "Nuevo proyecto: $nombre",
                    "El proyecto \"$nombre\" fue registrado. Estará disponible en cuanto Ingeniería finalice el BOM.",
                    'almacen2.php?seccion=proyectos'
                );
            } catch (Exception $e) {}

            // PRG: redirect a misma sección, evita duplicado al refrescar
            header('Location: ingenieria.php?seccion=proyectos&ok=' . urlencode("Proyecto $nombre creado correctamente"));
            exit();
            
        case 'editar_proyecto':
            $id = intval($_POST['id']);
            $nombre = sanitize($_POST['nombre_proyecto']);
            $descripcion = sanitize($_POST['descripcion_proyecto'] ?? '');

            try {
                $stmt = $db->prepare("UPDATE proyectos SET nombre = ?, descripcion = ? WHERE id = ?");
                $stmt->execute([$nombre, $descripcion, $id]);
                $message = showMessage("Proyecto actualizado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al editar proyecto: " . $e->getMessage(), 'error');
            }
            break;

        case 'cambiar_estado_proyecto':
            $id     = intval($_POST['id']);
            $estado = sanitize($_POST['estado']);
            $estados_validos = ['activo', 'en_pausa', 'finalizado', 'cancelado'];

            if (!in_array($estado, $estados_validos)) {
                $error = showMessage("Estado no válido", 'error');
                break;
            }

            try {
                $stmt_proy = $db->prepare("SELECT nombre, tipo FROM proyectos WHERE id = ?");
                $stmt_proy->execute([$id]);
                $proy_data = $stmt_proy->fetch();

                if (!$proy_data) {
                    $error = showMessage("Proyecto no encontrado", 'error');
                    break;
                }

                $db->prepare("UPDATE proyectos SET estado = ?, fecha_estado = NOW() WHERE id = ?")
                   ->execute([$estado, $id]);

                if (in_array($estado, ['finalizado', 'cancelado'])) {
                    registrarEventoCalendario($id, $proy_data['nombre'], $proy_data['tipo'], date('Y-m-d'));
                } elseif ($estado === 'activo') {
                    registrarEventoCalendario($id, $proy_data['nombre'], $proy_data['tipo']);
                }

                $message = showMessage("Estado actualizado a '$estado'", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al cambiar estado: " . $e->getMessage(), 'error');
            }
            break;

        case 'eliminar_proyecto':
            // Borra en cascada BOMs, OTs y requisiciones/OCs de OTROS departamentos --
            // el guard genérico de módulo (hasAcceso('ingenieria')) no bastaba para algo
            // tan destructivo. Se restringe a admin, igual que otras herramientas de borrado
            // masivo del sistema.
            if (!isAdmin()) {
                $error = showMessage("Solo un administrador puede eliminar un proyecto.", 'error');
                break;
            }
            $id = intval($_POST['id']);

            try {
                // Obtener nombre del proyecto para limpiar requisiciones vinculadas por nombre
                $stmt_nombre = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                $stmt_nombre->execute([$id]);
                $proyecto_nombre = $stmt_nombre->fetchColumn();

                // Limpieza adicional (cada uno en try-catch propio: si falla no bloquea el delete principal)
                $extras = [
                    "DELETE ol FROM ot_logs ol INNER JOIN ordenes_trabajo ot ON ol.ot_id = ot.id WHERE ot.proyecto_id = ?",
                    "DELETE oa FROM ot_actividades oa INNER JOIN ordenes_trabajo ot ON oa.orden_id = ot.id WHERE ot.proyecto_id = ?",
                    "DELETE op FROM ot_procesos op INNER JOIN ordenes_trabajo ot ON op.ot_id = ot.id WHERE ot.proyecto_id = ?",
                    "DELETE FROM ordenes_trabajo WHERE proyecto_id = ?",
                    "DELETE FROM calendario_eventos WHERE proyecto_id = ?",
                    "DELETE sm FROM subproyecto_materiales sm INNER JOIN subproyectos_manufactura sp ON sm.subproyecto_id = sp.id WHERE sp.proyecto_id = ?",
                    "DELETE FROM subproyectos_manufactura WHERE proyecto_id = ?",
                    // Los IDs de boom_listas no son AUTO_INCREMENT (se reasignan con MAX+1), así que
                    // si no se limpian sus plano_paginas al borrar el BOM/plano, un BOM nuevo puede
                    // heredar el mismo id y "adoptar" nombres de página y páginas ocultas viejas.
                    "DELETE pp FROM plano_paginas pp INNER JOIN boom_listas bl ON pp.boom_lista_id = bl.id WHERE bl.proyecto_id = ?",
                ];
                foreach ($extras as $sql) {
                    try { $db->prepare($sql)->execute([$id]); } catch (Exception $e) { /* columna inexistente en esta instalación — ignorar */ }
                }

                // BOM: boom_versiones se elimina en cascada por FK al borrar boom_listas
                $db->prepare("DELETE FROM boom_items WHERE proyecto_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM boom_listas WHERE proyecto_id = ?")->execute([$id]);

                // almacen_items no se borraba aquí: si un proyecto nuevo llegaba a reutilizar
                // este mismo id (proyectos.id no es AUTO_INCREMENT, se calcula con MAX(id)+1),
                // heredaba materiales "fantasma" de este proyecto ya borrado.
                $db->prepare("DELETE FROM almacen_items WHERE proyecto_id = ?")->execute([$id]);

                if ($proyecto_nombre) {
                    // Cascada: orden_compra_detalle → ordenes_compra → requisicion_detalle → requisiciones
                    $db->prepare("DELETE ocd FROM orden_compra_detalle ocd
                                   INNER JOIN ordenes_compra oc ON ocd.orden_id = oc.id
                                   INNER JOIN requisiciones r ON oc.requisicion_id = r.id
                                   WHERE r.proyecto = ?")->execute([$proyecto_nombre]);

                    $db->prepare("DELETE oc FROM ordenes_compra oc
                                   INNER JOIN requisiciones r ON oc.requisicion_id = r.id
                                   WHERE r.proyecto = ?")->execute([$proyecto_nombre]);

                    $db->prepare("DELETE rd FROM requisicion_detalle rd
                                   INNER JOIN requisiciones r ON rd.requisicion_id = r.id
                                   WHERE r.proyecto = ?")->execute([$proyecto_nombre]);

                    $db->prepare("DELETE FROM requisiciones WHERE proyecto = ?")->execute([$proyecto_nombre]);
                }

                $db->prepare("DELETE FROM proyectos WHERE id = ?")->execute([$id]);

                if (isset($_SESSION['proyecto_boom_activo']) && $_SESSION['proyecto_boom_activo'] == $id) {
                    unset($_SESSION['proyecto_boom_activo']);
                    unset($_SESSION['boom_lista_activo']);
                }

                $message = showMessage("Proyecto eliminado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al eliminar proyecto: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'crear_boom_lista':
            $proyecto_id = intval($_POST['proyecto_id']);
            $nombre_boom  = sanitize($_POST['nombre_boom'] ?? 'BOM Principal');
            $num_equipos  = max(1, intval($_POST['num_equipos'] ?? 1));

            if (proyectoCierreTotal($db, $proyecto_id)) {
                $error = showMessage("Este proyecto ya fue cerrado (todos sus equipos fueron fabricados) — Ingeniería quedó bloqueada de forma indefinida.", 'error');
                break;
            }

            try {
                // boom_listas.id ahora es AUTO_INCREMENT (antes MAX(id)+1 a mano).
                $stmt = $db->prepare("INSERT INTO boom_listas (proyecto_id, nombre, num_equipos, creado_por, fecha_creacion, guardado) VALUES (?, ?, ?, ?, NOW(), 0)");
                $stmt->execute([$proyecto_id, $nombre_boom, $num_equipos, $_SESSION['user_id']]);
                $boom_id = (int)$db->lastInsertId();

                $_SESSION['proyecto_boom_activo'] = $proyecto_id;
                $_SESSION['boom_lista_activo']    = $boom_id;

                // Get project name for notification
                $pn = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                $pn->execute([$proyecto_id]);
                $proy_nombre_notif = $pn->fetchColumn() ?: "ID $proyecto_id";

                notificarRoles(
                    ['almacen', 'inventario'],
                    'nuevo_boom',
                    "BOM en progreso: $proy_nombre_notif",
                    "El proyecto \"$proy_nombre_notif\" tiene un BOM en edición. Recibirás otra notificación cuando esté listo para asignación.",
                    'almacen2.php?seccion=proyectos'
                );

                header("Location: ?seccion=boom&proyecto_boom=$proyecto_id&boom_id=$boom_id");
                exit();
            } catch (Exception $e) {
                $error = showMessage("Error al crear BOM: " . $e->getMessage(), 'error');
            }
            break;

        case 'crear_plano':
            $proyecto_id  = intval($_POST['proyecto_id']);
            $pieza_nombre = sanitize($_POST['pieza_nombre'] ?? '');
            $obs_fab      = sanitize($_POST['obs_fabricacion'] ?? '');
            if (!$pieza_nombre) {
                $error = showMessage("El nombre de la pieza es requerido", 'error');
                break;
            }
            if (proyectoCierreTotal($db, $proyecto_id)) {
                $error = showMessage("Este proyecto ya fue cerrado (todos sus equipos fueron fabricados) — Ingeniería quedó bloqueada de forma indefinida.", 'error');
                break;
            }
            try {
                $plano_ruta = null;
                if (!empty($_FILES['plano']['name']) && $_FILES['plano']['error'] === 0) {
                    $upload = uploadPlano($_FILES['plano']);
                    if ($upload['success']) {
                        $plano_ruta = $upload['file'];
                    } else {
                        $error = showMessage($upload['message'], 'error');
                        break;
                    }
                }
                $db->prepare("INSERT INTO boom_listas (proyecto_id, nombre, num_equipos, creado_por, fecha_creacion, guardado, tipo_lista, pieza_nombre, plano_ruta, observacion_fab, estado_plano)
                              VALUES (?,?,1,?,NOW(),0,'plano',?,?,?,'enviado')")
                   ->execute([$proyecto_id, "Plano: $pieza_nombre", $_SESSION['user_id'], $pieza_nombre, $plano_ruta, $obs_fab]);
                $new_boom_id = (int)$db->lastInsertId();
                unset($_SESSION['proyecto_boom_activo'], $_SESSION['boom_lista_activo']);
                $pn = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                $pn->execute([$proyecto_id]);
                $proy_nombre_notif = $pn->fetchColumn() ?: "ID $proyecto_id";

                notificarRoles(
                    ['manufactura'],
                    'nuevo_plano',
                    "Nuevo plano: $pieza_nombre",
                    "Se subió el plano \"$pieza_nombre\" del proyecto \"$proy_nombre_notif\". Procesar BOM con IA en Producción.",
                    "produccion.php?seccion=subproyectos",
                    $_SESSION['user_id']
                );
                header("Location: ?seccion=proyectos&msg=" . urlencode("Plano \"$pieza_nombre\" creado correctamente."));
                exit();
            } catch (Exception $e) {
                $error = showMessage("Error al crear plano: " . $e->getMessage(), 'error');
            }
            break;

        case 'reemplazar_plano':
            $boom_id     = intval($_POST['boom_id']);
            $proyecto_id = intval($_POST['proyecto_id']);
            try {
                if (empty($_FILES['plano']['name']) || $_FILES['plano']['error'] !== 0) {
                    $error = showMessage("Debes subir un nuevo plano.", 'error');
                    break;
                }
                $upload = uploadPlano($_FILES['plano']);
                if (!$upload['success']) {
                    $error = showMessage($upload['message'], 'error');
                    break;
                }
                $db->prepare("UPDATE boom_listas SET plano_ruta=?, estado_plano='enviado', guardado=0, plano_comentario=NULL, plano_comentario_fecha=NULL, plano_comentario_por=NULL WHERE id=? AND tipo_lista='plano'")
                   ->execute([$upload['file'], $boom_id]);
                header("Location: ?seccion=proyectos&msg=" . urlencode("Plano reemplazado y enviado a revisión."));
                exit();
            } catch (Exception $e) {
                $error = showMessage("Error al reemplazar plano: " . $e->getMessage(), 'error');
            }
            break;

        case 'finalizar_proyecto':
            $id = intval($_POST['id']);
            try {
                $stmt_proy = $db->prepare("SELECT nombre, tipo FROM proyectos WHERE id = ?");
                $stmt_proy->execute([$id]);
                $proy_data = $stmt_proy->fetch();
                if (!$proy_data) { $error = showMessage("Proyecto no encontrado", 'error'); break; }

                // Antes había aquí un empuje paralelo de respaldo a almacen_items (INSERT
                // IGNORE crudo) que solo corría si almacen_items estaba en 0 para el proyecto
                // -- con 2+ BOMs y solo uno guardado, los materiales de los demás se perdían
                // en silencio (INSERT IGNORE descarta sin avisar si el material ya tenía fila
                // de otro BOM), y sin transacción ni las columnas cantidad_ingenieria/
                // cantidad_produccion que sí llena el camino real (guardar_boom ->
                // sincronizarAlmacenItem). Se quitó por completo: ahora la ÚNICA forma de
                // mandar materiales a Almacén es guardar_boom, y liberar el proyecto exige
                // que TODOS sus BOMs reales ya hayan pasado por ahí.
                $stmt_pend = $db->prepare("
                    SELECT nombre FROM boom_listas
                    WHERE proyecto_id = ? AND (tipo_lista IS NULL OR tipo_lista IN ('principal','adicional'))
                      AND (enviado_almacen IS NULL OR enviado_almacen = 0)
                    ORDER BY nombre
                ");
                $stmt_pend->execute([$id]);
                $boms_pendientes = $stmt_pend->fetchAll(PDO::FETCH_COLUMN);
                if ($boms_pendientes) {
                    $error = showMessage('No se puede liberar: falta guardar el BOM "' . implode('", "', $boms_pendientes) . '". Guárdalo primero para que sus materiales lleguen a Almacén.', 'error');
                    break;
                }

                $db->prepare("UPDATE proyectos SET estado = 'finalizado', fecha_estado = NOW() WHERE id = ?")
                   ->execute([$id]);
                registrarEventoCalendario($id, $proy_data['nombre'], $proy_data['tipo'], date('Y-m-d'));

                // Fecha real de entrega de ingeniería para el indicador DOC-GEN-FO-15 (ver includes/indicadores.php):
                // este es el único punto del sistema donde Ingeniería considera su paquete terminado y lo libera.
                $db->prepare("UPDATE proyecto_fases SET fecha_fin_real = COALESCE(fecha_fin_real, NOW()) WHERE proyecto_id = ? AND fase = 'ingenieria'")
                   ->execute([$id]);

                notificarRoles(['almacen','compras'],'proyecto_almacen',"Proyecto liberado: {$proy_data['nombre']}",
                    "El proyecto \"{$proy_data['nombre']}\" quedó liberado a Compras -- todos sus BOMs ya están guardados y sus materiales en Almacén.",
                    'almacen2.php?seccion=proyectos');

                $message = showMessage("Proyecto liberado a compras. El BOM quedó bloqueado y Almacén fue notificado.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al finalizar proyecto: " . $e->getMessage(), 'error');
            }
            break;

        case 'supervisor_unlock':
            // Solo admin puede desbloquear temporalmente un proyecto finalizado
            if (!isAdmin()) {
                $error = showMessage("Solo un administrador puede desbloquear proyectos finalizados.", 'error');
                break;
            }
            $id = intval($_POST['id']);
            $motivo = sanitize($_POST['motivo'] ?? '');
            if (proyectoCierreTotal($db, $id)) {
                $error = showMessage("Este proyecto tuvo su cierre total (todos sus equipos fueron fabricados) — quedó bloqueado de forma indefinida, ni el desbloqueo de administrador aplica.", 'error');
                break;
            }
            try {
                $stmt_proy = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                $stmt_proy->execute([$id]);
                $proy_nm = $stmt_proy->fetchColumn();
                // Desbloquear por 2 horas
                $expiry = date('Y-m-d H:i:s', strtotime('+2 hours'));
                try {
                    $db->prepare("UPDATE proyectos SET supervisor_unlock = 1, unlock_expiry = ?, unlock_by = ?, estado = 'activo' WHERE id = ?")
                       ->execute([$expiry, $_SESSION['user_id'], $id]);
                } catch (Exception $e2) {
                    // Columnas nuevas pueden no existir aún; actualizar solo estado
                    $db->prepare("UPDATE proyectos SET estado = 'activo' WHERE id = ?")->execute([$id]);
                }
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'supervisor_unlock', 'proyectos', ?, ?)")
                   ->execute([$_SESSION['user_id'], $id, "Proyecto desbloqueado por supervisor. Motivo: $motivo. Expira: $expiry"]);
                notificarRoles(
                    ['compras', 'almacen'],
                    'bom_modificado',
                    "BOM desbloqueado: $proy_nm",
                    "Un supervisor desbloqueó el BOM del proyecto \"$proy_nm\" para corrección. El BOM puede modificarse temporalmente.",
                    'ingenieria.php?seccion=proyectos'
                );
                $message = showMessage("Proyecto desbloqueado temporalmente hasta $expiry. Se notificó a compras y almacén.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al desbloquear: " . $e->getMessage(), 'error');
            }
            break;

        case 'marcar_doc_recibido':
            $doc_id = intval($_POST['doc_id']);
            try {
                $db->prepare("UPDATE proyecto_documentos SET recibido=1, fecha_recibido=NOW(), recibido_por=? WHERE id=?")
                   ->execute([$_SESSION['user_id'], $doc_id]);
                $message = showMessage("Documento marcado como recibido.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'agregar_documento':
            $pid_doc     = intval($_POST['proyecto_id_doc']);
            $nombre_doc  = sanitize($_POST['nombre_doc']    ?? '');
            $tipo_doc    = sanitize($_POST['tipo_doc']       ?? 'otro');
            $fase_doc    = sanitize($_POST['fase_doc']       ?? 'ingenieria');
            $requerido_d = intval($_POST['requerido_doc']    ?? 0);
            $revision_d  = sanitize($_POST['revision_doc']   ?? '');
            if (!$nombre_doc) { $error = showMessage("El nombre del documento es requerido.", 'error'); break; }
            try {
                $ruta_doc = null;
                if (!empty($_FILES['archivo_doc']['name']) && $_FILES['archivo_doc']['error'] === 0) {
                    $up = uploadPlano($_FILES['archivo_doc']);
                    if ($up['success']) $ruta_doc = $up['file'];
                }
                $db->prepare("INSERT INTO proyecto_documentos (proyecto_id, tipo, nombre, fase, requerido, revision, ruta_archivo, creado_por) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$pid_doc, $tipo_doc, $nombre_doc, $fase_doc, $requerido_d, $revision_d ?: null, $ruta_doc, $_SESSION['user_id']]);
                $message = showMessage("Documento registrado correctamente.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'supervisor_lock':
            // Volver a bloquear el proyecto
            if (!isAdmin()) {
                $error = showMessage("Solo un administrador puede realizar esta acción.", 'error');
                break;
            }
            $id = intval($_POST['id']);
            try {
                $stmt_proy = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                $stmt_proy->execute([$id]);
                $proy_nm = $stmt_proy->fetchColumn();
                try {
                    $db->prepare("UPDATE proyectos SET supervisor_unlock = 0, unlock_expiry = NULL, estado = 'finalizado' WHERE id = ?")->execute([$id]);
                } catch (Exception $e2) {
                    $db->prepare("UPDATE proyectos SET estado = 'finalizado' WHERE id = ?")->execute([$id]);
                }
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'supervisor_lock', 'proyectos', ?, ?)")
                   ->execute([$_SESSION['user_id'], $id, "Proyecto vuelto a bloquear por supervisor."]);
                $message = showMessage("Proyecto bloqueado nuevamente.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'eliminar_boom_lista':
            $boom_id = intval($_POST['boom_id']);

            try {
                $db->prepare("DELETE FROM boom_items WHERE boom_id = ?")->execute([$boom_id]);
                // Los ids de boom_listas se reasignan con MAX+1 (no son AUTO_INCREMENT): si no se
                // limpian sus plano_paginas aquí, un BOM/plano nuevo puede heredar el mismo id y
                // "adoptar" nombres de página y páginas ocultas (ignorada=1) de este BOM borrado.
                $db->prepare("DELETE FROM plano_paginas WHERE boom_lista_id = ?")->execute([$boom_id]);
                $db->prepare("DELETE FROM boom_listas WHERE id = ?")->execute([$boom_id]);

                if (isset($_SESSION['boom_lista_activo']) && $_SESSION['boom_lista_activo'] == $boom_id) {
                    unset($_SESSION['boom_lista_activo']);
                    unset($_SESSION['proyecto_boom_activo']);
                }

                $message = showMessage("BOM eliminado correctamente", 'success');
                header('Location: ?seccion=proyectos');
                exit();
            } catch (Exception $e) {
                $error = showMessage("Error al eliminar BOM: " . $e->getMessage(), 'error');
            }
            break;

        case 'guardar_boom':
            $boom_id     = intval($_POST['boom_id']);
            $proyecto_id = intval($_POST['proyecto_id']);
            $nombre_boom = sanitize($_POST['nombre_boom'] ?? 'BOM Principal');
            $num_equipos = max(1, intval($_POST['num_equipos'] ?? 1));

            if (proyectoCierreTotal($db, $proyecto_id)) {
                $error = showMessage("Este proyecto ya fue cerrado (todos sus equipos fueron fabricados) — Ingeniería quedó bloqueada de forma indefinida.", 'error');
                break;
            }

            try {
                // Bloquear solo si ESTE BOM ya fue liberado a almacén (no el proyecto completo)
                $stmt_env = $db->prepare("SELECT enviado_almacen, tipo_lista FROM boom_listas WHERE id = ?");
                $stmt_env->execute([$boom_id]);
                $boom_row_actual = $stmt_env->fetch();
                if ((int)($boom_row_actual['enviado_almacen'] ?? 0) === 1) {
                    $error = showMessage("Este BOM ya fue liberado. Desbloquéalo primero para poder editarlo.", 'error');
                    break;
                }

                if (boomTieneReemplazoPendiente($db, $boom_id)) {
                    $error = showMessage("Este BOM tiene un reemplazo de material pendiente de aprobación -- no se puede guardar hasta que se resuelva en Reemplazos de Material.", 'error');
                    break;
                }

                // Feature 5: Validaciones de integridad antes de guardar
                // Se valida el TOTAL de items del BOM (no solo los nuevos/sin guardar): un BOM
                // desfijado sin cambios debe poder re-guardarse (re-fijarse) igual, ya que no
                // agregar nada nuevo es un caso válido, no un BOM vacío.
                $val_items = $db->prepare("SELECT COUNT(*) FROM boom_items WHERE boom_id = ?");
                $val_items->execute([$boom_id]);
                if ($val_items->fetchColumn() < 1) {
                    $error = showMessage("El BOM no tiene items. Agrega al menos un material antes de guardar.", 'error');
                    break;
                }
                $val_qty = $db->prepare("SELECT COUNT(*) FROM boom_items WHERE boom_id = ? AND cantidad <= 0");
                $val_qty->execute([$boom_id]);
                if ($val_qty->fetchColumn() > 0) {
                    $error = showMessage("Hay items con cantidad 0 o negativa. Corrígelos antes de guardar.", 'error');
                    break;
                }
                $val_dup = $db->prepare("SELECT material_id,COUNT(*) c FROM boom_items WHERE boom_id = ? AND material_id IS NOT NULL GROUP BY material_id HAVING c > 1");
                $val_dup->execute([$boom_id]);
                if ($val_dup->fetch()) {
                    $error = showMessage("Hay materiales duplicados en el BOM. Consolida los items antes de guardar.", 'error');
                    break;
                }

                // Capturar los items nuevos (aún no guardados) ANTES de marcarlos, para saber
                // exactamente cuáles empujar a almacén — así un BOM re-liberado tras un
                // desbloqueo solo manda lo nuevo/editado, no todo de nuevo.
                $stmt_nuevos = $db->prepare("SELECT material_id, item, descripcion, modelo, marca, cantidad FROM boom_items WHERE boom_id = ? AND (guardado = 0 OR guardado IS NULL)");
                $stmt_nuevos->execute([$boom_id]);
                $items_nuevos_almacen = $stmt_nuevos->fetchAll();

                $db->beginTransaction();
                $db->prepare("UPDATE boom_listas SET guardado = 1, nombre = ?, num_equipos = ? WHERE id = ?")
                   ->execute([$nombre_boom, $num_equipos, $boom_id]);
                $db->prepare("UPDATE boom_items SET guardado = 1 WHERE boom_id = ? AND (guardado = 0 OR guardado IS NULL)")
                   ->execute([$boom_id]);

                // Empujar a almacén los items recién guardados de este BOM
                foreach ($items_nuevos_almacen as $it_alm) {
                    sincronizarAlmacenItem($db, $proyecto_id, [
                        'material_id' => $it_alm['material_id'] ?: null,
                        'item'        => $it_alm['item'] ?: $it_alm['descripcion'],
                        'descripcion' => $it_alm['descripcion'],
                        'modelo'      => $it_alm['modelo'] ?: '',
                        'marca'       => $it_alm['marca'] ?: '',
                        'cantidad'    => $it_alm['cantidad'] * $num_equipos,
                    ], $_SESSION['user_id'], 'ingenieria');
                }
                if (!empty($items_nuevos_almacen)) {
                    $db->prepare("UPDATE proyectos SET almacen_activo = 1 WHERE id = ?")->execute([$proyecto_id]);
                }
                // Fijar el BOM siempre que se guarde con éxito, incluso si no había items nuevos
                // que empujar a almacén (ej. se desfijó solo para revisar/editar un dato y no
                // hubo cambios) — de lo contrario el BOM queda desfijado sin forma de re-fijarlo.
                $db->prepare("UPDATE boom_listas SET enviado_almacen = 1 WHERE id = ?")->execute([$boom_id]);
                $db->commit();

                // Automático: solo las BOMs principales definen equipos -- adicional/plano/plano_ia
                // son materiales complementarios, no una tanda nueva de equipos que fabricar.
                if (($boom_row_actual['tipo_lista'] ?? 'principal') === 'principal') {
                    sincronizarEquiposFabricados($db, $proyecto_id, $_SESSION['user_id']);
                }

                if (isset($_SESSION['boom_lista_activo']) && $_SESSION['boom_lista_activo'] == $boom_id) {
                    unset($_SESSION['boom_lista_activo']);
                    unset($_SESSION['proyecto_boom_activo']);
                }

                // Count items saved
                $cnt = $db->prepare("SELECT COUNT(*), p.nombre FROM boom_items bi JOIN proyectos p ON p.id = bi.proyecto_id WHERE bi.boom_id = ?");
                $cnt->execute([$boom_id]);
                [$num_items_saved, $proy_nombre_notif] = $cnt->fetch(PDO::FETCH_NUM) ?: [0, "ID $proyecto_id"];

                notificarRoles(
                    ['almacen', 'inventario'],
                    'boom_guardado',
                    "Proyecto listo para asignación: $proy_nombre_notif",
                    "El proyecto \"$proy_nombre_notif\" tiene $num_items_saved material(es) listos. Pásalo a Almacén para iniciar la asignación de stock.",
                    'almacen2.php?seccion=proyectos'
                );
                notificarRoles(
                    ['compras'],
                    'boom_guardado',
                    "Materiales listos para cotizar: $proy_nombre_notif",
                    "El proyecto \"$proy_nombre_notif\" tiene $num_items_saved material(es) definidos y listos para solicitar cotización.",
                    'compras.php'
                );

                // Auto-actualizar avance de fase 'ingenieria' en proyecto_fases
                try {
                    $stmt_tb = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND (tipo_lista IS NULL OR tipo_lista NOT IN ('plano_ia'))");
                    $stmt_tb->execute([$proyecto_id]);
                    $total_bl = (int)$stmt_tb->fetchColumn();

                    $stmt_gb = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND guardado = 1 AND (tipo_lista IS NULL OR tipo_lista NOT IN ('plano_ia'))");
                    $stmt_gb->execute([$proyecto_id]);
                    $guard_bl = (int)$stmt_gb->fetchColumn();

                    $pct_ing    = $total_bl > 0 ? round($guard_bl / $total_bl * 100) : 0;
                    $estado_ing = $pct_ing >= 100 ? 'completado' : ($pct_ing > 0 ? 'en_proceso' : 'pendiente');

                    // Limpiar duplicados (registros con id=0 que quedaron de versiones anteriores)
                    $db->prepare("DELETE FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria' AND id = 0")
                       ->execute([$proyecto_id]);

                    // Verificar existencia usando COUNT (evita el problema de id=0 siendo falsy)
                    $stmt_chk = $db->prepare("SELECT COUNT(*) FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria'");
                    $stmt_chk->execute([$proyecto_id]);
                    $existe = (int)$stmt_chk->fetchColumn() > 0;

                    if ($existe) {
                        $db->prepare("UPDATE proyecto_fases SET pct_avance = ?, estado = ?, fecha_actualizacion = NOW(), actualizado_por = ? WHERE proyecto_id = ? AND fase = 'ingenieria'")
                           ->execute([$pct_ing, $estado_ing, $_SESSION['user_id'], $proyecto_id]);
                    } else {
                        $db->prepare("INSERT INTO proyecto_fases (proyecto_id, fase, nombre_display, pct_avance, estado, fecha_inicio, fecha_actualizacion, actualizado_por) VALUES (?, 'ingenieria', 'Ingeniería', ?, ?, CURDATE(), NOW(), ?)")
                           ->execute([$proyecto_id, $pct_ing, $estado_ing, $_SESSION['user_id']]);
                    }
                } catch (Exception $ef) { /* proyecto_fases puede no existir aún */ }

                $message = showMessage("BOM guardado correctamente.", 'success');
                header('Location: ?seccion=proyectos');
                exit();
            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $error = showMessage("Error al guardar BOM: " . $e->getMessage(), 'error');
            }
            break;

        case 'desbloquear_boom_individual':
            $boom_id = intval($_POST['boom_id']);
            try {
                $stmt_bp = $db->prepare("SELECT proyecto_id FROM boom_listas WHERE id = ?");
                $stmt_bp->execute([$boom_id]);
                $boom_proyecto_id = (int)$stmt_bp->fetchColumn();
                if (proyectoCierreTotal($db, $boom_proyecto_id)) {
                    $error = showMessage("Este proyecto ya fue cerrado (todos sus equipos fueron fabricados) — el BOM quedó bloqueado de forma indefinida.", 'error');
                    break;
                }
                $db->prepare("UPDATE boom_listas SET enviado_almacen = 0 WHERE id = ?")->execute([$boom_id]);
                $message = showMessage("BOM desbloqueado. Ya puedes editarlo.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al desbloquear: " . $e->getMessage(), 'error');
            }
            break;

        case 'recalcular_progreso_ing':
            try {
                $stmtPids  = $db->query("SELECT id FROM proyectos");
                $stmtTotal = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND (tipo_lista IS NULL OR tipo_lista NOT IN ('plano_ia'))");
                $stmtGuard = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND guardado = 1 AND (tipo_lista IS NULL OR tipo_lista NOT IN ('plano_ia'))");
                $stmtDel   = $db->prepare("DELETE FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria' AND id = 0");
                $stmtCnt   = $db->prepare("SELECT COUNT(*) FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria'");
                $stmtUpd   = $db->prepare("UPDATE proyecto_fases SET pct_avance=?, estado=?, fecha_actualizacion=NOW(), actualizado_por=? WHERE proyecto_id=? AND fase='ingenieria'");
                $stmtIns   = $db->prepare("INSERT INTO proyecto_fases (proyecto_id,fase,nombre_display,pct_avance,estado,fecha_inicio,fecha_actualizacion,actualizado_por) VALUES (?,'ingenieria','Ingeniería',?,?,CURDATE(),NOW(),?)");

                foreach ($stmtPids->fetchAll(PDO::FETCH_COLUMN) as $pid_r) {
                    $stmtTotal->execute([$pid_r]); $t = (int)$stmtTotal->fetchColumn();
                    $stmtGuard->execute([$pid_r]); $g = (int)$stmtGuard->fetchColumn();
                    $pct = $t > 0 ? round($g / $t * 100) : 0;
                    $est = $pct >= 100 ? 'completado' : ($pct > 0 ? 'en_proceso' : 'pendiente');
                    $stmtDel->execute([$pid_r]);
                    $stmtCnt->execute([$pid_r]); $existe = (int)$stmtCnt->fetchColumn() > 0;
                    if ($existe) {
                        $stmtUpd->execute([$pct, $est, $_SESSION['user_id'], $pid_r]);
                    } else {
                        $stmtIns->execute([$pid_r, $pct, $est, $_SESSION['user_id']]);
                    }
                }
                $message = showMessage("Progreso de ingeniería actualizado para todos los proyectos.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al recalcular: " . $e->getMessage(), 'error');
            }
            break;

        case 'cancelar_boom':
            $boom_id     = intval($_POST['boom_id']);
            $proyecto_id = intval($_POST['proyecto_id']);

            if ($boom_id) {
                $db->prepare("DELETE FROM boom_items WHERE boom_id = ? AND (guardado = 0 OR guardado IS NULL)")->execute([$boom_id]);
                // If the lista itself has no items left and was never saved, delete it too
                $remaining = $db->prepare("SELECT COUNT(*) FROM boom_items WHERE boom_id = ?");
                $remaining->execute([$boom_id]);
                if ($remaining->fetchColumn() == 0) {
                    $db->prepare("DELETE FROM boom_listas WHERE id = ? AND guardado = 0")->execute([$boom_id]);
                } else {
                    // Cancelar solo descarta items nuevos sin guardar; los cambios a items ya
                    // existentes (cantidad, certificados, nota) se guardan al instante y no se
                    // revierten aquí. Si el BOM ya había sido guardado alguna vez (guardado=1),
                    // re-fijarlo al salir — de lo contrario queda desfijado sin forma de
                    // volver a fijarlo cuando no hubo nada nuevo que guardar.
                    $db->prepare("UPDATE boom_listas SET enviado_almacen = 1 WHERE id = ? AND guardado = 1")->execute([$boom_id]);
                }
            }

            if (isset($_SESSION['boom_lista_activo']) && $_SESSION['boom_lista_activo'] == $boom_id) {
                unset($_SESSION['boom_lista_activo']);
                unset($_SESSION['proyecto_boom_activo']);
            }

            $message = showMessage("BOM cancelado", 'info');
            break;
            
        case 'guardar_material':
            try {
                $codigo = sanitize($_POST['codigo'] ?? '');
                // 1. Tomamos descripción y lo hacemos obligatorio
                $descripcion = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
                $categoria_id = $_POST['categoria_id'];
                $marca = sanitize($_POST['marca'] ?? '');
                $modelo = sanitize($_POST['modelo'] ?? '');
                $precio = 0;
                $stock = 0;
                $stock_min = 0;
                $unidad = sanitize($_POST['unidad_medida'] ?? 'pza');
                $imagen = null;
                if (!empty($_FILES['imagen']['name'])) {
                    $upload = uploadFile($_FILES['imagen'], 'materiales/');
                    if ($upload['success']) {
                        $imagen = $upload['file'];
                    }
                }
                
                // Ingeniería nunca asigna código real -- ver resolverCodigoMaterial() en
                // includes/config.php. Se guarda con una sugerencia (sin verificar) y se
                // avisa a Almacén para que lo revise.
                $res = resolverCodigoMaterial($db, null, null, $codigo, $descripcion, true);

                $new_id = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM materiales")->fetchColumn();
                $stmt = $db->prepare("INSERT INTO materiales
                    (id, codigo, codigo_verificado, descripcion, categoria_id, marca, modelo, precio_unitario, stock_disponible, stock_minimo, unidad_medida, imagen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$new_id, $res['codigo'], $res['verificado'], $descripcion, $categoria_id, $marca, $modelo, $precio, $stock, $stock_min, $unidad, $imagen]);

                if ($marca) {
                    $stmtC = $db->prepare("SELECT id FROM carpetas_materiales WHERE nombre = ? AND padre_id IS NULL");
                    $stmtC->execute([$marca]);
                    $carpeta = $stmtC->fetch();
                    if ($carpeta) {
                        $cid = $carpeta['id'];
                    } else {
                        $cid = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM carpetas_materiales")->fetchColumn();
                        $db->prepare("INSERT INTO carpetas_materiales (id, nombre, padre_id, creado_por) VALUES (?, ?, NULL, ?)")
                           ->execute([$cid, $marca, $_SESSION['user_id']]);
                    }
                    $db->prepare("UPDATE materiales SET carpeta_id = ? WHERE id = ?")->execute([$cid, $new_id]);
                }

                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'materiales', ?, ?)")
                   ->execute([$_SESSION['user_id'], $new_id, "Material creado: $descripcion"]);

                if ($res['verificado'] === 0) {
                    notificarRoles(['almacen'], 'material_sin_verificar', "Código sin verificar: $descripcion",
                        "Ingeniería agregó el material \"$descripcion\"" . ($marca ? " ($marca)" : "") . " con código sugerido \"{$res['codigo']}\" -- revísalo en Inventario.",
                        'almacen2.php?seccion=inventario', $_SESSION['user_id']);
                } else {
                    notificarRoles(['almacen'], 'material_nuevo', "Nuevo material: $descripcion",
                        "Ingeniería agregó el material \"$descripcion\"" . ($marca ? " ($marca)" : "") . " al catálogo.",
                        'almacen2.php', $_SESSION['user_id']);
                }

                $message = showMessage("Material agregado correctamente", 'success');
            } catch (Exception $e) {
                $message = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'agregar_multiples_items':
            $proyecto_id     = intval($_POST['proyecto_id']);
            $boom_id         = intval($_POST['boom_id']);
            $items_recibidos = $_POST['items'] ?? [];

            if (empty($items_recibidos)) {
                $error = showMessage("No se seleccionaron materiales", 'error');
                break;
            }

            try {
                foreach ($items_recibidos as $material_id => $datos) {
                    if (!isset($datos['cantidad'])) continue;
                    $cantidad    = intval($datos['cantidad']);
                    $material_id = intval($material_id);
                    if ($cantidad <= 0) continue;

                    $stmt = $db->prepare("SELECT id, cantidad FROM boom_items WHERE boom_id = ? AND material_id = ? AND (guardado = 0 OR guardado IS NULL)");
                    $stmt->execute([$boom_id, $material_id]);
                    $existente = $stmt->fetch();

                    if ($existente) {
                        $prev_qty = $existente['cantidad'];
                        $new_qty  = $prev_qty + $cantidad;
                        $db->prepare("UPDATE boom_items SET cantidad = ? WHERE id = ?")
                           ->execute([$new_qty, $existente['id']]);
                        logBoomItemIng($db,$existente['id'],$boom_id,$proyecto_id,$_SESSION['user_id'],'editar','cantidad',$prev_qty,$new_qty);
                    } else {
                        $stmt_mat = $db->prepare("SELECT descripcion, modelo, marca FROM materiales WHERE id = ?");
                        $stmt_mat->execute([$material_id]);
                        $material = $stmt_mat->fetch();

                        if ($material) {
                            $db->prepare("INSERT INTO boom_items (proyecto_id, boom_id, item, descripcion, modelo, marca, cantidad, material_id, fecha_agregado, agregado_por, guardado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0)")
                               ->execute([$proyecto_id, $boom_id, $material['descripcion'] ?? '', $material['descripcion'] ?? '', $material['modelo'] ?? '', $material['marca'] ?? '', $cantidad, $material_id, $_SESSION['user_id']]);
                            $new_iid = (int)$db->lastInsertId();
                            logBoomItemIng($db,$new_iid,$boom_id,$proyecto_id,$_SESSION['user_id'],'agregar',null,null,$material['descripcion']??'');
                        }
                    }
                }

                header("Location: ?seccion=boom&proyecto_boom=$proyecto_id&boom_id=$boom_id&t=" . time());
                exit();
            } catch (Exception $e) {
                $error = showMessage("Error al agregar materiales: " . $e->getMessage(), 'error');
            }
            break;

        // Requisición externa sin proyecto: mismo módulo compartido que ya usan
        // Producción/Compras/Calidad/Proyectos (includes/generar_requisicion.php) —
        // antes Ingeniería tenía su propia copia casi idéntica de este código.
        case 'crear_requisicion_area':
            $resultado_reqia = procesarCrearRequisicionArea($db, 'Ingeniería');
            // Texto plano (no showMessage(), que ya devuelve el <div> armado) --
            // renderRequisicionesSeccion() es quien arma su propio <div class="alert">
            // con este mensaje, igual que en Calidad/Producción/Proyectos.
            if ($resultado_reqia['ok']) {
                $message = $resultado_reqia['mensaje'];
                // Este case no redirige (a diferencia de calidad/producción/proyecto)
                // -- renderiza en el mismo request, así que forzamos la pestaña "Mis
                // Requisiciones" aquí para que el mensaje (que vive en esa pestaña) se
                // vea de una vez, en lugar de quedar oculto en la pestaña "Generar".
                $_GET['tab'] = 'mis';
            } else {
                $error = showMessage($resultado_reqia['mensaje'], 'error');
            }
            break;

        case 'reutilizar_boom':
            $proyecto_origen_id = intval($_POST['proyecto_id']);
            $nuevo_nombre = sanitize($_POST['nuevo_nombre']);

            try {
                $db->beginTransaction();

                $stmt = $db->prepare("SELECT id FROM proyectos WHERE nombre = ?");
                $stmt->execute([$nuevo_nombre]);
                $existe = $stmt->fetch();

                if ($existe) {
                    $base_nombre = $nuevo_nombre;
                    $contador = 1;
                    do {
                        $nuevo_nombre = $base_nombre . '-' . $contador;
                        $stmt->execute([$nuevo_nombre]);
                        $existe = $stmt->fetch();
                        $contador++;
                    } while ($existe);
                }

                $nuevo_proyecto_id = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM proyectos")->fetchColumn();
                $stmt = $db->prepare("INSERT INTO proyectos (id, nombre, descripcion, creado_por, fecha_creacion) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$nuevo_proyecto_id, $nuevo_nombre, "Reutilizado de proyecto ID: " . $proyecto_origen_id, $_SESSION['user_id']]);

                // Copiar boom_listas del proyecto origen (excluir BOMs extraídos por IA)
                $listas_origen = $db->prepare("SELECT * FROM boom_listas WHERE proyecto_id = ? AND (tipo_lista IS NULL OR tipo_lista NOT IN ('plano_ia'))");
                $listas_origen->execute([$proyecto_origen_id]);
                foreach ($listas_origen->fetchAll() as $lista) {
                    $db->prepare("INSERT INTO boom_listas (proyecto_id, nombre, num_equipos, creado_por, fecha_creacion, guardado) VALUES (?, ?, ?, ?, NOW(), 0)")
                       ->execute([$nuevo_proyecto_id, $lista['nombre'], $lista['num_equipos'], $_SESSION['user_id']]);
                    $nueva_lista_id = (int)$db->lastInsertId();

                    $items_origen = $db->prepare("SELECT material_id, item, descripcion, modelo, marca, cantidad FROM boom_items WHERE boom_id = ?");
                    $items_origen->execute([$lista['id']]);
                    foreach ($items_origen->fetchAll() as $item) {
                        $db->prepare("INSERT INTO boom_items (proyecto_id, boom_id, item, descripcion, modelo, marca, cantidad, material_id, fecha_agregado, agregado_por, guardado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0)")
                           ->execute([$nuevo_proyecto_id, $nueva_lista_id, $item['item'], $item['descripcion'], $item['modelo'], $item['marca'], $item['cantidad'], $item['material_id'], $_SESSION['user_id']]);
                    }
                }

                $db->commit();

                $message = showMessage("BOM reutilizado correctamente como: " . $nuevo_nombre, 'success');
            } catch (Exception $e) {
                $db->rollBack();
                $error = showMessage("Error al reutilizar BOM: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'eliminar_item_boom':
            $item_id = intval($_POST['item_id']);

            try {
                $prev_item = $db->prepare("SELECT descripcion,boom_id,proyecto_id FROM boom_items WHERE id=?");
                $prev_item->execute([$item_id]);
                $prev_row_del = $prev_item->fetch();

                $stmt = $db->prepare("DELETE FROM boom_items WHERE id = ?");
                $stmt->execute([$item_id]);

                if ($prev_row_del) {
                    logBoomItemIng($db,$item_id,$prev_row_del['boom_id'],$prev_row_del['proyecto_id'],$_SESSION['user_id'],'eliminar',null,$prev_row_del['descripcion'],null);
                }
                $message = showMessage("Item eliminado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al eliminar item: " . $e->getMessage(), 'error');
            }
            break;

        case 'editar_cantidad_item':
            $item_id = intval($_POST['item_id']);
            $nueva_cantidad = intval($_POST['nueva_cantidad']);
            try {
                $prev_ec = $db->prepare("SELECT cantidad,descripcion,boom_id,proyecto_id,material_id FROM boom_items WHERE id=?");
                $prev_ec->execute([$item_id]);
                $prev_ec_row = $prev_ec->fetch();

                if ($nueva_cantidad <= 0) {
                    $db->prepare("DELETE FROM boom_items WHERE id = ?")->execute([$item_id]);
                    if ($prev_ec_row) logBoomItemIng($db,$item_id,$prev_ec_row['boom_id'],$prev_ec_row['proyecto_id'],$_SESSION['user_id'],'eliminar',null,$prev_ec_row['descripcion'],null);
                    $message = showMessage("Item eliminado del BOM", 'success');
                } else {
                    $db->prepare("UPDATE boom_items SET cantidad = ? WHERE id = ?")->execute([$nueva_cantidad, $item_id]);
                    if ($prev_ec_row) logBoomItemIng($db,$item_id,$prev_ec_row['boom_id'],$prev_ec_row['proyecto_id'],$_SESSION['user_id'],'editar','cantidad',$prev_ec_row['cantidad'],$nueva_cantidad);

                    // Si este material ya tiene una fila en almacén (de una liberación previa
                    // de este u otro BOM), ajustar la diferencia al instante — sin importar
                    // si el BOM está liberado o desbloqueado en este momento.
                    if ($prev_ec_row && $prev_ec_row['material_id']) {
                        $stmt_bl_ec = $db->prepare("SELECT num_equipos FROM boom_listas WHERE id = ?");
                        $stmt_bl_ec->execute([$prev_ec_row['boom_id']]);
                        $bl_ec = $stmt_bl_ec->fetch();
                        if ($bl_ec) {
                            $delta_ec = ($nueva_cantidad - $prev_ec_row['cantidad']) * $bl_ec['num_equipos'];
                            $stmt_alm_ec = $db->prepare("SELECT id, cantidad_requerida FROM almacen_items WHERE proyecto_id = ? AND material_id = ?");
                            $stmt_alm_ec->execute([$prev_ec_row['proyecto_id'], $prev_ec_row['material_id']]);
                            if ($alm_ec = $stmt_alm_ec->fetch()) {
                                $nueva_alm_ec = max(0, $alm_ec['cantidad_requerida'] + $delta_ec);
                                $db->prepare("UPDATE almacen_items SET cantidad_requerida = ?, diferencia = stock_actual - ? WHERE id = ?")
                                   ->execute([$nueva_alm_ec, $nueva_alm_ec, $alm_ec['id']]);
                            }
                        }
                    }

                    $message = showMessage("Cantidad actualizada correctamente", 'success');
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'editar_boom_item':
            $item_id      = intval($_POST['item_id']);
            $cantidad     = max(1, intval($_POST['cantidad'] ?? 1));
            $certificados = sanitize($_POST['certificados'] ?? '');
            $observacion  = sanitize($_POST['observacion']  ?? '');
            $nota_libre   = sanitize($_POST['nota_libre']   ?? '');
            try {
                $prev_ebi = $db->prepare("SELECT cantidad,boom_id,proyecto_id FROM boom_items WHERE id=?");
                $prev_ebi->execute([$item_id]);
                $prev_ebi_row = $prev_ebi->fetch();
                $db->prepare("UPDATE boom_items SET cantidad = ?, certificados = ?, observacion = ?, nota_libre = ? WHERE id = ?")
                   ->execute([$cantidad, $certificados ?: null, $observacion ?: null, $nota_libre ?: null, $item_id]);
                if ($prev_ebi_row && $prev_ebi_row['cantidad'] != $cantidad) {
                    logBoomItemIng($db,$item_id,$prev_ebi_row['boom_id'],$prev_ebi_row['proyecto_id'],$_SESSION['user_id'],'editar','cantidad',$prev_ebi_row['cantidad'],$cantidad);
                }
                $message = showMessage("Item actualizado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        // Feature 7: Importar BOM desde CSV
        case 'importar_bom_csv':
            $boom_id_imp  = intval($_POST['boom_id_import'] ?? 0);
            $proyecto_imp = intval($_POST['proyecto_id_import'] ?? 0);
            if (!$boom_id_imp || !$proyecto_imp) {
                $error = showMessage("Datos de BOM inválidos para importar.", 'error');
                break;
            }
            $stmt_chk_fin = $db->prepare("SELECT finalizado,tipo_lista FROM boom_listas WHERE id=?");
            $stmt_chk_fin->execute([$boom_id_imp]);
            $bfin = $stmt_chk_fin->fetch();
            if ($bfin && $bfin['finalizado'] == 1 && $bfin['tipo_lista'] === 'principal') {
                $error = showMessage("El BOM principal está finalizado. No se puede importar.", 'error');
                break;
            }
            if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== 0) {
                $error = showMessage("Selecciona un archivo CSV válido.", 'error');
                break;
            }
            $tmpFile  = $_FILES['csv_file']['tmp_name'];
            $handle   = fopen($tmpFile, 'r');
            if (!$handle) { $error = showMessage("No se pudo leer el archivo.", 'error'); break; }

            $imported = 0; $skipped = 0; $errors_csv = [];
            fgetcsv($handle); // skip header row
            $db->beginTransaction();
            try {
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    if (count($row) < 3) { $skipped++; continue; }
                    $csv_codigo   = trim($row[0] ?? '');
                    $csv_desc     = trim($row[1] ?? '');
                    $csv_qty      = intval($row[2] ?? 0);
                    $csv_marca    = trim($row[3] ?? '');
                    $csv_modelo   = trim($row[4] ?? '');
                    $csv_obs      = trim($row[5] ?? '');
                    if (empty($csv_desc) || $csv_qty <= 0) { $skipped++; continue; }

                    $mat_id = null;
                    if ($csv_codigo) {
                        $ml = $db->prepare("SELECT id FROM materiales WHERE codigo=? LIMIT 1");
                        $ml->execute([$csv_codigo]);
                        $mat_id = $ml->fetchColumn() ?: null;
                    }
                    if (!$mat_id && $csv_desc) {
                        $ml2 = $db->prepare("SELECT id FROM materiales WHERE descripcion=? LIMIT 1");
                        $ml2->execute([$csv_desc]);
                        $mat_id = $ml2->fetchColumn() ?: null;
                    }

                    $ex_chk = $db->prepare("SELECT id,cantidad FROM boom_items WHERE boom_id=? AND (material_id=? OR (material_id IS NULL AND descripcion=?))");
                    $ex_chk->execute([$boom_id_imp, $mat_id, $csv_desc]);
                    $ex_row = $ex_chk->fetch();

                    if ($ex_row) {
                        $db->prepare("UPDATE boom_items SET cantidad=? WHERE id=?")->execute([$csv_qty, $ex_row['id']]);
                        logBoomItemIng($db,$ex_row['id'],$boom_id_imp,$proyecto_imp,$_SESSION['user_id'],'editar','cantidad (importado)',$ex_row['cantidad'],$csv_qty);
                    } else {
                        $db->prepare("INSERT INTO boom_items (boom_id,proyecto_id,item,descripcion,marca,modelo,cantidad,material_id,observacion,fecha_agregado,agregado_por,guardado) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,0)")
                           ->execute([$boom_id_imp,$proyecto_imp,$csv_desc,$csv_desc,$csv_marca,$csv_modelo,$csv_qty,$mat_id,$csv_obs,$_SESSION['user_id']]);
                        $nid = (int)$db->lastInsertId();
                        logBoomItemIng($db,$nid,$boom_id_imp,$proyecto_imp,$_SESSION['user_id'],'agregar','importado CSV',null,$csv_desc);
                    }
                    $imported++;
                }
                $db->commit();
                fclose($handle);
                $message = showMessage("Importación completada: $imported item(s) procesados" . ($skipped ? ", $skipped omitidos" : "") . ".", 'success');
            } catch (Exception $e) {
                $db->rollBack();
                fclose($handle);
                $error = showMessage("Error al importar: " . $e->getMessage(), 'error');
            }
            break;

        // Feature 3: Guardar BOM actual como plantilla
        case 'guardar_plantilla':
            $boom_id_tpl   = intval($_POST['boom_id_tpl'] ?? 0);
            $nombre_tpl    = sanitize($_POST['nombre_plantilla'] ?? '');
            $desc_tpl      = sanitize($_POST['desc_plantilla'] ?? '');
            if (!$boom_id_tpl || !$nombre_tpl) {
                $error = showMessage("Nombre de plantilla requerido.", 'error');
                break;
            }
            try {
                $tpl_id = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM bom_plantillas")->fetchColumn();
                $db->prepare("INSERT INTO bom_plantillas (id,nombre,descripcion,creado_por) VALUES (?,?,?,?)")
                   ->execute([$tpl_id, $nombre_tpl, $desc_tpl, $_SESSION['user_id']]);
                $tpl_items = $db->prepare("SELECT * FROM boom_items WHERE boom_id=?");
                $tpl_items->execute([$boom_id_tpl]);
                foreach ($tpl_items->fetchAll() as $ti) {
                    $db->prepare("INSERT INTO bom_plantilla_items (plantilla_id,material_id,descripcion,marca,modelo,cantidad,certificados,observacion) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$tpl_id,$ti['material_id'],$ti['descripcion']??'',$ti['marca']??'',$ti['modelo']??'',$ti['cantidad'],$ti['certificados']??'',$ti['observacion']??'']);
                }
                $message = showMessage("Plantilla \"$nombre_tpl\" guardada con " . count($tpl_items->fetchAll()) . " items.", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al guardar plantilla: " . $e->getMessage(), 'error');
            }
            break;

        // Feature 3: Cargar plantilla en BOM actual
        case 'cargar_plantilla':
            $boom_id_lp    = intval($_POST['boom_id_lp'] ?? 0);
            $proyecto_lp   = intval($_POST['proyecto_id_lp'] ?? 0);
            $plantilla_id  = intval($_POST['plantilla_id'] ?? 0);
            $items_sel     = $_POST['tpl_items'] ?? [];
            if (!$boom_id_lp || !$plantilla_id || empty($items_sel)) {
                $error = showMessage("Selecciona al menos un item de la plantilla.", 'error');
                break;
            }
            try {
                $db->beginTransaction();
                foreach ($items_sel as $tpl_item_id) {
                    $tpl_item_id = intval($tpl_item_id);
                    $ti = $db->prepare("SELECT * FROM bom_plantilla_items WHERE id=? AND plantilla_id=?");
                    $ti->execute([$tpl_item_id, $plantilla_id]);
                    $trow = $ti->fetch();
                    if (!$trow) continue;
                    $ex2 = $db->prepare("SELECT id FROM boom_items WHERE boom_id=? AND material_id=?");
                    $ex2->execute([$boom_id_lp, $trow['material_id']]);
                    if ($ex2->fetch()) continue; // skip duplicates
                    $db->prepare("INSERT INTO boom_items (boom_id,proyecto_id,item,descripcion,marca,modelo,cantidad,material_id,certificados,observacion,fecha_agregado,agregado_por,guardado) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,0)")
                       ->execute([$boom_id_lp,$proyecto_lp,$trow['descripcion'],$trow['descripcion'],$trow['marca']??'',$trow['modelo']??'',$trow['cantidad'],$trow['material_id'],$trow['certificados']??'',$trow['observacion']??'',$_SESSION['user_id']]);
                    $nid2 = (int)$db->lastInsertId();
                    logBoomItemIng($db,$nid2,$boom_id_lp,$proyecto_lp,$_SESSION['user_id'],'agregar','cargado de plantilla',null,$trow['descripcion']);
                }
                $db->commit();
                $message = showMessage("Items de la plantilla cargados correctamente.", 'success');
            } catch (Exception $e) {
                $db->rollBack();
                $error = showMessage("Error al cargar plantilla: " . $e->getMessage(), 'error');
            }
            break;

        // ══ Equipos Fabricados — Ingeniería define cuántos van y les asigna serie/TAG.
        // Sin candado de proyectoListoParaEquipos(): corre antes de que existan OTs. ══
        case 'crear_equipos_fabricados':
            // Botón manual de respaldo -- normalmente ya no hace falta, guardar_boom llama a
            // sincronizarEquiposFabricados() automáticamente. Agrupa TODAS las BOMs principales
            // del proyecto por su "Equipo:" (boom_listas.tipo_valvula) y suma su num_equipos por
            // nombre, en vez de tomar solo la primera BOM -- un proyecto real puede tener varias
            // BOMs principales con equipos de distinta especificación (ver caso T476: 11 equipos
            // en 5 variantes distintas, cada BOM aportando los suyos).
            $ef_proyecto_id = intval($_POST['proyecto_id'] ?? 0);
            sincronizarEquiposFabricados($db, $ef_proyecto_id, $_SESSION['user_id']);
            header('Location: ingenieria.php?seccion=equipos_fabricados&proyecto_id=' . $ef_proyecto_id); exit();

        case 'guardar_id_tag_equipo':
            $ef_proyecto_id = intval($_POST['proyecto_id'] ?? 0);
            $ef_equipo_id   = intval($_POST['equipo_id'] ?? 0);
            $ef_id_tag      = sanitize($_POST['id_tag'] ?? '');
            $ef_nombre      = sanitize($_POST['nombre'] ?? '');
            if ($ef_id_tag !== '') {
                try {
                    $db->prepare("UPDATE equipos_fabricados SET id_tag=?, nombre=? WHERE id=? AND proyecto_id=? AND estado != 'completado'")
                       ->execute([$ef_id_tag, $ef_nombre ?: null, $ef_equipo_id, $ef_proyecto_id]);
                } catch (Exception $e) {
                    $_SESSION['proy_error'] = 'No se pudo guardar el ID/TAG (puede que ya esté en uso por otro equipo).';
                }
            }
            header('Location: ingenieria.php?seccion=equipos_fabricados&proyecto_id=' . $ef_proyecto_id); exit();

        // ══ Pruebas FAT — Ingeniería las genera; Producción "desarrolla" (checklist),
        // luego firman en cadena Producción -> Proyectos -> Calidad (api/fat_firma.php). ══
        case 'crear_fat':
            $fat_proy_id  = intval($_POST['fat_proyecto_id'] ?? 0);
            $fat_titulo   = sanitize($_POST['fat_titulo'] ?? '');
            $fat_desc     = sanitize($_POST['fat_descripcion'] ?? '');
            $fat_f_prog    = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : null;
            $fat_f_real    = !empty($_POST['fecha_realizada'])  ? $_POST['fecha_realizada']  : null;
            if (!$fat_proy_id || !$fat_titulo) {
                $error = showMessage("Proyecto y título son requeridos para crear la prueba FAT.", 'error');
                break;
            }
            $fat_checklist_json = fatSanitizarPasosJson($_POST['pasos_json'] ?? '');
            if ($fat_checklist_json === null) {
                $error = showMessage("Agrega al menos una prueba (Etapa 5) antes de crear la FAT.", 'error');
                break;
            }
            $fat_folio = generarFolioFAT();
            // Arranca en pendiente_ingenieria -- Ingeniería firma primero (define el alcance de
            // la prueba), luego Producción la desarrolla y firma, después Proyectos, y al
            // último Calidad (orden pedido explícitamente por el usuario, 2026-08-14).
            $db->prepare("INSERT INTO pruebas_fat (proyecto_id, folio, titulo, descripcion, checklist, fecha_programada, fecha_realizada, estado, creado_por, fecha_creacion)
                          VALUES (?,?,?,?,?,?,?,'pendiente_ingenieria',?,NOW())")
               ->execute([$fat_proy_id, $fat_folio, $fat_titulo, $fat_desc ?: null, $fat_checklist_json, $fat_f_prog, $fat_f_real, $_SESSION['user_id']]);
            header('Location: ingenieria.php?seccion=fat&ok=' . urlencode("Prueba FAT $fat_folio creada. Fírmala para enviarla a Producción."));
            exit();

        // Plantillas reutilizables de la Etapa 5 -- por tipo de equipo (nombre libre, ej.
        // "Válvula de bola", "Actuador doble acción"), para no rearmar los pasos desde cero
        // cada vez. No están ligadas a ningún proyecto -- son compartidas por toda Ingeniería.
        case 'crear_fat_plantilla':
            $fatp_nombre = sanitize($_POST['plantilla_nombre'] ?? '');
            $fatp_return = intval($_POST['proyecto_id_return'] ?? 0);
            $fatp_pasos_json = fatSanitizarPasosJson($_POST['plantilla_pasos_json'] ?? '');
            if ($fatp_nombre && $fatp_pasos_json !== null) {
                $db->prepare("INSERT INTO fat_plantillas (nombre, checklist_json, creado_por) VALUES (?,?,?)")
                   ->execute([$fatp_nombre, $fatp_pasos_json, $_SESSION['user_id']]);
            }
            header('Location: ingenieria.php?seccion=fat_nueva&proyecto_id=' . $fatp_return);
            exit();

        case 'eliminar_fat_plantilla':
            $fatp_id     = intval($_POST['plantilla_id'] ?? 0);
            $fatp_return = intval($_POST['proyecto_id_return'] ?? 0);
            if ($fatp_id) {
                $db->prepare("DELETE FROM fat_plantillas WHERE id = ?")->execute([$fatp_id]);
            }
            header('Location: ingenieria.php?seccion=fat_nueva&proyecto_id=' . $fatp_return);
            exit();
    }
}

if (isset($_GET['proyecto_boom']) && !empty($_GET['proyecto_boom'])) {
    $_SESSION['proyecto_boom_activo'] = intval($_GET['proyecto_boom']);
}
if (isset($_GET['boom_id']) && !empty($_GET['boom_id'])) {
    $_SESSION['boom_lista_activo'] = intval($_GET['boom_id']);
}

$proyectos = $db->query("SELECT p.*, u.nombre_completo as creador_nombre FROM proyectos p LEFT JOIN usuarios u ON p.creado_por = u.id ORDER BY p.fecha_creacion DESC")->fetchAll();
$materiales = $db->query("SELECT m.*, c.nombre as categoria_nombre FROM materiales m LEFT JOIN categorias c ON m.categoria_id = c.id WHERE m.activo = 1 ORDER BY m.descripcion")->fetchAll();
$seccion = isset($_GET['seccion']) ? $_GET['seccion'] : '';

// Mensaje de éxito tras redirect PRG
if (!empty($_GET['ok'])) {
    showMessage(htmlspecialchars($_GET['ok']), 'success');
}

// Active project / boom_lista
$proyecto_boom_id   = isset($_SESSION['proyecto_boom_activo']) ? intval($_SESSION['proyecto_boom_activo']) : 0;
$boom_lista_id      = isset($_SESSION['boom_lista_activo'])    ? intval($_SESSION['boom_lista_activo'])    : 0;
$proyecto_boom_nombre = '';
$boom_lista_nombre    = 'BOM Principal';
$boom_num_equipos     = 1;

$proyecto_finalizado   = false;
$proyecto_cierre_total = false;
if ($proyecto_boom_id) {
    $stmt = $db->prepare("SELECT nombre, estado, realizada, cierre_total FROM proyectos WHERE id = ?");
    $stmt->execute([$proyecto_boom_id]);
    $proy_data = $stmt->fetch();
    $proyecto_boom_nombre = $proy_data['nombre'] ?? '';
    if (!$proyecto_boom_nombre) {
        $proyecto_boom_id = 0;
        $boom_lista_id    = 0;
        unset($_SESSION['proyecto_boom_activo'], $_SESSION['boom_lista_activo']);
    } else {
        $proyecto_finalizado   = !in_array($proy_data['estado'], ['activo', 'en_pausa', null]);
        $proyecto_cierre_total = (bool)($proy_data['cierre_total'] ?? false);
    }
}
$boom_activo_bloqueado = false;
$boom_revision_numero  = 0;
$boom_tipo_valvula     = '';
// Nombres de "Equipo:" ya usados en OTRAS BOMs de este mismo proyecto -- para el
// datalist del campo, así Ingeniería elige uno existente en vez de volver a
// teclearlo (y arriesgar un typo que lo cuente como un equipo distinto).
$equipos_nombres_proyecto = [];
if ($proyecto_boom_id) {
    $stmt_eqn = $db->prepare("SELECT DISTINCT TRIM(tipo_valvula) AS nombre FROM boom_listas WHERE proyecto_id = ? AND tipo_lista = 'principal' AND tipo_valvula IS NOT NULL AND TRIM(tipo_valvula) != '' ORDER BY nombre");
    $stmt_eqn->execute([$proyecto_boom_id]);
    $equipos_nombres_proyecto = $stmt_eqn->fetchAll(PDO::FETCH_COLUMN);
}
if ($boom_lista_id) {
    $stmt = $db->prepare("SELECT nombre, num_equipos, enviado_almacen, revision_numero, tipo_valvula FROM boom_listas WHERE id = ?");
    $stmt->execute([$boom_lista_id]);
    $bl = $stmt->fetch();
    if ($bl) {
        $boom_lista_nombre     = $bl['nombre'];
        $boom_num_equipos      = $bl['num_equipos'];
        // Cierre total del proyecto: bloqueo indefinido, sin excepción (ni el desbloqueo de
        // admin ni el individual por BOM aplican) — ver includes/equipos_fabricados.php.
        $boom_activo_bloqueado = $proyecto_cierre_total || (bool)$bl['enviado_almacen'];
        $boom_revision_numero  = (int)($bl['revision_numero'] ?? 0);
        $boom_tipo_valvula     = $bl['tipo_valvula'] ?? '';
    } else {
        $boom_lista_id = 0;
        unset($_SESSION['boom_lista_activo']);
    }
}

// boom_listas per project (with item counts) for folder view
$boms_por_proyecto = [];
$stmt_listas = $db->query("
    SELECT bl.*, COUNT(bi.id) as num_items_guardados,
           SUM(bi.guardado=0 OR bi.guardado IS NULL) as num_items_borrador
    FROM boom_listas bl
    LEFT JOIN boom_items bi ON bi.boom_id = bl.id
    WHERE bl.tipo_lista IS NULL OR bl.tipo_lista NOT IN ('plano_ia')
    GROUP BY bl.id
    ORDER BY bl.proyecto_id, bl.fecha_creacion ASC
");
foreach ($stmt_listas->fetchAll() as $row) {
    $boms_por_proyecto[$row['proyecto_id']][] = $row;
}

// BOMs con un reemplazo de material pendiente de aprobación -- se cruza por
// proyecto+material_id porque almacen_items no guarda de qué BOM salió cada
// fila (el mismo material puede venir de más de un BOM del proyecto, en cuyo
// caso se marcan todos; es preferible avisar de más que quedarse callado).
$boms_con_reemplazo_pendiente = [];
$stmt_reempl_bom = $db->query("
    SELECT DISTINCT bi.boom_id, mo.descripcion AS desc_original
    FROM material_reemplazo_solicitudes mrs
    JOIN almacen_items ai ON mrs.origen_tipo = 'almacen_item' AND mrs.origen_id = ai.id
    JOIN boom_items bi ON bi.proyecto_id = ai.proyecto_id AND bi.material_id = ai.material_id AND bi.guardado = 1
    LEFT JOIN materiales mo ON mo.id = mrs.material_original_id
    WHERE mrs.estado = 'pendiente'
");
foreach ($stmt_reempl_bom->fetchAll() as $row) {
    $boms_con_reemplazo_pendiente[$row['boom_id']][] = $row['desc_original'] ?? 'material desconocido';
}

// Igual que arriba pero a nivel proyecto -- para el badge en el encabezado de la
// carpeta (colapsada), que antes solo se veía hasta abrir el proyecto y expandir
// la tabla de BOMs. La solicitud ya trae proyecto_id directo, sin necesidad de
// cruzar con boom_items.
$proyectos_con_reemplazo_pendiente = array_fill_keys(
    $db->query("SELECT DISTINCT proyecto_id FROM material_reemplazo_solicitudes WHERE estado = 'pendiente' AND proyecto_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN),
    true
);

// Conteo de BOMs per project for folder badges
$conteo_items = [];
foreach ($boms_por_proyecto as $pid => $listas) {
    $guardados   = array_sum(array_column($listas, 'num_items_guardados'));
    $en_progreso = array_sum(array_column($listas, 'num_items_borrador'));
    $conteo_items[$pid] = ['guardados' => $guardados, 'en_progreso' => $en_progreso];
}

// Fallback: if boom_lista_id not set but proyecto_boom_id is, pick the first unsaved lista
if ($proyecto_boom_id && !$boom_lista_id) {
    $stmt = $db->prepare("SELECT bl.id, bl.nombre, bl.num_equipos FROM boom_listas bl LEFT JOIN boom_items bi ON bi.boom_id = bl.id WHERE bl.proyecto_id = ? AND (bl.tipo_lista IS NULL OR bl.tipo_lista NOT IN ('plano_ia')) AND (bi.guardado = 0 OR bi.guardado IS NULL) GROUP BY bl.id LIMIT 1");
    $stmt->execute([$proyecto_boom_id]);
    $bl = $stmt->fetch();
    if ($bl) {
        $boom_lista_id      = $bl['id'];
        $boom_lista_nombre  = $bl['nombre'];
        $boom_num_equipos   = $bl['num_equipos'];
        $_SESSION['boom_lista_activo'] = $boom_lista_id;
    }
}

// BOMs of the current project (for tab strip in BOM editor)
$boms_del_proyecto = [];
if ($proyecto_boom_id) {
    $stmt = $db->prepare("SELECT bl.id, bl.nombre, bl.num_equipos, bl.guardado FROM boom_listas bl WHERE bl.proyecto_id = ? AND (bl.tipo_lista IS NULL OR bl.tipo_lista NOT IN ('plano_ia')) ORDER BY bl.fecha_creacion ASC");
    $stmt->execute([$proyecto_boom_id]);
    $boms_del_proyecto = $stmt->fetchAll();
}

// Feature 3: load plantillas
$plantillas_bom = [];
try {
    $plantillas_bom = $db->query("SELECT id,nombre,descripcion FROM bom_plantillas WHERE activo=1 ORDER BY nombre")->fetchAll();
} catch (Exception $e) { /* tabla no migrada aún */ }

// Items for the active boom_lista (with stock info for Feature 2)
$items_boom = [];
if ($boom_lista_id) {
    $stmt = $db->prepare("SELECT b.*, p.nombre as proyecto_nombre,
                                 m.stock_disponible as mat_stock, m.descripcion as mat_desc_cat,
                                 c.nombre as cat_nombre, c.id as cat_id_val
                          FROM boom_items b
                          LEFT JOIN proyectos p ON b.proyecto_id = p.id
                          LEFT JOIN materiales m ON m.id = b.material_id
                          LEFT JOIN categorias c ON c.id = m.categoria_id
                          WHERE b.boom_id = ?
                          ORDER BY b.guardado DESC, b.fecha_agregado ASC");
    $stmt->execute([$boom_lista_id]);
    $items_boom = $stmt->fetchAll();
}

// Marcas distintas para el filtro del BOM
$marcas_bom = [];
foreach ($materiales as $m) {
    $marca = trim($m['marca'] ?? '');
    if ($marca && !in_array($marca, $marcas_bom)) {
        $marcas_bom[] = $marca;
    }
}
sort($marcas_bom);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingeniería - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            text-decoration: none !important;
        }
        
        input, textarea {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }
        
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background: #ffffff;
            height: 100vh;
            overflow: hidden;
        }
        
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: #2E5CAE;
            box-shadow: 0 2px 10px rgba(46,92,174,0.25);
            border-bottom: 2px solid #c9a227;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        /* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
        .header-logo {
            height: 66px;
            width: auto;
            object-fit: contain;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .back-button-header {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 40px;
            padding: 8px 20px;
            cursor: pointer;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .back-button-header:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
            transform: translateX(-5px);
        }
        
        .back-button-header i {
            font-size: 14px;
        }
        
        .user-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .user-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.15);
            padding: 5px 15px 5px 5px;
            border-radius: 40px;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.2s;
        }
        
        .user-trigger:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #FF9800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: white;
        }
        
        .user-info-text {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: white;
            font-size: 13px;
            line-height: 1.2;
        }
        
        .user-role {
            font-size: 10px;
            color: rgba(255,255,255,0.8);
            text-transform: uppercase;
        }
        
        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 5px);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-5px);
            transition: all 0.2s;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            z-index: 1000;
        }
        
        .user-dropdown:hover .user-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-size: 13px;
        }
        
        .dropdown-item:hover {
            background: #f5f5f5;
            color: #f44336;
        }
        
        .dropdown-item i {
            width: 18px;
            color: #666;
        }
        
        .app-container {
            height: 100vh;
            width: 100vw;
            padding-top: 80px;
            position: relative;
        }
        
        .main-center {
            height: calc(100vh - 140px);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding: 20px 20px 100px 20px;
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .page-title {
            font-size: 2.2rem;
            color: #003d5c;
            font-weight: 300;
            margin-bottom: 10px;
        }
        
        .page-title strong {
            font-weight: 800;
            color: #005686;
            background: linear-gradient(135deg, #003d5c, #0077b3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.4rem;
        }
        
        .page-subtitle {
            color: #666;
            font-size: 1rem;
        }
        
        .cards-grid {
            display: flex;
            gap: 30px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .nav-card {
            width: 180px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .nav-card:hover {
            transform: translateY(-8px);
        }
        
        .nav-card:active {
            transform: scale(0.95);
        }
        
        .nav-card:hover .card-icon {
            border-color: #FF9800;
            box-shadow: 0 15px 30px rgba(255, 152, 0, 0.25);
        }
        
        .nav-card:hover .card-title {
            color: #FF9800;
        }
        
        /* Definimos una variable para el color azul oscuro */
        :root {
            --color-valvula: #1e3a5f; /* Azul oscuro similar a la imagen */
        }

        /* 1. Contenedor principal: El cuerpo de la válvula */
        .logo-valve-pure-css {
            position: relative;
            width: 60%;  /* Ancho del cuerpo principal */
            height: 35%; /* Alto del cuerpo principal */
            background-color: var(--color-valvula);
            border-radius: 4px; /* Un ligero redondeado */
        }

        /* 2. Pseudo-elemento 'before': Flanges laterales */
        .logo-valve-pure-css::before {
            content: '';
            position: absolute;
            top: -15%; /* Centrado verticalmente respecto al cuerpo */
            width: 12%; /* Ancho de cada flange */
            height: 130%; /* Alto total de los flanges */
            background-color: var(--color-valvula);
            border-radius: 2px;
            
            /* Dibujamos ambos flanges usando shadow: uno a la izquierda y otro a la derecha */
            /* Sintaxis: h-shadow v-shadow blur spread color */
            left: -15%; /* Posición del primer flange (izquierdo) */
            box-shadow: calc(100% + 115%) 0 0 0 var(--color-valvula); /* Flange derecho */
        }

        /* 3. Pseudo-elemento 'after': Tallo y Pantalla superior */
        .logo-valve-pure-css::after {
            content: '';
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            bottom: 100%;
            width: 15%;
            height: 40%;
            background-color: var(--color-valvula);
            
            /* La Pantalla Superior y su borde (usando múltiples box-shadows) */
            box-shadow: 
                /* 1. La caja azul grande superior */
                0 -70% 0 8px var(--color-valvula), 
                
                /* 2. El recuadro blanco/vacío central (una sombra blanca interna) */
                0 -70% 0 1px white inset,
                
                /* 3. El borde externo fino de la caja (una sombra sutil) */
                0 -70% 0 9px rgba(0,0,0,0.1);
        }
        
        .card-icon {
            width: 100%;
            aspect-ratio: 1/1;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            border: 3px solid #e8ecf0;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }

        .card-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .nav-card:hover .card-icon::before {
            left: 100%;
        }
        
        .card-icon i {
            font-size: 4rem;
            color: #005686;
            transition: all 0.3s ease;
            z-index: 2;
        }
        
        .nav-card:hover .card-icon i {
            color: #FF9800;
            transform: scale(1.1);
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            transition: color 0.3s ease;
            margin-bottom: 5px;
        }
        
        .card-desc {
            font-size: 0.8rem;
            color: #888;
            font-weight: 400;
        }
        
        .dashboard-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 40px;
            border-top: 2px solid #e0e0e0;
            background: white;
            height: 60px;
            z-index: 1000;
        }
        
        .footer-left {
            display: flex;
            gap: 30px;
            color: #555;
            font-size: 13px;
            font-weight: 500;
        }
        
        .footer-left i {
            color: #003d5c;
            width: 16px;
            margin-right: 5px;
        }
        
        .footer-right img {
            height: 25px;
            width: auto;
            object-fit: contain;
        }
        
        .section-content {
            height: calc(100vh - 140px);
            overflow-y: auto;
            padding: 0 30px 30px 30px;
            margin-top: 0;
            position: relative;
        }
        
        .sticky-container {
            position: sticky;
            top: 0;
            background: white;
            z-index: 90;
            padding: 20px 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-row h2 {
            font-size: 1.5rem;
            color: #003d5c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-wrapper {
            position: relative;
            width: 300px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 35px 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 40px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #005686;
            box-shadow: 0 0 0 3px rgba(0,86,134,0.1);
        }
        
        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 16px;
            display: none;
        }
        
        .search-clear.visible {
            display: block;
        }
        
        .search-clear:hover {
            color: #f44336;
        }
        
        .btn-categorias {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 40px;
            padding: 10px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #003d5c;
            transition: all 0.2s;
        }
        
        .btn-categorias:hover {
            border-color: #005686;
            color: #005686;
            background: #f0f8ff;
        }
        
        .btn-categorias i {
            color: #FF9800;
        }
        
        .content-panel {
            height: calc(100vh - 280px);
            overflow-y: auto;
            padding-right: 10px;
            padding-bottom: 100px; /* <--- AGREGA ESTA LÍNEA */
        }
        
        .proyecto-info {
            background: #003d5c;
            color: white;
            padding: 15px 20px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 95;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .boom-tabs {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            flex-wrap: wrap;
        }

        .boom-tab {
            padding: 5px 14px;
            border-radius: 6px 6px 0 0;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            border-bottom: none;
            color: rgba(255,255,255,0.75);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .boom-tab:hover {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }

        .boom-tab.active {
            background: #FF9800;
            border-color: #FF9800;
            color: #fff;
            font-weight: 700;
        }
        
        .btn-outline-light {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.5);
            color: white;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            margin-left: 10px;
        }
        
        .btn-outline-light:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.8);
            transform: translateY(-2px);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary { background: #2196F3; color: white; }
        .btn-primary:hover { background: #1976D2; }
        .btn-success { background: #4CAF50; color: white; }
        .btn-success:hover { background: #388E3C; }
        .btn-warning { background: #FF9800; color: white; }
        .btn-warning:hover { background: #F57C00; }
        .btn-danger { background: #f44336; color: white; }
        .btn-danger:hover { background: #d32f2f; }
        .btn-outline { background: transparent; border: 1px solid #ddd; color: #666; }
        .btn-outline:hover { background: #f5f5f5; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .section {
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #003d5c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #333; }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        select.form-input { background: white; }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 12px 10px;
            background: #f5f5f5;
            color: #333;
            font-weight: 600;
            font-size: 13px;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }
        tr:hover td { background: #f9f9f9; }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
        }
        .modal-header {
            background: #003d5c;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        .modal-footer {
            padding: 15px 20px;
            background: #f5f5f5;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .categoria-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .categoria-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .categoria-card:hover {
            border-color: #2196F3;
            background: #f0f8ff;
            transform: translateY(-2px);
        }
        
        .categoria-card.selected {
            border-color: #2196F3;
            background: #e3f2fd;
        }
        
        .categoria-card i {
            font-size: 32px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .categoria-card.selected i {
            color: #2196F3;
        }
        
        .categoria-card .cat-nombre {
            font-weight: 600;
            color: #333;
        }
        
/* ===== TARJETAS DE MATERIALES COMPACTAS ===== */
.materiales-grid {
    display: grid;
    /* Reducimos el ancho mínimo de 280px a 200px para que quepan más por fila */
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px; /* Reducimos la separación entre tarjetas */
}

.material-card {
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
    transition: all 0.2s;
    display: flex;
    flex-direction: column;
    cursor: pointer;
}

.material-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
    border-color: #2196F3;
}

.material-card.selected {
    border-color: #2196F3;
    box-shadow: 0 0 0 3px rgba(33,150,243,0.3);
}

.material-imagen {
    height: 90px; /* Reducimos la altura de la imagen de 140px a 90px */
    background: #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
    font-size: 30px; /* Reducimos el tamaño del icono por defecto */
    overflow: hidden;
}

.material-imagen img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.material-content {
    padding: 10px; /* Reducimos el relleno interno de 15px a 10px */
}

.material-nombre { 
    font-size: 13px; /* Letra más pequeña */
    font-weight: 700; 
    color: #333; 
    margin-bottom: 3px; 
    white-space: nowrap; /* Evita que el nombre baje a 2 líneas si es muy largo */
    overflow: hidden;
    text-overflow: ellipsis;
}

.material-descripcion { 
    font-size: 11px; 
    color: #666; 
    margin-bottom: 6px;
    white-space: nowrap; /* Evita descripciones largas de varias líneas */
    overflow: hidden;
    text-overflow: ellipsis;
}

.material-detalles {
    display: flex;
    gap: 10px;
    font-size: 11px; /* Letra de detalles más pequeña */
    color: #666;
    flex-wrap: wrap;
}

.cantidad-control {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e0e0e0;
}

.cantidad-botones {
    display: flex;
    align-items: center;
    gap: 5px;
}

.cantidad-botones button {
    width: 25px; /* Botones de cantidad más pequeños (antes 30px) */
    height: 25px;
    border: none;
    background: #003d5c;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cantidad-botones button:hover {
    background: #002c44;
}

.cantidad-botones button:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.cantidad-valor {
    font-size: 14px;
    font-weight: 600;
    min-width: 25px;
    text-align: center;
}        
        .proyecto-activo-badge {
            background: #FF9800;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* ── Carpetas de proyectos ── */
        .proy-folder {
            border: 1px solid #e8ecf0;
            border-radius: 6px;
            margin-bottom: 8px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .proy-folder:hover { box-shadow: 0 2px 8px rgba(0,61,92,0.10); }
        .proy-folder-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            cursor: pointer;
            background: #fff;
            transition: background 0.15s;
            gap: 12px;
        }
        .proy-folder-header:hover { background: #f4f7fb; }
        .proy-folder.open .proy-folder-header { background: #eef3f8; border-bottom: 1px solid #e0e8f0; }
        .proy-folder.open .proy-chevron { transform: rotate(90deg); }
        .proy-folder-body { display: none; background: #fafbfc; }
        .proy-folder.open .proy-folder-body { display: block; }
        
        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: none;
            background: #2196F3;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .btn-icon:hover {
            background: #1565C0;
            transform: scale(1.1);
        }
        
        .btn-icon i {
            font-size: 14px;
        }
        
        .reutilizar-btn {
            background: white;
            color: #4CAF50;
            border: 2px solid #4CAF50;
            padding: 8px 15px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .reutilizar-btn:hover {
            background: #4CAF50;
            color: white;
            transform: translateY(-2px);
        }
        
        .cantidad-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .cantidad-btn {
            width: 40px;
            height: 40px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 18px;
        }
        
        .cantidad-input {
            width: 80px;
            height: 40px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 18px;
            font-weight: bold;
        }
        
        .btn-limpiar {
            background: #f5f5f5;
            color: #666;
            border: 1px solid #ddd;
        }
        
        .btn-limpiar:hover {
            background: #e0e0e0;
            color: #333;
        }
        
        .search-guardados {
            width: 100%;
            max-width: 400px;
        }
        /* ===== ESTILOS PARA PANELES DIVIDIDOS (BOM) ===== */
        .split-view-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            /* Calcula el espacio exacto restando header, footer y la info del proyecto */
            height: calc(100vh - 240px); 
            width: 100%;
        }

        .split-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            transition: all 0.3s ease;
            min-height: 0; /* Crucial para que el scroll interno funcione en Flexbox */
        }

        /* Clases para el estado Maximizado/Minimizado */
        .split-panel.maximized {
            flex: 100% !important;
        }

        .split-panel.minimized {
            flex: 0 !important;
            border: none;
            margin: 0;
            padding: 0;
            opacity: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
        }

        .panel-header h2, .panel-header h3 {
            margin: 0;
            font-size: 1.2rem;
            color: #003d5c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #ffffff;
        }

        .btn-maximize {
            background: #ffffff;
            border: 1px solid #ccc;
            color: #555;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            margin-left: 15px;
        }

        .btn-maximize:hover {
            background: #e0e0e0;
            color: #003d5c;
            border-color: #003d5c;
        }
        /* ===== TARJETAS DE PROYECTOS (ESTILO PROVEEDORES) ===== */
        .proyectos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .proyecto-card {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }

        .proyecto-card:hover {
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            transform: translateY(-3px);
            border-color: #005686;
        }

        .proyecto-header {
            background: #003d5c;
            color: white;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .proyecto-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: #FF9800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .proyecto-header-info {
            flex: 1;
            overflow: hidden; /* Evita que nombres largos rompan la tarjeta */
        }

        .proyecto-nombre {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .proyecto-fecha {
            font-size: 12px;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .proyecto-body {
            padding: 20px 15px;
            flex: 1;
        }

        .proyecto-detalle {
            display: flex;
            flex-direction: column;
            gap: 12px;
            font-size: 13px;
        }

        .proyecto-detalle-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #555;
            line-height: 1.4;
        }

        .proyecto-detalle-item i {
            width: 18px;
            color: #003d5c;
            margin-top: 2px;
        }

        .proyecto-footer {
            padding: 12px 15px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #f5f5f5;
        }

        /* ===== ESTILOS BASE DEL CHAT ===== */
        .chat-bubble {
            position: fixed;
            bottom: 80px;
            right: 20px;
            z-index: 9999;
        }
        .chat-button {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #003d5c, #005686);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            position: relative;
        }
        .chat-button i {
            font-size: 28px;
            color: white;
        }
        .chat-status {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
            background-color: #4caf50;
        }
        .chat-status.offline {
            background-color: #f44336;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #f44336;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .chat-window {
            position: fixed;
            bottom: 150px;
            right: 20px;
            width: 380px;
            height: 550px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            z-index: 9998;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        .chat-window.open {
            display: flex;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .chat-header {
            background: linear-gradient(135deg, #003d5c, #005686);
            color: white;
            padding: 12px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header h4 {
            margin: 0;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chat-header-actions {
            display: flex;
            gap: 10px;
        }
        .chat-header-actions button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
        }
        .chat-tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }
        .chat-tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #666;
            border-bottom: 2px solid transparent;
        }
        .chat-tab.active {
            color: #003d5c;
            border-bottom-color: #003d5c;
        }
        .chat-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-view {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .chat-view-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .chat-view-title {
            font-weight: 600;
            font-size: 16px;
            color: #333;
        }
        .chat-users-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        .chat-user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #f0f0f0;
        }
        .chat-user-item:hover {
            background: #f5f5f5;
        }
        .chat-user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: #FF9800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }
        .chat-user-info {
            flex: 1;
            min-width: 0;
        }
        .chat-user-name {
            font-weight: 600;
            font-size: 15px;
            color: #333;
            margin-bottom: 3px;
        }
        .chat-user-role {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }
        .chat-user-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .chat-user-status.online {
            background: #4caf50;
        }
        .chat-user-status.offline {
            background: #9e9e9e;
        }
        .chat-user-badge {
            background: #f44336;
            color: white;
            border-radius: 12px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 8px;
        }
        .btn-back-chat {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #003d5c;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-back-chat:hover {
            background: #e0e0e0;
        }
        .chat-conversation {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-conversation-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .chat-conversation-header .chat-user-avatar {
            width: 40px;
            height: 40px;
            font-size: 16px;
        }
        .chat-conversation-header .chat-user-info {
            flex: 1;
        }
        .chat-conversation-header .chat-user-name {
            font-size: 16px;
            margin-bottom: 0;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .message-item {
            display: flex;
            flex-direction: column;
            max-width: 85%;
        }
        .message-sent {
            align-items: flex-end;
            align-self: flex-end;
        }
        .message-received {
            align-items: flex-start;
            align-self: flex-start;
        }
        .message-bubble {
            padding: 10px 12px;
            border-radius: 18px;
            font-size: 13px;
            word-wrap: break-word;
            max-width: 100%;
        }
        .message-sent .message-bubble {
            background: #003d5c;
            color: white;
            border-bottom-right-radius: 5px;
        }
        .message-received .message-bubble {
            background: white;
            border: 1px solid #e0e0e0;
            color: #333;
            border-bottom-left-radius: 5px;
        }
        .message-time {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            margin-left: 8px;
            margin-right: 8px;
        }
        .message-file {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0,0,0,0.05);
            padding: 6px 10px;
            border-radius: 12px;
            cursor: pointer;
        }
        .message-file i {
            font-size: 20px;
        }
        .message-file span {
            font-size: 12px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .message-file-actions {
            display: flex;
            gap: 8px;
            margin-top: 5px;
        }
        .message-file-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 12px;
        }
        .message-file-actions button:hover {
            background: rgba(0,0,0,0.1);
        }
        .chat-input-area {
            padding: 12px;
            border-top: 1px solid #e0e0e0;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .chat-input-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .chat-input-row textarea {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
            resize: vertical;
            font-family: inherit;
            min-height: 40px;
            max-height: 120px;
        }
        .chat-input-row textarea:focus {
            border-color: #003d5c;
        }
        .chat-input-row button {
            background: #003d5c;
            border: none;
            color: white;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            cursor: pointer;
            flex-shrink: 0;
        }
        .chat-file-buttons {
            display: flex;
            gap: 8px;
            padding-left: 5px;
        }
        .chat-file-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 50%;
        }
        .chat-file-btn:hover {
            background: #f0f0f0;
            color: #003d5c;
        }
        .empty-chat {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-chat i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }
        .image-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .image-preview-modal.active {
            display: flex;
        }
        .image-preview-modal img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }
        .image-preview-modal .close-preview {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 30px;
            cursor: pointer;
        }
        .file-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: white;
            z-index: 10000;
            display: none;
            flex-direction: column;
        }
        .file-preview-modal.active {
            display: flex;
        }
        .file-preview-header {
            background: #003d5c;
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .file-preview-body {
            flex: 1;
            overflow: auto;
            padding: 20px;
        }
        .file-preview-body iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        .notification-item {
            background: #e8f4fc;
            border-left: 4px solid #FF9800;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 8px;
            cursor: pointer;
        }
        .notification-item:hover {
            background: #e0eef8;
            transform: translateX(3px);
        }
        .notif-unread {
            background: #fff8e1 !important;
            border-left-color: #FF9800 !important;
            font-weight: 600;
        }
        .notif-unread::after {
            content: '';
            display: inline-block;
            width: 8px; height: 8px;
            background: #FF9800;
            border-radius: 50%;
            float: right;
            margin-top: 4px;
        }
        .notification-title {
            font-weight: 600;
            font-size: 13px;
            color: #003d5c;
            margin-bottom: 5px;
        }
        .notification-message {
            font-size: 12px;
            color: #666;
        }
        .notification-time {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }
        /* ── Carpetas de Materiales ── */
        .inventario-layout {
            display: flex;
            height: calc(100vh - 140px);
            overflow: hidden;
        }
        .carpetas-panel {
            width: 220px;
            min-width: 220px;
            border-right: 1px solid #e0e0e0;
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .carpetas-header {
            padding: 12px 15px;
            background: #003d5c;
            color: white;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .carpetas-header button {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            border-radius: 4px;
            padding: 3px 8px;
            cursor: pointer;
            font-size: 12px;
        }
        .carpetas-header button:hover { background: rgba(255,255,255,0.35); }
        .carpetas-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }
        .carpeta-item {
            padding: 8px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #333;
            border-left: 3px solid transparent;
            transition: all 0.15s;
        }
        .carpeta-item:hover { background: #e9ecef; }
        .carpeta-item.active { background: #e3f2fd; border-left-color: #003d5c; color: #003d5c; font-weight: 600; }
        .carpeta-item i { color: #FF9800; width: 16px; }
        .carpetas-inventario-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .btn-mover-carpeta {
            background: #f0f4f8;
            border: 1px solid #c0c8d0;
            border-radius: 4px;
            padding: 3px 8px;
            font-size: 11px;
            cursor: pointer;
            color: #003d5c;
            margin-top: 5px;
        }
        .btn-mover-carpeta:hover { background: #e3f2fd; }

        /* Esta página no tiene ningún documento oficial embebido para imprimir (el BOM
           se imprime desde un iframe aparte, api/vista_pdf_bom.php) -- esta regla es
           solo defensa básica por si alguien hace Ctrl+P directo aquí, mismo criterio
           de limpieza de chrome ya usado en almacen2.php/compras.php. */
        @media print {
            .top-header, .chat-bubble, .chat-window, #notifHeaderDropdown { display: none !important; }
            .app-container { padding-top: 0 !important; }
        }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>
    <div class="top-header">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
        
        <div class="header-right">
            <?php if ($seccion !== ''): ?>
            <a href="?seccion=" class="back-button-header">
                <i class="fas fa-arrow-left"></i> Volver al Menú
            </a>
            <?php endif; ?>

            <div style="position:relative;">
                <div id="notifBellBtn" onclick="toggleNotifHeaderDropdown()" style="position:relative;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer;flex-shrink:0;transition:background .2s;" onmouseenter="this.style.background='rgba(255,255,255,.28)'" onmouseleave="this.style.background='rgba(255,255,255,.15)'">
                    <i class="fas fa-bell"></i>
                    <span id="notifBellBadge" style="position:absolute;top:-4px;right:-4px;background:#e53935;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:.7rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #005686;">0</span>
                </div>
                <div id="notifHeaderDropdown" style="display:none;position:absolute;top:52px;right:0;z-index:9999;width:380px;max-height:70vh;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.3);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #eee;">
                        <span style="font-size:12px;font-weight:700;color:#005686;"><i class="fas fa-hard-hat"></i> Notificaciones de Ingeniería</span>
                        <div style="display:flex;gap:6px;">
                            <button onclick="marcarTodasLeidasIngenieria()" class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                                <i class="fas fa-check-double"></i> Marcar leído
                            </button>
                            <button onclick="borrarNotificacionesIngenieria()" class="btn btn-sm" style="background:#ffebee;color:#c62828;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
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
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <div class="user-info-text">
                        <div class="user-name"><?php echo $_SESSION['user_name']; ?></div>
                        <div class="user-role"><?php echo $_SESSION['user_type']; ?></div>
                    </div>
                </div>
                <div class="user-dropdown-menu">
    <?php if ($mostrar_boton_menu): ?>
    <a href="Menu.php" class="dropdown-item" style="color: #4CAF50;">
        <i class="fas fa-arrow-left"></i> Volver al Menú de Perfiles
    </a>
    <div style="height: 1px; background: #e0e0e0; margin: 5px 0;"></div>
    <?php endif; ?>
    
    <a href="?logout=1" class="dropdown-item">
        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
    </a>
</div>
            </div>
        </div>
    </div>
    
    <div class="app-container">
        <?php
        // Aviso de éxito/error del switch de arriba (decenas de acciones: crear/guardar/
        // finalizar proyecto, BOM, FAT, etc.) -- antes solo se imprimía dentro de 2 de las
        // ~10 secciones (Proyectos, BOM); en las demás el mensaje se generaba pero nunca
        // llegaba a pantalla. Un solo renderFlash() aquí, arriba de TODAS las secciones,
        // en vez de repetirlo (y arriesgarse a olvidarlo) en cada bloque $seccion === '...'.
        renderFlash();
        ?>
        <?php if ($seccion === ''): ?>
        <div class="main-center">
            <div class="page-header">
                <h1 class="page-title">
                    <strong>Ingeniería</strong><br>
                    Panel de Control
                </h1>
                <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
            </div>
            
            <div class="cards-grid">
                <div class="nav-card" onclick="window.location.href='?seccion=inventario'">
                    <div class="card-icon">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div class="card-title">Inventario</div>
                    <div class="card-desc">Materiales y existencias</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=proyectos'">
                    <div class="card-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="card-title">Proyectos</div>
                    <div class="card-desc">Gestión de proyectos</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=boom'">
                    <div class="card-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="card-title">BOM</div>
                    <div class="card-desc">Bill of Materials</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=orden_produccion'">
                    <div class="card-icon"><i class="fas fa-industry"></i></div>
                    <div class="card-title">Orden de Producción</div>
                    <div class="card-desc">Órdenes activas</div>
                </div>

                <?php renderGenerarRequisicionNavCard('ingenieria.php'); ?>

                <?php require_once 'includes/indicadores.php'; renderIndicadoresNavCard('ingenieria.php'); ?>

                <?php renderEquiposFabricadosNavCard('ingenieria.php'); ?>

                <div class="nav-card" onclick="window.location.href='?seccion=fat'">
                    <div class="card-icon"><i class="fas fa-clipboard-check"></i></div>
                    <div class="card-title">Pruebas FAT</div>
                    <div class="card-desc">Generar pruebas FAT por proyecto</div>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'indicadores'): ?>
        <div class="section-content">
            <?php require_once 'includes/indicadores.php'; renderIndicadoresSeccion($db, 'ingenieria', 'ingenieria.php'); ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'equipos_fabricados'): ?>
        <?php renderEquiposFabricadosSeccion($db, 'ingenieria.php', 'ingenieria'); ?>
        <?php endif; ?>

        <?php if ($seccion === 'fat'):
            // fat_completadas: "completada" = ya tiene su Protocolo FAT aprobado por Producción
            // (fat_protocolos.revision_estado), no pf.estado -- esa cadena de firma digital dejó
            // de avanzar el 2026-08-28 (firmas ahora en físico) y se quedaría en 0 siempre.
            $fat_proyectos = $db->query("SELECT p.id, p.nombre,
                    COUNT(pf.id) AS total_fat,
                    (SELECT COUNT(*) FROM pruebas_fat pf2
                        JOIN fat_protocolos fp2 ON fp2.pruebas_fat_id = pf2.id AND fp2.revision_estado = 'aprobado'
                        WHERE pf2.proyecto_id = p.id) AS fat_completadas,
                    (SELECT op.id FROM ordenes_produccion op WHERE op.proyecto_id = p.id ORDER BY op.id DESC LIMIT 1) AS orden_produccion_id
                FROM proyectos p
                LEFT JOIN pruebas_fat pf ON pf.proyecto_id = p.id
                GROUP BY p.id, p.nombre
                ORDER BY p.id DESC")->fetchAll();
        ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-clipboard-check"></i> Pruebas FAT</h2>
                </div>
            </div>

            <?php if (empty($fat_proyectos)): ?>
            <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>No hay proyectos registrados.</p></div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($fat_proyectos as $fp):
                    $totalF = (int)$fp['total_fat']; $compF = (int)$fp['fat_completadas'];
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #e0e8f0;border-radius:8px;padding:12px 16px;background:#fff;">
                    <div>
                        <strong style="color:#003d5c;"><i class="fas fa-folder"></i> <?= htmlspecialchars($fp['nombre']) ?></strong>
                        <?php if ($totalF > 0): ?>
                        <span style="margin-left:8px;font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $compF===$totalF?'#e8f5e9':'#fff3e0' ?>;color:<?= $compF===$totalF?'#2e7d32':'#e65100' ?>;"><?= $compF ?>/<?= $totalF ?> completadas</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <?php if ($totalF > 0 && !empty($fp['orden_produccion_id'])): ?>
                        <!-- Ya hay Prueba(s) FAT -- se administran/generan desde el Protocolo FAT. -->
                        <a href="fat_protocolo.php?id=<?= (int)$fp['orden_produccion_id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-file-shield"></i> Ver Protocolo FAT</a>
                        <?php elseif (!empty($fp['orden_produccion_id'])): ?>
                        <!-- Sin ninguna FAT todavía -- "Crear FAT" ahora vive en el Protocolo FAT
                             mismo (título + pasos de Etapa 5), no en esta pantalla aparte. -->
                        <a href="fat_protocolo.php?id=<?= (int)$fp['orden_produccion_id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Crear FAT</a>
                        <?php else: ?>
                        <!-- Sin ninguna Orden de Producción todavía -- no hay a qué Protocolo FAT
                             entrar, se mantiene el formulario aparte como respaldo. -->
                        <a href="?seccion=fat_nueva&proyecto_id=<?= (int)$fp['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Crear FAT</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'fat_nueva'):
            $fatn_proy_id = intval($_GET['proyecto_id'] ?? 0);
            $stmtFatnProy = $db->prepare("SELECT id, nombre FROM proyectos WHERE id = ?");
            $stmtFatnProy->execute([$fatn_proy_id]);
            $fatn_proy = $stmtFatnProy->fetch();
            if (!$fatn_proy) {
                header('Location: ?seccion=fat');
                exit();
            }
            $fat_plantillas = $db->query("SELECT id, nombre, checklist_json FROM fat_plantillas ORDER BY nombre")->fetchAll();
        ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-clipboard-check"></i> Nueva Prueba FAT — <?= htmlspecialchars($fatn_proy['nombre']) ?></h2>
                    <a href="?seccion=fat" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                </div>
            </div>

            <form method="POST" style="max-width:960px;margin:0 auto;" onsubmit="return fatnAntesDeEnviar()">
                <input type="hidden" name="action" value="crear_fat">
                <input type="hidden" name="fat_proyecto_id" value="<?= (int)$fatn_proy['id'] ?>">
                <input type="hidden" name="pasos_json" id="fatnPasosJsonInput">

                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:4px;">
                    <div class="form-group">
                        <label class="form-label">Título *</label>
                        <input type="text" name="fat_titulo" class="form-input" value="FAT — <?= htmlspecialchars($fatn_proy['nombre']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha programada</label>
                        <input type="date" name="fecha_programada" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha realizada</label>
                        <input type="date" name="fecha_realizada" class="form-input">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Descripción / Notas</label>
                    <textarea name="fat_descripcion" class="form-input" rows="2" placeholder="Observaciones generales..."></textarea>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:22px 0 10px;flex-wrap:wrap;">
                    <div>
                        <label class="form-label" style="margin:0;">Pasos de la prueba — Etapa 5 (Pruebas de Funcionalidad)</label>
                        <div style="font-size:.72rem;color:#888;margin-top:2px;">Cada paso lleva instrucciones y sus propios campos de captura (Producción los llena al desarrollar la prueba).</div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select id="fatnPlantillaSel" class="form-input" style="width:auto;min-width:260px;" onchange="fatnCargarPlantilla(this.value)">
                            <option value="">— Cargar desde plantilla —</option>
                            <?php foreach ($fat_plantillas as $pl): ?>
                            <option value='<?= htmlspecialchars($pl['checklist_json'], ENT_QUOTES) ?>' data-id="<?= (int)$pl['id'] ?>" data-nombre="<?= htmlspecialchars($pl['nombre'], ENT_QUOTES) ?>"><?= htmlspecialchars($pl['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($fat_plantillas): ?>
                        <button type="button" class="btn btn-outline btn-sm" onclick="fatnEliminarPlantillaSel()" title="Eliminar la plantilla seleccionada"><i class="fas fa-trash"></i></button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="fatnPasosContainer" style="display:flex;flex-direction:column;gap:12px;margin-bottom:10px;"></div>
                <button type="button" class="btn btn-outline btn-sm" onclick="fatnAddPaso()"><i class="fas fa-plus"></i> Agregar prueba</button>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:30px;padding-top:16px;border-top:1px solid #e8edf2;flex-wrap:wrap;gap:10px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="fatnGuardarPlantilla()"><i class="fas fa-save"></i> Guardar estos pasos como plantilla</button>
                    <div style="display:flex;gap:10px;">
                        <a href="?seccion=fat" class="btn btn-outline">Cancelar</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Crear FAT</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Forms ocultos: guardar/eliminar plantilla vuelven a esta misma pantalla -->
        <form method="POST" id="fatnFormPlantilla" style="display:none;">
            <input type="hidden" name="action" value="crear_fat_plantilla">
            <input type="hidden" name="plantilla_nombre" id="fatnPlantillaNombreInput">
            <input type="hidden" name="plantilla_pasos_json" id="fatnPlantillaPasosJsonInput">
            <input type="hidden" name="proyecto_id_return" value="<?= (int)$fatn_proy['id'] ?>">
        </form>
        <form method="POST" id="fatnFormEliminarPlantilla" style="display:none;">
            <input type="hidden" name="action" value="eliminar_fat_plantilla">
            <input type="hidden" name="plantilla_id" id="fatnEliminarPlantillaIdInput">
            <input type="hidden" name="proyecto_id_return" value="<?= (int)$fatn_proy['id'] ?>">
        </form>

        <script>
            // Cada "paso" = {titulo, instrucciones, campos:[{tipo:'texto'|'opciones', etiqueta, unidad?, opciones?, valor}], resultado:null}.
            // DOM APIs en vez de innerHTML para todo lo que trae texto de usuario/plantilla --
            // value/placeholder se asignan como propiedad, nunca se parsean como HTML.
            let fatnPasoCount = 0;

            function _fatnCampoRow(campo) {
                campo = campo || { tipo: 'texto', etiqueta: '', unidad: '', valor: '' };
                const row = document.createElement('div');
                row.className = 'fatn-campo-row';
                row.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;';

                const tipoSel = document.createElement('select');
                tipoSel.className = 'form-input fatn-campo-tipo';
                tipoSel.style.cssText = 'width:auto;flex:0 0 140px;font-size:.8rem;';
                [['texto', 'Texto/número'], ['opciones', 'Opciones']].forEach(([v, l]) => {
                    const o = document.createElement('option');
                    o.value = v; o.textContent = l;
                    tipoSel.appendChild(o);
                });
                tipoSel.value = campo.tipo === 'opciones' ? 'opciones' : 'texto';

                const etiquetaInp = document.createElement('input');
                etiquetaInp.type = 'text';
                etiquetaInp.className = 'form-input fatn-campo-etiqueta';
                etiquetaInp.placeholder = 'Etiqueta (ej. Tiempo de operación)';
                etiquetaInp.style.cssText = 'flex:1;min-width:160px;font-size:.8rem;';
                etiquetaInp.value = campo.etiqueta || '';

                const unidadInp = document.createElement('input');
                unidadInp.type = 'text';
                unidadInp.className = 'form-input fatn-campo-unidad';
                unidadInp.placeholder = 'Unidad (opcional, ej. PSI)';
                unidadInp.style.cssText = 'flex:0 0 130px;font-size:.8rem;';
                unidadInp.value = campo.unidad || '';

                const opcionesInp = document.createElement('input');
                opcionesInp.type = 'text';
                opcionesInp.className = 'form-input fatn-campo-opciones';
                opcionesInp.placeholder = 'Opciones separadas por coma (ej. Abierto,Cerrado)';
                opcionesInp.style.cssText = 'flex:0 0 260px;font-size:.8rem;';
                opcionesInp.value = (campo.opciones || ['Abierto', 'Cerrado']).join(',');

                function actualizarVisibilidad() {
                    const esOpciones = tipoSel.value === 'opciones';
                    unidadInp.style.display = esOpciones ? 'none' : '';
                    opcionesInp.style.display = esOpciones ? '' : 'none';
                }
                tipoSel.onchange = actualizarVisibilidad;
                actualizarVisibilidad();

                const quitarBtn = document.createElement('button');
                quitarBtn.type = 'button';
                quitarBtn.className = 'btn btn-danger btn-sm';
                quitarBtn.title = 'Quitar campo';
                quitarBtn.onclick = () => row.remove();
                quitarBtn.innerHTML = '<i class="fas fa-times"></i>';

                row.appendChild(tipoSel);
                row.appendChild(etiquetaInp);
                row.appendChild(unidadInp);
                row.appendChild(opcionesInp);
                row.appendChild(quitarBtn);
                return row;
            }

            function _fatnPasoCard(paso) {
                paso = paso || { titulo: '', instrucciones: '', campos: [] };
                fatnPasoCount++;
                const card = document.createElement('div');
                card.className = 'fatn-paso-card';
                card.style.cssText = 'border:1px solid #e8edf2;border-radius:8px;padding:12px 14px;background:#f9fbfc;';

                const header = document.createElement('div');
                header.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
                const numSpan = document.createElement('div');
                numSpan.style.cssText = 'flex:0 0 22px;text-align:center;font-weight:700;color:#003d5c;font-size:.85rem;';
                numSpan.textContent = fatnPasoCount;
                const tituloInp = document.createElement('input');
                tituloInp.type = 'text';
                tituloInp.className = 'form-input fatn-paso-titulo';
                tituloInp.placeholder = 'Título de la prueba (ej. Operación local del actuador)';
                tituloInp.style.cssText = 'flex:1;font-weight:600;';
                tituloInp.value = paso.titulo || '';
                const quitarPasoBtn = document.createElement('button');
                quitarPasoBtn.type = 'button';
                quitarPasoBtn.className = 'btn btn-danger btn-sm';
                quitarPasoBtn.title = 'Quitar prueba';
                quitarPasoBtn.onclick = () => card.remove();
                quitarPasoBtn.innerHTML = '<i class="fas fa-times"></i>';
                header.appendChild(numSpan);
                header.appendChild(tituloInp);
                header.appendChild(quitarPasoBtn);

                const instrTa = document.createElement('textarea');
                instrTa.className = 'form-input fatn-paso-instrucciones';
                instrTa.rows = 2;
                instrTa.placeholder = 'Instrucciones / procedimiento de esta prueba';
                instrTa.style.marginBottom = '8px';
                instrTa.value = paso.instrucciones || '';

                const camposDiv = document.createElement('div');
                camposDiv.className = 'fatn-paso-campos';
                camposDiv.style.marginBottom = '6px';
                (paso.campos || []).forEach(c => camposDiv.appendChild(_fatnCampoRow(c)));

                const addCampoBtn = document.createElement('button');
                addCampoBtn.type = 'button';
                addCampoBtn.className = 'btn btn-outline btn-sm';
                addCampoBtn.innerHTML = '<i class="fas fa-plus"></i> Agregar campo de captura';
                addCampoBtn.onclick = () => camposDiv.appendChild(_fatnCampoRow());

                card.appendChild(header);
                card.appendChild(instrTa);
                card.appendChild(camposDiv);
                card.appendChild(addCampoBtn);
                return card;
            }

            function fatnAddPaso(paso) {
                document.getElementById('fatnPasosContainer').appendChild(_fatnPasoCard(paso));
            }

            function fatnRecolectarPasos() {
                const pasos = [];
                document.querySelectorAll('#fatnPasosContainer .fatn-paso-card').forEach(card => {
                    const titulo = card.querySelector('.fatn-paso-titulo').value.trim();
                    if (!titulo) return;
                    const instrucciones = card.querySelector('.fatn-paso-instrucciones').value.trim();
                    const campos = [];
                    card.querySelectorAll('.fatn-campo-row').forEach(row => {
                        const etiqueta = row.querySelector('.fatn-campo-etiqueta').value.trim();
                        if (!etiqueta) return;
                        if (row.querySelector('.fatn-campo-tipo').value === 'opciones') {
                            const opciones = row.querySelector('.fatn-campo-opciones').value.split(',').map(s => s.trim()).filter(Boolean);
                            campos.push({ tipo: 'opciones', etiqueta, opciones: opciones.length ? opciones : ['Abierto', 'Cerrado'], valor: '' });
                        } else {
                            campos.push({ tipo: 'texto', etiqueta, unidad: row.querySelector('.fatn-campo-unidad').value.trim(), valor: '' });
                        }
                    });
                    pasos.push({ titulo, instrucciones, campos, resultado: null });
                });
                return pasos;
            }

            function fatnAntesDeEnviar() {
                const pasos = fatnRecolectarPasos();
                if (!pasos.length) { alert('Agrega al menos una prueba con título antes de crear la FAT.'); return false; }
                document.getElementById('fatnPasosJsonInput').value = JSON.stringify(pasos);
                return true;
            }

            function fatnCargarPlantilla(jsonStr) {
                if (!jsonStr) return;
                let pasos;
                try { pasos = JSON.parse(jsonStr); } catch (e) { return; }
                const cont = document.getElementById('fatnPasosContainer');
                cont.innerHTML = '';
                fatnPasoCount = 0;
                pasos.forEach(p => fatnAddPaso(p));
            }

            function fatnGuardarPlantilla() {
                const pasos = fatnRecolectarPasos();
                if (!pasos.length) { alert('Agrega al menos una prueba con título antes de guardar la plantilla.'); return; }
                const nombre = prompt('Nombre de la plantilla (ej. "Actuador doble acción", "Válvula de bola"):');
                if (!nombre || !nombre.trim()) return;
                document.getElementById('fatnPlantillaNombreInput').value = nombre.trim();
                document.getElementById('fatnPlantillaPasosJsonInput').value = JSON.stringify(pasos);
                document.getElementById('fatnFormPlantilla').submit();
            }

            function fatnEliminarPlantillaSel() {
                const sel = document.getElementById('fatnPlantillaSel');
                const opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.dataset.id) { alert('Selecciona primero una plantilla de la lista.'); return; }
                if (!confirm('¿Eliminar la plantilla "' + opt.dataset.nombre + '"? Esto no afecta las pruebas FAT ya creadas con ella.')) return;
                document.getElementById('fatnEliminarPlantillaIdInput').value = opt.dataset.id;
                document.getElementById('fatnFormEliminarPlantilla').submit();
            }
        </script>
        <?php endif; ?>

        <?php if ($seccion === 'orden_produccion'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-industry"></i> Órdenes de Producción Activas</h2>
                    <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                </div>
            </div>
            <?php require_once 'includes/lista_ordenes_produccion.php'; ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'inventario'): ?>
        <div class="section-content" style="height:calc(100vh - 80px);display:flex;flex-direction:column;overflow:hidden;">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-warehouse"></i> Materiales en Inventario</h2>
                    <div class="action-buttons">
                        <div class="search-wrapper">
                            <input type="text" class="search-input" id="searchInventario" placeholder="Buscar material...">
                            <button class="search-clear" id="clearSearchInventario" onclick="clearSearch('inventario')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <button class="btn-categorias" onclick="abrirModalCategoriasInventario()">
                            <i class="fas fa-tags"></i> Categorías
                        </button>
                        <button class="btn btn-primary" onclick="abrirModalMaterial()">
                            <i class="fas fa-plus"></i> Nuevo Material
                        </button>
                    </div>
                </div>
            </div>

            <div class="inventario-layout">
                <!-- Panel de carpetas -->
                <div class="carpetas-panel">
                    <div class="carpetas-header">
                        <span><i class="fas fa-folder"></i> Carpetas</span>
                        <div style="display:flex;gap:4px;">
                            <button onclick="organizarPorMarca()" title="Organizar por marca automáticamente" style="background:#005686;color:#fff;border:none;border-radius:4px;padding:3px 7px;cursor:pointer;font-size:12px;"><i class="fas fa-tags"></i></button>
                            <button onclick="crearCarpeta()" title="Nueva carpeta"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                    <div style="padding:6px 8px;">
                        <input type="text" id="searchCarpetas" placeholder="Buscar carpeta..." oninput="filtrarCarpetas(this.value)"
                               style="width:100%;padding:5px 8px;border:1.5px solid #dde3ea;border-radius:6px;font-size:12px;font-family:'Montserrat',sans-serif;outline:none;">
                    </div>
                    <div class="carpetas-list" id="carpetasList">
                        <div class="carpeta-item active" data-id="0" onclick="filtrarPorCarpeta(0, this)">
                            <i class="fas fa-inbox"></i> Todas
                        </div>
                        <div class="carpeta-item" data-id="-1" onclick="filtrarPorCarpeta(-1, this)">
                            <i class="fas fa-folder-open"></i> Sin carpeta
                        </div>
                        <!-- Carpetas dinámicas cargadas por JS -->
                    </div>
                </div>

                <!-- Grid de materiales -->
                <div class="carpetas-inventario-content">
                    <div class="content-panel" style="flex:1;overflow-y:auto;">
                        <div class="materiales-grid" id="inventarioGrid">
                            <?php foreach ($materiales as $mat): ?>
                            <div class="material-card"
                                 data-descripcion="<?php echo strtolower($mat['descripcion'] ?? ''); ?>"
                                 data-codigo="<?php echo strtolower($mat['codigo'] ?? ''); ?>"
                                 data-categoria="<?php echo $mat['categoria_id']; ?>"
                                 data-carpeta="<?php echo $mat['carpeta_id'] ?? ''; ?>">
                                <div class="material-imagen">
                                    <?php $img_marca = getMarcaImagen($mat['marca'] ?? '', $marcas_imagenes); ?>
                                    <?php if ($img_marca): ?>
                                        <img src="<?php echo $img_marca; ?>" alt="<?php echo htmlspecialchars($mat['marca'] ?? ''); ?>" style="object-fit:contain;padding:6px;">
                                    <?php elseif (!empty($mat['imagen'])): ?>
                                        <img src="uploads/<?php echo $mat['imagen']; ?>" alt="<?php echo htmlspecialchars($mat['descripcion'] ?? ''); ?>">
                                    <?php else: ?>
                                        <i class="fas fa-box"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="material-content">
                                    <div class="material-nombre"><?php echo htmlspecialchars($mat['descripcion'] ?? ''); ?></div>
                                    <div class="material-detalles">
                                        <span><strong>Marca:</strong> <?php echo $mat['marca'] ?: '-'; ?></span>
                                        <span><strong>Modelo:</strong> <?php echo $mat['modelo'] ?: '-'; ?></span>
                                        <?php if ($mat['categoria_nombre']): ?>
                                        <span><strong>Categoría:</strong> <?php echo $mat['categoria_nombre']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn-mover-carpeta"
                                            onclick="abrirMoverMaterial(<?php echo $mat['id']; ?>, '<?php echo htmlspecialchars(addslashes($mat['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', <?php echo $mat['carpeta_id'] ?? 'null'; ?>)">
                                        <i class="fas fa-folder-arrow-up"></i> Mover a carpeta
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($seccion === 'proyectos'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-folder"></i> Proyectos Creados</h2>
                    <div class="action-buttons">
                        <div class="search-wrapper">
                            <input type="text" class="search-input" id="searchProyectos" placeholder="Buscar proyecto...">
                            <button class="search-clear" id="clearSearchProyectos" onclick="clearSearch('proyectos')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <form method="POST" action="?seccion=proyectos" style="margin:0;">
                            <input type="hidden" name="action" value="recalcular_progreso_ing">
                            <button type="submit" class="btn btn-outline" title="Recalcular el avance de Ingeniería en todos los proyectos">
                                <i class="fas fa-sync-alt"></i> Actualizar Progreso
                            </button>
                        </form>
                        <button class="btn btn-primary" onclick="abrirModalProyecto()">
                            <i class="fas fa-plus"></i> Nuevo Proyecto
                        </button>
                    </div>
                </div>
            </div>

<div class="content-panel" id="proyectosContent">
                <?php if (empty($proyectos)): ?>
                <div style="text-align:center;padding:60px;color:#999;">
                    <i class="fas fa-folder-open" style="font-size:48px;margin-bottom:15px;display:block;"></i>
                    <p>No hay proyectos aún</p>
                    <button class="btn btn-primary" onclick="abrirModalProyecto()" style="margin-top:15px;">
                        <i class="fas fa-plus"></i> Crear primer proyecto
                    </button>
                </div>
                <?php else: ?>
                <?php foreach ($proyectos as $proy):
                    $pid    = $proy['id'];
                    $conteo = $conteo_items[$pid] ?? ['guardados'=>0,'en_progreso'=>0];
                    $boms   = $boms_por_proyecto[$pid] ?? [];
                    $lista_finalizado   = !in_array($proy['estado'], ['activo', 'en_pausa', null]);
                    $lista_cierre_total = (bool)($proy['cierre_total'] ?? false);

                    // Progreso de ingeniería: BOMs guardados/finalizados vs total
                    $total_boms       = count($boms);
                    $boms_finalizados = count(array_filter($boms, fn($b) => ($b['guardado'] ?? 0) == 1));
                    $progreso_ing     = $total_boms > 0 ? round($boms_finalizados / $total_boms * 100) : 0;
                    $color_progreso   = $progreso_ing === 100 ? '#4CAF50' : ($progreso_ing > 0 ? '#FF9800' : '#e0e0e0');
                ?>
                <div class="proy-folder" id="folder-<?php echo $pid; ?>"
                     data-nombre="<?php echo strtolower(htmlspecialchars($proy['nombre'])); ?>"
                     data-desc="<?php echo strtolower(htmlspecialchars($proy['descripcion'] ?? '')); ?>">

                    <!-- ENCABEZADO / CLICK PARA EXPANDIR -->
                    <div class="proy-folder-header" onclick="toggleFolder(<?php echo $pid; ?>)">
                        <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;">
                            <i class="fas fa-chevron-right proy-chevron" id="chev-<?php echo $pid; ?>" style="color:#003d5c;font-size:13px;transition:transform 0.2s;flex-shrink:0;"></i>
                            <i class="fas fa-folder" style="color:#FF9800;font-size:20px;flex-shrink:0;"></i>
                            <div style="min-width:0;">
                                <div style="font-weight:700;font-size:15px;color:#003d5c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?php echo htmlspecialchars($proy['nombre']); ?>
                                </div>
                                <div style="font-size:11px;color:#888;margin-top:2px;">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($proy['creador_nombre'] ?? 'Desconocido'); ?>
                                    &nbsp;·&nbsp;<i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($proy['fecha_creacion'])); ?>
                                    <?php if ($proy['descripcion']): ?>
                                    &nbsp;·&nbsp;<?php echo htmlspecialchars($proy['descripcion']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;" onclick="event.stopPropagation()">
                            <?php if ($total_boms > 0): ?>
                            <span title="<?php echo $boms_finalizados; ?>/<?php echo $total_boms; ?> BOMs finalizados"
                                  style="background:<?php echo $progreso_ing===100?'#e8f5e9':($progreso_ing>0?'#fff8e1':'#f5f5f5'); ?>;
                                         color:<?php echo $progreso_ing===100?'#2e7d32':($progreso_ing>0?'#e65100':'#999'); ?>;
                                         font-size:11px;padding:3px 10px;border-radius:12px;font-weight:700;display:flex;align-items:center;gap:5px;">
                                <i class="fas fa-tasks"></i>
                                <?php echo $progreso_ing; ?>%
                                <span style="font-weight:400;opacity:.8;">(<?php echo $boms_finalizados; ?>/<?php echo $total_boms; ?>)</span>
                            </span>
                            <?php endif; ?>
                            <?php if (count($boms) > 0): ?>
                            <span style="background:#e3f2fd;color:#1565c0;font-size:11px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                <i class="fas fa-layer-group"></i> <?php echo count($boms); ?> BOM<?php echo count($boms)!=1?'s':''; ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($proyectos_con_reemplazo_pendiente[$pid])): ?>
                            <span style="background:#fff3cd;color:#856404;font-size:11px;padding:3px 10px;border-radius:12px;font-weight:600;" title="Uno o más BOMs de este proyecto tienen un reemplazo de material pendiente de aprobación. Ábrelo para ver cuál.">
                                <i class="fas fa-lock"></i> Reemplazo pendiente
                            </span>
                            <?php endif; ?>
                            <?php if ($lista_cierre_total): ?>
                            <span style="background:#2e7d32;color:white;font-size:11px;padding:3px 10px;border-radius:12px;font-weight:600;" title="Proyecto cerrado — todos los equipos fueron fabricados. Bloqueado de forma indefinida.">
                                <i class="fas fa-lock"></i> Cerrado
                            </span>
                            <?php elseif ($lista_finalizado): ?>
                            <span style="background:#757575;color:white;font-size:11px;padding:3px 10px;border-radius:12px;font-weight:600;">
                                <i class="fas fa-lock"></i> Finalizado
                            </span>
                            <button class="btn btn-success btn-sm" onclick="abrirModalNuevoBoom(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Crear nuevo BOM">
                                <i class="fas fa-plus"></i> Nuevo BOM
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="abrirModalCrearPlano(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Crear BOM de manufactura con plano">
                                <i class="fas fa-drafting-compass"></i> Crear Plano
                            </button>
                            <?php if (!empty($boms)): ?>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalReutilizar(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Reutilizar en nuevo proyecto">
                                <i class="fas fa-copy"></i> Reutilizar
                            </button>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                            <button class="btn btn-sm" style="background:#b71c1c;color:#fff;" onclick="abrirSupervisorUnlock(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Desbloquear BOM (solo supervisor)">
                                <i class="fas fa-unlock-alt"></i> Desbloquear
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-danger btn-sm" onclick="eliminarProyecto(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php else: ?>
                            <?php if ($conteo['en_progreso'] > 0): ?>
                            <span style="background:#fff8e1;color:#f57f17;font-size:11px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                <i class="fas fa-pencil-alt"></i> En progreso
                            </span>
                            <?php endif; ?>
                            <?php if (empty($boms)): ?>
                            <span style="background:#f5f5f5;color:#999;font-size:11px;padding:3px 8px;border-radius:12px;">Sin BOM</span>
                            <?php endif; ?>
                            <button class="btn btn-success btn-sm" onclick="abrirModalNuevoBoom(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Crear nuevo BOM">
                                <i class="fas fa-plus"></i> Nuevo BOM
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="abrirModalCrearPlano(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Crear BOM de manufactura con plano">
                                <i class="fas fa-drafting-compass"></i> Crear Plano
                            </button>
                            <?php if (!empty($boms)): ?>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalReutilizar(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Reutilizar en nuevo proyecto">
                                <i class="fas fa-copy"></i> Reutilizar
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-primary btn-sm" onclick="editarProyecto(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars(addslashes($proy['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>')" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn" style="background:#7B3F00;color:#fff;padding:5px 10px;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;border:none;" onclick="confirmarFinalizarProyecto(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Liberar proyecto a compras">
                                <i class="fas fa-paper-plane"></i> Liberar
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="eliminarProyecto(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Barra de progreso de ingeniería -->
                    <?php if ($total_boms > 0): ?>
                    <div style="height:4px;background:#f0f0f0;border-radius:0;" title="Progreso: <?php echo $progreso_ing; ?>%">
                        <div style="height:100%;width:<?php echo $progreso_ing; ?>%;background:<?php echo $color_progreso; ?>;border-radius:0;transition:width .4s ease;"></div>
                    </div>
                    <?php endif; ?>

                    <!-- CUERPO EXPANDIBLE: lista de BOMs del proyecto -->
                    <div class="proy-folder-body" id="body-<?php echo $pid; ?>">
                        <?php if (empty($boms)): ?>
                        <div style="padding:20px 24px;color:#aaa;font-size:13px;font-style:italic;">
                            <i class="fas fa-inbox"></i> Este proyecto aún no tiene BOMs.
                            <button onclick="abrirModalNuevoBoom(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" style="background:none;border:none;color:#003d5c;cursor:pointer;font-style:normal;padding:0;margin-left:8px;">
                                <i class="fas fa-plus"></i> Crear primer BOM
                            </button>
                        </div>
                        <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="background:#f4f7fb;">
                                    <th style="padding:8px 12px;text-align:left;color:#003d5c;font-weight:600;border-bottom:1px solid #e0e0e0;">Nombre del BOM</th>
                                    <th style="padding:8px 12px;text-align:center;color:#003d5c;font-weight:600;border-bottom:1px solid #e0e0e0;">Equipos</th>
                                    <th style="padding:8px 12px;text-align:center;color:#003d5c;font-weight:600;border-bottom:1px solid #e0e0e0;">Items</th>
                                    <th style="padding:8px 12px;text-align:center;color:#003d5c;font-weight:600;border-bottom:1px solid #e0e0e0;">Estado</th>
                                    <th style="padding:8px 12px;text-align:center;color:#003d5c;font-weight:600;border-bottom:1px solid #e0e0e0;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($boms as $i => $bl): ?>
                                <tr style="background:<?php echo $i%2===0?'#fff':'#f9fafb'; ?>;">
                                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;font-weight:600;">
                                        <i class="fas fa-file-alt" style="color:#FF9800;margin-right:6px;"></i>
                                        <?php echo htmlspecialchars($bl['nombre']); ?>
                                    </td>
                                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:center;">
                                        <span style="background:#e8f5e9;color:#2e7d32;font-weight:700;padding:2px 10px;border-radius:12px;">
                                            <i class="fas fa-cogs"></i> <?php echo $bl['num_equipos']; ?>
                                        </span>
                                    </td>
                                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:center;color:#555;">
                                        <?php echo intval($bl['num_items_guardados']); ?>
                                    </td>
                                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:center;">
                                        <?php if (($bl['tipo_lista'] ?? '') === 'plano'):
                                            $ep = $bl['estado_plano'] ?? 'enviado';
                                            $ep_cfg = ['borrador'=>['Borrador','#757575','#f5f5f5'],'enviado'=>['Enviado','#1565c0','#e3f2fd'],'en_revision'=>['En revisión','#e65100','#fff3e0'],'aprobado'=>['Aprobado','#2e7d32','#e8f5e9'],'rechazado'=>['Rechazado','#b71c1c','#ffebee']];
                                            [$ep_label,$ep_color,$ep_bg] = $ep_cfg[$ep] ?? ['Enviado','#1565c0','#e3f2fd'];
                                        ?>
                                        <span style="background:<?= $ep_bg ?>;color:<?= $ep_color ?>;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;"><i class="fas fa-drafting-compass"></i> <?= $ep_label ?></span>
                                        <?php elseif ($bl['guardado']): ?>
                                        <span style="background:#e8f5e9;color:#2e7d32;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;"><i class="fas fa-check-circle"></i> Finalizado</span>
                                        <?php else: ?>
                                        <span style="background:#fff8e1;color:#f57f17;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;"><i class="fas fa-pencil-alt"></i> En progreso</span>
                                        <?php endif; ?>
                                        <?php if (!empty($boms_con_reemplazo_pendiente[$bl['id']])): ?>
                                        <div style="margin-top:4px;">
                                            <span style="background:#fff3cd;color:#856404;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;" title="Almacén propuso reemplazar <?= htmlspecialchars(implode(', ', $boms_con_reemplazo_pendiente[$bl['id']])) ?> -- esperando tu aprobación en Reemplazos de Material.">
                                                <i class="fas fa-lock"></i> Reemplazo pendiente
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:center;">
                                        <button type="button" class="btn btn-outline btn-sm" id="btn-toggle-items-<?php echo $bl['id']; ?>" onclick="toggleItemsBoom(<?php echo $bl['id']; ?>, this)" title="Ver items de este BOM">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                        <?php if ($bl['guardado'] && ($bl['tipo_lista'] ?? '') !== 'plano'): ?>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="abrirModalImportarBoom(<?php echo $bl['id']; ?>, '<?php echo htmlspecialchars(addslashes($bl['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Importar este BOM a Excel o PDF">
                                            <i class="fas fa-file-export"></i> Importar
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($lista_cierre_total): ?>
                                        <span style="color:#2e7d32;font-size:13px;font-weight:700;" title="Proyecto cerrado — todos los equipos fueron fabricados. Bloqueado de forma indefinida."><i class="fas fa-lock"></i> Bloqueado indefinidamente</span>
                                        <?php elseif (!empty($boms_con_reemplazo_pendiente[$bl['id']])): ?>
                                        <a href="reemplazos_material.php?boom_id=<?php echo $bl['id']; ?>" class="btn btn-sm" style="background:#fff3cd;color:#856404;border:1px solid #ffe69c;" title="No se puede editar mientras el reemplazo de material siga pendiente de aprobación. Clic para ver la(s) solicitud(es).">
                                            <i class="fas fa-lock"></i> Bloqueado por reemplazo
                                        </a>
                                        <?php elseif (($bl['tipo_lista'] ?? '') !== 'plano' && !empty($bl['enviado_almacen'])): ?>
                                        <button class="btn btn-sm" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;" onclick="abrirModalDesfijarBoom(<?php echo $bl['id']; ?>, '<?php echo htmlspecialchars(addslashes($bl['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Clic para desfijar y editar este BOM">
                                            <i class="fas fa-thumbtack"></i> Fijado
                                        </button>
                                        <?php elseif ($lista_finalizado && ($bl['tipo_lista'] ?? '') !== 'plano'): ?>
                                        <a href="?seccion=boom&proyecto_boom=<?php echo $pid; ?>&boom_id=<?php echo $bl['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                        <?php elseif ($lista_finalizado): ?>
                                        <span style="color:#bbb;font-size:13px;" title="Proyecto finalizado"><i class="fas fa-lock"></i> Bloqueado</span>
                                        <?php elseif (($bl['tipo_lista'] ?? '') === 'plano'): ?>
                                            <?php if (($bl['estado_plano'] ?? '') === 'rechazado'): ?>
                                            <button onclick="abrirModalReemplazarPlano(<?= $bl['id'] ?>, <?= $pid ?>, '<?= htmlspecialchars(addslashes($bl['pieza_nombre'] ?? $bl['nombre']), ENT_QUOTES, 'UTF-8') ?>')" class="btn btn-sm" style="background:#1565c0;color:#fff;font-size:.75rem;">
                                                <i class="fas fa-upload"></i> Reemplazar
                                            </button>
                                            <?php else: ?>
                                            <span style="color:#aaa;font-size:.78rem;font-style:italic;">Gestionado en Producción</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                        <a href="?seccion=boom&proyecto_boom=<?php echo $pid; ?>&boom_id=<?php echo $bl['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                        <button class="btn btn-danger btn-sm" onclick="eliminarBoomLista(<?php echo $bl['id']; ?>, '<?php echo htmlspecialchars(addslashes($bl['nombre']), ENT_QUOTES, 'UTF-8'); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr id="bom-items-row-<?php echo $bl['id']; ?>" style="display:none;">
                                    <td colspan="5" style="padding:0 12px 10px 34px;border-bottom:1px solid #f0f0f0;background:#fafbfc;">
                                        <div id="bom-items-container-<?php echo $bl['id']; ?>"></div>
                                    </td>
                                </tr>
                                <?php if (($bl['tipo_lista'] ?? '') === 'plano' && ($bl['estado_plano'] ?? '') === 'rechazado' && !empty($bl['plano_comentario'])): ?>
                                <tr>
                                    <td colspan="5" style="padding:4px 12px 10px 28px;border-bottom:1px solid #f0f0f0;background:#fff8f8;">
                                        <span style="color:#c62828;font-size:.78rem;"><i class="fas fa-exclamation-circle"></i> <strong>Motivo de rechazo:</strong> <?= htmlspecialchars($bl['plano_comentario']) ?></span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>

                        <!-- ── GESTIÓN DOCUMENTAL ──────────────────────────────── -->
                        <?php
                        $docs_proy = [];
                        try {
                            $stmt_docs = $db->prepare("SELECT * FROM proyecto_documentos WHERE proyecto_id = ? ORDER BY fase, tipo");
                            $stmt_docs->execute([$pid]);
                            $docs_proy = $stmt_docs->fetchAll();
                        } catch (Exception $e) { /* tabla puede no existir */ }
                        ?>
                        <div style="border-top:2px dashed #e0e8f0;margin-top:12px;padding-top:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding:0 14px;">
                                <span style="font-size:.83rem;font-weight:700;color:#003d5c;"><i class="fas fa-folder-open"></i> Gestión Documental</span>
                                <button onclick="abrirModalNuevoDoc(<?= $pid ?>)"
                                        style="font-size:.75rem;background:#e3f2fd;color:#1565c0;border:1px solid #bbdefb;border-radius:7px;padding:4px 10px;cursor:pointer;font-family:inherit;font-weight:600;">
                                    <i class="fas fa-plus"></i> Agregar documento
                                </button>
                            </div>
                            <?php if (empty($docs_proy)): ?>
                            <div style="padding:12px 16px;color:#aaa;font-size:.8rem;font-style:italic;">
                                <i class="fas fa-file-slash"></i> Sin documentos registrados.
                                Puedes agregar planos, diagramas y certificados desde el botón superior.
                            </div>
                            <?php else: ?>
                            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                                <thead>
                                    <tr style="background:#f0f4f8;">
                                        <th style="padding:6px 12px;text-align:left;color:#003d5c;font-weight:700;border-bottom:1px solid #e0e0e0;">Documento</th>
                                        <th style="padding:6px 12px;text-align:center;color:#003d5c;font-weight:700;border-bottom:1px solid #e0e0e0;">Tipo</th>
                                        <th style="padding:6px 12px;text-align:center;color:#003d5c;font-weight:700;border-bottom:1px solid #e0e0e0;">Fase</th>
                                        <th style="padding:6px 12px;text-align:center;color:#003d5c;font-weight:700;border-bottom:1px solid #e0e0e0;">Requerido</th>
                                        <th style="padding:6px 12px;text-align:center;color:#003d5c;font-weight:700;border-bottom:1px solid #e0e0e0;">Estado</th>
                                        <th style="padding:6px 12px;text-align:center;color:#003d5c;font-weight:700;border-bottom:1px solid #e0e0e0;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($docs_proy as $doc): ?>
                                <tr style="border-bottom:1px solid #f0f0f0;">
                                    <td style="padding:6px 12px;font-weight:600;">
                                        <?php if ($doc['ruta_archivo']): ?>
                                        <a href="uploads/<?= htmlspecialchars($doc['ruta_archivo']) ?>" target="_blank" style="color:#005686;">
                                            <i class="fas fa-file-alt"></i> <?= htmlspecialchars($doc['nombre']) ?>
                                        </a>
                                        <?php else: ?>
                                        <i class="fas fa-file" style="color:#bbb;"></i> <?= htmlspecialchars($doc['nombre']) ?>
                                        <?php endif; ?>
                                        <?php if ($doc['revision']): ?><span style="font-size:.68rem;background:#e8eaf6;color:#3949ab;padding:1px 5px;border-radius:8px;margin-left:4px;"><?= htmlspecialchars($doc['revision']) ?></span><?php endif; ?>
                                    </td>
                                    <td style="padding:6px 12px;text-align:center;"><span style="font-size:.7rem;background:#f3e5f5;color:#7b1fa2;padding:2px 7px;border-radius:10px;"><?= htmlspecialchars($doc['tipo']) ?></span></td>
                                    <td style="padding:6px 12px;text-align:center;color:#666;"><?= ucfirst($doc['fase'] ?? '') ?></td>
                                    <td style="padding:6px 12px;text-align:center;"><?= $doc['requerido'] ? '<span style="color:#e65100;font-weight:700;">Sí</span>' : '<span style="color:#9e9e9e;">No</span>' ?></td>
                                    <td style="padding:6px 12px;text-align:center;">
                                        <?php if ($doc['recibido']): ?>
                                        <span style="background:#e8f5e9;color:#2e7d32;font-size:.7rem;padding:2px 8px;border-radius:10px;font-weight:700;"><i class="fas fa-check"></i> Recibido</span>
                                        <?php else: ?>
                                        <span style="background:#fce4ec;color:#b71c1c;font-size:.7rem;padding:2px 8px;border-radius:10px;font-weight:700;"><i class="fas fa-clock"></i> Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:6px 12px;text-align:center;">
                                        <?php if (!$doc['recibido']): ?>
                                        <form method="POST" style="display:inline;" action="?seccion=proyectos">
                                            <input type="hidden" name="action" value="marcar_doc_recibido">
                                            <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                            <button type="submit" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;border-radius:5px;padding:2px 8px;font-size:.72rem;cursor:pointer;font-family:inherit;">
                                                <i class="fas fa-check"></i> Marcar recibido
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($seccion === 'boom'): ?>
        <div class="section-content">
            <?php if (empty($proyectos)): ?>
            <div class="alert alert-error" style="text-align: center;">
                <i class="fas fa-exclamation-triangle"></i>
                No hay proyectos creados. Primero crea un proyecto en la sección Proyectos.
            </div>
            <?php elseif (!$proyecto_boom_id || !$boom_lista_id): ?>
            <div class="alert alert-error" style="text-align: center;">
                <i class="fas fa-exclamation-triangle"></i>
                No hay un BOM activo. Selecciona un proyecto y crea o edita un BOM desde la sección Proyectos.
            </div>
            <?php else: ?>

            <div class="proyecto-info">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div>
                        <i class="fas fa-folder-open"></i>
                        <strong><?php echo htmlspecialchars($proyecto_boom_nombre); ?></strong>
                        <i class="fas fa-chevron-right" style="font-size:11px;margin:0 6px;opacity:0.6;"></i>
                        <input type="text" id="inputBoomNombre" value="<?php echo htmlspecialchars($boom_lista_nombre); ?>"
                               style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.4);border-radius:6px;color:#fff;padding:3px 10px;font-size:13px;font-weight:600;width:180px;">
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <label style="font-size:12px;opacity:0.85;white-space:nowrap;"><i class="fas fa-cogs"></i> Equipos:</label>
                        <input type="number" id="inputNumEquipos" value="<?php echo $boom_num_equipos; ?>" min="1"
                               style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.4);border-radius:6px;color:#fff;padding:3px 8px;font-size:13px;font-weight:700;width:65px;text-align:center;">
                    </div>
                </div>

                <?php if (count($boms_del_proyecto) > 1): ?>
                <div class="boom-tabs">
                    <?php foreach ($boms_del_proyecto as $bt): ?>
                    <div class="boom-tab <?php echo $bt['id'] == $boom_lista_id ? 'active' : ''; ?>"
                         title="<?php echo htmlspecialchars($bt['nombre']); ?>"
                         onclick="window.location.href='?seccion=boom&proyecto_boom=<?php echo $proyecto_boom_id; ?>&boom_id=<?php echo $bt['id']; ?>'">
                        <?php echo htmlspecialchars($bt['nombre']); ?>
                        <?php if (!$bt['guardado']): ?><span style="margin-left:4px;font-size:9px;opacity:0.7;">●</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div>
                    <button class="btn-outline-light" onclick="abrirModalCancelarBoom(<?php echo $proyecto_boom_id; ?>, <?php echo $boom_lista_id; ?>, '<?php echo htmlspecialchars(addslashes($boom_lista_nombre), ENT_QUOTES, 'UTF-8'); ?>')">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <a href="?seccion=proyectos" class="btn-outline-light">Proyectos</a>
                </div>
            </div>
            
<div class="split-view-container">

                <div class="split-panel" id="panelMateriales">
                    <div class="panel-header">
                        <h2><i class="fas fa-clipboard-list"></i> Materiales</h2>
                        <div class="action-buttons">
                            <div class="search-wrapper">
                                <input type="text" class="search-input" id="searchBOM" placeholder="Buscar material...">
                                <button class="search-clear" id="clearSearchBOM" onclick="clearSearch('bom')">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <button class="btn-categorias" onclick="abrirModalCategoriasBOM()">
                                <i class="fas fa-tags"></i> Categorías
                            </button>
                            <button class="btn-categorias" onclick="abrirModalMarcasBOM()" style="background:#005686;color:#fff;">
                                <i class="fas fa-industry"></i> Marcas
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalMaterial()" title="Dar de alta un material nuevo sin salir del BOM">
                                <i class="fas fa-plus"></i> Nuevo Material
                            </button>
                            <?php if (!$boom_activo_bloqueado): ?>
                            <button class="btn btn-success" onclick="agregarSeleccionados()">
                                <i class="fas fa-plus-circle"></i> Agregar a BOM
                            </button>
                            <!-- Feature 7: Importar CSV -->
                            <button class="btn btn-outline btn-sm" onclick="abrirModalImportarCSV()" title="Importar items desde CSV/Excel">
                                <i class="fas fa-file-import"></i> Importar
                            </button>
                            <!-- Feature 3: Plantillas -->
                            <?php if (!empty($plantillas_bom)): ?>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalPlantilla()" title="Cargar desde plantilla">
                                <i class="fas fa-layer-group"></i> Plantillas
                            </button>
                            <?php endif; ?>
                            <?php else: ?>
                            <button class="btn btn-success" disabled style="opacity:0.45;cursor:not-allowed;" title="Este BOM ya fue liberado">
                                <i class="fas fa-lock"></i> Agregar a BOM
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn-maximize" onclick="toggleMaximize('panelMateriales')" title="Maximizar / Restaurar">
                                <i class="fas fa-expand-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form id="boomForm" method="POST" action="?seccion=boom&proyecto_boom=<?php echo $proyecto_boom_id; ?>&boom_id=<?php echo $boom_lista_id; ?>">
                            <input type="hidden" name="action" value="agregar_multiples_items">
                            <input type="hidden" name="proyecto_id" value="<?php echo $proyecto_boom_id; ?>">
                            <input type="hidden" name="boom_id" value="<?php echo $boom_lista_id; ?>">
                            
                            <div class="materiales-grid" id="materialesBOMGrid"></div>
                            <div id="bomMatSinResultados" style="display:none;text-align:center;padding:40px 20px;color:#999;">
                                <i class="fas fa-box-open" style="font-size:30px;display:block;margin-bottom:10px;"></i>
                                Ningún material coincide con el filtro.
                            </div>
                            <div id="bomMatCargarMasWrap" style="text-align:center;padding:18px 0;">
                                <button type="button" class="btn btn-outline btn-sm" id="bomMatCargarMasBtn" onclick="bomMatCargarMas()">Cargar más materiales</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!--
                    Los ~3600 materiales se mandan como datos (no como HTML repetido por
                    cada tarjeta) -- antes esto renderizaba miles de <div class="material-card">
                    de una sola vez en cada carga de la sección BOM, que era la causa real de
                    la lentitud (no era un problema de caché). Las tarjetas se arman en el
                    navegador, en bloques de 60, igual que ya se hizo en Generar Requisiciones.
                -->
                <script type="application/json" id="bomDatosMateriales"><?= json_encode(array_map(function ($mat) use ($marcas_imagenes) {
                    return [
                        'id'              => (int) $mat['id'],
                        'nombre'          => $mat['descripcion'] ?? '',
                        'codigo'          => $mat['codigo'] ?? '',
                        'categoriaId'     => (int) ($mat['categoria_id'] ?? 0),
                        'categoriaNombre' => $mat['categoria_nombre'] ?? '',
                        'marca'           => $mat['marca'] ?? '',
                        'modelo'          => $mat['modelo'] ?? '',
                        'imgMarca'        => getMarcaImagen($mat['marca'] ?? '', $marcas_imagenes),
                        'imagen'          => !empty($mat['imagen']) ? ('uploads/' . $mat['imagen']) : null,
                    ];
                }, $materiales), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="split-panel" id="panelTabla">
                    <div class="panel-header">
                        <h3><i class="fas fa-list-ol"></i> Items agregados a este proyecto</h3>
                        <div class="action-buttons">
                            <?php if ($proyecto_cierre_total): ?>
                            <span style="background:#2e7d32;color:white;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600;" title="Todos los equipos de este proyecto fueron fabricados y el proyecto quedó cerrado — no hay forma de volver a editar este BOM.">
                                <i class="fas fa-lock"></i> Proyecto cerrado — bloqueado indefinidamente
                            </span>
                            <?php elseif ($boom_activo_bloqueado): ?>
                            <span style="background:#f44336;color:white;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                                <i class="fas fa-lock"></i> BOM liberado — solo lectura
                            </span>
                            <?php else: ?>
                            <?php
                            // El botón de guardar siempre está disponible mientras el BOM esté
                            // desfijado: no solo agregar items nuevos requiere re-guardar/re-fijar,
                            // también editar cantidades u otros datos de items ya existentes
                            // (esos cambios no marcan el item como "nuevo", pero igual deben
                            // poder guardarse y volver a fijar el BOM).
                            ?>
                            <button class="btn btn-warning" id="btnGuardarBoom" onclick="confirmarGuardarBoom(<?php echo $proyecto_boom_id; ?>, <?php echo $boom_lista_id; ?>, '<?php echo htmlspecialchars(addslashes($boom_lista_nombre), ENT_QUOTES, 'UTF-8'); ?>')">
                                <i class="fas fa-save"></i> Guardar BOM
                            </button>
                            <?php endif; ?>
                            <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#555;">
                                Rev:
                                <input type="number" id="boomRevisionInput" min="0" value="<?php echo $boom_revision_numero; ?>"
                                       onblur="guardarRevisionBom(<?php echo $boom_lista_id; ?>, this.value)"
                                       onkeydown="if(event.key==='Enter'){this.blur();}"
                                       style="width:42px;text-align:center;font-weight:700;font-size:12px;border:1px solid #ccc;border-radius:5px;padding:3px 2px;"
                                       title="Número de revisión que aparece arriba de la tabla al exportar"
                                       <?php echo $boom_activo_bloqueado ? 'disabled' : ''; ?>>
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#555;">
                                Equipo:
                                <input type="text" id="boomTipoValvulaInput" value="<?php echo htmlspecialchars($boom_tipo_valvula); ?>"
                                       onblur="guardarTipoValvulaBom(<?php echo $boom_lista_id; ?>, this.value)"
                                       onkeydown="if(event.key==='Enter'){this.blur();}"
                                       placeholder='Ej. ACTUADOR DOBLE ACCIÓN'
                                       list="listaEquiposProyecto"
                                       style="width:180px;font-size:12px;border:1px solid #ccc;border-radius:5px;padding:3px 6px;"
                                       title="Se manda a Producción junto con el BOM -- si ya existe otra BOM de este proyecto con el mismo equipo, escribe el nombre IGUAL (elige de la lista) para que sus equipos se sumen en vez de contarse aparte."
                                       <?php echo $boom_activo_bloqueado ? 'disabled' : ''; ?>>
                                <datalist id="listaEquiposProyecto">
                                    <?php foreach ($equipos_nombres_proyecto as $eqn): ?>
                                    <option value="<?php echo htmlspecialchars($eqn); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </span>
                            <a href="api/exportar_bom.php?boom_id=<?php echo $boom_lista_id; ?>" class="btn btn-outline btn-sm" target="_blank" title="Exportar BOM como Excel" style="text-decoration:none;">
                                <i class="fas fa-file-excel"></i> Exportar
                            </a>
                            <?php if (!empty($items_boom)): ?>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalGuardarPlantilla()" title="Guardar BOM como plantilla reutilizable">
                                <i class="fas fa-layer-group"></i> Guardar como Plantilla
                            </button>
                            <?php endif; ?>

                            <button type="button" class="btn-maximize" onclick="toggleMaximize('panelTabla')" title="Maximizar / Restaurar">
                                <i class="fas fa-expand-alt"></i>
                            </button>
                        </div>
                    </div>
                    <?php
                        // Clasificación para documentos (BOM impreso/Excel) -- independiente del
                        // catálogo real, orden y colores fijos pedidos por el usuario. Sigue en
                        // uso por el <select> de la columna "Categoría (documento)" y por el mapa
                        // de colores CATDOC_COLORES en JS -- las pestañas de filtro que la usaban
                        // arriba de la tabla se quitaron (el usuario pidió quitarlas).
                        $CATEGORIAS_DOC = [
                            'componente_principal' => ['label' => 'Componente Principal', 'color' => '#c62828'],
                            'conector'              => ['label' => 'Conectores',           'color' => '#1565c0'],
                            'material_electrico'    => ['label' => 'Material Eléctrico',   'color' => '#2e7d32'],
                            'tornilleria'           => ['label' => 'Tornillería',          'color' => '#000000'],
                        ];
                    ?>
                    <div class="panel-body">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Categoría</th>
                                    <th style="min-width:130px;">Categoría (documento)</th>
                                    <th>Descripción</th>
                                    <th>Modelo</th>
                                    <th>Marca</th>
                                    <th>Cantidad</th>
                                    <th style="min-width:160px;">Certificados</th>
                                    <th style="min-width:140px;">Nota</th>
                                    <th>Fecha</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBOMTable">
                                <?php if (empty($items_boom)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #999;">No hay items agregados aún</td>
                                </tr>
                                <?php else: ?>
                                    <?php $contador = 1; ?>
                                    <?php foreach ($items_boom as $item):
                                        $es_viejo     = ($item['guardado'] == 1);
                                        $tiene_cert   = !empty($item['certificados']);
                                        $iid          = $item['id'];
                                    ?>
                                    <?php
                                        $qty_total  = $item['cantidad'] * $boom_num_equipos;
                                        $mat_stock  = isset($item['mat_stock']) ? intval($item['mat_stock']) : null;
                                        $stk_badge  = '';
                                        if ($mat_stock !== null && $item['material_id']) {
                                            if ($mat_stock >= $qty_total)  $stk_badge = '<span style="background:#e8f5e9;color:#2E7D32;padding:2px 7px;border-radius:10px;font-size:.68rem;font-weight:700;">OK ('.$mat_stock.')</span>';
                                            elseif ($mat_stock > 0)        $stk_badge = '<span style="background:#fff8e1;color:#F57F17;padding:2px 7px;border-radius:10px;font-size:.68rem;font-weight:700;">Parcial ('.$mat_stock.'/'.$qty_total.')</span>';
                                            else                           $stk_badge = '<span style="background:#fce4ec;color:#b71c1c;padding:2px 7px;border-radius:10px;font-size:.68rem;font-weight:700;">Sin stock</span>';
                                        }
                                    ?>
                                    <tr data-item-id="<?php echo $iid; ?>" style="<?php echo $es_viejo ? 'background: #f5f5f5; opacity: 0.8;' : ''; ?>">
                                        <td><strong><?php echo $contador++; ?></strong></td>
                                        <td>
                                            <?php if ($es_viejo): ?>
                                                <span style="display:block;font-size:10px;color:#4CAF50;font-weight:bold;"><i class="fas fa-check-circle"></i> Ya enviado</span>
                                            <?php else: ?>
                                                <span style="display:block;font-size:10px;color:#FF9800;font-weight:bold;">Nuevo</span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['cat_nombre'])): ?>
                                                <span style="display:block;font-size:11px;color:#7b1fa2;font-weight:600;"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['cat_nombre']); ?></span>
                                            <?php else: ?>
                                                <span style="display:block;font-size:11px;color:#aaa;">Sin categoría</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <select id="catdoc_<?php echo $iid; ?>" onchange="guardarCategoriaDoc(<?php echo $iid; ?>, this.value)" <?php echo $boom_activo_bloqueado ? 'disabled' : ''; ?>
                                                style="width:100%;font-size:10.5px;padding:3px 4px;border:1px solid #ddd;border-radius:4px;color:<?php echo $CATEGORIAS_DOC[$item['categoria_doc'] ?? '']['color'] ?? '#888'; ?>;font-weight:700;">
                                                <option value="">— Sin clasificar —</option>
                                                <?php foreach ($CATEGORIAS_DOC as $cd_key => $cd_info): ?>
                                                <option value="<?php echo $cd_key; ?>" <?php echo ($item['categoria_doc'] ?? '') === $cd_key ? 'selected' : ''; ?>><?php echo htmlspecialchars($cd_info['label']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><?php echo $item['descripcion'] ?: '-'; ?></td>
                                        <td><?php echo $item['modelo'] ?: '-'; ?></td>
                                        <td><?php echo $item['marca'] ?: '-'; ?></td>
                                        <td style="text-align:center;<?php echo !$boom_activo_bloqueado ? 'cursor:pointer;' : ''; ?>" data-cantidad="<?php echo $item['cantidad']; ?>"<?php if (!$boom_activo_bloqueado): ?> onclick="editarCantidadInline(this, <?php echo $iid; ?>)" title="Clic para editar cantidad"<?php endif; ?>><?php echo $item['cantidad']; ?></td>

                                        <!-- CERTIFICADOS inline -->
                                        <td style="vertical-align:top;padding:6px 8px;">
                                            <?php if (!$boom_activo_bloqueado): ?>
                                            <label style="display:flex;align-items:center;gap:5px;margin-bottom:4px;cursor:pointer;font-size:11px;color:#555;">
                                                <input type="checkbox" id="cert_chk_<?php echo $iid; ?>"
                                                    <?php echo $tiene_cert ? 'checked' : ''; ?>
                                                    onchange="toggleCert(<?php echo $iid; ?>, this)">
                                                Requiere
                                            </label>
                                            <input type="text" id="cert_inp_<?php echo $iid; ?>"
                                                value="<?php echo htmlspecialchars($item['certificados'] ?? ''); ?>"
                                                placeholder="ISO 9001, ASME…"
                                                <?php echo $tiene_cert ? '' : 'disabled'; ?>
                                                style="width:100%;font-size:11px;padding:3px 6px;border:1px solid #ddd;border-radius:4px;transition:opacity .2s;<?php echo $tiene_cert ? '' : 'opacity:.35;'; ?>"
                                                onblur="guardarItemBom(<?php echo $iid; ?>)">
                                            <?php else: ?>
                                            <span style="font-size:11px;color:#555;"><?php echo $item['certificados'] ? htmlspecialchars($item['certificados']) : '<em style="color:#ccc;">—</em>'; ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- NOTA LIBRE inline -->
                                        <td style="vertical-align:top;padding:6px 8px;">
                                            <?php if (!$boom_activo_bloqueado): ?>
                                            <textarea id="nota_inp_<?php echo $iid; ?>" rows="2"
                                                placeholder="Nota opcional…"
                                                style="width:100%;font-size:11px;padding:3px 6px;border:1px solid #ddd;border-radius:4px;resize:vertical;min-height:28px;"
                                                onblur="guardarItemBom(<?php echo $iid; ?>)"><?php echo htmlspecialchars($item['nota_libre'] ?? ''); ?></textarea>
                                            <span id="saved_<?php echo $iid; ?>" style="display:none;font-size:10px;color:#2E7D32;">✓ guardado</span>
                                            <?php else: ?>
                                            <span style="font-size:11px;color:#555;"><?php echo $item['nota_libre'] ? htmlspecialchars($item['nota_libre']) : '<em style="color:#ccc;">—</em>'; ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <td><?php echo date('d/m/Y', strtotime($item['fecha_agregado'])); ?></td>
                                        <td style="display:flex;gap:4px;align-items:center;">
                                            <?php if (!$boom_activo_bloqueado): ?>
                                            <button class="btn-icon" title="Editar cantidad" onclick="abrirModalEditarCantidad(<?php echo $iid; ?>, '<?php echo htmlspecialchars(addslashes($item['item']), ENT_QUOTES, 'UTF-8'); ?>', <?php echo $item['cantidad']; ?>)">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <?php if (!$es_viejo): ?>
                                            <button class="btn-icon" onclick="abrirModalEliminarItem(<?php echo $iid; ?>, '<?php echo htmlspecialchars(addslashes($item['item']), ENT_QUOTES, 'UTF-8'); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <i class="fas fa-lock" style="color:#bbb;" title="Proyecto finalizado"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Pie de página tipo "hoja de control" (formato oficial) -->
                        <div style="margin-top:14px;border:1px solid #333;font-family:Arial, sans-serif;">
                            <div style="display:flex;">
                                <div style="flex:1;border-right:1px solid #333;border-bottom:1px solid #333;padding:3px 6px;text-align:center;font-size:11px;font-weight:700;">
                                    DOC-ING-FO-01C
                                </div>
                                <div style="flex:1;border-right:1px solid #333;border-bottom:1px solid #333;padding:3px 6px;text-align:center;font-size:11px;">
                                    Revisión No: <strong>6</strong>
                                </div>
                                <div style="flex:1;border-right:1px solid #333;border-bottom:1px solid #333;padding:3px 6px;text-align:center;font-size:11px;">
                                    Fecha: <strong>09 / Jul / 20</strong>
                                </div>
                                <div style="flex:1;border-bottom:1px solid #333;padding:3px 6px;text-align:center;font-size:11px;">
                                    Página: <strong>1</strong>
                                </div>
                            </div>
                            <div style="padding:4px 8px;text-align:center;font-size:11px;color:#333;">
                                Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            </div>

            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'generar_requisicion'): ?>
        <?php renderRequisicionesSeccion($db, 'ingenieria.php', 'action', 'Ingeniería', 'ingenieria', $message); ?>
        <?php endif; ?>

    </div>

    <div class="dashboard-footer">
        <div class="footer-left">
            <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
            <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
        </div>
        <div class="footer-right">
            <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO">
        </div>
    </div>
    
    <div class="modal-overlay" id="modalProyecto">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-folder-plus"></i> Nuevo Proyecto</div>
                <button class="modal-close" onclick="cerrarModal('modalProyecto')">&times;</button>
            </div>
            <form method="POST" action="?seccion=proyectos">
                <input type="hidden" name="action" value="crear_proyecto">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Nombre del Proyecto *</label>
                        <input type="text" name="nombre_proyecto" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion_proyecto" class="form-input" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalProyecto')">Cancelar</button>
                    <button type="submit" class="btn btn-success">Crear Proyecto</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalEditarProyecto">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-edit"></i> Editar Proyecto</div>
                <button class="modal-close" onclick="cerrarModal('modalEditarProyecto')">&times;</button>
            </div>
            <form method="POST" action="?seccion=proyectos">
                <input type="hidden" name="action" value="editar_proyecto">
                <input type="hidden" name="id" id="edit_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Nombre del Proyecto *</label>
                        <input type="text" name="nombre_proyecto" id="edit_nombre" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion_proyecto" id="edit_descripcion" class="form-input" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEditarProyecto')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Proyecto</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal alerta BOM reutilizable -->
    <div class="modal-overlay" id="modalAlertaBOM">
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header" style="background: linear-gradient(135deg,#e65100,#ff6d00);">
                <div class="modal-title" id="modalAlertaBOM_titulo"><i class="fas fa-exclamation-triangle"></i> Aviso</div>
                <button class="modal-close" onclick="cerrarModal('modalAlertaBOM')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="modalAlertaBOM_mensaje" style="margin:0;font-size:15px;color:#333;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="cerrarModal('modalAlertaBOM')">Aceptar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalEliminar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;">
                <div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</div>
                <button class="modal-close" onclick="cerrarModal('modalEliminar')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-trash" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i>
                <p id="eliminar_mensaje" style="margin-bottom: 20px;">¿Estás seguro de eliminar este proyecto?</p>
                <p style="font-size: 12px; color: #666;">Se eliminarán también todos sus items</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=proyectos" id="formEliminar">
                    <input type="hidden" name="action" value="eliminar_proyecto">
                    <input type="hidden" name="id" id="eliminar_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEliminar')">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Sí, Eliminar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalGuardarBoom">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #FF9800;">
                <div class="modal-title"><i class="fas fa-save"></i> Confirmar Guardar BOM</div>
                <button class="modal-close" onclick="cerrarModal('modalGuardarBoom')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-save" style="font-size: 48px; color: #FF9800; margin: 20px 0;"></i>
                <p id="guardar_mensaje" style="margin-bottom: 20px; font-size: 16px;">¿Guardar este BOM?</p>
                <p style="font-size: 13px; color: #666; margin-bottom: 10px;">Los items pasarán a BOMs Guardados.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=boom" id="formGuardarBoom">
                    <input type="hidden" name="action" value="guardar_boom">
                    <input type="hidden" name="proyecto_id"  id="guardar_proyecto_id" value="">
                    <input type="hidden" name="boom_id"      id="guardar_boom_id"     value="">
                    <input type="hidden" name="nombre_boom"  id="guardar_boom_nombre" value="">
                    <input type="hidden" name="num_equipos"  id="guardar_num_equipos" value="1">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalGuardarBoom')">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Sí, Guardar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalCancelarBoom">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;">
                <div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Cancelación</div>
                <button class="modal-close" onclick="cerrarModal('modalCancelarBoom')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-times-circle" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i>
                <p id="cancelar_mensaje" style="margin-bottom: 20px; font-size: 16px;">¿Cancelar el proyecto "<span id="proyecto_cancelar_nombre"></span>"?</p>
                <p style="font-size: 13px; color: #666; margin-bottom: 10px;">Se eliminarán todos los items agregados.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=proyectos" id="formCancelarBoom">
                    <input type="hidden" name="action" value="cancelar_boom">
                    <input type="hidden" name="proyecto_id" id="proyecto_cancelar_id" value="">
                    <input type="hidden" name="boom_id"     id="boom_cancelar_id"     value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalCancelarBoom')">No, Volver</button>
                    <button type="submit" class="btn btn-danger">Sí, Cancelar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalNuevoBoom">
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header" style="background: linear-gradient(135deg,#003d5c,#005686);">
                <div class="modal-title"><i class="fas fa-layer-group"></i> Nuevo BOM — <span id="nuevo_boom_proyecto_nombre"></span></div>
                <button class="modal-close" onclick="cerrarModal('modalNuevoBoom')">&times;</button>
            </div>
            <form method="POST" action="?seccion=proyectos">
                <input type="hidden" name="action" value="crear_boom_lista">
                <input type="hidden" name="proyecto_id" id="nuevo_boom_proyecto_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Nombre del BOM *</label>
                        <input type="text" name="nombre_boom" id="nuevo_boom_nombre" class="form-input" placeholder="Ej: BOM Eléctrico, BOM Mecánico..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-cogs"></i> Número de equipos</label>
                        <input type="number" name="num_equipos" id="nuevo_boom_equipos" class="form-input" value="1" min="1" required>
                        <small style="color:#888;font-size:12px;">Las cantidades del BOM se multiplicarán por este número al pasar a compras.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalNuevoBoom')">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Crear BOM</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Crear Plano -->
    <div class="modal-overlay" id="modalCrearPlano">
        <div class="modal" style="max-width: 480px;">
            <div class="modal-header" style="background: linear-gradient(135deg,#7B3F00,#a0522d);">
                <div class="modal-title"><i class="fas fa-drafting-compass"></i> Crear Plano — <span id="crear_plano_proyecto_nombre"></span></div>
                <button class="modal-close" onclick="cerrarModal('modalCrearPlano')">&times;</button>
            </div>
            <form method="POST" action="?seccion=proyectos" enctype="multipart/form-data">
                <input type="hidden" name="action" value="crear_plano">
                <input type="hidden" name="proyecto_id" id="crear_plano_proyecto_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Nombre de la pieza a fabricar *</label>
                        <input type="text" name="pieza_nombre" class="form-input" placeholder="Ej: Eje principal, Soporte hidráulico..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subir plano PDF *</label>
                        <input type="file" name="plano" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalCrearPlano')">Cancelar</button>
                    <button type="submit" class="btn" style="background:#7B3F00;color:#fff;"><i class="fas fa-drafting-compass"></i> Crear Plano</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Reemplazar Plano -->
    <div class="modal-overlay" id="modalReemplazarPlano">
        <div class="modal" style="max-width: 460px;">
            <div class="modal-header" style="background: linear-gradient(135deg,#1565c0,#1976d2);">
                <div class="modal-title"><i class="fas fa-upload"></i> Reemplazar Plano — <span id="reemplazar_plano_nombre"></span></div>
                <button class="modal-close" onclick="cerrarModal('modalReemplazarPlano')">&times;</button>
            </div>
            <form method="POST" action="?seccion=proyectos" enctype="multipart/form-data">
                <input type="hidden" name="action" value="reemplazar_plano">
                <input type="hidden" name="boom_id" id="reemplazar_plano_boom_id" value="">
                <input type="hidden" name="proyecto_id" id="reemplazar_plano_proyecto_id" value="">
                <div class="modal-body">
                    <div style="background:#fff3e0;border:1px solid #ffe0b2;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.83rem;color:#e65100;">
                        <i class="fas fa-info-circle"></i> El plano anterior fue rechazado por Producción. Sube el plano corregido; volverá a estado <strong>Enviado</strong> para revisión.
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nuevo plano PDF *</label>
                        <input type="file" name="plano" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalReemplazarPlano')">Cancelar</button>
                    <button type="submit" class="btn" style="background:#1565c0;color:#fff;"><i class="fas fa-paper-plane"></i> Subir y enviar a revisión</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Finalizar Proyecto -->
    <div class="modal-overlay" id="modalFinalizarProyecto">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #5D4037;">
                <div class="modal-title"><i class="fas fa-paper-plane"></i> Liberar Proyecto a Compras</div>
                <button class="modal-close" onclick="cerrarModal('modalFinalizarProyecto')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-paper-plane" style="font-size: 48px; color: #7B3F00; margin: 20px 0; display:block;"></i>
                <p style="margin-bottom: 12px; font-size: 16px;">¿Liberar el proyecto <strong id="finalizar_proyecto_nombre"></strong> a compras?</p>
                <p style="font-size: 13px; color: #666; margin-bottom: 10px;">El BOM quedará bloqueado y compras podrá iniciar el proceso de requisición.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=proyectos" id="formFinalizarProyecto">
                    <input type="hidden" name="action" value="finalizar_proyecto">
                    <input type="hidden" name="id" id="finalizar_proyecto_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalFinalizarProyecto')">Cancelar</button>
                    <button type="submit" class="btn" style="background:#7B3F00;color:#fff;"><i class="fas fa-paper-plane"></i> Sí, Liberar</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Nuevo Documento -->
    <div class="modal-overlay" id="modalNuevoDoc">
        <div class="modal" style="max-width:500px;">
            <div class="modal-header" style="background:#1565c0;">
                <div class="modal-title"><i class="fas fa-file-plus"></i> Agregar Documento al Proyecto</div>
                <button class="modal-close" onclick="cerrarModal('modalNuevoDoc')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="?seccion=proyectos" enctype="multipart/form-data" id="formNuevoDoc">
                    <input type="hidden" name="action" value="agregar_documento">
                    <input type="hidden" name="proyecto_id_doc" id="nd_proyecto_id">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                        <div style="grid-column:1/-1;">
                            <label style="font-size:.78rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Nombre del documento *</label>
                            <input type="text" name="nombre_doc" class="form-input" placeholder="Ej. Plano eléctrico Rev.A" required>
                        </div>
                        <div>
                            <label style="font-size:.78rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Tipo</label>
                            <select name="tipo_doc" class="form-input">
                                <option value="plano">Plano</option>
                                <option value="diagrama_electrico">Diagrama Eléctrico</option>
                                <option value="diagrama_neumatico">Diagrama Neumático</option>
                                <option value="manual">Manual</option>
                                <option value="especificacion">Especificación</option>
                                <option value="memoria_calculo">Memoria de Cálculo</option>
                                <option value="certificado">Certificado</option>
                                <option value="foto">Foto</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:.78rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Fase</label>
                            <select name="fase_doc" class="form-input">
                                <option value="ingenieria">Ingeniería</option>
                                <option value="procura">Procura</option>
                                <option value="manufactura">Manufactura</option>
                                <option value="fat">Pruebas FAT</option>
                                <option value="entrega">Entrega</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:.78rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Revisión</label>
                            <input type="text" name="revision_doc" class="form-input" placeholder="Rev. A">
                        </div>
                        <div>
                            <label style="font-size:.78rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">¿Requerido?</label>
                            <select name="requerido_doc" class="form-input">
                                <option value="1">Sí (obligatorio)</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                        <div style="grid-column:1/-1;">
                            <label style="font-size:.78rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Archivo (PDF/imagen) — opcional</label>
                            <input type="file" name="archivo_doc" class="form-input" accept=".pdf,.png,.jpg,.jpeg">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                        <button type="button" class="btn btn-outline" onclick="cerrarModal('modalNuevoDoc')">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Supervisor Unlock -->
    <?php if (isAdmin()): ?>
    <div class="modal-overlay" id="modalSupervisorUnlock">
        <div class="modal" style="max-width: 460px;">
            <div class="modal-header" style="background: #b71c1c;">
                <div class="modal-title"><i class="fas fa-unlock-alt"></i> Desbloquear BOM (Supervisor)</div>
                <button class="modal-close" onclick="cerrarModal('modalSupervisorUnlock')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:#c62828;background:#fdecea;padding:10px;border-radius:6px;margin-bottom:12px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Atención:</strong> Esta acción desbloquea el BOM del proyecto <strong id="unlock_proy_nombre"></strong> para edición temporal (2 horas). Se notificará a compras y almacén.
                </p>
                <form method="POST" action="?seccion=proyectos" id="formSupervisorUnlock">
                    <input type="hidden" name="action" value="supervisor_unlock">
                    <input type="hidden" name="id" id="unlock_proy_id" value="">
                    <div style="margin-bottom:10px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:4px;">Motivo del desbloqueo *</label>
                        <textarea name="motivo" style="width:100%;border:1.5px solid #ddd;border-radius:7px;padding:8px;font-family:inherit;font-size:13px;" rows="3" placeholder="Ej. Corrección urgente de cantidad en material X..." required></textarea>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                        <button type="button" class="btn btn-outline" onclick="cerrarModal('modalSupervisorUnlock')">Cancelar</button>
                        <button type="submit" class="btn" style="background:#b71c1c;color:#fff;"><i class="fas fa-unlock-alt"></i> Desbloquear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: Importar BOM (Excel o PDF) -->
    <div class="modal-overlay" id="modalImportarBoom">
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header" style="background: #003d5c;">
                <div class="modal-title"><i class="fas fa-file-export"></i> Importar BOM</div>
                <button class="modal-close" onclick="cerrarModal('modalImportarBoom')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 14px; font-size: 14px;">¿En qué formato quieres importar el BOM <strong id="importar_boom_nombre"></strong>?</p>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <button type="button" class="btn" style="background:#1e6f3e;color:#fff;justify-content:flex-start;gap:8px;" onclick="confirmarImportarBoom('excel')">
                        <i class="fas fa-file-excel"></i> Importar a Excel
                    </button>
                    <button type="button" class="btn" style="background:#b71c1c;color:#fff;justify-content:flex-start;gap:8px;" onclick="confirmarImportarBoom('pdf')">
                        <i class="fas fa-file-pdf"></i> Importar a PDF
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="cerrarModal('modalImportarBoom')">Cancelar</button>
            </div>
        </div>
    </div>

    <!-- Modal: Desfijar BOM (edición de un BOM ya liberado a almacén) -->
    <div class="modal-overlay" id="modalDesfijarBoom">
        <div class="modal" style="max-width: 440px;">
            <div class="modal-header" style="background: #2e7d32;">
                <div class="modal-title"><i class="fas fa-thumbtack"></i> Desfijar BOM</div>
                <button class="modal-close" onclick="cerrarModal('modalDesfijarBoom')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 12px; font-size: 14px;">¿Desfijar el BOM <strong id="desfijar_boom_nombre"></strong> para editarlo?</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=proyectos" id="formDesfijarBoom">
                    <input type="hidden" name="action" value="desbloquear_boom_individual">
                    <input type="hidden" name="boom_id" id="desfijar_boom_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalDesfijarBoom')">Cancelar</button>
                    <button type="submit" class="btn" style="background:#2e7d32;color:#fff;"><i class="fas fa-thumbtack"></i> Desfijar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalEditarCantidad">
        <div class="modal" style="max-width: 380px;">
            <div class="modal-header" style="background: #1976D2;">
                <div class="modal-title"><i class="fas fa-edit"></i> Editar Cantidad</div>
                <button class="modal-close" onclick="cerrarModal('modalEditarCantidad')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <p id="editar_cantidad_nombre" style="margin-bottom: 15px; font-weight: bold; font-size: 15px;"></p>
                <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:10px;">
                    <button type="button" class="btn btn-outline" onclick="ajustarCantidadEdicion(-1)" style="width:36px;height:36px;padding:0;font-size:18px;">−</button>
                    <input type="number" id="editar_cantidad_input" min="0" value="1" style="width:80px;text-align:center;font-size:18px;font-weight:bold;border:2px solid #ddd;border-radius:4px;padding:6px;">
                    <button type="button" class="btn btn-outline" onclick="ajustarCantidadEdicion(1)" style="width:36px;height:36px;padding:0;font-size:18px;">+</button>
                </div>
                <small style="color:#999;">Ponlo en 0 para eliminar el item</small>
            </div>
            <div class="modal-footer">
                <form method="POST" id="formEditarCantidad">
                    <input type="hidden" name="action" value="editar_cantidad_item">
                    <input type="hidden" name="item_id" id="editar_cantidad_item_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEditarCantidad')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalEditarItem">
        <div class="modal" style="max-width: 520px;">
            <div class="modal-header" style="background: #1976D2;">
                <div class="modal-title"><i class="fas fa-edit"></i> Editar Item BOM</div>
                <button class="modal-close" onclick="cerrarModal('modalEditarItem')">&times;</button>
            </div>
            <form method="POST" action="?seccion=boom" id="formEditarItem">
                <input type="hidden" name="action" value="editar_boom_item">
                <input type="hidden" name="item_id" id="editar_item_id" value="">
                <div class="modal-body">
                    <p id="editar_item_nombre" style="font-weight:700;font-size:15px;color:#003d5c;margin-bottom:14px;"></p>
                    <div class="form-group">
                        <label class="form-label">Cantidad</label>
                        <input type="number" name="cantidad" id="editar_item_cantidad" min="1" class="form-input" value="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Certificados <small style="color:#999;">(ej. ISO 9001, ASME)</small></label>
                        <input type="text" name="certificados" id="editar_item_certificados" class="form-input" placeholder="Certificaciones requeridas...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Observación</label>
                        <textarea name="observacion" id="editar_item_observacion" class="form-input" rows="2" placeholder="Observaciones del item..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nota libre</label>
                        <textarea name="nota_libre" id="editar_item_nota_libre" class="form-input" rows="2" placeholder="Notas adicionales..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEditarItem')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modalEliminarItem">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;">
                <div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</div>
                <button class="modal-close" onclick="cerrarModal('modalEliminarItem')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-trash" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i>
                <p id="eliminar_item_mensaje" style="margin-bottom: 20px; font-size: 16px;">¿Eliminar este item?</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=boom" id="formEliminarItem">
                    <input type="hidden" name="action" value="eliminar_item_boom">
                    <input type="hidden" name="item_id" id="eliminar_item_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEliminarItem')">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Sí, Eliminar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalMaterial">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-box"></i> <span id="materialModalTitle">Nuevo Material</span></div>
                <button class="modal-close" onclick="cerrarModal('modalMaterial')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="guardar_material">
                <input type="hidden" name="id" id="materialId">
                
                <div class="modal-body">
                    <div class="grid-2">
                        <input type="hidden" name="codigo" id="materialCodigo" value="">
                        <div class="form-group">
                            <label class="form-label">Categoría *</label>
                            <select name="categoria_id" id="materialCategoria" class="form-input" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Marca</label>
                            <input type="text" name="marca" id="materialMarca" class="form-input" placeholder="Marca" list="marcasDatalist" autocomplete="off">
                            <datalist id="marcasDatalist">
                                <?php foreach ($marcas_bom as $marca_dl): ?>
                                <option value="<?php echo htmlspecialchars($marca_dl); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Modelo</label>
                            <input type="text" name="modelo" id="materialModelo" class="form-input" placeholder="Modelo" list="modelosDatalist" autocomplete="off">
                            <datalist id="modelosDatalist"></datalist>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unidad de Medida</label>
                            <select name="unidad_medida" id="materialUnidad" class="form-input">
                                <option value="pza">Pieza</option>
                                <option value="kg">Kilogramo</option>
                                <option value="m">Metro</option>
                                <option value="l">Litro</option>
                                <option value="caja">Caja</option>
                                <option value="rollo">Rollo</option>
                            </select>
                        </div>
                        <!-- Descripción ahora tiene el * y la etiqueta required -->
                        <div class="form-group full">
                            <label class="form-label">Descripción *</label>
                            <textarea name="descripcion" id="materialDescripcion" class="form-input" rows="2" placeholder="Descripción del material" required></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Imagen</label>
                            <input type="file" name="imagen" class="form-input" accept="image/*">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalMaterial')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalCategoriasInventario">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Seleccionar Categoría</h3>
                <button class="modal-close" onclick="cerrarModal('modalCategoriasInventario')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($categorias as $cat): ?>
                    <div class="categoria-card" data-id="<?php echo $cat['id']; ?>" onclick="seleccionarCategoriaInventario(this)">
                        <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                        <div class="cat-nombre"><?php echo $cat['nombre']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="limpiarFiltroInventario()">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button class="btn" onclick="cerrarModal('modalCategoriasInventario')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroInventario()">Aplicar</button>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalCategoriasBOM">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Seleccionar Categoría</h3>
                <button class="modal-close" onclick="cerrarModal('modalCategoriasBOM')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($categorias as $cat): ?>
                    <div class="categoria-card" data-id="<?php echo $cat['id']; ?>" onclick="seleccionarCategoriaBOM(this)">
                        <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                        <div class="cat-nombre"><?php echo $cat['nombre']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="limpiarFiltroBOM()">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button class="btn" onclick="cerrarModal('modalCategoriasBOM')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroBOM()">Aplicar</button>
            </div>
        </div>
    </div>
    
    <!-- Modal Marcas BOM -->
    <div class="modal-overlay" id="modalMarcasBOM">
        <div class="modal" style="max-width:600px;">
            <div class="modal-header">
                <h3><i class="fas fa-industry"></i> Filtrar por Marca</h3>
                <button class="modal-close" onclick="cerrarModal('modalMarcasBOM')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($marcas_bom as $mrc):
                        $img_bom = getMarcaImagen($mrc, $marcas_imagenes);
                    ?>
                    <div class="categoria-card" data-marca="<?php echo strtolower(htmlspecialchars($mrc)); ?>" onclick="seleccionarMarcaBOM(this)">
                        <?php if ($img_bom): ?>
                            <img src="<?php echo $img_bom; ?>" alt="<?php echo htmlspecialchars($mrc); ?>"
                                 style="width:60px;height:44px;object-fit:contain;">
                        <?php else: ?>
                            <i class="fas fa-industry"></i>
                        <?php endif; ?>
                        <div class="cat-nombre"><?php echo htmlspecialchars($mrc); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="limpiarFiltroMarcaBOM()">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button class="btn" onclick="cerrarModal('modalMarcasBOM')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroMarcaBOM()">Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Feature 7: Modal Importar CSV -->
    <div class="modal-overlay" id="modalImportarCSV">
        <div class="modal" style="max-width:520px;">
            <div class="modal-header" style="background:#1565c0;">
                <div class="modal-title" style="color:#fff;"><i class="fas fa-file-import"></i> Importar BOM desde CSV</div>
                <button class="modal-close" onclick="cerrarModal('modalImportarCSV')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="?seccion=boom&proyecto_boom=<?php echo $proyecto_boom_id; ?>&boom_id=<?php echo $boom_lista_id; ?>">
                <input type="hidden" name="action" value="importar_bom_csv">
                <input type="hidden" name="boom_id_import" value="<?php echo $boom_lista_id; ?>">
                <input type="hidden" name="proyecto_id_import" value="<?php echo $proyecto_boom_id; ?>">
                <div class="modal-body">
                    <div style="background:#e3f2fd;border:1px solid #90caf9;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:#1565c0;">
                        <strong>Formato CSV requerido:</strong>
                        <code style="display:block;margin-top:4px;font-size:.78rem;">codigo,descripcion,cantidad,marca,modelo,observacion</code>
                        La primera fila es el encabezado (se omite). El código busca el material en el catálogo.
                        <a href="api/descargar_plantilla_csv.php" style="display:inline-block;margin-top:6px;color:#1565c0;font-weight:700;font-size:.78rem;">
                            <i class="fas fa-download"></i> Descargar plantilla CSV
                        </a>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Archivo CSV (.csv)</label>
                        <input type="file" name="csv_file" class="form-input" accept=".csv,.txt" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalImportarCSV')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Importar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Feature 3: Modal Guardar como Plantilla -->
    <div class="modal-overlay" id="modalGuardarPlantilla">
        <div class="modal" style="max-width:440px;">
            <div class="modal-header" style="background:#5D4037;">
                <div class="modal-title" style="color:#fff;"><i class="fas fa-layer-group"></i> Guardar BOM como Plantilla</div>
                <button class="modal-close" onclick="cerrarModal('modalGuardarPlantilla')">&times;</button>
            </div>
            <form method="POST" action="?seccion=boom">
                <input type="hidden" name="action" value="guardar_plantilla">
                <input type="hidden" name="boom_id_tpl" value="<?php echo $boom_lista_id; ?>">
                <div class="modal-body">
                    <p style="font-size:.85rem;color:#555;margin-bottom:14px;">
                        Guarda este BOM como una plantilla reutilizable en futuros proyectos.
                    </p>
                    <div class="form-group">
                        <label class="form-label">Nombre de la plantilla *</label>
                        <input type="text" name="nombre_plantilla" class="form-input" placeholder="Ej. BOM Tablero Eléctrico Tipo A" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Descripción (opcional)</label>
                        <textarea name="desc_plantilla" class="form-input" rows="2" placeholder="Describe cuándo usar esta plantilla..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalGuardarPlantilla')">Cancelar</button>
                    <button type="submit" class="btn" style="background:#5D4037;color:#fff;"><i class="fas fa-save"></i> Guardar Plantilla</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Feature 3: Modal Cargar desde Plantilla -->
    <?php if (!empty($plantillas_bom)): ?>
    <div class="modal-overlay" id="modalCargarPlantilla">
        <div class="modal" style="max-width:560px;">
            <div class="modal-header" style="background:#1B5E20;">
                <div class="modal-title" style="color:#fff;"><i class="fas fa-layer-group"></i> Cargar desde Plantilla</div>
                <button class="modal-close" onclick="cerrarModal('modalCargarPlantilla')">&times;</button>
            </div>
            <form method="POST" action="?seccion=boom&proyecto_boom=<?php echo $proyecto_boom_id; ?>&boom_id=<?php echo $boom_lista_id; ?>" id="formCargarPlantilla">
                <input type="hidden" name="action" value="cargar_plantilla">
                <input type="hidden" name="boom_id_lp" value="<?php echo $boom_lista_id; ?>">
                <input type="hidden" name="proyecto_id_lp" value="<?php echo $proyecto_boom_id; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Selecciona una Plantilla</label>
                        <select name="plantilla_id" class="form-input" onchange="cargarItemsPlantilla(this.value)">
                            <option value="">-- Elige una plantilla --</option>
                            <?php foreach ($plantillas_bom as $pt): ?>
                            <option value="<?php echo $pt['id']; ?>"><?php echo htmlspecialchars($pt['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="plantillaItemsContainer"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalCargarPlantilla')">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-plus-circle"></i> Agregar al BOM</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal-overlay" id="modalReutilizar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #4CAF50;">
                <div class="modal-title"><i class="fas fa-copy"></i> Reutilizar BOM</div>
                <button class="modal-close" onclick="cerrarModal('modalReutilizar')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-copy" style="font-size: 48px; color: #4CAF50; margin: 20px 0;"></i>
                <p style="margin-bottom: 15px; font-size: 16px;">¿Reutilizar el BOM?</p>
                <p style="font-size: 13px; color: #666; margin-bottom: 20px;">Se creará una copia del BOM seleccionado para trabajar en él.</p>
                <form method="POST" action="?seccion=<?php echo htmlspecialchars($seccion); ?>" id="formReutilizar">
                    <input type="hidden" name="action" value="reutilizar_boom">
                    <input type="hidden" name="proyecto_id" id="reutilizar_proyecto_id" value="">
                    <input type="hidden" name="nuevo_nombre" id="reutilizar_nuevo_nombre" value="">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="cerrarModal('modalReutilizar')">Cancelar</button>
                <button class="btn btn-success" onclick="confirmarReutilizar()">Sí, Reutilizar</button>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalCantidad">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <h3><i class="fas fa-calculator"></i> Cantidad</h3>
                <button class="modal-close" onclick="cerrarModal('modalCantidad')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <h4 id="nombreMaterialCantidad"></h4>
                <p style="color: #666;">Stock disponible: <span id="stockDisponible"></span></p>
                <div class="cantidad-wrapper">
                    <button class="cantidad-btn" onclick="ajustarCantidadModal(-1)">-</button>
                    <input type="number" class="cantidad-input" id="cantidadModalInput" value="1" min="1" onchange="validarCantidadModal()" onkeyup="validarCantidadModal()">
                    <button class="cantidad-btn" onclick="ajustarCantidadModal(1)">+</button>
                </div>
                <p id="errorCantidad" style="color: #f44336; font-size: 12px; margin-top: 10px; display: none;">
                    <i class="fas fa-exclamation-circle"></i> La cantidad excede el stock disponible
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="cerrarModal('modalCantidad')">Cancelar</button>
                <button class="btn btn-success" onclick="confirmarCantidad()" id="btnConfirmarCantidad">
                    <i class="fas fa-check"></i> Agregar
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let categoriaSeleccionadaInventario = null;
        let categoriaSeleccionadaBOM = null;
        let marcaSeleccionadaBOM = null;
        let materialSeleccionado = null;
        let materialStockActual = 0;
        
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                return false;
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('dragstart', (e) => e.preventDefault());
            });
            
            initSearch('inventario');
            initSearch('bom');
            initSearch('proyectos');
        });

        // "bom" es una sigla (id="searchBOM"/"clearSearchBOM", toda en mayúsculas) — capitalizar
        // solo la primera letra da "Bom" y nunca encuentra el elemento, dejando el buscador de
        // materiales del BOM sin ningún listener (el filtro nunca se ejecutaba, con o sin texto).
        function seccionIdSufijo(section) {
            return section === 'bom' ? 'BOM' : (section.charAt(0).toUpperCase() + section.slice(1));
        }

        function initSearch(section) {
            const input = document.getElementById(`search${seccionIdSufijo(section)}`);
            const clearBtn = document.getElementById(`clearSearch${seccionIdSufijo(section)}`);

            if (!input) return;

            const aplicarBusqueda = function() {
                if (input.value.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }
                if (section === 'inventario') {
                    filtrarInventario(input.value);
                } else if (section === 'bom') {
                    filtrarBOM(input.value);
                } else if (section === 'proyectos') {
                    filtrarProyectos(input.value);
                }
            };

            input.addEventListener('input', aplicarBusqueda);

            // El navegador puede restaurar el texto del buscador (autocompletado/back-forward)
            // sin disparar el evento "input" — si ya trae valor al cargar, aplicamos el filtro
            // de una vez para que la lista no se quede desincronizada del texto visible.
            if (input.value.length > 0) aplicarBusqueda();
        }
        
        function clearSearch(section) {
            const input = document.getElementById(`search${seccionIdSufijo(section)}`);
            const clearBtn = document.getElementById(`clearSearch${seccionIdSufijo(section)}`);
            
            if (input) {
                input.value = '';
                clearBtn.classList.remove('visible');
                
                if (section === 'inventario') {
                    filtrarInventario('');
                } else if (section === 'bom') {
                    filtrarBOM('');
                } else if (section === 'proyectos') {
                    filtrarProyectos('');
                }
            }
        }
        
        function aplicarFiltrosInventario() {
            var searchEl = document.getElementById('searchInventario');
            var termino  = searchEl ? searchEl.value.toLowerCase() : '';
            var catId    = categoriaSeleccionadaInventario;
            var carpId   = carpetaActivaId;

            document.querySelectorAll('#inventarioGrid .material-card').forEach(function(card) {
                var matchSearch  = true;
                var matchCat     = true;
                var matchCarpeta = true;

                if (termino) {
                    var desc = card.dataset.descripcion || '';
                    var cod  = card.dataset.codigo      || '';
                    matchSearch = desc.includes(termino) || cod.includes(termino);
                }
                if (catId) {
                    matchCat = (card.dataset.categoria == catId);
                }
                if (carpId === 0 || carpId === '0') {
                    matchCarpeta = true;
                } else if (carpId === -1 || carpId === '-1') {
                    matchCarpeta = (!card.dataset.carpeta || card.dataset.carpeta === '');
                } else {
                    matchCarpeta = (card.dataset.carpeta === String(carpId));
                }

                card.style.display = (matchSearch && matchCat && matchCarpeta) ? '' : 'none';
            });
        }

        function filtrarInventario(termino) {
            aplicarFiltrosInventario();
        }

        function filtrarBOM(termino) {
            aplicarFiltrosBOM();
        }

        // ── Materiales del BOM: datos + render en bloques ───────────────────
        // Mismo patrón ya usado en Generar Requisiciones: los materiales viajan
        // como JSON (no como HTML repetido), y las tarjetas se arman en el
        // navegador solo para el bloque que se ve.
        // bomDatosMateriales solo existe cuando $seccion === 'boom' con un BOM
        // activo -- en cualquier otra sección de esta misma página este script
        // sigue corriendo, así que hay que evitar tronar aquí y romper todo lo
        // que se define después en este mismo bloque <script>.
        const bomDatosMaterialesEl = document.getElementById('bomDatosMateriales');
        const bomMatTodos = bomDatosMaterialesEl ? JSON.parse(bomDatosMaterialesEl.textContent) : [];
        const bomCantidades = {}; // { materialId: cantidad }, sobrevive aunque la tarjeta se deje de renderizar
        const BOM_MAT_CHUNK = 60;
        let bomMatFiltrados = bomMatTodos;
        let bomMatRenderizados = 0;

        function bomMatEscapar(valor) {
            return String(valor == null ? '' : valor)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function bomMatTarjetaHtml(mat) {
            const cantidad = bomCantidades[mat.id] || 0;
            let imgHtml;
            if (mat.imgMarca) {
                imgHtml = '<img src="' + bomMatEscapar(mat.imgMarca) + '" alt="' + bomMatEscapar(mat.marca) + '" style="object-fit:contain;padding:6px;">';
            } else if (mat.imagen) {
                imgHtml = '<img src="' + bomMatEscapar(mat.imagen) + '" alt="' + bomMatEscapar(mat.nombre) + '">';
            } else {
                imgHtml = '<i class="fas fa-box"></i>';
            }
            return '<div class="material-card" data-id="' + mat.id + '">'
                + '<div class="material-imagen">' + imgHtml + '</div>'
                + '<div class="material-content">'
                + '<div class="material-nombre">' + bomMatEscapar(mat.nombre) + '</div>'
                + (mat.categoriaNombre ? '<div style="font-size:10px;color:#7b1fa2;font-weight:600;margin-bottom:3px;"><i class="fas fa-tag"></i> ' + bomMatEscapar(mat.categoriaNombre) + '</div>' : '')
                + '<div class="material-detalles">'
                + '<span><strong>Marca:</strong> ' + bomMatEscapar(mat.marca || '-') + '</span>'
                + '<span><strong>Modelo:</strong> ' + bomMatEscapar(mat.modelo || '-') + '</span>'
                + '</div>'
                + '<div class="cantidad-control">'
                + '<span class="cantidad-valor" id="cantidad-' + mat.id + '" onclick="editarCantidadFicha(this, ' + mat.id + ')" title="Clic para escribir la cantidad">' + cantidad + '</span>'
                + '<div class="cantidad-botones">'
                + '<button type="button" onclick="cambiarCantidad(' + mat.id + ', -1)">-</button>'
                + '<button type="button" onclick="cambiarCantidad(' + mat.id + ', 1)">+</button>'
                + '</div></div></div></div>';
        }

        function bomMatRenderizarChunk() {
            const grid = document.getElementById('materialesBOMGrid');
            const siguientes = bomMatFiltrados.slice(bomMatRenderizados, bomMatRenderizados + BOM_MAT_CHUNK);
            grid.insertAdjacentHTML('beforeend', siguientes.map(bomMatTarjetaHtml).join(''));
            bomMatRenderizados += siguientes.length;
            const wrap = document.getElementById('bomMatCargarMasWrap');
            const restantes = bomMatFiltrados.length - bomMatRenderizados;
            if (restantes <= 0) {
                wrap.style.display = 'none';
            } else {
                wrap.style.display = '';
                document.getElementById('bomMatCargarMasBtn').textContent = 'Cargar más materiales (' + restantes + ' restantes)';
            }
        }

        function bomMatCargarMas() { bomMatRenderizarChunk(); }

        function bomSetCantidad(materialId, cantidad) {
            if (cantidad > 0) bomCantidades[materialId] = cantidad; else delete bomCantidades[materialId];
            const span = document.getElementById('cantidad-' + materialId);
            if (span) span.textContent = cantidad;
        }

        // Pinta el primer bloque al cargar la página -- initSearch('bom') solo
        // repinta si el buscador YA trae texto (restaurado por el navegador),
        // así que en una carga normal (buscador vacío) nadie más llama esto.
        if (bomDatosMaterialesEl) aplicarFiltrosBOM();
        
function filtrarProyectos(termino) {
            const folders = document.querySelectorAll('#proyectosContent .proy-folder');
            termino = termino.toLowerCase();
            folders.forEach(f => {
                const nombre = f.dataset.nombre || '';
                const desc   = f.dataset.desc   || '';
                f.style.display = (nombre.includes(termino) || desc.includes(termino)) ? '' : 'none';
            });
        }

        function toggleFolder(id) {
            const folder = document.getElementById('folder-' + id);
            if (folder) folder.classList.toggle('open');
        }

        function abrirModalCategoriasInventario() {
            document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected'));
            categoriaSeleccionadaInventario = null;
            document.getElementById('modalCategoriasInventario').classList.add('active');
        }
        
        function abrirModalCategoriasBOM() {
            document.querySelectorAll('#modalCategoriasBOM .categoria-card').forEach(c => c.classList.remove('selected'));
            categoriaSeleccionadaBOM = null;
            document.getElementById('modalCategoriasBOM').classList.add('active');
        }
        
        function seleccionarCategoriaInventario(card) {
            document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            categoriaSeleccionadaInventario = card.dataset.id;
        }
        
        function seleccionarCategoriaBOM(card) {
            document.querySelectorAll('#modalCategoriasBOM .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            categoriaSeleccionadaBOM = card.dataset.id;
        }
        
        function aplicarFiltroInventario() {
            cerrarModal('modalCategoriasInventario');
            aplicarFiltrosInventario();
        }
        
        function aplicarFiltrosBOM() {
            var termino = (document.getElementById('searchBOM')?.value || '').toLowerCase();
            bomMatFiltrados = bomMatTodos.filter(function(mat) {
                var matchCat = !categoriaSeleccionadaBOM || String(mat.categoriaId) === String(categoriaSeleccionadaBOM);
                var matchMarca = !marcaSeleccionadaBOM || (mat.marca || '').toLowerCase() === String(marcaSeleccionadaBOM).toLowerCase();
                var matchSearch = !termino || (mat.nombre || '').toLowerCase().includes(termino) || (mat.codigo || '').toLowerCase().includes(termino);
                return matchCat && matchMarca && matchSearch;
            });
            bomMatRenderizados = 0;
            document.getElementById('materialesBOMGrid').innerHTML = '';
            document.getElementById('bomMatSinResultados').style.display = bomMatFiltrados.length === 0 ? 'block' : 'none';
            bomMatRenderizarChunk();
        }

        function aplicarFiltroBOM() {
            cerrarModal('modalCategoriasBOM');
            aplicarFiltrosBOM();
        }

        function limpiarFiltroInventario() {
            categoriaSeleccionadaInventario = null;
            document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected'));
            aplicarFiltroInventario();
            cerrarModal('modalCategoriasInventario');
        }

        function limpiarFiltroBOM() {
            categoriaSeleccionadaBOM = null;
            document.querySelectorAll('#modalCategoriasBOM .categoria-card').forEach(c => c.classList.remove('selected'));
            aplicarFiltroBOM();
            cerrarModal('modalCategoriasBOM');
        }

        function abrirModalMarcasBOM() {
            document.querySelectorAll('#modalMarcasBOM .categoria-card').forEach(c => c.classList.remove('selected'));
            marcaSeleccionadaBOM = null;
            document.getElementById('modalMarcasBOM').classList.add('active');
        }

        function seleccionarMarcaBOM(card) {
            document.querySelectorAll('#modalMarcasBOM .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            marcaSeleccionadaBOM = card.dataset.marca;
        }

        function aplicarFiltroMarcaBOM() {
            cerrarModal('modalMarcasBOM');
            aplicarFiltrosBOM();
        }

        function limpiarFiltroMarcaBOM() {
            marcaSeleccionadaBOM = null;
            document.querySelectorAll('#modalMarcasBOM .categoria-card').forEach(c => c.classList.remove('selected'));
            aplicarFiltroMarcaBOM();
            cerrarModal('modalMarcasBOM');
        }

        // Feature 1: chip filter (instant, no modal needed)
        function filtrarChipBOM(chip, catId) {
            document.querySelectorAll('.cat-chip-bom').forEach(c => {
                c.style.background = '#fff'; c.style.color = '#555'; c.style.borderColor = '#e0e0e0';
            });
            chip.style.background = '#005686'; chip.style.color = '#fff'; chip.style.borderColor = '#005686';
            const cards = document.querySelectorAll('#materialesBOMGrid .material-card');
            cards.forEach(card => {
                card.style.display = (!catId || card.dataset.categoria == catId) ? '' : 'none';
            });
        }

        // Feature 7: Import CSV modal
        function abrirModalImportarCSV() {
            document.getElementById('modalImportarCSV').classList.add('active');
        }

        // Feature 3: Plantillas modals
        function abrirModalPlantilla() {
            document.getElementById('modalCargarPlantilla').classList.add('active');
        }
        function abrirModalGuardarPlantilla() {
            document.getElementById('modalGuardarPlantilla').classList.add('active');
        }

        // Feature 3: Load plantilla items via AJAX then show checkboxes
        function cargarItemsPlantilla(plantillaId) {
            if (!plantillaId) { document.getElementById('plantillaItemsContainer').innerHTML = ''; return; }
            fetch('api/get_plantilla_items.php?id=' + plantillaId)
                .then(r => r.json())
                .then(data => {
                    if (!data.items || !data.items.length) {
                        document.getElementById('plantillaItemsContainer').innerHTML = '<p style="color:#999;">Esta plantilla no tiene items.</p>';
                        return;
                    }
                    let html = '<div style="max-height:260px;overflow-y:auto;margin-top:10px;">';
                    html += '<label style="font-size:.8rem;font-weight:700;color:#555;display:block;margin-bottom:6px;">Selecciona los items a copiar:</label>';
                    data.items.forEach(it => {
                        html += `<label style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:.82rem;cursor:pointer;">
                            <input type="checkbox" name="tpl_items[]" value="${it.id}" checked>
                            <span><strong>${it.descripcion}</strong> — ${it.cantidad} u. ${it.marca ? '| '+it.marca : ''}</span>
                        </label>`;
                    });
                    html += '</div>';
                    document.getElementById('plantillaItemsContainer').innerHTML = html;
                })
                .catch(() => { document.getElementById('plantillaItemsContainer').innerHTML = '<p style="color:#c00;">Error al cargar items.</p>'; });
        }

        function cambiarCantidad(materialId, delta) {
            const actual = bomCantidades[materialId] || 0;
            bomSetCantidad(materialId, Math.max(0, actual + delta));
        }

        function editarCantidadFicha(span, materialId) {
            if (span.querySelector('input')) return;
            const actual = span.textContent.trim();
            span.innerHTML = '<input type="number" min="0" value="' + actual + '" style="width:44px;text-align:center;font-size:inherit;font-weight:inherit;border:1px solid #ccc;border-radius:4px;padding:1px 2px;" onclick="event.stopPropagation()" onblur="guardarCantidadFicha(this, ' + materialId + ')" onkeydown="if(event.key===\'Enter\'){this.blur();}">';
            const inp = span.querySelector('input');
            inp.focus();
            inp.select();
        }

        function guardarCantidadFicha(inputEl, materialId) {
            let cantidad = parseInt(inputEl.value, 10);
            if (isNaN(cantidad) || cantidad < 0) cantidad = 0;
            bomSetCantidad(materialId, cantidad);
            const span = document.getElementById('cantidad-' + materialId);
            if (span) span.setAttribute('onclick', 'editarCantidadFicha(this, ' + materialId + ')');
        }
        
        function agregarSeleccionados() {
    // Arma los <input> de items[] justo antes de enviar, a partir de
    // bomCantidades -- ya no depende de qué tarjetas están renderizadas en ese
    // momento (un material pudo quedar seleccionado y luego "desaparecer" del
    // DOM por un cambio de filtro o por paginado en bloques).
    const idsConCantidad = Object.keys(bomCantidades).filter(function(id) { return bomCantidades[id] > 0; });

    if (idsConCantidad.length === 0) {
        mostrarAlertaBOM('Selecciona al menos un material con cantidad mayor a 0.');
        return;
    }

    const form = document.getElementById('boomForm');
    form.querySelectorAll('input[name^="items["]').forEach(function(el) { el.remove(); });
    idsConCantidad.forEach(function(id) {
        const inpMaterial = document.createElement('input');
        inpMaterial.type = 'hidden'; inpMaterial.name = 'items[' + id + '][material_id]'; inpMaterial.value = id;
        const inpCantidad = document.createElement('input');
        inpCantidad.type = 'hidden'; inpCantidad.name = 'items[' + id + '][cantidad]'; inpCantidad.value = bomCantidades[id];
        form.appendChild(inpMaterial);
        form.appendChild(inpCantidad);
    });
    form.submit();
}

        function abrirModalReutilizar(id, nombre) {
            document.getElementById('reutilizar_proyecto_id').value = id;
            document.getElementById('reutilizar_nuevo_nombre').value = nombre;
            document.getElementById('modalReutilizar').classList.add('active');
        }
        
        function confirmarReutilizar() {
            document.getElementById('formReutilizar').submit();
        }
        
        function abrirModalProyecto() {
            document.getElementById('modalProyecto').classList.add('active');
        }
        
        function editarProyecto(id, nombre, descripcion) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nombre').value = nombre;
            document.getElementById('edit_descripcion').value = descripcion;
            document.getElementById('modalEditarProyecto').classList.add('active');
        }
        
        function eliminarProyecto(id, nombre) {
            document.getElementById('eliminar_id').value = id;
            document.getElementById('eliminar_mensaje').textContent = '¿Estás seguro de eliminar el proyecto "' + nombre + '"?';
            document.getElementById('modalEliminar').classList.add('active');
        }
        
        function mostrarAlertaBOM(mensaje) {
            document.getElementById('modalAlertaBOM_mensaje').textContent = mensaje;
            document.getElementById('modalAlertaBOM').classList.add('active');
        }

        function confirmarGuardarBoom(proyectoId, boomId, boomNombre) {
            const rows = document.querySelectorAll('#itemsBOMTable tr[data-item-id]');
            if (rows.length === 0) {
                mostrarAlertaBOM('El BOM no tiene materiales. Agrega al menos un material antes de guardar.');
                return;
            }
            document.getElementById('guardar_proyecto_id').value = proyectoId;
            document.getElementById('guardar_boom_id').value = boomId;
            // Sync live inputs into hidden fields before saving
            document.getElementById('guardar_boom_nombre').value   = document.getElementById('inputBoomNombre')?.value   || boomNombre;
            document.getElementById('guardar_num_equipos').value   = document.getElementById('inputNumEquipos')?.value   || 1;
            document.getElementById('guardar_mensaje').textContent = '¿Guardar el BOM "' + (document.getElementById('inputBoomNombre')?.value || boomNombre) + '"?';
            document.getElementById('modalGuardarBoom').classList.add('active');
        }

        function abrirModalCancelarBoom(proyectoId, boomId, boomNombre) {
            document.getElementById('proyecto_cancelar_id').value  = proyectoId;
            document.getElementById('boom_cancelar_id').value      = boomId;
            document.getElementById('proyecto_cancelar_nombre').textContent = boomNombre;
            document.getElementById('modalCancelarBoom').classList.add('active');
        }

        function abrirModalNuevoBoom(proyectoId, proyectoNombre) {
            document.getElementById('nuevo_boom_proyecto_id').value = proyectoId;
            document.getElementById('nuevo_boom_proyecto_nombre').textContent = proyectoNombre;
            document.getElementById('nuevo_boom_nombre').value     = '';
            document.getElementById('nuevo_boom_equipos').value    = 1;
            document.getElementById('modalNuevoBoom').classList.add('active');
        }

        function abrirModalCrearPlano(proyectoId, proyectoNombre) {
            document.getElementById('crear_plano_proyecto_id').value = proyectoId;
            document.getElementById('crear_plano_proyecto_nombre').textContent = proyectoNombre;
            document.getElementById('modalCrearPlano').classList.add('active');
        }

        function abrirModalReemplazarPlano(boomId, proyectoId, pieza) {
            document.getElementById('reemplazar_plano_boom_id').value = boomId;
            document.getElementById('reemplazar_plano_proyecto_id').value = proyectoId;
            document.getElementById('reemplazar_plano_nombre').textContent = pieza;
            document.getElementById('modalReemplazarPlano').classList.add('active');
        }

        function confirmarFinalizarProyecto(id, nombre) {
            document.getElementById('finalizar_proyecto_id').value = id;
            document.getElementById('finalizar_proyecto_nombre').textContent = nombre;
            document.getElementById('modalFinalizarProyecto').classList.add('active');
        }

        function abrirModalNuevoDoc(proyectoId) {
            document.getElementById('nd_proyecto_id').value = proyectoId;
            abrirModal('modalNuevoDoc');
        }

        function abrirSupervisorUnlock(id, nombre) {
            const el_id = document.getElementById('unlock_proy_id');
            const el_nm = document.getElementById('unlock_proy_nombre');
            if (el_id) el_id.value = id;
            if (el_nm) el_nm.textContent = nombre;
            const modal = document.getElementById('modalSupervisorUnlock');
            if (modal) modal.classList.add('active');
            else abrirModal('modalSupervisorUnlock');
        }

        function eliminarBoomLista(boomId, boomNombre) {
            if (confirm('¿Eliminar el BOM "' + boomNombre + '" y todos sus items? Esta acción no se puede deshacer.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '?seccion=proyectos';
                form.innerHTML = '<input type="hidden" name="action" value="eliminar_boom_lista">' +
                                 '<input type="hidden" name="boom_id" value="' + boomId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        let importarBoomIdActivo = null;

        function abrirModalImportarBoom(boomId, boomNombre) {
            importarBoomIdActivo = boomId;
            document.getElementById('importar_boom_nombre').textContent = boomNombre;
            document.getElementById('modalImportarBoom').classList.add('active');
        }

        function confirmarImportarBoom(tipo) {
            if (!importarBoomIdActivo) return;

            if (tipo === 'excel') {
                window.open('api/exportar_bom.php?boom_id=' + importarBoomIdActivo, '_blank');
                cerrarModal('modalImportarBoom');
                return;
            }

            // PDF: se carga en un iframe oculto y se dispara la vista de impresión nativa
            // del navegador (Guardar como PDF / Imprimir) — sin abrir ninguna pestaña.
            let frame = document.getElementById('iframeImprimirBom');
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = 'iframeImprimirBom';
                frame.style.display = 'none';
                document.body.appendChild(frame);
            }
            frame.onload = function () {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            };
            frame.src = 'api/vista_pdf_bom.php?boom_id=' + importarBoomIdActivo;
            cerrarModal('modalImportarBoom');
        }

        function abrirModalDesfijarBoom(boomId, boomNombre) {
            document.getElementById('desfijar_boom_id').value = boomId;
            document.getElementById('desfijar_boom_nombre').textContent = boomNombre;
            document.getElementById('modalDesfijarBoom').classList.add('active');
        }

        function toggleItemsBoom(boomId, btnEl) {
            const row  = document.getElementById('bom-items-row-' + boomId);
            const icon = btnEl.querySelector('i');
            if (!row) return;

            const abierto = row.style.display !== 'none';
            if (abierto) {
                row.style.display = 'none';
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
                return;
            }

            row.style.display = 'table-row';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');

            const cont = document.getElementById('bom-items-container-' + boomId);
            if (cont.dataset.loaded === '1') return;

            cont.innerHTML = '<div style="padding:10px 0;color:#999;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Cargando items...</div>';
            fetch('api/get_boms_ingenieria.php?action=items&boom_id=' + boomId)
                .then(r => r.json())
                .then(d => {
                    if (!d.success || !d.data || !d.data.length) {
                        cont.innerHTML = '<div style="padding:10px 0;color:#aaa;font-size:12px;font-style:italic;"><i class="fas fa-inbox"></i> Este BOM aún no tiene items guardados.</div>';
                        cont.dataset.loaded = '1';
                        return;
                    }
                    let html = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;">' +
                        '<thead><tr style="background:#eef3f8;">' +
                        '<th style="padding:5px 10px;text-align:left;color:#003d5c;">#</th>' +
                        '<th style="padding:5px 10px;text-align:left;color:#003d5c;">Categoría</th>' +
                        '<th style="padding:5px 10px;text-align:left;color:#003d5c;">Descripción</th>' +
                        '<th style="padding:5px 10px;text-align:left;color:#003d5c;">Marca</th>' +
                        '<th style="padding:5px 10px;text-align:left;color:#003d5c;">Modelo</th>' +
                        '<th style="padding:5px 10px;text-align:center;color:#003d5c;">Cantidad</th>' +
                        '</tr></thead><tbody>';
                    d.data.forEach((it, idx) => {
                        const total = (parseFloat(it.cantidad || 0) * parseFloat(it.num_equipos || 1));
                        html += '<tr>' +
                            '<td style="padding:4px 10px;border-top:1px solid #eee;font-weight:700;">' + (idx + 1) + '</td>' +
                            '<td style="padding:4px 10px;border-top:1px solid #eee;">' + (it.categoria_nombre ? escapeHtml(it.categoria_nombre) : '<span style="color:#aaa;">Sin categoría</span>') + '</td>' +
                            '<td style="padding:4px 10px;border-top:1px solid #eee;">' + escapeHtml(it.descripcion || '-') + '</td>' +
                            '<td style="padding:4px 10px;border-top:1px solid #eee;">' + escapeHtml(it.marca || '-') + '</td>' +
                            '<td style="padding:4px 10px;border-top:1px solid #eee;">' + escapeHtml(it.modelo || '-') + '</td>' +
                            '<td style="padding:4px 10px;border-top:1px solid #eee;text-align:center;font-weight:600;">' + total + ' ' + escapeHtml(it.unidad_medida || 'pza') + '</td>' +
                            '</tr>';
                    });
                    html += '</tbody></table>';
                    cont.innerHTML = html;
                    cont.dataset.loaded = '1';
                })
                .catch(() => {
                    cont.innerHTML = '<div style="padding:10px 0;color:#c62828;font-size:12px;">Error al cargar los items.</div>';
                });
        }

        function toggleCert(itemId, checkbox) {
            const inp = document.getElementById('cert_inp_' + itemId);
            inp.disabled = !checkbox.checked;
            inp.style.opacity = checkbox.checked ? '1' : '0.35';
            if (!checkbox.checked) {
                inp.value = '';
                guardarItemBom(itemId);
            }
        }

        const CATDOC_COLORES = {
            '': '#888',
            'componente_principal': '#c62828',
            'conector': '#1565c0',
            'material_electrico': '#2e7d32',
            'tornilleria': '#000000',
        };

        function guardarCategoriaDoc(itemId, valor) {
            const sel = document.getElementById('catdoc_' + itemId);
            if (sel) sel.style.color = CATDOC_COLORES[valor] || '#888';
            fetch('api/guardar_item_bom.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({item_id: itemId, categoria_doc: valor})
            }).catch(() => {});
        }

        function guardarItemBom(itemId) {
            const certChk = document.getElementById('cert_chk_' + itemId);
            const certInp = document.getElementById('cert_inp_' + itemId);
            const notaInp = document.getElementById('nota_inp_' + itemId);
            const certificados = (certChk && certChk.checked) ? (certInp ? certInp.value.trim() : '') : '';
            const nota_libre   = notaInp ? notaInp.value.trim() : '';

            fetch('api/guardar_item_bom.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({item_id: itemId, certificados, nota_libre})
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    const saved = document.getElementById('saved_' + itemId);
                    if (saved) {
                        saved.style.display = 'inline';
                        setTimeout(() => saved.style.display = 'none', 2000);
                    }
                }
            }).catch(() => {});
        }

        function editarCantidadInline(td, itemId) {
            if (td.querySelector('input')) return;
            const actual = td.dataset.cantidad;
            td.innerHTML = '<input type="number" min="1" value="' + actual + '" style="width:60px;text-align:center;" onblur="guardarCantidadInline(this, ' + itemId + ')" onkeydown="if(event.key===\'Enter\'){this.blur();}">';
            const inp = td.querySelector('input');
            inp.focus();
            inp.select();
        }

        function guardarCantidadInline(input, itemId) {
            const td = input.parentElement;
            const anterior = td.dataset.cantidad;
            const nueva = parseInt(input.value, 10);
            if (!nueva || nueva <= 0) {
                td.textContent = anterior;
                return;
            }
            fetch('api/guardar_item_bom.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({item_id: itemId, cantidad: nueva})
            })
            .then(r => r.json())
            .then(() => {
                td.textContent = nueva;
                td.dataset.cantidad = nueva;
                td.setAttribute('onclick', 'editarCantidadInline(this, ' + itemId + ')');
            })
            .catch(() => { td.textContent = anterior; });
        }

        function guardarRevisionBom(boomId, valor) {
            let revision = parseInt(valor, 10);
            if (isNaN(revision) || revision < 0) revision = 0;
            fetch('api/guardar_revision_bom.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({boom_id: boomId, revision: revision})
            })
            .then(r => r.json())
            .then(d => {
                const inp = document.getElementById('boomRevisionInput');
                if (inp) inp.value = (d && d.revision !== undefined) ? d.revision : revision;
            });
        }

        function guardarTipoValvulaBom(boomId, valor) {
            fetch('api/guardar_tipo_valvula_bom.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({boom_id: boomId, tipo_valvula: valor})
            });
        }

        function abrirModalEliminarItem(itemId, itemNombre) {
            document.getElementById('eliminar_item_id').value = itemId;
            document.getElementById('eliminar_item_mensaje').textContent = '¿Eliminar el item "' + itemNombre + '"?';
            document.getElementById('modalEliminarItem').classList.add('active');
        }

        function abrirModalEditarCantidad(itemId, nombre, cantidadActual) {
            document.getElementById('editar_cantidad_item_id').value = itemId;
            document.getElementById('editar_cantidad_nombre').textContent = nombre;
            document.getElementById('editar_cantidad_input').value = cantidadActual;
            document.getElementById('modalEditarCantidad').classList.add('active');
        }

        function ajustarCantidadEdicion(delta) {
            const input = document.getElementById('editar_cantidad_input');
            const nuevo = parseInt(input.value) + delta;
            if (nuevo >= 0) input.value = nuevo;
        }

        document.getElementById('formEditarCantidad')?.addEventListener('submit', function() {
            const qty = parseInt(document.getElementById('editar_cantidad_input').value);
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'nueva_cantidad';
            hidden.value = qty;
            this.appendChild(hidden);
        });
        
        function cerrarModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        function abrirModalMaterial() {
            document.getElementById('materialModalTitle').textContent = 'Nuevo Material';
            document.getElementById('materialId').value = '';
            document.querySelector('#modalMaterial form').reset();
            document.getElementById('modalMaterial').classList.add('active');
        }

        // Buscador de modelos ya existentes en el catálogo (1547 distintos, 2026-08-25) --
        // se filtra en el servidor conforme se escribe, para detectar que un modelo ya
        // existe antes de darlo de alta duplicado con otra capitalización/espacios.
        (function() {
            const campo = document.getElementById('materialModelo');
            const dl = document.getElementById('modelosDatalist');
            if (!campo || !dl) return;
            let timer = null;
            campo.addEventListener('input', function() {
                clearTimeout(timer);
                const q = campo.value.trim();
                if (q.length < 2) { dl.innerHTML = ''; return; }
                timer = setTimeout(function() {
                    fetch('api/materiales.php?action=buscar_modelos&q=' + encodeURIComponent(q))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            dl.innerHTML = (data || []).map(function(m) {
                                const d = document.createElement('div');
                                d.textContent = m;
                                return '<option value="' + d.innerHTML.replace(/"/g, '&quot;') + '">';
                            }).join('');
                        })
                        .catch(function() {});
                }, 300);
            });
        })();

        function seleccionarMaterial(card, id, stock) {
            document.querySelectorAll('.material-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            materialSeleccionado = id;
            materialStockActual = stock;
            
            const nombre = card.querySelector('.material-nombre').textContent;
            
            abrirModalCantidad(nombre, stock);
        }
        
        function abrirModalCantidad(nombre, stock) {
            document.getElementById('nombreMaterialCantidad').textContent = nombre;
            document.getElementById('stockDisponible').textContent = stock;
            document.getElementById('cantidadModalInput').value = 1;
            document.getElementById('cantidadModalInput').max = stock;
            document.getElementById('errorCantidad').style.display = 'none';
            document.getElementById('btnConfirmarCantidad').disabled = false;
            document.getElementById('modalCantidad').classList.add('active');
        }
        
        function validarCantidadModal() {
            const input = document.getElementById('cantidadModalInput');
            const cantidad = parseInt(input.value);
            const stockDisponible = materialStockActual;
            const errorMsg = document.getElementById('errorCantidad');
            const btnConfirmar = document.getElementById('btnConfirmarCantidad');
            
            if (cantidad > stockDisponible) {
                errorMsg.style.display = 'block';
                btnConfirmar.disabled = true;
            } else if (cantidad < 1) {
                errorMsg.textContent = 'La cantidad debe ser mayor a 0';
                errorMsg.style.display = 'block';
                btnConfirmar.disabled = true;
            } else {
                errorMsg.style.display = 'none';
                btnConfirmar.disabled = false;
            }
        }
        
        function ajustarCantidadModal(delta) {
            const input = document.getElementById('cantidadModalInput');
            const stockDisponible = materialStockActual;
            const nuevo = parseInt(input.value) + delta;
            if (nuevo >= 1 && nuevo <= stockDisponible) {
                input.value = nuevo;
                validarCantidadModal();
            }
        }
        
        function confirmarCantidad() {
            cerrarModal('modalCantidad');
        }
        
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });
        function toggleMaximize(panelId) {
    const panelMateriales = document.getElementById('panelMateriales');
    const panelTabla = document.getElementById('panelTabla');
    
    // Identificar cuál panel fue clickeado y cuál es el "otro"
    const targetPanel = document.getElementById(panelId);
    const otherPanel = (panelId === 'panelMateriales') ? panelTabla : panelMateriales;

    const isAlreadyMaximized = targetPanel.classList.contains('maximized');

    if (isAlreadyMaximized) {
        // Si ya estaba maximizado, lo restauramos a 50/50
        targetPanel.classList.remove('maximized');
        otherPanel.classList.remove('minimized');
        
        // Cambiar ícono a expandir
        targetPanel.querySelector('.btn-maximize i').className = 'fas fa-expand-alt';
        otherPanel.querySelector('.btn-maximize i').className = 'fas fa-expand-alt';
    } else {
        // Maximizar el target y desaparecer el otro
        targetPanel.classList.add('maximized');
        otherPanel.classList.add('minimized');
        
        // Cambiar ícono a contraer
        targetPanel.querySelector('.btn-maximize i').className = 'fas fa-compress-alt';
    }
}   

    // ===== VARIABLES DEL CHAT =====
    let chatInterval = null;
    let contactoActivo = null;
    let archivoAdjunto = null;
    let archivoTipo = null;
    let usuariosLista = [];
    let usuarioActualId = <?php echo $_SESSION['user_id']; ?>;
    let usuarioActualNombre = '<?php echo addslashes($_SESSION['user_name']); ?>';

    function actualizarEstadoConexion() {
        fetch('includes/ajax_chat.php?action=actualizar_estado')
            .then(() => {
                const dot = document.getElementById('chatStatusDot');
                if (dot) {
                    dot.classList.remove('offline', 'away');
                    dot.classList.add('online');
                }
            });
    }

    function iniciarPollingChat() {
        if (chatInterval) clearInterval(chatInterval);
        chatInterval = setInterval(verificarNuevosMensajes, 5000);
        setInterval(actualizarEstadoConexion, 30000);
        actualizarEstadoConexion();
        cargarUsuarios();
    }

    function verificarNuevosMensajes() {
        fetch('includes/ajax_chat.php?action=obtener_nuevos')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const totalNoLeidos = (data.total_no_leidos || 0) + (data.notificaciones ? data.notificaciones.length : 0);
                const badge = document.getElementById('chatNotificationBadge');

                if (totalNoLeidos > 0) {
                    badge.textContent = totalNoLeidos > 99 ? '99+' : totalNoLeidos;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
                
                if (data.contactos_con_no_leidos && usuariosLista.length) {
                    actualizarBadgesUsuarios(data.contactos_con_no_leidos);
                }
                
                if (document.getElementById('chatWindow').classList.contains('open')) {
                    if (document.querySelector('.chat-tab.active').getAttribute('data-tab') === 'conversaciones' && contactoActivo) {
                        cargarHistorial(contactoActivo);
                    } else if (document.querySelector('.chat-tab.active').getAttribute('data-tab') === 'notificaciones') {
                        cargarNotificaciones();
                    }
                    if (contactoActivo) {
                        marcarMensajesComoLeidos(contactoActivo);
                    }
                }
            }
        });
    }

    function actualizarBadgesUsuarios(noLeidosPorContacto) {
        const items = document.querySelectorAll('.chat-user-item');
        items.forEach(item => {
            const userId = parseInt(item.dataset.id);
            const noLeidos = noLeidosPorContacto[userId] || 0;
            let badgeSpan = item.querySelector('.chat-user-badge');
            if (noLeidos > 0) {
                if (!badgeSpan) {
                    badgeSpan = document.createElement('span');
                    badgeSpan.className = 'chat-user-badge';
                    item.querySelector('.chat-user-info').appendChild(badgeSpan);
                }
                badgeSpan.textContent = noLeidos;
                badgeSpan.style.display = 'inline-block';
            } else if (badgeSpan) {
                badgeSpan.style.display = 'none';
            }
        });
    }

    function toggleChat() {
        const chatWindow = document.getElementById('chatWindow');
        if (chatWindow.classList.contains('open')) {
            closeChat();
        } else {
            openChat();
        }
    }

    function openChat() {
        const chatWindow = document.getElementById('chatWindow');
        chatWindow.classList.add('open');
        cargarUsuarios();
        volverAListaUsuarios();
    }

    function closeChat() {
        const chatWindow = document.getElementById('chatWindow');
        chatWindow.classList.remove('open');
        contactoActivo = null;
    }

    function minimizeChat() {
        closeChat();
    }

    function switchChatTab(tab) {
        const tabs = document.querySelectorAll('.chat-tab');
        const panels = document.querySelectorAll('.chat-panel');
        
        tabs.forEach(t => t.classList.remove('active'));
        panels.forEach(p => p.style.display = 'none');
        
        if (tab === 'conversaciones') {
            document.querySelector('.chat-tab[data-tab="conversaciones"]').classList.add('active');
            document.getElementById('chatPanelConversaciones').style.display = 'flex';
            cargarUsuarios();
            volverAListaUsuarios();
        } else {
            document.querySelector('.chat-tab[data-tab="notificaciones"]').classList.add('active');
            document.getElementById('chatPanelNotificaciones').style.display = 'block';
            cargarNotificaciones();
        }
    }

    function volverAListaUsuarios() {
        contactoActivo = null;
        document.getElementById('vistaUsuarios').style.display = 'flex';
        document.getElementById('vistaChat').style.display = 'none';
        cargarUsuarios();
    }

    function abrirChatConUsuario(usuario) {
        contactoActivo = usuario.id;
        
        const inicial = usuario.nombre_completo.charAt(0).toUpperCase();
        document.getElementById('chatAvatar').textContent = inicial;
        document.getElementById('chatUserName').textContent = usuario.nombre_completo;
        document.getElementById('chatUserRole').textContent = usuario.tipo_usuario;
        
        const statusDot = document.getElementById('chatUserStatus');
        if (usuario.estado === 'online') {
            statusDot.className = 'chat-user-status online';
        } else {
            statusDot.className = 'chat-user-status offline';
        }
        
        document.getElementById('vistaUsuarios').style.display = 'none';
        document.getElementById('vistaChat').style.display = 'flex';
        
        cargarHistorial(usuario.id);
        marcarMensajesComoLeidos(usuario.id);
    }

    function cargarUsuarios() {
        fetch('includes/ajax_chat.php?action=obtener_contactos')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.contactos) {
                usuariosLista = data.contactos;
                renderizarUsuarios(data.contactos, data.no_leidos_por_contacto);
            }
        });
    }

    function renderizarUsuarios(usuarios, noLeidosPorContacto) {
        const container = document.getElementById('usersList');
        
        if (!usuarios || usuarios.length === 0) {
            container.innerHTML = `
                <div class="empty-chat" style="padding: 40px; text-align: center;">
                    <i class="fas fa-users"></i>
                    <p>No hay otros usuarios en el sistema</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        usuarios.forEach(usuario => {
            const noLeidos = noLeidosPorContacto ? (noLeidosPorContacto[usuario.id] || 0) : 0;
            const estadoClass = usuario.estado === 'online' ? 'online' : 'offline';
            const inicial = usuario.nombre_completo.charAt(0).toUpperCase();
            
            html += `
                <div class="chat-user-item" data-id="${usuario.id}" onclick='abrirChatConUsuario(${JSON.stringify(usuario)})'>
                    <div class="chat-user-avatar">${inicial}</div>
                    <div class="chat-user-info">
                        <div class="chat-user-name">${escapeHtml(usuario.nombre_completo)}</div>
                        <div class="chat-user-role">${escapeHtml(usuario.tipo_usuario)}</div>
                    </div>
                    <div class="chat-user-status ${estadoClass}"></div>
                    ${noLeidos > 0 ? `<span class="chat-user-badge">${noLeidos}</span>` : ''}
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    function cargarHistorial(contactoId) {
        fetch(`includes/ajax_chat.php?action=historial&con=${contactoId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.historial) {
                renderizarHistorial(data.historial);
            }
        });
    }

    function renderizarHistorial(mensajes) {
        const container = document.getElementById('chatMessagesList');
        
        if (!mensajes || mensajes.length === 0) {
            container.innerHTML = `
                <div class="empty-chat">
                    <i class="fas fa-comment-dots"></i>
                    <p>No hay mensajes aún. ¡Envía el primero!</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        mensajes.forEach(msg => {
            const esPropio = msg.es_propio;
            const hora = new Date(msg.fecha_envio).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const fecha = new Date(msg.fecha_envio).toLocaleDateString();
            
            html += `
                <div class="message-item ${esPropio ? 'message-sent' : 'message-received'}">
                    <div class="message-bubble">
                        ${!esPropio ? '<strong>' + escapeHtml(msg.remitente_nombre) + '</strong><br>' : ''}
                        ${escapeHtml(msg.mensaje)}
                    `;
            
            if (msg.archivo_ruta) {
                const esImagen = msg.archivo_tipo === 'imagen' || /\.(jpg|jpeg|png|gif|webp)$/i.test(msg.archivo_nombre);
                const esPdf = /\.pdf$/i.test(msg.archivo_nombre);
                
                if (esImagen) {
                    html += `
                        <div class="message-file" onclick="verImagen('${msg.archivo_ruta}')">
                            <i class="fas fa-image"></i>
                            <span>${escapeHtml(msg.archivo_nombre)}</span>
                            <i class="fas fa-expand" style="margin-left: auto;"></i>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="message-file">
                            <i class="fas ${esPdf ? 'fa-file-pdf' : 'fa-file-alt'}"></i>
                            <span>${escapeHtml(msg.archivo_nombre)}</span>
                        </div>
                        <div class="message-file-actions">
                            <button onclick="descargarArchivo('${msg.archivo_ruta}', '${msg.archivo_nombre}')">
                                <i class="fas fa-download"></i> Descargar
                            </button>
                            ${esPdf ? `<button onclick="verPdf('${msg.archivo_ruta}')"><i class="fas fa-eye"></i> Ver</button>` : ''}
                        </div>
                    `;
                }
            }
            
            html += `
                    </div>
                    <div class="message-time">${fecha} ${hora}</div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        container.scrollTop = container.scrollHeight;
    }

    function enviarMensaje() {
        if (!contactoActivo) return;
        
        const input = document.getElementById('chatInput');
        const mensaje = input.value.trim();
        
        if (!mensaje && !archivoAdjunto) return;
        
        const formData = new FormData();
        formData.append('action', 'enviar');
        formData.append('destinatario_id', contactoActivo);
        formData.append('mensaje', mensaje);
        if (archivoAdjunto) {
            formData.append('archivo', archivoAdjunto);
            formData.append('archivo_tipo', archivoTipo);
        }
        
        fetch('includes/ajax_chat.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                archivoAdjunto = null;
                archivoTipo = null;
                document.getElementById('chatFileInfo').innerHTML = '';
                cargarHistorial(contactoActivo);
                cargarUsuarios();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }

    function adjuntarArchivo(input, tipo) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('El archivo no puede superar los 10MB');
                input.value = '';
                return;
            }
            archivoAdjunto = file;
            archivoTipo = tipo;
            document.getElementById('chatFileInfo').innerHTML = `<i class="fas fa-paperclip"></i> ${file.name}`;
        }
    }

    function cargarNotificaciones() {
        fetch('includes/ajax_chat.php?action=obtener_notificaciones')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.notificaciones) {
                renderizarNotificaciones(data.notificaciones);
            }
        });
    }

    function confirmarModal(mensaje) {
        return new Promise(resolve => {
            const modal = document.getElementById('modalConfirmarGenerico');
            if (!modal) { resolve(confirm(mensaje)); return; }
            document.getElementById('modalConfirmarMensaje').textContent = mensaje;
            modal.classList.add('active');
            const si = document.getElementById('btnConfirmarSi');
            const no = document.getElementById('btnConfirmarNo');
            const cleanup = (val) => { modal.classList.remove('active'); si.onclick = null; no.onclick = null; resolve(val); };
            si.onclick = () => cleanup(true);
            no.onclick = () => cleanup(false);
        });
    }

    function marcarTodasLeidas() {
        fetch('includes/ajax_chat.php?action=marcar_todas_leidas')
        .then(r => r.json()).then(d => { if (d.success) cargarNotificaciones(); });
    }

    async function borrarNotificaciones() {
        const ok = await confirmarModal('¿Borrar todas las notificaciones?');
        if (!ok) return;
        fetch('includes/ajax_chat.php?action=borrar_notificaciones')
        .then(r => r.json()).then(d => { if (d.success) cargarNotificaciones(); });
    }

    function renderizarNotificaciones(notificaciones) {
        const container = document.getElementById('notificacionesList');
        
        if (!notificaciones || notificaciones.length === 0) {
            container.innerHTML = `
                <div class="empty-chat">
                    <i class="fas fa-bell-slash"></i>
                    <p>No hay notificaciones</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        notificaciones.forEach(notif => {
            const fecha = new Date(notif.fecha_creacion).toLocaleString();
            const iconos = {
                'nuevo_proyecto':    '<i class="fas fa-folder-plus"        style="color:#003d5c"></i>',
                'nuevo_boom':        '<i class="fas fa-layer-group"         style="color:#FF9800"></i>',
                'boom_guardado':     '<i class="fas fa-save"                style="color:#4CAF50"></i>',
                'nueva_requisicion': '<i class="fas fa-clipboard-list"      style="color:#7b1fa2"></i>',
                'cotizacion_enviada':'<i class="fas fa-paper-plane"         style="color:#0097a7"></i>',
                'cotizacion_recibida':'<i class="fas fa-file-invoice"       style="color:#1565c0"></i>',
                'orden_generada':    '<i class="fas fa-truck"               style="color:#e64a19"></i>',
                'proyecto_almacen':  '<i class="fas fa-warehouse"           style="color:#ff5722"></i>',
                'proyecto_guardado': '<i class="fas fa-box"                 style="color:#795548"></i>',
                'sincronizacion':    '<i class="fas fa-sync-alt"            style="color:#009688"></i>',
                'stock_insuficiente':'<i class="fas fa-exclamation-triangle" style="color:#f44336"></i>',
            };
            const icono = iconos[notif.tipo] || '<i class="fas fa-bell" style="color:#999"></i>';
            const leido = notif.leido == 1;
            html += `
                <div class="notification-item${leido ? '' : ' notif-unread'}" onclick="marcarNotificacion(${notif.id}, '${notif.link || ''}')">
                    <div class="notification-title">${icono} ${escapeHtml(notif.titulo)}</div>
                    <div class="notification-message">${escapeHtml(notif.mensaje)}</div>
                    <div class="notification-time">${fecha}</div>
                </div>
            `;
        });

        container.innerHTML = html;
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
            }
        });
    }

    function marcarMensajesComoLeidos(contactoId) {
        if (!contactoId) return Promise.resolve();
        return fetch(`includes/ajax_chat.php?action=marcar_leidos&contacto=${contactoId}`);
    }

    function handleChatKeyPress(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            enviarMensaje();
        }
    }

    const chatInputField = document.getElementById('chatInput');
    if (chatInputField) {
        chatInputField.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }

    function verImagen(ruta) {
        const modal = document.getElementById('imagePreviewModal');
        const img = document.getElementById('previewImage');
        img.src = ruta;
        modal.classList.add('active');
    }

    function cerrarPreviewImagen(event) {
        if (!event || event.target === document.getElementById('imagePreviewModal') || event.target.classList.contains('close-preview')) {
            document.getElementById('imagePreviewModal').classList.remove('active');
            document.getElementById('previewImage').src = '';
        }
    }

    function verPdf(ruta) {
        const modal = document.getElementById('filePreviewModal');
        const iframe = document.getElementById('filePreviewFrame');
        iframe.src = ruta;
        modal.classList.add('active');
    }

    function cerrarPreviewArchivo() {
        const modal = document.getElementById('filePreviewModal');
        const iframe = document.getElementById('filePreviewFrame');
        iframe.src = '';
        modal.classList.remove('active');
    }

    function descargarArchivo(ruta, nombre) {
        const link = document.createElement('a');
        link.href = ruta;
        link.download = nombre;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function() {
        iniciarPollingChat();
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarPreviewImagen();
                cerrarPreviewArchivo();
            }
        });
        
        window.addEventListener('beforeunload', function() {
            fetch('includes/ajax_chat.php?action=actualizar_estado&estado=offline', { keepalive: true });
        });
    });
    </script>
    <!-- ===== CHAT BUBBLE CON ESTADO ===== -->
    <div class="chat-bubble">
        <div class="chat-button" onclick="toggleChat()">
            <i class="fas fa-comment-dots"></i>
            <span class="chat-status" id="chatStatusDot"></span>
            <span class="notification-badge" id="chatNotificationBadge" style="display: none;">0</span>
        </div>
    </div>

    <!-- ===== CHAT WINDOW ===== -->
    <div class="chat-window" id="chatWindow">
        <div class="chat-header">
            <h4><i class="fas fa-comments"></i> Mensajes</h4>
            <div class="chat-header-actions">
                <button onclick="minimizeChat()"><i class="fas fa-minus"></i></button>
                <button onclick="closeChat()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        
        <div class="chat-tabs">
            <div class="chat-tab active" data-tab="conversaciones" onclick="switchChatTab('conversaciones')">
                <i class="fas fa-users"></i> Conversaciones
            </div>
            <div class="chat-tab" data-tab="notificaciones" onclick="switchChatTab('notificaciones')">
                <i class="fas fa-bell"></i> Notificaciones
            </div>
        </div>
        
        <!-- Panel de Conversaciones -->
        <div id="chatPanelConversaciones" class="chat-panel">
            
            <!-- VISTA DE USUARIOS (lista de contactos) -->
            <div id="vistaUsuarios" class="chat-view">
                <div class="chat-view-header">
                    <span class="chat-view-title"><i class="fas fa-users"></i> Usuarios activos</span>
                </div>
                <div class="chat-users-list" id="usersList">
                    <div class="empty-chat">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Cargando usuarios...</p>
                    </div>
                </div>
            </div>
            
            <!-- VISTA DE CHAT (conversación con un usuario) -->
            <div id="vistaChat" class="chat-conversation" style="display: none;">
                <div class="chat-conversation-header">
                    <button class="btn-back-chat" onclick="volverAListaUsuarios()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div class="chat-user-avatar" id="chatAvatar">U</div>
                    <div class="chat-user-info">
                        <div class="chat-user-name" id="chatUserName">Cargando...</div>
                        <div class="chat-user-role" id="chatUserRole"></div>
                    </div>
                    <div class="chat-user-status" id="chatUserStatus"></div>
                </div>
                <div class="chat-messages" id="chatMessagesList">
                    <div class="empty-chat">
                        <i class="fas fa-comment-dots"></i>
                        <p>No hay mensajes aún</p>
                    </div>
                </div>
                <div class="chat-input-area">
                    <div class="chat-input-row">
                        <textarea id="chatInput" placeholder="Escribe un mensaje..." rows="1" onkeypress="handleChatKeyPress(event)"></textarea>
                        <button onclick="enviarMensaje()"><i class="fas fa-paper-plane"></i></button>
                    </div>
                    <div class="chat-file-buttons">
                        <button class="chat-file-btn" onclick="document.getElementById('chatFileInput').click()" title="Adjuntar archivo">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <button class="chat-file-btn" onclick="document.getElementById('chatImageInput').click()" title="Adjuntar imagen">
                            <i class="fas fa-image"></i>
                        </button>
                        <input type="file" id="chatFileInput" style="display: none;" accept=".pdf,.xls,.xlsx,.doc,.docx,.txt" onchange="adjuntarArchivo(this, 'archivo')">
                        <input type="file" id="chatImageInput" style="display: none;" accept="image/*" onchange="adjuntarArchivo(this, 'imagen')">
                        <span id="chatFileInfo" style="font-size: 11px; color: #666;"></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Panel de Notificaciones -->
        <div id="chatPanelNotificaciones" class="chat-panel" style="display: none; overflow-y: auto; padding: 15px;">
            <div style="display:flex;gap:8px;margin-bottom:10px;">
                <button onclick="marcarTodasLeidas()" class="btn btn-sm" style="flex:1;background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;">
                    <i class="fas fa-check-double"></i> Marcar todo leído
                </button>
                <button onclick="borrarNotificaciones()" class="btn btn-sm" style="flex:1;background:#fce4ec;color:#c62828;border:none;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;">
                    <i class="fas fa-trash"></i> Borrar todo
                </button>
            </div>
            <div id="notificacionesList">
                <div class="empty-chat">
                    <i class="fas fa-bell-slash"></i>
                    <p>No hay notificaciones</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modales para vista previa de archivos -->
    <div class="image-preview-modal" id="imagePreviewModal" onclick="cerrarPreviewImagen(event)">
        <span class="close-preview" onclick="cerrarPreviewImagen()">&times;</span>
        <img id="previewImage" src="">
    </div>
    <div class="file-preview-modal" id="filePreviewModal">
        <div class="file-preview-header">
            <span><i class="fas fa-file-alt"></i> Vista previa</span>
            <button onclick="cerrarPreviewArchivo()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        <div class="file-preview-body">
            <iframe id="filePreviewFrame" src=""></iframe>
        </div>
    </div>

    <!-- ===== MODAL CONFIRMAR GENÉRICO ===== -->
    <div class="modal-overlay" id="modalConfirmarGenerico">
        <div class="modal" style="max-width:440px">
            <div class="modal-header"><h3 style="margin:0;font-size:1rem"><i class="fas fa-question-circle"></i> Confirmar</h3></div>
            <div class="modal-body"><p id="modalConfirmarMensaje" style="margin:0;line-height:1.6"></p></div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="btnConfirmarNo">Cancelar</button>
                <button class="btn btn-primary" id="btnConfirmarSi">Confirmar</button>
            </div>
        </div>
    </div>

<!-- Modal Mover Material a Carpeta -->
<div id="modalMoverCarpeta" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:3000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:90%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <h3 style="margin:0 0 1rem;color:#003d5c;font-size:1.1rem;"><i class="fas fa-folder-arrow-up"></i> Mover Material</h3>
        <p style="font-size:0.85rem;color:#666;margin-bottom:1rem;" id="moverMaterialNombre"></p>
        <select id="moverCarpetaSelect" style="width:100%;padding:0.5rem;border:1.5px solid #ddd;border-radius:6px;font-size:0.9rem;box-sizing:border-box;">
            <option value="">Sin carpeta (raíz)</option>
        </select>
        <div style="display:flex;gap:0.6rem;justify-content:flex-end;margin-top:1.2rem;">
            <button onclick="cerrarMoverCarpeta()" style="padding:0.5rem 1rem;border:1.5px solid #003d5c;background:transparent;color:#003d5c;border-radius:6px;cursor:pointer;font-size:0.9rem;">Cancelar</button>
            <button onclick="confirmarMoverMaterial()" style="padding:0.5rem 1rem;background:#003d5c;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.9rem;font-weight:600;">Mover</button>
        </div>
    </div>
</div>

<script>
/* ── Carpetas de materiales ── */
var carpetasData   = [];
var carpetaActivaId = 0; // 0 = todas, -1 = sin carpeta, N = carpeta N
var moverMaterialId = 0;

// Cargar carpetas al entrar en sección inventario
(function() {
    if (!document.getElementById('carpetasList')) return;
    fetch('api/materiales.php?action=carpetas')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            carpetasData = data.data || [];
            renderCarpetas();
        });
})();

function renderCarpetas() {
    var list = document.getElementById('carpetasList');
    if (!list) return;
    // Remove existing dynamic carpetas (keep first two static items)
    var items = list.querySelectorAll('.carpeta-item[data-dinamica]');
    items.forEach(function(el) { el.remove(); });

    carpetasData.forEach(function(c) {
        var div = document.createElement('div');
        div.className = 'carpeta-item';
        div.dataset.id = c.id;
        div.dataset.dinamica = '1';
        div.innerHTML = '<i class="fas fa-folder"></i> ' + escapeHtml(c.nombre);
        div.addEventListener('click', function() { filtrarPorCarpeta(c.id, div); });
        list.appendChild(div);
    });
}

function filtrarCarpetas(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.carpeta-item[data-dinamica]').forEach(function(el) {
        el.style.display = (!q || el.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
}

function filtrarPorCarpeta(id, el) {
    carpetaActivaId = id;
    document.querySelectorAll('.carpeta-item').forEach(function(i) { i.classList.remove('active'); });
    if (el) el.classList.add('active');
    aplicarFiltrosInventario();
}

function organizarPorMarca() {
    if (!confirm('¿Organizar automáticamente los materiales en carpetas por marca?\n\nSe crearán carpetas para cada marca y cada material se asignará a la suya.')) return;
    var fd = new FormData();
    fd.append('action', 'organizar_por_marca');
    fetch('api/materiales.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                alert('¡Listo!\n• Carpetas creadas: ' + data.carpetas_creadas + '\n• Materiales asignados: ' + data.materiales_asignados);
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'No se pudo organizar'));
            }
        });
}

function crearCarpeta() {
    var nombre = prompt('Nombre de la nueva carpeta:');
    if (!nombre || !nombre.trim()) return;

    var fd = new FormData();
    fd.append('action', 'crear_carpeta');
    fd.append('nombre', nombre.trim());

    fetch('api/materiales.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                carpetasData.push({ id: data.id, nombre: nombre.trim(), padre_id: null });
                renderCarpetas();
            } else {
                alert('Error: ' + (data.error || 'No se pudo crear la carpeta'));
            }
        });
}

function abrirMoverMaterial(materialId, materialNombre, carpetaActual) {
    moverMaterialId = materialId;
    document.getElementById('moverMaterialNombre').textContent = 'Material: ' + materialNombre;

    var sel = document.getElementById('moverCarpetaSelect');
    sel.innerHTML = '<option value="">Sin carpeta (raíz)</option>';
    carpetasData.forEach(function(c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.nombre;
        if (carpetaActual && String(c.id) === String(carpetaActual)) opt.selected = true;
        sel.appendChild(opt);
    });

    var modal = document.getElementById('modalMoverCarpeta');
    modal.style.display = 'flex';
}

function cerrarMoverCarpeta() {
    document.getElementById('modalMoverCarpeta').style.display = 'none';
    moverMaterialId = 0;
}

function confirmarMoverMaterial() {
    var carpetaId = document.getElementById('moverCarpetaSelect').value;
    var fd = new FormData();
    fd.append('action', 'mover_material');
    fd.append('material_id', moverMaterialId);
    fd.append('carpeta_id', carpetaId);

    fetch('api/materiales.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Actualizar data-carpeta del card en el DOM
                document.querySelectorAll('#inventarioGrid .material-card').forEach(function(c) {
                    // find by matching button onclick which contains moverMaterialId
                    var btn = c.querySelector('.btn-mover-carpeta');
                    if (btn && btn.getAttribute('onclick').includes('(' + moverMaterialId + ',')) {
                        c.dataset.carpeta = carpetaId;
                    }
                });
                cerrarMoverCarpeta();
                filtrarPorCarpeta(carpetaActivaId, null);
            } else {
                alert('Error al mover: ' + (data.error || 'desconocido'));
            }
        });
}

function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

document.getElementById('modalMoverCarpeta').addEventListener('click', function(e) {
    if (e.target === this) cerrarMoverCarpeta();
});

// ══ Campana de notificaciones del header — mismo mecanismo que produccion.php,
// nombres de función distintos a cargarNotificaciones()/marcarNotificacion() de
// arriba (esas son de la sección de página completa "Notificaciones", sin filtrar;
// esta es el dropdown siempre visible, filtrado solo a los tipos que le llegan a
// Ingeniería). ══
const INGENIERIA_NOTIF_TIPOS = ['plano_revision', 'plano_rechazado', 'proyecto_cerrado', 'reemplazo_material_pendiente', 'reemplazo_material_resuelto', 'fat_protocolo_firmado', 'orden_produccion_difundida', 'requisicion_actualizada'];
let _notifHeaderAbierta = false;

function cargarNotificacionesHeader() {
    fetch('includes/ajax_chat.php?action=obtener_notificaciones')
    .then(response => response.json())
    .then(data => {
        if (data.success && data.notificaciones) {
            renderizarNotificacionesHeader(data.notificaciones.filter(n => INGENIERIA_NOTIF_TIPOS.includes(n.tipo)));
        }
    });
}

function renderizarNotificacionesHeader(notificaciones) {
    const container = document.getElementById('notifHeaderList');
    if (!container) return;
    container.innerHTML = '';

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
        'plano_revision':  '<i class="fas fa-comment-dots" style="color:#e65100"></i>',
        'plano_rechazado': '<i class="fas fa-times-circle" style="color:#c62828"></i>',
        'proyecto_cerrado':'<i class="fas fa-lock"          style="color:#2e7d32"></i>',
        'fat_protocolo_firmado': '<i class="fas fa-file-shield" style="color:#2e7d32"></i>',
        'orden_produccion_difundida': '<i class="fas fa-broadcast-tower" style="color:#003d5c"></i>',
        'requisicion_actualizada': '<i class="fas fa-file-alt" style="color:#1565c0"></i>',
    };

    notificaciones.forEach(notif => {
        const fecha = new Date(notif.fecha_creacion).toLocaleString();
        const leido = notif.leido == 1;

        const item = document.createElement('div');
        item.className = 'notification-item' + (leido ? '' : ' notif-unread');

        const titleDiv = document.createElement('div');
        titleDiv.className = 'notification-title';
        titleDiv.innerHTML = iconos[notif.tipo] || '<i class="fas fa-bell" style="color:#999"></i>';
        titleDiv.append(' ' + (notif.titulo || ''));

        const msgDiv = document.createElement('div');
        msgDiv.className = 'notification-message';
        msgDiv.textContent = notif.mensaje || '';

        const timeDiv = document.createElement('div');
        timeDiv.className = 'notification-time';
        timeDiv.textContent = fecha;

        item.appendChild(titleDiv);
        item.appendChild(msgDiv);
        item.appendChild(timeDiv);
        item.addEventListener('click', () => marcarNotificacionHeader(notif.id, notif.link || ''));
        container.appendChild(item);
    });
}

function marcarNotificacionHeader(id, link) {
    fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })
    .then(() => {
        if (link) {
            window.location.href = link;
        } else {
            cargarNotificacionesHeader();
            actualizarBadgeNotifHeader();
        }
    });
}

async function marcarTodasLeidasIngenieria() {
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const ids = res.notificaciones.filter(n => INGENIERIA_NOTIF_TIPOS.includes(n.tipo) && n.leido == 0).map(n => n.id);
    await Promise.all(ids.map(id => fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificacionesHeader();
    actualizarBadgeNotifHeader();
}

async function borrarNotificacionesIngenieria() {
    if (!confirm('¿Borrar todas las notificaciones de Ingeniería? Esta acción no se puede deshacer.')) return;
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const ids = res.notificaciones.filter(n => INGENIERIA_NOTIF_TIPOS.includes(n.tipo)).map(n => n.id);
    await Promise.all(ids.map(id => fetch('includes/ajax_chat.php?action=borrar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificacionesHeader();
    actualizarBadgeNotifHeader();
}

function toggleNotifHeaderDropdown() {
    _notifHeaderAbierta = !_notifHeaderAbierta;
    const dd = document.getElementById('notifHeaderDropdown');
    dd.style.display = _notifHeaderAbierta ? 'block' : 'none';
    if (_notifHeaderAbierta) cargarNotificacionesHeader();
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
        const noLeidas = (data.notificaciones || []).filter(n => n.leido == 0 && INGENIERIA_NOTIF_TIPOS.includes(n.tipo)).length;
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