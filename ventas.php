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

if (!isLoggedIn() || (!hasAcceso('ventas') && !isAdmin())) {
    header('Location: index.php');
    exit();
}

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;
$db      = getDB();
$seccion = $_GET['seccion'] ?? '';
$message = '';
$error   = '';

// ── Generador de folio ──────────────────────────────────────────────────────
function generarFolioVenta() {
    $db   = getDB();
    $year = date('Y');
    $stmt = $db->query("SELECT COUNT(*) FROM ventas_cotizaciones WHERE YEAR(fecha_creacion) = $year");
    $n    = (int)$stmt->fetchColumn() + 1;
    return 'VTA-' . $year . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ── Procesamiento de acciones POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ── Crear cotización ──────────────────────────────────────────────────
    if ($accion === 'crear_cotizacion') {
        $cliente   = sanitize($_POST['cliente_nombre'] ?? '');
        $rfc       = sanitize($_POST['cliente_rfc']    ?? '');
        $email     = sanitize($_POST['cliente_email']  ?? '');
        $moneda    = in_array($_POST['moneda'] ?? 'MXN', ['MXN','USD']) ? $_POST['moneda'] : 'MXN';
        $validez   = max(1, intval($_POST['validez_dias'] ?? 30));
        $desc      = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
        $proy_id   = !empty($_POST['proyecto_id']) ? intval($_POST['proyecto_id']) : null;

        if (!$cliente) {
            $error = showMessage('El nombre del cliente es requerido.', 'error');
        } else {
            try {
                $folio = generarFolioVenta();
                $db->prepare("INSERT INTO ventas_cotizaciones
                    (folio, cliente_nombre, cliente_rfc, cliente_email, moneda, validez_dias,
                     descripcion, proyecto_id, fecha_emision, fecha_vencimiento, estado, creado_por)
                    VALUES (?,?,?,?,?,?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? DAY),'borrador',?)")
                   ->execute([$folio, $cliente, $rfc ?: null, $email ?: null, $moneda, $validez,
                              $desc ?: null, $proy_id, $validez, $_SESSION['user_id']]);
                $new_id = $db->lastInsertId();
                header("Location: ventas.php?seccion=detalle&id=$new_id&msg=" . urlencode("Cotización $folio creada."));
                exit();
            } catch (Exception $e) {
                $error = showMessage('Error: ' . $e->getMessage(), 'error');
            }
        }
    }

    // ── Agregar item ──────────────────────────────────────────────────────
    elseif ($accion === 'agregar_item') {
        $cot_id    = intval($_POST['cotizacion_id']);
        $desc      = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
        $qty       = max(0.01, floatval($_POST['cantidad'] ?? 1));
        $unidad    = sanitize($_POST['unidad'] ?? 'pza');
        $precio    = max(0, floatval($_POST['precio_unitario'] ?? 0));
        $dto       = max(0, min(100, floatval($_POST['descuento_pct'] ?? 0)));
        $subtotal  = round($qty * $precio * (1 - $dto / 100), 2);

        if (!$desc) {
            $error = showMessage('La descripción es requerida.', 'error');
        } else {
            try {
                $stmt_item = $db->prepare("SELECT COALESCE(MAX(item_numero),0)+1 FROM ventas_cotizaciones_detalle WHERE cotizacion_id = ?");
                $stmt_item->execute([$cot_id]);
                $item_n = (int)($stmt_item->fetchColumn() ?: 1);
                $db->prepare("INSERT INTO ventas_cotizaciones_detalle
                    (cotizacion_id, item_numero, descripcion, cantidad, unidad, precio_unitario, descuento_pct, subtotal)
                    VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$cot_id, $item_n, $desc, $qty, $unidad, $precio, $dto, $subtotal]);
                recalcularTotales($db, $cot_id);
                $message = showMessage('Item agregado.', 'success');
            } catch (Exception $e) {
                $error = showMessage('Error: ' . $e->getMessage(), 'error');
            }
        }
    }

    // ── Eliminar item ─────────────────────────────────────────────────────
    elseif ($accion === 'eliminar_item') {
        $item_id   = intval($_POST['item_id']);
        $cot_id    = intval($_POST['cotizacion_id']);
        $db->prepare("DELETE FROM ventas_cotizaciones_detalle WHERE id = ? AND cotizacion_id = ?")
           ->execute([$item_id, $cot_id]);
        recalcularTotales($db, $cot_id);
        $message = showMessage('Item eliminado.', 'success');
    }

    // ── Cambiar estado ────────────────────────────────────────────────────
    elseif ($accion === 'cambiar_estado') {
        $cot_id   = intval($_POST['cotizacion_id']);
        $nuevo    = sanitize($_POST['nuevo_estado'] ?? '');
        $estados  = ['borrador','enviada','aceptada','rechazada','vencida'];
        if (in_array($nuevo, $estados)) {
            $db->prepare("UPDATE ventas_cotizaciones SET estado = ? WHERE id = ?")
               ->execute([$nuevo, $cot_id]);
            $message = showMessage("Estado actualizado a «$nuevo».", 'success');
        }
    }

    // ── Editar cabecera ───────────────────────────────────────────────────
    elseif ($accion === 'editar_cabecera') {
        $cot_id  = intval($_POST['cotizacion_id']);
        $cliente = sanitize($_POST['cliente_nombre'] ?? '');
        $rfc     = sanitize($_POST['cliente_rfc']    ?? '');
        $email   = sanitize($_POST['cliente_email']  ?? '');
        $desc    = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
        $validez = max(1, intval($_POST['validez_dias'] ?? 30));
        $moneda  = in_array($_POST['moneda'] ?? 'MXN', ['MXN','USD']) ? $_POST['moneda'] : 'MXN';
        if (!$cliente) { $error = showMessage('El nombre del cliente es requerido.', 'error'); }
        else {
            $db->prepare("UPDATE ventas_cotizaciones
                SET cliente_nombre=?, cliente_rfc=?, cliente_email=?, descripcion=?,
                    validez_dias=?, moneda=?,
                    fecha_vencimiento=DATE_ADD(fecha_emision, INTERVAL ? DAY)
                WHERE id=?")
               ->execute([$cliente, $rfc ?: null, $email ?: null, $desc ?: null,
                          $validez, $moneda, $validez, $cot_id]);
            $message = showMessage('Cotización actualizada.', 'success');
        }
    }

    // ── Eliminar cotización ───────────────────────────────────────────────
    elseif ($accion === 'eliminar_cotizacion') {
        $cot_id = intval($_POST['cotizacion_id']);
        $db->prepare("DELETE FROM ventas_cotizaciones_detalle WHERE cotizacion_id = ?")->execute([$cot_id]);
        $db->prepare("DELETE FROM ventas_cotizaciones WHERE id = ? AND estado = 'borrador'")->execute([$cot_id]);
        header('Location: ventas.php?seccion=lista&msg=' . urlencode('Cotización eliminada.'));
        exit();
    }

    if (empty($error)) {
        $redir_id = intval($_POST['cotizacion_id'] ?? 0);
        if ($seccion === 'detalle' || $redir_id) {
            header("Location: ventas.php?seccion=detalle&id=$redir_id&msg=" . urlencode(strip_tags($message)));
        } else {
            header("Location: ventas.php?seccion=lista&msg=" . urlencode(strip_tags($message)));
        }
        exit();
    }
}

