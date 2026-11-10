<?php
require_once 'includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    http_response_code(401);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['desde_menu'] = true;
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(405);
}
?>