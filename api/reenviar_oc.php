<?php
require_once '../includes/config.php';

if (!isLoggedIn() || (!isAdmin() && !hasAcceso('compras'))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true);
$orden_id = intval($data['orden_id'] ?? 0);

if (!$orden_id) {
    echo json_encode(['ok' => false, 'error' => 'orden_id inválido']);
    exit;
}

$db = getDB();
$db->prepare("UPDATE ordenes_compra SET estado='pendiente_aprobacion', motivo_rechazo=NULL WHERE id=? AND estado='rechazada'")
   ->execute([$orden_id]);

echo json_encode(['ok' => true]);
