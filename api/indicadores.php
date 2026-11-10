<?php
require_once '../includes/config.php';
require_once '../includes/indicadores.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('No autorizado');
}

$db     = getDB();
$accion = $_POST['accion'] ?? '';

/**
 * Redirige de vuelta a return_url si apunta al mismo host que la petición actual — evita
 * open-redirect sin depender de SITE_URL, que aquí (dentro de /api/) se calcula con el
 * directorio de ESTE script (dirname('/api/indicadores.php') = '/api'), no con la raíz
 * real del sitio — comparar contra SITE_URL literal siempre fallaba.
 */
function ind_redirect_back(string $fallback = 'index.php'): never {
    $ret       = $_POST['return_url'] ?? '';
    $host      = $_SERVER['HTTP_HOST'] ?? '';
    $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $origen    = $protocolo . '://' . $host;
    if ($ret && $host && strpos($ret, $origen . '/') === 0) {
        redirect($ret);
    }
    redirect($origen . '/' . ltrim($fallback, '/'));
}

if ($accion === 'obtener_fechas') {
    // AJAX — responde JSON, no redirige. Autollenado de fechas al elegir un proyecto/OC existente.
    header('Content-Type: application/json');
    $area  = sanitize($_POST['area'] ?? '');
    $refId = intval($_POST['ref_id'] ?? 0);
    if (!ind_valida_acceso($area) || !$refId) { echo json_encode(['error' => 'No autorizado']); exit(); }
    echo json_encode(['ok' => true] + ind_obtener_fechas_referencia($db, $area, $refId));
    exit();
}

if ($accion === 'crear_reporte') {
    $area      = sanitize($_POST['area'] ?? '');
    $trimestre = intval($_POST['trimestre'] ?? 0);
    $anio      = intval($_POST['anio'] ?? 0);
    if (!ind_valida_acceso($area) || !isset(INDICADORES_CONFIG[$area]) || $trimestre < 1 || $trimestre > 4 || $anio < 2020) {
        ind_redirect_back();
    }
    try {
        $chk = $db->prepare("SELECT id FROM indicadores_reportes WHERE area = ? AND trimestre = ? AND anio = ?");
        $chk->execute([$area, $trimestre, $anio]);
        $existente = $chk->fetchColumn();
        if ($existente) {
            $reporte_id = (int)$existente;
        } else {
            // Auto-llenado: nombre del usuario logueado como representante, cargo = nombre
            // del área (igual que en el documento de ejemplo, donde Cargo = Producción),
            // fecha de hoy, y 100% como % requerido por defecto (criterio base "Tiempo ≤ 100%").
            $stmtU = $db->prepare("SELECT nombre_completo FROM usuarios WHERE id = ?");
            $stmtU->execute([$_SESSION['user_id']]);
            $nombreRepresentante = $stmtU->fetchColumn() ?: '';
            $cargoDefault = INDICADORES_CONFIG[$area]['nombre_area'];

            $db->prepare("INSERT INTO indicadores_reportes (area, trimestre, anio, estado, representante_nombre, representante_cargo, fecha_reporte, porcentaje_requerido, creado_por, fecha_creacion) VALUES (?,?,?,'borrador',?,?,CURDATE(),100.00,?,NOW())")
               ->execute([$area, $trimestre, $anio, $nombreRepresentante, $cargoDefault, $_SESSION['user_id']]);
            $reporte_id = (int)$db->lastInsertId();
        }
        // Mismo criterio anti open-redirect que ind_redirect_back(): solo seguimos return_url
        // si apunta al mismo host que la petición actual.
        $ret       = $_POST['return_url'] ?? '';
        $host      = $_SERVER['HTTP_HOST'] ?? '';
        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $origen    = $protocolo . '://' . $host;
        if ($ret && $host && strpos($ret, $origen . '/') === 0) {
            redirect($ret . '&reporte_id=' . $reporte_id);
        }
        ind_redirect_back();
    } catch (Exception $e) {
        ind_redirect_back();
    }
}

if ($accion === 'actualizar_header') {
    $reporte_id = intval($_POST['reporte_id'] ?? 0);
    $stmt = $db->prepare("SELECT area, estado FROM indicadores_reportes WHERE id = ?");
    $stmt->execute([$reporte_id]);
    $rep = $stmt->fetch();
    if (!$rep || !ind_valida_acceso($rep['area']) || $rep['estado'] !== 'borrador') { ind_redirect_back(); }

    $fecha_reporte = trim($_POST['fecha_reporte'] ?? '') ?: null;
    $requerido     = ($_POST['porcentaje_requerido'] ?? '') !== '' ? (float)$_POST['porcentaje_requerido'] : null;
    $notas         = sanitize($_POST['notas'] ?? '');

    // Nombre del representante y Cargo ya no son editables (se hacen en base al perfil de
    // quien firma y al área del indicador) — se recalculan aquí siempre, no vienen del form.
    $stmtU = $db->prepare("SELECT nombre_completo FROM usuarios WHERE id = ?");
    $stmtU->execute([$_SESSION['user_id']]);
    $representante = $stmtU->fetchColumn() ?: '';
    $cargo         = INDICADORES_CONFIG[$rep['area']]['nombre_area'];

    $db->prepare("UPDATE indicadores_reportes SET fecha_reporte=?, representante_nombre=?, representante_cargo=?, porcentaje_requerido=?, notas=? WHERE id=?")
       ->execute([$fecha_reporte, $representante, $cargo, $requerido, $notas, $reporte_id]);
    ind_redirect_back();
}

