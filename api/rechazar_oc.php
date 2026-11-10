<?php
require_once '../includes/config.php';

if (!isLoggedIn() || (!isAdmin() && !hasAcceso('gerente'))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true);
$orden_id = intval($data['orden_id'] ?? 0);
$motivo   = trim($data['motivo'] ?? '');

if (!$orden_id || !$motivo) {
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
    exit;
}

$db = getDB();

// A diferencia de aprobar_oc.php/reenviar_oc.php, esto no validaba el estado previo --
// se podía rechazar una OC ya aprobada o incluso ya recibida. Mismo estado de origen que
// aprobar_oc.php: solo se puede rechazar lo que sigue pendiente de aprobación.
$stmt = $db->prepare("SELECT id FROM ordenes_compra WHERE id = ? AND estado = 'pendiente_aprobacion'");
$stmt->execute([$orden_id]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'OC no encontrada o ya procesada']);
    exit;
}

try {
    $db->beginTransaction();

    $db->prepare("UPDATE ordenes_compra SET estado='rechazada', motivo_rechazo=? WHERE id=?")
       ->execute([$motivo, $orden_id]);

    // orden_compra.php descuenta stock al CREAR la OC (no al recibirla) para las OCs
    // manuales de ese flujo -- si se rechaza, ese stock nunca salió de verdad y hay
    // que restituirlo. Se identifica exactamente qué se descontó por ESTA OC vía
    // historial_movimientos.referencia_id (las OCs generadas desde cotizaciones no
    // tocan stock al crearse, así que ahí esto simplemente no encuentra nada que
    // revertir).
    $stmtMovs = $db->prepare("SELECT material_id, cantidad FROM historial_movimientos WHERE referencia_tipo = 'orden_compra' AND referencia_id = ? AND tipo_movimiento = 'salida'");
    $stmtMovs->execute([$orden_id]);
    foreach ($stmtMovs->fetchAll() as $mov) {
        registrarMovimientoAlmacen($db, (int)$mov['material_id'], 'entrada', (float)$mov['cantidad'], 'orden_compra_rechazada', $orden_id, "Reversa por rechazo de OC");
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Error al rechazar: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true]);
