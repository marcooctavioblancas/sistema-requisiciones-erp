<?php
/**
 * Guarda (crea o actualiza) los datos de Etapa 2 del Protocolo FAT de una Orden de Producción:
 * los campos de actuador/válvula (editados a mano tras la mini-IA de api/analizar_fat_datos.php)
 * y cuál pruebas_fat se usa para la Etapa 5.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/fat_protocolo.php';

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
if (!$orden_produccion_id) {
    echo json_encode(['success' => false, 'error' => 'orden_produccion_id requerido']);
    exit;
}

$db = getDB();
$op = $db->prepare("SELECT id, proyecto_id FROM ordenes_produccion WHERE id = ?");
$op->execute([$orden_produccion_id]);
$op_row = $op->fetch();
if (!$op_row) {
    echo json_encode(['success' => false, 'error' => 'Orden de Producción no encontrada']);
    exit;
}

$pruebas_fat_id = intval($_POST['pruebas_fat_id'] ?? 0) ?: null;
if ($pruebas_fat_id) {
    $chk = $db->prepare("SELECT id FROM pruebas_fat WHERE id = ? AND proyecto_id = ?");
    $chk->execute([$pruebas_fat_id, $op_row['proyecto_id']]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'La Prueba FAT indicada no pertenece a este proyecto']);
        exit;
    }
}

$entrada = json_decode($_POST['datos_generales'] ?? '{}', true) ?: [];
$datos_generales = fatProtocoloNormalizarDatosGenerales($entrada);

$stmt = $db->prepare("INSERT INTO fat_protocolos (orden_produccion_id, pruebas_fat_id, datos_generales, creado_por, actualizado_por, fecha_actualizacion)
                       VALUES (?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE pruebas_fat_id = VALUES(pruebas_fat_id), datos_generales = VALUES(datos_generales),
                           actualizado_por = VALUES(actualizado_por), fecha_actualizacion = NOW()");
$stmt->execute([
    $orden_produccion_id,
    $pruebas_fat_id,
    json_encode($datos_generales, JSON_UNESCAPED_UNICODE),
    $_SESSION['user_id'] ?? null,
    $_SESSION['user_id'] ?? null,
]);

echo json_encode(['success' => true, 'datos_generales' => $datos_generales]);
