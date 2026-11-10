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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que el usuario está logueado
if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$db = getDB();

// Obtener los accesos del usuario desde la base de datos
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT accesos, tipo_usuario, nombre_completo FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Decodificar los accesos
$accesos = [];
if (!empty($user['accesos'])) {
    $decoded = json_decode($user['accesos'], true);
    if (is_array($decoded)) {
        $accesos = $decoded;
    } else {
        $accesos = explode(',', $user['accesos']);
    }
}

// Definir los perfiles disponibles
$perfiles_disponibles = [
    'admin' => [
        'nombre' => 'Administrador',
        'icono' => 'fa-user-cog',
        'descripcion' => 'Acceso total',
        'url' => 'admin.php',
        'color' => '#1B2D4E'
    ],
    'compras' => [
        'nombre' => 'Compras',
        'icono' => 'fa-shopping-cart',
        'descripcion' => 'Gestión de compras',
        'url' => 'compras.php',
        'color' => '#1B2D4E'
    ],
    'almacen' => [
        'nombre' => 'Almacén',
        'icono' => 'fa-warehouse',
        'descripcion' => 'Control de inventario',
        'url' => 'almacen2.php',
        'color' => '#1B2D4E'
    ],
    'ingenieria' => [
        'nombre' => 'Ingeniería',
        'icono' => 'fa-hard-hat',
        'descripcion' => 'Proyectos y diseño',
        'url' => 'ingenieria.php',
        'color' => '#1B2D4E'
    ],
    'proyectos' => [
        'nombre'      => 'Proyectos',
        'icono'       => 'fa-project-diagram',
        'descripcion' => 'Gestión de proyectos',
        'url'         => 'proyecto.php',
        'color'       => '#1B2D4E'
    ],
    'manufactura' => [
        'nombre'      => 'Producción',
        'icono'       => 'fa-industry',
        'descripcion' => 'Producción y fabricación',
        'url'         => 'produccion.php',
        'color'       => '#1B2D4E'
    ],
    'gerente' => [
        'nombre'      => 'Gerencia',
        'icono'       => 'fa-stamp',
        'descripcion' => 'Aprobación de órdenes de compra',
        'url'         => 'gerencia.php',
        'color'       => '#1B2D4E'
    ],
    'calidad' => [
        'nombre'      => 'Calidad',
        'icono'       => 'fa-check-double',
        'descripcion' => 'Inspecciones de calidad',
        'url'         => 'calidad.php',
        'color'       => '#1B2D4E'
    ],
    'proceso' => [
        'nombre'      => 'Proceso',
        'icono'       => 'fa-hard-hat',
        'descripcion' => 'Control de fabricación',
        'url'         => 'proceso.php',
        'color'       => '#1B2D4E'
    ],
];

// Admin tiene acceso a todos los módulos; administrador-proceso solo a Producción y Proceso
$perfiles_usuario = [];
if ($user['tipo_usuario'] === 'admin') {
    $perfiles_usuario = $perfiles_disponibles;
} elseif ($user['tipo_usuario'] === 'administrador-proceso') {
    $perfiles_usuario = array_intersect_key($perfiles_disponibles, array_flip(['manufactura', 'proceso']));
} else {
    foreach ($accesos as $acceso) {
        $acceso = trim($acceso);
        if (isset($perfiles_disponibles[$acceso])) {
            $perfiles_usuario[$acceso] = $perfiles_disponibles[$acceso];
        }
    }
}

// Si solo tiene un perfil, redirigir directamente
if (count($perfiles_usuario) === 1) {
    $_SESSION['desde_menu'] = true;
    $primer_perfil = reset($perfiles_usuario);
    header('Location: ' . $primer_perfil['url']);
    exit();
}

