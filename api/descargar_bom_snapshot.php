<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Acceso denegado');
}
if (!isAdmin() && !hasAcceso('ingenieria') && !hasAcceso('proyectos') && !hasAcceso('almacen') && !hasAcceso('compras')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$proyecto_id = intval($_GET['proyecto_id'] ?? 0);
if (!$proyecto_id) {
    http_response_code(400);
    exit('proyecto_id requerido');
}

$db = getDB();

$stmt = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
$stmt->execute([$proyecto_id]);
$proyecto_nombre = $stmt->fetchColumn();
if ($proyecto_nombre === false) {
    http_response_code(404);
    exit('Proyecto no encontrado');
}

// Snapshot congelado más reciente (manual o automático al finalizar) — no
// depende de que almacen_items siga existiendo, así que sigue disponible
// aunque el proyecto ya esté finalizado y sus materiales hayan cambiado.
$stmt2 = $db->prepare("SELECT snapshot_json, fecha_creacion FROM bom_snapshots WHERE proyecto_id = ? ORDER BY fecha_creacion DESC LIMIT 1");
$stmt2->execute([$proyecto_id]);
$snap = $stmt2->fetch();
if (!$snap) {
    http_response_code(404);
    exit('Este proyecto todavía no tiene ningún BOM guardado (imprime uno primero, o finaliza el proyecto en Almacén).');
}

$items = json_decode($snap['snapshot_json'], true) ?: [];
$fecha_gen = date('d/m/Y H:i', strtotime($snap['fecha_creacion']));

renderBomImprimibleProyecto($proyecto_nombre, $items, "Generado el $fecha_gen (versión guardada, no en vivo)");
