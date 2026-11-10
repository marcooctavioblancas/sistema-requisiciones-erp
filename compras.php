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
    header('Location: index.php');
    exit();
}

$user_type = isset($_SESSION['user_type']) ? trim($_SESSION['user_type']) : '';
$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;
if (!hasAcceso('compras')) {
    header('Location: sin_acceso.php');
    exit();
}

$db = getDB();
$message = '';
$error = '';
// Solo el jefe de Almacén (o admin) puede escribir código de verdad -- ver
// resolverCodigoMaterial() en includes/config.php.
$puedeAsignarCodigoMaterial = esJefeDe('almacen') || isAdmin();

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'eliminar_material':
            try {
                $id = intval($_POST['id']);
                $db->beginTransaction();
                $db->prepare("DELETE FROM historial_movimientos WHERE material_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM boom_items WHERE material_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM almacen_items WHERE material_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM requisicion_detalle WHERE material_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM orden_compra_detalle WHERE material_id = ?")->execute([$id]);
                $stmt = $db->prepare("DELETE FROM materiales WHERE id = ?");
                $stmt->execute([$id]);
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'eliminar', 'materiales', ?, 'Material eliminado físicamente')")
                    ->execute([$_SESSION['user_id'], $id]);
                $db->commit();
                $message = showMessage("Material borrado completamente de la base de datos", 'success');
            } catch (Exception $e) {
                $db->rollBack();
                $error = showMessage("Error al eliminar material: " . $e->getMessage(), 'error');
            }
            break;

        case 'guardar_material':
            try {
                $id          = $_POST['id'] ?? null;
                $codigo      = sanitize($_POST['codigo']      ?? '');
                $descripcion = strip_tags(trim($_POST['descripcion'] ?? '')); // campo principal -- no htmlspecialchars aquí, se escapa al mostrar (ver proyecto.php:1005)
                $categoria_id = $_POST['categoria_id'];
                $marca       = sanitize($_POST['marca']       ?? '');
                $modelo      = sanitize($_POST['modelo']      ?? '');
                $precio      = floatval($_POST['precio_unitario']  ?? 0);
                $stock       = max(0, intval($_POST['stock_disponible'] ?? 0)); // nunca negativo, ver registrarMovimientoAlmacen()
                $stock_min   = intval($_POST['stock_minimo']       ?? 0);
                $unidad      = sanitize($_POST['unidad_medida']    ?? 'pza');
                $imagen = null;
                if (!empty($_FILES['imagen']['name'])) {
                    $upload = uploadFile($_FILES['imagen'], 'materiales/');
                    if ($upload['success']) $imagen = $upload['file'];
                }
                if ($id) {
                    // Solo jefe de Almacén (o admin) puede escribir código de verdad -- ver
                    // resolverCodigoMaterial() en includes/config.php. A cualquier otro se le
                    // conserva el código y el estado de verificación que el material ya tenía.
                    $stmtActual = $db->prepare("SELECT codigo, codigo_verificado FROM materiales WHERE id = ?");
                    $stmtActual->execute([$id]);
                    $actual = $stmtActual->fetch();
                    $res = resolverCodigoMaterial($db, $actual['codigo'] ?? null, isset($actual['codigo_verificado']) ? (int)$actual['codigo_verificado'] : null, $codigo, $descripcion, false);

                    $sql    = "UPDATE materiales SET codigo=?, codigo_verificado=?, descripcion=?, categoria_id=?, marca=?, modelo=?, precio_unitario=?, stock_disponible=?, stock_minimo=?, unidad_medida=?";
                    $params = [$res['codigo'], $res['verificado'], $descripcion, $categoria_id, $marca, $modelo, $precio, $stock, $stock_min, $unidad];
                    if ($imagen) { $sql .= ", imagen=?"; $params[] = $imagen; }
                    $sql .= " WHERE id=?";
                    $params[] = $id;
                    $db->prepare($sql)->execute($params);
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
                        $db->prepare("UPDATE materiales SET carpeta_id = ? WHERE id = ?")->execute([$cid, $id]);
                    }
                    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'actualizar', 'materiales', ?, ?)")
                        ->execute([$_SESSION['user_id'], $id, "Material actualizado: $descripcion"]);
                    $message = showMessage("Material actualizado correctamente", 'success');
                } else {
                    $res = resolverCodigoMaterial($db, null, null, $codigo, $descripcion, true);
                    $new_id = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM materiales")->fetchColumn();
                    $stmt = $db->prepare("INSERT INTO materiales (id, codigo, codigo_verificado, descripcion, categoria_id, marca, modelo, precio_unitario, stock_disponible, stock_minimo, unidad_medida, imagen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                            "Compras agregó el material \"$descripcion\"" . ($marca ? " ($marca)" : "") . " con código sugerido \"{$res['codigo']}\" -- revísalo en Inventario.",
                            'almacen2.php?seccion=inventario', $_SESSION['user_id']);
                    } else {
                        notificarRoles(['almacen'], 'material_nuevo', "Nuevo material: $descripcion",
                            "Compras agregó el material \"$descripcion\"" . ($marca ? " ($marca)" : "") . " al catálogo.",
                            'almacen2.php', $_SESSION['user_id']);
                    }

                    $message = showMessage("Material agregado correctamente", 'success');
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'eliminar_proveedor':
            try {
                $id = intval($_POST['id']);
                try {
                    $stmt = $db->prepare("DELETE FROM proveedores WHERE id = ?");
                    $stmt->execute([$id]);
                    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'eliminar', 'proveedores', ?, 'Proveedor eliminado físicamente')")
                        ->execute([$_SESSION['user_id'], $id]);
                    $message = showMessage("Proveedor borrado completamente de la base de datos", 'success');
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') {
                        $db->prepare("UPDATE proveedores SET activo = 0 WHERE id = ?")->execute([$id]);
                        $message = showMessage("El proveedor tiene historial. Fue ocultado de las listas.", 'info');
                    } else {
                        throw $e;
                    }
                }
            } catch (Exception $e) {
                $error = showMessage("Error al eliminar proveedor: " . $e->getMessage(), 'error');
            }
            break;

        case 'guardar_proveedor':
            try {
                $id = $_POST['id'] ?? null;
                $nombre = sanitize($_POST['nombre']);
                $direccion = sanitize($_POST['direccion'] ?? '');
                $lada = sanitize($_POST['lada'] ?? '');
                $telefono = sanitize($_POST['telefono'] ?? '');
                $email = sanitize($_POST['email'] ?? '');
                $rfc = sanitize($_POST['rfc'] ?? '');
                $atencion = sanitize($_POST['atencion'] ?? '');
                $referencia = sanitize($_POST['referencia'] ?? '');
                $cats_ids = array_map('intval', $_POST['categorias_ids'] ?? []);
                $cats_json = !empty($cats_ids) ? json_encode($cats_ids) : null;
                $etiqueta = sanitize($_POST['etiqueta'] ?? '');
                if ($id) {
                    $stmt = $db->prepare("UPDATE proveedores SET nombre=?, direccion=?, lada=?, telefono=?, email=?, rfc=?, atencion=?, referencia=?, categorias_ids=?, etiqueta=? WHERE id=?");
                    $stmt->execute([$nombre, $direccion, $lada, $telefono, $email, $rfc, $atencion, $referencia, $cats_json, $etiqueta, $id]);
                    $message = showMessage("Proveedor actualizado correctamente", 'success');
                    header("Location: compras.php?seccion=proveedores&ver=" . $id . "&success=1");
                    exit();
                } else {
                    // proveedores.id ahora es AUTO_INCREMENT (antes se calculaba a mano con
                    // MAX(id)+1 -- dos altas de proveedor concurrentes podían calcular el
                    // mismo id, condición de carrera confirmada en la auditoría).
                    $stmt = $db->prepare("INSERT INTO proveedores (nombre, direccion, lada, telefono, email, rfc, atencion, referencia, categorias_ids, etiqueta, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$nombre, $direccion, $lada, $telefono, $email, $rfc, $atencion, $referencia, $cats_json, $etiqueta]);
                    $nuevo_id = (int)$db->lastInsertId();
                    $message = showMessage("Proveedor agregado correctamente", 'success');
                    header("Location: compras.php?seccion=proveedores&ver=" . $nuevo_id . "&success=1");
                    exit();
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'ajustar_stock':
            try {
                $material_id = intval($_POST['material_id']);
                $cantidad = intval($_POST['cantidad']);
                $tipo = $_POST['tipo_ajuste'];
                
                // CORRECCIÓN: Seleccionar 'descripcion' en lugar de 'nombre'
                $stmtStock = $db->prepare("SELECT stock_disponible, descripcion FROM materiales WHERE id = ?");
                $stmtStock->execute([$material_id]);
                $stock_actual = $stmtStock->fetch();
                $nuevo_stock = $tipo === 'entrada' ? $stock_actual['stock_disponible'] + $cantidad : $stock_actual['stock_disponible'] - $cantidad;
                
                if ($nuevo_stock < 0) {
                    $error = showMessage("Error: El stock no puede ser negativo", 'error');
                } else {
                    $db->prepare("UPDATE materiales SET stock_disponible = ? WHERE id = ?")->execute([$nuevo_stock, $material_id]);
                    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'ajustar_stock', 'materiales', ?, ?)")
                        ->execute([$_SESSION['user_id'], $material_id, "Stock ajustado: {$stock_actual['descripcion']} - $tipo de $cantidad unidades"]);
                    $message = showMessage("Stock ajustado correctamente", 'success');
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

    }
}

// Obtener datos base
$categorias = getCategoriasActivas($db);
$proveedores = $db->query("SELECT * FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll();

// Confiabilidad real por proveedor (descuento individual por tipo de fallo)
$conf_raw = $db->query("SELECT proveedor_id, SUM(CASE tipo WHEN 'llegada_tardia' THEN 5 WHEN 'entrega_incompleta' THEN 15 WHEN 'mal_estado' THEN 20 WHEN 'otro' THEN 5 ELSE 5 END) as descuento FROM proveedor_incidencias GROUP BY proveedor_id")->fetchAll(PDO::FETCH_KEY_PAIR);
// $conf_raw = [proveedor_id => descuento_total]
$confiabilidad_map = []; // [proveedor_id => pct]
foreach ($proveedores as $pv) {
    $confiabilidad_map[$pv['id']] = max(0, 100 - intval($conf_raw[$pv['id']] ?? 0));
}
$materiales = $db->query("SELECT m.*, c.nombre as categoria_nombre FROM materiales m LEFT JOIN categorias c ON m.categoria_id = c.id WHERE m.activo = 1 ORDER BY m.descripcion")->fetchAll();
$stats = $db->query("SELECT COUNT(*) as total_materiales, SUM(CASE WHEN stock_disponible <= stock_minimo AND stock_disponible > 0 THEN 1 ELSE 0 END) as stock_bajo, SUM(CASE WHEN stock_disponible = 0 THEN 1 ELSE 0 END) as agotados FROM materiales WHERE activo = 1")->fetch();

$seccion    = isset($_GET['seccion']) ? $_GET['seccion'] : '';
$detalle_id = isset($_GET['detalle_id']) ? intval($_GET['detalle_id']) : 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras - <?php echo SITE_NAME; ?></title>
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
        input, textarea, select {
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
            box-shadow: 0 2px 10px rgba(46, 92, 174, 0.25);
            border-bottom: 2px solid #c9a227;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }
        /* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
        .header-logo { height: 66px; width: auto; object-fit: contain; }
        .header-right { display: flex; align-items: center; gap: 20px; }
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
        .user-dropdown { position: relative; display: inline-block; }
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
        .user-trigger:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.5); }
        .user-avatar {
            width: 35px; height: 35px; border-radius: 50%;
            background: #FF9800; display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 16px; color: white;
        }
        .user-info-text { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; color: white; font-size: 13px; line-height: 1.2; }
        .user-role { font-size: 10px; color: rgba(255,255,255,0.8); text-transform: uppercase; }
        .user-dropdown-menu {
            position: absolute; top: calc(100% + 5px); right: 0; width: 180px;
            background: white; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            opacity: 0; visibility: hidden; transform: translateY(-5px);
            transition: all 0.2s; border: 1px solid #e0e0e0; overflow: hidden; z-index: 1000;
        }
        .user-dropdown:hover .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-item {
            padding: 12px 20px; display: flex; align-items: center; gap: 10px;
            color: #333; transition: all 0.2s; cursor: pointer;
            border: none; background: none; width: 100%; font-size: 13px;
        }
        .dropdown-item:hover { background: #f5f5f5; color: #f44336; }
        .dropdown-item i { width: 18px; color: #666; }
        .app-container { height: 100vh; width: 100vw; padding-top: 80px; position: relative; box-sizing: border-box; }
        .main-center {
            height: calc(100vh - 140px);
            overflow-y: auto;
            display: flex; flex-direction: column;
            justify-content: flex-start; align-items: center;
            padding: 20px 20px 100px 20px; box-sizing: border-box;
        }
        .page-header { text-align: center; margin-bottom: 40px; }
        .page-title { font-size: 2.2rem; color: #003d5c; font-weight: 300; margin-bottom: 10px; }
        .page-title strong { font-weight: 800; background: linear-gradient(135deg, #003d5c, #0077b3); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 2.4rem; }
        .page-subtitle { color: #666; font-size: 1rem; }
        .cards-grid {
            display: flex; flex-wrap: wrap;
            justify-content: center; align-items: flex-start;
            gap: 40px 30px; max-width: 900px;
            margin: 0 auto; padding-bottom: 50px;
        }
        .nav-card {
            width: 180px; cursor: pointer; transition: all 0.3s ease;
            text-align: center; display: flex; flex-direction: column;
            margin-bottom: 10px; position: relative; z-index: 1;
        }
        .nav-card:hover { transform: translateY(-8px); z-index: 10; }
        .nav-card:active { transform: scale(0.95); }
        .nav-card:hover .card-icon { border-color: #FF9800; box-shadow: 0 15px 30px rgba(255,152,0,0.25); }
        .nav-card:hover .card-title { color: #FF9800; }
        .card-icon {
            position: relative; width: 100%; aspect-ratio: 1/1;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 20px; display: flex; align-items: center;
            justify-content: center; margin-bottom: 8px;
            border: 3px solid #e0e0e0; transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08); overflow: hidden;
        }
        .card-icon::before {
            content: ''; position: absolute; top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease; pointer-events: none; z-index: 1;
        }
        .nav-card:hover .card-icon::before { left: 100%; }
        .card-icon i { font-size: 4rem; color: #005686; transition: all 0.3s ease; z-index: 2; }
        .nav-card:hover .card-icon i { color: #FF9800; transform: scale(1.1); }
        .card-title { font-weight: bold; font-size: 1rem; color: #333; margin-bottom: 2px; line-height: 1.2; }
        .card-desc { font-size: 0.8rem; color: #666; line-height: 1.3; display: block; padding: 0 5px; }
        .dashboard-footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            display: flex; justify-content: space-between; align-items: center;
            padding: 15px 40px; border-top: 2px solid #e0e0e0;
            background: white; height: 60px; z-index: 1000;
        }
        .footer-left { display: flex; gap: 30px; color: #555; font-size: 13px; font-weight: 500; }
        .footer-left i { color: #003d5c; width: 16px; margin-right: 5px; }
        .footer-right img { height: 25px; width: auto; object-fit: contain; }
        .section-content { height: calc(100vh - 140px); overflow-y: auto; padding: 0 30px 30px 30px; margin-top: 0; position: relative; }
        .sticky-container {
            position: sticky; top: 0; background: white; z-index: 100;
            padding: 20px 0; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0;
        }
        .header-row { display: flex; justify-content: space-between; align-items: center; }
        .header-row h2 { font-size: 1.5rem; color: #003d5c; display: flex; align-items: center; gap: 10px; }
        .action-buttons { display: flex; gap: 10px; align-items: center; }
        .search-wrapper { position: relative; width: 300px; }
        .search-input {
            width: 100%; padding: 10px 35px 10px 15px; border: 2px solid #e0e0e0;
            border-radius: 40px; font-size: 14px; transition: all 0.2s;
        }
        .search-input:focus { outline: none; border-color: #005686; box-shadow: 0 0 0 3px rgba(0,86,134,0.1); }
        .search-clear {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #999; cursor: pointer; font-size: 16px; display: none;
        }
        .search-clear.visible { display: block; }
        .search-clear:hover { color: #f44336; }
        .btn-categorias {
            background: white; border: 2px solid #e0e0e0; border-radius: 40px;
            padding: 10px 20px; cursor: pointer; display: flex; align-items: center;
            gap: 8px; font-weight: 600; color: #003d5c; transition: all 0.2s;
        }
        .btn-categorias:hover { border-color: #005686; color: #005686; background: #f0f8ff; }
        .btn-categorias i { color: #FF9800; }
        .content-panel { height: calc(100vh - 280px); overflow-y: auto; padding-right: 10px; }
        .materiales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .material-card {
            background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px;
            overflow: hidden; transition: all 0.2s; display: flex; flex-direction: column; cursor: pointer;
        }
        .material-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); transform: translateY(-2px); border-color: #2196F3; }
        .material-imagen { height: 140px; background: #e0e0e0; display: flex; align-items: center; justify-content: center; color: #999; font-size: 40px; overflow: hidden; }
        .material-imagen img { width: 100%; height: 100%; object-fit: cover; }
        .material-content { padding: 15px; }
        .material-nombre { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 5px; }
        .material-descripcion { font-size: 13px; color: #666; margin-bottom: 8px; }
        .material-detalles { display: flex; gap: 15px; font-size: 12px; color: #666; flex-wrap: wrap; }
        .material-stock { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0e0e0; }
        .stock-value { font-size: 18px; font-weight: 700; color: #003d5c; }
        .proveedores-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
        .proveedor-card { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; transition: all 0.2s; display: flex; flex-direction: column; }
        .proveedor-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); transform: translateY(-2px); border-color: #4CAF50; }
        .proveedor-header { background: #003d5c; color: white; padding: 15px; display: flex; align-items: center; gap: 12px; }
        .proveedor-avatar { width: 45px; height: 45px; border-radius: 50%; background: #FF9800; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 20px; }
        .proveedor-header-info { flex: 1; }
        .proveedor-nombre { font-weight: 600; font-size: 16px; margin-bottom: 3px; }
        .proveedor-rfc { font-size: 12px; opacity: 0.8; display: flex; align-items: center; gap: 5px; }
        .proveedor-body { padding: 15px; flex: 1; }
        .proveedor-detalle { display: flex; flex-direction: column; gap: 8px; font-size: 13px; margin-bottom: 15px; }
        .proveedor-detalle-item { display: flex; align-items: center; gap: 10px; color: #555; }
        .proveedor-detalle-item i { width: 18px; color: #003d5c; }
        .proveedor-confiabilidad-mini { border-top: 1px solid #e0e0e0; padding-top: 12px; }
        .confiabilidad-mini { display: flex; align-items: center; gap: 10px; }
        .confiabilidad-mini-valor { font-size: 14px; font-weight: 600; color: #4CAF50; min-width: 45px; }
        .confiabilidad-mini-barra { flex: 1; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
        .confiabilidad-mini-progreso { height: 100%; background: linear-gradient(90deg, #4CAF50, #8BC34A); border-radius: 4px; }
        .proveedor-footer { padding: 10px 15px; border-top: 1px solid #e0e0e0; display: flex; justify-content: flex-end; gap: 5px; background: #f5f5f5; }
        .tab-btn.active { color: #003d5c; border-bottom: 3px solid #FF9800; font-weight: 600; }
        .tab-btn:hover { background: #f0f0f0; }
        .categoria-card { border: 2px solid #ddd; border-radius: 8px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s; }
        .categoria-card:hover { border-color: #2196F3; background: #f0f8ff; transform: translateY(-2px); }
        .categoria-card.selected { border-color: #2196F3; background: #e3f2fd; }
        .proveedor-confiabilidad { width: 100%; max-width: 400px; margin: 15px auto; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .confiabilidad-header { display: flex; justify-content: space-between; align-items: center; font-size: 15px; font-weight: 500; color: #2c3e50; margin-bottom: 15px; padding: 0 5px; }
        .confiabilidad-valor { background: linear-gradient(145deg, #667eea, #764ba2); color: white; padding: 4px 12px; border-radius: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 3px 10px rgba(102,126,234,0.3); }
        .velocimetro-decorativo { position: relative; width: 250px; height: 125px; margin: 0 auto 30px auto; overflow: visible; }
        .velocimetro-fondo { position: absolute; bottom: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(90deg, #f44336 0%, #ff9800 33%, #ffc107 66%, #4caf50 100%); border-radius: 180px 180px 0 0; opacity: 0.25; border: 2px solid rgba(255,255,255,0.5); box-shadow: inset 0 -5px 10px rgba(0,0,0,0.1); }
        .velocimetro-aguja { position: absolute; bottom: 0; left: 50%; width: 6px; height: 70px; transform-origin: 60% 90%; transform: translateX(-50%) rotate(75deg); z-index: 5; }
        .aguja-cuerpo { position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 4px; height: 65px; background: linear-gradient(to top, #b71c1c, #ff5252); border-radius: 2px 2px 0 0; box-shadow: 0 0 8px rgba(255,82,82,0.6); clip-path: polygon(20% 0%, 80% 0%, 100% 100%, 0% 100%); }
        .velocimetro-centro { position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%); width: 18px; height: 18px; background: radial-gradient(circle at 30% 30%, #ffd700, #b8860b); border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3); z-index: 10; }
        .velocimetro-marcas { position: absolute; bottom: 10px; left: 15px; right: 15px; display: flex; justify-content: space-between; z-index: 4; }
        .velocimetro-marcas span { width: 2px; height: 12px; background: rgba(0,0,0,0.3); border-radius: 2px; }
        .confiabilidad-labels { display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; margin-top: 5px; padding: 0 10px; }
        .confiabilidad-labels span:first-child { color: #f44336; }
        .confiabilidad-labels span:nth-child(2) { color: #ff9800; }
        .confiabilidad-labels span:last-child { color: #4caf50; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
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
        .btn-secondary-disabled {
            background: #9e9e9e; color: white; border: none; border-radius: 4px;
            padding: 10px 20px; font-size: 14px; display: inline-flex;
            align-items: center; gap: 8px; cursor: not-allowed; opacity: 0.65;
        }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 10px; background: #f5f5f5; color: #333; font-weight: 600; font-size: 13px; }
        td { padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 13px; }
        tr:hover td { background: #f9f9f9; }
        .alert { padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 4px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .stat-icon.blue { background: #e3f2fd; color: #2196F3; }
        .stat-icon.orange { background: #fff3e0; color: #FF9800; }
        .stat-icon.red { background: #ffebee; color: #f44336; }
        .stat-icon.green { background: #e8f5e9; color: #4CAF50; }
        .stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-overlay.active { display: flex; }
        .modal { background: white; border-radius: 8px; width: 90%; max-width: 500px; max-height: 90vh; overflow: hidden; }
        .modal-header { background: #003d5c; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        .modal-footer { padding: 15px 20px; background: #f5f5f5; display: flex; justify-content: flex-end; gap: 10px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #333; }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        select.form-input { background: white; }
        .proyecto-info {
            background: #003d5c; color: white; padding: 15px 20px; border-radius: 4px;
            margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 95; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn-outline-light {
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.5);
            color: white; padding: 8px 16px; border-radius: 40px; font-size: 13px;
            font-weight: 500; transition: all 0.2s; margin-left: 10px;
        }
        .btn-outline-light:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.8); transform: translateY(-2px); }
        /* Badge de estado */
        .badge-estado {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px; border-radius: 20px; font-size: 11px;
            font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .badge-espera            { background: rgba(255,193,7,0.2);   color: #f57f17; border: 1px solid rgba(255,193,7,0.5); }
        .badge-activo            { background: rgba(76,175,80,0.2);   color: #2e7d32; border: 1px solid rgba(76,175,80,0.5); }
        .badge-realizado         { background: rgba(33,150,243,0.2);  color: #1565c0; border: 1px solid rgba(33,150,243,0.5); }
        .badge-cotizacion        { background: rgba(103,58,183,0.15); color: #4527a0; border: 1px solid rgba(103,58,183,0.4); }
        .badge-cotizacion-ok     { background: rgba(0,188,212,0.15);  color: #00838f; border: 1px solid rgba(0,188,212,0.4); }
        .badge-orden             { background: rgba(255,87,34,0.15);  color: #bf360c; border: 1px solid rgba(255,87,34,0.4); }
        .badge-parcial           { background: rgba(255,152,0,0.2);   color: #e65100; border: 1px solid rgba(255,152,0,0.5); }
        .badge-otro              { background: rgba(158,158,158,0.2); color: #424242; border: 1px solid rgba(158,158,158,0.5); }
        /* Cotizacion workflow panel */
        .cot-panel { background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-bottom:18px; }
        .cot-panel h3 { font-size:15px; color:#003d5c; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .prov-card { border:1px solid #eee; border-radius:6px; padding:14px 18px; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#fafafa; }
        .prov-cat-chip:has(input:checked) { background:#f3e5f5; border-color:#7b1fa2; color:#4a148c; font-weight:600; }
        .prov-check-label:hover { background:#f9f9f9; }
        .prov-card.recibida { background:#f0faf2; border-color:#66bb6a; }
        .prov-card.pendiente { background:#fffdf0; border-color:#ffc107; }
        .prov-card .prov-info { flex:1; }
        .prov-card .prov-nombre { font-weight:700; color:#333; font-size:14px; }
        .prov-card .prov-email  { color:#888; font-size:12px; }
        .comparativa-table { width:100%; border-collapse:collapse; font-size:13px; }
        .comparativa-table th { background:#003d5c; color:#fff; padding:8px 10px; text-align:left; }
        .comparativa-table td { padding:7px 10px; border-bottom:1px solid #eee; vertical-align:top; }
        .comparativa-table tr:hover td { background:#f5f8ff; }
        .precio-cot { font-weight:700; color:#1565c0; }
        .mejor-precio { background:#e8f5e9 !important; }
        .step-indicator { display:flex; gap:0; margin-bottom:24px; }
        .step-item { flex:1; padding:10px 8px; text-align:center; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#aaa; border-bottom:3px solid #eee; }
        .step-item.active { color:#003d5c; border-bottom-color:#003d5c; }
        .step-item.done { color:#43a047; border-bottom-color:#43a047; }
        /* ERP styles */
        .erp-container { padding: 20px; background: #f4f4f4; }
        .erp-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .back-link { color: #003d5c; cursor: pointer; font-weight: bold; text-decoration: none; }
        .erp-form-card { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-width: 1100px; margin: 0 auto; }
        .erp-title-area { background: #003d5c; color: white; padding: 20px 30px; display: flex; align-items: center; gap: 20px; }
        .erp-avatar-circle { width: 60px; height: 60px; border-radius: 50%; background: #FF9800; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 24px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .erp-title-text h1 { font-size: 22px; margin: 0; color: white; }
        .erp-title-text small { opacity: 0.8; font-size: 13px; letter-spacing: 0.5px; }
        .erp-form-top { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; padding: 30px; background: white; }
        .erp-input-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 15px; }
        .erp-input-group label { font-weight: bold; color: #003d5c; font-size: 12px; text-transform: uppercase; }
        .erp-text-static { font-size: 15px; color: #555; padding: 5px 0; border-bottom: 1px solid #eee; }
        .erp-tabs { display: flex; background: #f5f5f5; padding: 0 30px; border-top: 1px solid #e0e0e0; }
        .erp-tab { padding: 12px 20px; color: #666; cursor: pointer; font-size: 13px; font-weight: 600; }
        .erp-tab.active { background: white; color: #003d5c; border-left: 1px solid #e0e0e0; border-right: 1px solid #e0e0e0; position: relative; }
        .erp-table-container { background: white; padding: 0 30px 30px 30px; }
        .erp-table { width: 100%; border-collapse: collapse; }
        .erp-table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #003d5c; color: #003d5c; font-size: 13px; }
        .erp-table td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .erp-form-footer { padding: 20px 30px; background: #f9f9f9; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: flex-start; }
        .erp-totals { width: 300px; }
        .total-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 14px; color: #666; }
        .main-total { margin-top: 10px; padding-top: 10px; border-top: 2px solid #003d5c; font-size: 20px; color: #003d5c; font-weight: 800; }
        /* Modal Subir Cotización */
        .modal-cotizacion {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;
        }
        .modal-cotizacion.active { display: flex; }
        .modal-cotizacion-box {
            background: white; border-radius: 8px; width: 680px; max-width: 95vw;
            max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-cotizacion-header {
            background: linear-gradient(135deg, #003d5c, #005686); color: white;
            padding: 20px 25px; border-radius: 8px 8px 0 0;
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-cotizacion-header h3 { margin: 0; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        .modal-cotizacion-close { background: none; border: none; color: white; font-size: 22px; cursor: pointer; line-height: 1; }
        .modal-cotizacion-body { padding: 25px; }
        .drop-zone {
            border: 2px dashed #005686; border-radius: 8px; padding: 40px 20px;
            text-align: center; cursor: pointer; transition: all 0.2s; background: #f8fbff;
        }
        .drop-zone:hover, .drop-zone.dragover { border-color: #FF9800; background: #fff8f0; }
        .drop-zone i { font-size: 3rem; color: #005686; margin-bottom: 12px; display: block; }
        .drop-zone p { color: #555; margin: 0; font-size: 14px; }
        .drop-zone small { color: #999; font-size: 12px; }
        .cotizacion-result {
            margin-top: 20px; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden;
        }
        .cotizacion-result-header {
            background: #f5f5f5; padding: 12px 18px; font-weight: 600; color: #003d5c;
            font-size: 14px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #e0e0e0;
        }
        .cotizacion-result-body { padding: 18px; }
        .cot-field { margin-bottom: 12px; }
        .cot-field label { font-size: 11px; color: #999; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 2px; }
        .cot-field span { font-size: 14px; color: #333; }
        .cot-productos-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        .cot-productos-table th { background: #003d5c; color: white; padding: 8px 10px; text-align: left; font-weight: 600; }
        .cot-productos-table td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; }
        .cot-productos-table tr:hover td { background: #f9f9f9; }
        .cot-loading { text-align: center; padding: 30px; color: #555; }
        .cot-loading i { font-size: 2.5rem; color: #005686; animation: spin 1s linear infinite; display: block; margin-bottom: 12px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .cot-error { background: #fff3f3; border: 1px solid #ffcdd2; border-radius: 6px; padding: 15px; color: #c62828; font-size: 14px; margin-top: 15px; }
        .cot-badge-ok { background: #e8f5e9; color: #2e7d32; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        /* Botón subir archivo inhabilitado (kept for reference, no longer used) */
        .btn-upload-disabled {
            background: #f5f5f5;
            color: #bdbdbd;
            border: 2px dashed #bdbdbd;
            border-radius: 4px;
            padding: 10px 20px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: not-allowed;
            font-weight: 600;
            position: relative;
        }
        .btn-upload-disabled::after {
            content: 'Próximamente';
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            background: #616161;
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 4px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .btn-upload-disabled:hover::after { opacity: 1; }
        /* Proyecto card en lista */
        .proyecto-costo {
            cursor: pointer; margin-bottom: 15px;
            border-radius: 4px; overflow: hidden;
            background: white; transition: all 0.2s;
        }
        .proyecto-costo:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.15); }
        /* Calendario */
        .btn-vista { padding: 8px 16px; border: none; background: transparent; cursor: pointer; border-radius: 40px; font-weight: 500; transition: all 0.2s; }
        .btn-vista.active { background: #FF9800; color: white; }
        .evento-calendario { background: #FF9800; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; margin: 2px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .evento-calendario:hover { background: #F57C00; transform: scale(1.02); }
        .dia-celda { min-height: 100px; vertical-align: top; padding: 8px; border: 1px solid #e0e0e0; }
        .dia-numero { font-weight: bold; margin-bottom: 8px; color: #666; }
        .dia-actual { background: #fff3e0; }
        /* Chat */
        .chat-bubble { position: fixed; bottom: 80px; right: 20px; z-index: 9999; }
        .chat-button { width: 60px; height: 60px; background: linear-gradient(135deg, #003d5c, #005686); border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: all 0.3s ease; position: relative; }
        .chat-button i { font-size: 28px; color: white; }
        .chat-status { position: absolute; bottom: 2px; right: 2px; width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; background-color: #4caf50; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #f44336; color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 12px; display: flex; align-items: center; justify-content: center; font-weight: bold; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
        .chat-window { position: fixed; bottom: 150px; right: 20px; width: 380px; height: 550px; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); display: none; flex-direction: column; z-index: 9998; overflow: hidden; }
        .chat-window.open { display: flex; }
        .chat-header { background: linear-gradient(135deg, #003d5c, #005686); color: white; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; }
        .chat-header h4 { margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .chat-header-actions { display: flex; gap: 10px; }
        .chat-header-actions button { background: none; border: none; color: white; cursor: pointer; font-size: 16px; }
        .chat-tabs { display: flex; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .chat-tab { flex: 1; padding: 10px; text-align: center; cursor: pointer; font-size: 13px; font-weight: 500; color: #666; border-bottom: 2px solid transparent; }
        .chat-tab.active { color: #003d5c; border-bottom-color: #003d5c; }
        .chat-panel { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .chat-view { display: flex; flex-direction: column; height: 100%; }
        .chat-view-header { display: flex; align-items: center; gap: 12px; padding: 10px 15px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; }
        .chat-view-title { font-weight: 600; font-size: 16px; color: #333; }
        .chat-users-list { flex: 1; overflow-y: auto; padding: 0; }
        .chat-user-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; cursor: pointer; transition: background 0.2s; border-bottom: 1px solid #f0f0f0; }
        .chat-user-item:hover { background: #f5f5f5; }
        .chat-user-avatar { width: 45px; height: 45px; border-radius: 50%; background: #FF9800; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; color: white; flex-shrink: 0; }
        .chat-user-info { flex: 1; min-width: 0; }
        .chat-user-name { font-weight: 600; font-size: 15px; color: #333; margin-bottom: 3px; }
        .chat-user-role { font-size: 11px; color: #999; text-transform: uppercase; }
        .chat-user-status { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .chat-user-status.online { background: #4caf50; }
        .chat-user-status.offline { background: #9e9e9e; }
        .chat-user-badge { background: #f44336; color: white; border-radius: 12px; padding: 2px 6px; font-size: 10px; font-weight: bold; margin-left: 8px; }
        .btn-back-chat { background: none; border: none; font-size: 18px; cursor: pointer; color: #003d5c; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .btn-back-chat:hover { background: #e0e0e0; }
        .chat-conversation { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .chat-conversation-header { display: flex; align-items: center; gap: 12px; padding: 10px 15px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 15px; background: #fafafa; display: flex; flex-direction: column; gap: 12px; }
        .message-item { display: flex; flex-direction: column; max-width: 85%; }
        .message-sent { align-items: flex-end; align-self: flex-end; }
        .message-received { align-items: flex-start; align-self: flex-start; }
        .message-bubble { padding: 10px 12px; border-radius: 18px; font-size: 13px; word-wrap: break-word; max-width: 100%; }
        .message-sent .message-bubble { background: #003d5c; color: white; border-bottom-right-radius: 5px; }
        .message-received .message-bubble { background: white; border: 1px solid #e0e0e0; color: #333; border-bottom-left-radius: 5px; }
        .message-time { font-size: 10px; color: #999; margin-top: 5px; }
        .message-file { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: rgba(0,0,0,0.08); border-radius: 10px; margin-top: 6px; cursor: pointer; font-size: 12px; }
        .message-file i { font-size: 16px; flex-shrink: 0; }
        .message-file span { flex: 1; word-break: break-all; }
        .message-file-actions { display: flex; gap: 6px; margin-top: 4px; flex-wrap: wrap; }
        .message-file-actions button { background: rgba(0,0,0,0.15); border: none; color: inherit; padding: 4px 10px; border-radius: 12px; cursor: pointer; font-size: 11px; display: flex; align-items: center; gap: 4px; }
        .message-file-actions button:hover { background: rgba(0,0,0,0.25); }
        .chat-input-area { padding: 12px; border-top: 1px solid #e0e0e0; background: white; display: flex; flex-direction: column; gap: 10px; }
        .chat-input-row { display: flex; gap: 10px; align-items: flex-end; }
        .chat-input-row textarea { flex: 1; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 20px; font-size: 13px; outline: none; resize: vertical; font-family: inherit; min-height: 40px; max-height: 120px; }
        .chat-input-row textarea:focus { border-color: #003d5c; }
        .chat-input-row button { background: #003d5c; border: none; color: white; width: 38px; height: 38px; border-radius: 50%; cursor: pointer; flex-shrink: 0; }
        .chat-file-buttons { display: flex; gap: 8px; padding-left: 5px; }
        .chat-file-btn { background: none; border: none; color: #666; cursor: pointer; font-size: 18px; padding: 5px; border-radius: 50%; }
        .chat-file-btn:hover { background: #f0f0f0; color: #003d5c; }
        .empty-chat { text-align: center; padding: 40px 20px; color: #999; }
        .empty-chat i { font-size: 48px; margin-bottom: 15px; color: #ddd; }
        .notification-item { background: #e8f4fc; border-left: 4px solid #FF9800; padding: 12px; margin-bottom: 10px; border-radius: 8px; cursor: pointer; }
        .notification-item:hover { background: #e0eef8; transform: translateX(3px); }
        .notif-unread { background: #fff8e1 !important; border-left-color: #FF9800 !important; font-weight: 600; }
        .notif-unread::after { content:''; display:inline-block; width:8px; height:8px; background:#FF9800; border-radius:50%; float:right; margin-top:4px; }
        .notification-title { font-weight: 600; font-size: 13px; color: #003d5c; margin-bottom: 5px; }
        .notification-message { font-size: 12px; color: #666; }
        .notification-time { font-size: 10px; color: #999; margin-top: 5px; }
        .image-preview-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.9); z-index: 10000; display: none; align-items: center; justify-content: center; }
        .image-preview-modal.active { display: flex; }
        .image-preview-modal img { max-width: 90%; max-height: 90%; object-fit: contain; }
        .image-preview-modal .close-preview { position: absolute; top: 20px; right: 30px; color: white; font-size: 30px; cursor: pointer; }
        .file-preview-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: white; z-index: 10000; display: none; flex-direction: column; }
        .file-preview-modal.active { display: flex; }
        .file-preview-header { background: #003d5c; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .file-preview-body { flex: 1; overflow: auto; padding: 20px; }
        .file-preview-body iframe { width: 100%; height: 100%; border: none; }
        @media print {
            body * { visibility: hidden; margin: 0; padding: 0; }
            .erp-container, .erp-container * { visibility: visible; }
            .erp-container { position: absolute; left: 0; top: 0; width: 100%; border: none !important; }
            .action-buttons, .back-link, .sticky-container, .erp-tabs { display: none !important; }
            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>
    <div class="top-header">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
            onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">

        <div class="header-right">
            <?php if ($seccion !== ''): ?>
                <a href="?seccion=" class="back-button-header">
                    <i class="fas fa-arrow-left"></i> Volver al Menú
                </a>
            <?php endif; ?>

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

        <!-- ===================== MENÚ PRINCIPAL ===================== -->
        <?php if ($seccion === ''): ?>
            <div class="main-center">
                <div class="page-header">
                    <h1 class="page-title"><strong>Compras</strong><br>Panel de Control</h1>
                    <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
                </div>
                <div class="cards-grid">
                    <div class="nav-card" onclick="window.location.href='?seccion=proyectos_costos'">
                        <div class="card-icon"><i class="fas fa-folder-open"></i></div>
                        <div class="card-title">Proyectos</div>
                        <div class="card-desc">Gestión y órdenes de compra</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=requisiciones_compra'">
                        <div class="card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="card-title">Requisiciones por Comprar</div>
                        <div class="card-desc">Requisiciones sin proyecto con falta de stock</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=realizados'">
                        <div class="card-icon"><i class="fas fa-history"></i></div>
                        <div class="card-title">Proyectos Realizados</div>
                        <div class="card-desc">Historial y costos</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=registro_contable'">
                        <div class="card-icon"><i class="fas fa-calculator"></i></div>
                        <div class="card-title">Registro Contable</div>
                        <div class="card-desc">Gasto y horas al cierre de cada proyecto</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=inventario'">
                        <div class="card-icon"><i class="fas fa-warehouse"></i></div>
                        <div class="card-title">Inventario</div>
                        <div class="card-desc">Gestionar materiales</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=proveedores'">
                        <div class="card-icon"><i class="fas fa-truck"></i></div>
                        <div class="card-title">Proveedores</div>
                        <div class="card-desc">Administrar proveedores</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=calendario'">
                        <div class="card-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="card-title">Calendario</div>
                        <div class="card-desc">Seguimiento de órdenes</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=reportes_categoria'">
                        <div class="card-icon"><i class="fas fa-chart-pie"></i></div>
                        <div class="card-title">Reportes</div>
                        <div class="card-desc">Costos por categoría</div>
                    </div>

                    <div class="nav-card" onclick="window.location.href='?seccion=orden_produccion'">
                        <div class="card-icon"><i class="fas fa-industry"></i></div>
                        <div class="card-title">Orden de Producción</div>
                        <div class="card-desc">Órdenes activas</div>
                    </div>

                    <?php require_once 'includes/indicadores.php'; renderIndicadoresNavCard('compras.php'); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($seccion === 'indicadores'): ?>
        <div class="section-content">
            <?php require_once 'includes/indicadores.php'; renderIndicadoresSeccion($db, 'compras', 'compras.php'); ?>
        </div>
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

        <!-- ===================== INVENTARIO ===================== -->
        <?php if ($seccion === 'inventario'): ?>
            <div class="section-content">
                <div class="sticky-container">
                    <div class="header-row">
                        <h2><i class="fas fa-warehouse"></i> Gestión de Inventario</h2>
                        <div class="action-buttons">
                            <div class="search-wrapper">
                                <input type="text" class="search-input" id="searchInventario" placeholder="Buscar material...">
                                <button class="search-clear" id="clearSearchInventario" onclick="clearSearch('inventario')"><i class="fas fa-times"></i></button>
                            </div>
                            <button class="btn-categorias" onclick="abrirModalCategoriasInventario()"><i class="fas fa-tags"></i> Categorías</button>
                            <button class="btn btn-primary" onclick="abrirModalMaterial()"><i class="fas fa-plus"></i> Nuevo Material</button>
                        </div>
                    </div>
                </div>
                <div class="content-panel" id="inventarioContent">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="fas fa-boxes"></i></div>
                            <div><div class="stat-value"><?php echo $stats['total_materiales']; ?></div><div class="stat-label">Total Materiales</div></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><div class="stat-value"><?php echo $stats['stock_bajo']; ?></div><div class="stat-label">Stock Bajo</div></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                            <div><div class="stat-value"><?php echo $stats['agotados']; ?></div><div class="stat-label">Agotados</div></div>
                        </div>
                    </div>
                    <div class="materiales-grid" id="inventarioGrid">
                        <?php foreach ($materiales as $mat): ?>
                            <div class="material-card" data-descripcion="<?php echo strtolower($mat['descripcion'] ?? ''); ?>" data-codigo="<?php echo strtolower($mat['codigo'] ?? ''); ?>" data-categoria="<?php echo $mat['categoria_id']; ?>">
                                <div class="material-imagen">
                                    <?php if (!empty($mat['imagen'])): ?>
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
                                    </div>
                                    <div style="margin-top: 10px; display: flex; gap: 5px;">
                                        <button class="btn btn-primary btn-sm" onclick='editarMaterial(<?php echo htmlspecialchars(json_encode($mat, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)'><i class="fas fa-edit"></i> Editar</button>
                                        <button class="btn btn-warning btn-sm" onclick="ajustarStock(<?php echo $mat['id']; ?>, '<?php echo htmlspecialchars(addslashes($mat['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', <?php echo $mat['stock_disponible']; ?>)"><i class="fas fa-balance-scale"></i> Stock</button>
                                        <button class="btn btn-danger btn-sm" onclick="confirmarEliminarMaterial(<?php echo $mat['id']; ?>, '<?php echo htmlspecialchars(addslashes($mat['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>')"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===================== PROVEEDORES ===================== -->
        <?php if ($seccion === 'proveedores'): ?>
            <?php
            $ver_proveedor = isset($_GET['ver']) ? intval($_GET['ver']) : 0;
            $editando = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
            $proveedor_detalle = null;
            if ($ver_proveedor > 0) {
                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
                $stmt->execute([$ver_proveedor]);
                $proveedor_detalle = $stmt->fetch();
                if (!$proveedor_detalle) $ver_proveedor = 0;
            }
            if (isset($_GET['success']) && $_GET['success'] == 1 && $ver_proveedor > 0) {
                $message = showMessage("Proveedor guardado correctamente", 'success');
            }
            ?>
            <?php if ($ver_proveedor > 0): ?>
            <div class="section-content">
                <div class="proyecto-info" style="margin-bottom: 20px;">
                    <div><i class="fas fa-building"></i> <strong>Proveedor:</strong> <?php echo htmlspecialchars($proveedor_detalle['nombre']); ?></div>
                    <div>
                        <a href="?seccion=proveedores" class="btn-outline-light"><i class="fas fa-arrow-left"></i> Volver</a>
                        <button class="btn-outline-light" onclick="window.location.href='?seccion=proveedores&edit=<?php echo $proveedor_detalle['id']; ?>&ver=<?php echo $proveedor_detalle['id']; ?>'"><i class="fas fa-edit"></i> Editar</button>
                    </div>
                </div>
                <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <div style="display: flex; border-bottom: 1px solid #e0e0e0; background: #f8f9fa;">
                        <button class="tab-btn active" data-tab="info" style="padding: 15px 25px; background: none; border: none; cursor: pointer; font-weight: 600; color: #003d5c; border-bottom: 3px solid #FF9800;">Información</button>
                        <button class="tab-btn" data-tab="ventas" style="padding: 15px 25px; background: none; border: none; cursor: pointer; font-weight: 600; color: #666;">Ventas y Compras</button>
                        <button class="tab-btn" data-tab="contabilidad" style="padding: 15px 25px; background: none; border: none; cursor: pointer; font-weight: 600; color: #666;">Contabilidad</button>
                        <button class="tab-btn" data-tab="notas" style="padding: 15px 25px; background: none; border: none; cursor: pointer; font-weight: 600; color: #666;">Notas</button>
                        <button class="tab-btn" data-tab="confiabilidad" style="padding: 15px 25px; background: none; border: none; cursor: pointer; font-weight: 600; color: #666;">Confiabilidad</button>
                    </div>
                    <div class="tab-content active" id="tab-info" style="padding: 25px;">
                        <?php if ($editando == $proveedor_detalle['id']): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="guardar_proveedor">
                                <input type="hidden" name="id" value="<?php echo $proveedor_detalle['id']; ?>">
                                <div class="grid-2">
                                    <div class="form-group full"><label class="form-label">Razón Social / Nombre *</label><input type="text" name="nombre" value="<?php echo htmlspecialchars($proveedor_detalle['nombre']); ?>" class="form-input" required></div>
                                    <div class="form-group"><label class="form-label">RFC</label><input type="text" name="rfc" value="<?php echo htmlspecialchars($proveedor_detalle['rfc'] ?? ''); ?>" class="form-input"></div>
                                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($proveedor_detalle['email'] ?? ''); ?>" class="form-input"></div>
                                    <div class="form-group" style="grid-column:1/-1;">
                                        <label class="form-label">Teléfono</label>
                                        <div style="display:flex;gap:8px;">
                                            <input type="text" name="lada" value="<?php echo htmlspecialchars($proveedor_detalle['lada'] ?? ''); ?>" class="form-input" placeholder="Lada" style="width:90px;flex-shrink:0;" maxlength="10">
                                            <input type="text" name="telefono" value="<?php echo htmlspecialchars($proveedor_detalle['telefono'] ?? ''); ?>" class="form-input" placeholder="Número">
                                        </div>
                                    </div>
                                    <div class="form-group full"><label class="form-label">Dirección</label><textarea name="direccion" class="form-input" rows="2"><?php echo htmlspecialchars($proveedor_detalle['direccion'] ?? ''); ?></textarea></div>
                                    <div class="form-group"><label class="form-label">Atención a (At'n)</label><input type="text" name="atencion" value="<?php echo htmlspecialchars($proveedor_detalle['atencion'] ?? ''); ?>" class="form-input"></div>
                                    <div class="form-group"><label class="form-label">Referencia (Pago/Banco)</label><input type="text" name="referencia" value="<?php echo htmlspecialchars($proveedor_detalle['referencia'] ?? ''); ?>" class="form-input"></div>
                                    <div class="form-group full">
                                        <label class="form-label">Categorías de productos que maneja</label>
                                        <?php
                                        $pv_cats_edit = [];
                                        if (!empty($proveedor_detalle['categorias_ids'])) {
                                            $pv_cats_edit = json_decode($proveedor_detalle['categorias_ids'], true) ?? [];
                                        } elseif (!empty($proveedor_detalle['categoria'])) {
                                            $pv_cats_edit = [(int)$proveedor_detalle['categoria']];
                                        }
                                        ?>
                                        <div style="display:flex;gap:10px;margin-top:6px;flex-wrap:wrap;">
                                            <?php foreach ($categorias as $cat): ?>
                                            <label style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #ddd;border-radius:20px;cursor:pointer;font-size:13px;user-select:none" class="prov-cat-chip">
                                                <input type="checkbox" name="categorias_ids[]" value="<?php echo $cat['id']; ?>" style="width:14px;height:14px" <?php echo in_array($cat['id'], $pv_cats_edit) ? 'checked' : ''; ?>>
                                                <i class="fas fa-<?php echo $cat['icono'] ?? 'tag'; ?>" style="color:#7b1fa2;font-size:11px"></i>
                                                <?php echo htmlspecialchars($cat['nombre']); ?>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="form-group full"><label class="form-label">Etiqueta / Especialidad</label><input type="text" name="etiqueta" value="<?php echo htmlspecialchars($proveedor_detalle['etiqueta'] ?? ''); ?>" class="form-input"></div>
                                </div>
                                <div style="display: flex; gap: 10px; margin-top: 20px;">
                                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar Cambios</button>
                                    <a href="?seccion=proveedores&ver=<?php echo $proveedor_detalle['id']; ?>" class="btn btn-outline">Cancelar</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="grid-2">
                                <div class="form-group full"><label class="form-label">Razón Social</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php echo htmlspecialchars($proveedor_detalle['nombre']); ?></div></div>
                                <div class="form-group"><label class="form-label">RFC</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php echo htmlspecialchars($proveedor_detalle['rfc'] ?? '-'); ?></div></div>
                                <div class="form-group"><label class="form-label">Email</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php echo htmlspecialchars($proveedor_detalle['email'] ?? '-'); ?></div></div>
                                <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Teléfono</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php
                                    $lada_v = trim($proveedor_detalle['lada'] ?? '');
                                    $tel_v  = trim($proveedor_detalle['telefono'] ?? '');
                                    if ($lada_v && $tel_v) echo htmlspecialchars("($lada_v) $tel_v");
                                    elseif ($tel_v) echo htmlspecialchars($tel_v);
                                    else echo '-';
                                ?></div></div>
                                <div class="form-group full"><label class="form-label">Dirección</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php echo htmlspecialchars($proveedor_detalle['direccion'] ?? '-'); ?></div></div>
                                <div class="form-group"><label class="form-label">At'n</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php echo htmlspecialchars($proveedor_detalle['atencion'] ?? '-'); ?></div></div>
                                <div class="form-group"><label class="form-label">Referencia</label><div style="padding: 10px 12px; background: #f5f5f5; border-radius: 4px;"><?php echo htmlspecialchars($proveedor_detalle['referencia'] ?? '-'); ?></div></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="tab-content" id="tab-ventas" style="padding: 25px; display: none;">
                        <?php
                        // ── Cotizaciones de este proveedor ──────────────────────────────
                        $stmt_vc = $db->prepare("
                            SELECT c.id, c.estado, c.fecha_solicitud, c.fecha_respuesta,
                                   COALESCE(p.nombre, r.folio, 'Sin proyecto') as proyecto_nombre,
                                   p.id as proyecto_id, r.folio as req_folio
                            FROM cotizaciones c
                            LEFT JOIN proyectos p ON c.proyecto_id = p.id
                            LEFT JOIN requisiciones r ON c.requisicion_id = r.id
                            WHERE c.proveedor_id = ?
                            ORDER BY c.fecha_solicitud DESC
                            LIMIT 50
                        ");
                        $stmt_vc->execute([$ver_proveedor]);
                        $cots_prov = $stmt_vc->fetchAll();

                        // ── Órdenes de compra de este proveedor ─────────────────────────
                        $stmt_oc = $db->prepare("
                            SELECT oc.id, oc.folio, oc.total, oc.moneda, oc.fecha_creacion,
                                   oc.fecha_entrega_estimada, oc.condiciones_pago,
                                   COALESCE(p.nombre, r.folio, 'Sin proyecto') as proyecto_nombre,
                                   p.id as proyecto_id
                            FROM ordenes_compra oc
                            LEFT JOIN proyectos p ON oc.proyecto_id = p.id
                            LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
                            WHERE oc.proveedor_id = ?
                            ORDER BY oc.fecha_creacion DESC
                            LIMIT 50
                        ");
                        $stmt_oc->execute([$ver_proveedor]);
                        $ocs_prov = $stmt_oc->fetchAll();

                        $estado_labels = [
                            'pendiente'  => ['Pendiente',         '#FF9800', 'fa-clock'],
                            'recibida'   => ['Cotización recibida','#4CAF50', 'fa-check-circle'],
                        ];
                        ?>

                        <!-- Resumen rápido -->
                        <div style="display:flex;gap:16px;margin-bottom:24px;">
                            <div style="flex:1;background:#e3f2fd;border-radius:8px;padding:16px;text-align:center;">
                                <div style="font-size:28px;font-weight:700;color:#1565c0;"><?php echo count($cots_prov); ?></div>
                                <div style="font-size:13px;color:#555;margin-top:4px;"><i class="fas fa-file-alt"></i> Cotizaciones</div>
                            </div>
                            <div style="flex:1;background:#e8f5e9;border-radius:8px;padding:16px;text-align:center;">
                                <div style="font-size:28px;font-weight:700;color:#2e7d32;"><?php echo count($ocs_prov); ?></div>
                                <div style="font-size:13px;color:#555;margin-top:4px;"><i class="fas fa-shopping-cart"></i> Órdenes de compra</div>
                            </div>
                            <div style="flex:1;background:#fff3e0;border-radius:8px;padding:16px;text-align:center;">
                                <div style="font-size:28px;font-weight:700;color:#e65100;">$<?php echo number_format(array_sum(array_column($ocs_prov, 'total')), 2); ?></div>
                                <div style="font-size:13px;color:#555;margin-top:4px;"><i class="fas fa-dollar-sign"></i> Total comprado</div>
                            </div>
                        </div>

                        <!-- Órdenes de Compra -->
                        <h4 style="margin:0 0 12px 0;color:#003d5c;font-size:14px;"><i class="fas fa-shopping-cart"></i> Órdenes de Compra</h4>
                        <?php if (empty($ocs_prov)): ?>
                            <p style="color:#999;font-size:13px;margin-bottom:24px;">Sin órdenes de compra registradas.</p>
                        <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;">
                            <thead>
                                <tr style="background:#f5f5f5;">
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Folio</th>
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Proyecto</th>
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Fecha</th>
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Entrega est.</th>
                                    <th style="padding:8px 10px;text-align:right;color:#555;font-weight:600;">Total</th>
                                    <th style="padding:8px 10px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($ocs_prov as $oc): ?>
                                <tr style="border-bottom:1px solid #eee;">
                                    <td style="padding:8px 10px;font-weight:600;color:#003d5c;"><?php echo htmlspecialchars($oc['folio']); ?></td>
                                    <td style="padding:8px 10px;"><?php echo htmlspecialchars($oc['proyecto_nombre']); ?></td>
                                    <td style="padding:8px 10px;color:#666;"><?php echo $oc['fecha_creacion'] ? date('d/m/Y', strtotime($oc['fecha_creacion'])) : '-'; ?></td>
                                    <td style="padding:8px 10px;color:#666;"><?php echo $oc['fecha_entrega_estimada'] ? date('d/m/Y', strtotime($oc['fecha_entrega_estimada'])) : '-'; ?></td>
                                    <td style="padding:8px 10px;text-align:right;font-weight:600;">$<?php echo number_format($oc['total'], 2); ?> <?php echo $oc['moneda']; ?></td>
                                    <td style="padding:8px 10px;"><a href="orden_compra.php?orden_id=<?php echo $oc['id']; ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>

                        <!-- Cotizaciones -->
                        <h4 style="margin:0 0 12px 0;color:#003d5c;font-size:14px;"><i class="fas fa-file-invoice"></i> Cotizaciones Solicitadas</h4>
                        <?php if (empty($cots_prov)): ?>
                            <p style="color:#999;font-size:13px;">Sin cotizaciones registradas.</p>
                        <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="background:#f5f5f5;">
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Proyecto</th>
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Solicitada</th>
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Respondida</th>
                                    <th style="padding:8px 10px;text-align:left;color:#555;font-weight:600;">Estado</th>
                                    <th style="padding:8px 10px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cots_prov as $cot): ?>
                                <?php $lbl = $estado_labels[$cot['estado']] ?? ['Desconocido', '#999', 'fa-question']; ?>
                                <tr style="border-bottom:1px solid #eee;">
                                    <td style="padding:8px 10px;"><?php echo htmlspecialchars($cot['proyecto_nombre']); ?></td>
                                    <td style="padding:8px 10px;color:#666;"><?php echo $cot['fecha_solicitud'] ? date('d/m/Y', strtotime($cot['fecha_solicitud'])) : '-'; ?></td>
                                    <td style="padding:8px 10px;color:#666;"><?php echo $cot['fecha_respuesta'] ? date('d/m/Y', strtotime($cot['fecha_respuesta'])) : '-'; ?></td>
                                    <td style="padding:8px 10px;">
                                        <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:<?php echo $lbl[1]; ?>22;color:<?php echo $lbl[1]; ?>;">
                                            <i class="fas <?php echo $lbl[2]; ?>"></i> <?php echo $lbl[0]; ?>
                                        </span>
                                    </td>
                                    <td style="padding:8px 10px;">
                                        <?php if (!empty($cot['proyecto_id'])): ?>
                                        <a href="?seccion=proyectos_costos&detalle_id=<?php echo $cot['proyecto_id']; ?>" class="btn btn-outline btn-sm" title="Ver cotización del proyecto"><i class="fas fa-eye"></i></a>
                                        <?php else: ?>
                                        <span style="color:#bbb;font-size:12px;">Sin proyecto</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                    <div class="tab-content" id="tab-contabilidad" style="padding: 25px; display: none;"><p style="color: #666; text-align: center; padding: 40px;">Próximamente: Información contable</p></div>
                    <div class="tab-content" id="tab-notas" style="padding: 25px; display: none;"><p style="color: #666; text-align: center; padding: 40px;">Próximamente: Notas y observaciones</p></div>
                    <div class="tab-content" id="tab-confiabilidad" style="padding: 25px; display: none;">
                        <?php
                        // ── Cargar incidencias del proveedor ──────────────────────────
                        $stmt_inc = $db->prepare("SELECT pi.*, p.nombre as proyecto_nombre FROM proveedor_incidencias pi LEFT JOIN proyectos p ON pi.proyecto_id = p.id WHERE pi.proveedor_id = ? ORDER BY pi.fecha ASC");
                        $stmt_inc->execute([$ver_proveedor]);
                        $incidencias_prov = $stmt_inc->fetchAll();

                        // ── Calcular índice de confiabilidad (por fallo individual) ───
                        $stmt_oc_cnt = $db->prepare("SELECT COUNT(*) FROM ordenes_compra WHERE proveedor_id = ?");
                        $stmt_oc_cnt->execute([$ver_proveedor]);
                        $total_ocs_prov = intval($stmt_oc_cnt->fetchColumn());

                        // Pesos de descuento por tipo de fallo
                        $peso_fallo = ['llegada_tardia' => 5, 'entrega_incompleta' => 15, 'mal_estado' => 20, 'otro' => 5];
                        $tipo_labels = ['llegada_tardia'=>'Llegada tardía','entrega_incompleta'=>'Entrega incompleta','mal_estado'=>'Mal estado','otro'=>'Otro'];
                        $color_tipo  = ['llegada_tardia'=>'#FF9800','entrega_incompleta'=>'#f44336','mal_estado'=>'#9C27B0','otro'=>'#607D8B'];

                        $inc_by_date    = [];
                        $inc_types_count = ['llegada_tardia'=>0,'entrega_incompleta'=>0,'mal_estado'=>0,'otro'=>0];
                        $descuento_total = 0;

                        foreach ($incidencias_prov as $inc) {
                            $fecha_k = date('d/m/Y', strtotime($inc['fecha']));
                            if (!isset($inc_by_date[$fecha_k])) $inc_by_date[$fecha_k] = [];
                            $inc_by_date[$fecha_k][] = $inc;
                            $inc_types_count[$inc['tipo']] = ($inc_types_count[$inc['tipo']] ?? 0) + 1;
                            $descuento_total += $peso_fallo[$inc['tipo']] ?? 5;
                        }

                        $confiabilidad_pct = max(0, 100 - $descuento_total);

                        // Fallos más frecuentes (para el banner de 0%)
                        $fallos_ordenados = $inc_types_count;
                        arsort($fallos_ordenados);
                        $fallos_principales = array_filter($fallos_ordenados, fn($v) => $v > 0);
                        ?>

                        <!-- Resumen -->
                        <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:140px;background:#e8f5e9;border-radius:8px;padding:16px;text-align:center;">
                                <div style="font-size:30px;font-weight:800;color:<?php echo $confiabilidad_pct >= 80 ? '#2e7d32' : ($confiabilidad_pct >= 40 ? '#e65100' : '#c62828'); ?>;"><?php echo $confiabilidad_pct; ?>%</div>
                                <div style="font-size:12px;color:#555;margin-top:4px;">Índice de confiabilidad</div>
                            </div>
                            <div style="flex:1;min-width:120px;background:#fff3e0;border-radius:8px;padding:16px;text-align:center;">
                                <div style="font-size:28px;font-weight:700;color:#e65100;"><?php echo count($incidencias_prov); ?></div>
                                <div style="font-size:12px;color:#555;margin-top:4px;">Incidencias totales</div>
                            </div>
                            <div style="flex:1;min-width:120px;background:#e3f2fd;border-radius:8px;padding:16px;text-align:center;">
                                <div style="font-size:28px;font-weight:700;color:#1565c0;"><?php echo $total_ocs_prov; ?></div>
                                <div style="font-size:12px;color:#555;margin-top:4px;">Órdenes de compra</div>
                            </div>
                            <div style="display:flex;align-items:center;">
                                <button onclick="toggleGraficaPastel()" style="background:#003d5c;color:white;border:none;border-radius:8px;padding:10px 16px;cursor:pointer;font-size:13px;display:flex;align-items:center;gap:8px;">
                                    <i class="fas fa-chart-pie"></i> Ver gráfica de confiabilidad
                                </button>
                            </div>
                        </div>

                        <?php if ($confiabilidad_pct === 0 && !empty($fallos_principales)): ?>
                        <div style="background:#ffebee;border:2px solid #c62828;border-radius:8px;padding:16px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">
                            <i class="fas fa-exclamation-triangle" style="color:#c62828;font-size:24px;margin-top:2px;flex-shrink:0;"></i>
                            <div>
                                <div style="font-weight:700;color:#c62828;font-size:14px;margin-bottom:6px;">⚠ Proveedor con confiabilidad en 0% — Considerar reemplazar</div>
                                <div style="font-size:13px;color:#555;margin-bottom:8px;">Este proveedor ha acumulado demasiados fallos. Los principales problemas registrados son:</div>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                    <?php foreach ($fallos_principales as $tipo => $cnt):
                                        $iconos_banner = ['llegada_tardia'=>'fa-clock','entrega_incompleta'=>'fa-boxes','mal_estado'=>'fa-times-circle','otro'=>'fa-comment'];
                                    ?>
                                    <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:<?php echo $color_tipo[$tipo]; ?>;color:white;">
                                        <i class="fas <?php echo $iconos_banner[$tipo] ?? 'fa-exclamation'; ?>"></i>
                                        <?php echo $tipo_labels[$tipo]; ?> (<?php echo $cnt; ?> <?php echo $cnt === 1 ? 'vez' : 'veces'; ?>)
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Pie chart modal overlay -->
                        <div id="pastelOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
                            <div style="background:white;border-radius:12px;padding:24px;max-width:420px;width:90%;position:relative;">
                                <button onclick="document.getElementById('pastelOverlay').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>
                                <h3 style="margin:0 0 16px 0;font-size:15px;color:#003d5c;"><i class="fas fa-chart-pie"></i> Distribución de incidencias — <?php echo htmlspecialchars($proveedor_detalle['nombre']); ?></h3>
                                <?php if (empty($incidencias_prov)): ?>
                                <p style="color:#999;text-align:center;padding:20px;">Sin incidencias registradas.</p>
                                <?php else: ?>
                                <canvas id="chartPastel" style="max-height:280px;"></canvas>
                                <?php endif; ?>
                                <div style="margin-top:16px;text-align:center;font-size:13px;color:#555;">Confiabilidad: <strong><?php echo $confiabilidad_pct; ?>%</strong> (<?php echo $total_ocs_prov; ?> órdenes, <?php echo count($incidencias_prov); ?> incidencias)</div>
                            </div>
                        </div>

                        <!-- Timeline chart -->
                        <?php if (empty($incidencias_prov)): ?>
                        <div style="text-align:center;padding:40px;color:#999;">
                            <i class="fas fa-check-circle" style="font-size:36px;color:#4CAF50;margin-bottom:12px;display:block;"></i>
                            <p>Sin incidencias registradas. ¡Proveedor confiable!</p>
                        </div>
                        <?php else: ?>
                        <div style="margin-bottom:16px;">
                            <div style="font-size:13px;font-weight:600;color:#003d5c;margin-bottom:8px;"><i class="fas fa-chart-bar"></i> Historial de incidencias (clic en una barra para ver detalles)</div>
                            <div style="position:relative;height:220px;">
                                <canvas id="chartBarras"></canvas>
                            </div>
                        </div>

                        <!-- Tabla de incidencias clickeable -->
                        <div id="incidenciaDetalle" style="display:none;background:#fff8e1;border:1px solid #FFB300;border-radius:8px;padding:14px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <strong id="incDetFecha" style="color:#e65100;"></strong>
                                <button onclick="document.getElementById('incidenciaDetalle').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:16px;color:#999;">&times;</button>
                            </div>
                            <div id="incDetContenido"></div>
                        </div>

                        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
                            <thead><tr style="background:#f5f5f5;">
                                <th style="padding:8px;text-align:left;">Fecha</th>
                                <th style="padding:8px;text-align:left;">Tipo</th>
                                <th style="padding:8px;text-align:left;">Proyecto</th>
                                <th style="padding:8px;text-align:left;">Descripción</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach (array_reverse($incidencias_prov) as $inc): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:8px;"><?php echo date('d/m/Y', strtotime($inc['fecha'])); ?></td>
                                <td style="padding:8px;">
                                    <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:<?php echo $color_tipo[$inc['tipo']] ?? '#607D8B'; ?>22;color:<?php echo $color_tipo[$inc['tipo']] ?? '#607D8B'; ?>;">
                                        <?php echo $tipo_labels[$inc['tipo']] ?? $inc['tipo']; ?>
                                    </span>
                                </td>
                                <td style="padding:8px;"><?php echo htmlspecialchars($inc['proyecto_nombre'] ?? '-'); ?></td>
                                <td style="padding:8px;color:#666;"><?php echo htmlspecialchars($inc['descripcion'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>

                        <script>
                        <?php if (!empty($incidencias_prov)): ?>
                        (function() {
                            // ── Datos para gráfica de barras ─────────────────────────────
                            const incByDate = <?php echo json_encode(array_map(fn($items) => array_map(fn($i) => ['tipo'=>$i['tipo'],'descripcion'=>$i['descripcion'],'proyecto'=>$i['proyecto_nombre']], $items), $inc_by_date)); ?>;
                            const colorTipo = <?php echo json_encode($color_tipo); ?>;
                            const tipoLabels = <?php echo json_encode($tipo_labels); ?>;

                            const fechas = Object.keys(incByDate);
                            const tipos = ['llegada_tardia','entrega_incompleta','mal_estado','otro'];
                            const datasets = tipos.map(tipo => ({
                                label: tipoLabels[tipo],
                                data: fechas.map(f => incByDate[f].filter(i => i.tipo === tipo).length),
                                backgroundColor: colorTipo[tipo] + 'CC',
                                borderColor: colorTipo[tipo],
                                borderWidth: 1,
                                borderRadius: 4,
                            }));

                            const ctx = document.getElementById('chartBarras').getContext('2d');
                            const chart = new Chart(ctx, {
                                type: 'bar',
                                data: { labels: fechas, datasets: datasets },
                                options: {
                                    responsive: true, maintainAspectRatio: false,
                                    plugins: { legend: { position: 'top', labels: { font: { size: 11 } } }, tooltip: { mode: 'index' } },
                                    scales: { x: { stacked: true }, y: { stacked: true, ticks: { stepSize: 1 }, beginAtZero: true } },
                                    onClick: (e, elements) => {
                                        if (!elements.length) return;
                                        const idx = elements[0].index;
                                        const fecha = fechas[idx];
                                        const items = incByDate[fecha];
                                        document.getElementById('incDetFecha').textContent = 'Incidencias del ' + fecha;
                                        let html = '<ul style="margin:0;padding-left:18px;">';
                                        items.forEach(i => {
                                            html += `<li style="margin-bottom:4px;"><strong style="color:${colorTipo[i.tipo]}">${tipoLabels[i.tipo]}</strong>`;
                                            if (i.proyecto) html += ` — Proyecto: ${i.proyecto}`;
                                            if (i.descripcion) html += `<br><span style="color:#666;font-size:12px;">${i.descripcion}</span>`;
                                            html += '</li>';
                                        });
                                        html += '</ul>';
                                        document.getElementById('incDetContenido').innerHTML = html;
                                        document.getElementById('incidenciaDetalle').style.display = 'block';
                                    }
                                }
                            });
                        })();
                        <?php endif; ?>

                        // ── Gráfica de pastel ─────────────────────────────────────────
                        let _pastelInit = false;
                        function toggleGraficaPastel() {
                            const overlay = document.getElementById('pastelOverlay');
                            overlay.style.display = 'flex';
                            <?php if (!empty($incidencias_prov)): ?>
                            if (!_pastelInit) {
                                _pastelInit = true;
                                const ctx2 = document.getElementById('chartPastel').getContext('2d');
                                const counts = <?php echo json_encode(array_values($inc_types_count)); ?>;
                                const labels = <?php echo json_encode(array_values($tipo_labels)); ?>;
                                const colors = <?php echo json_encode(array_values($color_tipo)); ?>;
                                // Add "Sin incidencias" slice for confiabilidad visual
                                const confiables = <?php echo $confiabilidad_pct; ?>;
                                const totalFallos = 100 - confiables;
                                new Chart(ctx2, {
                                    type: 'pie',
                                    data: {
                                        labels: [...labels, 'Sin problemas'],
                                        datasets: [{
                                            data: [...counts, confiables],
                                            backgroundColor: [...colors, '#4CAF50'],
                                            borderWidth: 2,
                                            borderColor: 'white'
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        plugins: {
                                            legend: { position: 'bottom', labels: { font: { size: 12 } } },
                                            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } }
                                        }
                                    }
                                });
                            }
                            <?php endif; ?>
                        }
                        </script>
                    </div>
                </div>
                <script>
                    document.querySelectorAll('.tab-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            document.querySelectorAll('.tab-btn').forEach(b => { b.classList.remove('active'); b.style.color = '#666'; b.style.borderBottom = '3px solid transparent'; });
                            this.classList.add('active'); this.style.color = '#003d5c'; this.style.borderBottom = '3px solid #FF9800';
                            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
                            document.getElementById('tab-' + this.getAttribute('data-tab')).style.display = 'block';
                        });
                    });
                </script>
            </div>
            <?php else: ?>
                <div class="section-content">
                    <div class="sticky-container">
                        <div class="header-row">
                            <h2><i class="fas fa-truck"></i> Proveedores</h2>
                            <div class="action-buttons">
                                <div class="search-wrapper">
                                    <input type="text" class="search-input" id="searchProveedores" placeholder="Buscar proveedor...">
                                    <button class="search-clear" id="clearSearchProveedores" onclick="clearSearch('proveedores')"><i class="fas fa-times"></i></button>
                                </div>
                                <button class="btn-categorias" onclick="abrirModalCategoriasProveedores()"><i class="fas fa-tags"></i> Categorías</button>
                                <button class="btn btn-primary" onclick="abrirModalProveedor()"><i class="fas fa-plus"></i> Nuevo Proveedor</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-overlay" id="modalCategoriasProveedores">
                        <div class="modal">
                            <div class="modal-header"><h3><i class="fas fa-tags"></i> Filtrar por Categoría</h3><button class="modal-close" onclick="cerrarModal('modalCategoriasProveedores')">&times;</button></div>
                            <div class="modal-body">
                                <div class="categoria-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                                    <?php foreach ($categorias as $cat): ?>
                                    <div class="categoria-card" data-categoria="<?php echo $cat['id']; ?>" onclick="seleccionarCategoriaProveedor(this, <?php echo $cat['id']; ?>)" style="border: 2px solid #ddd; border-radius: 8px; padding: 15px; text-align: center; cursor: pointer;">
                                        <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                                        <div><?php echo $cat['nombre']; ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline" onclick="limpiarFiltroProveedores()"><i class="fas fa-eraser"></i> Limpiar</button>
                                <button class="btn" onclick="cerrarModal('modalCategoriasProveedores')">Cancelar</button>
                                <button class="btn btn-primary" onclick="aplicarFiltroProveedores()">Aplicar</button>
                            </div>
                        </div>
                    </div>
                    <div class="content-panel">
                        <div class="proveedores-grid" id="proveedoresGrid">
                            <?php foreach ($proveedores as $prov): ?>
                            <?php
                            $pv_cats_ids = [];
                            if (!empty($prov['categorias_ids'])) $pv_cats_ids = json_decode($prov['categorias_ids'], true) ?? [];
                            elseif (!empty($prov['categoria'])) $pv_cats_ids = [(int)$prov['categoria']];
                            $pv_cats_str = implode(' ', $pv_cats_ids);
                            ?>
                            <div class="proveedor-card" data-nombre="<?php echo strtolower($prov['nombre']); ?>" data-rfc="<?php echo strtolower($prov['rfc'] ?? ''); ?>" data-categorias="<?php echo $pv_cats_str; ?>" data-etiqueta="<?php echo strtolower($prov['etiqueta'] ?? ''); ?>">
                                <div class="proveedor-header" onclick="window.location.href='?seccion=proveedores&ver=<?php echo $prov['id']; ?>'" style="cursor: pointer;">
                                    <div class="proveedor-avatar"><?php echo strtoupper(substr($prov['nombre'], 0, 1)); ?></div>
                                    <div class="proveedor-header-info">
                                        <div class="proveedor-nombre"><?php echo $prov['nombre']; ?></div>
                                        <div class="proveedor-rfc"><i class="fas fa-id-card"></i> <?php echo $prov['rfc'] ?? 'RFC pendiente'; ?></div>
                                    </div>
                                </div>
                                <div class="proveedor-body">
                                    <div class="proveedor-detalle">
                                        <?php if (!empty($prov['telefono'])): ?><div class="proveedor-detalle-item"><i class="fas fa-phone"></i><span><?php echo (!empty($prov['lada']) ? '('.$prov['lada'].') ' : '') . $prov['telefono']; ?></span></div><?php endif; ?>
                                        <?php if (!empty($prov['atencion'])): ?><div class="proveedor-detalle-item"><i class="fas fa-user-tie"></i><span>At'n: <?php echo $prov['atencion']; ?></span></div><?php endif; ?>
                                    </div>
                                    <?php
                                    $conf_pct = $confiabilidad_map[$prov['id']] ?? 100;
                                    $conf_color = $conf_pct >= 80 ? '#4CAF50' : ($conf_pct >= 40 ? '#FF9800' : '#f44336');
                                    ?>
                                    <div class="proveedor-confiabilidad-mini">
                                        <div style="font-size:11px;color:#999;margin-bottom:4px;">Confiabilidad</div>
                                        <div class="confiabilidad-mini">
                                            <span class="confiabilidad-mini-valor" style="color:<?php echo $conf_color; ?>"><?php echo $conf_pct; ?>%</span>
                                            <div class="confiabilidad-mini-barra">
                                                <div class="confiabilidad-mini-progreso" style="width:<?php echo $conf_pct; ?>%;background:linear-gradient(90deg,<?php echo $conf_color; ?>,<?php echo $conf_color; ?>aa);"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="proveedor-footer">
                                    <button class="btn btn-danger btn-sm" onclick="event.stopPropagation(); confirmarEliminarProveedor(<?php echo $prov['id']; ?>, '<?php echo htmlspecialchars(addslashes($prov['nombre']), ENT_QUOTES, 'UTF-8'); ?>')"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ===================== PROYECTOS (costos + espera unificados) ===================== -->
        <?php if ($seccion === 'proyectos_costos'): ?>
            <?php /* $detalle_id ya está definido globalmente al inicio */ ?>
            <div class="section-content" style="background: white;">
                <?php if (!$detalle_id): ?>
                    <!-- LISTA DE TODOS LOS PROYECTOS -->
                    <div class="sticky-container" style="border-bottom: 1px solid #eee;">
                        <div class="header-row">
                            <h2><i class="fas fa-folder-open"></i> Gestión de Proyectos</h2>
                            <div class="search-wrapper">
                                <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #888;"></i>
                                <input type="text" class="search-input" id="searchProyectosCostos" placeholder="Buscar proyecto...">
                            </div>
                        </div>
                        <!-- Leyenda de estados -->
                        <div style="display: flex; gap: 15px; margin-top: 12px; flex-wrap: wrap;">
                            <span style="font-size: 12px; color: #666; display: flex; align-items: center; gap: 6px;">
                                <span class="badge-estado badge-espera"><i class="fas fa-clock"></i> En Espera</span> Pendiente de orden de compra
                            </span>
                            <span style="font-size: 12px; color: #666; display: flex; align-items: center; gap: 6px;">
                                <span class="badge-estado badge-activo"><i class="fas fa-play-circle"></i> Activo</span> En proceso
                            </span>
                            <span style="font-size: 12px; color: #666; display: flex; align-items: center; gap: 6px;">
                                <span class="badge-estado badge-realizado"><i class="fas fa-check-circle"></i> Realizado</span> Completado
                            </span>
                        </div>
                    </div>
                    <div class="content-panel" id="proyectosCostosContent" style="background: white;">
                        <?php
                        $proyectos_todos = $db->query("SELECT p.*, u.nombre_completo FROM proyectos p LEFT JOIN usuarios u ON p.creado_por = u.id ORDER BY p.estado ASC, p.fecha_creacion DESC")->fetchAll();

                        // El costo por proyecto (1-2 queries) corría DENTRO de este loop para
                        // CADA proyecto (N+1, y esta lista es de TODOS los proyectos) -- se trae
                        // aquí de una sola vez y se agrupa en PHP, mismo patrón usado en otras
                        // secciones de la auditoría (ej. almacen2.php::recepcion_pedidos).
                        $costo_oc_por_proy = [];
                        $cotizaciones_por_proy = [];
                        $proy_ids_cp = array_column($proyectos_todos, 'id');
                        if ($proy_ids_cp) {
                            $proy_nombres_cp = array_column($proyectos_todos, 'nombre');
                            $nombre_a_id_cp = [];
                            foreach ($proyectos_todos as $pp) { $nombre_a_id_cp[$pp['nombre']] = $pp['id']; }
                            $phIdsCp = implode(',', array_fill(0, count($proy_ids_cp), '?'));
                            $phNombresCp = implode(',', array_fill(0, count($proy_nombres_cp), '?'));

                            // 1. Órdenes de compra (por proyecto_id directo o vía requisicion)
                            $stmtOcCp = $db->prepare(
                                "SELECT oc.proyecto_id, oc.total, r.proyecto_id AS req_proyecto_id, r.proyecto AS req_proyecto_nombre
                                 FROM ordenes_compra oc
                                 LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
                                 WHERE oc.proyecto_id IN ($phIdsCp) OR r.proyecto_id IN ($phIdsCp) OR r.proyecto IN ($phNombresCp)"
                            );
                            $stmtOcCp->execute(array_merge($proy_ids_cp, $proy_ids_cp, $proy_nombres_cp));
                            foreach ($stmtOcCp->fetchAll() as $row) {
                                $matchIds = [];
                                if ($row['proyecto_id'] && in_array($row['proyecto_id'], $proy_ids_cp)) $matchIds[] = (int)$row['proyecto_id'];
                                if ($row['req_proyecto_id'] && in_array($row['req_proyecto_id'], $proy_ids_cp)) $matchIds[] = (int)$row['req_proyecto_id'];
                                if ($row['req_proyecto_nombre'] && isset($nombre_a_id_cp[$row['req_proyecto_nombre']])) $matchIds[] = (int)$nombre_a_id_cp[$row['req_proyecto_nombre']];
                                foreach (array_unique($matchIds) as $mid) {
                                    $costo_oc_por_proy[$mid] = ($costo_oc_por_proy[$mid] ?? 0) + floatval($row['total']);
                                }
                            }

                            // 2. Cotizaciones recibidas (solo se usan de fallback cuando no hay costo por OC)
                            $stmtCotCp = $db->prepare(
                                "SELECT proyecto_id, datos_ia FROM cotizaciones WHERE proyecto_id IN ($phIdsCp) AND estado = 'recibida' AND datos_ia IS NOT NULL"
                            );
                            $stmtCotCp->execute($proy_ids_cp);
                            foreach ($stmtCotCp->fetchAll() as $row) {
                                $cotizaciones_por_proy[(int)$row['proyecto_id']][] = $row;
                            }
                        }

                        foreach ($proyectos_todos as $proy):
                            // Prioridad de costo: 1) OCs generadas, 2) cotizaciones recibidas (datos_ia), 3) almacen_items×precio, 4) boom_items×precio
                            $costo = $costo_oc_por_proy[$proy['id']] ?? 0;

                            // 2. Cotizaciones recibidas (suma de productos en datos_ia)
                            if (!$costo) {
                                $total_cots = 0;
                                foreach ($cotizaciones_por_proy[$proy['id']] ?? [] as $cot_row) {
                                    $d = json_decode($cot_row['datos_ia'], true);
                                    foreach ($d['productos'] ?? [] as $prod) {
                                        $total_cots += floatval($prod['subtotal'] ?? (($prod['cantidad'] ?? 0) * ($prod['precio_unitario'] ?? 0)));
                                    }
                                }
                                if ($total_cots > 0) $costo = $total_cots;
                            }


                            // Definir color y badge según estado
                            $estado = $proy['estado'] ?? 'activo';
                            if ($proy['realizada'] ?? false) $estado = 'realizado';

                            $colores = [
                                'espera'               => ['border'=>'#ffc107','header'=>'#ffc107','text'=>'#333',  'badge_class'=>'badge-espera',        'icon'=>'fa-clock',        'label'=>'En Espera'],
                                'finalizado'           => ['border'=>'#1565c0','header'=>'#1565c0','text'=>'white', 'badge_class'=>'badge-finalizado',     'icon'=>'fa-check',        'label'=>'Liberado a Compras'],
                                'cotizacion_enviada'   => ['border'=>'#7b1fa2','header'=>'#7b1fa2','text'=>'white', 'badge_class'=>'badge-cotizacion',     'icon'=>'fa-paper-plane',  'label'=>'Cot. Enviada'],
                                'cotizacion_recibida'  => ['border'=>'#0097a7','header'=>'#0097a7','text'=>'white', 'badge_class'=>'badge-cotizacion-ok',  'icon'=>'fa-file-invoice', 'label'=>'Cot. Recibida'],
                                'orden_generada'       => ['border'=>'#e64a19','header'=>'#e64a19','text'=>'white', 'badge_class'=>'badge-orden',          'icon'=>'fa-truck',        'label'=>'Pedido en Camino'],
                                'recepcion_parcial'    => ['border'=>'#f57c00','header'=>'#f57c00','text'=>'white', 'badge_class'=>'badge-parcial',        'icon'=>'fa-exclamation',  'label'=>'Recep. Parcial'],
                                'activo'               => ['border'=>'#FF9800','header'=>'#FF9800','text'=>'white', 'badge_class'=>'badge-activo',         'icon'=>'fa-play-circle',  'label'=>'Activo'],
                                'realizado'            => ['border'=>'#003d5c','header'=>'#003d5c','text'=>'white', 'badge_class'=>'badge-realizado',      'icon'=>'fa-check-circle', 'label'=>'Realizado'],
                            ];
                            $c = $colores[$estado] ?? $colores['activo'];
                        ?>
                            <div class="proyecto-costo"
                                data-nombre="<?php echo strtolower(htmlspecialchars($proy['nombre'])); ?>"
                                data-estado="<?php echo $estado; ?>"
                                onclick="window.location.href='?seccion=proyectos_costos&detalle_id=<?php echo $proy['id']; ?>'"
                                style="border: 1px solid <?php echo $c['border']; ?>;">
                                <div style="background: <?php echo $c['header']; ?>; color: <?php echo $c['text']; ?>; padding: 15px; display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                                            <strong><i class="fas fa-folder"></i> <?php echo htmlspecialchars($proy['nombre']); ?></strong>
                                            <span class="badge-estado <?php echo $c['badge_class']; ?>">
                                                <i class="fas <?php echo $c['icon']; ?>"></i> <?php echo $c['label']; ?>
                                            </span>
                                        </div>
                                        <small style="opacity: 0.85;">Creado: <?php echo date('d/m/Y', strtotime($proy['fecha_creacion'])); ?>
                                        <?php if ($proy['realizada'] && !empty($proy['fecha_realizada'])): ?>
                                            · Realizado: <?php echo date('d/m/Y', strtotime($proy['fecha_realizada'])); ?>
                                        <?php elseif ($estado === 'espera' && !empty($proy['fecha_estado'])): ?>
                                            · En espera desde: <?php echo date('d/m/Y', strtotime($proy['fecha_estado'])); ?>
                                        <?php endif; ?>
                                        </small>
                                    </div>
                                    <div style="background: white; color: <?php echo $c['border']; ?>; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                        $<?php echo number_format($costo, 2); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($proyectos_todos)): ?>
                            <div style="text-align: center; padding: 60px; color: #999;">
                                <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 15px;"></i>
                                <p>No hay proyectos registrados</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <script>
                        const searchProy = document.getElementById('searchProyectosCostos');
                        if (searchProy) {
                            searchProy.addEventListener('input', function() {
                                const t = this.value.toLowerCase();
                                document.querySelectorAll('#proyectosCostosContent .proyecto-costo').forEach(el => {
                                    el.style.display = el.dataset.nombre.includes(t) ? '' : 'none';
                                });
                            });
                        }
                    </script>

                <?php else: ?>
                    <!-- DETALLE DEL PROYECTO -->
                    <?php
                    $stmt = $db->prepare("SELECT p.*, u.nombre_completo FROM proyectos p LEFT JOIN usuarios u ON p.creado_por = u.id WHERE p.id = ?");
                    $stmt->execute([$detalle_id]);
                    $p = $stmt->fetch();

                    if (!$p) {
                        echo '<div class="alert alert-error" style="margin: 20px;">Proyecto no encontrado.</div>';
                    } else {
                        // Intentar cargar materiales desde requisicion_detalle (fuente más completa)
                        $mats = [];
                        $stmt_req_items = $db->prepare("
                            SELECT rd.id, rd.descripcion as item, rd.descripcion, rd.cantidad as cantidad_requerida,
                                   rd.cantidad_entregada as cantidad_asignada, rd.marca, rd.modelo,
                                   m.precio_unitario, m.unidad_medida, m.descripcion as mat_nombre
                            FROM requisicion_detalle rd
                            LEFT JOIN materiales m ON rd.material_id = m.id
                            WHERE rd.requisicion_id = (
                                SELECT id FROM requisiciones WHERE proyecto_id = ? OR proyecto = ? ORDER BY fecha_creacion DESC LIMIT 1
                            )
                            ORDER BY rd.item_numero");
                        $stmt_req_items->execute([$detalle_id, $p['nombre']]);
                        $mats = $stmt_req_items->fetchAll();

                        // Fallback a almacen_items si la requisición no tiene detalles
                        if (empty($mats)) {
                            $stmt_ai = $db->prepare("SELECT ai.*, m.precio_unitario, m.unidad_medida, m.descripcion as mat_nombre FROM almacen_items ai LEFT JOIN materiales m ON ai.material_id = m.id WHERE ai.proyecto_id = ?");
                            $stmt_ai->execute([$detalle_id]);
                            $mats = $stmt_ai->fetchAll();
                        }

                        // Último fallback: boom_items (ingeniería) — aggregated, with num_equipos multiplier
                        if (empty($mats)) {
                            $stmt_bi = $db->prepare("
                                SELECT bi.material_id as id, bi.item, bi.descripcion,
                                       SUM(bi.cantidad * bl.num_equipos) AS cantidad_requerida,
                                       0 as cantidad_asignada, bi.marca, bi.modelo,
                                       MAX(m.precio_unitario) as precio_unitario,
                                       MAX(m.unidad_medida) as unidad_medida,
                                       MAX(m.descripcion) as mat_nombre
                                FROM boom_items bi
                                JOIN boom_listas bl ON bi.boom_id = bl.id
                                LEFT JOIN materiales m ON bi.material_id = m.id
                                WHERE bl.proyecto_id = ? AND bl.guardado = 1 AND bi.guardado = 1
                                GROUP BY bi.material_id, bi.item, bi.descripcion, bi.marca, bi.modelo
                                ORDER BY bi.item
                            ");
                            $stmt_bi->execute([$detalle_id]);
                            $mats = $stmt_bi->fetchAll();
                        }

                        $estado_proyecto = $p['estado'] ?? 'activo';
                        if ($p['realizada'] ?? false) $estado_proyecto = 'realizado';
                        $es_espera = ($estado_proyecto === 'espera');

                        // Categorías de materiales del proyecto (para filtrar proveedores relevantes)
                        $stmt_cat_proy = $db->prepare("
                            SELECT DISTINCT m.categoria_id
                            FROM almacen_items ai
                            JOIN materiales m ON ai.material_id = m.id
                            WHERE ai.proyecto_id = ? AND m.categoria_id IS NOT NULL
                            UNION
                            SELECT DISTINCT m.categoria_id
                            FROM requisicion_detalle rd
                            JOIN materiales m ON rd.material_id = m.id
                            WHERE rd.requisicion_id = ? AND m.categoria_id IS NOT NULL
                        ");
                        $stmt_cat_proy->execute([$detalle_id, $req_id_para_oc ?? 0]);
                        $cats_del_proyecto = array_column($stmt_cat_proy->fetchAll(), 'categoria_id');

                        // Proveedores: ordenados por relevancia (los que tienen categorías del proyecto primero)
                        $proveedores_list = $db->query("SELECT id, nombre, email, telefono, categoria, categorias_ids FROM proveedores WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
                        // Marcar cada proveedor como relevante o no
                        foreach ($proveedores_list as &$pv) {
                            $pv_cats = [];
                            if (!empty($pv['categorias_ids'])) {
                                $pv_cats = json_decode($pv['categorias_ids'], true) ?? [];
                            } elseif (!empty($pv['categoria'])) {
                                $pv_cats = [(int)$pv['categoria']];
                            }
                            $pv['_relevante'] = !empty($cats_del_proyecto) && !empty(array_intersect($cats_del_proyecto, $pv_cats));
                        }
                        unset($pv);
                        // Ordenar: relevantes primero
                        usort($proveedores_list, fn($a, $b) => ($b['_relevante'] <=> $a['_relevante']) ?: strcmp($a['nombre'], $b['nombre']));

                        // Buscar la requisición asociada a este proyecto (para enlazar orden de compra)
                        $stmt_req = $db->prepare("SELECT id, folio FROM requisiciones WHERE proyecto_id = ? OR proyecto = ? ORDER BY fecha_creacion DESC LIMIT 1");
                        $stmt_req->execute([$detalle_id, $p['nombre']]);
                        $req_vinculada = $stmt_req->fetch();
                        $req_id_para_oc = $req_vinculada ? $req_vinculada['id'] : 0;
                    ?>
                    <div class="sticky-container" style="border-bottom: 1px solid #eee;">
                        <div class="header-row">
                            <h2>
                                <span onclick="window.location.href='?seccion=proyectos_costos'" style="cursor:pointer; color: #003d5c;">
                                    <i class="fas fa-folder-open"></i> Proyectos
                                </span>
                                <span style="font-weight: 300; font-size: 1.2rem; color: #888;">/</span>
                                <span id="nombre_proyecto_pdf"><?php echo htmlspecialchars($p['nombre']); ?></span>
                                <?php
                                $badge_map = [
                                    'espera'              => ['class'=>'badge-espera',       'icon'=>'fa-clock',        'label'=>'En Espera'],
                                    'finalizado'          => ['class'=>'badge-finalizado',   'icon'=>'fa-check',        'label'=>'Liberado a Compras'],
                                    'cotizacion_enviada'  => ['class'=>'badge-cotizacion',   'icon'=>'fa-paper-plane',  'label'=>'Cot. Enviada'],
                                    'cotizacion_recibida' => ['class'=>'badge-cotizacion-ok','icon'=>'fa-file-invoice', 'label'=>'Cot. Recibida'],
                                    'orden_generada'      => ['class'=>'badge-orden',        'icon'=>'fa-truck',        'label'=>'Pedido en Camino'],
                                    'recepcion_parcial'   => ['class'=>'badge-parcial',      'icon'=>'fa-exclamation',  'label'=>'Recep. Parcial'],
                                    'activo'              => ['class'=>'badge-activo',       'icon'=>'fa-play-circle',  'label'=>'Activo'],
                                    'realizado'           => ['class'=>'badge-realizado',    'icon'=>'fa-check-circle', 'label'=>'Realizado'],
                                ];
                                $bm = $badge_map[$estado_proyecto] ?? $badge_map['activo'];
                                ?>
                                <span class="badge-estado <?php echo $bm['class']; ?>"><i class="fas <?php echo $bm['icon']; ?>"></i> <?php echo $bm['label']; ?></span>
                            </h2>
                            <div class="action-buttons">
                                <button class="btn btn-outline" onclick="imprimirBomProyecto(<?php echo $detalle_id; ?>)">
                                    <i class="fas fa-print"></i> Imprimir BOM
                                </button>
                                <button class="btn btn-outline" onclick="descargarERP_PDF(this)">
                                    <i class="fas fa-file-pdf"></i> DESCARGAR PDF
                                </button>
                                <button class="btn btn-outline" onclick="window.location.href='?seccion=proyectos_costos'">
                                    <i class="fas fa-arrow-left"></i> VOLVER
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php
                    // ── Cargar cotizaciones del proyecto ─────────────────────────────
                    $stmt_cots = $db->prepare("SELECT c.*, pv.nombre as prov_nombre, pv.email as prov_email, pv.telefono as prov_tel
                        FROM cotizaciones c JOIN proveedores pv ON c.proveedor_id = pv.id
                        WHERE c.proyecto_id = ? ORDER BY c.fecha_solicitud DESC");
                    $stmt_cots->execute([$detalle_id]);
                    $cotizaciones_proy = $stmt_cots->fetchAll();
                    $cots_recibidas = array_filter($cotizaciones_proy, fn($c) => $c['estado'] === 'recibida');

                    // ── Cargar órdenes de compra generadas ───────────────────────────
                    $ordenes_proy = [];
                    // Primero buscar por proyecto_id (OCs generadas desde este flujo)
                    $stmt_ocs = $db->prepare("SELECT oc.*, pv.nombre as prov_nombre FROM ordenes_compra oc LEFT JOIN proveedores pv ON oc.proveedor_id = pv.id WHERE oc.proyecto_id = ?");
                    $stmt_ocs->execute([$detalle_id]);
                    $ordenes_proy = $stmt_ocs->fetchAll();
                    // Fallback: buscar por requisicion_id para OCs antiguas
                    if (empty($ordenes_proy) && $req_id_para_oc) {
                        $stmt_ocs = $db->prepare("SELECT oc.*, pv.nombre as prov_nombre FROM ordenes_compra oc LEFT JOIN proveedores pv ON oc.proveedor_id = pv.id WHERE oc.requisicion_id = ? AND oc.proyecto_id IS NULL");
                        $stmt_ocs->execute([$req_id_para_oc]);
                        $ordenes_proy = $stmt_ocs->fetchAll();
                    }
                    ?>

                    <!-- ── STEP INDICATOR ───────────────────────────────────────────── -->
                    <?php $readonly_mode = in_array($estado_proyecto, ['orden_generada', 'recepcion_parcial', 'realizado']); ?>
                    <div class="step-indicator" style="margin:16px 0 0 0;">
                        <?php
                        $steps = [
                            'finalizado'          => ['Solicitar cotización', 'fa-check'],
                            'espera'              => ['Solicitar cotización', 'fa-envelope'],
                            'cotizacion_enviada'  => ['Esperando respuesta',  'fa-clock'],
                            'cotizacion_recibida' => ['Comparar precios',     'fa-balance-scale'],
                            'orden_generada'      => ['Pedido en camino',     'fa-truck'],
                            'realizado'           => ['Completado',           'fa-check-circle'],
                        ];
                        $step_order = array_keys($steps);
                        $cur_idx = array_search($estado_proyecto, $step_order);
                        if ($cur_idx === false) $cur_idx = 0;
                        foreach ($step_order as $si => $sk):
                            $cls = $si < $cur_idx ? 'done' : ($si === $cur_idx ? 'active' : '');
                            $clickable = $readonly_mode && ($cls === 'done' || $cls === 'active');
                        ?>
                        <div class="step-item <?php echo $cls; ?>"
                            data-proj="<?php echo $detalle_id; ?>"
                            data-key="<?php echo $sk; ?>"
                            <?php if ($clickable): ?>onclick="showTabErp(this)" style="cursor:pointer;"<?php endif; ?>>
                            <i class="fas <?php echo $steps[$sk][1]; ?>"></i><br><?php echo $steps[$sk][0]; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($readonly_mode): ?>
                    <div id="readonly-tabs-<?php echo $detalle_id; ?>" style="display:none;">

                        <!-- ── TAB: Solicitar cotización (estado finalizado) ── -->
                        <div id="tab-finalizado-<?php echo $detalle_id; ?>" class="readonly-panel cot-panel" style="display:none;">
                            <h3><i class="fas fa-check" style="color:#43a047"></i> Materiales requisitados</h3>
                            <table class="erp-table">
                                <thead><tr><th>#</th><th>Artículo</th><th>Descripción</th><th style="text-align:center">Cant.</th><th>Marca</th><th>Modelo</th></tr></thead>
                                <tbody>
                                <?php foreach ($mats as $i => $m):
                                    $dn = fn($v) => htmlspecialchars(html_entity_decode($v ?? '', ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td><?php echo $dn($m['item'] ?? $m['mat_nombre'] ?? '-'); ?></td>
                                    <td><?php echo $dn($m['descripcion'] ?: ($m['mat_nombre'] ?? '-')); ?></td>
                                    <td style="text-align:center"><?php echo $m['cantidad_requerida']; ?></td>
                                    <td><?php echo $dn($m['marca'] ?? '-'); ?></td>
                                    <td><?php echo $dn($m['modelo'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($mats)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:20px;color:#999;">Sin materiales registrados</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- ── TAB: Solicitar cotización (estado espera) — proveedores contactados ── -->
                        <div id="tab-espera-<?php echo $detalle_id; ?>" class="readonly-panel cot-panel" style="display:none;">
                            <h3><i class="fas fa-envelope" style="color:#7b1fa2"></i> Proveedores contactados</h3>
                            <?php if (empty($cotizaciones_proy)): ?>
                            <p style="color:#999;">No se registraron contactos con proveedores.</p>
                            <?php else: ?>
                            <table class="erp-table">
                                <thead><tr><th>Proveedor</th><th>Email enviado a</th><th>Fecha solicitud</th><th>Estado</th></tr></thead>
                                <tbody>
                                <?php foreach ($cotizaciones_proy as $cot_ro): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cot_ro['prov_nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($cot_ro['email_enviado_a'] ?? '-'); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($cot_ro['fecha_solicitud'])); ?></td>
                                    <td>
                                        <?php if ($cot_ro['estado'] === 'recibida'): ?>
                                        <span class="badge-estado badge-activo"><i class="fas fa-check"></i> Recibida</span>
                                        <?php else: ?>
                                        <span class="badge-estado badge-espera"><i class="fas fa-clock"></i> Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>

                        <!-- ── TAB: Esperando respuesta (estado cotizacion_enviada) ── -->
                        <div id="tab-cotizacion_enviada-<?php echo $detalle_id; ?>" class="readonly-panel cot-panel" style="display:none;">
                            <h3><i class="fas fa-clock" style="color:#f9a825"></i> Esperando respuesta de proveedores</h3>
                            <?php if (empty($cotizaciones_proy)): ?>
                            <p style="color:#999;">No se enviaron solicitudes de cotización.</p>
                            <?php else: ?>
                            <table class="erp-table">
                                <thead><tr><th>Proveedor</th><th>Email</th><th>Fecha solicitud</th><th>Estado</th></tr></thead>
                                <tbody>
                                <?php foreach ($cotizaciones_proy as $cot_ro): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cot_ro['prov_nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($cot_ro['email_enviado_a'] ?? '-'); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($cot_ro['fecha_solicitud'])); ?></td>
                                    <td>
                                        <?php if ($cot_ro['estado'] === 'recibida'): ?>
                                        <span class="badge-estado badge-activo"><i class="fas fa-check"></i> Recibida</span>
                                        <?php else: ?>
                                        <span class="badge-estado badge-espera"><i class="fas fa-clock"></i> Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>

                        <!-- ── TAB: Comparar precios (estado cotizacion_recibida) ── -->
                        <div id="tab-cotizacion_recibida-<?php echo $detalle_id; ?>" class="readonly-panel cot-panel" style="display:none;">
                            <h3><i class="fas fa-balance-scale" style="color:#0097a7"></i> Cotizaciones recibidas</h3>
                            <?php
                            $cots_rec_ro = array_filter($cotizaciones_proy, fn($c) => $c['estado'] === 'recibida');
                            if (empty($cots_rec_ro)): ?>
                            <p style="color:#999;">No se recibieron cotizaciones registradas.</p>
                            <?php else: ?>
                            <?php foreach ($cots_rec_ro as $cot_ro):
                                $d_ro = $cot_ro['datos_ia'] ? json_decode($cot_ro['datos_ia'], true) : [];
                            ?>
                            <div class="prov-card" style="flex-direction:column;align-items:flex-start;margin-bottom:12px;">
                                <div style="font-weight:600;font-size:14px;margin-bottom:8px;">
                                    <?php echo htmlspecialchars($cot_ro['prov_nombre']); ?>
                                    <span style="font-size:12px;color:#555;font-weight:400;margin-left:8px;">
                                        Recibida: <?php echo date('d/m/Y', strtotime($cot_ro['fecha_respuesta'] ?? $cot_ro['fecha_solicitud'])); ?>
                                        <?php if (!empty($d_ro['total'])): ?> &nbsp;·&nbsp; Total: <strong>$<?php echo number_format($d_ro['total'], 2); ?></strong><?php endif; ?>
                                    </span>
                                </div>
                                <?php if (!empty($d_ro['productos'])): ?>
                                <table class="erp-table" style="font-size:12px;">
                                    <thead><tr><th>#</th><th>Descripción</th><th>Cant.</th><th>Precio Unit.</th><th>Subtotal</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($d_ro['productos'] as $pi => $prod_ro):
                                        $pu_ro  = floatval($prod_ro['precio_unitario'] ?? 0);
                                        $qty_ro = floatval($prod_ro['cantidad'] ?? 1);
                                    ?>
                                    <tr>
                                        <td><?php echo $pi+1; ?></td>
                                        <td><?php echo htmlspecialchars($prod_ro['descripcion'] ?? '-'); ?></td>
                                        <td><?php echo $qty_ro; ?></td>
                                        <td>$<?php echo number_format($pu_ro, 2); ?></td>
                                        <td>$<?php echo number_format($qty_ro * $pu_ro, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                <p style="color:#999;font-size:13px;">Sin detalle de productos registrado.</p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div><!-- /readonly-tabs -->
                    <?php endif; ?>

                    <div id="active-panel-<?php echo $detalle_id; ?>" class="erp-container" style="background:white; padding:20px 0;">

                    <?php if (in_array($estado_proyecto, ['finalizado', 'activo'])): ?>
                    <div class="cot-panel" style="text-align:center;padding:40px 20px;">
                        <i class="fas fa-warehouse" style="font-size:2.5rem;color:#1565c0;margin-bottom:14px;display:block;"></i>
                        <h3 style="justify-content:center;color:#1565c0;">Esperando requisición de Almacén</h3>
                        <p style="color:#666;font-size:13px;margin-top:6px;">Ingeniería liberó el proyecto. Almacén debe realizar la comparativa de stock y guardar la requisición antes de que compras pueda proceder.</p>
                    </div>

                    <!-- ════════════════════════════════════════════════════════════════
                         ESTADO 1: espera — Solicitar cotizaciones a proveedores
                    ═══════════════════════════════════════════════════════════════════ -->
                    <?php elseif ($estado_proyecto === 'espera'): ?>
                    <div class="cot-panel">
                        <h3><i class="fas fa-envelope" style="color:#7b1fa2"></i> Solicitar cotización a proveedores</h3>

                        <!-- Tabla de materiales de referencia -->
                        <p style="font-size:13px;color:#666;margin-bottom:10px;">Materiales del proyecto que se enviarán al proveedor:</p>
                        <table class="erp-table" style="margin-bottom:18px;">
                            <thead><tr><th>#</th><th>Artículo</th><th>Descripción</th><th style="text-align:center">Cant.</th><th>Marca</th><th>Modelo</th></tr></thead>
                            <tbody>
                            <?php foreach ($mats as $i => $m):
                                $dn2 = fn($v) => htmlspecialchars(html_entity_decode($v ?? '', ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr>
                                    <td><?php echo $i+1; ?></td>
                                    <td><?php echo $dn2($m['item'] ?? $m['mat_nombre'] ?? '-'); ?></td>
                                    <td><?php echo $dn2($m['descripcion'] ?: ($m['mat_nombre'] ?? '-')); ?></td>
                                    <td style="text-align:center"><?php echo $m['cantidad_requerida']; ?></td>
                                    <td><?php echo $dn2($m['marca'] ?? '-'); ?></td>
                                    <td><?php echo $dn2($m['modelo'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($mats)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:20px;color:#999;">Sin materiales registrados</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Selección de proveedores -->
                        <?php
                        $provs_relevantes = array_filter($proveedores_list, fn($pv) => $pv['_relevante']);
                        $provs_otros = array_filter($proveedores_list, fn($pv) => !$pv['_relevante']);
                        // Proveedores bloqueados para ESTE proyecto (entrega parcial o mal estado
                        // en una recepción anterior) — ver bloquearProveedorProyecto() en config.php.
                        $provs_bloqueados_map = [];
                        $stmt_bloq_p = $db->prepare("SELECT id, proveedor_id, motivo FROM proveedor_bloqueado WHERE proyecto_id = ? AND activo = 1");
                        $stmt_bloq_p->execute([$detalle_id]);
                        foreach ($stmt_bloq_p->fetchAll() as $bq) { $provs_bloqueados_map[$bq['proveedor_id']] = $bq; }
                        ?>
                        <p style="font-size:13px;color:#666;margin-bottom:8px;"><strong>Selecciona los proveedores a contactar:</strong>
                        <?php if (!empty($provs_relevantes)): ?>
                            <span style="font-size:11px;color:#7b1fa2;margin-left:8px;"><i class="fas fa-star"></i> Los resaltados manejan las categorías de este proyecto</span>
                        <?php endif; ?></p>
                        <?php $cat_mapa = array_column($categorias, 'icono', 'id'); ?>
                        <?php
                        // Alerta de proveedores con baja confiabilidad seleccionados por defecto
                        $provs_baja_conf = array_filter($proveedores_list, fn($pv) => $pv['_relevante'] && ($confiabilidad_map[$pv['id']] ?? 100) < 70);
                        if (!empty($provs_baja_conf)):
                        ?>
                        <div style="background:#fff3e0;border:1px solid #ffb74d;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#e65100;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Atención:</strong> Los siguientes proveedores relevantes tienen confiabilidad baja (&lt;70%) — considera priorizar otros:
                            <ul style="margin:4px 0 0 16px;">
                            <?php foreach ($provs_baja_conf as $pbc): ?>
                                <li><strong><?= htmlspecialchars($pbc['nombre']) ?></strong> — <?= $confiabilidad_map[$pbc['id']] ?>% confiabilidad</li>
                            <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;margin-bottom:8px;" id="provCheckList">
                        <?php foreach ($proveedores_list as $pv):
                            $conf_pct = $confiabilidad_map[$pv['id']] ?? 100;
                            $conf_baja = $conf_pct < 70;
                            $bloqueo_pv = $provs_bloqueados_map[$pv['id']] ?? null;
                            $border_color = $bloqueo_pv ? '#757575' : ($conf_baja ? '#e53935' : ($pv['_relevante'] ? '#7b1fa2' : '#eee'));
                            $bg_color     = $bloqueo_pv ? '#f0f0f0' : ($conf_baja ? '#fdecea' : ($pv['_relevante'] ? '#f3e5f5' : ''));
                        ?>
                            <label style="display:flex;align-items:flex-start;gap:8px;border:1px solid <?= $border_color ?>;border-radius:5px;padding:8px 12px;cursor:<?= $bloqueo_pv ? 'not-allowed' : 'pointer' ?>;user-select:none;<?= $bg_color ? "background:$bg_color;" : '' ?><?= $bloqueo_pv ? 'opacity:.75;' : '' ?>" class="prov-check-label">
                                <input type="checkbox" value="<?php echo $pv['id']; ?>" name="prov_sel" style="width:16px;height:16px;margin-top:2px;flex-shrink:0;" <?php echo ($pv['_relevante'] && !$conf_baja && !$bloqueo_pv) ? 'checked' : ''; ?> <?php echo $bloqueo_pv ? 'disabled' : ''; ?>>
                                <div style="flex:1;min-width:0">
                                    <div style="font-weight:600;font-size:13px"><?php echo htmlspecialchars($pv['nombre']); ?>
                                        <?php if ($pv['_relevante'] && !$conf_baja && !$bloqueo_pv): ?><i class="fas fa-star" style="color:#7b1fa2;font-size:10px;margin-left:4px"></i><?php endif; ?>
                                        <?php if ($conf_baja && !$bloqueo_pv): ?><span style="background:#e53935;color:white;font-size:9px;padding:1px 5px;border-radius:8px;margin-left:4px;">⚠ Baja conf.</span><?php endif; ?>
                                    </div>
                                    <?php if ($bloqueo_pv): ?>
                                    <div style="font-size:11px;color:#c62828;margin-top:2px;">
                                        <i class="fas fa-ban"></i> Bloqueado para este proyecto: <?php echo htmlspecialchars($bloqueo_pv['motivo']); ?>
                                        <a href="#" onclick="desbloquearProveedorProyecto(<?php echo (int)$bloqueo_pv['id']; ?>);return false;" style="color:#1565c0;font-weight:700;margin-left:6px;">Desbloquear</a>
                                    </div>
                                    <?php endif; ?>
                                    <div style="font-size:11px;color:#888">
                                        <?php echo $pv['email'] ? htmlspecialchars($pv['email']) : '<span style="color:#e53935">Sin email</span>'; ?>
                                        &nbsp;·&nbsp;
                                        <span style="color:<?= $conf_pct >= 70 ? '#2E7D32' : '#e53935' ?>;font-weight:700;"><?= $conf_pct ?>% conf.</span>
                                    </div>
                                    <?php
                                    $pv_cat_ids = [];
                                    if (!empty($pv['categorias_ids'])) $pv_cat_ids = json_decode($pv['categorias_ids'], true) ?? [];
                                    elseif (!empty($pv['categoria'])) $pv_cat_ids = [(int)$pv['categoria']];
                                    if (!empty($pv_cat_ids)):
                                    ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;">
                                        <?php foreach ($pv_cat_ids as $cid):
                                            $icono = $cat_mapa[$cid] ?? 'tag';
                                        ?>
                                        <span title="<?php foreach($categorias as $cc) if($cc['id']==$cid){echo htmlspecialchars($cc['nombre']);break;} ?>" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:4px;background:<?php echo $pv['_relevante'] && in_array($cid,$cats_del_proyecto??[]) ? '#7b1fa2' : '#e0e0e0'; ?>;color:<?php echo $pv['_relevante'] && in_array($cid,$cats_del_proyecto??[]) ? 'white' : '#555'; ?>;font-size:11px;">
                                            <i class="fas fa-<?php echo htmlspecialchars($icono); ?>"></i>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                        </div>
                        <div style="margin-bottom:14px;font-size:12px;color:#888">
                            <a href="#" onclick="document.querySelectorAll('#provCheckList input').forEach(c=>c.checked=true);return false;">Seleccionar todos</a> ·
                            <a href="#" onclick="document.querySelectorAll('#provCheckList input').forEach(c=>c.checked=false);return false;">Deseleccionar todos</a>
                        </div>

                        <div style="margin-bottom:14px;">
                            <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">Mensaje adicional (opcional):</label>
                            <textarea id="msg_cotizacion" rows="3" style="width:100%;border:1px solid #ddd;border-radius:4px;padding:8px;font-size:13px;" placeholder="Indicaciones especiales, fecha límite, etc."></textarea>
                        </div>

                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <button class="btn btn-success" onclick="enviarSolicitudCotizacion(<?php echo $detalle_id; ?>, <?php echo $req_id_para_oc; ?>)">
                                <i class="fas fa-paper-plane"></i> Enviar solicitud de cotización
                            </button>
                            <button class="btn btn-outline" style="color:#555;border-color:#bbb;" onclick="saltarFaseCotizacion(<?php echo $detalle_id; ?>, <?php echo $req_id_para_oc; ?>)" title="Si ya tienes una cotización previa válida, registra los proveedores sin enviar correo y súbela manualmente.">
                                <i class="fas fa-forward"></i> Saltar envío (cotización previa)
                            </button>
                            <span style="font-size:12px;color:#888;">Se creará un registro por cada proveedor seleccionado y se intentará enviar el email.</span>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════════════════════════
                         ESTADO 2: cotizacion_enviada — Seguimiento y subida de cotizaciones
                    ═══════════════════════════════════════════════════════════════════ -->
                    <?php elseif ($estado_proyecto === 'cotizacion_enviada'): ?>
                    <div class="cot-panel">
                        <h3><i class="fas fa-clock" style="color:#7b1fa2"></i> Cotizaciones enviadas — En espera de respuesta</h3>
                        <?php if (empty($cotizaciones_proy)): ?>
                            <div style="color:#999;padding:20px;text-align:center;">No hay cotizaciones registradas. Contacta a los proveedores manualmente.</div>
                        <?php else: ?>
                            <?php foreach ($cotizaciones_proy as $cot): ?>
                            <div class="prov-card <?php echo $cot['estado']; ?>">
                                <div class="prov-info">
                                    <div class="prov-nombre"><?php echo htmlspecialchars($cot['prov_nombre']); ?></div>
                                    <div class="prov-email">
                                        <?php echo $cot['email_enviado_a'] ? '✉ ' . htmlspecialchars($cot['email_enviado_a']) : 'Sin email'; ?>
                                        &nbsp;·&nbsp; Enviado: <?php echo date('d/m/Y H:i', strtotime($cot['fecha_solicitud'])); ?>
                                    </div>
                                    <?php if ($cot['estado'] === 'recibida'): ?>
                                        <?php $d = $cot['datos_ia'] ? json_decode($cot['datos_ia'], true) : null; ?>
                                        <div style="font-size:12px;color:#43a047;margin-top:4px;">
                                            ✓ Cotización recibida el <?php echo date('d/m/Y', strtotime($cot['fecha_respuesta'])); ?>
                                            <?php if ($d): ?>
                                             · Total: <strong>$<?php echo number_format($d['total'] ?? 0, 2); ?></strong>
                                             · <?php echo count($d['productos'] ?? []); ?> productos
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
                                    <?php if ($cot['estado'] === 'pendiente'): ?>
                                        <span class="badge-estado badge-espera"><i class="fas fa-clock"></i> Pendiente</span>
                                        <button class="btn btn-outline btn-sm" onclick="abrirModalCotizacion(<?php echo $cot['id']; ?>)" title="Subir y analizar cotización de este proveedor">
                                            <i class="fas fa-cloud-upload-alt"></i> Subir cotización
                                        </button>
                                        <button class="btn btn-outline btn-sm" style="color:#e65100;border-color:#e65100;" onclick="abrirModalManual(<?php echo $cot['id']; ?>, '<?php echo htmlspecialchars(addslashes($cot['prov_nombre']), ENT_QUOTES, 'UTF-8'); ?>')" title="Ingresar datos de cotización manualmente">
                                            <i class="fas fa-keyboard"></i> Ingresar manualmente
                                        </button>
                                    <?php else: ?>
                                        <span class="badge-estado badge-activo"><i class="fas fa-check"></i> Recibida</span>
                                        <button class="btn btn-outline btn-sm" onclick="verDatosCotizacion(<?php echo $cot['id']; ?>)" title="Ver datos extraídos por IA">
                                            <i class="fas fa-eye"></i> Ver datos
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php
                        // Embeder datos de cotizaciones en JS para verDatosCotizacion()
                        $cots_js = [];
                        foreach ($cotizaciones_proy as $cot) {
                            $cots_js[$cot['id']] = $cot['datos_ia'] ? json_decode($cot['datos_ia'], true) : null;
                        }
                        ?>
                        <script>window._cotData = <?php echo json_encode($cots_js); ?>;</script>
                        <script>window._matsProyecto = <?php echo json_encode(array_values(array_map(fn($m) => ['id'=>$m['id']??0,'nombre'=>$m['item']??$m['mat_nombre']??'-','cantidad'=>$m['cantidad_requerida']??1,'unidad'=>$m['unidad_medida']??'pza'], $mats))); ?>;</script>

                        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                            <?php if (!empty($cots_recibidas)): ?>
                            <button class="btn btn-success" onclick="irAComparativa(<?php echo $detalle_id; ?>)">
                                <i class="fas fa-balance-scale"></i> Comparar cotizaciones recibidas (<?php echo count($cots_recibidas); ?>)
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-outline" onclick="verArchivosCotizacion(<?php echo $detalle_id; ?>)">
                                <i class="fas fa-folder-open"></i> Ver archivos
                            </button>
                            <button class="btn btn-outline" style="color:#666;border-color:#ccc;" onclick="regresarEstado(<?php echo $detalle_id; ?>, 'espera')">
                                <i class="fas fa-arrow-left"></i> Regresar al paso anterior
                            </button>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════════════════════════
                         ESTADO 3: cotizacion_recibida — Comparativa y selección de proveedores
                    ═══════════════════════════════════════════════════════════════════ -->
                    <?php elseif ($estado_proyecto === 'cotizacion_recibida'): ?>
                    <div class="cot-panel">
                        <h3><i class="fas fa-balance-scale" style="color:#0097a7"></i> Comparativa de cotizaciones — Selecciona proveedores para ordenar</h3>

                        <?php if (empty($cots_recibidas)): ?>
                            <div style="color:#999;padding:20px;text-align:center;">No hay cotizaciones recibidas aún.</div>
                        <?php else: ?>
                            <p style="font-size:13px;color:#666;margin-bottom:16px;">Selecciona los items que deseas ordenar de cada proveedor. Puedes ajustar cantidades. Se generará una Orden de Compra por cada proveedor con al menos un item seleccionado.</p>

                            <?php foreach ($cots_recibidas as $cot): ?>
                            <?php $d = $cot['datos_ia'] ? json_decode($cot['datos_ia'], true) : []; ?>
                            <div class="prov-card recibida prov-cot-card" style="flex-direction:column;align-items:flex-start;" data-cot-id="<?php echo $cot['id']; ?>">
                                <div style="display:flex;width:100%;align-items:center;justify-content:space-between;margin-bottom:10px;">
                                    <div>
                                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                            <input type="checkbox" class="cot-sel-all" data-cot-id="<?php echo $cot['id']; ?>"
                                                style="width:18px;height:18px;" checked
                                                onchange="toggleTodosItems(<?php echo $cot['id']; ?>, this.checked)">
                                            <span style="font-weight:700;font-size:15px;"><?php echo htmlspecialchars($cot['prov_nombre']); ?></span>
                                        </label>
                                    </div>
                                    <div style="text-align:right;">
                                        <?php if ($d): ?>
                                        <div style="font-size:13px;color:#555;">Subtotal: <strong id="sub-card-<?php echo $cot['id']; ?>">$<?php echo number_format($d['subtotal'] ?? 0, 2); ?></strong></div>
                                        <div style="font-size:14px;color:#1565c0;font-weight:700;">Total c/IVA: <span id="tot-card-<?php echo $cot['id']; ?>">$<?php echo number_format($d['total'] ?? 0, 2); ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($d['productos'])): ?>
                                <table class="comparativa-table" style="margin-top:4px;width:100%;">
                                    <thead>
                                        <tr>
                                            <th style="width:28px;"></th>
                                            <th style="width:28px;">#</th>
                                            <th>Descripción</th>
                                            <th style="width:90px;">Cant.</th>
                                            <th>Precio Unit.</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($d['productos'] as $pi => $prod):
                                        $pu       = floatval($prod['precio_unitario'] ?? 0);
                                        $qty_orig = floatval($prod['cantidad'] ?? 1);
                                        $sub_orig = round($qty_orig * $pu, 2);
                                        $desc_cot = trim($prod['descripcion'] ?? '');
                                        if ($desc_cot === '' && isset($mats[$pi])) {
                                            $desc_cot = $mats[$pi]['mat_nombre'] ?: ($mats[$pi]['descripcion'] ?? '-');
                                        }
                                    ?>
                                        <tr class="item-row"
                                            data-cot-id="<?php echo $cot['id']; ?>"
                                            data-descripcion="<?php echo htmlspecialchars($desc_cot ?: ($prod['descripcion'] ?? ''), ENT_QUOTES); ?>"
                                            data-precio-unit="<?php echo $pu; ?>"
                                            data-unidad="<?php echo htmlspecialchars($prod['unidad'] ?? 'pza', ENT_QUOTES); ?>">
                                            <td style="text-align:center;">
                                                <input type="checkbox" class="item-chk" checked
                                                    onchange="recalcularCard(<?php echo $cot['id']; ?>)">
                                            </td>
                                            <td><?php echo $pi + 1; ?></td>
                                            <td><?php echo htmlspecialchars($desc_cot ?: '-'); ?></td>
                                            <td>
                                                <input type="number" class="item-qty" value="<?php echo $qty_orig; ?>"
                                                    min="0.01" step="0.01"
                                                    style="width:68px;padding:3px 5px;border:1px solid #ddd;border-radius:4px;font-size:12px;"
                                                    oninput="recalcularCard(<?php echo $cot['id']; ?>)">
                                            </td>
                                            <td class="precio-cot">$<?php echo number_format($pu, 2); ?></td>
                                            <td class="item-subtotal">$<?php echo number_format($sub_orig, 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                    <div style="color:#999;font-size:13px;">Sin detalle de productos disponible.</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                                <button class="btn btn-success" onclick="generarOrdenes(event, <?php echo $detalle_id; ?>, <?php echo $req_id_para_oc; ?>)">
                                    <i class="fas fa-shopping-cart"></i> Generar órdenes de compra
                                </button>
                                <button class="btn btn-outline" onclick="regresarEstado(<?php echo $detalle_id; ?>, 'cotizacion_enviada')">
                                    <i class="fas fa-arrow-left"></i> Regresar — subir más cotizaciones
                                </button>
                                <span style="font-size:12px;color:#888;">Se generará una orden de compra por cada proveedor marcado.</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ════════════════════════════════════════════════════════════════
                         ESTADO 4: orden_generada — Pedido en camino
                    ═══════════════════════════════════════════════════════════════════ -->
                    <?php elseif (in_array($estado_proyecto, ['orden_generada', 'recepcion_parcial'])): ?>
                    <div class="cot-panel">
                        <h3><i class="fas fa-truck" style="color:#e64a19"></i> Pedido en camino — Esperando recepción en almacén</h3>
                        <?php if (empty($ordenes_proy)): ?>
                            <div style="color:#999;padding:20px;text-align:center;">No se encontraron órdenes de compra para este proyecto.</div>
                        <?php else: ?>
                            <?php foreach ($ordenes_proy as $oc): ?>
                            <?php $oc_aprobacion = $oc['estado'] ?? 'pendiente_aprobacion'; ?>
                            <div class="prov-card" style="<?php echo $oc['estado'] === 'recibida' ? 'background:#f0faf2;border-color:#66bb6a;' : ''; ?>">
                                <div class="prov-info">
                                    <div class="prov-nombre"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($oc['folio']); ?></div>
                                    <div class="prov-email">Proveedor: <?php echo htmlspecialchars($oc['prov_nombre'] ?? '—'); ?></div>
                                    <div style="font-size:12px;color:#555;margin-top:3px;">
                                        Total: <strong>$<?php echo number_format($oc['total'] ?? 0, 2); ?></strong>
                                        <?php if ($oc['fecha_entrega_estimada']): ?> · Entrega est.: <?php echo date('d/m/Y', strtotime($oc['fecha_entrega_estimada'])); ?><?php endif; ?>
                                    </div>
                                    <?php if ($oc_aprobacion === 'rechazada' && !empty($oc['motivo_rechazo'])): ?>
                                    <div style="font-size:12px;color:#c62828;margin-top:5px;padding:4px 8px;background:#ffebee;border-radius:4px;">
                                        <i class="fas fa-comment-alt"></i> <?php echo htmlspecialchars($oc['motivo_rechazo']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
                                    <?php if ($oc['estado'] === 'recibida'): ?>
                                        <span class="badge-estado badge-activo"><i class="fas fa-check"></i> Recibido</span>
                                    <?php elseif ($oc_aprobacion === 'aprobada'): ?>
                                        <span class="badge-estado badge-activo"><i class="fas fa-check-circle"></i> Aprobada</span>
                                    <?php elseif ($oc_aprobacion === 'rechazada'): ?>
                                        <span class="badge-estado" style="background:#ffebee;color:#c62828;border:1px solid #ef9a9a;"><i class="fas fa-times-circle"></i> Rechazada</span>
                                    <?php else: ?>
                                        <span class="badge-estado badge-espera"><i class="fas fa-clock"></i> Pend. aprobación</span>
                                    <?php endif; ?>
                                    <?php if ($oc_aprobacion === 'rechazada'): ?>
                                    <button class="btn btn-outline btn-sm" onclick="reenviarOC(<?php echo $oc['id']; ?>, this)">
                                        <i class="fas fa-redo"></i> Re-enviar
                                    </button>
                                    <?php endif; ?>
                                    <a href="orden_compra.php?orden_id=<?php echo $oc['id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-eye"></i> Ver OC
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- ════════════════════════════════════════════════════════════════
                         ESTADO 5: realizado — Completado
                    ═══════════════════════════════════════════════════════════════════ -->
                    <?php else: ?>
                    <div class="cot-panel" style="border-color:#66bb6a;">
                        <h3 style="color:#2e7d32"><i class="fas fa-check-circle"></i> Proyecto completado</h3>
                        <p style="color:#555;font-size:14px;">Este proyecto fue completado. Todos los pedidos fueron recibidos en almacén.</p>
                        <?php if ($p['fecha_realizada']): ?>
                        <p style="color:#888;font-size:13px;">Fecha de cierre: <?php echo date('d/m/Y', strtotime($p['fecha_realizada'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    </div><!-- /erp-container -->
                    <?php } ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ===================== REQUISICIONES POR COMPRAR (sin proyecto) ===================== -->
        <?php if ($seccion === 'requisiciones_compra'): ?>
            <div class="section-content" style="background: white;">
                <?php if (!$detalle_id): ?>
                    <!-- LISTA -->
                    <div class="sticky-container" style="border-bottom: 1px solid #eee;">
                        <div class="header-row">
                            <h2><i class="fas fa-file-invoice-dollar"></i> Requisiciones por Comprar</h2>
                        </div>
                    </div>
                    <div class="content-panel" style="background: white;">
                        <?php
                        // No se quitan de la lista al completarse -- igual que "Gestión de Proyectos",
                        // se quedan como historial con su badge final y el total gastado.
                        $reqs_por_comprar = $db->query("
                            SELECT r.*,
                                COALESCE((SELECT SUM(oc.total) FROM ordenes_compra oc WHERE oc.requisicion_id = r.id AND oc.estado != 'rechazada'), 0) AS costo_total
                            FROM requisiciones r
                            WHERE r.estado_compra IS NOT NULL
                               OR EXISTS (
                                  SELECT 1 FROM requisicion_detalle rd LEFT JOIN materiales m ON m.id = rd.material_id
                                  WHERE rd.requisicion_id = r.id AND (rd.cantidad - rd.cantidad_entregada) > 0
                                    AND (rd.material_id IS NULL OR m.stock_disponible < (rd.cantidad - rd.cantidad_entregada))
                               )
                            ORDER BY (r.estado = 'completada'), r.fecha_creacion DESC
                        ")->fetchAll();

                        $badges_compra = [
                            null                  => ['border'=>'#ffc107','header'=>'#ffc107','text'=>'#333',  'icon'=>'fa-clock',        'label'=>'Por cotizar'],
                            'cotizacion_enviada'   => ['border'=>'#7b1fa2','header'=>'#7b1fa2','text'=>'white', 'icon'=>'fa-paper-plane',  'label'=>'Cot. Enviada'],
                            'cotizacion_recibida'  => ['border'=>'#0097a7','header'=>'#0097a7','text'=>'white', 'icon'=>'fa-file-invoice', 'label'=>'Cot. Recibida'],
                            'orden_generada'       => ['border'=>'#e64a19','header'=>'#e64a19','text'=>'white', 'icon'=>'fa-truck',        'label'=>'Pedido en Camino'],
                            'recepcion_parcial'    => ['border'=>'#f57c00','header'=>'#f57c00','text'=>'white', 'icon'=>'fa-exclamation',  'label'=>'Recep. Parcial'],
                            'recibido'             => ['border'=>'#003d5c','header'=>'#003d5c','text'=>'white', 'icon'=>'fa-check-circle', 'label'=>'Recibido'],
                        ];
                        $badge_completada = ['border'=>'#003d5c','header'=>'#003d5c','text'=>'white', 'icon'=>'fa-check-circle', 'label'=>'Completada'];
                        foreach ($reqs_por_comprar as $rq):
                            $bc = $rq['estado'] === 'completada' ? $badge_completada : ($badges_compra[$rq['estado_compra']] ?? $badges_compra[null]);
                        ?>
                        <div class="proyecto-costo"
                            onclick="window.location.href='?seccion=requisiciones_compra&detalle_id=<?= (int)$rq['id'] ?>'"
                            style="border: 1px solid <?= $bc['border'] ?>;">
                            <div style="background: <?= $bc['header'] ?>; color: <?= $bc['text'] ?>; padding: 15px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                                        <strong><i class="fas fa-file-alt"></i> <?= htmlspecialchars($rq['folio']) ?></strong>
                                        <span class="badge-estado" style="background:rgba(255,255,255,.25);padding:2px 10px;border-radius:20px;font-size:11px;"><i class="fas <?= $bc['icon'] ?>"></i> <?= $bc['label'] ?></span>
                                    </div>
                                    <small style="opacity: 0.85;"><?= htmlspecialchars($rq['area'] ?? '—') ?> · <?= htmlspecialchars($rq['solicitante'] ?? '—') ?> · Creada: <?= date('d/m/Y', strtotime($rq['fecha_creacion'])) ?></small>
                                </div>
                                <?php if ($rq['costo_total'] > 0): ?>
                                <div style="background: white; color: <?= $bc['border'] ?>; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                    $<?= number_format($rq['costo_total'], 2) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($reqs_por_comprar)): ?>
                            <div style="text-align: center; padding: 60px; color: #999;">
                                <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
                                <p>No hay requisiciones pendientes de comprar.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- DETALLE -->
                    <?php
                    $stmt = $db->prepare("SELECT * FROM requisiciones WHERE id = ?");
                    $stmt->execute([$detalle_id]);
                    $req_compra = $stmt->fetch();

                    if (!$req_compra) {
                        echo '<div class="alert alert-error" style="margin: 20px;">Requisición no encontrada.</div>';
                    } else {
                        marcarRequisicionVistaSiNueva($db, $detalle_id, (int) $req_compra['usuario_id']);
                        $stmt_it = $db->prepare("SELECT rd.*, (rd.cantidad - rd.cantidad_entregada) AS pendiente, m.unidad_medida AS mat_unidad,
                                COALESCE((SELECT SUM(ocd.cantidad) FROM orden_compra_detalle ocd
                                          JOIN ordenes_compra oc2 ON oc2.id = ocd.orden_id
                                          WHERE oc2.requisicion_id = rd.requisicion_id AND oc2.estado != 'rechazada'
                                            AND ocd.material_id = rd.material_id), 0) AS ya_ordenado
                            FROM requisicion_detalle rd LEFT JOIN materiales m ON m.id = rd.material_id
                            WHERE rd.requisicion_id = ? AND (rd.cantidad - rd.cantidad_entregada) > 0
                            ORDER BY rd.item_numero");
                        $stmt_it->execute([$detalle_id]);
                        $items_pendientes_rc = $stmt_it->fetchAll();

                        $stmt_cots = $db->prepare("SELECT c.*, pv.nombre as prov_nombre FROM cotizaciones c JOIN proveedores pv ON c.proveedor_id = pv.id WHERE c.requisicion_id = ? ORDER BY c.fecha_solicitud DESC");
                        $stmt_cots->execute([$detalle_id]);
                        $cotizaciones_rc = $stmt_cots->fetchAll();

                        $stmt_ocs_rc = $db->prepare("SELECT oc.*, pv.nombre as prov_nombre FROM ordenes_compra oc LEFT JOIN proveedores pv ON pv.id = oc.proveedor_id WHERE oc.requisicion_id = ? ORDER BY oc.fecha_creacion DESC");
                        $stmt_ocs_rc->execute([$detalle_id]);
                        $ordenes_rc = $stmt_ocs_rc->fetchAll();

                        $badges_compra = [
                            null                  => ['border'=>'#ffc107','header'=>'#ffc107','text'=>'#333',  'icon'=>'fa-clock',        'label'=>'Por cotizar'],
                            'cotizacion_enviada'   => ['border'=>'#7b1fa2','header'=>'#7b1fa2','text'=>'white', 'icon'=>'fa-paper-plane',  'label'=>'Cot. Enviada'],
                            'cotizacion_recibida'  => ['border'=>'#0097a7','header'=>'#0097a7','text'=>'white', 'icon'=>'fa-file-invoice', 'label'=>'Cot. Recibida'],
                            'orden_generada'       => ['border'=>'#e64a19','header'=>'#e64a19','text'=>'white', 'icon'=>'fa-truck',        'label'=>'Pedido en Camino'],
                            'recepcion_parcial'    => ['border'=>'#f57c00','header'=>'#f57c00','text'=>'white', 'icon'=>'fa-exclamation',  'label'=>'Recep. Parcial'],
                            'recibido'             => ['border'=>'#003d5c','header'=>'#003d5c','text'=>'white', 'icon'=>'fa-check-circle', 'label'=>'Recibido'],
                        ];
                        $bc = $req_compra['estado'] === 'completada'
                            ? ['border'=>'#003d5c','header'=>'#003d5c','text'=>'white', 'icon'=>'fa-check-circle', 'label'=>'Completada']
                            : ($badges_compra[$req_compra['estado_compra']] ?? $badges_compra[null]);
                    ?>
                    <div class="sticky-container" style="border-bottom: 1px solid #eee;">
                        <div class="header-row">
                            <h2>
                                <span onclick="window.location.href='?seccion=requisiciones_compra'" style="cursor:pointer; color: #003d5c;">
                                    <i class="fas fa-file-invoice-dollar"></i> Requisiciones
                                </span>
                                <span style="font-weight: 300; font-size: 1.2rem; color: #888;">/</span>
                                <?= htmlspecialchars($req_compra['folio']) ?>
                                <span class="badge-estado" style="background:<?= $bc['header'] ?>;color:<?= $bc['text'] ?>;padding:2px 10px;border-radius:20px;font-size:12px;"><i class="fas <?= $bc['icon'] ?>"></i> <?= $bc['label'] ?></span>
                            </h2>
                            <div class="action-buttons">
                                <button class="btn btn-outline" onclick="window.location.href='?seccion=requisiciones_compra'"><i class="fas fa-arrow-left"></i> Volver</button>
                            </div>
                        </div>
                    </div>

                    <div style="padding:20px;">
                        <div style="font-size:13px;color:#555;margin-bottom:16px;">
                            <strong>Área:</strong> <?= htmlspecialchars($req_compra['area'] ?? '—') ?> &nbsp;|&nbsp;
                            <strong>Solicitante:</strong> <?= htmlspecialchars($req_compra['solicitante'] ?? '—') ?>
                        </div>

                        <h3 style="margin-bottom:8px;"><i class="fas fa-list"></i> Ítems pendientes</h3>
                        <table style="width:100%;font-size:13px;margin-bottom:20px;">
                            <thead><tr><th>Descripción</th><th>Marca / Modelo</th><th>Pendiente</th><th>Ya ordenado (otra OC)</th></tr></thead>
                            <tbody>
                            <?php if (empty($items_pendientes_rc)): ?>
                                <tr><td colspan="4" style="text-align:center;color:#999;padding:14px;">Sin ítems pendientes.</td></tr>
                            <?php else: foreach ($items_pendientes_rc as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['descripcion'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars(trim(($it['marca'] ?? '') . ' ' . ($it['modelo'] ?? '')) ?: '—') ?></td>
                                    <td style="text-align:center;font-weight:700;"><?= $it['pendiente'] + 0 ?> <?= htmlspecialchars($it['unidad_medida'] ?? $it['mat_unidad'] ?? '') ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($it['ya_ordenado'] > 0): ?>
                                        <span style="color:#e65100;" title="Ya hay una OC pidiendo esta cantidad para esta requisición — verifica antes de volver a cotizar/comprar lo mismo."><?= $it['ya_ordenado'] + 0 ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>

                        <h3 style="margin-bottom:8px;"><i class="fas fa-paper-plane"></i> Solicitar cotización</h3>
                        <div style="border:1px solid #eee;border-radius:8px;padding:14px;margin-bottom:20px;">
                            <div id="provCheckListReq" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;max-height:180px;overflow-y:auto;margin-bottom:10px;">
                                <?php foreach ($proveedores as $pv): ?>
                                <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                    <input type="checkbox" name="prov_sel_req" value="<?= (int)$pv['id'] ?>">
                                    <?= htmlspecialchars($pv['nombre']) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <textarea id="msg_cotizacion_req" class="form-input" placeholder="Mensaje adicional (opcional)" style="margin-bottom:10px;"></textarea>
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-success" onclick="enviarSolicitudCotizacionReq(<?= (int)$detalle_id ?>)">
                                    <i class="fas fa-paper-plane"></i> Enviar solicitud de cotización
                                </button>
                                <button type="button" class="btn btn-outline" style="color:#555;border-color:#bbb;" onclick="saltarFaseCotizacionReq(<?= (int)$detalle_id ?>)" title="Si ya sabes el costo o no se requiere cotización formal, registra los proveedores sin enviar correo y captura el precio directamente.">
                                    <i class="fas fa-forward"></i> Saltar cotización
                                </button>
                            </div>
                        </div>

                        <h3 style="margin-bottom:8px;"><i class="fas fa-file-invoice"></i> Cotizaciones</h3>
                        <?php if (empty($cotizaciones_rc)): ?>
                        <div style="color:#999;font-size:13px;margin-bottom:20px;">Aún no se ha solicitado ninguna cotización.</div>
                        <?php else: foreach ($cotizaciones_rc as $cot): ?>
                        <div class="cot-panel" style="border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:14px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <div><strong><?= htmlspecialchars($cot['prov_nombre']) ?></strong> — <span style="font-size:12px;color:#666;"><?= ucfirst($cot['estado']) ?></span></div>
                                <small style="color:#888;"><?= date('d/m/Y', strtotime($cot['fecha_solicitud'])) ?></small>
                            </div>

                            <?php if ($cot['estado'] === 'pendiente'): ?>
                            <div class="cot-captura" data-cotid="<?= (int)$cot['id'] ?>" style="margin-top:10px;">
                                <table style="width:100%;font-size:13px;">
                                    <thead><tr><th>Descripción</th><th>Cantidad</th><th>Precio unitario</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($items_pendientes_rc as $it): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['descripcion'] ?? '—') ?></td>
                                        <td style="text-align:center;"><?= $it['pendiente'] + 0 ?> <?= htmlspecialchars($it['unidad_medida'] ?? $it['mat_unidad'] ?? '') ?></td>
                                        <td>
                                            <input type="number" class="form-input precio-input"
                                                data-desc="<?= htmlspecialchars($it['descripcion'] ?? '', ENT_QUOTES) ?>"
                                                data-cant="<?= $it['pendiente'] + 0 ?>"
                                                data-unidad="<?= htmlspecialchars($it['unidad_medida'] ?? $it['mat_unidad'] ?? 'pza', ENT_QUOTES) ?>"
                                                data-matid="<?= (int)($it['material_id'] ?? 0) ?>"
                                                min="0" step="0.01" style="width:100px;" placeholder="0.00">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-success btn-sm" style="margin-top:8px;" onclick="guardarCotizacionRecibidaReq(<?= (int)$cot['id'] ?>)">
                                    <i class="fas fa-save"></i> Guardar cotización recibida
                                </button>
                            </div>
                            <?php elseif ($cot['estado'] === 'recibida'):
                                $datos_ia_cot  = json_decode($cot['datos_ia'] ?? '', true);
                                $productos_cot = $datos_ia_cot['productos'] ?? [];
                                $total_cot     = array_sum(array_map(fn($p) => floatval($p['subtotal'] ?? 0), $productos_cot));
                            ?>
                            <div style="margin-top:10px;font-size:13px;display:flex;align-items:center;gap:14px;">
                                <span>Total cotizado: <strong>$<?= number_format($total_cot, 2) ?></strong></span>
                                <?php if (empty($cot['orden_generada_id'])): ?>
                                <button type="button" class="btn btn-success btn-sm"
                                    data-items='<?= htmlspecialchars(json_encode($productos_cot), ENT_QUOTES) ?>'
                                    onclick="generarOCReq(<?= (int)$detalle_id ?>, <?= (int)$cot['id'] ?>, this)">
                                    <i class="fas fa-file-invoice-dollar"></i> Generar Orden de Compra
                                </button>
                                <?php else: ?>
                                <span style="color:#2e7d32;"><i class="fas fa-check-circle"></i> Ya se generó una orden de compra de esta cotización (ver abajo).</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; endif; ?>

                        <h3 style="margin:20px 0 8px;"><i class="fas fa-truck"></i> Órdenes de compra</h3>
                        <?php if (empty($ordenes_rc)): ?>
                        <div style="color:#999;font-size:13px;">Aún no se ha generado ninguna orden de compra.</div>
                        <?php else: ?>
                        <table style="width:100%;font-size:13px;">
                            <thead><tr><th>Folio</th><th>Proveedor</th><th>Total</th><th>Estado</th><th>Calidad</th><th>Acciones</th></tr></thead>
                            <tbody>
                            <?php foreach ($ordenes_rc as $oc): ?>
                            <tr>
                                <td><?= htmlspecialchars($oc['folio']) ?></td>
                                <td><?= htmlspecialchars($oc['prov_nombre'] ?? '—') ?></td>
                                <td>$<?= number_format($oc['total'], 2) ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $oc['estado'])) ?></td>
                                <td><?= ucfirst($oc['calidad_aprobacion']) ?></td>
                                <td><a href="orden_compra.php?orden_id=<?= (int)$oc['id'] ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver OC</a></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="font-size:12px;color:#888;margin-top:8px;">Cuando la OC esté aprobada (y calidad la valide, si aplica), confírmala en Almacén → Surtir esta requisición.</div>
                        <?php endif; ?>
                    </div>
                    <?php } ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ===================== REGISTRO CONTABLE ===================== -->
        <?php if ($seccion === 'registro_contable'): ?>
            <?php
            // Un registro por proyecto, generado automáticamente al cierre total real
            // (proyectos.cierre_total=1, ver registrarCierreContable() en includes/config.php).
            // Si un proyecto ya cerró pero no aparece aquí, es porque cerró ANTES de que
            // existiera esta feature (2026-08-20) -- no hay backfill retroactivo.
            $registros_contables = $db->query("
                SELECT rc.*, p.nombre AS proyecto_nombre
                FROM proyecto_registro_contable rc
                JOIN proyectos p ON p.id = rc.proyecto_id
                ORDER BY rc.fecha_cierre DESC
            ")->fetchAll();
            $gasto_acumulado = array_sum(array_column($registros_contables, 'gasto_total'));
            ?>
            <div class="section-content">
                <div class="sticky-container">
                    <div class="header-row">
                        <h2><i class="fas fa-calculator"></i> Registro Contable</h2>
                        <div class="action-buttons">
                            <button class="btn btn-outline" onclick="window.location.href='?seccion='"><i class="fas fa-arrow-left"></i> Volver</button>
                        </div>
                    </div>
                </div>
                <div class="content-panel">
                    <?php if (empty($registros_contables)): ?>
                        <p style="text-align:center;color:#999;padding:30px;">Todavía no hay proyectos cerrados con registro contable.</p>
                    <?php else: ?>
                        <div style="display:flex;gap:14px;margin-bottom:18px;">
                            <div style="background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 2px 4px rgba(0,0,0,.08);">
                                <div style="font-size:22px;font-weight:800;color:#333;">$<?= number_format($gasto_acumulado, 2) ?></div>
                                <div style="font-size:11px;color:#777;text-transform:uppercase;">Gasto total acumulado</div>
                            </div>
                            <div style="background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 2px 4px rgba(0,0,0,.08);">
                                <div style="font-size:22px;font-weight:800;color:#333;"><?= count($registros_contables) ?></div>
                                <div style="font-size:11px;color:#777;text-transform:uppercase;">Proyectos con registro</div>
                            </div>
                        </div>
                        <p style="font-size:.75rem;color:#999;margin:-8px 0 12px;">Cuando una columna de tiempo muestra "—" es porque ese proyecto no tiene la fecha de inicio/fin real necesaria para calcularlo (ej. no se difundió Orden de Producción, o nunca entró material por Almacén-directo) -- se deja en blanco en vez de mostrar un número inventado.</p>
                        <table>
                            <thead>
                                <tr>
                                    <th>Proyecto</th><th>Gasto total</th><th>Horas regulares</th><th>Horas extra</th><th>OTs</th><th>T. Ingeniería</th><th>T. Almacén (req.)</th><th>T. Almacén (directo)</th><th>T. Calidad</th><th>T. Gerencia</th><th>Fecha de cierre</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $fmtHoras = function ($min) {
                                    $min = (int)$min;
                                    return $min > 0 ? round($min / 60, 1) . 'h' : '—';
                                };
                                foreach ($registros_contables as $rc): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($rc['proyecto_nombre']) ?></strong></td>
                                    <td>$<?= number_format((float)$rc['gasto_total'], 2) ?></td>
                                    <td><?= round((int)$rc['tiempo_regular_min'] / 60, 1) ?>h</td>
                                    <td><?= round((int)$rc['tiempo_extra_min'] / 60, 1) ?>h</td>
                                    <td><?= (int)$rc['total_ots'] ?></td>
                                    <td><?= $fmtHoras($rc['ingenieria_tiempo_min'] ?? 0) ?></td>
                                    <td><?= $fmtHoras($rc['almacen_tiempo_min'] ?? 0) ?></td>
                                    <td><?= $fmtHoras($rc['almacen_directo_tiempo_min'] ?? 0) ?></td>
                                    <td><?= $fmtHoras($rc['calidad_tiempo_min'] ?? 0) ?></td>
                                    <td><?= $fmtHoras($rc['gerencia_tiempo_min'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars(substr($rc['fecha_cierre'], 0, 16)) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===================== PROYECTOS REALIZADOS ===================== -->
        <?php if ($seccion === 'realizados'): ?>
            <?php
            $proyectos_realizados = $db->query("SELECT p.*, u.nombre_completo as creador_nombre FROM proyectos p LEFT JOIN usuarios u ON p.creado_por = u.id WHERE p.realizada = 1 ORDER BY p.fecha_realizada DESC")->fetchAll();
            $items_por_proyecto_realizado = [];
            $proyectos_costos_r = [];
            $costo_total_general = 0;

            // Costo + ítems de detalle corrían con 2 queries POR proyecto dentro de este loop
            // (N+1) -- se traen aquí de una sola vez, agrupados en PHP, mismo patrón usado en
            // el resto de la auditoría (ej. sección proyectos_costos de este mismo archivo).
            $pid_list_r = array_column($proyectos_realizados, 'id');
            if ($pid_list_r) {
                $nombre_list_r = array_column($proyectos_realizados, 'nombre');
                $nombre_a_pid_r = [];
                foreach ($proyectos_realizados as $pp) { $nombre_a_pid_r[$pp['nombre']] = $pp['id']; }
                $phPidR = implode(',', array_fill(0, count($pid_list_r), '?'));
                $phNomR = implode(',', array_fill(0, count($nombre_list_r), '?'));

                $stmtCostoR = $db->prepare(
                    "SELECT oc.proyecto_id, oc.total, r.proyecto_id AS req_proyecto_id, r.proyecto AS req_proyecto_nombre
                     FROM ordenes_compra oc
                     LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
                     WHERE oc.proyecto_id IN ($phPidR) OR r.proyecto_id IN ($phPidR) OR r.proyecto IN ($phNomR)"
                );
                $stmtCostoR->execute(array_merge($pid_list_r, $pid_list_r, $nombre_list_r));
                foreach ($stmtCostoR->fetchAll() as $row) {
                    $matchIds = [];
                    if ($row['proyecto_id'] && in_array($row['proyecto_id'], $pid_list_r)) $matchIds[] = (int)$row['proyecto_id'];
                    if ($row['req_proyecto_id'] && in_array($row['req_proyecto_id'], $pid_list_r)) $matchIds[] = (int)$row['req_proyecto_id'];
                    if ($row['req_proyecto_nombre'] && isset($nombre_a_pid_r[$row['req_proyecto_nombre']])) $matchIds[] = (int)$nombre_a_pid_r[$row['req_proyecto_nombre']];
                    foreach (array_unique($matchIds) as $mid) {
                        $proyectos_costos_r[$mid] = ($proyectos_costos_r[$mid] ?? 0) + floatval($row['total']);
                    }
                }

                $stmtItemsR = $db->prepare(
                    "SELECT ocd.descripcion as item, ocd.descripcion, ocd.cantidad,
                            ocd.precio_unitario, ocd.subtotal, oc.folio,
                            oc.proyecto_id, r.proyecto_id AS req_proyecto_id, r.proyecto AS req_proyecto_nombre
                     FROM orden_compra_detalle ocd
                     JOIN ordenes_compra oc ON ocd.orden_id = oc.id
                     LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
                     WHERE oc.proyecto_id IN ($phPidR) OR r.proyecto_id IN ($phPidR) OR r.proyecto IN ($phNomR)
                     ORDER BY oc.id, ocd.item_numero"
                );
                $stmtItemsR->execute(array_merge($pid_list_r, $pid_list_r, $nombre_list_r));
                foreach ($stmtItemsR->fetchAll() as $row) {
                    $matchIds = [];
                    if ($row['proyecto_id'] && in_array($row['proyecto_id'], $pid_list_r)) $matchIds[] = (int)$row['proyecto_id'];
                    if ($row['req_proyecto_id'] && in_array($row['req_proyecto_id'], $pid_list_r)) $matchIds[] = (int)$row['req_proyecto_id'];
                    if ($row['req_proyecto_nombre'] && isset($nombre_a_pid_r[$row['req_proyecto_nombre']])) $matchIds[] = (int)$nombre_a_pid_r[$row['req_proyecto_nombre']];
                    foreach (array_unique($matchIds) as $mid) {
                        $items_por_proyecto_realizado[$mid][] = $row;
                    }
                }
            }

            foreach ($proyectos_realizados as $proy) {
                $costo_total_general += $proyectos_costos_r[$proy['id']] ?? 0;
            }
            ?>
            <div class="section-content">
                <div class="sticky-container">
                    <div class="header-row">
                        <h2><i class="fas fa-history"></i> Proyectos Realizados</h2>
                        <div class="action-buttons">
                            <div class="search-wrapper">
                                <input type="text" class="search-input" id="searchRealizados" placeholder="Buscar proyecto...">
                                <button class="search-clear" id="clearSearchRealizados" onclick="clearSearch('realizados')"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="content-panel" id="realizadosContent">
                    <?php if (empty($proyectos_realizados)): ?>
                        <div style="text-align: center; padding: 60px; color: #999;">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>No hay proyectos realizados</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($proyectos_realizados as $proy):
                            $items = $items_por_proyecto_realizado[$proy['id']] ?? [];
                            $costo_proyecto = $proyectos_costos_r[$proy['id']] ?? 0;
                        ?>
                            <div class="boom-guardado" data-nombre="<?php echo strtolower(htmlspecialchars($proy['nombre'])); ?>" style="margin-bottom: 20px; border: 1px solid #003d5c; border-radius: 4px; overflow: hidden;">
                                <div style="background: #003d5c; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <div style="font-size: 16px;"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($proy['nombre']); ?></div>
                                        <div style="font-size: 12px; opacity: 0.9;">Realizado: <?php echo date('d/m/Y', strtotime($proy['fecha_realizada'])); ?></div>
                                    </div>
                                    <span style="background: white; color: #003d5c; padding: 8px 15px; border-radius: 30px; font-weight: bold;">$<?php echo number_format($costo_proyecto, 2); ?></span>
                                </div>
                                <?php if (!empty($items)): ?>
                                    <table style="width: 100%;">
                                        <thead style="background: #f5f5f5;">
                                            <tr>
                                                <th>Item</th><th>Descripción</th><th>Cantidad</th><th>Precio Unit.</th><th>Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($items as $item):
                                                $cantidad = floatval($item['cantidad'] ?? 0);
                                                $precio   = floatval($item['precio_unitario'] ?? 0);
                                                $subtotal = floatval($item['subtotal'] ?? ($cantidad * $precio));
                                            ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($item['item']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($item['descripcion'] ?: '-'); ?></td>
                                                    <td><?php echo $cantidad; ?></td>
                                                    <td>$<?php echo number_format($precio, 2); ?></td>
                                                    <td>$<?php echo number_format($subtotal, 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot style="background: #e8f5e9; font-weight: bold;">
                                            <tr><td colspan="4" style="text-align: right;">TOTAL:</td><td>$<?php echo number_format($costo_proyecto, 2); ?></td></tr>
                                        </tfoot>
                                    </table>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div style="margin-top: 30px; padding: 20px; background: linear-gradient(135deg, #003d5c, #005686); border-radius: 12px; color: white; text-align: center;">
                            <div style="font-size: 14px; text-transform: uppercase; letter-spacing: 2px; opacity: 0.8;">Costo Total de Proyectos Realizados</div>
                            <div style="font-size: 32px; font-weight: 800; margin-top: 5px;"><i class="fas fa-chart-line"></i> $<?php echo number_format($costo_total_general, 2); ?></div>
                            <div style="font-size: 13px; margin-top: 10px; opacity: 0.7;"><?php echo count($proyectos_realizados); ?> proyectos realizados</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <script>
                const inputR = document.getElementById('searchRealizados');
                const clearR = document.getElementById('clearSearchRealizados');
                if (inputR) {
                    inputR.addEventListener('input', function() {
                        if (this.value.length > 0) clearR.classList.add('visible');
                        else clearR.classList.remove('visible');
                        const t = this.value.toLowerCase();
                        document.querySelectorAll('#realizadosContent .boom-guardado').forEach(el => {
                            el.style.display = el.dataset.nombre.includes(t) ? '' : 'none';
                        });
                    });
                }
            </script>
        <?php endif; ?>

        <!-- ===================== CALENDARIO ===================== -->
        <?php if ($seccion === 'calendario'): ?>
            <?php
            $ordenes_calendario = $db->query("SELECT oc.*, p.nombre as proyecto_nombre, p.id as proyecto_id, prov.nombre as proveedor_nombre FROM ordenes_compra oc INNER JOIN requisiciones r ON oc.requisicion_id = r.id INNER JOIN proyectos p ON r.proyecto = p.nombre LEFT JOIN proveedores prov ON oc.proveedor_id = prov.id WHERE oc.fecha_entrega_estimada IS NOT NULL ORDER BY oc.fecha_entrega_estimada ASC")->fetchAll();
            $eventos_por_fecha = [];
            foreach ($ordenes_calendario as $orden) {
                $fecha_entrega = date('Y-m-d', strtotime($orden['fecha_entrega_estimada']));
                $eventos_por_fecha[$fecha_entrega][] = [
                    'id' => $orden['id'], 'proyecto_id' => $orden['proyecto_id'],
                    'proyecto_nombre' => $orden['proyecto_nombre'],
                    'proveedor_nombre' => $orden['proveedor_nombre'],
                    'fecha_entrega' => $orden['fecha_entrega_estimada'],
                    'costo' => floatval($orden['total'] ?? 0)
                ];
            }
            $vista = isset($_GET['vista']) ? $_GET['vista'] : 'mes';
            $fecha_navegacion = isset($_GET['fecha']) ? new DateTime($_GET['fecha']) : new DateTime();
            ?>
            <div class="section-content">
                <div class="sticky-container">
                    <div class="header-row">
                        <h2><i class="fas fa-calendar-alt"></i> Calendario de Órdenes</h2>
                        <div class="action-buttons">
                            <div style="display: flex; gap: 10px; background: white; border-radius: 40px; padding: 5px; border: 1px solid #e0e0e0;">
                                <button class="btn-vista <?php echo $vista == 'semana' ? 'active' : ''; ?>" onclick="cambiarVista('semana')">Semana</button>
                                <button class="btn-vista <?php echo $vista == 'mes' ? 'active' : ''; ?>" onclick="cambiarVista('mes')">Mes</button>
                                <button class="btn-vista <?php echo $vista == 'ano' ? 'active' : ''; ?>" onclick="cambiarVista('ano')">Año</button>
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <button class="btn-outline" onclick="navegarFecha(-1)"><i class="fas fa-chevron-left"></i></button>
                                <span id="fechaActual" style="font-weight: 600; min-width: 200px; text-align: center;"></span>
                                <button class="btn-outline" onclick="navegarFecha(1)"><i class="fas fa-chevron-right"></i></button>
                                <button class="btn btn-primary" onclick="irHoy()">Hoy</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="content-panel" id="calendarioContent">
                    <div id="vistaSemana" style="display: <?php echo $vista == 'semana' ? 'block' : 'none'; ?>;"><div style="background: white; border-radius: 8px; overflow-x: auto;"><table style="width: 100%; border-collapse: collapse;"><thead><tr style="background: #003d5c; color: white;"><th style="padding: 12px; text-align: center; min-width: 100px;">Hora / Día</th><th style="padding: 12px; text-align: center;">Lunes</th><th style="padding: 12px; text-align: center;">Martes</th><th style="padding: 12px; text-align: center;">Miércoles</th><th style="padding: 12px; text-align: center;">Jueves</th><th style="padding: 12px; text-align: center;">Viernes</th><th style="padding: 12px; text-align: center;">Sábado</th><th style="padding: 12px; text-align: center;">Domingo</th></tr></thead><tbody id="semanaBody"></tbody></table></div></div>
                    <div id="vistaMes" style="display: <?php echo $vista == 'mes' ? 'block' : 'none'; ?>;"><div style="background: white; border-radius: 8px; overflow-x: auto;"><table style="width: 100%; border-collapse: collapse;"><thead><tr style="background: #003d5c; color: white;"><th style="padding: 12px; text-align: center;">Dom</th><th style="padding: 12px; text-align: center;">Lun</th><th style="padding: 12px; text-align: center;">Mar</th><th style="padding: 12px; text-align: center;">Mié</th><th style="padding: 12px; text-align: center;">Jue</th><th style="padding: 12px; text-align: center;">Vie</th><th style="padding: 12px; text-align: center;">Sáb</th></tr></thead><tbody id="mesBody"></tbody></table></div></div>
                    <div id="vistaAno" style="display: <?php echo $vista == 'ano' ? 'block' : 'none'; ?>;"><div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;" id="anoBody"></div></div>
                </div>
            </div>
            <script>
                const eventosCalendario = <?php echo json_encode($eventos_por_fecha); ?>;
                let vistaActual = '<?php echo $vista; ?>';
                let fechaActual = new Date('<?php echo $fecha_navegacion->format('Y-m-d'); ?>');
                function cambiarVista(v) { vistaActual = v; document.getElementById('vistaSemana').style.display = v==='semana'?'block':'none'; document.getElementById('vistaMes').style.display = v==='mes'?'block':'none'; document.getElementById('vistaAno').style.display = v==='ano'?'block':'none'; document.querySelectorAll('.btn-vista').forEach(b=>b.classList.remove('active')); if(v==='semana') document.querySelector('.btn-vista').classList.add('active'); else if(v==='mes') document.querySelectorAll('.btn-vista')[1].classList.add('active'); else document.querySelectorAll('.btn-vista')[2].classList.add('active'); renderizarCalendario(); }
                function navegarFecha(d) { if(vistaActual==='semana') fechaActual.setDate(fechaActual.getDate()+(d*7)); else if(vistaActual==='mes') fechaActual.setMonth(fechaActual.getMonth()+d); else fechaActual.setFullYear(fechaActual.getFullYear()+d); renderizarCalendario(); }
                function irHoy() { fechaActual = new Date(); renderizarCalendario(); }
                function renderizarCalendario() { document.getElementById('fechaActual').textContent = fechaActual.toLocaleDateString('es-ES', {year:'numeric',month:'long',day:'numeric'}); if(vistaActual==='semana') renderizarSemana(); else if(vistaActual==='mes') renderizarMes(); else renderizarAno(); }
                function renderizarSemana() { const i = new Date(fechaActual); i.setDate(fechaActual.getDate()-fechaActual.getDay()+(fechaActual.getDay()===0?-6:1)); const horas = ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00']; let html=''; for(let h of horas) { html+='<tr><td style="padding:8px;border:1px solid #e0e0e0;background:#f5f5f5;font-weight:bold;">'+h+'</td>'; for(let d=0;d<7;d++){let f=new Date(i);f.setDate(i.getDate()+d);let fk=f.toISOString().split('T')[0];let ev=eventosCalendario[fk]||[];let eh='';ev.forEach(e=>{eh+=`<div class="evento-calendario" onclick="verDetalleOrden(${e.id},'${e.proyecto_nombre}','${e.fecha_entrega}',${e.costo},'${e.proveedor_nombre}')">${e.proyecto_nombre}</div>`});html+=`<td style="padding:4px;border:1px solid #e0e0e0;vertical-align:top;">${eh}</td>`}html+='</tr>'} document.getElementById('semanaBody').innerHTML=html; }
                function renderizarMes() { const año=fechaActual.getFullYear();const mes=fechaActual.getMonth();const primer=new Date(año,mes,1);const ultimo=new Date(año,mes+1,0);const ini=new Date(primer);ini.setDate(primer.getDate()-primer.getDay());let html='';let dia=new Date(ini);let hoy=new Date();hoy.setHours(0,0,0,0);while(dia<=ultimo||dia.getDay()!==0){html+='<tr>';for(let i=0;i<7;i++){let fk=dia.toISOString().split('T')[0];let ev=eventosCalendario[fk]||[];let esM=dia.getMonth()===mes;let esH=dia.toDateString()===hoy.toDateString();let eh='';ev.forEach(e=>{eh+=`<div class="evento-calendario" onclick="verDetalleOrden(${e.id},'${e.proyecto_nombre}','${e.fecha_entrega}',${e.costo},'${e.proveedor_nombre}')">${e.proyecto_nombre}</div>`});html+=`<td class="dia-celda ${esH?'dia-actual':''}" style="${!esM?'opacity:0.5;':''}"><div class="dia-numero">${dia.getDate()}</div>${eh}</td>`;dia.setDate(dia.getDate()+1)}html+='</tr>'}document.getElementById('mesBody').innerHTML=html; }
                function renderizarAno() { const año=fechaActual.getFullYear();const meses=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];let html='';for(let m=0;m<12;m++){let evs=[];for(let d=1;d<=31;d++){let f=new Date(año,m,d);if(f.getMonth()!==m)continue;let fk=f.toISOString().split('T')[0];if(eventosCalendario[fk])evs.push(...eventosCalendario[fk])}let eh='';evs.slice(0,3).forEach(e=>{eh+=`<div class="evento-calendario" style="font-size:10px;" onclick="verDetalleOrden(${e.id},'${e.proyecto_nombre}','${e.fecha_entrega}',${e.costo},'${e.proveedor_nombre}')">${e.proyecto_nombre}</div>`});if(evs.length>3)eh+=`<div style="font-size:10px;color:#666;">+${evs.length-3} más</div>`;html+=`<div style="background:white;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);"><div style="background:#003d5c;color:white;padding:10px;font-weight:bold;text-align:center;">${meses[m]}</div><div style="padding:10px;min-height:150px;">${eh||'<div style="color:#999;text-align:center;padding:20px;">Sin eventos</div>'}</div></div>`}document.getElementById('anoBody').innerHTML=html; }
                function verDetalleOrden(id,proyecto,fechaEntrega,costo,proveedor) { const m=`<div class="modal-overlay active" id="modalDetalleOrden" style="display:flex;"><div class="modal" style="max-width:450px;"><div class="modal-header"><div class="modal-title"><i class="fas fa-file-invoice"></i> ${proyecto}</div><button class="modal-close" onclick="document.getElementById('modalDetalleOrden').remove()">&times;</button></div><div class="modal-body"><div style="margin-bottom:15px;"><strong><i class="fas fa-calendar"></i> Fecha de entrega:</strong><br>${new Date(fechaEntrega).toLocaleDateString('es-ES',{year:'numeric',month:'long',day:'numeric'})}</div><div style="margin-bottom:15px;"><strong><i class="fas fa-dollar-sign"></i> Costo:</strong><br>$${Number(costo).toLocaleString('es-MX',{minimumFractionDigits:2})}</div><div><strong><i class="fas fa-truck"></i> Proveedor:</strong><br>${proveedor||'No especificado'}</div></div><div class="modal-footer"><button class="btn btn-outline" onclick="document.getElementById('modalDetalleOrden').remove()">Cerrar</button></div></div></div>`; document.body.insertAdjacentHTML('beforeend',m); }
            renderizarCalendario();
            </script>
        <?php endif; ?>

        <!-- ===================== REPORTES POR CATEGORÍA ===================== -->
        <?php if ($seccion === 'reportes_categoria'): ?>
        <?php
        // Costo total por categoría (de órdenes de compra completadas/aprobadas)
        $costo_cat = $db->query("
            SELECT c.nombre AS categoria, c.icono, SUM(ocd.cantidad * ocd.precio_unitario) AS total
            FROM orden_compra_detalle ocd
            JOIN ordenes_compra oc ON oc.id = ocd.orden_id
            JOIN materiales m ON ocd.material_id = m.id
            JOIN categorias c ON m.categoria_id = c.id
            WHERE oc.estado IN ('aprobada')
            GROUP BY c.id, c.nombre, c.icono
            ORDER BY total DESC
        ")->fetchAll();

        // Costo por proyecto y por categoría
        $costo_proy_cat = $db->query("
            SELECT p.nombre AS proyecto, c.nombre AS categoria, SUM(ocd.cantidad * ocd.precio_unitario) AS total
            FROM orden_compra_detalle ocd
            JOIN ordenes_compra oc ON oc.id = ocd.orden_id
            JOIN requisiciones r ON oc.requisicion_id = r.id
            JOIN proyectos p ON r.proyecto = p.nombre
            JOIN materiales m ON ocd.material_id = m.id
            JOIN categorias c ON m.categoria_id = c.id
            WHERE oc.estado IN ('aprobada')
            GROUP BY p.nombre, c.nombre
            ORDER BY p.nombre, total DESC
        ")->fetchAll();

        // Agrupar por proyecto
        $proy_cat_map = [];
        $all_cats_rep  = [];
        foreach ($costo_proy_cat as $r) {
            $proy_cat_map[$r['proyecto']][$r['categoria']] = $r['total'];
            $all_cats_rep[$r['categoria']] = true;
        }
        $all_cats_rep = array_keys($all_cats_rep);

        // Confiabilidad proveedores para tabla
        $prov_conf_list = $db->query("
            SELECT p.id, p.nombre, p.email,
                   COUNT(pi.id) as total_incidencias,
                   SUM(CASE pi.tipo WHEN 'llegada_tardia' THEN 5 WHEN 'entrega_incompleta' THEN 15 WHEN 'mal_estado' THEN 20 ELSE 5 END) as descuento
            FROM proveedores p
            LEFT JOIN proveedor_incidencias pi ON pi.proveedor_id = p.id
            WHERE p.activo = 1
            GROUP BY p.id, p.nombre, p.email
            ORDER BY descuento DESC, p.nombre ASC
        ")->fetchAll();
        ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-chart-pie"></i> Reportes de Costos por Categoría</h2>
                    <button class="btn btn-outline" onclick="location.href='?seccion='"><i class="fas fa-arrow-left"></i> Volver</button>
                </div>
            </div>
            <div style="padding-bottom:20px;">

                <?php if (empty($costo_cat)): ?>
                <div style="text-align:center;padding:40px;color:#bbb;">
                    <i class="fas fa-chart-pie" style="font-size:2.5rem;display:block;margin-bottom:10px;"></i>
                    <p>No hay órdenes de compra aprobadas con datos de categoría.</p>
                </div>
                <?php else: ?>

                <!-- Gráfica de pastel + tabla resumen -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
                    <div style="background:#fafcff;border:1px solid #e0e8f0;border-radius:12px;padding:20px;">
                        <div style="font-size:.9rem;font-weight:700;color:#003d5c;margin-bottom:12px;"><i class="fas fa-chart-pie"></i> Distribución por Categoría</div>
                        <canvas id="chartCategoriaPie" style="max-height:300px;"></canvas>
                    </div>
                    <div style="background:#fafcff;border:1px solid #e0e8f0;border-radius:12px;padding:20px;">
                        <div style="font-size:.9rem;font-weight:700;color:#003d5c;margin-bottom:12px;"><i class="fas fa-table"></i> Totales por Categoría</div>
                        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                            <thead><tr><th style="text-align:left;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Categoría</th><th style="text-align:right;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Total</th></tr></thead>
                            <tbody>
                            <?php $gran_total = 0; foreach ($costo_cat as $cc): $gran_total += $cc['total']; ?>
                            <tr>
                                <td style="padding:6px 8px;border-bottom:1px solid #f0f0f0;"><i class="fas fa-<?= htmlspecialchars($cc['icono'] ?? 'box') ?>"></i> <?= htmlspecialchars($cc['categoria']) ?></td>
                                <td style="padding:6px 8px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600;">$<?= number_format($cc['total'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background:#e8f5e9;"><td style="padding:8px;font-weight:700;color:#2E7D32;">Total general</td><td style="padding:8px;font-weight:700;color:#2E7D32;text-align:right;">$<?= number_format($gran_total,2) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Gráfica de barras por proyecto y categoría -->
                <?php if (!empty($proy_cat_map)): ?>
                <div style="background:#fafcff;border:1px solid #e0e8f0;border-radius:12px;padding:20px;margin-bottom:24px;">
                    <div style="font-size:.9rem;font-weight:700;color:#003d5c;margin-bottom:12px;"><i class="fas fa-chart-bar"></i> Comparativa por Proyecto y Categoría</div>
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:.8rem;min-width:600px;">
                            <thead>
                                <tr>
                                    <th style="text-align:left;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Proyecto</th>
                                    <?php foreach ($all_cats_rep as $cat_name): ?>
                                    <th style="text-align:right;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;"><?= htmlspecialchars($cat_name) ?></th>
                                    <?php endforeach; ?>
                                    <th style="text-align:right;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($proy_cat_map as $pnm => $cats):
                                $row_total = array_sum($cats);
                            ?>
                            <tr style="border-bottom:1px solid #f0f0f0;">
                                <td style="padding:6px 8px;font-weight:600;color:#003d5c;"><?= htmlspecialchars($pnm) ?></td>
                                <?php foreach ($all_cats_rep as $cat_name): ?>
                                <td style="padding:6px 8px;text-align:right;"><?= isset($cats[$cat_name]) ? '$'.number_format($cats[$cat_name],2) : '—' ?></td>
                                <?php endforeach; ?>
                                <td style="padding:6px 8px;text-align:right;font-weight:700;color:#003d5c;">$<?= number_format($row_total,2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Tabla de confiabilidad de proveedores -->
                <div style="background:#fafcff;border:1px solid #e0e8f0;border-radius:12px;padding:20px;">
                    <div style="font-size:.9rem;font-weight:700;color:#003d5c;margin-bottom:12px;"><i class="fas fa-star-half-alt"></i> Índice de Confiabilidad de Proveedores</div>
                    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                        <thead><tr>
                            <th style="text-align:left;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Proveedor</th>
                            <th style="text-align:center;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Incidencias</th>
                            <th style="text-align:center;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Confiabilidad</th>
                            <th style="text-align:left;padding:6px 8px;background:#f0f4f8;color:#003d5c;border-bottom:2px solid #dde;font-weight:700;">Estado</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($prov_conf_list as $pc):
                            $conf = max(0, 100 - intval($pc['descuento'] ?? 0));
                            $conf_color = $conf >= 80 ? '#2E7D32' : ($conf >= 60 ? '#e65100' : '#b71c1c');
                            $conf_label = $conf >= 80 ? 'Buena' : ($conf >= 60 ? 'Regular' : 'Baja');
                        ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:6px 8px;font-weight:600;"><?= htmlspecialchars($pc['nombre']) ?></td>
                            <td style="padding:6px 8px;text-align:center;"><?= $pc['total_incidencias'] ?></td>
                            <td style="padding:6px 8px;text-align:center;">
                                <div style="background:#e0e0e0;border-radius:10px;height:8px;width:100px;display:inline-block;vertical-align:middle;margin-right:6px;">
                                    <div style="width:<?= $conf ?>%;height:100%;border-radius:10px;background:<?= $conf_color ?>;"></div>
                                </div>
                                <strong style="color:<?= $conf_color ?>;"><?= $conf ?>%</strong>
                            </td>
                            <td style="padding:6px 8px;"><span style="background:<?= $conf >= 80 ? '#e8f5e9' : ($conf >= 60 ? '#fff3e0' : '#fce4ec') ?>;color:<?= $conf_color ?>;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;"><?= $conf_label ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const catData = <?= json_encode(array_map(fn($c) => ['label'=>$c['categoria'],'value'=>(float)$c['total']], $costo_cat ?? [])) ?>;
            if (catData.length > 0 && document.getElementById('chartCategoriaPie')) {
                const colors = ['#005686','#FF9800','#2E7D32','#7b1fa2','#e65100','#1565c0','#f57f17','#00695c','#d32f2f','#455a64'];
                new Chart(document.getElementById('chartCategoriaPie'), {
                    type: 'doughnut',
                    data: {
                        labels: catData.map(d => d.label),
                        datasets: [{data: catData.map(d => d.value), backgroundColor: colors.slice(0,catData.length), borderWidth: 2, borderColor: '#fff'}]
                    },
                    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { family: 'Montserrat', size: 11 } } } } }
                });
            }
        });
        </script>
        <?php endif; ?>


    </div><!-- /app-container -->

    <div class="dashboard-footer">
        <div class="footer-left">
            <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
            <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
        </div>
        <div class="footer-right"><img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO"></div>
    </div>

    <!-- ===================== MODALES ===================== -->
    <div class="modal-overlay" id="modalProveedor">
        <div class="modal">
            <div class="modal-header"><div class="modal-title"><i class="fas fa-truck"></i> <span id="proveedorModalTitle">Nuevo Proveedor</span></div><button class="modal-close" onclick="cerrarModal('modalProveedor')">&times;</button></div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="guardar_proveedor">
                <input type="hidden" name="id" id="proveedorId">
                <div class="modal-body">
                    <div class="grid-2">
                        <div class="form-group full" style="grid-column: 1 / -1;"><label class="form-label">Razón Social / Nombre *</label><input type="text" name="nombre" id="proveedorNombre" class="form-input" required></div>
                        <div class="form-group"><label class="form-label">RFC</label><input type="text" name="rfc" id="proveedorRfc" class="form-input"></div>
                        <div class="form-group"><label class="form-label">Teléfono</label><input type="text" name="telefono" id="proveedorTelefono" class="form-input"></div>
                        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="proveedorEmail" class="form-input"></div>
                        <div class="form-group full"><label class="form-label">Dirección</label><textarea name="direccion" id="proveedorDireccion" class="form-input" rows="2"></textarea></div>
                        <div class="form-group"><label class="form-label">Atención a (At'n)</label><input type="text" name="atencion" id="proveedorAtencion" class="form-input"></div>
                        <div class="form-group"><label class="form-label">Referencia</label><input type="text" name="referencia" id="proveedorReferencia" class="form-input"></div>
                        <div class="form-group full">
                            <label class="form-label">Categorías de productos que maneja <span style="font-size:11px;color:#888;">(selecciona todas las que apliquen)</span></label>
                            <div style="display:flex;gap:10px;margin-top:6px;flex-wrap:wrap;" id="provCatCheckboxes">
                                <?php foreach ($categorias as $cat): ?>
                                <label style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #ddd;border-radius:20px;cursor:pointer;font-size:13px;user-select:none" class="prov-cat-chip">
                                    <input type="checkbox" name="categorias_ids[]" value="<?php echo $cat['id']; ?>" style="width:14px;height:14px">
                                    <i class="fas fa-<?php echo $cat['icono'] ?? 'tag'; ?>" style="color:#7b1fa2;font-size:11px"></i>
                                    <?php echo htmlspecialchars($cat['nombre']); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group full"><label class="form-label">Etiqueta / Especialidad</label><input type="text" name="etiqueta" id="proveedorEtiqueta" class="form-input" placeholder="ej. Importador, Nacional, Maquinaria..."></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalProveedor')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modalEliminarProveedor">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;"><div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</div><button class="modal-close" onclick="cerrarModal('modalEliminarProveedor')">&times;</button></div>
            <div class="modal-body" style="text-align: center;"><i class="fas fa-trash" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i><p id="eliminar_proveedor_mensaje" style="margin-bottom: 20px; font-size: 16px;"></p></div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="eliminar_proveedor">
                    <input type="hidden" name="id" id="eliminar_proveedor_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEliminarProveedor')">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Sí, Eliminar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalMaterial">
        <div class="modal">
            <div class="modal-header"><div class="modal-title"><i class="fas fa-box"></i> <span id="materialModalTitle">Nuevo Material</span></div><button class="modal-close" onclick="cerrarModal('modalMaterial')">&times;</button></div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="guardar_material">
                <input type="hidden" name="id" id="materialId">
                <div class="modal-body">
                    <div class="grid-2">
                        <div class="form-group full">
                            <label class="form-label">Descripción <span style="color:#e53935;">*</span></label>
                            <textarea name="descripcion" id="materialDescripcion" class="form-input" rows="2" required placeholder="Descripción completa del material (es el identificador principal)"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Código<?php if (!$puedeAsignarCodigoMaterial): ?> <small style="font-weight:400;color:#999;">(sugerido -- Almacén lo verifica)</small><?php endif; ?></label>
                            <input type="text" name="codigo" id="materialCodigo" class="form-input" <?= $puedeAsignarCodigoMaterial ? '' : 'readonly placeholder="Se sugiere al escribir la descripción"' ?>>
                        </div>
                        <div class="form-group"><label class="form-label">Categoría <span style="color:#e53935;">*</span></label><select name="categoria_id" id="materialCategoria" class="form-input" required><option value="">Seleccione...</option><?php foreach ($categorias as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label class="form-label">Marca</label><input type="text" name="marca" id="materialMarca" class="form-input"></div>
                        <div class="form-group"><label class="form-label">Modelo</label><input type="text" name="modelo" id="materialModelo" class="form-input" list="modelosDatalist" autocomplete="off"><datalist id="modelosDatalist"></datalist></div>
                        <input type="hidden" name="precio_unitario" id="materialPrecio" value="0">
                        <div class="form-group"><label class="form-label">Unidad de Medida</label><select name="unidad_medida" id="materialUnidad" class="form-input"><option value="pza">Pieza</option><option value="kg">Kilogramo</option><option value="m">Metro</option><option value="l">Litro</option><option value="caja">Caja</option><option value="rollo">Rollo</option></select></div>
                        <div class="form-group"><label class="form-label">Stock Disponible</label><input type="number" name="stock_disponible" id="materialStock" class="form-input" min="0" value="0"></div>
                        <div class="form-group"><label class="form-label">Stock Mínimo</label><input type="number" name="stock_minimo" id="materialStockMin" class="form-input" min="0" value="0"></div>
                        <div class="form-group full"><label class="form-label">Imagen</label><input type="file" name="imagen" class="form-input" accept="image/*"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="cerrarModal('modalMaterial')">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modalEliminarMaterial">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;"><div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</div><button class="modal-close" onclick="cerrarModal('modalEliminarMaterial')">&times;</button></div>
            <div class="modal-body" style="text-align: center;"><i class="fas fa-trash" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i><p id="eliminar_material_mensaje" style="margin-bottom: 20px;"></p></div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="eliminar_material">
                    <input type="hidden" name="id" id="eliminar_material_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEliminarMaterial')">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Sí, Eliminar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalStock">
    <div class="modal" style="max-width: 400px;">
        <div class="modal-header" style="background: #FF9800;">
            <div class="modal-title"><i class="fas fa-balance-scale"></i> Ajustar Stock</div>
            <button class="modal-close" onclick="cerrarModal('modalStock')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajustar_stock">
            <input type="hidden" name="material_id" id="stockMaterialId">
            <div class="modal-body" style="text-align: center;">
                <h4 id="stockMaterialNombre"></h4>
                <p style="color: #666;">Stock actual: <strong id="stockActual"></strong></p>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Tipo de Ajuste</label>
                    <select name="tipo_ajuste" class="form-input" required>
                        <option value="entrada">Entrada (Agregar)</option>
                        <option value="salida">Salida (Restar)</option>
                    </select>
                </div>
                <div class="form-group" style="text-align: left;">
                    <label class="form-label">Cantidad a ajustar</label>
                    <input type="number" name="cantidad" class="form-input" min="1" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="cerrarModal('modalStock')">Cancelar</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-check"></i> Aplicar</button>
            </div>
        </form>
    </div>
</div>

    <div class="modal-overlay" id="modalCategoriasInventario">
        <div class="modal">
            <div class="modal-header"><h3><i class="fas fa-tags"></i> Filtrar por Categoría</h3><button class="modal-close" onclick="cerrarModal('modalCategoriasInventario')">&times;</button></div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                    <?php foreach ($categorias as $cat): ?>
                        <div class="categoria-card" data-id="<?php echo $cat['id']; ?>" onclick="seleccionarCategoriaInventario(this)" style="border: 2px solid #ddd; border-radius: 8px; padding: 15px; text-align: center; cursor: pointer;">
                            <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                            <div><?php echo $cat['nombre']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="limpiarFiltroInventario()"><i class="fas fa-eraser"></i> Limpiar</button>
                <button class="btn" onclick="cerrarModal('modalCategoriasInventario')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroInventario()">Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Chat -->
    <div class="chat-bubble">
        <div class="chat-button" onclick="toggleChat()">
            <i class="fas fa-comment-dots"></i>
            <span class="chat-status" id="chatStatusDot"></span>
            <span class="notification-badge" id="chatNotificationBadge" style="display: none;">0</span>
        </div>
    </div>
    <div class="chat-window" id="chatWindow">
        <div class="chat-header"><h4><i class="fas fa-comments"></i> Mensajes</h4><div class="chat-header-actions"><button onclick="minimizeChat()"><i class="fas fa-minus"></i></button><button onclick="closeChat()"><i class="fas fa-times"></i></button></div></div>
        <div class="chat-tabs"><div class="chat-tab active" data-tab="conversaciones" onclick="switchChatTab('conversaciones')"><i class="fas fa-users"></i> Conversaciones</div><div class="chat-tab" data-tab="notificaciones" onclick="switchChatTab('notificaciones')"><i class="fas fa-bell"></i> Notificaciones</div></div>
        <div id="chatPanelConversaciones" class="chat-panel">
            <div id="vistaUsuarios" class="chat-view">
                <div class="chat-view-header"><span class="chat-view-title"><i class="fas fa-users"></i> Usuarios activos</span></div>
                <div class="chat-users-list" id="usersList"><div class="empty-chat"><i class="fas fa-spinner fa-spin"></i><p>Cargando...</p></div></div>
            </div>
            <div id="vistaChat" class="chat-conversation" style="display: none;">
                <div class="chat-conversation-header"><button class="btn-back-chat" onclick="volverAListaUsuarios()"><i class="fas fa-arrow-left"></i></button><div class="chat-user-avatar" id="chatAvatar">U</div><div class="chat-user-info"><div class="chat-user-name" id="chatUserName">...</div><div class="chat-user-role" id="chatUserRole"></div></div><div class="chat-user-status" id="chatUserStatus"></div></div>
                <div class="chat-messages" id="chatMessagesList"><div class="empty-chat"><i class="fas fa-comment-dots"></i><p>No hay mensajes aún</p></div></div>
                <div class="chat-input-area">
                    <div class="chat-input-row"><textarea id="chatInput" placeholder="Escribe un mensaje..." rows="1" onkeypress="handleChatKeyPress(event)"></textarea><button onclick="enviarMensaje()"><i class="fas fa-paper-plane"></i></button></div>
                    <div class="chat-file-buttons"><button class="chat-file-btn" onclick="document.getElementById('chatFileInput').click()"><i class="fas fa-paperclip"></i></button><button class="chat-file-btn" onclick="document.getElementById('chatImageInput').click()"><i class="fas fa-image"></i></button><input type="file" id="chatFileInput" style="display:none;" accept=".pdf,.xls,.xlsx,.doc,.docx,.txt" onchange="adjuntarArchivo(this,'archivo')"><input type="file" id="chatImageInput" style="display:none;" accept="image/*" onchange="adjuntarArchivo(this,'imagen')"><span id="chatFileInfo" style="font-size:11px;color:#666;"></span></div>
                </div>
            </div>
        </div>
        <div id="chatPanelNotificaciones" class="chat-panel" style="display:none;overflow-y:auto;padding:15px;"><div style="display:flex;gap:8px;margin-bottom:10px;"><button onclick="marcarTodasLeidas()" style="flex:1;background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;"><i class="fas fa-check-double"></i> Marcar todo leído</button><button onclick="borrarNotificaciones()" style="flex:1;background:#fce4ec;color:#c62828;border:none;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;"><i class="fas fa-trash"></i> Borrar todo</button></div><div id="notificacionesList"><div class="empty-chat"><i class="fas fa-bell-slash"></i><p>No hay notificaciones</p></div></div></div>
    </div>
    <div class="image-preview-modal" id="imagePreviewModal" onclick="cerrarPreviewImagen(event)"><span class="close-preview">&times;</span><img id="previewImage" src=""></div>
    <div class="file-preview-modal" id="filePreviewModal"><div class="file-preview-header"><span>Vista previa</span><button onclick="cerrarPreviewArchivo()" style="background:none;border:none;color:white;font-size:20px;cursor:pointer;">&times;</button></div><div class="file-preview-body"><iframe id="filePreviewFrame" src=""></iframe></div></div>

    <script>
        // ===== FUNCIONES GENERALES =====
        let categoriaSeleccionadaInventario = null;
        let categoriaSeleccionadaProveedores = null;

        document.addEventListener('DOMContentLoaded', function() {
            initSearch('inventario');
            initSearch('proveedores');
            initSearch('realizados');
            iniciarPollingChat();
            actualizarEstadoConexion();
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') { cerrarPreviewImagen(); cerrarPreviewArchivo(); }
            });
            window.addEventListener('beforeunload', function() {
                fetch('includes/ajax_chat.php?action=actualizar_estado&estado=offline', { keepalive: true });
            });
        });

        function initSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            if (!input) return;
            input.addEventListener('input', function() {
                if (clearBtn) { if (this.value.length > 0) clearBtn.classList.add('visible'); else clearBtn.classList.remove('visible'); }
                if (section === 'inventario') filtrarInventario(this.value);
                else if (section === 'proveedores') filtrarProveedores(this.value);
                else if (section === 'realizados') filtrarRealizados(this.value);
            });
        }

        function ajustarStock(id, descripcion, stockActual) {
            document.getElementById('stockMaterialId').value = id;
            document.getElementById('stockMaterialNombre').textContent = descripcion;
            document.getElementById('stockActual').textContent = stockActual;
            document.getElementById('modalStock').classList.add('active');
        }

        function clearSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            if (input) { input.value = ''; if (clearBtn) clearBtn.classList.remove('visible'); if (section === 'inventario') filtrarInventario(''); else if (section === 'proveedores') filtrarProveedores(''); else if (section === 'realizados') filtrarRealizados(''); }
        }

        async function desbloquearProveedorProyecto(bloqueoId) {
            const ok = await confirmarModal('¿Desbloquear a este proveedor para este proyecto? Va a volver a aparecer disponible para cotizar.');
            if (!ok) return;
            const fd = new FormData();
            fd.append('action', 'desbloquear_proveedor');
            fd.append('bloqueo_id', bloqueoId);
            try {
                const res = await fetch('includes/solicitar_cotizacion.php', { method:'POST', body: fd }).then(r=>r.json());
                if (res.success) { window.location.reload(); }
                else { alert('Error: ' + (res.error || 'desconocido')); }
            } catch(e) { alert('Error de red'); }
        }

        async function saltarFaseCotizacion(proyectoId, reqId) {
            const checks = document.querySelectorAll('#provCheckList input[name="prov_sel"]:checked');
            if (!checks.length) { alert('Selecciona al menos un proveedor.'); return; }
            const ok = await confirmarModal('¿Registrar los proveedores seleccionados sin enviar email?\n\nPodrás ingresar las cotizaciones manualmente en el siguiente paso.');
            if (!ok) return;
            const ids = Array.from(checks).map(c => c.value);
            const fd = new FormData();
            fd.append('action', 'saltar_fase');
            fd.append('proyecto_id', proyectoId);
            fd.append('requisicion_id', reqId || 0);
            ids.forEach(id => fd.append('proveedor_ids[]', id));
            try {
                const res = await fetch('includes/solicitar_cotizacion.php', { method:'POST', body: fd }).then(r=>r.json());
                if (res.success) { window.location.reload(); }
                else { alert('Error: ' + (res.error || 'desconocido')); }
            } catch(e) { alert('Error de red'); }
        }

        let _manualCotId = 0;
        let _manualProvNombre = '';

        function abrirModalManual(cotId, provNombre) {
            _manualCotId = cotId;
            _manualProvNombre = provNombre;
            const mats = window._matsProyecto || [];
            const tbody = document.getElementById('manualMatsTbody');
            if (!tbody) return;
            tbody.innerHTML = '';
            mats.forEach((m, i) => {
                const tr = document.createElement('tr');
                const desc = m.nombre || m.descripcion || '';
                tr.innerHTML = `
                    <td style="padding:6px 8px;font-size:13px;">${i+1}</td>
                    <td style="padding:6px 8px;font-size:13px;">${desc}</td>
                    <td style="padding:6px 8px;font-size:13px;text-align:center;">${m.cantidad} ${m.unidad}</td>
                    <td style="padding:6px 8px;"><input type="number" step="0.01" min="0" class="manual-precio" data-idx="${i}" data-descripcion="${desc.replace(/"/g,'&quot;')}" data-qty="${m.cantidad}" value="0" style="width:100%;padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:13px;" oninput="recalcularManual()"></td>
                    <td style="padding:6px 8px;font-size:13px;text-align:right;" id="manual-sub-${i}">$0.00</td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('manualProvNombre').textContent = provNombre;
            document.getElementById('manualSubtotal').textContent = '$0.00';
            document.getElementById('manualIva').textContent = '$0.00';
            document.getElementById('manualTotal').textContent = '$0.00';
            document.getElementById('manualNotas').value = '';
            document.getElementById('manualIvaCheck').checked = true;
            document.getElementById('modalCotManual').classList.add('active');
        }

        function recalcularManual() {
            let subtotal = 0;
            document.querySelectorAll('.manual-precio').forEach((inp, i) => {
                const qty = parseFloat(inp.dataset.qty) || 1;
                const price = parseFloat(inp.value) || 0;
                const sub = qty * price;
                subtotal += sub;
                const cell = document.getElementById('manual-sub-' + i);
                if (cell) cell.textContent = '$' + sub.toFixed(2);
            });
            const conIva = document.getElementById('manualIvaCheck').checked;
            const iva = conIva ? subtotal * 0.16 : 0;
            const total = subtotal + iva;
            document.getElementById('manualSubtotal').textContent = '$' + subtotal.toFixed(2);
            document.getElementById('manualIva').textContent = '$' + iva.toFixed(2);
            document.getElementById('manualTotal').textContent = '$' + total.toFixed(2);
        }

        async function guardarCotizacionManual() {
            const mats = window._matsProyecto || [];
            let subtotal = 0;
            const productos = [];
            document.querySelectorAll('.manual-precio').forEach((inp, i) => {
                const qty = parseFloat(inp.dataset.qty) || 1;
                const price = parseFloat(inp.value) || 0;
                const sub = qty * price;
                subtotal += sub;
                productos.push({ descripcion: inp.dataset.descripcion, unidad: (mats[i] && mats[i].unidad) || 'pza', cantidad: qty, precio_unitario: price, subtotal: sub });
            });
            if (subtotal === 0) { alert('Ingresa al menos un precio.'); return; }
            const conIva = document.getElementById('manualIvaCheck').checked;
            const iva = conIva ? subtotal * 0.16 : 0;
            const total = subtotal + iva;
            const notas = document.getElementById('manualNotas').value;
            const datosIa = JSON.stringify({
                proveedor: _manualProvNombre,
                fecha: new Date().toISOString().split('T')[0],
                referencia: 'Manual',
                subtotal: subtotal,
                descuento: 0,
                iva: iva,
                total: total,
                notas: notas,
                productos: productos
            });
            const fd = new FormData();
            fd.append('action', 'guardar_datos_cotizacion');
            fd.append('cotizacion_id', _manualCotId);
            fd.append('datos_ia', datosIa);
            fd.append('archivo_ruta', '');
            try {
                const res = await fetch('includes/solicitar_cotizacion.php', { method:'POST', body: fd }).then(r=>r.json());
                if (res.success) { document.getElementById('modalCotManual').classList.remove('active'); window.location.reload(); }
                else { alert('Error: ' + (res.error || 'desconocido')); }
            } catch(e) { alert('Error de red'); }
        }

        function filtrarInventario(t) { t = t.toLowerCase(); document.querySelectorAll('#inventarioGrid .material-card').forEach(c => { c.style.display = (c.dataset.descripcion||'').includes(t) || (c.dataset.codigo||'').includes(t) ? '' : 'none'; }); }
        function filtrarProveedores(t) { t = t.toLowerCase(); document.querySelectorAll('#proveedoresGrid .proveedor-card').forEach(c => { c.style.display = (c.dataset.nombre||'').includes(t) || (c.dataset.rfc||'').includes(t) || (c.dataset.etiqueta||'').includes(t) ? '' : 'none'; }); }
        function filtrarRealizados(t) { t = t.toLowerCase(); document.querySelectorAll('#realizadosContent .boom-guardado').forEach(el => { el.style.display = (el.dataset.nombre||'').includes(t) ? '' : 'none'; }); }

        function cerrarModal(id) { document.getElementById(id).classList.remove('active'); }
        document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); }); });

        // Categorías Inventario
        function abrirModalCategoriasInventario() { document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected')); categoriaSeleccionadaInventario = null; document.getElementById('modalCategoriasInventario').classList.add('active'); }
        function seleccionarCategoriaInventario(card) { document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected')); card.classList.add('selected'); categoriaSeleccionadaInventario = card.dataset.id; }
        function aplicarFiltroInventario() { cerrarModal('modalCategoriasInventario'); const cards = document.querySelectorAll('#inventarioGrid .material-card'); if (!categoriaSeleccionadaInventario) { cards.forEach(c => c.style.display = ''); return; } cards.forEach(c => { c.style.display = c.dataset.categoria == categoriaSeleccionadaInventario ? '' : 'none'; }); }
        function limpiarFiltroInventario() { categoriaSeleccionadaInventario = null; document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected')); aplicarFiltroInventario(); cerrarModal('modalCategoriasInventario'); }

        // Categorías Proveedores
        function abrirModalCategoriasProveedores() { document.querySelectorAll('#modalCategoriasProveedores .categoria-card').forEach(c => c.classList.remove('selected')); categoriaSeleccionadaProveedores = null; document.getElementById('modalCategoriasProveedores').classList.add('active'); }
        function seleccionarCategoriaProveedor(card, cat) { document.querySelectorAll('#modalCategoriasProveedores .categoria-card').forEach(c => c.classList.remove('selected')); card.classList.add('selected'); categoriaSeleccionadaProveedores = cat; }
        function aplicarFiltroProveedores() { cerrarModal('modalCategoriasProveedores'); const cards = document.querySelectorAll('#proveedoresGrid .proveedor-card'); if (!categoriaSeleccionadaProveedores) { cards.forEach(c => c.style.display = ''); return; } cards.forEach(c => { const ids = (c.dataset.categorias || '').split(' ').filter(Boolean); c.style.display = ids.includes(String(categoriaSeleccionadaProveedores)) ? '' : 'none'; }); }
        function limpiarFiltroProveedores() { categoriaSeleccionadaProveedores = null; document.querySelectorAll('#modalCategoriasProveedores .categoria-card').forEach(c => c.classList.remove('selected')); aplicarFiltroProveedores(); cerrarModal('modalCategoriasProveedores'); }

        // Material
        function abrirModalMaterial() { document.getElementById('materialModalTitle').textContent = 'Nuevo Material'; document.getElementById('materialId').value = ''; document.querySelector('#modalMaterial form').reset(); document.getElementById('modalMaterial').classList.add('active'); }
        function editarMaterial(m) {
            document.getElementById('materialModalTitle').textContent = 'Editar Material';
            document.getElementById('materialId').value            = m.id;
            document.getElementById('materialDescripcion').value   = m.descripcion || '';
            document.getElementById('materialCodigo').value        = m.codigo || '';
            document.getElementById('materialCategoria').value     = m.categoria_id || '';
            document.getElementById('materialMarca').value         = m.marca || '';
            document.getElementById('materialModelo').value        = m.modelo || '';
            document.getElementById('materialUnidad').value        = m.unidad_medida || 'pza';
            document.getElementById('materialStock').value         = m.stock_disponible || 0;
            document.getElementById('materialStockMin').value      = m.stock_minimo || 0;
            document.getElementById('modalMaterial').classList.add('active');
    }
        function confirmarEliminarMaterial(id, nombre) {
            document.getElementById('eliminar_material_id').value = id;
            document.getElementById('eliminar_material_mensaje').textContent = '¿Estás seguro de eliminar el material "' + nombre + '"?';
            document.getElementById('modalEliminarMaterial').classList.add('active');
    }
    
        function confirmarEliminarMaterial(id, nombre) {
        document.getElementById('eliminar_material_id').value = id;
        document.getElementById('eliminar_material_mensaje').textContent = '¿Estás seguro de eliminar el material "' + nombre + '"?';
        document.getElementById('modalEliminarMaterial').classList.add('active');
    }

        // Proveedor
        function abrirModalProveedor() { document.getElementById('proveedorModalTitle').textContent = 'Nuevo Proveedor'; document.getElementById('proveedorId').value = ''; document.querySelector('#modalProveedor form').reset(); document.getElementById('modalProveedor').classList.add('active'); }
        function confirmarEliminarProveedor(id, nombre) { document.getElementById('eliminar_proveedor_id').value = id; document.getElementById('eliminar_proveedor_mensaje').textContent = '¿Eliminar al proveedor "' + nombre + '"?'; document.getElementById('modalEliminarProveedor').classList.add('active'); }

        // ── ENVIAR SOLICITUD DE COTIZACIÓN A PROVEEDORES ─────────────────────────
        async function enviarSolicitudCotizacion(proyectoId, reqId) {
            // Capturar btn ANTES de cualquier await (event.currentTarget se vuelve null después)
            const btn = (event.currentTarget || event.target);
            const resetBtn = () => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar solicitud de cotización'; } };

            const checks = document.querySelectorAll('#provCheckList input[name="prov_sel"]:checked');
            if (checks.length === 0) {
                mostrarModalAlerta('error', 'Selección requerida', 'Selecciona al menos un proveedor.');
                return;
            }
            const msg = document.getElementById('msg_cotizacion')?.value || '';
            const ids = Array.from(checks).map(c => c.value);

            const ok = await confirmarModal(`¿Enviar solicitud de cotización a ${ids.length} proveedor(es)?`);
            if (!ok) return;

            const fd = new FormData();
            fd.append('action', 'enviar_solicitud');
            fd.append('proyecto_id', proyectoId);
            fd.append('requisicion_id', reqId);
            fd.append('mensaje', msg);
            ids.forEach(id => fd.append('proveedor_ids[]', id));

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }

            let res;
            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const txt = await r.text();
                try { res = JSON.parse(txt); } catch(e) {
                    mostrarModalAlerta('error', 'Error del servidor', txt.substring(0, 400));
                    resetBtn(); return;
                }
            } catch(e) {
                mostrarModalAlerta('error', 'Error de conexión', e.message);
                resetBtn(); return;
            }

            if (res.success) {
                mostrarModalEmailResultado(res);
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
                resetBtn();
            }
        }

        // ── REQUISICIONES POR COMPRAR (sin proyecto) ─────────────────────────────
        async function enviarSolicitudCotizacionReq(reqId) {
            const btn = (event.currentTarget || event.target);
            const resetBtn = () => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar solicitud de cotización'; } };

            const checks = document.querySelectorAll('#provCheckListReq input[name="prov_sel_req"]:checked');
            if (checks.length === 0) {
                mostrarModalAlerta('error', 'Selección requerida', 'Selecciona al menos un proveedor.');
                return;
            }
            const msg = document.getElementById('msg_cotizacion_req')?.value || '';
            const ids = Array.from(checks).map(c => c.value);

            const ok = await confirmarModal(`¿Enviar solicitud de cotización a ${ids.length} proveedor(es)?`);
            if (!ok) return;

            const fd = new FormData();
            fd.append('action', 'enviar_solicitud_req');
            fd.append('requisicion_id', reqId);
            fd.append('mensaje', msg);
            ids.forEach(id => fd.append('proveedor_ids[]', id));

            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }

            let res;
            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const txt = await r.text();
                try { res = JSON.parse(txt); } catch(e) {
                    mostrarModalAlerta('error', 'Error del servidor', txt.substring(0, 400));
                    resetBtn(); return;
                }
            } catch(e) {
                mostrarModalAlerta('error', 'Error de conexión', e.message);
                resetBtn(); return;
            }

            if (res.success) {
                mostrarModalEmailResultado(res);
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
                resetBtn();
            }
        }

        async function saltarFaseCotizacionReq(reqId) {
            const checks = document.querySelectorAll('#provCheckListReq input[name="prov_sel_req"]:checked');
            if (checks.length === 0) {
                mostrarModalAlerta('error', 'Selección requerida', 'Selecciona al menos un proveedor.');
                return;
            }
            const ok = await confirmarModal('¿Registrar los proveedores seleccionados sin enviar email?\n\nPodrás capturar el precio directamente en el siguiente paso.');
            if (!ok) return;

            const ids = Array.from(checks).map(c => c.value);
            const fd = new FormData();
            fd.append('action', 'saltar_fase_req');
            fd.append('requisicion_id', reqId);
            ids.forEach(id => fd.append('proveedor_ids[]', id));

            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) { window.location.reload(); }
                else { mostrarModalAlerta('error', 'Error', res.error || 'No se pudo registrar'); }
            } catch(e) { mostrarModalAlerta('error', 'Error de conexión', e.message); }
        }

        async function guardarCotizacionRecibidaReq(cotId) {
            const panel = document.querySelector('.cot-captura[data-cotid="' + cotId + '"]');
            const inputs = panel.querySelectorAll('.precio-input');
            const productos = [];
            inputs.forEach(inp => {
                const precio = parseFloat(inp.value) || 0;
                if (precio <= 0) return;
                const cant = parseFloat(inp.dataset.cant) || 0;
                productos.push({
                    descripcion: inp.dataset.desc,
                    cantidad: cant,
                    unidad: inp.dataset.unidad,
                    material_id: parseInt(inp.dataset.matid) || null,
                    precio_unitario: precio,
                    subtotal: Math.round(cant * precio * 100) / 100
                });
            });
            if (productos.length === 0) {
                mostrarModalAlerta('error', 'Faltan precios', 'Captura al menos un precio unitario.');
                return;
            }
            const ok = await confirmarModal('¿Guardar esta cotización recibida?');
            if (!ok) return;

            const fd = new FormData();
            fd.append('action', 'guardar_datos_cotizacion');
            fd.append('cotizacion_id', cotId);
            fd.append('datos_ia', JSON.stringify({ productos }));
            fd.append('archivo_ruta', '');
            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) { window.location.reload(); }
                else { mostrarModalAlerta('error', 'Error', res.error || 'No se pudo guardar'); }
            } catch(e) { mostrarModalAlerta('error', 'Error de conexión', e.message); }
        }

        async function generarOCReq(reqId, cotId, btn) {
            const productos = JSON.parse(btn.dataset.items || '[]');
            if (productos.length === 0) {
                mostrarModalAlerta('error', 'Sin ítems', 'Esta cotización no tiene ítems con precio.');
                return;
            }
            const ok = await confirmarModal('¿Generar la orden de compra con esta cotización?');
            if (!ok) return;

            const seleccion = {};
            seleccion[cotId] = productos.map(p => ({ descripcion: p.descripcion, cantidad: p.cantidad, unidad: p.unidad, material_id: p.material_id, precio_unit: p.precio_unitario }));

            const fd = new FormData();
            fd.append('action', 'generar_ordenes_req');
            fd.append('requisicion_id', reqId);
            fd.append('seleccion', JSON.stringify(seleccion));
            fd.append('condiciones_pago', 'Crédito');

            btn.disabled = true;
            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) { mostrarModalAlerta('success', 'Orden generada', 'Se generó la orden de compra correctamente.', () => window.location.reload()); }
                else { mostrarModalAlerta('error', 'Error', res.error || 'No se pudo generar'); btn.disabled = false; }
            } catch(e) { mostrarModalAlerta('error', 'Error de conexión', e.message); btn.disabled = false; }
        }

        // ── REGRESAR AL PASO ANTERIOR ────────────────────────────────────────────
        async function regresarEstado(proyectoId, estadoAnterior) {
            const labels = { 'espera': 'Solicitar cotización', 'cotizacion_enviada': 'Esperando respuesta' };
            const ok = await confirmarModal(`¿Regresar al paso "${labels[estadoAnterior] || estadoAnterior}"?\nLos registros existentes no se borrarán.`);
            if (!ok) return;
            const fd = new FormData();
            fd.append('action', 'cambiar_estado');
            fd.append('proyecto_id', proyectoId);
            fd.append('estado', estadoAnterior);
            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) { window.location.reload(); }
                else { mostrarModalAlerta('error', 'Error', res.error || 'No se pudo regresar'); }
            } catch(e) { mostrarModalAlerta('error', 'Error de conexión', e.message); }
        }

        // ── NAVEGAR A COMPARATIVA (cambia estado a cotizacion_recibida) ──────────
        async function irAComparativa(proyectoId) {
            const fd = new FormData();
            fd.append('action', 'cambiar_estado');
            fd.append('proyecto_id', proyectoId);
            fd.append('estado', 'cotizacion_recibida');
            try {
                await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
            } catch(e) { /* continuar aunque falle */ }
            window.location.reload();
        }

        function recalcularCard(cotId) {
            const card = document.querySelector('.prov-cot-card[data-cot-id="' + cotId + '"]');
            if (!card) return;
            let subtotal = 0;
            const chks = card.querySelectorAll('.item-chk');

            card.querySelectorAll('.item-row').forEach(row => {
                const chk = row.querySelector('.item-chk');
                const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
                const pu  = parseFloat(row.dataset.precioUnit) || 0;
                const sub = (chk && chk.checked) ? Math.round(qty * pu * 100) / 100 : 0;
                const subEl = row.querySelector('.item-subtotal');
                if (subEl) subEl.textContent = '$' + sub.toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
                subtotal += sub;
            });

            const iva   = subtotal * 0.16;
            const total = subtotal + iva;
            const fmt   = n => n.toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});

            const subEl = document.getElementById('sub-card-' + cotId);
            const totEl = document.getElementById('tot-card-' + cotId);
            if (subEl) subEl.textContent = '$' + fmt(subtotal);
            if (totEl) totEl.textContent = '$' + fmt(total);

            const anyChecked = Array.from(chks).some(c => c.checked);
            card.style.opacity = anyChecked ? '1' : '0.5';

            const allChk = card.querySelector('.cot-sel-all');
            if (allChk) {
                const allChecked = Array.from(chks).every(c => c.checked);
                allChk.checked       = allChecked;
                allChk.indeterminate = !allChecked && anyChecked;
            }
        }

        function toggleTodosItems(cotId, checked) {
            const card = document.querySelector('.prov-cot-card[data-cot-id="' + cotId + '"]');
            if (!card) return;
            card.querySelectorAll('.item-chk').forEach(c => c.checked = checked);
            recalcularCard(cotId);
        }

        // ── GENERAR ÓRDENES DE COMPRA ─────────────────────────────────────────────
        // Estado compartido para el modal de generar OC
        let _genOC = { proyectoId: 0, reqId: 0, seleccion: {}, btn: null };

        function generarOrdenes(event, proyectoId, reqId) {
            const btn = (event.currentTarget || event.target);
            const seleccion = {};

            document.querySelectorAll('.prov-cot-card').forEach(card => {
                const cotId = card.dataset.cotId;
                const items = [];
                card.querySelectorAll('.item-row').forEach(row => {
                    const chk = row.querySelector('.item-chk');
                    if (!chk || !chk.checked) return;
                    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
                    if (qty <= 0) return;
                    const pu = parseFloat(row.dataset.precioUnit) || 0;
                    items.push({
                        descripcion: row.dataset.descripcion,
                        cantidad:    qty,
                        precio_unit: pu,
                        subtotal:    parseFloat((qty * pu).toFixed(2)),
                        unidad:      row.dataset.unidad || 'pza'
                    });
                });
                if (items.length > 0) seleccion[cotId] = items;
            });

            if (Object.keys(seleccion).length === 0) {
                mostrarModalAlerta('error', 'Selección requerida', 'Selecciona al menos un item para generar la orden.');
                return;
            }

            _genOC = { proyectoId, reqId, seleccion, btn };

            const totalCards = Object.keys(seleccion).length;
            document.getElementById('modalGenerarOCInfo').textContent =
                `Se generará${totalCards > 1 ? 'n' : ''} ${totalCards} orden${totalCards > 1 ? 'es' : ''} de compra (una por proveedor con items seleccionados).`;
            document.getElementById('inputFechaEntregaOC').value = '';
            document.getElementById('modalGenerarOC').classList.add('active');
        }

        async function _confirmarGenerarOC() {
            const { proyectoId, reqId, seleccion, btn } = _genOC;
            const fechaEntrega      = document.getElementById('inputFechaEntregaOC').value;
            const condicionesPago   = document.getElementById('inputCondicionesPago').value;
            const notasOC           = document.getElementById('inputNotasOC').value;
            document.getElementById('modalGenerarOC').classList.remove('active');

            const resetBtnOC = () => {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-shopping-cart"></i> Generar órdenes de compra'; }
            };
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...'; }

            const fd = new FormData();
            fd.append('action',            'generar_ordenes');
            fd.append('proyecto_id',       proyectoId);
            fd.append('requisicion_id',    reqId || 0);
            fd.append('fecha_entrega',     fechaEntrega);
            fd.append('condiciones_pago',  condicionesPago);
            fd.append('notas',             notasOC);
            fd.append('seleccion',         JSON.stringify(seleccion));

            let res;
            try {
                const r   = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
                const txt = await r.text();
                try { res = JSON.parse(txt); } catch(e) {
                    mostrarModalAlerta('error', 'Error del servidor', txt.substring(0, 400));
                    resetBtnOC(); return;
                }
            } catch(e) {
                mostrarModalAlerta('error', 'Error de conexión', e.message);
                resetBtnOC(); return;
            }

            if (res.success) {
                let info = 'Órdenes de compra creadas:\n';
                res.ordenes.forEach(o => { info += `· ${o.folio} — ${o.proveedor}\n`; });
                mostrarModalAlerta('success', 'Órdenes generadas', info, () => window.location.reload());
            } else {
                mostrarModalAlerta('error', 'Error al generar', res.error || 'Error desconocido');
                resetBtnOC();
            }
        }

        // ── VER DATOS IA DE UNA COTIZACIÓN ───────────────────────────────────────
        function verDatosCotizacion(cotId) {
            const data = (window._cotData || {})[cotId];
            const modal = document.getElementById('modalEmailResultado');
            const body  = document.getElementById('modalEmailResultadoBody');
            if (!modal || !body) return;

            const header = modal.querySelector('.modal-header h3');
            if (header) header.innerHTML = '<i class="fas fa-file-invoice"></i> Datos extraídos de cotización';

            if (!data) {
                body.innerHTML = '<p style="color:#999;text-align:center;padding:20px;">Sin datos disponibles para esta cotización.</p>';
                modal.classList.add('active');
                return;
            }

            let html = '';
            if (data.proveedor)      html += `<p style="margin:0 0 6px"><strong>Proveedor:</strong> ${escapeHtml(data.proveedor)}</p>`;
            if (data.fecha_cotizacion) html += `<p style="margin:0 0 6px"><strong>Fecha:</strong> ${escapeHtml(data.fecha_cotizacion)}</p>`;
            if (data.vigencia)       html += `<p style="margin:0 0 6px"><strong>Vigencia:</strong> ${escapeHtml(data.vigencia)}</p>`;
            if (data.tiempo_entrega) html += `<p style="margin:0 0 12px"><strong>Tiempo de entrega:</strong> ${escapeHtml(data.tiempo_entrega)}</p>`;

            if (data.productos && data.productos.length) {
                html += `<table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead><tr style="background:#003d5c;color:white;">
                        <th style="padding:8px;text-align:left;">#</th>
                        <th style="padding:8px;text-align:left;">Descripción</th>
                        <th style="padding:8px;text-align:center;">Cant.</th>
                        <th style="padding:8px;text-align:right;">Precio Unit.</th>
                        <th style="padding:8px;text-align:right;">Subtotal</th>
                    </tr></thead><tbody>`;
                data.productos.forEach((p, i) => {
                    const bg = i % 2 === 0 ? '#fff' : '#f4f7fb';
                    const sub = p.subtotal ?? ((p.cantidad ?? 0) * (p.precio_unitario ?? 0));
                    html += `<tr style="background:${bg};">
                        <td style="padding:7px 8px;border-bottom:1px solid #eee;">${i+1}</td>
                        <td style="padding:7px 8px;border-bottom:1px solid #eee;">${escapeHtml(p.descripcion ?? '-')}</td>
                        <td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:center;">${p.cantidad ?? '-'}</td>
                        <td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:right;">$${parseFloat(p.precio_unitario ?? 0).toLocaleString('es-MX', {minimumFractionDigits:2})}</td>
                        <td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:right;">$${parseFloat(sub).toLocaleString('es-MX', {minimumFractionDigits:2})}</td>
                    </tr>`;
                });
                html += `</tbody></table>`;
            }

            const subtotal = data.subtotal ?? 0;
            const descuento = data.descuento ?? 0;
            const total = data.total ?? 0;
            html += `<div style="margin-top:12px;text-align:right;font-size:14px;border-top:2px solid #003d5c;padding-top:10px;">`;
            if (subtotal) html += `<div>Subtotal: <strong>$${parseFloat(subtotal).toLocaleString('es-MX', {minimumFractionDigits:2})}</strong></div>`;
            if (descuento) html += `<div style="color:#43a047;">Descuento: -$${parseFloat(descuento).toLocaleString('es-MX', {minimumFractionDigits:2})}</div>`;
            if (total)    html += `<div style="font-size:16px;color:#1565c0;font-weight:700;">Total c/IVA: $${parseFloat(total).toLocaleString('es-MX', {minimumFractionDigits:2})}</div>`;
            html += `</div>`;
            if (data.notas) html += `<p style="margin:12px 0 0;font-size:12px;color:#666;"><strong>Notas:</strong> ${escapeHtml(data.notas)}</p>`;

            body.innerHTML = html;
            modal.classList.add('active');
        }

        // PDF
        function descargarERP_PDF(btn) {
            const fileName = 'Solicitud_Cotizacion.pdf';
            const element = document.querySelector('.erp-container');
            if (!element) { alert("No se encontró el contenedor"); return; }
            html2pdf().set({ margin: [10,10,10,10], filename: fileName, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } }).from(element).save();
        }

        // Imprime el BOM del proyecto (mismo formato que usa Ingeniería): se
        // carga en un iframe oculto y se dispara la vista de impresión nativa.
        function imprimirBomProyecto(proyectoId) {
            let frame = document.getElementById('iframeImprimirBomProyecto');
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = 'iframeImprimirBomProyecto';
                frame.style.display = 'none';
                document.body.appendChild(frame);
            }
            frame.onload = function () {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            };
            frame.src = 'api/imprimir_bom_proyecto.php?proyecto_id=' + proyectoId;
        }

        // ===== CHAT =====
        let chatInterval = null;
        let contactoActivo = null;
        let archivoAdjunto = null;
        let archivoTipo = null;
        let usuariosLista = [];
        let usuarioActualId = <?php echo $_SESSION['user_id']; ?>;

        function actualizarEstadoConexion() { fetch('includes/ajax_chat.php?action=actualizar_estado').then(() => { const dot = document.getElementById('chatStatusDot'); if(dot){dot.classList.remove('offline','away');dot.classList.add('online');} }); }
        function iniciarPollingChat() { if(chatInterval) clearInterval(chatInterval); chatInterval = setInterval(verificarNuevosMensajes, 5000); setInterval(actualizarEstadoConexion, 30000); }
        function marcarMensajesComoLeidos(contactoId) { if(!contactoId) return Promise.resolve(); return fetch(`includes/ajax_chat.php?action=marcar_leidos&contacto=${contactoId}`); }
        function verificarNuevosMensajes() { if(contactoActivo) marcarMensajesComoLeidos(contactoActivo); fetch('includes/ajax_chat.php?action=obtener_nuevos').then(r=>r.json()).then(data => { if(data.success){ const badge=document.getElementById('chatNotificationBadge'); const total=(data.total_no_leidos||0)+(data.notificaciones?data.notificaciones.length:0); if(total>0){badge.textContent=total>99?'99+':total;badge.style.display='flex';}else{badge.style.display='none';} if(document.getElementById('chatWindow').classList.contains('open')&&contactoActivo) cargarHistorial(contactoActivo); } }); }
        function toggleChat() { const w=document.getElementById('chatWindow'); if(w.classList.contains('open')){closeChat();}else{openChat();} }
        function openChat() { document.getElementById('chatWindow').classList.add('open'); cargarUsuarios(); }
        function closeChat() { document.getElementById('chatWindow').classList.remove('open'); contactoActivo=null; }
        function minimizeChat() { closeChat(); }
        function switchChatTab(tab) { document.querySelectorAll('.chat-tab').forEach(t=>t.classList.remove('active')); document.querySelectorAll('.chat-panel').forEach(p=>p.style.display='none'); if(tab==='conversaciones'){document.querySelector('.chat-tab[data-tab="conversaciones"]').classList.add('active');document.getElementById('chatPanelConversaciones').style.display='flex';cargarUsuarios();}else{document.querySelector('.chat-tab[data-tab="notificaciones"]').classList.add('active');document.getElementById('chatPanelNotificaciones').style.display='block';cargarNotificaciones();} }
        function volverAListaUsuarios() { contactoActivo=null; document.getElementById('vistaUsuarios').style.display='flex'; document.getElementById('vistaChat').style.display='none'; cargarUsuarios(); }
        function abrirChatConUsuario(usuario) { contactoActivo=usuario.id; document.getElementById('chatAvatar').textContent=usuario.nombre_completo.charAt(0).toUpperCase(); document.getElementById('chatUserName').textContent=usuario.nombre_completo; document.getElementById('chatUserRole').textContent=usuario.tipo_usuario; document.getElementById('chatUserStatus').className='chat-user-status '+(usuario.estado==='online'?'online':'offline'); document.getElementById('vistaUsuarios').style.display='none'; document.getElementById('vistaChat').style.display='flex'; cargarHistorial(usuario.id); marcarMensajesComoLeidos(usuario.id).then(() => verificarNuevosMensajes()); }
        function cargarUsuarios() { fetch('includes/ajax_chat.php?action=obtener_contactos').then(r=>r.json()).then(data=>{ if(data.success&&data.contactos){ usuariosLista=data.contactos; renderizarUsuarios(data.contactos, data.no_leidos_por_contacto); } }); }
        function renderizarUsuarios(usuarios, noLeidos) { const c=document.getElementById('usersList'); if(!usuarios||!usuarios.length){c.innerHTML='<div class="empty-chat"><i class="fas fa-users"></i><p>No hay otros usuarios</p></div>';return;} let html=''; usuarios.forEach(u=>{ const nl=noLeidos?(noLeidos[u.id]||0):0; html+=`<div class="chat-user-item" data-id="${u.id}" onclick='abrirChatConUsuario(${JSON.stringify(u)})'><div class="chat-user-avatar">${u.nombre_completo.charAt(0).toUpperCase()}</div><div class="chat-user-info"><div class="chat-user-name">${escapeHtml(u.nombre_completo)}</div><div class="chat-user-role">${escapeHtml(u.tipo_usuario)}</div></div><div class="chat-user-status ${u.estado==='online'?'online':'offline'}"></div>${nl>0?`<span class="chat-user-badge">${nl}</span>`:''}</div>`; }); c.innerHTML=html; }
        function cargarHistorial(cId) { fetch(`includes/ajax_chat.php?action=historial&con=${cId}`).then(r=>r.json()).then(data=>{ if(data.success&&data.historial) renderizarHistorial(data.historial); }); }
        function renderizarHistorial(msgs) {
            const c = document.getElementById('chatMessagesList');
            if (!msgs || !msgs.length) { c.innerHTML = '<div class="empty-chat"><i class="fas fa-comment-dots"></i><p>¡Envía el primero!</p></div>'; return; }
            let html = '';
            msgs.forEach(m => {
                const hora = new Date(m.fecha_envio).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                html += `<div class="message-item ${m.es_propio ? 'message-sent' : 'message-received'}">
                    <div class="message-bubble">
                        ${!m.es_propio ? '<strong>' + escapeHtml(m.remitente_nombre) + '</strong><br>' : ''}
                        ${escapeHtml(m.mensaje)}`;
                if (m.archivo_ruta) {
                    const esImagen = m.archivo_tipo === 'imagen' || /\.(jpg|jpeg|png|gif|webp)$/i.test(m.archivo_nombre);
                    const esPdf = /\.pdf$/i.test(m.archivo_nombre);
                    if (esImagen) {
                        html += `<div class="message-file" onclick="verImagen('${m.archivo_ruta}')">
                            <i class="fas fa-image"></i>
                            <span>${escapeHtml(m.archivo_nombre)}</span>
                            <i class="fas fa-expand" style="margin-left:auto;"></i>
                        </div>`;
                    } else {
                        html += `<div class="message-file">
                            <i class="fas ${esPdf ? 'fa-file-pdf' : 'fa-file-alt'}"></i>
                            <span>${escapeHtml(m.archivo_nombre)}</span>
                        </div>
                        <div class="message-file-actions">
                            <button onclick="descargarArchivo('${m.archivo_ruta}', '${m.archivo_nombre}')"><i class="fas fa-download"></i> Descargar</button>
                            ${esPdf ? `<button onclick="verPdf('${m.archivo_ruta}')"><i class="fas fa-eye"></i> Ver</button>` : ''}
                        </div>`;
                    }
                }
                html += `</div><div class="message-time">${hora}</div></div>`;
            });
            c.innerHTML = html;
            c.scrollTop = c.scrollHeight;
        }
        function enviarMensaje() { if(!contactoActivo) return; const input=document.getElementById('chatInput'); const mensaje=input.value.trim(); if(!mensaje&&!archivoAdjunto) return; const fd=new FormData(); fd.append('action','enviar'); fd.append('destinatario_id',contactoActivo); fd.append('mensaje',mensaje); if(archivoAdjunto){fd.append('archivo',archivoAdjunto);fd.append('archivo_tipo',archivoTipo);} fetch('includes/ajax_chat.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{ if(data.success){input.value='';archivoAdjunto=null;archivoTipo=null;document.getElementById('chatFileInfo').innerHTML='';cargarHistorial(contactoActivo);}else{alert('Error: '+data.error);} }); }
        function adjuntarArchivo(input, tipo) { if(input.files&&input.files[0]){const f=input.files[0];if(f.size>10*1024*1024){alert('Máximo 10MB');input.value='';return;}archivoAdjunto=f;archivoTipo=tipo;document.getElementById('chatFileInfo').innerHTML=`<i class="fas fa-paperclip"></i> ${f.name}`;} }
        function cargarNotificaciones() { fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r=>r.json()).then(data=>{ if(data.success&&data.notificaciones) renderizarNotificaciones(data.notificaciones); }); }
        function marcarTodasLeidas() { fetch('includes/ajax_chat.php?action=marcar_todas_leidas').then(r=>r.json()).then(d=>{ if(d.success) cargarNotificaciones(); }); }
        async function borrarNotificaciones() { const ok = await confirmarModal('¿Borrar todas las notificaciones?'); if(!ok) return; fetch('includes/ajax_chat.php?action=borrar_notificaciones').then(r=>r.json()).then(d=>{ if(d.success) cargarNotificaciones(); }); }
        function renderizarNotificaciones(notifs) {
            const c = document.getElementById('notificacionesList');
            if (!notifs || !notifs.length) { c.innerHTML = '<div class="empty-chat"><i class="fas fa-bell-slash"></i><p>Sin notificaciones</p></div>'; return; }
            const iconos = {
                'nuevo_proyecto':    '<i class="fas fa-folder-plus"        style="color:#003d5c"></i>',
                'boom_guardado':     '<i class="fas fa-save"                style="color:#4CAF50"></i>',
                'nueva_requisicion': '<i class="fas fa-clipboard-list"      style="color:#7b1fa2"></i>',
                'cotizacion_enviada':'<i class="fas fa-paper-plane"         style="color:#0097a7"></i>',
                'cotizacion_recibida':'<i class="fas fa-file-invoice"       style="color:#1565c0"></i>',
                'orden_generada':    '<i class="fas fa-truck"               style="color:#e64a19"></i>',
                'proyecto_almacen':  '<i class="fas fa-warehouse"           style="color:#ff5722"></i>',
                'stock_insuficiente':'<i class="fas fa-exclamation-triangle" style="color:#f44336"></i>',
                'bom_sincronizado':  '<i class="fas fa-code-branch"         style="color:#009688"></i>',
                'bom_modificado':    '<i class="fas fa-unlock"              style="color:#f57c00"></i>',
                'bom_finalizado':    '<i class="fas fa-clipboard-check"     style="color:#2e7d32"></i>',
                'oc_aprobada':       '<i class="fas fa-check-circle"        style="color:#2e7d32"></i>',
                'oc_recotizacion':   '<i class="fas fa-times-circle"        style="color:#c62828"></i>',
                'mercancia_recibida':'<i class="fas fa-box-open"            style="color:#2e7d32"></i>',
                'ot_problema':       '<i class="fas fa-exclamation-triangle" style="color:#f44336"></i>',
                'orden_produccion_difundida':'<i class="fas fa-broadcast-tower" style="color:#003d5c"></i>',
            };
            let html = '';
            notifs.forEach(n => {
                const icono = iconos[n.tipo] || '<i class="fas fa-bell" style="color:#999"></i>';
                const leido = n.leido == 1;
                html += `<div class="notification-item${leido ? '' : ' notif-unread'}" onclick="marcarNotificacion(${n.id},'${n.link||''}')"><div class="notification-title">${icono} ${escapeHtml(n.titulo)}</div><div class="notification-message">${escapeHtml(n.mensaje)}</div><div class="notification-time">${new Date(n.fecha_creacion).toLocaleString()}</div></div>`;
            });
            c.innerHTML = html;
        }
        function marcarNotificacion(id, link) { fetch('includes/ajax_chat.php?action=marcar_notificacion',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}`}).then(()=>{ if(link) window.location.href=link; else cargarNotificaciones(); }); }
        function handleChatKeyPress(e) { if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();enviarMensaje();} }
        function verImagen(ruta) { const modal = document.getElementById('imagePreviewModal'); document.getElementById('previewImage').src = ruta; modal.classList.add('active'); }
        function verPdf(ruta) { document.getElementById('filePreviewFrame').src = ruta; document.getElementById('filePreviewModal').classList.add('active'); }
        function descargarArchivo(ruta, nombre) { const a = document.createElement('a'); a.href = ruta; a.download = nombre; document.body.appendChild(a); a.click(); document.body.removeChild(a); }
        function cerrarPreviewImagen(e) { if(!e||e.target===document.getElementById('imagePreviewModal')||e.target.classList.contains('close-preview')){document.getElementById('imagePreviewModal').classList.remove('active');} }
        function cerrarPreviewArchivo() { document.getElementById('filePreviewModal').classList.remove('active'); document.getElementById('filePreviewFrame').src=''; }
        function escapeHtml(t) { if(!t) return ''; const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

        // ===== MODAL SUBIR COTIZACIÓN =====
        let _cotizacionIdActual = 0;
        let _sessionQueue = []; // Cotizaciones analizadas en esta sesión del modal
        // Lista de cotizaciones pendientes para el selector del modal
        const _cotsPendientes = <?php echo json_encode(
            array_map(fn($c) => ['id' => $c['id'], 'proveedor' => $c['prov_nombre'], 'estado' => $c['estado']],
            $cotizaciones_proy ?? [])
        ); ?>;
        // ── CONFIRM MODAL (reemplaza confirm()) — devuelve Promise<bool> ─────────
        function confirmarModal(mensaje) {
            return new Promise(resolve => {
                const modal = document.getElementById('modalConfirmarGenerico');
                if (!modal) { resolve(confirm(mensaje)); return; }
                document.getElementById('modalConfirmarMensaje').textContent = mensaje;
                modal.classList.add('active');
                const si  = document.getElementById('btnConfirmarSi');
                const no  = document.getElementById('btnConfirmarNo');
                const cleanup = (val) => { modal.classList.remove('active'); si.onclick = null; no.onclick = null; resolve(val); };
                si.onclick = () => cleanup(true);
                no.onclick = () => cleanup(false);
            });
        }

        // ── MODAL GENÉRICO DE ALERTA (reemplaza alert()) ──────────────────────────
        function mostrarModalAlerta(tipo, titulo, mensaje, onAceptar) {
            const modal = document.getElementById('modalAlertaGenerico');
            if (!modal) { alert((titulo ? titulo + ': ' : '') + mensaje); if (onAceptar) onAceptar(); return; }
            const icon  = tipo === 'error' ? 'fa-times-circle' : (tipo === 'success' ? 'fa-check-circle' : 'fa-info-circle');
            const color = tipo === 'error' ? '#c62828' : (tipo === 'success' ? '#2e7d32' : '#1565c0');
            modal.querySelector('.modal-alerta-titulo').innerHTML = `<i class="fas ${icon}" style="color:${color}"></i> ${escapeHtml(titulo)}`;
            modal.querySelector('.modal-alerta-mensaje').innerHTML = escapeHtml(mensaje).replace(/\n/g, '<br>');
            modal.classList.add('active');
            if (onAceptar) {
                const btn = modal.querySelector('.modal-footer .btn-primary');
                btn.addEventListener('click', onAceptar, { once: true });
            }
        }

        // ── MODAL RESULTADO DE ENVÍO DE EMAIL ────────────────────────────────────
        function mostrarModalEmailResultado(res) {
            const modal = document.getElementById('modalEmailResultado');
            const body  = document.getElementById('modalEmailResultadoBody');
            if (!modal || !body) {
                // Fallback si el modal no existe
                let info = 'Solicitudes registradas.\n';
                if (res.emails_ok?.length) info += '✓ Enviados: ' + res.emails_ok.join(', ') + '\n';
                if (res.emails_fail?.length) info += '⚠ Fallaron: ' + res.emails_fail.map(e=>e.nombre).join(', ');
                alert(info);
                window.location.reload();
                return;
            }
            let html = '';

            if (res.emails_ok?.length) {
                html += `<div style="margin-bottom:14px">
                    <div style="font-weight:600;color:#2e7d32;margin-bottom:6px"><i class="fas fa-check-circle"></i> Emails enviados correctamente</div>
                    <ul style="margin:0;padding-left:20px;font-size:13px">` +
                    res.emails_ok.map(e => `<li>${escapeHtml(e)}</li>`).join('') +
                    `</ul></div>`;
            }

            if (res.emails_fail?.length) {
                html += `<div style="margin-bottom:14px">
                    <div style="font-weight:600;color:#e65100;margin-bottom:8px"><i class="fas fa-exclamation-triangle"></i> Falló el envío automático — envíe manualmente</div>`;
                res.emails_fail.forEach((f, idx) => {
                    const asunto = encodeURIComponent(f.asunto || 'Solicitud de cotización');
                    const cuerpo = encodeURIComponent(f.cuerpo || '');
                    const mailto = `mailto:${encodeURIComponent(f.email)}?subject=${asunto}&body=${cuerpo}`;
                    // Store body in a data attribute to avoid quote escaping issues
                    html += `<div style="border:1px solid #ffb74d;border-radius:8px;padding:12px 14px;margin-bottom:10px;background:#fff8e1">
                        <div style="font-weight:600;margin-bottom:4px">${escapeHtml(f.nombre)}</div>
                        <div style="font-size:12px;color:#666;margin-bottom:8px">${escapeHtml(f.email)}</div>
                        <a href="${mailto}" class="btn btn-sm" style="background:#e65100;color:white;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;font-size:13px" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Abrir en cliente de correo
                        </a>
                        <button class="btn btn-sm btn-outline email-cuerpo-btn" data-idx="${idx}" style="margin-left:8px;font-size:13px" title="Copiar cuerpo del email">
                            <i class="fas fa-copy"></i> Copiar texto
                        </button>
                    </div>`;
                });
                html += `</div>`;
            }

            if (res.sin_email?.length) {
                html += `<div style="margin-bottom:14px">
                    <div style="font-weight:600;color:#555;margin-bottom:6px"><i class="fas fa-info-circle"></i> Sin email configurado</div>
                    <ul style="margin:0;padding-left:20px;font-size:13px;color:#777">` +
                    res.sin_email.map(n => `<li>${escapeHtml(n)}</li>`).join('') +
                    `</ul></div>`;
            }

            body.innerHTML = html || '<p style="color:#999">Sin detalles adicionales.</p>';

            // Attach copy handlers using data-idx to avoid HTML attribute escaping issues
            if (res.emails_fail?.length) {
                body.querySelectorAll('.email-cuerpo-btn').forEach(btn => {
                    const idx = parseInt(btn.dataset.idx);
                    btn.addEventListener('click', () => copiarTexto(res.emails_fail[idx]?.cuerpo || ''));
                });
            }

            modal.classList.add('active');
        }

        function copiarTexto(texto) {
            navigator.clipboard.writeText(texto).then(() => {
                mostrarModalAlerta('success', 'Copiado', 'Texto copiado al portapapeles.');
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = texto; document.body.appendChild(ta);
                ta.select(); document.execCommand('copy');
                document.body.removeChild(ta);
                mostrarModalAlerta('success', 'Copiado', 'Texto copiado al portapapeles.');
            });
        }

        function cerrarModalEmailResultado() {
            document.getElementById('modalEmailResultado').classList.remove('active');
            window.location.reload();
        }

        function abrirModalCotizacion(cotizacionId) {
            _cotizacionIdActual = cotizacionId || 0;
            _sessionQueue = [];
            document.getElementById('modalCotizacion').classList.add('active');
            resetUploadZone();
            actualizarSelectorProveedor();
            actualizarListaQueue();
        }
        function cerrarModalCotizacion() {
            document.getElementById('modalCotizacion').classList.remove('active');
        }
        function resetUploadZone() {
            const fi = document.getElementById('cotizacionFileInput');
            if (fi) fi.value = '';
            document.getElementById('cotizacionDropLabel').innerHTML = '<i class="fas fa-cloud-upload-alt"></i><p><strong>Arrastra el archivo aquí</strong> o haz clic para seleccionar</p><small>PDF, JPG, PNG, WEBP – máx. 10 MB</small>';
            document.getElementById('cotizacionResult').style.display = 'none';
            document.getElementById('cotizacionError').style.display = 'none';
            document.getElementById('cotizacionLoading').style.display = 'none';
        }

        function actualizarSelectorProveedor() {
            const sel = document.getElementById('cot-prov-selector');
            if (!sel) return;
            sel.innerHTML = '<option value="0">— Sin proveedor específico —</option>';
            _cotsPendientes.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.proveedor + (c.estado === 'recibida' ? ' ✓' : '');
                if (c.id === _cotizacionIdActual) opt.selected = true;
                sel.appendChild(opt);
            });
            sel.onchange = () => { _cotizacionIdActual = parseInt(sel.value) || 0; };
        }

        function actualizarListaQueue() {
            const lista = document.getElementById('cot-queue-lista');
            if (!lista) return;
            const cont = document.getElementById('cot-queue-container');
            if (_sessionQueue.length === 0) {
                cont.style.display = 'none';
                return;
            }
            cont.style.display = 'block';
            lista.innerHTML = _sessionQueue.map((q, i) => `
                <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #c8e6c9;border-radius:6px;background:#f0faf2;margin-bottom:6px">
                    <i class="fas fa-check-circle" style="color:#43a047;font-size:18px"></i>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;font-size:13px">${escapeHtml(q.proveedor || 'Proveedor no identificado')}</div>
                        <div style="font-size:11px;color:#666">${escapeHtml(q.archivo)} · Total: ${q.total ? '$'+Number(q.total).toLocaleString('es-MX',{minimumFractionDigits:2}) : '-'}</div>
                    </div>
                    <span style="font-size:11px;color:#43a047;font-weight:600">GUARDADO</span>
                </div>`).join('');
            document.getElementById('btnConfirmarTodo').style.display = 'inline-flex';
        }

        function agregarOtraCotizacion() {
            resetUploadZone();
            // Reset selector para que el usuario elija el siguiente proveedor
            const sel = document.getElementById('cot-prov-selector');
            if (sel) { sel.value = '0'; _cotizacionIdActual = 0; }
        }

        function confirmarTodoCotizaciones() {
            cerrarModalCotizacion();
            window.location.reload();
        }
        function handleCotizacionDrop(e) {
            e.preventDefault();
            document.getElementById('cotizacionDropZone').classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file) procesarArchivoCotizacion(file);
        }
        function handleCotizacionFile(input) {
            if (input.files && input.files[0]) procesarArchivoCotizacion(input.files[0]);
        }
        // Configura worker de PDF.js
        if (typeof pdfjsLib !== 'undefined') {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        async function pdfAPaginas(file) {
            // Devuelve array de strings base64 JPEG, uno por página
            if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js no disponible');
            const arrayBuffer = await file.arrayBuffer();
            const pdf      = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            const numPages = pdf.numPages;
            const scale    = 1.6;
            const paginas  = [];

            for (let i = 1; i <= numPages; i++) {
                const loadingP = document.querySelector('#cotizacionLoading p');
                if (loadingP) loadingP.textContent =
                    `Convirtiendo PDF… página ${i} de ${numPages}`;

                const page     = await pdf.getPage(i);
                const viewport = page.getViewport({ scale });
                const c = document.createElement('canvas');
                c.width  = viewport.width;
                c.height = viewport.height;
                const ctx = c.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, c.width, c.height);
                await page.render({ canvasContext: ctx, viewport }).promise;

                // Convertir a base64 JPEG directamente desde el canvas
                const b64 = c.toDataURL('image/jpeg', 0.85).split(',')[1];
                paginas.push(b64);
            }
            return paginas;
        }

        async function extraerTextoPdfNativo(file) {
            // Texto seleccionable del PDF vía PDF.js (getTextContent) — 0 tokens de visión.
            // Devuelve '' si el PDF no trae texto (escaneado/imagen).
            if (typeof pdfjsLib === 'undefined') return '';
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            let texto = '';
            for (let i = 1; i <= pdf.numPages; i++) {
                const page = await pdf.getPage(i);
                const content = await page.getTextContent();
                texto += content.items.map(it => it.str).join(' ') + '\n';
            }
            return texto;
        }

        async function procesarArchivoCotizacion(file) {
            const esPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            const allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!allowed.includes(file.type) && !esPdf) {
                mostrarErrorCotizacion('Tipo de archivo no soportado. Use PDF, JPG o PNG.');
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                mostrarErrorCotizacion('El archivo es demasiado grande (máx. 10 MB).');
                return;
            }

            document.getElementById('cotizacionDropLabel').innerHTML =
                `<i class="fas fa-file" style="color:#FF9800"></i><p><strong>${escapeHtml(file.name)}</strong></p><small>${(file.size/1024).toFixed(1)} KB</small>`;
            document.getElementById('cotizacionResult').style.display = 'none';
            document.getElementById('cotizacionError').style.display = 'none';
            document.getElementById('cotizacionLoading').style.display = 'block';
            document.querySelector('#cotizacionLoading p').textContent =
                esPdf ? 'Leyendo texto del PDF…' : 'Analizando documento con IA…';

            const fd = new FormData();

            if (esPdf) {
                let textoNativo = '';
                try { textoNativo = await extraerTextoPdfNativo(file); }
                catch (err) { console.warn('Extracción de texto nativo falló:', err.message); }

                if (textoNativo.trim().length > 100) {
                    // Texto nativo suficiente: se manda como texto, sin gastar tokens de visión
                    fd.append('texto_ocr', textoNativo.substring(0, 15000));
                    fd.append('archivo', new File([''], 'doc.txt', { type: 'text/plain' }));
                    document.querySelector('#cotizacionLoading p').textContent = 'Analizando texto…';
                } else {
                    // Sin texto seleccionable (escaneado): convertir a imagen para visión
                    try {
                        document.querySelector('#cotizacionLoading p').textContent = 'Convirtiendo PDF a imagen…';
                        const paginas = await pdfAPaginas(file);
                        document.querySelector('#cotizacionLoading p').textContent =
                            `Analizando ${paginas.length} página(s) con IA…`;
                        fd.append('paginas_pdf', JSON.stringify(paginas));
                        fd.append('archivo', new File([''], 'doc.jpg', { type: 'image/jpeg' }));
                        // PDF original para PyMuPDF (respaldo server-side sin tokens de visión)
                        fd.append('pdf_original', file);
                    } catch (err) {
                        console.warn('PDF.js falló, enviando PDF original:', err.message);
                        fd.append('archivo', file);
                    }
                }
            } else {
                fd.append('archivo', file);
            }
            // Pasar cotizacion_id si existe para vincular el análisis
            if (_cotizacionIdActual) fd.append('cotizacion_id', _cotizacionIdActual);

            fetch('includes/analizar_cotizacion.php', { method: 'POST', body: fd })
                .then(r => r.text())
                .then(texto => {
                    document.getElementById('cotizacionLoading').style.display = 'none';
                    let data;
                    try {
                        data = JSON.parse(texto);
                    } catch(e) {
                        mostrarErrorCotizacion('Error PHP: ' + texto.substring(0, 400));
                        return;
                    }
                    if (data.success) {
                        mostrarResultadoCotizacion(data.cotizacion, file.name);
                        // Mostrar botón "Agregar otro" y ocultar btnGuardarCotizacion antiguo
                        const btnAgregar = document.getElementById('btnAgregarOtra');
                        if (btnAgregar) btnAgregar.style.display = 'inline-flex';
                    } else {
                        mostrarErrorCotizacion(data.error || 'No se pudo analizar el archivo.');
                    }
                })
                .catch(e => {
                    document.getElementById('cotizacionLoading').style.display = 'none';
                    mostrarErrorCotizacion('Error de red: ' + e.message);
                });
        }
        function mostrarErrorCotizacion(msg) {
            const el = document.getElementById('cotizacionError');
            el.textContent = msg;
            el.style.display = 'block';
        }
        function mostrarResultadoCotizacion(cot, archivoNombre) {
            // Agregar a la cola de la sesión
            const provLabel = _cotsPendientes.find(c => c.id === _cotizacionIdActual)?.proveedor || cot.proveedor || '';
            _sessionQueue.push({ cotizacion_id: _cotizacionIdActual, proveedor: provLabel || cot.proveedor, archivo: archivoNombre || 'archivo', total: cot.total });
            actualizarListaQueue();

            const box = document.getElementById('cotizacionResult');
            let productosHtml = '';
            if (cot.productos && cot.productos.length > 0) {
                productosHtml = `<table class="cot-productos-table">
                    <thead><tr><th>Producto / Descripción</th><th>Cantidad</th><th>Precio Unit.</th><th>Subtotal</th></tr></thead>
                    <tbody>`;
                cot.productos.forEach(p => {
                    productosHtml += `<tr>
                        <td>${escapeHtml(p.descripcion || p.nombre || '-')}</td>
                        <td style="text-align:center">${escapeHtml(String(p.cantidad || '-'))}</td>
                        <td style="text-align:right">${p.precio_unitario ? '$' + Number(p.precio_unitario).toLocaleString('es-MX', {minimumFractionDigits:2}) : '-'}</td>
                        <td style="text-align:right">${p.subtotal ? '$' + Number(p.subtotal).toLocaleString('es-MX', {minimumFractionDigits:2}) : '-'}</td>
                    </tr>`;
                });
                productosHtml += '</tbody></table>';
            } else {
                productosHtml = '<p style="color:#999;font-size:13px">No se encontraron productos en el documento.</p>';
            }
            const fmt = v => (v != null && v !== '') ? '$' + Number(v).toLocaleString('es-MX', {minimumFractionDigits:2}) : '-';
            box.querySelector('#cot-proveedor').textContent  = cot.proveedor  || '-';
            box.querySelector('#cot-fecha').textContent      = cot.fecha      || '-';
            box.querySelector('#cot-referencia').textContent = cot.referencia || '-';
            box.querySelector('#cot-subtotal').textContent   = fmt(cot.subtotal);
            box.querySelector('#cot-descuento').textContent  = (cot.descuento && cot.descuento > 0) ? '-' + fmt(cot.descuento) : '$0.00';
            box.querySelector('#cot-iva').textContent        = fmt(cot.iva);
            box.querySelector('#cot-total').textContent      = fmt(cot.total);
            box.querySelector('#cot-notas').textContent      = cot.notas || '-';
            box.querySelector('#cot-productos-container').innerHTML = productosHtml;
            box.style.display = 'block';
            // Rellenar proveedor en el form principal si está en espera
            const provInput = document.getElementById('proveedor_nombre_input');
            if (provInput && !provInput.disabled && cot.proveedor) {
                provInput.value = cot.proveedor;
            }
            // Rellenar notas
            const notasInput = document.getElementById('notas_presupuesto');
            if (notasInput && !notasInput.disabled && cot.notas) {
                notasInput.value = cot.notas;
            }
        }
    </script>

    <!-- ===== MODAL COTIZACIÓN MANUAL ===== -->
    <div class="modal-overlay" id="modalCotManual" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="modal" style="max-width:680px;width:95%;max-height:90vh;overflow-y:auto;">
            <div class="modal-header" style="background:#e65100;">
                <div class="modal-title"><i class="fas fa-keyboard"></i> Ingresar cotización manualmente — <span id="manualProvNombre" style="font-weight:400"></span></div>
                <button class="modal-close" onclick="document.getElementById('modalCotManual').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:#666;margin-bottom:12px;">Ingresa el precio unitario por cada material. El subtotal se calculará automáticamente.</p>
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                    <thead>
                        <tr style="background:#f5f5f5;">
                            <th style="padding:8px;text-align:left;">#</th>
                            <th style="padding:8px;text-align:left;">Material</th>
                            <th style="padding:8px;text-align:center;">Cant.</th>
                            <th style="padding:8px;text-align:left;">Precio unitario</th>
                            <th style="padding:8px;text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="manualMatsTbody"></tbody>
                </table>
                <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;user-select:none;">
                        <input type="checkbox" id="manualIvaCheck" checked onchange="recalcularManual()" style="width:16px;height:16px;">
                        Incluir IVA (16%)
                    </label>
                </div>
                <div style="background:#f8f9fa;border-radius:6px;padding:12px 16px;font-size:13px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span>Subtotal:</span><strong id="manualSubtotal">$0.00</strong></div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;color:#666;"><span>IVA (16%):</span><span id="manualIva">$0.00</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;color:#003d5c;border-top:1px solid #ddd;padding-top:8px;margin-top:8px;"><span>Total:</span><span id="manualTotal">$0.00</span></div>
                </div>
                <div>
                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">Notas / Condiciones de pago:</label>
                    <textarea id="manualNotas" rows="2" style="width:100%;border:1px solid #ddd;border-radius:4px;padding:8px;font-size:13px;" placeholder="Tiempo de entrega, condiciones, observaciones..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="document.getElementById('modalCotManual').classList.remove('active')">Cancelar</button>
                <button class="btn btn-success" onclick="guardarCotizacionManual()"><i class="fas fa-save"></i> Guardar cotización</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL SUBIR COTIZACIÓN (multi-upload) ===== -->
    <div class="modal-cotizacion" id="modalCotizacion" onclick="if(event.target===this) cerrarModalCotizacion()">
        <div class="modal-cotizacion-box" style="max-width:700px;width:95%">
            <div class="modal-cotizacion-header">
                <h3><i class="fas fa-robot"></i> Subir y analizar cotizaciones</h3>
                <button class="modal-cotizacion-close" onclick="cerrarModalCotizacion()">&times;</button>
            </div>
            <div class="modal-cotizacion-body">

                <!-- Selector de proveedor -->
                <div style="margin-bottom:14px">
                    <label style="font-size:12px;color:#666;display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600">Proveedor al que pertenece esta cotización:</label>
                    <select id="cot-prov-selector" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;background:white">
                        <option value="0">— Sin proveedor específico —</option>
                    </select>
                </div>

                <!-- Drop zone -->
                <div class="drop-zone" id="cotizacionDropZone"
                    onclick="document.getElementById('cotizacionFileInput').click()"
                    ondragover="event.preventDefault();this.classList.add('dragover')"
                    ondragleave="this.classList.remove('dragover')"
                    ondrop="handleCotizacionDrop(event)">
                    <div id="cotizacionDropLabel">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p><strong>Arrastra el archivo aquí</strong> o haz clic para seleccionar</p>
                        <small>PDF, JPG, PNG, WEBP – máx. 10 MB</small>
                    </div>
                </div>
                <input type="file" id="cotizacionFileInput" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none" onchange="handleCotizacionFile(this)">

                <div id="cotizacionLoading" class="cot-loading" style="display:none">
                    <i class="fas fa-spinner"></i>
                    <p>Analizando documento con IA…</p>
                    <small style="color:#999">Puede tardar 1–3 minutos. Por favor espera.</small>
                </div>
                <div id="cotizacionError" class="cot-error" style="display:none"></div>

                <!-- Resultado del análisis actual -->
                <div class="cotizacion-result" id="cotizacionResult" style="display:none">
                    <div class="cotizacion-result-header">
                        <i class="fas fa-check-circle" style="color:#4caf50"></i>
                        Cotización identificada <span class="cot-badge-ok">IA</span>
                    </div>
                    <div class="cotizacion-result-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 30px;">
                            <div class="cot-field"><label>Proveedor</label><span id="cot-proveedor"></span></div>
                            <div class="cot-field"><label>Fecha</label><span id="cot-fecha"></span></div>
                            <div class="cot-field"><label>Referencia / Folio</label><span id="cot-referencia"></span></div>
                        </div>
                        <div style="margin-top:12px;border:1px solid #f0f0f0;border-radius:6px;padding:12px 16px;display:flex;gap:30px;flex-wrap:wrap;align-items:center;">
                            <div class="cot-field" style="margin:0"><label>Subtotal</label><span id="cot-subtotal"></span></div>
                            <div class="cot-field" style="margin:0"><label>Descuento</label><span id="cot-descuento" style="color:#e53935"></span></div>
                            <div class="cot-field" style="margin:0"><label>IVA</label><span id="cot-iva"></span></div>
                            <div class="cot-field" style="margin:0"><label>Total</label><span id="cot-total" style="font-weight:700;color:#003d5c;font-size:18px"></span></div>
                        </div>
                        <div class="cot-field" style="margin-top:12px"><label>Notas / Condiciones</label><span id="cot-notas"></span></div>
                        <div style="margin-top:15px">
                            <div style="font-size:11px;color:#999;text-transform:uppercase;font-weight:600;margin-bottom:8px">Productos</div>
                            <div id="cot-productos-container"></div>
                        </div>
                    </div>
                </div>

                <!-- Cola de cotizaciones analizadas en esta sesión -->
                <div id="cot-queue-container" style="display:none;margin-top:16px">
                    <div style="font-size:12px;color:#2e7d32;font-weight:600;margin-bottom:8px;text-transform:uppercase"><i class="fas fa-list-check"></i> Cotizaciones guardadas en esta sesión:</div>
                    <div id="cot-queue-lista"></div>
                </div>

                <!-- Botones del footer -->
                <div style="display:flex;justify-content:space-between;margin-top:20px;gap:10px;flex-wrap:wrap">
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-outline" onclick="cerrarModalCotizacion()">Cerrar</button>
                        <button id="btnAgregarOtra" class="btn btn-outline" style="display:none;color:#1565c0;border-color:#1565c0" onclick="agregarOtraCotizacion()">
                            <i class="fas fa-plus"></i> Agregar otro archivo
                        </button>
                    </div>
                    <button id="btnConfirmarTodo" class="btn btn-success" style="display:none" onclick="confirmarTodoCotizaciones()">
                        <i class="fas fa-check-double"></i> Confirmar y ver resultados
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== MODAL ALERTA GENÉRICO ===== -->
    <div class="modal-overlay" id="modalAlertaGenerico" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="modal" style="max-width:440px">
            <div class="modal-header" style="background:#003d5c">
                <h3 class="modal-alerta-titulo" style="font-size:1rem"><i class="fas fa-info-circle"></i> Aviso</h3>
                <button onclick="document.getElementById('modalAlertaGenerico').classList.remove('active')" style="background:none;border:none;color:white;font-size:20px;cursor:pointer;line-height:1">&times;</button>
            </div>
            <div class="modal-body"><p class="modal-alerta-mensaje" style="margin:0;white-space:pre-wrap;line-height:1.6"></p></div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="document.getElementById('modalAlertaGenerico').classList.remove('active')">Aceptar</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL CONFIRMAR GENÉRICO ===== -->
    <div class="modal-overlay" id="modalConfirmarGenerico">
        <div class="modal" style="max-width:440px">
            <div class="modal-header" style="background:#003d5c">
                <h3 style="font-size:1rem"><i class="fas fa-question-circle"></i> Confirmar</h3>
            </div>
            <div class="modal-body"><p id="modalConfirmarMensaje" style="margin:0;line-height:1.6"></p></div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="btnConfirmarNo">Cancelar</button>
                <button class="btn btn-primary" id="btnConfirmarSi">Confirmar</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL RESULTADO ENVÍO EMAIL ===== -->
    <!-- Modal: Generar OC con fecha estimada -->
    <div class="modal-overlay" id="modalGenerarOC">
        <div class="modal" style="max-width:480px">
            <div class="modal-header" style="background:#003d5c">
                <h3><i class="fas fa-shopping-cart"></i> Generar órdenes de compra</h3>
                <button class="modal-close" onclick="document.getElementById('modalGenerarOC').classList.remove('active')" style="color:white;background:none;border:none;font-size:20px;cursor:pointer">&times;</button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
                <p id="modalGenerarOCInfo" style="margin:0;color:#555;font-size:14px;"></p>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#333;margin-bottom:5px;">
                        <i class="fas fa-calendar-alt" style="color:#003d5c"></i> Fecha estimada de entrega
                    </label>
                    <input type="date" id="inputFechaEntregaOC"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px;box-sizing:border-box;">
                    <p style="margin:5px 0 0;font-size:12px;color:#888;">Puedes dejarla en blanco si aún no tienes fecha confirmada.</p>
                </div>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#333;margin-bottom:5px;">
                        <i class="fas fa-credit-card" style="color:#003d5c"></i> Condiciones de pago
                    </label>
                    <select id="inputCondicionesPago"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px;box-sizing:border-box;">
                        <option value="Crédito">Crédito</option>
                        <option value="Contado">Contado</option>
                        <option value="50/50">50/50 — 50% anticipo, 50% entrega</option>
                        <option value="60/40">60/40 — 60% anticipo, 40% entrega</option>
                        <option value="70/30">70/30 — 70% anticipo, 30% entrega</option>
                        <option value="80/20">80/20 — 80% anticipo, 20% entrega</option>
                        <option value="30/70">30/70 — 30% anticipo, 70% entrega</option>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#333;margin-bottom:5px;">
                        <i class="fas fa-sticky-note" style="color:#003d5c"></i> Notas para el proveedor
                    </label>
                    <textarea id="inputNotasOC" rows="4"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-size:13px;box-sizing:border-box;resize:vertical;">please send with the procedure next documents/ Al entregar los productos por favor incluya los siguientes documentos: 1- Invoice / Factura</textarea>
                </div>
            </div>
            <div class="modal-footer" style="gap:10px;display:flex;justify-content:flex-end">
                <button class="btn btn-outline" onclick="document.getElementById('modalGenerarOC').classList.remove('active')">Cancelar</button>
                <button class="btn btn-success" id="btnConfirmarGenerarOC" onclick="_confirmarGenerarOC()">
                    <i class="fas fa-check"></i> Confirmar y generar
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalEmailResultado" onclick="if(event.target===this) cerrarModalEmailResultado()">
        <div class="modal" style="max-width:600px">
            <div class="modal-header" style="background:#003d5c">
                <h3><i class="fas fa-paper-plane"></i> Resultado del envío de solicitudes</h3>
                <button class="modal-close" onclick="cerrarModalEmailResultado()" style="color:white;background:none;border:none;font-size:20px;cursor:pointer">&times;</button>
            </div>
            <div class="modal-body" id="modalEmailResultadoBody">
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="cerrarModalEmailResultado()">
                    <i class="fas fa-arrow-right"></i> Continuar
                </button>
            </div>
        </div>
    </div>
<script>
/* Validación unicidad código de material */
(function() {
    var campo = document.getElementById('materialCodigo') || document.querySelector('input[name="codigo"]');
    if (!campo) return;
    if (!campo.id) campo.id = 'campoCodigo';

    var feedback = document.createElement('small');
    feedback.style.cssText = 'display:block;margin-top:4px;font-size:0.8rem';
    campo.parentNode.appendChild(feedback);

    campo.addEventListener('blur', function() {
        var codigo    = this.value.trim();
        var excludeId = (document.getElementById('materialId') || {}).value || this.dataset.excludeId || 0;
        if (!codigo) { feedback.textContent = ''; return; }

        feedback.textContent = '⏳ Verificando...';
        feedback.style.color = '#888';

        fetch('api/materiales.php?action=check_codigo&codigo=' + encodeURIComponent(codigo) + '&exclude_id=' + excludeId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.disponible) {
                    feedback.textContent = '✓ Código disponible';
                    feedback.style.color = '#2E7D32';
                } else {
                    feedback.textContent = '✗ Este código ya está en uso';
                    feedback.style.color = '#c0392b';
                }
            })
            .catch(function() { feedback.textContent = ''; });
    });
})();

/* Sugerencia de código de solo lectura (quien no es jefe de Almacén no puede escribirlo) --
   se recalcula al escribir la Descripción, solo para material NUEVO (no pisar el código
   real de un material que ya se está editando). */
<?php if (!$puedeAsignarCodigoMaterial): ?>
(function() {
    var desc = document.getElementById('materialDescripcion');
    var cod  = document.getElementById('materialCodigo');
    if (!desc || !cod) return;
    var timer = null;
    desc.addEventListener('input', function() {
        if (document.getElementById('materialId').value) return; // editando, no tocar
        clearTimeout(timer);
        timer = setTimeout(function() {
            var texto = desc.value.trim();
            if (!texto) { cod.value = ''; return; }
            fetch('api/materiales.php?action=sugerir_codigo&descripcion=' + encodeURIComponent(texto))
                .then(function(r) { return r.json(); })
                .then(function(data) { cod.value = data.codigo || ''; })
                .catch(function() {});
        }, 400);
    });
})();
<?php endif; ?>

/* Buscador de modelos ya existentes en el catálogo (1547 distintos, 2026-08-25) -- se
   filtra en el servidor conforme se escribe, para detectar que un modelo ya existe antes
   de darlo de alta duplicado con otra capitalización/espacios. */
(function() {
    var campo = document.getElementById('materialModelo');
    var dl = document.getElementById('modelosDatalist');
    if (!campo || !dl) return;
    var timer = null;
    campo.addEventListener('input', function() {
        clearTimeout(timer);
        var q = campo.value.trim();
        if (q.length < 2) { dl.innerHTML = ''; return; }
        timer = setTimeout(function() {
            fetch('api/materiales.php?action=buscar_modelos&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    dl.innerHTML = (data || []).map(function(m) {
                        return '<option value="' + esc(m) + '">';
                    }).join('');
                })
                .catch(function() {});
        }, 300);
    });
})();

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

async function verArchivosCotizacion(proyectoId) {
    const modal = document.getElementById('modalArchivosCot');
    const lista = document.getElementById('listaArchivosCot');
    modal.style.display = 'flex';
    lista.innerHTML = '<p style="text-align:center;color:#bbb;padding:20px;">Cargando...</p>';

    try {
        const r = await fetch('api/listar_archivos_cotizacion.php?proyecto_id=' + proyectoId);
        const files = await r.json();

        if (files.length === 0) {
            lista.innerHTML = '<p style="color:#bbb;text-align:center;padding:30px;"><i class="fas fa-folder-open" style="display:block;font-size:2rem;margin-bottom:8px;"></i>No hay archivos PDF en esta carpeta.</p>';
            return;
        }

        lista.innerHTML = files.map(f => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 4px;border-bottom:1px solid #f5f5f5;">
                <div>
                    <div style="font-weight:600;font-size:13px;color:#003d5c;">
                        <i class="fas fa-file-pdf" style="color:#e53935;margin-right:6px;"></i>${esc(f.nombre)}
                    </div>
                    <div style="font-size:11px;color:#aaa;margin-top:2px;">${esc(f.fecha)} &middot; ${esc(f.size)}</div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <a href="api/descargar_cotizacion.php?proyecto_id=${f.pid}&archivo=${encodeURIComponent(f.nombre)}" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver</a>
                    <a href="api/descargar_cotizacion.php?proyecto_id=${f.pid}&archivo=${encodeURIComponent(f.nombre)}" download="${esc(f.nombre)}" class="btn btn-outline btn-sm"><i class="fas fa-download"></i></a>
                </div>
            </div>
        `).join('');
    } catch(e) {
        lista.innerHTML = '<p style="color:#e53935;text-align:center;padding:20px;">Error al cargar archivos.</p>';
    }
}

function showTabErp(el) {
            var projId = el.getAttribute('data-proj');
            var key    = el.getAttribute('data-key');
            var roCont = document.getElementById('readonly-tabs-' + projId);
            var actPanel = document.getElementById('active-panel-' + projId);
            if (!roCont || !actPanel) return;

            if (el.classList.contains('active')) {
                roCont.style.display = 'none';
                actPanel.style.display = '';
            } else {
                roCont.querySelectorAll('.readonly-panel').forEach(function(p) { p.style.display = 'none'; });
                roCont.style.display = 'block';
                actPanel.style.display = 'none';
                var panel = document.getElementById('tab-' + key + '-' + projId);
                if (panel) panel.style.display = 'block';
            }
        }

        async function reenviarOC(ordenId, btn) {
    if (!confirm('¿Re-enviar esta OC a aprobación?')) return;
    btn.disabled = true;
    try {
        const r = await fetch('api/reenviar_oc.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({orden_id: ordenId})
        });
        const data = await r.json();
        if (data.ok) window.location.reload();
        else alert('Error: ' + (data.error || 'desconocido'));
    } catch(e) {
        alert('Error de conexión');
        btn.disabled = false;
    }
}
</script>

<!-- Modal archivos cotizaciones -->
<div id="modalArchivosCot" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:540px;width:92%;max-height:75vh;display:flex;flex-direction:column;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid #eee;">
            <h3 style="margin:0;color:#003d5c;font-size:1rem;"><i class="fas fa-folder-open"></i> Archivos de cotizaciones</h3>
            <button onclick="document.getElementById('modalArchivosCot').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer;color:#888;">&times;</button>
        </div>
        <div id="listaArchivosCot" style="padding:16px;overflow-y:auto;flex:1;">
            <p style="text-align:center;color:#bbb;">Cargando...</p>
        </div>
    </div>
</div>
</body>
</html>