// ── Recalcular totales ──────────────────────────────────────────────────────
function recalcularTotales($db, $cot_id) {
    $stmt = $db->prepare("SELECT SUM(subtotal) FROM ventas_cotizaciones_detalle WHERE cotizacion_id = ?");
    $stmt->execute([$cot_id]);
    $subtotal = round((float)$stmt->fetchColumn(), 2);
    $iva      = round($subtotal * 0.16, 2);
    $total    = round($subtotal + $iva, 2);
    $db->prepare("UPDATE ventas_cotizaciones SET subtotal=?, iva=?, total=? WHERE id=?")
       ->execute([$subtotal, $iva, $total, $cot_id]);
}

// ── Datos para listado ──────────────────────────────────────────────────────
$cotizaciones = [];
if ($seccion === 'lista' || $seccion === '') {
    $cotizaciones = $db->query(
        "SELECT vc.*, u.nombre_completo AS creador_nombre
         FROM ventas_cotizaciones vc
         LEFT JOIN usuarios u ON u.id = vc.creado_por
         ORDER BY vc.fecha_creacion DESC"
    )->fetchAll();
}

// ── Datos para detalle ──────────────────────────────────────────────────────
$cot_detalle = null;
$cot_items   = [];
$detalle_id  = intval($_GET['id'] ?? 0);
if ($seccion === 'detalle' && $detalle_id) {
    $stmt = $db->prepare("SELECT vc.*, u.nombre_completo AS creador_nombre
                          FROM ventas_cotizaciones vc
                          LEFT JOIN usuarios u ON u.id = vc.creado_por
                          WHERE vc.id = ?");
    $stmt->execute([$detalle_id]);
    $cot_detalle = $stmt->fetch();

    $stmt2 = $db->prepare("SELECT * FROM ventas_cotizaciones_detalle WHERE cotizacion_id = ? ORDER BY item_numero");
    $stmt2->execute([$detalle_id]);
    $cot_items = $stmt2->fetchAll();
}

// Proyectos disponibles para vincular
$proyectos_lista = $db->query("SELECT id, nombre FROM proyectos WHERE tipo = 'ingenieria' ORDER BY nombre")->fetchAll();

if (!empty($_GET['msg'])) $message = showMessage(htmlspecialchars($_GET['msg']), 'success');

// ── Estadísticas rápidas ────────────────────────────────────────────────────
$stats_vta = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado='borrador'  THEN 1 ELSE 0 END) AS borradores,
        SUM(CASE WHEN estado='enviada'   THEN 1 ELSE 0 END) AS enviadas,
        SUM(CASE WHEN estado='aceptada'  THEN 1 ELSE 0 END) AS aceptadas,
        SUM(CASE WHEN estado='aceptada'  THEN total ELSE 0 END) AS monto_ganado
    FROM ventas_cotizaciones