if ($accion === 'agregar_entrada') {
    $reporte_id = intval($_POST['reporte_id'] ?? 0);
    $stmt = $db->prepare("SELECT area, estado FROM indicadores_reportes WHERE id = ?");
    $stmt->execute([$reporte_id]);
    $rep = $stmt->fetch();
    if (!$rep || !ind_valida_acceso($rep['area']) || $rep['estado'] !== 'borrador') { ind_redirect_back(); }

    $etiqueta         = sanitize($_POST['etiqueta'] ?? '');
    $fecha_real       = trim($_POST['fecha_real'] ?? '') ?: null;
    $fecha_programada = trim($_POST['fecha_programada'] ?? '') ?: null;
    $fecha_referencia = trim($_POST['fecha_referencia'] ?? '') ?: null;
    $nota             = sanitize($_POST['nota'] ?? '');

    [$diasReales, $diasProgramados, $porcentaje] = calcularPorcentajeTiempo($fecha_real, $fecha_programada, $fecha_referencia);

    $ordenMax = (int)($db->query("SELECT COALESCE(MAX(orden),0) FROM indicadores_entradas WHERE reporte_id = $reporte_id")->fetchColumn());

    $db->prepare("INSERT INTO indicadores_entradas (reporte_id, etiqueta, fecha_real, fecha_programada, fecha_referencia, dias_reales, dias_programados, porcentaje, nota, orden) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$reporte_id, $etiqueta, $fecha_real, $fecha_programada, $fecha_referencia, $diasReales, $diasProgramados, $porcentaje, $nota, $ordenMax + 1]);

    ind_redirect_back();
}

if ($accion === 'eliminar_entrada') {
    $reporte_id = intval($_POST['reporte_id'] ?? 0);
    $entrada_id = intval($_POST['entrada_id'] ?? 0);
    $stmt = $db->prepare("SELECT area, estado FROM indicadores_reportes WHERE id = ?");
    $stmt->execute([$reporte_id]);
    $rep = $stmt->fetch();
    if (!$rep || !ind_valida_acceso($rep['area']) || $rep['estado'] !== 'borrador') { ind_redirect_back(); }

    $db->prepare("DELETE FROM indicadores_entradas WHERE id = ? AND reporte_id = ?")->execute([$entrada_id, $reporte_id]);
    ind_redirect_back();
}

if ($accion === 'finalizar_reporte') {
    $reporte_id = intval($_POST['reporte_id'] ?? 0);
    $stmt = $db->prepare("SELECT area, estado, porcentaje_requerido FROM indicadores_reportes WHERE id = ?");
    $stmt->execute([$reporte_id]);
    $rep = $stmt->fetch();
    if (!$rep || !ind_valida_acceso($rep['area']) || $rep['estado'] !== 'borrador') { ind_redirect_back(); }

    $promedio  = recalcularPromedioReporte($db, $reporte_id);
    $requerido = $rep['porcentaje_requerido'] !== null ? (float)$rep['porcentaje_requerido'] : 100.0;
    $cumple    = ($promedio !== null) ? ($promedio <= $requerido ? 1 : 0) : null;

    $db->prepare("UPDATE indicadores_reportes SET estado='finalizado', porcentaje_obtenido=?, porcentaje_requerido=?, cumple=?, fecha_finalizado=NOW() WHERE id=?")
       ->execute([$promedio, $requerido, $cumple, $reporte_id]);

    ind_redirect_back();
}

if ($accion === 'reabrir_reporte') {
    $reporte_id = intval($_POST['reporte_id'] ?? 0);
    $stmt = $db->prepare("SELECT area, estado FROM indicadores_reportes WHERE id = ?");
    $stmt->execute([$reporte_id]);
    $rep = $stmt->fetch();
    if (!$rep || !ind_valida_acceso($rep['area']) || $rep['estado'] !== 'finalizado') { ind_redirect_back(); }

    $db->prepare("UPDATE indicadores_reportes SET estado='borrador' WHERE id=?")->execute([$reporte_id]);
    ind_redirect_back();
}

if ($accion === 'eliminar_reporte') {
    $reporte_id = intval($_POST['reporte_id'] ?? 0);
    $stmt = $db->prepare("SELECT area FROM indicadores_reportes WHERE id = ?");
    $stmt->execute([$reporte_id]);
    $rep = $stmt->fetch();
    if (!$rep || !ind_valida_acceso($rep['area'])) { ind_redirect_back(); }

    $db->prepare("DELETE FROM indicadores_entradas WHERE reporte_id = ?")->execute([$reporte_id]);
    $db->prepare("DELETE FROM indicadores_reportes WHERE id = ?")->execute([$reporte_id]);
    // A diferencia del detalle, la lista no lleva reporte_id en el return_url.
    ind_redirect_back();
}

// Acción no reconocida
ind_redirect_back();
