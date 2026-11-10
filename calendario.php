<?php
require_once 'includes/config.php';

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

if (!isLoggedIn()) redirect('index.php');
if (!hasAcceso('ingenieria') && !hasAcceso('proyectos') && !isAdmin()) redirect('Menu.php');

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario de Proyectos — EMPRESA EJEMPLO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <style>
        :root { --color-ingenieria: #023760; --color-servicios: #2E7D32; --color-accent: #BE893B; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; margin: 0; }

        /* ── Top Header (same as ingenieria.php) ── */
        .top-header {
            position: fixed; top: 0; left: 0; width: 100%; height: 80px;
            background: #2E5CAE;
            box-shadow: 0 2px 10px rgba(46,92,174,0.25);
            border-bottom: 2px solid #c9a227;
            z-index: 1000; display: flex; align-items: center;
            justify-content: space-between; padding: 0 30px; box-sizing: border-box;
        }
        /* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
        .header-logo { height: 66px; width: auto; object-fit: contain; }
        .header-right { display: flex; align-items: center; gap: 20px; }
        .back-button-header {
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);
            border-radius: 40px; padding: 8px 20px; cursor: pointer; font-weight: 600;
            color: white; display: flex; align-items: center; gap: 8px;
            transition: all 0.2s; font-size: 14px; text-decoration: none;
        }
        .back-button-header:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.5); transform: translateX(-5px); }
        .user-dropdown { position: relative; display: inline-block; }
        .user-trigger {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.15); padding: 5px 15px 5px 5px;
            border-radius: 40px; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); transition: all 0.2s;
        }
        .user-trigger:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.5); }
        .user-avatar {
            width: 35px; height: 35px; border-radius: 50%; background: #FF9800;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 16px; color: white;
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
            color: #333; transition: all 0.2s; cursor: pointer; border: none;
            background: none; width: 100%; font-size: 13px; text-decoration: none;
        }
        .dropdown-item:hover { background: #f5f5f5; color: #f44336; }
        .dropdown-item i { width: 18px; color: #666; }

        /* ── Main content ── */
        .app-container { padding-top: 80px; min-height: 100vh; }
        .main-content { padding: 2rem; }
        .page-header { margin-bottom: 1.5rem; }
        .page-header h1 { font-size: 1.6rem; color: #1a2b42; margin: 0 0 0.5rem; }

        .leyenda { display: flex; gap: 1.2rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .leyenda-item { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #555; }
        .leyenda-color { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }

        #calendar-container { background: #fff; border-radius: 10px; padding: 1.5rem;
                              box-shadow: 0 2px 8px rgba(0,0,0,0.07); }

        /* ── Popup ── */
        .popup-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45);
                         z-index: 2000; align-items: center; justify-content: center; }
        .popup-overlay.open { display: flex; }
        .popup { background: #fff; border-radius: 12px; padding: 1.8rem;
                 width: 90%; max-width: 420px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .popup h3 { margin: 0 0 1rem; font-size: 1.15rem; color: #1a2b42; }
        .popup-meta { display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.88rem; color: #555; }
        .popup-meta strong { color: #1a2b42; }
        .badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
        .badge-verde { background: #e8f5e9; color: #2E7D32; }
        .badge-rojo  { background: #fdecea; color: #c0392b; }
        .popup-close { margin-top: 1.2rem; text-align: right; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1.1rem;
               border-radius: 6px; font-size: 0.88rem; font-weight: 600; cursor: pointer; border: none; }
        .btn-outline { background: transparent; border: 1.5px solid var(--color-ingenieria); color: var(--color-ingenieria); }
    </style>
</head>
<body>
    <div class="top-header">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
             onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
        <div class="header-right">
            <a href="proyecto.php" class="back-button-header">
                <i class="fas fa-arrow-left"></i> Mis Proyectos
            </a>
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
                    <a href="Menu.php" class="dropdown-item" style="color:#4CAF50;">
                        <i class="fas fa-arrow-left"></i> Volver al Menú
                    </a>
                    <div style="height:1px;background:#e0e0e0;margin:5px 0;"></div>
                    <?php endif; ?>
                    <a href="?logout=1" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-container">
        <div class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-calendar-alt"></i> Calendario de Proyectos</h1>
            </div>

            <div class="leyenda">
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background:#023760"></div>
                    <span>Ingeniería / Proyectos</span>
                </div>
                <?php if (isAdmin()): ?>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background:#2E7D32"></div>
                    <span>Servicios</span>
                </div>
                <?php endif; ?>
            </div>

            <div id="calendar-container">
                <div id="calendar"></div>
            </div>
        </div>
    </div>

<div class="popup-overlay" id="popupEvento">
    <div class="popup">
        <h3 id="popupTitulo"></h3>
        <div class="popup-meta">
            <div><strong>Tipo:</strong> <span id="popupTipo"></span></div>
            <div><strong>Estado:</strong> <span id="popupEstado"></span></div>
            <div><strong>Inicio:</strong> <span id="popupInicio"></span></div>
            <div id="popupFinWrap"><strong>Fin:</strong> <span id="popupFin"></span></div>
            <div id="popupDiasWrap"><span id="popupDias" class="badge"></span></div>
        </div>
        <div class="popup-close">
            <button class="btn btn-outline" onclick="cerrarPopup()">Cerrar</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const calEl = document.getElementById('calendar');
    const calendar = new FullCalendar.Calendar(calEl, {
        initialView: 'dayGridMonth',
        locale: 'es',
        height: 'auto',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,listMonth'
        },
        buttonText: {
            today: 'Hoy',
            month: 'Mes',
            week:  'Semana',
            list:  'Lista'
        },
        events: 'api/calendario.php',
        eventClick: function(info) {
            const e = info.event;
            const p = e.extendedProps;

            document.getElementById('popupTitulo').textContent = e.title;
            document.getElementById('popupTipo').textContent   = capitalize(p.tipo);
            document.getElementById('popupEstado').textContent = capitalize(p.estado.replace('_', ' '));
            document.getElementById('popupInicio').textContent = formatFecha(e.startStr);

            if (e.endStr) {
                document.getElementById('popupFinWrap').style.display = 'block';
                document.getElementById('popupFin').textContent = formatFecha(e.endStr);
            } else {
                document.getElementById('popupFinWrap').style.display = 'none';
            }

            if (p.dias_texto) {
                document.getElementById('popupDiasWrap').style.display = 'block';
                const badge = document.getElementById('popupDias');
                badge.textContent = p.dias_texto;
                badge.className   = 'badge ' + (p.dias_texto.includes('retraso') ? 'badge-rojo' : 'badge-verde');
            } else {
                document.getElementById('popupDiasWrap').style.display = 'none';
            }

            document.getElementById('popupEvento').classList.add('open');
        }
    });
    calendar.render();
});

function cerrarPopup() {
    document.getElementById('popupEvento').classList.remove('open');
}

document.getElementById('popupEvento').addEventListener('click', function(e) {
    if (e.target === this) cerrarPopup();
});

function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatFecha(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' });
}
</script>
</body>
</html>
