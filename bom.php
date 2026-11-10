<?php
require_once 'includes/config.php';

if (!isLoggedIn() || (!hasAcceso('ingenieria') && !hasAcceso('manufactura') && !isAdmin())) {
    header('Location: index.php');
    exit();
}

$db = getDB();
$proyecto_id = intval($_GET['proyecto_id'] ?? 0);
$boom_id     = intval($_GET['boom_id'] ?? 0);
$message = '';
$error   = '';

// Helper: write audit entry to boom_items_log
function logBoomItem($db, $boom_item_id, $boom_id, $proyecto_id, $usuario_id, $accion, $campo = null, $valor_anterior = null, $valor_nuevo = null) {
    try {
        $db->prepare("INSERT INTO boom_items_log (boom_item_id,boom_id,proyecto_id,usuario_id,accion,campo,valor_anterior,valor_nuevo) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$boom_item_id, $boom_id, $proyecto_id, $usuario_id, $accion, $campo, $valor_anterior, $valor_nuevo]);
    } catch (Exception $e) { /* tabla aún no migrada */ }
}

// ── POST handlers ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ─ Guardar / editar item ────────────────────────────────
    if ($accion === 'guardar_item') {
        $item_id  = intval($_POST['item_id'] ?? 0);
        $bid      = intval($_POST['boom_id']);
        $pid      = intval($_POST['proyecto_id']);

        $stmt_fin = $db->prepare("SELECT finalizado,tipo_lista FROM boom_listas WHERE id=?");
        $stmt_fin->execute([$bid]);
        $boom_row = $stmt_fin->fetch();
        if ($boom_row && $boom_row['finalizado'] == 1 && $boom_row['tipo_lista'] === 'principal') {
            $error = "El BOM principal está finalizado. No se pueden editar sus items.";
        } else {
            $descripcion    = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
            $marca          = sanitize($_POST['marca'] ?? '');
            $modelo         = sanitize($_POST['modelo'] ?? '');
            $cantidad       = max(1, intval($_POST['cantidad'] ?? 1));
            $material_id    = !empty($_POST['material_id']) ? intval($_POST['material_id']) : null;
            $certificados   = sanitize($_POST['certificados'] ?? '');
            $observacion    = sanitize($_POST['observacion'] ?? '');
            $nota_libre     = sanitize($_POST['nota_libre'] ?? '');
            $es_manufactura = isset($_POST['es_manufactura']) ? 1 : 0;

            // Si el material ya existe en el BOM → sumar cantidad en vez de duplicar
            $existing_id = null;
            if ($material_id && $item_id === 0) {
                $chk = $db->prepare("SELECT id, cantidad FROM boom_items WHERE boom_id=? AND material_id=?");
                $chk->execute([$bid, $material_id]);
                $existing = $chk->fetch();
                if ($existing) { $existing_id = $existing['id']; }
            }

            if (empty($error)) {
                try {
                    if ($existing_id) {
                        // Sumar cantidad al ítem existente
                        $stmtQty = $db->prepare("SELECT cantidad FROM boom_items WHERE id=?");
                        $stmtQty->execute([$existing_id]);
                        $cant_actual = (float)$stmtQty->fetchColumn();
                        $db->prepare("UPDATE boom_items SET cantidad=? WHERE id=?")->execute([$cant_actual + $cantidad, $existing_id]);
                        logBoomItem($db, $existing_id, $bid, $pid, $_SESSION['user_id'], 'editar', 'cantidad', $cant_actual, $cant_actual + $cantidad);
                        $message = "Material ya existente — cantidad sumada: " . ($cant_actual + $cantidad);
                        $item_id = $existing_id;
                    } elseif ($item_id > 0) {
                        // Capture previous values for log
                        $prev = $db->prepare("SELECT descripcion,cantidad FROM boom_items WHERE id=?");
                        $prev->execute([$item_id]);
                        $prev_row = $prev->fetch();

                        $db->prepare("UPDATE boom_items SET descripcion=?,marca=?,modelo=?,cantidad=?,material_id=?,
                                      certificados=?,observacion=?,nota_libre=?,es_manufactura=?
                                      WHERE id=? AND boom_id=?")
                           ->execute([$descripcion,$marca,$modelo,$cantidad,$material_id,
                                      $certificados,$observacion,$nota_libre,$es_manufactura,$item_id,$bid]);

                        if ($prev_row) {
                            if ($prev_row['cantidad'] != $cantidad)
                                logBoomItem($db,$item_id,$bid,$pid,$_SESSION['user_id'],'editar','cantidad',$prev_row['cantidad'],$cantidad);
                            if ($prev_row['descripcion'] != $descripcion)
                                logBoomItem($db,$item_id,$bid,$pid,$_SESSION['user_id'],'editar','descripcion',$prev_row['descripcion'],$descripcion);
                        }
                    } else {
                        $db->prepare("INSERT INTO boom_items (boom_id,proyecto_id,descripcion,marca,modelo,cantidad,material_id,
                                      certificados,observacion,nota_libre,es_manufactura,guardado)
                                      VALUES (?,?,?,?,?,?,?,?,?,?,?,1)")
                           ->execute([$bid,$pid,$descripcion,$marca,$modelo,$cantidad,$material_id,
                                      $certificados,$observacion,$nota_libre,$es_manufactura]);
                        $item_id = $db->lastInsertId();
                        logBoomItem($db,$item_id,$bid,$pid,$_SESSION['user_id'],'agregar',null,null,$descripcion);
                    }

                    // Manufacturing sub-project
                    if ($es_manufactura && !empty($_POST['nombre_pieza'])) {
                        $nombre_pieza = sanitize($_POST['nombre_pieza']);
                        $obs_fab      = sanitize($_POST['obs_fabricacion'] ?? '');

                        $ex = $db->prepare("SELECT id,plano_ruta FROM subproyectos_manufactura WHERE boom_item_id=?");
                        $ex->execute([$item_id]);
                        $sub = $ex->fetch();

                        $plano_ruta = $sub['plano_ruta'] ?? null;
                        if (!empty($_FILES['plano']['name']) && $_FILES['plano']['error'] === 0) {
                            $upload = uploadPlano($_FILES['plano']);
                            if ($upload['success']) $plano_ruta = $upload['file'];
                        }

                        if ($sub) {
                            $db->prepare("UPDATE subproyectos_manufactura SET nombre=?,observacion=?,plano_ruta=? WHERE id=?")
                               ->execute([$nombre_pieza,$obs_fab,$plano_ruta,$sub['id']]);
                        } else {
                            $db->prepare("INSERT INTO subproyectos_manufactura (proyecto_id,boom_item_id,nombre,observacion,plano_ruta,creado_por) VALUES (?,?,?,?,?,?)")
                               ->execute([$pid,$item_id,$nombre_pieza,$obs_fab,$plano_ruta,$_SESSION['user_id']]);
                            $pn = $db->prepare("SELECT nombre FROM proyectos WHERE id=?");
                            $pn->execute([$pid]);
                            $proy_nombre = $pn->fetchColumn() ?: "ID $pid";
                            notificarRoles(['manufactura'],'nuevo_subproyecto',"Nuevo sub-proyecto: $nombre_pieza",
                                "Se asignó la pieza \"$nombre_pieza\" del proyecto \"$proy_nombre\" para fabricación interna.",
                                'produccion.php?seccion=subproyectos',$_SESSION['user_id']);
                        }
                    }

                    $message = "Item guardado correctamente.";
                } catch (Exception $e) {
                    $error = "Error al guardar item: " . $e->getMessage();
                }
            }
        }
    }

    // ─ Eliminar item ────────────────────────────────────────
    elseif ($accion === 'eliminar_item') {
        $item_id = intval($_POST['item_id']);
        $bid     = intval($_POST['boom_id']);
        $stmt_fin = $db->prepare("SELECT finalizado,tipo_lista FROM boom_listas WHERE id=?");
        $stmt_fin->execute([$bid]);
        $boom_row = $stmt_fin->fetch();
        if ($boom_row && $boom_row['finalizado'] == 1 && $boom_row['tipo_lista'] === 'principal') {
            $error = "El BOM principal está finalizado.";
        } else {
            try {
                $prev = $db->prepare("SELECT descripcion,proyecto_id FROM boom_items WHERE id=?");
                $prev->execute([$item_id]);
                $prev_row = $prev->fetch();

                $db->prepare("DELETE FROM subproyectos_manufactura WHERE boom_item_id=?")->execute([$item_id]);
                $db->prepare("DELETE FROM boom_items WHERE id=? AND boom_id=?")->execute([$item_id,$bid]);

                logBoomItem($db,$item_id,$bid,$prev_row['proyecto_id'] ?? $proyecto_id,
                            $_SESSION['user_id'],'eliminar',null,$prev_row['descripcion'] ?? '',null);

                $message = "Item eliminado.";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }

    // ─ Finalizar BOM ────────────────────────────────────────
    elseif ($accion === 'finalizar_boom') {
        $bid = intval($_POST['boom_id']);
        $pid = intval($_POST['proyecto_id']);
        try {
            // 1. Debe tener al menos un item
            $cnt = $db->prepare("SELECT COUNT(*) FROM boom_items WHERE boom_id=?");
            $cnt->execute([$bid]);
            if ($cnt->fetchColumn() < 1) {
                $error = "Debes agregar al menos un item antes de finalizar.";
            } else {
                // 2. Sin cantidades <= 0
                $inv_qty = $db->prepare("SELECT COUNT(*) FROM boom_items WHERE boom_id=? AND cantidad<=0");
                $inv_qty->execute([$bid]);
                if ($inv_qty->fetchColumn() > 0) {
                    $error = "Hay items con cantidad 0 o negativa. Corrígelos antes de finalizar.";
                } else {
                    // 3. Sin material_id duplicados
                    $dup = $db->prepare("SELECT material_id,COUNT(*) c FROM boom_items WHERE boom_id=? AND material_id IS NOT NULL GROUP BY material_id HAVING c>1");
                    $dup->execute([$bid]);
                    if ($dup->fetch()) {
                        $error = "Hay materiales duplicados en el BOM (mismo catálogo). Consolida los items antes de finalizar.";
                    } else {
                        $db->prepare("UPDATE boom_listas SET finalizado=1,fecha_finalizado=NOW(),finalizado_por=? WHERE id=?")
                           ->execute([$_SESSION['user_id'],$bid]);

                        $pn = $db->prepare("SELECT nombre FROM proyectos WHERE id=?");
                        $pn->execute([$pid]);
                        $proy_nombre = $pn->fetchColumn() ?: "ID $pid";
                        notificarRoles(['almacen','compras'],'bom_finalizado',"BOM Finalizado: $proy_nombre",
                            "El BOM del proyecto \"$proy_nombre\" ha sido finalizado y está listo para revisión.",
                            "bom.php?proyecto_id=$pid&boom_id=$bid",$_SESSION['user_id']);
                        $message = "BOM finalizado correctamente.";
                    }
                }
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    // ─ Crear BOM adicional ──────────────────────────────────
    elseif ($accion === 'crear_adicional') {
        $pid = intval($_POST['proyecto_id']);
        try {
            $db->prepare("INSERT INTO boom_listas (proyecto_id,nombre,num_equipos,creado_por,fecha_creacion,guardado,tipo_lista) VALUES (?,?,1,?,NOW(),0,'adicional')")
               ->execute([$pid,'Piezas Adicionales',$_SESSION['user_id']]);
            $new_boom_id = $db->lastInsertId();
            header("Location: bom.php?proyecto_id=$pid&boom_id=$new_boom_id");
            exit();
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    // ─ Cambiar estado del plano (Feature 4) ─────────────────
    elseif ($accion === 'cambiar_estado_plano') {
        $bid           = intval($_POST['boom_id']);
        $pid           = intval($_POST['proyecto_id']);
        $nuevo_estado  = sanitize($_POST['estado_plano'] ?? '');
        $comentario    = sanitize($_POST['comentario_plano'] ?? '');
        $estados_valid = ['borrador','enviado','en_revision','aprobado','rechazado'];
        if (!in_array($nuevo_estado, $estados_valid)) {
            $error = "Estado no válido.";
        } else {
            try {
                $db->prepare("UPDATE boom_listas SET estado_plano=?,plano_comentario=?,plano_comentario_fecha=NOW(),plano_comentario_por=? WHERE id=?")
                   ->execute([$nuevo_estado, $comentario ?: null, $_SESSION['user_id'], $bid]);

                if (in_array($nuevo_estado, ['en_revision','rechazado'])) {
                    $pn = $db->prepare("SELECT nombre FROM proyectos WHERE id=?");
                    $pn->execute([$pid]);
                    $proy_nombre = $pn->fetchColumn() ?: "ID $pid";
                    // Bug real (2026-09-21): antes SIEMPRE mandaba tipo 'plano_revision' con
                    // título "Plano en revisión" incluso cuando el estado nuevo era 'rechazado'
                    // -- Ingeniería veía un ícono/título de "en revisión" para lo que en
                    // realidad era un rechazo. Mismo criterio que ya usa produccion.php para su
                    // propio flujo de Aceptar/Rechazar (línea ~726).
                    if ($nuevo_estado === 'rechazado') {
                        notificarRoles(['ingenieria'],'plano_rechazado',"Plano rechazado: $proy_nombre",
                            "Manufactura rechazó el plano" . ($comentario ? ": $comentario" : "."),
                            "bom.php?proyecto_id=$pid&boom_id=$bid",$_SESSION['user_id']);
                    } else {
                        notificarRoles(['ingenieria'],'plano_revision',"Plano en revisión: $proy_nombre",
                            "Manufactura puso el plano en revisión" . ($comentario ? ": $comentario" : "."),
                            "bom.php?proyecto_id=$pid&boom_id=$bid",$_SESSION['user_id']);
                    }
                }
                $message = "Estado del plano actualizado.";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }

    // ─ Reemplazar archivo del plano (Feature 10) ────────────
    elseif ($accion === 'reemplazar_plano') {
        $bid = intval($_POST['boom_id']);
        $pid = intval($_POST['proyecto_id']);
        if (!empty($_FILES['nuevo_plano']['name']) && $_FILES['nuevo_plano']['error'] === 0) {
            $upload = uploadPlano($_FILES['nuevo_plano']);
            if ($upload['success']) {
                try {
                    $db->prepare("UPDATE boom_listas SET plano_ruta=? WHERE id=?")->execute([$upload['file'],$bid]);
                    $message = "Plano reemplazado correctamente.";
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            } else {
                $error = $upload['message'] ?? "Error al subir el archivo.";
            }
        } else {
            $error = "Selecciona un archivo para reemplazar el plano.";
        }
    }

    if (empty($error)) {
        $pid_r = intval($_POST['proyecto_id'] ?? $proyecto_id);
        $bid_r = intval($_POST['boom_id'] ?? $boom_id);
        header("Location: bom.php?proyecto_id=$pid_r&boom_id=$bid_r&msg=" . urlencode($message));
        exit();
    }
}

if (!empty($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// ── Data loading ────────────────────────────────────────────
$proyecto = null;
if ($proyecto_id) {
    $stmt = $db->prepare("SELECT * FROM proyectos WHERE id=?");
    $stmt->execute([$proyecto_id]);
    $proyecto = $stmt->fetch();
}

$proyectos_lista = $db->query("SELECT id,nombre FROM proyectos WHERE tipo='ingenieria' ORDER BY nombre")->fetchAll();

$booms = [];
if ($proyecto_id) {
    $stmt = $db->prepare("SELECT bl.*,u.nombre_completo as finalizado_por_nombre
                          FROM boom_listas bl
                          LEFT JOIN usuarios u ON u.id=bl.finalizado_por
                          WHERE bl.proyecto_id=?
                          ORDER BY bl.tipo_lista,bl.fecha_creacion");
    $stmt->execute([$proyecto_id]);
    $booms = $stmt->fetchAll();
}

$boom_actual = null;
if ($boom_id) {
    foreach ($booms as $b) { if ($b['id'] == $boom_id) { $boom_actual = $b; break; } }
}

$items = [];
if ($boom_id) {
    $stmt = $db->prepare("SELECT bi.*,
                                 m.descripcion as material_nombre, m.codigo as material_codigo,
                                 m.stock_disponible as material_stock,
                                 c.nombre as categoria_nombre,
                                 sm.id as subproyecto_id, sm.nombre as sub_nombre,
                                 sm.observacion as sub_obs, sm.plano_ruta as sub_plano_ruta, sm.estado as sub_estado
                          FROM boom_items bi
                          LEFT JOIN materiales m ON m.id=bi.material_id
                          LEFT JOIN categorias c ON c.id=m.categoria_id
                          LEFT JOIN subproyectos_manufactura sm ON sm.boom_item_id=bi.id
                          WHERE bi.boom_id=?
                          ORDER BY bi.id");
    $stmt->execute([$boom_id]);
    $items = $stmt->fetchAll();
}

// Materiales with stock and category for left-panel selector
$materiales_all = $db->query("SELECT m.id,m.descripcion,m.codigo,m.marca,m.modelo,m.stock_disponible,
                                     c.nombre as categoria_nombre,c.id as categoria_id
                               FROM materiales m
                               LEFT JOIN categorias c ON c.id=m.categoria_id
                               WHERE m.activo=1
                               ORDER BY m.descripcion")->fetchAll();

$categorias_all = $db->query("SELECT id,nombre,icono FROM categorias WHERE activo=1 ORDER BY nombre")->fetchAll();

$finalizado   = ($boom_actual['finalizado'] ?? 0) == 1;
$tipo_lista   = $boom_actual['tipo_lista'] ?? 'principal';
$puede_editar = !$finalizado || $tipo_lista === 'adicional';

$boom_principal_finalizado = false;
foreach ($booms as $b) {
    if ($b['tipo_lista'] === 'principal' && $b['finalizado'] == 1) { $boom_principal_finalizado = true; break; }
}
$tiene_adicional = false;
foreach ($booms as $b) { if ($b['tipo_lista'] === 'adicional') { $tiene_adicional = true; break; } }

$num_equipos     = intval($boom_actual['num_equipos'] ?? 1);
$plano_ruta_act  = $boom_actual['plano_ruta'] ?? null;
$estado_plano    = $boom_actual['estado_plano'] ?? 'borrador';
$plano_coment    = $boom_actual['plano_comentario'] ?? '';

$ep_map = [
    'borrador'    => ['Borrador',              '#9E9E9E','pencil-alt'],
    'enviado'     => ['Enviado a Manufactura', '#1976D2','paper-plane'],
    'en_revision' => ['En Revisión',           '#F57C00','search'],
    'aprobado'    => ['Aprobado',              '#2E7D32','check-circle'],
    'rechazado'   => ['Rechazado',             '#C62828','times-circle'],
];
$ep_info = $ep_map[$estado_plano] ?? $ep_map['borrador'];

// Historial de cambios
$historial = [];
if ($boom_id) {
    try {
        $h = $db->prepare("SELECT bil.*,u.nombre_completo as usuario_nombre
                           FROM boom_items_log bil
                           LEFT JOIN usuarios u ON u.id=bil.usuario_id
                           WHERE bil.boom_id=?
                           ORDER BY bil.fecha_creacion DESC LIMIT 200");
        $h->execute([$boom_id]);
        $historial = $h->fetchAll();
    } catch (Exception $e) { /* tabla no migrada aún */ }
}

// Puede cambiar estado del plano si tiene acceso a manufactura o ingeniería
$puede_cambiar_estado = hasAcceso('manufactura') || hasAcceso('ingenieria') || isAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<link rel="icon" href="favicon.ico?v=3">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Editor BOM — EMPRESA EJEMPLO</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Montserrat',sans-serif;background:#f5f7fa;padding-top:70px;padding-bottom:70px;}
.top-header{position:fixed;top:0;left:0;right:0;height:65px;background:linear-gradient(135deg,#003d5c,#005686);display:flex;align-items:center;padding:0 24px;z-index:100;box-shadow:0 2px 10px rgba(0,0,0,.25);}
.top-header .brand{color:#fff;font-size:1.1rem;font-weight:700;letter-spacing:.5px;}
.top-header .brand span{color:#FF9800;}
.top-header .back-btn{margin-left:auto;color:#fff;text-decoration:none;font-size:.85rem;display:flex;align-items:center;gap:6px;opacity:.85;}
.top-header .back-btn:hover{opacity:1;}
.app-container{max-width:1300px;margin:0 auto;padding:24px 16px;}
.page-header{background:#fff;border-radius:12px;padding:20px 24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.07);display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.page-header h1{font-size:1.3rem;color:#003d5c;font-weight:700;flex:1;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-family:'Montserrat',sans-serif;font-size:.85rem;font-weight:600;text-decoration:none;transition:all .2s;}
.btn-primary{background:#005686;color:#fff;}.btn-primary:hover{background:#003d5c;}
.btn-success{background:#2E7D32;color:#fff;}.btn-success:hover{background:#1B5E20;}
.btn-danger{background:#d32f2f;color:#fff;}.btn-danger:hover{background:#b71c1c;}
.btn-outline{background:transparent;color:#005686;border:1.5px solid #005686;}.btn-outline:hover{background:#005686;color:#fff;}
.btn-warning{background:#FF9800;color:#fff;}.btn-warning:hover{background:#e65100;}
.btn-info{background:#0288D1;color:#fff;}.btn-info:hover{background:#0277BD;}
.btn-sm{padding:5px 10px;font-size:.78rem;}
.tabs{display:flex;gap:4px;background:#fff;border-radius:10px 10px 0 0;padding:12px 16px 0;border-bottom:2px solid #e0e8f0;margin-bottom:0;flex-wrap:wrap;}
.tab{padding:8px 18px;border-radius:8px 8px 0 0;cursor:pointer;font-size:.85rem;font-weight:600;color:#666;border:1.5px solid transparent;border-bottom:none;transition:all .2s;}
.tab.active{background:#005686;color:#fff;border-color:#005686;}
.tab:hover:not(.active){background:#f0f4f8;color:#003d5c;}
.card{background:#fff;border-radius:0 12px 12px 12px;box-shadow:0 2px 8px rgba(0,0,0,.07);padding:20px;}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:1rem;font-weight:700;color:#003d5c;}
.items-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.items-table th{background:#f0f4f8;color:#003d5c;font-weight:700;padding:10px 12px;text-align:left;border-bottom:2px solid #e0e8f0;}
.items-table td{padding:9px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.items-table tr:hover td{background:#fafcff;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;}
.badge-success{background:#e8f5e9;color:#2E7D32;}
.badge-warning{background:#fff3e0;color:#e65100;}
.badge-info{background:#e3f2fd;color:#1565c0;}
.badge-danger{background:#fce4ec;color:#b71c1c;}
.badge-manufactura{background:#f3e5f5;color:#7B3F00;}
.badge-stock-ok{background:#e8f5e9;color:#2E7D32;font-size:.68rem;}
.badge-stock-warn{background:#fff8e1;color:#F57F17;font-size:.68rem;}
.badge-stock-bad{background:#fce4ec;color:#b71c1c;font-size:.68rem;}
.finalizado-banner{background:linear-gradient(135deg,#e8f5e9,#c8e6c9);border:1.5px solid #2E7D32;border-radius:10px;padding:12px 18px;display:flex;align-items:center;gap:10px;color:#1B5E20;font-weight:600;font-size:.9rem;margin-bottom:16px;}
.plano-banner{border-radius:10px;padding:12px 18px;display:flex;align-items:center;gap:12px;margin-bottom:16px;font-size:.9rem;font-weight:600;flex-wrap:wrap;}
.estado-plano-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:.82rem;font-weight:700;color:#fff;}
.cat-filter-bar{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #f0f0f0;}
.cat-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;border:1.5px solid #e0e0e0;background:#fff;font-size:.76rem;font-weight:600;cursor:pointer;color:#555;transition:all .2s;}
.cat-chip:hover{border-color:#005686;color:#005686;}
.cat-chip.active{background:#005686;color:#fff;border-color:#005686;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:14px;width:min(620px,95vw);max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.modal-wide{width:min(860px,95vw);}
.modal-header{padding:18px 22px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;}
.modal-title{font-size:1rem;font-weight:700;color:#003d5c;}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:#999;}
.modal-body{padding:20px 22px;}
.modal-footer{padding:14px 22px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:8px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:.8rem;font-weight:600;color:#555;margin-bottom:5px;}
.form-input{width:100%;padding:9px 12px;border:1.5px solid #ddd;border-radius:8px;font-family:'Montserrat',sans-serif;font-size:.85rem;transition:border-color .2s;}
.form-input:focus{outline:none;border-color:#005686;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.manufactura-section{background:#fdf6f0;border:1.5px solid #e8c49a;border-radius:10px;padding:14px;margin-top:10px;display:none;}
.manufactura-section.visible{display:block;}
.selector-container{position:relative;}
.selector-dropdown{position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid #ddd;border-radius:0 0 8px 8px;max-height:220px;overflow-y:auto;z-index:50;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1);}
.selector-dropdown.open{display:block;}
.selector-item{padding:8px 12px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f5f5f5;}
.selector-item:hover{background:#f0f4f8;}
.selector-item small{color:#888;display:block;font-size:.74rem;}
.no-project{text-align:center;padding:60px 20px;color:#999;}
.project-selector{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.fixed-footer{position:fixed;bottom:0;left:0;right:0;height:60px;background:#fff;border-top:1px solid #e0e8f0;display:flex;align-items:center;justify-content:center;z-index:99;font-size:.8rem;color:#888;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.85rem;}
.alert-success{background:#e8f5e9;color:#2E7D32;border-left:4px solid #2E7D32;}
.alert-error{background:#fce4ec;color:#b71c1c;border-left:4px solid #b71c1c;}
.historial-table{width:100%;border-collapse:collapse;font-size:.8rem;}
.historial-table th{background:#f0f4f8;color:#003d5c;font-weight:700;padding:8px 10px;text-align:left;}
.historial-table td{padding:7px 10px;border-bottom:1px solid #f0f0f0;vertical-align:top;}
.accion-agregar{color:#2E7D32;font-weight:700;}
.accion-editar{color:#1565c0;font-weight:700;}
.accion-eliminar{color:#b71c1c;font-weight:700;}
.plano-preview-link{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1565c0;color:#fff;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;transition:background .2s;}
.plano-preview-link:hover{background:#0d47a1;}
.total-col{font-weight:700;color:#003d5c;}
.stock-warn-icon{color:#F57C00;}
.stock-bad-icon{color:#b71c1c;}
</style>
<link rel="stylesheet" href="css/responsive.css">
</head>
<body>
<div class="top-header">
    <div class="brand">EMPRESA EJEMPLO — <span>Editor BOM</span></div>
    <a href="ingenieria.php?seccion=proyectos" class="back-btn"><i class="fas fa-arrow-left"></i> Volver</a>
</div>

<div class="app-container">

<?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

<!-- ── Encabezado de página ── -->
<div class="page-header">
    <h1><i class="fas fa-clipboard-list" style="color:#005686;margin-right:8px;"></i>
    <?= $proyecto ? htmlspecialchars($proyecto['nombre']) : 'Editor de BOM' ?></h1>

    <form method="GET" action="bom.php" class="project-selector">
        <select name="proyecto_id" class="form-input" style="width:240px;" onchange="this.form.submit()">
            <option value="">-- Selecciona proyecto --</option>
            <?php foreach ($proyectos_lista as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($p['id'] == $proyecto_id ? 'selected' : '') ?>>
                <?= htmlspecialchars($p['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($proyecto_id && $boom_actual): ?>
        <?php if (!$finalizado && $tipo_lista === 'principal'): ?>
        <form method="POST" onsubmit="return confirm('¿Finalizar el BOM? Esta acción es irreversible para el BOM principal.');">
            <input type="hidden" name="accion" value="finalizar_boom">
            <input type="hidden" name="boom_id" value="<?= $boom_id ?>">
            <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
            <button type="submit" class="btn btn-success" <?= count($items) === 0 ? 'disabled title="Agrega al menos 1 item"' : '' ?>>
                <i class="fas fa-lock"></i> Finalizar BOM
            </button>
        </form>
        <?php elseif ($finalizado && $tipo_lista === 'principal'): ?>
        <span class="badge badge-success" style="font-size:.85rem;padding:8px 14px;">
            <i class="fas fa-check-circle"></i> BOM Finalizado
            <?= $boom_actual['finalizado_por_nombre'] ? '— '.htmlspecialchars($boom_actual['finalizado_por_nombre']) : '' ?>
        </span>
        <?php endif; ?>

        <?php if ($boom_principal_finalizado && !$tiene_adicional): ?>
        <form method="POST">
            <input type="hidden" name="accion" value="crear_adicional">
            <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
            <button type="submit" class="btn btn-warning"><i class="fas fa-plus"></i> + Piezas Adicionales</button>
        </form>
        <?php endif; ?>

        <!-- Historial de cambios -->
        <?php if (!empty($historial)): ?>
        <button class="btn btn-info btn-sm" onclick="abrirHistorial()">
            <i class="fas fa-history"></i> Historial (<?= count($historial) ?>)
        </button>
        <?php endif; ?>

        <a href="api/exportar_bom.php?boom_id=<?= $boom_id ?>" class="btn btn-outline" target="_blank">
            <i class="fas fa-file-excel"></i> Exportar
        </a>
    <?php endif; ?>
</div>

<?php if (!$proyecto_id): ?>
<div class="no-project">
    <i class="fas fa-clipboard-list" style="font-size:3rem;margin-bottom:12px;display:block;opacity:.3;"></i>
    Selecciona un proyecto para ver o editar su BOM.
</div>

<?php elseif (empty($booms) && $proyecto_id): ?>
<div class="card" style="text-align:center;padding:40px;">
    <i class="fas fa-file-alt" style="font-size:3rem;color:#ccc;margin-bottom:12px;"></i>
    <p style="color:#666;margin-bottom:16px;">Este proyecto no tiene BOM todavía.</p>
    <form method="POST" action="ingenieria.php">
        <input type="hidden" name="action" value="crear_boom_lista">
        <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
        <input type="hidden" name="nombre_boom" value="BOM Principal">
        <input type="hidden" name="num_equipos" value="1">
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Crear BOM</button>
    </form>
</div>

<?php else: ?>

<!-- ── Tabs de BOMs ── -->
<div class="tabs">
    <?php foreach ($booms as $b): ?>
    <div class="tab <?= ($b['id'] == $boom_id ? 'active' : '') ?>"
         onclick="window.location.href='bom.php?proyecto_id=<?= $proyecto_id ?>&boom_id=<?= $b['id'] ?>'">
        <?php if ($b['tipo_lista'] === 'plano'): ?>
            <i class="fas fa-drafting-compass"></i> <?= htmlspecialchars($b['pieza_nombre'] ?? 'Plano') ?>
        <?php elseif ($b['tipo_lista'] === 'adicional'): ?>
            <i class="fas fa-plus-circle"></i> Adicionales
        <?php else: ?>
            <i class="fas fa-list"></i> BOM Principal
        <?php endif; ?>
        <?php if ($b['finalizado']): ?><i class="fas fa-lock" style="font-size:.7rem;margin-left:4px;"></i><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <!-- ── Banner finalizado ── -->
    <?php if ($finalizado && $tipo_lista === 'principal'): ?>
    <div class="finalizado-banner">
        <i class="fas fa-check-circle" style="font-size:1.3rem;"></i>
        BOM Finalizado el <?= date('d/m/Y H:i',strtotime($boom_actual['fecha_finalizado'])) ?>
        <?= $boom_actual['finalizado_por_nombre'] ? '— por '.htmlspecialchars($boom_actual['finalizado_por_nombre']) : '' ?>
    </div>
    <?php endif; ?>

    <!-- ── Banner plano: estado + preview + cambiar estado ── -->
    <?php if ($tipo_lista === 'plano'): ?>
    <div class="plano-banner" style="background:<?= $ep_info[1] ?>18;border:1.5px solid <?= $ep_info[1] ?>;">
        <span class="estado-plano-badge" style="background:<?= $ep_info[1] ?>;">
            <i class="fas fa-<?= $ep_info[2] ?>"></i> <?= $ep_info[0] ?>
        </span>

        <?php if ($plano_ruta_act): ?>
        <a href="uploads/planos/<?= htmlspecialchars($plano_ruta_act) ?>" target="_blank" class="plano-preview-link">
            <i class="fas fa-eye"></i> Ver Plano
        </a>
        <?php if ($puede_editar): ?>
        <button class="btn btn-outline btn-sm" onclick="abrirReemplazarPlano()">
            <i class="fas fa-exchange-alt"></i> Reemplazar
        </button>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($puede_cambiar_estado): ?>
        <button class="btn btn-sm" style="background:#5D4037;color:#fff;" onclick="abrirCambiarEstado()">
            <i class="fas fa-tasks"></i> Cambiar Estado
        </button>
        <?php endif; ?>

        <?php if ($plano_coment): ?>
        <div style="flex:1;font-size:.82rem;color:#555;background:rgba(255,255,255,.7);padding:6px 10px;border-radius:8px;">
            <i class="fas fa-comment" style="color:<?= $ep_info[1] ?>;"></i>
            <strong>Comentario:</strong> <?= htmlspecialchars($plano_coment) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Encabezado del card ── -->
    <div class="card-header">
        <div class="card-title">
            <?php
            if ($tipo_lista==='plano') echo htmlspecialchars($boom_actual['pieza_nombre'] ?? 'Plano');
            elseif ($tipo_lista==='adicional') echo 'Piezas Adicionales';
            else echo 'BOM Principal';
            ?>
            <span style="font-weight:400;color:#888;font-size:.82rem;margin-left:8px;">(<?= count($items) ?> items
            <?php if ($num_equipos > 1): ?>· <?= $num_equipos ?> equipos<?php endif; ?>)</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?php if ($puede_editar): ?>
            <button class="btn btn-primary" onclick="abrirModalItem(0)">
                <i class="fas fa-plus"></i> Agregar Item
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filtro por categoría ── -->
    <?php if (!empty($categorias_all)): ?>
    <div class="cat-filter-bar" id="catFilterBar">
        <div class="cat-chip active" data-cat="" onclick="filtrarPorCategoria(this,'')">
            <i class="fas fa-th"></i> Todas
        </div>
        <?php foreach ($categorias_all as $cat): ?>
        <div class="cat-chip" data-cat="<?= $cat['id'] ?>" onclick="filtrarPorCategoria(this,'<?= $cat['id'] ?>')">
            <i class="fas fa-<?= htmlspecialchars($cat['icono'] ?? 'tag') ?>"></i>
            <?= htmlspecialchars($cat['nombre']) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Tabla de items ── -->
    <?php if (empty($items)): ?>
    <div style="text-align:center;padding:40px;color:#bbb;">
        <i class="fas fa-inbox" style="font-size:2.5rem;margin-bottom:10px;display:block;"></i>
        No hay items en este BOM.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="items-table" id="itemsTableBOM">
        <thead>
            <tr>
                <th>#</th>
                <th>Descripción / Material</th>
                <th>Marca / Modelo</th>
                <th style="text-align:center;">Cant. Unit.</th>
                <?php if ($num_equipos > 1): ?>
                <th style="text-align:center;">Total ×<?= $num_equipos ?></th>
                <?php endif; ?>
                <th>Stock</th>
                <th>Certificados</th>
                <th>Observación</th>
                <th>Nota Libre</th>
                <th>Tipo</th>
                <?php if ($puede_editar): ?><th>Acciones</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item):
            $cat_id  = $item['categoria_nombre'] ?? '';
            $cat_data = '';
            // find cat_id numeric
            foreach ($categorias_all as $ccat) {
                if ($ccat['nombre'] === $cat_id) { $cat_data = $ccat['id']; break; }
            }
            $total = $item['cantidad'] * $num_equipos;
            $stock = isset($item['material_stock']) ? intval($item['material_stock']) : null;
            $stock_class = '';
            $stock_label = '';
            if ($stock !== null && $item['material_id']) {
                if ($stock >= $total)      { $stock_class = 'badge-stock-ok'; $stock_label = "OK ($stock)"; }
                elseif ($stock > 0)        { $stock_class = 'badge-stock-warn'; $stock_label = "Parcial ($stock/$total)"; }
                else                       { $stock_class = 'badge-stock-bad'; $stock_label = "Sin stock ($total req.)"; }
            }
        ?>
        <tr data-cat="<?= $cat_data ?>">
            <td><?= $i + 1 ?></td>
            <td>
                <strong><?= htmlspecialchars($item['descripcion']) ?></strong>
                <?php if ($item['material_nombre']): ?>
                <br><small style="color:#888;">
                    <?= htmlspecialchars($item['material_nombre']) ?>
                    <?= $item['material_codigo'] ? ' ('.htmlspecialchars($item['material_codigo']).')' : '' ?>
                </small>
                <?php endif; ?>
                <?php if ($cat_id): ?>
                <br><small style="color:#7b1fa2;font-weight:600;"><i class="fas fa-tag"></i> <?= htmlspecialchars($cat_id) ?></small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($item['marca']) ?> <?= htmlspecialchars($item['modelo']) ?></td>
            <td style="text-align:center;"><?= $item['cantidad'] ?></td>
            <?php if ($num_equipos > 1): ?>
            <td style="text-align:center;" class="total-col"><?= $total ?></td>
            <?php endif; ?>
            <td>
                <?php if ($stock_label): ?>
                <span class="badge <?= $stock_class ?>"><?= $stock_label ?></span>
                <?php else: ?>
                <span style="color:#ccc;font-size:.75rem;">—</span>
                <?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($item['certificados'] ?? '') ?></small></td>
            <td><small><?= htmlspecialchars($item['observacion'] ?? '') ?></small></td>
            <td><small><?= htmlspecialchars($item['nota_libre'] ?? '') ?></small></td>
            <td>
                <?php if ($item['es_manufactura']): ?>
                <span class="badge badge-manufactura"><i class="fas fa-industry"></i> Manufactura</span>
                <?php if ($item['subproyecto_id']): ?>
                <br><small style="color:#666;margin-top:4px;display:block;">
                    <?= htmlspecialchars($item['sub_nombre']) ?>
                    <span class="badge badge-info" style="margin-left:4px;"><?= htmlspecialchars($item['sub_estado']) ?></span>
                    <?php if ($item['sub_plano_ruta']): ?>
                    <a href="uploads/planos/<?= htmlspecialchars($item['sub_plano_ruta']) ?>" target="_blank"
                       style="display:inline-flex;align-items:center;gap:3px;color:#1565c0;font-size:.72rem;margin-left:4px;">
                        <i class="fas fa-eye"></i> Ver plano
                    </a>
                    <?php endif; ?>
                </small>
                <?php endif; ?>
                <?php else: ?>
                <span class="badge badge-info">Compra</span>
                <?php endif; ?>
            </td>
            <?php if ($puede_editar): ?>
            <td>
                <button class="btn btn-outline btn-sm" onclick="abrirModalItem(<?= $item['id'] ?>)"
                        title="Editar item"><i class="fas fa-edit"></i></button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este item?');">
                    <input type="hidden" name="accion" value="eliminar_item">
                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="boom_id" value="<?= $boom_id ?>">
                    <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Eliminar item"><i class="fas fa-trash"></i></button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════════════════════ -->

<!-- ── Modal: Agregar/Editar Item ── -->
<div class="modal-overlay" id="modalItem">
<div class="modal">
    <div class="modal-header">
        <div class="modal-title" id="modalItemTitle">Agregar Item</div>
        <button class="modal-close" onclick="cerrarModal()">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="formItem">
        <div class="modal-body">
            <input type="hidden" name="accion" value="guardar_item">
            <input type="hidden" name="boom_id" value="<?= $boom_id ?>">
            <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
            <input type="hidden" name="item_id" id="itemId" value="0">

            <!-- Categorías rápidas -->
            <?php if (!empty($categorias_all)): ?>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-filter"></i> Filtrar catálogo por categoría</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <span class="cat-chip active" data-cat="" onclick="filtrarCatalogoModal(this,'')">
                        <i class="fas fa-th"></i> Todas
                    </span>
                    <?php foreach ($categorias_all as $cat): ?>
                    <span class="cat-chip" data-cat="<?= $cat['id'] ?>" onclick="filtrarCatalogoModal(this,'<?= $cat['id'] ?>')">
                        <i class="fas fa-<?= htmlspecialchars($cat['icono'] ?? 'tag') ?>"></i>
                        <?= htmlspecialchars($cat['nombre']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Material del catálogo (opcional)</label>
                <div class="selector-container">
                    <input type="text" id="materialSearch" class="form-input" placeholder="Buscar material..." autocomplete="off" oninput="filtrarMateriales(this.value)">
                    <div class="selector-dropdown" id="materialDropdown">
                        <?php foreach ($materiales_all as $m): ?>
                        <div class="selector-item"
                             data-id="<?= $m['id'] ?>"
                             data-nombre="<?= htmlspecialchars($m['descripcion'] ?? '') ?>"
                             data-marca="<?= htmlspecialchars($m['marca']) ?>"
                             data-modelo="<?= htmlspecialchars($m['modelo']) ?>"
                             data-stock="<?= intval($m['stock_disponible']) ?>"
                             data-cat="<?= intval($m['categoria_id']) ?>"
                             onclick="seleccionarMaterial(this)">
                            <?= htmlspecialchars($m['descripcion'] ?? '') ?>
                            <?= $m['codigo'] ? ' ('.htmlspecialchars($m['codigo']).')' : '' ?>
                            <small><?= htmlspecialchars($m['categoria_nombre'] ?? '') ?> — Stock: <?= intval($m['stock_disponible']) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <input type="hidden" name="material_id" id="materialId">
            </div>

            <div class="form-grid">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Descripción *</label>
                    <input type="text" name="descripcion" id="itemDescripcion" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Marca</label>
                    <input type="text" name="marca" id="itemMarca" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Modelo</label>
                    <input type="text" name="modelo" id="itemModelo" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Cantidad *</label>
                    <input type="number" name="cantidad" id="itemCantidad" class="form-input" value="1" min="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Certificados (ej: ISO 9001, ASME)</label>
                    <input type="text" name="certificados" id="itemCertificados" class="form-input">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Observación</label>
                    <textarea name="observacion" id="itemObservacion" class="form-input" rows="2"></textarea>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nota Libre</label>
                    <textarea name="nota_libre" id="itemNotaLibre" class="form-input" rows="2"></textarea>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="es_manufactura" id="checkManufactura" onchange="toggleManufactura(this)">
                        <span class="form-label" style="margin:0;">Manufactura interna (se fabrica en planta)</span>
                    </label>
                </div>
            </div>

            <div class="manufactura-section" id="manufSection">
                <div style="font-weight:700;color:#7B3F00;margin-bottom:10px;font-size:.85rem;">
                    <i class="fas fa-industry"></i> Datos de Fabricación
                </div>
                <div class="form-group">
                    <label class="form-label">Nombre de la pieza a fabricar *</label>
                    <input type="text" name="nombre_pieza" id="itemNombrePieza" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Observación de fabricación</label>
                    <textarea name="obs_fabricacion" id="itemObsFab" class="form-input" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Subir plano (PDF, JPG, PNG — máx 15MB)</label>
                    <input type="file" name="plano" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
        </div>
    </form>
</div>
</div>

<!-- ── Modal: Historial de cambios (Feature 6) ── -->
<div class="modal-overlay" id="modalHistorial">
<div class="modal modal-wide">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-history"></i> Historial de Cambios del BOM</div>
        <button class="modal-close" onclick="cerrarModalHistorial()">&times;</button>
    </div>
    <div class="modal-body">
        <?php if (empty($historial)): ?>
        <p style="color:#999;text-align:center;padding:20px;">Sin cambios registrados aún.</p>
        <?php else: ?>
        <table class="historial-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($historial as $h): ?>
            <tr>
                <td style="white-space:nowrap;"><?= date('d/m/Y H:i',strtotime($h['fecha_creacion'])) ?></td>
                <td><?= htmlspecialchars($h['usuario_nombre'] ?? 'Sistema') ?></td>
                <td><span class="accion-<?= $h['accion'] ?>"><?= ucfirst($h['accion']) ?></span></td>
                <td><?= htmlspecialchars($h['campo'] ?? '—') ?></td>
                <td style="max-width:180px;word-break:break-all;"><?= htmlspecialchars($h['valor_anterior'] ?? '—') ?></td>
                <td style="max-width:180px;word-break:break-all;"><?= htmlspecialchars($h['valor_nuevo'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <button class="btn btn-outline" onclick="cerrarModalHistorial()">Cerrar</button>
    </div>
</div>
</div>

<!-- ── Modal: Cambiar estado plano (Feature 4) ── -->
<div class="modal-overlay" id="modalEstadoPlano">
<div class="modal" style="max-width:480px;">
    <div class="modal-header" style="background:#5D4037;">
        <div class="modal-title" style="color:#fff;"><i class="fas fa-tasks"></i> Estado del Plano</div>
        <button class="modal-close" onclick="cerrarModalEstado()">&times;</button>
    </div>
    <form method="POST">
        <input type="hidden" name="accion" value="cambiar_estado_plano">
        <input type="hidden" name="boom_id" value="<?= $boom_id ?>">
        <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Nuevo Estado</label>
                <select name="estado_plano" class="form-input">
                    <option value="borrador"    <?= $estado_plano==='borrador'    ? 'selected':'' ?>>Borrador</option>
                    <option value="enviado"     <?= $estado_plano==='enviado'     ? 'selected':'' ?>>Enviado a Manufactura</option>
                    <option value="en_revision" <?= $estado_plano==='en_revision' ? 'selected':'' ?>>En Revisión</option>
                    <option value="aprobado"    <?= $estado_plano==='aprobado'    ? 'selected':'' ?>>Aprobado</option>
                    <option value="rechazado"   <?= $estado_plano==='rechazado'   ? 'selected':'' ?>>Rechazado</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Comentario / Observación (opcional)</label>
                <textarea name="comentario_plano" class="form-input" rows="3" placeholder="Describe el motivo del cambio..."><?= htmlspecialchars($plano_coment) ?></textarea>
            </div>
            <?php if (in_array($estado_plano, ['en_revision','rechazado'])): ?>
            <div style="background:#fff8e1;border:1px solid #FFB300;border-radius:8px;padding:10px;font-size:.82rem;color:#5D4037;">
                <i class="fas fa-exclamation-triangle"></i>
                Al marcar como "En Revisión" o "Rechazado" se notificará al equipo de Ingeniería.
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="cerrarModalEstado()">Cancelar</button>
            <button type="submit" class="btn" style="background:#5D4037;color:#fff;"><i class="fas fa-check"></i> Guardar Estado</button>
        </div>
    </form>
</div>
</div>

<!-- ── Modal: Reemplazar archivo del plano (Feature 10) ── -->
<div class="modal-overlay" id="modalReemplazarPlano">
<div class="modal" style="max-width:420px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-exchange-alt"></i> Reemplazar Archivo del Plano</div>
        <button class="modal-close" onclick="cerrarModalReemplazar()">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="reemplazar_plano">
        <input type="hidden" name="boom_id" value="<?= $boom_id ?>">
        <input type="hidden" name="proyecto_id" value="<?= $proyecto_id ?>">
        <div class="modal-body">
            <?php if ($plano_ruta_act): ?>
            <p style="margin-bottom:12px;font-size:.85rem;color:#555;">
                <i class="fas fa-file"></i> Archivo actual:
                <a href="uploads/planos/<?= htmlspecialchars($plano_ruta_act) ?>" target="_blank"
                   style="color:#1565c0;"><?= htmlspecialchars(basename($plano_ruta_act)) ?></a>
            </p>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">Nuevo archivo (PDF, JPG, PNG — máx 15MB)</label>
                <input type="file" name="nuevo_plano" class="form-input" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="cerrarModalReemplazar()">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Subir Nuevo Plano</button>
        </div>
    </form>
</div>
</div>

<div class="fixed-footer">© <?= date('Y') ?> EMPRESA EJEMPLO — Sistema de Requisiciones</div>

<script>
const itemsData = <?= json_encode(array_column($items, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;

// ── Modal Item ──────────────────────────────────────────────
function abrirModalItem(id) {
    document.getElementById('modalItemTitle').textContent = id > 0 ? 'Editar Item' : 'Agregar Item';
    document.getElementById('itemId').value = id;
    const modal = document.getElementById('modalItem');

    document.getElementById('formItem').reset();
    document.getElementById('materialId').value = '';
    document.getElementById('materialSearch').value = '';
    document.getElementById('manufSection').classList.remove('visible');

    if (id > 0 && itemsData[id]) {
        const it = itemsData[id];
        document.getElementById('itemDescripcion').value  = it.descripcion || '';
        document.getElementById('itemMarca').value        = it.marca || '';
        document.getElementById('itemModelo').value       = it.modelo || '';
        document.getElementById('itemCantidad').value     = it.cantidad || 1;
        document.getElementById('itemCertificados').value = it.certificados || '';
        document.getElementById('itemObservacion').value  = it.observacion || '';
        document.getElementById('itemNotaLibre').value    = it.nota_libre || '';
        document.getElementById('materialId').value       = it.material_id || '';
        if (it.material_nombre) document.getElementById('materialSearch').value = it.material_nombre;
        if (it.es_manufactura == 1) {
            document.getElementById('checkManufactura').checked = true;
            document.getElementById('manufSection').classList.add('visible');
            document.getElementById('itemNombrePieza').value = it.sub_nombre || '';
            document.getElementById('itemObsFab').value      = it.sub_obs   || '';
        }
    }
    modal.classList.add('open');
}
function cerrarModal() {
    document.getElementById('modalItem').classList.remove('open');
}
function toggleManufactura(cb) {
    document.getElementById('manufSection').classList.toggle('visible', cb.checked);
    document.getElementById('itemNombrePieza').required = cb.checked;
}

// ── Filtros en modal (por categoría) ───────────────────────
function filtrarCatalogoModal(chip, catId) {
    document.querySelectorAll('#modalItem .cat-chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    const items = document.querySelectorAll('#materialDropdown .selector-item');
    items.forEach(it => {
        it.style.display = (!catId || it.dataset.cat == catId) ? '' : 'none';
    });
}

// ── Búsqueda de materiales ──────────────────────────────────
function filtrarMateriales(q) {
    const dd = document.getElementById('materialDropdown');
    const items = dd.querySelectorAll('.selector-item');
    q = q.toLowerCase();
    let found = false;
    items.forEach(it => {
        const match = it.textContent.toLowerCase().includes(q);
        // respect category filter too
        const activeCat = document.querySelector('#modalItem .cat-chip.active');
        const catId = activeCat ? activeCat.dataset.cat : '';
        const catMatch = !catId || it.dataset.cat == catId;
        it.style.display = (match && catMatch) ? '' : 'none';
        if (match && catMatch) found = true;
    });
    dd.classList.toggle('open', q.length > 0 && found);
}
function seleccionarMaterial(el) {
    document.getElementById('materialId').value = el.dataset.id;
    document.getElementById('materialSearch').value = el.dataset.nombre;
    if (!document.getElementById('itemMarca').value)   document.getElementById('itemMarca').value   = el.dataset.marca   || '';
    if (!document.getElementById('itemModelo').value)  document.getElementById('itemModelo').value  = el.dataset.modelo  || '';
    if (!document.getElementById('itemDescripcion').value) document.getElementById('itemDescripcion').value = el.dataset.nombre;
    document.getElementById('materialDropdown').classList.remove('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.selector-container')) {
        document.getElementById('materialDropdown').classList.remove('open');
    }
});
document.getElementById('modalItem').addEventListener('click', e => {
    if (e.target === document.getElementById('modalItem')) cerrarModal();
});

// ── Filtro de tabla por categoría (Feature 1) ───────────────
function filtrarPorCategoria(chip, catId) {
    document.querySelectorAll('#catFilterBar .cat-chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    const rows = document.querySelectorAll('#itemsTableBOM tbody tr');
    rows.forEach(row => {
        row.style.display = (!catId || row.dataset.cat == catId) ? '' : 'none';
    });
}

// ── Historial (Feature 6) ───────────────────────────────────
function abrirHistorial() {
    document.getElementById('modalHistorial').classList.add('open');
}
function cerrarModalHistorial() {
    document.getElementById('modalHistorial').classList.remove('open');
}
document.getElementById('modalHistorial').addEventListener('click', e => {
    if (e.target === document.getElementById('modalHistorial')) cerrarModalHistorial();
});

// ── Estado del plano (Feature 4) ───────────────────────────
function abrirCambiarEstado() {
    document.getElementById('modalEstadoPlano').classList.add('open');
}
function cerrarModalEstado() {
    document.getElementById('modalEstadoPlano').classList.remove('open');
}
document.getElementById('modalEstadoPlano').addEventListener('click', e => {
    if (e.target === document.getElementById('modalEstadoPlano')) cerrarModalEstado();
});

// ── Reemplazar plano (Feature 10) ──────────────────────────
function abrirReemplazarPlano() {
    document.getElementById('modalReemplazarPlano').classList.add('open');
}
function cerrarModalReemplazar() {
    document.getElementById('modalReemplazarPlano').classList.remove('open');
}
document.getElementById('modalReemplazarPlano').addEventListener('click', e => {
    if (e.target === document.getElementById('modalReemplazarPlano')) cerrarModalReemplazar();
});
</script>
</body>
</html>
