<?php
/**
 * Guarda la ESTRUCTURA de la Etapa 5 (título/instrucciones/campos de cada paso) desde la pestaña
 * "Generar Etapa 5" del Protocolo FAT -- el mismo constructor de pasos que ya existe en
 * ingenieria.php (accion=crear_fat), pero en modo edición sobre un pruebas_fat ya creado.
 * Solo estructura -- nunca valores capturados: el documento se imprime y se llena a mano durante
 * la prueba física, así que "resultado"/"valor" siempre quedan vacíos (fatSanitizarPasosJson sin
 * conservarValores).
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

$fat_id               = intval($_POST['fat_id'] ?? 0);
$orden_produccion_id  = intval($_POST['orden_produccion_id'] ?? 0);
$checklist_json       = fatSanitizarPasosJson($_POST['pasos_json'] ?? '');

if (!$fat_id || !$orden_produccion_id || $checklist_json === null) {
    echo json_encode(['success' => false, 'error' => 'Agrega al menos una prueba con título antes de guardar.']);
    exit;
}

$db = getDB();

// La Prueba FAT debe pertenecer al proyecto de la Orden de Producción que se está viendo --
// si no, cualquiera con acceso a esta pantalla podría pisar el checklist de otro proyecto
// mandando un fat_id ajeno (mismo criterio que ya usa fat_protocolo_datos.php).
$chk = $db->prepare(
    "SELECT pf.id FROM pruebas_fat pf
     JOIN ordenes_produccion op ON op.proyecto_id = pf.proyecto_id
     WHERE pf.id = ? AND op.id = ?"
);
$chk->execute([$fat_id, $orden_produccion_id]);
if (!$chk->fetch()) {
    echo json_encode(['success' => false, 'error' => 'La Prueba FAT indicada no pertenece a esta Orden de Producción']);
    exit;
}

$db->prepare("UPDATE pruebas_fat SET checklist = ? WHERE id = ?")->execute([$checklist_json, $fat_id]);

echo json_encode(['success' => true]);
