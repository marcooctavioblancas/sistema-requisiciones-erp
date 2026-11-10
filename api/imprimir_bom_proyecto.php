<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Acceso denegado');
}
// A diferencia de vista_pdf_bom.php (Ingeniería/Proyectos, por boom_id), este
// endpoint es el BOM agregado por proyecto que usan Compras y Almacén para
// imprimir/cotizar — mismo dato que ya ven en sus propias pantallas.
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

$stmt2 = $db->prepare("
    SELECT ai.descripcion, ai.modelo, ai.marca, ai.cantidad_requerida,
           COALESCE(c.nombre, ai.categoria_nombre, 'Sin categoría') AS cat_nombre
    FROM almacen_items ai
    LEFT JOIN materiales m ON ai.material_id = m.id
    LEFT JOIN categorias c ON m.categoria_id = c.id
    WHERE ai.proyecto_id = ?
    ORDER BY cat_nombre, ai.fecha_agregado
");
$stmt2->execute([$proyecto_id]);
$items = $stmt2->fetchAll();

guardarSnapshotBom($db, $proyecto_id, $items, 'impresion', $_SESSION['user_id'] ?? null);

renderBomImprimibleProyecto($proyecto_nombre, $items);
