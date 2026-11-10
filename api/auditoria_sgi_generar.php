<?php
/**
 * Genera y descarga el Word de un departamento de una Auditoría SGI -- copia la plantilla real
 * y sustituye No. de auditoría / Fecha (ver includes/auditoria_sgi.php).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auditoria_sgi.php';

if (!isLoggedIn() || (!hasAcceso('calidad') && !isAdmin())) {
    http_response_code(403);
    exit('No autorizado');
}

$depto_id = intval($_GET['depto_id'] ?? 0);
if (!$depto_id) {
    http_response_code(400);
    exit('depto_id requerido');
}

$db = getDB();
$stmt = $db->prepare("SELECT ad.departamento, a.numero, a.fecha
    FROM auditorias_sgi_departamentos ad
    JOIN auditorias_sgi a ON a.id = ad.auditoria_id
    WHERE ad.id = ?");
$stmt->execute([$depto_id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('No encontrado');
}

auditoriaSgiGenerarYEnviar($row['departamento'], $row['numero'], date('d/m/Y', strtotime($row['fecha'])));