")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<link rel="icon" href="favicon.ico?v=3">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ventas — <?= SITE_NAME ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── RESET & BASE ── */
* { margin:0; padding:0; box-sizing:border-box; text-decoration:none !important; }
input,textarea,select { -webkit-user-select:text; user-select:text; }
body { font-family:'Montserrat',Arial,sans-serif; background:#fff; height:100vh; overflow:hidden; }

/* ── HEADER ── */
.top-header {
    position:fixed; top:0; left:0; width:100%; height:80px;
    background:linear-gradient(135deg,#003d5c,#005686);
    box-shadow:0 2px 10px rgba(0,86,134,.2);
    z-index:1000; display:flex; align-items:center;
    justify-content:space-between; padding:0 30px;
}
.header-logo { height:60px; width:auto; object-fit:contain; }
.header-right { display:flex; align-items:center; gap:20px; }
.back-button-header {
    background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3);
    border-radius:40px; padding:8px 20px; font-weight:600; color:#fff;
    display:flex; align-items:center; gap:8px; transition:all .2s; font-size:14px; cursor:pointer;
}
.back-button-header:hover { background:rgba(255,255,255,.25); transform:translateX(-5px); }
.user-dropdown { position:relative; display:inline-block; }
.user-trigger {
    display:flex; align-items:center; gap:12px;
    background:rgba(255,255,255,.15); padding:5px 15px 5px 5px;
    border-radius:40px; cursor:pointer; border:1px solid rgba(255,255,255,.3); transition:all .2s;
}
.user-trigger:hover { background:rgba(255,255,255,.25); }
.user-avatar { width:35px; height:35px; border-radius:50%; background:#FF9800; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px; color:#fff; }
.user-name { font-weight:600; color:#fff; font-size:13px; line-height:1.2; }
.user-role { font-size:10px; color:rgba(255,255,255,.8); text-transform:uppercase; }
.user-dropdown-menu {
    position:absolute; top:calc(100% + 5px); right:0; width:200px;
    background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.15);
    opacity:0; visibility:hidden; transform:translateY(-5px);
    transition:all .2s; border:1px solid #e0e0e0; overflow:hidden; z-index:1000;
}
.user-dropdown:hover .user-dropdown-menu { opacity:1; visibility:visible; transform:translateY(0); }
.dropdown-item { padding:12px 20px; display:flex; align-items:center; gap:10px; color:#333; transition:all .2s; font-size:13px; font-family:'Montserrat',sans-serif; }
.dropdown-item:hover { background:#f5f5f5; color:#f44336; }
.dropdown-item i { width:18px; color:#666; }

/* ── LAYOUT ── */
.app-container { height:100vh; width:100vw; padding-top:80px; }
.section-content { height:calc(100vh - 140px); padding:0 30px 30px; overflow-y:auto; }
.sticky-container { position:sticky; top:0; background:#fff; z-index:90; padding:20px 0; margin-bottom:20px; border-bottom:2px solid #e0e0e0; }
.header-row { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.header-row h2 { font-size:1.4rem; color:#003d5c; display:flex; align-items:center; gap:10px; }

/* DASHBOARD */
.main-center { height:calc(100vh - 140px); overflow-y:auto; display:flex; flex-direction:column; justify-content:flex-start; align-items:center; padding:20px 20px 100px 20px; }
.page-title { font-size:2rem; color:#003d5c; font-weight:300; margin-bottom:10px; text-align:center; }
.page-title strong { font-weight:800; background:linear-gradient(135deg,#003d5c,#0077b3); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
.page-subtitle { color:#666; font-size:.95rem; text-align:center; margin-bottom:40px; }
.cards-grid { display:flex; gap:28px; justify-content:center; flex-wrap:wrap; max-width:900px; }
.nav-card { width:160px; cursor:pointer; transition:all .3s; text-align:center; }
.nav-card:hover { transform:translateY(-8px); }
.nav-card:hover .card-icon { border-color:#FF9800; box-shadow:0 15px 30px rgba(255,152,0,.25); }
.nav-card:hover .card-title { color:#FF9800; }
.card-icon { width:100%; aspect-ratio:1/1; background:linear-gradient(135deg,#f8f9fa,#fff); border-radius:20px; display:flex; align-items:center; justify-content:center; margin-bottom:14px; border:3px solid #e0e0e0; transition:all .3s; box-shadow:0 5px 15px rgba(0,0,0,.08); }
.card-icon i { font-size:3.2rem; color:#005686; transition:all .3s; }
.nav-card:hover .card-icon i { color:#FF9800; transform:scale(1.1); }
.card-title { font-size:1rem; font-weight:700; color:#333; margin-bottom:4px; }
.card-desc { font-size:.75rem; color:#888; }

/* STATS */
.stats-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:20px; }
.stat-box { background:#fff; border:1.5px solid #e0e8f0; border-radius:12px; padding:14px 20px; display:flex; align-items:center; gap:14px; min-width:160px; flex:1; }
.stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.si-blue   { background:#e3f2fd; color:#1565c0; }
.si-orange { background:#fff3e0; color:#e65100; }
.si-green  { background:#e8f5e9; color:#2e7d32; }
.si-purple { background:#f3e5f5; color:#7b1fa2; }
.stat-val  { font-size:1.5rem; font-weight:800; color:#003d5c; line-height:1; }
.stat-lbl  { font-size:.7rem; color:#888; font-weight:600; margin-top:2px; }

/* TABLA */
.data-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.data-table th { background:#f0f4f8; color:#003d5c; font-weight:700; padding:9px 12px; text-align:left; border-bottom:2px solid #dde3ea; }
.data-table td { padding:8px 12px; border-bottom:1px solid #f0f0f0; color:#333; vertical-align:middle; }
.data-table tr:hover td { background:#f9fafb; }
.data-table .actions { display:flex; gap:5px; }

/* BADGES */
.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:700; }
.badge-borrador  { background:#f5f5f5; color:#616161; }
.badge-enviada   { background:#e3f2fd; color:#1565c0; }
.badge-aceptada  { background:#e8f5e9; color:#2e7d32; }
.badge-rechazada { background:#fce4ec; color:#b71c1c; }
.badge-vencida   { background:#fff3e0; color:#e65100; }

/* ALERTAS */
.alert { padding:11px 16px; border-radius:8px; margin-bottom:16px; font-size:.84rem; }
.alert-success { background:#e8f5e9; color:#2E7D32; border-left:4px solid #2E7D32; }
.alert-error   { background:#fce4ec; color:#b71c1c; border-left:4px solid #b71c1c; }

/* BOTONES */
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; border:none; cursor:pointer; font-family:'Montserrat',sans-serif; font-size:.82rem; font-weight:600; transition:all .2s; }
.btn-primary { background:#005686; color:#fff; } .btn-primary:hover { background:#003d5c; }
.btn-success  { background:#2e7d32; color:#fff; } .btn-success:hover  { background:#1b5e20; }
.btn-danger   { background:#d32f2f; color:#fff; } .btn-danger:hover   { background:#b71c1c; }
.btn-warning  { background:#FF9800; color:#fff; } .btn-warning:hover  { background:#e65100; }
.btn-outline  { background:transparent; color:#005686; border:1.5px solid #005686; } .btn-outline:hover { background:#005686; color:#fff; }
.btn-purple   { background:#7b1fa2; color:#fff; } .btn-purple:hover   { background:#4a148c; }
.btn-sm { padding:4px 9px; font-size:.74rem; }

/* DETALLE */
.detail-header { background:linear-gradient(135deg,#f8faff,#eef3fa); border:1.5px solid #d0dcea; border-radius:12px; padding:18px 24px; margin-bottom:20px; }
.detail-meta   { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; margin-top:14px; }
.meta-item     { display:flex; flex-direction:column; gap:2px; }
.meta-label    { font-size:.7rem; font-weight:700; color:#888; text-transform:uppercase; }
.meta-value    { font-size:.9rem; font-weight:600; color:#003d5c; }

.totales-box { background:#f8faff; border:1.5px solid #d0dcea; border-radius:10px; padding:16px 20px; max-width:320px; margin-left:auto; }
.total-row   { display:flex; justify-content:space-between; font-size:.85rem; margin-bottom:6px; }
.total-row.grand { font-size:1rem; font-weight:800; color:#003d5c; border-top:2px solid #d0dcea; padding-top:8px; margin-top:4px; }

/* FORMULARIO */
.form-group  { margin-bottom:12px; }
.form-label  { display:block; font-size:.77rem; font-weight:700; color:#555; margin-bottom:4px; }
.form-input  { width:100%; padding:8px 10px; border:1.5px solid #ddd; border-radius:7px; font-family:'Montserrat',sans-serif; font-size:.82rem; }
.form-input:focus { outline:none; border-color:#005686; }
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.form-grid-3 { display:grid; grid-template-columns:2fr 1fr 1fr; gap:8px; }
.form-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }

/* MODAL */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:26px; width:90%; max-width:560px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.modal-box.lg { max-width:700px; }
.modal-title { font-size:1.05rem; font-weight:700; color:#003d5c; margin-bottom:18px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #e8ecf0; padding-bottom:12px; }
.modal-footer { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; border-top:1px solid #e8ecf0; padding-top:14px; }

/* FOOTER */
.dashboard-footer { position:fixed; bottom:0; left:0; right:0; display:flex; justify-content:space-between; align-items:center; padding:15px 40px; border-top:2px solid #e0e0e0; background:#fff; height:60px; z-index:1000; }
.footer-left { display:flex; gap:30px; color:#555; font-size:13px; font-weight:500; }
.footer-left i { color:#003d5c; width:16px; margin-right:5px; }
.footer-right img { height:28px; }

/* EMPTY */
.empty-state { text-align:center; padding:50px 20px; color:#aaa; }
.empty-state i { font-size:3rem; display:block; margin-bottom:12px; color:#ccc; }

/* ESTADO BUTTONS */
.estado-selector { display:flex; flex-wrap:wrap; gap:6px; }
.estado-opt { padding:5px 12px; border-radius:20px; border:1.5px solid; cursor:pointer; font-size:.75rem; font-weight:700; font-family:'Montserrat',sans-serif; background:transparent; transition:all .2s; }
.estado-opt:hover, .estado-opt.active { color:#fff !important; }
.opt-borrador  { color:#616161; border-color:#9e9e9e; } .opt-borrador.active,  .opt-borrador:hover  { background:#616161; }
.opt-enviada   { color:#1565c0; border-color:#1565c0; } .opt-enviada.active,   .opt-enviada:hover   { background:#1565c0; }
.opt-aceptada  { color:#2e7d32; border-color:#2e7d32; } .opt-aceptada.active,  .opt-aceptada:hover  { background:#2e7d32; }
.opt-rechazada { color:#b71c1c; border-color:#b71c1c; } .opt-rechazada.active, .opt-rechazada:hover { background:#b71c1c; }
.opt-vencida   { color:#e65100; border-color:#e65100; } .opt-vencida.active,   .opt-vencida:hover   { background:#e65100; }
</style>
<link rel="stylesheet" href="css/responsive.css">
</head>
<body>

<!-- ── TOP HEADER ── -->
<div class="top-header">
    <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
    <div class="header-right">
        <?php if ($seccion !== '' && $seccion !== 'lista'): ?>
        <a href="ventas.php?seccion=lista" class="back-button-header"><i class="fas fa-arrow-left"></i> Volver</a>
        <?php elseif ($seccion === 'lista'): ?>
        <a href="ventas.php" class="back-button-header"><i class="fas fa-arrow-left"></i> Menú</a>
        <?php endif; ?>
        <div class="user-dropdown">
            <div class="user-trigger">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                    <div class="user-role"><?= htmlspecialchars($_SESSION['user_type']) ?></div>
                </div>
            </div>
            <div class="user-dropdown-menu">
                <?php if ($mostrar_boton_menu): ?>
                <a href="Menu.php" class="dropdown-item" style="color:#4CAF50;"><i class="fas fa-arrow-left"></i> Menú de Perfiles</a>
                <div style="height:1px;background:#e0e0e0;margin:4px 0;"></div>
                <?php endif; ?>
                <a href="?logout=1" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
            </div>
        </div>
    </div>
</div>

<div class="app-container">

<!-- ══════════════════════════════════════════════════
     DASHBOARD PRINCIPAL
══════════════════════════════════════════════════ -->
<?php if ($seccion === ''): ?>
<div class="main-center">
    <h1 class="page-title"><strong>Ventas</strong><br>Panel de Control</h1>
    <p class="page-subtitle">Gestión de cotizaciones a clientes</p>
    <div class="cards-grid">
        <div class="nav-card" onclick="location.href='ventas.php?seccion=lista'">
            <div class="card-icon"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="card-title">Cotizaciones</div>
            <div class="card-desc">Ver y gestionar</div>
        </div>
        <div class="nav-card" onclick="abrirModalNuevaCot()">
            <div class="card-icon"><i class="fas fa-plus-circle"></i></div>
            <div class="card-title">Nueva Cotización</div>
            <div class="card-desc">Crear registro</div>
        </div>
        <div class="nav-card" onclick="location.href='ventas.php?seccion=orden_produccion'">
            <div class="card-icon"><i class="fas fa-industry"></i></div>
            <div class="card-title">Orden de Producción</div>
            <div class="card-desc">Órdenes activas</div>
        </div>
    </div>

    <!-- Estadísticas rápidas -->
    <div class="stats-row" style="max-width:700px;width:100%;margin-top:30px;">
        <div class="stat-box">
            <div class="stat-icon si-blue"><i class="fas fa-file-alt"></i></div>
            <div><div class="stat-val"><?= $stats_vta['total'] ?></div><div class="stat-lbl">Total cotizaciones</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-orange"><i class="fas fa-paper-plane"></i></div>
            <div><div class="stat-val"><?= $stats_vta['enviadas'] ?></div><div class="stat-lbl">Enviadas</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
            <div><div class="stat-val"><?= $stats_vta['aceptadas'] ?></div><div class="stat-lbl">Aceptadas</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-purple"><i class="fas fa-dollar-sign"></i></div>
            <div><div class="stat-val">$<?= number_format($stats_vta['monto_ganado'] ?? 0, 0) ?></div><div class="stat-lbl">Monto ganado</div></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     LISTADO DE COTIZACIONES
══════════════════════════════════════════════════ -->
<?php elseif ($seccion === 'orden_produccion'): ?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-industry"></i> Órdenes de Producción Activas</h2>
            <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>
    <?php require_once 'includes/lista_ordenes_produccion.php'; ?>
</div>

<?php elseif ($seccion === 'lista'): ?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-file-invoice-dollar"></i> Cotizaciones de Venta</h2>
            <button class="btn btn-primary" onclick="abrirModalNuevaCot()"><i class="fas fa-plus"></i> Nueva Cotización</button>
        </div>
    </div>

    <?php if ($message): ?><?= $message ?><?php endif; ?>
    <?php if ($error):   ?><?= $error   ?><?php endif; ?>

    <!-- Stats resumen -->
    <div class="stats-row" style="margin-bottom:20px;">
        <div class="stat-box">
            <div class="stat-icon si-blue"><i class="fas fa-file-alt"></i></div>
            <div><div class="stat-val"><?= $stats_vta['total'] ?></div><div class="stat-lbl">Total</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-orange"><i class="fas fa-pencil-alt"></i></div>
            <div><div class="stat-val"><?= $stats_vta['borradores'] ?></div><div class="stat-lbl">Borradores</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-orange"><i class="fas fa-paper-plane"></i></div>
            <div><div class="stat-val"><?= $stats_vta['enviadas'] ?></div><div class="stat-lbl">Enviadas</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-green"><i class="fas fa-handshake"></i></div>
            <div><div class="stat-val"><?= $stats_vta['aceptadas'] ?></div><div class="stat-lbl">Aceptadas</div></div>
        </div>
        <div class="stat-box">
            <div class="stat-icon si-purple"><i class="fas fa-coins"></i></div>
            <div><div class="stat-val">$<?= number_format($stats_vta['monto_ganado'] ?? 0, 0) ?></div><div class="stat-lbl">Monto ganado MXN</div></div>
        </div>
    </div>

    <?php if (empty($cotizaciones)): ?>
    <div class="empty-state">
        <i class="fas fa-file-invoice-dollar"></i>
        <p>No hay cotizaciones registradas.</p>
        <button class="btn btn-primary" style="margin-top:12px;" onclick="abrirModalNuevaCot()"><i class="fas fa-plus"></i> Crear primera cotización</button>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Folio</th>
                <th>Cliente</th>
                <th>Descripción</th>
                <th>Moneda</th>
                <th style="text-align:right;">Total</th>
                <th style="text-align:center;">Estado</th>
                <th>Emisión</th>
                <th>Vence</th>
                <th style="text-align:center;">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cotizaciones as $c):
            $vencida = !empty($c['fecha_vencimiento']) && strtotime($c['fecha_vencimiento']) < time() && $c['estado'] === 'enviada';
        ?>
        <tr>
            <td><strong style="color:#003d5c;"><?= htmlspecialchars($c['folio']) ?></strong></td>
            <td>
                <?= htmlspecialchars($c['cliente_nombre']) ?>
                <?php if ($c['cliente_rfc']): ?><br><small style="color:#aaa;"><?= htmlspecialchars($c['cliente_rfc']) ?></small><?php endif; ?>
            </td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($c['descripcion'] ?? '—') ?></td>
            <td><?= $c['moneda'] ?></td>
            <td style="text-align:right;font-weight:700;">$<?= number_format($c['total'],2) ?></td>
            <td style="text-align:center;">
                <span class="badge badge-<?= $vencida ? 'vencida' : $c['estado'] ?>">
                    <?= $vencida ? 'Vencida' : ucfirst($c['estado']) ?>
                </span>
            </td>
            <td><?= $c['fecha_emision'] ? date('d/m/Y', strtotime($c['fecha_emision'])) : '—' ?></td>
            <td style="<?= $vencida ? 'color:#e65100;font-weight:700;' : '' ?>">
                <?= $c['fecha_vencimiento'] ? date('d/m/Y', strtotime($c['fecha_vencimiento'])) : '—' ?>
            </td>
            <td>
                <div class="actions">
                    <a href="ventas.php?seccion=detalle&id=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i></a>
                    <?php if ($c['estado'] === 'borrador'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar? Solo se pueden eliminar borradores.')">
                        <input type="hidden" name="accion" value="eliminar_cotizacion">
                        <input type="hidden" name="cotizacion_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════
     DETALLE DE COTIZACIÓN
══════════════════════════════════════════════════ -->
<?php elseif ($seccion === 'detalle' && $cot_detalle): ?>
<div class="section-content">
    <?php if ($message): ?><?= $message ?><?php endif; ?>
    <?php if ($error):   ?><?= $error   ?><?php endif; ?>

    <!-- Cabecera del documento -->
    <div class="detail-header">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <div>
                <div style="font-size:1.3rem;font-weight:800;color:#003d5c;"><?= htmlspecialchars($cot_detalle['folio']) ?></div>
                <div style="font-size:.82rem;color:#666;margin-top:2px;">Creada por <?= htmlspecialchars($cot_detalle['creador_nombre'] ?? '—') ?></div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <span class="badge badge-<?= $cot_detalle['estado'] ?>" style="font-size:.78rem;padding:5px 14px;">
                    <?= ucfirst($cot_detalle['estado']) ?>
                </span>
                <button class="btn btn-outline btn-sm" onclick="abrirModalEditarCab()"><i class="fas fa-edit"></i> Editar</button>
            </div>
        </div>
        <div class="detail-meta">
            <div class="meta-item"><span class="meta-label">Cliente</span><span class="meta-value"><?= htmlspecialchars($cot_detalle['cliente_nombre']) ?></span></div>
            <?php if ($cot_detalle['cliente_rfc']): ?>
            <div class="meta-item"><span class="meta-label">RFC</span><span class="meta-value"><?= htmlspecialchars($cot_detalle['cliente_rfc']) ?></span></div>
            <?php endif; ?>
            <?php if ($cot_detalle['cliente_email']): ?>
            <div class="meta-item"><span class="meta-label">Email</span><span class="meta-value"><?= htmlspecialchars($cot_detalle['cliente_email']) ?></span></div>
            <?php endif; ?>
            <div class="meta-item"><span class="meta-label">Moneda</span><span class="meta-value"><?= $cot_detalle['moneda'] ?></span></div>
            <div class="meta-item"><span class="meta-label">Emisión</span><span class="meta-value"><?= $cot_detalle['fecha_emision'] ? date('d/m/Y', strtotime($cot_detalle['fecha_emision'])) : '—' ?></span></div>
            <div class="meta-item"><span class="meta-label">Vence</span><span class="meta-value"><?= $cot_detalle['fecha_vencimiento'] ? date('d/m/Y', strtotime($cot_detalle['fecha_vencimiento'])) : '—' ?></span></div>
            <div class="meta-item"><span class="meta-label">Validez</span><span class="meta-value"><?= $cot_detalle['validez_dias'] ?> días</span></div>
        </div>
        <?php if ($cot_detalle['descripcion']): ?>
        <div style="margin-top:10px;font-size:.82rem;color:#555;border-top:1px dashed #d0dcea;padding-top:8px;">
            <strong>Alcance:</strong> <?= htmlspecialchars($cot_detalle['descripcion']) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cambiar estado -->
    <div style="background:#f8faff;border:1px solid #e0e8f0;border-radius:10px;padding:14px 18px;margin-bottom:18px;">
        <div style="font-size:.78rem;font-weight:700;color:#555;margin-bottom:8px;">Cambiar estado:</div>
        <div class="estado-selector">
            <?php foreach (['borrador','enviada','aceptada','rechazada','vencida'] as $est): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="accion" value="cambiar_estado">
                <input type="hidden" name="cotizacion_id" value="<?= $cot_detalle['id'] ?>">
                <input type="hidden" name="nuevo_estado" value="<?= $est ?>">
                <button type="submit" class="estado-opt opt-<?= $est ?> <?= $cot_detalle['estado'] === $est ? 'active' : '' ?>"
                        <?= $cot_detalle['estado'] === $est ? 'disabled' : '' ?>>
                    <?= ucfirst($est) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tabla de items -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <div style="font-size:.9rem;font-weight:700;color:#003d5c;"><i class="fas fa-list-ol"></i> Items de la Cotización</div>
        <button class="btn btn-success btn-sm" onclick="abrirModalAgregarItem()"><i class="fas fa-plus"></i> Agregar Item</button>
    </div>

    <?php if (empty($cot_items)): ?>
    <div class="empty-state" style="padding:30px;">
        <i class="fas fa-boxes"></i>
        <p>Sin items. Agrega los productos o servicios a cotizar.</p>
    </div>
    <?php else: ?>
    <table class="data-table" style="margin-bottom:16px;">
        <thead>
            <tr>
                <th>#</th>
                <th>Descripción</th>
                <th>Und.</th>
                <th style="text-align:center;">Cant.</th>
                <th style="text-align:right;">P. Unitario</th>
                <th style="text-align:center;">Dto%</th>
                <th style="text-align:right;">Subtotal</th>
                <th style="text-align:center;">Acción</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cot_items as $it): ?>
        <tr>
            <td><?= $it['item_numero'] ?></td>
            <td><?= htmlspecialchars($it['descripcion']) ?></td>
            <td><?= htmlspecialchars($it['unidad']) ?></td>
            <td style="text-align:center;"><?= number_format($it['cantidad'], 2) ?></td>
            <td style="text-align:right;">$<?= number_format($it['precio_unitario'], 2) ?></td>
            <td style="text-align:center;"><?= $it['descuento_pct'] > 0 ? $it['descuento_pct'] . '%' : '—' ?></td>
            <td style="text-align:right;font-weight:700;">$<?= number_format($it['subtotal'], 2) ?></td>
            <td style="text-align:center;">
                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar item?')">
                    <input type="hidden" name="accion" value="eliminar_item">
                    <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                    <input type="hidden" name="cotizacion_id" value="<?= $cot_detalle['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Totales -->
    <div class="totales-box">
        <div class="total-row"><span>Subtotal</span><span>$<?= number_format($cot_detalle['subtotal'],2) ?> <?= $cot_detalle['moneda'] ?></span></div>
        <div class="total-row"><span>IVA (16%)</span><span>$<?= number_format($cot_detalle['iva'],2) ?></span></div>
        <div class="total-row grand"><span>TOTAL</span><span>$<?= number_format($cot_detalle['total'],2) ?> <?= $cot_detalle['moneda'] ?></span></div>
    </div>
</div>
<?php endif; ?>

</div><!-- /.app-container -->

<!-- ══════════════════════════════════════ MODALES ══════════════════════════════════════ -->

<!-- Modal: Nueva cotización -->
<div class="modal-overlay" id="modalNuevaCot">
<div class="modal-box">
    <div class="modal-title"><i class="fas fa-file-plus"></i> Nueva Cotización de Venta</div>
    <form method="POST" action="ventas.php?seccion=lista">
        <input type="hidden" name="accion" value="crear_cotizacion">
        <div class="form-grid-2">
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Cliente *</label>
                <input type="text" name="cliente_nombre" class="form-input" placeholder="Nombre o razón social" required>
            </div>
            <div class="form-group">
                <label class="form-label">RFC</label>
                <input type="text" name="cliente_rfc" class="form-input" placeholder="XAXX010101000">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="cliente_email" class="form-input" placeholder="cliente@empresa.com">
            </div>
            <div class="form-group">
                <label class="form-label">Moneda</label>
                <select name="moneda" class="form-input">
                    <option value="MXN">MXN — Peso mexicano</option>
                    <option value="USD">USD — Dólar americano</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Validez (días)</label>
                <input type="number" name="validez_dias" class="form-input" value="30" min="1">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Descripción / Alcance</label>
                <textarea name="descripcion" class="form-input" rows="2" placeholder="Breve descripción del proyecto o servicio…"></textarea>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Vincular a proyecto interno (opcional)</label>
                <select name="proyecto_id" class="form-input">
                    <option value="">— Sin proyecto vinculado —</option>
                    <?php foreach ($proyectos_lista as $pl): ?>
                    <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('modalNuevaCot')">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Crear Cotización</button>
        </div>
    </form>
</div>
</div>

<!-- Modal: Agregar item (solo en detalle) -->
<?php if ($seccion === 'detalle' && $cot_detalle): ?>
<div class="modal-overlay" id="modalAgregarItem">
<div class="modal-box lg">
    <div class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Item a <?= htmlspecialchars($cot_detalle['folio']) ?></div>
    <form method="POST" action="ventas.php?seccion=detalle&id=<?= $cot_detalle['id'] ?>" id="formAgregarItem">
        <input type="hidden" name="accion" value="agregar_item">
        <input type="hidden" name="cotizacion_id" value="<?= $cot_detalle['id'] ?>">
        <div class="form-group">
            <label class="form-label">Descripción *</label>
            <input type="text" name="descripcion" class="form-input" placeholder="Nombre del producto o servicio" required>
        </div>
        <div class="form-grid-4" style="margin-bottom:10px;">
            <div class="form-group">
                <label class="form-label">Unidad</label>
                <select name="unidad" class="form-input">
                    <option value="pza">pza</option>
                    <option value="srv">srv</option>
                    <option value="lote">lote</option>
                    <option value="hr">hr</option>
                    <option value="m">m</option>
                    <option value="kg">kg</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Cantidad</label>
                <input type="number" name="cantidad" id="ai_qty" class="form-input" value="1" min="0.01" step="0.01" oninput="calcItemTotal()">
            </div>
            <div class="form-group">
                <label class="form-label">Precio unit. (<?= $cot_detalle['moneda'] ?>)</label>
                <input type="number" name="precio_unitario" id="ai_precio" class="form-input" value="0" min="0" step="0.01" oninput="calcItemTotal()">
            </div>
            <div class="form-group">
                <label class="form-label">Descuento %</label>
                <input type="number" name="descuento_pct" id="ai_dto" class="form-input" value="0" min="0" max="100" step="1" oninput="calcItemTotal()">
            </div>
        </div>
        <!-- Preview de totales -->
        <div style="background:#f8faff;border:1px solid #e0e8f0;border-radius:8px;padding:12px 16px;font-size:.82rem;display:flex;gap:20px;flex-wrap:wrap;margin-bottom:10px;">
            <span>Subtotal: <strong id="prev_subtotal">$0.00</strong></span>
            <span style="color:#888;">IVA 16%: <strong id="prev_iva">$0.00</strong></span>
            <span>Total con IVA: <strong id="prev_total" style="color:#003d5c;">$0.00</strong></span>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('modalAgregarItem')">Cancelar</button>
            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Agregar</button>
        </div>
    </form>
</div>
</div>

<!-- Modal: Editar cabecera -->
<div class="modal-overlay" id="modalEditarCab">
<div class="modal-box lg">
    <div class="modal-title"><i class="fas fa-edit"></i> Editar Cotización</div>
    <form method="POST" action="ventas.php?seccion=detalle&id=<?= $cot_detalle['id'] ?>">
        <input type="hidden" name="accion" value="editar_cabecera">
        <input type="hidden" name="cotizacion_id" value="<?= $cot_detalle['id'] ?>">
        <div class="form-grid-2">
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Cliente *</label>
                <input type="text" name="cliente_nombre" class="form-input" value="<?= htmlspecialchars($cot_detalle['cliente_nombre']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">RFC</label>
                <input type="text" name="cliente_rfc" class="form-input" value="<?= htmlspecialchars($cot_detalle['cliente_rfc'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="cliente_email" class="form-input" value="<?= htmlspecialchars($cot_detalle['cliente_email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Moneda</label>
                <select name="moneda" class="form-input">
                    <option value="MXN" <?= $cot_detalle['moneda']==='MXN'?'selected':'' ?>>MXN</option>
                    <option value="USD" <?= $cot_detalle['moneda']==='USD'?'selected':'' ?>>USD</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Validez (días)</label>
                <input type="number" name="validez_dias" class="form-input" value="<?= $cot_detalle['validez_dias'] ?>" min="1">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Descripción / Alcance</label>
                <textarea name="descripcion" class="form-input" rows="2"><?= htmlspecialchars($cot_detalle['descripcion'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('modalEditarCab')">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>

<!-- ── FOOTER ── -->
<div class="dashboard-footer">
    <div class="footer-left">
        <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
        <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
    </div>
    <div class="footer-right"><img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO" onerror="this.style.display='none'"></div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
function abrirModalNuevaCot()    { openModal('modalNuevaCot'); }
function abrirModalAgregarItem() { openModal('modalAgregarItem'); calcItemTotal(); }
function abrirModalEditarCab()   { openModal('modalEditarCab'); }

function calcItemTotal() {
    const qty    = parseFloat(document.getElementById('ai_qty')?.value    || 0);
    const precio = parseFloat(document.getElementById('ai_precio')?.value || 0);
    const dto    = parseFloat(document.getElementById('ai_dto')?.value    || 0);
    const sub    = qty * precio * (1 - dto / 100);
    const iva    = sub * 0.16;
    const total  = sub + iva;
    const fmt = v => '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const ps = document.getElementById('prev_subtotal');
    const pi = document.getElementById('prev_iva');
    const pt = document.getElementById('prev_total');
    if (ps) ps.textContent = fmt(sub);
    if (pi) pi.textContent = fmt(iva);
    if (pt) pt.textContent = fmt(total);
}
</script>
</body>
</html>
