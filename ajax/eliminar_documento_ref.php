<?php
/**
 * Elimina el registro de un documento de referencia de la BD.
 * Solo el usuario que lo subió o un admin puede eliminarlo.
 * Nota: el archivo físico queda en uploads/documentos_ref/ como huérfano
 *       (limpieza mediante tarea de mantenimiento separada).
 * POST param: doc_id (int)
 * Retorna { success } o { success, error }
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'Método no permitido']);
    exit;
}

$doc_id = intval($_POST['doc_id'] ?? 0);
if (!$doc_id) {
    echo json_encode(['success'=>false,'error'=>'doc_id requerido']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT subido_por FROM documentos_referencia WHERE id = ?");
$stmt->execute([$doc_id]);
$doc  = $stmt->fetch();

if (!$doc) {
    echo json_encode(['success'=>false,'error'=>'Documento no encontrado']);
    exit;
}

if (!isAdmin() && (int)$doc['subido_por'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Sin permiso para eliminar este documento']);
    exit;
}

$db->prepare("DELETE FROM documentos_referencia WHERE id = ?")->execute([$doc_id]);

echo json_encode(['success'=>true]);
