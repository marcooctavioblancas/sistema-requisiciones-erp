<?php
require_once 'includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || $_SESSION['user_username'] !== 'admin') {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $mode = $_POST['mode'];
    
    if ($mode === 'normal' || $mode === 'prueba') {
        $_SESSION['admin_mode_selected'] = true;
        $_SESSION['admin_mode'] = $mode;
        
        if ($mode === 'normal') {
            header('Location: almacen2.php');
            exit();
        } else {
            header('Location: admin.php');
            exit();
        }
    }
}

header('Location: index.php');
exit();
?>