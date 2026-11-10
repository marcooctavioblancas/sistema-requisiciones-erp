<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('compras') && !isAdmin())) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$proyecto_id = intval($_GET['proyecto_id'] ?? 0);
if (!$proyecto_id) {
    echo json_encode([]);
    exit();
}

$dir = __DIR__ . '/../uploads/cotizaciones/' . $proyecto_id . '/';
$files = [];

if (is_dir($dir)) {
    foreach (glob($dir . '*.pdf') as $ruta) {
        $nombre = basename($ruta);
        $files[] = [
            'nombre' => $nombre,
            'pid'    => $proyecto_id,
            'fecha'  => date('d/m/Y H:i', filemtime($ruta)),
            'mtime'  => filemtime($ruta),
            'size'   => round(filesize($ruta) / 1024) . ' KB',
        ];
    }
}

usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

echo json_encode($files);
