<?php
/**
 * Sube el documento escaneado/firmado en papel de un departamento de una Auditoría SGI --
 * mismo patrón que api/fat_protocolo_subir_archivo.php. Un archivo por departamento; volver a
 * subir reemplaza al anterior.
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('calidad') && !isAdmin())) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$depto_id = intval($_POST['depto_id'] ?? 0);
if (!$depto_id) {
    echo json_encode(['success' => false, 'error' => 'depto_id requerido']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, archivo_escaneado_ruta FROM auditorias_sgi_departamentos WHERE id = ?");
$stmt->execute([$depto_id]);
$row = $stmt->fetch();
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'No encontrado']);
    exit;
}

if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No se recibió el archivo']);
    exit;
}

$ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
$permitidas = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];
if (!in_array($ext, $permitidas)) {
    echo json_encode(['success' => false, 'error' => 'Formato no válido. Usa PDF, Word (doc/docx) o imagen (png/jpg).']);
    exit;
}
if ($_FILES['archivo']['size'] > 20 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'El archivo pesa más de 20MB']);
    exit;
}

$dir = __DIR__ . '/../uploads/auditoria_sgi/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$fname = 'auditoria_' . $depto_id . '_' . time() . '.' . $ext;
if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $dir . $fname)) {
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar el archivo']);
    exit;
}
$ruta_relativa = 'auditoria_sgi/' . $fname;

if ($row['archivo_escaneado_ruta']) {
    $rutaAnteriorAbs = __DIR__ . '/../uploads/' . $row['archivo_escaneado_ruta'];
    if (file_exists($rutaAnteriorAbs)) @unlink($rutaAnteriorAbs);
}

$db->prepare("UPDATE auditorias_sgi_departamentos SET archivo_escaneado_ruta=?, archivo_escaneado_nombre=?, subido_por=?, fecha_subida=NOW() WHERE id=?")
   ->execute([$ruta_relativa, $_FILES['archivo']['name'], $_SESSION['user_id'] ?? null, $depto_id]);

echo json_encode(['success' => true, 'archivo_url' => 'uploads/' . $ruta_relativa, 'archivo_nombre' => $_FILES['archivo']['name']]);
