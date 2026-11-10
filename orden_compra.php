<?php
/**
 * Orden de Compra - Versión corregida con totales persistentes y manejo correcto de USD
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

// Se enlaza desde Compras, Gerencia y Proyectos (ver/generar OC) -- antes cualquier
// usuario logueado podía entrar con cualquier orden_id/req sin ningún control de acceso.
if (!hasAcceso('compras') && !hasAcceso('gerente') && !hasAcceso('proyectos') && !isAdmin()) {
    redirect('sin_acceso.php');
}

$db = getDB();

$requisicion_id = $_GET['req'] ?? 0;
$requisicion = null;
$detalles = [];

if ($requisicion_id) {
    $stmt = $db->prepare("SELECT r.*, u.nombre_completo as usuario_nombre 
        FROM requisiciones r 
        LEFT JOIN usuarios u ON r.usuario_id = u.id 
        WHERE r.id = ?");
    $stmt->execute([$requisicion_id]);
    $requisicion = $stmt->fetch();
    
    if ($requisicion) {
        $stmt = $db->prepare("SELECT rd.*, m.descripcion as material_nombre, m.codigo as material_codigo,
            m.precio_unitario as material_precio, m.unidad_medida as material_unidad
            FROM requisicion_detalle rd 
            LEFT JOIN materiales m ON rd.material_id = m.id 
            WHERE rd.requisicion_id = ?");
        $stmt->execute([$requisicion_id]);
        $detalles = $stmt->fetchAll();
    }
}

$proveedores = $db->query("SELECT * FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll();

// Pre-selección de proveedor desde compras.php
$proveedor_presel_nombre = $_GET['proveedor_nombre'] ?? '';
$proveedor_presel_id = 0;
if ($proveedor_presel_nombre) {
    foreach ($proveedores as $pv) {
        if (mb_strtolower(trim($pv['nombre'])) === mb_strtolower(trim($proveedor_presel_nombre))) {
            $proveedor_presel_id = $pv['id'];
            break;
        }
    }
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'guardar_oc') {
        try {
            $db->beginTransaction();
            
            $folio           = generarFolioOC(null, $requisicion['proyecto'] ?? null);
            $proveedor_id    = $_POST['proveedor_id'];
            $fecha_entrega   = $_POST['fecha_entrega_estimada'] ?? null;
            $condiciones     = sanitize($_POST['condiciones_pago'] ?? '');
            $forma_envio     = sanitize($_POST['forma_envio'] ?? '');
            $moneda          = $_POST['moneda'] ?? 'MXN';
            $tipo_cambio     = floatval($_POST['tipo_cambio'] ?? 1);
            $notas           = sanitize($_POST['notas'] ?? '');
            
            $subtotal = 0;
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    $cantidad = floatval($item['cantidad'] ?? 0);
                    $precio   = floatval($item['precio_unitario'] ?? 0);

                    // Guardamos el precio en la moneda seleccionada
                    if ($cantidad > 0 && $precio > 0) {
                        $subtotal += $cantidad * $precio;
                    }
                }
            }

            $subtotal = round($subtotal, 2);
            $iva      = round($subtotal * 0.16, 2);
            $total    = round($subtotal + $iva, 2);
            
            $stmt = $db->prepare("INSERT INTO ordenes_compra 
                (folio, requisicion_id, proveedor_id, usuario_id, fecha_entrega_estimada,
                 condiciones_pago, forma_envio, subtotal, iva, total, moneda, tipo_cambio, notas) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $folio, $requisicion_id, $proveedor_id, $_SESSION['user_id'],
                $fecha_entrega, $condiciones, $forma_envio,
                $subtotal, $iva, $total,
                $moneda, $tipo_cambio, $notas
            ]);
            $orden_id = $db->lastInsertId();
            
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $item_num = 1;
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['cantidad']) && !empty($item['descripcion'])) {
                        $material_id   = !empty($item['material_id']) ? $item['material_id'] : null;
                        $cantidad      = intval($item['cantidad']);
                        $unidad        = sanitize($item['unidad'] ?? 'pza');
                        $descripcion   = strip_tags(trim($item['descripcion'])); // no htmlspecialchars aquí -- se escapa al mostrar, ver comentario en proyecto.php:1005
                        $precio        = floatval($item['precio_unitario'] ?? 0);
                        
                        // Guardamos el precio en la moneda seleccionada (sin convertir)
                        $subtotal_item = $cantidad * $precio;
                        
                        $stmt = $db->prepare("INSERT INTO orden_compra_detalle 
                            (orden_id, item_numero, material_id, cantidad, unidad_medida,
                             descripcion, precio_unitario, subtotal) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $orden_id, $item_num, $material_id,
                            $cantidad, $unidad, $descripcion,
                            $precio, $subtotal_item
                        ]);
                        
                        if ($material_id) {
                            // Antes: UPDATE + INSERT a historial_movimientos a mano, sin
                            // referencia_id (no quedaba trazable a esta OC específica, así
                            // que rechazar la OC no podía revertir el stock con seguridad)
                            // y sin el lock FOR UPDATE del ledger central. Ahora pasa por
                            // registrarMovimientoAlmacen() con referencia_id=$orden_id, lo
                            // que permite revertir exactamente este movimiento al rechazar.
                            registrarMovimientoAlmacen($db, (int)$material_id, 'salida', -$cantidad, 'orden_compra', (int)$orden_id, "Orden de compra: $folio");
                        }
                        
                        $item_num++;
                    }
                }
            }
            
            $db->prepare("UPDATE requisiciones SET estado = 'en_proceso' WHERE id = ?")
               ->execute([$requisicion_id]);
            registrarEventoRequisicion($db, $requisicion_id, 'orden_generada');
            
            // Marcar el proyecto como realizado (orden de compra generada)
            $stmt_proy = $db->prepare("SELECT proyecto FROM requisiciones WHERE id = ?");
            $stmt_proy->execute([$requisicion_id]);
            $req_data = $stmt_proy->fetch();
            if ($req_data && !empty($req_data['proyecto'])) {
                $db->prepare("UPDATE proyectos SET orden_compra_generada = 1, fecha_orden_compra = NOW(), realizada = 1, fecha_realizada = NOW(), estado = 'realizado' WHERE nombre = ? AND estado = 'espera'")
                   ->execute([$req_data['proyecto']]);
            }
            
            $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'ordenes_compra', ?, ?)")
               ->execute([$_SESSION['user_id'], $orden_id, "Orden de compra creada: $folio"]);
            
            $db->commit();
            header("Location: orden_compra.php?success=1&folio=" . urlencode($folio) . "&orden_id=" . $orden_id);
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $message = showMessage("Error: " . $e->getMessage(), 'error');
        }
    }
}

if (isset($_GET['success']) && isset($_GET['folio'])) {
    $message = showMessage("Orden de Compra " . sanitize($_GET['folio']) . " guardada correctamente", 'success');
}

$orden_guardada = null;
$detalles_oc    = [];
if (isset($_GET['orden_id'])) {
    $stmt = $db->prepare("SELECT oc.*, p.nombre as proveedor_nombre, p.direccion as proveedor_direccion,
        p.telefono as proveedor_telefono, p.rfc as proveedor_rfc,
        p.atencion as proveedor_atencion, p.referencia as proveedor_referencia,
        r.folio as requisicion_folio,
        u.nombre_completo as aprobador_nombre
        FROM ordenes_compra oc
        LEFT JOIN proveedores p ON oc.proveedor_id = p.id
        LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
        LEFT JOIN usuarios u ON oc.aprobado_por = u.id
        WHERE oc.id = ?");
    $stmt->execute([$_GET['orden_id']]);
    $orden_guardada = $stmt->fetch();
    
    if ($orden_guardada) {
        $stmt = $db->prepare(
            "SELECT ocd.*,
                    COALESCE(NULLIF(TRIM(ocd.descripcion),''), m.descripcion) AS descripcion
             FROM orden_compra_detalle ocd
             LEFT JOIN materiales m ON m.id = ocd.material_id
             WHERE ocd.orden_id = ? ORDER BY ocd.item_numero"
        );
        $stmt->execute([$_GET['orden_id']]);
        $detalles_oc = $stmt->fetchAll();
    }
}

$empresa = [
    'nombre'    => 'EMPRESA EJEMPLO',
    'direccion' => "Calle Ejemplo No. 123\nColonia Centro\nCP 12345 Ciudad de México",
    'telefono'  => '(55) 1234-5678',
    'rfc'       => 'XXX010101XXX'
];

$oc_moneda      = $orden_guardada ? $orden_guardada['moneda']      : 'MXN';
$oc_tipo_cambio = $orden_guardada ? floatval($orden_guardada['tipo_cambio']) : 1;

// Calcular totales de manera centralizada
$subtotal_mostrar = 0;
$iva_mostrar = 0;
$total_mostrar = 0;

if ($orden_guardada) {
    // Primero intentamos usar los valores de la base de datos
    $subtotal_mostrar = floatval($orden_guardada['subtotal'] ?? 0);
    $iva_mostrar = floatval($orden_guardada['iva'] ?? 0);
    $total_mostrar = floatval($orden_guardada['total'] ?? 0);
    
    // Si los valores de la BD son 0 pero hay detalles, recalculamos
    if (($subtotal_mostrar == 0 || $iva_mostrar == 0 || $total_mostrar == 0) && !empty($detalles_oc)) {
        $subtotal_mostrar = 0;
        
        foreach ($detalles_oc as $item) {
            $precio_item = floatval($item['precio_unitario'] ?? 0);
            $cantidad_item = intval($item['cantidad'] ?? 0);
            $subtotal_item = floatval($item['subtotal'] ?? ($precio_item * $cantidad_item));
            
            // Si el subtotal del item es 0 pero hay precio y cantidad, calculamos
            if ($subtotal_item == 0 && $precio_item > 0 && $cantidad_item > 0) {
                $subtotal_item = $precio_item * $cantidad_item;
            }
            
            $subtotal_mostrar += $subtotal_item;
        }
        
        $iva_mostrar = round($subtotal_mostrar * 0.16, 2);
        $total_mostrar = round($subtotal_mostrar + $iva_mostrar, 2);
        
        // Actualizar los valores en la base de datos si están incorrectos
        $stmt_update = $db->prepare("UPDATE ordenes_compra SET subtotal = ?, iva = ?, total = ? WHERE id = ?");
        $stmt_update->execute([$subtotal_mostrar, $iva_mostrar, $total_mostrar, $orden_guardada['id']]);
    }
} else {
    // Para orden nueva, calculamos desde los detalles de la requisición
    foreach ($detalles as $item) {
        $precio = floatval($item['material_precio'] ?? $item['precio_estimado'] ?? 0);
        $cantidad = intval($item['cantidad'] ?? 0);
        $subtotal_mostrar += $precio * $cantidad;
    }
    $iva_mostrar = round($subtotal_mostrar * 0.16, 2);
    $total_mostrar = round($subtotal_mostrar + $iva_mostrar, 2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden de Compra - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        
        .no-print { display: block; }
        
        @media print {
            @page { margin: 0.8cm; }
            .no-print { display: none !important; }
            body { background: white; padding: 0; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .orden-container { box-shadow: none !important; margin: 0 !important; padding: 16px !important; border-radius: 0 !important; }
            .info-box { border-radius: 0 !important; }
            .notas-box { border-radius: 0 !important; }
            .totales-table { border-radius: 0 !important; }

            /* Compactar espacios verticales */
            .orden-header { margin-bottom: 10px !important; padding-bottom: 10px !important; }
            .info-section { margin-bottom: 10px !important; gap: 8px !important; }
            .info-box-body { padding: 4px 8px !important; }
            .items-table { margin-bottom: 10px !important; font-size: 11px !important; }
            .items-table th, .items-table td { padding: 4px 6px !important; }
            .totales-section { margin-bottom: 10px !important; }
            .notas-section, .notas-box { margin-bottom: 10px !important; }
            .notas-body { min-height: 35px !important; padding: 5px 8px !important; }
            .firmas-section { margin-top: 10px !important; }
            .firma-line { margin-top: 30px !important; }
            .iso-footer { margin-top: 14px !important; padding-top: 10px !important; font-size: 10px !important; }
            .iso-footer img { height: 35px !important; }
        }
        /* Estilos súper compactos exclusivos para forzar 1 hoja completa en el PDF */
