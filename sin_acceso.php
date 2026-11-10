<?php
/**
 * Sin acceso asignado — destino para usuarios logueados sin ningún módulo
 * asignado, y para cualquier intento de entrar a una página sin el permiso
 * requerido. No tiene funcionalidad: el admin es quien asigna accesos, si
 * alguien no tiene uno es una decisión deliberada, no un error a resolver aquí.
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

if (!isLoggedIn()) {
    redirect('index.php');
}

$nombre = $_SESSION['user_name'] ?? '';
$username = $_SESSION['user_username'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin acceso — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background: #023760;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #ffffff;
            border-radius: 16px;
            max-width: 440px;
            width: 100%;
            padding: 44px 36px;
            text-align: center;
            box-shadow: 0 12px 40px rgba(0,0,0,0.25);
        }
        .card img { max-width: 150px; margin-bottom: 24px; }
        .icon {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: rgba(190,137,59,0.12);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px;
        }
        .icon i { font-size: 26px; color: #BE893B; }
        h1 { font-size: 1.25rem; color: #023760; margin-bottom: 10px; }
        p { font-size: .92rem; color: #555; line-height: 1.5; margin-bottom: 6px; }
        .user-tag {
            display: inline-block;
            margin-top: 14px;
            padding: 6px 14px;
            background: #f4f6f9;
            border-radius: 20px;
            font-size: .8rem;
            color: #023760;
            font-weight: 600;
        }
        .btn-logout {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 28px;
            padding: 11px 26px;
            background: #005686;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-logout:hover { background: #023760; }
    </style>
</head>
<body>
    <div class="card">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" onerror="this.style.display='none'">
        <div class="icon"><i class="fas fa-lock"></i></div>
        <h1>Tu cuenta no tiene acceso asignado</h1>
        <p>Todavía no tienes ningún módulo del sistema asignado. Contacta al administrador para que te dé acceso.</p>
        <?php if ($nombre || $username): ?>
        <div class="user-tag"><i class="fas fa-user"></i> <?php echo htmlspecialchars($nombre ?: $username); ?></div>
        <?php endif; ?>
        <br>
        <a href="?logout=1" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar sesión</a>
    </div>
</body>
</html>
