<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}
// Mismo candado que la página completa de ingenieria.php — evita que un usuario de
// otro módulo (compras, almacén, etc.) cambie el tipo de válvula vía este endpoint.
if (!hasAcceso('ingenieria') && !hasAcceso('admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$data          = json_decode(file_get_contents('php://input'), true);
$boom_id       = intval($data['boom_id'] ?? 0);
$tipo_valvula  = trim($data['tipo_valvula'] ?? '');

if (!$boom_id) {
    echo json_encode(['error' => 'boom_id inválido']);
    exit();
}

$db = getDB();

// Mismo criterio que guardar_revision_bom.php: un BOM liberado es de solo lectura
// hasta que se desbloquee.
$stmt = $db->prepare("SELECT enviado_almacen FROM boom_listas WHERE id = ?");
$stmt->execute([$boom_id]);
$enviado = $stmt->fetchColumn();
if ($enviado === false) {
    http_response_code(404);
    echo json_encode(['error' => 'BOM no encontrado']);
    exit();
}
if ((bool)$enviado) {
    http_response_code(403);
    echo json_encode(['error' => 'Este BOM está liberado — desbloquéalo antes de cambiar el equipo.']);
    exit();
}

$db->prepare("UPDATE boom_listas SET tipo_valvula = ? WHERE id = ?")->execute([$tipo_valvula ?: null, $boom_id]);

echo json_encode(['ok' => true, 'tipo_valvula' => $tipo_valvula]);
