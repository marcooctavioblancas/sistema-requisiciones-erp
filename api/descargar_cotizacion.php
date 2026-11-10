<?php
require_once '../includes/config.php';

if (!isLoggedIn() || (!hasAcceso('compras') && !isAdmin())) {
    http_response_code(403);
    exit;
}

$proyecto_id = intval($_GET['proyecto_id'] ?? 0);
$archivo     = basename($_GET['archivo'] ?? '');

if (!$proyecto_id || !$archivo || !preg_match('/^[0-9]+_[a-z0-9_]+\.pdf$/i', $archivo)) {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/../uploads/cotizaciones/' . $proyecto_id . '/' . $archivo;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $archivo . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
