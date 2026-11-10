<?php
require_once 'includes/config.php';

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

$categorias = getCategoriasActivas($db);
$proveedores = $db->query("SELECT * FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        
        case 'crear_proyecto':
            $nombre = sanitize($_POST['nombre_proyecto']);
            $descripcion = sanitize($_POST['descripcion_proyecto'] ?? '');

            try {
                $stmt = $db->prepare("INSERT INTO proyectos (nombre, descripcion, creado_por, fecha_creacion) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$nombre, $descripcion, $_SESSION['user_id']]);
                $message = showMessage("Proyecto '$nombre' creado correctamente", 'success');

                notificarRoles(
                    ['almacen', 'inventario'],
                    'nuevo_proyecto',
                    "Nuevo proyecto: $nombre",
                    "El proyecto \"$nombre\" fue registrado. Estará disponible en cuanto Ingeniería finalice el BOM.",
                    'almacen2.php?seccion=proyectos'
                );
            } catch (Exception $e) {
                $error = showMessage("Error al crear proyecto: " . $e->getMessage(), 'error');
            }
            break;
            
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
            
        case 'eliminar_proyecto':
            $id = intval($_POST['id']);

            try {
                // Limpieza adicional (silenciosa si falla alguna)
                foreach ([
                    "DELETE ol FROM ot_logs ol INNER JOIN ordenes_trabajo ot ON ol.ot_id = ot.id WHERE ot.proyecto_id = ?",
                    "DELETE oa FROM ot_actividades oa INNER JOIN ordenes_trabajo ot ON oa.orden_id = ot.id WHERE ot.proyecto_id = ?",
                    "DELETE op FROM ot_procesos op INNER JOIN ordenes_trabajo ot ON op.ot_id = ot.id WHERE ot.proyecto_id = ?",
                    "DELETE FROM ordenes_trabajo WHERE proyecto_id = ?",
                    "DELETE FROM calendario_eventos WHERE proyecto_id = ?",
                    "DELETE sm FROM subproyecto_materiales sm INNER JOIN subproyectos_manufactura sp ON sm.subproyecto_id = sp.id WHERE sp.proyecto_id = ?",
                    "DELETE FROM subproyectos_manufactura WHERE proyecto_id = ?",
                ] as $_sql) {
                    try { $db->prepare($_sql)->execute([$id]); } catch (Exception $_e) {}
                }
                $db->prepare("DELETE FROM boom_items WHERE proyecto_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM boom_listas WHERE proyecto_id = ?")->execute([$id]);
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

            try {
                $stmt = $db->prepare("INSERT INTO boom_listas (proyecto_id, nombre, num_equipos, creado_por, fecha_creacion, guardado) VALUES (?, ?, ?, ?, NOW(), 0)");
                $stmt->execute([$proyecto_id, $nombre_boom, $num_equipos, $_SESSION['user_id']]);
                $boom_id = $db->lastInsertId();

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

        case 'eliminar_boom_lista':
            $boom_id = intval($_POST['boom_id']);

            try {
                $db->prepare("DELETE FROM boom_items WHERE boom_id = ?")->execute([$boom_id]);
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

            try {
                // Bloquear si el proyecto está finalizado
                $stmt_fin = $db->prepare("SELECT estado, realizada FROM proyectos WHERE id = ?");
                $stmt_fin->execute([$proyecto_id]);
                $proy_fin = $stmt_fin->fetch();
                if ($proy_fin && ($proy_fin['estado'] === 'realizado' || $proy_fin['realizada'] == 1)) {
                    $error = showMessage("No se puede guardar el BOM: el proyecto está finalizado.", 'error');
                    break;
                }
                $db->prepare("UPDATE boom_listas SET guardado = 1, nombre = ?, num_equipos = ? WHERE id = ?")
                   ->execute([$nombre_boom, $num_equipos, $boom_id]);
                $db->prepare("UPDATE boom_items SET guardado = 1 WHERE boom_id = ? AND (guardado = 0 OR guardado IS NULL)")
                   ->execute([$boom_id]);

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

                $message = showMessage("BOM guardado correctamente.", 'success');
                header('Location: ?seccion=proyectos');
                exit();
            } catch (Exception $e) {
                $error = showMessage("Error al guardar BOM: " . $e->getMessage(), 'error');
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
                $nombre = sanitize($_POST['nombre']);
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
                
                $stmt = $db->prepare("INSERT INTO materiales
                    (codigo, descripcion, categoria_id, marca, modelo, precio_unitario, stock_disponible, stock_minimo, unidad_medida, imagen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$codigo, $descripcion, $categoria_id, $marca, $modelo, $precio, $stock, $stock_min, $unidad, $imagen]);
                $new_id = $db->lastInsertId();
                
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'materiales', ?, ?)")
                   ->execute([$_SESSION['user_id'], $new_id, "Material creado: $nombre"]);
                
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

            $db->beginTransaction();
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
                        $db->prepare("UPDATE boom_items SET cantidad = ? WHERE id = ?")
                           ->execute([$existente['cantidad'] + $cantidad, $existente['id']]);
                    } else {
                        $stmt_mat = $db->prepare("SELECT descripcion, modelo, marca FROM materiales WHERE id = ?");
                        $stmt_mat->execute([$material_id]);
                        $material = $stmt_mat->fetch();

                        if ($material) {
                            $db->prepare("INSERT INTO boom_items (proyecto_id, boom_id, item, descripcion, modelo, marca, cantidad, material_id, fecha_agregado, agregado_por, guardado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0)")
                               ->execute([$proyecto_id, $boom_id, $material['descripcion'], $material['descripcion'] ?? '', $material['modelo'] ?? '', $material['marca'] ?? '', $cantidad, $material_id, $_SESSION['user_id']]);
                        }
                    }
                }

                $db->commit();
                header("Location: ?seccion=boom&proyecto_boom=$proyecto_id&boom_id=$boom_id&t=" . time());
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                $error = showMessage("Error al agregar materiales: " . $e->getMessage(), 'error');
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

                $stmt = $db->prepare("INSERT INTO proyectos (nombre, descripcion, creado_por, fecha_creacion) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$nuevo_nombre, "Reutilizado de proyecto ID: " . $proyecto_origen_id, $_SESSION['user_id']]);
                $nuevo_proyecto_id = $db->lastInsertId();

                // Copy all boom_listas (and their items) from origin project
                $listas_origen = $db->prepare("SELECT * FROM boom_listas WHERE proyecto_id = ? AND guardado = 1");
                $listas_origen->execute([$proyecto_origen_id]);
                foreach ($listas_origen->fetchAll() as $lista) {
                    $db->prepare("INSERT INTO boom_listas (proyecto_id, nombre, num_equipos, creado_por, fecha_creacion, guardado) VALUES (?, ?, ?, ?, NOW(), 0)")
                       ->execute([$nuevo_proyecto_id, $lista['nombre'], $lista['num_equipos'], $_SESSION['user_id']]);
                    $nueva_lista_id = $db->lastInsertId();

                    $items_origen = $db->prepare("SELECT material_id, item, descripcion, modelo, marca, cantidad FROM boom_items WHERE boom_id = ? AND guardado = 1");
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
                $stmt = $db->prepare("DELETE FROM boom_items WHERE id = ?");
                $stmt->execute([$item_id]);
                $message = showMessage("Item eliminado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error al eliminar item: " . $e->getMessage(), 'error');
            }
            break;

        case 'editar_cantidad_item':
            $item_id = intval($_POST['item_id']);
            $nueva_cantidad = intval($_POST['nueva_cantidad']);
            try {
                if ($nueva_cantidad <= 0) {
                    $db->prepare("DELETE FROM boom_items WHERE id = ?")->execute([$item_id]);
                    $message = showMessage("Item eliminado del BOM", 'success');
                } else {
                    $db->prepare("UPDATE boom_items SET cantidad = ? WHERE id = ?")->execute([$nueva_cantidad, $item_id]);
                    $message = showMessage("Cantidad actualizada correctamente", 'success');
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
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

// Active project / boom_lista
$proyecto_boom_id   = isset($_SESSION['proyecto_boom_activo']) ? intval($_SESSION['proyecto_boom_activo']) : 0;
$boom_lista_id      = isset($_SESSION['boom_lista_activo'])    ? intval($_SESSION['boom_lista_activo'])    : 0;
$proyecto_boom_nombre = '';
$boom_lista_nombre    = 'BOM Principal';
$boom_num_equipos     = 1;

$proyecto_finalizado = false;
if ($proyecto_boom_id) {
    $stmt = $db->prepare("SELECT nombre, estado, realizada FROM proyectos WHERE id = ?");
    $stmt->execute([$proyecto_boom_id]);
    $proy_data = $stmt->fetch();
    $proyecto_boom_nombre = $proy_data['nombre'] ?? '';
    if (!$proyecto_boom_nombre) {
        $proyecto_boom_id = 0;
        $boom_lista_id    = 0;
        unset($_SESSION['proyecto_boom_activo'], $_SESSION['boom_lista_activo']);
    } else {
        $proyecto_finalizado = ($proy_data['estado'] === 'realizado' || $proy_data['realizada'] == 1);
    }
}
if ($boom_lista_id) {
    $stmt = $db->prepare("SELECT nombre, num_equipos FROM boom_listas WHERE id = ?");
    $stmt->execute([$boom_lista_id]);
    $bl = $stmt->fetch();
    if ($bl) {
        $boom_lista_nombre  = $bl['nombre'];
        $boom_num_equipos   = $bl['num_equipos'];
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
    GROUP BY bl.id
    ORDER BY bl.proyecto_id, bl.fecha_creacion ASC
");
foreach ($stmt_listas->fetchAll() as $row) {
    $boms_por_proyecto[$row['proyecto_id']][] = $row;
}

// Conteo de BOMs per project for folder badges
$conteo_items = [];
foreach ($boms_por_proyecto as $pid => $listas) {
    $guardados   = array_sum(array_column($listas, 'num_items_guardados'));
    $en_progreso = array_sum(array_column($listas, 'num_items_borrador'));
    $conteo_items[$pid] = ['guardados' => $guardados, 'en_progreso' => $en_progreso];
}

// Fallback: if boom_lista_id not set but proyecto_boom_id is, pick the first unsaved lista
if ($proyecto_boom_id && !$boom_lista_id) {
    $stmt = $db->prepare("SELECT bl.id, bl.nombre, bl.num_equipos FROM boom_listas bl LEFT JOIN boom_items bi ON bi.boom_id = bl.id WHERE bl.proyecto_id = ? AND (bi.guardado = 0 OR bi.guardado IS NULL) GROUP BY bl.id LIMIT 1");
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
    $stmt = $db->prepare("SELECT bl.id, bl.nombre, bl.num_equipos, bl.guardado FROM boom_listas bl WHERE bl.proyecto_id = ? ORDER BY bl.fecha_creacion ASC");
    $stmt->execute([$proyecto_boom_id]);
    $boms_del_proyecto = $stmt->fetchAll();
}

// Items for the active boom_lista
$items_boom = [];
if ($boom_lista_id) {
    $stmt = $db->prepare("SELECT b.*, p.nombre as proyecto_nombre FROM boom_items b JOIN proyectos p ON b.proyecto_id = p.id WHERE b.boom_id = ? ORDER BY b.guardado DESC, b.fecha_agregado ASC");
    $stmt->execute([$boom_lista_id]);
    $items_boom = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingeniería - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="js/theme.js"></script>
    <link rel="stylesheet" href="css/theme.css">
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
            font-family: 'Figtree', Arial, sans-serif;
            background: var(--surface);
            height: 100vh;
            overflow: hidden;
        }
        
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            box-shadow: 0 2px 10px rgba(0,86,134,0.2);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }
        
        .header-logo {
            height: 60px;
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
            background: var(--surface);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-5px);
            transition: all 0.2s;
            border: 1px solid var(--border);
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
            color: var(--text-1);
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-size: 13px;
        }
        
        .dropdown-item:hover {
            background: var(--surface-2);
            color: var(--danger);
        }
        
        .dropdown-item i {
            width: 18px;
            color: var(--text-2);
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
            color: var(--primary);
            font-weight: 300;
            margin-bottom: 10px;
        }
        
        .page-title strong {
            font-weight: 800;
            color: var(--primary-light);
            background: linear-gradient(135deg, #003d5c, #0077b3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.4rem;
        }
        
        .page-subtitle {
            color: var(--text-2);
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
        
        .card-icon {
            width: 100%;
            aspect-ratio: 1/1;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            border: 3px solid var(--border);
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
            color: var(--primary-light);
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
            color: var(--text-1);
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
            border-top: 2px solid var(--border);
            background: var(--surface);
            height: 60px;
            z-index: 1000;
        }
        
        .footer-left {
            display: flex;
            gap: 30px;
            color: var(--text-2);
            font-size: 13px;
            font-weight: 500;
        }
        
        .footer-left i {
            color: var(--primary);
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
            padding: 0 30px 30px 30px;
            margin-top: 0;
            position: relative;
        }
        
        .sticky-container {
            position: sticky;
            top: 0;
            background: var(--surface);
            z-index: 90;
            padding: 20px 0;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }
        
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-row h2 {
            font-size: 1.5rem;
            color: var(--primary);
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
            border: 2px solid var(--border);
            border-radius: 40px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(0,86,134,0.1);
        }
        
        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-3);
            cursor: pointer;
            font-size: 16px;
            display: none;
        }
        
        .search-clear.visible {
            display: block;
        }
        
        .search-clear:hover {
            color: var(--danger);
        }
        
        .btn-categorias {
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: 40px;
            padding: 10px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--primary);
            transition: all 0.2s;
        }
        
        .btn-categorias:hover {
            border-color: var(--primary-light);
            color: var(--primary-light);
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
            background: var(--primary);
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
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #388E3C; }
        .btn-warning { background: #FF9800; color: white; }
        .btn-warning:hover { background: #F57C00; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: var(--danger); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text-2); }
        .btn-outline:hover { background: var(--surface-2); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .section {
            background: var(--surface);
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
            color: var(--primary);
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
        .form-label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: var(--text-1); }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; }
        select.form-input { background: var(--surface); }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 12px 10px;
            background: var(--surface-2);
            color: var(--text-1);
            font-weight: 600;
            font-size: 13px;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        tr:hover td { background: var(--surface-2); }
        
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
            background: var(--surface);
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
        }
        .modal-header {
            background: var(--primary);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        .modal-footer {
            padding: 15px 20px;
            background: var(--surface-2);
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
            color: var(--text-2);
            margin-bottom: 10px;
        }
        
        .categoria-card.selected i {
            color: #2196F3;
        }
        
        .categoria-card .cat-nombre {
            font-weight: 600;
            color: var(--text-1);
        }
        
/* ===== TARJETAS DE MATERIALES COMPACTAS ===== */
.materiales-grid {
    display: grid;
    /* Reducimos el ancho mínimo de 280px a 200px para que quepan más por fila */
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px; /* Reducimos la separación entre tarjetas */
}

.material-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
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
    background: var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
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
    color: var(--text-1); 
    margin-bottom: 3px; 
    white-space: nowrap; /* Evita que el nombre baje a 2 líneas si es muy largo */
    overflow: hidden;
    text-overflow: ellipsis;
}

.material-descripcion { 
    font-size: 11px; 
    color: var(--text-2); 
    margin-bottom: 6px;
    white-space: nowrap; /* Evita descripciones largas de varias líneas */
    overflow: hidden;
    text-overflow: ellipsis;
}

.material-detalles {
    display: flex;
    gap: 10px;
    font-size: 11px; /* Letra de detalles más pequeña */
    color: var(--text-2);
    flex-wrap: wrap;
}

.cantidad-control {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
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
    background: var(--primary);
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
        .proy-folder-body { display: none; background: var(--surface-2); }
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
            background: var(--surface);
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
            background: var(--success);
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
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: 4px;
            cursor: pointer;
            font-size: 18px;
        }
        
        .cantidad-input {
            width: 80px;
            height: 40px;
            text-align: center;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 18px;
            font-weight: bold;
        }
        
        .btn-limpiar {
            background: var(--surface-2);
            color: var(--text-2);
            border: 1px solid var(--border);
        }
        
        .btn-limpiar:hover {
            background: var(--border);
            color: var(--text-1);
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
            background: var(--surface);
            border: 1px solid var(--border);
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
            background: var(--surface-2);
            border-bottom: 2px solid var(--border);
        }

        .panel-header h2, .panel-header h3 {
            margin: 0;
            font-size: 1.2rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: var(--surface);
        }

        .btn-maximize {
            background: var(--surface);
            border: 1px solid #ccc;
            color: var(--text-2);
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
            background: var(--border);
            color: var(--primary);
            border-color: var(--primary);
        }
        /* ===== TARJETAS DE PROYECTOS (ESTILO PROVEEDORES) ===== */
        .proyectos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .proyecto-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }

        .proyecto-card:hover {
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            transform: translateY(-3px);
            border-color: var(--primary-light);
        }

        .proyecto-header {
            background: var(--primary);
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
            color: var(--text-2);
            line-height: 1.4;
        }

        .proyecto-detalle-item i {
            width: 18px;
            color: var(--primary);
            margin-top: 2px;
        }

        .proyecto-footer {
            padding: 12px 15px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: var(--surface-2);
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
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
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
            background-color: var(--danger);
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
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
            background: var(--surface);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            z-index: 9998;
            overflow: hidden;
            border: 1px solid var(--border);
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
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
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
            border-bottom: 1px solid var(--border);
            background: var(--surface-2);
        }
        .chat-tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-2);
            border-bottom: 2px solid transparent;
        }
        .chat-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
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
            background: var(--surface-2);
            border-bottom: 1px solid var(--border);
        }
        .chat-view-title {
            font-weight: 600;
            font-size: 16px;
            color: var(--text-1);
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
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }
        .chat-user-item:hover {
            background: var(--primary-dim);
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
            color: var(--text-1);
            margin-bottom: 3px;
        }
        .chat-user-role {
            font-size: 11px;
            color: var(--text-3);
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
            background: var(--danger);
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
            color: var(--primary);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-back-chat:hover {
            background: var(--border);
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
            background: var(--surface-2);
            border-bottom: 1px solid var(--border);
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
            background: var(--surface-2);
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
            background: var(--primary);
            color: white;
            border-bottom-right-radius: 5px;
        }
        .message-received .message-bubble {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-1);
            border-bottom-left-radius: 5px;
        }
        .message-time {
            font-size: 10px;
            color: var(--text-3);
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
            border-top: 1px solid var(--border);
            background: var(--surface);
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
            border: 1px solid var(--border);
            border-radius: 20px;
            font-size: 13px;
            outline: none;
            resize: vertical;
            font-family: inherit;
            min-height: 40px;
            max-height: 120px;
        }
        .chat-input-row textarea:focus {
            border-color: var(--primary);
        }
        .chat-input-row button {
            background: var(--primary);
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
            color: var(--text-2);
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 50%;
        }
        .chat-file-btn:hover {
            background: #f0f0f0;
            color: var(--primary);
        }
        .empty-chat {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-3);
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
            background: var(--surface);
            z-index: 10000;
            display: none;
            flex-direction: column;
        }
        .file-preview-modal.active {
            display: flex;
        }
        .file-preview-header {
            background: var(--primary);
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
            color: var(--primary);
            margin-bottom: 5px;
        }
        .notification-message {
            font-size: 12px;
            color: var(--text-2);
        }
        .notification-time {
            font-size: 10px;
            color: var(--text-3);
            margin-top: 5px;
        }
    </style>
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
            
            <button id="themeToggle" onclick="toggleTheme()" title="Cambiar tema">
                <i id="themeIcon" class="fas fa-moon"></i>
            </button>

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
                
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($seccion === 'inventario'): ?>
        <div class="section-content">
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
            
            <div class="content-panel">
                <div class="materiales-grid" id="inventarioGrid">
                    <?php foreach ($materiales as $mat): ?>
                    <div class="material-card" data-nombre="<?php echo strtolower($mat['nombre']); ?>" data-codigo="<?php echo strtolower($mat['codigo'] ?? ''); ?>" data-categoria="<?php echo $mat['categoria_id']; ?>">
                        <div class="material-imagen">
                            <?php if (!empty($mat['imagen'])): ?>
                                <img src="uploads/<?php echo $mat['imagen']; ?>" alt="<?php echo $mat['nombre']; ?>">
                            <?php else: ?>
                                <i class="fas fa-box"></i>
                            <?php endif; ?>
                        </div>
                        <div class="material-content">
                            <div class="material-nombre"><?php echo $mat['nombre']; ?></div>
                            <div class="material-descripcion"><?php echo $mat['descripcion'] ?: 'Sin descripción'; ?></div>
                            <div class="material-detalles">
                                <span><strong>Marca:</strong> <?php echo $mat['marca'] ?: '-'; ?></span>
                                <span><strong>Modelo:</strong> <?php echo $mat['modelo'] ?: '-'; ?></span>
                                <?php if ($mat['categoria_nombre']): ?>
                                <span><strong>Categoría:</strong> <?php echo $mat['categoria_nombre']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
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
                    $lista_finalizado = ($proy['estado'] === 'realizado' || $proy['realizada'] == 1);
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
                            <?php if (count($boms) > 0): ?>
                            <span style="background:#e3f2fd;color:#1565c0;font-size:11px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                <i class="fas fa-layer-group"></i> <?php echo count($boms); ?> BOM<?php echo count($boms)!=1?'s':''; ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($lista_finalizado): ?>
                            <span style="background:#757575;color:white;font-size:11px;padding:3px 10px;border-radius:12px;font-weight:600;">
                                <i class="fas fa-lock"></i> Finalizado
                            </span>
                            <?php if (!empty($boms)): ?>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalReutilizar(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Reutilizar en nuevo proyecto">
                                <i class="fas fa-copy"></i> Reutilizar
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
                            <?php if (!empty($boms)): ?>
                            <button class="btn btn-outline btn-sm" onclick="abrirModalReutilizar(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Reutilizar en nuevo proyecto">
                                <i class="fas fa-copy"></i> Reutilizar
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-primary btn-sm" onclick="editarProyecto(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars(addslashes($proy['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>')" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="eliminarProyecto(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

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
                                        <?php if ($bl['guardado']): ?>
                                        <span style="background:#e8f5e9;color:#2e7d32;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;"><i class="fas fa-check"></i> Guardado</span>
                                        <?php else: ?>
                                        <span style="background:#fff8e1;color:#f57f17;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;"><i class="fas fa-pencil-alt"></i> En progreso</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:center;">
                                        <?php if ($lista_finalizado): ?>
                                        <span style="color:#bbb;font-size:13px;" title="Proyecto finalizado"><i class="fas fa-lock"></i> Bloqueado</span>
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
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
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
                            <?php if (!$proyecto_finalizado): ?>
                            <button class="btn btn-success" onclick="agregarSeleccionados()">
                                <i class="fas fa-plus-circle"></i> Agregar a BOM
                            </button>
                            <?php else: ?>
                            <button class="btn btn-success" disabled style="opacity:0.45;cursor:not-allowed;" title="Proyecto finalizado">
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
                            
                            <div class="materiales-grid" id="materialesBOMGrid">
                                <?php foreach ($materiales as $mat): ?>
                                <div class="material-card" data-id="<?php echo $mat['id']; ?>" data-nombre="<?php echo strtolower($mat['nombre']); ?>" data-codigo="<?php echo strtolower($mat['codigo'] ?? ''); ?>" data-categoria="<?php echo $mat['categoria_id']; ?>">
                                    <div class="material-imagen">
                                        <?php if (!empty($mat['imagen'])): ?>
                                            <img src="uploads/<?php echo $mat['imagen']; ?>" alt="<?php echo $mat['nombre']; ?>">
                                        <?php else: ?>
                                            <i class="fas fa-box"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="material-content">
                                        <div class="material-nombre"><?php echo $mat['nombre']; ?></div>
                                        <div class="material-descripcion"><?php echo $mat['descripcion'] ?: 'Sin descripción'; ?></div>
                                        <div class="material-detalles">
                                            <span><strong>Marca:</strong> <?php echo $mat['marca'] ?: '-'; ?></span>
                                            <span><strong>Modelo:</strong> <?php echo $mat['modelo'] ?: '-'; ?></span>
                                        </div>
                                        <div class="cantidad-control">
                                            <span class="cantidad-valor" id="cantidad-<?php echo $mat['id']; ?>">0</span>
                                            <div class="cantidad-botones">
                                                <button type="button" onclick="cambiarCantidad(<?php echo $mat['id']; ?>, -1)">-</button>
                                                <button type="button" onclick="cambiarCantidad(<?php echo $mat['id']; ?>, 1)">+</button>
                                            </div>
                                        </div>
                                        <input type="hidden" name="items[<?php echo $mat['id']; ?>][material_id]" value="<?php echo $mat['id']; ?>">
                                        <input type="hidden" name="items[<?php echo $mat['id']; ?>][cantidad]" id="input-<?php echo $mat['id']; ?>" value="0">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </form>
                    </div>
                </div>

<div class="split-panel" id="panelTabla">
                    <div class="panel-header">
                        <h3><i class="fas fa-list-ol"></i> Items agregados a este proyecto</h3>
                        <div class="action-buttons">
                            <?php if ($proyecto_finalizado): ?>
                            <span style="background:#f44336;color:white;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600;">
                                <i class="fas fa-lock"></i> Proyecto finalizado — solo lectura
                            </span>
                            <?php else: ?>
                            <?php
                            // Solo mostramos el botón de guardar si existe al menos un item NUEVO
                            $hay_nuevos = false;
                            foreach ($items_boom as $ib) {
                                if ($ib['guardado'] == 0 || $ib['guardado'] === null) {
                                    $hay_nuevos = true; break;
                                }
                            }
                            if ($hay_nuevos): ?>
                            <button class="btn btn-warning" onclick="confirmarGuardarBoom(<?php echo $proyecto_boom_id; ?>, <?php echo $boom_lista_id; ?>, '<?php echo htmlspecialchars(addslashes($boom_lista_nombre), ENT_QUOTES, 'UTF-8'); ?>')">
                                <i class="fas fa-save"></i> Guardar BOM
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <button type="button" class="btn-maximize" onclick="toggleMaximize('panelTabla')" title="Maximizar / Restaurar">
                                <i class="fas fa-expand-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="panel-body">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Item</th>
                                    <th>Descripción</th>
                                    <th>Modelo</th>
                                    <th>Marca</th>
                                    <th>Cantidad</th>
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
                                        $es_viejo = ($item['guardado'] == 1);
                                    ?>
                                    <tr data-item-id="<?php echo $item['id']; ?>" style="<?php echo $es_viejo ? 'background: #f5f5f5; opacity: 0.8;' : ''; ?>">
                                        <td><strong><?php echo $contador++; ?></strong></td>
                                        <td>
                                            <?php echo $item['item']; ?>
                                            <?php if ($es_viejo): ?>
                                                <span style="display: block; font-size: 10px; color: #4CAF50; font-weight: bold; margin-top: 2px;"><i class="fas fa-check-circle"></i> Ya enviado</span>
                                            <?php else: ?>
                                                <span style="display: block; font-size: 10px; color: #FF9800; font-weight: bold; margin-top: 2px;">Nuevo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $item['descripcion'] ?: '-'; ?></td>
                                        <td><?php echo $item['modelo'] ?: '-'; ?></td>
                                        <td><?php echo $item['marca'] ?: '-'; ?></td>
                                        <td><?php echo $item['cantidad']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($item['fecha_agregado'])); ?></td>
                                        <td style="display:flex;gap:4px;align-items:center;">
                                            <?php if (!$proyecto_finalizado): ?>
                                            <button class="btn-icon" title="Editar cantidad" onclick="abrirModalEditarCantidad(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item']), ENT_QUOTES, 'UTF-8'); ?>', <?php echo $item['cantidad']; ?>)">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <?php if (!$es_viejo): ?>
                                            <button class="btn-icon" onclick="abrirModalEliminarItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item']), ENT_QUOTES, 'UTF-8'); ?>')">
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
                    </div>
                </div>
            </div>
                
            </div>
            
            <?php endif; ?>
        </div>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalProyecto')">Cancelar</button>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalEditarProyecto')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Proyecto</button>
                </div>
            </form>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalEliminar')">Cancelar</button>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalGuardarBoom')">Cancelar</button>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalCancelarBoom')">No, Volver</button>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalNuevoBoom')">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Crear BOM</button>
                </div>
            </form>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalEditarCantidad')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </form>
            </div>
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
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalEliminarItem')">Cancelar</button>
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
                        <div class="form-group">
                            <label class="form-label">Código</label>
                            <input type="text" name="codigo" id="materialCodigo" class="form-input" placeholder="Código del material">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" id="materialNombre" class="form-input" required placeholder="Nombre del material">
                        </div>
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
                            <input type="text" name="marca" id="materialMarca" class="form-input" placeholder="Marca">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Modelo</label>
                            <input type="text" name="modelo" id="materialModelo" class="form-input" placeholder="Modelo">
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
                        <div class="form-group full">
                            <label class="form-label">Descripción</label>
                            <textarea name="descripcion" id="materialDescripcion" class="form-input" rows="2" placeholder="Descripción del material"></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Imagen</label>
                            <input type="file" name="imagen" class="form-input" accept="image/*">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalMaterial')">Cancelar</button>
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
                <form method="POST" action="?seccion=booms_guardados" id="formReutilizar">
                    <input type="hidden" name="action" value="reutilizar_boom">
                    <input type="hidden" name="proyecto_id" id="reutilizar_proyecto_id" value="">
                    <input type="hidden" name="nuevo_nombre" id="reutilizar_nuevo_nombre" value="">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalReutilizar')">Cancelar</button>
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
                <button class="btn btn-outline btn-modal-cancel" onclick="cerrarModal('modalCantidad')">Cancelar</button>
                <button class="btn btn-success" onclick="confirmarCantidad()" id="btnConfirmarCantidad">
                    <i class="fas fa-check"></i> Agregar
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let categoriaSeleccionadaInventario = null;
        let categoriaSeleccionadaBOM = null;
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

        function initSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            
            if (!input) return;
            
            input.addEventListener('input', function() {
                if (this.value.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }
                if (section === 'inventario') {
                    filtrarInventario(this.value);
                } else if (section === 'bom') {
                    filtrarBOM(this.value);
                } else if (section === 'proyectos') {
                    filtrarProyectos(this.value);
                }
            });
        }
        
        function clearSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            
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
        
        function filtrarInventario(termino) {
            const cards = document.querySelectorAll('#inventarioGrid .material-card');
            termino = termino.toLowerCase();
            
            cards.forEach(card => {
                const nombre = card.dataset.nombre || '';
                const codigo = card.dataset.codigo || '';
                
                if (nombre.includes(termino) || codigo.includes(termino)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function filtrarBOM(termino) {
            const cards = document.querySelectorAll('#materialesBOMGrid .material-card');
            termino = termino.toLowerCase();
            
            cards.forEach(card => {
                const nombre = card.dataset.nombre || '';
                const codigo = card.dataset.codigo || '';
                
                if (nombre.includes(termino) || codigo.includes(termino)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
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
            
            const cards = document.querySelectorAll('#inventarioGrid .material-card');
            
            if (!categoriaSeleccionadaInventario) {
                cards.forEach(card => card.style.display = '');
                return;
            }
            
            cards.forEach(card => {
                if (card.dataset.categoria == categoriaSeleccionadaInventario) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function aplicarFiltroBOM() {
            cerrarModal('modalCategoriasBOM');
            
            const cards = document.querySelectorAll('#materialesBOMGrid .material-card');
            
            if (!categoriaSeleccionadaBOM) {
                cards.forEach(card => card.style.display = '');
                return;
            }
            
            cards.forEach(card => {
                if (card.dataset.categoria == categoriaSeleccionadaBOM) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
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
        
        function cambiarCantidad(materialId, delta) {
            const span = document.getElementById('cantidad-' + materialId);
            const input = document.getElementById('input-' + materialId);
            let cantidad = parseInt(span.textContent) + delta;
            if (cantidad < 0) cantidad = 0;
            span.textContent = cantidad;
            input.value = cantidad;
        }
        
        function agregarSeleccionados() {
    const form = document.getElementById('boomForm');
    
    let haySeleccion = false;
    const inputsCantidad = form.querySelectorAll('input[name$="[cantidad]"]');
    
    // Primero verificamos si el usuario seleccionó al menos un material (cantidad > 0)
    inputsCantidad.forEach(input => {
        if (parseInt(input.value) > 0) {
            haySeleccion = true;
        }
    });
    
    if (!haySeleccion) {
        alert('Selecciona al menos un material.');
        return;
    }
    
    // Desactivamos SOLO los campos con cantidad 0 para no saturar la petición POST (max_input_vars)
    inputsCantidad.forEach(input => {
        if (parseInt(input.value) <= 0) {
            input.disabled = true; // Deshabilita el input de cantidad
            
            // Busca y deshabilita el input de material_id correspondiente de forma SEGURA
            const matchId = input.id.replace('input-', '');
            const nombreMaterialId = `items[${matchId}][material_id]`;
            
            // Usamos form.elements que no tiene problemas con los corchetes []
            if (form.elements[nombreMaterialId]) {
                form.elements[nombreMaterialId].disabled = true;
            }
        }
    });
    
    // Enviamos el formulario
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
        
        function confirmarGuardarBoom(proyectoId, boomId, boomNombre) {
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
        if (!contactoId) return;
        fetch(`includes/ajax_chat.php?action=marcar_leidos&contacto=${contactoId}`);
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
<script>
(function(){
  const icon = document.getElementById('themeIcon');
  if(icon && localStorage.getItem('srTheme') === 'dark') icon.className = 'fas fa-sun';
})();
</script>
</body>
</html>