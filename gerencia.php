<?php
require_once 'includes/config.php';

if (!isLoggedIn()) redirect('index.php');
if (!isAdmin() && !hasAcceso('gerente')) redirect('Menu.php');

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

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;

$db = getDB();

$stmt_pend = $db->query("
    SELECT oc.*, pv.nombre as prov_nombre, p.nombre as proyecto_nombre
    FROM ordenes_compra oc
    LEFT JOIN proveedores pv ON oc.proveedor_id = pv.id
    LEFT JOIN proyectos p ON oc.proyecto_id = p.id
    WHERE oc.estado = 'pendiente_aprobacion'
    ORDER BY oc.fecha_creacion ASC
");
$pendientes = $stmt_pend->fetchAll();

$stmt_hist = $db->query("
    SELECT oc.*, pv.nombre as prov_nombre, p.nombre as proyecto_nombre,
           u.nombre_completo as aprobador_nombre
    FROM ordenes_compra oc
    LEFT JOIN proveedores pv ON oc.proveedor_id = pv.id
    LEFT JOIN proyectos p ON oc.proyecto_id = p.id
    LEFT JOIN usuarios u ON oc.aprobado_por = u.id
    WHERE oc.estado IN ('aprobada','rechazada','recibida')
    ORDER BY oc.fecha_aprobacion DESC
    LIMIT 50
");
$historial = $stmt_hist->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerencia — Aprobación de OCs</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; text-decoration: none !important; }
        body { font-family: 'Montserrat', sans-serif; background: #ffffff; height: 100vh; overflow: hidden; color: #333; }

        /* ── TOP HEADER ── */
        .top-header {
            position: fixed; top: 0; left: 0; width: 100%; height: 80px;
            background: #2E5CAE;
            box-shadow: 0 2px 10px rgba(46,92,174,0.25);
            border-bottom: 2px solid #c9a227;
            z-index: 1000; display: flex; align-items: center;
            justify-content: space-between; padding: 0 30px;
        }
        /* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
        .header-logo { height: 66px; width: auto; object-fit: contain; }
        .header-right { display: flex; align-items: center; gap: 20px; }
        .user-dropdown { position: relative; display: inline-block; }
        .user-trigger {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.15); padding: 5px 15px 5px 5px;
            border-radius: 40px; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); transition: all 0.2s;
        }
        .user-trigger:hover { background: rgba(255,255,255,0.25); }
        .user-avatar {
            width: 35px; height: 35px; border-radius: 50%; background: #FF9800;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 16px; color: white;
        }
        .user-info-text { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; color: white; font-size: 13px; line-height: 1.2; }
        .user-role { font-size: 10px; color: rgba(255,255,255,0.8); text-transform: uppercase; }
        .user-dropdown-menu {
            position: absolute; top: calc(100% + 5px); right: 0; width: 200px;
            background: white; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            opacity: 0; visibility: hidden; transform: translateY(-5px);
            transition: all 0.2s; border: 1px solid #e0e0e0; overflow: hidden; z-index: 1000;
        }
        .user-dropdown:hover .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-item {
            padding: 12px 20px; display: flex; align-items: center; gap: 10px;
            color: #333; transition: all 0.2s; font-size: 13px; font-family: 'Montserrat', sans-serif;
        }
        .dropdown-item:hover { background: #f5f5f5; color: #f44336; }
        .dropdown-item i { width: 18px; color: #666; }

        /* ── LAYOUT ── */
        .app-container { height: 100vh; padding-top: 80px; padding-bottom: 60px; overflow-y: auto; }
        .app-inner { max-width: 1100px; margin: 0 auto; padding: 28px 24px; }

        /* ── SECTION CARDS ── */
        .section-card {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            margin-bottom: 28px; overflow: hidden;
            border: 1px solid #e8edf2;
        }
        .section-header {
            padding: 16px 24px; border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; gap: 10px; background: #f8f9fa;
        }
        .section-header h2 { font-size: 15px; font-weight: 700; color: #003d5c; }
        .section-body { padding: 20px 24px; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 8px 12px; background: #f4f7fb; color: #003d5c; font-weight: 700; border-bottom: 2px solid #e0e0e0; }
        td { padding: 10px 12px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; font-family: 'Montserrat', sans-serif; }
        .btn-success { background: #1b5e20; color: white; } .btn-success:hover { background: #2e7d32; }
        .btn-danger  { background: #b71c1c; color: white; } .btn-danger:hover  { background: #c62828; }
        .btn-outline { background: transparent; border: 1px solid #ccc; color: #555; } .btn-outline:hover { background: #f5f5f5; }
        .btn-sm { padding: 5px 10px; font-size: 11px; }

        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-apr  { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
        .badge-rech { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }

        .empty-state { text-align: center; color: #bbb; padding: 40px 20px; font-size: 14px; }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 10px; }

        /* ── FOOTER ── */
        .dashboard-footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            display: flex; justify-content: space-between; align-items: center;
            padding: 15px 40px; border-top: 2px solid #e0e0e0;
            background: white; height: 60px; z-index: 1000;
        }
        .footer-left { display: flex; gap: 30px; color: #555; font-size: 13px; font-weight: 500; }
        .footer-left i { color: #003d5c; width: 16px; margin-right: 5px; }
        .footer-right img { height: 28px; }

        /* ── MODALS ── */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; border-radius: 12px; width: 92%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
        .modal-header { padding: 18px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 15px; color: #003d5c; }
        .modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: #888; line-height: 1; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 14px 20px; border-top: 1px solid #eee; display: flex; gap: 10px; justify-content: flex-end; }
        .tabs { display: flex; gap: 0; border-bottom: 2px solid #eee; margin-bottom: 16px; }
        .tab-btn { padding: 8px 18px; border: none; background: none; font-family: 'Montserrat', sans-serif; font-size: 13px; font-weight: 600; color: #888; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .tab-btn.active { color: #003d5c; border-bottom-color: #003d5c; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        canvas#firmaCanvas { border: 2px dashed #ccc; border-radius: 8px; cursor: crosshair; display: block; width: 100%; touch-action: none; }
        .form-label { display: block; font-size: 12px; font-weight: 600; color: #555; margin-bottom: 6px; }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: 'Montserrat', sans-serif; }
        textarea.form-input { resize: vertical; min-height: 90px; }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>

<!-- ── TOP HEADER ── -->
<div class="top-header">
    <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
    <div class="header-right">
        <div class="user-dropdown">
            <div class="user-trigger">
                <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($_SESSION['user_type']); ?></div>
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
<div class="app-inner">

    <!-- OCs PENDIENTES -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-clock" style="color:#e65100"></i>
            <h2>Pendientes de aprobación (<?php echo count($pendientes); ?>)</h2>
        </div>
        <div class="section-body">
            <?php if (empty($pendientes)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:10px;color:#4caf50;"></i>
                    Sin OCs pendientes de aprobación.
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Proyecto</th>
                        <th>Proveedor</th>
                        <th>Total</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendientes as $oc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($oc['folio']); ?></strong></td>
                        <td><?php echo htmlspecialchars($oc['proyecto_nombre'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($oc['prov_nombre'] ?? '—'); ?></td>
                        <td><strong>$<?php echo number_format($oc['total'], 2); ?></strong> <?php echo htmlspecialchars($oc['moneda'] ?? 'MXN'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($oc['fecha_creacion'])); ?></td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <a href="orden_compra.php?orden_id=<?php echo $oc['id']; ?>" class="btn btn-outline btn-sm">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                                <button class="btn btn-success btn-sm" onclick="abrirAprobar(<?php echo $oc['id']; ?>, '<?php echo addslashes(htmlspecialchars($oc['folio'])); ?>')">
                                    <i class="fas fa-check"></i> Aprobar
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="abrirRechazar(<?php echo $oc['id']; ?>, '<?php echo addslashes(htmlspecialchars($oc['folio'])); ?>')">
                                    <i class="fas fa-times"></i> Rechazar
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- HISTORIAL -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-history" style="color:#555"></i>
            <h2>Historial reciente</h2>
        </div>
        <div class="section-body">
            <?php if (empty($historial)): ?>
                <div class="empty-state">Sin historial aún.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr><th>Folio</th><th>Proyecto</th><th>Proveedor</th><th>Total</th><th>Estado</th><th>Aprobador</th><th>Fecha</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($historial as $oc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($oc['folio']); ?></strong></td>
                        <td><?php echo htmlspecialchars($oc['proyecto_nombre'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($oc['prov_nombre'] ?? '—'); ?></td>
                        <td>$<?php echo number_format($oc['total'], 2); ?></td>
                        <td>
                            <?php if ($oc['estado'] === 'aprobada'): ?>
                                <span class="badge badge-apr"><i class="fas fa-check-circle"></i> Aprobada</span>
                            <?php elseif ($oc['estado'] === 'recibida'): ?>
                                <span class="badge" style="background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;"><i class="fas fa-box"></i> Recibida</span>
                            <?php else: ?>
                                <span class="badge badge-rech"><i class="fas fa-times-circle"></i> Rechazada</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($oc['aprobador_nombre'] ?? '—'); ?></td>
                        <td><?php echo $oc['fecha_aprobacion'] ? date('d/m/Y H:i', strtotime($oc['fecha_aprobacion'])) : '—'; ?></td>
                        <td><a href="orden_compra.php?orden_id=<?php echo $oc['id']; ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.app-inner -->
</div><!-- /.app-container -->

<!-- ── FOOTER ── -->
<div class="dashboard-footer">
    <div class="footer-left">
        <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
        <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
    </div>
    <div class="footer-right">
        <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO" onerror="this.style.display='none'">
    </div>
</div>

<!-- MODAL APROBAR -->
<div id="modalAprobar" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle" style="color:#2e7d32"></i> Aprobar OC — <span id="aprobarFolio"></span></h3>
            <button class="modal-close" onclick="cerrarModal('modalAprobar')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="aprobarOrdenId">
            <p style="font-size:13px;color:#555;margin-bottom:14px;">Dibuja o sube tu firma para autorizar esta OC.</p>
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('dibujar')">Dibujar firma</button>
                <button class="tab-btn" onclick="switchTab('subir')">Subir imagen</button>
            </div>
            <div id="tabDibujar" class="tab-panel active">
                <canvas id="firmaCanvas" width="440" height="150"></canvas>
                <button class="btn btn-outline btn-sm" style="margin-top:8px;" onclick="limpiarCanvas()"><i class="fas fa-eraser"></i> Limpiar</button>
            </div>
            <div id="tabSubir" class="tab-panel">
                <label class="form-label">Selecciona imagen de firma (PNG, JPG, GIF)</label>
                <input type="file" id="firmaArchivo" accept="image/*" class="form-input">
                <div id="firmaPreview" style="margin-top:10px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="cerrarModal('modalAprobar')">Cancelar</button>
            <button id="btnConfirmarAprobar" class="btn btn-success" onclick="confirmarAprobar()"><i class="fas fa-check"></i> Confirmar aprobación</button>
        </div>
    </div>
</div>

<!-- MODAL RECHAZAR -->
<div id="modalRechazar" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle" style="color:#b71c1c"></i> Rechazar OC — <span id="rechazarFolio"></span></h3>
            <button class="modal-close" onclick="cerrarModal('modalRechazar')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="rechazarOrdenId">
            <label class="form-label">Motivo del rechazo <span style="color:#b71c1c">*</span></label>
            <textarea id="rechazarMotivo" class="form-input" placeholder="Describe el motivo del rechazo para que compras pueda corregirlo..."></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="cerrarModal('modalRechazar')">Cancelar</button>
            <button id="btnConfirmarRechazar" class="btn btn-danger" onclick="confirmarRechazar()"><i class="fas fa-times"></i> Confirmar rechazo</button>
        </div>
    </div>
</div>

<script>
// Canvas firma
const canvas = document.getElementById('firmaCanvas');
const ctx    = canvas.getContext('2d');
let drawing  = false;

function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width  / rect.width;
    const scaleY = canvas.height / rect.height;
    const src = e.touches ? e.touches[0] : e;
    return { x: (src.clientX - rect.left) * scaleX, y: (src.clientY - rect.top) * scaleY };
}

canvas.addEventListener('mousedown',  e => { drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
canvas.addEventListener('mousemove',  e => { if (!drawing) return; const p = getPos(e); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#003d5c'; ctx.lineTo(p.x, p.y); ctx.stroke(); });
canvas.addEventListener('mouseup',    () => drawing = false);
canvas.addEventListener('mouseleave', () => drawing = false);
canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }, {passive: false});
canvas.addEventListener('touchmove',  e => { e.preventDefault(); if (!drawing) return; const p = getPos(e); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#003d5c'; ctx.lineTo(p.x, p.y); ctx.stroke(); }, {passive: false});
canvas.addEventListener('touchend',   () => drawing = false);

function limpiarCanvas() { ctx.clearRect(0, 0, canvas.width, canvas.height); }

function canvasEsVacio() {
    const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    return !data.some(v => v !== 0);
}

// Tabs
let tabActual = 'dibujar';
function switchTab(tab) {
    tabActual = tab;
    document.querySelectorAll('.tab-btn').forEach((b, i) => b.classList.toggle('active', (i === 0 && tab === 'dibujar') || (i === 1 && tab === 'subir')));
    document.getElementById('tabDibujar').classList.toggle('active', tab === 'dibujar');
    document.getElementById('tabSubir').classList.toggle('active', tab === 'subir');
}

document.getElementById('firmaArchivo').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('firmaPreview').innerHTML = `<img src="${e.target.result}" style="max-height:100px;border:1px solid #ddd;border-radius:4px;">`;
    };
    reader.readAsDataURL(file);
});

// Modales
let _gerenciaOrdenId = 0;
function abrirAprobar(ordenId, folio) {
    _gerenciaOrdenId = parseInt(ordenId);
    document.getElementById('aprobarOrdenId').value = ordenId;
    document.getElementById('aprobarFolio').textContent = folio;
    limpiarCanvas();
    document.getElementById('firmaArchivo').value = '';
    document.getElementById('firmaPreview').innerHTML = '';
    switchTab('dibujar');
    document.getElementById('modalAprobar').classList.add('active');
}

function abrirRechazar(ordenId, folio) {
    document.getElementById('rechazarOrdenId').value = ordenId;
    document.getElementById('rechazarFolio').textContent = folio;
    document.getElementById('rechazarMotivo').value = '';
    document.getElementById('modalRechazar').classList.add('active');
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Confirmar aprobar
async function confirmarAprobar() {
    const ordenId = _gerenciaOrdenId || parseInt(document.getElementById('aprobarOrdenId').value);
    if (!ordenId) { alert('Error: no se detectó el ID de la OC. Cierra el modal y vuelve a intentar.'); return; }

    const btn = document.getElementById('btnConfirmarAprobar');

    // Validaciones previas (antes de deshabilitar el botón)
    if (tabActual === 'dibujar') {
        if (canvasEsVacio()) { alert('Dibuja tu firma antes de confirmar.'); return; }
    } else {
        const file = document.getElementById('firmaArchivo').files[0];
        if (!file) { alert('Selecciona una imagen de firma.'); return; }
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

    // Helper de envío
    const enviar = async (fd) => {
        try {
            const r    = await fetch('api/aprobar_oc.php', { method: 'POST', body: fd });
            const data = await r.json();
            if (data.ok) {
                cerrarModal('modalAprobar');
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'desconocido'));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Confirmar aprobación';
            }
        } catch(e) {
            alert('Error de conexión: ' + e.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Confirmar aprobación';
        }
    };

    if (tabActual === 'dibujar') {
        // Usar toBlob() en lugar de toDataURL para evitar problemas con campos de texto grandes
        canvas.toBlob(blob => {
            const fd = new FormData();
            fd.append('orden_id', String(ordenId));
            fd.append('firma_archivo', blob, 'firma_' + ordenId + '.png');
            enviar(fd);
        }, 'image/png');
    } else {
        const file = document.getElementById('firmaArchivo').files[0];
        const fd = new FormData();
        fd.append('orden_id', String(ordenId));
        fd.append('firma_archivo', file);
        enviar(fd);
    }

}

// Confirmar rechazar
async function confirmarRechazar() {
    const ordenId = document.getElementById('rechazarOrdenId').value;
    const motivo  = document.getElementById('rechazarMotivo').value.trim();
    if (!motivo) { alert('Escribe un motivo de rechazo.'); return; }

    const btn = document.getElementById('btnConfirmarRechazar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

    try {
        const r    = await fetch('api/rechazar_oc.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({orden_id: ordenId, motivo})
        });
        const data = await r.json();
        if (data.ok) {
            cerrarModal('modalRechazar');
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'desconocido'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-times"></i> Confirmar rechazo';
        }
    } catch(e) {
        alert('Error de conexión');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-times"></i> Confirmar rechazo';
    }
}
</script>

</body>
</html>
