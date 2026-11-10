<?php
// Endpoint mínimo para el botón "Seguir conectado" del aviso de expiración de sesión
// (ver includes/config.php). Solo toca $_SESSION para renovar el TTL de Redis -- no
// hace ninguna otra cosa. GET a propósito: no cambia estado del negocio, no necesita CSRF.
require_once 'includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true]);
