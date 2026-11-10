<?php
require_once '../includes/config.php';
require_once '../includes/hoja_viajera.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('No autorizado');
}
if (!hv_valida_acceso()) {
    http_response_code(403);
    exit('No autorizado');
}

$db     = getDB();
$accion = $_POST['accion'] ?? '';

/** Redirige de vuelta a return_url solo si apunta al mismo host — evita open-redirect. */
function hv_redirect_back(string $fallback = 'index.php'): never {
    $ret       = $_POST['return_url'] ?? '';
    $host      = $_SERVER['HTTP_HOST'] ?? '';
    $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $origen    = $protocolo . '://' . $host;
    if ($ret && $host && strpos($ret, $origen . '/') === 0) {
        redirect($ret);
    }
    redirect($origen . '/' . ltrim($fallback, '/'));
}

if ($accion === 'crear_hoja_viajera') {
    if (!hv_puede_editar_produccion()) { hv_redirect_back(); }
    $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
    $no_serie    = sanitize($_POST['no_serie']  ?? '');
    $id_tag      = sanitize($_POST['id_tag']    ?? '');
    if (!$proyecto_id) { hv_redirect_back(); }

    // Cliente y Producto se toman de la Orden de Producción real del proyecto (proyecto.php),
    // no se capturan a mano -- deben coincidir siempre con lo que Proyectos difundió.
    $stmtNomProy = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
    $stmtNomProy->execute([$proyecto_id]);
    $nombreProyecto = $stmtNomProy->fetchColumn();

    $stmtOp = $db->prepare("SELECT cliente, descripcion FROM ordenes_produccion WHERE proyecto_id = ? ORDER BY id DESC LIMIT 1");
    $stmtOp->execute([$proyecto_id]);
    $op = $stmtOp->fetch(PDO::FETCH_ASSOC);
    if (!$op && $nombreProyecto) {
        $stmtOp2 = $db->prepare("SELECT cliente, descripcion FROM ordenes_produccion WHERE numero_proyecto COLLATE utf8mb4_general_ci = ? ORDER BY id DESC LIMIT 1");
        $stmtOp2->execute([$nombreProyecto]);
        $op = $stmtOp2->fetch(PDO::FETCH_ASSOC);
    }
    $cliente  = $op['cliente'] ?? '';
    $producto = $op['descripcion'] ?? '';

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO hoja_viajera (proyecto_id, cliente, no_serie, id_tag, producto, creado_por, fecha_creacion)
                               VALUES (?,?,?,?,?,?,NOW())");
        $stmt->execute([$proyecto_id, $cliente ?: null, $no_serie ?: null, $id_tag ?: null, $producto ?: null, $_SESSION['user_id']]);
        $hoja_id = (int)$db->lastInsertId();

        $insPaso = $db->prepare("INSERT INTO hoja_viajera_pasos (hoja_id, numero, nombre, tipo) VALUES (?,?,?,?)");
        foreach (HOJA_VIAJERA_PASOS_FIJOS as $numero => $def) {
            $insPaso->execute([$hoja_id, $numero, $def['nombre'], $def['tipo']]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
    }
    hv_redirect_back();
}

if ($accion === 'guardar_recubrimientos') {
    if (!hv_puede_editar_produccion()) { hv_redirect_back(); }
    $paso_id = intval($_POST['paso_id'] ?? 0);
    if (!$paso_id) { hv_redirect_back(); }
    $valvula     = isset($_POST['sub_valvula'])     ? 1 : 0;
    $actuador    = isset($_POST['sub_actuador'])    ? 1 : 0;
    $accesorios  = isset($_POST['sub_accesorios'])  ? 1 : 0;
    $db->prepare("UPDATE hoja_viajera_pasos SET sub_valvula=?, sub_actuador=?, sub_accesorios=? WHERE id=?")
       ->execute([$valvula, $actuador, $accesorios, $paso_id]);
    hv_redirect_back();
}

if ($accion === 'guardar_campo_encabezado') {
    if (!hv_puede_editar_produccion()) { hv_redirect_back(); }
    $hoja_id = intval($_POST['hoja_id'] ?? 0);
    $campo   = $_POST['campo'] ?? '';
    $valor   = sanitize($_POST['valor'] ?? '');
    // Whitelist estricta — nunca interpolar el nombre de columna desde el POST.
    if ($hoja_id && in_array($campo, HV_CAMPOS_ENCABEZADO, true)) {
        $sqlPorCampo = [
            'cliente'                => "UPDATE hoja_viajera SET cliente = ? WHERE id = ?",
            'no_serie'               => "UPDATE hoja_viajera SET no_serie = ? WHERE id = ?",
            'producto'               => "UPDATE hoja_viajera SET producto = ? WHERE id = ?",
            'pasadas_recubrimiento'  => "UPDATE hoja_viajera SET pasadas_recubrimiento = ? WHERE id = ?",
            'id_tag'                 => "UPDATE hoja_viajera SET id_tag = ? WHERE id = ?",
        ];
        // pasadas_recubrimiento es numérico (columna INT) -- solo se guarda si es un entero real,
        // cualquier otra cosa (vacío, texto) se limpia a NULL en vez de fallar el UPDATE.
        $valorGuardar = ($campo === 'pasadas_recubrimiento')
            ? (ctype_digit($valor) ? (int)$valor : null)
            : ($valor ?: null);
        $db->prepare($sqlPorCampo[$campo])->execute([$valorGuardar, $hoja_id]);
    }
    hv_redirect_back();
}

if ($accion === 'guardar_paso_detalle') {
    if (!hv_puede_editar_produccion()) { hv_redirect_back(); }
    $paso_id = intval($_POST['paso_id'] ?? 0);
    if (!$paso_id) { hv_redirect_back(); }

    $stmt = $db->prepare("SELECT * FROM hoja_viajera_pasos WHERE id = ?");
    $stmt->execute([$paso_id]);
    $pasoActual = $stmt->fetch();
    if (!$pasoActual) { hv_redirect_back(); }

    // Si "No se requirió" ya estaba elegido, Actividad y NC quedan disabled en el formulario —
    // un navegador NO manda campos disabled, así que su ausencia en $_POST significa "seguía
    // congelado", no "se vació": hay que conservar el valor anterior en vez de borrarlo.
    if (array_key_exists('actividad_estado', $_POST)) {
        $actividad_estado = $_POST['actividad_estado'];
        if (!in_array($actividad_estado, ['conforme','no_conforme'], true)) $actividad_estado = null;
    } else {
        $actividad_estado = $pasoActual['actividad_estado'];
    }
    if (array_key_exists('nc_numero', $_POST)) {
        $nc_numero = sanitize($_POST['nc_numero']);
    } else {
        $nc_numero = $pasoActual['nc_numero'];
    }
    $accion_realizada = $_POST['accion_realizada'] ?? null;
    if (!in_array($accion_realizada, ['retrabajo','reproceso','no_se_requirio'], true)) $accion_realizada = null;

    $db->prepare("UPDATE hoja_viajera_pasos SET actividad_estado=?, accion_realizada=?, nc_numero=? WHERE id=?")
       ->execute([$actividad_estado, $accion_realizada, $nc_numero ?: null, $paso_id]);
    hv_recalcular_conteos($db, (int)$pasoActual['hoja_id']);
    hv_redirect_back();
}

if ($accion === 'vobo_hoja_paso') {
    $paso_id = intval($_POST['paso_id'] ?? 0);
    $rol     = $_POST['rol']   ?? '';
    $valor   = $_POST['valor'] ?? '';
    if (!$paso_id || !in_array($rol, ['produccion','calidad'], true) || !in_array($valor, ['conforme','no_aplica'], true)) {
        hv_redirect_back();
    }

    // Cada rol solo firma su propia columna: Producción no puede dar el Vo.Bo. de Calidad, ni viceversa.
    if ($rol === 'produccion' && !hv_puede_editar_produccion()) { hv_redirect_back(); }
    if ($rol === 'calidad' && !hv_puede_actuar_calidad()) { hv_redirect_back(); }

    $stmt = $db->prepare("SELECT * FROM hoja_viajera_pasos WHERE id = ?");
    $stmt->execute([$paso_id]);
    $paso = $stmt->fetch();
    if (!$paso) { hv_redirect_back(); }

    // Orden: Calidad solo puede dar su visto bueno si Producción dio Conforme. Un "No Aplica" de
    // Producción no es una corroboración real — tampoco habilita a Calidad para ese renglón.
    if ($rol === 'calidad' && $paso['vobo_produccion'] !== 'conforme') {
        hv_redirect_back();
    }

    // Un paso "detallado" sin Actividad ni Acción realizada no tiene nada que corroborar — Producción
    // puede marcarlo "No Aplica" pero no "Firmar" como conforme sin haber llenado al menos uno de los dos.
    if ($rol === 'produccion' && $valor === 'conforme' && $paso['tipo'] === 'detallado'
        && $paso['actividad_estado'] === null && $paso['accion_realizada'] === null) {
        hv_redirect_back();
    }

    // Nombres de columna estáticos (whitelist), nunca interpolados desde $rol directamente.
    $sqlPorRol = [
        'produccion' => "UPDATE hoja_viajera_pasos SET vobo_produccion = ?, vobo_produccion_por = ?, vobo_produccion_fecha = NOW() WHERE id = ?",
        'calidad'    => "UPDATE hoja_viajera_pasos SET vobo_calidad = ?, vobo_calidad_por = ?, vobo_calidad_fecha = NOW() WHERE id = ?",
    ];
    $db->prepare($sqlPorRol[$rol])->execute([$valor, $_SESSION['user_id'], $paso_id]);
    hv_redirect_back();
}

if ($accion === 'finalizar_produccion') {
    if (!hv_puede_editar_produccion()) { hv_redirect_back(); }
    $hoja_id   = intval($_POST['hoja_id'] ?? 0);
    $resultado = $_POST['resultado'] ?? '';
    if (!$hoja_id || !in_array($resultado, ['aceptado','rechazado'], true)) { hv_redirect_back(); }
    // No se puede cerrar la parte de Producción mientras queden pasos sin resolver (conforme/no aplica).
    if (!hv_produccion_completa($db, $hoja_id)) { hv_redirect_back(); }

    // Si Producción rechaza su propia parte, la hoja se cierra ahí — nunca llega a Calidad.
    if ($resultado === 'rechazado') {
        $db->prepare("UPDATE hoja_viajera SET resultado_produccion = 'rechazado', resultado = 'rechazado', fecha_finalizado = NOW() WHERE id = ?")
           ->execute([$hoja_id]);
    } else {
        $db->prepare("UPDATE hoja_viajera SET resultado_produccion = 'aceptado' WHERE id = ?")->execute([$hoja_id]);
    }
    hv_redirect_back();
}

if ($accion === 'finalizar_calidad') {
    if (!hv_puede_actuar_calidad()) { hv_redirect_back(); }
    $hoja_id   = intval($_POST['hoja_id'] ?? 0);
    $resultado = $_POST['resultado'] ?? '';
    if (!$hoja_id || !in_array($resultado, ['aceptado','rechazado'], true)) { hv_redirect_back(); }

    $stmt = $db->prepare("SELECT hv.*, p.nombre AS proyecto_nombre FROM hoja_viajera hv JOIN proyectos p ON p.id = hv.proyecto_id WHERE hv.id = ?");
    $stmt->execute([$hoja_id]);
    $hoja = $stmt->fetch();
    if (!$hoja || $hoja['resultado_produccion'] !== 'aceptado') { hv_redirect_back(); }
    // No se puede cerrar la parte de Calidad mientras queden pasos que sí corrobora sin resolver.
    if (!hv_calidad_completa($db, $hoja_id)) { hv_redirect_back(); }

    if ($resultado === 'aceptado') {
        $db->prepare("UPDATE hoja_viajera SET resultado_calidad = 'aceptado', resultado = 'aceptado', fecha_finalizado = NOW() WHERE id = ?")
           ->execute([$hoja_id]);
    } else {
        // Calidad rechaza el cierre: se regresa a Producción, se reinician todos los pasos
        // (hay que volver a hacer las pruebas) y se notifica al área de manufactura.
        $db->prepare("UPDATE hoja_viajera SET resultado_produccion = 'pendiente', resultado_calidad = 'pendiente', ciclo_rechazo = ciclo_rechazo + 1 WHERE id = ?")
           ->execute([$hoja_id]);
        hv_reiniciar_pasos($db, $hoja_id);
        notificarRoles(
            ['manufactura'],
            'hoja_viajera_rechazada',
            'Hoja Viajera rechazada por Calidad',
            "Calidad rechazó el cierre de la Hoja Viajera del proyecto \"{$hoja['proyecto_nombre']}\" — hay que volver a hacer las pruebas.",
            'produccion.php?seccion=hoja_viajera&hoja_id=' . $hoja_id,
            $_SESSION['user_id']
        );
    }
    hv_redirect_back();
}

if ($accion === 'eliminar_hoja_viajera') {
    if (!hv_puede_editar_produccion()) { hv_redirect_back(); }
    $hoja_id = intval($_POST['hoja_id'] ?? 0);
    if ($hoja_id) {
        $db->prepare("DELETE FROM hoja_viajera WHERE id = ?")->execute([$hoja_id]);
    }
    hv_redirect_back();
}

hv_redirect_back();
