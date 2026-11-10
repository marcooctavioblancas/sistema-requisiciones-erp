<?php
/**
 * Guarda los pasos actuales del constructor de Etapa 5 (fat_protocolo.php) como una plantilla
 * reutilizable de fat_plantillas -- compartidas por toda Ingeniería, no ligadas a ningún proyecto.
 * Mismo criterio que ingenieria.php (accion=crear_fat_plantilla), como endpoint AJAX propio.
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

$nombre         = sanitize($_POST['nombre'] ?? '');
$checklist_json = fatSanitizarPasosJson($_POST['pasos_json'] ?? '');

if (!$nombre || $checklist_json === null) {
    echo json_encode(['success' => false, 'error' => 'Nombre y al menos una prueba con título son requeridos']);
    exit;
}

$db = getDB();
$db->prepare("INSERT INTO fat_plantillas (nombre, checklist_json, creado_por) VALUES (?,?,?)")
   ->execute([$nombre, $checklist_json, $_SESSION['user_id']]);

echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId(), 'nombre' => $nombre, 'checklist_json' => $checklist_json]);
