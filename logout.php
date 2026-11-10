<?php
/**
 * Cerrar Sesión
 */

require_once 'includes/config.php';

// Registrar logout en auditoría si hay sesión activa
if (isLoggedIn()) {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, detalle, ip_address) VALUES (?, 'logout', NULL, ?, ?)")
       ->execute([$_SESSION['user_id'], "Cierre de sesión: {$_SESSION['user_username']}", $ip]);
}

// Destruir sesión
session_destroy();

// Redirigir al login
redirect('index.php');
