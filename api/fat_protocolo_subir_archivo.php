<?php
/**
 * Sube el FAT firmado en papel (escaneo/foto, o el mismo Word/PDF ya firmado digitalmente) como
 * evidencia -- pestaña "Subir FAT" de fat_protocolo.php. Un archivo por Orden de Producción;
 * volver a subir reemplaza al anterior (y borra el archivo viejo del disco).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/fat_protocolo.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
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
$op = $db->prepare("SELECT id, numero_proyecto FROM ordenes_produccion WHERE id = ?");
$op->execute([$orden_produccion_id]);
$ordenProd = $op->fetch();
if (!$ordenProd) {
    echo json_encode(['success' => false, 'error' => 'Orden de Producción no encontrada']);
    exit;
}

// Flujo (2026-09-17, pedido explícito del usuario -- reemplaza el de 2026-08-30): ya no hay
// aprobar/rechazar de por medio. Ingeniería sube el documento generado; Producción y Proyectos
// solo lo ven en modo lectura (produccion.php/proyecto.php). Cuando ya se hizo la prueba física,
// Calidad sube el documento realmente firmado -- eso reemplaza el de Ingeniería y queda fijo
// (revision_estado se reutiliza como bandera de "ya es el firmado final", sin volver a mostrar
// aprobar/rechazar en ningún lado). Antes de eso, solo Ingeniería puede subir/reemplazar.
//
// $depto viene explícito del cliente (fat_protocolo.php siempre manda 'ingenieria',
// calidad.php siempre manda 'calidad') -- NO se puede inferir solo de hasAcceso(), porque
// hasAcceso() devuelve true para CUALQUIER string cuando el usuario es admin (ver
// includes/config.php). Con la lógica vieja, un admin subiendo desde la pestaña de Ingeniería
// quedaba tratado como si fuera Calidad subiendo el firmado, marcando revision_estado='aprobado'
// desde la primera subida (bug real reportado 2026-09-20).
$depto = sanitize($_POST['depto'] ?? '');
if (!in_array($depto, ['ingenieria', 'calidad'], true)) {
    echo json_encode(['success' => false, 'error' => 'Parámetro depto inválido']);
    exit;
}
if ($depto === 'ingenieria' && !isAdmin() && !hasAcceso('ingenieria')) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}
if ($depto === 'calidad' && !isAdmin() && !hasAcceso('calidad')) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$estadoActual = $db->prepare("SELECT revision_estado, pruebas_fat_id FROM fat_protocolos WHERE orden_produccion_id = ?");
$estadoActual->execute([$orden_produccion_id]);
$fpActual = $estadoActual->fetch();
$yaFirmado = ($fpActual['revision_estado'] ?? null) === 'aprobado';

if ($depto === 'ingenieria' && $yaFirmado) {
    echo json_encode(['success' => false, 'error' => 'Este Protocolo FAT ya tiene el documento firmado subido por Calidad -- solo Calidad puede reemplazarlo.']);
    exit;
}
$subeCalidad = ($depto === 'calidad');

if (empty($_FILES['fat_archivo']['tmp_name']) || $_FILES['fat_archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No se recibió el archivo']);
    exit;
}

// PDF/Word (documento escaneado o el mismo Word/PDF firmado) e imagen (foto del FAT firmado) --
// pedido explícito del usuario (2026-08-28): no limitar solo a imagen, una foto puede salir
// chueca/ilegible y el escaneo en PDF o el Word firmado es preferible cuando se tiene a la mano.
$ext = strtolower(pathinfo($_FILES['fat_archivo']['name'], PATHINFO_EXTENSION));
$permitidas = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];
if (!in_array($ext, $permitidas)) {
    echo json_encode(['success' => false, 'error' => 'Formato no válido. Usa PDF, Word (doc/docx) o imagen (png/jpg).']);
    exit;
}

// 20MB -- generoso para un escaneo/foto en buena resolución, sin dejar la puerta abierta a
// cualquier tamaño.
if ($_FILES['fat_archivo']['size'] > 20 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'El archivo pesa más de 20MB']);
    exit;
}

$dir = __DIR__ . '/../uploads/fat_protocolo_firmado/';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$fname = 'fat_' . $orden_produccion_id . '_' . time() . '.' . $ext;
if (!move_uploaded_file($_FILES['fat_archivo']['tmp_name'], $dir . $fname)) {
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar el archivo']);
    exit;
}
$ruta_relativa = 'fat_protocolo_firmado/' . $fname;

// Borra el archivo viejo (si había uno) al reemplazarlo -- no dejar huérfanos en uploads/.
$anterior = $db->prepare("SELECT archivo_subido_ruta FROM fat_protocolos WHERE orden_produccion_id = ?");
$anterior->execute([$orden_produccion_id]);
$rutaAnterior = $anterior->fetchColumn();
if ($rutaAnterior) {
    $rutaAnteriorAbs = __DIR__ . '/../uploads/' . $rutaAnterior;
    if (file_exists($rutaAnteriorAbs)) @unlink($rutaAnteriorAbs);
}

// revision_estado se reutiliza como bandera simple: 'aprobado' = ya es el documento firmado
// final que subió Calidad (candado de la sección de arriba), 'pendiente' = todavía es el
// borrador de Ingeniería.
$nuevoEstado = $subeCalidad ? 'aprobado' : 'pendiente';
$stmt = $db->prepare("INSERT INTO fat_protocolos (orden_produccion_id, archivo_subido_ruta, archivo_subido_nombre, archivo_subido_por, archivo_subido_fecha, creado_por, revision_estado, revision_por, revision_fecha)
                       VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE archivo_subido_ruta = VALUES(archivo_subido_ruta), archivo_subido_nombre = VALUES(archivo_subido_nombre),
                           archivo_subido_por = VALUES(archivo_subido_por), archivo_subido_fecha = NOW(),
                           revision_estado = VALUES(revision_estado), revision_por = VALUES(revision_por), revision_fecha = NOW()");
$stmt->execute([
    $orden_produccion_id,
    $ruta_relativa,
    $_FILES['fat_archivo']['name'],
    $_SESSION['user_id'] ?? null,
    $_SESSION['user_id'] ?? null,
    $nuevoEstado,
    $_SESSION['user_id'] ?? null,
]);

$numProy = $ordenProd['numero_proyecto'] ?? ('OP ' . $orden_produccion_id);
if ($subeCalidad) {
    // Cierre real del FAT (mismo dato que usa el indicador DOC-GEN-FO-15) -- antes se marcaba
    // hasta que Producción/Calidad/Proyectos aprobaban las 3 por separado; ahora que ya no hay
    // aprobar/rechazar, el cierre es cuando Calidad sube el documento realmente firmado.
    $pruebasFatId = $fpActual['pruebas_fat_id'] ?? null;
    if ($pruebasFatId) {
        $db->prepare("UPDATE pruebas_fat SET fecha_realizada = CURDATE() WHERE id = ?")->execute([$pruebasFatId]);
    }
    notificarRoles(['ingenieria', 'manufactura', 'proyectos'], 'fat_protocolo_firmado', "Protocolo FAT firmado disponible: $numProy",
        "Calidad subió el documento firmado del Protocolo FAT de \"$numProy\".",
        'fat_protocolo.php?id=' . $orden_produccion_id, $_SESSION['user_id']);
} else {
    notificarRoles(['calidad'], 'fat_protocolo_generado', "Protocolo FAT listo para firmar: $numProy",
        "Ingeniería generó el Protocolo FAT de \"$numProy\" -- ya se puede subir el documento firmado.",
        'calidad.php?seccion=fat', $_SESSION['user_id']);
}

echo json_encode(['success' => true, 'archivo_url' => 'uploads/' . $ruta_relativa, 'archivo_nombre' => $_FILES['fat_archivo']['name']]);
