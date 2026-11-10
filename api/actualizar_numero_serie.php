<?php
/**
 * Edita almacen_items.numero_serie desde la tabla de proyecto en Trazabilidad (calidad.php)
 * -- campo editable pedido explícito del usuario, 2026-09-17.
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('calidad') && !isAdmin())) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$item_id = intval($_POST['item_id'] ?? 0);
$numero_serie = trim($_POST['numero_serie'] ?? '');
if (!$item_id) {
    echo json_encode(['success' => false, 'error' => 'item_id requerido']);
    exit;
}

$db = getDB();
$db->prepare("UPDATE almacen_items SET numero_serie = ? WHERE id = ?")
   ->execute([$numero_serie !== '' ? $numero_serie : null, $item_id]);

echo json_encode(['success' => true]);
