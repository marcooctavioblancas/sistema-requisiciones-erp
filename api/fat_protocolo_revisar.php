<?php
/**
 * Aprueba o rechaza el Protocolo FAT firmado en papel que subió Ingeniería/Producción
 * (archivo_subido_* de fat_protocolos, ver api/fat_protocolo_subir_archivo.php) -- SIN firma
 * digital, las firmas son solo en físico (2026-08-28). Producción revisa primero (da el pase
 * antes de la prueba física); una vez que Producción aprueba, el documento final queda visible
 * en solo lectura para Proyectos y Calidad (proyecto.php/calidad.php), quienes también aprueban
 * o rechazan de forma independiente (2026-09-06, Fase 57) -- cada área tiene su propio juego de
 * columnas revision_<area>_estado/por/fecha/motivo (revision_* sin prefijo = Producción).
 * "Cierre" real del FAT (pruebas_fat.fecha_realizada, el dato que pide el indicador DOC-GEN-FO-15
 * de Proyectos) = hasta que las 3 áreas ya aprobaron, no antes.
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// depto: a qué juego de columnas corresponde esta revisión. 'produccion' (default, columnas sin
// prefijo) mantiene compatible al llamador ya existente en produccion.php que nunca manda depto.
$depto = sanitize($_POST['depto'] ?? 'produccion');
$deptos = [
    'produccion' => ['acceso' => 'manufactura', 'label' => 'Producción'],
    'calidad'    => ['acceso' => 'calidad',     'label' => 'Calidad'],
    'proyectos'  => ['acceso' => 'proyectos',   'label' => 'Proyectos'],
];
if (!isset($deptos[$depto])) {
    echo json_encode(['success' => false, 'error' => 'Departamento inválido']);
    exit;
}
if (!isAdmin() && !hasAcceso($deptos[$depto]['acceso'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}
// Consultas fijas por departamento (no interpolar $depto/$col en el SQL) -- $depto ya está
// validado contra $deptos arriba, pero un nombre de columna armado con string interpolation
// sigue leyendo raro para cualquier escáner/revisor futuro; esto dice explícitamente cuáles son
// las únicas 6 sentencias posibles.
$sqlAprobar = [
    'produccion' => "UPDATE fat_protocolos SET revision_estado = 'aprobado', revision_por = ?, revision_fecha = NOW(), revision_motivo = NULL WHERE orden_produccion_id = ?",
    'calidad'    => "UPDATE fat_protocolos SET revision_calidad_estado = 'aprobado', revision_calidad_por = ?, revision_calidad_fecha = NOW(), revision_calidad_motivo = NULL WHERE orden_produccion_id = ?",
    'proyectos'  => "UPDATE fat_protocolos SET revision_proyectos_estado = 'aprobado', revision_proyectos_por = ?, revision_proyectos_fecha = NOW(), revision_proyectos_motivo = NULL WHERE orden_produccion_id = ?",
];
$sqlRechazar = [
    'produccion' => "UPDATE fat_protocolos SET revision_estado = 'rechazado', revision_por = ?, revision_fecha = NOW(), revision_motivo = ? WHERE orden_produccion_id = ?",
    'calidad'    => "UPDATE fat_protocolos SET revision_calidad_estado = 'rechazado', revision_calidad_por = ?, revision_calidad_fecha = NOW(), revision_calidad_motivo = ? WHERE orden_produccion_id = ?",
    'proyectos'  => "UPDATE fat_protocolos SET revision_proyectos_estado = 'rechazado', revision_proyectos_por = ?, revision_proyectos_fecha = NOW(), revision_proyectos_motivo = ? WHERE orden_produccion_id = ?",
];

$orden_produccion_id = intval($_POST['orden_produccion_id'] ?? 0);
$accion = sanitize($_POST['accion'] ?? '');
if (!$orden_produccion_id || !in_array($accion, ['aprobar', 'rechazar'])) {
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT fp.*, op.numero_proyecto FROM fat_protocolos fp
                       JOIN ordenes_produccion op ON op.id = fp.orden_produccion_id
                       WHERE fp.orden_produccion_id = ?");
$stmt->execute([$orden_produccion_id]);
$protocolo = $stmt->fetch();
if (!$protocolo) {
    echo json_encode(['success' => false, 'error' => 'Protocolo FAT no encontrado']);
    exit;
}
if (empty($protocolo['archivo_subido_ruta'])) {
    echo json_encode(['success' => false, 'error' => 'Todavía no hay un archivo subido para revisar']);
    exit;
}
// Proyectos/Calidad solo revisan el documento final, YA aprobado por Producción -- antes de eso
// ni siquiera lo ven (mismo gate que usan proyecto.php/calidad.php para mostrarlo en solo lectura).
if ($depto !== 'produccion' && $protocolo['revision_estado'] !== 'aprobado') {
    echo json_encode(['success' => false, 'error' => 'Todavía no se puede revisar -- espera a que Producción apruebe el documento primero.']);
    exit;
}

// Recalcula pruebas_fat.fecha_realizada ("cierre" real del FAT) solo cuando las 3 áreas ya
// aprobaron -- se llama después de cada aprobación, así que basta con checar el estado actual.
function fatProtocoloRecalcularCierre(PDO $db, array $protocolo): void {
    if (empty($protocolo['pruebas_fat_id'])) return;
    $stmt = $db->prepare("SELECT revision_estado, revision_calidad_estado, revision_proyectos_estado FROM fat_protocolos WHERE orden_produccion_id = ?");
    $stmt->execute([$protocolo['orden_produccion_id']]);
    $r = $stmt->fetch();
    if (!$r) return;
    $todasAprobadas = $r['revision_estado'] === 'aprobado'
        && $r['revision_calidad_estado'] === 'aprobado'
        && $r['revision_proyectos_estado'] === 'aprobado';
    if ($todasAprobadas) {
        $db->prepare("UPDATE pruebas_fat SET fecha_realizada = CURDATE() WHERE id = ?")
           ->execute([$protocolo['pruebas_fat_id']]);
    }
}

if ($accion === 'aprobar') {
    $db->prepare($sqlAprobar[$depto])
       ->execute([$_SESSION['user_id'], $orden_produccion_id]);

    fatProtocoloRecalcularCierre($db, $protocolo);

    // Producción notifica a Ingeniería (le da el pase para hacer la prueba física); Calidad y
    // Proyectos notifican a Producción, que es quien coordina el cierre del Protocolo FAT.
    $notifRol = $depto === 'produccion' ? 'ingenieria' : 'manufactura';
    notificarRoles([$notifRol], 'fat_protocolo_aprobado', "Protocolo FAT aprobado por {$deptos[$depto]['label']}",
        "{$deptos[$depto]['label']} aprobó el Protocolo FAT de \"{$protocolo['numero_proyecto']}\".",
        'fat_protocolo.php?id=' . $orden_produccion_id, $_SESSION['user_id']);

    echo json_encode(['success' => true]);
    exit;
}

// ── Rechazar ─────────────────────────────────────────────────────────────
$motivo = sanitize($_POST['motivo'] ?? '');
if (!$motivo) {
    echo json_encode(['success' => false, 'error' => 'Se requiere motivo de rechazo']);
    exit;
}

$db->prepare($sqlRechazar[$depto])
   ->execute([$_SESSION['user_id'], $motivo, $orden_produccion_id]);

$notifRol = $depto === 'produccion' ? 'ingenieria' : 'manufactura';
notificarRoles([$notifRol], 'fat_protocolo_rechazado', "Protocolo FAT rechazado por {$deptos[$depto]['label']} — corregir y volver a subir",
    "{$deptos[$depto]['label']} rechazó el Protocolo FAT de \"{$protocolo['numero_proyecto']}\": {$motivo}",
    'fat_protocolo.php?id=' . $orden_produccion_id, $_SESSION['user_id']);

echo json_encode(['success' => true]);
