<?php
require_once 'includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isLoggedIn()) {
    $tipo = isset($_SESSION['user_type']) ? trim($_SESSION['user_type']) : '';
    $username = isset($_SESSION['user_username']) ? $_SESSION['user_username'] : '';
    
    if ($username === 'admin') {
        if (isset($_SESSION['admin_mode_selected'])) {
            if ($_SESSION['admin_mode'] === 'normal') {
                header('Location: almacen2.php');
                exit();
            } elseif ($_SESSION['admin_mode'] === 'prueba') {
                header('Location: admin.php');
                exit();
            }
        }
    } elseif ($tipo === 'almacen') {
        header('Location: almacen2.php');
        exit();
    } elseif ($tipo === 'ingenieria') {
        header('Location: ingenieria.php');
        exit();
    } elseif ($tipo === 'compras') {
        header('Location: compras.php');
        exit();
    } elseif ($tipo === 'ventas') {
        header('Location: ventas.php');
        exit();
    } elseif ($tipo === 'calidad') {
        header('Location: calidad.php');
        exit();
    } else {
        header('Location: sin_acceso.php');
        exit();
    }
}

// ── CAPTCHA con imagen (GD, ver captcha_img.php) ──────────────────────────────
// Antes era una expresión matemática ("N + M") en texto plano dentro del HTML --
// un script podía leerla con una regex de una línea sin resolver nada. Ahora es
// un código alfanumérico de 5 caracteres que solo existe como imagen renderizada
// con GD -- hace falta OCR real para automatizarlo, no un simple parseo de HTML.
function generarCodigoCaptcha(): string {
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O/1/I/L -- se confunden a simple vista
    $codigo = '';
    for ($i = 0; $i < 5; $i++) {
        $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $codigo;
}

function regenerarCaptcha(): void {
    $_SESSION['captcha_code'] = generarCodigoCaptcha();
}

function verificarCaptcha(string $respuesta): bool {
    $respuesta = strtoupper(trim($respuesta));
    if ($respuesta === '' || empty($_SESSION['captcha_code'])) {
        return false;
    }
    return hash_equals($_SESSION['captcha_code'], $respuesta);
}

if (empty($_SESSION['captcha_code'])) {
    regenerarCaptcha();
}

/** Registra el fallo para el rate-limit por IP y avisa a admin si esta IP se acaba de bloquear. */
function registrarFalloIpYNotificar(string $ip): void {
    if (registrarIntentoFallidoIp($ip)) {
        notificarRoles(['admin'], 'seguridad', 'IP bloqueada por intentos de login',
            "La IP $ip fue bloqueada 30 min tras demasiados intentos fallidos de login.", 'index.php');
    }
}

// Botón "generar otro código" -- solo cambia el código, no reenvía el form.
if (isset($_GET['nuevo_captcha'])) {
    regenerarCaptcha();
    header('Location: index.php');
    exit();
}

$error = '';

if (isset($_GET['sesion_expirada'])) {
    $error = 'Tu sesión expiró por inactividad mientras llenabas el formulario. Inicia sesión de nuevo y vuelve a intentarlo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip_cliente = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Rate-limit por IP -- independiente del bloqueo por cuenta (usuarios.bloqueado_hasta),
    // que es POR CUENTA y no frena a alguien que rota de usuario desde la misma IP.
    if (loginIpBloqueada($ip_cliente)) {
        http_response_code(429);
        $error = 'Demasiados intentos de inicio de sesión desde esta red. Intenta de nuevo más tarde.';
        regenerarCaptcha();
    } else {

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $captcha_respuesta = isset($_POST['captcha_respuesta']) ? trim($_POST['captcha_respuesta']) : '';

    $errores = [];

    if (empty($username)) {
        $errores[] = 'Por favor ingrese su usuario';
    }

    if (empty($password)) {
        $errores[] = 'Por favor ingrese su contraseña';
    }

    if (empty($errores)) {
        if (empty($captcha_respuesta)) {
            $errores[] = 'Por favor ingrese el código de la imagen';
        } elseif (!verificarCaptcha($captcha_respuesta)) {
            $errores[] = 'El código de la imagen es incorrecto';
            regenerarCaptcha();
        }
    }

    if (!empty($errores)) {
        $error = implode('<br>', $errores);
        registrarFalloIpYNotificar($ip_cliente);
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['bloqueado_hasta']) && strtotime($user['bloqueado_hasta']) > time()) {
                $minutos_restantes = (int)ceil((strtotime($user['bloqueado_hasta']) - time()) / 60);
                $error = "Cuenta bloqueada temporalmente por múltiples intentos fallidos. Intenta de nuevo en $minutos_restantes minuto(s).";
                regenerarCaptcha();
                registrarFalloIpYNotificar($ip_cliente);
            } elseif ($user) {
                $password_valida = false;
                $necesita_rehash = false;

                if (function_exists('password_verify') && password_verify($password, $user['password'])) {
                    $password_valida = true;
                }
                elseif ($user['password'] === hash('sha256', $password)) {
                    $password_valida = true;
                    $necesita_rehash = true; // sha256 sin salt -- migrar a bcrypt
                }
                elseif ($user['password'] === md5($password)) {
                    $password_valida = true;
                    $necesita_rehash = true;
                }
                elseif ($user['password'] === sha1($password)) {
                    $password_valida = true;
                    $necesita_rehash = true;
                }

                if ($password_valida) {
                    // Migración transparente de contraseñas con hash débil (MD5/SHA1/SHA256 sin
                    // salt): en vez de forzar un reset a todos, se re-hashea a bcrypt en el
                    // siguiente login exitoso -- el usuario no nota nada, pero su hash queda
                    // fuerte desde ese momento en adelante.
                    if ($necesita_rehash) {
                        $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                           ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                    }

                    // Regenerar el ID de sesión ANTES de escribir datos nuevos -- si el ID de
                    // sesión previo fue fijado por un atacante (session fixation), queda
                    // invalidado en vez de heredar la sesión ya autenticada.
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['nombre_completo'];
                    $_SESSION['user_type'] = trim($user['tipo_usuario']);
                    $_SESSION['user_username'] = $user['username'];
                    
                    $accesos_usuario = [];
                    if (!empty($user['accesos'])) {
                        $decoded = json_decode($user['accesos'], true);
                        if (is_array($decoded)) {
                            $accesos_usuario = $decoded;
                        } else {
                            $accesos_usuario = explode(',', $user['accesos']);
                        }
                    }
                    
                    $_SESSION['user_accesos'] = $accesos_usuario;
                    
                    unset($_SESSION['admin_mode_selected']);
                    unset($_SESSION['admin_mode']);
                    
                    $stmt = $db->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?");
                    $stmt->execute([$user['id']]);

                    $stmt = $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, detalle, ip_address) VALUES (?, 'login', NULL, ?, ?)");
                    $stmt->execute([$user['id'], "Inicio de sesión: {$user['username']}", $ip_cliente]);

                    regenerarCaptcha();
                    limpiarIntentosFallidosIp($ip_cliente);
                    
                    $tipo = trim($user['tipo_usuario']);

                    // Admin siempre va al menú con acceso total
                    if ($tipo === 'admin') {
                        header('Location: Menu.php');
                        exit();
                    }

                    // Operador-Proceso: solo puede ver proceso.php, sin tiempos ni logs
                    if ($tipo === 'operador-proceso') {
                        $_SESSION['user_accesos'] = ['proceso'];
                        header('Location: proceso.php');
                        exit();
                    }

                    // Administrador-Proceso: ve Producción + Proceso con historial completo
                    if ($tipo === 'administrador-proceso') {
                        $_SESSION['user_accesos'] = ['manufactura', 'proceso'];
                        header('Location: Menu.php');
                        exit();
                    }

                    // Multiple: si tiene más de un acceso va al menú, si tiene uno va directo.
                    // Faltaban 'proyectos'/'manufactura'/'gerente'/'proceso' -- una cuenta 'multiple'
                    // con exactamente uno de esos accesos autenticaba bien pero caía en sin_acceso.php
                    // por no estar en este mapa. 'inventario' NO se agrega -- es un acceso legacy que
                    // ya no se checa en ningún lado del código (ni siquiera está en el checkbox de
                    // admin.php), no tiene sentido darle una ruta.
                    $url_map = [
                        'admin'      => 'admin.php',
                        'compras'    => 'compras.php',
                        'almacen'    => 'almacen2.php',
                        'ingenieria' => 'ingenieria.php',
                        'ventas'     => 'ventas.php',
                        'calidad'    => 'calidad.php',
                        'proyectos'  => 'proyecto.php',
                        'manufactura'=> 'produccion.php',
                        'gerente'    => 'gerencia.php',
                        'proceso'    => 'proceso.php',
                    ];

                    if (count($accesos_usuario) === 1) {
                        $unico = reset($accesos_usuario);
                        header('Location: ' . ($url_map[$unico] ?? 'sin_acceso.php'));
                        exit();
                    }

                    if (count($accesos_usuario) > 1) {
                        header('Location: Menu.php');
                        exit();
                    }

                    // Sin accesos asignados
                    header('Location: sin_acceso.php');
                    exit();
                } else {
                    // Bloqueo temporal tras varios fallos seguidos -- sin esto, un
                    // script podía probar contraseñas indefinidamente (el CAPTCHA
                    // matemático simple no frena a un bot de verdad).
                    $intentos = (int)$user['intentos_fallidos'] + 1;
                    if ($intentos >= 5) {
                        $db->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                           ->execute([$intentos, $user['id']]);
                        $error = 'Cuenta bloqueada temporalmente por múltiples intentos fallidos. Intenta de nuevo en 15 minutos.';
                        notificarRoles(['admin'], 'seguridad', 'Cuenta bloqueada por intentos fallidos',
                            "La cuenta \"{$user['username']}\" se bloqueó 15 min tras $intentos intentos fallidos seguidos (IP: $ip_cliente).", 'index.php');
                    } else {
                        $db->prepare("UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?")->execute([$intentos, $user['id']]);
                        $error = 'Usuario o contraseña incorrectos';
                    }
                    regenerarCaptcha();
                    registrarFalloIpYNotificar($ip_cliente);
                }
            } else {
                $error = 'Usuario o contraseña incorrectos';
                regenerarCaptcha();
                registrarFalloIpYNotificar($ip_cliente);
            }
        } catch (Exception $e) {
            $error = 'Error en el sistema: ' . $e->getMessage();
        }
    }
    } // fin else de loginIpBloqueada()
}

