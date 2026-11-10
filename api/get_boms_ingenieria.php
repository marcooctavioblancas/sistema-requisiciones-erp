<?php
/**
 * GET ?action=boms&proyecto_id=N  → lista BOMs de ingeniería del proyecto
 * GET ?action=items&boom_id=N     → ítems de un BOM específico
 */
require_once '../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }
// Mismo criterio que vista_pdf_bom.php/exportar_bom.php: Ingeniería/Admin, más Manufactura
// (produccion.php lo usa para "Ver Materiales" de un plano/subproyecto).
if (!isAdmin() && !hasAcceso('ingenieria') && !hasAcceso('manufactura')) {
    echo json_encode(['success'=>false,'error'=>'No autorizado']); exit;
}

$action      = $_GET['action']      ?? 'boms';
$proyecto_id = intval($_GET['proyecto_id'] ?? 0);
$boom_id     = intval($_GET['boom_id']     ?? 0);
$db          = getDB();

if ($action === 'boms') {
    if (!$proyecto_id) { echo json_encode(['success'=>false,'error'=>'proyecto_id requerido']); exit; }
    $stmt = $db->prepare(
        "SELECT id, nombre, num_equipos, tipo_lista, fecha_creacion
         FROM boom_listas
         WHERE proyecto_id = ? AND tipo_lista IN ('principal','adicional') AND guardado = 1
         ORDER BY tipo_lista ASC, nombre ASC"
    );
    $stmt->execute([$proyecto_id]);
    echo json_encode(['success'=>true, 'data'=>$stmt->fetchAll()]);
    exit;
}

if ($action === 'items') {
    if (!$boom_id) { echo json_encode(['success'=>false,'error'=>'boom_id requerido']); exit; }
    $stmt = $db->prepare(
        "SELECT bi.descripcion, bi.cantidad, bi.unidad_medida, bi.marca, bi.modelo, bi.material_id,
                bl.num_equipos,
                COALESCE(m.stock_disponible, 0) AS stock_disponible,
                c.nombre AS categoria_nombre
         FROM boom_items bi
         JOIN boom_listas bl ON bi.boom_id = bl.id
         LEFT JOIN materiales m ON m.id = bi.material_id
         LEFT JOIN categorias c ON c.id = m.categoria_id
         WHERE bi.boom_id = ? AND bi.guardado = 1
         ORDER BY bi.id ASC"
    );
    $stmt->execute([$boom_id]);
    echo json_encode(['success'=>true, 'data'=>$stmt->fetchAll()]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Acción no reconocida']);
