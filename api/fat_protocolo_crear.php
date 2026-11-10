<?php
/**
 * Crea una nueva Prueba FAT (pruebas_fat) para el proyecto de una Orden de Producción, desde el
 * formulario "Crear FAT" de fat_protocolo.php -- Ingeniería genera aquí mismo el documento
 * (título/fechas/descripción + pasos de Etapa 5), sin salir de esta pantalla. Mismo criterio de
 * creación que usaba ingenieria.php?seccion=fat_nueva (accion=crear_fat).
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

$orden_produccion_id = intval($_POST['orden_produccion_id'] ?? 0);
$db = getDB();
$op = $db->prepare("SELECT id, proyecto_id FROM ordenes_produccion WHERE id = ?");
$op->execute([$orden_produccion_id]);
$op_row = $op->fetch();
if (!$op_row || !$op_row['proyecto_id']) {
    echo json_encode(['success' => false, 'error' => 'Orden de Producción no encontrada']);
    exit;
}

$fat_titulo = sanitize($_POST['fat_titulo'] ?? '');
$fat_desc   = sanitize($_POST['fat_descripcion'] ?? '');
$fat_f_prog = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : null;
$fat_f_real = !empty($_POST['fecha_realizada'])  ? $_POST['fecha_realizada']  : null;
if (!$fat_titulo) {
    echo json_encode(['success' => false, 'error' => 'El título es requerido']);
    exit;
}

$checklist_json = fatSanitizarPasosJson($_POST['pasos_json'] ?? '');
if ($checklist_json === null) {
    echo json_encode(['success' => false, 'error' => 'Agrega al menos una prueba con título antes de crear la FAT.']);
    exit;
}

// Arranca en pendiente_ingenieria -- Ingeniería firma primero (define el alcance de la prueba),
// luego Producción la desarrolla y firma, después Proyectos, y al último Calidad.
$fat_folio = generarFolioFAT();
$db->prepare("INSERT INTO pruebas_fat (proyecto_id, folio, titulo, descripcion, checklist, fecha_programada, fecha_realizada, estado, creado_por, fecha_creacion)
              VALUES (?,?,?,?,?,?,?,'pendiente_ingenieria',?,NOW())")
   ->execute([$op_row['proyecto_id'], $fat_folio, $fat_titulo, $fat_desc ?: null, $checklist_json, $fat_f_prog, $fat_f_real, $_SESSION['user_id']]);

echo json_encode(['success' => true, 'fat_id' => (int)$db->lastInsertId(), 'folio' => $fat_folio]);
