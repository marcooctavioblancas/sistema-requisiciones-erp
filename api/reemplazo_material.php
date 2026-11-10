<?php
require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || (!hasAcceso('almacen') && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$origen_tipo    = $data['origen_tipo'] ?? '';
$origen_id      = intval($data['origen_id'] ?? 0);
// alternativa_ids (array, varios candidatos) es lo nuevo; alternativa_id (uno solo)
// se mantiene por compatibilidad con cualquier llamador viejo.
$alternativa_ids = $data['alternativa_ids'] ?? (isset($data['alternativa_id']) ? [$data['alternativa_id']] : []);
$nota           = trim($data['nota'] ?? '');

if (!in_array($origen_tipo, ['almacen_item', 'requisicion_detalle'], true) || !$origen_id || empty($alternativa_ids)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos para proponer el reemplazo.']);
    exit();
}

$db = getDB();
$resultado = proponerReemplazoMaterial($db, $origen_tipo, $origen_id, (int)$_SESSION['user_id'], $alternativa_ids, $nota);

if ($resultado['ok']) {
    echo json_encode(['success' => true, 'message' => 'Reemplazo propuesto. Queda pendiente de aprobación del área responsable.']);
} else {
    echo json_encode(['success' => false, 'error' => $resultado['error']]);
}
