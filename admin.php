<?php
/**
 * Panel de Administración
 * Unifica auditoría y gestión de usuarios con el nuevo diseño
 */

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

// Verificar login y permisos de admin
if (!isLoggedIn() || !hasAcceso('admin')) {
    header('Location: index.php');
    exit();
}


// Establecer modo por defecto si no existe
if (!isset($_SESSION['admin_mode'])) {
    $_SESSION['admin_mode'] = 'normal';
}

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;

$db = getDB();
$message = '';
$error = '';

// ============================================
// PROCESAMIENTO DE ACCIONES
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        
        case 'guardar_usuario':
            try {
                $id = $_POST['id'] ?? null;
                $username = sanitize($_POST['username']);
                $nombre_completo = sanitize($_POST['nombre_completo']);
                $email = sanitize($_POST['email'] ?? '');
                $tipo_usuario = sanitize($_POST['tipo_usuario']);
                $password = $_POST['password'] ?? '';
                
                if (empty($username) || empty($nombre_completo) || empty($tipo_usuario)) {
                    throw new Exception("Todos los campos obligatorios deben ser llenados");
                }
                
                if (!$id) {
                    $check = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
                    $check->execute([$username]);
                    if ($check->fetch()) {
                        throw new Exception("El nombre de usuario ya está en uso");
                    }
                    
                    if (empty($password)) {
                        throw new Exception("La contraseña es obligatoria para nuevos usuarios");
                    }
                }
                
                $telefono = sanitize($_POST['telefono'] ?? '');
                $accesos = isset($_POST['accesos']) ? $_POST['accesos'] : [];
                $accesos_str = json_encode($accesos);

                // Nivel jerárquico (trabajador/jefe) por cada acceso marcado -- ver
                // usuario_niveles / nivelUsuario() en includes/config.php. Reemplaza
                // todas las filas del usuario cada vez, mismo criterio que accesos
                // (se sobreescribe completo, no se hace merge parcial).
                $niveles_post = [];
                foreach ($accesos as $modulo) {
                    $nivel = $_POST['nivel_' . $modulo] ?? 'trabajador';
                    $niveles_post[$modulo] = in_array($nivel, ['trabajador', 'jefe'], true) ? $nivel : 'trabajador';
                }

                if ($id) {
                    if (!empty($password)) {
                        $hashed_password = hash('sha256', $password);
                        $stmt = $db->prepare("UPDATE usuarios SET username = ?, password = ?, nombre_completo = ?, email = ?, tipo_usuario = ?, accesos = ?, telefono = ? WHERE id = ?");
                        $stmt->execute([$username, $hashed_password, $nombre_completo, $email, $tipo_usuario, $accesos_str, $telefono ?: null, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE usuarios SET username = ?, nombre_completo = ?, email = ?, tipo_usuario = ?, accesos = ?, telefono = ? WHERE id = ?");
                        $stmt->execute([$username, $nombre_completo, $email, $tipo_usuario, $accesos_str, $telefono ?: null, $id]);
                    }
                    
                    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'actualizar', 'usuarios', ?, ?)")
                       ->execute([$_SESSION['user_id'], $id, "Usuario actualizado: $username"]);

                    $usuario_id_guardado = (int) $id;
                    $message = showMessage("Usuario actualizado correctamente", 'success');
                } else {
                    $hashed_password = hash('sha256', $password);
                    $stmt = $db->prepare("INSERT INTO usuarios (username, password, nombre_completo, email, tipo_usuario, accesos, telefono, activo, fecha_creacion) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                    $stmt->execute([$username, $hashed_password, $nombre_completo, $email, $tipo_usuario, $accesos_str, $telefono ?: null]);
                    $new_id = $db->lastInsertId();

                    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'usuarios', ?, ?)")
                       ->execute([$_SESSION['user_id'], $new_id, "Usuario creado: $username"]);

                    $usuario_id_guardado = (int) $new_id;
                    $message = showMessage("Usuario creado correctamente", 'success');
                }

                // Reemplaza completo (mismo criterio que accesos): borra los niveles
                // viejos del usuario y deja solo los de los accesos actuales.
                $db->prepare("DELETE FROM usuario_niveles WHERE usuario_id = ?")->execute([$usuario_id_guardado]);
                if ($niveles_post) {
                    $stmtNivel = $db->prepare("INSERT INTO usuario_niveles (usuario_id, modulo, nivel) VALUES (?, ?, ?)");
                    foreach ($niveles_post as $modulo => $nivel) {
                        $stmtNivel->execute([$usuario_id_guardado, $modulo, $nivel]);
                    }
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'eliminar_usuario':
            try {
                $id = intval($_POST['id']);
                
                if ($id == $_SESSION['user_id']) {
                    throw new Exception("No puedes eliminar tu propio usuario");
                }
                
                $stmt = $db->prepare("SELECT username FROM usuarios WHERE id = ?");
                $stmt->execute([$id]);
                $username = $stmt->fetchColumn();

                // Registrar en auditoría ANTES de borrar para no perder la referencia
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'eliminar', 'usuarios', ?, ?)")
                   ->execute([$_SESSION['user_id'], $id, "Usuario eliminado: $username"]);

                // Eliminar registros dependientes respetando FK y columnas NOT NULL

                // 1. Tablas hijas de ordenes_compra del usuario
                $db->prepare("DELETE ocd FROM orden_compra_detalle ocd
                               INNER JOIN ordenes_compra oc ON ocd.orden_id = oc.id
                               WHERE oc.usuario_id = ?")->execute([$id]);

                // 2. Tablas hijas de ordenes_compra que referencian requisiciones del usuario
                $db->prepare("DELETE ocd FROM orden_compra_detalle ocd
                               INNER JOIN ordenes_compra oc ON ocd.orden_id = oc.id
                               INNER JOIN requisiciones r ON oc.requisicion_id = r.id
                               WHERE r.usuario_id = ?")->execute([$id]);

                // 3. ordenes_compra del usuario (NOT NULL, no se puede nullificar)
                $db->prepare("DELETE FROM ordenes_compra WHERE usuario_id = ?")->execute([$id]);

                // 4. ordenes_compra que referencian requisiciones del usuario
                $db->prepare("DELETE oc FROM ordenes_compra oc
                               INNER JOIN requisiciones r ON oc.requisicion_id = r.id
                               WHERE r.usuario_id = ?")->execute([$id]);

                // 5. requisicion_detalle de las requisiciones del usuario
                $db->prepare("DELETE rd FROM requisicion_detalle rd
                               INNER JOIN requisiciones r ON rd.requisicion_id = r.id
                               WHERE r.usuario_id = ?")->execute([$id]);

                // 6. requisiciones del usuario (NOT NULL, no se puede nullificar)
                $db->prepare("DELETE FROM requisiciones WHERE usuario_id = ?")->execute([$id]);

                // 7. Resto de tablas con columnas nullable o simples
                $db->prepare("DELETE FROM auditoria WHERE usuario_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM notificaciones WHERE usuario_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM usuarios_estado WHERE usuario_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM chat_mensajes WHERE remitente_id = ? OR destinatario_id = ?")->execute([$id, $id]);
                $db->prepare("DELETE FROM chat_conversaciones WHERE usuario_id = ? OR contacto_id = ?")->execute([$id, $id]);
                $db->prepare("DELETE FROM historial_movimientos WHERE usuario_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM almacen_movimientos WHERE usuario_id = ?")->execute([$id]);
                $db->prepare("UPDATE almacen_items SET agregado_por = NULL WHERE agregado_por = ?")->execute([$id]);
                $db->prepare("UPDATE boom_items SET agregado_por = NULL WHERE agregado_por = ?")->execute([$id]);
                $db->prepare("UPDATE proyectos SET creado_por = NULL WHERE creado_por = ?")->execute([$id]);

                $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
                $stmt->execute([$id]);
                
                $message = showMessage("Usuario eliminado correctamente", 'success');
                
            } catch (Exception $e) {
                $error = showMessage("Error al eliminar usuario: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'toggle_usuario':
            try {
                $id = intval($_POST['id']);
                $estado = intval($_POST['estado']);
                
                if ($id == $_SESSION['user_id'] && $estado == 0) {
                    throw new Exception("No puedes desactivar tu propio usuario");
                }
                
                $stmt = $db->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
                $stmt->execute([$estado, $id]);
                
                $accion = $estado ? 'activar' : 'desactivar';
                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, ?, 'usuarios', ?, ?)")
                   ->execute([$_SESSION['user_id'], $accion, $id, "Usuario $accionado"]);
                
                $message = showMessage("Usuario " . ($estado ? "activado" : "desactivado") . " correctamente", 'success');
                
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
    }
}

// ============================================
// OBTENER DATOS PARA LAS VISTAS
// ============================================

$seccion = isset($_GET['seccion']) ? $_GET['seccion'] : '';

$usuarios = $db->query("SELECT * FROM usuarios ORDER BY nombre_completo")->fetchAll();

// Nivel jerárquico (trabajador/jefe) por usuario+módulo, ver usuario_niveles / nivelUsuario().
$niveles_por_usuario = [];
foreach ($db->query("SELECT usuario_id, modulo, nivel FROM usuario_niveles")->fetchAll() as $n) {
    $niveles_por_usuario[$n['usuario_id']][$n['modulo']] = $n['nivel'];
}

$tipos_usuarios = $db->query("SELECT DISTINCT tipo_usuario FROM usuarios WHERE tipo_usuario IS NOT NULL ORDER BY tipo_usuario")->fetchAll(PDO::FETCH_COLUMN);

$accesos_disponibles = [
    'almacen'     => 'Almacén',
    'ingenieria'  => 'Ingeniería',
    'compras'     => 'Compras',
    'proyectos'   => 'Proyectos',
    'manufactura' => 'Producción',
    'gerente'     => 'Gerencia',
    'calidad'     => 'Calidad',
    // 'proceso' con nivel Jefe = equivalente a administrador-proceso (ve detalles/historial
    // en proceso.php, ver esJefeDe('proceso') más abajo); con nivel Trabajador = equivalente
    // a operador-proceso.
    'proceso'     => 'Proceso',
];

$usuario_filtro = $_GET['usuario'] ?? '';
$accion_filtro = $_GET['accion'] ?? '';
$tabla_filtro = $_GET['tabla'] ?? '';
$fecha_desde = $_GET['desde'] ?? '';
$fecha_hasta = $_GET['hasta'] ?? '';

$sql_auditoria = "SELECT a.*, u.nombre_completo as usuario_nombre, u.username 
                  FROM auditoria a 
                  LEFT JOIN usuarios u ON a.usuario_id = u.id 
                  WHERE 1=1";
$params = [];

if ($usuario_filtro) {
    $sql_auditoria .= " AND a.usuario_id = ?";
    $params[] = $usuario_filtro;
}

if ($accion_filtro) {
    $sql_auditoria .= " AND a.accion = ?";
    $params[] = $accion_filtro;
}

if ($tabla_filtro) {
    $sql_auditoria .= " AND a.tabla = ?";
    $params[] = $tabla_filtro;
}

if ($fecha_desde) {
    $sql_auditoria .= " AND DATE(a.fecha_hora) >= ?";
    $params[] = $fecha_desde;
}

if ($fecha_hasta) {
    $sql_auditoria .= " AND DATE(a.fecha_hora) <= ?";
    $params[] = $fecha_hasta;
}

$sql_auditoria .= " ORDER BY a.fecha_hora DESC LIMIT 500";

$stmt = $db->prepare($sql_auditoria);
$stmt->execute($params);
$auditorias = $stmt->fetchAll();

$usuarios_filtro = $db->query("SELECT id, nombre_completo, username FROM usuarios WHERE activo = 1 ORDER BY nombre_completo")->fetchAll();
$acciones = $db->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);
$tablas = $db->query("SELECT DISTINCT tabla FROM auditoria WHERE tabla IS NOT NULL ORDER BY tabla")->fetchAll(PDO::FETCH_COLUMN);

$stats = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN accion = 'login' THEN 1 ELSE 0 END) as logins,
    SUM(CASE WHEN accion IN ('crear', 'actualizar', 'eliminar') THEN 1 ELSE 0 END) as modificaciones
    FROM auditoria WHERE DATE(fecha_hora) = CURDATE()")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap');
        
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

        .page-header { text-align: center; margin-bottom: 40px; }
        .page-title { font-size: 2.2rem; color: #003d5c; font-weight: 300; margin-bottom: 10px; }
        .page-title strong { font-weight: 800; background: linear-gradient(135deg, #003d5c, #0077b3); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 2.4rem; }
        .page-subtitle { color: #666; font-size: 1rem; }

        .cards-grid {
            display: flex;
            gap: 30px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .nav-card {
            width: 250px;
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
            border: 3px solid #e0e0e0;
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
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
            transition: color 0.3s ease;
            margin-bottom: 5px;
        }
        
        .card-desc {
            font-size: 0.9rem;
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
            z-index: 100;
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
        
        .content-panel {
            height: calc(100vh - 280px);
            overflow-y: auto;
            padding-right: 10px;
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
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        
        .usuarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        
        .usuario-card {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }
        .usuario-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
            border-color: #2196F3;
        }
        .usuario-card.inactivo {
            opacity: 0.6;
            background: #f0f0f0;
        }
        .usuario-header {
            background: #003d5c;
            color: white;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .usuario-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: #FF9800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
        }
        .usuario-header-info {
            flex: 1;
        }
        .usuario-nombre {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 3px;
        }
        .usuario-username {
            font-size: 12px;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .usuario-badge {
            background: rgba(255,255,255,0.2);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .usuario-body {
            padding: 15px;
            flex: 1;
        }
        .usuario-detalle {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 13px;
        }
        .usuario-detalle-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #555;
        }
        .usuario-detalle-item i {
            width: 18px;
            color: #003d5c;
        }
        .usuario-accesos {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }
        .acceso-tag {
            display: inline-block;
            background: #e3f2fd;
            color: #1976D2;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin: 2px;
        }
        .usuario-footer {
            padding: 10px 15px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 5px;
            background: #f5f5f5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.blue { background: #e3f2fd; color: #2196F3; }
        .stat-icon.green { background: #e8f5e9; color: #4CAF50; }
        .stat-icon.orange { background: #fff3e0; color: #FF9800; }
        .stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
        
        .filters-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: white;
            padding: 15px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filter-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .filter-item label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .filter-item select, .filter-item input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .table-container {
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table th {
            background: #003d5c;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        .table td {
            padding: 10px 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .table tr:hover {
            background: #f9f9f9;
        }
        .table-footer {
            padding: 15px;
            background: #f9f9f9;
            font-size: 12px;
            color: #666;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
            display: inline-block;
        }
        .badge-crear { background: #4CAF50; color: white; }
        .badge-actualizar { background: #2196F3; color: white; }
        .badge-eliminar { background: #f44336; color: white; }
        .badge-login { background: #9C27B0; color: white; }
        .badge-logout { background: #607D8B; color: white; }
        .badge-ajustar_stock { background: #FF9800; color: white; }
        .badge-activar { background: #4CAF50; color: white; }
        .badge-desactivar { background: #FF9800; color: white; }
        .badge-default { background: #e0e0e0; color: #333; }
        
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
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group.full {
            grid-column: 1 / -1;
        }
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-input:focus {
            outline: none;
            border-color: #005686;
            box-shadow: 0 0 0 3px rgba(0,86,134,0.1);
        }
        select.form-input {
            background: white;
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .checkbox-item label {
            font-size: 13px;
            color: #333;
            cursor: pointer;
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
    </style>
</head>
<body>
    <div class="top-header">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
        
    <div class="header-right">
    <?php if ($seccion !== ''): ?>
    <a href="?seccion=" class="back-button-header">
        <i class="fas fa-arrow-left"></i> Volver al Menú Principal
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
       <?php if ($seccion === ''): ?>
<div class="main-center">
    <?php if ($mostrar_boton_menu): ?>
    <div style="position: absolute; top: 20px; left: 30px; z-index: 10;">
        <a href="Menu.php" class="back-button-header" style="background: #4CAF50; border-color: #4CAF50; display: inline-flex;">
            <i class="fas fa-arrow-left"></i> Volver al Menú de Perfiles
        </a>
    </div>
    <?php endif; ?>

    <div class="page-header">
        <h1 class="page-title"><strong>Administración</strong><br>Panel de Control</h1>
        <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
    </div>

    <div class="cards-grid">
        <div class="nav-card" onclick="window.location.href='?seccion=auditoria'">
            <div class="card-icon">
                <i class="fas fa-history"></i>
            </div>
            <div class="card-title">Auditoría</div>
            <div class="card-desc">Historial de acciones</div>
        </div>
        
        <div class="nav-card" onclick="window.location.href='?seccion=usuarios'">
            <div class="card-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="card-title">Usuarios</div>
            <div class="card-desc">Gestionar accesos</div>
        </div>
    </div>
</div>
<?php endif; ?>
        
        <?php if ($seccion === 'auditoria'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-history"></i> Auditoría del Sistema</h2>
                    <div class="action-buttons"></div>
                </div>
            </div>
            
            <?php echo $message; ?>
            <?php echo $error; ?>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-list"></i></div>
                    <div>
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Acciones Hoy</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-sign-in-alt"></i></div>
                    <div>
                        <div class="stat-value"><?php echo $stats['logins']; ?></div>
                        <div class="stat-label">Inicios de Sesión</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-edit"></i></div>
                    <div>
                        <div class="stat-value"><?php echo $stats['modificaciones']; ?></div>
                        <div class="stat-label">Modificaciones</div>
                    </div>
                </div>
            </div>
            
            <form method="GET" action="" class="filters-bar">
                <input type="hidden" name="seccion" value="auditoria">
                <div class="filter-item">
                    <label>Usuario:</label>
                    <select name="usuario" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($usuarios_filtro as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $usuario_filtro == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo $u['nombre_completo']; ?> (<?php echo $u['username']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Acción:</label>
                    <select name="accion" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($acciones as $a): ?>
                        <option value="<?php echo $a; ?>" <?php echo $accion_filtro == $a ? 'selected' : ''; ?>><?php echo ucfirst($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Tabla:</label>
                    <select name="tabla" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($tablas as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $tabla_filtro == $t ? 'selected' : ''; ?>><?php echo ucfirst($t ?: 'NULL'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Desde:</label>
                    <input type="date" name="desde" value="<?php echo htmlspecialchars($fecha_desde, ENT_QUOTES, 'UTF-8'); ?>" onchange="this.form.submit()">
                </div>
                <div class="filter-item">
                    <label>Hasta:</label>
                    <input type="date" name="hasta" value="<?php echo htmlspecialchars($fecha_hasta, ENT_QUOTES, 'UTF-8'); ?>" onchange="this.form.submit()">
                </div>
                <a href="?seccion=auditoria" class="btn btn-outline"><i class="fas fa-eraser"></i> Limpiar</a>
            </form>
            
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Fecha/Hora</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Tabla</th>
                            <th>Detalle</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditorias as $a): 
                            $badge_class = 'badge-default';
                            if (in_array($a['accion'], ['crear', 'actualizar', 'eliminar', 'login', 'logout', 'ajustar_stock', 'activar', 'desactivar'])) {
                                $badge_class = 'badge-' . $a['accion'];
                            }
                        ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i:s', strtotime($a['fecha_hora'])); ?></td>
                            <td><strong><?php echo $a['usuario_nombre']; ?></strong><br><small><?php echo $a['username']; ?></small></td>
                            <td><span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($a['accion']); ?></span></td>
                            <td><?php echo $a['tabla'] ? ucfirst($a['tabla']) : '-'; ?></td>
                            <td><?php echo $a['detalle']; ?></td>
                            <td><small style="color: #999;"><?php echo $a['ip_address']; ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="table-footer">
                    Mostrando <?php echo count($auditorias); ?> registros (máximo 500)
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($seccion === 'usuarios'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-users"></i> Gestión de Usuarios</h2>
                    <div class="action-buttons">
                        <div class="search-wrapper">
                            <input type="text" class="search-input" id="searchUsuarios" placeholder="Buscar usuario...">
                            <button class="search-clear" id="clearSearchUsuarios" onclick="clearSearch('usuarios')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <button class="btn btn-primary" onclick="abrirModalUsuario()">
                            <i class="fas fa-user-plus"></i> Nuevo Usuario
                        </button>
                    </div>
                </div>
            </div>
            
            <?php echo $message; ?>
            <?php echo $error; ?>
            
            <div class="content-panel">
                <div class="usuarios-grid" id="usuariosGrid">
                    <?php foreach ($usuarios as $user): 
                        $inactivo = $user['activo'] == 0;
                        $accesos = explode(',', $user['accesos'] ?? '');
                    ?>
                    <div class="usuario-card <?php echo $inactivo ? 'inactivo' : ''; ?>" 
                         data-nombre="<?php echo strtolower($user['nombre_completo']); ?>" 
                         data-username="<?php echo strtolower($user['username']); ?>"
                         data-email="<?php echo strtolower($user['email'] ?? ''); ?>">
                        <div class="usuario-header">
                            <div class="usuario-avatar"><?php echo strtoupper(substr($user['nombre_completo'], 0, 1)); ?></div>
                            <div class="usuario-header-info">
                                <div class="usuario-nombre"><?php echo $user['nombre_completo']; ?></div>
                                <div class="usuario-username">
                                    <i class="fas fa-user"></i> <?php echo $user['username']; ?>
                                    <?php if ($inactivo): ?>
                                    <span class="usuario-badge" style="background: #f44336;">Inactivo</span>
                                    <?php endif; ?>
                                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                    <span class="usuario-badge" style="background: #4CAF50;">Tú</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="usuario-body">
                            <div class="usuario-detalle">
                                <?php if (!empty($user['email'])): ?>
                                <div class="usuario-detalle-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo $user['email']; ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($user['telefono'])): ?>
                                <div class="usuario-detalle-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($user['telefono']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="usuario-detalle-item">
                                    <i class="fas fa-tag"></i>
                                    <span><strong>Tipo:</strong> <?php echo ucfirst($user['tipo_usuario']); ?></span>
                                </div>
                                <?php if (!empty($user['fecha_creacion'])): ?>
                                <div class="usuario-detalle-item">
                                    <i class="fas fa-calendar-plus"></i>
                                    <span>Creado: <?php echo date('d/m/Y', strtotime($user['fecha_creacion'])); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($user['ultimo_acceso'])): ?>
                                <div class="usuario-detalle-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Último acceso: <?php echo date('d/m/Y H:i', strtotime($user['ultimo_acceso'])); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($accesos) && $accesos[0] != ''): ?>
                            <div class="usuario-accesos">
                                <strong>Accesos:</strong>
                                <div style="margin-top: 5px;">
                                    <?php foreach ($accesos as $acceso): 
                                        $acceso_nombre = isset($accesos_disponibles[$acceso]) ? $accesos_disponibles[$acceso] : $acceso;
                                    ?>
                                    <span class="acceso-tag"><?php echo $acceso_nombre; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="usuario-footer">
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <?php if ($user['activo']): ?>
                                <button class="btn btn-warning btn-sm" onclick="toggleUsuario(<?php echo $user['id']; ?>, 0, '<?php echo htmlspecialchars(addslashes($user['nombre_completo']), ENT_QUOTES, 'UTF-8'); ?>')">
                                    <i class="fas fa-ban"></i> Desactivar
                                </button>
                                <?php else: ?>
                                <button class="btn btn-success btn-sm" onclick="toggleUsuario(<?php echo $user['id']; ?>, 1, '<?php echo htmlspecialchars(addslashes($user['nombre_completo']), ENT_QUOTES, 'UTF-8'); ?>')">
                                    <i class="fas fa-check-circle"></i> Activar
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php $user_con_niveles = $user; $user_con_niveles['niveles'] = $niveles_por_usuario[$user['id']] ?? []; ?>
                            <button class="btn btn-primary btn-sm" onclick='editarUsuario(<?php echo htmlspecialchars(json_encode($user_con_niveles, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)'>
                                <i class="fas fa-edit"></i> Editar
                            </button>
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <button class="btn btn-danger btn-sm" onclick="confirmarEliminarUsuario(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['nombre_completo']), ENT_QUOTES, 'UTF-8'); ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
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
    
    <div class="modal-overlay" id="modalUsuario">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-user"></i> <span id="usuarioModalTitle">Nuevo Usuario</span></div>
                <button class="modal-close" onclick="cerrarModal('modalUsuario')">&times;</button>
            </div>
            <form method="POST" action="" id="usuarioForm">
                <input type="hidden" name="action" value="guardar_usuario">
                <input type="hidden" name="id" id="usuarioId">
                
                <div class="modal-body">
                    <div class="grid-2">
                        <div class="form-group full">
                            <label class="form-label">Nombre Completo *</label>
                            <input type="text" name="nombre_completo" id="usuarioNombre" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Usuario *</label>
                            <input type="text" name="username" id="usuarioUsername" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contraseña <?php echo isset($id) ? '(dejar en blanco para no cambiar)' : '*'; ?></label>
                            <input type="password" name="password" id="usuarioPassword" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="usuarioEmail" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="tel" name="telefono" id="usuarioTelefono" class="form-input" placeholder="ej. 8112345678">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tipo de Usuario *</label>
                            <select name="tipo_usuario" id="usuarioTipo" class="form-input" required>
                                <option value="">Seleccione...</option>
                                <option value="admin">Admin</option>
                                <option value="multiple">Multiple</option>
                                <option value="operador-proceso">Operador-Proceso</option>
                                <option value="administrador-proceso">Administrador-Proceso</option>
                            </select>
                        </div>
                        <div class="form-group full" id="accesosGroup">
                            <label class="form-label">Accesos permitidos</label>
                            <div class="checkbox-group">
                                <?php foreach ($accesos_disponibles as $key => $label): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="accesos[]" value="<?php echo $key; ?>" id="acceso_<?php echo $key; ?>" onchange="toggleNivelAcceso('<?php echo $key; ?>')">
                                    <label for="acceso_<?php echo $key; ?>"><?php echo $label; ?></label>
                                    <select name="nivel_<?php echo $key; ?>" id="nivel_<?php echo $key; ?>" class="form-input" style="display:none;margin-left:8px;width:auto;padding:2px 6px;font-size:.8rem;" disabled>
                                        <option value="trabajador">Trabajador</option>
                                        <option value="jefe">Jefe de depto.</option>
                                    </select>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalUsuario')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalEliminarUsuario">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;">
                <div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Eliminación</div>
                <button class="modal-close" onclick="cerrarModal('modalEliminarUsuario')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-trash" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i>
                <p id="eliminar_usuario_mensaje" style="margin-bottom: 20px; font-size: 16px;">¿Estás seguro de eliminar este usuario?</p>
                <p style="font-size: 13px; color: #666;">Esta acción eliminará permanentemente al usuario de la base de datos.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="eliminarUsuarioForm">
                    <input type="hidden" name="action" value="eliminar_usuario">
                    <input type="hidden" name="id" id="eliminar_usuario_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEliminarUsuario')">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Sí, Eliminar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalToggleUsuario">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #FF9800;">
                <div class="modal-title"><i class="fas fa-exchange-alt"></i> Confirmar Cambio</div>
                <button class="modal-close" onclick="cerrarModal('modalToggleUsuario')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-question-circle" style="font-size: 48px; color: #FF9800; margin: 20px 0;"></i>
                <p id="toggle_usuario_mensaje" style="margin-bottom: 20px; font-size: 16px;">¿Deseas cambiar el estado de este usuario?</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="toggleUsuarioForm">
                    <input type="hidden" name="action" value="toggle_usuario">
                    <input type="hidden" name="id" id="toggle_usuario_id" value="">
                    <input type="hidden" name="estado" id="toggle_usuario_estado" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalToggleUsuario')">Cancelar</button>
                    <button type="submit" class="btn btn-warning" id="toggle_usuario_btn">Sí, Cambiar</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let accesosDisponibles = <?php echo json_encode($accesos_disponibles); ?>;
        
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
            
            initSearch('usuarios');
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
                
                if (section === 'usuarios') {
                    filtrarUsuarios(this.value);
                }
            });
        }
        
        function clearSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            
            if (input) {
                input.value = '';
                clearBtn.classList.remove('visible');
                
                if (section === 'usuarios') {
                    filtrarUsuarios('');
                }
            }
        }
        
        function filtrarUsuarios(termino) {
            const cards = document.querySelectorAll('#usuariosGrid .usuario-card');
            termino = termino.toLowerCase();
            
            cards.forEach(card => {
                const nombre = card.dataset.nombre || '';
                const username = card.dataset.username || '';
                const email = card.dataset.email || '';
                
                if (nombre.includes(termino) || username.includes(termino) || email.includes(termino)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function cerrarModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });
        
        function actualizarVisibilidadAccesos() {
            const tipo = document.getElementById('usuarioTipo').value;
            const grupo = document.getElementById('accesosGroup');
            // Solo 'multiple' usa el array de accesos -- admin/operador-proceso/administrador-proceso
            // tienen su acceso fijo resuelto en index.php al hacer login, marcar checkboxes ahí no hace nada.
            if (tipo === 'multiple') {
                grupo.style.display = '';
            } else {
                grupo.style.display = 'none';
                grupo.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
            }
        }

        document.getElementById('usuarioTipo').addEventListener('change', actualizarVisibilidadAccesos);

        // Muestra/habilita el select de nivel (trabajador/jefe) de un acceso solo
        // mientras ese checkbox esté marcado -- un select disabled no se manda en
        // el submit, así que un acceso desmarcado nunca deja nivel basura en el POST.
        function toggleNivelAcceso(modulo) {
            const cb = document.getElementById(`acceso_${modulo}`);
            const sel = document.getElementById(`nivel_${modulo}`);
            if (!cb || !sel) return;
            sel.style.display = cb.checked ? 'inline-block' : 'none';
            sel.disabled = !cb.checked;
        }

        function abrirModalUsuario() {
            document.getElementById('usuarioModalTitle').textContent = 'Nuevo Usuario';
            document.getElementById('usuarioId').value = '';
            document.getElementById('usuarioForm').reset();
            document.querySelectorAll('#usuarioForm input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
                if (cb.id.startsWith('acceso_')) toggleNivelAcceso(cb.id.replace('acceso_', ''));
            });
            actualizarVisibilidadAccesos();
            document.getElementById('modalUsuario').classList.add('active');
        }

        function editarUsuario(user) {
            document.getElementById('usuarioModalTitle').textContent = 'Editar Usuario';
            document.getElementById('usuarioId').value = user.id;
            document.getElementById('usuarioNombre').value = user.nombre_completo || '';
            document.getElementById('usuarioUsername').value = user.username || '';
            document.getElementById('usuarioPassword').value = '';
            document.getElementById('usuarioEmail').value = user.email || '';
            document.getElementById('usuarioTelefono').value = user.telefono || '';
            document.getElementById('usuarioTipo').value = user.tipo_usuario || '';

            document.querySelectorAll('#usuarioForm input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });

            document.querySelectorAll('#usuarioForm select[id^="nivel_"]').forEach(sel => {
                sel.style.display = 'none';
                sel.disabled = true;
                sel.value = 'trabajador';
            });

            if (user.accesos) {
                let accesos = [];
                try { accesos = JSON.parse(user.accesos); } catch(e) { accesos = user.accesos.split(','); }
                const niveles = user.niveles || {};
                accesos.forEach(acceso => {
                    acceso = acceso.trim();
                    const cb = document.getElementById(`acceso_${acceso}`);
                    if (cb) cb.checked = true;
                    const sel = document.getElementById(`nivel_${acceso}`);
                    if (sel) sel.value = niveles[acceso] || 'trabajador';
                    toggleNivelAcceso(acceso);
                });
            }

            actualizarVisibilidadAccesos();
            document.getElementById('modalUsuario').classList.add('active');
        }
        
        function confirmarEliminarUsuario(id, nombre) {
            document.getElementById('eliminar_usuario_id').value = id;
            document.getElementById('eliminar_usuario_mensaje').textContent = '¿Estás seguro de eliminar al usuario "' + nombre + '"?';
            document.getElementById('modalEliminarUsuario').classList.add('active');
        }
        
        function toggleUsuario(id, estado, nombre) {
            document.getElementById('toggle_usuario_id').value = id;
            document.getElementById('toggle_usuario_estado').value = estado;
            const accion = estado ? 'activar' : 'desactivar';
            document.getElementById('toggle_usuario_mensaje').textContent = '¿Deseas ' + accion + ' al usuario "' + nombre + '"?';
            document.getElementById('toggle_usuario_btn').textContent = 'Sí, ' + (estado ? 'Activar' : 'Desactivar');
            document.getElementById('modalToggleUsuario').classList.add('active');
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
                // Marcar todas las notificaciones como leídas al abrir el panel
                fetch('includes/ajax_chat.php?action=marcar_todas_leidas', { method: 'POST' })
                    .then(() => setTimeout(verificarNuevosMensajes, 300));
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
            marcarMensajesComoLeidos(usuario.id).then(() => verificarNuevosMensajes());
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
                    'nuevo_proyecto':    '<i class="fas fa-folder-plus"       style="color:#003d5c"></i>',
                    'nuevo_boom':        '<i class="fas fa-layer-group"        style="color:#FF9800"></i>',
                    'boom_guardado':     '<i class="fas fa-save"               style="color:#4CAF50"></i>',
                    'nueva_requisicion': '<i class="fas fa-clipboard-list"     style="color:#7b1fa2"></i>',
                    'cotizacion_enviada':'<i class="fas fa-paper-plane"        style="color:#0097a7"></i>',
                    'cotizacion_recibida':'<i class="fas fa-file-invoice"      style="color:#1565c0"></i>',
                    'orden_generada':    '<i class="fas fa-truck"              style="color:#e64a19"></i>',
                    'proyecto_almacen':  '<i class="fas fa-warehouse"          style="color:#ff5722"></i>',
                    'proyecto_guardado': '<i class="fas fa-box"                style="color:#795548"></i>',
                    'sincronizacion':    '<i class="fas fa-sync-alt"           style="color:#009688"></i>',
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
</body>
</html>