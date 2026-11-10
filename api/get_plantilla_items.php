<?php
require_once '../includes/config.php';
if (!isLoggedIn()) { http_response_code(403); echo json_encode(['items'=>[]]); exit(); }

$db = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['items'  => []]); exit(); }

$stmt = $db->prepare("SELECT bpi.id, bpi.descripcion, bpi.cantidad, bpi.marca, bpi.modelo
                      FROM bom_plantilla_items bpi
                      WHERE bpi.plantilla_id = ?
                      ORDER BY bpi.id");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['items' => $items]);
