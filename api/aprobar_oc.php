<?php
ob_start(); // captura cualquier output PHP antes del JSON
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || (!isAdmin() && !hasAcceso('gerente'))) {
    http_response_code(403);
    ob_clean(); echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    exit;
}

$orden_id = intval($_POST['orden_id'] ?? 0);
if (!$orden_id) {
    ob_clean(); echo json_encode(['ok' => false, 'error' => 'orden_id inválido']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT oc.id, oc.proyecto_id, oc.revision_proyectos_ok, r.proyecto_id AS req_proyecto_id
    FROM ordenes_compra oc
    LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
    WHERE oc.id = ? AND oc.estado = 'pendiente_aprobacion'
");
$stmt->execute([$orden_id]);
$oc_check = $stmt->fetch();
if (!$oc_check) {
    ob_clean(); echo json_encode(['ok' => false, 'error' => 'OC no encontrada o ya procesada']);
    exit;
}

// Hueco real encontrado y cerrado (2026-08-20): antes Gerencia podía aprobar una OC
// ligada a un proyecto sin que Proyectos hubiera revisado nunca la fecha de entrega
// -- no había ningún candado real, solo un resaltado visual en proyecto.php. Admin
// puede saltárselo (mismo criterio que el resto del sistema); OCs sin proyecto
// (requisiciones externas) no necesitan esto, a Proyectos no le incumben.
$oc_tiene_proyecto = !empty($oc_check['proyecto_id']) || !empty($oc_check['req_proyecto_id']);
if ($oc_tiene_proyecto && !$oc_check['revision_proyectos_ok'] && !isAdmin()) {
    ob_clean(); echo json_encode(['ok' => false, 'error' => 'Proyectos todavía no confirma que la fecha de entrega le sirve. Pídeles que la revisen antes de aprobar.']);
    exit;
}

$dir = __DIR__ . '/../uploads/firmas/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$firma_ruta = null;

if (!empty($_POST['firma_base64'])) {
    $data = $_POST['firma_base64'];
    if (preg_match('/^data:image\/\w+;base64,/', $data)) {
        $data = preg_replace('/^data:image\/\w+;base64,/', '', $data);
    }
    $decoded = base64_decode($data);
    if ($decoded) {
        $filename = $orden_id . '.png';
        file_put_contents($dir . $filename, $decoded);
        $firma_ruta = 'uploads/firmas/' . $filename;
    }
} elseif (!empty($_FILES['firma_archivo']['tmp_name']) && is_uploaded_file($_FILES['firma_archivo']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['firma_archivo']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', ''])) {
        $filename = $orden_id . '_firma.png';
        // move_uploaded_file directamente — canvas.toBlob() ya genera PNG válido, no se necesita GD
        if (move_uploaded_file($_FILES['firma_archivo']['tmp_name'], $dir . $filename)) {
            $firma_ruta = 'uploads/firmas/' . $filename;
        }
    }
}

if (!$firma_ruta) {
    ob_clean(); echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la firma']);
    exit;
}

$db->prepare("UPDATE ordenes_compra SET estado='aprobada', aprobado_por=?, fecha_aprobacion=NOW(), firma_ruta=? WHERE id=?")
   ->execute([$_SESSION['user_id'], $firma_ruta, $orden_id]);

// Notificar a compras y manufactura que la OC fue aprobada
$stmt_oc = $db->prepare("SELECT oc.folio, oc.proyecto_id, r.proyecto as proy_nombre FROM ordenes_compra oc LEFT JOIN requisiciones r ON oc.requisicion_id = r.id WHERE oc.id = ?");
$stmt_oc->execute([$orden_id]);
$oc_data = $stmt_oc->fetch();
$folio_oc = $oc_data['folio'] ?? "OC-$orden_id";
$proy_nm  = $oc_data['proy_nombre'] ?? 'proyecto';

notificarRoles(
    ['compras'],
    'oc_aprobada',
    "OC Aprobada: $folio_oc",
    "La orden de compra $folio_oc del proyecto \"$proy_nm\" fue aprobada por gerencia.",
    'compras.php?seccion=proyectos_costos'
);

// Si el proyecto tiene planos de manufactura, notificar
if (!empty($oc_data['proyecto_id'])) {
    $chk = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND tipo_lista = 'plano'");
    $chk->execute([$oc_data['proyecto_id']]);
    if ($chk->fetchColumn() > 0) {
        notificarRoles(
            ['manufactura'],
            'oc_aprobada',
            "OC aprobada para planos: $proy_nm",
            "La orden de compra $folio_oc para el proyecto \"$proy_nm\" fue aprobada. Los materiales estarán próximamente disponibles.",
            'produccion.php?seccion=subproyectos'
        );
    }
}

ob_clean(); echo json_encode(['ok' => true]);
