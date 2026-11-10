<?php
require_once 'includes/config.php';

$desde_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;

if (isset($_GET['logout'])) {
    if ($desde_menu) {
        // Usuario multi-rol que vino del menú → solo regresar al menú, sin cerrar sesión
        header('Location: Menu.php');
        exit();
    }
    // Usuario de proceso exclusivo → cerrar sesión completa
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}

if (!isLoggedIn() || (!hasAcceso('proceso') && !isAdmin())) {
    header('Location: index.php');
    exit();
}

$db       = getDB();
$user_id  = $_SESSION['user_id'];
// Equivalente a administrador-proceso: cuenta 'multiple' con acceso 'proceso' Y nivel
// Jefe (usuario_niveles / esJefeDe()) -- permite armar el mismo rol supervisor sin
// necesitar el tipo_usuario especial, marcando Producción + Proceso (jefe) en el checkbox.
$puede_ver_detalles = isAdmin() || ($_SESSION['user_type'] ?? '') === 'administrador-proceso'
    || (hasAcceso('proceso') && esJefeDe('proceso'));

// ── Auto-migración silenciosa — SOLO en carga de página, nunca en AJAX ────────
// Ejecutar DDL en cada petición AJAX adquiere un MDL exclusivo en ordenes_trabajo
// bloqueando todas las operaciones concurrentes hasta timeout.
if (empty($_POST['ajax'])) {
    try {
        $db->exec("ALTER TABLE ordenes_trabajo
            ADD COLUMN IF NOT EXISTS tiempo_regular_min INT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS tiempo_extra_min   INT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS tiempo_pausado_min INT NOT NULL DEFAULT 0");
        try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS es_prioritaria TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS en_stand_by TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS orden_posicion INT NOT NULL DEFAULT 0"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS secuencia_ejecucion INT NOT NULL DEFAULT 0"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS operador_nombre VARCHAR(200) NULL"); } catch(Exception $e) {}
        try { $db->exec("CREATE TABLE IF NOT EXISTS ot_procesos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ot_id INT NOT NULL,
            nombre VARCHAR(200) NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            estado ENUM('pendiente','activo','completado') NOT NULL DEFAULT 'pendiente',
            fecha_inicio DATETIME NULL,
            fecha_fin DATETIME NULL,
            INDEX idx_otp (ot_id),
            FOREIGN KEY (ot_id) REFERENCES ordenes_trabajo(id) ON DELETE CASCADE
        )"); } catch(Exception $e) {}

        $db->exec("CREATE TABLE IF NOT EXISTS ot_logs (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            ot_id             INT NOT NULL,
            usuario_id        INT NOT NULL,
            accion            ENUM('inicio','reanudacion','pausa','fin') NOT NULL,
            timestamp         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            operador_nombre   VARCHAR(200) NULL,
            maquina           VARCHAR(200) NULL,
            actividad         TEXT        NULL,
            resumen           TEXT        NULL,
            motivo_pausa      VARCHAR(200) NULL,
            piezas_terminadas INT         NULL DEFAULT 0,
            observaciones     TEXT        NULL,
            tiempo_regular_min INT        NULL DEFAULT 0,
            tiempo_extra_min   INT        NULL DEFAULT 0,
            INDEX idx_ot (ot_id),
            FOREIGN KEY (ot_id)       REFERENCES ordenes_trabajo(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
        )");
    } catch (Exception $e) { /* columnas/tabla ya existen */ }
}

// ── Función: calcula minutos regulares y extra de un bloque de tiempo ─────────
// Turno regular: 08:00-13:00 y 14:00-18:00 | Comida: 13:00-14:00 (no cuenta)
// Extra: 00:00-08:00 y 18:00-24:00
// Usa aritmética de enteros (Unix timestamps) para evitar bugs de zona horaria
// con new DateTime('@ts') que crea objetos en UTC y rompe comparaciones locales.
function calcularTiempoBloque(DateTime $inicio, DateTime $fin): array {
    $regular = 0.0;
    $extra   = 0.0;

    $tsIni = $inicio->getTimestamp();
    $tsFin = $fin->getTimestamp();

    if ($tsIni >= $tsFin) {
        return ['regular' => 0, 'extra' => 0];
    }

    // Segmentos en minutos desde medianoche local
    $segs = [
        [0,    480,  'extra'],    // 00:00-08:00
        [480,  780,  'regular'],  // 08:00-13:00
        [780,  840,  'none'],     // 13:00-14:00 comida
        [840,  1080, 'regular'],  // 14:00-18:00
        [1080, 1440, 'extra'],    // 18:00-24:00
    ];

    $ts    = $tsIni;
    $guard = 0; // seguridad: máximo 365 días

    while ($ts < $tsFin && $guard < 365) {
        $guard++;

        // Medianoche LOCAL del día actual usando getdate (respeta zona horaria de PHP)
        $d        = getdate($ts);
        $dayStart = mktime(0, 0, 0, $d['mon'], $d['mday'], $d['year']);
        $dayEnd   = $dayStart + 86400; // avanzar exactamente 24h en timestamps

        $limitTs  = min($tsFin, $dayEnd);

        $fromMin  = ($ts       - $dayStart) / 60.0;
        $toMin    = ($limitTs  - $dayStart) / 60.0;

        foreach ($segs as [$sS, $sE, $tipo]) {
            $sFrom = max($fromMin, $sS);
            $sTo   = min($toMin,   $sE);
            if ($sTo > $sFrom) {
                $dur = $sTo - $sFrom;
                if ($tipo === 'regular') $regular += $dur;
                elseif ($tipo === 'extra') $extra += $dur;
            }
        }

        $ts = $dayEnd; // avanzar al día siguiente
    }

    return ['regular' => (int)round($regular), 'extra' => (int)round($extra)];
}

// ── Obtener último log activo (inicio o reanudación sin cierre) ───────────────
function getUltimoLogActivo(PDO $db, int $ot_id): ?array {
    $s = $db->prepare(
        "SELECT * FROM ot_logs WHERE ot_id=? AND accion IN ('inicio','reanudacion')
         ORDER BY timestamp DESC LIMIT 1");
    $s->execute([$ot_id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Acciones AJAX ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    session_write_close();

    // ── Logger AJAX ──────────────────────────────────────────────────────────
    $__t0     = microtime(true);
    $__logBuf = [];
    $__logFile = __DIR__ . '/logs/proceso_ajax.log';
    if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0755, true);

    function _plog(string $step, string $extra = ''): void {
        global $__t0, $__logBuf;
        $ms = (int)round((microtime(true) - $__t0) * 1000);
        $__logBuf[] = sprintf("  [%5d ms] %-40s %s", $ms, $step, $extra);
    }
    function _plogFlush(string $accion, int $ot_id, string $resultado): void {
        global $__logBuf, $__logFile;
        $ts   = date('Y-m-d H:i:s');
        $head = "[$ts] accion=$accion ot_id=$ot_id resultado=$resultado";
        $body = implode("\n", $__logBuf);
        @file_put_contents($__logFile, "$head\n$body\n" . str_repeat('-', 60) . "\n", FILE_APPEND | LOCK_EX);
    }

    register_shutdown_function(function() use (&$__logBuf) {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            _plog('FATAL', $err['message'] . ' line ' . $err['line']);
            _plogFlush($_POST['accion'] ?? '?', (int)($_POST['ot_id'] ?? 0), 'FATAL');
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Error fatal del servidor']);
        }
    });

    $accion = sanitize($_POST['accion'] ?? '');
    $ot_id  = intval($_POST['ot_id'] ?? 0);
    _plog('inicio', "accion=$accion ot_id=$ot_id user=$user_id");

    // ── Acciones que NO requieren OT específica — van ANTES del lookup ────────

    // ── TICKER DATA ──────────────────────────────────────────────────────────
    // Todos los queries hacen JOIN boom_listas para excluir OTs huérfanas (boom eliminado)
    // y JOIN proyectos con p.activo=1 para excluir proyectos inactivos/eliminados.
    if ($accion === 'ticker') {
        $items = [];

        // 1. Prioritarias — liberadas, boom y proyecto existen, proyecto activo
        $s_prio = $db->query("
            SELECT ot.folio, ot.titulo, ot.operador_nombre, ot.proceso, p.nombre AS proyecto_nombre
            FROM ordenes_trabajo ot
            JOIN boom_listas bl ON bl.id = ot.boom_lista_id
            JOIN proyectos p ON p.id = ot.proyecto_id AND p.activo=1
            WHERE ot.es_prioritaria=1 AND ot.estado='pendiente' AND ot.ots_liberadas=1
            ORDER BY ot.fecha_creacion DESC LIMIT 5");
        foreach ($s_prio->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $items[] = [
                'tipo'=>'prioritaria',
                'folio'=>htmlspecialchars($o['folio'] ?? ''),
                'titulo'=>htmlspecialchars($o['titulo'] ?? ''),
                'proyecto'=>htmlspecialchars($o['proyecto_nombre'] ?? ''),
                'operador_nombre'=>htmlspecialchars($o['operador_nombre'] ?? ''),
                'proceso'=>htmlspecialchars($o['proceso'] ?? ''),
            ];
        }

        // 2. En proceso activas — boom y proyecto existen, proyecto activo, liberada o con operario
        $s_ep = $db->query("
            SELECT ot.folio, ot.titulo, ot.operador_nombre, ot.proceso, p.nombre AS proyecto_nombre
            FROM ordenes_trabajo ot
            JOIN boom_listas bl ON bl.id = ot.boom_lista_id
            JOIN proyectos p ON p.id = ot.proyecto_id AND p.activo=1
            WHERE ot.estado='en_proceso' AND ot.pausado=0
              AND (ot.ots_liberadas=1 OR ot.operario_id IS NOT NULL)
            ORDER BY ot.fecha_inicio_real DESC LIMIT 10");
        foreach ($s_ep->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $items[] = [
                'tipo'=>'en_curso',
                'folio'=>htmlspecialchars($o['folio'] ?? ''),
                'titulo'=>htmlspecialchars($o['titulo'] ?? ''),
                'proyecto'=>htmlspecialchars($o['proyecto_nombre'] ?? ''),
                'operador_nombre'=>htmlspecialchars($o['operador_nombre'] ?? ''),
                'proceso'=>htmlspecialchars($o['proceso'] ?? ''),
            ];
        }

        // 3. En pausa — boom y proyecto existen, proyecto activo, liberada o con operario
        $s_pau = $db->query("
            SELECT ot.folio, ot.titulo, ot.operador_nombre, ot.proceso, p.nombre AS proyecto_nombre
            FROM ordenes_trabajo ot
            JOIN boom_listas bl ON bl.id = ot.boom_lista_id
            JOIN proyectos p ON p.id = ot.proyecto_id AND p.activo=1
            WHERE ot.estado='en_proceso' AND ot.pausado=1
              AND (ot.ots_liberadas=1 OR ot.operario_id IS NOT NULL)
            ORDER BY ot.fecha_creacion ASC LIMIT 5");
        foreach ($s_pau->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $items[] = [
                'tipo'=>'pausada',
                'folio'=>htmlspecialchars($o['folio'] ?? ''),
                'titulo'=>htmlspecialchars($o['titulo'] ?? ''),
                'proyecto'=>htmlspecialchars($o['proyecto_nombre'] ?? ''),
                'operador_nombre'=>htmlspecialchars($o['operador_nombre'] ?? ''),
                'proceso'=>htmlspecialchars($o['proceso'] ?? ''),
            ];
        }

        // 4. Siguiente en cola — liberadas, boom y proyecto existen, proyecto activo
        $s_sig = $db->query("
            SELECT ot.folio, ot.titulo, ot.operador_nombre, ot.proceso, p.nombre AS proyecto_nombre
            FROM ordenes_trabajo ot
            JOIN boom_listas bl ON bl.id = ot.boom_lista_id
            JOIN proyectos p ON p.id = ot.proyecto_id AND p.activo=1
            WHERE ot.estado='pendiente' AND ot.orden_posicion>0 AND ot.ots_liberadas=1
              AND EXISTS (
                SELECT 1 FROM ordenes_trabajo ot2
                WHERE ot2.boom_lista_id=ot.boom_lista_id
                  AND ot2.orden_posicion < ot.orden_posicion
                  AND ot2.estado='completado'
              )
              AND NOT EXISTS (
                SELECT 1 FROM ordenes_trabajo ot3
                WHERE ot3.boom_lista_id=ot.boom_lista_id
                  AND ot3.orden_posicion < ot.orden_posicion
                  AND ot3.estado IN ('pendiente','en_proceso')
              )
            ORDER BY ot.orden_posicion ASC LIMIT 5");
        foreach ($s_sig->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $items[] = [
                'tipo'=>'siguiente',
                'folio'=>htmlspecialchars($o['folio'] ?? ''),
                'titulo'=>htmlspecialchars($o['titulo'] ?? ''),
                'proyecto'=>htmlspecialchars($o['proyecto_nombre'] ?? ''),
                'operador_nombre'=>htmlspecialchars($o['operador_nombre'] ?? ''),
                'proceso'=>htmlspecialchars($o['proceso'] ?? ''),
            ];
        }

        // 5. Disponibles — liberadas, sin predecesora, boom y proyecto existen, proyecto activo
        $s_disp = $db->query("
            SELECT ot.folio, ot.titulo, ot.operador_nombre, ot.proceso, p.nombre AS proyecto_nombre
            FROM ordenes_trabajo ot
            JOIN boom_listas bl ON bl.id = ot.boom_lista_id
            JOIN proyectos p ON p.id = ot.proyecto_id AND p.activo=1
            WHERE ot.estado='pendiente' AND ot.ots_liberadas=1 AND ot.es_prioritaria=0
              AND (ot.orden_posicion=0 OR NOT EXISTS (
                SELECT 1 FROM ordenes_trabajo ot2
                WHERE ot2.boom_lista_id=ot.boom_lista_id
                  AND ot2.orden_posicion>0
                  AND ot2.orden_posicion < ot.orden_posicion
                  AND ot2.estado IN ('pendiente','en_proceso')
              ))
            ORDER BY ot.fecha_creacion ASC LIMIT 8");
        foreach ($s_disp->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $items[] = [
                'tipo'=>'disponible',
                'folio'=>htmlspecialchars($o['folio'] ?? ''),
                'titulo'=>htmlspecialchars($o['titulo'] ?? ''),
                'proyecto'=>htmlspecialchars($o['proyecto_nombre'] ?? ''),
                'operador_nombre'=>htmlspecialchars($o['operador_nombre'] ?? ''),
                'proceso'=>htmlspecialchars($o['proceso'] ?? ''),
            ];
        }

        // Si no hay nada: mensaje base
        if (empty($items)) {
            $items[] = ['tipo'=>'sin_ots','folio'=>'','titulo'=>'Sistema en espera — Sin órdenes de trabajo activas','proyecto'=>''];
        }

        _plogFlush($accion, 0, 'OK');
        echo json_encode(['ok' => true, 'items' => $items]);
        exit();
    }

    // ── MATERIALES + PLANO para preview ──────────────────────────────────────
    if ($accion === 'get_materiales') {
        $ot_id_m = intval($_POST['ot_id'] ?? 0);
        $stmt_m  = $db->prepare("SELECT ot.boom_lista_id, bl.plano_ruta, bl.pieza_nombre FROM ordenes_trabajo ot JOIN boom_listas bl ON bl.id=ot.boom_lista_id WHERE ot.id=?");
        $stmt_m->execute([$ot_id_m]);
        $ot_m = $stmt_m->fetch(PDO::FETCH_ASSOC);
        $mats = [];
        if ($ot_m) {
            $stmt_mat = $db->prepare("
                SELECT bi.descripcion, bi.cantidad, bi.unidad_medida, bi.marca
                FROM boom_items bi
                JOIN boom_listas bl_ia ON bi.boom_id = bl_ia.id
                WHERE bl_ia.padre_boom_id = ? AND bl_ia.tipo_lista = 'plano_ia'
                ORDER BY bi.id
            ");
            $stmt_mat->execute([$ot_m['boom_lista_id']]);
            $mats = $stmt_mat->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['ok'=>true, 'plano_ruta'=>$ot_m['plano_ruta']??null, 'pieza'=>$ot_m['pieza_nombre']??'', 'materiales'=>$mats]);
        exit();
    }

    // ── COMPLETAR PROCESO ─────────────────────────────────────────────────────
    if ($accion === 'completar_proceso') {
        try {
            $proc_id = intval($_POST['proc_id'] ?? 0);
            $ot_id_p = intval($_POST['ot_id'] ?? 0);
            $db->prepare("UPDATE ot_procesos SET estado='completado', fecha_fin=NOW() WHERE id=? AND ot_id=?")->execute([$proc_id, $ot_id_p]);
            $next = $db->prepare("SELECT id FROM ot_procesos WHERE ot_id=? AND estado='pendiente' ORDER BY orden ASC LIMIT 1");
            $next->execute([$ot_id_p]);
            $next_id = $next->fetchColumn();
            if ($next_id) {
                $db->prepare("UPDATE ot_procesos SET estado='activo', fecha_inicio=NOW() WHERE id=?")->execute([$next_id]);
                echo json_encode(['ok'=>true,'done'=>false,'next_id'=>$next_id]);
                exit();
            }

            // Último paso completado — auto-finalizar la OT (misma lógica que la acción 'terminar')
            $stmt_ot = $db->prepare("SELECT * FROM ordenes_trabajo WHERE id=?");
            $stmt_ot->execute([$ot_id_p]);
            $ot_cp = $stmt_ot->fetch(PDO::FETCH_ASSOC);
            if (!$ot_cp) {
                echo json_encode(['ok'=>true,'done'=>true]);
                exit();
            }

            $tiempos_cp = ['regular' => 0, 'extra' => 0];
            if (!$ot_cp['pausado']) {
                $lastLog_cp = getUltimoLogActivo($db, $ot_id_p);
                if ($lastLog_cp) {
                    $tiempos_cp = calcularTiempoBloque(new DateTime($lastLog_cp['timestamp']), new DateTime());
                }
            }

            $db->beginTransaction();
            $db->prepare("UPDATE ordenes_trabajo
                          SET estado='completado', pausado=0,
                              tiempo_regular_min = tiempo_regular_min + ?,
                              tiempo_extra_min   = tiempo_extra_min   + ?
                          WHERE id=?")
               ->execute([$tiempos_cp['regular'], $tiempos_cp['extra'], $ot_id_p]);
            $db->prepare("INSERT INTO ot_logs
                          (ot_id, usuario_id, accion, resumen, motivo_pausa, piezas_terminadas, observaciones, tiempo_regular_min, tiempo_extra_min)
                          VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$ot_id_p, $user_id, 'fin', 'Finalizado automáticamente', 'Pieza terminada', 0, '', $tiempos_cp['regular'], $tiempos_cp['extra']]);
            $db->commit();

            notificarRoles(
                ['manufactura'], 'ot_fabricada',
                "OT fabricada: " . ($ot_cp['titulo'] ?? ''),
                "El operario terminó la fabricación. Requiere Visto Bueno de Producción.",
                "produccion.php?seccion=ordenes", $user_id);
            avisarSiTiempoExcedido($db, $ot_id_p, $user_id);

            echo json_encode(['ok'=>true,'done'=>true,'estado'=>'fabricado']);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok'=>false,'error'=>'Error al completar: ' . $e->getMessage()]);
        }
        exit();
    }

    // ── AUTO-PAUSA / AUTO-REANUDACIÓN POR HORARIO (comida o fin de turno) ────────
    // Bug real encontrado y corregido (2026-08-20): estas 2 acciones actúan sobre
    // TODAS las OTs activas, no sobre una OT específica -- el JS que las dispara
    // (más abajo en este archivo) manda ot_id=0 a propósito. Vivían más adelante en
    // este mismo switch, DESPUÉS del gate "requiere OT específica" (línea de abajo),
    // que hace SELECT ... WHERE id=? y corta con error si no encuentra la OT --
    // con ot_id=0 nunca la encontraba, así que el mecanismo completo de auto-pausa/
    // reanudación por horario nunca llegó a ejecutarse ni una sola vez desde que se
    // implementó. Se mueven aquí arriba, junto a 'ticker'/'completar_proceso', que
    // son las otras acciones que tampoco requieren una OT específica.
    if ($accion === 'auto_pausar') {
        $tipo   = sanitize($_POST['tipo'] ?? 'turno');
        $motivo = $tipo === 'comida' ? 'Horario automático — comida' : 'Horario automático — fin de turno';
        $activas = $db->query("SELECT id FROM ordenes_trabajo WHERE estado='en_proceso' AND pausado=0");
        $count = 0;
        $db->beginTransaction();
        foreach ($activas->fetchAll(PDO::FETCH_ASSOC) as $ot_a) {
            $lastLog = getUltimoLogActivo($db, $ot_a['id']);
            $tiempos = ['regular' => 0, 'extra' => 0];
            if ($lastLog) {
                $tiempos = calcularTiempoBloque(new DateTime($lastLog['timestamp']), new DateTime());
            }
            $db->prepare("UPDATE ordenes_trabajo SET pausado=1, tiempo_regular_min=tiempo_regular_min+?, tiempo_extra_min=tiempo_extra_min+? WHERE id=?")
               ->execute([$tiempos['regular'], $tiempos['extra'], $ot_a['id']]);
            $db->prepare("INSERT INTO ot_logs (ot_id, usuario_id, accion, resumen, motivo_pausa, tiempo_regular_min, tiempo_extra_min) VALUES (?,?,?,?,?,?,?)")
               ->execute([$ot_a['id'], $user_id, 'pausa', 'Pausa automática por horario', $motivo, $tiempos['regular'], $tiempos['extra']]);
            $count++;
        }
        $db->commit();
        _plogFlush($accion, 0, 'OK');
        echo json_encode(['ok' => true, 'count' => $count, 'mensaje' => "Pausadas $count OT(s): $motivo."]);
        exit();
    }

    if ($accion === 'auto_reanudar') {
        $pausadas = $db->query("
            SELECT ot.id FROM ordenes_trabajo ot
            JOIN (SELECT ot_id, MAX(id) AS lid FROM ot_logs GROUP BY ot_id) lm ON lm.ot_id = ot.id
            JOIN ot_logs ol ON ol.id = lm.lid
            WHERE ot.estado='en_proceso' AND ot.pausado=1
              AND ol.accion='pausa' AND ol.motivo_pausa LIKE 'Horario automático%'
        ");
        $count = 0;
        $db->beginTransaction();
        foreach ($pausadas->fetchAll(PDO::FETCH_ASSOC) as $ot_p) {
            $lastPauseStmt = $db->prepare("SELECT timestamp FROM ot_logs WHERE ot_id=? AND accion='pausa' ORDER BY id DESC LIMIT 1");
            $lastPauseStmt->execute([$ot_p['id']]);
            $pauseTs = $lastPauseStmt->fetchColumn();
            $paused_min = $pauseTs ? max(0, (int)round((time() - strtotime($pauseTs)) / 60)) : 0;
            $db->prepare("UPDATE ordenes_trabajo SET pausado=0, en_stand_by=0, tiempo_pausado_min=tiempo_pausado_min+? WHERE id=?")
               ->execute([$paused_min, $ot_p['id']]);
            $db->prepare("INSERT INTO ot_logs (ot_id, usuario_id, accion, operador_nombre) VALUES (?,?,?,?)")
               ->execute([$ot_p['id'], $user_id, 'reanudacion', 'Sistema automático']);
            $count++;
        }
        $db->commit();
        _plogFlush($accion, 0, 'OK');
        echo json_encode(['ok' => true, 'count' => $count, 'mensaje' => "Reanudadas $count OT(s) por horario."]);
        exit();
    }

    // ── Para el resto: requiere OT específica ─────────────────────────────────
    // Admin → cualquier OT.
    // Iniciar → solo OTs sin asignar o propias (para no robar el inicio de otro).
    // Pausar/reanudar/terminar → cualquier OT visible al operador (liberada o propia).
    if ($puede_ver_detalles) {
        $stmt = $db->prepare("SELECT * FROM ordenes_trabajo WHERE id=?");
        $stmt->execute([$ot_id]);
    } elseif ($accion === 'iniciar') {
        $stmt = $db->prepare(
            "SELECT * FROM ordenes_trabajo WHERE id=? AND (operario_id=? OR operario_id IS NULL)");
        $stmt->execute([$ot_id, $user_id]);
    } else {
        // pausar / reanudar / terminar: OT visible = liberada o propia
        $stmt = $db->prepare(
            "SELECT * FROM ordenes_trabajo WHERE id=? AND (ots_liberadas=1 OR operario_id=? OR operario_id IS NULL)");
        $stmt->execute([$ot_id, $user_id]);
    }
    $ot = $stmt->fetch(PDO::FETCH_ASSOC);
    _plog('SELECT OT', $ot ? "encontrada estado={$ot['estado']} pausado={$ot['pausado']}" : 'NO ENCONTRADA');

    if (!$ot) {
        _plogFlush($accion, $ot_id, 'OT_NO_ENCONTRADA');
        echo json_encode(['ok' => false, 'error' => 'OT no encontrada']);
        exit();
    }

    // ── INICIAR ──────────────────────────────────────────────────────────────
    if ($accion === 'iniciar') {
        $operador  = sanitize($_POST['operador']  ?? '');
        $maquina   = sanitize($_POST['maquina']   ?? '');
        $actividad = sanitize($_POST['actividad'] ?? '');

        // Verificar que no haya una OT anterior en la misma cola sin completar
        // (el admin puede forzar el inicio enviando forzar=1)
        $forzar = intval($_POST['forzar'] ?? 0);
        if ($ot['orden_posicion'] > 0 && !($forzar && $puede_ver_detalles)) {
            // Bloquear solo dentro de la misma página; si no tiene página, usar plano completo
            if (!empty($ot['plano_pagina_id'])) {
                $blk = $db->prepare("
                    SELECT titulo FROM ordenes_trabajo
                    WHERE plano_pagina_id = ?
                      AND id != ?
                      AND orden_posicion > 0
                      AND orden_posicion < ?
                      AND estado IN ('pendiente','en_proceso')
                    ORDER BY orden_posicion ASC
                    LIMIT 1
                ");
                $blk->execute([$ot['plano_pagina_id'], $ot_id, $ot['orden_posicion']]);
            } else {
                $blk = $db->prepare("
                    SELECT titulo FROM ordenes_trabajo
                    WHERE boom_lista_id = ?
                      AND id != ?
                      AND orden_posicion > 0
                      AND orden_posicion < ?
                      AND estado IN ('pendiente','en_proceso')
                    ORDER BY orden_posicion ASC
                    LIMIT 1
                ");
                $blk->execute([$ot['boom_lista_id'], $ot_id, $ot['orden_posicion']]);
            }
            if ($bloq = $blk->fetch(PDO::FETCH_ASSOC)) {
                _plogFlush($accion, $ot_id, 'BLOQUEADA');
                echo json_encode([
                    'ok'    => false,
                    'bloqueada' => true,
                    'error' => "Esperando que \"" . $bloq['titulo'] . "\" se complete primero."
                ]);
                exit();
            }
        }

        // Verificar que la máquina no esté ocupada por otra OT en curso
        // (alcance global — cualquier página/proyecto; el admin puede forzar con forzar=1)
        $maquina_actual = trim($ot['maquina'] ?? '');
        if ($maquina_actual !== '' && !($forzar && $puede_ver_detalles)) {
            $blkMaq = $db->prepare("
                SELECT titulo FROM ordenes_trabajo
                WHERE maquina = ?
                  AND id != ?
                  AND estado = 'en_proceso'
                LIMIT 1
            ");
            $blkMaq->execute([$maquina_actual, $ot_id]);
            if ($ocupante = $blkMaq->fetch(PDO::FETCH_ASSOC)) {
                _plogFlush($accion, $ot_id, 'MAQUINA_OCUPADA');
                echo json_encode([
                    'ok'    => false,
                    'bloqueada' => true,
                    'error' => "La máquina \"$maquina_actual\" está ocupada por \"" . $ocupante['titulo'] . "\"."
                ]);
                exit();
            }
        }

        _plog('beginTransaction');
        $db->beginTransaction();
        _plog('UPDATE ordenes_trabajo — antes');
        $db->prepare("UPDATE ordenes_trabajo
                      SET estado='en_proceso', fecha_inicio_real=NOW(), pausado=0, operario_id=?, en_stand_by=0
                      WHERE id=? AND estado='pendiente'")
           ->execute([$user_id, $ot_id]);
        // Poner en stand-by otras OTs activas del mismo operario,
        // solo si están en la misma página (páginas distintas corren en paralelo)
        if (!empty($ot['plano_pagina_id'])) {
            $db->prepare("UPDATE ordenes_trabajo SET pausado=1, en_stand_by=1
                          WHERE id!=? AND operario_id=? AND estado='en_proceso' AND pausado=0
                            AND (plano_pagina_id = ? OR plano_pagina_id IS NULL OR plano_pagina_id = 0)")
               ->execute([$ot_id, $user_id, $ot['plano_pagina_id']]);
        } else {
            $db->prepare("UPDATE ordenes_trabajo SET pausado=1, en_stand_by=1
                          WHERE id!=? AND operario_id=? AND estado='en_proceso' AND pausado=0")
               ->execute([$ot_id, $user_id]);
        }
        _plog('UPDATE ordenes_trabajo — hecho');

        _plog('INSERT ot_logs — antes');
        $db->prepare("INSERT INTO ot_logs
                      (ot_id, usuario_id, accion, operador_nombre, maquina, actividad)
                      VALUES (?,?,?,?,?,?)")
           ->execute([$ot_id, $user_id, 'inicio', $operador, $maquina, $actividad]);
        _plog('INSERT ot_logs — hecho');
        $db->commit();
        _plog('commit — hecho');
        marcarAsistenciaActiva($db, $user_id);

        // Antes este bloque no avisaba a nadie al arrancar una OT -- Producción solo se
        // enteraba al terminar (ot_fabricada) o si había un problema (ot_problema), nunca
        // cuando de verdad se ponía en marcha una máquina.
        notificarRoles(
            ['manufactura'], 'ot_iniciada',
            "OT iniciada: " . ($ot['titulo'] ?? ''),
            "Se inició la fabricación" . ($maquina !== '' ? " en \"$maquina\"" : '') . ($operador !== '' ? " (operador: $operador)" : '') . '.',
            "produccion.php?seccion=ver_proceso", $user_id);

        _plogFlush($accion, $ot_id, 'OK');
        echo json_encode(['ok' => true, 'estado' => 'en_proceso', 'pausado' => 0,
                          'hora_inicio' => date('H:i')]);

    // ── PAUSAR ───────────────────────────────────────────────────────────────
    } elseif ($accion === 'pausar') {
        try {
            _plog('pausar — inicio');
            $resumen       = sanitize($_POST['resumen']       ?? '');
            $motivo        = sanitize($_POST['motivo']        ?? '');
            $piezas        = intval($_POST['piezas']          ?? 0);
            $observaciones = sanitize($_POST['observaciones'] ?? '');

            _plog('getUltimoLogActivo — antes');
            $lastLog  = getUltimoLogActivo($db, $ot_id);
            _plog('getUltimoLogActivo — hecho', $lastLog ? "ts={$lastLog['timestamp']}" : 'sin log activo');
            $tiempos  = ['regular' => 0, 'extra' => 0];
            if ($lastLog) {
                $tiempos = calcularTiempoBloque(
                    new DateTime($lastLog['timestamp']), new DateTime());
            }
            _plog('calcularTiempoBloque — hecho', "reg={$tiempos['regular']} ext={$tiempos['extra']}");

            _plog('beginTransaction');
            $db->beginTransaction();
            _plog('UPDATE ordenes_trabajo — antes');
            $db->prepare("UPDATE ordenes_trabajo
                          SET pausado=1,
                              tiempo_regular_min = tiempo_regular_min + ?,
                              tiempo_extra_min   = tiempo_extra_min   + ?
                          WHERE id=? AND estado='en_proceso'")
               ->execute([$tiempos['regular'], $tiempos['extra'], $ot_id]);
            _plog('UPDATE ordenes_trabajo — hecho');

            _plog('INSERT ot_logs — antes');
            $db->prepare("INSERT INTO ot_logs
                          (ot_id, usuario_id, accion, resumen, motivo_pausa, piezas_terminadas,
                           observaciones, tiempo_regular_min, tiempo_extra_min)
                          VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$ot_id, $user_id, 'pausa', $resumen, $motivo, $piezas,
                          $observaciones, $tiempos['regular'], $tiempos['extra']]);
            _plog('INSERT ot_logs — hecho');
            $db->commit();
            _plog('commit — hecho');

            $stmt_pn2 = $db->prepare("SELECT titulo FROM ordenes_trabajo WHERE id=?");
            $stmt_pn2->execute([$ot_id]);
            $ot_data2 = $stmt_pn2->fetch();
            notificarRoles(
                ['manufactura'], 'ot_pausada',
                "OT pausada: " . ($ot_data2['titulo'] ?? ''),
                "Se pausó la fabricación" . ($motivo !== '' ? " ($motivo)" : '') . '.',
                "produccion.php?seccion=ver_proceso", $user_id);

            _plogFlush($accion, $ot_id, 'OK');
            echo json_encode(['ok' => true, 'estado' => 'en_proceso', 'pausado' => 1, // nosemgrep
                              'regular' => $tiempos['regular'], 'extra' => $tiempos['extra']]);
        } catch (\Throwable $e) {
            _plog('EXCEPCION', $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            _plogFlush($accion, $ot_id, 'ERROR');
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Error al pausar: ' . $e->getMessage()]);
        }

    // ── REANUDAR ─────────────────────────────────────────────────────────────
    } elseif ($accion === 'reanudar') {
        try {
            _plog('reanudar — inicio');
            $operador  = sanitize($_POST['operador']  ?? '');
            $maquina   = sanitize($_POST['maquina']   ?? '');
            $actividad = sanitize($_POST['actividad'] ?? '');

            // Calcular tiempo pausado desde el último log de pausa
            $lastPauseStmt = $db->prepare("SELECT timestamp FROM ot_logs WHERE ot_id=? AND accion='pausa' ORDER BY id DESC LIMIT 1");
            $lastPauseStmt->execute([$ot_id]);
            $pauseTs = $lastPauseStmt->fetchColumn();
            $paused_min = 0;
            if ($pauseTs) {
                $paused_min = max(0, (int)round((time() - strtotime($pauseTs)) / 60));
            }

            _plog('beginTransaction');
            $db->beginTransaction();
            _plog('UPDATE ordenes_trabajo — antes');
            $db->prepare("UPDATE ordenes_trabajo SET pausado=0, en_stand_by=0, tiempo_pausado_min=tiempo_pausado_min+? WHERE id=? AND estado='en_proceso'")
               ->execute([$paused_min, $ot_id]);
            // Poner en stand-by otras OTs activas (respetando páginas independientes)
            if (!empty($ot['plano_pagina_id'])) {
                $db->prepare("UPDATE ordenes_trabajo SET pausado=1, en_stand_by=1
                              WHERE id!=? AND operario_id=? AND estado='en_proceso' AND pausado=0
                                AND (plano_pagina_id = ? OR plano_pagina_id IS NULL OR plano_pagina_id = 0)")
                   ->execute([$ot_id, $user_id, $ot['plano_pagina_id']]);
            } else {
                $db->prepare("UPDATE ordenes_trabajo SET pausado=1, en_stand_by=1
                              WHERE id!=? AND operario_id=? AND estado='en_proceso' AND pausado=0")
                   ->execute([$ot_id, $user_id]);
            }
            _plog('UPDATE — hecho');

            _plog('INSERT ot_logs — antes');
            $db->prepare("INSERT INTO ot_logs
                          (ot_id, usuario_id, accion, operador_nombre, maquina, actividad)
                          VALUES (?,?,?,?,?,?)")
               ->execute([$ot_id, $user_id, 'reanudacion', $operador, $maquina, $actividad]);
            _plog('INSERT ot_logs — hecho');
            $db->commit();
            _plog('commit — hecho');
            marcarAsistenciaActiva($db, $user_id);

            _plogFlush($accion, $ot_id, 'OK');
            echo json_encode(['ok' => true, 'estado' => 'en_proceso', 'pausado' => 0,
                              'hora_inicio' => date('H:i')]);
        } catch (\Throwable $e) {
            _plog('EXCEPCION', $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            _plogFlush($accion, $ot_id, 'ERROR');
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Error al reanudar: ' . $e->getMessage()]);
        }

    // ── TERMINAR ─────────────────────────────────────────────────────────────
    } elseif ($accion === 'terminar') {
        try {
            _plog('terminar — inicio');
            $resumen       = sanitize($_POST['resumen']       ?? '');
            $motivo        = sanitize($_POST['motivo']        ?? '');
            $piezas        = intval($_POST['piezas']          ?? 0);
            $observaciones = sanitize($_POST['observaciones'] ?? '');

            $tiempos = ['regular' => 0, 'extra' => 0];
            if (!$ot['pausado']) {
                _plog('getUltimoLogActivo — antes');
                $lastLog = getUltimoLogActivo($db, $ot_id);
                _plog('getUltimoLogActivo — hecho', $lastLog ? "ts={$lastLog['timestamp']}" : 'sin log');
                if ($lastLog) {
                    $tiempos = calcularTiempoBloque(
                        new DateTime($lastLog['timestamp']), new DateTime());
                }
                _plog('calcularTiempoBloque — hecho', "reg={$tiempos['regular']} ext={$tiempos['extra']}");
            }

            _plog('beginTransaction');
            $db->beginTransaction();
            _plog('UPDATE ordenes_trabajo — antes');
            $db->prepare("UPDATE ordenes_trabajo
                          SET estado='completado', pausado=0,
                              tiempo_regular_min = tiempo_regular_min + ?,
                              tiempo_extra_min   = tiempo_extra_min   + ?
                          WHERE id=?")
               ->execute([$tiempos['regular'], $tiempos['extra'], $ot_id]);
            _plog('UPDATE — hecho');

            _plog('INSERT ot_logs — antes');
            $db->prepare("INSERT INTO ot_logs
                          (ot_id, usuario_id, accion, resumen, motivo_pausa, piezas_terminadas,
                           observaciones, tiempo_regular_min, tiempo_extra_min)
                          VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$ot_id, $user_id, 'fin', $resumen, $motivo, $piezas,
                          $observaciones, $tiempos['regular'], $tiempos['extra']]);
            _plog('INSERT ot_logs — hecho');
            $db->commit();
            _plog('commit — hecho');

            _plog('notificarRoles — antes');
            $stmt_pn = $db->prepare("SELECT titulo FROM ordenes_trabajo WHERE id=?");
            $stmt_pn->execute([$ot_id]);
            $ot_data = $stmt_pn->fetch();
            notificarRoles(
                ['manufactura'], 'ot_fabricada',
                "OT fabricada: " . ($ot_data['titulo'] ?? ''),
                "El operario terminó la fabricación. Requiere Visto Bueno de Producción.",
                "produccion.php?seccion=ordenes", $user_id);
            avisarSiTiempoExcedido($db, $ot_id, $user_id);
            _plog('notificarRoles — hecho');

            _plogFlush($accion, $ot_id, 'OK');
            echo json_encode(['ok' => true, 'estado' => 'fabricado']);
        } catch (\Throwable $e) {
            _plog('EXCEPCION', $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            _plogFlush($accion, $ot_id, 'ERROR');
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Error al finalizar: ' . $e->getMessage()]);
        }

    // ── MARCAR PROBLEMA ──────────────────────────────────────────────────────
    } elseif ($accion === 'marcar_problema') {
        $desc = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
        $db->prepare("UPDATE ordenes_trabajo SET en_problema=1, problema_descripcion=?, estado='en_proceso', pausado=1 WHERE id=?")->execute([$desc, $ot_id]);
        notificarRoles(['manufactura','compras'], 'ot_problema', "OT con problema", "OT pausada por problema: $desc", "produccion.php?seccion=ver_proceso", $user_id);
        _plogFlush($accion, $ot_id, 'OK');
        echo json_encode(['ok'=>true, 'estado'=>'en_proceso', 'pausado'=>1]);
        exit();

    // ── LOGS (solo admin) ─────────────────────────────────────────────────────
    } elseif ($accion === 'logs' && $puede_ver_detalles) {
        $rows = $db->prepare(
            "SELECT l.*, u.nombre_completo AS usuario_nombre
             FROM ot_logs l
             LEFT JOIN usuarios u ON u.id = l.usuario_id
             WHERE l.ot_id = ?
             ORDER BY l.timestamp ASC");
        $rows->execute([$ot_id]);
        $logs = $rows->fetchAll(PDO::FETCH_ASSOC);

        // Calcular horas muertas (tiempo en pausa dentro del horario laboral)
        $muerto_min = 0;
        $pausa_dt   = null;
        foreach ($logs as $log) {
            if ($log['accion'] === 'pausa') {
                $pausa_dt = new DateTime($log['timestamp']);
            } elseif (in_array($log['accion'], ['reanudacion','fin']) && $pausa_dt) {
                $t = calcularTiempoBloque($pausa_dt, new DateTime($log['timestamp']));
                $muerto_min += $t['regular'];
                $pausa_dt = null;
            }
        }

        // Totales de la OT
        $tot = $db->prepare(
            "SELECT titulo, maquina, proceso, tiempo_regular_min, tiempo_extra_min
             FROM ordenes_trabajo WHERE id=?");
        $tot->execute([$ot_id]);
        $totales = $tot->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'         => true,
            'logs'       => $logs,
            'muerto_min' => $muerto_min,
            'totales'    => $totales,
        ]);
    }
    exit();
}

// ── Proyectos con OTs visibles (para sidebar) — proyecto activo y boom existente ──
$sidebar_proyectos = $db->query("
    SELECT DISTINCT p.id, p.nombre
    FROM proyectos p
    JOIN ordenes_trabajo ot ON ot.proyecto_id = p.id
    JOIN boom_listas bl ON bl.id = ot.boom_lista_id
    WHERE ot.estado IN ('pendiente','en_proceso')
      AND (ot.ots_liberadas = 1 OR ot.operario_id IS NOT NULL)
      AND p.activo = 1
    ORDER BY p.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// ── Cargar OTs ─────────────────────────────────────────────────────────────────
// Se muestran TODAS las OTs liberadas (visibles para todos) +
// las que este usuario ya inició (por si el admin las movió antes de liberar).
// La columna operario_id ya NO filtra visibilidad — solo controla quién opera.
$stmt = $db->prepare("
    SELECT ot.*, bl.pieza_nombre, bl.plano_ruta, p.nombre AS proyecto_nombre,
           pp.nombre AS pagina_nombre,
           u.nombre_completo AS responsable_nombre,
           uop.nombre_completo AS operario_nombre_carg,
           COALESCE(
               (SELECT operador_nombre FROM ot_logs
                WHERE ot_id=ot.id AND accion IN ('inicio','reanudacion')
                ORDER BY timestamp DESC LIMIT 1),
               ot.operador_nombre
           ) AS ultimo_operador,
           COALESCE(
               (SELECT maquina FROM ot_logs
                WHERE ot_id=ot.id AND accion IN ('inicio','reanudacion')
                ORDER BY timestamp DESC LIMIT 1),
               ot.maquina
           ) AS ultima_maquina,
           COALESCE(
               (SELECT timestamp FROM ot_logs
                WHERE ot_id=ot.id AND accion IN ('inicio','reanudacion')
                ORDER BY timestamp DESC LIMIT 1),
               ot.fecha_inicio_real
           ) AS ultimo_inicio_ts
    FROM ordenes_trabajo ot
    JOIN boom_listas bl ON bl.id = ot.boom_lista_id
    JOIN proyectos p ON p.id = ot.proyecto_id
    LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
    LEFT JOIN usuarios u   ON u.id   = ot.responsable_id
    LEFT JOIN usuarios uop ON uop.id = ot.operario_id
    WHERE ot.estado IN ('pendiente','en_proceso')
      AND (ot.ots_liberadas = 1 OR ot.operario_id = ?)
      AND p.activo = 1
    ORDER BY
        ot.es_prioritaria DESC,
        FIELD(ot.estado,'en_proceso','pendiente'),
        ot.boom_lista_id ASC,
        CASE WHEN ot.orden_posicion = 0 THEN 9999 ELSE ot.orden_posicion END ASC,
        ot.fecha_creacion ASC
");
$stmt->execute([$user_id]);
$mis_ots = $stmt->fetchAll();

// ── Calcular OTs bloqueadas por orden de cola ────────────────────────────────
// Agrupa por plano_pagina_id (si tiene página) o boom_lista_id (sin página).
// OTs de páginas distintas corren en paralelo sin bloquearse entre sí.
$ot_bloqueada_map = []; // ot_id => titulo del bloqueante

$seq_grupos = [];
foreach ($mis_ots as $ot_g) {
    // Clave de agrupación: por página si está asignada, si no por plano completo
    $gkey = $ot_g['plano_pagina_id']
        ? 'pag_' . $ot_g['plano_pagina_id']
        : 'bl_'  . $ot_g['boom_lista_id'];
    $seq_grupos[$gkey][] = $ot_g;
}
foreach ($seq_grupos as $gkey => $grupo) {
    $tiene_orden = count(array_filter($grupo, fn($o) => $o['orden_posicion'] > 0)) > 0;
    if (!$tiene_orden || count($grupo) < 2) continue;

    usort($grupo, fn($a, $b) =>
        ($a['orden_posicion'] ?: PHP_INT_MAX) <=> ($b['orden_posicion'] ?: PHP_INT_MAX)
    );

    $primer_activo_encontrado = false;
    $bloqueante_titulo = '';
    foreach ($grupo as $ot_g) {
        if (in_array($ot_g['estado'], ['completado','cancelado'])) continue;
        if (!$primer_activo_encontrado) {
            $primer_activo_encontrado = true;
            $bloqueante_titulo = $ot_g['titulo'];
        } else {
            if ($ot_g['estado'] === 'pendiente') {
                $ot_bloqueada_map[$ot_g['id']] = $bloqueante_titulo;
            }
        }
    }
}

// ── Calcular OTs bloqueadas por OT prioritaria en la misma página ────────────
// Si una página (o plano sin página) tiene alguna OT prioritaria activa
// (pendiente o en_proceso), las demás OTs pendientes NO prioritarias de esa
// misma página quedan bloqueadas. No pausa ninguna OT ya en curso.
$ot_bloqueada_prioridad_map = []; // ot_id => titulo de la OT prioritaria

foreach ($seq_grupos as $gkey => $grupo) {
    $prioritaria_activa = null;
    foreach ($grupo as $ot_g) {
        if (!empty($ot_g['es_prioritaria']) && in_array($ot_g['estado'], ['pendiente','en_proceso'])) {
            $prioritaria_activa = $ot_g;
            break;
        }
    }
    if (!$prioritaria_activa) continue;

    foreach ($grupo as $ot_g) {
        if ($ot_g['estado'] === 'pendiente' && empty($ot_g['es_prioritaria'])) {
            $ot_bloqueada_prioridad_map[$ot_g['id']] = $prioritaria_activa['titulo'];
        }
    }
}

// ── Calcular OTs bloqueadas por máquina compartida (alcance global) ──────────
// Si una máquina tiene alguna OT en_proceso (pausada o no — pausar NO libera
// la máquina), las demás OTs pendientes que usan esa misma máquina, sin
// importar página ni proyecto, quedan bloqueadas.
$ot_bloqueada_maquina_map = []; // ot_id => ['maquina'=>, 'titulo'=>, 'operador'=>]

$maquina_grupos = [];
foreach ($mis_ots as $ot_g) {
    $maq = trim($ot_g['maquina'] ?? '');
    if ($maq === '') continue;
    $maquina_grupos[$maq][] = $ot_g;
}
foreach ($maquina_grupos as $maq => $grupo) {
    $ocupante = null;
    foreach ($grupo as $ot_g) {
        if ($ot_g['estado'] === 'en_proceso') {
            $ocupante = $ot_g;
            break;
        }
    }
    if (!$ocupante) continue;

    foreach ($grupo as $ot_g) {
        if ($ot_g['estado'] === 'pendiente') {
            $ot_bloqueada_maquina_map[$ot_g['id']] = [
                'maquina'  => $maq,
                'titulo'   => $ocupante['titulo'],
                'operador' => $ocupante['operador_nombre'] ?? '',
            ];
        }
    }
}

// Cargar ot_procesos para cada OT activa
$ot_procesos_map = [];
$ot_pasos_reales_map = [];
foreach ($mis_ots as $mota) {
    if ($mota['estado'] === 'en_proceso') {
        $sp = $db->prepare("SELECT * FROM ot_procesos WHERE ot_id=? ORDER BY orden ASC");
        $sp->execute([$mota['id']]);
        $procs = $sp->fetchAll(PDO::FETCH_ASSOC);
        $ot_pasos_reales_map[$mota['id']] = !empty($procs);
        // Si no tiene procesos pero tiene campo proceso, crear uno virtual activo
        if (empty($procs) && !empty($mota['proceso'])) {
            $procs = [['id'=>0,'nombre'=>$mota['proceso'],'estado'=>'activo','orden'=>1]];
        }
        $ot_procesos_map[$mota['id']] = $procs;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<link rel="icon" href="favicon.ico?v=3">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Proceso — EMPRESA EJEMPLO</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; user-select:none; }
html, body { height:100%; overflow:hidden; font-family:'Montserrat',sans-serif; background:#0d1b2a; color:#fff; }

/* ── HEADER ── */
.proc-header {
    position:fixed; top:0; left:0; right:0; height:70px; z-index:100;
    background:linear-gradient(135deg,#003d5c,#005686);
    display:flex; align-items:center; justify-content:space-between;
    padding:0 24px; box-shadow:0 2px 16px rgba(0,0,0,.4);
}
.proc-header-logo { height:44px; object-fit:contain; }
.proc-header-center { display:flex; flex-direction:column; align-items:center; }
.proc-header-title { font-size:1rem; font-weight:700; color:#fff; letter-spacing:1px; }
.proc-clock { font-size:1.5rem; font-weight:800; color:#FF9800; letter-spacing:2px; font-variant-numeric:tabular-nums; }
.proc-header-right { display:flex; align-items:center; gap:12px; }
.proc-avatar { width:42px; height:42px; border-radius:50%; background:#FF9800; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.1rem; flex-shrink:0; }
.proc-username { font-size:.85rem; font-weight:600; color:rgba(255,255,255,.9); }
.proc-logout { background:rgba(244,67,54,.15); border:1.5px solid rgba(244,67,54,.5); color:#ef9a9a; border-radius:8px; padding:8px 14px; font-size:.8rem; font-weight:700; cursor:pointer; font-family:'Montserrat',sans-serif; transition:all .2s; }
.proc-logout:hover { background:rgba(244,67,54,.3); }

/* ── SCROLL BODY ── */
.proc-body { height:100vh; padding-top:106px; overflow-y:auto; padding-bottom:20px; }

/* ── EMPTY ── */
.proc-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; height:calc(100vh - 70px); gap:16px; color:rgba(255,255,255,.3); }
.proc-empty i { font-size:5rem; }
.proc-empty p { font-size:1.2rem; font-weight:600; }
.proc-empty small { font-size:.9rem; }

/* ── GRID ── */
.proc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(440px,1fr)); gap:20px; padding:20px; }

/* ── OT CARD ── */
.proc-card {
    background:#1a2d3e; border-radius:20px; overflow:hidden;
    box-shadow:0 8px 32px rgba(0,0,0,.4);
    border:2px solid rgba(255,255,255,.08);
    display:flex; flex-direction:column;
}
.proc-card.estado-pendiente         { border-top:5px solid #FF9800; }
.proc-card.estado-en_proceso.activo { border-top:5px solid #4caf50; }
.proc-card.estado-en_proceso.pausado{ border-top:5px solid #f44336; }

/* Card top */
.proc-card-top { padding:18px 20px 12px; }
.proc-card-folio { font-size:.72rem; font-weight:800; color:#FF9800; letter-spacing:1px; margin-bottom:6px; text-transform:uppercase; }
.proc-card-titulo { font-size:1.1rem; font-weight:800; color:#fff; line-height:1.3; margin-bottom:8px; }
.proc-card-meta { display:flex; flex-wrap:wrap; gap:8px; }
.proc-card-tag { display:flex; align-items:center; gap:5px; font-size:.78rem; color:rgba(255,255,255,.6); background:rgba(255,255,255,.06); padding:4px 10px; border-radius:20px; }

/* ── STATUS STRIP (reemplaza al cronómetro) ── */
.proc-status-strip {
    margin:0 20px 14px;
    background:rgba(0,0,0,.3);
    border-radius:12px;
    padding:12px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.proc-status-badge {
    font-size:.8rem; font-weight:800; padding:6px 16px;
    border-radius:20px; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;
}
.badge-pendiente { background:#FF9800; color:#000; }
.badge-activo    { background:#4caf50; color:#fff; }
.badge-pausado   { background:#f44336; color:#fff; }

.proc-status-detail { flex:1; }
.proc-status-line { font-size:.72rem; color:rgba(255,255,255,.45); text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
.proc-status-val { font-size:.88rem; font-weight:700; color:rgba(255,255,255,.85); }

/* Acumulado mini */
.proc-acum {
    display:flex; gap:10px; margin:0 20px 10px;
}
.proc-acum-item {
    flex:1; background:rgba(255,255,255,.04); border-radius:8px; padding:7px 10px; text-align:center;
}
.proc-acum-label { font-size:.62rem; color:rgba(255,255,255,.4); text-transform:uppercase; letter-spacing:.4px; }
.proc-acum-val { font-size:.9rem; font-weight:800; margin-top:2px; }
.acum-regular { color:#4caf50; }
.acum-extra   { color:#FF9800; }

/* Buttons */
.proc-btns { padding:0 16px 16px; display:flex; gap:10px; flex-wrap:wrap; }
.proc-btn {
    flex:1; min-width:120px; height:72px; border:none; border-radius:14px;
    font-family:'Montserrat',sans-serif; font-size:1rem; font-weight:800;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    gap:8px; transition:transform .1s, box-shadow .1s; letter-spacing:.5px;
    text-transform:uppercase;
}
.proc-btn:active { transform:scale(.96); }
.proc-btn-inicio    { background:linear-gradient(135deg,#4caf50,#2e7d32); color:#fff; box-shadow:0 4px 20px rgba(76,175,80,.4); }
.proc-btn-pausa     { background:linear-gradient(135deg,#ff9800,#e65100); color:#fff; box-shadow:0 4px 20px rgba(255,152,0,.4); }
.proc-btn-reanudar  { background:linear-gradient(135deg,#2196f3,#1565c0); color:#fff; box-shadow:0 4px 20px rgba(33,150,243,.4); }
.proc-btn-terminado { background:linear-gradient(135deg,#e91e63,#880e4f); color:#fff; box-shadow:0 4px 20px rgba(233,30,99,.4); }
.proc-btn i { font-size:1.3rem; }
.proc-btn[disabled] { opacity:.4; cursor:not-allowed; }

/* Done overlay */
.proc-card-done { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px; gap:8px; }
.proc-card-done i { font-size:3rem; color:#4caf50; }
.proc-card-done p { font-size:.9rem; color:rgba(255,255,255,.5); text-align:center; }

/* ── MODAL OVERLAY ── */
.modal-overlay {
    position:fixed; inset:0; background:rgba(0,0,0,.75);
    display:none; align-items:center; justify-content:center;
    z-index:1000; padding:20px;
}
.modal-overlay.active { display:flex; }

.modal-box {
    background:#1a2d3e; border-radius:20px; width:100%; max-width:520px;
    max-height:90vh; overflow-y:auto;
    box-shadow:0 20px 60px rgba(0,0,0,.6);
    border:1.5px solid rgba(255,255,255,.1);
}
.modal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:20px 24px 16px;
    border-bottom:1px solid rgba(255,255,255,.08);
}
.modal-title { font-size:1.1rem; font-weight:800; color:#fff; }
.modal-close { background:none; border:none; color:rgba(255,255,255,.5); font-size:1.4rem; cursor:pointer; }
.modal-close:hover { color:#fff; }

.modal-body { padding:20px 24px; display:flex; flex-direction:column; gap:16px; }

.form-group { display:flex; flex-direction:column; gap:6px; }
.form-label { font-size:.75rem; font-weight:700; color:rgba(255,255,255,.55); text-transform:uppercase; letter-spacing:.5px; }
.form-label .req { color:#f44336; }

.form-input, .form-select, .form-textarea {
    background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.12);
    border-radius:10px; color:#fff; font-family:'Montserrat',sans-serif;
    font-size:.92rem; padding:12px 14px; width:100%;
    transition:border-color .2s;
}
.form-input::placeholder, .form-textarea::placeholder { color:rgba(255,255,255,.3); }
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline:none; border-color:#4caf50; background:rgba(76,175,80,.08);
}
.form-select option { background:#1a2d3e; color:#fff; }
.form-textarea { resize:vertical; min-height:80px; }

.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

.modal-footer {
    padding:16px 24px 20px;
    border-top:1px solid rgba(255,255,255,.08);
    display:flex; gap:10px; justify-content:flex-end;
}
.btn-cancel {
    background:rgba(255,255,255,.08); border:none; border-radius:10px;
    color:rgba(255,255,255,.7); padding:12px 22px; font-family:'Montserrat',sans-serif;
    font-weight:700; font-size:.9rem; cursor:pointer; transition:background .2s;
}
.btn-cancel:hover { background:rgba(255,255,255,.15); }
.btn-confirm {
    border:none; border-radius:10px; padding:12px 26px;
    font-family:'Montserrat',sans-serif; font-weight:800; font-size:.9rem;
    cursor:pointer; transition:opacity .2s; color:#fff;
    display:flex; align-items:center; gap:8px;
}
.btn-confirm.verde   { background:linear-gradient(135deg,#4caf50,#2e7d32); }
.btn-confirm.naranja { background:linear-gradient(135deg,#ff9800,#e65100); }
.btn-confirm.azul    { background:linear-gradient(135deg,#2196f3,#1565c0); }
.btn-confirm.rojo    { background:linear-gradient(135deg,#e91e63,#880e4f); }
.btn-confirm[disabled] { opacity:.5; cursor:not-allowed; }

/* ── Responsive tableta ── */
@media (max-width:700px) {
    .proc-grid { grid-template-columns:1fr; padding:12px; gap:14px; }
    .proc-btn { height:80px; font-size:1.1rem; }
    .form-row { grid-template-columns:1fr; }
}

/* ── TICKER MARQUEE (GRANDE) ── */
.proc-ticker {
    position:fixed; top:70px; left:0; right:0; height:80px; z-index:99;
    background:linear-gradient(90deg,#050e1a,#0a1628 20%,#0d1b2a 80%,#050e1a);
    border-bottom:2px solid rgba(255,152,0,.5);
    display:flex; align-items:center; overflow:hidden;
    box-shadow:0 4px 20px rgba(0,0,0,.6);
}
.proc-ticker-icon {
    padding:0 20px; color:#FF9800; font-size:1.3rem; font-weight:900;
    white-space:nowrap; border-right:2px solid rgba(255,152,0,.3);
    display:flex; align-items:center; gap:8px; flex-shrink:0; height:100%;
    background:rgba(255,152,0,.1); letter-spacing:1px;
}
.proc-ticker-icon i { font-size:1.4rem; }
.proc-ticker-track {
    flex:1; overflow:hidden; position:relative; height:100%;
}
.proc-ticker-inner {
    position:absolute; white-space:nowrap; display:flex; align-items:center; gap:0;
    height:100%; animation: tickerMove 40s linear infinite;
}
.proc-ticker-inner:hover { animation-play-state:paused; }
@keyframes tickerMove {
    0%   { transform:translateX(100vw); }
    100% { transform:translateX(-100%); }
}
.tick-item {
    display:inline-flex; align-items:center; gap:8px; padding:0 34px;
    font-size:1.15rem; font-weight:700; color:rgba(255,255,255,.7);
    border-right:1px solid rgba(255,255,255,.1); height:100%;
}
.tick-dot { width:14px; height:14px; border-radius:50%; flex-shrink:0; }
.tick-en_curso   .tick-dot { background:#4caf50; box-shadow:0 0 8px #4caf50; }
.tick-pausada    .tick-dot { background:#FF9800; }
.tick-disponible .tick-dot { background:#607d8b; }
.tick-finalizada .tick-dot { background:#e91e63; }
.tick-prioritaria .tick-dot { background:#f44336; box-shadow:0 0 10px #f44336; animation:tickBlink .5s alternate infinite; }
@keyframes tickBlink { from{opacity:.3} to{opacity:1} }
.tick-en_curso   { color:#4caf50; }
.tick-pausada    { color:#FF9800; }
.tick-prioritaria { color:#f44336; font-size:1rem; }
.tick-finalizada { color:#e91e63; }
.tick-label { font-size:.8rem; text-transform:uppercase; letter-spacing:.5px; opacity:.55; margin-right:2px; }

/* ── LAYOUT con sidebar ── */
.proc-layout {
    display:flex; height:100vh; padding-top:150px; /* header 70 + ticker 80 */
    overflow:hidden;
}
.proc-sidebar {
    width:240px; flex-shrink:0; background:#0a1628;
    border-right:1px solid rgba(255,255,255,.08);
    overflow-y:auto; display:flex; flex-direction:column;
    padding-bottom:20px;
}
.proc-sidebar-title {
    padding:14px 16px; font-size:.65rem; font-weight:800; color:rgba(255,255,255,.35);
    text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid rgba(255,255,255,.07);
    flex-shrink:0;
}
.proc-proj-item {
    padding:11px 16px; font-size:.82rem; font-weight:600; color:rgba(255,255,255,.55);
    cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:8px;
    border-left:3px solid transparent;
}
.proc-proj-item:hover { background:rgba(255,255,255,.05); color:rgba(255,255,255,.85); }
.proc-proj-item.active { background:rgba(255,152,0,.1); color:#FF9800; border-left-color:#FF9800; }
.proc-proj-dot { width:7px; height:7px; border-radius:50%; background:rgba(255,255,255,.2); flex-shrink:0; }
.proc-proj-item.active .proc-proj-dot { background:#FF9800; }

.proc-main-area {
    flex:1; overflow-y:auto; padding-bottom:20px;
}

/* ── SCROLL BODY (fallback cuando no hay sidebar) ── */
.proc-body { height:100vh; padding-top:150px; overflow-y:auto; padding-bottom:20px; }

/* ── GRID ── */
.proc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(440px,1fr)); gap:20px; padding:20px; }

/* ── COLA BLOQUEADA ── */
.proc-card.cola-bloqueada {
    border-top:5px solid #37474f !important;
    opacity:.52;
    filter:grayscale(.4);
}
.proc-bloqueo-strip {
    margin:0 16px 14px; background:rgba(55,71,79,.45);
    border-radius:10px; padding:12px 16px;
    display:flex; align-items:center; gap:12px;
    font-size:.82rem; color:rgba(255,255,255,.5); font-weight:600;
}
.proc-bloqueo-strip i { color:#607d8b; font-size:1.1rem; flex-shrink:0; }
.proc-bloqueo-strip strong { color:rgba(255,255,255,.75); font-weight:800; }

/* ── STAND-BY CARD ── */
.proc-card.estado-stand_by { border-top:5px solid #607d8b; opacity:.65; }
.stand-by-badge {
    display:inline-flex; align-items:center; gap:5px; background:#607d8b; color:#fff;
    font-size:.7rem; font-weight:800; padding:4px 12px; border-radius:20px; letter-spacing:.4px;
    text-transform:uppercase;
}
/* ── PRIORIDAD CARD ── */
.proc-card.es-prioritaria { border-top:5px solid #f44336 !important; }
.proc-card.es-prioritaria .proc-card-folio { color:#f44336; }
.prio-badge {
    display:inline-flex; align-items:center; gap:5px; background:#f44336; color:#fff;
    font-size:.68rem; font-weight:800; padding:3px 10px; border-radius:20px; animation:tickBlink .8s alternate infinite;
}
.proc-pdf-btn {
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); border-radius:8px;
    color:rgba(255,255,255,.7); padding:6px 12px; font-size:.75rem; font-weight:700;
    cursor:pointer; display:inline-flex; align-items:center; gap:6px; text-decoration:none;
    transition:background .2s; margin-right:8px;
}
.proc-pdf-btn:hover { background:rgba(255,255,255,.18); color:#fff; }

/* ── MÁQUINA + OPERADOR destacados ── */
.proc-maquina-bloque {
    margin:10px 0 8px;
    background:rgba(0,86,134,.18);
    border:1.5px solid rgba(0,86,134,.35);
    border-radius:12px;
    padding:10px 14px;
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
}
.proc-maquina-nombre {
    font-size:1.45rem;
    font-weight:900;
    color:#fff;
    letter-spacing:.5px;
    display:flex;
    align-items:center;
    gap:10px;
    line-height:1.15;
}
.proc-maquina-ico {
    font-size:1.2rem;
    color:#FF9800;
    flex-shrink:0;
}
.proc-operador-nombre {
    font-size:.92rem;
    font-weight:700;
    color:rgba(255,255,255,.85);
    display:flex;
    align-items:center;
    border-left:2px solid rgba(255,255,255,.15);
    padding-left:14px;
}

/* ── PROCESO LIST ── */
.proc-proc-list {
    margin:0 16px 14px; border-radius:10px; overflow:hidden;
    border:1px solid rgba(255,255,255,.08); background:rgba(0,0,0,.25);
}
.proc-proc-item {
    display:flex; align-items:center; gap:10px; padding:9px 14px;
    border-bottom:1px solid rgba(255,255,255,.05); transition:background .15s;
}
.proc-proc-item:last-child { border-bottom:none; }
.proc-proc-item.activo { background:rgba(76,175,80,.12); }
.proc-proc-item.completado { opacity:.5; }
.proc-proc-num { width:22px; height:22px; border-radius:50%; font-size:.65rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.proc-proc-num.activo    { background:#4caf50; color:#fff; }
.proc-proc-num.pendiente { background:rgba(255,255,255,.1); color:rgba(255,255,255,.4); }
.proc-proc-num.completado{ background:#2e7d32; color:#fff; }
.proc-proc-name { flex:1; font-size:.82rem; font-weight:700; color:rgba(255,255,255,.85); }
.proc-proc-name.completado { text-decoration:line-through; color:rgba(255,255,255,.35); }
.proc-proc-name.pendiente  { color:rgba(255,255,255,.4); }
.proc-proc-state { font-size:.65rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px; padding:2px 8px; border-radius:8px; }
.proc-proc-state.activo    { background:#4caf50; color:#fff; }
.proc-proc-state.pendiente { background:rgba(255,255,255,.07); color:rgba(255,255,255,.35); }
.proc-proc-state.completado{ background:#1b5e20; color:#a5d6a7; }
.btn-completar-proc {
    background:linear-gradient(135deg,#4caf50,#2e7d32); border:none; border-radius:7px;
    color:#fff; padding:4px 10px; font-size:.72rem; font-weight:800; cursor:pointer;
    font-family:'Montserrat',sans-serif; transition:opacity .15s; white-space:nowrap;
}
.btn-completar-proc:hover { opacity:.85; }

/* ── PREVIEW MODAL (plano + materiales) ── */
.ini-preview-wrap { display:flex; gap:10px; margin-bottom:14px; }
.ini-preview-pdf  { width:160px; flex-shrink:0; border-radius:8px; overflow:hidden; border:1px solid rgba(255,255,255,.1); }
.ini-preview-pdf canvas { width:100%; height:auto; display:block; }
.ini-mats-list { flex:1; overflow-y:auto; max-height:160px; }
.ini-mat-row { display:flex; align-items:center; gap:8px; font-size:.74rem; padding:4px 0; border-bottom:1px solid rgba(255,255,255,.05); }
.ini-mat-desc { flex:1; color:rgba(255,255,255,.75); }
.ini-mat-qty  { color:#FF9800; font-weight:700; white-space:nowrap; }
.ini-mat-stk  { font-size:.65rem; padding:1px 6px; border-radius:6px; }
.ini-mat-stk.ok  { background:#1b5e20; color:#a5d6a7; }
.ini-mat-stk.low { background:#e65100; color:#fff; }

/* ── Materiales cargando ── */
.ini-loading { text-align:center; padding:16px; color:rgba(255,255,255,.3); font-size:.85rem; }
</style>
</head>
<body>

<!-- ── HEADER ── -->
<div class="proc-header">
    <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="proc-header-logo"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'120\' height=\'40\'><rect width=\'120\' height=\'40\' fill=\'%23005b96\'/><text x=\'10\' y=\'28\' font-family=\'Arial\' font-size=\'18\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
    <div class="proc-header-center">
        <div class="proc-header-title"><i class="fas fa-industry"></i> PROCESO DE PRODUCCIÓN</div>
        <div class="proc-clock" id="reloj">--:--:--</div>
    </div>
    <div class="proc-header-right">
        <div class="proc-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
        <span class="proc-username"><?= htmlspecialchars($_SESSION['user_name']) ?></span>
        <?php if ($desde_menu): ?>
        <button class="proc-logout" style="background:rgba(76,175,80,.15);border-color:rgba(76,175,80,.5);color:#a5d6a7;" onclick="location.href='Menu.php'">
            <i class="fas fa-th-large"></i> Menú
        </button>
        <?php else: ?>
        <button class="proc-logout" onclick="mostrarConfirmar('¿Cerrar sesión?', ()=>location.href='?logout=1', {icon:'fa-sign-out-alt',iconColor:'#ef9a9a',label:'Cerrar sesión',color:'rojo'})">
            <i class="fas fa-sign-out-alt"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ── TICKER ── -->
<div class="proc-ticker">
    <div class="proc-ticker-icon"><i class="fas fa-bell"></i> AVISOS</div>
    <div class="proc-ticker-track">
        <div class="proc-ticker-inner" id="procTickerInner">
            <span class="tick-item"><span class="tick-dot"></span><span class="tick-label">Sistema</span>Cargando notificaciones...</span>
        </div>
    </div>
</div>

<!-- ── BODY ── -->
<div class="proc-layout">

<!-- SIDEBAR: Proyectos -->
<div class="proc-sidebar">
    <div class="proc-sidebar-title"><i class="fas fa-folder"></i> &nbsp;Proyectos</div>
    <div class="proc-proj-item active" id="proj-filter-all" onclick="filtrarPorProyecto(null, this)">
        <div class="proc-proj-dot"></div>Todos los proyectos
    </div>
    <?php foreach ($sidebar_proyectos as $sp_p): ?>
    <div class="proc-proj-item" id="proj-filter-<?= $sp_p['id'] ?>" onclick="filtrarPorProyecto(<?= $sp_p['id'] ?>, this)">
        <div class="proc-proj-dot"></div><?= htmlspecialchars($sp_p['nombre']) ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- MAIN AREA -->
<div class="proc-main-area">
<?php if (empty($mis_ots)): ?>
<div class="proc-empty">
    <i class="fas fa-hard-hat"></i>
    <p>Sin órdenes de trabajo</p>
    <small>No hay órdenes pendientes o en proceso</small>
</div>
<?php else: ?>

<div class="proc-grid" id="procGrid">
<?php
$hay_activa = false;
foreach ($mis_ots as $_ck) { if ($_ck['estado']==='en_proceso' && !$_ck['pausado'] && !$_ck['en_stand_by']) { $hay_activa = true; break; } }
?>
<?php foreach ($mis_ots as $ot):
    $pausado      = (bool)$ot['pausado'];
    $stand_by     = (bool)($ot['en_stand_by'] ?? 0);
    $prioritaria  = (bool)($ot['es_prioritaria'] ?? 0);
    $estado       = $ot['estado'];
    $regular_h    = $ot['tiempo_regular_min'] ? round($ot['tiempo_regular_min'] / 60, 1) : 0;
    $extra_h      = $ot['tiempo_extra_min']   ? round($ot['tiempo_extra_min']   / 60, 1) : 0;
    $pausado_h    = $ot['tiempo_pausado_min'] ?? 0;

    $bloqueante_por_prioridad = $ot_bloqueada_prioridad_map[$ot['id']] ?? null;
    $bloqueante_por_maquina   = $ot_bloqueada_maquina_map[$ot['id']] ?? null;
    $bloqueante_por           = $bloqueante_por_prioridad
        ?? ($bloqueante_por_maquina ? $bloqueante_por_maquina['titulo'] : null)
        ?? ($ot_bloqueada_map[$ot['id']] ?? null);
    $es_bloqueada             = ($bloqueante_por !== null);

    // Formato legible de minutos → "Xh Ymin" (solo admin lo ve)
    $fmt_min = function(int $m): string {
        if (!$m) return '0min';
        $h = intdiv($m, 60); $r = $m % 60;
        return ($h ? $h . 'h ' : '') . $r . 'min';
    };
    $regular_fmt = $fmt_min(intval($ot['tiempo_regular_min']));
    $extra_fmt   = $fmt_min(intval($ot['tiempo_extra_min']));
    $pausado_fmt = $fmt_min(intval($ot['tiempo_pausado_min'] ?? 0));

    if ($es_bloqueada) {
        $card_class  = 'estado-pendiente cola-bloqueada';
        $badge_class = 'badge-pendiente';
        $badge_label = 'En cola';
    } elseif ($stand_by) {
        $card_class  = 'estado-en_proceso pausado';
        $badge_class = 'badge-pausado';
        $badge_label = 'Stand-by';
    } elseif ($estado === 'pendiente') {
        $card_class  = 'estado-pendiente';
        $badge_class = 'badge-pendiente';
        $badge_label = 'Pendiente';
    } elseif ($pausado) {
        $card_class  = 'estado-en_proceso pausado';
        $badge_class = 'badge-pausado';
        $badge_label = 'En pausa';
    } else {
        $card_class  = 'estado-en_proceso activo';
        $badge_class = 'badge-activo';
        $badge_label = 'En progreso';
    }
    if ($prioritaria) $card_class .= ' es-prioritaria';

    $hora_inicio = '';
    if ($ot['ultimo_inicio_ts']) {
        $hora_inicio = date('H:i', strtotime($ot['ultimo_inicio_ts']));
    }
?>
<div class="proc-card <?= $card_class ?>" id="card-<?= $ot['id'] ?>"
     data-id="<?= $ot['id'] ?>"
     data-proy-id="<?= intval($ot['proyecto_id']) ?>"
     data-boom-id="<?= intval($ot['boom_lista_id']) ?>"
     data-pagina-id="<?= intval($ot['plano_pagina_id'] ?? 0) ?>"
     data-orden="<?= intval($ot['orden_posicion']) ?>"
     data-estado="<?= $estado ?>"
     data-pausado="<?= $pausado ? 1 : 0 ?>"
     data-bloqueada="<?= $es_bloqueada ? 1 : 0 ?>"
     data-maquina="<?= htmlspecialchars($ot['maquina'] ?? '') ?>"
     data-proceso="<?= htmlspecialchars($ot['proceso']  ?? '') ?>">

    <!-- Top info -->
    <div class="proc-card-top">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <div class="proc-card-folio"><?= htmlspecialchars($ot['folio'] ?? 'OT-'.$ot['id']) ?></div>
            <div style="display:flex;gap:6px;align-items:center;">
                <?php if ($prioritaria): ?><span class="prio-badge"><i class="fas fa-bolt"></i> PRIORIDAD</span><?php endif; ?>
                <?php if ($stand_by): ?><span class="stand-by-badge"><i class="fas fa-pause-circle"></i> Stand-by</span><?php endif; ?>
                <?php if ($ot['plano_ruta']): ?><a href="uploads/<?= htmlspecialchars($ot['plano_ruta']) ?>" target="_blank" class="proc-pdf-btn"><i class="fas fa-file-pdf"></i> Plano</a><?php endif; ?>
            </div>
        </div>
        <div class="proc-card-titulo"><?= htmlspecialchars($ot['titulo']) ?></div>

        <!-- Máquina GRANDE + Operador — bloque destacado -->
        <?php if ($ot['ultima_maquina'] || $ot['ultimo_operador']): ?>
        <div class="proc-maquina-bloque">
            <?php if ($ot['ultima_maquina']): ?>
            <div class="proc-maquina-nombre">
                <i class="fas fa-industry proc-maquina-ico"></i>
                <?= htmlspecialchars($ot['ultima_maquina']) ?>
            </div>
            <?php endif; ?>
            <?php if ($ot['operador_nombre']): ?>
            <div class="proc-operador-nombre">
                <i class="fas fa-user-hard-hat" style="font-size:.85rem;color:#FF9800;margin-right:5px;"></i>
                <?= htmlspecialchars($ot['operador_nombre']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="proc-card-meta">
            <span class="proc-card-tag"><i class="fas fa-folder"></i><?= htmlspecialchars($ot['proyecto_nombre']) ?></span>
            <span class="proc-card-tag"><i class="fas fa-drafting-compass"></i><?= htmlspecialchars($ot['pieza_nombre'] ?? '—') ?></span>
            <?php if (!empty($ot['pagina_nombre'])): ?>
            <span class="proc-card-tag"><i class="fas fa-file-alt"></i><?= htmlspecialchars($ot['pagina_nombre']) ?></span>
            <?php endif; ?>
            <?php if (!empty($ot['proceso'])): ?>
            <span class="proc-card-tag" style="background:rgba(255,152,0,.15);color:#FF9800;"><i class="fas fa-sliders-h"></i><?= htmlspecialchars($ot['proceso']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($estado !== 'completado'): ?>

    <!-- Status strip — sin cronómetro -->
    <div class="proc-status-strip" id="strip-<?= $ot['id'] ?>">
        <span class="proc-status-badge <?= $badge_class ?>" id="badge-<?= $ot['id'] ?>"><?= $badge_label ?></span>
        <div class="proc-status-detail" id="detail-<?= $ot['id'] ?>">
            <?php if ($puede_ver_detalles && $estado === 'en_proceso' && !$pausado && $hora_inicio): ?>
                <div class="proc-status-line">Iniciado / Reanudado</div>
                <div class="proc-status-val"><?= $hora_inicio ?></div>
            <?php elseif ($ot['ultimo_operador']): ?>
                <div class="proc-status-line">Secuencia</div>
                <div class="proc-status-val">
                    <?php if (!empty($ot['secuencia_ejecucion']) && $ot['secuencia_ejecucion'] > 0): ?>
                        #<?= intval($ot['secuencia_ejecucion']) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="proc-status-line">Sin asignación</div>
                <div class="proc-status-val">—</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($puede_ver_detalles): ?>
    <!-- Acumulado + sesión en vivo — solo admin / administrador-proceso -->
    <?php
    $ts_proc_unix = $ot['ultimo_inicio_ts'] ? strtotime($ot['ultimo_inicio_ts']) : 0;
    $acum_seg     = intval($ot['tiempo_regular_min']) * 60;
    ?>
    <div class="proc-acum" id="acum-<?= $ot['id'] ?>">
        <div class="proc-acum-item">
            <div class="proc-acum-label"><i class="fas fa-clock"></i> Regulares</div>
            <div class="proc-acum-val acum-regular" id="acum-reg-<?= $ot['id'] ?>"><?= $regular_fmt ?></div>
        </div>
        <div class="proc-acum-item">
            <div class="proc-acum-label"><i class="fas fa-star"></i> Extra</div>
            <div class="proc-acum-val acum-extra" id="acum-ext-<?= $ot['id'] ?>"><?= $extra_fmt ?></div>
        </div>
        <div class="proc-acum-item">
            <div class="proc-acum-label"><i class="fas fa-pause-circle"></i> Pausado</div>
            <div class="proc-acum-val" style="color:#607d8b;" id="acum-pau-<?= $ot['id'] ?>"><?= $pausado_fmt ?></div>
        </div>
        <?php if ($estado === 'en_proceso' && !$pausado && $ts_proc_unix > 0): ?>
        <div class="proc-acum-item" style="border-left:1px solid rgba(255,255,255,.06);">
            <div class="proc-acum-label" style="color:#4caf50;"><i class="fas fa-play-circle"></i> Sesión actual</div>
            <div class="proc-acum-val acum-regular"
                 id="sesion-timer-<?= $ot['id'] ?>"
                 data-ts="<?= $ts_proc_unix ?>"
                 data-acum="<?= $acum_seg ?>">
                00:00:00
            </div>
        </div>
        <?php endif; ?>
        <button class="proc-acum-logs-btn" onclick="verLogs(<?= $ot['id'] ?>)" title="Ver historial completo">
            <i class="fas fa-history"></i> Logs
        </button>
    </div>
    <?php endif; ?>

    <!-- Botones -->
    <div class="proc-btns" id="btns-<?= $ot['id'] ?>">
        <?php if ($es_bloqueada): ?>
        <div style="flex:1;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div class="proc-bloqueo-strip" style="flex:1;margin:0;min-width:200px;">
                <i class="fas fa-hourglass-half"></i>
                <?php if ($bloqueante_por_prioridad): ?>
                    Esperando a que se atienda primero la OT prioritaria <strong><?= htmlspecialchars($bloqueante_por_prioridad) ?></strong>
                <?php elseif ($bloqueante_por_maquina): ?>
                    Esperando a que se libere la máquina <strong><?= htmlspecialchars($bloqueante_por_maquina['maquina']) ?></strong>
                    (la está usando <strong><?= htmlspecialchars($bloqueante_por_maquina['titulo']) ?></strong><?= $bloqueante_por_maquina['operador'] !== '' ? ' — ' . htmlspecialchars($bloqueante_por_maquina['operador']) : '' ?>)
                <?php else: ?>
                    Esperando que <strong><?= htmlspecialchars($bloqueante_por) ?></strong> se complete primero
                <?php endif; ?>
            </div>
            <?php if ($puede_ver_detalles): ?>
            <button class="proc-btn proc-btn-reanudar" style="flex:0 0 auto;min-width:110px;height:52px;font-size:.78rem;"
                    onclick="abrirModalInicio(<?= $ot['id'] ?>, 'iniciar', true)"
                    title="Solo administrador: iniciar aunque haya otra OT activa en esta cola">
                <i class="fas fa-unlock"></i> Forzar inicio
            </button>
            <?php endif; ?>
        </div>
        <?php elseif ($estado === 'pendiente'): ?>
        <button class="proc-btn proc-btn-inicio" onclick="abrirModalInicio(<?= $ot['id'] ?>, 'iniciar')">
            <i class="fas fa-play"></i> Iniciar
        </button>

        <?php elseif ($estado === 'en_proceso' && !$pausado && !$stand_by): ?>
        <button class="proc-btn proc-btn-pausa" onclick="pausarDirecto(<?= $ot['id'] ?>)">
            <i class="fas fa-pause"></i> Pausar
        </button>
        <button class="proc-btn" style="background:linear-gradient(135deg,#f44336,#b71c1c);color:#fff;box-shadow:0 4px 20px rgba(244,67,54,.4);" onclick="abrirModalProblema(<?= $ot['id'] ?>)">
            <i class="fas fa-exclamation-triangle"></i> Problema
        </button>
        <?php if (empty($ot_pasos_reales_map[$ot['id']])): ?>
            <?php if ($puede_ver_detalles): ?>
            <button class="proc-btn proc-btn-terminado" onclick="abrirModalCierre(<?= $ot['id'] ?>, 'terminar')">
                <i class="fas fa-flag-checkered"></i> Finalizar
            </button>
            <?php else: ?>
            <button class="proc-btn proc-btn-terminado" onclick="terminarDirecto(<?= $ot['id'] ?>)">
                <i class="fas fa-flag-checkered"></i> Finalizar
            </button>
            <?php endif; ?>
        <?php endif; ?>

        <?php elseif ($estado === 'en_proceso' && $pausado && !$stand_by): ?>
        <button class="proc-btn proc-btn-reanudar" onclick="abrirModalInicio(<?= $ot['id'] ?>, 'reanudar')">
            <i class="fas fa-play-circle"></i> Reanudar
        </button>
        <?php elseif ($stand_by): ?>
        <button class="proc-btn proc-btn-reanudar" onclick="abrirModalInicio(<?= $ot['id'] ?>, 'reanudar')" style="opacity:.7;">
            <i class="fas fa-hourglass-half"></i> En espera — Reanudar
        </button>
        <?php elseif ($estado === 'pendiente' && $hay_activa): ?>
        <button class="proc-btn" style="background:rgba(96,125,139,.3);color:rgba(255,255,255,.4);cursor:not-allowed;" disabled>
            <i class="fas fa-pause-circle"></i> Stand-by
        </button>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="proc-card-done">
        <i class="fas fa-check-circle"></i>
        <p>Fabricación completada<br><small>Pendiente de Visto Bueno de Producción</small></p>
    </div>
    <?php endif; ?>

    <!-- Lista de procesos (solo cuando está en proceso y tiene procesos definidos) -->
    <?php if ($estado === 'en_proceso' && !$stand_by && !empty($ot_procesos_map[$ot['id']])): ?>
    <div class="proc-proc-list" id="proclist-<?= $ot['id'] ?>">
    <?php foreach ($ot_procesos_map[$ot['id']] as $pi => $proc): ?>
    <div class="proc-proc-item <?= $proc['estado'] ?>" id="procitem-<?= $proc['id'] ?>-<?= $ot['id'] ?>">
        <div class="proc-proc-num <?= $proc['estado'] ?>">
            <?= $proc['estado'] === 'completado' ? '<i class="fas fa-check"></i>' : ($pi+1) ?>
        </div>
        <div class="proc-proc-name <?= $proc['estado'] ?>"><?= htmlspecialchars($proc['nombre']) ?></div>
        <span class="proc-proc-state <?= $proc['estado'] ?>"><?= ['pendiente'=>'En espera','activo'=>'Activo','completado'=>'Completado'][$proc['estado']] ?></span>
        <?php if ($proc['estado'] === 'activo' && intval($proc['id']) > 0): ?>
        <button class="btn-completar-proc" onclick="completarProceso(<?= $proc['id'] ?>,<?= $ot['id'] ?>)"><i class="fas fa-check"></i> Completar</button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
</div><!-- /.proc-main-area -->
</div><!-- /.proc-layout -->

<!-- ══════════════ MODAL: INICIO / REANUDACIÓN ══════════════ -->
<div class="modal-overlay" id="modalInicio">
    <div class="modal-box" style="max-width:620px;">
        <div class="modal-header">
            <span class="modal-title" id="tituloModalInicio">Iniciar Orden</span>
            <button class="modal-close" onclick="cerrarModal('modalInicio')">&times;</button>
        </div>
        <div class="modal-body">

            <!-- Preview plano + materiales -->
            <div id="ini_preview_section" style="display:none;">
                <div style="font-size:.72rem;font-weight:800;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;"><i class="fas fa-eye"></i> Vista previa del plano y materiales</div>
                <div class="ini-preview-wrap">
                    <div class="ini-preview-pdf" id="ini_pdf_wrap">
                        <div class="ini-loading"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    <div class="ini-mats-list" id="ini_mats_list">
                        <div class="ini-loading"><i class="fas fa-spinner fa-spin"></i> Cargando materiales...</div>
                    </div>
                </div>
            </div>

            <!-- Datos pre-llenados -->
            <div class="ini-prefill-block">
                <div class="ini-prefill-label"><i class="fas fa-info-circle"></i> Datos de la orden</div>
                <div class="ini-prefill-grid">
                    <div class="ini-prefill-item"><span class="ini-pl-key">Máquina</span><span class="ini-pl-val" id="ini_pre_maquina">—</span></div>
                    <div class="ini-prefill-item"><span class="ini-pl-key">Tag</span><span class="ini-pl-val" id="ini_pre_tag">—</span></div>
                    <div class="ini-prefill-item"><span class="ini-pl-key">Modelo</span><span class="ini-pl-val" id="ini_pre_mod">—</span></div>
                    <div class="ini-prefill-item"><span class="ini-pl-key">Proceso</span><span class="ini-pl-val" id="ini_pre_proceso">—</span></div>
                </div>
            </div>

            <!-- Operador -->
            <div class="form-group">
                <label class="form-label">Nombre del operador <span class="req">*</span></label>
                <input type="text" class="form-input" id="ini_operador"
                       placeholder="Nombre completo del trabajador" autocomplete="off">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="cerrarModal('modalInicio')">Cancelar</button>
            <button class="btn-confirm verde" id="btnConfirmarInicio" onclick="confirmarInicio()">
                <i class="fas fa-play"></i> <span id="labelConfirmarInicio">Iniciar</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════ MODAL: PAUSA / FINALIZAR ══════════════ -->
<div class="modal-overlay" id="modalCierre">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="tituloModalCierre">Pausar Orden</span>
            <button class="modal-close" onclick="cerrarModal('modalCierre')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Resumen de lo realizado <span class="req">*</span></label>
                <textarea class="form-textarea" id="cie_resumen"
                          placeholder="¿Qué se hizo durante este bloque de trabajo?"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group" id="cie_motivo_row">
                    <label class="form-label">Motivo de pausa</label>
                    <select class="form-select" id="cie_motivo">
                        <option value="">— Sin motivo —</option>
                        <option value="Fin de turno">Fin de turno</option>
                        <option value="Hora de comida" class="opt-solo-pausa">Hora de comida</option>
                        <option value="Falta de material">Falta de material</option>
                        <option value="Mantenimiento">Mantenimiento</option>
                        <option value="Reunión">Reunión</option>
                        <option value="Pieza terminada">Pieza terminada</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Piezas terminadas</label>
                    <input type="number" class="form-input" id="cie_piezas"
                           min="0" value="0" placeholder="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Observaciones / Problemas encontrados</label>
                <textarea class="form-textarea" id="cie_observaciones" style="min-height:60px;"
                          placeholder="Inconvenientes, notas importantes, materiales faltantes..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="cerrarModal('modalCierre')">Cancelar</button>
            <button class="btn-confirm naranja" id="btnConfirmarCierre" onclick="confirmarCierre()">
                <i class="fas fa-pause" id="iconConfirmarCierre"></i>
                <span id="labelConfirmarCierre">Pausar</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════ MODAL: PROBLEMA ══════════════ -->
<div class="modal-overlay" id="modalProblema">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">&#9888; Reportar Problema</span>
            <button class="modal-close" onclick="cerrarModal('modalProblema')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Descripcion del problema <span class="req">*</span></label>
                <textarea class="form-textarea" id="prob_descripcion"
                          placeholder="Describe el problema encontrado (falta de material, falla de maquina, etc.)"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="cerrarModal('modalProblema')">Cancelar</button>
            <button class="btn-confirm rojo" onclick="confirmarProblema()">
                <i class="fas fa-exclamation-triangle"></i> Reportar Problema
            </button>
        </div>
    </div>
</div>

<script>
// ── Reloj ────────────────────────────────────────────────────────────────────
(function tick() {
    const n = new Date();
    document.getElementById('reloj').textContent =
        String(n.getHours()).padStart(2,'0') + ':' +
        String(n.getMinutes()).padStart(2,'0') + ':' +
        String(n.getSeconds()).padStart(2,'0');
    setTimeout(tick, 1000);
})();

// ── Rol del usuario en sesión ────────────────────────────────────────────────
const ES_ADMIN   = <?= $puede_ver_detalles ? 'true' : 'false' ?>;
const ES_MENU    = <?= $desde_menu ? 'true' : 'false' ?>;
const SITE_URL_P = <?= json_encode(SITE_URL) ?>;

// Inicializar PDF.js
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

// ── Diccionario de maquinaria (para lookup de Tag/Modelo) ────────────────────
const MAQUINARIA = {
    "Torno Convencional":             { tag:"MO001", mod:"SW71c-300 / 2013" },
    "Fresadora Universal Convencional":{ tag:"MO002", mod:"KA-300 / 2012" },
    "Cepillo de Codo":                { tag:"MO003", mod:"1970" },
    "Torno CNC":                      { tag:"MO004", mod:"ST 30Y / 2014" },
    "Fresadora CNC 1":                { tag:"MO005", mod:"VF8-40 / 2013" },
    "Fresadora CNC 2":                { tag:"MO006", mod:"VF2 / 2024" },
    "Sierra Cinta":                   { tag:"MO007", mod:"281 CC / 2014" },
    "Robot Soldador":                 { tag:"MO008", mod:"/ 2024" },
    "Taladro Radial":                 { tag:"MO009", mod:"TR38-750 / 2012" },
};

// ── Estado del modal activo ───────────────────────────────────────────────────
let modalOtId    = null;
let modalAccion  = null;
let modalMaquina = '';
let modalProceso = '';
let modalForzar  = false;

// ── Filtrar OTs por proyecto (sidebar) ──────────────────────────────────────
let proyFiltroActivo = null;
function filtrarPorProyecto(proyId, el) {
    proyFiltroActivo = proyId;
    // UI: resaltar item activo
    document.querySelectorAll('.proc-proj-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');
    // Filtrar cards
    document.querySelectorAll('.proc-card').forEach(card => {
        if (!proyId) {
            card.style.display = '';
        } else {
            card.style.display = card.dataset.proyId == proyId ? '' : 'none';
        }
    });
}

// ── Completar proceso de una OT ──────────────────────────────────────────────
async function completarProceso(procId, otId) {
    const fd = new FormData();
    fd.append('ajax','1'); fd.append('accion','completar_proceso');
    fd.append('proc_id', procId); fd.append('ot_id', otId);
    try {
        const r = await fetch('proceso.php', {method:'POST',body:fd});
        const d = await r.json();
        if (d.ok) {
            // Actualizar visualmente
            const item = document.getElementById('procitem-' + procId + '-' + otId);
            if (item) {
                item.className = 'proc-proc-item completado';
                item.querySelector('.proc-proc-num').className = 'proc-proc-num completado';
                item.querySelector('.proc-proc-num').innerHTML = '<i class="fas fa-check"></i>';
                item.querySelector('.proc-proc-name').classList.add('completado');
                item.querySelector('.proc-proc-state').className = 'proc-proc-state completado';
                item.querySelector('.proc-proc-state').textContent = 'Completado';
                const btn = item.querySelector('.btn-completar-proc');
                if (btn) btn.remove();
            }
            if (d.next_id) {
                const nextItem = document.getElementById('procitem-' + d.next_id + '-' + otId);
                if (nextItem) {
                    nextItem.className = 'proc-proc-item activo';
                    nextItem.querySelector('.proc-proc-num').className = 'proc-proc-num activo';
                    nextItem.querySelector('.proc-proc-name').classList.remove('pendiente');
                    const stateEl = nextItem.querySelector('.proc-proc-state');
                    stateEl.className = 'proc-proc-state activo';
                    stateEl.textContent = 'Activo';
                    const btn = document.createElement('button');
                    btn.className = 'btn-completar-proc';
                    btn.innerHTML = '<i class="fas fa-check"></i> Completar';
                    btn.onclick = () => completarProceso(d.next_id, otId);
                    nextItem.appendChild(btn);
                }
            } else if (d.estado === 'fabricado') {
                actualizarCard(otId, d, 'terminar');
            } else if (d.done) {
                mostrarToastError('✓ Todos los procesos completados. Puedes finalizar la OT.');
            }
        }
    } catch(e) { mostrarToastError('Error: ' + e.message); }
}

// ── Fetch materiales + plano para preview ────────────────────────────────────
let _ini_pdfDoc = null;

async function cargarPreviewOT(otId) {
    const previw = document.getElementById('ini_preview_section');
    previw.style.display = 'block';
    document.getElementById('ini_pdf_wrap').innerHTML = '<div class="ini-loading"><i class="fas fa-spinner fa-spin"></i></div>';
    document.getElementById('ini_mats_list').innerHTML = '<div class="ini-loading"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';

    const fd = new FormData();
    fd.append('ajax','1'); fd.append('accion','get_materiales'); fd.append('ot_id', otId);
    try {
        const r = await fetch('proceso.php', {method:'POST',body:fd});
        const d = await r.json();
        // Materiales
        const matsEl = document.getElementById('ini_mats_list');
        if (d.materiales && d.materiales.length) {
            matsEl.innerHTML = d.materiales.map(m => {
                return `<div class="ini-mat-row">
                    <span class="ini-mat-desc">${escProc(m.descripcion)}${m.marca?' <small style="opacity:.5">'+escProc(m.marca)+'</small>':''}</span>
                    <span class="ini-mat-qty">${m.cantidad} ${m.unidad_medida||''}</span>
                </div>`;
            }).join('');
        } else {
            matsEl.innerHTML = '<div class="ini-loading">Sin materiales registrados en BOM.</div>';
        }
        // PDF
        const pdfWrap = document.getElementById('ini_pdf_wrap');
        if (d.plano_ruta && typeof pdfjsLib !== 'undefined') {
            try {
                const pdfDoc = await pdfjsLib.getDocument(SITE_URL_P + '/uploads/' + d.plano_ruta).promise;
                const page = await pdfDoc.getPage(1);
                const vp = page.getViewport({scale:0.6});
                const canvas = document.createElement('canvas');
                canvas.width = vp.width; canvas.height = vp.height;
                canvas.style.width = '100%'; canvas.style.height = 'auto';
                await page.render({canvasContext:canvas.getContext('2d'),viewport:vp}).promise;
                pdfWrap.innerHTML = '';
                pdfWrap.appendChild(canvas);
                // Link to open full
                const link = document.createElement('a');
                link.href = <?= json_encode(SITE_URL) ?> + '/uploads/' + d.plano_ruta;
                link.target = '_blank';
                link.style.cssText = 'display:block;text-align:center;font-size:.7rem;color:#FF9800;padding:4px;';
                link.textContent = 'Ver plano completo';
                pdfWrap.appendChild(link);
            } catch(e) {
                pdfWrap.innerHTML = '<div class="ini-loading" style="font-size:.7rem;">No disponible</div>';
            }
        } else {
            pdfWrap.innerHTML = '<div class="ini-loading" style="font-size:.7rem;">Sin plano</div>';
        }
    } catch(e) {
        document.getElementById('ini_mats_list').innerHTML = '<div class="ini-loading" style="color:#f44336;">Error al cargar.</div>';
    }
}

function escProc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }

// ── Apertura modal Inicio / Reanudación ───────────────────────────────────────
function abrirModalInicio(id, accion, forzar = false) {
    modalOtId   = id;
    modalAccion = accion;
    modalForzar = forzar;

    // Leer datos de la card
    const card = document.getElementById('card-' + id);
    modalMaquina = card?.dataset.maquina || '';
    modalProceso = card?.dataset.proceso || '';

    // Título y botón
    document.getElementById('tituloModalInicio').textContent =
        accion === 'iniciar' ? '▶ Iniciar Orden' : '↻ Reanudar Orden';
    document.getElementById('labelConfirmarInicio').textContent =
        accion === 'iniciar' ? 'Iniciar' : 'Reanudar';
    document.getElementById('btnConfirmarInicio').className =
        'btn-confirm ' + (accion === 'iniciar' ? 'verde' : 'azul');

    // Pre-llenar bloque de datos de OT (readonly)
    const info = MAQUINARIA[modalMaquina] || {};
    document.getElementById('ini_pre_maquina').textContent = modalMaquina || '—';
    document.getElementById('ini_pre_tag').textContent     = info.tag || '—';
    document.getElementById('ini_pre_mod').textContent     = info.mod || '—';
    document.getElementById('ini_pre_proceso').textContent = modalProceso || '—';

    // Operador: pre-llenar con nombre de sesión si aún vacío
    if (!document.getElementById('ini_operador').value) {
        document.getElementById('ini_operador').value = <?= json_encode($_SESSION['user_name']) ?>;
    }

    // Cargar preview del plano + materiales solo en iniciar
    if (accion === 'iniciar') {
        cargarPreviewOT(id);
    } else {
        document.getElementById('ini_preview_section').style.display = 'none';
    }

    document.getElementById('modalInicio').classList.add('active');
    setTimeout(() => document.getElementById('ini_operador').focus(), 100);
}

function abrirModalCierre(id, accion) {
    modalOtId   = id;
    modalAccion = accion;

    const esFin = (accion === 'terminar');
    document.getElementById('tituloModalCierre').textContent =
        esFin ? '✓ Finalizar Orden' : '⏸ Pausar Orden';
    document.getElementById('labelConfirmarCierre').textContent =
        esFin ? 'Finalizar' : 'Pausar';

    const btnClass = esFin ? 'rojo' : 'naranja';
    document.getElementById('btnConfirmarCierre').className = 'btn-confirm ' + btnClass;
    document.getElementById('iconConfirmarCierre').className =
        esFin ? 'fas fa-flag-checkered' : 'fas fa-pause';

    document.getElementById('cie_resumen').value       = '';
    document.getElementById('cie_motivo').value        = esFin ? 'Pieza terminada' : '';
    document.getElementById('cie_piezas').value        = '0';
    document.getElementById('cie_observaciones').value = '';

    // Al finalizar: ocultar el selector de motivo (siempre es "Pieza terminada")
    const motivoRow = document.getElementById('cie_motivo_row');
    if (motivoRow) motivoRow.style.display = esFin ? 'none' : '';

    // "Hora de comida" no aplica al finalizar
    document.querySelectorAll('#cie_motivo .opt-solo-pausa').forEach(opt => {
        opt.style.display = esFin ? 'none' : '';
    });

    document.getElementById('modalCierre').classList.add('active');
    setTimeout(() => document.getElementById('cie_resumen').focus(), 100);
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('active');
}

// ── Confirmar Inicio / Reanudación ────────────────────────────────────────────
async function confirmarInicio() {
    const operador = document.getElementById('ini_operador').value.trim();
    if (!operador) { shake('modalInicio'); return; }

    cerrarModal('modalInicio');
    await enviarAccion(modalOtId, modalAccion, {
        operador,
        maquina:   modalMaquina,
        actividad: modalProceso,
        forzar:    modalForzar ? 1 : 0,
    });
}

// ── Confirmar Pausa / Finalización ───────────────────────────────────────────
async function confirmarCierre() {
    const resumen = document.getElementById('cie_resumen').value.trim();
    if (!resumen) { shake('modalCierre'); return; }

    const datos = {
        resumen,
        motivo:        document.getElementById('cie_motivo').value,
        piezas:        document.getElementById('cie_piezas').value,
        observaciones: document.getElementById('cie_observaciones').value.trim(),
    };

    cerrarModal('modalCierre');
    await enviarAccion(modalOtId, modalAccion, datos);
}

// ── Enviar al servidor ────────────────────────────────────────────────────────
async function enviarAccion(id, accion, datos = {}) {
    bloquearBtns(id, true);

    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('accion', accion);
    fd.append('ot_id', id);
    Object.entries(datos).forEach(([k, v]) => fd.append(k, v));

    const ctrl    = new AbortController();
    const timeout = setTimeout(() => ctrl.abort(), 15000);

    try {
        const r = await fetch('proceso.php', { method: 'POST', body: fd, signal: ctrl.signal });
        clearTimeout(timeout);
        const d = await r.json();
        if (d.ok) {
            actualizarCard(id, d, accion);
        } else {
            mostrarToastError(d.error || 'Error desconocido');
            bloquearBtns(id, false);
        }
    } catch(e) {
        clearTimeout(timeout);
        mostrarToastError(e.name === 'AbortError'
            ? 'La operación tardó demasiado. Intenta de nuevo.'
            : 'Error de conexión. Verifica tu red.');
        bloquearBtns(id, false);
    }
}

// ── Actualizar card tras acción exitosa ───────────────────────────────────────
function actualizarCard(id, resp, accion) {
    const card  = document.getElementById('card-' + id);
    if (!card) return;

    // Iniciar: recargar para que aparezca el proc-proc-list (generado por PHP)
    if (accion === 'iniciar') {
        setTimeout(() => location.reload(), 350);
        return;
    }

    // Completado: recargar para que el servidor recalcule todos los bloqueos
    // (secuencia, prioridad, máquina compartida) y se reflejen en toda la página
    if (resp.estado === 'fabricado' || accion === 'terminar') {
        setTimeout(() => location.reload(), 350);
        return;
    }

    const pausado = resp.pausado;

    // Refrescar ticker inmediatamente para reflejar el nuevo estado en los anuncios
    cargarTicker();

    // Clases del card
    card.className = 'proc-card ' + (pausado ? 'estado-en_proceso pausado' : 'estado-en_proceso activo');

    // Badge
    const badge = document.getElementById('badge-' + id);
    if (badge) {
        if (pausado) {
            badge.className   = 'proc-status-badge badge-pausado';
            badge.textContent = 'En pausa';
        } else {
            badge.className   = 'proc-status-badge badge-activo';
            badge.textContent = 'En progreso';
        }
    }

    // Detalle (hora inicio — solo para quien puede ver detalles)
    const detail = document.getElementById('detail-' + id);
    if (detail && resp.hora_inicio && ES_ADMIN) {
        detail.innerHTML =
            '<div class="proc-status-line">Iniciado / Reanudado</div>' +
            '<div class="proc-status-val">' + resp.hora_inicio + '</div>';
    }

    // Acumulado (si viene en pausa) — admin ve Xh Ymin, operador ve X.X h
    if (typeof resp.regular !== 'undefined') {
        const regEl = document.getElementById('acum-reg-' + id);
        const extEl = document.getElementById('acum-ext-' + id);
        const _fmtM = m => { if(!m) return '0min'; const h=Math.floor(m/60),r=m%60; return (h?h+'h ':'')+r+'min'; };
        if (regEl) regEl.textContent = ES_ADMIN ? _fmtM(resp.regular) : (resp.regular/60).toFixed(1)+' h';
        if (extEl) extEl.textContent = ES_ADMIN ? _fmtM(resp.extra)   : (resp.extra  /60).toFixed(1)+' h';
    }

    // Botones
    const btns = document.getElementById('btns-' + id);
    if (btns) {
        if (!pausado) {
            const finBtn = ES_ADMIN
                ? `<button class="proc-btn proc-btn-terminado" onclick="abrirModalCierre(${id},'terminar')"><i class="fas fa-flag-checkered"></i> Finalizar</button>`
                : `<button class="proc-btn proc-btn-terminado" onclick="terminarDirecto(${id})"><i class="fas fa-flag-checkered"></i> Finalizar</button>`;
            btns.innerHTML =
                `<button class="proc-btn proc-btn-pausa" onclick="pausarDirecto(${id})">
                    <i class="fas fa-pause"></i> Pausar</button>
                 <button class="proc-btn" style="background:linear-gradient(135deg,#f44336,#b71c1c);color:#fff;box-shadow:0 4px 20px rgba(244,67,54,.4);" onclick="abrirModalProblema(${id})"><i class="fas fa-exclamation-triangle"></i> Problema</button>
                 ${finBtn}`;
        } else {
            btns.innerHTML =
                `<button class="proc-btn proc-btn-reanudar" onclick="abrirModalInicio(${id},'reanudar')">
                    <i class="fas fa-play-circle"></i> Reanudar</button>`;
        }
    }
}

// ── Modal de confirmación genérico ───────────────────────────────────────────
let _confirmarCb = null;
function mostrarConfirmar(mensaje, cb, opts = {}) {
    _confirmarCb = cb;
    document.getElementById('procConfirmarMensaje').textContent = mensaje;
    // icono
    const iconEl = document.getElementById('procConfirmarIcon');
    iconEl.className = 'fas ' + (opts.icon || 'fa-flag-checkered');
    iconEl.style.color = opts.iconColor || '#e91e63';
    // botón confirmar
    const btnEl = document.getElementById('btnProcConfirmarSi');
    btnEl.className = 'btn-confirm ' + (opts.color || 'rojo');
    document.getElementById('btnProcConfirmarLabel').textContent = opts.label || 'Finalizar';
    document.getElementById('modalProcConfirmar').classList.add('active');
}
function _procConfirmarSi() {
    cerrarModal('modalProcConfirmar');
    if (_confirmarCb) { _confirmarCb(); _confirmarCb = null; }
}

// ── Toast de error (reemplaza alert) ─────────────────────────────────────────
let _toastTimer = null;
function mostrarToastError(msg) {
    const t = document.getElementById('procToastError');
    document.getElementById('procToastMsg').textContent = msg;
    t.classList.add('visible');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => t.classList.remove('visible'), 4500);
}

// ── Finalizar directo sin modal (Operador-Proceso) ────────────────────────────
function terminarDirecto(id) {
    mostrarConfirmar('¿Finalizar esta orden de trabajo?', () => {
        enviarAccion(id, 'terminar', { resumen: 'Finalizado', motivo: 'Pieza terminada', piezas: 0, observaciones: '' });
    });
}

// ── Pausar directo (sin modal, solo confirm) ───────────────────────────────────
function pausarDirecto(id) {
    enviarAccion(id, 'pausar', { resumen: '', motivo: '', piezas: 0, observaciones: '' });
}

// ── Problema ───────────────────────────────────────────────────────────────────
let _problemaOtId = 0;
function abrirModalProblema(id) {
    _problemaOtId = id;
    document.getElementById('prob_descripcion').value = '';
    document.getElementById('modalProblema').classList.add('active');
}
function confirmarProblema() {
    const desc = document.getElementById('prob_descripcion').value.trim();
    if (!desc) { alert('Escribe una descripcion del problema'); return; }
    enviarAccion(_problemaOtId, 'marcar_problema', { descripcion: desc });
    cerrarModal('modalProblema');
}

// ── TICKER ────────────────────────────────────────────────────────────────────
const TICK_LABELS = {
    en_curso:    '▶ EN PROCESO',
    pausada:     '⏸ EN PAUSA',
    disponible:  '⚡ DISPONIBLE',
    finalizada:  '✓ COMPLETADA',
    prioritaria: '🚨 URGENTE',
    siguiente:   '⏭ SIGUIENTE EN FILA',
    sin_ots:     '◉ SISTEMA',
};

async function cargarTicker() {
    try {
        const fd = new FormData();
        fd.append('ajax','1'); fd.append('accion','ticker'); fd.append('ot_id','0');
        const r = await fetch('proceso.php', { method:'POST', body:fd });
        const d = await r.json();
        if (!d.ok || !d.items) return;

        const inner = document.getElementById('procTickerInner');
        if (!inner) return;

        const buildItem = (it) => {
            const label = TICK_LABELS[it.tipo] || it.tipo;
            let txt = '';
            if (it.folio) txt += `<strong style="margin:0 4px;">${it.folio}</strong>`;
            if (it.titulo) txt += ` ${it.titulo}`;
            if (it.proyecto && it.tipo !== 'sin_ots') txt += ` <span style="opacity:.5;font-size:.82em;">· ${it.proyecto}</span>`;
            if (it.operador_nombre) txt += ` <span style="opacity:.7;font-size:.85em;"><i class="fas fa-user-hard-hat" style="font-size:.85em;"></i> ${it.operador_nombre}</span>`;
            if (it.proceso) txt += ` <span style="opacity:.7;font-size:.85em;">· <i class="fas fa-sliders-h" style="font-size:.85em;"></i> ${it.proceso}</span>`;
            if (it.tipo === 'siguiente') txt += ` <span style="color:#FF9800;font-weight:800;"> · PREPARAR MAQUINARIA</span>`;
            if (it.tipo === 'prioritaria') txt += ` <span style="color:#f44336;font-weight:900;"> · INICIAR INMEDIATAMENTE</span>`;
            return `<span class="tick-item tick-${it.tipo}">
                <span class="tick-dot"></span>
                <span class="tick-label">${label}</span>${txt}
            </span><span style="color:rgba(255,255,255,.1);padding:0 20px;font-size:1.2rem;display:inline-block;">|</span>`;
        };

        // Duplicar para scroll continuo
        const allItems = [...d.items, ...d.items];
        inner.innerHTML = allItems.map(buildItem).join('');
        inner.style.animationDuration = Math.max(25, d.items.length * 9) + 's';
    } catch(e) {}
}

// Agregar tipo sin_ots al CSS del ticker si no existe
const stStyle = document.createElement('style');
stStyle.textContent = '.tick-sin_ots { color:rgba(255,255,255,.35); } .tick-siguiente { color:#FF9800; } .tick-siguiente .tick-dot { background:#FF9800; }';
document.head.appendChild(stStyle);

cargarTicker();
setInterval(cargarTicker, 20000);

// ── Timers de sesión en vivo (admin) ──────────────────────────────────────────
(function() {
    function fmtSeg(s) {
        if (isNaN(s) || s < 0) s = 0;
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sc = s % 60;
        return (h > 0 ? h + ':' : '') +
               String(m).padStart(2,'0') + ':' +
               String(sc).padStart(2,'0');
    }
    setInterval(function() {
        const nowSec = Math.floor(Date.now() / 1000);
        document.querySelectorAll('[id^="sesion-timer-"]').forEach(function(el) {
            const ts   = parseInt(el.dataset.ts   || 0);
            const acum = parseInt(el.dataset.acum || 0);
            if (!ts || isNaN(ts)) return;
            const sesion = Math.max(0, nowSec - ts);
            el.textContent = fmtSeg(acum + sesion);
        });
    }, 1000);
})();

function bloquearBtns(id, estado) {
    const btns = document.querySelectorAll('#btns-' + id + ' .proc-btn');
    btns.forEach(b => b.disabled = estado);
}

// Sacudida visual en modal con campos vacíos
function shake(modalId) {
    const box = document.querySelector('#' + modalId + ' .modal-box');
    box.style.animation = 'none';
    box.offsetHeight;
    box.style.animation = 'shake .3s ease';
}

// ── Panel de Logs (solo admin) ────────────────────────────────────────────────
async function verLogs(id) {
    if (!ES_ADMIN) return;
    document.getElementById('logsContenido').innerHTML =
        '<div style="text-align:center;padding:30px;opacity:.5;"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
    document.getElementById('modalLogs').classList.add('active');

    const fd = new FormData();
    fd.append('ajax','1'); fd.append('accion','logs'); fd.append('ot_id', id);
    try {
        const r = await fetch('proceso.php', { method:'POST', body:fd });
        const d = await r.json();
        if (!d.ok) { document.getElementById('logsContenido').innerHTML = '<p style="color:#f44336">Error al cargar logs.</p>'; return; }
        renderLogs(d);
    } catch(e) {
        document.getElementById('logsContenido').innerHTML = '<p style="color:#f44336">Error de conexión.</p>';
    }
}

function renderLogs(d) {
    const fmtMin = m => m >= 60
        ? Math.floor(m/60) + 'h ' + (m%60) + 'min'
        : m + ' min';

    const accionLabel = {
        inicio:      '<span style="color:#4caf50">▶ Inicio</span>',
        reanudacion: '<span style="color:#2196f3">↻ Reanudación</span>',
        pausa:       '<span style="color:#FF9800">⏸ Pausa</span>',
        fin:         '<span style="color:#e91e63">✓ Fin</span>',
    };

    // Resumen encabezado
    const tot = d.totales || {};
    let html = `
    <div class="logs-resumen">
        <div class="logs-res-item"><span>Máquina</span><strong>${tot.maquina || '—'}</strong></div>
        <div class="logs-res-item"><span>Proceso</span><strong>${tot.proceso || '—'}</strong></div>
        <div class="logs-res-item acum-regular"><span>Horas Regulares</span><strong>${fmtMin(tot.tiempo_regular_min||0)}</strong></div>
        <div class="logs-res-item acum-extra"><span>Horas Extra</span><strong>${fmtMin(tot.tiempo_extra_min||0)}</strong></div>
        <div class="logs-res-item" style="color:#f44336"><span>Horas Muertas</span><strong>${fmtMin(d.muerto_min||0)}</strong></div>
    </div>`;

    if (!d.logs.length) {
        html += '<p style="text-align:center;opacity:.5;padding:20px;">Sin registros de actividad.</p>';
    } else {
        html += '<div style="overflow-x:auto;"><table class="logs-tabla"><thead><tr>' +
            '<th>Hora</th><th>Acción</th><th>Operador</th><th>Máquina / Actividad</th>' +
            '<th>Resumen / Motivo</th><th>Piezas</th><th>Reg.</th><th>Extra</th>' +
            '</tr></thead><tbody>';

        d.logs.forEach(l => {
            const dt = l.timestamp.substring(0,16).replace('T',' ');
            const maqAct = l.accion==='inicio'||l.accion==='reanudacion'
                ? (l.maquina||'') + (l.actividad ? ' / '+l.actividad : '')
                : '';
            const resMotivo = [l.resumen, l.motivo_pausa ? '('+l.motivo_pausa+')' : ''].filter(Boolean).join(' ');
            const reg = l.tiempo_regular_min ? fmtMin(l.tiempo_regular_min) : '—';
            const ext = l.tiempo_extra_min   ? fmtMin(l.tiempo_extra_min)   : '—';
            html += `<tr>
                <td style="white-space:nowrap">${dt}</td>
                <td>${accionLabel[l.accion]||l.accion}</td>
                <td>${l.operador_nombre||l.usuario_nombre||'—'}</td>
                <td style="font-size:.75rem">${maqAct}</td>
                <td style="font-size:.75rem">${resMotivo}</td>
                <td style="text-align:center">${l.piezas_terminadas||'—'}</td>
                <td style="text-align:center;color:#4caf50">${reg}</td>
                <td style="text-align:center;color:#FF9800">${ext}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
    }
    document.getElementById('logsContenido').innerHTML = html;
}
</script>

<!-- ══════════════ MODAL: LOGS (solo admin) ══════════════ -->
<div class="modal-overlay" id="modalLogs">
    <div class="modal-box" style="max-width:820px;">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-history"></i> Historial de Actividad — OT</span>
            <button class="modal-close" onclick="cerrarModal('modalLogs')">&times;</button>
        </div>
        <div class="modal-body" id="logsContenido" style="gap:12px;padding:16px 20px;">
            <!-- contenido dinámico -->
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="cerrarModal('modalLogs')">Cerrar</button>
        </div>
    </div>
</div>

<style>
/* ── Bloque pre-llenado del modal inicio ── */
.ini-prefill-block {
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.1);
    border-radius:12px;
    padding:14px 16px;
}
.ini-prefill-label {
    font-size:.7rem; font-weight:700; color:rgba(255,255,255,.4);
    text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px;
}
.ini-prefill-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:10px;
}
.ini-prefill-item { display:flex; flex-direction:column; gap:2px; }
.ini-pl-key { font-size:.65rem; color:rgba(255,255,255,.4); text-transform:uppercase; letter-spacing:.4px; }
.ini-pl-val { font-size:.88rem; font-weight:700; color:#fff; }

/* ── Botón logs en el acum ── */
.proc-acum-logs-btn {
    background:rgba(255,255,255,.08); border:none; border-radius:8px;
    color:rgba(255,255,255,.6); padding:6px 10px; font-size:.72rem;
    font-family:'Montserrat',sans-serif; font-weight:700; cursor:pointer;
    align-self:center; white-space:nowrap; transition:background .2s;
}
.proc-acum-logs-btn:hover { background:rgba(255,255,255,.18); color:#fff; }

/* ── Resumen de logs ── */
.logs-resumen {
    display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px;
}
.logs-res-item {
    background:rgba(255,255,255,.06); border-radius:10px;
    padding:10px 14px; display:flex; flex-direction:column; gap:2px; flex:1; min-width:110px;
}
.logs-res-item span { font-size:.65rem; color:rgba(255,255,255,.45); text-transform:uppercase; letter-spacing:.4px; }
.logs-res-item strong { font-size:.9rem; color:#fff; }

/* ── Tabla de logs ── */
.logs-tabla {
    width:100%; border-collapse:collapse; font-size:.78rem;
}
.logs-tabla th {
    background:rgba(255,255,255,.08); color:rgba(255,255,255,.7);
    padding:8px 10px; text-align:left; font-size:.68rem;
    text-transform:uppercase; letter-spacing:.4px;
    border-bottom:1px solid rgba(255,255,255,.1);
}
.logs-tabla td {
    padding:7px 10px; border-bottom:1px solid rgba(255,255,255,.05); vertical-align:top;
}
.logs-tabla tr:last-child td { border-bottom:none; }
.logs-tabla tr:hover td { background:rgba(255,255,255,.03); }

@keyframes shake {
    0%,100%{transform:translateX(0)}
    20%{transform:translateX(-8px)}
    40%{transform:translateX(8px)}
    60%{transform:translateX(-5px)}
    80%{transform:translateX(5px)}
}

/* ── Toast de error ── */
.proc-toast {
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px);
    background:#c62828; color:#fff; padding:12px 24px; border-radius:12px;
    font-weight:700; font-size:.9rem; z-index:9999;
    box-shadow:0 4px 20px rgba(0,0,0,.5);
    transition:transform .3s ease, opacity .3s ease; opacity:0;
    display:flex; align-items:center; gap:10px; white-space:nowrap;
}
.proc-toast.visible { transform:translateX(-50%) translateY(0); opacity:1; }
</style>

<!-- ══════════════ MODAL: CONFIRMAR ACCIÓN ══════════════ -->
<div class="modal-overlay" id="modalProcConfirmar">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-header">
            <span class="modal-title">Confirmar</span>
            <button class="modal-close" onclick="cerrarModal('modalProcConfirmar')">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;padding:28px 24px;">
            <i id="procConfirmarIcon" class="fas fa-flag-checkered" style="font-size:2.8rem;color:#e91e63;margin-bottom:14px;display:block;"></i>
            <p id="procConfirmarMensaje" style="color:rgba(255,255,255,.9);font-size:1rem;line-height:1.5;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="cerrarModal('modalProcConfirmar')">Cancelar</button>
            <button id="btnProcConfirmarSi" class="btn-confirm rojo" onclick="_procConfirmarSi()">
                <i id="procConfirmarIconBtn" class="fas fa-flag-checkered"></i>
                <span id="btnProcConfirmarLabel">Finalizar</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════ TOAST ERROR ══════════════ -->
<div class="proc-toast" id="procToastError">
    <i class="fas fa-exclamation-circle"></i>
    <span id="procToastMsg"></span>
</div>

<script>
// ── Horario de trabajo automático ─────────────────────────────────────────────
// Turno: 08:00-13:00 y 14:00-18:00 | Comida: 13:00-14:00
(function() {
    const HORARIO = {
        inicioTurno: { h: 8,  m: 0, accion: 'auto_reanudar', tipo: 'turno',  msj: 'Inicio de turno. Reanudando OTs.' },
        inicioComida:{ h: 13, m: 0, accion: 'auto_pausar',   tipo: 'comida', msj: 'Hora de comida. Pausando OTs.' },
        finComida:   { h: 14, m: 0, accion: 'auto_reanudar', tipo: 'comida', msj: 'Fin de comida. Reanudando OTs.' },
        finTurno:    { h: 18, m: 0, accion: 'auto_pausar',   tipo: 'turno',  msj: 'Fin de turno. Pausando OTs.' },
    };

    const LSKEY = 'procAutoHorario_' + new Date().toDateString();
    let fired = {};
    try { fired = JSON.parse(localStorage.getItem(LSKEY) || '{}'); } catch(e) {}

    async function dispararAutoHorario(key, accion, tipo, msj) {
        if (fired[key]) return;
        fired[key] = true;
        localStorage.setItem(LSKEY, JSON.stringify(fired));
        try {
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('accion', accion);
            fd.append('ot_id', '0');
            fd.append('tipo', tipo);
            const r = await fetch('proceso.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.ok && d.count > 0) {
                mostrarToastError(msj + ' (' + d.count + ' OT' + (d.count > 1 ? 's' : '') + ')');
                setTimeout(() => location.reload(), 2500);
            }
        } catch(e) {}
    }

    function checkHorario() {
        const now = new Date();
        const h = now.getHours();
        const m = now.getMinutes();
        Object.entries(HORARIO).forEach(([key, cfg]) => {
            if (h === cfg.h && m === cfg.m) {
                dispararAutoHorario(key, cfg.accion, cfg.tipo, cfg.msj);
            }
        });
    }

    // Verificar al cargar si estamos en pausa de comida o fuera de horario
    // para auto-pausar OTs que pudieran estar activas
    (function checkAlCargar() {
        const now = new Date();
        const hm = now.getHours() * 60 + now.getMinutes();
        const dentroComida = hm >= 13 * 60 && hm < 14 * 60;
        const fueraTurno   = hm < 8 * 60 || hm >= 18 * 60;
        if (dentroComida && !fired['inicioComida']) {
            dispararAutoHorario('inicioComida', 'auto_pausar', 'comida', 'Hora de comida. Pausando OTs.');
        } else if (fueraTurno && !fired['finTurno'] && !fired['inicioTurno']) {
            dispararAutoHorario('finTurno', 'auto_pausar', 'turno', 'Fuera de horario. Pausando OTs.');
        }
    })();

    setInterval(checkHorario, 30000);
})();
</script>

</body>
</html>
