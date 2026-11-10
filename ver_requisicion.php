<?php
/**
 * Ver Detalle de Requisición - Formato exacto
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

// Acceso base: cualquiera de las áreas que enlazan aquí (Producción, Calidad,
// Ingeniería, Proyectos -- "Mis Requisiciones" en includes/generar_requisicion.php)
// o admin. La restricción fina (solo TU requisición si no eres manufactura/admin,
// y aprobar solo si eres manufactura/admin) va más abajo, una vez cargado
// $requisicion -- antes este guard solo pedía isLoggedIn(), lo que dejaba ver Y
// APROBAR el detalle de CUALQUIER requisición a cualquier usuario logueado.
if (!isLoggedIn() || (!hasAcceso('manufactura') && !hasAcceso('calidad') && !hasAcceso('ingenieria') && !hasAcceso('proyectos') && !isAdmin())) {
    redirect('index.php');
}

$db = getDB();

// Aprobar SIEMPRE es cosa de Producción/admin -- Calidad/Ingeniería/Proyectos solo
// deben poder VER sus propias requisiciones, nunca aprobar ninguna (ni la propia).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmar_requisicion') {
    if (!hasAcceso('manufactura') && !isAdmin()) {
        http_response_code(403);
        exit('No autorizado.');
    }
    $req_id = intval($_POST['req_id'] ?? 0);
    if ($req_id) {
        $db->prepare("UPDATE requisiciones SET estado = 'aprobada' WHERE id = ?")->execute([$req_id]);
        registrarEventoRequisicion($db, $req_id, 'aprobada');
    }
    header("Location: produccion.php?seccion=generar_requisicion&tab=mis&msg=" . urlencode("Requisición guardada correctamente."));
    exit();
}

$id = intval($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT r.*, u.nombre_completo as usuario_nombre
    FROM requisiciones r
    LEFT JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.id = ?");
$stmt->execute([$id]);
$requisicion = $stmt->fetch();

if (!$requisicion) {
    redirect('index.php');
}

// Calidad/Ingeniería/Proyectos solo pueden ver SU PROPIA requisición (esto es lo
// que separa "Mis Requisiciones" de un IDOR) -- Producción/admin sí conservan la
// vista amplia que ya tenían (necesaria para el flujo de aprobación de planos, que
// revisa requisiciones que no siempre creó el mismo usuario de manufactura).
if (!isAdmin() && !hasAcceso('manufactura') && (int) $requisicion['usuario_id'] !== (int) ($_SESSION['user_id'] ?? 0)) {
    redirect('index.php');
}

$stmt = $db->prepare("SELECT rd.*, m.descripcion as material_nombre, m.codigo as material_codigo
    FROM requisicion_detalle rd 
    LEFT JOIN materiales m ON rd.material_id = m.id 
    WHERE rd.requisicion_id = ?");
$stmt->execute([$id]);
$detalles = $stmt->fetchAll();

$stmt = $db->prepare("SELECT oc.*, p.nombre as proveedor_nombre 
    FROM ordenes_compra oc 
    LEFT JOIN proveedores p ON oc.proveedor_id = p.id 
    WHERE oc.requisicion_id = ?");
$stmt->execute([$id]);
$ordenes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requisición <?php echo $requisicion['folio']; ?> - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background: #f0f4f8;
            padding: 20px;
            font-size: 13px;
            color: #222;
        }

        /* ── Botones de control ── */
        .btn {
            padding: 9px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 12px;
            text-decoration: none;
            font-weight: 600;
        }
        .btn-primary { background: #2196F3; color: white; }
        .btn-success { background: #4CAF50; color: white; }
        .btn-warning { background: #FF9800; color: white; }

        /* ── Contenedor principal ── */
        .container {
            max-width: 1050px;
            margin: 0 auto;
            background: white;
            padding: 36px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,.09);
        }

        /* ── Cabecera corporativa ── */
        .req-header {
            display: flex;
            border: 2px solid #003d5c;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 18px;
        }
        .req-logo-cell {
            width: 210px;
            flex-shrink: 0;
            border-right: 2px solid #003d5c;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f7fb;
        }
        .req-title-cell {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #003d5c;
            padding: 12px 20px;
            gap: 3px;
        }
        .req-title-cell h1 {
            color: #fff;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        .req-title-cell span {
            color: rgba(255,255,255,.65);
            font-size: 10px;
            letter-spacing: 1px;
        }

        /* ── Metadatos del solicitante ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
            border: 1px solid #b0c4de;
            border-radius: 6px;
            overflow: hidden;
            font-size: 12px;
        }
        .info-table td {
            padding: 9px 13px;
            border: 1px solid #b0c4de;
            vertical-align: middle;
        }
        .label-cell {
            background: #eef2f7;
            font-weight: 700;
            color: #1a3a5c;
            width: 26%;
            white-space: nowrap;
        }
        .value-cell { color: #222; background: #fff; }

        /* ══ MATRIZ RÍGIDA PDF-SAFE ══ */
        .req-rigid-matrix-table {
            table-layout: fixed !important;
            width: 100% !important;
            border-collapse: collapse !important;
            margin-bottom: 20px !important;
            border: none !important;
            font-family: 'Helvetica Neue', Arial, sans-serif;
        }
        .req-rigid-matrix-table th {
            background: #0056b3 !important;
            color: white !important;
            font-weight: bold !important;
            padding: 12px 6px !important;
            text-align: center !important;
            border: none !important;
        }
        .req-rigid-matrix-table td {
            padding: 10px 8px !important;
            border-bottom: 1px solid #e2e8f0 !important;
            border-left: none !important;
            border-right: none !important;
            border-top: none !important;
            vertical-align: middle !important;
            overflow: hidden !important;
        }
        .req-rigid-matrix-table tbody tr:nth-child(even) td { background: #f8fafd !important; }
        .req-rigid-matrix-table tbody tr:last-child td { border-bottom: none !important; }

        /* ── Escudo de texto: nowrap para encabezados, desc-mode para descripción ── */
        .cell-text-shield {
            display: block !important;
            width: 100% !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.3 !important;
            font-size: 11px !important;
            box-sizing: border-box !important;
        }
        .cell-text-shield.desc-mode {
            white-space: normal !important;
            word-break: keep-all !important;
            overflow-wrap: break-word !important;
        }

        /* ── Input camuflado como texto plano ── */
        .clean-camuflaje-input {
            border: none !important;
            background: transparent !important;
            text-align: center !important;
            font-size: inherit !important;
            outline: none !important;
            box-shadow: none !important;
            width: 100%;
            padding: 0 !important;
            margin: 0 !important;
            font-family: inherit !important;
        }

        /* ── Notas generales ── */
        .notas-section {
            margin-top: 16px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 12px 14px;
            background: #fafafa;
            font-size: 11px;
        }
        .notas-section h3 { font-size: 11px; margin-bottom: 5px; color: #003d5c; }

        /* ── Órdenes de compra ── */
        .ordenes-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
        }
        .ordenes-section h3 { font-size: 15px; margin-bottom: 14px; color: #003d5c; }
        .ordenes-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .ordenes-table th { background: #003d5c; color: white; padding: 9px 10px; text-align: left; }
        .ordenes-table td { padding: 8px 10px; border-bottom: 1px solid #ddd; }
        .badge { padding: 3px 9px; border-radius: 10px; font-size: 10px; text-transform: uppercase; font-weight: 600; }
        .badge-pendiente  { background: #ffc107; color: #333; }
        .badge-aprobada   { background: #28a745; color: white; }
        .badge-en_proceso { background: #17a2b8; color: white; }
        .badge-completada { background: #003d5c; color: white; }

        /* ── Destrucción absoluta de columna de acción en impresión ── */
        @media print {
            .col-accion, .col-accion *, button.btn-danger, th.col-accion, td.col-accion {
                display: none !important;
                visibility: hidden !important;
                width: 0 !important;
                max-width: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
            }
        }

        /* ── Impresión ── */
        @media print {
            @page { margin: 1.2cm 1cm; }
            .no-print,
            .no-print *,
            tr.no-print,
            td.no-print,
            .col-accion,
            .col-accion * { display: none !important; visibility: hidden !important; height: 0 !important; padding: 0 !important; margin: 0 !important; }
            body { background: white; padding: 0; font-size: 11px; font-family: 'Helvetica Neue', Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .container { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; max-width: 100%; }
            tr { page-break-inside: avoid !important; }
            .req-rigid-matrix-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <?php
    $from = $_GET['from'] ?? '';
    // "volver" es una CLAVE fija (no una URL) para que no se pueda inyectar un href
    // arbitrario vía query string -- mapea a la sección "Mis Requisiciones" de cada
    // módulo. Gana sobre el mapeo por "from", que sigue existiendo para el flujo
    // viejo de subproyectos.
    $volver_map = [
        'manufactura' => 'produccion.php?seccion=generar_requisicion&tab=mis',
        'calidad'     => 'calidad.php?seccion=generar_requisicion&tab=mis',
        'ingenieria'  => 'ingenieria.php?seccion=generar_requisicion&tab=mis',
        'proyectos'   => 'proyecto.php?seccion=generar_requisicion&tab=mis',
    ];
    $volver_url = $volver_map[$_GET['volver'] ?? '']
        ?? (($from === 'manufactura') ? 'produccion.php?seccion=subproyectos' : 'Menu.php');
    ?>
    <div class="no-print" style="max-width: 1200px; margin: 0 auto 15px;">
        <a href="<?php echo $volver_url; ?>" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
        <button class="btn btn-success" onclick="window.print()">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <?php if ($from === 'manufactura' && $requisicion['estado'] === 'pendiente'): ?>
        <form method="POST" action="ver_requisicion.php?id=<?php echo htmlspecialchars((string)(int)$id, ENT_QUOTES, 'UTF-8'); ?>&from=manufactura" style="display:inline;">
            <input type="hidden" name="action" value="confirmar_requisicion">
            <input type="hidden" name="req_id" value="<?php echo (int)$requisicion['id']; ?>">
            <button type="submit" class="btn btn-warning" onclick="return confirm('¿Confirmar que la requisición está completa?')">
                <i class="fas fa-check"></i> Guardar Requisición
            </button>
        </form>
        <?php endif; ?>
    </div>
    
    <div class="container">

        <!-- ══ 1. CABECERA CORPORATIVA ══ -->
        <div class="req-header">
            <div class="req-logo-cell">
                <img src="uploads/logo/logo.png"
                     style="max-width:170px; max-height:65px; object-fit:contain;"
                     alt="EMPRESA EJEMPLO"
                     onerror="this.style.display='none'">
            </div>
            <div class="req-title-cell">
                <h1>HOJA DE REQUISICIÓN</h1>
                <span>Folio&nbsp;#<?php echo htmlspecialchars($requisicion['folio']); ?></span>
            </div>
        </div>

        <!-- ══ 2. METADATOS DEL SOLICITANTE ══ -->
        <table class="info-table">
            <tr>
                <td class="label-cell">Proyecto / Cliente destino</td>
                <td class="value-cell"><?php echo htmlspecialchars($requisicion['proyecto'] ?? ''); ?></td>
                <td class="label-cell" style="width:24%;">Fecha máx. requerida</td>
                <td class="value-cell" style="width:14%; text-align:center;">
                    <?php echo $requisicion['fecha_requerida']
                        ? date('d/m/Y', strtotime($requisicion['fecha_requerida']))
                        : '—'; ?>
                </td>
            </tr>
            <tr>
                <td class="label-cell">Departamento solicitante</td>
                <td class="value-cell" colspan="3"><?php echo htmlspecialchars($requisicion['area'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="label-cell">Nombre del solicitante</td>
                <td class="value-cell" colspan="3"><?php echo htmlspecialchars($requisicion['solicitante'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="label-cell">No. de Requisición</td>
                <td class="value-cell"><strong><?php echo htmlspecialchars($requisicion['folio'] ?? ''); ?></strong></td>
                <td class="label-cell">Fecha de solicitud</td>
                <td class="value-cell" style="text-align:center;">
                    <?php echo $requisicion['fecha_solicitud']
                        ? date('d/m/Y', strtotime($requisicion['fecha_solicitud']))
                        : '—'; ?>
                </td>
            </tr>
        </table>

        <!-- ══ 3. TABLA DE ARTÍCULOS ══ -->
        <table class="req-rigid-matrix-table">
            <thead>
                <tr>
                    <th style="width:5%;"><div class="cell-text-shield">Ítem</div></th>
                    <th style="width:10%;"><div class="cell-text-shield">Cantidad</div></th>
                    <th style="width:14%;"><div class="cell-text-shield">Artículo</div></th>
                    <th style="width:32%;"><div class="cell-text-shield desc-mode">Descripción</div></th>
                    <th style="width:12%;"><div class="cell-text-shield">Marca</div></th>
                    <th style="width:12%;"><div class="cell-text-shield">Modelo</div></th>
                    <th style="width:15%;"><div class="cell-text-shield">Nota</div></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detalles)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; color:#999; padding:22px !important;">
                        Sin artículos registrados
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($detalles as $idx => $item):
                        $i_num   = $idx + 1;
                        $i_cant  = $item['cantidad'] ?? '-';
                        $i_cod   = !empty($item['material_codigo']) ? htmlspecialchars($item['material_codigo']) : '-';
                        $i_desc  = !empty($item['descripcion'])     ? htmlspecialchars($item['descripcion'])     : '-';
                        $i_marca = !empty($item['marca'])           ? htmlspecialchars($item['marca'])           : '-';
                        $i_mod   = !empty($item['modelo'])          ? htmlspecialchars($item['modelo'])          : '-';
                        $i_nota  = !empty($item['notas'])           ? htmlspecialchars($item['notas'])           : '-';
                    ?>
                    <tr>
                        <td style="text-align:center;"><div class="cell-text-shield" style="font-weight:700;"><?php echo $i_num; ?></div></td>
                        <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_cant; ?></div></td>
                        <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_cod; ?></div></td>
                        <td style="text-align:left; padding-left:10px !important;"><div class="cell-text-shield desc-mode"><?php echo $i_desc; ?></div></td>
                        <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_marca; ?></div></td>
                        <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_mod; ?></div></td>
                        <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_nota; ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ══ 4. NOTAS GENERALES ══ -->
        <?php if (!empty($requisicion['notas_generales'])): ?>
        <div class="notas-section">
            <h3><i class="fas fa-sticky-note"></i> Notas Generales</h3>
            <p style="margin-top:4px;"><?php echo nl2br(htmlspecialchars($requisicion['notas_generales'])); ?></p>
        </div>
        <?php else: ?>
        <div style="height:18px;"></div>
        <?php endif; ?>

        <!-- ══ 5. PIE DE CONTROL DE CALIDAD ISO ══ -->
        <table style="width:100%; border-collapse:collapse; border:1px solid #aaa; margin-top:20px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:11px;">
            <tr>
                <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px; font-weight:700; letter-spacing:.5px;">DOC-COM-FO-01B</td>
                <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                    Rev.&nbsp;<input type="text" value="4" class="clean-camuflaje-input" style="width:20px; text-align:left !important;">
                </td>
                <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                    Fecha:&nbsp;<input type="text" value="20 - dic - 21" class="clean-camuflaje-input" style="width:85px;">
                </td>
                <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                    Página:&nbsp;<input type="text" value="1" class="clean-camuflaje-input" style="width:20px;">
                </td>
            </tr>
            <tr>
                <td colspan="4" style="text-align:center; padding:8px 12px; border-top:1px solid #aaa; font-size:10px; color:#444; line-height:1.4;">
                    Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                </td>
            </tr>
        </table>

        <!-- ══ 6. FOOTER INSTITUCIONAL (3 columnas) ══ -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; padding-top:10px; border-top:1px solid #e0e0e0; font-size:10px; color:#333; gap:10px; font-family:'Helvetica Neue',Arial,sans-serif;">
            <div style="flex:1; text-align:left; line-height:1.7;">
                Calle Ejemplo No. 123<br>
                Col. Centro. C.P. 00000<br>
                Ciudad de M&eacute;xico<br>
                <span style="color:#0056b3; font-weight:600;">www.ejemplo.com</span>
            </div>
            <div style="flex:1; text-align:center; line-height:1.7;">
                Tel. 52 55 0000 0000<br>
                contacto@ejemplo.com<br>
                <strong>Sistema de Gesti&oacute;n de Calidad</strong>
            </div>
            <div style="flex:0 0 auto; text-align:right; display:flex; align-items:center; justify-content:flex-end;">
                <img src="../uploads/logo/isotipo.png" style="width:30px; height:auto;" alt="EMPRESA EJEMPLO"
                     onerror="this.src='uploads/logo/isotipo.png'">
            </div>
        </div>

        <!-- ══ 7. ÓRDENES DE COMPRA (solo pantalla) ══ -->
        <?php if (!empty($ordenes)): ?>
        <div class="ordenes-section no-print">
            <h3><i class="fas fa-shopping-cart"></i> Órdenes de Compra Relacionadas</h3>
            <table class="ordenes-table">
                <thead>
                    <tr>
                        <th>Folio OC</th>
                        <th>Proveedor</th>
                        <th>Fecha</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ordenes as $oc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($oc['folio']); ?></strong></td>
                        <td><?php echo htmlspecialchars($oc['proveedor_nombre'] ?? ''); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($oc['fecha_oc'])); ?></td>
                        <td><?php echo formatMoney($oc['total'], $oc['moneda']); ?></td>
                        <td><span class="badge badge-<?php echo $oc['estado']; ?>"><?php echo ucfirst($oc['estado']); ?></span></td>
                        <td>
                            <a href="orden_compra.php?orden_id=<?php echo $oc['id']; ?>" class="btn btn-primary" style="font-size:12px; padding:5px 12px;">
                                <i class="fas fa-eye"></i> Ver
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div><!-- /.container -->
</body>
</html>
