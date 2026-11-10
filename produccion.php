<?php
require_once 'includes/config.php';
require_once 'includes/equipos_fabricados.php';
require_once 'includes/generar_requisicion.php';

// Única fuente de verdad del catálogo de maquinaria. Usado en PHP por el
// selector de "Nueva OT" y la carga por máquina de "Lista de Actividades", y
// volcado como JSON al JS global (const MAQUINARIA, más abajo en este archivo)
// para el dropdown de "Proceso" que depende de la máquina elegida.
// Vive en la tabla `maquinas` (antes era un array fijo aquí mismo) para poder
// agregar máquinas nuevas desde la UI sin tocar código.
function produccionCatalogoMaquinas(): array {
    $db = getDB();
    $rows = $db->query("SELECT clave, nombre, tag, modelo, marca, procesos FROM maquinas WHERE activo=1 ORDER BY orden, id")->fetchAll();
    $catalogo = [];
    foreach ($rows as $r) {
        $catalogo[$r['clave']] = [
            'nombre'   => $r['nombre'],
            'tag'      => $r['tag'] ?? '',
            'mod'      => $r['modelo'] ?? '',
            'marca'    => $r['marca'] ?? '',
            'procesos' => $r['procesos'] ? json_decode($r['procesos'], true) : [],
        ];
    }
    return $catalogo;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}

if (!isLoggedIn() || (!hasAcceso('manufactura') && !isAdmin())) {
    header('Location: index.php');
    exit();
}

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;
$db = getDB();
$seccion = $_GET['seccion'] ?? '';

// ── Migraciones inline ─────────────────────────────────────────────────────
try { $db->exec("ALTER TABLE proyectos ADD COLUMN produccion_liberada TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS es_prioritaria TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS en_stand_by TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS ots_liberadas TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS en_problema TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS problema_descripcion TEXT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS requisicion_externa_id INT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE proyectos ADD COLUMN IF NOT EXISTS orden_ver_proceso INT NOT NULL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE requisiciones ADD COLUMN IF NOT EXISTS ot_id INT NULL"); } catch(Exception $e) {}
try { $db->exec("
    CREATE TABLE IF NOT EXISTS maquinas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clave VARCHAR(50) NOT NULL UNIQUE,
        nombre VARCHAR(150) NOT NULL,
        tag VARCHAR(30) NULL,
        modelo VARCHAR(100) NULL,
        marca VARCHAR(100) NULL,
        procesos TEXT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0,
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
"); } catch(Exception $e) {}

// ── Limpieza única de datos fantasma (corre solo una vez en toda la instalación) ──
$_ghost_flag = __DIR__ . '/logs/.ghost_cleanup_v1';
if (!file_exists($_ghost_flag)) {
    try {
        // Borra OTs huérfanas (proyecto ya no existe)
        $db->exec("DELETE ot FROM ordenes_trabajo ot LEFT JOIN proyectos p ON p.id = ot.proyecto_id WHERE p.id IS NULL");
        // Borra boom_listas huérfanas
        $db->exec("DELETE bl FROM boom_listas bl LEFT JOIN proyectos p ON p.id = bl.proyecto_id WHERE p.id IS NULL");
        // Borra eventos de calendario huérfanos
        $db->exec("DELETE ce FROM calendario_eventos ce LEFT JOIN proyectos p ON p.id = ce.proyecto_id WHERE p.id IS NULL");
        @mkdir(__DIR__ . '/logs', 0755, true);
        file_put_contents($_ghost_flag, date('Y-m-d H:i:s'));
    } catch (Exception $e) { /* silencioso */ }
}

// ── AJAX: Operaciones de generar_ot (sin recarga de página) ──────────────────
if (!empty($_POST['ajax_got'])) {
    header('Content-Type: application/json');
    $ag_accion = $_POST['accion'] ?? '';

    if ($ag_accion === 'crear_orden') {
        $boom_id_ag    = intval($_POST['boom_id']);
        $prioridad_ag  = sanitize($_POST['prioridad'] ?? 'media');
        $maquina_ag    = sanitize($_POST['maquina']   ?? '');
        $proceso_ag    = sanitize($_POST['proceso']   ?? '');
        if ($proceso_ag === '') {
            echo json_encode(['ok'=>false,'error'=>'Debes seleccionar una máquina y un proceso']);
            exit();
        }
        $f_comp_ag     = $_POST['fecha_compromiso'] ?? null;
        $procesos_ag   = $_POST['procesos'] ?? [];
        $operador_ag   = sanitize($_POST['operador_nombre'] ?? '');
        $pagina_id_ag  = !empty($_POST['plano_pagina_id']) ? intval($_POST['plano_pagina_id']) : null;
        if ($pagina_id_ag) {
            $chk_pag = $db->prepare("SELECT id FROM plano_paginas WHERE id=? AND boom_lista_id=?");
            $chk_pag->execute([$pagina_id_ag, $boom_id_ag]);
            if (!$chk_pag->fetch()) { echo json_encode(['ok'=>false,'error'=>'Página no pertenece al plano']); exit(); }
        }
        // Secuencia_ejecucion: si el usuario la especifica se usa; si no, se calcula automáticamente
        $secuencia_raw = intval($_POST['secuencia_ejecucion'] ?? 0);

        // Derivar proyecto_id del boom_lista, no del POST (evita contaminación cruzada)
        $stmt_bl = $db->prepare("SELECT bl.pieza_nombre, bl.proyecto_id, p.nombre FROM boom_listas bl JOIN proyectos p ON p.id=bl.proyecto_id WHERE bl.id=? AND bl.tipo_lista='plano'");
        $stmt_bl->execute([$boom_id_ag]);
        $bl_info = $stmt_bl->fetch();
        if (!$bl_info) { echo json_encode(['ok'=>false,'error'=>'Plano no encontrado']); exit(); }
        $proyecto_id_ag = (int)$bl_info['proyecto_id'];
        $pieza_nm = $bl_info['pieza_nombre'] ?? 'Pieza';
        $proy_nm  = $bl_info['nombre'] ?? '';

        $cnt_stmt = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE boom_lista_id=?");
        $cnt_stmt->execute([$boom_id_ag]);
        $cnt_base = (int)$cnt_stmt->fetchColumn();

        // Calcular siguiente posición y secuencia de ejecución
        if ($pagina_id_ag) {
            $pos_stmt = $db->prepare("SELECT COALESCE(MAX(orden_posicion),0)+1 FROM ordenes_trabajo WHERE plano_pagina_id=?");
            $pos_stmt->execute([$pagina_id_ag]);
        } else {
            $pos_stmt = $db->prepare("SELECT COALESCE(MAX(orden_posicion),0)+1 FROM ordenes_trabajo WHERE boom_lista_id=?");
            $pos_stmt->execute([$boom_id_ag]);
        }
        $pos_next = (int)$pos_stmt->fetchColumn();

        // Si el usuario no especificó secuencia, auto-asignar la siguiente disponible
        if ($secuencia_raw <= 0) {
            $sec_stmt = $db->prepare("SELECT COALESCE(MAX(secuencia_ejecucion),0)+1 FROM ordenes_trabajo WHERE boom_lista_id=?");
            $sec_stmt->execute([$boom_id_ag]);
            $secuencia_ag = (int)$sec_stmt->fetchColumn();
        } else {
            $secuencia_ag = $secuencia_raw;
        }

        $created = [];
        try {
            $db->beginTransaction();
            // Se crea UNA sola OT por llamada (la cantidad se eliminó del formulario)
            $num    = str_pad($cnt_base + 1, 4, '0', STR_PAD_LEFT);
            $folio  = generarFolioOT($proyecto_id_ag, $proy_nm);
            $titulo = trim($pieza_nm) . ' — ' . $num;
            $db->prepare("INSERT INTO ordenes_trabajo (folio,boom_lista_id,proyecto_id,titulo,prioridad,maquina,proceso,fecha_compromiso,estado,orden_posicion,secuencia_ejecucion,operador_nombre,creado_por,plano_pagina_id) VALUES (?,?,?,?,?,?,?,?,'pendiente',?,?,?,?,?)")
               ->execute([$folio,$boom_id_ag,$proyecto_id_ag,$titulo,$prioridad_ag,$maquina_ag?:null,$proceso_ag?:null,$f_comp_ag?:null,$pos_next,$secuencia_ag,$operador_ag?:null,$_SESSION['user_id'],$pagina_id_ag]);
            $ot_id_new = (int)$db->lastInsertId();
            // Crear procesos si vienen en el request
            if (!empty($procesos_ag)) {
                foreach ($procesos_ag as $pi => $pnombre) {
                    $pnombre = trim($pnombre);
                    if (!$pnombre) continue;
                    $db->prepare("INSERT INTO ot_procesos (ot_id,nombre,orden,estado) VALUES (?,?,?,?)")
                       ->execute([$ot_id_new, $pnombre, $pi, $pi===0?'activo':'pendiente']);
                }
            } elseif ($proceso_ag) {
                $db->prepare("INSERT INTO ot_procesos (ot_id,nombre,orden,estado) VALUES (?,?,0,'activo')")
                   ->execute([$ot_id_new, $proceso_ag]);
            }
            $created[] = ['id'=>$ot_id_new,'folio'=>$folio,'titulo'=>$titulo,'estado'=>'pendiente','prioridad'=>$prioridad_ag,'maquina'=>$maquina_ag,'proceso'=>$proceso_ag,'orden_posicion'=>$pos_next,'secuencia_ejecucion'=>$secuencia_ag,'operador_nombre'=>$operador_ag,'es_prioritaria'=>0,'ots_liberadas'=>0,'fecha_compromiso'=>$f_comp_ag,'plano_pagina_id'=>$pagina_id_ag];
            $db->commit();
        } catch(Exception $e) { $db->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit(); }
        echo json_encode(['ok'=>true,'ots'=>$created]);

    } elseif ($ag_accion === 'eliminar_orden') {
        // Mismo fix que el bloque no-AJAX de más abajo: filtrar por autor.
        $ot_id_ag = intval($_POST['ot_id'] ?? 0);
        $db->prepare("DELETE FROM ordenes_trabajo WHERE id=? AND creado_por=?")->execute([$ot_id_ag, $_SESSION['user_id']]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'liberar_ots') {
        $boom_id_ag = intval($_POST['boom_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET ots_liberadas=1 WHERE boom_lista_id=? AND estado='pendiente'")->execute([$boom_id_ag]);
        $bl_info2 = $db->prepare("SELECT bl.pieza_nombre, p.nombre FROM boom_listas bl JOIN proyectos p ON p.id=bl.proyecto_id WHERE bl.id=?");
        $bl_info2->execute([$boom_id_ag]);
        $bld2 = $bl_info2->fetch();
        if ($bld2) notificarRoles(['proceso'], 'ots_liberadas', "OTs liberadas — {$bld2['pieza_nombre']}", "OTs del plano \"{$bld2['pieza_nombre']}\" ({$bld2['nombre']}) liberadas.", "proceso.php", $_SESSION['user_id']);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'marcar_prioritaria') {
        $ot_id_ag   = intval($_POST['ot_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=1 WHERE id=?")->execute([$ot_id_ag]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'quitar_prioritaria') {
        $ot_id_ag = intval($_POST['ot_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=0 WHERE id=?")->execute([$ot_id_ag]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'marcar_prioritaria_proyecto') {
        $proyecto_id_ag = intval($_POST['proyecto_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=1 WHERE proyecto_id=? AND estado='pendiente'")->execute([$proyecto_id_ag]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'quitar_prioritaria_proyecto') {
        $proyecto_id_ag = intval($_POST['proyecto_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=0 WHERE proyecto_id=? AND estado='pendiente'")->execute([$proyecto_id_ag]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'marcar_prioritaria_pagina') {
        $pagina_id_ag = intval($_POST['pagina_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=1 WHERE plano_pagina_id=? AND estado='pendiente'")->execute([$pagina_id_ag]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'quitar_prioritaria_pagina') {
        $pagina_id_ag = intval($_POST['pagina_id'] ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=0 WHERE plano_pagina_id=? AND estado='pendiente'")->execute([$pagina_id_ag]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'reordenar') {
        $orden_ag = $_POST['orden'] ?? [];
        foreach ($orden_ag as $pos => $ot_id_r) {
            $db->prepare("UPDATE ordenes_trabajo SET orden_posicion=? WHERE id=?")->execute([$pos+1, intval($ot_id_r)]);
        }
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'reordenar_proyectos_vp') {
        $orden_p = $_POST['orden'] ?? [];
        foreach ($orden_p as $pos => $proyecto_id_r) {
            $db->prepare("UPDATE proyectos SET orden_ver_proceso=? WHERE id=?")->execute([$pos+1, intval($proyecto_id_r)]);
        }
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'get_ots') {
        $boom_id_ag = intval($_POST['boom_id'] ?? 0);
        // Derivar proyecto_id del plano para filtrar correctamente (evita OTs cruzadas)
        $bl_proy = $db->prepare("SELECT proyecto_id FROM boom_listas WHERE id=?");
        $bl_proy->execute([$boom_id_ag]);
        $proy_id_ag = (int)($bl_proy->fetchColumn() ?: 0);
        $s_ag = $db->prepare("SELECT ot.id,ot.folio,ot.titulo,ot.estado,ot.prioridad,ot.es_prioritaria,ot.ots_liberadas,ot.maquina,ot.proceso,ot.fecha_compromiso,ot.orden_posicion FROM ordenes_trabajo ot WHERE ot.boom_lista_id=? AND ot.proyecto_id=? ORDER BY ot.es_prioritaria DESC, ot.orden_posicion ASC, ot.id ASC");
        $s_ag->execute([$boom_id_ag, $proy_id_ag]);
        echo json_encode(['ok'=>true,'ots'=>$s_ag->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($ag_accion === 'inicializar_paginas') {
        $boom_id_ag   = intval($_POST['boom_id'] ?? 0);
        $total_pags   = intval($_POST['total_paginas'] ?? 0);
        if (!$boom_id_ag || $total_pags < 1) { echo json_encode(['ok'=>false,'error'=>'Parámetros inválidos']); exit(); }
        $chk = $db->prepare("SELECT id FROM boom_listas WHERE id=? AND tipo_lista='plano'");
        $chk->execute([$boom_id_ag]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'Plano no encontrado']); exit(); }
        $ins = $db->prepare("INSERT IGNORE INTO plano_paginas (boom_lista_id, numero_pagina, nombre) VALUES (?,?,?)");
        for ($pg = 1; $pg <= $total_pags; $pg++) {
            $ins->execute([$boom_id_ag, $pg, 'Página ' . $pg]);
        }
        $rows = $db->prepare("SELECT id, numero_pagina, nombre, ignorada, numero_serie FROM plano_paginas WHERE boom_lista_id=? ORDER BY numero_pagina");
        $rows->execute([$boom_id_ag]);
        echo json_encode(['ok'=>true, 'paginas'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);

    } elseif ($ag_accion === 'renombrar_pagina') {
        $pagina_id  = intval($_POST['pagina_id'] ?? 0);
        $boom_id_rn = intval($_POST['boom_id']   ?? 0);
        $nombre     = trim(sanitize($_POST['nombre'] ?? ''));
        if (!$pagina_id || !$boom_id_rn || $nombre === '') { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit(); }
        $chk_rn = $db->prepare("SELECT id FROM plano_paginas WHERE id=? AND boom_lista_id=?");
        $chk_rn->execute([$pagina_id, $boom_id_rn]);
        if (!$chk_rn->fetch()) { echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit(); }
        $db->prepare("UPDATE plano_paginas SET nombre=? WHERE id=?")->execute([$nombre, $pagina_id]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'guardar_serie_pagina') {
        $pagina_id  = intval($_POST['pagina_id'] ?? 0);
        $boom_id_sr = intval($_POST['boom_id']   ?? 0);
        $numeroSerie = trim(sanitize($_POST['numero_serie'] ?? ''));
        if (!$pagina_id || !$boom_id_sr) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit(); }
        $chk_sr = $db->prepare("SELECT id FROM plano_paginas WHERE id=? AND boom_lista_id=?");
        $chk_sr->execute([$pagina_id, $boom_id_sr]);
        if (!$chk_sr->fetch()) { echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit(); }
        $db->prepare("UPDATE plano_paginas SET numero_serie=? WHERE id=?")->execute([$numeroSerie ?: null, $pagina_id]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'ignorar_pagina') {
        $pagina_id  = intval($_POST['pagina_id'] ?? 0);
        $boom_id_ig = intval($_POST['boom_id']   ?? 0);
        $ignorada   = intval($_POST['ignorada']  ?? 0) ? 1 : 0;
        if (!$pagina_id || !$boom_id_ig) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit(); }
        $chk_ig = $db->prepare("SELECT id FROM plano_paginas WHERE id=? AND boom_lista_id=?");
        $chk_ig->execute([$pagina_id, $boom_id_ig]);
        if (!$chk_ig->fetch()) { echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit(); }
        $db->prepare("UPDATE plano_paginas SET ignorada=? WHERE id=?")->execute([$ignorada, $pagina_id]);
        echo json_encode(['ok'=>true]);

    } elseif ($ag_accion === 'reordenar_pagina') {
        $pagina_id_r = intval($_POST['pagina_id'] ?? 0);
        $orden_r     = $_POST['orden'] ?? [];
        if (!$pagina_id_r) { echo json_encode(['ok'=>false]); exit(); }
        foreach ($orden_r as $pos => $ot_id_r) {
            $db->prepare("UPDATE ordenes_trabajo SET orden_posicion=? WHERE id=? AND plano_pagina_id=?")
               ->execute([$pos + 1, intval($ot_id_r), $pagina_id_r]);
        }
        echo json_encode(['ok'=>true]);
    }
    exit();
}

// ── Migración inline: orden_posicion, secuencia_ejecucion, operador_nombre ────
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
    INDEX idx_otp2 (ot_id),
    FOREIGN KEY (ot_id) REFERENCES ordenes_trabajo(id) ON DELETE CASCADE
)"); } catch(Exception $e) {}

// ── Migración inline: plano_paginas y plano_pagina_id ────────────────────────
try { $db->exec("
    CREATE TABLE IF NOT EXISTS plano_paginas (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        boom_lista_id   INT NOT NULL,
        numero_pagina   INT NOT NULL,
        nombre          VARCHAR(200) NOT NULL DEFAULT '',
        ignorada        TINYINT(1) NOT NULL DEFAULT 0,
        fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_plano_pagina (boom_lista_id, numero_pagina)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE ordenes_trabajo ADD COLUMN IF NOT EXISTS plano_pagina_id INT NULL"); } catch(Exception $e) {}

$message = '';
$error   = '';

if (isset($_GET['accion']) && $_GET['accion'] === 'get_ots_pagina') {
    header('Content-Type: application/json');
    $pagina_id_g = intval($_GET['pagina_id'] ?? 0);
    if (!$pagina_id_g) { echo json_encode(['ok'=>false]); exit(); }
    $s = $db->prepare("SELECT id, folio, titulo, estado, prioridad, es_prioritaria, ots_liberadas, maquina, proceso, fecha_compromiso, orden_posicion, plano_pagina_id, tiempo_regular_min, tiempo_extra_min FROM ordenes_trabajo WHERE plano_pagina_id = ? ORDER BY es_prioritaria DESC, orden_posicion ASC, id ASC");
    $s->execute([$pagina_id_g]);
    echo json_encode(['ok'=>true, 'ots'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

// ── AJAX: detalle completo de OT para ver_proceso ─────────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_ot_detalle') {
    header('Content-Type: application/json');
    $ot_id = intval($_GET['ot_id'] ?? 0);
    if (!$ot_id) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit(); }

    $stmt = $db->prepare("
        SELECT ot.*, bl.pieza_nombre, p.nombre AS proyecto_nombre,
               u.nombre_completo   AS usuario_inicio_nombre,
               uvp.nombre_completo AS vb_prod_nombre,
               uvc.nombre_completo AS vb_cal_nombre
        FROM ordenes_trabajo ot
        JOIN boom_listas bl   ON bl.id  = ot.boom_lista_id
        JOIN proyectos p      ON p.id   = ot.proyecto_id
        LEFT JOIN usuarios u   ON u.id  = ot.operario_id
        LEFT JOIN usuarios uvp ON uvp.id = ot.visto_produccion_por
        LEFT JOIN usuarios uvc ON uvc.id = ot.visto_calidad_por
        WHERE ot.id = ?
    ");
    $stmt->execute([$ot_id]);
    $ot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ot) { echo json_encode(['ok' => false, 'error' => 'OT no encontrada']); exit(); }

    $logs_stmt = $db->prepare("
        SELECT l.*, u.nombre_completo AS usuario_nombre
        FROM ot_logs l
        LEFT JOIN usuarios u ON u.id = l.usuario_id
        WHERE l.ot_id = ?
        ORDER BY l.timestamp ASC
    ");
    $logs_stmt->execute([$ot_id]);
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

    $reg = 0; $ext = 0; $piezas = 0;
    foreach ($logs as $l) {
        $reg    += (int)($l['tiempo_regular_min'] ?? 0);
        $ext    += (int)($l['tiempo_extra_min']   ?? 0);
        $piezas += (int)($l['piezas_terminadas']  ?? 0);
    }

    // Todas las requisiciones externas generadas para esta OT (puede haber varias).
    $reqs_stmt = $db->prepare("SELECT id, folio, estado, fecha_creacion FROM requisiciones WHERE ot_id = ? ORDER BY fecha_creacion DESC");
    $reqs_stmt->execute([$ot_id]);
    $requisiciones_externas = $reqs_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'    => true,
        'ot'    => $ot,
        'logs'  => $logs,
        'totales' => ['regular_min' => $reg, 'extra_min' => $ext, 'piezas' => $piezas],
        'requisiciones_externas' => $requisiciones_externas,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── AJAX: detalle agregado de proyecto para ver_proceso ────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_proyecto_detalle') {
    header('Content-Type: application/json');
    $proyecto_id = intval($_GET['proyecto_id'] ?? 0);
    if (!$proyecto_id) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit(); }

    $p_stmt = $db->prepare("SELECT id, nombre FROM proyectos WHERE id = ?");
    $p_stmt->execute([$proyecto_id]);
    $proyecto = $p_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proyecto) { echo json_encode(['ok' => false, 'error' => 'Proyecto no encontrado']); exit(); }

    $agg_stmt = $db->prepare("
        SELECT
            COALESCE(SUM(l.tiempo_regular_min), 0) AS regular_min,
            COALESCE(SUM(l.tiempo_extra_min), 0)   AS extra_min,
            COALESCE(SUM(l.piezas_terminadas), 0)  AS piezas
        FROM ot_logs l
        JOIN ordenes_trabajo ot ON ot.id = l.ot_id
        WHERE ot.proyecto_id = ?
    ");
    $agg_stmt->execute([$proyecto_id]);
    $agg = $agg_stmt->fetch(PDO::FETCH_ASSOC);

    $ots_stmt = $db->prepare("SELECT estado FROM ordenes_trabajo WHERE proyecto_id = ?");
    $ots_stmt->execute([$proyecto_id]);
    $ots_estados = $ots_stmt->fetchAll(PDO::FETCH_COLUMN);
    $total_ots = count($ots_estados);
    $ok_ots    = count(array_filter($ots_estados, fn($e) => $e === 'completado'));

    echo json_encode([
        'ok'      => true,
        'nombre'  => $proyecto['nombre'],
        'totales' => [
            'regular_min' => (int)$agg['regular_min'],
            'extra_min'   => (int)$agg['extra_min'],
            'piezas'      => (int)$agg['piezas'],
            'ots_total'   => $total_ots,
            'ots_ok'      => $ok_ots,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── AJAX: detalle agregado de página para ver_proceso ───────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_pagina_detalle') {
    header('Content-Type: application/json');
    $pagina_id = intval($_GET['pagina_id'] ?? 0);
    if (!$pagina_id) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit(); }

    $pg_stmt = $db->prepare("SELECT id, nombre FROM plano_paginas WHERE id = ?");
    $pg_stmt->execute([$pagina_id]);
    $pagina = $pg_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pagina) { echo json_encode(['ok' => false, 'error' => 'Página no encontrada']); exit(); }

    $ots_stmt = $db->prepare("SELECT id, folio, titulo, estado FROM ordenes_trabajo WHERE plano_pagina_id = ?");
    $ots_stmt->execute([$pagina_id]);
    $ots = $ots_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_ots = count($ots);
    $ok_ots    = count(array_filter($ots, fn($o) => $o['estado'] === 'completado'));

    $logs = [];
    $reg = 0; $ext = 0; $piezas = 0;
    if ($total_ots > 0) {
        $ot_ids = array_column($ots, 'id');
        $placeholders = implode(',', array_fill(0, count($ot_ids), '?'));
        $logs_stmt = $db->prepare("
            SELECT l.*, ot.folio AS ot_folio, ot.titulo AS ot_titulo, u.nombre_completo AS usuario_nombre
            FROM ot_logs l
            JOIN ordenes_trabajo ot ON ot.id = l.ot_id
            LEFT JOIN usuarios u ON u.id = l.usuario_id
            WHERE l.ot_id IN ($placeholders)
            ORDER BY l.timestamp ASC
        ");
        $logs_stmt->execute($ot_ids);
        $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as $l) {
            $reg    += (int)($l['tiempo_regular_min'] ?? 0);
            $ext    += (int)($l['tiempo_extra_min']   ?? 0);
            $piezas += (int)($l['piezas_terminadas']  ?? 0);
        }
    }

    echo json_encode([
        'ok'      => true,
        'nombre'  => $pagina['nombre'],
        'logs'    => $logs,
        'totales' => [
            'regular_min' => $reg,
            'extra_min'   => $ext,
            'piezas'      => $piezas,
            'ots_total'   => $total_ots,
            'ots_ok'      => $ok_ots,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── AJAX: detalle agregado de plano para ver_proceso ────────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_plano_detalle') {
    header('Content-Type: application/json');
    $plano_id = intval($_GET['plano_id'] ?? 0);
    if (!$plano_id) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit(); }

    $pl_stmt = $db->prepare("SELECT id, pieza_nombre FROM boom_listas WHERE id = ?");
    $pl_stmt->execute([$plano_id]);
    $plano = $pl_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plano) { echo json_encode(['ok' => false, 'error' => 'Plano no encontrado']); exit(); }

    $ots_stmt = $db->prepare("SELECT id, folio, titulo, estado FROM ordenes_trabajo WHERE boom_lista_id = ?");
    $ots_stmt->execute([$plano_id]);
    $ots = $ots_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_ots = count($ots);
    $ok_ots    = count(array_filter($ots, fn($o) => $o['estado'] === 'completado'));

    $logs = [];
    $reg = 0; $ext = 0; $piezas = 0;
    if ($total_ots > 0) {
        $ot_ids = array_column($ots, 'id');
        $placeholders = implode(',', array_fill(0, count($ot_ids), '?'));
        $logs_stmt = $db->prepare("
            SELECT l.*, ot.folio AS ot_folio, ot.titulo AS ot_titulo, pp.nombre AS ot_pagina, u.nombre_completo AS usuario_nombre
            FROM ot_logs l
            JOIN ordenes_trabajo ot ON ot.id = l.ot_id
            LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
            LEFT JOIN usuarios u ON u.id = l.usuario_id
            WHERE l.ot_id IN ($placeholders)
            ORDER BY l.timestamp ASC
        ");
        $logs_stmt->execute($ot_ids);
        $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as $l) {
            $reg    += (int)($l['tiempo_regular_min'] ?? 0);
            $ext    += (int)($l['tiempo_extra_min']   ?? 0);
            $piezas += (int)($l['piezas_terminadas']  ?? 0);
        }
    }

    echo json_encode([
        'ok'      => true,
        'nombre'  => $plano['pieza_nombre'],
        'logs'    => $logs,
        'totales' => [
            'regular_min' => $reg,
            'extra_min'   => $ext,
            'piezas'      => $piezas,
            'ots_total'   => $total_ots,
            'ots_ok'      => $ok_ots,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── AJAX: eventos de calendario de producción ─────────────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_cal_produccion') {
    header('Content-Type: application/json');
    $ev_stmt = $db->query("
        SELECT ce.id, ce.proyecto_id, ce.tipo, ce.fecha_inicio, ce.fecha_fin,
               p.nombre AS proyecto_nombre
        FROM calendario_eventos ce
        JOIN proyectos p ON p.id = ce.proyecto_id
        ORDER BY ce.fecha_inicio
    ");
    $result = [];
    foreach ($ev_stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $result[] = [
            'id'    => $e['id'],
            'title' => $e['proyecto_nombre'],
            'start' => $e['fecha_inicio'],
            'end'   => $e['fecha_fin'] ?? null,
            'color' => getColorProyecto($e['tipo']),
            'extendedProps' => [
                'proyecto_id' => (int)$e['proyecto_id'],
                'tipo'        => $e['tipo'],
            ],
        ];
    }
    echo json_encode($result);
    exit();
}

// ── AJAX: OTs de un proyecto para popup del calendario ────────────────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_cal_ots_proyecto') {
    header('Content-Type: application/json');
    $cp_id = intval($_GET['proyecto_id'] ?? 0);
    if (!$cp_id) { echo json_encode(['ok' => false]); exit(); }
    $cp_proy = $db->prepare("SELECT nombre, tipo FROM proyectos WHERE id=?");
    $cp_proy->execute([$cp_id]);
    $cp_pdat = $cp_proy->fetch(PDO::FETCH_ASSOC);
    $cp_ots  = $db->prepare("
        SELECT ot.id, ot.folio, ot.titulo, ot.estado, ot.pausado,
               ot.tiempo_regular_min, ot.tiempo_extra_min, bl.pieza_nombre
        FROM ordenes_trabajo ot
        JOIN boom_listas bl ON bl.id = ot.boom_lista_id
        WHERE ot.proyecto_id = ?
        ORDER BY FIELD(ot.estado,'en_proceso','pendiente','completado','cancelado'), ot.id ASC
    ");
    $cp_ots->execute([$cp_id]);
    echo json_encode([
        'ok'      => true,
        'proyecto'=> $cp_pdat,
        'ots'     => $cp_ots->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── AJAX: materiales de un OT para hoja de requisicion externa ───────────
if (isset($_GET['accion']) && $_GET['accion'] === 'get_materiales_ot') {
    header('Content-Type: application/json');
    $ot_id_m = intval($_GET['ot_id'] ?? 0);
    if (!$ot_id_m) { echo json_encode(['ok' => false]); exit(); }
    $stmt_ot_m = $db->prepare("
        SELECT ot.titulo, ot.folio, bl.pieza_nombre, p.nombre AS proy_nombre
        FROM ordenes_trabajo ot
        JOIN boom_listas bl ON bl.id = ot.boom_lista_id
        JOIN proyectos p ON p.id = ot.proyecto_id
        WHERE ot.id = ?
    ");
    $stmt_ot_m->execute([$ot_id_m]);
    $ot_info_m = $stmt_ot_m->fetch(PDO::FETCH_ASSOC);
    $mats_m = [];
    if ($ot_info_m) {
        $stmt_mats_m = $db->prepare("
            SELECT bi.descripcion, bi.cantidad, bi.unidad_medida, bi.marca, bi.modelo, bi.nota_libre, bi.material_id
            FROM boom_listas bl_ia
            JOIN boom_items bi ON bi.boom_id = bl_ia.id
            JOIN ordenes_trabajo ot ON ot.boom_lista_id = bl_ia.padre_boom_id
            WHERE ot.id = ? AND bl_ia.tipo_lista = 'plano_ia'
            ORDER BY bi.id
        ");
        $stmt_mats_m->execute([$ot_id_m]);
        $mats_m = $stmt_mats_m->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok' => true, 'ot' => $ot_info_m, 'materiales' => $mats_m], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── Cargar usuarios para asignar responsables ──────────────────────────────
$usuarios_list = $db->query("SELECT id, nombre_completo, tipo_usuario FROM usuarios WHERE activo = 1 ORDER BY nombre_completo")->fetchAll();

// Usuarios con acceso a proceso (para datalist de operador en Generar OT)
try {
    $usuarios_proceso = $db->query("SELECT id, nombre_completo FROM usuarios WHERE activo = 1 AND (tipo_usuario IN ('proceso','administrador-proceso') OR (accesos IS NOT NULL AND accesos LIKE '%proceso%')) ORDER BY nombre_completo")->fetchAll();
} catch (Exception $e) { $usuarios_proceso = []; }
if (empty($usuarios_proceso)) {
    $usuarios_proceso = $usuarios_list; // fallback: todos los usuarios activos
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'generar_requisicion_plano') {
        $boom_id_p   = intval($_POST['boom_id']);
        $proyecto_id = intval($_POST['proyecto_id']);
        $stmt = $db->prepare("SELECT bl.pieza_nombre, bl.requisicion_individual_generada, p.nombre as proyecto_nombre FROM boom_listas bl JOIN proyectos p ON p.id = bl.proyecto_id WHERE bl.id = ? AND bl.tipo_lista = 'plano'");
        $stmt->execute([$boom_id_p]);
        $plano_data = $stmt->fetch();
        $items_stmt = $db->prepare(
            "SELECT bi.*, m.descripcion as mat_nombre, m.unidad_medida as mat_unidad
             FROM boom_items bi
             JOIN boom_listas bl ON bi.boom_id = bl.id
             LEFT JOIN materiales m ON m.id = bi.material_id
             WHERE bl.padre_boom_id = ? AND bl.tipo_lista = 'plano_ia'"
        );
        $items_stmt->execute([$boom_id_p]);
        $plano_items = $items_stmt->fetchAll();
        if ($plano_data && $plano_data['requisicion_individual_generada']) {
            $error = "Ya se generó una requisición individual para este plano.";
        } elseif ($plano_data && count($plano_items) > 0) {
            try { $db->exec("ALTER TABLE almacen_items MODIFY COLUMN material_id INT NULL"); } catch(Exception $e) {}
            try {
                $db->beginTransaction();
                $notas = "Plano de manufactura: {$plano_data['pieza_nombre']}. Proyecto: {$plano_data['proyecto_nombre']}.";
                $items_req = array_map(fn($it) => [
                    'material_id'   => $it['material_id'] ?: null,
                    'descripcion'   => $it['descripcion'],
                    'cantidad'      => $it['cantidad'],
                    'unidad_medida' => $it['unidad_medida'] ?: $it['mat_unidad'] ?: '',
                ], $plano_items);
                $req = crearRequisicion($db, [
                    'usuario_id'      => $_SESSION['user_id'],
                    'proyecto'        => $plano_data['proyecto_nombre'],
                    'proyecto_id'     => $proyecto_id,
                    'area'            => 'Producción',
                    'solicitante'     => $_SESSION['user_name'] ?? '',
                    'notas_generales' => $notas,
                    'notif_titulo'    => "Requisición Manufactura: {$plano_data['pieza_nombre']}",
                ], $items_req);
                $folio = $req['folio'];
                avisarDuplicado($req['duplicados'] ?? []);

                // ── Insertar en almacen_items para que Almacén sepa que estos materiales
                // son de este proyecto (antes solo quedaban en requisiciones, invisibles
                // para almacen_items).
                foreach ($plano_items as $it) {
                    sincronizarAlmacenItem($db, $proyecto_id, $it, $_SESSION['user_id'], 'produccion');
                }
                $db->prepare("UPDATE proyectos SET almacen_activo = 1 WHERE id = ?")->execute([$proyecto_id]);

                // Marca este plano como ya solicitado — bloquea volver a generar su
                // requisición individual dos veces (ver el elseif de arriba).
                $db->prepare("UPDATE boom_listas SET requisicion_individual_generada = 1 WHERE id = ?")->execute([$boom_id_p]);
                $db->commit();
                $message = "Requisición $folio generada.";
            } catch (Exception $e) { $db->rollBack(); $error = "Error: " . $e->getMessage(); }
        } else { $error = "El plano no tiene materiales en su BOM."; }
    }

    elseif ($accion === 'cambiar_estado_plano') {
        $boom_id      = intval($_POST['boom_id']);
        $nuevo_estado = sanitize($_POST['nuevo_estado'] ?? '');
        $nota         = sanitize($_POST['nota_rechazo'] ?? '');
        $estados_validos = ['aprobado', 'rechazado', 'en_revision', 'borrador', 'enviado'];
        if (in_array($nuevo_estado, $estados_validos)) {
            if ($nuevo_estado === 'aprobado') {
                // Aprobado → guardado=1 para que avance el progreso en Ingeniería
                $db->prepare("UPDATE boom_listas SET estado_plano='aprobado', guardado=1 WHERE id=? AND tipo_lista='plano'")
                   ->execute([$boom_id]);
            } elseif ($nuevo_estado === 'rechazado') {
                // Rechazado → guardado=0, guardar nota
                $db->prepare("UPDATE boom_listas SET estado_plano='rechazado', guardado=0, plano_comentario=?, plano_comentario_fecha=NOW(), plano_comentario_por=? WHERE id=? AND tipo_lista='plano'")
                   ->execute([$nota, $_SESSION['user_id'], $boom_id]);
                // Notificar a ingeniería
                $binfo = $db->prepare("SELECT bl.pieza_nombre, p.nombre as proy_nombre FROM boom_listas bl JOIN proyectos p ON p.id=bl.proyecto_id WHERE bl.id=?");
                $binfo->execute([$boom_id]);
                if ($bdat = $binfo->fetch()) {
                    notificarRoles(['ingenieria'], 'plano_rechazado', "Plano rechazado: {$bdat['pieza_nombre']}", "El plano \"{$bdat['pieza_nombre']}\" del proyecto \"{$bdat['proy_nombre']}\" fue rechazado. Motivo: $nota", "ingenieria.php?seccion=proyectos", $_SESSION['user_id']);
                }
            } else {
                // en_revision / borrador / enviado → resetear guardado a 0
                $db->prepare("UPDATE boom_listas SET estado_plano=?, guardado=0 WHERE id=? AND tipo_lista='plano'")
                   ->execute([$nuevo_estado, $boom_id]);
            }
            $message = "Estado del plano actualizado a \xc2\xab" . $nuevo_estado . "\xc2\xbb.";
        } else { $error = "Estado no válido."; }
    }

    elseif ($accion === 'actualizar_tiempos_ot') {
        $ot_id        = intval($_POST['ot_id']);
        $tiempo_esp   = floatval($_POST['tiempo_espera'] ?? 0);
        $tiempo_fin   = floatval($_POST['tiempo_final']  ?? 0);
        $db->prepare("UPDATE ordenes_trabajo SET tiempo_espera=?, tiempo_final=? WHERE id=?")
           ->execute([$tiempo_esp, $tiempo_fin, $ot_id]);
        $message = "Tiempos actualizados.";
    }

    elseif ($accion === 'visto_bueno_ot') {
        $ot_id = intval($_POST['ot_id']);
        $tipo  = sanitize($_POST['tipo']); // produccion | calidad
        if ($tipo === 'produccion') {
            $db->prepare("UPDATE ordenes_trabajo SET visto_produccion=1, visto_produccion_por=?, visto_produccion_fecha=NOW() WHERE id=?")
               ->execute([$_SESSION['user_id'], $ot_id]);
            $message = "Visto bueno de Producción registrado.";
        } elseif ($tipo === 'calidad') {
            $db->prepare("UPDATE ordenes_trabajo SET visto_calidad=1, visto_calidad_por=?, visto_calidad_fecha=NOW(), estado='completado' WHERE id=?")
               ->execute([$_SESSION['user_id'], $ot_id]);
            $message = "Visto bueno de Calidad registrado. OT completada.";
        }
    }

    elseif ($accion === 'marcar_prioritaria') {
        $ot_id = intval($_POST['ot_id']);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=1 WHERE id=?")->execute([$ot_id]);
        $message = "OT marcada como prioritaria.";
    }

    elseif ($accion === 'quitar_prioritaria') {
        $ot_id = intval($_POST['ot_id']);
        $db->prepare("UPDATE ordenes_trabajo SET es_prioritaria=0 WHERE id=?")->execute([$ot_id]);
        $message = "Prioridad removida.";
    }

    elseif ($accion === 'liberar_ots') {
        $boom_id_lib = intval($_POST['boom_id']);
        // Marcar las OTs de este plano como liberadas (listas para proceso)
        $db->prepare("UPDATE ordenes_trabajo SET ots_liberadas=1 WHERE boom_lista_id=? AND estado='pendiente'")->execute([$boom_id_lib]);
        $cnt_stmt = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE boom_lista_id=? AND estado='pendiente'");
        $cnt_stmt->execute([$boom_id_lib]);
        $cnt = (int)$cnt_stmt->fetchColumn();
        // Notificar a proceso
        $bl_info = $db->prepare("SELECT bl.pieza_nombre, p.nombre FROM boom_listas bl JOIN proyectos p ON p.id=bl.proyecto_id WHERE bl.id=?");
        $bl_info->execute([$boom_id_lib]);
        if ($bld = $bl_info->fetch()) {
            notificarRoles(['proceso'], 'ots_liberadas', "OTs liberadas — {$bld['pieza_nombre']}", "$cnt OT(s) del plano \"{$bld['pieza_nombre']}\" ({$bld['nombre']}) liberadas a Proceso.", "proceso.php", $_SESSION['user_id']);
        }
        $message = "$cnt OT(s) liberadas a Proceso.";
    }

    // actualizar_checklist_fat (llenado digital de la Etapa 5 en Producción) -- quitado
    // (2026-08-28): a partir de aquí todo se llena a mano en el documento físico (ver el bloque
    // "Protocolo FAT" de la tarjeta, que revisa el PDF/Word/imagen firmado en papel).

    elseif ($accion === 'entregar_fat') {
        $fat_id = intval($_POST['fat_id']);
        $doc_ruta = null;
        if (!empty($_FILES['fat_documento']['tmp_name'])) {
            $realExt = validarTipoArchivoReal($_FILES['fat_documento']['tmp_name'], [
                'application/pdf' => 'pdf',
                'image/png'        => 'png',
                'image/jpeg'       => 'jpg',
            ]);
            if ($realExt) {
                $dir = __DIR__ . '/uploads/documentos_fat/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'fat_doc_' . $fat_id . '_' . time() . '.' . $realExt;
                if (move_uploaded_file($_FILES['fat_documento']['tmp_name'], $dir . $fname)) {
                    $doc_ruta = 'documentos_fat/' . $fname;
                }
            }
        }
        $db->prepare("UPDATE pruebas_fat SET entregado=1, fecha_entrega=NOW()" . ($doc_ruta ? ", documento_ruta=?" : "") . " WHERE id=? AND estado='completado'")
           ->execute($doc_ruta ? [$doc_ruta, $fat_id] : [$fat_id]);
        $message = "Prueba FAT marcada como entregada.";
    }

    elseif ($accion === 'eliminar_fat') {
        $fat_id = intval($_POST['fat_id']);
        $db->prepare("DELETE FROM pruebas_fat WHERE id = ? AND creado_por = ?")->execute([$fat_id, $_SESSION['user_id']]);
        $message = "Prueba FAT eliminada.";
    }

    // Aprobación de Producción sobre una OT completada — primera etapa antes de Calidad
    // (mismo mecanismo que calidad_ot en calidad.php, pero del lado de Producción).
    elseif ($accion === 'aprobar_ot_produccion') {
        $ot_id     = intval($_POST['ot_id']);
        $resultado = sanitize($_POST['resultado'] ?? '');
        $nota      = sanitize($_POST['nota'] ?? '');
        if (!in_array($resultado, ['aprobado','rechazado'], true)) {
            $error = 'Resultado inválido.';
        } else {
            $db->prepare("UPDATE ordenes_trabajo SET produccion_aprobacion=?, produccion_fecha=NOW(), produccion_nota=? WHERE id=?")
               ->execute([$resultado, $nota ?: null, $ot_id]);
            $stmt_ot = $db->prepare("SELECT titulo FROM ordenes_trabajo WHERE id=?");
            $stmt_ot->execute([$ot_id]);
            $titulo_ot = $stmt_ot->fetchColumn() ?: "OT-$ot_id";
            if ($resultado === 'aprobado') {
                notificarRoles(['calidad'], 'produccion_ot_aprobada', "OT lista para Calidad: $titulo_ot",
                    "Producción aprobó la OT. Ya se puede revisar en Calidad.", 'calidad.php?seccion=aprobacion_produccion', $_SESSION['user_id']);
            } else {
                notificarRoles(['manufactura'], 'produccion_ot_rechazada', "OT rechazada por Producción: $titulo_ot",
                    "Nota: $nota", 'produccion.php?seccion=aprobar_ots', $_SESSION['user_id']);
            }
            $message = "OT " . ($resultado === 'aprobado' ? 'aprobada' : 'rechazada') . " correctamente.";
        }
    }

    // ══ Equipos Fabricados — Ingeniería los crea y les pone serie/TAG (ver ingenieria.php);
    // Producción solo vincula componentes y marca terminado, bloqueado hasta que
    // proyectoListoParaEquipos() sea true. Proyectos (proyecto.php) cierra al final. ══
    elseif ($accion === 'vincular_componente_equipo') {
        $proyecto_id = intval($_POST['proyecto_id']);
        $equipo_id   = intval($_POST['equipo_id']);
        $serie_id    = intval($_POST['serie_id']);
        if (!proyectoListoParaEquipos($db, $proyecto_id)['listo']) {
            header('Location: produccion.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id); exit();
        }
        $chkS = $db->prepare("SELECT id FROM series_lotes WHERE id=? AND proyecto_id=? AND estado IN ('Fabricado','En Uso')");
        $chkS->execute([$serie_id, $proyecto_id]);
        $chkE = $db->prepare("SELECT id FROM equipos_fabricados WHERE id=? AND proyecto_id=? AND estado != 'completado'");
        $chkE->execute([$equipo_id, $proyecto_id]);
        if ($chkS->fetch() && $chkE->fetch()) {
            try {
                $db->beginTransaction();
                $db->prepare("INSERT INTO equipos_fabricados_componentes (equipo_fabricado_id, serie_componente_id, agregado_por) VALUES (?,?,?)")
                   ->execute([$equipo_id, $serie_id, $_SESSION['user_id']]);
                $db->prepare("UPDATE series_lotes SET estado='Integrado' WHERE id=?")->execute([$serie_id]);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['proy_error'] = 'Esa serie ya fue vinculada a otro equipo.';
            }
        }
        header('Location: produccion.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id); exit();
    }

    elseif ($accion === 'desvincular_componente_equipo') {
        $proyecto_id   = intval($_POST['proyecto_id']);
        $componente_id = intval($_POST['componente_id']);
        $stmt = $db->prepare("SELECT efc.id, efc.serie_componente_id, sl.origen
            FROM equipos_fabricados_componentes efc
            JOIN series_lotes sl ON sl.id = efc.serie_componente_id
            JOIN equipos_fabricados ef ON ef.id = efc.equipo_fabricado_id
            WHERE efc.id = ? AND ef.proyecto_id = ? AND ef.estado != 'completado'");
        $stmt->execute([$componente_id, $proyecto_id]);
        $comp = $stmt->fetch();
        if ($comp) {
            $db->prepare("DELETE FROM equipos_fabricados_componentes WHERE id=?")->execute([$componente_id]);
            $estadoRevert = $comp['origen'] === 'fabricado' ? 'Fabricado' : 'En Uso';
            $db->prepare("UPDATE series_lotes SET estado=? WHERE id=?")->execute([$estadoRevert, $comp['serie_componente_id']]);
        }
        header('Location: produccion.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id); exit();
    }

    elseif ($accion === 'completar_equipo_fabricado') {
        $proyecto_id = intval($_POST['proyecto_id']);
        $equipo_id   = intval($_POST['equipo_id']);
        if (!proyectoListoParaEquipos($db, $proyecto_id)['listo']) {
            header('Location: produccion.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id); exit();
        }
        $stmt = $db->prepare("SELECT numero_serie FROM equipos_fabricados WHERE id=? AND proyecto_id=? AND estado != 'completado'");
        $stmt->execute([$equipo_id, $proyecto_id]);
        $numSerie = $stmt->fetchColumn();
        if ($numSerie) {
            $db->prepare("UPDATE equipos_fabricados SET estado='completado', completado_por=?, fecha_completado=NOW() WHERE id=?")
               ->execute([$_SESSION['user_id'], $equipo_id]);
            notificarRoles(['manufactura','calidad','almacen'], 'equipo_fabricado_completado', "Equipo fabricado: $numSerie",
                "Se completó el equipo con serie $numSerie.", 'almacen2.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id, $_SESSION['user_id']);
        }
        header('Location: produccion.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id); exit();
    }

    elseif ($accion === 'liberar_produccion') {
        // Candado: solo confirma que TODOS los planos del proyecto ya están
        // aprobados y marca el hito. No genera ninguna requisición — los
        // materiales de cada plano se piden individualmente conforme se van
        // aprobando (botón "Generar Requisición" por plano). Esperar a que
        // todos los planos lleguen para pedir materiales de golpe no aplica:
        // los planos se aprueban de forma parcial/escalonada, no todos a la vez.
        $proyecto_id = intval($_POST['proyecto_id']);
        $s_total = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND tipo_lista = 'plano'");
        $s_total->execute([$proyecto_id]);
        $total_planos = (int)$s_total->fetchColumn();
        $s_aprob = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND tipo_lista = 'plano' AND estado_plano = 'aprobado'");
        $s_aprob->execute([$proyecto_id]);
        $aprobados = (int)$s_aprob->fetchColumn();
        if ($total_planos === 0 || $aprobados < $total_planos) {
            $error = "No se puede liberar: solo $aprobados de $total_planos planos están aprobados.";
        } else {
            $db->prepare("UPDATE proyectos SET produccion_liberada = 1 WHERE id = ?")->execute([$proyecto_id]);
            $message = "Producción liberada: los $total_planos planos del proyecto están aprobados.";
        }
    }

    elseif ($accion === 'marcar_problema') {
        $ot_id = intval($_POST['ot_id']);
        $desc  = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar (ver proyecto.php:1005)
        $db->prepare("UPDATE ordenes_trabajo SET en_problema=1, problema_descripcion=? WHERE id=?")->execute([$desc, $ot_id]);
        notificarRoles(['manufactura','compras'], 'ot_problema', "OT con problema", "La OT #$ot_id fue marcada con problema: $desc", "produccion.php?seccion=ver_proceso", $_SESSION['user_id']);
        $message = "OT marcada con problema.";
    }

    elseif ($accion === 'resolver_problema') {
        $ot_id = intval($_POST['ot_id']);
        $db->prepare("UPDATE ordenes_trabajo SET en_problema=0, problema_descripcion=NULL, estado='pendiente', pausado=0, operario_id=NULL WHERE id=?")->execute([$ot_id]);
        $message = "OT liberada de nuevo a Proceso.";
    }

    elseif ($accion === 'generar_requisicion_ot') {
        $ot_id = intval($_POST['ot_id']);
        $notas_ext = sanitize($_POST['notas'] ?? '');
        $stmt_ot = $db->prepare("SELECT ot.titulo, ot.boom_lista_id, bl.pieza_nombre, p.nombre as proy_nombre, p.id as proy_id FROM ordenes_trabajo ot JOIN boom_listas bl ON bl.id=ot.boom_lista_id JOIN proyectos p ON p.id=ot.proyecto_id WHERE ot.id=?");
        $stmt_ot->execute([$ot_id]);
        $ot_data = $stmt_ot->fetch();
        if ($ot_data) {
            try {
                $db->beginTransaction();
                $notas_req = "Requisición externa para OT: {$ot_data['titulo']}. Pieza: {$ot_data['pieza_nombre']}. " . ($notas_ext ? "Notas: $notas_ext" : "");

                // Materiales enviados desde el formulario editable
                $req_descs   = $_POST['req_desc']   ?? [];
                $req_cants   = $_POST['req_cant']   ?? [];
                $req_unids   = $_POST['req_unid']   ?? [];
                $req_matids  = $_POST['req_matid']  ?? [];
                $req_marcas  = $_POST['req_marca']  ?? [];
                $req_modelos = $_POST['req_modelo'] ?? [];
                $req_notas_i = $_POST['req_notas']  ?? [];
                $items_req = [];
                foreach ($req_descs as $idx => $desc) {
                    $desc = trim(sanitize($desc));
                    if (!$desc) continue;
                    $items_req[] = [
                        'material_id'   => intval($req_matids[$idx] ?? 0) ?: null,
                        'descripcion'   => $desc,
                        'cantidad'      => floatval($req_cants[$idx] ?? 1),
                        'unidad_medida' => sanitize($req_unids[$idx] ?? ''),
                        'marca'         => sanitize($req_marcas[$idx]  ?? ''),
                        'modelo'        => sanitize($req_modelos[$idx] ?? ''),
                        'notas'         => sanitize($req_notas_i[$idx] ?? ''),
                    ];
                }

                // ot_id: liga la requisición a la OT que la originó. Una misma OT puede generar
                // varias requisiciones externas a lo largo del tiempo (ej. material nuevo que
                // se descubre necesario después) — ya no se bloquea tras la primera.
                $req = crearRequisicion($db, [
                    'usuario_id'      => $_SESSION['user_id'],
                    'proyecto'        => $ot_data['proy_nombre'],
                    'proyecto_id'     => $ot_data['proy_id'],
                    'area'            => 'Producción',
                    'solicitante'     => $_SESSION['user_name'] ?? '',
                    'notas_generales' => $notas_req,
                    'ot_id'           => $ot_id,
                    'folio_externo'   => true,
                ], $items_req, null); // null: notificación manual abajo (mensaje trae el folio)
                $folio = $req['folio'];
                $req_ext_id = $req['id'];

                $db->prepare("UPDATE ordenes_trabajo SET requisicion_externa_id=? WHERE id=?")->execute([$req_ext_id, $ot_id]);
                $db->commit();
                notificarRoles(['compras'], 'nueva_requisicion', "Req. Externa OT: {$ot_data['titulo']}", "Se generó requisición $folio para trabajo externo.", "compras.php", $_SESSION['user_id']);
                notificarRoles(['almacen'], 'nueva_requisicion', "Req. Externa OT: {$ot_data['titulo']}", "Se generó requisición $folio para trabajo externo.", "almacen2.php", $_SESSION['user_id']);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'folio' => $folio, 'req_id' => $req_ext_id, 'duplicados' => $req['duplicados'] ?? []]);
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit();
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'OT no encontrada']);
        exit();
    }

    elseif ($accion === 'crear_requisicion_area') {
        $resultado = procesarCrearRequisicionArea($db, 'Producción');
        if ($resultado['ok']) { $message = $resultado['mensaje']; } else { $error = $resultado['mensaje']; }
    }

    // ── Asistencia de operadores + reasignación (2026-08-20) ─────────────────
    elseif ($accion === 'marcar_inactivo_asistencia' && (esJefeDe('proceso') || esJefeDe('manufactura') || isAdmin())) {
        $usuario_asis = intval($_POST['usuario_id'] ?? 0);
        if ($usuario_asis) {
            $db->prepare("
                INSERT INTO asistencia_operadores (usuario_id, fecha, estado, fecha_marcado, marcado_por)
                VALUES (?, CURDATE(), 'inactivo', NOW(), ?)
                ON DUPLICATE KEY UPDATE estado = 'inactivo', fecha_marcado = NOW(), marcado_por = VALUES(marcado_por)
            ")->execute([$usuario_asis, $_SESSION['user_id']]);
            $message = "Operador marcado como inactivo hoy.";
        }
    }
    elseif ($accion === 'marcar_activo_asistencia' && (esJefeDe('proceso') || esJefeDe('manufactura') || isAdmin())) {
        $usuario_asis = intval($_POST['usuario_id'] ?? 0);
        if ($usuario_asis) {
            $db->prepare("
                INSERT INTO asistencia_operadores (usuario_id, fecha, estado, fecha_marcado, marcado_por)
                VALUES (?, CURDATE(), 'activo', NOW(), ?)
                ON DUPLICATE KEY UPDATE estado = 'activo', fecha_marcado = NOW(), marcado_por = VALUES(marcado_por)
            ")->execute([$usuario_asis, $_SESSION['user_id']]);
            $message = "Operador marcado como activo hoy.";
        }
    }
    elseif ($accion === 'reasignar_operador_ot' && (esJefeDe('proceso') || esJefeDe('manufactura') || isAdmin())) {
        $ot_reasignar = intval($_POST['ot_id'] ?? 0);
        $nuevo_operador = sanitize($_POST['nuevo_operador'] ?? '');
        if ($ot_reasignar && $nuevo_operador !== '') {
            $db->prepare("UPDATE ordenes_trabajo SET operador_nombre = ? WHERE id = ?")->execute([$nuevo_operador, $ot_reasignar]);
            $message = "OT reasignada a $nuevo_operador.";
        } else {
            $error = "Falta el nuevo operador.";
        }
    }
    elseif ($accion === 'agregar_maquina') {
        $maq_nombre = sanitize($_POST['nombre'] ?? '');
        $maq_tag    = sanitize($_POST['tag'] ?? '');
        $maq_modelo = sanitize($_POST['modelo'] ?? '');
        $maq_marca  = sanitize($_POST['marca'] ?? '');
        $maq_procesos = array_values(array_filter(array_map('trim', explode("\n", $_POST['procesos'] ?? ''))));
        if ($maq_nombre === '') {
            $error = "Falta el nombre de la máquina.";
        } else {
            $maq_clave = 'maq_' . preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($maq_nombre)) . '_' . time();
            $maq_orden = (int)$db->query("SELECT COALESCE(MAX(orden),0)+1 FROM maquinas")->fetchColumn();
            $db->prepare("INSERT INTO maquinas (clave, nombre, tag, modelo, marca, procesos, orden) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$maq_clave, $maq_nombre, $maq_tag, $maq_modelo, $maq_marca, json_encode($maq_procesos, JSON_UNESCAPED_UNICODE), $maq_orden]);
            $message = "Máquina \"$maq_nombre\" agregada.";
        }
    }

    if (empty($error)) {
        if (isset($req_id) && $req_id) {
            header("Location: ver_requisicion.php?id={$req_id}&from=manufactura"); exit();
        }
        $qs = http_build_query(array_filter(['seccion' => $seccion, 'ot_id' => $_GET['ot_id'] ?? null, 'open_sub' => $_POST['boom_id'] ?? null]));
        // tab=mis: tras generar una requisición, abre directo en "Mis Requisiciones"
        // para que el mensaje de éxito (que vive en esa pestaña) se vea de una vez.
        $tab_extra = $accion === 'crear_requisicion_area' ? '&tab=mis' : '';
        header("Location: produccion.php?seccion=$seccion$tab_extra&msg=" . urlencode($message)); exit();
    }
}

if (!empty($_GET['msg'])) $message = htmlspecialchars($_GET['msg']);
$open_sub = intval($_GET['open_sub'] ?? 0);
$focus_ot = intval($_GET['ot_id'] ?? 0);

// ── Visor plano IA ──────────────────────────────────────────────────────────
$plano_ia = null;
if ($seccion === 'procesar_plano') {
    $bid = intval($_GET['boom_id'] ?? 0);
    $stmt = $db->prepare("SELECT bl.*, p.nombre as proyecto_nombre FROM boom_listas bl LEFT JOIN proyectos p ON p.id=bl.proyecto_id WHERE bl.id=?");
    $stmt->execute([$bid]);
    $plano_ia = $stmt->fetch();

    // BOMs ya extraídos por IA para este plano
    $boms_ia = $db->prepare("SELECT bl2.*, COUNT(bi.id) as total_items FROM boom_listas bl2 LEFT JOIN boom_items bi ON bi.boom_id=bl2.id WHERE bl2.padre_boom_id=? AND bl2.tipo_lista='plano_ia' GROUP BY bl2.id ORDER BY bl2.hoja_plano")->execute([$bid]) ? [] : [];
    $stmt_boms = $db->prepare("SELECT bl2.*, COUNT(bi.id) as total_items FROM boom_listas bl2 LEFT JOIN boom_items bi ON bi.boom_id=bl2.id WHERE bl2.padre_boom_id=? AND bl2.tipo_lista='plano_ia' GROUP BY bl2.id ORDER BY bl2.hoja_plano");
    $stmt_boms->execute([$bid]);
    $boms_ia = $stmt_boms->fetchAll();
}

// ── Datos por sección ───────────────────────────────────────────────────────
$subproyectos = [];
if ($seccion === 'subproyectos') {
    $stmt = $db->query("SELECT bl.id, bl.proyecto_id, bl.pieza_nombre as nombre, bl.observacion_fab as observacion,
                               bl.plano_ruta, bl.finalizado, bl.fecha_creacion, p.nombre as proyecto_nombre,
                               bl.ia_procesado, bl.estado_plano, bl.requisicion_individual_generada,
                               p.estado as proyecto_estado, p.produccion_liberada,
                               (SELECT COUNT(*) FROM boom_listas bl2
                                JOIN boom_items bi2 ON bi2.boom_id = bl2.id
                                WHERE bl2.padre_boom_id = bl.id AND bl2.tipo_lista = 'plano_ia') AS total_items
                        FROM boom_listas bl
                        JOIN proyectos p ON p.id = bl.proyecto_id
                        WHERE bl.tipo_lista = 'plano'
                        ORDER BY bl.fecha_creacion DESC");
    $subproyectos = $stmt->fetchAll();
}

// Planos disponibles para crear OT
$planos_disponibles = $db->query("SELECT bl.id, bl.pieza_nombre, bl.proyecto_id, p.nombre as proyecto_nombre FROM boom_listas bl JOIN proyectos p ON p.id = bl.proyecto_id WHERE bl.tipo_lista='plano' ORDER BY bl.fecha_creacion DESC")->fetchAll();

// ── Datos para generar_ot ─────────────────────────────────────────────────────
$genot_proyectos = [];
$genot_plano_sel = null;
$genot_ots_plano = [];
$genot_paginas   = [];
if ($seccion === 'generar_ot') {
    // Proyectos con sus planos y conteo de OTs
    $stmt_gp = $db->query("
        SELECT p.id, p.nombre, p.estado,
               COUNT(DISTINCT bl.id) AS total_planos,
               COUNT(DISTINCT ot.id) AS total_ots,
               SUM(CASE WHEN ot.estado='pendiente' THEN 1 ELSE 0 END) AS ots_pend,
               SUM(CASE WHEN ot.estado='pendiente' AND ot.es_prioritaria=1 THEN 1 ELSE 0 END) AS ots_prio_pend
        FROM proyectos p
        JOIN boom_listas bl ON bl.proyecto_id = p.id AND bl.tipo_lista = 'plano'
        LEFT JOIN ordenes_trabajo ot ON ot.boom_lista_id = bl.id
        GROUP BY p.id, p.nombre, p.estado
        ORDER BY p.nombre
    ");
    $proyectos_raw = $stmt_gp->fetchAll();

    // Los planos de cada proyecto (con conteo de OTs) corrían con 1 query POR proyecto
    // dentro de este loop (N+1) -- se traen aquí de una sola vez con proyecto_id en el
    // SELECT/GROUP BY y se agrupan en PHP.
    $planos_por_proy_got = [];
    $proy_ids_got = array_column($proyectos_raw, 'id');
    if ($proy_ids_got) {
        $phGot = implode(',', array_fill(0, count($proy_ids_got), '?'));
        $stmt_pl_all = $db->prepare("
            SELECT bl.proyecto_id, bl.id, bl.pieza_nombre, bl.plano_ruta, bl.estado_plano, bl.ia_procesado,
                   COUNT(ot.id) AS total_ots,
                   SUM(CASE WHEN ot.estado='completado' THEN 1 ELSE 0 END) AS ots_completadas,
                   SUM(CASE WHEN ot.es_prioritaria=1 THEN 1 ELSE 0 END) AS ots_prio,
                   SUM(CASE WHEN ot.ots_liberadas=1 THEN 1 ELSE 0 END) AS ots_lib
            FROM boom_listas bl
            LEFT JOIN ordenes_trabajo ot ON ot.boom_lista_id = bl.id
            WHERE bl.proyecto_id IN ($phGot) AND bl.tipo_lista = 'plano'
            GROUP BY bl.proyecto_id, bl.id, bl.pieza_nombre, bl.plano_ruta, bl.estado_plano, bl.ia_procesado
            ORDER BY bl.proyecto_id, bl.fecha_creacion ASC
        ");
        $stmt_pl_all->execute($proy_ids_got);
        foreach ($stmt_pl_all->fetchAll() as $row) {
            $planos_por_proy_got[(int)$row['proyecto_id']][] = $row;
        }
    }

    foreach ($proyectos_raw as $pr) {
        $pr['planos'] = $planos_por_proy_got[$pr['id']] ?? [];
        $genot_proyectos[] = $pr;
    }

    // Carga actual por máquina -- para que al elegir máquina en "Nueva OT" se vea
    // qué tan ocupada está cada una antes de apilarle más trabajo (pedido 2026-08-20).
    // ot.maquina guarda el NOMBRE tal cual sale del catálogo MAQUINARIA del JS de abajo.
    $genot_carga_maquinas = [];
    $stmt_carga_maq = $db->query("
        SELECT maquina,
               SUM(CASE WHEN estado='en_proceso' AND pausado=0 THEN 1 ELSE 0 END) AS en_proceso,
               SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes
        FROM ordenes_trabajo
        WHERE maquina IS NOT NULL AND maquina <> ''
        GROUP BY maquina
    ");
    foreach ($stmt_carga_maq->fetchAll() as $row) {
        $genot_carga_maquinas[$row['maquina']] = ['en_proceso' => (int)$row['en_proceso'], 'pendientes' => (int)$row['pendientes']];
    }

    // Si se seleccionó un plano (split view)
    $genot_boom_id = intval($_GET['boom_id'] ?? 0);
    if ($genot_boom_id) {
        $stmt_gplano = $db->prepare("
            SELECT bl.*, p.nombre AS proyecto_nombre
            FROM boom_listas bl
            JOIN proyectos p ON p.id = bl.proyecto_id
            WHERE bl.id = ? AND bl.tipo_lista = 'plano'
        ");
        $stmt_gplano->execute([$genot_boom_id]);
        $genot_plano_sel = $stmt_gplano->fetch();

        if ($genot_plano_sel) {
            // Filtrar por boom_lista_id Y por proyecto_id para evitar contaminación cruzada
            $stmt_gots = $db->prepare("
                SELECT ot.*, u.nombre_completo AS creado_por_nombre
                FROM ordenes_trabajo ot
                LEFT JOIN usuarios u ON u.id = ot.creado_por
                WHERE ot.boom_lista_id = ? AND ot.proyecto_id = ?
                ORDER BY ot.es_prioritaria DESC, ot.id ASC
            ");
            $stmt_gots->execute([$genot_boom_id, $genot_plano_sel['proyecto_id']]);
            $genot_ots_plano = $stmt_gots->fetchAll();
            // Cargar páginas del plano (para renderizado inicial sin AJAX extra)
            $stmt_gpags = $db->prepare("SELECT id, numero_pagina, nombre, ignorada, numero_serie FROM plano_paginas WHERE boom_lista_id=? ORDER BY numero_pagina");
            $stmt_gpags->execute([$genot_boom_id]);
            $genot_paginas = $stmt_gpags->fetchAll();
        }
    }
}

// Ver Proceso
$proceso_proyectos = [];
$proceso_ots       = [];
$proceso_proy_sel  = intval($_GET['proy_id'] ?? 0);
if ($seccion === 'ver_proceso') {
    $proceso_proyectos = $db->query("SELECT DISTINCT p.id, p.nombre FROM proyectos p JOIN ordenes_trabajo ot ON ot.proyecto_id=p.id ORDER BY p.nombre")->fetchAll();
    if ($proceso_proy_sel) {
        $stmt_po = $db->prepare("
            SELECT ot.*, bl.pieza_nombre, u.nombre_completo AS operario_nombre,
                   uvp.nombre_completo AS vb_prod_nombre, uvc.nombre_completo AS vb_cal_nombre,
                   pf.estado AS fat_estado, pf.entregado AS fat_entregado
            FROM ordenes_trabajo ot
            JOIN boom_listas bl ON bl.id = ot.boom_lista_id
            LEFT JOIN usuarios u   ON u.id   = ot.operario_id
            LEFT JOIN usuarios uvp ON uvp.id = ot.visto_produccion_por
            LEFT JOIN usuarios uvc ON uvc.id = ot.visto_calidad_por
            LEFT JOIN pruebas_fat pf ON pf.orden_trabajo_id = ot.id
            WHERE ot.proyecto_id = ?
            ORDER BY ot.fecha_creacion ASC
        ");
        $stmt_po->execute([$proceso_proy_sel]);
        $proceso_ots = $stmt_po->fetchAll();
    }
}

// Pruebas FAT
$pruebas_fat = [];
if ($seccion === 'aprobar_ots') {
    $stmt = $db->query("SELECT ot.*, p.nombre as proyecto_nombre, bl.pieza_nombre
        FROM ordenes_trabajo ot
        LEFT JOIN proyectos p ON p.id = ot.proyecto_id
        LEFT JOIN boom_listas bl ON bl.id = ot.boom_lista_id
        WHERE ot.estado = 'completado' AND ot.produccion_aprobacion = 'pendiente'
        ORDER BY ot.fecha_creacion DESC");
    $ots_por_aprobar = $stmt->fetchAll();
}
if ($seccion === 'fat') {
    // fp.* = Protocolo FAT (fat_protocolos, el PDF/Word/imagen firmado en papel que sube
    // Ingeniería desde fat_protocolo.php) -- objeto distinto de pruebas_fat (el checklist de
    // Etapa 5). Enlazado por Orden de Producción del proyecto (misma OP más reciente que usa
    // ingenieria.php para armar el link a fat_protocolo.php), NO por pruebas_fat_id -- ese campo
    // solo se fija cuando alguien guarda datos de Etapa 2 en fat_protocolo.php, así que un FAT
    // subido antes de guardar esos datos se quedaba invisible aquí (bug real reportado
    // 2026-09-20: ni Producción ni Calidad veían el documento recién subido). Sin JOINs a
    // usuarios por firma_*_por -- el estado de firmas (2026-08-28: las firmas son en físico, ya
    // no se muestran aquí) se quitó de la UI.
    $stmt = $db->query("SELECT pf.*, p.nombre as proyecto_nombre,
                               ot.titulo as ot_titulo, bl.pieza_nombre as ot_pieza,
                               fp.id as fp_id, fp.orden_produccion_id as fp_orden_produccion_id,
                               fp.archivo_subido_ruta as fp_archivo_ruta, fp.archivo_subido_nombre as fp_archivo_nombre,
                               fp.revision_estado as fp_revision_estado, fp.revision_motivo as fp_revision_motivo, fp.revision_fecha as fp_revision_fecha
                        FROM pruebas_fat pf
                        LEFT JOIN proyectos p ON p.id = pf.proyecto_id
                        LEFT JOIN ordenes_trabajo ot ON ot.id = pf.orden_trabajo_id
                        LEFT JOIN boom_listas bl ON bl.id = ot.boom_lista_id
                        LEFT JOIN fat_protocolos fp ON fp.orden_produccion_id = (
                            SELECT op.id FROM ordenes_produccion op WHERE op.proyecto_id = pf.proyecto_id ORDER BY op.id DESC LIMIT 1
                        )
                        ORDER BY pf.fecha_creacion DESC");
    $pruebas_fat = $stmt->fetchAll();
}

// "Inventario por Proyecto" -- movido de Almacén a Producción (2026-08-05, a
// petición del usuario). almacen_items es una tabla compartida, no exclusiva
// de Almacén, así que la consulta viaja igual.
if ($seccion === 'inventario_proyectos') {
    $proyectos_con_inventario = $db->query("SELECT DISTINCT p.id, p.nombre
        FROM proyectos p
        INNER JOIN almacen_items ai ON ai.proyecto_id = p.id
        ORDER BY p.nombre")->fetchAll();

    $items_por_proyecto_raw = $db->query("SELECT ai.*, m.codigo as material_codigo, m.unidad_medida
        FROM almacen_items ai
        LEFT JOIN materiales m ON m.id = ai.material_id
        ORDER BY ai.proyecto_id, ai.item")->fetchAll();

    $items_por_proyecto = [];
    foreach ($items_por_proyecto_raw as $it) {
        $items_por_proyecto[$it['proyecto_id']][] = $it;
    }
}

$proyectos_lista = $db->query("SELECT id, nombre FROM proyectos WHERE activo=1 ORDER BY nombre")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<link rel="icon" href="favicon.ico?v=3">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Producción — EMPRESA EJEMPLO</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; text-decoration: none !important; }
body { font-family: 'Montserrat', Arial, sans-serif; background: #ffffff; height: 100vh; overflow: hidden; }

/* ── TOP HEADER ── */
.top-header {
    position: fixed; top: 0; left: 0; width: 100%; height: 80px;
    background: #2E5CAE;
    box-shadow: 0 2px 10px rgba(46,92,174,0.25);
    border-bottom: 2px solid #c9a227;
    z-index: 1000; display: flex; align-items: center;
    justify-content: space-between; padding: 0 30px;
}
/* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
.header-logo { height: 66px; width: auto; object-fit: contain; }
.header-right { display: flex; align-items: center; gap: 20px; }
.back-button-header {
    background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);
    border-radius: 40px; padding: 8px 20px; font-weight: 600; color: white;
    display: flex; align-items: center; gap: 8px; transition: all 0.2s; font-size: 14px; cursor: pointer;
}
.back-button-header:hover { background: rgba(255,255,255,0.25); transform: translateX(-5px); }
.empty-chat { text-align: center; padding: 40px 20px; color: #999; }
.empty-chat i { font-size: 48px; margin-bottom: 15px; color: #ddd; }
.notification-item { background: #e8f4fc; border-left: 4px solid #FF9800; padding: 12px; margin-bottom: 10px; border-radius: 8px; cursor: pointer; }
.notification-item:hover { background: #e0eef8; transform: translateX(3px); }
.notif-unread { background: #fff8e1 !important; border-left-color: #FF9800 !important; font-weight: 600; }
.notif-unread::after { content: ''; display: inline-block; width: 8px; height: 8px; background: #FF9800; border-radius: 50%; float: right; margin-top: 4px; }
.notification-title { font-weight: 600; font-size: 13px; color: #003d5c; margin-bottom: 5px; }
.notification-message { font-size: 12px; color: #666; }
.notification-time { font-size: 10px; color: #999; margin-top: 5px; }
.user-dropdown { position: relative; display: inline-block; }
.user-trigger {
    display: flex; align-items: center; gap: 12px;
    background: rgba(255,255,255,0.15); padding: 5px 15px 5px 5px;
    border-radius: 40px; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); transition: all 0.2s;
}
.user-trigger:hover { background: rgba(255,255,255,0.25); }
.user-avatar { width: 35px; height: 35px; border-radius: 50%; background: #FF9800; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; color: white; }
.user-info-text { display: flex; flex-direction: column; }
.user-name { font-weight: 600; color: white; font-size: 13px; line-height: 1.2; }
.user-role { font-size: 10px; color: rgba(255,255,255,0.8); text-transform: uppercase; }
.user-dropdown-menu {
    position: absolute; top: calc(100% + 5px); right: 0; width: 200px;
    background: white; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    opacity: 0; visibility: hidden; transform: translateY(-5px);
    transition: all 0.2s; border: 1px solid #e0e0e0; overflow: hidden; z-index: 1000;
}
.user-dropdown:hover .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
.dropdown-item { padding: 12px 20px; display: flex; align-items: center; gap: 10px; color: #333; transition: all 0.2s; font-size: 13px; font-family: 'Montserrat', sans-serif; }
.dropdown-item:hover { background: #f5f5f5; color: #f44336; }
.dropdown-item i { width: 18px; color: #666; }

/* ── APP CONTAINER ── */
.app-container { height: 100vh; width: 100vw; padding-top: 80px; position: relative; }

/* ── DASHBOARD ── */
.main-center { height: calc(100vh - 140px); overflow-y: auto; display: flex; flex-direction: column; justify-content: flex-start; align-items: center; padding: 20px 20px 100px 20px; }
.page-header { text-align: center; margin-bottom: 40px; }
.page-title { font-size: 2.2rem; color: #003d5c; font-weight: 300; margin-bottom: 10px; }
.page-title strong { font-weight: 800; background: linear-gradient(135deg, #003d5c, #0077b3); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 2.4rem; }
.page-subtitle { color: #666; font-size: 1rem; }
.cards-grid { display: flex; gap: 30px; justify-content: center; align-items: center; flex-wrap: wrap; max-width: 1000px; margin: 0 auto; }
.nav-card { width: 170px; cursor: pointer; transition: all 0.3s ease; text-align: center; }
.nav-card:hover { transform: translateY(-8px); }
.nav-card:hover .card-icon { border-color: #FF9800; box-shadow: 0 15px 30px rgba(255,152,0,0.25); }
.nav-card:hover .card-title { color: #FF9800; }
.card-icon { width: 100%; aspect-ratio: 1/1; background: linear-gradient(135deg, #f8f9fa, #ffffff); border-radius: 20px; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; border: 3px solid #e0e0e0; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(0,0,0,0.08); position: relative; overflow: hidden; }
.card-icon::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); transition: left 0.5s ease; }
.nav-card:hover .card-icon::before { left: 100%; }
.card-icon i { font-size: 3.5rem; color: #005686; transition: all 0.3s ease; z-index: 2; }
.nav-card:hover .card-icon i { color: #FF9800; transform: scale(1.1); }
.card-title { font-size: 1.1rem; font-weight: 600; color: #333; transition: color 0.3s ease; margin-bottom: 5px; }
.card-desc { font-size: 0.78rem; color: #888; font-weight: 400; }

/* ── FOOTER ── */
.dashboard-footer { position: fixed; bottom: 0; left: 0; right: 0; display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; border-top: 2px solid #e0e0e0; background: white; height: 60px; z-index: 1000; }
.footer-left { display: flex; gap: 30px; color: #555; font-size: 13px; font-weight: 500; }
.footer-left i { color: #003d5c; width: 16px; margin-right: 5px; }
.footer-right img { height: 28px; }

/* ── SECTION CONTENT ── */
.section-content { height: calc(100vh - 140px); padding: 0 30px 30px; overflow-y: auto; }
.sticky-container { position: sticky; top: 0; background: white; z-index: 90; padding: 20px 0; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
.header-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.header-row h2 { font-size: 1.5rem; color: #003d5c; display: flex; align-items: center; gap: 10px; }

/* ── ALERTS ── */
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: .85rem; }
.alert-success { background: #e8f5e9; color: #2E7D32; border-left: 4px solid #2E7D32; }
.alert-error   { background: #fce4ec; color: #b71c1c; border-left: 4px solid #b71c1c; }
.alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }

/* ── BUTTONS ── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: none; cursor: pointer; font-family: 'Montserrat', sans-serif; font-size: .82rem; font-weight: 600; text-decoration: none; transition: all .2s; }
.btn-primary { background: #005686; color: #fff; } .btn-primary:hover { background: #003d5c; }
.btn-success  { background: #2E7D32; color: #fff; } .btn-success:hover  { background: #1B5E20; }
.btn-danger   { background: #d32f2f; color: #fff; } .btn-danger:hover   { background: #b71c1c; }
.btn-outline  { background: transparent; color: #005686; border: 1.5px solid #005686; } .btn-outline:hover { background: #005686; color: #fff; }
.btn-warning  { background: #FF9800; color: #fff; } .btn-warning:hover  { background: #e65100; }
.btn-purple   { background: #7b1fa2; color: #fff; } .btn-purple:hover   { background: #4a148c; }
.btn-sm { padding: 4px 9px; font-size: .75rem; }

/* ── BADGES ── */
.badge { display: inline-flex; align-items: center; gap: 3px; padding: 3px 9px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
.badge-pendiente   { background: #fff3e0; color: #e65100; }
.badge-en_proceso  { background: #e3f2fd; color: #1565c0; }
.badge-completado  { background: #e8f5e9; color: #2E7D32; }
.badge-cancelado   { background: #f5f5f5; color: #616161; }
.badge-urgente     { background: #fce4ec; color: #b71c1c; }
.badge-alta        { background: #fff8e1; color: #f57f17; }
.badge-normal      { background: #e8eaf6; color: #3949ab; }
.badge-baja        { background: #f3e5f5; color: #7b1fa2; }

/* ── OT CARD ── */
.ot-card { border: 1.5px solid #e0e8f0; border-radius: 12px; margin-bottom: 16px; background: #fafcff; overflow: hidden; }
.ot-card.estado-en_proceso { border-left: 4px solid #1565c0; }
.ot-card.estado-completado { border-left: 4px solid #2E7D32; opacity: .85; }
.ot-card.estado-cancelado  { border-left: 4px solid #9e9e9e; opacity: .7; }
.ot-card.estado-pendiente  { border-left: 4px solid #FF9800; }
.ot-header { padding: 14px 16px; display: flex; align-items: flex-start; gap: 12px; cursor: pointer; transition: background .15s; }
.ot-header:hover { background: #f0f4f8; }
.ot-info { flex: 1; }
.ot-titulo { font-size: .92rem; font-weight: 700; color: #003d5c; margin-bottom: 4px; }
.ot-meta   { font-size: .77rem; color: #666; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
.ot-body   { display: none; padding: 14px 16px; border-top: 1px solid #e8ecf0; }
.ot-body.open { display: block; }

/* ── BARRA DE PROGRESO ── */
.progress-bar-wrap { height: 6px; background: #e8ecf0; border-radius: 3px; margin: 6px 0; }
.progress-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #1565c0, #42a5f5); transition: width .4s; }

/* ── ACTIVIDADES ── */
.act-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
.act-item { background: #f8faff; border: 1px solid #dde4ef; border-radius: 8px; padding: 10px 12px; display: flex; align-items: flex-start; gap: 10px; }
.act-item.estado-completado { opacity: .75; }
.act-icon { font-size: 1rem; margin-top: 2px; flex-shrink: 0; }
.act-details { flex: 1; }
.act-nombre { font-size: .84rem; font-weight: 600; color: #003d5c; }
.act-meta   { font-size: .74rem; color: #888; margin-top: 2px; }
.act-actions { display: flex; gap: 4px; flex-wrap: wrap; }
.estado-btn { font-size: .7rem; padding: 3px 7px; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 600; transition: all .15s; background: transparent; }
.estado-btn.pendiente   { color: #e65100; border-color: #e65100; } .estado-btn.pendiente:hover   { background: #e65100; color: #fff; }
.estado-btn.en_proceso  { color: #1565c0; border-color: #1565c0; } .estado-btn.en_proceso:hover  { background: #1565c0; color: #fff; }
.estado-btn.completado  { color: #2E7D32; border-color: #2E7D32; } .estado-btn.completado:hover  { background: #2E7D32; color: #fff; }
.estado-btn.cancelado   { color: #616161; border-color: #616161; } .estado-btn.cancelado:hover   { background: #616161; color: #fff; }

/* ── MATERIALES PLANO ── */
.mat-table { width: 100%; border-collapse: collapse; font-size: .8rem; margin-bottom: 12px; }
.mat-table th { background: #f0f4f8; color: #003d5c; font-weight: 700; padding: 7px 10px; text-align: left; border-bottom: 2px solid #dde3ea; }
.mat-table td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; }
.stock-ok  { color: #2E7D32; font-weight: 700; }
.stock-low { color: #e65100; font-weight: 700; }
.stock-out { color: #b71c1c; font-weight: 700; }

/* ── FORMS ── */
.form-section { background: #f8faff; border: 1px solid #e0e8f0; border-radius: 10px; padding: 16px; margin-top: 12px; }
.form-section-title { font-size: .85rem; font-weight: 700; color: #003d5c; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
.form-group { margin-bottom: 10px; }
.form-label { display: block; font-size: .78rem; font-weight: 600; color: #555; margin-bottom: 4px; }
.form-input { width: 100%; padding: 7px 10px; border: 1.5px solid #ddd; border-radius: 7px; font-family: 'Montserrat', sans-serif; font-size: .82rem; }
.form-input:focus { outline: none; border-color: #005686; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.form-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 8px; }
.form-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }

/* ── MATERIAL STOCK INDICATOR ── */
.stock-indicator { display: inline-flex; align-items: center; gap: 4px; font-size: .75rem; font-weight: 700; padding: 2px 8px; border-radius: 12px; }
.si-ok  { background: #e8f5e9; color: #2E7D32; }
.si-low { background: #fff3e0; color: #e65100; }
.si-out { background: #fce4ec; color: #b71c1c; }

/* ── REQ TABLE ── */
.req-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.req-table th { background: #f0f4f8; padding: 9px 12px; text-align: left; border-bottom: 2px solid #dde3ea; color: #003d5c; font-weight: 700; }
.req-table td { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; }

/* ── SUB-CARDS ── */
.sub-card { border: 1.5px solid #e0e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; background: #fafcff; }
.sub-card-header { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.sub-card-info { flex: 1; min-width: 0; }
.sub-card-nombre { font-size: 1.05rem; font-weight: 800; color: #003d5c; margin-bottom: 4px; }
.sub-card-title { font-size: .9rem; font-weight: 700; color: #003d5c; }
.sub-card-meta  { font-size: .78rem; color: #777; margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.expand-panel { display: none; margin-top: 14px; border-top: 1px solid #e0e8f0; padding-top: 14px; }
.expand-panel.open { display: block; }
/* Menú desplegable de acciones secundarias */
.plano-menu { position: relative; display: inline-block; }
.plano-menu-btn { background: #fff; border: 1.5px solid #d0dcea; border-radius: 7px; padding: 5px 10px; cursor: pointer; font-size: .82rem; color: #555; line-height: 1; transition: background .15s, border-color .15s; }
.plano-menu-btn:hover { background: #f0f4f8; border-color: #a0b4cc; }
.plano-menu-list { display: none; position: absolute; right: 0; top: calc(100% + 4px); background: #fff; border: 1.5px solid #dde4ec; border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,.12); min-width: 150px; z-index: 100; overflow: hidden; }
.plano-menu.open .plano-menu-list { display: block; }
.plano-menu-list a { display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: .8rem; color: #333; text-decoration: none; transition: background .1s; }
.plano-menu-list a:hover { background: #f4f7fb; }
.plano-menu-list a i { width: 14px; color: #666; }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 60px 20px; color: #aaa; }
.empty-state i { font-size: 3rem; display: block; margin-bottom: 12px; color: #ccc; }

/* ── MODAL ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: white; border-radius: 14px; padding: 24px; width: 90%; max-width: 540px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); }
.modal-title { font-size: 1.1rem; font-weight: 700; color: #003d5c; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }

/* ── Modales "prd-" (compartidos entre secciones, ej. Comparar con BOM de Ingeniería) --
   global aquí a propósito: estaba duplicado dentro de los <style> de las secciones
   procesar_plano y ver_proceso nada más, así que en cualquier otra sección (dashboard,
   generar_ot, subproyectos, etc.) el modal no tenía su display:none y se veía como una
   barra sin estilo metida en medio del contenido (2026-09-08). ── */
.prd-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9900; align-items:center; justify-content:center; }
.prd-modal-overlay.open { display:flex; }
.prd-modal-box { background:#fff; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,.28); overflow:hidden; max-width:96vw; }
.prd-modal-head { color:#fff; padding:15px 20px; display:flex; align-items:center; justify-content:space-between; }
.prd-modal-head h3 { margin:0; font-size:.95rem; font-weight:700; }
.prd-modal-head > button { background:none; border:none; color:#fff; font-size:1.3rem; cursor:pointer; line-height:1; opacity:.8; }
.prd-modal-head > button:hover { opacity:1; }
.prd-modal-foot { padding:13px 20px; border-top:1px solid #f0f0f0; display:flex; justify-content:flex-end; gap:10px; }

/* ── Inventario por Proyecto (movido de Almacén, 2026-08-05) ── */
.inventario-layout { display: flex; height: calc(100vh - 140px); overflow: hidden; }
.carpetas-panel { width: 220px; min-width: 220px; border-right: 1px solid #e0e0e0; background: #f8f9fa; display: flex; flex-direction: column; overflow: hidden; }
.carpetas-header { padding: 12px 15px; background: #003d5c; color: white; font-size: 13px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
.carpetas-list { flex: 1; overflow-y: auto; padding: 8px 0; }
.carpeta-item { padding: 8px 15px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 13px; color: #333; border-left: 3px solid transparent; transition: all 0.15s; }
.carpeta-item:hover { background: #e9ecef; }
.carpeta-item.active { background: #e3f2fd; border-left-color: #003d5c; color: #003d5c; font-weight: 600; }
.carpeta-item i { color: #FF9800; width: 16px; }
.carpetas-inventario-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 2px solid #333; }
.info-table td, .info-table th { border: 1px solid #333; padding: 8px 12px; font-size: 14px; }
.info-table td:first-child { background: #f0f0f0; font-weight: normal; width: 30%; }
</style>
<link rel="stylesheet" href="css/responsive.css">
</head>
<body>
<?php renderAvisoDuplicado(); ?>
<!-- ── TOP HEADER ── -->
<div class="top-header">
    <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
    <div class="header-right">
        <?php if ($seccion !== ''): ?>
        <a href="produccion.php" class="back-button-header"><i class="fas fa-arrow-left"></i> Volver al Menú</a>
        <?php endif; ?>

        <div style="position:relative;">
            <div id="notifBellBtn" onclick="toggleNotifHeaderDropdown()" style="position:relative;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer;flex-shrink:0;transition:background .2s;" onmouseenter="this.style.background='rgba(255,255,255,.28)'" onmouseleave="this.style.background='rgba(255,255,255,.15)'">
                <i class="fas fa-bell"></i>
                <span id="notifBellBadge" style="position:absolute;top:-4px;right:-4px;background:#e53935;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:.7rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #005686;">0</span>
            </div>
            <div id="notifHeaderDropdown" style="display:none;position:absolute;top:52px;right:0;z-index:9999;width:380px;max-height:70vh;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.3);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #eee;">
                    <span style="font-size:12px;font-weight:700;color:#005686;"><i class="fas fa-industry"></i> Notificaciones de Producción</span>
                    <div style="display:flex;gap:6px;">
                        <button onclick="marcarTodasLeidasProduccion()" class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                            <i class="fas fa-check-double"></i> Marcar leído
                        </button>
                        <button onclick="borrarNotificacionesProduccion()" class="btn btn-sm" style="background:#ffebee;color:#c62828;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                            <i class="fas fa-trash"></i> Borrar
                        </button>
                    </div>
                </div>
                <div id="notifHeaderList" style="padding:6px;">
                    <div class="empty-chat">
                        <i class="fas fa-bell-slash"></i>
                        <p>No hay notificaciones</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="user-dropdown">
            <div class="user-trigger">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
                <div class="user-info-text">
                    <div class="user-name"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                    <div class="user-role"><?= htmlspecialchars($_SESSION['user_type']) ?></div>
                </div>
            </div>
            <div class="user-dropdown-menu">
                <?php if ($mostrar_boton_menu): ?>
                <a href="Menu.php" class="dropdown-item" style="color:#4CAF50;"><i class="fas fa-arrow-left"></i> Volver al Menú de Perfiles</a>
                <div style="height:1px;background:#e0e0e0;margin:5px 0;"></div>
                <?php endif; ?>
                <a href="?logout=1" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
            </div>
        </div>
    </div>
</div>

<div class="app-container">

<?php if ($seccion === ''):
    // Capacidad general de planta (pedido 2026-08-20) -- snapshot rápido de qué tan
    // cargado está el taller completo, sin entrar a ninguna sección. La capacidad
    // POR máquina (detalle) vive en "Lista de Actividades" -- ver esa sección.
    $dash_cap = $db->query("
        SELECT
            SUM(CASE WHEN estado='en_proceso' AND pausado=0 THEN 1 ELSE 0 END) AS en_proceso,
            SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS en_cola,
            COUNT(DISTINCT CASE WHEN estado='en_proceso' AND pausado=0 AND maquina IS NOT NULL AND maquina<>'' THEN maquina END) AS maquinas_activas
        FROM ordenes_trabajo
    ")->fetch();
?>
<!-- ── DASHBOARD ── -->
<div class="main-center">
    <div class="page-header">
        <h1 class="page-title"><strong>Producción</strong><br>Panel de Control</h1>
        <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;max-width:900px;margin:0 auto 28px;">
        <div style="background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 2px 4px rgba(0,0,0,.08);display:flex;align-items:center;gap:12px;">
            <div style="width:42px;height:42px;border-radius:8px;background:#fff3e0;color:#FF9800;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-industry"></i></div>
            <div><div style="font-size:22px;font-weight:800;color:#333;"><?= (int)($dash_cap['en_proceso'] ?? 0) ?></div><div style="font-size:11px;color:#777;text-transform:uppercase;letter-spacing:.3px;">OTs en proceso</div></div>
        </div>
        <div style="background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 2px 4px rgba(0,0,0,.08);display:flex;align-items:center;gap:12px;">
            <div style="width:42px;height:42px;border-radius:8px;background:#e3f2fd;color:#2196F3;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-layer-group"></i></div>
            <div><div style="font-size:22px;font-weight:800;color:#333;"><?= (int)($dash_cap['en_cola'] ?? 0) ?></div><div style="font-size:11px;color:#777;text-transform:uppercase;letter-spacing:.3px;">OTs en cola</div></div>
        </div>
        <div style="background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 2px 4px rgba(0,0,0,.08);display:flex;align-items:center;gap:12px;">
            <div style="width:42px;height:42px;border-radius:8px;background:#e8f5e9;color:#4CAF50;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-bolt"></i></div>
            <div><div style="font-size:22px;font-weight:800;color:#333;"><?= (int)($dash_cap['maquinas_activas'] ?? 0) ?></div><div style="font-size:11px;color:#777;text-transform:uppercase;letter-spacing:.3px;">Máquinas activas ahora</div></div>
        </div>
    </div>
    <div class="cards-grid">
        <div class="nav-card" onclick="location.href='produccion.php?seccion=generar_ot'">
            <div class="card-icon" style="border-color:#005686;"><i class="fas fa-plus-circle" style="color:#005686;"></i></div>
            <div class="card-title">Generar OT</div>
            <div class="card-desc">Asignar por plano y proyecto</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=subproyectos'">
            <div class="card-icon"><i class="fas fa-drafting-compass"></i></div>
            <div class="card-title">Planos</div>
            <div class="card-desc">BOM de manufactura</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=orden_produccion'">
            <div class="card-icon"><i class="fas fa-industry"></i></div>
            <div class="card-title">Orden de Producción</div>
            <div class="card-desc">Órdenes activas</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=fat'">
            <div class="card-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="card-title">Pruebas FAT</div>
            <div class="card-desc">Formato y firmas</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=ver_proceso'">
            <div class="card-icon"><i class="fas fa-stream"></i></div>
            <div class="card-title">Ver Proceso</div>
            <div class="card-desc">Seguimiento de OTs</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=calendario'">
            <div class="card-icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="card-title">Calendario</div>
            <div class="card-desc">Proyectos y fechas</div>
        </div>
        <?php require_once 'includes/indicadores.php'; renderIndicadoresNavCard('produccion.php'); ?>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=notificaciones'">
            <div class="card-icon"><i class="fas fa-bell"></i></div>
            <div class="card-title">Notificaciones</div>
            <div class="card-desc">Auditoría de avisos de proceso</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=actividades_maquinas'">
            <div class="card-icon"><i class="fas fa-cogs"></i></div>
            <div class="card-title">Lista de Actividades</div>
            <div class="card-desc">Historial de uso por máquina, operador, proyecto y proceso</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=asistencia'">
            <div class="card-icon"><i class="fas fa-user-check"></i></div>
            <div class="card-title">Asistencia</div>
            <div class="card-desc">Quién marcó actividad hoy y reasignación de OTs</div>
        </div>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=inventario_proyectos'">
            <div class="card-icon"><i class="fas fa-folder-tree"></i></div>
            <div class="card-title">Inventario por Proyecto</div>
            <div class="card-desc">Materiales asignados a cada proyecto</div>
        </div>
        <?php require_once 'includes/hoja_viajera.php'; renderHojaViajeraNavCard('produccion.php'); ?>
        <?php renderEquiposFabricadosNavCard('produccion.php'); ?>
        <?php renderGenerarRequisicionNavCard('produccion.php'); ?>
        <div class="nav-card" onclick="location.href='produccion.php?seccion=aprobar_ots'">
            <?php if (!empty($ots_por_aprobar)): ?><span class="nav-badge"><?= count($ots_por_aprobar) ?></span><?php endif; ?>
            <div class="card-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="card-title">Aprobar OTs</div>
            <div class="card-desc">Primera aprobación, antes de Calidad</div>
        </div>
    </div>
</div>

<?php elseif ($seccion === 'hoja_viajera'): ?>
<?php require_once 'includes/hoja_viajera.php'; renderHojaViajeraSeccion($db, 'produccion.php'); ?>

<?php elseif ($seccion === 'equipos_fabricados'): ?>
<?php renderEquiposFabricadosSeccion($db, 'produccion.php', 'produccion'); ?>

<?php elseif ($seccion === 'generar_requisicion'): ?>
<?php renderRequisicionesSeccion($db, 'produccion.php', 'accion', 'Producción', 'manufactura', $message); ?>

<?php elseif ($seccion === 'aprobar_ots'): ?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-clipboard-check"></i> Aprobar OTs</h2>
        </div>
    </div>
    <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>
    <?php if (empty($ots_por_aprobar)): ?>
    <div class="empty-state"><i class="fas fa-check-circle" style="color:#2e7d32;"></i><p>No hay OTs pendientes de aprobación.</p></div>
    <?php else: foreach ($ots_por_aprobar as $ot): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #e0e8f0;border-radius:12px;padding:14px 16px;background:#fafcff;margin-bottom:10px;flex-wrap:wrap;">
        <div>
            <div style="font-weight:700;color:#003d5c;"><?= htmlspecialchars($ot['folio'] ?? 'OT-'.$ot['id']) ?> — <?= htmlspecialchars($ot['titulo']) ?></div>
            <div style="font-size:.76rem;color:#666;display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                <?php if ($ot['proyecto_nombre']): ?><span><i class="fas fa-folder"></i> <?= htmlspecialchars($ot['proyecto_nombre']) ?></span><?php endif; ?>
                <?php if ($ot['pieza_nombre']): ?><span><i class="fas fa-drafting-compass"></i> <?= htmlspecialchars($ot['pieza_nombre']) ?></span><?php endif; ?>
                <?php if ($ot['maquina']): ?><span><i class="fas fa-cog"></i> <?= htmlspecialchars($ot['maquina']) ?></span><?php endif; ?>
                <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($ot['fecha_creacion'])) ?></span>
            </div>
        </div>
        <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="accion" value="aprobar_ot_produccion">
            <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
            <input type="text" name="nota" class="form-input" placeholder="Nota (opcional)" style="width:180px;">
            <button type="submit" name="resultado" value="aprobado" class="btn btn-success btn-sm" onclick="return confirm('¿Aprobar esta OT?')"><i class="fas fa-check"></i> Aprobar</button>
            <button type="submit" name="resultado" value="rechazado" class="btn btn-danger btn-sm" onclick="return confirm('¿Rechazar esta OT?')"><i class="fas fa-times"></i> Rechazar</button>
        </form>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php elseif ($seccion === 'indicadores'): ?>
<div class="section-content">
    <?php require_once 'includes/indicadores.php'; renderIndicadoresSeccion($db, 'produccion', 'produccion.php'); ?>
</div>

<?php elseif ($seccion === 'orden_produccion'): ?>
<!-- ── ÓRDENES DE PRODUCCIÓN ── -->
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-industry"></i> Órdenes de Producción Activas</h2>
            <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>
    <?php require_once 'includes/lista_ordenes_produccion.php'; ?>
</div>

<?php elseif ($seccion === 'generar_ot'): ?>
<!-- ── GENERAR ORDEN DE TRABAJO ── -->
<style>
/* ── Proyecto accordion bars ── */
.got-proj-bar {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    background:#fff; border:1.5px solid #e0e8f0; border-radius:12px; padding:16px 20px;
    margin-bottom:8px; cursor:pointer; transition:all .2s; box-shadow:0 1px 4px rgba(0,61,92,.05);
}
.got-proj-bar:hover { border-color:#005686; box-shadow:0 4px 12px rgba(0,61,92,.12); }
.got-proj-bar.open  { border-color:#005686; border-bottom-left-radius:0; border-bottom-right-radius:0; border-bottom:none; }
.got-proj-bar.tiene-prioridad { border-left:5px solid #f44336; background:#fff5f5; }
.got-proj-prio-chip {
    display:inline-flex; align-items:center; gap:5px; background:#f44336; color:#fff;
    font-size:.68rem; font-weight:800; padding:3px 10px; border-radius:20px; margin-left:8px;
}
.got-proj-name { font-size:1rem; font-weight:800; color:#003d5c; }
.got-proj-meta { font-size:.75rem; color:#888; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.got-proj-meta span { display:inline-flex; align-items:center; gap:4px; }
.got-proj-chevron { font-size:.85rem; color:#aaa; transition:transform .2s; }
.got-proj-bar.open .got-proj-chevron { transform:rotate(180deg); }
/* Body del proyecto */
.got-proj-body {
    display:none; background:#f8faff; border:1.5px solid #005686; border-top:none;
    border-bottom-left-radius:12px; border-bottom-right-radius:12px;
    margin-bottom:12px; overflow:hidden;
}
.got-proj-body.open { display:block; }
/* Plano item */
.got-plano-item {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    padding:12px 18px; border-bottom:1px solid #e8ecf0; transition:background .15s;
}
.got-plano-item:last-child { border-bottom:none; }
.got-plano-item:hover { background:#f0f4f8; }
.got-plano-info { flex:1; min-width:0; }
.got-plano-name { font-size:.9rem; font-weight:700; color:#003d5c; margin-bottom:3px; }
.got-plano-meta { font-size:.73rem; color:#888; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.got-plano-btns { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
/* Split view */
.got-split { display:flex; gap:0; height:calc(100vh - 80px); overflow:hidden; }
.got-split-left  { flex:1; background:#e9ecef; display:flex; flex-direction:column; min-width:0; border:1px solid #dde4ec; border-radius:12px; overflow:hidden; margin-right:6px; }
.got-split-right { flex:1; display:flex; flex-direction:column; min-width:0; border:1px solid #dde4ec; border-radius:12px; background:#fff; overflow:hidden; margin-left:6px; }
.got-sp-header { padding:10px 14px; border-bottom:2px solid #e0e8f0; background:#f4f7fb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; gap:8px; }
.got-sp-header h3 { font-size:.88rem; font-weight:700; color:#003d5c; margin:0; }
.got-sp-body { flex:1; overflow-y:auto; padding:14px; }
/* Cola de OTs */
.got-ot-queue { display:flex; flex-direction:column; gap:8px; }
.got-ot-queue-item {
    border:1.5px solid #e0e8f0; border-radius:10px; background:#fafcff;
    padding:10px 14px; display:flex; align-items:center; gap:10px; position:relative;
}
.got-ot-queue-item.es-prio { border-color:#f44336; background:#fff8f8; }
.got-ot-num { width:28px; height:28px; border-radius:50%; background:#005686; color:#fff; font-size:.72rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.got-ot-num.prio { background:#f44336; animation:got-pulse .8s alternate infinite; }
@keyframes got-pulse { from{box-shadow:0 0 0 0 rgba(244,67,54,.4)} to{box-shadow:0 0 0 8px rgba(244,67,54,0)} }
.got-ot-info { flex:1; min-width:0; }
.got-ot-titulo { font-size:.84rem; font-weight:700; color:#003d5c; margin-bottom:2px; }
.got-ot-meta   { font-size:.72rem; color:#888; display:flex; gap:8px; flex-wrap:wrap; }
.got-ot-actions { display:flex; gap:4px; flex-shrink:0; }
.got-ot-estado { font-size:.68rem; font-weight:800; padding:2px 8px; border-radius:10px; white-space:nowrap; }
.got-ot-estado.pendiente  { background:#fff3e0; color:#e65100; }
.got-ot-estado.en_proceso { background:#e3f2fd; color:#1565c0; }
.got-ot-estado.completado { background:#e8f5e9; color:#2e7d32; }
/* Tiempo real invertido (tiempo_regular_min/tiempo_extra_min, llenado por proceso.php) */
.got-tiempo-chip { font-size:.68rem; font-weight:700; padding:2px 8px 2px 6px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; gap:4px; background:linear-gradient(135deg,#e3f2fd,#eef7fc); color:#005686; border:1px solid #d5e9f7; }
.got-tiempo-chip.extra { background:linear-gradient(135deg,#fff3e0,#fff8ec); color:#e65100; border-color:#ffe0b2; }
.got-tiempo-chip i { font-size:.62rem; }
/* Botón prio */
.btn-prio { background:linear-gradient(135deg,#d32f2f,#b71c1c); color:#fff; border:none; border-radius:7px; padding:5px 10px; font-size:.72rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:4px; font-family:'Montserrat',sans-serif; transition:opacity .2s; }
.btn-prio:hover { opacity:.85; }
.btn-prio.active { background:linear-gradient(135deg,#4a148c,#7b1fa2); }
</style>

<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <?php if ($genot_plano_sel): ?>
            <h2><i class="fas fa-plus-circle"></i> Generar OT &mdash; <?= htmlspecialchars($genot_plano_sel['pieza_nombre']) ?></h2>
            <a href="produccion.php?seccion=generar_ot" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver a proyectos</a>
            <?php else: ?>
            <h2><i class="fas fa-plus-circle"></i> Generar Órdenes de Trabajo</h2>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

    <?php if (!$genot_plano_sel): ?>
    <!-- ── Vista principal: barras de proyecto ── -->
    <?php if (empty($genot_proyectos)): ?>
    <div class="empty-state"><i class="fas fa-drafting-compass"></i><p>No hay planos de manufactura.<br><small>Los planos se crean desde Ingeniería.</small></p></div>
    <?php else: ?>
    <?php foreach ($genot_proyectos as $gp):
        $ep_colors = ['borrador'=>'#757575','enviado'=>'#1565c0','en_revision'=>'#e65100','aprobado'=>'#2e7d32','rechazado'=>'#b71c1c'];
    ?>
    <?php $gp_todas_prio = ((int)$gp['ots_pend'] > 0 && (int)$gp['ots_prio_pend'] === (int)$gp['ots_pend']); ?>
    <?php $gp_tiene_prio = ((int)$gp['ots_prio_pend'] > 0); ?>
    <div class="got-proj-bar <?= $gp_tiene_prio ? 'tiene-prioridad' : '' ?>" id="gpbar_<?= $gp['id'] ?>" onclick="toggleGotProyecto(<?= $gp['id'] ?>)">
        <div style="display:flex;flex-direction:column;gap:4px;">
            <div class="got-proj-name">
                <i class="fas fa-folder" style="color:#005686;margin-right:6px;"></i><?= htmlspecialchars($gp['nombre']) ?>
                <?php if ($gp_tiene_prio): ?><span class="got-proj-prio-chip"><i class="fas fa-bolt"></i> <?= (int)$gp['ots_prio_pend'] ?> prioritaria(s)</span><?php endif; ?>
            </div>
            <div class="got-proj-meta">
                <span><i class="fas fa-drafting-compass"></i><?= $gp['total_planos'] ?> plano(s)</span>
                <span><i class="fas fa-tasks"></i><?= $gp['total_ots'] ?> OT(s) total</span>
                <?php if ($gp['estado'] === 'finalizado'): ?><span style="color:#2e7d32;font-weight:700;"><i class="fas fa-check-circle"></i> Ingeniería liberada</span><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="produccion.php?seccion=ver_bom_ingenieria&proyecto_id=<?= $gp['id'] ?>" class="btn btn-outline btn-sm" onclick="event.stopPropagation();" title="Ver los BOMs completos que generó Ingeniería para este proyecto (solo lectura)">
                <i class="fas fa-clipboard-list"></i> Ver BOM
            </a>
            <?php if ((int)$gp['ots_pend'] > 0): ?>
            <button type="button" class="btn-prio <?= $gp_todas_prio ? 'active' : '' ?>"
                    onclick="event.stopPropagation();togglePrioridadProyecto(<?= $gp['id'] ?>, <?= $gp_todas_prio ? 'true' : 'false' ?>)"
                    title="<?= $gp_todas_prio ? 'Quitar prioridad del proyecto' : 'Marcar proyecto como prioritario' ?>">
                <i class="fas fa-bolt"></i>
            </button>
            <?php endif; ?>
            <i class="fas fa-chevron-down got-proj-chevron" id="gpchevron_<?= $gp['id'] ?>"></i>
        </div>
    </div>
    <div class="got-proj-body" id="gpbody_<?= $gp['id'] ?>">
        <?php foreach ($gp['planos'] as $pl):
            $ep = $pl['estado_plano'] ?? 'borrador';
            $ep_col = $ep_colors[$ep] ?? '#757575';
            $ep_labels = ['borrador'=>'Borrador','enviado'=>'Enviado','en_revision'=>'En revisión','aprobado'=>'Aprobado','rechazado'=>'Rechazado'];
        ?>
        <div class="got-plano-item">
            <div class="got-plano-info">
                <div class="got-plano-name"><i class="fas fa-file-alt" style="color:#005686;margin-right:5px;"></i><?= htmlspecialchars($pl['pieza_nombre']) ?></div>
                <div class="got-plano-meta">
                    <span style="color:<?= $ep_col ?>;font-weight:700;"><i class="fas fa-circle" style="font-size:.5rem;"></i> <?= $ep_labels[$ep] ?? $ep ?></span>
                    <span><i class="fas fa-tasks"></i> <?= intval($pl['total_ots']) ?> OT(s)</span>
                    <?php if ($pl['ots_prio'] > 0): ?><span style="color:#f44336;font-weight:700;"><i class="fas fa-bolt"></i> <?= intval($pl['ots_prio']) ?> prioritaria(s)</span><?php endif; ?>
                    <?php if ($pl['ots_lib'] > 0): ?><span style="color:#1565c0;font-weight:700;"><i class="fas fa-paper-plane"></i> Liberada(s) a proceso</span><?php endif; ?>
                </div>
            </div>
            <div class="got-plano-btns">
                <?php if ($pl['plano_ruta']): ?>
                <a href="uploads/<?= htmlspecialchars($pl['plano_ruta']) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver PDF</a>
                <?php endif; ?>
                <a href="produccion.php?seccion=generar_ot&boom_id=<?= $pl['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus-circle"></i> Generar OT</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php else: ?>
    <!-- ── Vista split: PDF zoom+pan + Cola de OTs AJAX ── -->
    <?php
    $pdf_url_got = $genot_plano_sel['plano_ruta'] ? (SITE_URL . '/uploads/' . $genot_plano_sel['plano_ruta']) : '';
    $tiene_liberadas = !empty(array_filter($genot_ots_plano, fn($o) => $o['ots_liberadas']));
    ?>
    <style>
    .got-split { height:calc(100vh - 140px); display:flex; gap:0; }
    .got-split-left, .got-split-right { transition:flex .25s; overflow:hidden; }
    .got-split-left  { flex:1.1; min-width:180px; background:#e9ecef; display:flex; flex-direction:column; border:1px solid #dde4ec; border-radius:12px; margin-right:6px; }
    .got-split-right { flex:1; min-width:260px; display:flex; flex-direction:column; border:1px solid #dde4ec; border-radius:12px; background:#fff; margin-left:6px; }
    .got-split-left.got-expanded  { flex:2.8; }
    .got-split-left.got-collapsed { flex:0 !important; min-width:0; border:none; opacity:0; pointer-events:none; }
    .got-split-right.got-expanded  { flex:2.8; }
    .got-split-right.got-collapsed { flex:0 !important; min-width:0; border:none; opacity:0; pointer-events:none; }
    /* PDF viewport */
    .got-pdf-viewport {
        flex:1; overflow:hidden; cursor:grab; position:relative; background:#e9ecef;
    }
    .got-pdf-viewport:active { cursor:grabbing; }
    .got-pdf-canvas {
        position:absolute; top:0; left:0; transform-origin:0 0;
        display:flex; flex-direction:column; align-items:center; gap:8px; padding:10px;
    }
    /* Zoom controls */
    .got-zoom-bar { display:flex; align-items:center; gap:4px; }
    .got-zoom-btn { background:#fff; border:1px solid #c5d4e0; border-radius:5px; width:26px; height:26px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:.85rem; color:#555; transition:all .15s; }
    .got-zoom-btn:hover { background:#005686; color:#fff; border-color:#005686; }
    .got-zoom-lbl { font-size:.75rem; font-weight:700; color:#555; min-width:38px; text-align:center; }
    .got-panel-btn { background:#fff; border:1px solid #c5d4e0; border-radius:5px; padding:3px 7px; cursor:pointer; font-size:.78rem; color:#555; transition:all .15s; }
    .got-panel-btn:hover { background:#005686; color:#fff; }
    /* Cola OTs */
    .got-ot-queue-item { cursor:grab; }
    .got-ot-queue-item:active { cursor:grabbing; }
    .got-ot-queue-item.drag-over { border:2px dashed #005686; background:#f0f8ff; }
    .got-ot-queue-item.dragging { opacity:.4; }
    /* Lista procesos en OT */
    .got-proc-list { margin-top:6px; border:1px solid #e8edf2; border-radius:6px; overflow:hidden; }
    .got-proc-item { display:flex; align-items:center; gap:6px; padding:5px 10px; font-size:.72rem; border-bottom:1px solid #f0f0f0; }
    .got-proc-item:last-child { border-bottom:none; }
    .got-proc-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .got-proc-item.activo .got-proc-dot { background:#4caf50; }
    .got-proc-item.pendiente .got-proc-dot { background:#ccc; }
    .got-proc-item.completado .got-proc-dot { background:#1565c0; }
    .got-proc-name-txt.completado { text-decoration:line-through; color:#aaa; }
    /* ── Acordeones por página ── */
    .pag-acordeon { border:1.5px solid #dde4ec; border-radius:10px; margin-bottom:8px; overflow:hidden; background:#fff; transition:border-color .2s; }
    .pag-acordeon.activo { border-color:#005686; }
    .pag-header { display:flex; align-items:center; gap:6px; padding:9px 12px; cursor:pointer; background:#f8fafc; user-select:none; }
    .pag-header:hover { background:#eef4fb; }
    .pag-chevron { color:#888; font-size:.75rem; transition:transform .2s; flex-shrink:0; }
    .pag-acordeon.open .pag-chevron { transform:rotate(90deg); }
    .pag-nombre { font-weight:700; font-size:.85rem; color:#1a237e; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pag-num { font-size:.72rem; color:#90a4ae; font-weight:600; flex-shrink:0; }
    .pag-serie { font-size:.7rem; color:#607d8b; font-weight:600; font-family:monospace; flex-shrink:0; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pag-header.tiene-prioridad { border-left:4px solid #f44336; background:#fff5f5; }
    .pag-prio-chip {
        display:inline-flex; align-items:center; gap:4px; background:#f44336; color:#fff;
        font-size:.64rem; font-weight:800; padding:2px 8px; border-radius:20px; flex-shrink:0;
    }
    .pag-btn-edit, .pag-btn-ignore, .pag-btn-prio { background:none; border:none; cursor:pointer; color:#90a4ae; padding:2px 5px; border-radius:4px; font-size:.78rem; transition:color .2s; }
    .pag-btn-edit:hover { color:#005686; }
    .pag-btn-ignore:hover { color:#e53935; }
    .pag-btn-prio:hover { color:#f44336; }
    .pag-btn-prio.active { color:#f44336; }
    .pag-body { padding:8px 10px 10px; display:none; }
    .pag-acordeon.open .pag-body { display:block; }
    .pag-queue { min-height:32px; }
    .pag-nueva-ot-btn { width:100%; margin-top:8px; background:none; border:1.5px dashed #90caf9; border-radius:7px; padding:6px; color:#1565c0; font-size:.8rem; font-weight:600; cursor:pointer; transition:all .2s; }
    .pag-nueva-ot-btn:hover { background:#e3f2fd; border-color:#1565c0; }
    .pag-ignorada-chip { display:inline-flex; align-items:center; gap:5px; background:#fafafa; border:1px solid #e0e0e0; border-radius:20px; padding:3px 10px; font-size:.75rem; color:#888; margin:3px; }
    .pag-ignorada-chip button { background:none; border:none; cursor:pointer; color:#1565c0; font-size:.72rem; font-weight:700; padding:0 2px; }
    .pag-sin-asignar-header { display:flex; align-items:center; gap:6px; padding:7px 10px; cursor:pointer; background:#fff8e1; border-radius:8px; margin-top:8px; font-size:.8rem; color:#795548; font-weight:600; border:1px solid #ffe082; }
    </style>

    <div class="got-split">
        <!-- IZQUIERDA: PDF zoom+pan -->
        <div class="got-split-left" id="gotPanelLeft">
            <div class="got-sp-header">
                <h3><i class="fas fa-file-pdf" style="color:#d32f2f;"></i> <?= htmlspecialchars($genot_plano_sel['pieza_nombre']) ?></h3>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div class="got-zoom-bar">
                        <button class="got-zoom-btn" onclick="zoomGot(-0.15)" title="Alejar">−</button>
                        <span class="got-zoom-lbl" id="gotZoomLbl">100%</span>
                        <button class="got-zoom-btn" onclick="zoomGot(+0.15)" title="Acercar">+</button>
                        <button class="got-zoom-btn" onclick="resetZoomGot()" title="Restablecer"><i class="fas fa-expand-arrows-alt"></i></button>
                    </div>
                    <button class="got-panel-btn" onclick="expandPanel('gotPanelLeft','gotPanelRight')" title="Expandir/Colapsar"><i class="fas fa-expand-alt"></i></button>
                </div>
            </div>
            <div class="got-pdf-viewport" id="gotPdfViewport">
                <div class="got-pdf-canvas" id="gotPdfCanvas">
                    <?php if ($pdf_url_got): ?>
                    <div id="gotPdfPages"></div>
                    <?php else: ?>
                    <div style="padding:40px;text-align:center;color:#aaa;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Sin PDF</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- DERECHA: Cola de OTs -->
        <div class="got-split-right" id="gotPanelRight">
            <div class="got-sp-header">
                <h3><i class="fas fa-tasks"></i> <?= $tiene_liberadas ? 'Modificar OTs' : 'Asignar OTs' ?></h3>
                <div style="display:flex;gap:6px;align-items:center;">
                    <button id="btnLiberarOTs" class="btn btn-success btn-sm" onclick="liberarOTsGot()" <?= empty($genot_ots_plano) ? 'style="display:none;"' : '' ?>><i class="fas fa-paper-plane"></i> <?= $tiene_liberadas ? 'Actualizar OTs' : 'Liberar a Proceso' ?></button>
                    <button class="got-panel-btn" onclick="expandPanel('gotPanelRight','gotPanelLeft')" title="Expandir"><i class="fas fa-expand-alt"></i></button>
                </div>
            </div>
            <div class="got-sp-body" id="gotSpBody" style="overflow-y:auto;padding:10px;">
                <!-- Acordeones por página (renderizados por JS) -->
                <div id="pagAcordeonesWrap"></div>
                <!-- Páginas ignoradas -->
                <div id="pagIgnoradasWrap" style="margin-top:6px;"></div>
                <!-- OTs sin página asignada (legacy) -->
                <div id="pagSinAsignarWrap" style="display:none;">
                    <div class="pag-sin-asignar-header" onclick="toggleSinAsignar()">
                        <i class="fas fa-chevron-right" id="sinAsignarChev" style="font-size:.72rem;transition:transform .2s;"></i>
                        <i class="fas fa-exclamation-triangle"></i>
                        OTs sin página asignada
                        <span id="sinAsignarCount" style="background:#ff8f00;color:#fff;border-radius:10px;padding:1px 7px;font-size:.7rem;margin-left:4px;"></span>
                    </div>
                    <div id="sinAsignarBody" style="display:none;padding:6px 0;">
                        <div class="got-ot-queue" id="pagQueueNull"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Modal: Nueva OT por página ── -->
    <div id="modalFormOTPag" style="display:none;position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.4);justify-content:center;align-items:center;">
        <div style="background:#fff;border-radius:12px;width:min(480px,96vw);box-shadow:0 8px 40px rgba(0,0,0,.25);overflow:hidden;">
            <div style="background:#005686;color:#fff;padding:11px 16px;font-size:.88rem;font-weight:700;display:flex;justify-content:space-between;align-items:center;">
                <span id="modalOTPagTitulo"><i class="fas fa-plus-circle"></i> Nueva OT</span>
                <button onclick="cerrarFormOTPag()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:1.1rem;">&times;</button>
            </div>
            <div style="padding:14px;">
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label"><i class="fas fa-user-hard-hat"></i> Operador *</label>
                    <input type="text" id="got_operador" class="form-input" placeholder="Nombre completo del operador"
                           list="got_operadores_list" autocomplete="off">
                    <datalist id="got_operadores_list">
                        <?php foreach ($usuarios_proceso as $up): ?>
                        <option value="<?= htmlspecialchars($up['nombre_completo']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-grid-2" style="margin-bottom:8px;">
                    <div class="form-group">
                        <label class="form-label">Prioridad</label>
                        <select id="got_prioridad" class="form-input">
                            <option value="baja">🟢 Baja</option>
                            <option value="media" selected>🔵 Media</option>
                            <option value="alta">🟠 Alta</option>
                            <option value="urgente">🔴 Urgente</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Secuencia</label>
                        <input type="number" id="got_secuencia" class="form-input" min="1" placeholder="Auto">
                    </div>
                </div>
                <div class="form-grid-2" style="margin-bottom:8px;">
                    <div class="form-group">
                        <label class="form-label">Maquinaria</label>
                        <select id="got_maq_sel" class="form-input" onchange="gotOnMaquina(this)">
                            <option value="">— Sin asignar —</option>
                            <?php
                            // Mismo catálogo que MAQUINARIA (JS, más abajo) -- el nombre es el
                            // valor real que se guarda en ordenes_trabajo.maquina, por eso la
                            // carga (calculada arriba, $genot_carga_maquinas) se busca por nombre.
                            $genot_maquinas_cat = produccionCatalogoMaquinas();
                            foreach ($genot_maquinas_cat as $maq_val => $maq_datos):
                                $maq_nombre = $maq_datos['nombre'];
                                $maq_carga = $genot_carga_maquinas[$maq_nombre] ?? ['en_proceso' => 0, 'pendientes' => 0];
                                $maq_sufijo = '';
                                if ($maq_carga['en_proceso'] > 0 || $maq_carga['pendientes'] > 0) {
                                    $maq_sufijo = ' — ' . $maq_carga['en_proceso'] . ' en proceso, ' . $maq_carga['pendientes'] . ' en cola';
                                }
                            ?>
                            <option value="<?= $maq_val ?>"><?= htmlspecialchars($maq_nombre . $maq_sufijo) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="got_maquina_val">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha compromiso</label>
                        <input type="date" id="got_fecha" class="form-input">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Proceso</label>
                    <select id="got_proc_sel" class="form-input" onchange="this.style.borderColor=''">
                        <option value="">— Selecciona máquina primero —</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" onclick="cerrarFormOTPag()" class="btn btn-outline btn-sm">Cancelar</button>
                    <button type="button" onclick="crearOTGot()" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Generar OT</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Modal: Confirmar Liberar/Actualizar OTs a Proceso ── -->
    <div class="modal-overlay" id="modalLiberarOTs">
        <div class="modal-box" style="max-width:420px;">
            <div class="modal-title"><i class="fas fa-paper-plane"></i> <span id="modalLiberarOTsTitulo">Liberar OTs a Proceso</span></div>
            <div style="padding:16px;font-size:.88rem;color:#444;" id="modalLiberarOTsMensaje">
                ¿Liberar todas las OTs pendientes a Proceso?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalLiberarOTs')">Cancelar</button>
                <button class="btn btn-success" onclick="confirmarLiberarOTsGot()"><i class="fas fa-check"></i> Confirmar</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const GOT_PDF_URL   = <?= json_encode($pdf_url_got) ?>;
    const GOT_BOOM_ID   = <?= intval($genot_boom_id) ?>;
    const GOT_PROY_ID   = <?= intval($genot_plano_sel['proyecto_id']) ?>;
    const SECCION_URL   = 'produccion.php?seccion=generar_ot&boom_id=' + GOT_BOOM_ID;
    const PLANO_PAGINAS_INIT = <?= json_encode(array_values($genot_paginas), JSON_UNESCAPED_UNICODE) ?>;
    const _gotOTsLegacyInit  = <?= json_encode(array_values($genot_ots_plano), JSON_UNESCAPED_UNICODE) ?>;
    let gotScale = 1.0, gotPanX = 0, gotPanY = 0, gotIsPanning = false, gotPanSX, gotPanSY;

    // ── PDF zoom + pan ────────────────────────────────────────────────────────
    async function cargarGotPDF() {
        if (!GOT_PDF_URL) return;
        try {
            const doc = await pdfjsLib.getDocument(GOT_PDF_URL).promise;
            const wrap = document.getElementById('gotPdfPages');
            wrap.innerHTML = '';
            let maxPageWidth = 0;
            for (let n = 1; n <= doc.numPages; n++) {
                const page = await doc.getPage(n);
                const vp = page.getViewport({scale:1.4});
                const canvas = document.createElement('canvas');
                canvas.width = vp.width; canvas.height = vp.height;
                canvas.style.cssText = 'display:block;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.2);';
                canvas.dataset.page = n;
                wrap.appendChild(canvas);
                await page.render({canvasContext:canvas.getContext('2d'),viewport:vp}).promise;
                if (vp.width > maxPageWidth) maxPageWidth = vp.width;
            }
            await pagInicializar(doc.numPages);
            ajustarZoomInicialGot(maxPageWidth);
        } catch(e) { console.error('PDF', e); }
    }
    function ajustarZoomInicialGot(maxPageWidth) {
        const vpEl = document.getElementById('gotPdfViewport');
        if (!vpEl || !maxPageWidth) return;
        // Encaja el ancho de la página más ancha al viewport (con margen) para que el
        // plano se vea completo por defecto al abrir, en vez de arrancar recortado/muy cerca.
        const margenPx = 20;
        const escalaAjuste = Math.min(1, (vpEl.clientWidth - margenPx) / maxPageWidth);
        gotScale = Math.max(0.2, Math.min(5, escalaAjuste || 1));
        gotPanX = (vpEl.clientWidth - maxPageWidth * gotScale) / 2;
        gotPanY = 10;
        applyGotTransform();
    }
    function zoomGot(d) {
        const nuevaEscala = Math.min(5, Math.max(0.2, gotScale + d));
        const vpEl = document.getElementById('gotPdfViewport');
        if (vpEl && nuevaEscala !== gotScale) {
            // Ancla el zoom al centro del viewport (no a la esquina del contenido completo)
            // para que lo que estás viendo no "salte" al acercar/alejar.
            const sx = vpEl.clientWidth / 2, sy = vpEl.clientHeight / 2;
            const cx = (sx - gotPanX) / gotScale;
            const cy = (sy - gotPanY) / gotScale;
            gotPanX = sx - cx * nuevaEscala;
            gotPanY = sy - cy * nuevaEscala;
        }
        gotScale = nuevaEscala;
        applyGotTransform();
    }
    function resetZoomGot() { gotScale=1; gotPanX=0; gotPanY=0; applyGotTransform(); }
    function applyGotTransform() {
        const c = document.getElementById('gotPdfCanvas');
        c.style.transform = `translate(${gotPanX}px,${gotPanY}px) scale(${gotScale})`;
        document.getElementById('gotZoomLbl').textContent = Math.round(gotScale*100)+'%';
    }
    const vp = document.getElementById('gotPdfViewport');
    if (vp) {
        vp.addEventListener('mousedown', e => { gotIsPanning=true; gotPanSX=e.clientX-gotPanX; gotPanSY=e.clientY-gotPanY; });
        vp.addEventListener('mousemove', e => { if(!gotIsPanning) return; gotPanX=e.clientX-gotPanSX; gotPanY=e.clientY-gotPanSY; applyGotTransform(); });
        vp.addEventListener('mouseup',   () => gotIsPanning=false);
        vp.addEventListener('mouseleave',() => gotIsPanning=false);
        vp.addEventListener('wheel', e => { e.preventDefault(); zoomGot(e.deltaY<0?0.1:-0.1); }, {passive:false});
    }
    window.addEventListener('load', cargarGotPDF);

    // ── Estado acordeones por página ──────────────────────────────────────────
    let _paginas = [];
    let _gotActivePaginaId = null;
    const _pagOTs = {};
    window._gotOTsLegacy = _gotOTsLegacyInit;

    function pagRenderAll() {
        const wrap  = document.getElementById('pagAcordeonesWrap');
        const iwrap = document.getElementById('pagIgnoradasWrap');
        if (!wrap) return;
        const activas   = _paginas.filter(p => !parseInt(p.ignorada));
        const ignoradas = _paginas.filter(p => parseInt(p.ignorada));
        wrap.innerHTML  = activas.map(p => pagHtmlAcordeon(p)).join('');
        iwrap.innerHTML = ignoradas.length
            ? '<div style="font-size:.72rem;color:#90a4ae;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Páginas ignoradas</div>'
              + ignoradas.map(p =>
                  `<span class="pag-ignorada-chip"><i class="fas fa-eye-slash"></i> Pág.${p.numero_pagina} — ${escGot(p.nombre)}<button onclick="ignorarPag(${p.id},0)">Restaurar</button></span>`
                ).join('')
            : '';
        activas.forEach(p => pagRenderOTs(p.id));
        activas.forEach(p => pagInitDrag(p.id));
        pagRenderSinAsignar();
    }

    function pagHtmlAcordeon(p) {
        return `
        <div class="pag-acordeon" id="pagAcc_${p.id}" data-pagina-id="${p.id}" data-numero="${p.numero_pagina}">
            <div class="pag-header" id="pagHeader_${p.id}" onclick="togglePagAcc(${p.id})">
                <i class="fas fa-chevron-right pag-chevron" id="pagChev_${p.id}"></i>
                <span class="pag-nombre" id="pagNombre_${p.id}">${escGot(p.nombre)}</span>
                <span class="pag-prio-chip" id="pagPrioChip_${p.id}" style="display:none;"><i class="fas fa-bolt"></i> <span id="pagPrioCount_${p.id}">0</span> prioritaria(s)</span>
                <span class="pag-num">#${p.numero_pagina}</span>
                <span class="pag-serie" id="pagSerie_${p.id}" title="Número de serie de esta pieza">${p.numero_serie ? escGot(p.numero_serie) : 'Sin serie'}</span>
                <button type="button" class="pag-btn-edit" onclick="event.stopPropagation();editarSeriePag(${p.id})" title="Editar número de serie"><i class="fas fa-barcode"></i></button>
                <button type="button" class="pag-btn-edit" onclick="event.stopPropagation();editarNombrePag(${p.id})" title="Editar nombre"><i class="fas fa-pencil-alt"></i></button>
                <button type="button" class="pag-btn-prio" id="pagPrioBtn_${p.id}" onclick="event.stopPropagation();togglePrioridadPagina(${p.id})" title="Marcar página como prioritaria"><i class="fas fa-bolt"></i></button>
                <button type="button" class="pag-btn-ignore" onclick="event.stopPropagation();ignorarPag(${p.id},1)" title="Ignorar página"><i class="fas fa-eye-slash"></i></button>
            </div>
            <div class="pag-body" id="pagBody_${p.id}">
                <div class="pag-queue got-ot-queue" id="pagQueue_${p.id}"></div>
                <button type="button" class="pag-nueva-ot-btn" onclick="abrirFormOTPag(${p.id})">
                    <i class="fas fa-plus"></i> Nueva OT
                </button>
            </div>
        </div>`;
    }

    // fmtMin() vive en el <script> de la sección ver_proceso -- cada sección del archivo
    // tiene su propio <script> y esta ($seccion=generar_ot) nunca se renderiza junto con
    // esa, así que no comparte su scope. Copia local mínima, mismo formato.
    function _fmtMinGot(m) {
        if (!m) return '0 min';
        if (m < 60) return m + ' min';
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'min';
    }

    function pagRenderOTs(paginaId) {
        const queue = document.getElementById('pagQueue_' + paginaId);
        if (!queue) return;
        const ots = _pagOTs[paginaId] || [];
        const btnPrioPag = document.getElementById('pagPrioBtn_' + paginaId);
        if (btnPrioPag) {
            const pendientes = ots.filter(o => o.estado === 'pendiente');
            const todasPrio  = pendientes.length > 0 && pendientes.every(o => o.es_prioritaria == '1');
            btnPrioPag.classList.toggle('active', todasPrio);
            btnPrioPag.title = todasPrio ? 'Quitar prioridad de la página' : 'Marcar página como prioritaria';
        }
        const headerPag = document.getElementById('pagHeader_' + paginaId);
        const chipPag   = document.getElementById('pagPrioChip_' + paginaId);
        const chipCount = document.getElementById('pagPrioCount_' + paginaId);
        if (headerPag && chipPag && chipCount) {
            const numPrio = ots.filter(o => o.estado === 'pendiente' && o.es_prioritaria == '1').length;
            headerPag.classList.toggle('tiene-prioridad', numPrio > 0);
            chipPag.style.display = numPrio > 0 ? 'inline-flex' : 'none';
            chipCount.textContent = numPrio;
        }
        if (!ots.length) {
            queue.innerHTML = '<div style="text-align:center;padding:14px;color:#bbb;font-size:.78rem;"><i class="fas fa-tasks"></i> Sin OTs asignadas</div>';
            return;
        }
        const estLabels = {pendiente:'Pendiente',en_proceso:'En proceso',completado:'Completado',cancelado:'Cancelado'};
        queue.innerHTML = ots.map((ot, idx) => `
            <div class="got-ot-queue-item ${ot.es_prioritaria=='1'?'es-prio':''}"
                 id="gotItem_${ot.id}" data-ot-id="${ot.id}"
                 draggable="${ot.estado==='pendiente'?'true':'false'}">
                <div class="got-ot-num ${ot.es_prioritaria=='1'?'prio':''}" id="gotNum_${ot.id}">${idx+1}</div>
                <div class="got-ot-info">
                    <div class="got-ot-titulo">
                        ${ot.es_prioritaria=='1'?'<i class="fas fa-bolt" style="color:#f44336;margin-right:4px;"></i>':''}
                        ${escGot(ot.titulo)}
                    </div>
                    <div class="got-ot-meta">
                        ${ot.folio?`<span style="font-family:monospace;font-weight:700;color:#607d8b;font-size:.67rem;">${escGot(ot.folio)}</span>`:''}
                        <span class="got-ot-estado ${ot.estado}">${estLabels[ot.estado]||ot.estado}</span>
                        ${ot.maquina?`<span><i class="fas fa-cog"></i> ${escGot(ot.maquina)}</span>`:''}
                        ${ot.fecha_compromiso?`<span><i class="fas fa-calendar-check"></i> ${ot.fecha_compromiso}</span>`:''}
                        ${(parseInt(ot.tiempo_regular_min)||0) > 0 ? `<span class="got-tiempo-chip"><i class="fas fa-stopwatch"></i> ${_fmtMinGot(parseInt(ot.tiempo_regular_min))}</span>` : ''}
                        ${(parseInt(ot.tiempo_extra_min)||0) > 0 ? `<span class="got-tiempo-chip extra"><i class="fas fa-star"></i> ${_fmtMinGot(parseInt(ot.tiempo_extra_min))}</span>` : ''}
                    </div>
                </div>
                <div class="got-ot-actions">
                    ${ot.es_prioritaria!='1' && ot.estado==='pendiente'
                        ? `<button class="btn-prio" onclick="gotPrioridad(${ot.id},true)" title="Emergencia"><i class="fas fa-bolt"></i></button>`
                        : ot.es_prioritaria=='1'
                            ? `<button class="btn-prio active" onclick="gotPrioridad(${ot.id},false)" title="Quitar"><i class="fas fa-times"></i></button>`
                            : ''}
                    ${ot.estado==='pendiente'
                        ? `<button class="btn btn-danger btn-sm" onclick="gotEliminar(${ot.id})" title="Eliminar"><i class="fas fa-trash"></i></button>`
                        : ''}
                </div>
            </div>`).join('');
    }

    function pagRenderSinAsignar() {
        const sinPagina = (window._gotOTsLegacy || []).filter(o => !o.plano_pagina_id);
        const wrap  = document.getElementById('pagSinAsignarWrap');
        const count = document.getElementById('sinAsignarCount');
        if (!wrap) return;
        if (!sinPagina.length) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'block';
        if (count) count.textContent = sinPagina.length;
        const queue = document.getElementById('pagQueueNull');
        if (!queue) return;
        const estLabels = {pendiente:'Pendiente',en_proceso:'En proceso',completado:'Completado'};
        queue.innerHTML = sinPagina.map(ot => `
            <div class="got-ot-queue-item" id="gotItem_${ot.id}" data-ot-id="${ot.id}" draggable="false">
                <div class="got-ot-info">
                    <div class="got-ot-titulo">${escGot(ot.titulo)}</div>
                    <div class="got-ot-meta">
                        <span class="got-ot-estado ${ot.estado}">${estLabels[ot.estado]||ot.estado}</span>
                    </div>
                </div>
            </div>`).join('');
    }

    async function pagInicializar(totalPaginas) {
        if (PLANO_PAGINAS_INIT && PLANO_PAGINAS_INIT.length > 0) {
            _paginas = PLANO_PAGINAS_INIT;
            const activas = _paginas.filter(p => !parseInt(p.ignorada));
            await Promise.all(activas.map(p => pagCargarOTs(p.id)));
            pagRenderAll();
            return;
        }
        const fd = new FormData();
        fd.append('ajax_got','1'); fd.append('accion','inicializar_paginas');
        fd.append('boom_id', GOT_BOOM_ID); fd.append('total_paginas', totalPaginas);
        try {
            const r = await fetch('produccion.php', {method:'POST',body:fd});
            const d = await r.json();
            if (d.ok) { _paginas = d.paginas; pagRenderAll(); }
        } catch(e) { console.error('Error inicializando páginas:', e); }
    }

    async function pagCargarOTs(paginaId) {
        try {
            const r = await fetch(`produccion.php?accion=get_ots_pagina&pagina_id=${paginaId}`);
            const d = await r.json();
            if (d.ok) _pagOTs[paginaId] = d.ots;
        } catch(e) {}
    }

    // ── Interacciones de acordeones ───────────────────────────────────────────
    function togglePagAcc(paginaId) {
        const acc = document.getElementById('pagAcc_' + paginaId);
        if (!acc) return;
        const isOpen = acc.classList.contains('open');
        document.querySelectorAll('.pag-acordeon.open').forEach(el => el.classList.remove('open','activo'));
        if (!isOpen) {
            acc.classList.add('open', 'activo');
            const pag = _paginas.find(p => p.id == paginaId);
            if (pag) pagScrollPDF(parseInt(pag.numero_pagina));
        }
    }

    function pagScrollPDF(numeroPagina) {
        const canvas = document.querySelector('#gotPdfPages canvas[data-page="' + numeroPagina + '"]');
        if (!canvas) return;
        gotPanY = -canvas.offsetTop * gotScale;
        gotPanX = 0;
        applyGotTransform();
    }

    function toggleSinAsignar() {
        const body = document.getElementById('sinAsignarBody');
        const chev = document.getElementById('sinAsignarChev');
        if (!body) return;
        const open = body.style.display !== 'none';
        body.style.display = open ? 'none' : 'block';
        if (chev) chev.style.transform = open ? 'rotate(0deg)' : 'rotate(90deg)';
    }

    async function editarNombrePag(paginaId) {
        const span = document.getElementById('pagNombre_' + paginaId);
        if (!span || span.querySelector('input')) return;
        const nombreActual = span.textContent.trim();
        const input = document.createElement('input');
        input.type = 'text';
        input.value = nombreActual;
        input.style.cssText = 'font-size:.85rem;font-weight:700;color:#1a237e;border:1px solid #005686;border-radius:4px;padding:2px 6px;width:100%;box-sizing:border-box;';
        span.textContent = '';
        span.appendChild(input);
        input.focus(); input.select();
        const guardar = async () => {
            const nuevoNombre = input.value.trim();
            if (!nuevoNombre) { span.textContent = nombreActual; return; }
            span.textContent = nuevoNombre;
            const pag = _paginas.find(p => p.id == paginaId);
            if (pag) pag.nombre = nuevoNombre;
            const fd = new FormData();
            fd.append('ajax_got','1'); fd.append('accion','renombrar_pagina');
            fd.append('pagina_id', paginaId); fd.append('nombre', nuevoNombre); fd.append('boom_id', GOT_BOOM_ID);
            try { await fetch('produccion.php', {method:'POST',body:fd}); } catch(e) {}
        };
        input.addEventListener('blur', guardar);
        input.addEventListener('keydown', e => { if (e.key==='Enter') { e.preventDefault(); input.blur(); } });
    }

    async function editarSeriePag(paginaId) {
        const span = document.getElementById('pagSerie_' + paginaId);
        if (!span || span.querySelector('input')) return;
        const pag = _paginas.find(p => p.id == paginaId);
        const serieActual = (pag && pag.numero_serie) ? pag.numero_serie : '';
        const input = document.createElement('input');
        input.type = 'text';
        input.value = serieActual;
        input.placeholder = 'N° de serie';
        input.style.cssText = 'font-size:.7rem;font-weight:600;font-family:monospace;color:#607d8b;border:1px solid #005686;border-radius:4px;padding:2px 6px;width:130px;box-sizing:border-box;';
        span.textContent = '';
        span.appendChild(input);
        input.focus(); input.select();
        const guardar = async () => {
            const nuevaSerie = input.value.trim();
            span.textContent = nuevaSerie || 'Sin serie';
            if (pag) pag.numero_serie = nuevaSerie;
            const fd = new FormData();
            fd.append('ajax_got','1'); fd.append('accion','guardar_serie_pagina');
            fd.append('pagina_id', paginaId); fd.append('numero_serie', nuevaSerie); fd.append('boom_id', GOT_BOOM_ID);
            try { await fetch('produccion.php', {method:'POST',body:fd}); } catch(e) {}
        };
        input.addEventListener('blur', guardar);
        input.addEventListener('keydown', e => { if (e.key==='Enter') { e.preventDefault(); input.blur(); } });
    }

    async function ignorarPag(paginaId, valor) {
        const fd = new FormData();
        fd.append('ajax_got','1'); fd.append('accion','ignorar_pagina');
        fd.append('pagina_id', paginaId); fd.append('ignorada', valor); fd.append('boom_id', GOT_BOOM_ID);
        try {
            const r = await fetch('produccion.php', {method:'POST',body:fd});
            const d = await r.json();
            if (d.ok) {
                const pag = _paginas.find(p => p.id == paginaId);
                if (pag) pag.ignorada = valor;
                pagRenderAll();
            }
        } catch(e) {}
    }

    // ── Expandir/colapsar panels ──────────────────────────────────────────────
    function expandPanel(expandId, collapseId) {
        const ep = document.getElementById(expandId);
        const cp = document.getElementById(collapseId);
        if (ep.classList.contains('got-expanded')) {
            ep.classList.remove('got-expanded'); cp.classList.remove('got-collapsed');
        } else {
            ep.classList.add('got-expanded'); cp.classList.add('got-collapsed');
        }
    }

    // ── Drag-and-drop reordenar ───────────────────────────────────────────────
    let gotDragSrc = null;
    function initGotDrag() {
        document.querySelectorAll('.got-ot-queue-item[draggable="true"]').forEach(function(el) {
            el.addEventListener('dragstart', function(e) {
                gotDragSrc = this;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', this.dataset.otId);
            });
            el.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                document.querySelectorAll('.got-ot-queue-item').forEach(i => i.classList.remove('drag-over'));
                actualizarOrdenGot();
            });
            el.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
            el.addEventListener('dragleave',function() { this.classList.remove('drag-over'); });
            el.addEventListener('drop', function(e) {
                e.stopPropagation(); this.classList.remove('drag-over');
                if (gotDragSrc && gotDragSrc !== this) {
                    const q = document.getElementById('gotOTQueue');
                    const items = [...q.querySelectorAll('.got-ot-queue-item')];
                    const si = items.indexOf(gotDragSrc), ti = items.indexOf(this);
                    if (si < ti) this.after(gotDragSrc);
                    else this.before(gotDragSrc);
                    renumerarGot();
                }
            });
        });
    }
    function renumerarGot() {
        document.querySelectorAll('#gotOTQueue .got-ot-queue-item').forEach(function(el, i) {
            const num = document.getElementById('gotNum_' + el.dataset.otId);
            if (num && !el.classList.contains('es-prio')) num.textContent = i+1;
        });
    }
    async function actualizarOrdenGot() {
        const ids = [...document.querySelectorAll('#gotOTQueue .got-ot-queue-item')].map(e => e.dataset.otId);
        const fd = new FormData();
        fd.append('ajax_got','1'); fd.append('accion','reordenar');
        ids.forEach(id => fd.append('orden[]', id));
        await fetch(SECCION_URL, {method:'POST',body:fd}).catch(()=>{});
    }
    initGotDrag();

    // ── AJAX: Crear OT ────────────────────────────────────────────────────────
    function toggleFormGot() {
        cerrarFormOTPag();
    }
    function cerrarFormOTPag() {
        const m = document.getElementById('modalFormOTPag');
        if (m) m.style.display = 'none';
    }
    function abrirFormOTPag(paginaId) {
        _gotActivePaginaId = paginaId;
        const pag = _paginas.find(p => p.id == paginaId);
        const titulo = document.getElementById('modalOTPagTitulo');
        if (titulo && pag) titulo.innerHTML = '<i class="fas fa-plus-circle"></i> Nueva OT — ' + escGot(pag.nombre);
        document.getElementById('got_operador').value  = '';
        document.getElementById('got_prioridad').value = 'media';
        document.getElementById('got_secuencia').value = '';
        document.getElementById('got_maq_sel').value   = '';
        document.getElementById('got_maquina_val').value = '';
        document.getElementById('got_fecha').value     = '';
        document.getElementById('got_proc_sel').innerHTML = '<option value="">— Selecciona máquina primero —</option>';
        const m = document.getElementById('modalFormOTPag');
        if (m) m.style.display = 'flex';
    }
    document.getElementById('modalFormOTPag').addEventListener('click', function(e) {
        if (e.target === this) cerrarFormOTPag();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') cerrarFormOTPag();
    });
    function gotOnMaquina(sel) {
        sel.style.borderColor = '';
        const key = sel.value;
        const maq = (typeof MAQUINARIA !== 'undefined') ? MAQUINARIA[key] : null;
        document.getElementById('got_maquina_val').value = maq ? maq.nombre : '';
        const ps = document.getElementById('got_proc_sel');
        ps.style.borderColor = '';
        if (maq && maq.procesos && maq.procesos.length) {
            ps.innerHTML = '<option value="">— Seleccionar proceso —</option>' +
                maq.procesos.map(p => `<option value="${p}">${p}</option>`).join('');
        } else {
            ps.innerHTML = '<option value="">— Selecciona máquina primero —</option>';
        }
    }

    async function crearOTGot() {
        if (!_gotActivePaginaId) return;
        const operador = document.getElementById('got_operador').value.trim();
        if (!operador) {
            document.getElementById('got_operador').style.borderColor = '#f44336';
            document.getElementById('got_operador').focus();
            return;
        }
        document.getElementById('got_operador').style.borderColor = '';

        const maqSel  = document.getElementById('got_maq_sel');
        const procSel = document.getElementById('got_proc_sel');
        if (!procSel.value) {
            procSel.style.borderColor = '#f44336';
            if (!maqSel.value) {
                maqSel.style.borderColor = '#f44336';
                maqSel.focus();
            } else {
                procSel.focus();
            }
            return;
        }
        maqSel.style.borderColor  = '';
        procSel.style.borderColor = '';

        const btnGen = document.querySelector('#modalFormOTPag .btn-primary');
        if (btnGen) { btnGen.disabled = true; btnGen.textContent = 'Creando…'; }
        const fd = new FormData();
        fd.append('ajax_got','1'); fd.append('accion','crear_orden');
        fd.append('boom_id', GOT_BOOM_ID);
        fd.append('plano_pagina_id', _gotActivePaginaId);
        fd.append('operador_nombre', operador);
        fd.append('prioridad',      document.getElementById('got_prioridad').value);
        fd.append('secuencia_ejecucion', document.getElementById('got_secuencia').value || '0');
        fd.append('maquina',        document.getElementById('got_maquina_val').value);
        fd.append('proceso',        document.getElementById('got_proc_sel').value);
        fd.append('fecha_compromiso', document.getElementById('got_fecha').value);
        try {
            const r = await fetch('produccion.php', {method:'POST',body:fd});
            const d = await r.json();
            if (d.ok && d.ots && d.ots.length) {
                const ot = d.ots[0];
                if (!_pagOTs[_gotActivePaginaId]) _pagOTs[_gotActivePaginaId] = [];
                _pagOTs[_gotActivePaginaId].push(ot);
                pagRenderOTs(_gotActivePaginaId);
                pagInitDrag(_gotActivePaginaId);
                cerrarFormOTPag();
                const btnLib = document.getElementById('btnLiberarOTs');
                if (btnLib) btnLib.style.display = '';
            } else {
                alert('Error: ' + (d.error || 'No se pudo crear la OT.'));
            }
        } catch(e) { alert('Error de red: ' + e.message); }
        finally {
            if (btnGen) { btnGen.disabled = false; btnGen.innerHTML = '<i class="fas fa-check"></i> Generar OT'; }
        }
    }

    function pagInitDrag(paginaId) {
        const queue = document.getElementById('pagQueue_' + paginaId);
        if (!queue) return;
        let dragSrc = null;
        queue.querySelectorAll('.got-ot-queue-item[draggable="true"]').forEach(function(item) {
            item.addEventListener('dragstart', function(e) {
                dragSrc = item;
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            item.addEventListener('dragend', function() {
                item.classList.remove('dragging');
                queue.querySelectorAll('.got-ot-queue-item').forEach(i => i.classList.remove('drag-over'));
                dragSrc = null;
            });
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (item !== dragSrc) item.classList.add('drag-over');
            });
            item.addEventListener('dragleave', function() { item.classList.remove('drag-over'); });
            item.addEventListener('drop', function(e) {
                e.preventDefault();
                item.classList.remove('drag-over');
                if (!dragSrc || dragSrc === item) return;
                const items = [...queue.querySelectorAll('.got-ot-queue-item')];
                const srcIdx = items.indexOf(dragSrc);
                const tgtIdx = items.indexOf(item);
                if (srcIdx < tgtIdx) queue.insertBefore(dragSrc, item.nextSibling);
                else queue.insertBefore(dragSrc, item);
                queue.querySelectorAll('.got-ot-queue-item').forEach(function(el, i) {
                    const numEl = el.querySelector('.got-ot-num');
                    if (numEl) numEl.textContent = i + 1;
                });
                const newOrder = [...queue.querySelectorAll('.got-ot-queue-item')].map(el => el.dataset.otId);
                const fd = new FormData();
                fd.append('ajax_got','1'); fd.append('accion','reordenar_pagina');
                fd.append('pagina_id', paginaId);
                newOrder.forEach(id => fd.append('orden[]', id));
                fetch('produccion.php', {method:'POST',body:fd}).catch(function(){});
            });
        });
    }
    function agregarOTCard(ot) {
        const queue = document.getElementById('gotOTQueue');
        const count = queue.querySelectorAll('.got-ot-queue-item').length + 1;
        const div = document.createElement('div');
        div.className = 'got-ot-queue-item';
        div.id = 'gotItem_' + ot.id;
        div.dataset.otId = ot.id;
        div.setAttribute('draggable','true');
        div.innerHTML = `
            <div class="got-ot-num" id="gotNum_${ot.id}">${ot.secuencia_ejecucion > 0 ? ot.secuencia_ejecucion : count}</div>
            <div class="got-ot-info">
                <div class="got-ot-titulo">${escGot(ot.titulo)}</div>
                <div class="got-ot-meta">
                    <span style="font-family:monospace;font-size:.67rem;color:#607d8b;">${escGot(ot.folio||'')}</span>
                    <span class="got-ot-estado pendiente">Pendiente</span>
                    ${ot.maquina?`<span><i class="fas fa-cog"></i> ${escGot(ot.maquina)}</span>`:''}
                    ${ot.operador_nombre?`<span><i class="fas fa-user-hard-hat"></i> ${escGot(ot.operador_nombre)}</span>`:''}
                </div>
            </div>
            <div class="got-ot-actions">
                <button class="btn-prio" onclick="gotPrioridad(${ot.id},true)" title="Emergencia"><i class="fas fa-bolt"></i></button>
                <button class="btn btn-danger btn-sm" onclick="gotEliminar(${ot.id})" title="Eliminar"><i class="fas fa-trash"></i></button>
            </div>`;
        queue.appendChild(div);
        // Registrar drag
        div.addEventListener('dragstart', function(e){ gotDragSrc=this; this.classList.add('dragging'); e.dataTransfer.setData('text/plain',this.dataset.otId); });
        div.addEventListener('dragend', function(){ this.classList.remove('dragging'); actualizarOrdenGot(); });
        div.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('drag-over'); });
        div.addEventListener('dragleave',function(){ this.classList.remove('drag-over'); });
        div.addEventListener('drop', function(e){
            e.stopPropagation(); this.classList.remove('drag-over');
            if (gotDragSrc && gotDragSrc!==this) {
                const q=document.getElementById('gotOTQueue');
                const items=[...q.querySelectorAll('.got-ot-queue-item')];
                const si=items.indexOf(gotDragSrc),ti=items.indexOf(this);
                if(si<ti) this.after(gotDragSrc); else this.before(gotDragSrc);
                renumerarGot();
            }
        });
    }

    async function gotEliminar(otId) {
        if (!confirm('¿Eliminar esta OT?')) return;
        const fd = new FormData();
        fd.append('ajax_got','1'); fd.append('accion','eliminar_orden'); fd.append('ot_id', otId);
        const r = await fetch(SECCION_URL, {method:'POST',body:fd});
        const d = await r.json();
        if (d.ok) {
            const el = document.getElementById('gotItem_' + otId);
            if (el) el.remove();
            const paginaId = Object.keys(_pagOTs).find(pid =>
                (_pagOTs[pid] || []).some(o => String(o.id) === String(otId)));
            if (paginaId) {
                _pagOTs[paginaId] = _pagOTs[paginaId].filter(o => String(o.id) !== String(otId));
                pagRenderOTs(parseInt(paginaId));
                pagInitDrag(parseInt(paginaId));
            }
        }
    }

    async function gotPrioridad(otId, marcar) {
        const fd = new FormData();
        fd.append('ajax_got','1');
        fd.append('accion', marcar ? 'marcar_prioritaria' : 'quitar_prioritaria');
        fd.append('ot_id', otId); fd.append('boom_id', GOT_BOOM_ID);
        const r = await fetch(SECCION_URL, {method:'POST',body:fd});
        const d = await r.json();
        if (d.ok) {
            const paginaId = Object.keys(_pagOTs).find(pid =>
                (_pagOTs[pid] || []).some(o => String(o.id) === String(otId)));
            if (paginaId) {
                _pagOTs[paginaId].forEach(o => {
                    if (String(o.id) === String(otId)) o.es_prioritaria = marcar ? '1' : '0';
                });
                pagRenderOTs(parseInt(paginaId));
                pagInitDrag(parseInt(paginaId));
            }
        }
    }

    async function togglePrioridadPagina(paginaId) {
        const btn = document.getElementById('pagPrioBtn_' + paginaId);
        const activa = !!(btn && btn.classList.contains('active'));
        const fd = new FormData();
        fd.append('ajax_got','1');
        fd.append('accion', activa ? 'quitar_prioritaria_pagina' : 'marcar_prioritaria_pagina');
        fd.append('pagina_id', paginaId);
        const r = await fetch('produccion.php', {method:'POST', body: fd});
        const d = await r.json();
        if (d.ok) {
            (_pagOTs[paginaId] || []).forEach(o => {
                if (o.estado === 'pendiente') o.es_prioritaria = activa ? '0' : '1';
            });
            pagRenderOTs(paginaId);
            pagInitDrag(paginaId);
        }
    }

    function liberarOTsGot() {
        const btnLib = document.getElementById('btnLiberarOTs');
        const esActualizacion = !!btnLib && btnLib.textContent.includes('Actualizar');
        document.getElementById('modalLiberarOTsTitulo').textContent = esActualizacion ? 'Actualizar OTs en Proceso' : 'Liberar OTs a Proceso';
        document.getElementById('modalLiberarOTsMensaje').textContent = esActualizacion
            ? '¿Actualizar en Proceso las OTs con los cambios que hiciste?'
            : '¿Liberar todas las OTs pendientes a Proceso?';
        openModal('modalLiberarOTs');
    }

    async function confirmarLiberarOTsGot() {
        closeModal('modalLiberarOTs');
        const fd = new FormData();
        fd.append('ajax_got','1'); fd.append('accion','liberar_ots'); fd.append('boom_id', GOT_BOOM_ID);
        const r = await fetch(SECCION_URL, {method:'POST',body:fd});
        const d = await r.json();
        if (d.ok) {
            document.querySelectorAll('.got-ot-estado.pendiente').forEach(e => {
                e.className='got-ot-estado en_proceso'; e.textContent='Liberada';
            });
            // Actualizar texto del botón
            const btnLib = document.getElementById('btnLiberarOTs');
            if (btnLib) { btnLib.innerHTML = '<i class="fas fa-paper-plane"></i> Actualizar OTs'; }
            mostrarToastGot('OTs liberadas a Proceso correctamente.');
        }
    }

    function escGot(s) { const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

    function mostrarToastGot(msg) {
        let t = document.getElementById('gotToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'gotToast';
            t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#2e7d32;color:#fff;padding:12px 20px;border-radius:10px;font-size:.84rem;font-weight:700;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.3);transition:opacity .3s;opacity:0;font-family:\'Montserrat\',sans-serif;';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.opacity = '1';
        setTimeout(() => t.style.opacity = '0', 3000);
    }
    </script>

    <?php endif; ?>
</div>

<script>
function toggleGotProyecto(id) {
    const bar  = document.getElementById('gpbar_' + id);
    const body = document.getElementById('gpbody_' + id);
    const chev = document.getElementById('gpchevron_' + id);
    const open = body.classList.contains('open');
    // Cerrar todos primero
    document.querySelectorAll('.got-proj-bar.open').forEach(b => b.classList.remove('open'));
    document.querySelectorAll('.got-proj-body.open').forEach(b => b.classList.remove('open'));
    if (!open) {
        bar.classList.add('open');
        body.classList.add('open');
    }
}
function toggleFormOT() {
    const w = document.getElementById('formOTWrapper');
    w.style.display = w.style.display === 'none' ? 'block' : 'none';
}
async function togglePrioridadProyecto(proyectoId, activa) {
    const fd = new FormData();
    fd.append('ajax_got','1');
    fd.append('accion', activa ? 'quitar_prioritaria_proyecto' : 'marcar_prioritaria_proyecto');
    fd.append('proyecto_id', proyectoId);
    const r = await fetch('produccion.php', {method:'POST', body: fd});
    const d = await r.json();
    if (d.ok) location.reload();
}
</script>

<?php elseif ($seccion === 'ver_bom_ingenieria'):
    // Solo lectura -- todo lo que conlleva el BOM que Ingeniería armó para este proyecto
    // (principal/adicional), tal cual lo generó, incluida la clasificación de documentos
    // y el tipo de válvula. No incluye 'plano_ia' (eso es la extracción de Producción).
    $vbi_proyecto_id = intval($_GET['proyecto_id'] ?? 0);
    $vbi_proyecto = null; $vbi_boms = [];
    if ($vbi_proyecto_id) {
        $stmt = $db->prepare("SELECT id, nombre FROM proyectos WHERE id = ?");
        $stmt->execute([$vbi_proyecto_id]);
        $vbi_proyecto = $stmt->fetch();

        $stmt = $db->prepare("SELECT id, nombre, tipo_valvula, pieza_nombre, tipo_lista
                               FROM boom_listas
                               WHERE proyecto_id = ? AND tipo_lista IN ('principal','adicional')
                               ORDER BY fecha_creacion ASC");
        $stmt->execute([$vbi_proyecto_id]);
        $vbi_listas = $stmt->fetchAll();

        $stmtItems = $db->prepare("SELECT bi.*, c.nombre AS cat_nombre
                                    FROM boom_items bi
                                    LEFT JOIN materiales m ON m.id = bi.material_id
                                    LEFT JOIN categorias c ON c.id = m.categoria_id
                                    WHERE bi.boom_id = ?
                                    ORDER BY bi.id");
        foreach ($vbi_listas as $vbl) {
            $stmtItems->execute([$vbl['id']]);
            $vbi_boms[] = ['lista' => $vbl, 'items' => bomOrdenarItemsPorCategoriaDoc($stmtItems->fetchAll())];
        }
    }
    $VBI_CATEGORIAS_DOC = bomCategoriasDocMeta();
?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-clipboard-list"></i> BOM de Ingeniería <?= $vbi_proyecto ? '— ' . htmlspecialchars($vbi_proyecto['nombre']) : '' ?></h2>
            <a href="produccion.php?seccion=generar_ot" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>
    <?php if (!$vbi_proyecto): ?>
    <div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Proyecto no encontrado.</p></div>
    <?php elseif (empty($vbi_boms)): ?>
    <div class="empty-state"><i class="fas fa-clipboard-list"></i><p>Ingeniería todavía no ha armado ningún BOM para este proyecto.</p></div>
    <?php else: foreach ($vbi_boms as $vb):
        $vbl = $vb['lista'];
    ?>
    <div style="border:1px solid #e0e8f0;border-radius:10px;margin-bottom:16px;overflow:hidden;background:#fff;">
        <div style="padding:12px 16px;background:#f4f7fb;border-bottom:1px solid #e0e8f0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
            <div>
                <strong style="color:#003d5c;"><i class="fas fa-folder-open"></i> <?= htmlspecialchars($vbl['nombre']) ?></strong>
                <?php if ($vbl['pieza_nombre']): ?><span style="color:#888;font-size:.8rem;"> — <?= htmlspecialchars($vbl['pieza_nombre']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($vbl['tipo_valvula'])): ?>
            <span style="font-size:.78rem;font-weight:700;color:#003d5c;background:#e3f2fd;padding:4px 12px;border-radius:20px;"><i class="fas fa-valve" style="margin-right:4px;"></i>Equipo: <?= htmlspecialchars($vbl['tipo_valvula']) ?></span>
            <?php endif; ?>
        </div>
        <?php if (empty($vb['items'])): ?>
        <div style="padding:20px;text-align:center;color:#999;">Sin items.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
            <thead>
                <tr style="background:#003d5c;color:#fff;">
                    <th style="padding:6px 8px;text-align:left;">#</th>
                    <th style="padding:6px 8px;text-align:left;">Descripción</th>
                    <th style="padding:6px 8px;text-align:left;">Modelo</th>
                    <th style="padding:6px 8px;text-align:left;">Marca</th>
                    <th style="padding:6px 8px;text-align:center;">Cantidad</th>
                    <th style="padding:6px 8px;text-align:left;">Categoría (catálogo)</th>
                    <th style="padding:6px 8px;text-align:left;">Categoría (documento)</th>
                    <th style="padding:6px 8px;text-align:left;">Certificados</th>
                    <th style="padding:6px 8px;text-align:left;">Nota</th>
                </tr>
            </thead>
            <tbody>
                <?php $vbi_n = 1; foreach ($vb['items'] as $vit):
                    $vcd = $vit['categoria_doc'] ?? '';
                    $vcdMeta = $VBI_CATEGORIAS_DOC[$vcd] ?? null;
                ?>
                <tr style="border-bottom:1px solid #eee;<?= $vcdMeta ? 'background:' . $vcdMeta['bg'] . ';' : '' ?>">
                    <td style="padding:6px 8px;font-weight:700;"><?= $vbi_n++ ?></td>
                    <td style="padding:6px 8px;"><?= htmlspecialchars($vit['descripcion'] ?? '') ?></td>
                    <td style="padding:6px 8px;"><?= htmlspecialchars($vit['modelo'] ?? '') ?></td>
                    <td style="padding:6px 8px;"><?= htmlspecialchars($vit['marca'] ?? '') ?></td>
                    <td style="padding:6px 8px;text-align:center;"><?= htmlspecialchars($vit['cantidad']) ?></td>
                    <td style="padding:6px 8px;"><?= htmlspecialchars($vit['cat_nombre'] ?? '') ?></td>
                    <td style="padding:6px 8px;<?= $vcdMeta ? 'color:' . $vcdMeta['color'] . ';font-weight:700;' : '' ?>"><?= $vcdMeta ? htmlspecialchars($vcdMeta['label']) : '—' ?></td>
                    <td style="padding:6px 8px;"><?= htmlspecialchars($vit['certificados'] ?? '') ?></td>
                    <td style="padding:6px 8px;"><?= htmlspecialchars($vit['nota_libre'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php elseif ($seccion === 'subproyectos'): ?>
<!-- ── PLANOS ── -->
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-drafting-compass"></i> Planos de Manufactura</h2>
        </div>
    </div>
    <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

    <?php if (empty($subproyectos)): ?>
    <div style="text-align:center;padding:40px;color:#bbb;">
        <i class="fas fa-drafting-compass" style="font-size:2.5rem;margin-bottom:10px;display:block;"></i>
        No hay planos de manufactura asignados aún.<br>
        <small style="font-size:.8rem;">Los planos se crean desde Ingeniería.</small>
    </div>
    <?php else: ?>
    <?php
    // Agrupar planos por proyecto
    $proyectos_agrupados = [];
    foreach ($subproyectos as $sub) {
        $pid = $sub['proyecto_id'];
        if (!isset($proyectos_agrupados[$pid])) {
            $proyectos_agrupados[$pid] = [
                'nombre'               => $sub['proyecto_nombre'],
                'estado'               => $sub['proyecto_estado'],
                'produccion_liberada'  => $sub['produccion_liberada'],
                'planos'               => []
            ];
        }
        $proyectos_agrupados[$pid]['planos'][] = $sub;
    }
    ?>
    <?php foreach ($proyectos_agrupados as $proy_id => $proy_group):
        $todos_aprobados = count(array_filter($proy_group['planos'], fn($p) => ($p['estado_plano'] ?? '') === 'aprobado')) === count($proy_group['planos']);
        $ya_liberado     = (bool)$proy_group['produccion_liberada'];
        $ing_liberada    = $proy_group['estado'] === 'finalizado';
    ?>
    <!-- Encabezado de proyecto -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin:20px 0 10px;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:1rem;font-weight:800;color:#003d5c;"><?= htmlspecialchars($proy_group['nombre']) ?></span>
            <?php if ($ya_liberado): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;">
                <i class="fas fa-check-circle"></i> Producción liberada<?= $ing_liberada ? ' · BOM enviado' : '' ?>
            </span>
            <?php elseif ($todos_aprobados): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:#fff8e1;color:#f57f17;border:1px solid #ffe082;">
                <i class="fas fa-check"></i> Todos aprobados — listo para liberar
            </span>
            <?php else:
                $n_aprob = count(array_filter($proy_group['planos'], fn($p) => ($p['estado_plano'] ?? '') === 'aprobado'));
                $n_total = count($proy_group['planos']);
            ?>
            <span style="font-size:.75rem;color:#aaa;"><?= $n_aprob ?>/<?= $n_total ?> aprobados</span>
            <?php endif; ?>
        </div>
        <?php if ($todos_aprobados && !$ya_liberado): ?>
        <form method="POST" onsubmit="return confirm('¿Liberar producción para «<?= htmlspecialchars(addslashes($proy_group['nombre'])) ?>»?\n\nEsto solo confirma que todos los planos están aprobados — los materiales de cada plano se piden individualmente con su propio botón \'Generar Requisición\'.');">
            <input type="hidden" name="accion" value="liberar_produccion">
            <input type="hidden" name="proyecto_id" value="<?= $proy_id ?>">
            <button type="submit" class="btn btn-success" style="gap:6px;">
                <i class="fas fa-rocket"></i> Liberar Producción
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php foreach ($proy_group['planos'] as $sub):
        $boom_id_plano = $sub['id'];
        $is_open = $open_sub == $boom_id_plano;
        // Cargar materiales del BOM validado (hijo del plano con tipo_lista='plano_ia')
        $items_stmt = $db->prepare(
            "SELECT bi.*, m.descripcion as mat_nombre, m.codigo, m.stock_disponible, c.nombre as cat_nombre
             FROM boom_items bi
             JOIN boom_listas bl ON bi.boom_id = bl.id
             LEFT JOIN materiales m ON m.id = bi.material_id
             LEFT JOIN categorias c ON c.id = m.categoria_id
             WHERE bl.padre_boom_id = ? AND bl.tipo_lista = 'plano_ia'
             ORDER BY bl.id, bi.id"
        );
        $items_stmt->execute([$boom_id_plano]);
        $plano_items = $items_stmt->fetchAll();

        // Check if OT exists for this plano
        $ot_check = $db->prepare("SELECT id FROM ordenes_trabajo WHERE boom_lista_id = ? LIMIT 1");
        $ot_check->execute([$boom_id_plano]);
        $has_ot = $ot_check->fetchColumn();
    ?>
    <?php
        $ep = $sub['estado_plano'] ?? null;
        $ep_labels = ['borrador'=>['Borrador','#757575','#f5f5f5'],'enviado'=>['Enviado','#1565c0','#e3f2fd'],'en_revision'=>['En revisión','#e65100','#fff3e0'],'aprobado'=>['Aprobado','#2e7d32','#e8f5e9'],'rechazado'=>['Rechazado','#b71c1c','#ffebee']];
        $ep_info = $ep_labels[$ep] ?? null;
    ?>
    <div class="sub-card">
        <div class="sub-card-header">
            <div class="sub-card-info">
                <div class="sub-card-nombre"><?= htmlspecialchars($sub['nombre'] ?? 'Sin nombre') ?></div>
                <div class="sub-card-meta">
                    <span class="badge <?= $sub['finalizado'] ? 'badge-completado' : 'badge-en_proceso' ?>" style="font-size:.68rem;"><?= $sub['finalizado'] ? 'Completado' : 'En proceso' ?></span>
                    <?php if ($ep_info): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:.68rem;font-weight:700;background:<?= $ep_info[2] ?>;color:<?= $ep_info[1] ?>;border:1px solid <?= $ep_info[1] ?>33;"><?= $ep_info[0] ?></span>
                    <?php endif; ?>
                    <span style="color:#bbb;">·</span>
                    <?= intval($sub['total_items']) ?> mat.
                    <?php if ($sub['observacion']): ?><span style="color:#bbb;">·</span><em><?= htmlspecialchars($sub['observacion']) ?></em><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;">
                <?php if ($sub['plano_ruta']): ?>
                <a href="produccion.php?seccion=procesar_plano&boom_id=<?= $boom_id_plano ?>" class="btn btn-sm" style="background:<?= $sub['ia_procesado'] ? '#7b1fa2' : '#005686' ?>;color:#fff;">
                    <i class="fas fa-robot"></i> <?= $sub['ia_procesado'] ? 'BOM IA' : 'Procesar' ?>
                </a>
                <?php endif; ?>
                <!-- Menú secundario -->
                <div class="plano-menu" id="pmenu_<?= $boom_id_plano ?>">
                    <button class="plano-menu-btn" onclick="togglePlanoMenu(<?= $boom_id_plano ?>)" title="Más acciones"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="plano-menu-list">
                        <?php if ($sub['plano_ruta']): ?>
                        <a href="<?= SITE_URL ?>/uploads/<?= htmlspecialchars($sub['plano_ruta']) ?>" target="_blank"><i class="fas fa-file-alt"></i> Ver Plano</a>
                        <?php endif; ?>
                        <a href="<?= SITE_URL ?>/api/exportar_bom.php?boom_id=<?= $boom_id_plano ?>" target="_blank"><i class="fas fa-file-excel"></i> Exportar BOM</a>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="togglePanel(<?= $boom_id_plano ?>)">
                    <i class="fas fa-chevron-down" id="icon_<?= $boom_id_plano ?>"></i>
                    <span id="btn_text_<?= $boom_id_plano ?>"><?= $is_open ? 'Cerrar' : 'Ver materiales' ?></span>
                </button>
            </div>
        </div>
        <div class="expand-panel <?= $is_open ? 'open' : '' ?>" id="panel_<?= $boom_id_plano ?>">
            <?php if (!empty($plano_items)): ?>
            <table class="mat-table">
                <thead><tr><th>#</th><th>Descripción</th><th>Categoría</th><th>Marca</th><th>Cant.</th><th>Stock</th></tr></thead>
                <tbody>
                <?php foreach ($plano_items as $pi => $pitem):
                    $stk = $pitem['stock_disponible'] ?? 0;
                    $req = $pitem['cantidad'];
                    $stk_class = $stk >= $req ? 'stock-ok' : ($stk > 0 ? 'stock-low' : 'stock-out');
                ?>
                <tr>
                    <td><?= $pi + 1 ?></td>
                    <td><?= htmlspecialchars($pitem['descripcion']) ?><?php if ($pitem['mat_nombre']): ?><br><small style="color:#888;"><?= htmlspecialchars($pitem['mat_nombre']) ?></small><?php endif; ?></td>
                    <td><small><?= htmlspecialchars($pitem['cat_nombre'] ?? '—') ?></small></td>
                    <td><?= htmlspecialchars($pitem['marca'] ?? '') ?></td>
                    <td><?= $req ?></td>
                    <td><span class="<?= $stk_class ?>"><?= $stk ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color:#bbb;font-size:.82rem;">Sin materiales en BOM.</p>
            <?php endif; ?>
            <?php if (!empty($plano_items)): ?>
            <div style="margin-top:10px;border-top:1px solid #e0e8f0;padding-top:12px;">
                <?php if (!empty($sub['requisicion_individual_generada'])): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:700;background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;">
                    <i class="fas fa-check-circle"></i> Requisición individual ya generada
                </span>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="accion" value="generar_requisicion_plano">
                    <input type="hidden" name="boom_id" value="<?= $boom_id_plano ?>">
                    <input type="hidden" name="proyecto_id" value="<?= $sub['proyecto_id'] ?>">
                    <input type="hidden" name="redirect_seccion" value="subproyectos">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Generar Requisición</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <!-- Aceptar / Rechazar plano -->
            <div style="margin-top:10px;border-top:1px solid #e0e8f0;padding-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:.78rem;color:#aaa;">Revisión del plano:</span>
                <?php if ($ep !== 'aprobado'): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Aceptar este plano?')">
                    <input type="hidden" name="accion" value="cambiar_estado_plano">
                    <input type="hidden" name="boom_id" value="<?= $boom_id_plano ?>">
                    <input type="hidden" name="nuevo_estado" value="aprobado">
                    <button type="submit" class="btn btn-sm" style="background:#2e7d32;color:#fff;"><i class="fas fa-check"></i> Aceptar</button>
                </form>
                <?php endif; ?>
                <?php if ($ep !== 'rechazado'): ?>
                <button type="button" onclick="toggleRechazarPlano(<?= $boom_id_plano ?>)" class="btn btn-sm" style="background:#c62828;color:#fff;"><i class="fas fa-times"></i> Rechazar</button>
                <?php endif; ?>
                <?php if ($ep === 'aprobado' || $ep === 'rechazado'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="accion" value="cambiar_estado_plano">
                    <input type="hidden" name="boom_id" value="<?= $boom_id_plano ?>">
                    <input type="hidden" name="nuevo_estado" value="en_revision">
                    <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-undo"></i> Regresar a revisión</button>
                </form>
                <?php endif; ?>
            </div>
            <?php if ($ep !== 'rechazado'): ?>
            <div id="rechazar_form_<?= $boom_id_plano ?>" style="display:none;margin-top:8px;border-top:1px solid #ffcdd2;padding-top:10px;">
                <form method="POST">
                    <input type="hidden" name="accion" value="cambiar_estado_plano">
                    <input type="hidden" name="boom_id" value="<?= $boom_id_plano ?>">
                    <input type="hidden" name="nuevo_estado" value="rechazado">
                    <textarea name="nota_rechazo" required placeholder="Motivo del rechazo (requerido)..." style="width:100%;min-height:64px;font-size:.82rem;border:1px solid #e0e0e0;border-radius:6px;padding:7px 10px;font-family:inherit;box-sizing:border-box;margin-bottom:7px;resize:vertical;"></textarea>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-sm" style="background:#c62828;color:#fff;"><i class="fas fa-times"></i> Confirmar rechazo</button>
                        <button type="button" onclick="toggleRechazarPlano(<?= $boom_id_plano ?>)" class="btn btn-outline btn-sm">Cancelar</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; // planos del proyecto ?>
    <?php endforeach; // proyectos_agrupados ?>
    <?php endif; ?>
</div>

<?php elseif ($seccion === 'procesar_plano'): ?>
<!-- ── PROCESAR PLANO CON IA ── -->
<?php if (!$plano_ia): ?>
<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Plano no encontrado.</p></div>
<?php else:
    $pdf_url = $plano_ia['plano_ruta'] ? (SITE_URL . '/uploads/' . $plano_ia['plano_ruta']) : '';
?>
<style>
.split-plano { display:flex; gap:0; height:calc(100vh - 80px); overflow:hidden; }
.split-plano-left  { flex:1; background:#e9ecef; display:flex; flex-direction:column; min-width:0; transition:flex .3s ease; overflow:hidden; margin-right:6px; border:1px solid #dde4ec; border-radius:12px; }
.split-plano-right { flex:1; display:flex; flex-direction:column; min-width:0; transition:flex .3s ease; overflow:hidden; margin-left:6px; border:1px solid #dde4ec; border-radius:12px; background:#fff; }
.split-plano-left.maximized  { flex:2.5 !important; }
.split-plano-left.minimized  { flex:0 !important; border:none; opacity:0; pointer-events:none; }
.split-plano-right.maximized { flex:2.5 !important; }
.split-plano-right.minimized { flex:0 !important; border:none; opacity:0; pointer-events:none; }
.sp-header { padding:10px 14px; border-bottom:2px solid #e0e8f0; background:#f4f7fb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; gap:8px; }
.sp-header h3 { font-size:.9rem; font-weight:700; color:#003d5c; margin:0; white-space:nowrap; }
.btn-sp-max { background:none; border:1px solid #c5d4e0; border-radius:6px; padding:4px 8px; cursor:pointer; color:#555; font-size:.78rem; transition:all .15s; }
.btn-sp-max:hover { background:#005686; color:#fff; border-color:#005686; }
.sp-body { flex:1; overflow-y:auto; padding:14px; }
.bom-ia-card { border:1.5px solid #e8ecf0; border-radius:10px; margin-bottom:14px; overflow:hidden; }
.bom-ia-card-head { background:#f0f4f8; padding:10px 14px; font-size:.82rem; font-weight:700; color:#003d5c; display:flex; justify-content:space-between; align-items:center; }
.bom-ia-table { width:100%; border-collapse:collapse; font-size:.78rem; }
.bom-ia-table th { background:#f8f8f8; padding:6px 8px; text-align:left; border-bottom:1.5px solid #e0e0e0; color:#555; font-weight:700; }
.bom-ia-table td { padding:5px 8px; border-bottom:1px solid #f0f0f0; }
.pdf-wrap { flex:1; overflow:auto; padding:10px; display:flex; flex-direction:column; align-items:center; gap:8px; }
#planosPages { display:flex; flex-direction:column; align-items:center; gap:10px; width:100%; }
.page-wrap { position:relative; display:inline-block; max-width:100%; }
.textLayer { position:absolute; top:0; left:0; overflow:hidden; line-height:1; pointer-events:auto; transform-origin:0 0; }
.textLayer span { color:transparent; position:absolute; white-space:pre; cursor:text; transform-origin:0% 0%; }
.textLayer ::selection { background:rgba(0,100,255,.25); color:transparent; }
.btn-ia-proc { background:linear-gradient(135deg,#7b1fa2,#4a148c); color:#fff; border:none; border-radius:8px; padding:10px 20px; font-family:'Montserrat',sans-serif; font-size:.85rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px; transition:all .2s; }
.btn-ia-proc:hover { background:linear-gradient(135deg,#9c27b0,#6a1b9a); }
/* Tabla de validación */
.val-wrap { display:flex; flex-direction:column; height:100%; }
.val-toolbar { display:flex; gap:8px; padding:10px 14px; border-bottom:1px solid #e8ecf0; background:#f8f9fa; flex-shrink:0; flex-wrap:wrap; align-items:center; }
.btn-validar  { background:#2e7d32; color:#fff; border:none; border-radius:7px; padding:7px 18px; font-size:.82rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; }
.btn-validar:hover  { background:#1b5e20; }
.btn-rechazar { background:#c62828; color:#fff; border:none; border-radius:7px; padding:7px 18px; font-size:.82rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; }
.btn-rechazar:hover { background:#7f0000; }
.val-body  { flex:1; overflow-y:auto; padding:14px 14px 6px; }
.val-title { font-size:.72rem; font-weight:800; color:#003d5c; letter-spacing:.5px; text-transform:uppercase; margin-bottom:10px; }
.val-table { width:100%; border-collapse:collapse; font-size:.78rem; }
.val-table thead th { padding:7px 8px; text-align:left; font-weight:700; color:#555; background:#f4f7fb; border-bottom:2px solid #dde4ec; white-space:nowrap; }
.val-table tbody td { padding:4px 4px; border-bottom:1px solid #f0f4f8; vertical-align:middle; }
.val-inp { width:100%; border:1.5px solid #d5e0ea; border-radius:6px; padding:5px 8px; font-size:.78rem; font-family:'Montserrat',sans-serif; color:#333; background:#fff; box-sizing:border-box; transition:border-color .15s; }
.val-inp:focus { outline:none; border-color:#005686; background:#f0f8ff; }
.val-inp::placeholder { color:#bbb; }
.val-inp.inp-num { text-align:center; }
.btn-del-row { background:none; border:none; color:#e53935; cursor:pointer; font-size:1rem; padding:3px 6px; border-radius:4px; line-height:1; }
.btn-del-row:hover { background:#ffebee; }
.btn-add-item { background:#003d5c; color:#fff; border:none; border-radius:7px; padding:8px 16px; font-size:.8rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; margin-top:10px; }
.btn-add-item:hover { background:#005686; }
.btn-agregar-mat { background:#e65100; color:#fff; border:none; border-radius:7px; padding:7px 16px; font-size:.82rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; }
.btn-agregar-mat:hover { background:#bf360c; }
.btn-comparar { background:#1565c0; color:#fff; border:none; border-radius:7px; padding:7px 16px; font-size:.82rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; }
.btn-comparar:hover { background:#0d47a1; }
/* ── Modales compartidos -- ver el <style> global arriba para .prd-modal-* ── */
.prd-btn-cancel { background:#f5f5f5; color:#555; border:none; border-radius:7px; padding:8px 18px; font-size:.84rem; font-weight:600; cursor:pointer; }
.prd-btn-cancel:hover { background:#e0e0e0; }
.prd-btn-primary { color:#fff; border:none; border-radius:7px; padding:8px 20px; font-size:.84rem; font-weight:700; cursor:pointer; }
/* ── Modal: Inventario ──────────────────────────────────────────── */
.inv-box { width:540px; }
.inv-search-bar { display:flex; gap:8px; padding:14px 20px; border-bottom:1px solid #eee; background:#fafafa; }
.inv-search-bar input { flex:1; border:1.5px solid #dde2e8; border-radius:7px; padding:8px 12px; font-size:.88rem; outline:none; }
.inv-search-bar input:focus { border-color:#e65100; }
.inv-search-bar button { background:#e65100; color:#fff; border:none; border-radius:7px; padding:8px 14px; cursor:pointer; font-size:.85rem; white-space:nowrap; }
.inv-search-bar button:hover { background:#bf360c; }
.inv-results { max-height:220px; overflow-y:auto; padding:8px 14px; }
.inv-result-item { display:flex; flex-direction:column; padding:10px 12px; border-radius:8px; cursor:pointer; border:2px solid transparent; margin-bottom:4px; transition:background .12s,border-color .12s; }
.inv-result-item:hover { background:#fff3e0; }
.inv-result-item.selected { border-color:#e65100; background:#fff8f2; }
.inv-item-top { display:flex; gap:8px; align-items:baseline; flex-wrap:wrap; }
.inv-codigo { font-size:.7rem; font-weight:700; color:#e65100; background:#fff3e0; border-radius:4px; padding:1px 6px; white-space:nowrap; }
.inv-desc { font-size:.85rem; font-weight:600; color:#222; }
.inv-item-meta { font-size:.73rem; color:#888; margin-top:2px; }
.inv-stock-ok { color:#2e7d32; font-weight:700; }
.inv-stock-low { color:#c62828; font-weight:700; }
.inv-empty { text-align:center; padding:24px; color:#bbb; font-size:.83rem; }
.inv-qty-row { display:flex; gap:16px; align-items:flex-end; padding:12px 20px; background:#f8f9fa; border-top:1px solid #eee; }
.inv-qty-field { display:flex; flex-direction:column; gap:4px; }
.inv-qty-field label { font-size:.74rem; font-weight:700; color:#555; text-transform:uppercase; }
.inv-qty-field input { border:1.5px solid #dde2e8; border-radius:7px; padding:8px 10px; font-size:.9rem; width:90px; outline:none; }
.inv-qty-field input:focus { border-color:#e65100; }
.inv-qty-field input.inp-error { border-color:#e53935; }
.inv-unidad-val { font-size:.88rem; font-weight:700; color:#444; padding-bottom:9px; }
.inv-selected-label { flex:1; font-size:.78rem; color:#555; padding-bottom:9px; font-style:italic; }
/* ── Modal: Comparador BOM ──────────────────────────────────────── */
.cmp-box { width:880px; }
.cmp-selector-row { display:flex; gap:10px; padding:14px 20px; border-bottom:1px solid #eee; align-items:center; background:#fafafa; }
.cmp-selector-row label { font-size:.82rem; font-weight:700; color:#444; white-space:nowrap; }
.cmp-selector-row select { flex:1; border:1.5px solid #dde2e8; border-radius:7px; padding:8px 12px; font-size:.84rem; outline:none; }
.cmp-selector-row button { background:#1565c0; color:#fff; border:none; border-radius:7px; padding:8px 16px; font-size:.82rem; font-weight:700; cursor:pointer; white-space:nowrap; }
.cmp-selector-row button:hover { background:#0d47a1; }
.cmp-legend { display:flex; gap:14px; flex-wrap:wrap; padding:10px 20px 6px; font-size:.74rem; border-bottom:1px solid #f0f0f0; }
.cmp-legend span { display:flex; align-items:center; gap:5px; color:#555; }
.cmp-result-area { max-height:400px; overflow-y:auto; }
.cmp-summary { padding:10px 20px; font-size:.8rem; color:#555; border-bottom:1px solid #f0f0f0; }
.cmp-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.cmp-table th { background:#f0f4f8; padding:8px 10px; text-align:left; font-weight:700; color:#444; border-bottom:2px solid #dde2e8; position:sticky; top:0; z-index:1; }
.cmp-table td { padding:7px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.cmp-row-ok td { background:#f1f8e9; }
.cmp-row-faltante td { background:#fff3e0; }
.cmp-row-exceso td { background:#fce4ec; }
.cmp-row-extra td { background:#e3f2fd; }
.cmp-badge { display:inline-block; font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:10px; white-space:nowrap; }
.cmp-badge-ok       { background:#c8e6c9; color:#2e7d32; }
.cmp-badge-faltante { background:#ffe0b2; color:#e65100; }
.cmp-badge-exceso   { background:#ffcdd2; color:#c62828; }
.cmp-badge-extra    { background:#bbdefb; color:#1565c0; }
.btn-ia-proc:disabled { opacity:.6; cursor:not-allowed; }
.ocr-badge { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:10px; background:#fff3cd; color:#856404; border:1px solid #ffc107; }
/* Badge de match contra catálogo */
.bom-num-cell { text-align:center; vertical-align:middle; }
.bom-num { color:#aaa; font-size:.72rem; display:block; line-height:1.2; }
.bom-match { display:inline-block; font-size:.6rem; font-weight:800; padding:1px 5px; border-radius:8px; margin-top:2px; line-height:1.4; white-space:nowrap; }
.bom-match.matched   { background:#c8e6c9; color:#1b5e20; cursor:default; }
.bom-match.unmatched { background:#fff3e0; color:#e65100; cursor:default; }
</style>

<div class="split-plano">
    <!-- IZQUIERDA: PDF Viewer -->
    <div class="split-plano-left" id="panelPlano">
        <div class="sp-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <a href="produccion.php?seccion=subproyectos" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i></a>
                <h3><i class="fas fa-file-pdf" style="color:#d32f2f;"></i> <?= htmlspecialchars($plano_ia['pieza_nombre']) ?> — <?= htmlspecialchars($plano_ia['proyecto_nombre']) ?></h3>
                <span id="ocrBadge" class="ocr-badge" style="display:none;">OCR…</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:4px;border:1px solid #ccc;border-radius:6px;padding:2px 6px;background:#fff;">
                    <input type="text" id="pdfSearchInput" placeholder="Buscar..." style="border:none;outline:none;padding:3px;width:130px;font-size:.8rem;">
                    <button id="pdfSearchBtn" onclick="buscarTextoP()" class="btn-sp-max" title="Buscar"><i class="fas fa-search"></i></button>
                    <button id="pdfPrevBtn" onclick="anteriorResultadoP()" class="btn-sp-max" title="Anterior"><i class="fas fa-chevron-up"></i></button>
                    <button id="pdfNextBtn" onclick="siguienteResultadoP()" class="btn-sp-max" title="Siguiente"><i class="fas fa-chevron-down"></i></button>
                    <span id="searchInfoP" style="font-size:.72rem;color:#888;min-width:28px;"></span>
                </div>
                <button onclick="zoomP(-0.2)" class="btn-sp-max">−</button>
                <span id="zoomLabel" style="font-size:.78rem;font-weight:700;min-width:40px;text-align:center;">100%</span>
                <button onclick="zoomP(+0.2)" class="btn-sp-max">+</button>
                <button class="btn-sp-max" onclick="toggleSplitP('panelPlano','panelBoms')" title="Maximizar"><i class="fas fa-expand-alt"></i></button>
            </div>
        </div>
        <div class="pdf-wrap" id="pdfWrap">
            <?php if ($pdf_url): ?>
            <div id="planosPages"></div>
            <?php else: ?>
            <div style="padding:40px;text-align:center;color:#aaa;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Este plano no tiene archivo PDF subido</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- DERECHA: BOMs IA -->
    <div class="split-plano-right" id="panelBoms">
        <div class="sp-header">
            <h3><i class="fas fa-robot" style="color:#7b1fa2;"></i> BOMs detectados</h3>
            <div style="display:flex;align-items:center;gap:6px;">
            <button class="btn-sp-max" onclick="toggleSplitP('panelBoms','panelPlano')" title="Maximizar"><i class="fas fa-expand-alt"></i></button>
            <?php if ($pdf_url): ?>
            <button class="btn-ia-proc" id="btnProcesar" onclick="procesarPlano()">
                <i class="fas fa-magic"></i> <?= $plano_ia['ia_procesado'] ? 'Reprocesar' : 'Procesar con IA' ?>
            </button>
            <?php endif; ?>
            </div>
        </div>
        <div class="sp-body" id="spBody">
            <?php if (empty($boms_ia)): ?>
            <div style="text-align:center;padding:40px 20px;color:#bbb;">
                <i class="fas fa-magic" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#ddd;"></i>
                <p style="font-size:.85rem;">Presiona <strong>Procesar con IA</strong> para extraer los BOMs del plano</p>
            </div>
            <?php else: ?>
            <?php foreach ($boms_ia as $bom_ia):
                $items_bom = $db->prepare("SELECT * FROM boom_items WHERE boom_id=? ORDER BY id");
                $items_bom->execute([$bom_ia['id']]);
                $items_bom = $items_bom->fetchAll();
            ?>
            <div class="bom-ia-card">
                <div class="bom-ia-card-head">
                    <span><i class="fas fa-layer-group"></i> <?= htmlspecialchars($bom_ia['pieza_nombre']) ?></span>
                    <span style="font-size:.72rem;color:#888;"><?= $bom_ia['total_items'] ?> materiales</span>
                </div>
                <?php if (!empty($items_bom)): ?>
                <table class="bom-ia-table">
                    <thead><tr><th>#</th><th>Descripción</th><th>Cant.</th><th>U.</th><th>Marca</th></tr></thead>
                    <tbody>
                    <?php foreach ($items_bom as $i => $it): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><?= htmlspecialchars($it['descripcion']) ?></td>
                        <td><?= $it['cantidad'] ?></td>
                        <td><?= htmlspecialchars($it['unidad_medida'] ?? '') ?></td>
                        <td><?= htmlspecialchars($it['marca'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($pdf_url): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const PDF_URL    = <?= json_encode($pdf_url) ?>;
const BOOM_ID    = <?= $plano_ia['id'] ?>;
const PROY_ID    = <?= intval($plano_ia['proyecto_id'] ?? 0) ?>;
let pdfDocP      = null;
let scaleP       = 1.0;
let ocrCache     = {};
let ocrTotal     = 0, ocrListo = 0;
let tWorkerP     = null;

async function getTesseract() {
    if (tWorkerP) return tWorkerP;
    tWorkerP = await Tesseract.createWorker('eng', 1, {
        workerPath:'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
        langPath:'https://tessdata.projectnaptha.com/4.0.0_best',
        corePath:'https://cdn.jsdelivr.net/npm/tesseract.js-core@5/tesseract-core-simd-lstm.wasm.js',
        logger:()=>{}
    });
    await tWorkerP.setParameters({ tessedit_pageseg_mode:'3' });
    return tWorkerP;
}

async function cargarPDF() {
    try {
        pdfDocP = await pdfjsLib.getDocument(PDF_URL).promise;
        ocrTotal = pdfDocP.numPages;
        document.getElementById('ocrBadge').style.display = '';
        document.getElementById('ocrBadge').textContent = 'OCR 0/' + ocrTotal;
        const wrap = document.getElementById('planosPages');
        wrap.innerHTML = '';
        for (let n = 1; n <= pdfDocP.numPages; n++) {
            await renderPagina(n, wrap);
        }
    } catch(e) { console.error('PDF error:', e); }
}

// Contenedor por página: { canvas, textLayer, ovCanvas, vp }
const pageContainersP = {};

async function renderPagina(num, wrap) {
    const page = await pdfDocP.getPage(num);
    const vp   = page.getViewport({ scale: scaleP });

    const pageDiv = document.createElement('div');
    pageDiv.className = 'page-wrap';

    const canvas = document.createElement('canvas');
    canvas.width = vp.width; canvas.height = vp.height;
    canvas.style.cssText = 'display:block;max-width:100%;height:auto;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.15);';
    pageDiv.appendChild(canvas);
    wrap.appendChild(pageDiv);

    await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;

    // Canvas overlay para highlights
    const ovCanvas = document.createElement('canvas');
    ovCanvas.width = vp.width; ovCanvas.height = vp.height;
    ovCanvas.style.cssText = 'position:absolute;top:0;left:0;max-width:100%;height:auto;pointer-events:none;border-radius:6px;';
    pageDiv.appendChild(ovCanvas);

    // TextLayer para scroll y referencia de posición
    const textLayer = document.createElement('div');
    textLayer.className = 'textLayer';
    textLayer.style.cssText = `position:absolute;top:0;left:0;width:${vp.width}px;height:${vp.height}px;overflow:hidden;transform:scale(${canvas.offsetWidth/vp.width});transform-origin:0 0;`;
    pageDiv.appendChild(textLayer);

    pageContainersP[num] = { canvas, ovCanvas, textLayer, vp, pageDiv };

    ocrPaginaP(num, canvas, vp);
}

async function ocrPaginaP(num, canvas, vp) {
    try {
        const w = await getTesseract();
        // Renderizar canvas oculto a 2x para que Tesseract detecte bien sin depender del zoom del usuario
        const page    = await pdfDocP.getPage(num);
        const vpOcr   = page.getViewport({ scale: 2.0 });
        const ocrCnv  = document.createElement('canvas');
        ocrCnv.width  = vpOcr.width;
        ocrCnv.height = vpOcr.height;
        await page.render({ canvasContext: ocrCnv.getContext('2d'), viewport: vpOcr }).promise;
        const { data } = await w.recognize(ocrCnv);

        // Normalizar bboxes a fracción (0-1) usando el canvas OCR (2x)
        function normBbox(bbox) {
            return { x0: bbox.x0/vpOcr.width, y0: bbox.y0/vpOcr.height,
                     x1: bbox.x1/vpOcr.width, y1: bbox.y1/vpOcr.height };
        }
        const words = (data.words||[]).filter(function(w){return w.text.trim();})
            .map(function(w){return {text:w.text, bbox:normBbox(w.bbox)};});
        const lines = (data.lines||[]).filter(function(l){return l.text.trim().length>1;})
            .map(function(l){return {text:l.text.trim(), bbox:normBbox(l.bbox)};});

        ocrCache[num] = { words, lines, fullText: data.text||'' };

        // Construir spans con bbox en el textLayer
        const pc = pageContainersP[num];
        if (pc) construirSpansP(words, lines, pc.vp, pc.textLayer);

        // Re-aplicar búsqueda si ya había una activa
        if (searchTextP) buscarTextoP();

        ocrListo++;
        const badge = document.getElementById('ocrBadge');
        if (ocrListo >= ocrTotal) {
            badge.textContent = 'OCR listo ✓';
            badge.style.background='#d4edda'; badge.style.color='#155724'; badge.style.borderColor='#c3e6cb';
        } else {
            badge.textContent = 'OCR ' + ocrListo + '/' + ocrTotal;
        }
    } catch(e) { console.error('OCR error p'+num, e); }
}

function construirSpansP(words, lines, vp, tl) {
    tl.innerHTML = '';
    const sx = vp.width, sy = vp.height;
    for (const word of words) {
        if (!word.text || !word.bbox) continue;
        const x0=Math.round(word.bbox.x0*sx), y0=Math.round(word.bbox.y0*sy);
        const x1=Math.round(word.bbox.x1*sx), y1=Math.round(word.bbox.y1*sy);
        const span = document.createElement('span');
        span.textContent = word.text;
        span.dataset.ocr='true'; span.dataset.x0=x0; span.dataset.y0=y0; span.dataset.x1=x1; span.dataset.y1=y1;
        span.style.cssText=`position:absolute;left:${x0}px;top:${y0}px;width:${x1-x0}px;height:${y1-y0}px;color:transparent;cursor:text;white-space:pre;`;
        tl.appendChild(span);
    }
    for (const line of (lines||[])) {
        if (!line.text) continue;
        const x0=Math.round(line.bbox.x0*sx), y0=Math.round(line.bbox.y0*sy);
        const x1=Math.round(line.bbox.x1*sx), y1=Math.round(line.bbox.y1*sy);
        const span = document.createElement('span');
        span.textContent = line.text;
        span.dataset.ocr='true'; span.dataset.ocrLine='true';
        span.dataset.x0=x0; span.dataset.y0=y0; span.dataset.x1=x1; span.dataset.y1=y1;
        span.style.cssText=`position:absolute;left:${x0}px;top:${y0}px;width:${x1-x0}px;height:${y1-y0}px;color:transparent;cursor:text;white-space:pre;`;
        tl.appendChild(span);
    }
}

// ── CANVAS HIGHLIGHTS ─────────────────────────────────────────────────────────
function getOverlayP(num) {
    return pageContainersP[num] ? pageContainersP[num].ovCanvas : null;
}
function limpiarCanvasHighlightsP(num) {
    const limpiar = function(n) {
        const ov = getOverlayP(n);
        if (ov) ov.getContext('2d').clearRect(0,0,ov.width,ov.height);
    };
    if (num) { limpiar(num); } else { Object.keys(pageContainersP).forEach(function(n){ limpiar(parseInt(n)); }); }
}
function dibujarHighlightP(x, y, w, h, num) {
    const ov = getOverlayP(num);
    if (!ov) return;
    const octx = ov.getContext('2d');
    octx.fillStyle = 'rgba(255,180,0,0.45)';
    octx.fillRect(x, y, w, h);
    octx.strokeStyle = 'rgba(200,110,0,0.9)';
    octx.lineWidth = 1.5;
    octx.strokeRect(x+0.75, y+0.75, w-1.5, h-1.5);
}

// ── BUSCADOR ──────────────────────────────────────────────────────────────────
let searchTextP = '', matchesP = [], matchIdxP = -1;

function buscarTextoP() {
    const val = document.getElementById('pdfSearchInput').value.trim();
    limpiarHighlightsP();
    matchesP = []; matchIdxP = -1;
    document.getElementById('searchInfoP').textContent = '';
    if (!val) { searchTextP = ''; return; }
    searchTextP = val;
    const escaped = val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    Object.keys(pageContainersP).forEach(function(n) {
        const num = parseInt(n);
        const pc  = pageContainersP[num];
        if (!pc || !pc.textLayer) return;
        limpiarCanvasHighlightsP(num);

        const spans     = Array.from(pc.textLayer.getElementsByTagName('span'));
        const wordSpans = spans.filter(function(s){return !s.dataset.ocrLine;});
        const lineSpans = spans.filter(function(s){return  s.dataset.ocrLine;});
        const cubiertas = [];
        const regex     = new RegExp(escaped, 'gi');

        function anadirMatchP(div) {
            const x0=parseInt(div.dataset.x0)||0, y0=parseInt(div.dataset.y0)||0;
            const x1=parseInt(div.dataset.x1)||x0+50, y1=parseInt(div.dataset.y1)||y0+16;
            dibujarHighlightP(x0, y0, x1-x0, y1-y0, num);
            cubiertas.push({x0,y0,x1,y1});
            const ref = document.createElement('div');
            ref.className='pmatch-ref';
            ref.style.cssText=`position:absolute;left:${x0}px;top:${y0}px;width:1px;height:1px;pointer-events:none;`;
            pc.textLayer.appendChild(ref);
            matchesP.push({num, el:ref});
        }

        for (const span of wordSpans) {
            if (!span.textContent.trim()) continue;
            regex.lastIndex = 0;
            if (regex.test(span.textContent)) anadirMatchP(span);
        }
        for (const span of lineSpans) {
            if (!span.textContent.trim()) continue;
            regex.lastIndex = 0;
            if (!regex.test(span.textContent)) continue;
            const ly0=parseInt(span.dataset.y0)||0, ly1=parseInt(span.dataset.y1)||ly0+16;
            if (!cubiertas.some(function(z){return ly0<z.y1&&ly1>z.y0;})) anadirMatchP(span);
        }
    });

    if (matchesP.length > 0) { matchIdxP = 0; irAMatchP(0); }
    document.getElementById('searchInfoP').textContent = matchesP.length > 0 ? '1/'+matchesP.length : '0';
}

function limpiarHighlightsP() {
    limpiarCanvasHighlightsP();
    Object.values(pageContainersP).forEach(function(pc) {
        if (pc.textLayer) pc.textLayer.querySelectorAll('.pmatch-ref').forEach(function(r){r.remove();});
    });
}

function irAMatchP(idx) {
    if (!matchesP.length) return;
    const mk = matchesP[idx];
    if (!mk || !mk.el) return;
    mk.el.scrollIntoView({ behavior:'smooth', block:'center' });
    document.getElementById('searchInfoP').textContent = (idx+1)+'/'+matchesP.length;
}

function siguienteResultadoP() {
    if (!matchesP.length) return;
    matchIdxP = (matchIdxP+1) % matchesP.length;
    irAMatchP(matchIdxP);
}
function anteriorResultadoP() {
    if (!matchesP.length) return;
    matchIdxP = (matchIdxP-1+matchesP.length) % matchesP.length;
    irAMatchP(matchIdxP);
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('pdfSearchInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') buscarTextoP();
    });
});
// ──────────────────────────────────────────────────────────────────────────────

function zoomP(delta) {
    if (delta === 0) { scaleP = 1.0; }
    else { scaleP = Math.max(0.5, Math.min(3.0, scaleP + delta)); }
    document.getElementById('zoomLabel').textContent = Math.round(scaleP * 100) + '%';
    if (pdfDocP) {
        // Limpiar estado antes de re-renderizar
        Object.keys(pageContainersP).forEach(function(k){ delete pageContainersP[k]; });
        limpiarHighlightsP();
        matchesP = []; matchIdxP = -1;
        document.getElementById('searchInfoP').textContent = '';
        document.getElementById('planosPages').innerHTML = '';
        for(let n=1;n<=pdfDocP.numPages;n++) renderPagina(n, document.getElementById('planosPages'));
    }
}

// Datos extraídos por la IA (pendientes de validar)
let bomsPendientes = null;

async function procesarPlano() {
    const btn = document.getElementById('btnProcesar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando…';

    const fd = new FormData();
    fd.append('boom_id', BOOM_ID);
    fd.append('solo_extraer', '1'); // No guardar en BD todavía

    let textoOCR = '';
    for (let n in ocrCache) { textoOCR += '--- Página ' + n + ' ---\n' + (ocrCache[n].fullText || ocrCache[n]) + '\n\n'; }

    if (textoOCR.trim().length > 100) {
        fd.append('texto_ocr', textoOCR.substring(0, 18000));
        fd.append('archivo', new File([''], 'doc.txt', { type:'text/plain' }));
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analizando texto…';
    } else {
        if (!pdfDocP) { alert('El PDF no está cargado'); btn.disabled=false; btn.innerHTML='<i class="fas fa-magic"></i> Procesar con IA'; return; }
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Convirtiendo páginas…';
        const paginas = [];
        for (let n = 1; n <= pdfDocP.numPages; n++) {
            const page = await pdfDocP.getPage(n);
            const vp   = page.getViewport({ scale: 1.5 });
            const c    = document.createElement('canvas');
            c.width = vp.width; c.height = vp.height;
            await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
            paginas.push(c.toDataURL('image/jpeg', 0.7).split(',')[1]);
        }
        fd.append('paginas_pdf', JSON.stringify(paginas));
        fd.append('archivo', new File([''],'doc.jpg',{type:'image/jpeg'}));
    }

    try {
        const r = await fetch('api/procesar_plano_ia.php', { method:'POST', body:fd });
        const d = await r.json();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic"></i> Reprocesar';
        if (d.success) {
            if (d.sin_bom) {
                document.getElementById('spBody').innerHTML = '<div style="text-align:center;padding:30px;color:#e65100;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:8px;"></i><p>No se detectó ningún BOM en el plano</p></div>';
            } else {
                bomsPendientes = d.datos.boms;

                // ── Match contra catálogo antes de mostrar la tabla ──
                try {
                    const flatMats = [];
                    (bomsPendientes || []).forEach(function(bom) {
                        (bom.materiales || []).forEach(function(m) { flatMats.push(m); });
                    });
                    const mfd = new FormData();
                    mfd.append('materiales', JSON.stringify(flatMats));
                    const mr  = await fetch('api/match_bom_materiales.php', { method:'POST', body:mfd });
                    const md  = await mr.json();
                    if (md.success && Array.isArray(md.data)) {
                        let idx = 0;
                        (bomsPendientes || []).forEach(function(bom) {
                            bom.materiales = (bom.materiales || []).map(function() { return md.data[idx++]; });
                        });
                    }
                } catch(_) { /* si falla el match, mostrar sin enriquecer */ }

                mostrarTablaValidacion(bomsPendientes);
            }
        } else {
            alert('Error IA: ' + (d.error || 'desconocido'));
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic"></i> Procesar con IA';
        alert('Error: ' + e.message);
    }
}

function mostrarTablaValidacion(boms) {
    const spBody = document.getElementById('spBody');
    const hoja   = (boms && boms[0]) ? (boms[0].hoja || '1') : '1';

    let rows = '';
    let n = 1;
    (boms || []).forEach(function(bom) {
        (bom.materiales || []).forEach(function(mat) {
            rows += filaHTML(n++, mat);
        });
    });

    spBody.innerHTML =
        '<div class="val-wrap">' +
        '<div class="val-toolbar">' +
            '<button class="btn-validar"  onclick="validarBOM()"><i class="fas fa-check"></i> Validar BOM</button>' +
            '<button class="btn-agregar-mat" onclick="abrirBuscadorInventario()"><i class="fas fa-boxes"></i> Agregar material</button>' +
            '<button class="btn-comparar"    onclick="abrirComparadorBOM()"><i class="fas fa-code-branch"></i> Comparar con Ingeniería</button>' +
            '<button class="btn-rechazar" onclick="rechazarBOM()"><i class="fas fa-times"></i> Rechazar</button>' +
        '</div>' +
        '<div class="val-body">' +
            '<div class="val-title">Lista de materiales</div>' +
            '<table class="val-table" id="valTable" data-hoja="' + hoja + '">' +
            '<thead><tr>' +
                '<th style="width:32px;">#</th>' +
                '<th style="width:52px;">Cant.</th>' +
                '<th style="width:56px;">Unidad</th>' +
                '<th>Descripción</th>' +
                '<th style="width:100px;">Marca</th>' +
                '<th style="width:100px;">Modelo</th>' +
                '<th style="width:30px;"></th>' +
            '</tr></thead>' +
            '<tbody id="valBody">' + rows + '</tbody>' +
            '</table>' +
            '<button class="btn-add-item" onclick="agregarFila()"><i class="fas fa-plus"></i> Agregar ítem</button>' +
        '</div></div>';
}

function filaHTML(n, mat) {
    const v = function(x){ return (x||'').toString().replace(/"/g,'&quot;'); };
    const matId  = mat.material_id || '';
    const badge  = mat.matched === true
        ? '<span class="bom-match matched"  title="Encontrado en catálogo">&#10003; Cat.</span>'
        : (mat.marca ? '<span class="bom-match unmatched" title="Sin coincidencia en catálogo">? Cat.</span>' : '');
    return '<tr data-material-id="' + v(matId) + '">' +
        '<td class="bom-num-cell">' +
            '<span class="bom-num">' + n + '</span>' +
            badge +
        '</td>' +
        '<td><input class="val-inp inp-num" type="number" min="0" step="any" value="' + v(mat.cantidad||1) + '" placeholder="0"></td>' +
        '<td><input class="val-inp inp-num" type="text" value="' + v(mat.unidad||'PZA') + '" placeholder="PZA"></td>' +
        '<td><input class="val-inp" type="text" value="' + v(mat.descripcion) + '" placeholder="Descripción del ítem"></td>' +
        '<td><input class="val-inp" type="text" value="' + v(mat.marca) + '" placeholder="Marca"></td>' +
        '<td><input class="val-inp" type="text" value="' + v(mat.modelo) + '" placeholder="Modelo"></td>' +
        '<td style="text-align:center;"><button class="btn-del-row" onclick="this.closest(\'tr\').remove();renumerarFilas();" title="Eliminar">✕</button></td>' +
    '</tr>';
}

function agregarFila() {
    const tbody = document.getElementById('valBody');
    if (!tbody) return;
    const n = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = filaHTML(n, {descripcion:'',cantidad:1,unidad:'PZA',marca:'',modelo:''})
        .replace(/^<tr>|<\/tr>$/g,'');
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
}

function renumerarFilas() {
    document.querySelectorAll('#valBody tr').forEach(function(tr, i) {
        const numEl = tr.cells[0].querySelector('.bom-num');
        if (numEl) { numEl.textContent = i + 1; } else { tr.cells[0].textContent = i + 1; }
    });
}

function leerTabla() {
    const hoja = document.getElementById('valTable')?.dataset.hoja || '1';
    const mats = [];
    document.querySelectorAll('#valBody tr').forEach(function(tr) {
        const inputs = tr.querySelectorAll('input');
        const desc   = inputs[2]?.value.trim();
        if (!desc) return;
        mats.push({
            cantidad:    parseFloat(inputs[0]?.value) || 1,
            unidad:      inputs[1]?.value.trim() || 'PZA',
            descripcion: desc,
            marca:       inputs[3]?.value.trim() || '',
            modelo:      inputs[4]?.value.trim() || '',
            material_id: tr.dataset.materialId ? parseInt(tr.dataset.materialId) : null,
        });
    });
    return [{ hoja, materiales: mats }];
}

async function validarBOM() {
    const boms = leerTabla();
    if (!boms[0].materiales.length) { alert('La tabla está vacía.'); return; }

    const fd = new FormData();
    fd.append('boom_id',  BOOM_ID);
    fd.append('bom_data', JSON.stringify(boms));

    const r = await fetch('api/validar_bom_plano.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
        window.location.reload();
    } else {
        alert('Error al guardar: ' + (d.error || 'desconocido'));
    }
}

function rechazarBOM() {
    bomsPendientes = null;
    document.getElementById('spBody').innerHTML = '<div style="text-align:center;padding:40px 20px;color:#bbb;"><i class="fas fa-magic" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#ddd;"></i><p style="font-size:.85rem;">Presiona <strong>Reprocesar</strong> para extraer los BOMs del plano</p></div>';
}

/* ── Utilidad: escapar HTML ──────────────────────────────────────── */
function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Modal: Buscar en Inventario ────────────────────────────────── */
let _invSelected = null;

function abrirBuscadorInventario() {
    const overlay = document.getElementById('modalInventarioOverlay');
    if (!overlay) return;
    _invSelected = null;
    document.getElementById('invSearchInput').value = '';
    document.getElementById('invResults').innerHTML = '<div class="inv-empty"><i class="fas fa-search" style="margin-right:6px;"></i>Escribe para buscar en el inventario...</div>';
    document.getElementById('invQtyRow').style.display = 'none';
    overlay.style.display = 'flex';
    setTimeout(function(){ document.getElementById('invSearchInput').focus(); }, 80);
}

function cerrarBuscadorInventario() {
    document.getElementById('modalInventarioOverlay').style.display = 'none';
}

async function buscarEnInventario() {
    const q = document.getElementById('invSearchInput').value.trim();
    const box = document.getElementById('invResults');
    if (q.length < 2) {
        box.innerHTML = '<div class="inv-empty">Escribe al menos 2 caracteres.</div>';
        return;
    }
    box.innerHTML = '<div class="inv-empty"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
    try {
        const r = await fetch('api/materiales.php?busqueda=' + encodeURIComponent(q));
        const d = await r.json();
        if (!d.success || !d.data || !d.data.length) {
            box.innerHTML = '<div class="inv-empty">Sin resultados para "' + escH(q) + '".</div>';
            return;
        }
        box.innerHTML = d.data.slice(0, 25).map(function(m) {
            const stockOk  = parseFloat(m.stock_disponible) > parseFloat(m.stock_minimo || 0);
            const stockCls = stockOk ? 'inv-stock-ok' : 'inv-stock-low';
            return '<div class="inv-result-item" onclick="seleccionarItemInventario(this)"' +
                ' data-id="'     + m.id                            + '"' +
                ' data-desc="'   + escH(m.descripcion)             + '"' +
                ' data-codigo="' + escH(m.codigo   || '')          + '"' +
                ' data-marca="'  + escH(m.marca    || '')          + '"' +
                ' data-modelo="' + escH(m.modelo   || '')          + '"' +
                ' data-unidad="' + escH(m.unidad_medida || 'PZA') + '"' +
                ' data-stock="'  + m.stock_disponible              + '">' +
                '<div class="inv-item-top">' +
                (m.codigo ? '<span class="inv-codigo">' + escH(m.codigo) + '</span>' : '') +
                '<span class="inv-desc">' + escH(m.descripcion) + '</span>' +
                '</div>' +
                '<div class="inv-item-meta">' +
                (m.marca ? 'Marca: <strong>' + escH(m.marca) + '</strong> · ' : '') +
                'Stock: <span class="' + stockCls + '">' + m.stock_disponible + ' ' + escH(m.unidad_medida || 'PZA') + '</span>' +
                '</div></div>';
        }).join('');
    } catch(e) {
        box.innerHTML = '<div class="inv-empty" style="color:#c62828;">Error: ' + escH(e.message) + '</div>';
    }
}

function seleccionarItemInventario(el) {
    document.querySelectorAll('.inv-result-item').forEach(function(i){ i.classList.remove('selected'); });
    el.classList.add('selected');
    _invSelected = {
        id:          el.dataset.id,
        descripcion: el.dataset.desc,
        codigo:      el.dataset.codigo,
        marca:       el.dataset.marca,
        modelo:      el.dataset.modelo,
        unidad:      el.dataset.unidad || 'PZA',
        stock:       parseFloat(el.dataset.stock) || 0,
    };
    const row = document.getElementById('invQtyRow');
    row.style.display = 'flex';
    document.getElementById('invQtyInput').value = '1';
    document.getElementById('invQtyInput').classList.remove('inp-error');
    document.getElementById('invUnidadVal').textContent = _invSelected.unidad;
    document.getElementById('invSelectedLabel').textContent = _invSelected.descripcion;
    document.getElementById('invQtyInput').focus();
}

function confirmarAgregarInventario() {
    if (!_invSelected) { alert('Selecciona un material primero.'); return; }
    const qEl = document.getElementById('invQtyInput');
    const qty = parseFloat(qEl.value);
    if (isNaN(qty) || qty <= 0) { qEl.classList.add('inp-error'); qEl.focus(); return; }
    qEl.classList.remove('inp-error');

    const tbody = document.getElementById('valBody');
    if (!tbody) { alert('Primero procesa el BOM del plano.'); return; }

    // Construir ítem compatible con filaHTML() sin mutar bomsPendientes
    const mat = {
        descripcion: _invSelected.descripcion,
        cantidad:    qty,
        unidad:      _invSelected.unidad,
        marca:       _invSelected.marca,
        modelo:      _invSelected.codigo || _invSelected.modelo,
    };
    const tmp = document.createElement('tbody');
    tmp.innerHTML = filaHTML(tbody.querySelectorAll('tr').length + 1, mat);
    const tr = tmp.querySelector('tr');
    if (tr) {
        tbody.appendChild(tr);
        renumerarFilas();
        tr.scrollIntoView({ behavior:'smooth', block:'nearest' });
    }
    cerrarBuscadorInventario();
}

/* ── Modal: Comparar con BOM de Ingeniería ──────────────────────── */
function abrirComparadorBOM() {
    const overlay = document.getElementById('modalComparadorOverlay');
    if (!overlay) return;
    document.getElementById('cmpResultArea').innerHTML = '';
    const sel = document.getElementById('cmpBOMSelect');
    sel.innerHTML = '<option value="">Cargando BOMs...</option>';
    overlay.classList.add('open');

    fetch('api/get_boms_ingenieria.php?action=boms&proyecto_id=' + PROY_ID)
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (!d.success || !d.data || !d.data.length) {
                sel.innerHTML = '<option value="">— Sin BOMs de Ingeniería guardados —</option>';
                return;
            }
            sel.innerHTML = '<option value="">Selecciona un BOM de Ingeniería...</option>' +
                d.data.map(function(b) {
                    const tag = b.tipo_lista === 'adicional' ? ' [adicional]' : '';
                    return '<option value="' + b.id + '">' + escH(b.nombre) + tag + ' (x' + b.num_equipos + ' equipos)</option>';
                }).join('');
        })
        .catch(function(e) {
            sel.innerHTML = '<option value="">Error al cargar: ' + escH(e.message) + '</option>';
        });
}

function cerrarComparadorBOM() {
    document.getElementById('modalComparadorOverlay').classList.remove('open');
}

function eliminarDuplicados() {
    if (!_duplicateKeys.size) return;
    const norm = function(s) { return (s || '').toLowerCase().trim().replace(/\s+/g, ' '); };
    let eliminados = 0;
    document.querySelectorAll('#valBody tr').forEach(function(tr) {
        const desc = tr.querySelectorAll('input')[2]?.value.trim() || '';
        if (_duplicateKeys.has(norm(desc))) {
            tr.remove();
            eliminados++;
        }
    });
    renumerarFilas();
    _duplicateKeys = new Set();
    cerrarComparadorBOM();
    if (eliminados > 0) {
        const msg = document.createElement('div');
        msg.className = 'alert alert-success';
        msg.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;min-width:260px;box-shadow:0 4px 12px rgba(0,0,0,.2);';
        msg.innerHTML = '<i class="fas fa-check-circle"></i> ' + eliminados + ' ítem(s) duplicado(s) eliminado(s) del plano.';
        document.body.appendChild(msg);
        setTimeout(function() { msg.remove(); }, 3500);
    }
}

let _duplicateKeys = new Set();

async function ejecutarComparacion() {
    const bom_id = document.getElementById('cmpBOMSelect').value;
    const area   = document.getElementById('cmpResultArea');
    if (!bom_id) {
        area.innerHTML = '<p style="text-align:center;padding:20px;color:#aaa;">Selecciona un BOM de Ingeniería para comparar.</p>';
        return;
    }
    area.innerHTML = '<p style="text-align:center;padding:24px;color:#888;"><i class="fas fa-spinner fa-spin"></i> Comparando...</p>';

    try {
        const r = await fetch('api/get_boms_ingenieria.php?action=items&boom_id=' + bom_id);
        const d = await r.json();
        if (!d.success) { area.innerHTML = '<p style="color:#c62828;padding:20px;">Error: ' + escH(d.error || '') + '</p>'; return; }

        const norm = function(s) { return (s || '').toLowerCase().trim().replace(/\s+/g, ' '); };

        // BOM de Ingeniería: agrupar por descripción normalizada (cantidad × num_equipos)
        const ingMap = {};
        d.data.forEach(function(i) {
            const k    = norm(i.descripcion);
            const cant = (parseFloat(i.cantidad) || 0) * (parseFloat(i.num_equipos) || 1);
            if (ingMap[k]) {
                ingMap[k].cant += cant;
            } else {
                ingMap[k] = { cant, unidad: i.unidad_medida, raw: i.descripcion };
            }
        });

        // BOM del plano (tabla OCR actual)
        const planoItems = leerTabla()[0].materiales;
        const planoMap = {};
        planoItems.forEach(function(p) {
            const k = norm(p.descripcion);
            if (planoMap[k]) {
                planoMap[k].cant += (parseFloat(p.cantidad) || 0);
            } else {
                planoMap[k] = { cant: parseFloat(p.cantidad) || 0, unidad: p.unidad, raw: p.descripcion };
            }
        });

        // Unión de claves de ambos BOMs
        const claves = new Set([...Object.keys(ingMap), ...Object.keys(planoMap)]);
        const filas  = [];
        _duplicateKeys = new Set();

        claves.forEach(function(k) {
            const cantIng   = ingMap[k]   ? ingMap[k].cant   : 0;
            const cantPlano = planoMap[k] ? planoMap[k].cant : 0;
            const rawDesc   = (ingMap[k] || planoMap[k]).raw;
            const unidad    = (ingMap[k] || planoMap[k]).unidad || '';

            let estado, badge;
            if (cantIng > 0 && cantPlano > 0) {
                estado = 'duplicado';
                badge  = '<span class="cmp-badge cmp-badge-faltante"><i class="fas fa-exclamation-triangle"></i> Duplicado</span>';
                _duplicateKeys.add(k);
            } else if (cantIng > 0) {
                estado = 'solo_ing';
                badge  = '<span class="cmp-badge cmp-badge-ok">Solo en Ingeniería</span>';
            } else {
                estado = 'solo_plano';
                badge  = '<span class="cmp-badge cmp-badge-extra">Solo en Plano</span>';
            }

            filas.push({ desc: rawDesc, cantIng, cantPlano, estado, badge, unidad });
        });

        // Orden: duplicados primero → solo_plano → solo_ing
        const orden = { duplicado: 0, solo_plano: 1, solo_ing: 2 };
        filas.sort(function(a, b) { return (orden[a.estado] || 0) - (orden[b.estado] || 0); });

        const total    = filas.length;
        const dupCnt   = filas.filter(function(f) { return f.estado === 'duplicado';  }).length;
        const planoCnt = filas.filter(function(f) { return f.estado === 'solo_plano'; }).length;
        const ingCnt   = filas.filter(function(f) { return f.estado === 'solo_ing';   }).length;

        const btnEliminar = dupCnt > 0
            ? '<button onclick="eliminarDuplicados()" style="margin-left:12px;padding:4px 14px;background:#d32f2f;color:#fff;border:none;border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;font-family:\'Montserrat\',sans-serif;"><i class="fas fa-trash"></i> Eliminar ' + dupCnt + ' duplicado(s) del plano</button>'
            : '';

        const summary = '<div class="cmp-summary">' +
            (dupCnt ? '<span style="color:#b71c1c;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> ' + dupCnt + ' duplicado(s)</span>' : '<span style="color:#2e7d32;font-weight:700;"><i class="fas fa-check-circle"></i> Sin duplicados</span>') +
            btnEliminar +
            ' &nbsp;·&nbsp; <span style="color:#1565c0;font-weight:700;">' + planoCnt + ' solo en plano</span>' +
            ' &nbsp;·&nbsp; <span style="color:#2e7d32;font-weight:700;">' + ingCnt + ' solo en ingeniería</span>' +
            '</div>';

        const tbody = filas.map(function(f, i) {
            return '<tr class="cmp-row-' + f.estado + '">' +
                '<td style="color:#aaa;text-align:center;width:30px;">' + (i + 1) + '</td>' +
                '<td>' + escH(f.desc || '') +
                (f.unidad ? '<span style="color:#aaa;font-size:.73rem;margin-left:5px;">(' + escH(f.unidad) + ')</span>' : '') + '</td>' +
                '<td style="text-align:center;font-weight:600;width:75px;">' + (f.cantPlano || '—') + '</td>' +
                '<td style="text-align:center;font-weight:600;width:75px;">' + (f.cantIng   || '—') + '</td>' +
                '<td style="width:160px;">' + f.badge + '</td>' +
                '</tr>';
        }).join('');

        area.innerHTML = summary +
            '<table class="cmp-table"><thead><tr>' +
            '<th>#</th><th>Descripción</th>' +
            '<th style="text-align:center">Cant. Plano</th>' +
            '<th style="text-align:center">Cant. Ing.</th>' +
            '<th>Estado</th>' +
            '</tr></thead><tbody>' + tbody + '</tbody></table>';

    } catch(e) {
        area.innerHTML = '<p style="color:#c62828;padding:20px;">Error inesperado: ' + escH(e.message) + '</p>';
    }
}

function toggleSplitP(panelId, otherId) {
    const p = document.getElementById(panelId);
    const o = document.getElementById(otherId);
    if (!p || !o) return;
    if (p.classList.contains('maximized')) {
        p.classList.remove('maximized');
        o.classList.remove('minimized');
    } else {
        p.classList.add('maximized');
        o.classList.add('minimized');
    }
    // Re-renderizar PDF tras la transición
    setTimeout(function() {
        if (pdfDocP) {
            Object.keys(pageContainersP).forEach(function(k){ delete pageContainersP[k]; });
            document.getElementById('planosPages').innerHTML = '';
            for (let n = 1; n <= pdfDocP.numPages; n++) renderPagina(n, document.getElementById('planosPages'));
        }
    }, 350);
}

// Cargar PDF al iniciar
window.addEventListener('load', cargarPDF);
</script>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($seccion === 'ver_proceso'): ?>
<!-- ── VER PROCESO ── -->
<?php
// Query mejorada: proyectos → planos → páginas → OTs con tiempo acumulado
$vp_proyectos = [];
$vp_raw_proyectos = $db->query("
    SELECT DISTINCT p.id, p.nombre, p.orden_ver_proceso
    FROM proyectos p
    JOIN ordenes_trabajo ot ON ot.proyecto_id = p.id
    ORDER BY p.orden_ver_proceso ASC, p.nombre ASC
")->fetchAll();

$stmt_vpl = $db->prepare("
    SELECT bl.id, bl.pieza_nombre, bl.plano_ruta,
           COUNT(ot.id) AS total_ots,
           SUM(CASE WHEN ot.estado='completado' THEN 1 ELSE 0 END) AS ots_ok,
           SUM(CASE WHEN ot.estado='en_proceso' AND ot.pausado=0 THEN 1 ELSE 0 END) AS ots_activas,
           SUM(CASE WHEN ot.estado='en_proceso' AND ot.pausado=1 THEN 1 ELSE 0 END) AS ots_pausadas
    FROM boom_listas bl
    LEFT JOIN ordenes_trabajo ot ON ot.boom_lista_id = bl.id
    LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id AND pp.ignorada = 1
    WHERE bl.proyecto_id = ? AND bl.tipo_lista = 'plano' AND pp.id IS NULL
    GROUP BY bl.id
    ORDER BY bl.fecha_creacion ASC
");

$stmt_vpags = $db->prepare("
    SELECT pp.id, pp.nombre, pp.numero_pagina,
           COUNT(ot.id) AS total_ots,
           SUM(CASE WHEN ot.estado='completado' THEN 1 ELSE 0 END) AS ots_ok,
           SUM(CASE WHEN ot.estado='en_proceso' AND ot.pausado=0 THEN 1 ELSE 0 END) AS ots_activas,
           SUM(CASE WHEN ot.estado='en_proceso' AND ot.pausado=1 THEN 1 ELSE 0 END) AS ots_pausadas,
           SUM(CASE WHEN ot.estado='pendiente' THEN 1 ELSE 0 END) AS ots_pendientes
    FROM plano_paginas pp
    LEFT JOIN ordenes_trabajo ot ON ot.plano_pagina_id = pp.id
    WHERE pp.boom_lista_id = ? AND pp.ignorada = 0
    GROUP BY pp.id
    ORDER BY pp.numero_pagina ASC
");

$stmt_vots = $db->prepare("
    SELECT ot.*, bl.pieza_nombre, bl.plano_ruta,
           u.nombre_completo AS usuario_inicio_nombre,
           pp.nombre AS pagina_nombre,
           (SELECT timestamp FROM ot_logs WHERE ot_id=ot.id AND accion IN ('inicio','reanudacion') ORDER BY timestamp DESC LIMIT 1) AS ultimo_inicio_ts,
           (SELECT timestamp FROM ot_logs WHERE ot_id=ot.id AND accion='pausa' ORDER BY timestamp DESC LIMIT 1) AS ultimo_pausa_ts,
           (SELECT COUNT(*) FROM requisiciones r WHERE r.ot_id=ot.id) AS num_req_externas
    FROM ordenes_trabajo ot
    JOIN boom_listas bl ON bl.id = ot.boom_lista_id
    LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
    LEFT JOIN usuarios u ON u.id = ot.operario_id
    WHERE ot.proyecto_id = ? AND (pp.ignorada IS NULL OR pp.ignorada = 0)
    ORDER BY bl.id ASC, (ot.plano_pagina_id IS NULL) ASC, pp.numero_pagina ASC, ot.es_prioritaria DESC, ot.id ASC
");

foreach ($vp_raw_proyectos as $vpr) {
    $stmt_vpl->execute([$vpr['id']]);
    $planos_vp = $stmt_vpl->fetchAll();

    foreach ($planos_vp as &$vpl) {
        $stmt_vpags->execute([$vpl['id']]);
        $vpl['paginas'] = $stmt_vpags->fetchAll();
    }
    unset($vpl);

    $stmt_vots->execute([$vpr['id']]);
    $ots_vp = $stmt_vots->fetchAll();

    $vp_proyectos[] = [
        'id'        => $vpr['id'],
        'nombre'    => $vpr['nombre'],
        'planos'    => $planos_vp,
        'ots'       => $ots_vp,
        'total'     => count($ots_vp),
        'ok'        => count(array_filter($ots_vp, fn($o) => $o['estado']==='completado')),
        'activas'   => count(array_filter($ots_vp, fn($o) => $o['estado']==='en_proceso' && !$o['pausado'])),
        'pausadas'  => count(array_filter($ots_vp, fn($o) => $o['estado']==='en_proceso' && $o['pausado'])),
        'pendientes'=> count(array_filter($ots_vp, fn($o) => $o['estado']==='pendiente')),
    ];
}
?>

<style>
.vp-select-wrap { display:none; }
/* Carpeta de proyecto completa (arrastrable) */
.vp-proj-folder { transition:opacity .15s; }
.vp-proj-folder.vp-proj-dragging { opacity:.4; }
.vp-proj-folder.vp-proj-drag-over > .vp-proj-bar { border-top:3px dashed #1565c0; }
/* Barras de proyecto */
.vp-proj-bar {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    background:#fff; border:1.5px solid #e0e8f0; border-radius:12px; padding:14px 20px;
    margin-bottom:8px; cursor:pointer; transition:all .2s; box-shadow:0 1px 4px rgba(0,61,92,.05);
}
.vp-proj-bar:hover { border-color:#1565c0; box-shadow:0 3px 10px rgba(21,101,192,.12); }
.vp-proj-bar.open { border-color:#1565c0; border-bottom-left-radius:0; border-bottom-right-radius:0; border-bottom:none; }
.vp-proj-bar-left { flex:1; }
.vp-proj-bar-name { font-size:1rem; font-weight:800; color:#003d5c; margin-bottom:5px; }
.vp-proj-bar-stats { display:flex; gap:14px; flex-wrap:wrap; }
.vp-proj-bar-stat { display:flex; align-items:center; gap:5px; font-size:.74rem; font-weight:700; }
.vp-proj-bar-stat.ok      { color:#2e7d32; }
.vp-proj-bar-stat.activa  { color:#4caf50; }
.vp-proj-bar-stat.pausa   { color:#FF9800; }
.vp-proj-bar-stat.pending { color:#e65100; }
.vp-proj-bar-stat.total   { color:#888; }
.vp-proj-mini-bar { height:5px; background:#e8ecf0; border-radius:3px; margin-top:6px; width:180px; }
.vp-proj-mini-fill { height:100%; background:linear-gradient(90deg,#1565c0,#42a5f5); border-radius:3px; }
.vp-proj-body {
    display:none; background:#f4f8fc; border:1.5px solid #1565c0; border-top:none;
    border-bottom-left-radius:12px; border-bottom-right-radius:12px; margin-bottom:12px; overflow:hidden;
}
.vp-proj-body.open { display:block; }
/* Planos header dentro de proyecto */
.vp-plano-section { border-bottom:1px solid #dde8f5; }
.vp-plano-section:last-child { border-bottom:none; }
.vp-plano-header {
    display:flex; align-items:center; gap:10px; padding:10px 18px; background:#e8f0fa; cursor:pointer;
    transition:background .15s;
}
.vp-plano-header:hover { background:#dce8f5; }
.vp-plano-name { font-size:.85rem; font-weight:700; color:#003d5c; flex:1; }
.vp-plano-badges { display:flex; gap:6px; align-items:center; }
.vp-plano-ots { padding:0 0 8px; }
/* OT row */
.vp-ot-row {
    display:flex; align-items:center; gap:10px; padding:10px 18px 10px 32px; border-bottom:1px solid #e8f0fa;
    flex-wrap:wrap;
}
.vp-ot-row:last-child { border-bottom:none; }
.vp-ot-row.activa { background:rgba(76,175,80,.04); }
.vp-ot-row.pausada { background:rgba(255,152,0,.04); }
.vp-ot-row.prioritaria { background:rgba(244,67,54,.05); }
.vp-ot-row[draggable="true"] { cursor:grab; }
.vp-ot-row[draggable="true"]:active { cursor:grabbing; }
.vp-ot-row.vp-dragging { opacity:.4; }
.vp-ot-row.vp-drag-over { border-top:2px dashed #1565c0; }
.vp-ot-row-main { flex:1; min-width:0; }
.vp-ot-row-folio { font-size:.68rem; font-weight:800; color:#607d8b; font-family:monospace; margin-bottom:2px; }
.vp-ot-row-titulo { font-size:.85rem; font-weight:700; color:#003d5c; }
.vp-ot-row-meta { font-size:.72rem; color:#888; display:flex; gap:8px; flex-wrap:wrap; margin-top:2px; }
/* Timer en vivo */
.vp-live-timer {
    font-size:.8rem; font-weight:800; color:#4caf50; font-family:monospace; min-width:70px; text-align:right;
    display:flex; align-items:center; gap:4px;
}
.vp-live-dot { width:6px; height:6px; border-radius:50%; background:#4caf50; animation:liveP .8s alternate infinite; }
@keyframes liveP { from{opacity:.3} to{opacity:1} }
.vp-live-timer.paused { color:#FF9800; }
.vp-live-timer.paused .vp-live-dot { background:#FF9800; animation:none; }
/* Tiempo acumulado */
.vp-tiempo-acum { font-size:.74rem; color:#888; text-align:right; }
.vp-tiempo-reg { color:#2e7d32; font-weight:700; }
.vp-tiempo-ext { color:#e65100; font-weight:700; }
.vp-ot-pdf-btn { background:#f0f4f8; border:1px solid #d0dce8; border-radius:6px; padding:4px 8px; font-size:.72rem; color:#005686; cursor:pointer; text-decoration:none; display:flex; align-items:center; gap:4px; white-space:nowrap; }
.vp-ot-pdf-btn:hover { background:#005686; color:#fff; }
/* Status del proyecto en barra */
.vp-proj-chevron { color:#aaa; font-size:.85rem; transition:transform .2s; }
.vp-proj-bar.open .vp-proj-chevron { transform:rotate(180deg); }
/* OT timeline */
.vp-ot-list { display:flex; flex-direction:column; gap:16px; }
.vp-ot-card { background:#fff; border-radius:14px; border:1.5px solid #e0e8f0; box-shadow:0 2px 8px rgba(0,61,92,.05); overflow:hidden; }
.vp-ot-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; flex-wrap:wrap; gap:8px; }
.vp-ot-folio { font-size:.72rem; font-weight:800; color:#005686; background:#e8f4fd; padding:3px 10px; border-radius:20px; letter-spacing:.5px; }
.vp-ot-title { font-size:.92rem; font-weight:700; color:#003d5c; margin:4px 0 2px; }
.vp-ot-sub   { font-size:.75rem; color:#888; display:flex; gap:10px; flex-wrap:wrap; }
/* Flujo de pasos */
.vp-steps { display:flex; align-items:stretch; border-top:1px solid #f0f0f0; }
.vp-step  { flex:1; padding:10px 14px; display:flex; flex-direction:column; align-items:center; gap:4px; border-right:1px solid #f0f0f0; position:relative; }
.vp-step:last-child { border-right:none; }
.vp-step-icon { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.9rem; margin-bottom:2px; }
.vp-step-label { font-size:.65rem; font-weight:700; text-align:center; color:#888; text-transform:uppercase; letter-spacing:.4px; }
.vp-step-sub   { font-size:.62rem; color:#aaa; text-align:center; }
/* Estados del paso */
.vp-step.done   .vp-step-icon { background:#e8f5e9; color:#2E7D32; }
.vp-step.active .vp-step-icon { background:#fff3e0; color:#e65100; }
.vp-step.wait   .vp-step-icon { background:#f5f5f5; color:#ccc; }
/* Conector */
.vp-step::after { content:''; position:absolute; top:50%; right:-1px; width:10px; height:2px; background:#e0e0e0; z-index:1; }
.vp-step:last-child::after { display:none; }
.vp-step.done::after { background:#a5d6a7; }
/* Barra de progreso general */
.vp-prog-wrap { padding:6px 18px 12px; }
.vp-prog-bar  { height:6px; background:#e8ecf0; border-radius:3px; overflow:hidden; }
.vp-prog-fill { height:100%; background:linear-gradient(90deg,#1565c0,#42a5f5); border-radius:3px; transition:width .5s; }
.vp-prog-label { font-size:.68rem; color:#888; margin-top:4px; display:flex; justify-content:space-between; }
/* Resumen stats */
.vp-stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.vp-stat  { flex:1; min-width:100px; background:#fff; border:1.5px solid #e0e8f0; border-radius:10px; padding:10px 14px; text-align:center; }
.vp-stat-num { font-size:1.6rem; font-weight:800; color:#003d5c; }
.vp-stat-lbl { font-size:.7rem; color:#888; font-weight:600; text-transform:uppercase; }
/* Botón ver detalles */
.vp-btn-det { border:none; background:transparent; color:#005686; cursor:pointer; font-size:.75rem; font-weight:700; padding:4px 10px; border-radius:6px; transition:background .15s; display:flex; align-items:center; gap:5px; font-family:'Montserrat',sans-serif; }
.vp-btn-det:hover { background:#e8f4fd; }
/* ── DRAWER DE DETALLE ── */
.vp-drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:4000; display:none; }
.vp-drawer-overlay.open { display:block; }
.vp-drawer {
    position:fixed; top:0; right:-520px; width:520px; max-width:100vw; height:100vh;
    background:#fff; box-shadow:-4px 0 24px rgba(0,0,0,.15); z-index:4001;
    display:flex; flex-direction:column; transition:right .3s ease;
    font-family:'Montserrat',sans-serif;
}
.vp-drawer.open { right:0; }
.vp-drawer-header {
    background:linear-gradient(135deg,#003d5c,#005686);
    color:#fff; padding:18px 20px; display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
}
.vp-drawer-folio { font-size:.7rem; font-weight:800; letter-spacing:.6px; color:rgba(255,255,255,.7); margin-bottom:4px; }
.vp-drawer-title { font-size:1rem; font-weight:800; line-height:1.3; }
.vp-drawer-close { background:rgba(255,255,255,.15); border:none; color:#fff; width:32px; height:32px; border-radius:50%; cursor:pointer; font-size:1.1rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .15s; }
.vp-drawer-close:hover { background:rgba(255,255,255,.3); }
.vp-drawer-body { flex:1; overflow-y:auto; padding:20px; }
/* Tarjetas de resumen dentro del drawer */
.vp-d-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:18px; }
.vp-d-card { background:#f4f8fc; border-radius:10px; padding:12px 14px; }
.vp-d-card.verde  { background:#e8f5e9; }
.vp-d-card.naranja{ background:#fff3e0; }
.vp-d-card.azul   { background:#e3f2fd; }
.vp-d-lbl  { font-size:.65rem; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
.vp-d-val  { font-size:1.1rem; font-weight:800; color:#003d5c; }
.vp-d-card.verde  .vp-d-val  { color:#2e7d32; }
.vp-d-card.naranja .vp-d-val { color:#e65100; }
.vp-d-card.azul   .vp-d-val  { color:#1565c0; }
/* Info row */
.vp-d-info { display:flex; flex-direction:column; gap:7px; margin-bottom:18px; background:#f8faff; border-radius:10px; padding:12px 14px; }
.vp-d-row  { display:flex; gap:8px; align-items:baseline; font-size:.8rem; }
.vp-d-row-lbl { color:#aaa; font-weight:600; width:100px; flex-shrink:0; font-size:.72rem; text-transform:uppercase; }
.vp-d-row-val { color:#2c3e50; font-weight:600; }
/* Timeline de logs */
.vp-d-section-title { font-size:.72rem; font-weight:800; color:#003d5c; text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.vp-tl { display:flex; flex-direction:column; gap:0; position:relative; }
.vp-tl::before { content:''; position:absolute; left:17px; top:0; bottom:0; width:2px; background:#e0e8f0; }
.vp-tl-item { display:flex; gap:14px; position:relative; padding-bottom:14px; }
.vp-tl-item:last-child { padding-bottom:0; }
.vp-tl-dot { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.8rem; flex-shrink:0; position:relative; z-index:1; border:2px solid #fff; }
.vp-tl-dot.inicio      { background:#4caf50; color:#fff; }
.vp-tl-dot.reanudacion { background:#2196f3; color:#fff; }
.vp-tl-dot.pausa       { background:#ff9800; color:#fff; }
.vp-tl-dot.fin         { background:#e91e63; color:#fff; }
.vp-tl-content { flex:1; background:#f8faff; border-radius:10px; padding:10px 12px; border:1px solid #e8eef5; }
.vp-tl-head    { display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; flex-wrap:wrap; gap:4px; }
.vp-tl-accion  { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px; }
.vp-tl-accion.inicio      { color:#2e7d32; }
.vp-tl-accion.reanudacion { color:#1565c0; }
.vp-tl-accion.pausa       { color:#e65100; }
.vp-tl-accion.fin         { color:#ad1457; }
.vp-tl-ts  { font-size:.65rem; color:#aaa; font-weight:600; }
.vp-tl-row { font-size:.75rem; color:#555; margin-top:3px; display:flex; gap:6px; }
.vp-tl-row b { color:#003d5c; }
.vp-tl-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
.vp-tl-chip { font-size:.67rem; font-weight:700; padding:2px 8px; border-radius:5px; }
.vp-tl-chip.reg  { background:#e8f5e9; color:#2e7d32; }
.vp-tl-chip.ext  { background:#fff3e0; color:#e65100; }
.vp-tl-chip.pzas { background:#e3f2fd; color:#1565c0; }
/* Spinner */
.vp-d-spin { text-align:center; padding:40px; color:#aaa; }
/* ── Páginas dentro de plano ── */
.vp-pag-section { border-bottom:1px solid #dde8f5; }
.vp-pag-section:last-child { border-bottom:none; }
.vp-pag-header {
    display:flex; align-items:center; gap:10px; padding:8px 18px 8px 28px;
    background:#f0f5fc; cursor:pointer; transition:background .15s; flex-wrap:wrap;
}
.vp-pag-header:hover { background:#e5eef8; }
.vp-pag-name { font-size:.82rem; font-weight:700; color:#1565c0; flex:1; }
.vp-pag-prog-wrap { display:flex; align-items:center; gap:8px; }
.vp-pag-prog-bar { width:100px; height:5px; background:#d0dcea; border-radius:4px; overflow:hidden; }
.vp-pag-prog-fill { height:100%; border-radius:4px; transition:width .4s; background:linear-gradient(90deg,#1565c0,#42a5f5); }
.vp-pag-prog-fill.done { background:#2e7d32; }
.vp-pag-stat { font-size:.68rem; font-weight:700; color:#607d8b; white-space:nowrap; }
.vp-pag-stat.all-done { color:#2e7d32; }
/* Operario chip en OT row */
.vp-op-chip { display:inline-flex; align-items:center; gap:4px; background:#e3f2fd; border-radius:6px; padding:2px 7px; font-size:.72rem; font-weight:700; color:#1565c0; }
/* Expandable OT detail inline */
.vp-ot-detail { display:none; background:#f4f8ff; border-top:1px dashed #d0dcea; padding:10px 18px 10px 46px; flex-wrap:wrap; gap:14px; align-items:flex-start; }
.vp-ot-detail.open { display:flex; }
.vp-ot-ditem { display:flex; flex-direction:column; gap:2px; min-width:110px; }
.vp-ot-dlbl { font-size:.62rem; color:#aaa; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
.vp-ot-dval { font-size:.8rem; color:#003d5c; font-weight:700; }
.vp-ot-det-btn { background:none; border:none; cursor:pointer; padding:2px 5px; color:#90a4ae; font-size:.78rem; transition:color .15s; }
.vp-ot-det-btn:hover,.vp-ot-det-btn.active { color:#1565c0; }
/* ── Buscador de inventario: Requisición Externa ── */
.btn-agregar-mat { background:#e65100; color:#fff; border:none; border-radius:7px; padding:7px 16px; font-size:.82rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; }
.btn-agregar-mat:hover { background:#bf360c; }
.prd-btn-cancel { background:#f5f5f5; color:#555; border:none; border-radius:7px; padding:8px 18px; font-size:.84rem; font-weight:600; cursor:pointer; }
.prd-btn-cancel:hover { background:#e0e0e0; }
.prd-btn-primary { color:#fff; border:none; border-radius:7px; padding:8px 20px; font-size:.84rem; font-weight:700; cursor:pointer; }
.inv-box { width:540px; }
.inv-search-bar { display:flex; gap:8px; padding:14px 20px; border-bottom:1px solid #eee; background:#fafafa; }
.inv-search-bar input { flex:1; border:1.5px solid #dde2e8; border-radius:7px; padding:8px 12px; font-size:.88rem; outline:none; }
.inv-search-bar input:focus { border-color:#e65100; }
.inv-search-bar button { background:#e65100; color:#fff; border:none; border-radius:7px; padding:8px 14px; cursor:pointer; font-size:.85rem; white-space:nowrap; }
.inv-search-bar button:hover { background:#bf360c; }
.inv-results { max-height:220px; overflow-y:auto; padding:8px 14px; }
.inv-result-item { display:flex; flex-direction:column; padding:10px 12px; border-radius:8px; cursor:pointer; border:2px solid transparent; margin-bottom:4px; transition:background .12s,border-color .12s; }
.inv-result-item:hover { background:#fff3e0; }
.inv-result-item.selected { border-color:#e65100; background:#fff8f2; }
.inv-item-top { display:flex; gap:8px; align-items:baseline; flex-wrap:wrap; }
.inv-codigo { font-size:.7rem; font-weight:700; color:#e65100; background:#fff3e0; border-radius:4px; padding:1px 6px; white-space:nowrap; }
.inv-desc { font-size:.85rem; font-weight:600; color:#222; }
.inv-item-meta { font-size:.73rem; color:#888; margin-top:2px; }
.inv-stock-ok { color:#2e7d32; font-weight:700; }
.inv-stock-low { color:#c62828; font-weight:700; }
.inv-empty { text-align:center; padding:24px; color:#bbb; font-size:.83rem; }
.inv-qty-row { display:flex; gap:16px; align-items:flex-end; padding:12px 20px; background:#f8f9fa; border-top:1px solid #eee; }
.inv-qty-field { display:flex; flex-direction:column; gap:4px; }
.inv-qty-field label { font-size:.74rem; font-weight:700; color:#555; text-transform:uppercase; }
.inv-qty-field input { border:1.5px solid #dde2e8; border-radius:7px; padding:8px 10px; font-size:.9rem; width:90px; outline:none; }
.inv-qty-field input:focus { border-color:#e65100; }
.inv-qty-field input.inp-error { border-color:#e53935; }
.inv-unidad-val { font-size:.88rem; font-weight:700; color:#444; padding-bottom:9px; }
.inv-selected-label { flex:1; font-size:.78rem; color:#555; padding-bottom:9px; font-style:italic; }
</style>

<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-stream"></i> Seguimiento de Órdenes de Trabajo</h2>
            <small style="color:#888;font-size:.75rem;"><i class="fas fa-circle" style="color:#4caf50;"></i> Tiempo en vivo</small>
        </div>
    </div>

    <?php if (empty($vp_proyectos)): ?>
    <div class="empty-state"><i class="fas fa-stream"></i><p>No hay proyectos con órdenes de trabajo.</p></div>
    <?php else: ?>

    <!-- Estados globales -->
    <?php
    $vp_total_ots = array_sum(array_column($vp_proyectos, 'total'));
    $vp_total_ok  = array_sum(array_column($vp_proyectos, 'ok'));
    $vp_total_act = array_sum(array_column($vp_proyectos, 'activas'));
    $vp_total_pau = array_sum(array_column($vp_proyectos, 'pausadas'));
    $vp_total_pen = array_sum(array_column($vp_proyectos, 'pendientes'));
    $vp_pct_global = $vp_total_ots > 0 ? round($vp_total_ok / $vp_total_ots * 100) : 0;
    ?>
    <div class="vp-stats" style="margin-bottom:16px;">
        <div class="vp-stat"><div class="vp-stat-num"><?= count($vp_proyectos) ?></div><div class="vp-stat-lbl">Proyectos</div></div>
        <div class="vp-stat"><div class="vp-stat-num"><?= $vp_total_ots ?></div><div class="vp-stat-lbl">OTs Total</div></div>
        <div class="vp-stat" style="border-color:#a5d6a7;"><div class="vp-stat-num" style="color:#2e7d32;"><?= $vp_total_ok ?></div><div class="vp-stat-lbl">Completadas</div></div>
        <div class="vp-stat" style="border-color:#a5d6a7;"><div class="vp-stat-num" style="color:#4caf50;"><?= $vp_total_act ?></div><div class="vp-stat-lbl">Activas</div></div>
        <div class="vp-stat" style="border-color:#ffcc80;"><div class="vp-stat-num" style="color:#e65100;"><?= $vp_total_pau ?></div><div class="vp-stat-lbl">Pausadas</div></div>
        <div class="vp-stat" style="border-color:#b39ddb;"><div class="vp-stat-num" style="color:#5e35b1;"><?= $vp_pct_global ?>%</div><div class="vp-stat-lbl">Avance</div></div>
    </div>
    <div style="margin-bottom:20px;">
        <div class="vp-prog-bar"><div class="vp-prog-fill" style="width:<?= $vp_pct_global ?>%;"></div></div>
        <div class="vp-prog-label"><span>Avance global de manufactura</span><span><?= $vp_total_ok ?>/<?= $vp_total_ots ?> OTs</span></div>
    </div>

    <!-- Barras de proyecto (accordion) -->
    <?php foreach ($vp_proyectos as $vproj):
        $pct_proj = $vproj['total'] > 0 ? round($vproj['ok'] / $vproj['total'] * 100) : 0;
        // Agrupar OTs por página y sin-página dentro de cada plano
        $ots_por_pagina = [];   // [boom_lista_id][pagina_id] => [...ots]
        $ots_sin_pagina = [];   // [boom_lista_id] => [...ots]
        foreach ($vproj['ots'] as $vot) {
            $bid = $vot['boom_lista_id'];
            $pid = $vot['plano_pagina_id'] ?? null;
            if ($pid) {
                $ots_por_pagina[$bid][$pid][] = $vot;
            } else {
                $ots_sin_pagina[$bid][] = $vot;
            }
        }
        $vproj_tiene_problema = !empty(array_filter($vproj['ots'], fn($o) => !empty($o['en_problema'])));
    ?>
    <div class="vp-proj-folder" data-proyecto-id="<?= $vproj['id'] ?>" draggable="true">
    <div class="vp-proj-bar" id="vpbar_<?= $vproj['id'] ?>" onclick="toggleVPProject(<?= $vproj['id'] ?>)">
        <i class="fas fa-grip-vertical" style="color:#b0c4d8;font-size:.85rem;cursor:grab;margin-right:4px;" title="Arrastra para reordenar el proyecto" onclick="event.stopPropagation()"></i>
        <div class="vp-proj-bar-left">
            <div class="vp-proj-bar-name">
                <i class="fas fa-folder" style="color:#1565c0;margin-right:6px;"></i><?= htmlspecialchars($vproj['nombre']) ?>
                <?php if ($vproj_tiene_problema): ?>
                <i class="fas fa-exclamation-triangle" style="color:#f44336;margin-left:6px;" title="Hay una OT con problema en este proyecto"></i>
                <?php endif; ?>
            </div>
            <div class="vp-proj-bar-stats">
                <span class="vp-proj-bar-stat total"><i class="fas fa-tasks"></i> <?= $vproj['total'] ?> OTs</span>
                <span class="vp-proj-bar-stat ok"><i class="fas fa-check-circle"></i> <?= $vproj['ok'] ?> completadas</span>
                <?php if ($vproj['activas'] > 0): ?><span class="vp-proj-bar-stat activa"><i class="fas fa-play-circle"></i> <?= $vproj['activas'] ?> activa(s)</span><?php endif; ?>
                <?php if ($vproj['pausadas'] > 0): ?><span class="vp-proj-bar-stat pausa"><i class="fas fa-pause-circle"></i> <?= $vproj['pausadas'] ?> en pausa</span><?php endif; ?>
                <?php if ($vproj['pendientes'] > 0): ?><span class="vp-proj-bar-stat pending"><i class="fas fa-clock"></i> <?= $vproj['pendientes'] ?> pendientes</span><?php endif; ?>
            </div>
            <div class="vp-proj-mini-bar"><div class="vp-proj-mini-fill" style="width:<?= $pct_proj ?>%;"></div></div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:1rem;font-weight:800;color:#1565c0;"><?= $pct_proj ?>%</span>
            <button class="vp-btn-det" onclick="event.stopPropagation();verDetalleAgregado('proyecto', <?= $vproj['id'] ?>)" title="Historial completo del proyecto"><i class="fas fa-eye"></i></button>
            <i class="fas fa-chevron-down vp-proj-chevron" id="vpchevron_<?= $vproj['id'] ?>"></i>
        </div>
    </div>

    <div class="vp-proj-body" id="vpbody_<?= $vproj['id'] ?>">
        <?php foreach ($vproj['planos'] as $vpl):
            $pl_ots_all = $vproj['ots'];
            $pl_ok  = intval($vpl['ots_ok']);
            $pl_tot = intval($vpl['total_ots']);
            $pl_act = intval($vpl['ots_activas']);
            $paginas_vpl = $vpl['paginas'] ?? [];
            $pl_tiene_problema = false;
            foreach ($vproj['ots'] as $vot_chk) {
                if ($vot_chk['boom_lista_id'] == $vpl['id'] && !empty($vot_chk['en_problema'])) { $pl_tiene_problema = true; break; }
            }
        ?>
        <div class="vp-plano-section">
            <div class="vp-plano-header" onclick="toggleVPPlano(<?= $vpl['id'] ?>)">
                <i class="fas fa-drafting-compass" style="color:#1565c0;"></i>
                <span class="vp-plano-name"><?= htmlspecialchars($vpl['pieza_nombre']) ?></span>
                <?php if ($pl_tiene_problema): ?>
                <i class="fas fa-exclamation-triangle" style="color:#f44336;" title="Hay una OT con problema en este plano"></i>
                <?php endif; ?>
                <div class="vp-plano-badges">
                    <?php if ($pl_act > 0): ?><span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;"><i class="fas fa-play-circle"></i> <?= $pl_act ?> activa(s)</span><?php endif; ?>
                    <span style="background:#f0f4f8;color:#607d8b;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;"><?= $pl_ok ?>/<?= $pl_tot ?></span>
                    <?php if ($vpl['plano_ruta']): ?><a href="uploads/<?= htmlspecialchars($vpl['plano_ruta']) ?>" target="_blank" class="vp-ot-pdf-btn" onclick="event.stopPropagation()"><i class="fas fa-file-pdf"></i> Plano</a><?php endif; ?>
                    <button class="vp-btn-det" onclick="event.stopPropagation();verDetalleAgregado('plano', <?= $vpl['id'] ?>)" title="Historial completo del plano"><i class="fas fa-eye"></i></button>
                    <i class="fas fa-chevron-down" id="vplchev_<?= $vpl['id'] ?>" style="color:#aaa;font-size:.75rem;transition:transform .2s;"></i>
                </div>
            </div>
            <div id="vpl_ots_<?= $vpl['id'] ?>" style="display:none;">

            <?php if (empty($paginas_vpl) && empty($ots_sin_pagina[$vpl['id']])): ?>
            <div style="padding:12px 32px;font-size:.78rem;color:#bbb;"><i class="fas fa-tasks"></i> Sin órdenes de trabajo asignadas.</div>
            <?php endif; ?>

            <?php foreach ($paginas_vpl as $vpag):
                $pag_ots   = $ots_por_pagina[$vpl['id']][$vpag['id']] ?? [];
                $pag_ok    = intval($vpag['ots_ok']);
                $pag_tot   = intval($vpag['total_ots']);
                $pag_act   = intval($vpag['ots_activas']);
                $pag_pau   = intval($vpag['ots_pausadas']);
                $pag_pct   = $pag_tot > 0 ? round($pag_ok / $pag_tot * 100) : 0;
                $pag_done  = ($pag_tot > 0 && $pag_ok === $pag_tot);
                $pag_color = $pag_done ? '#2e7d32' : ($pag_act > 0 ? '#1565c0' : ($pag_pau > 0 ? '#FF9800' : '#607d8b'));
                $pag_tiene_problema = false;
                foreach ($pag_ots as $vot_chk) {
                    if (!empty($vot_chk['en_problema'])) { $pag_tiene_problema = true; break; }
                }
            ?>
            <div class="vp-pag-section">
                <div class="vp-pag-header" onclick="toggleVPPage(<?= $vpag['id'] ?>)">
                    <i class="fas fa-file-alt" style="color:<?= $pag_color ?>;font-size:.8rem;"></i>
                    <span class="vp-pag-name"><?= htmlspecialchars($vpag['nombre']) ?></span>
                    <?php if ($pag_tiene_problema): ?>
                    <i class="fas fa-exclamation-triangle" style="color:#f44336;" title="Hay una OT con problema en esta página"></i>
                    <?php endif; ?>
                    <div class="vp-pag-prog-wrap">
                        <div class="vp-pag-prog-bar">
                            <div class="vp-pag-prog-fill <?= $pag_done?'done':'' ?>" style="width:<?= $pag_pct ?>%;"></div>
                        </div>
                        <span class="vp-pag-stat <?= $pag_done?'all-done':'' ?>"><?= $pag_ok ?>/<?= $pag_tot ?> OTs</span>
                        <?php if ($pag_act > 0): ?><span style="color:#4caf50;font-size:.67rem;font-weight:700;"><i class="fas fa-play-circle"></i> <?= $pag_act ?></span><?php endif; ?>
                        <?php if ($pag_pau > 0): ?><span style="color:#FF9800;font-size:.67rem;font-weight:700;"><i class="fas fa-pause-circle"></i> <?= $pag_pau ?></span><?php endif; ?>
                    </div>
                    <button class="vp-btn-det" onclick="event.stopPropagation();verDetalleAgregado('pagina', <?= $vpag['id'] ?>)" title="Historial completo de la página"><i class="fas fa-eye"></i></button>
                    <i class="fas fa-chevron-down" id="vpagchev_<?= $vpag['id'] ?>" style="color:#aaa;font-size:.7rem;transition:transform .2s;margin-left:4px;"></i>
                </div>
                <div id="vpag_ots_<?= $vpag['id'] ?>" style="display:none;">
                <div class="vp-plano-ots">
                <?php if (empty($pag_ots)): ?>
                <div style="padding:10px 46px;font-size:.76rem;color:#bbb;"><i class="fas fa-tasks"></i> Sin OTs en esta página.</div>
                <?php else: ?>
                <?php foreach ($pag_ots as $vot): ?>
                <?php
                    $is_activa  = ($vot['estado']==='en_proceso' && !$vot['pausado']);
                    $is_pausada = ($vot['estado']==='en_proceso' && $vot['pausado']);
                    $is_prio    = (bool)($vot['es_prioritaria'] ?? 0);
                    $row_class  = $is_activa ? 'activa' : ($is_pausada ? 'pausada' : ($is_prio ? 'prioritaria' : ''));
                    $est_map    = ['pendiente'=>['#e65100','Pendiente'],'en_proceso'=>['#1565c0','En proceso'],'completado'=>['#2e7d32','Completado'],'cancelado'=>['#9e9e9e','Cancelado']];
                    [$est_col, $est_lbl] = $est_map[$vot['estado']] ?? ['#999','—'];
                    $reg_min = intval($vot['tiempo_regular_min']);
                    $ext_min = intval($vot['tiempo_extra_min']);
                    $reg_h = $reg_min ? floor($reg_min/60).'h '.($reg_min%60).'m' : '0m';
                    $ext_h = $ext_min ? floor($ext_min/60).'h '.($ext_min%60).'m' : '0m';
                    $ts_inicio_raw  = $vot['ultimo_inicio_ts'] ?? $vot['fecha_inicio_real'] ?? null;
                    $ts_inicio_unix = $ts_inicio_raw ? strtotime($ts_inicio_raw) : 0;
                    $ts_pausa_raw   = $vot['ultimo_pausa_ts'] ?? null;
                    $ts_pausa_unix  = $ts_pausa_raw ? strtotime($ts_pausa_raw) : 0;
                ?>
                <div class="vp-ot-row <?= $row_class ?>" data-ot-id="<?= $vot['id'] ?>" draggable="<?= $vot['estado'] === 'pendiente' ? 'true' : 'false' ?>">
                    <?php if ($vot['estado'] === 'pendiente'): ?>
                    <i class="fas fa-grip-vertical" style="color:#ccc;font-size:.8rem;flex-shrink:0;cursor:grab;" title="Arrastra para reordenar"></i>
                    <?php endif; ?>
                    <div style="width:4px;min-height:44px;border-radius:2px;flex-shrink:0;align-self:stretch;background:<?= $is_activa?'#4caf50':($is_pausada?'#FF9800':($vot['estado']==='completado'?'#2e7d32':'#e0e0e0')) ?>;"></div>
                    <div class="vp-ot-row-main" style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span class="vp-ot-row-folio"><?= htmlspecialchars($vot['folio'] ?? 'OT-'.$vot['id']) ?></span>
                            <?php if ($is_prio): ?><span style="color:#f44336;font-size:.65rem;font-weight:800;"><i class="fas fa-bolt"></i> PRIO</span><?php endif; ?>
                            <span style="color:<?= $est_col ?>;font-size:.72rem;font-weight:700;"><?= $est_lbl ?></span>
                            <?php if ($vot['operador_nombre']): ?>
                            <span class="vp-op-chip"><i class="fas fa-user-hard-hat"></i><?= htmlspecialchars($vot['operador_nombre']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="vp-ot-row-titulo"><?= htmlspecialchars($vot['titulo']) ?></div>
                        <div class="vp-ot-row-meta">
                            <?php if ($vot['maquina']): ?><span><i class="fas fa-cog"></i> <?= htmlspecialchars($vot['maquina']) ?></span><?php endif; ?>
                            <?php if ($vot['proceso']): ?><span><i class="fas fa-sliders-h"></i> <?= htmlspecialchars($vot['proceso']) ?></span><?php endif; ?>
                            <?php if ($vot['en_stand_by']): ?><span style="color:#607d8b;font-weight:700;"><i class="fas fa-hourglass-half"></i> Stand-by</span><?php endif; ?>
                            <?php if (!empty($vot['en_problema'])): ?><span style="color:#f44336;font-weight:800;"><i class="fas fa-exclamation-triangle"></i> PROBLEMA</span><?php endif; ?>
                            <?php if (!empty($vot['requisicion_externa_id'])): ?>
                            <a href="ver_requisicion.php?id=<?= $vot['requisicion_externa_id'] ?>" target="_blank" style="font-size:.65rem;background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:10px;font-weight:700;text-decoration:none;" title="Ver la más reciente">
                                <i class="fas fa-file-alt"></i> REQ. EXTERNA<?= intval($vot['num_req_externas'] ?? 0) > 1 ? ' ×' . intval($vot['num_req_externas']) : '' ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($vot['en_problema']) && !empty($vot['problema_descripcion'])): ?>
                        <div style="font-size:.72rem;color:#b71c1c;margin-top:3px;"><i class="fas fa-info-circle"></i> <?= htmlspecialchars($vot['problema_descripcion']) ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Tiempo -->
                    <div style="text-align:right;flex-shrink:0;">
                        <div class="vp-tiempo-acum">
                            <?php if ($is_activa): ?>
                            <span class="vp-tiempo-reg" style="font-size:.67rem;opacity:.7;">Acum: <?= $reg_h ?></span>
                            <?php else: ?>
                            <span class="vp-tiempo-reg"><i class="fas fa-clock"></i> <?= $reg_h ?></span>
                            <?php if ($ext_min): ?> · <span class="vp-tiempo-ext"><i class="fas fa-star"></i> <?= $ext_h ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_activa && $ts_inicio_unix > 0): ?>
                        <div class="vp-live-timer" id="timer_<?= $vot['id'] ?>" data-ts="<?= $ts_inicio_unix ?>" data-acum="<?= $reg_min * 60 ?>">
                            <div class="vp-live-dot"></div><span>00:00:00</span>
                        </div>
                        <?php elseif ($is_activa): ?>
                        <div class="vp-live-timer"><div class="vp-live-dot"></div><span>En proceso</span></div>
                        <?php elseif ($is_pausada): ?>
                        <div class="vp-live-timer paused" id="ptimer_<?= $vot['id'] ?>"
                             data-ts="<?= $ts_pausa_unix ?>"
                             data-acum="<?= $reg_min * 60 ?>">
                            <div class="vp-live-dot"></div>
                            <span>En pausa<?= $reg_min ? ' · '.$reg_h : '' ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- Acciones -->
                    <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:2px;">
                        <button class="vp-ot-det-btn" id="detbtn_<?= $vot['id'] ?>" onclick="toggleOTDetail(<?= $vot['id'] ?>)" title="Ver detalle"><i class="fas fa-chevron-down"></i></button>
                        <?php if ($vot['plano_ruta']): ?>
                        <a href="uploads/<?= htmlspecialchars($vot['plano_ruta']) ?>" target="_blank" class="vp-ot-pdf-btn"><i class="fas fa-file-pdf"></i></a>
                        <?php endif; ?>
                        <?php if (!empty($vot['en_problema'])): ?>
                        <form method="post" action="produccion.php?seccion=ver_proceso" style="display:inline;" onsubmit="return confirm('¿Resolver este problema?');">
                            <input type="hidden" name="accion" value="resolver_problema">
                            <input type="hidden" name="ot_id" value="<?= $vot['id'] ?>">
                            <button type="submit" class="vp-btn-det" style="color:#2e7d32;" title="Resolver"><i class="fas fa-check-circle"></i></button>
                        </form>
                        <?php else: ?>
                        <?php if ($vot['estado'] !== 'completado'): ?>
                        <button type="button" class="vp-btn-det" style="color:#1565c0;" title="Generar otra Req. Externa" onclick="abrirModalReqExt(<?= $vot['id'] ?>)"><i class="fas fa-file-alt"></i></button>
                        <?php endif; ?>
                        <?php endif; ?>
                        <button class="vp-btn-det" onclick="verDetalleOT(<?= $vot['id'] ?>)" title="Historial completo"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <!-- Sub-detalle expandible de la OT -->
                <div class="vp-ot-detail" id="otdet_<?= $vot['id'] ?>">
                    <?php if ($vot['operador_nombre']): ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">Operario asignado</span>
                        <span class="vp-ot-dval"><i class="fas fa-user-hard-hat" style="color:#1565c0;"></i> <?= htmlspecialchars($vot['operador_nombre']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($vot['maquina']): ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">Máquina</span>
                        <span class="vp-ot-dval"><?= htmlspecialchars($vot['maquina']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($vot['proceso']): ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">Proceso</span>
                        <span class="vp-ot-dval"><?= htmlspecialchars($vot['proceso']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">T. Regular</span>
                        <span class="vp-ot-dval" style="color:#1565c0;"><?= $reg_h ?></span>
                    </div>
                    <?php if ($ext_min): ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">T. Extra</span>
                        <span class="vp-ot-dval" style="color:#e65100;"><?= $ext_h ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($vot['fecha_inicio_real']): ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">Inicio real</span>
                        <span class="vp-ot-dval"><?= date('d/m H:i', strtotime($vot['fecha_inicio_real'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($vot['fecha_compromiso']): ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">Compromiso</span>
                        <span class="vp-ot-dval"><?= date('d/m/y', strtotime($vot['fecha_compromiso'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="vp-ot-ditem">
                        <span class="vp-ot-dlbl">Folio</span>
                        <span class="vp-ot-dval"><?= htmlspecialchars($vot['folio'] ?? 'OT-'.$vot['id']) ?></span>
                    </div>
                    <div style="align-self:center;margin-left:auto;">
                        <button class="vp-btn-det" onclick="verDetalleOT(<?= $vot['id'] ?>)" style="color:#1565c0;font-size:.75rem;"><i class="fas fa-history"></i> Historial</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($ots_sin_pagina[$vpl['id']])): ?>
            <?php $sp_tiene_problema = !empty(array_filter($ots_sin_pagina[$vpl['id']], fn($o) => !empty($o['en_problema']))); ?>
            <div class="vp-pag-section">
                <div class="vp-pag-header" onclick="toggleVPPage('sp_<?= $vpl['id'] ?>')">
                    <i class="fas fa-layer-group" style="color:#90a4ae;font-size:.8rem;"></i>
                    <span class="vp-pag-name" style="color:#607d8b;">Sin página asignada</span>
                    <?php if ($sp_tiene_problema): ?>
                    <i class="fas fa-exclamation-triangle" style="color:#f44336;" title="Hay una OT con problema aquí"></i>
                    <?php endif; ?>
                    <span class="vp-pag-stat"><?= count($ots_sin_pagina[$vpl['id']]) ?> OT(s)</span>
                    <i class="fas fa-chevron-down" id="vpagchev_sp_<?= $vpl['id'] ?>" style="color:#aaa;font-size:.7rem;transition:transform .2s;margin-left:4px;"></i>
                </div>
                <div id="vpag_ots_sp_<?= $vpl['id'] ?>" style="display:none;">
                <div class="vp-plano-ots">
                <?php foreach ($ots_sin_pagina[$vpl['id']] as $vot):
                    $is_activa  = ($vot['estado']==='en_proceso' && !$vot['pausado']);
                    $is_pausada = ($vot['estado']==='en_proceso' && $vot['pausado']);
                    $is_prio    = (bool)($vot['es_prioritaria'] ?? 0);
                    $row_class  = $is_activa ? 'activa' : ($is_pausada ? 'pausada' : ($is_prio ? 'prioritaria' : ''));
                    $est_map    = ['pendiente'=>['#e65100','Pendiente'],'en_proceso'=>['#1565c0','En proceso'],'completado'=>['#2e7d32','Completado'],'cancelado'=>['#9e9e9e','Cancelado']];
                    [$est_col, $est_lbl] = $est_map[$vot['estado']] ?? ['#999','—'];
                    $reg_min = intval($vot['tiempo_regular_min']);
                    $ext_min = intval($vot['tiempo_extra_min']);
                    $reg_h = $reg_min ? floor($reg_min/60).'h '.($reg_min%60).'m' : '0m';
                    $ext_h = $ext_min ? floor($ext_min/60).'h '.($ext_min%60).'m' : '0m';
                    $ts_inicio_raw  = $vot['ultimo_inicio_ts'] ?? $vot['fecha_inicio_real'] ?? null;
                    $ts_inicio_unix = $ts_inicio_raw ? strtotime($ts_inicio_raw) : 0;
                    $ts_pausa_raw   = $vot['ultimo_pausa_ts'] ?? null;
                    $ts_pausa_unix  = $ts_pausa_raw ? strtotime($ts_pausa_raw) : 0;
                ?>
                <div class="vp-ot-row <?= $row_class ?>" data-ot-id="<?= $vot['id'] ?>" draggable="<?= $vot['estado'] === 'pendiente' ? 'true' : 'false' ?>">
                    <?php if ($vot['estado'] === 'pendiente'): ?>
                    <i class="fas fa-grip-vertical" style="color:#ccc;font-size:.8rem;flex-shrink:0;cursor:grab;" title="Arrastra para reordenar"></i>
                    <?php endif; ?>
                    <div style="width:4px;min-height:44px;border-radius:2px;flex-shrink:0;align-self:stretch;background:<?= $is_activa?'#4caf50':($is_pausada?'#FF9800':($vot['estado']==='completado'?'#2e7d32':'#e0e0e0')) ?>;"></div>
                    <div class="vp-ot-row-main" style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span class="vp-ot-row-folio"><?= htmlspecialchars($vot['folio'] ?? 'OT-'.$vot['id']) ?></span>
                            <?php if ($is_prio): ?><span style="color:#f44336;font-size:.65rem;font-weight:800;"><i class="fas fa-bolt"></i> PRIO</span><?php endif; ?>
                            <span style="color:<?= $est_col ?>;font-size:.72rem;font-weight:700;"><?= $est_lbl ?></span>
                            <?php if ($vot['operador_nombre']): ?><span class="vp-op-chip"><i class="fas fa-user-hard-hat"></i><?= htmlspecialchars($vot['operador_nombre']) ?></span><?php endif; ?>
                        </div>
                        <div class="vp-ot-row-titulo"><?= htmlspecialchars($vot['titulo']) ?></div>
                        <div class="vp-ot-row-meta">
                            <?php if ($vot['maquina']): ?><span><i class="fas fa-cog"></i> <?= htmlspecialchars($vot['maquina']) ?></span><?php endif; ?>
                            <?php if ($vot['proceso']): ?><span><i class="fas fa-sliders-h"></i> <?= htmlspecialchars($vot['proceso']) ?></span><?php endif; ?>
                            <?php if ($vot['en_stand_by']): ?><span style="color:#607d8b;font-weight:700;"><i class="fas fa-hourglass-half"></i> Stand-by</span><?php endif; ?>
                            <?php if (!empty($vot['en_problema'])): ?><span style="color:#f44336;font-weight:800;"><i class="fas fa-exclamation-triangle"></i> PROBLEMA</span><?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <?php if ($is_activa && $ts_inicio_unix > 0): ?>
                        <div class="vp-live-timer" id="timer_<?= $vot['id'] ?>" data-ts="<?= $ts_inicio_unix ?>" data-acum="<?= $reg_min * 60 ?>">
                            <div class="vp-live-dot"></div><span>00:00:00</span>
                        </div>
                        <?php elseif ($is_pausada): ?>
                        <div class="vp-live-timer paused"><div class="vp-live-dot"></div><span>En pausa<?= $reg_min ? ' · '.$reg_h : '' ?></span></div>
                        <?php else: ?>
                        <span class="vp-tiempo-reg" style="font-size:.72rem;"><i class="fas fa-clock"></i> <?= $reg_h ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:2px;">
                        <button class="vp-btn-det" onclick="verDetalleOT(<?= $vot['id'] ?>)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- /vpl_ots -->
        </div><!-- /vp-plano-section -->
        <?php endforeach; ?>
    </div>
    </div><!-- /vp-proj-folder -->
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ══════════ DRAWER DETALLE OT ══════════ -->
<div class="vp-drawer-overlay" id="vpOverlay" onclick="cerrarDetalleOT()"></div>
<div class="vp-drawer" id="vpDrawer">
    <div class="vp-drawer-header">
        <div>
            <div class="vp-drawer-folio" id="vpDFolio"></div>
            <div class="vp-drawer-title" id="vpDTitle"></div>
        </div>
        <button class="vp-drawer-close" onclick="cerrarDetalleOT()">&times;</button>
    </div>
    <div class="vp-drawer-body" id="vpDrawerBody">
        <div class="vp-d-spin"><i class="fas fa-spinner fa-spin"></i></div>
    </div>
</div>

<script>
function fmtMin(m) {
    if (!m) return '0 min';
    if (m < 60) return m + ' min';
    return Math.floor(m / 60) + 'h ' + (m % 60) + 'min';
}
function fmtTs(ts) {
    if (!ts) return '—';
    const d = new Date(ts.replace(' ', 'T'));
    return d.toLocaleString('es-MX', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
}
function esc(s) {
    const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
}

async function verDetalleOT(id) {
    document.getElementById('vpOverlay').classList.add('open');
    document.getElementById('vpDrawer').classList.add('open');
    document.getElementById('vpDrawerBody').innerHTML = '<div class="vp-d-spin"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
    document.getElementById('vpDFolio').textContent = '';
    document.getElementById('vpDTitle').textContent = 'Cargando...';

    try {
        const r = await fetch(`produccion.php?accion=get_ot_detalle&ot_id=${id}`);
        const d = await r.json();
        if (!d.ok) { document.getElementById('vpDrawerBody').innerHTML = '<div class="vp-d-spin" style="color:#f44336"><i class="fas fa-exclamation-circle"></i> Error al cargar</div>'; return; }
        renderDetalleOT(d.ot, d.logs, d.totales, d.requisiciones_externas || []);
    } catch(e) {
        document.getElementById('vpDrawerBody').innerHTML = '<div class="vp-d-spin" style="color:#f44336"><i class="fas fa-exclamation-circle"></i> Error de conexión</div>';
    }
}

// Timer en vivo para el drawer de ver_proceso
let _vpTimerInterval = null;
function _vpStopTimer() { if (_vpTimerInterval) { clearInterval(_vpTimerInterval); _vpTimerInterval = null; } }
function _vpStartTimer(tsUnix, acumSeg) {
    _vpStopTimer();
    const el = document.getElementById('vpDrawerLiveTimer');
    if (!el || !tsUnix) return;
    const update = () => {
        const sesion = Math.max(0, Math.floor(Date.now()/1000) - tsUnix);
        const total  = acumSeg + sesion;
        const h = Math.floor(total/3600);
        const m = Math.floor((total%3600)/60);
        const s = total%60;
        el.textContent = (h>0?String(h).padStart(2,'0')+':':'') +
            String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
    };
    update();
    _vpTimerInterval = setInterval(update, 1000);
}

function renderDetalleOT(ot, logs, tot, reqsExternas) {
    reqsExternas = reqsExternas || [];
    _vpStopTimer();
    const estadoColor = { pendiente:'#FF9800', en_proceso:'#1565c0', completado:'#2E7D32', cancelado:'#9e9e9e' };
    const estadoLabel = { pendiente:'Pendiente', en_proceso:'En proceso', completado:'Completado', cancelado:'Cancelado' };
    const col = estadoColor[ot.estado] || '#999';

    document.getElementById('vpDFolio').textContent = ot.folio || '';
    document.getElementById('vpDTitle').textContent = ot.titulo;

    // Timestamp del último inicio/reanudación (para timer en vivo)
    let lastInicioTs = 0;
    for (let i = logs.length - 1; i >= 0; i--) {
        if (logs[i].accion === 'inicio' || logs[i].accion === 'reanudacion') {
            lastInicioTs = Math.floor(new Date(logs[i].timestamp.replace(' ','T')).getTime()/1000);
            break;
        }
    }
    const esActiva = ot.estado === 'en_proceso' && !parseInt(ot.pausado||0);

    // ── Cards de resumen ──
    const regH  = fmtMin(tot.regular_min);
    const extH  = fmtMin(tot.extra_min);
    const costoReg = (tot.regular_min / 60) * 180;
    const costoExt = (tot.extra_min   / 60) * 270;
    const costoTotal = costoReg + costoExt;
    const costoFmt = costoTotal.toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});

    let sesionCard = '';
    if (esActiva && lastInicioTs > 0) {
        sesionCard = `
        <div class="vp-d-card" style="background:#e8f5e9;grid-column:span 2;">
            <div class="vp-d-lbl" style="color:#2e7d32;"><i class="fas fa-play-circle"></i> Sesión actual (tiempo transcurrido)</div>
            <div class="vp-d-val" style="color:#2e7d32;font-family:monospace;font-size:1.4rem;" id="vpDrawerLiveTimer">00:00:00</div>
        </div>`;
    }

    let html = `
    <div class="vp-d-grid">
        <div class="vp-d-card verde">
            <div class="vp-d-lbl"><i class="fas fa-clock"></i> Horas regulares acum.</div>
            <div class="vp-d-val">${regH}</div>
        </div>
        <div class="vp-d-card naranja">
            <div class="vp-d-lbl"><i class="fas fa-star"></i> Horas extra acum.</div>
            <div class="vp-d-val">${extH}</div>
        </div>
        <div class="vp-d-card azul">
            <div class="vp-d-lbl"><i class="fas fa-boxes"></i> Piezas terminadas</div>
            <div class="vp-d-val">${tot.piezas}</div>
        </div>
        <div class="vp-d-card">
            <div class="vp-d-lbl"><i class="fas fa-tasks"></i> Estado</div>
            <div class="vp-d-val" style="color:${col};font-size:.9rem">${estadoLabel[ot.estado] || ot.estado}</div>
        </div>
        ${sesionCard}
        <div class="vp-d-card" style="background:#fce4ec;grid-column:span 2;">
            <div class="vp-d-lbl" style="color:#880e4f;"><i class="fas fa-dollar-sign"></i> Costo estimado (MXN)</div>
            <div class="vp-d-val" style="color:#880e4f;">$${costoFmt} <span style="font-size:.65rem;font-weight:400;color:#c2185b;">($180/h reg · $270/h ext)</span></div>
        </div>
    </div>`;

    // ── Info detallada ──
    const rows = [
        ['Proyecto',   ot.proyecto_nombre],
        ['Pieza',      ot.pieza_nombre],
        ['Máquina',    ot.maquina],
        ['Proceso',    ot.proceso],
        ['Operario',   ot.operador_nombre],
        ['VB Producción', ot.vb_prod_nombre ? `${ot.vb_prod_nombre} (${fmtTs(ot.visto_produccion_fecha)})` : null],
        ['VB Calidad', ot.vb_cal_nombre ? `${ot.vb_cal_nombre} (${fmtTs(ot.visto_calidad_fecha)})` : null],
        ['Inicio real', ot.fecha_inicio_real ? fmtTs(ot.fecha_inicio_real) : null],
        ['Folio',      ot.folio],
    ].filter(([,v]) => v);

    if (rows.length) {
        html += `<div class="vp-d-info">`;
        rows.forEach(([lbl, val]) => {
            html += `<div class="vp-d-row"><span class="vp-d-row-lbl">${esc(lbl)}</span><span class="vp-d-row-val">${esc(val)}</span></div>`;
        });
        html += `</div>`;
    }

    // ── Timeline de logs ──
    html += `<div class="vp-d-section-title"><i class="fas fa-history"></i> Historial de actividad (${logs.length} eventos)</div>`;

    if (!logs.length) {
        html += `<div style="text-align:center;color:#aaa;padding:20px;font-size:.8rem">Sin registros de actividad</div>`;
    } else {
        const iconMap = { inicio:'play', reanudacion:'play-circle', pausa:'pause', fin:'flag-checkered' };
        html += `<div class="vp-tl">`;
        logs.forEach(l => {
            const chips = [];
            if (l.tiempo_regular_min > 0) chips.push(`<span class="vp-tl-chip reg"><i class="fas fa-clock"></i> ${fmtMin(l.tiempo_regular_min)} regular</span>`);
            if (l.tiempo_extra_min   > 0) chips.push(`<span class="vp-tl-chip ext"><i class="fas fa-star"></i> ${fmtMin(l.tiempo_extra_min)} extra</span>`);
            if (l.piezas_terminadas  > 0) chips.push(`<span class="vp-tl-chip pzas"><i class="fas fa-boxes"></i> ${l.piezas_terminadas} pzas</span>`);

            html += `<div class="vp-tl-item">
                <div class="vp-tl-dot ${l.accion}"><i class="fas fa-${iconMap[l.accion] || 'circle'}"></i></div>
                <div class="vp-tl-content">
                    <div class="vp-tl-head">
                        <span class="vp-tl-accion ${l.accion}">${l.accion.charAt(0).toUpperCase() + l.accion.slice(1)}</span>
                        <span class="vp-tl-ts">${fmtTs(l.timestamp)}</span>
                    </div>
                    ${l.operador_nombre ? `<div class="vp-tl-row"><b>Operador:</b> ${esc(l.operador_nombre)}</div>` : ''}
                    ${l.maquina        ? `<div class="vp-tl-row"><b>Máquina:</b> ${esc(l.maquina)}</div>` : ''}
                    ${l.actividad      ? `<div class="vp-tl-row"><b>Actividad:</b> ${esc(l.actividad)}</div>` : ''}
                    ${l.resumen        ? `<div class="vp-tl-row"><b>Resumen:</b> ${esc(l.resumen)}</div>` : ''}
                    ${l.motivo_pausa   ? `<div class="vp-tl-row"><b>Motivo:</b> ${esc(l.motivo_pausa)}</div>` : ''}
                    ${l.observaciones  ? `<div class="vp-tl-row"><b>Observaciones:</b> ${esc(l.observaciones)}</div>` : ''}
                    ${chips.length     ? `<div class="vp-tl-chips">${chips.join('')}</div>` : ''}
                </div>
            </div>`;
        });
        html += `</div>`;
    }

    // ── Requisiciones Externas (puede haber varias para la misma OT) ──
    if (reqsExternas.length) {
        const estLbl = { pendiente:'Pendiente', aprobada:'Aprobada', rechazada:'Rechazada', en_proceso:'En proceso', completada:'Completada', cancelada:'Cancelada' };
        html += `<div style="margin-top:16px;padding-top:14px;border-top:1px solid #e8ecf0;">
            <div style="font-size:.75rem;font-weight:700;color:#607d8b;margin-bottom:8px;text-transform:uppercase;">
                <i class="fas fa-file-alt"></i> Requisiciones Externas (${reqsExternas.length})
            </div>` +
            reqsExternas.map(r => `
            <a href="ver_requisicion.php?id=${r.id}" target="_blank" style="display:flex;justify-content:space-between;align-items:center;font-size:.78rem;font-weight:700;color:#1565c0;padding:6px 0;border-bottom:1px solid #f0f4f8;text-decoration:none;">
                <span>${esc(r.folio)}</span>
                <span style="font-size:.68rem;font-weight:600;color:#888;">${esc(estLbl[r.estado] || r.estado)}</span>
            </a>`).join('') +
            `</div>`;
    }
    if (ot.estado !== 'completado') {
        html += `<div style="margin-top:${reqsExternas.length ? '10' : '16'}px;${reqsExternas.length ? '' : 'padding-top:14px;border-top:1px solid #e8ecf0;'}">
            <button class="btn btn-outline btn-sm" style="width:100%;justify-content:center;" onclick="cerrarDetalleOT();abrirModalReqExt(${ot.id})">
                <i class="fas fa-file-alt"></i> ${reqsExternas.length ? 'Generar otra Requisición Externa' : 'Generar Requisición Externa'}
            </button>
        </div>`;
    }

    document.getElementById('vpDrawerBody').innerHTML = html;
    // Arrancar timer en vivo si la OT está activa
    if (esActiva && lastInicioTs > 0) {
        _vpStartTimer(lastInicioTs, tot.regular_min * 60);
    }
}

function cerrarDetalleOT() {
    _vpStopTimer();
    document.getElementById('vpOverlay').classList.remove('open');
    document.getElementById('vpDrawer').classList.remove('open');
}

async function verDetalleAgregado(tipo, id) {
    document.getElementById('vpOverlay').classList.add('open');
    document.getElementById('vpDrawer').classList.add('open');
    document.getElementById('vpDrawerBody').innerHTML = '<div class="vp-d-spin"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
    document.getElementById('vpDFolio').textContent = '';
    document.getElementById('vpDTitle').textContent = 'Cargando...';

    const accionMap = { proyecto: 'get_proyecto_detalle', plano: 'get_plano_detalle', pagina: 'get_pagina_detalle' };
    const paramMap  = { proyecto: 'proyecto_id', plano: 'plano_id', pagina: 'pagina_id' };
    const accion = accionMap[tipo];
    const param  = paramMap[tipo];

    try {
        const r = await fetch(`produccion.php?accion=${accion}&${param}=${id}`);
        const d = await r.json();
        if (!d.ok) { document.getElementById('vpDrawerBody').innerHTML = '<div class="vp-d-spin" style="color:#f44336"><i class="fas fa-exclamation-circle"></i> Error al cargar</div>'; return; }
        renderDetalleAgregado(tipo, d);
    } catch(e) {
        document.getElementById('vpDrawerBody').innerHTML = '<div class="vp-d-spin" style="color:#f44336"><i class="fas fa-exclamation-circle"></i> Error de conexión</div>';
    }
}

function renderDetalleAgregado(tipo, data) {
    _vpStopTimer();
    const tipoLabelMap = { proyecto: 'PROYECTO', plano: 'PLANO', pagina: 'PÁGINA' };
    document.getElementById('vpDFolio').textContent = tipoLabelMap[tipo];
    document.getElementById('vpDTitle').textContent = data.nombre;

    const tot = data.totales;
    const regH  = fmtMin(tot.regular_min);
    const extH  = fmtMin(tot.extra_min);
    const costoReg = (tot.regular_min / 60) * 180;
    const costoExt = (tot.extra_min   / 60) * 270;
    const costoTotal = costoReg + costoExt;
    const costoFmt = costoTotal.toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
    const otsColor = (tot.ots_total > 0 && tot.ots_ok === tot.ots_total) ? '#2e7d32' : '#1565c0';

    let html = `
    <div class="vp-d-grid">
        <div class="vp-d-card verde">
            <div class="vp-d-lbl"><i class="fas fa-clock"></i> Horas regulares acum.</div>
            <div class="vp-d-val">${regH}</div>
        </div>
        <div class="vp-d-card naranja">
            <div class="vp-d-lbl"><i class="fas fa-star"></i> Horas extra acum.</div>
            <div class="vp-d-val">${extH}</div>
        </div>
        <div class="vp-d-card azul">
            <div class="vp-d-lbl"><i class="fas fa-boxes"></i> Piezas terminadas</div>
            <div class="vp-d-val">${tot.piezas}</div>
        </div>
        <div class="vp-d-card">
            <div class="vp-d-lbl"><i class="fas fa-tasks"></i> OTs completadas</div>
            <div class="vp-d-val" style="color:${otsColor};">${tot.ots_ok}/${tot.ots_total}</div>
        </div>
        <div class="vp-d-card" style="background:#fce4ec;grid-column:span 2;">
            <div class="vp-d-lbl" style="color:#880e4f;"><i class="fas fa-dollar-sign"></i> Costo estimado (MXN)</div>
            <div class="vp-d-val" style="color:#880e4f;">$${costoFmt} <span style="font-size:.65rem;font-weight:400;color:#c2185b;">($180/h reg · $270/h ext)</span></div>
        </div>
    </div>`;

    if (tipo === 'pagina' || tipo === 'plano') {
        const logs = data.logs;
        html += `<div class="vp-d-section-title"><i class="fas fa-history"></i> Bitácora combinada (${logs.length} eventos)</div>`;

        if (!logs.length) {
            html += `<div style="text-align:center;color:#aaa;padding:20px;font-size:.8rem">Sin registros de actividad</div>`;
        } else {
            const iconMap = { inicio:'play', reanudacion:'play-circle', pausa:'pause', fin:'flag-checkered' };
            html += `<div class="vp-tl">`;
            logs.forEach(l => {
                const chips = [];
                if (l.tiempo_regular_min > 0) chips.push(`<span class="vp-tl-chip reg"><i class="fas fa-clock"></i> ${fmtMin(l.tiempo_regular_min)} regular</span>`);
                if (l.tiempo_extra_min   > 0) chips.push(`<span class="vp-tl-chip ext"><i class="fas fa-star"></i> ${fmtMin(l.tiempo_extra_min)} extra</span>`);
                if (l.piezas_terminadas  > 0) chips.push(`<span class="vp-tl-chip pzas"><i class="fas fa-boxes"></i> ${l.piezas_terminadas} pzas</span>`);

                html += `<div class="vp-tl-item">
                    <div class="vp-tl-dot ${l.accion}"><i class="fas fa-${iconMap[l.accion] || 'circle'}"></i></div>
                    <div class="vp-tl-content">
                        <div class="vp-tl-head">
                            <span class="vp-tl-accion ${l.accion}">${l.accion.charAt(0).toUpperCase() + l.accion.slice(1)}</span>
                            <span class="vp-tl-ts">${fmtTs(l.timestamp)}</span>
                        </div>
                        <div class="vp-tl-row"><b>OT:</b> ${esc(l.ot_folio || ('OT-' + l.ot_id))} — ${esc(l.ot_titulo || '')}</div>
                        ${l.ot_pagina ? `<div class="vp-tl-row"><b>Página:</b> ${esc(l.ot_pagina)}</div>` : ''}
                        ${l.operador_nombre ? `<div class="vp-tl-row"><b>Operador:</b> ${esc(l.operador_nombre)}</div>` : ''}
                        ${l.maquina        ? `<div class="vp-tl-row"><b>Máquina:</b> ${esc(l.maquina)}</div>` : ''}
                        ${l.actividad      ? `<div class="vp-tl-row"><b>Actividad:</b> ${esc(l.actividad)}</div>` : ''}
                        ${l.resumen        ? `<div class="vp-tl-row"><b>Resumen:</b> ${esc(l.resumen)}</div>` : ''}
                        ${l.motivo_pausa   ? `<div class="vp-tl-row"><b>Motivo:</b> ${esc(l.motivo_pausa)}</div>` : ''}
                        ${l.observaciones  ? `<div class="vp-tl-row"><b>Observaciones:</b> ${esc(l.observaciones)}</div>` : ''}
                        ${chips.length     ? `<div class="vp-tl-chips">${chips.join('')}</div>` : ''}
                    </div>
                </div>`;
            });
            html += `</div>`;
        }
    }

    document.getElementById('vpDrawerBody').innerHTML = html;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') { cerrarDetalleOT(); cerrarModalReqExt(); } });

// ── Modal Requisicion Externa ─────────────────────────────────────────────────
let _mreqOtId = null;

async function abrirModalReqExt(otId) {
    _mreqOtId = otId;
    document.getElementById('mreq-ot-info').textContent = 'Cargando…';
    document.getElementById('mreq-tbody').innerHTML = '';
    document.getElementById('mreq-notas').value = '';
    const btn = document.getElementById('mreq-guardar-btn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Guardar Requisición';
    document.getElementById('modalReqExt').style.display = 'flex';

    const r = await fetch(`produccion.php?accion=get_materiales_ot&ot_id=${otId}`);
    const data = await r.json();
    const tbody = document.getElementById('mreq-tbody');
    if (data.ok && data.ot) {
        document.getElementById('mreq-ot-info').textContent =
            (data.ot.folio ? data.ot.folio + ' — ' : '') + (data.ot.pieza_nombre ?? '') + ' (' + (data.ot.proy_nombre ?? '') + ')';
        if (data.materiales && data.materiales.length > 0) {
            data.materiales.forEach(m => tbody.appendChild(mreqCreateRow(m.descripcion, m.cantidad, m.unidad_medida, m.material_id, m.marca, m.modelo, m.nota_libre)));
        } else {
            tbody.appendChild(mreqCreateRow('', 1, ''));
        }
    } else {
        document.getElementById('mreq-ot-info').textContent = 'OT no encontrada';
        tbody.appendChild(mreqCreateRow('', 1, ''));
    }
}

function cerrarModalReqExt() {
    document.getElementById('modalReqExt').style.display = 'none';
    _mreqOtId = null;
}

function _escH(str) {
    return (str ?? '').toString()
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function mreqCreateRow(desc, cant, unid, matId = null, marca = '', modelo = '', notas = '') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:4px 6px;">
            <input type="text" class="mreq-desc" value="${_escH(desc)}"
                style="width:100%;padding:5px 8px;border:1px solid #cfd8dc;border-radius:4px;font-size:.875rem;box-sizing:border-box;">
        </td>
        <td style="padding:4px 6px;">
            <input type="number" class="mreq-cant" value="${_escH(String(cant ?? 1))}" min="0" step="any"
                style="width:100%;padding:5px 8px;border:1px solid #cfd8dc;border-radius:4px;font-size:.875rem;text-align:center;box-sizing:border-box;">
        </td>
        <td style="padding:4px 6px;">
            <input type="text" class="mreq-unid" value="${_escH(unid)}" placeholder="pza, kg…"
                style="width:100%;padding:5px 8px;border:1px solid #cfd8dc;border-radius:4px;font-size:.875rem;box-sizing:border-box;">
        </td>
        <td style="padding:4px 6px;">
            <input type="text" class="mreq-marca" value="${_escH(marca)}" placeholder="Marca"
                style="width:100%;padding:5px 8px;border:1px solid #cfd8dc;border-radius:4px;font-size:.875rem;box-sizing:border-box;">
        </td>
        <td style="padding:4px 6px;">
            <input type="text" class="mreq-modelo" value="${_escH(modelo)}" placeholder="Modelo"
                style="width:100%;padding:5px 8px;border:1px solid #cfd8dc;border-radius:4px;font-size:.875rem;box-sizing:border-box;">
        </td>
        <td style="padding:4px 6px;">
            <input type="text" class="mreq-notas" value="${_escH(notas)}" placeholder="Nota"
                style="width:100%;padding:5px 8px;border:1px solid #cfd8dc;border-radius:4px;font-size:.875rem;box-sizing:border-box;">
        </td>
        <td style="padding:4px 6px;text-align:center;">
            <input type="hidden" class="mreq-matid" value="${_escH(matId ?? '')}">
            <button type="button" onclick="this.closest('tr').remove()"
                style="background:none;border:none;color:#ef5350;cursor:pointer;font-size:1rem;line-height:1;">
                <i class="fas fa-times"></i>
            </button>
        </td>`;
    return tr;
}

function mreqAddRow() {
    document.getElementById('mreq-tbody').appendChild(mreqCreateRow('', 1, ''));
}

let _invReqExtSelected = null;

function abrirBuscadorInventarioReqExt() {
    const overlay = document.getElementById('modalInventarioReqExtOverlay');
    if (!overlay) return;
    _invReqExtSelected = null;
    document.getElementById('invReqExtSearchInput').value = '';
    document.getElementById('invReqExtResults').innerHTML = '<div class="inv-empty"><i class="fas fa-search" style="margin-right:6px;"></i>Escribe para buscar en el inventario...</div>';
    document.getElementById('invReqExtQtyRow').style.display = 'none';
    overlay.classList.add('open');
    setTimeout(function(){ document.getElementById('invReqExtSearchInput').focus(); }, 80);
}

function cerrarBuscadorInventarioReqExt() {
    document.getElementById('modalInventarioReqExtOverlay').classList.remove('open');
}

async function buscarEnInventarioReqExt() {
    const q = document.getElementById('invReqExtSearchInput').value.trim();
    const box = document.getElementById('invReqExtResults');
    if (q.length < 2) {
        box.innerHTML = '<div class="inv-empty">Escribe al menos 2 caracteres.</div>';
        return;
    }
    box.innerHTML = '<div class="inv-empty"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
    try {
        const r = await fetch('api/materiales.php?busqueda=' + encodeURIComponent(q));
        const d = await r.json();
        if (!d.success || !d.data || !d.data.length) {
            box.innerHTML = '<div class="inv-empty">Sin resultados para "' + _escH(q) + '".</div>';
            return;
        }
        box.innerHTML = d.data.slice(0, 25).map(function(m) {
            const stockOk  = parseFloat(m.stock_disponible) > parseFloat(m.stock_minimo || 0);
            const stockCls = stockOk ? 'inv-stock-ok' : 'inv-stock-low';
            return '<div class="inv-result-item" onclick="seleccionarItemInventarioReqExt(this)"' +
                ' data-id="'     + m.id                            + '"' +
                ' data-desc="'   + _escH(m.descripcion)             + '"' +
                ' data-codigo="' + _escH(m.codigo   || '')          + '"' +
                ' data-marca="'  + _escH(m.marca    || '')          + '"' +
                ' data-modelo="' + _escH(m.modelo   || '')          + '"' +
                ' data-unidad="' + _escH(m.unidad_medida || 'PZA') + '"' +
                ' data-stock="'  + m.stock_disponible              + '">' +
                '<div class="inv-item-top">' +
                (m.codigo ? '<span class="inv-codigo">' + _escH(m.codigo) + '</span>' : '') +
                '<span class="inv-desc">' + _escH(m.descripcion) + '</span>' +
                '</div>' +
                '<div class="inv-item-meta">' +
                (m.marca ? 'Marca: <strong>' + _escH(m.marca) + '</strong> · ' : '') +
                'Stock: <span class="' + stockCls + '">' + m.stock_disponible + ' ' + _escH(m.unidad_medida || 'PZA') + '</span>' +
                '</div></div>';
        }).join('');
    } catch(e) {
        box.innerHTML = '<div class="inv-empty" style="color:#c62828;">Error: ' + _escH(e.message) + '</div>';
    }
}

function seleccionarItemInventarioReqExt(el) {
    document.querySelectorAll('#invReqExtResults .inv-result-item').forEach(function(i){ i.classList.remove('selected'); });
    el.classList.add('selected');
    _invReqExtSelected = {
        id:          el.dataset.id,
        descripcion: el.dataset.desc,
        codigo:      el.dataset.codigo,
        marca:       el.dataset.marca,
        modelo:      el.dataset.modelo,
        unidad:      el.dataset.unidad || 'PZA',
        stock:       parseFloat(el.dataset.stock) || 0,
    };
    const row = document.getElementById('invReqExtQtyRow');
    row.style.display = 'flex';
    document.getElementById('invReqExtQtyInput').value = '1';
    document.getElementById('invReqExtQtyInput').classList.remove('inp-error');
    document.getElementById('invReqExtUnidadVal').textContent = _invReqExtSelected.unidad;
    document.getElementById('invReqExtSelectedLabel').textContent = _invReqExtSelected.descripcion;
    document.getElementById('invReqExtQtyInput').focus();
}

function confirmarAgregarInventarioReqExt() {
    if (!_invReqExtSelected) { alert('Selecciona un material primero.'); return; }
    const qEl = document.getElementById('invReqExtQtyInput');
    const qty = parseFloat(qEl.value);
    if (isNaN(qty) || qty <= 0) { qEl.classList.add('inp-error'); qEl.focus(); return; }
    qEl.classList.remove('inp-error');

    const tbody = document.getElementById('mreq-tbody');
    if (!tbody) return;

    tbody.appendChild(mreqCreateRow(_invReqExtSelected.descripcion, qty, _invReqExtSelected.unidad, _invReqExtSelected.id, _invReqExtSelected.marca, _invReqExtSelected.modelo, ''));
    cerrarBuscadorInventarioReqExt();
}

async function guardarReqExt() {
    if (!_mreqOtId) return;
    const rows = document.querySelectorAll('#mreq-tbody tr');
    const descs = [], cants = [], unids = [], matids = [], marcas = [], modelos = [], notasItem = [];
    let hayAlgo = false;
    rows.forEach(tr => {
        const d  = (tr.querySelector('.mreq-desc')?.value ?? '').trim();
        const c  = tr.querySelector('.mreq-cant')?.value ?? '1';
        const u  = (tr.querySelector('.mreq-unid')?.value ?? '').trim();
        const m  = (tr.querySelector('.mreq-matid')?.value ?? '').trim();
        const ma = (tr.querySelector('.mreq-marca')?.value ?? '').trim();
        const mo = (tr.querySelector('.mreq-modelo')?.value ?? '').trim();
        const no = (tr.querySelector('.mreq-notas')?.value ?? '').trim();
        descs.push(d); cants.push(c); unids.push(u); matids.push(m);
        marcas.push(ma); modelos.push(mo); notasItem.push(no);
        if (d) hayAlgo = true;
    });
    if (!hayAlgo) { alert('Agrega al menos un material.'); return; }
    const notas = document.getElementById('mreq-notas').value.trim();
    const btn = document.getElementById('mreq-guardar-btn');
    btn.disabled = true;
    btn.textContent = 'Guardando…';
    const fd = new FormData();
    fd.append('accion', 'generar_requisicion_ot');
    fd.append('ot_id', _mreqOtId);
    fd.append('notas', notas);
    descs.forEach((d, i) => {
        fd.append('req_desc[]', d); fd.append('req_cant[]', cants[i]); fd.append('req_unid[]', unids[i]);
        fd.append('req_matid[]', matids[i]); fd.append('req_marca[]', marcas[i]); fd.append('req_modelo[]', modelos[i]); fd.append('req_notas[]', notasItem[i]);
    });
    const r = await fetch('produccion.php', {method:'POST', body:fd});
    let data;
    try { data = await r.json(); } catch(e) { data = {ok:false, error:'Respuesta inválida del servidor'}; }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Guardar Requisición';
    if (data.ok) {
        cerrarModalReqExt();
        let msg = 'Requisición ' + data.folio + ' generada con éxito.';
        if (data.duplicados && data.duplicados.length) {
            msg += '\n\n⚠ Aviso: ya existe una requisición activa en este proyecto para:\n' +
                data.duplicados.map(d => '• ' + d.descripcion + ' — folio ' + d.folio + ', pedida por ' + d.area).join('\n');
        }
        alert(msg);
        location.reload();
    } else {
        alert('Error: ' + (data.error ?? 'No se pudo guardar.'));
    }
}

// ── Acordeones de ver_proceso ─────────────────────────────────────────────────
function toggleVPProject(id) {
    const bar  = document.getElementById('vpbar_' + id);
    const body = document.getElementById('vpbody_' + id);
    const chev = document.getElementById('vpchevron_' + id);
    const open = body.classList.contains('open');
    document.querySelectorAll('.vp-proj-bar.open').forEach(b => b.classList.remove('open'));
    document.querySelectorAll('.vp-proj-body.open').forEach(b => b.classList.remove('open'));
    if (!open) { bar.classList.add('open'); body.classList.add('open'); }
}
function toggleVPPlano(id) {
    const body = document.getElementById('vpl_ots_' + id);
    const chev = document.getElementById('vplchev_' + id);
    const open = body.style.display !== 'none' && body.style.display !== '';
    body.style.display = open ? 'none' : 'block';
    if (chev) chev.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
}
function toggleVPPage(id) {
    const body = document.getElementById('vpag_ots_' + id);
    const chev = document.getElementById('vpagchev_' + id);
    if (!body) return;
    const open = body.style.display !== 'none' && body.style.display !== '';
    body.style.display = open ? 'none' : 'block';
    if (chev) chev.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
}
function toggleOTDetail(id) {
    const det = document.getElementById('otdet_' + id);
    const btn = document.getElementById('detbtn_' + id);
    if (!det) return;
    const open = det.classList.contains('open');
    det.classList.toggle('open', !open);
    if (btn) {
        btn.classList.toggle('active', !open);
        btn.querySelector('i').style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    }
}

// ── Timers en vivo ────────────────────────────────────────────────────────────
function fmtLive(secs) {
    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    const s = secs % 60;
    return (h > 0 ? h + ':' : '') +
           String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}
(function initLiveTimers() {
    const timers = document.querySelectorAll('[id^="timer_"]');
    if (!timers.length) return;
    setInterval(function() {
        const nowMs = Date.now();
        timers.forEach(function(el) {
            const ts   = parseInt(el.dataset.ts   || 0); // Unix timestamp inicio sesión
            const acum = parseInt(el.dataset.acum || 0); // segundos acumulados previos
            if (!ts || isNaN(ts)) return;
            const sesion  = Math.max(0, Math.floor(nowMs / 1000) - ts);
            const total   = acum + sesion;
            const span = el.querySelector('span');
            if (span) span.textContent = fmtLive(total);
        });
    }, 1000);
})();

// ── Arrastrar y soltar para reordenar OTs pendientes (mismo mecanismo que Generar OT) ──
(function initVPDragDrop() {
    let dragSrc = null;
    document.querySelectorAll('.vp-plano-ots').forEach(function(queue) {
        queue.querySelectorAll('.vp-ot-row[draggable="true"]').forEach(function(row) {
            row.addEventListener('dragstart', function(e) {
                dragSrc = this;
                this.classList.add('vp-dragging');
                e.dataTransfer.setData('text/plain', this.dataset.otId);
            });
            row.addEventListener('dragend', function() {
                this.classList.remove('vp-dragging');
                queue.querySelectorAll('.vp-drag-over').forEach(el => el.classList.remove('vp-drag-over'));
                vpGuardarOrden(queue);
            });
            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (dragSrc && dragSrc !== this && dragSrc.parentElement === this.parentElement) {
                    this.classList.add('vp-drag-over');
                }
            });
            row.addEventListener('dragleave', function() { this.classList.remove('vp-drag-over'); });
            row.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('vp-drag-over');
                if (dragSrc && dragSrc !== this && dragSrc.parentElement === this.parentElement) {
                    const items = [...queue.querySelectorAll('.vp-ot-row[draggable="true"]')];
                    const si = items.indexOf(dragSrc), ti = items.indexOf(this);
                    if (si < ti) this.after(dragSrc); else this.before(dragSrc);
                }
            });
        });
    });

    function vpGuardarOrden(queue) {
        const ids = [...queue.querySelectorAll('.vp-ot-row[draggable="true"]')].map(r => r.dataset.otId);
        if (!ids.length) return;
        const fd = new FormData();
        fd.append('ajax_got', '1');
        fd.append('accion', 'reordenar');
        ids.forEach(id => fd.append('orden[]', id));
        fetch('produccion.php', { method: 'POST', body: fd }).catch(function () {});
    }
})();

// ── Arrastrar y soltar para reordenar las carpetas de proyecto completas ──
(function initVPProjectDragDrop() {
    let dragSrc = null;
    document.querySelectorAll('.vp-proj-folder[draggable="true"]').forEach(function (folder) {
        folder.addEventListener('dragstart', function (e) {
            // No interferir si el arrastre en realidad empezó en una fila de OT anidada.
            if (e.target.closest('.vp-ot-row')) return;
            dragSrc = this;
            this.classList.add('vp-proj-dragging');
            e.dataTransfer.setData('text/plain', this.dataset.proyectoId);
        });
        folder.addEventListener('dragend', function () {
            this.classList.remove('vp-proj-dragging');
            document.querySelectorAll('.vp-proj-drag-over').forEach(el => el.classList.remove('vp-proj-drag-over'));
            vpGuardarOrdenProyectos();
        });
        folder.addEventListener('dragover', function (e) {
            if (dragSrc && dragSrc !== this) {
                e.preventDefault();
                this.classList.add('vp-proj-drag-over');
            }
        });
        folder.addEventListener('dragleave', function () { this.classList.remove('vp-proj-drag-over'); });
        folder.addEventListener('drop', function (e) {
            if (!dragSrc || dragSrc === this) return;
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('vp-proj-drag-over');
            const items = [...document.querySelectorAll('.vp-proj-folder[draggable="true"]')];
            const si = items.indexOf(dragSrc), ti = items.indexOf(this);
            if (si < ti) this.after(dragSrc); else this.before(dragSrc);
        });
    });

    function vpGuardarOrdenProyectos() {
        const ids = [...document.querySelectorAll('.vp-proj-folder[draggable="true"]')].map(f => f.dataset.proyectoId);
        if (!ids.length) return;
        const fd = new FormData();
        fd.append('ajax_got', '1');
        fd.append('accion', 'reordenar_proyectos_vp');
        ids.forEach(id => fd.append('orden[]', id));
        fetch('produccion.php', { method: 'POST', body: fd }).catch(function () {});
    }
})();
</script>

<!-- ── Modal Requisición Externa ── -->
<div id="modalReqExt" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.45);justify-content:center;align-items:flex-start;padding-top:52px;">
    <div style="background:#fff;border-radius:12px;width:min(780px,96vw);max-height:82vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.28);display:flex;flex-direction:column;">
        <div style="padding:18px 24px;border-bottom:1px solid #e0e8f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
            <div>
                <div style="font-weight:700;font-size:1.05rem;color:#1a237e;"><i class="fas fa-file-alt" style="margin-right:8px;"></i>Generar Requisición Externa</div>
                <div id="mreq-ot-info" style="font-size:.82rem;color:#607d8b;margin-top:3px;"></div>
            </div>
            <button onclick="cerrarModalReqExt()" style="background:none;border:none;font-size:1.5rem;color:#90a4ae;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:18px 24px;flex:1;overflow-y:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr style="background:#f5f7fa;">
                        <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e0e8f0;font-weight:600;color:#455a64;">Descripción</th>
                        <th style="padding:8px 10px;text-align:center;border-bottom:2px solid #e0e8f0;width:80px;font-weight:600;color:#455a64;">Cantidad</th>
                        <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e0e8f0;width:90px;font-weight:600;color:#455a64;">Unidad</th>
                        <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e0e8f0;width:110px;font-weight:600;color:#455a64;">Marca</th>
                        <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e0e8f0;width:110px;font-weight:600;color:#455a64;">Modelo</th>
                        <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #e0e8f0;width:110px;font-weight:600;color:#455a64;">Nota</th>
                        <th style="padding:8px 10px;width:38px;border-bottom:2px solid #e0e8f0;"></th>
                    </tr>
                </thead>
                <tbody id="mreq-tbody"></tbody>
            </table>
            <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
                <button type="button" onclick="mreqAddRow()"
                    style="font-size:.82rem;background:none;border:1px dashed #90a4ae;border-radius:6px;padding:6px 16px;cursor:pointer;color:#546e7a;transition:border-color .2s;">
                    <i class="fas fa-plus"></i> Añadir material
                </button>
                <button type="button" class="btn-agregar-mat" onclick="abrirBuscadorInventarioReqExt()">
                    <i class="fas fa-boxes"></i> Agregar material
                </button>
            </div>
            <div style="margin-top:16px;">
                <label style="font-size:.82rem;color:#607d8b;font-weight:600;">Notas (opcional)</label>
                <textarea id="mreq-notas" rows="2"
                    style="width:100%;margin-top:5px;padding:8px 10px;border:1px solid #cfd8dc;border-radius:6px;font-size:.875rem;box-sizing:border-box;resize:vertical;font-family:inherit;"></textarea>
            </div>
        </div>
        <div style="padding:14px 24px;border-top:1px solid #e0e8f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;">
            <button type="button" onclick="cerrarModalReqExt()"
                style="padding:8px 20px;border:1px solid #cfd8dc;border-radius:6px;background:#fff;cursor:pointer;font-size:.875rem;color:#546e7a;">
                Cancelar
            </button>
            <button type="button" id="mreq-guardar-btn" onclick="guardarReqExt()"
                style="padding:8px 20px;border:none;border-radius:6px;background:#1565c0;color:#fff;cursor:pointer;font-size:.875rem;font-weight:600;">
                <i class="fas fa-save"></i> Guardar Requisición
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: Inventario (Requisición Externa) ── -->
<div id="modalInventarioReqExtOverlay" class="prd-modal-overlay" onclick="if(event.target===this)cerrarBuscadorInventarioReqExt()">
    <div class="prd-modal-box inv-box" role="dialog" aria-modal="true">
        <div class="prd-modal-head" style="background:#e65100;">
            <h3><i class="fas fa-boxes"></i> Agregar material del inventario</h3>
            <button onclick="cerrarBuscadorInventarioReqExt()">&times;</button>
        </div>
        <div class="inv-search-bar">
            <input id="invReqExtSearchInput" type="text" placeholder="Buscar por código o descripción..."
                   autocomplete="off" onkeydown="if(event.key==='Enter')buscarEnInventarioReqExt()">
            <button onclick="buscarEnInventarioReqExt()"><i class="fas fa-search"></i> Buscar</button>
        </div>
        <div id="invReqExtResults" class="inv-results">
            <div class="inv-empty"><i class="fas fa-search" style="margin-right:6px;"></i>Escribe para buscar en el inventario...</div>
        </div>
        <div id="invReqExtQtyRow" class="inv-qty-row" style="display:none;">
            <div class="inv-qty-field">
                <label>Cantidad</label>
                <input id="invReqExtQtyInput" type="number" min="0.001" step="any" value="1"
                       onkeydown="if(event.key==='Enter')confirmarAgregarInventarioReqExt()">
            </div>
            <div class="inv-qty-field">
                <label>Unidad</label>
                <div id="invReqExtUnidadVal" class="inv-unidad-val">PZA</div>
            </div>
            <div class="inv-qty-field" style="flex:1;">
                <label>Seleccionado</label>
                <div id="invReqExtSelectedLabel" class="inv-selected-label"></div>
            </div>
        </div>
        <div class="prd-modal-foot">
            <button class="prd-btn-cancel" onclick="cerrarBuscadorInventarioReqExt()">Cancelar</button>
            <button class="prd-btn-primary" style="background:#e65100;" onclick="confirmarAgregarInventarioReqExt()">
                <i class="fas fa-plus"></i> Agregar material
            </button>
        </div>
    </div>
</div>

<?php elseif ($seccion === 'calendario'): ?>
<!-- ── CALENDARIO DE PRODUCCIÓN ── -->
<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css'>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-calendar-alt"></i> Calendario de Producción</h2>
            <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <style>
    .cal-prod-wrap { max-width:1100px; margin:0 auto; padding:16px 20px 30px; }
    /* Popup de OTs */
    .calp-overlay {
        position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000;
        display:none; align-items:center; justify-content:center; padding:20px;
    }
    .calp-overlay.open { display:flex; }
    .calp-box {
        background:#fff; border-radius:16px; width:100%; max-width:560px; max-height:80vh;
        display:flex; flex-direction:column;
        box-shadow:0 20px 60px rgba(0,0,0,.2); overflow:hidden;
    }
    .calp-header {
        padding:16px 20px; display:flex; align-items:center; justify-content:space-between;
        border-bottom:1px solid #e8ecf0;
    }
    .calp-header-left { display:flex; align-items:center; gap:10px; }
    .calp-color-dot { width:14px; height:14px; border-radius:50%; flex-shrink:0; }
    .calp-title { font-size:1rem; font-weight:800; color:#003d5c; }
    .calp-close { background:none; border:none; font-size:1.3rem; color:#999; cursor:pointer; transition:color .15s; }
    .calp-close:hover { color:#003d5c; }
    .calp-body { flex:1; overflow-y:auto; padding:16px 20px; }
    .calp-dates { font-size:.78rem; color:#888; margin-bottom:14px; display:flex; gap:12px; flex-wrap:wrap; }
    .calp-dates span { display:flex; align-items:center; gap:5px; }
    /* OT rows en popup */
    .calp-ot-row {
        display:flex; align-items:center; gap:10px; padding:9px 12px;
        border-radius:10px; margin-bottom:6px; background:#f7fafd;
        border:1px solid #e8ecf0; transition:background .15s;
    }
    .calp-ot-row:hover { background:#edf4fb; }
    .calp-ot-estado {
        width:9px; height:9px; border-radius:50%; flex-shrink:0;
    }
    .calp-ot-estado.pendiente  { background:#FF9800; }
    .calp-ot-estado.en_proceso { background:#4caf50; box-shadow:0 0 6px #4caf50; }
    .calp-ot-estado.completado { background:#1565c0; }
    .calp-ot-estado.cancelado  { background:#9e9e9e; }
    .calp-ot-main { flex:1; min-width:0; }
    .calp-ot-folio { font-size:.65rem; font-weight:800; color:#607d8b; font-family:monospace; }
    .calp-ot-titulo { font-size:.82rem; font-weight:700; color:#003d5c; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .calp-ot-pieza { font-size:.7rem; color:#888; }
    .calp-ot-tiempo { font-size:.72rem; color:#2e7d32; font-weight:700; white-space:nowrap; }
    .calp-ot-tiempo.extra { color:#e65100; }
    .calp-empty { text-align:center; padding:30px; color:#aaa; font-size:.88rem; }
    .calp-badge-estado {
        font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px;
        padding:2px 8px; border-radius:8px; white-space:nowrap; flex-shrink:0;
    }
    .calp-badge-estado.pendiente  { background:#fff3e0; color:#e65100; }
    .calp-badge-estado.en_proceso { background:#e8f5e9; color:#2e7d32; }
    .calp-badge-estado.completado { background:#e3f2fd; color:#1565c0; }
    .calp-badge-estado.cancelado  { background:#f5f5f5; color:#999; }
    /* Summary bar */
    .calp-summary { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
    .calp-sum-item { flex:1; min-width:80px; background:#f4f8fc; border-radius:8px; padding:8px 12px; text-align:center; }
    .calp-sum-num { font-size:1.2rem; font-weight:800; color:#003d5c; }
    .calp-sum-lbl { font-size:.62rem; color:#888; text-transform:uppercase; letter-spacing:.3px; }
    </style>

    <div class="cal-prod-wrap">
        <div id="calProdEl"></div>
    </div>
</div>

<!-- Popup de OTs del proyecto -->
<div class="calp-overlay" id="calpOverlay">
    <div class="calp-box">
        <div class="calp-header">
            <div class="calp-header-left">
                <div class="calp-color-dot" id="calpDot"></div>
                <div class="calp-title" id="calpTitulo">Proyecto</div>
            </div>
            <button class="calp-close" onclick="cerrarCalpOverlay()">&times;</button>
        </div>
        <div class="calp-body">
            <div class="calp-dates" id="calpDates"></div>
            <div id="calpSummary"></div>
            <div id="calpOTs"><div class="calp-empty"><i class="fas fa-spinner fa-spin"></i> Cargando...</div></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const calEl = document.getElementById('calProdEl');
    if (!calEl || typeof FullCalendar === 'undefined') return;

    const calendar = new FullCalendar.Calendar(calEl, {
        initialView: 'dayGridMonth',
        locale: 'es',
        height: 'auto',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,listMonth'
        },
        buttonText: { today:'Hoy', month:'Mes', list:'Lista' },
        events: 'produccion.php?accion=get_cal_produccion',
        eventClick: function(info) {
            const props = info.event.extendedProps;
            const color = info.event.backgroundColor;
            const start = info.event.startStr;
            const end   = info.event.endStr;
            abrirCalpOTs(info.event.title, props.proyecto_id, color, start, end);
        },
        eventContent: function(arg) {
            return { html: `<div style="padding:2px 6px;font-weight:700;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${arg.event.title}</div>` };
        },
        noEventsText: 'Sin proyectos en el calendario',
    });
    calendar.render();
});

function cerrarCalpOverlay() {
    document.getElementById('calpOverlay').classList.remove('open');
}
document.getElementById('calpOverlay').addEventListener('click', function(e) {
    if (e.target === this) cerrarCalpOverlay();
});

function _escCalp(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function _fmtMinCalp(m) {
    if (!m) return ''; const h=Math.floor(m/60),r=m%60;
    return (h?h+'h ':'')+r+'min';
}

async function abrirCalpOTs(nombre, proyId, color, start, end) {
    document.getElementById('calpDot').style.background   = color || '#607d8b';
    document.getElementById('calpTitulo').textContent      = nombre;
    document.getElementById('calpOTs').innerHTML = '<div class="calp-empty"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
    document.getElementById('calpSummary').innerHTML = '';

    // Fechas
    const fmt = s => { if(!s) return ''; const d=new Date(s+'T00:00:00'); return d.toLocaleDateString('es-MX',{day:'2-digit',month:'short',year:'numeric'}); };
    let datesHtml = '';
    if (start) datesHtml += `<span><i class="fas fa-play-circle" style="color:#4caf50"></i> Inicio: ${fmt(start)}</span>`;
    if (end)   datesHtml += `<span><i class="fas fa-flag-checkered" style="color:#e91e63"></i> Fin: ${fmt(end)}</span>`;
    document.getElementById('calpDates').innerHTML = datesHtml;

    document.getElementById('calpOverlay').classList.add('open');

    try {
        const r = await fetch(`produccion.php?accion=get_cal_ots_proyecto&proyecto_id=${proyId}`);
        const d = await r.json();
        if (!d.ok) { document.getElementById('calpOTs').innerHTML = '<div class="calp-empty" style="color:#f44336">Error al cargar OTs.</div>'; return; }

        const ots = d.ots || [];
        if (!ots.length) {
            document.getElementById('calpOTs').innerHTML = '<div class="calp-empty"><i class="fas fa-inbox"></i><br>Sin órdenes de trabajo</div>';
            return;
        }

        // Summary
        const cnt = { pendiente:0, en_proceso:0, completado:0, cancelado:0 };
        ots.forEach(o => { if (cnt[o.estado] !== undefined) cnt[o.estado]++; });
        document.getElementById('calpSummary').innerHTML = `
            <div class="calp-summary">
                <div class="calp-sum-item"><div class="calp-sum-num" style="color:#4caf50">${cnt.en_proceso}</div><div class="calp-sum-lbl">En proceso</div></div>
                <div class="calp-sum-item"><div class="calp-sum-num" style="color:#FF9800">${cnt.pendiente}</div><div class="calp-sum-lbl">Pendientes</div></div>
                <div class="calp-sum-item"><div class="calp-sum-num" style="color:#1565c0">${cnt.completado}</div><div class="calp-sum-lbl">Completadas</div></div>
                <div class="calp-sum-item"><div class="calp-sum-num" style="color:#888">${ots.length}</div><div class="calp-sum-lbl">Total OTs</div></div>
            </div>`;

        // OT rows
        const estadoLabel = { pendiente:'Pendiente', en_proceso:'En proceso', completado:'Completado', cancelado:'Cancelado' };
        let html = '';
        ots.forEach(o => {
            const reg = _fmtMinCalp(parseInt(o.tiempo_regular_min)||0);
            const ext = _fmtMinCalp(parseInt(o.tiempo_extra_min)||0);
            const tiempoHtml = reg
                ? `<div class="calp-ot-tiempo">${reg}</div>` + (ext ? `<div class="calp-ot-tiempo extra">+${ext} ext</div>` : '')
                : '';
            html += `<div class="calp-ot-row">
                <div class="calp-ot-estado ${_escCalp(o.estado)}"></div>
                <div class="calp-ot-main">
                    <div class="calp-ot-folio">${_escCalp(o.folio)}</div>
                    <div class="calp-ot-titulo">${_escCalp(o.titulo)}</div>
                    <div class="calp-ot-pieza">${_escCalp(o.pieza_nombre)}</div>
                </div>
                ${tiempoHtml}
                <span class="calp-badge-estado ${_escCalp(o.estado)}">${_escCalp(estadoLabel[o.estado]||o.estado)}</span>
            </div>`;
        });
        document.getElementById('calpOTs').innerHTML = html;
    } catch(e) {
        document.getElementById('calpOTs').innerHTML = '<div class="calp-empty" style="color:#f44336">Error de conexión.</div>';
    }
}
</script>

<?php elseif ($seccion === 'fat'): ?>
<!-- ── PRUEBAS FAT ── -->
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-clipboard-check"></i> Pruebas FAT</h2>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>

    <?php if (empty($pruebas_fat)): ?>
    <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>No hay pruebas FAT registradas.</p></div>
    <?php else: ?>
    <?php
    $estado_label = ['borrador'=>'Borrador','pendiente_ingenieria'=>'Firma: Ingeniería','pendiente_produccion'=>'Firma: Producción','pendiente_calidad'=>'Firma: Calidad','pendiente_proyectos'=>'Firma: Proyectos','completado'=>'Completo'];
    $estado_badge = ['borrador'=>'badge-pendiente','pendiente_ingenieria'=>'badge-en_proceso','pendiente_produccion'=>'badge-en_proceso','pendiente_calidad'=>'badge-en_proceso','pendiente_proyectos'=>'badge-en_proceso','completado'=>'badge-completado'];
    foreach ($pruebas_fat as $fat):
        // checklist = pasos de la Etapa 5 (título/instrucciones/campos/resultado), no el
        // {texto,ok} viejo -- "completado" ahora es un paso con resultado ya capturado.
        $checklist = json_decode($fat['checklist'], true) ?? [];
        $total_ch  = count($checklist);
        $ok_ch     = count(array_filter($checklist, fn($i) => !empty($i['resultado'])));
        // Botón "PDF" del header: solo tiene sentido si el Protocolo FAT subido es
        // efectivamente un PDF -- ya no exporta el checklist de Etapa 5 (código muerto
        // quitado, ver api/vista_pdf_fat.php), abre/imprime el documento real subido.
        $esPdfSubido = $fat['fp_archivo_ruta'] && strtolower(pathinfo($fat['fp_archivo_ruta'], PATHINFO_EXTENSION)) === 'pdf';
    ?>
    <div class="ot-card estado-<?= $fat['estado'] === 'completado' ? 'completado' : ($fat['entregado'] ? 'completado' : 'pendiente') ?>" style="margin-bottom:16px;">
        <div class="ot-header" onclick="toggleFAT(<?= $fat['id'] ?>)" style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;cursor:pointer;">
            <div style="flex:1;">
                <div class="ot-titulo">
                    <span class="badge <?= $estado_badge[$fat['estado']] ?>"><?= $estado_label[$fat['estado']] ?></span>
                    <?php if ($fat['entregado']): ?><span class="badge badge-completado" style="margin-left:4px;"><i class="fas fa-box"></i> Entregado</span><?php endif; ?>
                    &nbsp;<strong><?= htmlspecialchars($fat['folio']) ?></strong> — <?= htmlspecialchars($fat['titulo']) ?>
                </div>
                <div class="ot-meta" style="margin-top:4px;">
                    <?php if ($fat['ot_titulo']): ?><span><i class="fas fa-tasks"></i> OT: <?= htmlspecialchars($fat['ot_titulo']) ?><?= $fat['ot_pieza'] ? ' — ' . htmlspecialchars($fat['ot_pieza']) : '' ?></span><?php endif; ?>
                    <?php if ($fat['proyecto_nombre']): ?><span><i class="fas fa-folder"></i> <?= htmlspecialchars($fat['proyecto_nombre']) ?></span><?php endif; ?>
                    <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($fat['fecha_creacion'])) ?></span>
                    <?php if ($total_ch > 0): ?><span><i class="fas fa-list-check"></i> <?= $ok_ch ?>/<?= $total_ch ?> ítems</span><?php endif; ?>
                </div>
            </div>
            <?php if ($esPdfSubido): ?>
            <button type="button" class="btn btn-outline btn-sm" onclick="event.stopPropagation(); descargarPdfFat(<?= htmlspecialchars(json_encode($fat['fp_archivo_ruta']), ENT_QUOTES) ?>)"><i class="fas fa-file-pdf"></i> PDF</button>
            <?php endif; ?>
            <i class="fas fa-chevron-down" id="fat-icon-<?= $fat['id'] ?>" style="font-size:.9rem;color:#999;margin-top:4px;transition:transform .2s;"></i>
        </div>

        <div id="fat-body-<?= $fat['id'] ?>" style="display:none;padding:14px 16px;border-top:1px solid #e8ecf0;">

            <!-- Descripción -->
            <?php if ($fat['descripcion']): ?>
            <div style="font-size:.8rem;color:#666;margin-bottom:12px;padding:8px 10px;background:#f8f8f8;border-radius:6px;"><?= nl2br(htmlspecialchars($fat['descripcion'])) ?></div>
            <?php endif; ?>

            <!-- ── Protocolo FAT (documento firmado en papel, subido desde Ingeniería) ──
                 Objeto distinto de "pruebas_fat" de arriba -- fat_protocolos vive por Orden de
                 Producción, no por esta prueba FAT; se enlaza por pruebas_fat_id. ── -->
            <?php if ($fat['fp_id']): ?>
            <div style="border-top:1px solid #e8ecf0;padding-top:12px;margin-top:4px;">
                <div style="font-size:.82rem;font-weight:700;color:#003d5c;margin-bottom:8px;"><i class="fas fa-file-shield"></i> Protocolo FAT (firmado en papel)</div>
                <?php
                $extArchivo = $fat['fp_archivo_ruta'] ? strtolower(pathinfo($fat['fp_archivo_ruta'], PATHINFO_EXTENSION)) : '';
                $esVisual   = in_array($extArchivo, ['pdf', 'png', 'jpg', 'jpeg']);
                ?>
                <?php if (!$fat['fp_archivo_ruta']): ?>
                <p style="font-size:.8rem;color:#888;margin-bottom:8px;"><i class="fas fa-circle-info"></i> Ingeniería aún no ha subido el Protocolo FAT.</p>
                <?php else: ?>
                    <?php if ($extArchivo === 'pdf'): ?>
                    <!-- 85vh, no un alto fijo chico -- el visor de PDF nativo del navegador no se
                         puede autoajustar al contenido (no expone cuántas páginas tiene ni su
                         zoom antes de renderizar), así que se le da todo el alto disponible de la
                         pantalla en vez de adivinar un número; se ve cortado con cualquier valor
                         fijo chico, pedido explícito del usuario. -->
                    <iframe src="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" style="width:100%;height:85vh;border:1px solid #e0e8f0;border-radius:8px;margin-bottom:8px;"></iframe>
                    <?php elseif (in_array($extArchivo, ['png','jpg','jpeg'])): ?>
                    <img src="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" style="max-width:100%;max-height:85vh;display:block;border:1px solid #e0e8f0;border-radius:8px;margin-bottom:8px;">
                    <?php else: ?>
                    <p style="font-size:.8rem;color:#888;"><i class="fas fa-file-word"></i> Documento Word — vista previa no disponible todavía.</p>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <a href="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:10px;"><i class="fas fa-<?= $esVisual ? 'up-right-from-square' : 'download' ?>"></i> <?= htmlspecialchars($fat['fp_archivo_nombre'] ?? 'Ver archivo') ?></a>
                    </div>

                    <!-- Producción ya no aprueba ni sube nada aquí -- solo lectura (2026-09-17,
                         pedido explícito del usuario). Calidad es quien sube el documento firmado. -->
                    <?php if ($fat['fp_revision_estado'] === 'aprobado'): ?>
                    <div style="font-size:.75rem;color:#2E7D32;"><i class="fas fa-check-circle"></i> Documento firmado subido por Calidad<?= $fat['fp_revision_fecha'] ? ' el ' . date('d/m/Y H:i', strtotime($fat['fp_revision_fecha'])) : '' ?>.</div>
                    <?php else: ?>
                    <div style="font-size:.75rem;color:#888;"><i class="fas fa-hourglass-half"></i> Pendiente de que Calidad suba el documento firmado.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Entregar -->
            <?php if ($fat['estado'] === 'completado' && !$fat['entregado']): ?>
            <form method="POST" enctype="multipart/form-data" style="border-top:1px solid #e0e8f0;padding-top:12px;">
                <input type="hidden" name="accion" value="entregar_fat">
                <input type="hidden" name="fat_id" value="<?= $fat['id'] ?>">
                <div style="font-size:.82rem;font-weight:700;color:#003d5c;margin-bottom:8px;"><i class="fas fa-box"></i> Marcar como entregado</div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="file" name="fat_documento" accept=".pdf,.png,.jpg,.jpeg" class="form-input" style="flex:1;min-width:200px;">
                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Marcar entregado</button>
                </div>
                <small style="color:#888;font-size:.72rem;">Adjunta el documento firmado (PDF o imagen). Opcional.</small>
            </form>
            <?php elseif ($fat['entregado'] && $fat['documento_ruta']): ?>
            <div style="border-top:1px solid #e0e8f0;padding-top:10px;">
                <a href="uploads/<?= htmlspecialchars($fat['documento_ruta']) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-file-alt"></i> Ver documento</a>
                <span style="font-size:.75rem;color:#2E7D32;margin-left:8px;"><i class="fas fa-check-circle"></i> Entregado el <?= $fat['fecha_entrega'] ? date('d/m/Y', strtotime($fat['fecha_entrega'])) : '' ?></span>
            </div>
            <?php endif; ?>

            <!-- Eliminar -->
            <?php if ($fat['estado'] === 'borrador' && $fat['creado_por'] == $_SESSION['user_id']): ?>
            <form method="POST" style="margin-top:10px;" onsubmit="return confirm('¿Eliminar esta prueba FAT?')">
                <input type="hidden" name="accion" value="eliminar_fat">
                <input type="hidden" name="fat_id" value="<?= $fat['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Eliminar</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php elseif ($seccion === 'inventario_proyectos'): ?>
<!-- ── INVENTARIO POR PROYECTO (movido de Almacén, 2026-08-05) ── -->
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-folder-tree"></i> Inventario por Proyecto</h2>
        </div>
    </div>

    <div class="inventario-layout">
        <div class="carpetas-panel">
            <div class="carpetas-header">
                <span><i class="fas fa-folder"></i> Proyectos</span>
            </div>
            <div style="padding:6px 8px;">
                <input type="text" id="searchCarpetasProyecto" placeholder="Buscar proyecto..." oninput="filtrarCarpetasProyecto(this.value)"
                       style="width:100%;padding:5px 8px;border:1.5px solid #dde3ea;border-radius:6px;font-size:12px;font-family:'Montserrat',sans-serif;outline:none;">
            </div>
            <div class="carpetas-list" id="carpetasListProyecto">
                <?php if (empty($proyectos_con_inventario)): ?>
                <div style="padding:15px;color:#999;font-size:12px;">Ningún proyecto tiene inventario asignado todavía</div>
                <?php else: foreach ($proyectos_con_inventario as $pci): ?>
                <div class="carpeta-item" data-id="<?php echo $pci['id']; ?>" onclick="verInventarioProyecto(<?php echo $pci['id']; ?>, this)">
                    <i class="fas fa-folder"></i> <?php echo htmlspecialchars($pci['nombre']); ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="carpetas-inventario-content">
            <div class="content-panel" style="flex:1;overflow-y:auto;padding:15px;">
                <div id="inventarioProyectoVacio" style="text-align:center;color:#999;padding:60px 20px;">
                    <i class="fas fa-hand-pointer" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                    Selecciona un proyecto para ver su inventario específico
                </div>
                <table class="info-table" id="inventarioProyectoTabla" style="display:none;">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Material</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Requerida</th>
                            <th>Asignada</th>
                            <th>Stock Actual</th>
                            <th>Diferencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="inventarioProyectoBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    var itemsPorProyecto = <?php echo json_encode($items_por_proyecto ?? new stdClass(), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    function filtrarCarpetasProyecto(q) {
        q = q.toLowerCase().trim();
        document.querySelectorAll('#carpetasListProyecto .carpeta-item').forEach(function(el) {
            el.style.display = (!q || el.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
    }

    function verInventarioProyecto(proyectoId, el) {
        document.querySelectorAll('#carpetasListProyecto .carpeta-item').forEach(function(i) { i.classList.remove('active'); });
        if (el) el.classList.add('active');

        var items = itemsPorProyecto[proyectoId] || [];
        var body = document.getElementById('inventarioProyectoBody');
        body.innerHTML = '';

        function tdTexto(valor) {
            var td = document.createElement('td');
            td.textContent = valor;
            return td;
        }

        items.forEach(function(it) {
            var tr = document.createElement('tr');
            var diferencia = it.diferencia;
            var colorDif = diferencia < 0 ? '#c0392b' : '#2E7D32';
            tr.appendChild(tdTexto(it.material_codigo || '-'));
            tr.appendChild(tdTexto(it.descripcion || it.item || '-'));
            tr.appendChild(tdTexto(it.marca || '-'));
            tr.appendChild(tdTexto(it.modelo || '-'));
            tr.appendChild(tdTexto(it.cantidad_requerida));
            tr.appendChild(tdTexto(it.cantidad_asignada));
            tr.appendChild(tdTexto(it.stock_actual + ' ' + (it.unidad_medida || '')));
            var tdDif = tdTexto(diferencia);
            tdDif.style.color = colorDif;
            tdDif.style.fontWeight = '600';
            tr.appendChild(tdDif);
            tr.appendChild(tdTexto(it.estado || '-'));
            body.appendChild(tr);
        });

        document.getElementById('inventarioProyectoVacio').style.display = 'none';
        document.getElementById('inventarioProyectoTabla').style.display = items.length ? 'table' : 'none';
        if (!items.length) {
            document.getElementById('inventarioProyectoVacio').style.display = 'block';
            document.getElementById('inventarioProyectoVacio').innerHTML = 'Este proyecto no tiene materiales asignados';
        }
    }
</script>

<?php elseif ($seccion === 'notificaciones'): ?>
<style>
.aud-stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-bottom:20px; }
.aud-stat-card { background:#fff; border-radius:10px; padding:18px; box-shadow:0 2px 4px rgba(0,0,0,.08); display:flex; align-items:center; gap:14px; }
.aud-stat-icon { width:46px; height:46px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.aud-stat-icon.blue { background:#e3f2fd; color:#2196F3; }
.aud-stat-icon.green { background:#e8f5e9; color:#4CAF50; }
.aud-stat-icon.orange { background:#fff3e0; color:#FF9800; }
.aud-stat-value { font-size:24px; font-weight:800; color:#333; }
.aud-stat-label { font-size:11px; color:#777; text-transform:uppercase; letter-spacing:.3px; }
.aud-filters { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; background:#fff; padding:14px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,.08); }
.aud-filter-item { display:flex; align-items:center; gap:6px; }
.aud-filter-item label { font-size:.78rem; font-weight:700; color:#444; }
.aud-filter-item select, .aud-filter-item input { padding:7px 10px; border:1px solid #ddd; border-radius:6px; font-size:.8rem; font-family:inherit; }
.aud-table-wrap { background:#fff; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,.08); overflow-x:auto; }
.aud-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.aud-table th { background:#003d5c; color:#fff; padding:10px 14px; text-align:left; font-weight:700; white-space:nowrap; }
.aud-table td { padding:9px 14px; border-bottom:1px solid #f0f0f0; }
.aud-table tr:hover td { background:#f9fafb; }
.aud-badge { padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:800; text-transform:uppercase; display:inline-block; white-space:nowrap; }
.aud-badge.inicio { background:#e3f2fd; color:#1565c0; }
.aud-badge.pausa { background:#fff3e0; color:#e65100; }
.aud-badge.fin { background:#e8f5e9; color:#2e7d32; }
.aud-badge.reanudacion { background:#ede7f6; color:#5e35b1; }
.aud-table-footer { padding:12px 14px; background:#f9fafb; font-size:.75rem; color:#888; }
</style>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-bell"></i> Notificaciones — Auditoría de Proceso</h2>
            <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <?php
    // Vista de auditoría sobre ot_logs (inicio/pausa/fin de proceso), guardado permanentemente.
    $notif_op     = $_GET['operador'] ?? '';
    $notif_accion = $_GET['accion_f'] ?? '';
    $notif_desde  = $_GET['desde'] ?? '';
    $notif_hasta  = $_GET['hasta'] ?? '';

    $sqlN = "SELECT l.accion, l.timestamp, l.operador_nombre, l.motivo_pausa, ot.folio, ot.titulo,
                    p.nombre AS proyecto_nombre, pp.nombre AS pagina_nombre
             FROM ot_logs l
             JOIN ordenes_trabajo ot ON ot.id = l.ot_id
             JOIN proyectos p ON p.id = ot.proyecto_id
             LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
             WHERE l.accion IN ('inicio','pausa','fin')";
    $paramsN = [];
    if ($notif_op !== '')     { $sqlN .= " AND l.operador_nombre = ?"; $paramsN[] = $notif_op; }
    if ($notif_accion !== '') { $sqlN .= " AND l.accion = ?"; $paramsN[] = $notif_accion; }
    if ($notif_desde !== '')  { $sqlN .= " AND DATE(l.timestamp) >= ?"; $paramsN[] = $notif_desde; }
    if ($notif_hasta !== '')  { $sqlN .= " AND DATE(l.timestamp) <= ?"; $paramsN[] = $notif_hasta; }
    $sqlN .= " ORDER BY l.timestamp DESC LIMIT 500";
    $stmtN = $db->prepare($sqlN);
    $stmtN->execute($paramsN);
    $notifRows = $stmtN->fetchAll();

    $statsN = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN accion='inicio' THEN 1 ELSE 0 END) AS inicios,
        SUM(CASE WHEN accion='fin' THEN 1 ELSE 0 END) AS fines
        FROM ot_logs WHERE accion IN ('inicio','pausa','fin') AND DATE(timestamp) = CURDATE()")->fetch();

    $operadoresN = $db->query("SELECT DISTINCT operador_nombre FROM ot_logs WHERE operador_nombre IS NOT NULL AND operador_nombre <> '' ORDER BY operador_nombre")->fetchAll(PDO::FETCH_COLUMN);
    ?>

    <div class="aud-stats-grid">
        <div class="aud-stat-card">
            <div class="aud-stat-icon blue"><i class="fas fa-list"></i></div>
            <div><div class="aud-stat-value"><?= (int)$statsN['total'] ?></div><div class="aud-stat-label">Avisos hoy</div></div>
        </div>
        <div class="aud-stat-card">
            <div class="aud-stat-icon green"><i class="fas fa-play-circle"></i></div>
            <div><div class="aud-stat-value"><?= (int)$statsN['inicios'] ?></div><div class="aud-stat-label">Inicios hoy</div></div>
        </div>
        <div class="aud-stat-card">
            <div class="aud-stat-icon orange"><i class="fas fa-check-circle"></i></div>
            <div><div class="aud-stat-value"><?= (int)$statsN['fines'] ?></div><div class="aud-stat-label">Terminadas hoy</div></div>
        </div>
    </div>

    <form method="GET" class="aud-filters">
        <input type="hidden" name="seccion" value="notificaciones">
        <div class="aud-filter-item">
            <label>Operador:</label>
            <select name="operador" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($operadoresN as $op): ?>
                <option value="<?= htmlspecialchars($op) ?>" <?= $notif_op === $op ? 'selected' : '' ?>><?= htmlspecialchars($op) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="aud-filter-item">
            <label>Acción:</label>
            <select name="accion_f" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="inicio" <?= $notif_accion==='inicio'?'selected':'' ?>>Inicio</option>
                <option value="pausa" <?= $notif_accion==='pausa'?'selected':'' ?>>Pausa</option>
                <option value="fin" <?= $notif_accion==='fin'?'selected':'' ?>>Terminada</option>
            </select>
        </div>
        <div class="aud-filter-item"><label>Desde:</label><input type="date" name="desde" value="<?= htmlspecialchars($notif_desde) ?>" onchange="this.form.submit()"></div>
        <div class="aud-filter-item"><label>Hasta:</label><input type="date" name="hasta" value="<?= htmlspecialchars($notif_hasta) ?>" onchange="this.form.submit()"></div>
        <a href="?seccion=notificaciones" class="btn btn-outline btn-sm"><i class="fas fa-eraser"></i> Limpiar</a>
    </form>

    <div class="aud-table-wrap">
        <table class="aud-table">
            <thead>
                <tr><th>Fecha/Hora</th><th>Acción</th><th>OT</th><th>Proyecto</th><th>Página</th><th>Operador</th><th>Detalle</th></tr>
            </thead>
            <tbody>
            <?php if (empty($notifRows)): ?>
                <tr><td colspan="7" style="text-align:center;color:#aaa;padding:30px;">Sin avisos registrados</td></tr>
            <?php else: foreach ($notifRows as $n):
                $labelsN = ['inicio' => 'Inicio', 'pausa' => 'Pausa', 'fin' => 'Terminada'];
            ?>
                <tr>
                    <td><?= date('d/m/Y H:i:s', strtotime($n['timestamp'])) ?></td>
                    <td><span class="aud-badge <?= $n['accion'] ?>"><?= $labelsN[$n['accion']] ?? $n['accion'] ?></span></td>
                    <td><strong><?= htmlspecialchars($n['folio'] ?: $n['titulo']) ?></strong></td>
                    <td><?= htmlspecialchars($n['proyecto_nombre']) ?></td>
                    <td><?= htmlspecialchars($n['pagina_nombre'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($n['operador_nombre'] ?: '—') ?></td>
                    <td><?= $n['accion'] === 'pausa' && $n['motivo_pausa'] ? htmlspecialchars($n['motivo_pausa']) : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <div class="aud-table-footer">Mostrando <?= count($notifRows) ?> registros (máximo 500)</div>
    </div>
</div>

<?php elseif ($seccion === 'actividades_maquinas'): ?>
<style>
.aud-stats-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:15px; margin-bottom:20px; }
.aud-maq-row { cursor:pointer; }
.aud-maq-row td:first-child i.aud-maq-caret { width:14px; display:inline-block; transition:transform .15s; color:#888; margin-right:6px; }
.aud-maq-row.open td:first-child i.aud-maq-caret { transform:rotate(90deg); }
.aud-maq-detalle { display:none; }
.aud-maq-detalle.open { display:table-row; }
.aud-maq-detalle td { background:#f9fafb; padding:8px 20px; }
.aud-maq-chip { display:inline-block; background:#e3f2fd; color:#1565c0; border-radius:20px; padding:2px 8px; font-size:.68rem; font-weight:700; margin:1px 4px 1px 0; }
.aud-maq-field { font-size:.75rem; color:#555; margin-bottom:3px; }
.aud-maq-field strong { color:#333; }
/* Tabla de máquinas más compacta que el resto de tablas .aud-table (menos aire
   por fila -- eran 9 máquinas fijas, ahora puede haber más y no debe estorbar
   tanto en pantalla). */
table.aud-table.aud-table-maquinas { font-size:.78rem; }
table.aud-table.aud-table-maquinas th { padding:5px 14px; }
table.aud-table.aud-table-maquinas td { padding:3px 14px; }
table.aud-table.aud-table-maquinas span[style*="border-radius:20px"] { padding:1px 8px !important; font-size:.65rem !important; }
/* Acordeón para TODA la tabla de máquinas (no solo por fila) -- colapsada por
   default para que no estorbe cuando no se necesita ver el estado de las
   máquinas, con un contador visible aunque esté cerrada. */
.aud-maquinas-toggle { display:flex; align-items:center; gap:8px; background:#fff; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,.08); padding:12px 16px; margin-bottom:10px; cursor:pointer; font-weight:700; color:#003d5c; font-size:.88rem; }
.aud-maquinas-toggle:hover { background:#f7fafc; }
.aud-maquinas-toggle-caret { transition:transform .15s; color:#888; font-size:.8rem; }
.aud-maquinas-toggle.open .aud-maquinas-toggle-caret { transform:rotate(90deg); }
.aud-maquinas-toggle-count { margin-left:auto; background:#e3f2fd; color:#1565c0; border-radius:20px; padding:2px 10px; font-size:.75rem; font-weight:800; }
.aud-maquinas-wrap { display:none; }
.aud-maquinas-wrap.open { display:block; }
.aud-stat-card { background:#fff; border-radius:10px; padding:18px; box-shadow:0 2px 4px rgba(0,0,0,.08); display:flex; align-items:center; gap:14px; }
.aud-stat-icon { width:46px; height:46px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.aud-stat-icon.blue { background:#e3f2fd; color:#2196F3; }
.aud-stat-icon.green { background:#e8f5e9; color:#4CAF50; }
.aud-stat-icon.orange { background:#fff3e0; color:#FF9800; }
.aud-stat-value { font-size:24px; font-weight:800; color:#333; }
.aud-stat-label { font-size:11px; color:#777; text-transform:uppercase; letter-spacing:.3px; }
.aud-filters { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; background:#fff; padding:14px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,.08); }
.aud-filter-item { display:flex; align-items:center; gap:6px; }
.aud-filter-item label { font-size:.78rem; font-weight:700; color:#444; }
.aud-filter-item select, .aud-filter-item input { padding:7px 10px; border:1px solid #ddd; border-radius:6px; font-size:.8rem; font-family:inherit; }
.aud-table-wrap { background:#fff; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,.08); overflow-x:auto; }
.aud-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.aud-table th { background:#003d5c; color:#fff; padding:10px 14px; text-align:left; font-weight:700; white-space:nowrap; }
.aud-table td { padding:9px 14px; border-bottom:1px solid #f0f0f0; }
.aud-table tr:hover td { background:#f9fafb; }
.aud-badge { padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:800; text-transform:uppercase; display:inline-block; white-space:nowrap; }
.aud-badge.inicio { background:#e3f2fd; color:#1565c0; }
.aud-badge.pausa { background:#fff3e0; color:#e65100; }
.aud-badge.fin { background:#e8f5e9; color:#2e7d32; }
.aud-badge.reanudacion { background:#ede7f6; color:#5e35b1; }
.aud-table-footer { padding:12px 14px; background:#f9fafb; font-size:.75rem; color:#888; }
</style>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-cogs"></i> Lista de Actividades</h2>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('modalAgregarMaquina').style.display='flex'"><i class="fas fa-plus"></i> Agregar Máquina</button>
                <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
            </div>
        </div>
    </div>

    <?php
    $maq_f       = $_GET['maquina'] ?? '';
    $maq_accion  = $_GET['accion_f'] ?? '';
    $maq_desde   = $_GET['desde'] ?? '';
    $maq_hasta   = $_GET['hasta'] ?? '';
    $maq_operador = $_GET['operador'] ?? '';
    $maq_proyecto = $_GET['proyecto_f'] ?? '';
    $maq_proceso  = $_GET['proceso_f'] ?? '';

    // l.maquina (no ot.maquina): la máquina queda registrada en el propio log al momento
    // del evento, así queda correcto históricamente aunque la OT cambie de máquina después.
    $sqlM = "SELECT l.accion, l.timestamp, l.operador_nombre, l.maquina, l.motivo_pausa, l.piezas_terminadas,
                    ot.folio, ot.titulo, ot.proceso, p.nombre AS proyecto_nombre
             FROM ot_logs l
             JOIN ordenes_trabajo ot ON ot.id = l.ot_id
             JOIN proyectos p ON p.id = ot.proyecto_id
             WHERE l.maquina IS NOT NULL AND l.maquina <> ''";
    $paramsM = [];
    if ($maq_f !== '')        { $sqlM .= " AND l.maquina = ?"; $paramsM[] = $maq_f; }
    if ($maq_accion !== '')   { $sqlM .= " AND l.accion = ?"; $paramsM[] = $maq_accion; }
    if ($maq_desde !== '')    { $sqlM .= " AND DATE(l.timestamp) >= ?"; $paramsM[] = $maq_desde; }
    if ($maq_hasta !== '')    { $sqlM .= " AND DATE(l.timestamp) <= ?"; $paramsM[] = $maq_hasta; }
    if ($maq_operador !== '') { $sqlM .= " AND l.operador_nombre = ?"; $paramsM[] = $maq_operador; }
    if ($maq_proyecto !== '') { $sqlM .= " AND p.nombre = ?"; $paramsM[] = $maq_proyecto; }
    if ($maq_proceso !== '')  { $sqlM .= " AND ot.proceso = ?"; $paramsM[] = $maq_proceso; }
    $sqlM .= " ORDER BY l.timestamp DESC LIMIT 500";
    $stmtM = $db->prepare($sqlM);
    $stmtM->execute($paramsM);
    $maqRows = $stmtM->fetchAll();

    $statsM = $db->query("SELECT COUNT(*) AS total FROM ot_logs WHERE maquina IS NOT NULL AND maquina<>'' AND DATE(timestamp)=CURDATE()")->fetch();
    $maquinasActivasN = (int) $db->query("SELECT COUNT(DISTINCT maquina) FROM ordenes_trabajo WHERE estado='en_proceso' AND pausado=0 AND maquina IS NOT NULL AND maquina<>''")->fetchColumn();

    $maquinasLista = $db->query("SELECT DISTINCT maquina FROM ot_logs WHERE maquina IS NOT NULL AND maquina <> '' ORDER BY maquina")->fetchAll(PDO::FETCH_COLUMN);
    $operadoresLista = $db->query("SELECT DISTINCT operador_nombre FROM ot_logs WHERE operador_nombre IS NOT NULL AND operador_nombre <> '' ORDER BY operador_nombre")->fetchAll(PDO::FETCH_COLUMN);
    $proyectosListaM = $db->query("SELECT DISTINCT p.nombre FROM ot_logs l JOIN ordenes_trabajo ot ON ot.id = l.ot_id JOIN proyectos p ON p.id = ot.proyecto_id WHERE l.maquina IS NOT NULL AND l.maquina <> '' ORDER BY p.nombre")->fetchAll(PDO::FETCH_COLUMN);
    $procesosListaM = $db->query("SELECT DISTINCT ot.proceso FROM ot_logs l JOIN ordenes_trabajo ot ON ot.id = l.ot_id WHERE l.maquina IS NOT NULL AND l.maquina <> '' AND ot.proceso IS NOT NULL AND ot.proceso <> '' ORDER BY ot.proceso")->fetchAll(PDO::FETCH_COLUMN);

    // Capacidad por máquina (pedido 2026-08-20) -- carga actual de cada máquina del
    // catálogo fijo, no solo las que ya tienen historial en ot_logs (una máquina sin
    // actividad todavía también debe verse, con 0). La capacidad TOTAL/general vive en
    // el Panel de Control (dashboard), esta es la vista de detalle por máquina.
    $cargaPorMaquina = [];
    $stmt_cargaM = $db->query("
        SELECT maquina,
               SUM(CASE WHEN estado='en_proceso' AND pausado=0 THEN 1 ELSE 0 END) AS en_proceso,
               SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes
        FROM ordenes_trabajo
        WHERE maquina IS NOT NULL AND maquina <> ''
        GROUP BY maquina
    ");
    foreach ($stmt_cargaM->fetchAll() as $row) {
        $cargaPorMaquina[$row['maquina']] = ['en_proceso' => (int)$row['en_proceso'], 'pendientes' => (int)$row['pendientes']];
    }
    $maquinasCat = produccionCatalogoMaquinas();
    ?>

    <div class="aud-maquinas-toggle" onclick="toggleTablaMaquinas(this)">
        <i class="fas fa-chevron-right aud-maquinas-toggle-caret"></i>
        <i class="fas fa-cogs"></i> Estado de Máquinas
        <span class="aud-maquinas-toggle-count"><?= count($maquinasCat) ?></span>
    </div>
    <div class="aud-table-wrap aud-maquinas-wrap" style="margin-bottom:20px;">
        <table class="aud-table aud-table-maquinas">
            <thead><tr><th></th><th>Máquina</th><th>En proceso</th><th>En cola</th><th>Carga</th></tr></thead>
            <tbody>
            <?php foreach ($maquinasCat as $cm_clave => $cm_datos):
                $cm_nombre = $cm_datos['nombre'];
                $cm = $cargaPorMaquina[$cm_nombre] ?? ['en_proceso' => 0, 'pendientes' => 0];
                $cm_total = $cm['en_proceso'] + $cm['pendientes'];
                $cm_color = $cm_total === 0 ? '#4CAF50' : ($cm_total <= 2 ? '#FF9800' : '#c62828');
                $cm_row_id = 'maqdet_' . preg_replace('/[^a-zA-Z0-9]/', '', $cm_clave);
            ?>
                <tr class="aud-maq-row" onclick="toggleMaquinaDetalle('<?= $cm_row_id ?>', this)">
                    <td style="width:24px;"><i class="fas fa-chevron-right aud-maq-caret"></i></td>
                    <td><strong><?= htmlspecialchars($cm_nombre) ?></strong></td>
                    <td><?= $cm['en_proceso'] ?></td>
                    <td><?= $cm['pendientes'] ?></td>
                    <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:800;color:#fff;background:<?= $cm_color ?>;"><?= $cm_total === 0 ? 'Libre' : "$cm_total OT(s)" ?></span></td>
                </tr>
                <tr class="aud-maq-detalle" id="<?= $cm_row_id ?>">
                    <td colspan="5">
                        <div class="aud-maq-field"><strong>TAG:</strong> <?= htmlspecialchars($cm_datos['tag'] ?: '—') ?></div>
                        <div class="aud-maq-field"><strong>Modelo:</strong> <?= htmlspecialchars($cm_datos['mod'] ?: '—') ?></div>
                        <div class="aud-maq-field"><strong>Marca:</strong> <?= htmlspecialchars($cm_datos['marca'] ?: '—') ?></div>
                        <div class="aud-maq-field"><strong>Procesos:</strong><br>
                            <?php if (!empty($cm_datos['procesos'])): foreach ($cm_datos['procesos'] as $cm_proc): ?>
                                <span class="aud-maq-chip"><?= htmlspecialchars($cm_proc) ?></span>
                            <?php endforeach; else: ?>
                                —
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="aud-stats-grid">
        <div class="aud-stat-card">
            <div class="aud-stat-icon blue"><i class="fas fa-list"></i></div>
            <div><div class="aud-stat-value"><?= (int)$statsM['total'] ?></div><div class="aud-stat-label">Actividades hoy</div></div>
        </div>
        <div class="aud-stat-card">
            <div class="aud-stat-icon green"><i class="fas fa-bolt"></i></div>
            <div><div class="aud-stat-value"><?= $maquinasActivasN ?></div><div class="aud-stat-label">Máquinas activas ahora</div></div>
        </div>
    </div>

    <!-- Modal Agregar Máquina -->
    <div id="modalAgregarMaquina" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:10px;width:90%;max-width:440px;overflow:hidden;">
            <form method="POST">
                <input type="hidden" name="accion" value="agregar_maquina">
                <input type="hidden" name="seccion" value="actividades_maquinas">
                <div style="background:#003d5c;color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <strong><i class="fas fa-plus"></i> Agregar Máquina</strong>
                    <button type="button" onclick="document.getElementById('modalAgregarMaquina').style.display='none'" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1;">&times;</button>
                </div>
                <div style="padding:20px;display:flex;flex-direction:column;gap:12px;">
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:#444;">Nombre *</label>
                        <input type="text" name="nombre" required style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-top:4px;">
                    </div>
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:#444;">TAG</label>
                        <input type="text" name="tag" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-top:4px;">
                    </div>
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:#444;">Modelo / Año</label>
                        <input type="text" name="modelo" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-top:4px;">
                    </div>
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:#444;">Marca</label>
                        <input type="text" name="marca" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-top:4px;">
                    </div>
                    <div>
                        <label style="font-size:.8rem;font-weight:700;color:#444;">Procesos (uno por línea)</label>
                        <textarea name="procesos" rows="4" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-top:4px;font-family:inherit;"></textarea>
                    </div>
                </div>
                <div style="padding:14px 20px;background:#f9fafb;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalAgregarMaquina').style.display='none'">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function toggleMaquinaDetalle(rowId, headerEl) {
        const det = document.getElementById(rowId);
        const open = det.classList.toggle('open');
        headerEl.classList.toggle('open', open);
    }
    function toggleTablaMaquinas(headerEl) {
        const wrap = document.querySelector('.aud-maquinas-wrap');
        const open = wrap.classList.toggle('open');
        headerEl.classList.toggle('open', open);
    }
    </script>

    <form method="GET" class="aud-filters">
        <input type="hidden" name="seccion" value="actividades_maquinas">
        <div class="aud-filter-item">
            <label>Máquina:</label>
            <select name="maquina" onchange="this.form.submit()">
                <option value="">Todas</option>
                <?php foreach ($maquinasLista as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>" <?= $maq_f === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="aud-filter-item">
            <label>Acción:</label>
            <select name="accion_f" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="inicio" <?= $maq_accion==='inicio'?'selected':'' ?>>Inicio</option>
                <option value="pausa" <?= $maq_accion==='pausa'?'selected':'' ?>>Pausa</option>
                <option value="reanudacion" <?= $maq_accion==='reanudacion'?'selected':'' ?>>Reanudación</option>
                <option value="fin" <?= $maq_accion==='fin'?'selected':'' ?>>Terminada</option>
            </select>
        </div>
        <div class="aud-filter-item">
            <label>Operador:</label>
            <select name="operador" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($operadoresLista as $op): ?>
                <option value="<?= htmlspecialchars($op) ?>" <?= $maq_operador === $op ? 'selected' : '' ?>><?= htmlspecialchars($op) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="aud-filter-item">
            <label>Proyecto:</label>
            <select name="proyecto_f" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($proyectosListaM as $py): ?>
                <option value="<?= htmlspecialchars($py) ?>" <?= $maq_proyecto === $py ? 'selected' : '' ?>><?= htmlspecialchars($py) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="aud-filter-item">
            <label>Proceso:</label>
            <select name="proceso_f" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($procesosListaM as $pc): ?>
                <option value="<?= htmlspecialchars($pc) ?>" <?= $maq_proceso === $pc ? 'selected' : '' ?>><?= htmlspecialchars($pc) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="aud-filter-item"><label>Desde:</label><input type="date" name="desde" value="<?= htmlspecialchars($maq_desde) ?>" onchange="this.form.submit()"></div>
        <div class="aud-filter-item"><label>Hasta:</label><input type="date" name="hasta" value="<?= htmlspecialchars($maq_hasta) ?>" onchange="this.form.submit()"></div>
        <a href="?seccion=actividades_maquinas" class="btn btn-outline btn-sm"><i class="fas fa-eraser"></i> Limpiar</a>
    </form>

    <div class="aud-table-wrap">
        <table class="aud-table">
            <thead>
                <tr><th>Fecha/Hora</th><th>Máquina</th><th>Acción</th><th>OT</th><th>Proceso</th><th>Proyecto</th><th>Operador</th><th>Detalle</th></tr>
            </thead>
            <tbody>
            <?php if (empty($maqRows)): ?>
                <tr><td colspan="8" style="text-align:center;color:#aaa;padding:30px;">Sin actividad registrada</td></tr>
            <?php else: foreach ($maqRows as $m):
                $labelsM = ['inicio' => 'Inicio', 'pausa' => 'Pausa', 'fin' => 'Terminada', 'reanudacion' => 'Reanudada'];
                $detalleM = $m['accion'] === 'pausa' && $m['motivo_pausa'] ? $m['motivo_pausa']
                          : ($m['accion'] === 'fin' && $m['piezas_terminadas'] ? $m['piezas_terminadas'] . ' pza(s)' : '—');
            ?>
                <tr>
                    <td><?= date('d/m/Y H:i:s', strtotime($m['timestamp'])) ?></td>
                    <td><strong><?= htmlspecialchars($m['maquina']) ?></strong></td>
                    <td><span class="aud-badge <?= $m['accion'] ?>"><?= $labelsM[$m['accion']] ?? $m['accion'] ?></span></td>
                    <td><?= htmlspecialchars($m['folio'] ?: $m['titulo']) ?></td>
                    <td><?= htmlspecialchars($m['proceso'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($m['proyecto_nombre']) ?></td>
                    <td><?= htmlspecialchars($m['operador_nombre'] ?: '—') ?></td>
                    <td><?= htmlspecialchars((string) $detalleM) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <div class="aud-table-footer">Mostrando <?= count($maqRows) ?> registros (máximo 500)</div>
    </div>
</div>

<?php elseif ($seccion === 'asistencia'): ?>
<style>
.asis-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:800; text-transform:uppercase; }
.asis-badge.activo { background:#e8f5e9; color:#2e7d32; }
.asis-badge.inactivo { background:#fdecea; color:#c62828; }
.asis-badge.sin_marcar { background:#f0f0f0; color:#777; }
.asis-table-wrap { background:#fff; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,.08); overflow-x:auto; margin-bottom:20px; }
.asis-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.asis-table th { background:#003d5c; color:#fff; padding:10px 14px; text-align:left; font-weight:700; white-space:nowrap; }
.asis-table td { padding:9px 14px; border-bottom:1px solid #f0f0f0; }
</style>
<?php
$puedeGestionarAsistencia = esJefeDe('proceso') || esJefeDe('manufactura') || isAdmin();

$operadores_hoy = operadoresProcesoEsperadosHoy($db);
$asis_hoy = [];
$stmt_asis = $db->query("SELECT usuario_id, estado FROM asistencia_operadores WHERE fecha = CURDATE()");
foreach ($stmt_asis->fetchAll() as $row) { $asis_hoy[(int)$row['usuario_id']] = $row['estado']; }

foreach ($operadores_hoy as &$op) {
    $op['estado_hoy'] = $asis_hoy[(int)$op['id']] ?? 'sin_marcar';
}
unset($op);

// OTs pendientes (sin iniciar) de operadores que hoy NO están confirmados como activos --
// candidatas reales a reasignar. Match por nombre porque operador_nombre es texto libre
// asignado al crear la OT, no una FK a usuarios.
$nombres_no_activos = array_column(array_filter($operadores_hoy, fn($o) => $o['estado_hoy'] !== 'activo'), 'nombre_completo');
$ots_por_reasignar = [];
if ($nombres_no_activos) {
    $ph = implode(',', array_fill(0, count($nombres_no_activos), '?'));
    $stmt_reas = $db->prepare("
        SELECT ot.id, ot.folio, ot.titulo, ot.operador_nombre, p.nombre AS proyecto_nombre
        FROM ordenes_trabajo ot
        LEFT JOIN proyectos p ON p.id = ot.proyecto_id
        WHERE ot.estado = 'pendiente' AND ot.operador_nombre IN ($ph)
        ORDER BY ot.operador_nombre, ot.fecha_creacion
    ");
    $stmt_reas->execute($nombres_no_activos);
    $ots_por_reasignar = $stmt_reas->fetchAll();
}
?>
<div class="section-content">
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-user-check"></i> Asistencia de hoy</h2>
            <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="asis-table-wrap">
        <table class="asis-table">
            <thead><tr><th>Operador</th><th>Estado hoy</th><th>Marcado</th><?php if ($puedeGestionarAsistencia): ?><th>Acción</th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (empty($operadores_hoy)): ?>
                <tr><td colspan="4" style="text-align:center;color:#aaa;padding:30px;">No hay cuentas de operador de proceso activas</td></tr>
            <?php else: foreach ($operadores_hoy as $op): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($op['nombre_completo']) ?></strong></td>
                    <td><span class="asis-badge <?= $op['estado_hoy'] ?>"><?= ucfirst(str_replace('_', ' ', $op['estado_hoy'])) ?></span></td>
                    <td><?= $op['estado_hoy'] === 'sin_marcar' ? '—' : 'hoy' ?></td>
                    <?php if ($puedeGestionarAsistencia): ?>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="usuario_id" value="<?= (int)$op['id'] ?>">
                            <?php if ($op['estado_hoy'] === 'inactivo'): ?>
                                <button type="submit" name="accion" value="marcar_activo_asistencia" class="btn btn-outline btn-sm">Marcar activo</button>
                            <?php else: ?>
                                <button type="submit" name="accion" value="marcar_inactivo_asistencia" class="btn btn-outline btn-sm" onclick="return confirm('¿Marcar a <?= htmlspecialchars(addslashes($op['nombre_completo'])) ?> como inactivo hoy?')">Marcar inactivo</button>
                            <?php endif; ?>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($puedeGestionarAsistencia): ?>
    <h3 style="margin:0 0 10px;color:#003d5c;"><i class="fas fa-exchange-alt"></i> OTs pendientes de operadores no confirmados hoy</h3>
    <div class="asis-table-wrap">
        <table class="asis-table">
            <thead><tr><th>OT</th><th>Proyecto</th><th>Operador asignado</th><th>Reasignar a</th></tr></thead>
            <tbody>
            <?php if (empty($ots_por_reasignar)): ?>
                <tr><td colspan="4" style="text-align:center;color:#aaa;padding:20px;">Sin OTs pendientes que reasignar</td></tr>
            <?php else: foreach ($ots_por_reasignar as $otr): ?>
                <tr>
                    <td><?= htmlspecialchars($otr['folio'] ?: $otr['titulo']) ?></td>
                    <td><?= htmlspecialchars($otr['proyecto_nombre'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($otr['operador_nombre']) ?></td>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;">
                            <input type="hidden" name="accion" value="reasignar_operador_ot">
                            <input type="hidden" name="ot_id" value="<?= (int)$otr['id'] ?>">
                            <input type="text" name="nuevo_operador" list="asisOperadoresList" class="form-input" style="width:160px;" placeholder="Nombre del operador" required>
                            <button type="submit" class="btn btn-primary btn-sm">Reasignar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <datalist id="asisOperadoresList">
        <?php foreach ($operadores_hoy as $op): ?>
        <option value="<?= htmlspecialchars($op['nombre_completo']) ?>">
        <?php endforeach; ?>
    </datalist>
    <?php endif; ?>
</div>

<?php endif; ?>

</div><!-- /.app-container -->

<!-- ── MODALES ── -->
<!-- ── FOOTER ── -->
<div class="dashboard-footer">
    <div class="footer-left">
        <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
        <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
    </div>
    <div class="footer-right"><img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO" onerror="this.style.display='none'"></div>
</div>

<script>
function togglePanel(id) {
    const panel = document.getElementById('panel_' + id);
    const icon  = document.getElementById('icon_' + id);
    const btn   = document.getElementById('btn_text_' + id);
    const open  = panel.classList.contains('open');
    panel.classList.toggle('open', !open);
    icon.className = open ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
    btn.textContent = open ? 'Ver materiales' : 'Cerrar';
}

function toggleRechazarPlano(id) {
    const el = document.getElementById('rechazar_form_' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function togglePlanoMenu(id) {
    const menu = document.getElementById('pmenu_' + id);
    const isOpen = menu.classList.contains('open');
    document.querySelectorAll('.plano-menu.open').forEach(m => m.classList.remove('open'));
    if (!isOpen) menu.classList.add('open');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.plano-menu')) {
        document.querySelectorAll('.plano-menu.open').forEach(m => m.classList.remove('open'));
    }
});

function toggleOT(id) {
    const body = document.getElementById('body-ot-' + id);
    const icon = document.getElementById('icon-ot-' + id);
    const open = body.style.display !== 'none' && body.style.display !== '';
    body.style.display = open ? 'none' : 'block';
    if (icon) icon.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
}

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Diccionario de maquinaria ─────────────────────────────────────────────────
// Única fuente: produccionCatalogoMaquinas() en PHP, arriba del archivo.
const MAQUINARIA = <?= json_encode(produccionCatalogoMaquinas(), JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

// ── FAT ────────────────────────────────────────────────────────────────────
function toggleFAT(id) {
    const body = document.getElementById('fat-body-' + id);
    const icon = document.getElementById('fat-icon-' + id);
    const open = body.style.display !== 'none' && body.style.display !== '';
    body.style.display = open ? 'none' : 'block';
    if (icon) icon.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
}

function descargarPdfFat(rutaArchivo) {
    let frame = document.getElementById('fatIframeImprimir');
    if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'fatIframeImprimir';
        frame.style.display = 'none';
        document.body.appendChild(frame);
    }
    frame.onload = function () { frame.contentWindow.focus(); frame.contentWindow.print(); };
    frame.src = 'uploads/' + rutaArchivo;
}

function toggleOT2(id) {
    const body = document.getElementById('body-ot-' + id);
    const btn  = document.getElementById('btn-ot2-' + id);
    const open = body.classList.contains('open');
    body.classList.toggle('open', !open);
    btn.innerHTML = open ? '<i class="fas fa-chevron-down"></i>' : '<i class="fas fa-chevron-up"></i>';
}

// ── Notificaciones de Producción — mismo patrón que la campana de Almacén: lee
// notificaciones reales y persistentes (tabla `notificaciones`), no eventos efímeros
// que solo existían mientras la pestaña estaba abierta. ──────────────────────────
// 'nuevo_plano' y 'ot_fabricada' faltaban aquí -- ingenieria.php/proceso.php ya las
// mandaban con notificarRoles(['manufactura'],...) desde antes, pero como no estaban
// en esta whitelist la campana nunca las mostraba (quedaban solo en la BD, invisibles).
const PRODUCCION_NOTIF_TIPOS = ['nuevo_subproyecto', 'calidad_ot_aprobada', 'calidad_ot_rechazada', 'nueva_orden_trabajo', 'ot_problema', 'produccion_ot_rechazada', 'equipo_fabricado_completado', 'proyecto_cerrado', 'nuevo_plano', 'ot_fabricada', 'ot_iniciada', 'asistencia_pendiente', 'reemplazo_material_pendiente', 'reemplazo_material_resuelto', 'orden_produccion_difundida', 'requisicion_actualizada', 'oc_aprobada', 'ot_tiempo_excedido', 'hoja_viajera_rechazada', 'materiales_disponibles', 'mercancia_recibida', 'bom_empresa', 'fat_protocolo_firmado', 'ot_pausada'];

let _notifHeaderAbierta = false;

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    // innerHTML solo escapa &/</> (seguro para contenido de texto) — las comillas se
    // codifican aparte para que este mismo helper también sea seguro dentro de atributos
    // (ej. data-link="${escapeHtml(...)}"), donde una comilla sin escapar rompería el atributo.
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function cargarNotificaciones() {
    fetch('includes/ajax_chat.php?action=obtener_notificaciones')
    .then(response => response.json())
    .then(data => {
        if (data.success && data.notificaciones) {
            const lista = data.notificaciones.filter(n => PRODUCCION_NOTIF_TIPOS.includes(n.tipo));
            renderizarNotificaciones(lista);
        }
    });
}

function renderizarNotificaciones(notificaciones) {
    const container = document.getElementById('notifHeaderList');
    if (!container) return;

    if (!notificaciones || notificaciones.length === 0) {
        container.innerHTML = `
            <div class="empty-chat">
                <i class="fas fa-bell-slash"></i>
                <p>No hay notificaciones</p>
            </div>
        `;
        return;
    }

    const iconos = {
        'nuevo_subproyecto':          '<i class="fas fa-drafting-compass"    style="color:#005686"></i>',
        'calidad_ot_aprobada':        '<i class="fas fa-check-circle"        style="color:#2e7d32"></i>',
        'calidad_ot_rechazada':       '<i class="fas fa-times-circle"        style="color:#c62828"></i>',
        'nueva_orden_trabajo':        '<i class="fas fa-clipboard-list"      style="color:#1565c0"></i>',
        'ot_problema':                '<i class="fas fa-exclamation-triangle" style="color:#f44336"></i>',
        'produccion_ot_rechazada':    '<i class="fas fa-undo"                style="color:#e65100"></i>',
        'equipo_fabricado_completado':'<i class="fas fa-microchip"           style="color:#00695c"></i>',
        'orden_produccion_difundida': '<i class="fas fa-broadcast-tower"     style="color:#003d5c"></i>',
        'requisicion_actualizada':    '<i class="fas fa-file-alt"            style="color:#1565c0"></i>',
        'proyecto_cerrado':           '<i class="fas fa-lock"                style="color:#2e7d32"></i>',
        'nuevo_plano':                '<i class="fas fa-file-import"         style="color:#005686"></i>',
        'ot_fabricada':               '<i class="fas fa-check-double"        style="color:#2e7d32"></i>',
        'ot_iniciada':                '<i class="fas fa-play-circle"         style="color:#1565c0"></i>',
        'oc_aprobada':                '<i class="fas fa-thumbs-up"           style="color:#2e7d32"></i>',
        'ot_tiempo_excedido':         '<i class="fas fa-hourglass-end"       style="color:#f57c00"></i>',
        'hoja_viajera_rechazada':     '<i class="fas fa-route"               style="color:#c62828"></i>',
        'materiales_disponibles':     '<i class="fas fa-boxes"               style="color:#2e7d32"></i>',
        'mercancia_recibida':         '<i class="fas fa-box-open"            style="color:#2e7d32"></i>',
        'bom_empresa':              '<i class="fas fa-industry"            style="color:#005686"></i>',
        'fat_protocolo_firmado':      '<i class="fas fa-file-shield"         style="color:#2e7d32"></i>',
        'ot_pausada':                 '<i class="fas fa-pause-circle"        style="color:#f57c00"></i>',
    };

    let html = '';
    notificaciones.forEach(notif => {
        const fecha = new Date(notif.fecha_creacion).toLocaleString();
        const icono = iconos[notif.tipo] || '<i class="fas fa-bell" style="color:#999"></i>';
        const leido = notif.leido == 1;
        html += `
            <div class="notification-item${leido ? '' : ' notif-unread'}" data-id="${Number(notif.id)}" data-link="${escapeHtml(notif.link || '')}">
                <div class="notification-title">${icono} ${escapeHtml(notif.titulo)}</div>
                <div class="notification-message">${escapeHtml(notif.mensaje)}</div>
                <div class="notification-time">${fecha}</div>
            </div>
        `;
    });
    container.innerHTML = html;
    container.querySelectorAll('.notification-item').forEach(el => {
        el.addEventListener('click', () => marcarNotificacion(el.dataset.id, el.dataset.link));
    });
}

function marcarNotificacion(id, link) {
    fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })
    .then(() => {
        if (link) {
            window.location.href = link;
        } else {
            cargarNotificaciones();
            actualizarBadgeNotifHeader();
        }
    });
}

// Marca como leídas SOLO las notificaciones de Producción (no toca las de otros módulos).
async function marcarTodasLeidasProduccion() {
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const idsProduccion = res.notificaciones.filter(n => PRODUCCION_NOTIF_TIPOS.includes(n.tipo) && n.leido == 0).map(n => n.id);
    await Promise.all(idsProduccion.map(id => fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificaciones();
    actualizarBadgeNotifHeader();
}

// Borra (no solo marca) las notificaciones de Producción ya mostradas en la campana —
// evita que la tabla notificaciones crezca indefinidamente. Solo afecta este mismo
// scope de tipos, igual que "Marcar leído".
async function borrarNotificacionesProduccion() {
    if (!confirm('¿Borrar todas las notificaciones de Producción? Esta acción no se puede deshacer.')) return;
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const idsProduccion = res.notificaciones.filter(n => PRODUCCION_NOTIF_TIPOS.includes(n.tipo)).map(n => n.id);
    await Promise.all(idsProduccion.map(id => fetch('includes/ajax_chat.php?action=borrar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificaciones();
    actualizarBadgeNotifHeader();
}

function toggleNotifHeaderDropdown() {
    _notifHeaderAbierta = !_notifHeaderAbierta;
    const dd = document.getElementById('notifHeaderDropdown');
    dd.style.display = _notifHeaderAbierta ? 'block' : 'none';
    if (_notifHeaderAbierta) cargarNotificaciones();
}
document.addEventListener('click', function(e) {
    const dd = document.getElementById('notifHeaderDropdown');
    const btn = document.getElementById('notifBellBtn');
    if (_notifHeaderAbierta && dd && !dd.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
        _notifHeaderAbierta = false;
        dd.style.display = 'none';
    }
});

function actualizarBadgeNotifHeader() {
    fetch('includes/ajax_chat.php?action=obtener_notificaciones')
    .then(r => r.json())
    .then(data => {
        const badge = document.getElementById('notifBellBadge');
        if (!badge || !data.success) return;
        const noLeidas = (data.notificaciones || []).filter(n => n.leido == 0 && PRODUCCION_NOTIF_TIPOS.includes(n.tipo)).length;
        if (noLeidas > 0) {
            badge.textContent = noLeidas > 99 ? '99+' : noLeidas;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    });
}

actualizarBadgeNotifHeader();
setInterval(actualizarBadgeNotifHeader, 15000);
</script>

<!-- ── Modal: Buscar en Inventario ───────────────────────────────── -->
<div id="modalInventarioOverlay" class="prd-modal-overlay" style="display:none;" onclick="if(event.target===this)cerrarBuscadorInventario()">
    <div class="prd-modal-box inv-box" role="dialog" aria-modal="true">
        <div class="prd-modal-head" style="background:#e65100;">
            <h3><i class="fas fa-boxes"></i> Agregar material del inventario</h3>
            <button onclick="cerrarBuscadorInventario()">&times;</button>
        </div>
        <div class="inv-search-bar">
            <input id="invSearchInput" type="text" placeholder="Buscar por código o descripción..."
                   autocomplete="off" onkeydown="if(event.key==='Enter')buscarEnInventario()">
            <button onclick="buscarEnInventario()"><i class="fas fa-search"></i> Buscar</button>
        </div>
        <div id="invResults" class="inv-results">
            <div class="inv-empty"><i class="fas fa-search" style="margin-right:6px;"></i>Escribe para buscar en el inventario...</div>
        </div>
        <div id="invQtyRow" class="inv-qty-row" style="display:none;">
            <div class="inv-qty-field">
                <label>Cantidad</label>
                <input id="invQtyInput" type="number" min="0.001" step="any" value="1"
                       onkeydown="if(event.key==='Enter')confirmarAgregarInventario()">
            </div>
            <div class="inv-qty-field">
                <label>Unidad</label>
                <div id="invUnidadVal" class="inv-unidad-val">PZA</div>
            </div>
            <div class="inv-qty-field" style="flex:1;">
                <label>Seleccionado</label>
                <div id="invSelectedLabel" class="inv-selected-label"></div>
            </div>
        </div>
        <div class="prd-modal-foot">
            <button class="prd-btn-cancel" onclick="cerrarBuscadorInventario()">Cancelar</button>
            <button class="prd-btn-primary" style="background:#e65100;" onclick="confirmarAgregarInventario()">
                <i class="fas fa-plus"></i> Agregar al BOM
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: Comparar con BOM de Ingeniería ─────────────────────── -->
<div id="modalComparadorOverlay" class="prd-modal-overlay" onclick="if(event.target===this)cerrarComparadorBOM()">
    <div class="prd-modal-box cmp-box" role="dialog" aria-modal="true">
        <div class="prd-modal-head" style="background:#1565c0;">
            <h3><i class="fas fa-code-branch"></i> Comparar con BOM de Ingeniería</h3>
            <button onclick="cerrarComparadorBOM()">&times;</button>
        </div>
        <div class="cmp-selector-row">
            <label>BOM de Ingeniería:</label>
            <select id="cmpBOMSelect"><option value="">Cargando...</option></select>
            <button onclick="ejecutarComparacion()"><i class="fas fa-exchange-alt"></i> Comparar</button>
        </div>
        <div class="cmp-legend">
            <span><span class="cmp-badge cmp-badge-faltante">Duplicado</span> Existe en ambos BOMs</span>
            <span><span class="cmp-badge cmp-badge-extra">Solo en Plano</span> Exclusivo del plano OCR</span>
            <span><span class="cmp-badge cmp-badge-ok">Solo en Ingeniería</span> No está en el plano</span>
        </div>
        <div id="cmpResultArea" class="cmp-result-area"></div>
        <div class="prd-modal-foot">
            <button class="prd-btn-cancel" onclick="cerrarComparadorBOM()">Cerrar</button>
        </div>
    </div>
</div>

</body>
</html>
