<?php
/**
 * Recibe un PDF, lo guarda en uploads/documentos_ref/ e inserta registro en
 * documentos_referencia con estado='pendiente'. Retorna { success, id, nombre }.
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('proyectos') && !isAdmin())) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'Método no permitido']); exit; }

$nombre      = trim($_POST['nombre']      ?? '');
$tipo        = $_POST['tipo']             ?? 'norma';
$idioma      = $_POST['idioma']           ?? 'es';
$descripcion = trim($_POST['descripcion'] ?? '');
$proyecto_id = !empty($_POST['proyecto_id']) ? intval($_POST['proyecto_id']) : null;

if (empty($nombre)) { echo json_encode(['success'=>false,'error'=>'El nombre es requerido']); exit; }
if (!in_array($tipo,   ['norma','especificacion','otro'])) { echo json_encode(['success'=>false,'error'=>'Tipo inválido']); exit; }
if (!in_array($idioma, ['es','en','otro']))                { echo json_encode(['success'=>false,'error'=>'Idioma inválido']); exit; }

if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'error'=>'No se recibió archivo o hubo error al subir']); exit;
}

$file = $_FILES['archivo'];

if (!validarTipoArchivoReal($file['tmp_name'], ['application/pdf' => 'pdf'])) {
    echo json_encode(['success'=>false,'error'=>'Solo se permiten archivos PDF']); exit;
}
if ($file['size'] > 50 * 1024 * 1024) {
    echo json_encode(['success'=>false,'error'=>'El archivo es demasiado grande (máx 50 MB)']); exit;
}

$uploadDir = UPLOAD_PATH . 'documentos_ref/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$fileName   = time() . '_' . uniqid() . '.pdf';
$targetPath = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success'=>false,'error'=>'Error al guardar el archivo']); exit;
}

// Páginas se obtendrán durante la extracción de texto (extraer_texto_doc.php)
$paginas = null;

$db   = getDB();
$stmt = $db->prepare("INSERT INTO documentos_referencia
    (proyecto_id, nombre, tipo, idioma, descripcion, archivo_ruta, paginas, estado, subido_por)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)");
$stmt->execute([
    $proyecto_id,
    $nombre,
    $tipo,
    $idioma,
    $descripcion ?: null,
    'documentos_ref/' . $fileName,
    $paginas,
    $_SESSION['user_id'],
]);

echo json_encode(['success'=>true, 'id'=>(int)$db->lastInsertId(), 'paginas'=>$paginas]);
