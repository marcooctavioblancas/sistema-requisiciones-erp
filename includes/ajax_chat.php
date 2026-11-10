<?php
require_once 'config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$user_name = $_SESSION['user_name'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Directorio para archivos subidos
$upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/sistema_requisiciones/uploads/chat/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

function responder($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

switch ($action) {
    case 'actualizar_estado':
        $estado = $_GET['estado'] ?? 'online';
        $stmt = $db->prepare("INSERT INTO usuarios_estado (usuario_id, estado, ultima_actividad) 
                               VALUES (?, ?, NOW()) 
                               ON DUPLICATE KEY UPDATE estado = ?, ultima_actividad = NOW()");
        $stmt->execute([$user_id, $estado, $estado]);
        responder(['success' => true]);
        break;
        
    case 'obtener_contactos':
        // Obtener todos los usuarios excepto el actual
        $stmt = $db->prepare("SELECT u.id, u.nombre_completo, u.tipo_usuario, u.username,
                                      COALESCE(ue.estado, 'offline') as estado
                               FROM usuarios u
                               LEFT JOIN usuarios_estado ue ON u.id = ue.usuario_id
                               WHERE u.id != ? AND u.activo = 1
                               ORDER BY u.nombre_completo");
        $stmt->execute([$user_id]);
        $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener no leídos por contacto
        $stmt2 = $db->prepare("SELECT remitente_id, COUNT(*) as no_leidos
                                FROM chat_mensajes
                                WHERE destinatario_id = ? AND leido = 0
                                GROUP BY remitente_id");
        $stmt2->execute([$user_id]);
        $no_leidos = [];
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $no_leidos[$row['remitente_id']] = $row['no_leidos'];
        }
        
        responder(['success' => true, 'contactos' => $contactos, 'no_leidos_por_contacto' => $no_leidos]);
        break;
        
    case 'enviar':
        $destinatario_id = intval($_POST['destinatario_id'] ?? 0);
        $mensaje = sanitize($_POST['mensaje'] ?? '');
        $archivo_nombre = null;
        $archivo_ruta = null;
        $archivo_tipo_db = null;
        
        if ($destinatario_id == $user_id) {
            responder(['success' => false, 'error' => 'No puedes enviarte mensajes a ti mismo']);
        }
        
        // Verificar que el destinatario existe
        $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$destinatario_id]);
        if (!$stmt->fetch()) {
            responder(['success' => false, 'error' => 'Usuario destinatario no existe']);
        }
        
        // Procesar archivo adjunto
        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['archivo'];
            // Extensión validada contra whitelist fija: la extensión original y el
            // Content-Type que manda el navegador son ambos falsificables, así que
            // determinan solo el "tipo" para mostrar en la UI, nunca qué se guarda.
            $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'xls', 'xlsx', 'doc', 'docx', 'txt'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $nombre_original = $file['name'];

            if (in_array($extension, $extensionesPermitidas, true) && $file['size'] <= 10 * 1024 * 1024) {
                $nombre_unico = uniqid() . '_' . time() . '.' . $extension;
                $ruta_destino = $upload_dir . $nombre_unico;

                if (move_uploaded_file($file['tmp_name'], $ruta_destino)) {
                    $archivo_nombre = $nombre_original;
                    $archivo_ruta = 'uploads/chat/' . $nombre_unico;
                    $archivo_tipo_db = $_POST['archivo_tipo'] ?? 'archivo';
                }
            }
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO chat_mensajes 
                (remitente_id, destinatario_id, mensaje, archivo_nombre, archivo_ruta, archivo_tipo, fecha_envio) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $destinatario_id, $mensaje, $archivo_nombre, $archivo_ruta, $archivo_tipo_db]);
            
            // Actualizar o crear conversación
            $stmt2 = $db->prepare("INSERT INTO chat_conversaciones (usuario_id, contacto_id, ultimo_mensaje, ultima_actividad)
                                   VALUES (?, ?, ?, NOW())
                                   ON DUPLICATE KEY UPDATE ultimo_mensaje = ?, ultima_actividad = NOW()");
            $stmt2->execute([$user_id, $destinatario_id, $mensaje, $mensaje]);
            $stmt2->execute([$destinatario_id, $user_id, $mensaje, $mensaje]);
            
            responder(['success' => true, 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            responder(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'historial':
        $contacto_id = intval($_GET['con'] ?? 0);
        if (!$contacto_id) {
            responder(['success' => false, 'error' => 'Contacto no especificado']);
        }
        
        try {
            $stmt = $db->prepare("SELECT m.*, u.nombre_completo as remitente_nombre
                                   FROM chat_mensajes m
                                   LEFT JOIN usuarios u ON m.remitente_id = u.id
                                   WHERE (remitente_id = ? AND destinatario_id = ?)
                                      OR (remitente_id = ? AND destinatario_id = ?)
                                   ORDER BY m.fecha_envio ASC
                                   LIMIT 200");
            $stmt->execute([$user_id, $contacto_id, $contacto_id, $user_id]);
            $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($historial as &$msg) {
                $msg['es_propio'] = ($msg['remitente_id'] == $user_id);
            }
            
            responder(['success' => true, 'historial' => $historial]);
        } catch (Exception $e) {
            responder(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'obtener_nuevos':
        try {
            // Contar mensajes no leídos para este usuario
            $stmt = $db->prepare("SELECT COUNT(*) FROM chat_mensajes 
                                   WHERE destinatario_id = ? AND leido = 0");
            $stmt->execute([$user_id]);
            $total_no_leidos = $stmt->fetchColumn();
            
            // Obtener no leídos por contacto
            $stmt2 = $db->prepare("SELECT remitente_id, COUNT(*) as no_leidos
                                    FROM chat_mensajes
                                    WHERE destinatario_id = ? AND leido = 0
                                    GROUP BY remitente_id");
            $stmt2->execute([$user_id]);
            $contactos_con_no_leidos = [];
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $contactos_con_no_leidos[$row['remitente_id']] = $row['no_leidos'];
            }
            
            // Obtener notificaciones no leídas
            $stmt3 = $db->prepare("SELECT * FROM notificaciones 
                                    WHERE usuario_id = ? AND leido = 0 
                                    ORDER BY fecha_creacion DESC LIMIT 20");
            $stmt3->execute([$user_id]);
            $notificaciones = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            
            responder([
                'success' => true, 
                'total_no_leidos' => $total_no_leidos,
                'contactos_con_no_leidos' => $contactos_con_no_leidos,
                'notificaciones' => $notificaciones
            ]);
        } catch (Exception $e) {
            responder(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'marcar_leidos':
        $contacto_id = intval($_GET['contacto'] ?? 0);
        if ($contacto_id) {
            $stmt = $db->prepare("UPDATE chat_mensajes SET leido = 1 
                                   WHERE destinatario_id = ? AND remitente_id = ? AND leido = 0");
            $stmt->execute([$user_id, $contacto_id]);
        }
        responder(['success' => true]);
        break;
        
    case 'marcar_notificacion':
        $id = intval($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE notificaciones SET leido = 1 WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id, $user_id]);
            responder(['success' => true]);
        } catch (Exception $e) {
            responder(['success' => false]);
        }
        break;

    case 'borrar_notificacion':
        $id = intval($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM notificaciones WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id, $user_id]);
            responder(['success' => true]);
        } catch (Exception $e) {
            responder(['success' => false]);
        }
        break;

    case 'marcar_todas_leidas':
        try {
            $db->prepare("UPDATE notificaciones SET leido = 1 WHERE usuario_id = ?")->execute([$user_id]);
            responder(['success' => true]);
        } catch (Exception $e) {
            responder(['success' => false]);
        }
        break;

    case 'borrar_notificaciones':
        try {
            $db->prepare("DELETE FROM notificaciones WHERE usuario_id = ?")->execute([$user_id]);
            responder(['success' => true]);
        } catch (Exception $e) {
            responder(['success' => false]);
        }
        break;

    case 'obtener_notificaciones':
        try {
            // Piggyback sobre este poll (cada 15s desde cualquier módulo abierto, ver
            // actualizarBadgeNotifHeader en produccion.php) para el aviso de asistencia
            // sin_marcar -- el sistema no tiene cron de servidor, ver avisarAsistenciaPendiente().
            avisarAsistenciaPendiente($db);

            $stmt = $db->prepare("SELECT * FROM notificaciones
                                   WHERE usuario_id = ?
                                   ORDER BY fecha_creacion DESC LIMIT 50");
            $stmt->execute([$user_id]);
            $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            responder(['success' => true, 'notificaciones' => $notificaciones]);
        } catch (Exception $e) {
            responder(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    default:
        responder(['success' => false, 'error' => 'Acción no válida']);
}