/* Estilos compactos corregidos para evitar recortes */
        .pdf-compacto {
            width: 800px !important;       /* Ancho fijo para captura perfecta */
            min-height: 1040px !important; /* Alto para empujar el footer al fondo */
            margin: 0 !important;          /* Quita el centrado temporalmente */
            padding: 40px !important;      /* Márgenes internos limpios */
            box-sizing: border-box !important;
            display: flex !important;
            flex-direction: column !important;
            background: white !important;
            position: relative !important;
        }
        .pdf-compacto .orden-header { margin-bottom: 10px !important; padding-bottom: 10px !important; }
        .pdf-compacto .orden-logo img { height: 55px !important; }
        .pdf-compacto .orden-titulo h2 { margin-bottom: 2px !important; }
        .pdf-compacto .info-section { margin-bottom: 10px !important; gap: 10px !important; }
        .pdf-compacto .info-box-header { padding: 4px 8px !important; font-size: 11px !important; }
        .pdf-compacto .info-box-body { padding: 5px 8px !important; font-size: 11px !important; line-height: 1.3 !important; }
        .pdf-compacto .items-table { margin-bottom: 10px !important; font-size: 11px !important; }
        .pdf-compacto .items-table th, .pdf-compacto .items-table td { padding: 4px !important; }
        .pdf-compacto .totales-section { margin-bottom: 10px !important; }
        .pdf-compacto .totales-table td { padding: 4px 8px !important; font-size: 11px !important; }
        .pdf-compacto .notas-box { margin-bottom: 10px !important; }
        .pdf-compacto .notas-header { padding: 4px 8px !important; font-size: 11px !important; }
        .pdf-compacto .notas-body { min-height: 30px !important; padding: 5px 8px !important; font-size: 11px !important; }
        
        /* Esto empuja las firmas y el footer hasta el fondo de la hoja */
        .pdf-compacto .firmas-section { 
            margin-top: auto !important; 
            gap: 20px !important; 
        }
        
        .pdf-compacto .firma-line { margin-top: 25px !important; font-size: 11px !important; }
        .pdf-compacto .iso-footer { margin-top: 15px !important; padding-top: 10px !important; font-size: 9px !important; }
        .pdf-compacto .iso-footer img { height: 35px !important; }
        
        .container { max-width: 1000px; margin: 0 auto; }

        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 15px; text-decoration: none; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-success { background: #16a34a; color: white; }
        .btn-warning { background: #d97706; color: white; }
        .btn-danger { background: #dc2626; color: white; float: right; }
        .btn-info { background: #0891b2; color: white; }

        .orden-container { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }

        .orden-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 2px solid #e2eaf4; }
        .orden-logo img { max-height: 80px; width: auto; object-fit: contain; }
        .orden-titulo { text-align: right; }
        .orden-titulo h2 { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 6px; }
        .orden-titulo .folio { font-size: 22px; font-weight: bold; color: #1a3c6e; }
        .orden-fecha { font-size: 11px; color: #6b7280; margin-top: 5px; line-height: 1.6; }

        .info-section { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }
        .info-box { border: 1px solid #d0dce8; border-radius: 8px; overflow: hidden; }
        .info-box-header { background: #e8f0f8; color: #1a3c6e; padding: 7px 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #d0dce8; }
        .info-box-body { padding: 12px; font-size: 12px; line-height: 1.6; }
        .info-box-body strong { display: block; margin-bottom: 4px; font-size: 13px; color: #1a3c6e; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 12px; }
        .items-table thead tr { background: #1a3c6e; }
        .items-table th { color: white; padding: 9px 8px; text-align: center; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border: none; }
        .items-table tbody tr { border-bottom: 1px solid #e8eef4; }
        .items-table tbody tr:last-child { border-bottom: 2px solid #d0dce8; }
        .items-table tbody tr:nth-child(even) { background: #f8fafc; }
        .items-table td { border: none; padding: 7px 8px; vertical-align: top; }
        .items-table .col-item { width: 40px; text-align: center; }
        .items-table .col-qty { width: 50px; text-align: center; }
        .items-table .col-unit { width: 55px; text-align: center; }
        .items-table .col-desc { text-align: left; }
        .items-table .col-price { width: 105px; text-align: right; }
        .items-table .col-subtotal { width: 105px; text-align: right; }
        .items-table input { width: 100%; border: none; padding: 2px; font-size: 12px; text-align: right; background: transparent; }
        .items-table input:focus { outline: none; background: #e8f4fc; border-radius: 3px; }

        .totales-section { display: flex; justify-content: flex-end; margin-bottom: 18px; }
        .totales-table { width: 300px; border-collapse: collapse; font-size: 12px; border: 1px solid #d0dce8; border-radius: 8px; overflow: hidden; }
        .totales-table td { padding: 7px 12px; border-bottom: 1px solid #e8eef4; }
        .totales-table tr:last-child td { border-bottom: none; }
        .totales-table .label { text-align: right; background: #f4f7fb; font-weight: 600; color: #374151; }
        .totales-table .value { text-align: right; width: 130px; }
        .totales-table .total-row td { font-size: 14px; font-weight: bold; color: #1a3c6e; background: #e8f0f8; }

        .notas-section { margin-bottom: 18px; }
        .notas-box { border: 1px solid #d0dce8; border-radius: 8px; overflow: hidden; margin-bottom: 14px; }
        .notas-header { background: #e8f0f8; color: #1a3c6e; padding: 7px 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #d0dce8; }
        .notas-body { padding: 12px; font-size: 11px; min-height: 50px; }
        .notas-body textarea { width: 100%; min-height: 50px; border: none; resize: vertical; font-family: Arial, sans-serif; font-size: 11px; outline: none; }
        .notas-body input { width: 100%; border: none; font-size: 11px; outline: none; }

        .firmas-section { display: flex; justify-content: center; margin-top: 36px; }
        .firmas-section .firma-box { width: 260px; }
        .firma-box { text-align: center; margin: 0 auto; }
        .firma-line { border-top: 2px solid #d0dce8; padding-top: 8px; margin-top: 50px; font-size: 11px; font-weight: bold; color: #374151; text-align: center; }

        .proveedor-selector { margin-bottom: 20px; }
        .proveedor-option { border: 1px solid #e2e8f0; padding: 12px; margin-bottom: 8px; cursor: pointer; border-radius: 8px; display: flex; align-items: flex-start; gap: 10px; transition: all 0.15s; }
        .proveedor-option:hover { border-color: #2563eb; background: #f0f7ff; }
        .proveedor-option.selected { border-color: #2563eb; background: #eff6ff; }
        .proveedor-option input { margin-top: 3px; }

        .moneda-selector { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; }
        .moneda-btn { padding: 8px 22px; border: 2px solid #d0dce8; background: white; cursor: pointer; font-weight: bold; border-radius: 6px; transition: all 0.15s; }
        .moneda-btn.active { border-color: #1a3c6e; background: #1a3c6e; color: white; }

        .alert { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .dias-info { margin-top: 5px; font-size: 11px; color: #6b7280; }

        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>
    <div class="container">
        <div class="no-print btn-group">
            <a href="javascript:history.back()" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <button class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <button class="btn btn-info" onclick="descargarPDF()">
                <i class="fas fa-file-pdf"></i> Descargar PDF
            </button>
            <?php if (!$orden_guardada): ?>
            <button class="btn btn-warning" onclick="guardarOC()">
                <i class="fas fa-save"></i> Guardar Orden
            </button>
            <?php endif; ?>
            <a href="?logout=1" class="btn btn-danger">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </div>
        
        <div class="no-print"><?php echo $message; ?></div>
        
        <?php if (!$requisicion && !$orden_guardada): ?>
        <div style="background: white; padding: 40px; text-align: center;">
            <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #ccc;"></i>
            <p style="margin-top: 15px;">No se encontró la requisición especificada</p>
        </div>
        <?php else: ?>
        
        <?php if (!$orden_guardada): ?>
        <div class="proveedor-selector no-print">
            <h3 style="margin-bottom: 15px;"><i class="fas fa-truck"></i> Seleccionar Proveedor</h3>
            <?php foreach ($proveedores as $prov): ?>
            <label class="proveedor-option<?php echo ($prov['id'] == $proveedor_presel_id) ? ' selected' : ''; ?>" onclick="seleccionarProveedor(this, <?php echo htmlspecialchars(json_encode($prov)); ?>)" data-id="<?php echo $prov['id']; ?>">
                <input type="radio" name="proveedor_id" value="<?php echo $prov['id']; ?>" <?php echo ($prov['id'] == $proveedor_presel_id) ? 'checked' : ''; ?> required>
                <div>
                    <strong><?php echo $prov['nombre']; ?></strong><br>
                    <small><?php echo $prov['direccion']; ?> | Tel: <?php echo $prov['telefono']; ?> | RFC: <?php echo $prov['rfc']; ?></small>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
        
        <div class="moneda-selector no-print">
            <span style="font-weight: bold; margin-right: 10px;">Moneda:</span>
            <button type="button" class="moneda-btn active" data-moneda="MXN" data-cambio="1" onclick="cambiarMoneda(this)">MXN</button>
            <button type="button" class="moneda-btn" data-moneda="USD" data-cambio="17" onclick="cambiarMoneda(this)">USD</button>
        </div>
        <?php endif; ?>
        
        <!-- Formato Orden de Compra -->
        <div class="orden-container" id="ordenCompra">
            <div class="orden-header">
                <div class="orden-logo">
                    <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO">
                </div>
                <div class="orden-titulo">
                    <h2>PURCHASE ORDER No</h2>
                    <div class="folio"><?php echo $orden_guardada ? htmlspecialchars($orden_guardada['folio']) : 'PENDIENTE'; ?></div>
                    <div class="orden-fecha">
                        Date/Fecha: <?php echo date('F d, Y'); ?><br>
                        <?php if ($requisicion): ?>Ref. Requisición: <?php echo htmlspecialchars($requisicion['folio']); ?><?php endif; ?>
                        <?php if ($orden_guardada && $orden_guardada['requisicion_folio']): ?>Ref. Requisición: <?php echo htmlspecialchars($orden_guardada['requisicion_folio']); ?><?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="info-section">
                <div class="info-box">
                    <div class="info-box-header">Buy To / Comprado a:</div>
                    <div class="info-box-body" id="proveedorInfo">
                        <?php if ($orden_guardada): ?>
                            <strong><?php echo htmlspecialchars($orden_guardada['proveedor_nombre']); ?></strong>
                            <?php echo nl2br(htmlspecialchars($orden_guardada['proveedor_direccion'])); ?><br>
                            Tel: <?php echo htmlspecialchars($orden_guardada['proveedor_telefono']); ?><br>
                            RFC: <?php echo htmlspecialchars($orden_guardada['proveedor_rfc']); ?><br>
                            At'n: <?php echo htmlspecialchars($orden_guardada['proveedor_atencion']); ?><br>
                            Ref: <?php echo htmlspecialchars($orden_guardada['proveedor_referencia']); ?>
                        <?php else: ?>
                            <em style="color: #999;">Seleccione un proveedor...</em>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-box">
                    <div class="info-box-header">Bill to / Facturar a:</div>
                    <div class="info-box-body">
                        <strong><?php echo $empresa['nombre']; ?></strong>
                        <?php echo nl2br(htmlspecialchars($empresa['direccion'])); ?><br>
                        Tel: <?php echo $empresa['telefono']; ?><br>
                        RFC: <?php echo $empresa['rfc']; ?>
                    </div>
                </div>
            </div>
            
            <div class="info-section" style="grid-template-columns: 1fr 1fr;">
                <div class="info-box">
                    <div class="info-box-header">Shipped/Enviar a:</div>
                    <div class="info-box-body">
                        <strong><?php echo $empresa['nombre']; ?></strong>
                        <?php echo nl2br(htmlspecialchars($empresa['direccion'])); ?><br>
                        Tel: <?php echo $empresa['telefono']; ?>
                    </div>
                </div>
                <div class="info-box">
                    <div class="info-box-header">Delivery/Tiempo de Entrega</div>
                    <div class="info-box-body">
                        <?php if (!$orden_guardada): ?>
                        <input type="date" name="fecha_entrega" id="fecha_entrega" style="width: 100%; padding: 5px; border: 1px solid #ddd;" onchange="calcularDiasEntrega()">
                        <div id="diasInfo" class="dias-info"></div>
                        <?php else: ?>
                        <?php 
                        echo $orden_guardada['fecha_entrega_estimada'] ? date('d/m/Y', strtotime($orden_guardada['fecha_entrega_estimada'])) : 'N/A'; 
                        if ($orden_guardada['fecha_entrega_estimada']) {
                            $fecha_entrega = new DateTime($orden_guardada['fecha_entrega_estimada']);
                            $hoy = new DateTime();
                            $diferencia = $hoy->diff($fecha_entrega)->days;
                            echo '<div class="dias-info">' . $diferencia . ' days for delivery / ' . $diferencia . ' días para entregar</div>';
                        }
                        ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Tabla de Items -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th class="col-item">Item</th>
                        <th class="col-qty">Qty</th>
                        <th class="col-unit">Unit</th>
                        <th class="col-desc">Description</th>
                        <th class="col-price">Unit Price</th>
                        <th class="col-subtotal">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="itemsBody">
                    <?php if ($orden_guardada): ?>
                        <?php foreach ($detalles_oc as $idx => $item): ?>
                            <?php 
                            $precio_bd = floatval($item['precio_unitario'] ?? 0);
                            $cantidad = intval($item['cantidad'] ?? 0);
                            $subtotal_bd = floatval($item['subtotal'] ?? ($precio_bd * $cantidad));
                            
                            // Si el subtotal del detalle es 0 pero hay precio y cantidad, calculamos
                            if ($subtotal_bd == 0 && $precio_bd > 0 && $cantidad > 0) {
                                $subtotal_bd = $precio_bd * $cantidad;
                            }
                            ?>
                            <tr data-material-id="<?php echo $item['material_id'] ?? ''; ?>"
                                data-precio-mxn="<?php echo ($oc_moneda === 'USD') ? ($precio_bd * $oc_tipo_cambio) : $precio_bd; ?>"
                                data-precio-usd="<?php echo ($oc_moneda === 'USD') ? $precio_bd : ($precio_bd / $oc_tipo_cambio); ?>">
                                <td class="col-item"><?php echo $idx + 1; ?></td>
                                <td class="col-qty"><?php echo $cantidad; ?></td>
                                <td class="col-unit"><?php echo htmlspecialchars($item['unidad_medida'] ?? ''); ?></td>
                                <td class="col-desc"><?php echo htmlspecialchars($item['descripcion'] ?? ''); ?></td>
                                <td class="col-price">
                                    $<?php echo number_format($precio_bd, 2); ?> <?php echo $oc_moneda; ?>
                                </td>
                                <td class="col-subtotal">
                                    $<?php echo number_format($subtotal_bd, 2); ?> <?php echo $oc_moneda; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($detalles as $idx => $item): ?>
                            <?php
                            $precio        = floatval($item['material_precio'] ?? $item['precio_estimado'] ?? 0);
                            $cantidad      = intval($item['cantidad']);
                            $item_subtotal = $precio * $cantidad;
                            $unidad        = $item['material_unidad'] ?? $item['unidad_medida'] ?? 'pza';
                            ?>
                            <tr data-material-id="<?php echo $item['material_id'] ?? ''; ?>"
                                data-precio-mxn="<?php echo $precio; ?>"
                                data-precio-usd="<?php echo round($precio / 17, 2); ?>">
                                <td class="col-item"><?php echo $idx + 1; ?></td>
                                <td class="col-qty"><?php echo $cantidad; ?></td>
                                <td class="col-unit"><?php echo htmlspecialchars($unidad); ?></td>
                                <td class="col-desc"><?php echo htmlspecialchars($item['descripcion']); ?></td>
                                <td class="col-price">
                                    <input type="number"
                                           class="precio-input"
                                           value="<?php echo number_format($precio, 2, '.', ''); ?>"
                                           step="0.01"
                                           min="0"
                                           onchange="calcularTotales()"
                                           data-precio-mxn="<?php echo $precio; ?>"
                                           data-precio-usd="<?php echo round($precio / 17, 2); ?>">
                                </td>
                                <td class="col-subtotal">
                                    $<?php echo number_format($item_subtotal, 2); ?> MXN
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Totales -->
            <div class="totales-section">
                <table class="totales-table">
                    <tr>
                        <td class="label">Subtotal:</td>
                        <td class="value" id="subtotalValor">
                            <?php if ($orden_guardada): ?>
                                $<?php echo number_format($subtotal_mostrar, 2); ?> <?php echo $oc_moneda; ?>
                            <?php else: ?>
                                $<?php echo number_format($subtotal_mostrar, 2); ?> MXN
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label">IVA (16%):</td>
                        <td class="value" id="ivaValor">
                            <?php if ($orden_guardada): ?>
                                $<?php echo number_format($iva_mostrar, 2); ?> <?php echo $oc_moneda; ?>
                            <?php else: ?>
                                $<?php echo number_format($iva_mostrar, 2); ?> MXN
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="total-row">
                        <td class="label">TOTAL:</td>
                        <td class="value" id="totalValor">
                            <?php if ($orden_guardada): ?>
                                $<?php echo number_format($total_mostrar, 2); ?> <?php echo $oc_moneda; ?>
                            <?php else: ?>
                                $<?php echo number_format($total_mostrar, 2); ?> MXN
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Notas -->
            <div class="notas-section">
                <div class="notas-box">
                    <div class="notas-header">Notas / Notes:</div>
                    <div class="notas-body">
                        <?php echo nl2br(htmlspecialchars($orden_guardada['notas'] ?? '')); ?>
                    </div>
                </div>
            </div>

            <div class="notas-box">
                <div class="notas-header">Payment Terms / Términos de Pago:</div>
                <div class="notas-body">
                    <?php echo htmlspecialchars($orden_guardada['condiciones_pago'] ?? ''); ?>
                </div>
            </div>
            
            <div class="firmas-section">
                <div class="firma-box">
                    <?php if ($orden_guardada && ($orden_guardada['estado'] ?? '') === 'aprobada' && !empty($orden_guardada['firma_ruta'])): ?>
                    <img src="<?php echo htmlspecialchars($orden_guardada['firma_ruta']); ?>"
                         style="max-height:60px;max-width:200px;display:block;margin:0 auto 6px;">
                    <?php endif; ?>
                    <div class="firma-line">Approved by / Autorizado por</div>
                </div>
            </div>
<table class="iso-footer" style="margin-top: 28px; width: 100%; border-collapse: collapse; font-size: 10px; font-family: Arial, sans-serif; color: #111;">
                <tr>
                    <td style="border: 1px solid #000; padding: 5px 8px; text-align: center; width: 25%;">DOC-COM-FO-01B</td>
                    <td style="border: 1px solid #000; padding: 5px 8px; text-align: center; width: 25%;">Rev. <input type="text" value="4" style="border:none;background:transparent;outline:none;font-size:inherit;font-family:inherit;text-align:center;width:60px;"></td>
                    <td style="border: 1px solid #000; padding: 5px 8px; text-align: center; width: 25%;">Fecha: <input type="text" value="20 - dic - 21" style="border:none;background:transparent;outline:none;font-size:inherit;font-family:inherit;text-align:center;width:90px;"></td>
                    <td style="border: 1px solid #000; padding: 5px 8px; text-align: center; width: 25%;">Página: <input type="text" value="1" style="border:none;background:transparent;outline:none;font-size:inherit;font-family:inherit;text-align:center;width:30px;"></td>
                </tr>
                <tr>
                    <td colspan="4" style="border: 1px solid #000; padding: 5px 8px; text-align: center;">
                        Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                    </td>
                </tr>
            </table>

            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 10px; font-size: 9.5px; color: #333; font-family: Arial, sans-serif; line-height: 1.7;">
                <div style="text-align: left;">
                    Calle Ejemplo No. 123<br>
                    Col. Centro. C.P. 00000<br>
                    Ciudad de M&eacute;xico<br>
                    www.ejemplo.com
                </div>
                <div style="text-align: center;">
                    Tel. 52 55 0000 0000<br>
                    contacto@ejemplo.com
                </div>
                <div style="text-align: right;">
                    <img src="uploads/logo/isotipo.png" alt="EMPRESA EJEMPLO" style="height: 36px; width: auto; object-fit: contain;">
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
let proveedorSeleccionado = null;
let monedaActual = 'MXN';
let tipoCambio   = 1;

// Auto-seleccionar proveedor si viene preseleccionado desde compras.php
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($proveedor_presel_id > 0): ?>
    const preselLabel = document.querySelector('.proveedor-option[data-id="<?php echo $proveedor_presel_id; ?>"]');
    if (preselLabel) {
        seleccionarProveedor(preselLabel, <?php echo htmlspecialchars(json_encode(array_values(array_filter($proveedores, fn($p) => $p['id'] == $proveedor_presel_id))[0] ?? [])); ?>);
        preselLabel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    <?php endif; ?>
});

function seleccionarProveedor(elemento, proveedor) {
    document.querySelectorAll('.proveedor-option').forEach(el => el.classList.remove('selected'));
    elemento.classList.add('selected');
    elemento.querySelector('input').checked = true;
    proveedorSeleccionado = proveedor;

    document.getElementById('proveedorInfo').innerHTML = `
        <strong>${proveedor.nombre}</strong><br>
        ${proveedor.direccion}<br>
        Tel: ${proveedor.telefono}<br>
        RFC: ${proveedor.rfc}<br>
        At'n: ${proveedor.atencion}<br>
        Ref: ${proveedor.referencia}
    `;
}

function cambiarMoneda(btn) {
    document.querySelectorAll('.moneda-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    monedaActual = btn.dataset.moneda;
    tipoCambio   = parseFloat(btn.dataset.cambio);

    document.querySelectorAll('#itemsBody tr').forEach(fila => {
        const input = fila.querySelector('.precio-input');
        if (!input) return;
        
        const precioMXN = parseFloat(fila.dataset.precioMxn) || 0;
        const precioUSD = parseFloat(fila.dataset.precioUsd) || 0;
        
        if (monedaActual === 'USD') {
            input.value = precioUSD.toFixed(2);
        } else {
            input.value = precioMXN.toFixed(2);
        }
    });

    calcularTotales();
}

function calcularTotales() {
    let subtotal = 0;
    const filas = document.querySelectorAll('#itemsBody tr');

    filas.forEach(fila => {
        const cantidad    = parseFloat(fila.querySelector('.col-qty')?.textContent?.trim()) || 0;
        const precioInput = fila.querySelector('.precio-input');
        
        let precio;
        if (precioInput) {
            precio = parseFloat(precioInput.value) || 0;
        } else {
            // Para órdenes guardadas, tomamos el precio del texto
            const precioText = fila.querySelector('.col-price')?.textContent?.trim() || '$0';
            precio = parseFloat(precioText.replace(/[$,]/g, '')) || 0;
        }
        
        const subtotalItem = cantidad * precio;

        const celdaSubtotal = fila.querySelector('.col-subtotal');
        if (celdaSubtotal && precioInput) {
            celdaSubtotal.textContent = '$' + subtotalItem.toFixed(2) + ' ' + monedaActual;
        }

        subtotal += subtotalItem;
    });

    const iva   = subtotal * 0.16;
    const total = subtotal + iva;

    document.getElementById('subtotalValor').textContent = '$' + subtotal.toFixed(2) + ' ' + monedaActual;
    document.getElementById('ivaValor').textContent      = '$' + iva.toFixed(2)      + ' ' + monedaActual;
    document.getElementById('totalValor').textContent    = '$' + total.toFixed(2)    + ' ' + monedaActual;
}

function calcularDiasEntrega() {
    const fechaInput = document.getElementById('fecha_entrega').value;
    if (!fechaInput) {
        document.getElementById('diasInfo').innerHTML = '';
        return;
    }
    
    const fechaEntrega = new Date(fechaInput);
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    
    const diferenciaMs = fechaEntrega - hoy;
    const diferenciaDias = Math.ceil(diferenciaMs / (1000 * 60 * 60 * 24));
    
    document.getElementById('diasInfo').innerHTML = diferenciaDias + ' days for delivery / ' + diferenciaDias + ' días para entregar';
}

function guardarOC() {
    if (!proveedorSeleccionado) {
        alert('Por favor seleccione un proveedor');
        return;
    }

    calcularTotales();

    const fechaEntrega    = document.getElementById('fecha_entrega')?.value     || '';
    const condicionesPago = document.getElementById('condiciones_pago')?.value  || '';
    const notas           = document.getElementById('notas')?.value             || '';
    const filas           = document.querySelectorAll('#itemsBody tr');

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    function addHidden(name, value) {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = name;
        input.value = value;
        form.appendChild(input);
    }

    addHidden('action',                 'guardar_oc');
    addHidden('proveedor_id',           proveedorSeleccionado.id);
    addHidden('moneda',                 monedaActual);
    addHidden('tipo_cambio',            tipoCambio);
    addHidden('condiciones_pago',       condicionesPago);
    addHidden('fecha_entrega_estimada', fechaEntrega);
    addHidden('notas',                  notas);

    filas.forEach((fila, idx) => {
        const materialId  = fila.dataset.materialId || '';
        const cantidad    = parseFloat(fila.querySelector('.col-qty')?.textContent?.trim()) || 0;
        const unidad      = fila.querySelector('.col-unit')?.textContent?.trim()  || '';
        const descripcion = fila.querySelector('.col-desc')?.textContent?.trim()  || '';
        const precioInput = fila.querySelector('.precio-input');
        
        let precio;
        if (precioInput) {
            precio = parseFloat(precioInput.value) || 0;
        } else {
            const precioText = fila.querySelector('.col-price')?.textContent?.trim() || '$0';
            precio = parseFloat(precioText.replace(/[$,]/g, '')) || 0;
        }
        
        const subtotalItem = cantidad * precio;

        addHidden(`items[${idx}][material_id]`,    materialId);
        addHidden(`items[${idx}][cantidad]`,        cantidad);
        addHidden(`items[${idx}][unidad]`,          unidad);
        addHidden(`items[${idx}][descripcion]`,     descripcion);
        addHidden(`items[${idx}][precio_unitario]`, precio.toFixed(2));
        addHidden(`items[${idx}][subtotal]`,        subtotalItem.toFixed(2));
    });

    document.body.appendChild(form);
    form.submit();
}
function descargarPDF() {
    const elemento = document.getElementById('ordenCompra');
    let folio = document.querySelector('.folio')?.innerText.trim() || 'OC';
    window.scrollTo(0, 0);
    elemento.classList.add('pdf-compacto');
    html2pdf()
        .set({
            margin:      0,
            filename:    'Orden_Compra_' + folio + '.pdf',
            image:       { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 1, useCORS: false, logging: false, scrollX: 0, scrollY: 0 },
            jsPDF:       { unit: 'in', format: 'letter', orientation: 'portrait', compress: true }
        })
        .from(elemento)
        .save()
        .then(() => elemento.classList.remove('pdf-compacto'));
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!$orden_guardada): ?>
    calcularTotales();
    if (document.getElementById('fecha_entrega')) {
        calcularDiasEntrega();
    }
    <?php endif; ?>
});
    </script>
</body>
</html>