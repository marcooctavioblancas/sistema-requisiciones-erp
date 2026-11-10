<?php
/**
 * Elimina una plantilla de Etapa 5 (fat_plantillas) desde el constructor de fat_protocolo.php.
 * No afecta las pruebas FAT ya creadas con ella (checklist_json ya quedó copiado a pruebas_fat).
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !(isAdmin() || hasAcceso('ingenieria') || hasAcceso('manufactura') || hasAcceso('proyectos') || hasAcceso('calidad'))) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$plantilla_id = intval($_POST['plantilla_id'] ?? 0);
if (!$plantilla_id) {
    echo json_encode(['success' => false, 'error' => 'plantilla_id requerido']);
    exit;
}

$db = getDB();
$db->prepare("DELETE FROM fat_plantillas WHERE id = ?")->execute([$plantilla_id]);

echo json_encode(['success' => true]);
