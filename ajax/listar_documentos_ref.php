<?php
/**
 * Lista documentos de referencia: globales (proyecto_id IS NULL)
 * y del proyecto indicado (proyecto_id = ?).
 * GET params: proyecto_id (optional), solo_procesados (0|1)
 * Retorna { success, global: [...], proyecto: [...] }
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('proyectos') && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'No autorizado']);
    exit;
}

$proyecto_id    = !empty($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : null;
$solo_procesados = ($_GET['solo_procesados'] ?? '0') === '1';

$db           = getDB();
$where_estado = $solo_procesados ? "AND estado = 'procesado'" : '';

$global = $db->query(
    "SELECT id, nombre, tipo, idioma, descripcion, paginas, estado, archivo_ruta, fecha_creacion
     FROM documentos_referencia
     WHERE proyecto_id IS NULL $where_estado
     ORDER BY nombre ASC"
)->fetchAll();

$proyecto = [];
if ($proyecto_id) {
    $s = $db->prepare(
        "SELECT id, nombre, tipo, idioma, descripcion, paginas, estado, archivo_ruta, fecha_creacion
         FROM documentos_referencia
         WHERE proyecto_id = ? $where_estado
         ORDER BY fecha_creacion DESC"
    );
    $s->execute([$proyecto_id]);
    $proyecto = $s->fetchAll();
}

echo json_encode(['success'=>true, 'global'=>$global, 'proyecto'=>$proyecto]);
