<?php
/**
 * Devuelve los primeros N caracteres del texto extraído de un documento.
 * GET param: doc_id (int), chars (int, opcional, default 3000)
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('proyectos') && !isAdmin())) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }

$doc_id = intval($_GET['doc_id'] ?? 0);
if (!$doc_id) { echo json_encode(['success'=>false,'error'=>'doc_id requerido']); exit; }

$limit  = min(10000, max(500, intval($_GET['chars'] ?? 3000)));

$db   = getDB();
$stmt = $db->prepare("SELECT nombre, paginas, estado, texto_extraido FROM documentos_referencia WHERE id = ?");
$stmt->execute([$doc_id]);
$doc  = $stmt->fetch();

if (!$doc) { echo json_encode(['success'=>false,'error'=>'Documento no encontrado']); exit; }

$texto    = strip_tags($doc['texto_extraido'] ?? '');
$total    = strlen($texto);
$preview  = mb_substr($texto, 0, $limit, 'UTF-8');

echo json_encode([
    'success'     => true,
    'nombre'      => htmlspecialchars($doc['nombre'] ?? '', ENT_QUOTES, 'UTF-8'),
    'paginas'     => (int)($doc['paginas'] ?? 0),
    'estado'      => htmlspecialchars($doc['estado'] ?? '', ENT_QUOTES, 'UTF-8'),
    'chars_total' => $total,
    'preview'     => $preview,
    'truncado'    => $total > $limit,
]);
