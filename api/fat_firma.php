<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit(); }

$db     = getDB();
$fat_id = intval($_POST['fat_id'] ?? 0);
$rol    = sanitize($_POST['rol'] ?? ''); // ingenieria | produccion | calidad | proyectos
$accion = sanitize($_POST['accion'] ?? 'firmar'); // firmar | rechazar

if (!$fat_id || !in_array($rol, ['ingenieria','produccion','calidad','proyectos'])) {
    echo json_encode(['ok'=>false,'error'=>'Parámetros inválidos']); exit();
}

// $rol lo manda el cliente y hasta ahora no se comparaba contra el departamento
// real del usuario -- cualquiera podía firmar/rechazar como si fuera de otro
// departamento. 'produccion' corresponde al acceso 'manufactura' (mismo mapeo
// que usa produccion.php para su propio guard).
$rol_a_acceso = ['ingenieria' => 'ingenieria', 'produccion' => 'manufactura', 'calidad' => 'calidad', 'proyectos' => 'proyectos'];
if (!isAdmin() && !hasAcceso($rol_a_acceso[$rol])) {
    echo json_encode(['ok'=>false,'error'=>'No tienes permiso para actuar como '.$rol]); exit();
}

$fat = $db->prepare("SELECT * FROM pruebas_fat WHERE id = ?")->execute([$fat_id])
    ? $db->prepare("SELECT * FROM pruebas_fat WHERE id = ?")->execute([$fat_id]) : null;
$stmt = $db->prepare("SELECT * FROM pruebas_fat WHERE id = ?");
$stmt->execute([$fat_id]);
$fat = $stmt->fetch();

if (!$fat) { echo json_encode(['ok'=>false,'error'=>'Prueba FAT no encontrada']); exit(); }

// ── RECHAZAR ────────────────────────────────────────────────────────────────
if ($accion === 'rechazar') {
    $motivo = sanitize($_POST['motivo'] ?? '');
    if (!$motivo) { echo json_encode(['ok'=>false,'error'=>'Se requiere motivo de rechazo']); exit(); }
    $db->prepare("UPDATE pruebas_fat SET estado='borrador', descripcion=CONCAT(COALESCE(descripcion,''), '\n[Rechazado por ".ucfirst($rol).": ', ?, ']') WHERE id=?")
       ->execute([$motivo, $fat_id]);
    echo json_encode(['ok'=>true]); exit();
}

// ── FIRMAR ───────────────────────────────────────────────────────────────────
// Validar que le toca firmar a este rol
$orden_firmas = ['ingenieria'=>'pendiente_ingenieria','produccion'=>'pendiente_produccion','calidad'=>'pendiente_calidad','proyectos'=>'pendiente_proyectos'];
if ($fat['estado'] !== $orden_firmas[$rol]) {
    echo json_encode(['ok'=>false,'error'=>'No es el turno de este departamento para firmar']); exit();
}

// Guardar firma
$firma_data = null;
if (!empty($_FILES['firma_archivo']['tmp_name'])) {
    $ext   = strtolower(pathinfo($_FILES['firma_archivo']['name'], PATHINFO_EXTENSION));
    $allow = ['png','jpg','jpeg','gif'];
    if (!in_array($ext, $allow)) { echo json_encode(['ok'=>false,'error'=>'Formato de firma no válido']); exit(); }
    $dir  = __DIR__ . '/../uploads/firmas_fat/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = 'fat_' . $fat_id . '_' . $rol . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['firma_archivo']['tmp_name'], $dir . $fname)) {
        echo json_encode(['ok'=>false,'error'=>'No se pudo guardar la firma']); exit();
    }
    $firma_data = 'firmas_fat/' . $fname;
}

if (!$firma_data) { echo json_encode(['ok'=>false,'error'=>'No se recibió la firma']); exit(); }

// Determinar siguiente estado -- orden: Ingeniería -> Producción -> Proyectos -> Calidad -> completado.
$siguiente = [
    'ingenieria' => 'pendiente_produccion',
    'produccion' => 'pendiente_proyectos',
    'proyectos'  => 'pendiente_calidad',
    'calidad'    => 'completado',
];
$nuevo_estado = $siguiente[$rol];

$db->prepare("UPDATE pruebas_fat SET
    firma_{$rol}_ruta = ?,
    firma_{$rol}_por  = ?,
    firma_{$rol}_fecha = NOW(),
    estado = ?
    WHERE id = ?")
   ->execute([$firma_data, $_SESSION['user_id'], $nuevo_estado, $fat_id]);

// Notificar al siguiente departamento
$notifs = [
    'ingenieria' => ['manufactura', 'fat_firma', 'FAT pendiente de firma: Producción', "La prueba FAT \"{$fat['titulo']}\" fue firmada por Ingeniería. Requiere desarrollo/firma de Producción.", 'produccion.php?seccion=fat'],
    'produccion' => ['proyectos',  'fat_firma', 'FAT pendiente de firma: Proyectos',  "La prueba FAT \"{$fat['titulo']}\" fue firmada por Producción. Requiere firma de Proyectos.", 'proyecto.php?seccion=fat'],
    'proyectos'  => ['calidad',    'fat_firma', 'FAT pendiente de firma: Calidad',    "La prueba FAT \"{$fat['titulo']}\" fue firmada por Proyectos. Requiere firma de Calidad.", 'calidad.php?seccion=fat'],
    'calidad'    => [null, null, null, null, null],
];
if ($notifs[$rol][0]) {
    notificarRoles([$notifs[$rol][0]], $notifs[$rol][1], $notifs[$rol][2], $notifs[$rol][3], $notifs[$rol][4], $_SESSION['user_id']);
}

echo json_encode(['ok'=>true,'nuevo_estado'=>$nuevo_estado]);
