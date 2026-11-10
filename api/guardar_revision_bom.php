<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}
// Mismo candado que la página completa de ingenieria.php — evita que un usuario de
// otro módulo (compras, almacén, etc.) cambie la revisión de un BOM vía este endpoint.
if (!hasAcceso('ingenieria') && !hasAcceso('admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$data    = json_decode(file_get_contents('php://input'), true);
$boom_id = intval($data['boom_id'] ?? 0);
$revision = intval($data['revision'] ?? 0);

if (!$boom_id) {
    echo json_encode(['error' => 'boom_id inválido']);
    exit();
}
if ($revision < 0) $revision = 0;

$db = getDB();

// Un BOM liberado (enviado_almacen=1) es de solo lectura en toda la pantalla de edición —
// la Rev tampoco se puede tocar hasta que se desbloquee, mismo criterio que el resto del BOM.
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
    echo json_encode(['error' => 'Este BOM está liberado — desbloquéalo antes de cambiar la revisión.']);
    exit();
}

$db->prepare("UPDATE boom_listas SET revision_numero = ? WHERE id = ?")->execute([$revision, $boom_id]);

echo json_encode(['ok' => true, 'revision' => $revision]);