function getLogoPath() {
    $basePath = 'uploads/logo/';
    $logoFile = 'logo.png';
    $fullPath = __DIR__ . '/' . $basePath . $logoFile;
    
    if (file_exists($fullPath)) {
        return $basePath . $logoFile;
    }
    
    return 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200" viewBox="0 0 300 200"><rect width="300" height="200" fill="%23005b96"/><text x="50" y="120" font-family="Arial" font-size="24" fill="white">EMPRESA EJEMPLO</text></svg>';
}

$logoPath = getLogoPath();

$username_input_val = isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '';

$show_admin_modal = (isLoggedIn() && isset($_SESSION['user_username']) && $_SESSION['user_username'] === 'admin' && !isset($_SESSION['admin_mode_selected']));
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Figtree:wght@300;400;500;600;700&display=swap');

        /* ── THEME VARIABLES ─────────────────── */
        :root {
            --bg-right:    #ffffff;
            --bg-input:    #f7f9fb;
            --card-border: #d6e4ef;
            --t1:  #021e33;
            --t2:  #4a6578;
            --t3:  #8fa4b2;
            --primary:       #023760;
            --primary-dim:   rgba(2,55,96,0.07);
            --primary-glow:  rgba(2,55,96,0.22);
            --gold:          #BE893B;
            --gold-dim:      rgba(190,137,59,0.1);
            --err-bg:        rgba(200,30,30,0.06);
            --err-text:      #b91c1c;
            --err-border:    #dc2626;
            --foot-border:   #d6e4ef;
            --tog-bg:        #eaf0f5;
            --tog-icon:      #4a6578;
        }
        [data-theme="dark"] {
            --bg-right:    #051d2e;
            --bg-input:    rgba(255,255,255,0.05);
            --card-border: rgba(255,255,255,0.09);
            --t1:  #e8f2f9;
            --t2:  #7fa5be;
            --t3:  #4a6a80;
            --primary:       #4a9fd4;
            --primary-dim:   rgba(74,159,212,0.1);
            --primary-glow:  rgba(74,159,212,0.22);
            --gold:          #BE893B;
            --gold-dim:      rgba(190,137,59,0.12);
            --err-bg:        rgba(239,68,68,0.1);
            --err-text:      #fca5a5;
            --err-border:    #ef4444;
            --foot-border:   rgba(255,255,255,0.07);
            --tog-bg:        rgba(255,255,255,0.07);
            --tog-icon:      #7fa5be;
        }

        /* ── BASE ──────────────────────────────── */
        *, *::before, *::after {
            margin: 0; padding: 0;
            box-sizing: border-box;
            text-decoration: none !important;
            -webkit-user-select: none;
            user-select: none;
        }
        input { -webkit-user-select: text; user-select: text; }

        html { transition: background-color 0.35s; }

        body {
            font-family: 'Figtree', sans-serif;
            height: 100vh;
            overflow: hidden;
            background: var(--bg-right);
        }

        /* ── LAYOUT ────────────────────────────── */
        .layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            height: 100vh;
        }

        /* ── LEFT: GRADIENT MESH ───────────────── */
        .side-left {
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #011e32;
        }

        /* Animated gradient orbs — brand palette, restrained opacity so logo breathes */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.45;
            will-change: transform;
        }
        .orb1 { width: 500px; height: 500px; background: #023760; top: -180px; left: -140px;
                animation: ob1 13s ease-in-out infinite alternate; }
        .orb2 { width: 300px; height: 300px; background: #BE893B; top: 10%; right: -80px;
                opacity: 0.3;
                animation: ob2 16s ease-in-out infinite alternate; }
        .orb3 { width: 440px; height: 440px; background: #025a8a; bottom: -150px; left: 5%;
                animation: ob3 14s ease-in-out infinite alternate; }
        .orb4 { width: 260px; height: 260px; background: #BE893B; top: 50%; right: 6%;
                opacity: 0.2;
                animation: ob4 11s ease-in-out infinite alternate; }

        @keyframes ob1 { to { transform: translate(50px, 70px) scale(1.07); } }
        @keyframes ob2 { to { transform: translate(-30px, 50px) scale(0.93); } }
        @keyframes ob3 { to { transform: translate(40px, -60px) scale(1.1); } }
        @keyframes ob4 { to { transform: translate(-25px, 35px) scale(1.05); } }

        /* Fine noise overlay for depth */
        .side-left::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.07'/%3E%3C/svg%3E");
            pointer-events: none;
            opacity: 0.5;
        }

        .logo-area {
            position: relative;
            z-index: 5;
            display: flex;
            flex-direction: column;
            align-items: center;
            animation: riseUp 0.9s cubic-bezier(.22,1,.36,1) both;
        }

        .logo-img {
            max-width: 370px;
            max-height: 265px;
            object-fit: contain;
            filter: drop-shadow(0 6px 30px rgba(0,0,0,0.35)) brightness(1.06);
        }

        /* ── RIGHT: FORM PANEL ─────────────────── */
        .side-right {
            background: var(--bg-right);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 4rem 5.5rem;
            position: relative;
            transition: background 0.35s;
            animation: fadeSlide 0.65s cubic-bezier(.22,1,.36,1) both 0.1s;
        }

        /* Theme toggle */
        .theme-btn {
            position: absolute;
            top: 1.5rem; right: 1.5rem;
            width: 38px; height: 38px;
            border-radius: 50%;
            background: var(--tog-bg);
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: var(--tog-icon);
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        .theme-btn:hover {
            background: var(--primary-dim);
            color: var(--primary);
            transform: rotate(25deg) scale(1.1);
        }

        /* ── HEADING ────────────────────────────── */
        .login-head { margin-bottom: 2.6rem; }

        .login-eyebrow {
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: var(--primary);
            margin-bottom: 0.7rem;
            transition: color 0.35s;
        }

        .login-title {
            font-family: 'Syne', sans-serif;
            font-size: 3rem;
            font-weight: 800;
            line-height: 1.05;
            letter-spacing: -1.5px;
            color: var(--t1);
            transition: color 0.35s;
        }

        .login-sub {
            margin-top: 0.6rem;
            font-size: 0.92rem;
            color: var(--t2);
            font-weight: 400;
            transition: color 0.35s;
        }

        /* ── FORM FIELDS ────────────────────────── */
        .field { margin-bottom: 1.5rem; }

        .field-label {
            display: block;
            font-size: 0.76rem;
            font-weight: 600;
            color: var(--t2);
            margin-bottom: 0.5rem;
            transition: color 0.35s;
        }

        .field-wrap { position: relative; }

        .field-icon {
            position: absolute;
            left: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--t3);
            font-size: 0.85rem;
            pointer-events: none;
            transition: color 0.25s;
        }

        .field-input {
            width: 100%;
            padding: 0.82rem 1rem 0.82rem 2.8rem;
            background: var(--bg-input);
            border: 1.5px solid var(--card-border);
            border-radius: 10px;
            color: var(--t1);
            font-family: 'Figtree', sans-serif;
            font-size: 0.94rem;
            font-weight: 500;
            outline: none;
            transition: border-color 0.25s, background 0.25s, box-shadow 0.25s, color 0.35s;
        }
        .field-input::placeholder { color: var(--t3); font-weight: 400; }
        .field-input:focus {
            border-color: var(--primary);
            background: var(--primary-dim);
            box-shadow: 0 0 0 4px var(--primary-dim);
        }
        .field-wrap:focus-within .field-icon { color: var(--primary); }

        .pass-toggle {
            position: absolute;
            right: 2px; top: 50%;
            transform: translateY(-50%);
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            background: none; border: none;
            color: var(--t3); cursor: pointer;
            font-size: 0.85rem;
            border-radius: 8px;
            transition: color 0.25s;
        }
        .pass-toggle:hover { color: var(--primary); }

        /* ── CAPTCHA ────────────────────────────── */
        .captcha-label {
            display: block;
            font-size: 0.76rem;
            font-weight: 600;
            color: var(--t2);
            margin-bottom: 0.5rem;
            transition: color 0.35s;
        }

        .captcha-row {
            display: flex;
            margin-bottom: 1.5rem;
            border: 1.5px solid var(--card-border);
            border-radius: 10px;
            overflow: hidden;
            transition: border-color 0.25s, box-shadow 0.25s;
        }
        .captcha-row:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-dim);
        }

        .captcha-img-wrap {
            padding: 0.4rem 0.6rem;
            background: var(--primary-dim);
            border-right: 1.5px solid var(--card-border);
            display: flex; align-items: center; gap: 8px;
            transition: background 0.35s, border-color 0.25s;
        }
        .captcha-img-wrap img {
            display: block;
            border-radius: 6px;
        }
        .captcha-refresh {
            color: var(--t3);
            font-size: 0.95rem;
            cursor: pointer;
            transition: color 0.2s, transform 0.2s;
        }
        .captcha-refresh:hover { color: var(--primary); transform: rotate(90deg); }

        .captcha-answer {
            flex: 1;
            padding: 0.82rem 1rem;
            background: var(--bg-input);
            border: none;
            color: var(--t1);
            font-family: 'Figtree', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            text-align: center;
            outline: none;
            transition: background 0.35s, color 0.35s;
        }
        .captcha-answer::placeholder {
            font-size: 0.72rem;
            font-weight: 400;
            color: var(--t3);
        }

        /* ── BUTTON ─────────────────────────────── */
        .btn-submit {
            width: 100%;
            padding: 0.95rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Syne', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: background 0.35s, transform 0.2s, box-shadow 0.3s;
            margin-top: 0.5rem;
        }
        .btn-submit::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.22), transparent);
            transform: translateX(-100%);
            transition: transform 0.55s;
        }
        .btn-submit:hover::after { transform: translateX(100%); }
        .btn-submit:hover {
            box-shadow: 0 8px 28px var(--primary-glow);
            transform: translateY(-2px);
        }
        .btn-submit:active { transform: translateY(0); box-shadow: none; }

        /* ── ALERT ──────────────────────────────── */
        .alert {
            display: flex; align-items: flex-start; gap: 0.65rem;
            padding: 0.85rem 1rem;
            margin-bottom: 1.4rem;
            border-radius: 10px;
            font-size: 0.86rem;
            border-left: 3px solid var(--err-border);
            background: var(--err-bg);
            color: var(--err-text);
            animation: slideDown 0.35s ease;
            transition: background 0.35s, color 0.35s;
        }
        .alert i { flex-shrink: 0; margin-top: 2px; }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── FOOTER ─────────────────────────────── */
        .login-foot {
            margin-top: 2rem;
            display: flex; justify-content: space-between; align-items: center;
            padding-top: 1.3rem;
            border-top: 1px solid var(--foot-border);
            transition: border-color 0.35s;
        }
        .foot-left {
            display: flex; align-items: center; gap: 7px;
            font-size: 0.72rem; color: var(--t3);
            transition: color 0.35s;
        }
        .foot-dot {
            width: 6px; height: 6px;
            background: #22c55e; border-radius: 50%;
            animation: blink 2.5s ease-in-out infinite;
        }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .foot-right {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.72rem; color: var(--t3);
            transition: color 0.35s;
        }

        /* ── MODAL ──────────────────────────────── */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(6px);
            display: none; align-items: center; justify-content: center;
            z-index: 2000;
        }
        .modal-overlay.active { display: flex; }

        .modal {
            background: var(--bg-right);
            border: 1.5px solid var(--card-border);
            border-top: 3px solid var(--primary);
            border-radius: 14px;
            width: 90%; max-width: 460px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.2);
            animation: riseUp 0.3s cubic-bezier(.22,1,.36,1);
            transition: background 0.35s, border-color 0.35s;
        }
        .modal-head {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--foot-border);
            display: flex; justify-content: space-between; align-items: center;
            transition: border-color 0.35s;
        }
        .modal-head h3 {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Syne', sans-serif;
            font-size: 1rem; font-weight: 700; color: var(--t1);
            transition: color 0.35s;
        }
        .modal-head h3 i { color: var(--primary); }
        .modal-close {
            background: none; border: none;
            color: var(--t3); font-size: 1rem; cursor: pointer;
            width: 30px; height: 30px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 6px; transition: all 0.2s;
        }
        .modal-close:hover { background: var(--primary-dim); color: var(--primary); }
        .modal-body { padding: 2rem 1.5rem; text-align: center; }
        .modal-body p { color: var(--t2); margin-bottom: 1.5rem; font-size: 0.92rem; transition: color 0.35s; }
        .modal-opts { display: flex; gap: 1rem; justify-content: center; }
        .mbtn {
            padding: 0.82rem 1.8rem; border: none; border-radius: 10px;
            font-family: 'Syne', sans-serif;
            font-size: 0.85rem; font-weight: 700; letter-spacing: 0.3px;
            cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.25s;
        }
        .mbtn-blue  { background: #3b82f6; color: #fff; }
        .mbtn-blue:hover  { background: #2563eb; transform: translateY(-2px); box-shadow: 0 6px 18px rgba(59,130,246,0.35); }
        .mbtn-amber { background: var(--amber); color: #000; }
        .mbtn-amber:hover { filter: brightness(1.08); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(245,158,11,0.35); }

        /* ── ANIMATIONS ─────────────────────────── */
        @keyframes riseUp    { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeSlide { from { opacity: 0; transform: translateX(16px);} to { opacity: 1; transform: translateX(0); } }

        /* ── RESPONSIVE ─────────────────────────── */
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; grid-template-rows: 210px 1fr; }
            .side-left { min-height: 210px; }
            .logo-img  { max-width: 195px; max-height: 135px; }
            .side-right { padding: 2.5rem 2rem; overflow-y: auto; }
            .login-title { font-size: 2.4rem; }
            .modal-opts { flex-direction: column; }
        }
        @media (max-width: 480px) {
            .side-right { padding: 2rem 1.4rem; }
            .login-title { font-size: 2rem; }
            .captcha-row { flex-direction: column; border-radius: 10px; }
            .captcha-eq  { border-right: none; border-bottom: 1.5px solid var(--card-border); }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- LEFT: animated gradient mesh -->
    <div class="side-left">
        <div class="orb orb1"></div>
        <div class="orb orb2"></div>
        <div class="orb orb3"></div>
        <div class="orb orb4"></div>
        <div class="logo-area">
            <img src="<?php echo $logoPath; ?>" alt="Logo EMPRESA EJEMPLO" class="logo-img">
        </div>
    </div>

    <!-- RIGHT: login form -->
    <div class="side-right">

        <button class="theme-btn" id="themeBtn" title="Cambiar tema" onclick="toggleTheme()">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>

        <div class="login-head">
            <p class="login-eyebrow">Bienvenido de nuevo</p>
            <h1 class="login-title">Inicia sesión.</h1>
            <p class="login-sub">Ingresa tus credenciales para continuar</p>
        </div>

        <?php if ($error): ?>
        <div class="alert" id="error-alert">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php echo $error; ?></div>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" autocomplete="off">

            <div class="field">
                <label class="field-label">Usuario</label>
                <div class="field-wrap">
                    <span class="field-icon"><i class="fas fa-user"></i></span>
                    <input
                        type="text"
                        name="username"
                        class="field-input"
                        placeholder="Ingrese su usuario"
                        required
                        value="<?php echo $username_input_val; // nosemgrep ?>"
                    >
                </div>
            </div>

            <div class="field">
                <label class="field-label">Contraseña</label>
                <div class="field-wrap">
                    <span class="field-icon"><i class="fas fa-lock"></i></span>
                    <input
                        type="password"
                        name="password"
                        id="passwordField"
                        class="field-input"
                        placeholder="Ingrese su contraseña"
                        required
                        style="padding-right: 42px;"
                    >
                    <button type="button" class="pass-toggle" id="togglePassword" onclick="togglePasswordVisibility()">
                        <i class="far fa-eye" id="togglePasswordIcon"></i>
                    </button>
                </div>
            </div>

            <span class="captcha-label">Verificación de seguridad</span>
            <div class="captcha-row">
                <div class="captcha-img-wrap">
                    <img src="captcha_img.php?v=<?php echo time(); ?>" width="140" height="50" alt="Código de verificación">
                    <a href="index.php?nuevo_captcha=1" class="captcha-refresh" title="Generar otro código">
                        <i class="fas fa-sync-alt"></i>
                    </a>
                </div>
                <input
                    type="text"
                    name="captcha_respuesta"
                    class="captcha-answer"
                    placeholder="Código de la imagen"
                    required autocomplete="off"
                    maxlength="5" style="text-transform:uppercase;"
                >
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i>&nbsp;&nbsp;Iniciar Sesión
            </button>

        </form>

        <div class="login-foot">
            <div class="foot-left">
                <span class="foot-dot"></span>
                Conexión segura
            </div>
            <div class="foot-right">
                &copy; <?php echo date('Y'); ?>&nbsp;
                <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO" style="height:14px;width:auto;object-fit:contain;opacity:0.4;">
            </div>
        </div>

    </div>
</div>

<!-- ADMIN MODAL -->
<div class="modal-overlay" id="adminModeModal">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fas fa-user-shield"></i> Selecciona el modo de acceso</h3>
            <button class="modal-close" onclick="cerrarModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>¿Cómo deseas ingresar al sistema?</p>
            <div class="modal-opts">
                <form method="POST" action="set_admin_mode.php" style="display:inline;">
                    <input type="hidden" name="mode" value="normal">
                    <button type="submit" class="mbtn mbtn-blue">
                        <i class="fas fa-user-check"></i> Modo normal
                    </button>
                </form>
                <form method="POST" action="set_admin_mode.php" style="display:inline;">
                    <input type="hidden" name="mode" value="prueba">
                    <button type="submit" class="mbtn mbtn-amber">
                        <i class="fas fa-flask"></i> Modo de prueba
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    /* Theme toggle – runs immediately to avoid flash */
    (function() {
        const saved = localStorage.getItem('srTheme') || 'light';
        document.documentElement.setAttribute('data-theme', saved);
        applyThemeIcon(saved);
    })();

    function toggleTheme() {
        const cur  = document.documentElement.getAttribute('data-theme');
        const next = cur === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('srTheme', next);
        applyThemeIcon(next);
    }

    function applyThemeIcon(theme) {
        const icon = document.getElementById('themeIcon');
        if (!icon) return;
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }

    <?php if ($show_admin_modal): ?>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('adminModeModal').classList.add('active');
    });
    <?php endif; ?>

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') e.preventDefault();
    });

    document.addEventListener('DOMContentLoaded', function() {
        const alert = document.getElementById('error-alert');
        if (alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 5000);
        }
        const captchaInput = document.querySelector('input[name="captcha_respuesta"]');
        if (captchaInput) captchaInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
        });
    });

    document.body.addEventListener('mousedown', function(e) {
        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') e.preventDefault();
    });
    document.body.addEventListener('selectstart', function(e) {
        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') e.preventDefault();
    });

    function cerrarModal() {
        document.getElementById('adminModeModal').classList.remove('active');
    }
    document.getElementById('adminModeModal').addEventListener('click', function(e) {
        if (e.target === this) cerrarModal();
    });

    function togglePasswordVisibility() {
        const f = document.getElementById('passwordField');
        const i = document.getElementById('togglePasswordIcon');
        if (f.type === 'password') {
            f.type = 'text';
            i.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            f.type = 'password';
            i.classList.replace('fa-eye-slash', 'fa-eye');
        }
        setTimeout(function() { f.selectionStart = f.selectionEnd = f.value.length; }, 0);
    }
</script>
</body>
</html>