// Si no tiene perfiles, redirigir al login
if (empty($perfiles_usuario)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>EMPRESA EJEMPLO - Selección de Perfil</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            background: #f5f5f5;
        }
        
        .menu-container {
            display: flex;
            height: 100vh;
            width: 100vw;
        }
        
        .menu-left {
            flex: 0.7; /* Reducido de 1 a 0.7 para dar más espacio a las tarjetas */
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding-left: 3%;
        }
        
        .menu-left::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #003d5c 0%, #005686 50%, #0077b3 100%);
            clip-path: polygon(0 0, 35% 0, 0% 60%, 0 100%);
            z-index: 1;
        }
        
        .menu-left::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background: #ffffff;
            clip-path: polygon(35% 0, 100% 0, 100% 100%, 0% 100%);
            z-index: 1;
        }
        
        .logo-container {
            position: relative;
            z-index: 2;
            width: min(350px, 25vw); /* Reducido de 500px a 350px */
            height: min(280px, 20vh); /* Reducido de 400px a 280px */
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 0;
        }
        
        .logo-container img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.15));
        }
        
        .menu-right {
            flex: 1.3; /* Aumentado de 1 a 1.3 para dar más espacio a las tarjetas */
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem 2rem;
            position: relative;
            height: 100vh;
            overflow-y: auto; /* Permite scroll si hay muchas tarjetas */
        }
        
        .menu-header {
            margin-bottom: 1.5rem;
            position: relative;
            width: 100%;
            max-width: 600px;
            text-align: center;
        }
        
        .menu-header::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #FF9800, #005686);
            border-radius: 2px;
        }
        
        .menu-title {
            font-size: 2rem;
            color: #003d5c;
            font-weight: 300;
            line-height: 1.2;
            margin-bottom: 0.3rem;
            font-family: 'Montserrat', sans-serif;
            letter-spacing: -0.5px;
        }
        
        .menu-title strong {
            font-weight: 800;
            color: #005686;
            background: linear-gradient(135deg, #003d5c, #0077b3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.2rem;
            display: inline-block;
            position: relative;
        }
        
        .menu-subtitle {
            color: #666;
            font-size: 1rem;
            font-family: 'Montserrat', sans-serif;
            margin-top: 0.5rem;
            font-weight: 400;
            letter-spacing: 0.3px;
        }
        
        .welcome-message {
            font-size: 1rem;
            color: #FF9800;
            margin-bottom: 0.3rem;
            font-weight: 500;
        }
        
        .profiles-container {
            display: flex;
            gap: 1.2rem;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            margin: 1rem 0 1.5rem;
            width: 100%;
            max-width: 1000px;
        }
        
        .profile-card {
            width: 140px; /* Reducido de 160px a 140px para que quepan más */
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .profile-card:hover {
            transform: translateY(-6px);
        }
        
        .profile-card:active {
            transform: scale(0.95);
        }
        
        .profile-card:hover .profile-icon {
            border-color: #FF9800;
            box-shadow: 0 12px 25px rgba(255, 152, 0, 0.2);
        }
        
        .profile-card:hover .profile-name {
            color: #FF9800;
        }
        
        .profile-icon {
            width: 100%;
            aspect-ratio: 1/1;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 16px; /* Reducido de 20px a 16px */
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.6rem;
            border: 2px solid #e0e0e0; /* Reducido de 3px a 2px */
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }
        
        .profile-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .profile-card:hover .profile-icon::before {
            left: 100%;
        }
        
        .profile-icon i {
            font-size: 3.5rem; /* Reducido de 4rem a 3.5rem */
            color: #1B2D4E;
            transition: all 0.3s ease;
            z-index: 2;
        }
        
        .profile-card:hover .profile-icon i {
            color: #FF9800;
            transform: scale(1.1);
        }
        
        .profile-name {
            font-size: 1rem; /* Reducido de 1.1rem a 1rem */
            font-weight: 600;
            color: #333;
            font-family: 'Montserrat', sans-serif;
            transition: color 0.3s ease;
            letter-spacing: 0.2px;
            margin-bottom: 0.1rem;
        }
        
        .profile-role {
            font-size: 0.7rem; /* Reducido de 0.8rem a 0.7rem */
            color: #888;
            font-family: 'Montserrat', sans-serif;
            font-weight: 400;
        }
        
        .logout-button {
            margin-top: 0.5rem;
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
            border: 1px solid #f44336;
            padding: 0.7rem 1.8rem;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .logout-button:hover {
            background: #f44336;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(244, 67, 54, 0.25);
        }
        
        .login-footer {
            position: absolute;
            bottom: 1.5rem;
            right: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: calc(100% - 4rem);
            font-size: 0.85rem;
            color: #666;
            font-family: 'Montserrat', sans-serif;
        }
        
        .security-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #4CAF50;
            background: rgba(76, 175, 80, 0.1);
            padding: 0.4rem 0.8rem;
            border-radius: 30px;
        }
        
        .security-info img {
            height: 25px;
            width: auto;
        }
        
        .copyright-info {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .copyright-info img {
            height: 16px;
            width: auto;
            object-fit: contain;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .menu-left {
                flex: 0.6;
            }
            .menu-right {
                flex: 1.4;
            }
            .profile-card {
                width: 130px;
            }
        }
        
        @media (max-width: 1100px) {
            .menu-left {
                flex: 0.5;
            }
            .menu-right {
                flex: 1.5;
            }
            .profile-card {
                width: 120px;
            }
            .profile-icon i {
                font-size: 3rem;
            }
        }
        
        @media (max-width: 900px) {
            .menu-container {
                flex-direction: column;
            }
            
            .menu-left {
                flex: none;
                min-height: 150px;
                padding: 15px;
                justify-content: center;
                padding-left: 0;
            }
            
            .menu-left::before {
                clip-path: polygon(0 0, 100% 0, 100% 60%, 0 100%);
            }
            
            .menu-left::after {
                clip-path: polygon(0 100%, 100% 60%, 100% 100%, 0 100%);
            }
            
            .logo-container {
                width: min(220px, 35vw);
                height: min(180px, 12vh);
            }
            
            .menu-right {
                flex: none;
                padding: 1.5rem;
                overflow-y: auto;
                height: calc(100vh - 150px);
            }
            
            .profiles-container {
                gap: 1rem;
            }
            
            .profile-card {
                width: 120px;
            }
            
            .login-footer {
                position: relative;
                bottom: auto;
                right: auto;
                margin-top: 1rem;
                width: 100%;
                flex-direction: row;
                justify-content: space-between;
            }
        }
        
        @media (max-width: 700px) {
            .profile-card {
                width: calc(33.33% - 0.8rem);
                min-width: 100px;
            }
        }
        
        @media (max-width: 600px) {
            .menu-left {
                min-height: 120px;
            }
            
            .logo-container {
                width: min(180px, 45vw);
                height: min(150px, 10vh);
            }
            
            .menu-right {
                padding: 1rem;
            }
            
            .menu-header {
                margin-bottom: 0.8rem;
            }
            
            .menu-header::after {
                width: 50px;
                height: 3px;
                bottom: -8px;
            }
            
            .menu-title {
                font-size: 1.5rem;
            }
            
            .menu-title strong {
                font-size: 1.7rem;
            }
            
            .menu-subtitle {
                font-size: 0.85rem;
                margin-top: 0.3rem;
            }
            
            .welcome-message {
                font-size: 0.9rem;
            }
            
            .profiles-container {
                gap: 0.6rem;
                margin: 0.8rem 0;
            }
            
            .profile-card {
                width: calc(50% - 0.6rem);
                min-width: 100px;
            }
            
            .profile-icon i {
                font-size: 2.8rem;
            }
            
            .profile-name {
                font-size: 0.9rem;
            }
            
            .profile-role {
                font-size: 0.65rem;
            }
            
            .logout-button {
                padding: 0.5rem 1.2rem;
                font-size: 0.8rem;
            }
            
            .login-footer {
                flex-direction: column;
                gap: 0.5rem;
                align-items: center;
                text-align: center;
                margin-top: 0.8rem;
            }
            
            .security-info {
                padding: 0.3rem 0.6rem;
                font-size: 0.75rem;
            }
            
            .security-info img {
                height: 22px;
            }
            
            .copyright-info {
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 380px) {
            .profile-card {
                width: 100%;
                max-width: 140px;
            }
            
            .profile-icon i {
                font-size: 3rem;
            }
        }
        
        @media (min-width: 1600px) {
            .profile-card {
                width: 160px;
            }
            
            .profile-icon i {
                font-size: 4rem;
            }
            
            .profile-name {
                font-size: 1.1rem;
            }
            
            .profile-role {
                font-size: 0.8rem;
            }
        }
        
        a, button, div, span, p, h1, h2, h3, h4, h5, h6, strong {
            text-decoration: none !important;
            -webkit-text-decoration: none !important;
        }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>
    <div class="menu-container">
        <div class="menu-left">
            <div class="logo-container">
                <img src="uploads/logo/logo.png" 
                     alt="Logo EMPRESA EJEMPLO"
                     onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'300\' height=\'200\' viewBox=\'0 0 300 200\'><rect width=\'300\' height=\'200\' fill=\'%23005b96\'/><text x=\'50\' y=\'120\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
            </div>
        </div>
        
        <div class="menu-right">
            <div class="menu-header">
                <div class="welcome-message">
                    <i class="fas fa-user"></i> Hola, <?php echo htmlspecialchars($user['nombre_completo']); ?>
                </div>
                <h1 class="menu-title">
                    ¿A dónde quieres ir?<br>
                    <strong>Selecciona tu perfil</strong>
                </h1>
                <p class="menu-subtitle">Tienes acceso a <?php echo count($perfiles_usuario); ?> módulos</p>
            </div>
            
            <div class="profiles-container" id="profilesContainer">
                <?php foreach ($perfiles_usuario as $key => $perfil): ?>
                <div class="profile-card" data-url="<?php echo $perfil['url']; ?>">
                    <div class="profile-icon">
                        <?php if ($key === 'ingenieria'): ?>
                        <img src="uploads/logo/Ingenieria.jpeg" alt="Ingeniería"
                             style="width:65%;height:65%;object-fit:contain;border-radius:6px;">
                        <?php else: ?>
                        <i class="fas <?php echo $perfil['icono']; ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="profile-name"><?php echo $perfil['nombre']; ?></div>
                    <div class="profile-role"><?php echo $perfil['descripcion']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <a href="?logout=1" class="logout-button">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
            
            <div class="login-footer">
                <div class="security-info">
                    <img src="uploads/logo/wempresa.png" alt="Seguridad" onerror="this.style.display='none'">
                </div>
                
                <div class="copyright-info">
                    &copy; <?php echo date('Y'); ?> 
                    <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO" onerror="this.style.display='none'">
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.profile-card');
            
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'all 0.4s ease';
                
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100 * (index + 1));
            });
            
            document.addEventListener('selectstart', function(e) {
                e.preventDefault();
                return false;
            });
            
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('dragstart', (e) => e.preventDefault());
            });
        });

        function irAPerfil(url, card) {
            card.style.opacity = '0.5';
            card.style.pointerEvents = 'none';
            
            fetch('set_menu_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ desde_menu: true })
            })
            .then(response => {
                window.location.href = url;
            })
            .catch(() => {
                window.location.href = url;
            });
        }

        document.querySelectorAll('.profile-card').forEach(card => {
            const url = card.getAttribute('data-url');
            if (url) {
                card.addEventListener('click', function() {
                    irAPerfil(url, this);
                });
            }
        });
    </script>

    <?php if ($user['tipo_usuario'] === 'admin'): ?>
    <!-- Reloj del servidor -- solo admin, para notar a simple vista si el reloj del
    contenedor se desfasó (bug conocido de Docker Desktop/WSL2 tras suspender la
    laptop). No hay forma de "sincronizar" desde la app -- el contenedor corre como
    www-data, sin permiso para cambiar la hora del sistema (date -s da "Operation
    not permitted"); si se ve mal, se arregla reiniciando Docker Desktop o con
    "wsl --shutdown" en Windows, no desde aquí. -->
    <div id="relojServidor" style="position:fixed;bottom:6px;right:8px;font-size:9px;color:rgba(0,0,0,.32);font-family:monospace;z-index:9999;pointer-events:none;user-select:none;"></div>
    <script>
        (function () {
            let t = new Date(<?= (int) (microtime(true) * 1000) ?>);
            const el = document.getElementById('relojServidor');
            function pad(n) { return String(n).padStart(2, '0'); }
            function tick() {
                t = new Date(t.getTime() + 1000);
                el.textContent = t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()) + ' '
                    + pad(t.getHours()) + ':' + pad(t.getMinutes()) + ':' + pad(t.getSeconds()) + ' (servidor)';
            }
            tick();
            setInterval(tick, 1000);
        })();
    </script>
    <?php endif; ?>
</body>
</html>