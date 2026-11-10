<?php
require_once 'includes/config.php';
require_once 'includes/equipos_fabricados.php';
require_once 'includes/generar_requisicion.php';

/** Intenta leer una fecha del campo de texto libre "fecha_entrega" (Escenario 1 del Resumen de
 * Proyecto) -- a veces trae solo "06/07/2026", a veces "16 semanas desde PO · (08-Jul-2026)".
 * dd/mm/yyyy PRIMERO, no strtotime(): PHP interpreta "06/07/2026" como mes/día (formato EUA), lo
 * que aquí daría la fecha equivocada. ponytail: no cubre todo el texto libre que la IA pueda
 * generar, solo dd/mm/yyyy, "(dd-Mes-yyyy)" entre paréntesis, y yyyy-mm-dd. */
function parsearFechaLibre(string $txt): ?DateTime {
    $txt = trim($txt);
    if ($txt === '') return null;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $txt, $m)) {
        return DateTime::createFromFormat('!Y-n-j', $m[3] . '-' . $m[2] . '-' . $m[1]) ?: null;
    }
    if (preg_match('/\((\d{1,2})-([A-Za-zÁÉÍÓÚáéíóú]+)-(\d{4})\)/u', $txt, $m)) {
        $meses = ['ene'=>1,'feb'=>2,'mar'=>3,'abr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'ago'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dic'=>12];
        $mn = $meses[mb_strtolower(mb_substr($m[2], 0, 3))] ?? 0;
        if ($mn) return DateTime::createFromFormat('!Y-n-j', $m[3] . '-' . $mn . '-' . $m[1]) ?: null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $txt)) {
        return DateTime::createFromFormat('!Y-m-d', $txt) ?: null;
    }
    return null;
}

/** Calcula los hasta-3 escenarios de fecha de entrega de un proyecto (fila de proyecto_resumen),
 * como texto -- EXACTO mismo formato que calcEscenarioN() en JS ("N Semanas desde X (dd-Mes-yyyy)")
 * para que el selector de la Orden de Producción autocomplete el campo de texto manual con el
 * mismo texto que ya se ve en las cajas verdes del Resumen IA (pedido explícito, 2026-09-07: el
 * campo de la OP sigue siendo manual/texto libre, el selector solo lo rellena). Cada escenario es
 * independiente (fecha base + sus propias semanas de plazo); uno sin fecha base capturada
 * simplemente no aparece en la lista. */
function calcularEscenariosFecha(array $r): array {
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $fmt = fn(DateTime $d) => $d->format('d') . '-' . $meses[(int)$d->format('n')] . '-' . $d->format('Y');
    $out = [];
    $base1 = parsearFechaLibre($r['fecha_entrega'] ?? '');
    if ($base1) {
        $sem1 = intval($r['semanas_plazo_po'] ?? 16);
        $d = clone $base1;
        $d->modify("+{$sem1} weeks");
        $out[] = $sem1 . ' Semanas desde PO del cliente (' . $fmt($d) . ')';
    }
    if (!empty($r['fecha_anticipo'])) {
        $sem2 = intval($r['semanas_plazo_anticipo'] ?? 16);
        $d = new DateTime($r['fecha_anticipo']);
        $d->modify("+{$sem2} weeks");
        $out[] = $sem2 . ' Semanas desde anticipo del cliente (' . $fmt($d) . ')';
    }
    // Ya NO depende de fecha_anticipo (pedido explícito, 2026-09-07) -- cada escenario es un
    // valor independiente, no una combinación/MAX de dos fechas.
    if (!empty($r['fecha_aprobacion_ing'])) {
        $sem3 = intval($r['semanas_plazo'] ?? 16);
        $d = new DateTime($r['fecha_aprobacion_ing']);
        $d->modify("+{$sem3} weeks");
        $out[] = $sem3 . ' Semanas desde aprobación de ingeniería (' . $fmt($d) . ')';
    }
    return $out;
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

if (!isLoggedIn()) redirect('index.php');
if (!hasAcceso('proyectos') && !isAdmin()) redirect('Menu.php');

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;
$seccion = $_GET['seccion'] ?? '';

$db = getDB();

// ── Crear tabla proyecto_resumen si no existe ────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS proyecto_resumen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id INT NOT NULL,
        numero_po VARCHAR(100),
        cliente VARCHAR(255),
        descripcion TEXT,
        items_json LONGTEXT,
        fecha_entrega VARCHAR(150),
        normas_json TEXT,
        condiciones TEXT,
        observaciones TEXT,
        plan_inspeccion_json LONGTEXT,
        archivo_po VARCHAR(500),
        archivo_ov VARCHAR(500),
        analizado_ia TINYINT(1) DEFAULT 0,
        creado_por INT,
        fecha_creacion DATETIME DEFAULT NOW(),
        fecha_actualizacion DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ya existe */ }

// ── Migración: columnas de escenarios de fecha en proyecto_resumen ───────────
foreach ([
    'fecha_anticipo'      => "DATE NULL",
    'fecha_aprobacion_ing'=> "DATE NULL",
    'semanas_plazo'       => "TINYINT UNSIGNED NOT NULL DEFAULT 16",
] as $_col => $_def) {
    try {
        $db->exec("ALTER TABLE proyecto_resumen ADD COLUMN $_col $_def");
    } catch (Exception $e) { /* ya existe */ }
}
unset($_col, $_def);

// ── Crear tabla documentos_referencia si no existe ───────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS documentos_referencia (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id     INT NULL,
        nombre          VARCHAR(255) NOT NULL,
        tipo            ENUM('norma','especificacion','otro') NOT NULL DEFAULT 'norma',
        idioma          ENUM('es','en','otro') NOT NULL DEFAULT 'es',
        descripcion     TEXT,
        archivo_ruta    VARCHAR(500),
        texto_extraido  MEDIUMTEXT,
        paginas         INT,
        estado          ENUM('pendiente','procesado','sin_texto','error') DEFAULT 'pendiente',
        subido_por      INT,
        fecha_creacion  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (proyecto_id),
        INDEX (subido_por)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ya existe */ }

// ── Filtros ──────────────────────────────────────────────────────────────────
$filtro_estado      = $_GET['filtro_estado']  ?? '';
$filtro_busqueda    = trim($_GET['busqueda']   ?? '');
$filtro_responsable = intval($_GET['filtro_resp'] ?? 0);
$filtro_fecha_oc    = trim($_GET['filtro_fecha_oc'] ?? '');

// Verificar si la columna 'tipo' existe antes de usarla en el WHERE
$col_tipo_existe = false;
try {
    $chk_col = $db->query("SHOW COLUMNS FROM proyectos LIKE 'tipo'");
    $col_tipo_existe = ($chk_col->rowCount() > 0);
} catch (Exception $e) {}

$where_parts = ["p.nombre NOT LIKE '[OC PENDIENTE]%'"];
if ($col_tipo_existe) {
    $where_parts[] = "(p.tipo = 'ingenieria' OR p.tipo IS NULL)";
}
$params_q    = [];

if ($filtro_estado !== '') {
    $where_parts[] = "p.estado = ?";
    $params_q[] = $filtro_estado;
}
if ($filtro_busqueda !== '') {
    $where_parts[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
    $params_q[] = "%$filtro_busqueda%";
    $params_q[] = "%$filtro_busqueda%";
}
if ($filtro_responsable > 0) {
    $where_parts[] = "p.creado_por = ?";
    $params_q[] = $filtro_responsable;
}

$where_sql = implode(' AND ', $where_parts);

$stmt_proy = $db->prepare("SELECT p.*, u.nombre_completo AS creado_por_nombre,
        (SELECT COUNT(*) FROM boom_listas bl WHERE bl.proyecto_id = p.id) AS total_boms
 FROM proyectos p
 LEFT JOIN usuarios u ON p.creado_por = u.id
 WHERE $where_sql
 ORDER BY p.fecha_creacion DESC");
$stmt_proy->execute($params_q);
$proyectos = $stmt_proy->fetchAll();

// ── BOM items (para el panel expandible) ────────────────────────────────────
$bom_rows = $db->query(
    "SELECT bl.proyecto_id, bl.id AS lista_id, bl.nombre AS lista_nombre,
            bl.guardado AS bl_guardado, bl.tipo_lista,
            bi.item, bi.descripcion, bi.cantidad,
            m.codigo, m.descripcion AS mat_nombre
     FROM boom_listas bl
     JOIN boom_items bi ON bi.boom_id = bl.id
     LEFT JOIN materiales m ON bi.material_id = m.id
     ORDER BY bl.proyecto_id, bl.nombre, bi.item"
)->fetchAll();

$boms_por_proyecto = [];
$listas_guardado   = []; // lista_id => guardado
foreach ($bom_rows as $r) {
    $boms_por_proyecto[$r['proyecto_id']][] = $r;
    $listas_guardado[$r['lista_id']] = (int)$r['bl_guardado'];
}

// ── Boom_listas guardadas por proyecto (FIX: independiente de boom_items) ───
$bl_guardadas_por_proy = []; // proyecto_id => ['total'=>N, 'guardadas'=>M]
$stmt_bl = $db->query("SELECT proyecto_id, COUNT(*) as total,
    SUM(CASE WHEN guardado=1 THEN 1 ELSE 0 END) as guardadas
    FROM boom_listas GROUP BY proyecto_id");
foreach ($stmt_bl->fetchAll() as $r) {
    $bl_guardadas_por_proy[$r['proyecto_id']] = $r;
}

// ── Estado del proceso de compras por proyecto (por ID y por nombre) ─────────
$compras_estado_map = []; // clave = proyecto_id
$nivel_labels = [0=>'Sin gestión',1=>'Req. enviada',2=>'Cot. enviada',3=>'Cot. recibida',4=>'OC en aprobación',5=>'OC aprobada',6=>'Recibido'];
$nivel_colors = [0=>'#9e9e9e',1=>'#e65100',2=>'#f57f17',3=>'#1565c0',4=>'#7b1fa2',5=>'#2e7d32',6=>'#00695c'];

$comp_rows = $db->query(
    "SELECT oc.proyecto_id,
            MAX(CASE WHEN oc.estado = 'recibida'              THEN 6
                     WHEN oc.estado = 'aprobada'              THEN 5
                     WHEN oc.estado = 'pendiente_aprobacion'  THEN 4
                     ELSE 0 END) AS nivel
     FROM ordenes_compra oc
     WHERE oc.proyecto_id IS NOT NULL
     GROUP BY oc.proyecto_id"
)->fetchAll();
foreach ($comp_rows as $cr) {
    $compras_estado_map[$cr['proyecto_id']] = (int)$cr['nivel'];
}

$comp_rows2 = $db->query(
    "SELECT p.id AS proyecto_id,
            MAX(CASE WHEN oc.estado = 'recibida'             THEN 6
                     WHEN oc.estado = 'aprobada'             THEN 5
                     WHEN oc.estado = 'pendiente_aprobacion' THEN 4
                     WHEN c.estado  = 'recibida'             THEN 3
                     WHEN c.estado  = 'enviada'              THEN 2
                     WHEN r.estado  = 'pendiente'            THEN 1
                     ELSE 0 END) AS nivel
     FROM requisiciones r
     JOIN proyectos p ON p.nombre = r.proyecto
     LEFT JOIN ordenes_compra oc ON oc.requisicion_id = r.id
     LEFT JOIN cotizaciones c    ON c.requisicion_id  = r.id
     WHERE r.proyecto IS NOT NULL
     GROUP BY p.id"
)->fetchAll();
foreach ($comp_rows2 as $cr) {
    $actual = $compras_estado_map[$cr['proyecto_id']] ?? 0;
    if ((int)$cr['nivel'] > $actual) {
        $compras_estado_map[$cr['proyecto_id']] = (int)$cr['nivel'];
    }
}

// ── OCs por proyecto (para seguimiento con fechas de entrega) ────────────────
$ocs_por_proyecto = [];
try {
    $stmt_ocs_seg = $db->query("
        SELECT oc.id, oc.proyecto_id, oc.folio, oc.fecha_entrega_estimada, oc.estado,
               oc.total, oc.moneda, pr.nombre AS proveedor, oc.revision_proyectos_ok
        FROM ordenes_compra oc
        JOIN proveedores pr ON pr.id = oc.proveedor_id
        WHERE oc.proyecto_id IS NOT NULL AND oc.estado != 'rechazada'
        ORDER BY oc.proyecto_id, oc.fecha_entrega_estimada ASC
    ");
    foreach ($stmt_ocs_seg->fetchAll() as $oc_r) {
        $ocs_por_proyecto[$oc_r['proyecto_id']][] = $oc_r;
    }
} catch (Exception $e) {}

// Filtrar proyectos por fecha OC si se especificó
if (!empty($filtro_fecha_oc)) {
    $proyectos = array_values(array_filter($proyectos, function($p) use ($ocs_por_proyecto, $filtro_fecha_oc) {
        foreach ($ocs_por_proyecto[$p['id']] ?? [] as $oc_r) {
            if (!empty($oc_r['fecha_entrega_estimada']) && $oc_r['fecha_entrega_estimada'] <= $filtro_fecha_oc) return true;
        }
        return false;
    }));
}

// ── Datos para sección Documentos ───────────────────────────────────────────
$doc_proyecto_id  = intval($_GET['proyecto_id'] ?? 0);
$doc_proyecto     = null;
$doc_op           = null;
$doc_resumen      = null;
$doc_items        = [];
$doc_normas       = [];
$doc_plan         = [];
$doc_checklist    = [];
if ($seccion === 'documentos') {
    if ($doc_proyecto_id) {
        $s = $db->prepare("SELECT * FROM proyectos WHERE id=? LIMIT 1");
        $s->execute([$doc_proyecto_id]);
        $doc_proyecto = $s->fetch();

        // Orden de Producción
        try {
            $s2 = $db->prepare("SELECT * FROM ordenes_produccion WHERE numero_proyecto COLLATE utf8mb4_general_ci=? ORDER BY id DESC LIMIT 1");
            $s2->execute([$doc_proyecto['nombre'] ?? '']);
            $doc_op = $s2->fetch() ?: null;
            // Intentar también por proyecto_id si la columna existe
            if (!$doc_op) {
                try {
                    $s2b = $db->prepare("SELECT * FROM ordenes_produccion WHERE proyecto_id=? ORDER BY id DESC LIMIT 1");
                    $s2b->execute([$doc_proyecto_id]);
                    $doc_op = $s2b->fetch() ?: null;
                } catch (Exception $eb) {}
            }
            if ($doc_op) {
                $doc_checklist = json_decode($doc_op['checklist'] ?? '{}', true) ?: [];
                $doc_op_items  = json_decode($doc_op['items']    ?? '[]', true) ?: [];
            }
        } catch (Exception $e) {}

        // Resumen IA + Plan de Inspección
        $s3 = $db->prepare("SELECT * FROM proyecto_resumen WHERE proyecto_id=? LIMIT 1");
        $s3->execute([$doc_proyecto_id]);
        $doc_resumen = $s3->fetch() ?: null;
        if ($doc_resumen) {
            $doc_items  = json_decode($doc_resumen['items_json']           ?? '[]', true) ?: [];
            $doc_normas = json_decode($doc_resumen['normas_json']          ?? '[]', true) ?: [];
            $doc_plan   = json_decode($doc_resumen['plan_inspeccion_json'] ?? '[]', true) ?: [];
        }
    }

    // Índice proyectos con documentos (para lista lateral)
    // Detectar si ordenes_produccion tiene columna proyecto_id
    $op_tiene_pid = false;
    try {
        $chk_op = $db->query("SHOW COLUMNS FROM ordenes_produccion LIKE 'proyecto_id'");
        $op_tiene_pid = ($chk_op && $chk_op->rowCount() > 0);
    } catch (Exception $e) {}

    $doc_proyectos_con_docs = [];
    try {
        $op_subq = $op_tiene_pid
            ? "SELECT COUNT(*) FROM ordenes_produccion op WHERE op.proyecto_id=p.id OR op.numero_proyecto COLLATE utf8mb4_general_ci=p.nombre COLLATE utf8mb4_general_ci"
            : "SELECT COUNT(*) FROM ordenes_produccion op WHERE op.numero_proyecto COLLATE utf8mb4_general_ci=p.nombre COLLATE utf8mb4_general_ci";
        $doc_proyectos_con_docs = $db->query("
            SELECT p.id, p.nombre, p.estado,
                   (SELECT COUNT(*) FROM proyecto_resumen pr WHERE pr.proyecto_id=p.id) AS tiene_resumen,
                   ($op_subq) AS tiene_op,
                   (SELECT COUNT(*) FROM proyecto_resumen pr2 WHERE pr2.proyecto_id=p.id AND pr2.plan_inspeccion_json IS NOT NULL AND pr2.plan_inspeccion_json NOT IN ('','[]','null')) AS tiene_plan
            FROM proyectos p
            WHERE p.nombre NOT LIKE '[OC PENDIENTE]%'
            ORDER BY p.fecha_creacion DESC
        ")->fetchAll();
    } catch (Exception $e) {
        // Fallback sin subquery de ordenes_produccion
        try {
            $doc_proyectos_con_docs = $db->query("
                SELECT p.id, p.nombre, p.estado,
                       (SELECT COUNT(*) FROM proyecto_resumen pr WHERE pr.proyecto_id=p.id) AS tiene_resumen,
                       0 AS tiene_op,
                       (SELECT COUNT(*) FROM proyecto_resumen pr2 WHERE pr2.proyecto_id=p.id AND pr2.plan_inspeccion_json IS NOT NULL AND pr2.plan_inspeccion_json NOT IN ('','[]','null')) AS tiene_plan
                FROM proyectos p
                WHERE p.nombre NOT LIKE '[OC PENDIENTE]%'
                ORDER BY p.fecha_creacion DESC
            ")->fetchAll();
        } catch (Exception $e2) {}
    }
}

// ── Lista de usuarios para filtro ────────────────────────────────────────────
$usuarios_filtro = $db->query("SELECT id, nombre_completo FROM usuarios WHERE activo = 1 ORDER BY nombre_completo")->fetchAll();

// ── Almacén y Manufactura, agregados por proyecto de una sola vez (antes eran
// 2 queries POR PROYECTO dentro del foreach de abajo — con la lista completa
// de proyectos esto corría en cada carga de esta página sin importar la
// sección, mismo patrón ya usado arriba para $bl_guardadas_por_proy) ─────────
$almacen_items_por_proy = [];
$stmt_ai_all = $db->query(
    "SELECT proyecto_id, COUNT(*) AS tot,
            SUM(CASE WHEN COALESCE(cantidad_asignada,0) >= cantidad_requerida THEN 1 ELSE 0 END) AS done
     FROM almacen_items WHERE proyecto_id IS NOT NULL GROUP BY proyecto_id"
);
foreach ($stmt_ai_all->fetchAll() as $r) {
    $almacen_items_por_proy[$r['proyecto_id']] = $r;
}

$ots_por_proy = [];
$stmt_ot_all = $db->query(
    "SELECT proyecto_id, COUNT(*) AS tot,
            SUM(CASE WHEN estado='completado' THEN 1 ELSE 0 END) AS done
     FROM ordenes_trabajo WHERE proyecto_id IS NOT NULL GROUP BY proyecto_id"
);
foreach ($stmt_ot_all->fetchAll() as $r) {
    $ots_por_proy[$r['proyecto_id']] = $r;
}

// ── Calcular días y % avance (Lógica interna) ────────────────────────────────
$hoy = new DateTime();
foreach ($proyectos as &$p) {
    $p['dias_texto'] = '';
    $p['dias_clase'] = '';
    if (!empty($p['fecha_finiquito'])) {
        $fin  = new DateTime($p['fecha_finiquito']);
        $dias = (int)$hoy->diff($fin)->format('%r%a');
        if ($dias >= 0) {
            $p['dias_texto'] = $dias === 0 ? 'Vence hoy' : "$dias días restantes";
            $p['dias_clase'] = ($dias <= 5) ? 'badge-rojo' : 'badge-verde';
        } else {
            $p['dias_texto'] = abs($dias) . ' días de retraso';
            $p['dias_clase'] = 'badge-rojo';
        }
    }
    
    // ── Ingeniería: ratio BOMs guardados ────────────────────────────────────
    $bl_data = $bl_guardadas_por_proy[$p['id']] ?? null;
    $ratio_bom = 0;
    if ($bl_data && (int)$bl_data['total'] > 0) {
        $ratio_bom = $bl_data['guardadas'] / $bl_data['total'];
    }
    $p['pct_ingenieria'] = (int)round($ratio_bom * 100);

    // ── Almacén: items asignados vs requeridos ───────────────────────────────
    $ai = $almacen_items_por_proy[$p['id']] ?? null;
    $almacen_tot  = $ai ? (int)$ai['tot']  : 0;
    $almacen_done = $ai ? (int)$ai['done'] : 0;
    $ratio_alm    = ($almacen_tot > 0) ? ($almacen_done / $almacen_tot) : 0;

    // ── Compras: nivel de OC ────────────────────────────────────────────────
    $nivel_c = $compras_estado_map[$p['id']] ?? 0;
    // OC sub-progreso: nivel 0→0%, 1→10%, 2→20%, 3→30%, nivel 4→80%, nivel 5→100%, nivel 6→100%
    if      ($nivel_c >= 5) $oc_pct = 100;
    elseif  ($nivel_c == 4) $oc_pct = 80;
    elseif  ($nivel_c == 3) $oc_pct = 30;
    elseif  ($nivel_c == 2) $oc_pct = 20;
    elseif  ($nivel_c == 1) $oc_pct = 10;
    else                    $oc_pct = 0;

    // Procura = 50% OC + 50% Almacén
    $p['pct_procura_oc']  = $oc_pct;
    $p['pct_procura_alm'] = (int)round($ratio_alm * 100);
    $p['pct_procura']     = (int)round($oc_pct * 0.5 + $ratio_alm * 100 * 0.5);

    // ── Manufactura: OTs completadas ────────────────────────────────────────
    $ot = $ots_por_proy[$p['id']] ?? null;
    $ot_tot  = $ot ? (int)$ot['tot']  : 0;
    $ot_done = $ot ? (int)$ot['done'] : 0;
    $ratio_ot = ($ot_tot > 0) ? ($ot_done / $ot_tot) : 0;
    $p['pct_manufactura'] = (int)round($ratio_ot * 100);

    // ── Porcentaje global: cada fase vale 25% ────────────────────────────────
    $pct  = (int)round($ratio_bom   * 25);   // Ingeniería
    $pct += (int)round($oc_pct * 0.005 * 25); // Procura OC: oc_pct/100*25
    $pct += (int)round($ratio_alm   * 25);   // Procura Almacén
    $pct += (int)round($ratio_ot    * 25);   // Manufactura

    $p['porcentaje_avance'] = min(100, max(0, $pct));
    // Si el proyecto está completado, forzar 100%
    if (in_array($p['estado'] ?? '', ['completado']) || ($p['realizada'] ?? false)) {
        $p['porcentaje_avance'] = 100;
    }
    $p['nivel_compras'] = $nivel_c;
}
unset($p);

// ── Fases de proyecto (para Gantt) ──────────────────────────────────────────
$fases_por_proyecto = [];
try {
    $stmt_fases = $db->query("SELECT * FROM proyecto_fases ORDER BY proyecto_id, FIELD(fase,'ingenieria','procura','manufactura','fat')");
    foreach ($stmt_fases->fetchAll() as $f) {
        $fases_por_proyecto[$f['proyecto_id']][] = $f;
    }
} catch (Exception $e) { /* tabla puede no existir */ }

// ── Detalle de proyecto individual (?ver=ID) ────────────────────────────
$ver_id = intval($_GET['ver'] ?? 0);
$ver_proyecto = null;
$ver_fases_calc = [];
$vfases_map = [];
if ($ver_id > 0 && $seccion === 'proyectos') {
    $stmt_vp = $db->prepare("SELECT p.*, u.nombre_completo AS creado_por_nombre FROM proyectos p LEFT JOIN usuarios u ON u.id=p.creado_por WHERE p.id=?");
    $stmt_vp->execute([$ver_id]);
    $ver_proyecto = $stmt_vp->fetch();
    if ($ver_proyecto) {
        // Obtener resumen (cliente)
        $stmt_vr = $db->prepare("SELECT * FROM proyecto_resumen WHERE proyecto_id=? LIMIT 1");
        $stmt_vr->execute([$ver_id]);
        $ver_resumen = $stmt_vr->fetch() ?: [];

        // Obtener orden de producción asociada
        $ver_op = null;
        try {
            $stmt_op = $db->prepare("SELECT * FROM ordenes_produccion WHERE numero_proyecto=? OR proyecto_id=? ORDER BY id DESC LIMIT 1");
            $stmt_op->execute([$ver_proyecto['nombre'], $ver_id]);
            $ver_op = $stmt_op->fetch() ?: null;
        } catch (Exception $e) {}

        // Calcular valores de cada fase para el panel de detalle
        // Ingeniería
        $vbl = $bl_guardadas_por_proy[$ver_id] ?? null;
        $v_pct_ing = 0;
        if ($vbl && (int)$vbl['total'] > 0) $v_pct_ing = (int)round($vbl['guardadas'] / $vbl['total'] * 100);

        // Compras / OC
        $v_nivel_c = $compras_estado_map[$ver_id] ?? 0;
        if      ($v_nivel_c >= 5) $v_oc_pct = 100;
        elseif  ($v_nivel_c == 4) $v_oc_pct = 80;
        elseif  ($v_nivel_c == 3) $v_oc_pct = 30;
        elseif  ($v_nivel_c == 2) $v_oc_pct = 20;
        elseif  ($v_nivel_c == 1) $v_oc_pct = 10;
        else                      $v_oc_pct = 0;

        // Almacén
        $vai_stmt = $db->prepare("SELECT COUNT(*) AS tot, SUM(CASE WHEN COALESCE(cantidad_asignada,0)>=cantidad_requerida THEN 1 ELSE 0 END) AS done FROM almacen_items WHERE proyecto_id=?");
        $vai_stmt->execute([$ver_id]);
        $vai = $vai_stmt->fetch();
        $v_alm_tot  = $vai ? (int)$vai['tot']  : 0;
        $v_alm_done = $vai ? (int)$vai['done'] : 0;
        $v_alm_pct  = ($v_alm_tot > 0) ? (int)round($v_alm_done / $v_alm_tot * 100) : 0;
        $v_pct_proc = (int)round($v_oc_pct * 0.5 + $v_alm_pct * 0.5);

        // Manufactura (OTs)
        $vot_stmt = $db->prepare("
            SELECT COUNT(*) AS tot, SUM(CASE WHEN ot.estado='completado' THEN 1 ELSE 0 END) AS done
            FROM ordenes_trabajo ot
            LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
            WHERE ot.proyecto_id=? AND (pp.ignorada IS NULL OR pp.ignorada = 0)
        ");
        $vot_stmt->execute([$ver_id]);
        $vot = $vot_stmt->fetch();
        $v_ot_tot  = $vot ? (int)$vot['tot']  : 0;
        $v_ot_done = $vot ? (int)$vot['done'] : 0;
        $v_pct_mfg = ($v_ot_tot > 0) ? (int)round($v_ot_done / $v_ot_tot * 100) : 0;

        // FAT: solo tiene sentido una vez que manufactura llegó a 100% (ahí es cuando se hace la
        // prueba física). "Completada" = Producción ya aprobó el Protocolo FAT firmado en papel
        // (fat_protocolos.revision_estado='aprobado'), mismo criterio que ya usa el resto del
        // sistema desde que las firmas dejaron de ser digitales (2026-08-28). Antes esto se
        // quedaba siempre en 0 -- las dos ramas del condicional eran el mismo valor, nunca
        // calculaba nada real ("estaba inactivo").
        $v_pct_fat = 0;
        if ($v_pct_mfg >= 100) {
            $vfat_stmt = $db->prepare("
                SELECT COUNT(*) AS tot, SUM(CASE WHEN fp.revision_estado = 'aprobado' THEN 1 ELSE 0 END) AS done
                FROM pruebas_fat pf
                LEFT JOIN fat_protocolos fp ON fp.pruebas_fat_id = pf.id
                WHERE pf.proyecto_id = ?
            ");
            $vfat_stmt->execute([$ver_id]);
            $vfat = $vfat_stmt->fetch();
            $v_fat_tot  = $vfat ? (int)$vfat['tot']  : 0;
            $v_fat_done = $vfat ? (int)$vfat['done'] : 0;
            $v_pct_fat  = ($v_fat_tot > 0) ? (int)round($v_fat_done / $v_fat_tot * 100) : 0;
        }

        // Si hay fases guardadas, usarlas en lugar del cálculo
        $vfases = $fases_por_proyecto[$ver_id] ?? [];
        $vfases_map = []; // mapa fase => fila completa para acceder a fechas/estado
        foreach ($vfases as $vf) {
            $ver_fases_calc[$vf['fase']] = $vf['pct_avance'];
            $vfases_map[$vf['fase']] = $vf;
        }
        if (empty($ver_fases_calc)) {
            $ver_fases_calc = [
                'ingenieria'  => $v_pct_ing,
                'procura'     => $v_pct_proc,
                'manufactura' => $v_pct_mfg,
                'fat'         => $v_pct_fat,
            ];
        }
        // Procura, Ingeniería, Manufactura y FAT siempre se calculan dinámicamente
        // (no usar el valor guardado en proyecto_fases — es automático)
        $ver_fases_calc['ingenieria']  = $v_pct_ing;
        $ver_fases_calc['procura']     = $v_pct_proc;
        $ver_fases_calc['manufactura'] = $v_pct_mfg;
        $ver_fases_calc['fat']         = $v_pct_fat;

        // Sub-valores para las barras internas de Procura
        $ver_fases_calc['_oc']       = $v_oc_pct;
        $ver_fases_calc['_alm']      = $v_alm_pct;
        $ver_fases_calc['_alm_tot']  = $v_alm_tot;
        $ver_fases_calc['_alm_done'] = $v_alm_done;
        $ver_fases_calc['_ot_tot']   = $v_ot_tot;
        $ver_fases_calc['_ot_done']  = $v_ot_done;
        $ver_fases_calc['_bom_total']     = $vbl['total']     ?? 0;
        $ver_fases_calc['_bom_guardadas'] = $vbl['guardadas'] ?? 0;
        $ver_fases_calc['_nivel_c']  = $v_nivel_c;
    }
}

// ── Usuarios para OT ────────────────────────────────────────────────────────
$usuarios_list = $db->query("SELECT id, nombre_completo FROM usuarios WHERE activo = 1 ORDER BY nombre_completo")->fetchAll();

// ── Planos disponibles para OT ───────────────────────────────────────────────
$planos_disponibles = $db->query(
    "SELECT bl.id, bl.pieza_nombre, bl.proyecto_id, p.nombre AS proyecto_nombre
     FROM boom_listas bl JOIN proyectos p ON p.id = bl.proyecto_id
     WHERE bl.tipo_lista='plano' ORDER BY bl.fecha_creacion DESC"
)->fetchAll();

// Conservar parámetro "from" en redirects internos
$from_param = !empty($_GET['from']) ? '&from=' . urlencode($_GET['from']) : '';

// ── Acciones POST (actualizar fases) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    
    // Crear proyecto
    if ($_POST['accion'] === 'crear_proyecto') {
        $nombre  = sanitize($_POST['nombre_proyecto'] ?? '');
        $desc    = sanitize($_POST['descripcion_proyecto'] ?? '');
        $fi      = $_POST['fecha_inicio']    ?: null;
        $ff      = $_POST['fecha_finiquito'] ?: null;
        if (!$nombre) {
            $_SESSION['proy_error'] = 'El nombre es requerido.';
        } else {
            try {
                $db->prepare("INSERT INTO proyectos (nombre, descripcion, creado_por, tipo, fecha_inicio, fecha_finiquito, fecha_creacion) VALUES (?,?,?,'ingenieria',?,?,NOW())")
                   ->execute([$nombre, $desc ?: null, $_SESSION['user_id'], $fi, $ff]);
                $nuevo_id = $db->lastInsertId();
                require_once 'includes/config.php';
                registrarEventoCalendario($nuevo_id, $nombre, 'ingenieria');
                notificarRoles(['almacen','inventario'],'nuevo_proyecto',"Nuevo proyecto: $nombre","El proyecto \"$nombre\" fue registrado.",'almacen2.php?seccion=proyectos');
            } catch (Exception $e) {
                $_SESSION['proy_error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: ?seccion=proyectos'); exit();
    }

    // Editar proyecto
    if ($_POST['accion'] === 'editar_proyecto') {
        $id   = intval($_POST['proyecto_id']);
        $nom  = sanitize($_POST['nombre_proyecto'] ?? '');
        $desc = sanitize($_POST['descripcion_proyecto'] ?? '');
        $fi   = $_POST['fecha_inicio']    ?: null;
        $ff   = $_POST['fecha_finiquito'] ?: null;
        if ($nom) {
            $db->prepare("UPDATE proyectos SET nombre=?, descripcion=?, fecha_inicio=?, fecha_finiquito=? WHERE id=?")
               ->execute([$nom, $desc ?: null, $fi, $ff, $id]);
        }
        header('Location: ?seccion=proyectos'); exit();
    }

    // Cambiar estado proyecto (Corrección de estados autorizados)
    if ($_POST['accion'] === 'cambiar_estado_proyecto') {
        $id     = intval($_POST['proyecto_id']);
        $estado = sanitize($_POST['estado'] ?? '');
        if (in_array($estado, ['pendiente','en_proceso','completado','cancelado'])) {
            $db->prepare("UPDATE proyectos SET estado=?, fecha_estado=NOW() WHERE id=?")->execute([$estado, $id]);
        }
        header('Location: ?seccion=proyectos'); exit();
    }

    // ══ Equipos Fabricados — Proyectos solo cierra el proyecto una vez que Producción
    // (produccion.php) ya completó todos los equipos; el resto de las acciones vive ahí. ══
    if ($_POST['accion'] === 'cerrar_proyecto_total') {
        $proyecto_id = intval($_POST['proyecto_id']);
        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(estado='completado') AS completados FROM equipos_fabricados WHERE proyecto_id=?");
        $stmt->execute([$proyecto_id]);
        $r = $stmt->fetch();
        if ((int)$r['total'] > 0 && (int)$r['total'] === (int)$r['completados']) {
            $stmtN = $db->prepare("SELECT nombre FROM proyectos WHERE id=?");
            $stmtN->execute([$proyecto_id]);
            $nombreProy = $stmtN->fetchColumn() ?: "Proyecto $proyecto_id";
            $db->prepare("UPDATE proyectos SET cierre_total=1, cierre_total_fecha=NOW(), cierre_total_por=? WHERE id=?")
               ->execute([$_SESSION['user_id'], $proyecto_id]);
            registrarCierreContable($db, $proyecto_id);
            $link_cierre = 'proyecto.php?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id;
            $titulo_cierre = "Proyecto cerrado: $nombreProy";
            notificarRoles(['manufactura'], 'proyecto_cerrado', $titulo_cierre,
                "Todos los equipos de \"$nombreProy\" fueron fabricados. El proyecto quedó cerrado, ya no se requieren más OTs ni fabricación.",
                $link_cierre, $_SESSION['user_id']);
            notificarRoles(['calidad'], 'proyecto_cerrado', $titulo_cierre,
                "El proyecto \"$nombreProy\" quedó cerrado. Ya no se requieren más inspecciones ni aprobaciones (OTs/OCs) de este proyecto.",
                $link_cierre, $_SESSION['user_id']);
            notificarRoles(['ingenieria'], 'proyecto_cerrado', $titulo_cierre,
                "El proyecto \"$nombreProy\" quedó cerrado. Ya no se requieren más BOMs ni planos para este proyecto.",
                $link_cierre, $_SESSION['user_id']);
            notificarRoles(['almacen'], 'proyecto_cerrado', $titulo_cierre,
                "El proyecto \"$nombreProy\" quedó cerrado. Ya no se requiere más surtido de materiales para este proyecto.",
                $link_cierre, $_SESSION['user_id']);
            notificarRoles(['compras'], 'proyecto_cerrado', $titulo_cierre,
                "El proyecto \"$nombreProy\" quedó cerrado. Ya no se requieren más cotizaciones ni órdenes de compra para este proyecto.",
                $link_cierre, $_SESSION['user_id']);
            notificarRoles(['proyectos'], 'proyecto_cerrado', $titulo_cierre,
                "El cierre total de \"$nombreProy\" se registró correctamente. Todos los equipos fueron fabricados y el proyecto no requiere más acciones.",
                $link_cierre, $_SESSION['user_id']);
        }
        header('Location: ?seccion=equipos_fabricados&proyecto_id=' . $proyecto_id); exit();
    }


    if ($_POST['accion'] === 'recalcular_progreso_ing') {
        try {
            $stmtPids  = $db->query("SELECT id FROM proyectos");
            $stmtTotal = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ?");
            $stmtGuard = $db->prepare("SELECT COUNT(*) FROM boom_listas WHERE proyecto_id = ? AND guardado = 1");
            $stmtDel   = $db->prepare("DELETE FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria' AND id = 0");
            $stmtCnt   = $db->prepare("SELECT COUNT(*) FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria'");
            $stmtUpd   = $db->prepare("UPDATE proyecto_fases SET pct_avance=?, estado=?, fecha_actualizacion=NOW(), actualizado_por=? WHERE proyecto_id=? AND fase='ingenieria'");
            $stmtIns   = $db->prepare("INSERT INTO proyecto_fases (proyecto_id,fase,nombre_display,pct_avance,estado,fecha_inicio,fecha_actualizacion,actualizado_por) VALUES (?,'ingenieria','Ingeniería',?,?,CURDATE(),NOW(),?)");

            foreach ($stmtPids->fetchAll(PDO::FETCH_COLUMN) as $pid_r) {
                $stmtTotal->execute([$pid_r]); $t = (int)$stmtTotal->fetchColumn();
                $stmtGuard->execute([$pid_r]); $g = (int)$stmtGuard->fetchColumn();
                $pct = $t > 0 ? round($g / $t * 100) : 0;
                $est = $pct >= 100 ? 'completado' : ($pct > 0 ? 'en_proceso' : 'pendiente');
                $stmtDel->execute([$pid_r]);
                $stmtCnt->execute([$pid_r]); $existe = (int)$stmtCnt->fetchColumn() > 0;
                if ($existe) {
                    $stmtUpd->execute([$pct, $est, $_SESSION['user_id'], $pid_r]);
                } else {
                    $stmtIns->execute([$pid_r, $pct, $est, $_SESSION['user_id']]);
                }
            }
            $_SESSION['proy_message'] = 'Progreso de Ingeniería actualizado para todos los proyectos.';
        } catch (Exception $e) {
            $_SESSION['proy_error'] = 'Error al recalcular: ' . $e->getMessage();
        }
        header('Location: ?seccion=proyectos'); exit();
    }

    if ($_POST['accion'] === 'actualizar_fase' && (hasAcceso('proyectos') || isAdmin())) {
        $pf_id       = intval($_POST['fase_id']   ?? 0);
        $pf_pct      = min(100, max(0, intval($_POST['pct_avance'] ?? 0)));
        $pf_estado   = sanitize($_POST['estado_fase'] ?? 'pendiente');
        $pf_fi       = $_POST['fecha_inicio']   ?: null;
        $pf_ff       = $_POST['fecha_fin_plan'] ?: null;
        $estados_ok  = ['pendiente','en_proceso','completado','cancelado'];
        if ($pf_id && in_array($pf_estado, $estados_ok)) {
            $db->prepare("UPDATE proyecto_fases SET pct_avance=?, estado=?, fecha_inicio=?, fecha_fin_plan=?, actualizado_por=? WHERE id=?")
               ->execute([$pf_pct, $pf_estado, $pf_fi, $pf_ff, $_SESSION['user_id'], $pf_id]);
        }
        header('Location: ?seccion=proyectos&' . http_build_query(array_filter(['filtro_estado'=>$filtro_estado,'busqueda'=>$filtro_busqueda,'filtro_resp'=>$filtro_responsable])));
        exit();
    }
    
    if ($_POST['accion'] === 'crear_fases_proyecto' && (hasAcceso('proyectos') || isAdmin())) {
        $pid = intval($_POST['proyecto_id']);
        $fases_def = [
            ['ingenieria','Ingeniería'],
            ['procura',   'Procura'],
            ['manufactura','Manufactura'],
            ['fat',        'Pruebas FAT'],
        ];
        foreach ($fases_def as [$slug, $nombre]) {
            $db->prepare("INSERT IGNORE INTO proyecto_fases (proyecto_id, fase, nombre_display, actualizado_por) VALUES (?,?,?,?)")
               ->execute([$pid, $slug, $nombre, $_SESSION['user_id']]);
        }
        header('Location: ?seccion=proyectos');
        exit();
    }

    // Guardar formato provisional EMPRESA EJEMPLO
    if ($_POST['accion'] === 'guardar_formato_empresa') {
        // En este bloque a futuro puedes programar el guardado en base de datos.
        // Por ahora funciona como un preparativo.
        header('Location: ?seccion=formato_trabajo');
        exit();
    }

    // ── Guardar Orden de Producción ─────────────────────────────────────────
    if ($_POST['accion'] === 'guardar_orden_produccion') {
        // strip_tags (no htmlspecialchars) a propósito -- este valor se usa para
        // buscar por nombre EXACTO contra proyectos creados por otras rutas (línea
        // ~722), que tampoco codifican entidades HTML; escapar aquí rompería ese
        // match. La protección real contra XSS va en cada punto donde se muestra.
        $op_num_proyecto  = strip_tags(trim($_POST['op_num_proyecto']  ?? ''));
        $op_fecha         = trim($_POST['op_fecha']         ?? '');
        $op_cliente       = trim($_POST['op_cliente']       ?? '');
        $op_oc            = trim($_POST['op_oc']            ?? '');
        $op_descripcion   = trim($_POST['op_descripcion']   ?? '');
        // Fecha compromiso 100% manual (texto libre, pedido explícito 2026-09-07) -- el selector
        // de arriba solo autocompleta este campo con el texto de un escenario ("N Semanas desde
        // ... (dd-Mes-yyyy)"), pero el campo se puede reescribir a mano libremente. Se extrae la
        // fecha ISO real (para fecha_compromiso en BD) de lo que haya entre paréntesis; si el
        // texto es ya una fecha ISO directa, se usa tal cual.
        $op_fecha_entrega_txt = trim($_POST['op_fecha_entrega'] ?? '');
        $op_fecha_entrega_iso = '';
        if (preg_match('/\((\d{1,2})-([A-Za-záéíóúÁÉÍÓÚ]+)-(\d{4})\)/', $op_fecha_entrega_txt, $m)) {
            $meses_num_h = ['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,'julio'=>7,'agosto'=>8,'septiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12];
            $mn_h = $meses_num_h[strtolower($m[2])] ?? 0;
            if ($mn_h) $op_fecha_entrega_iso = $m[3] . '-' . str_pad($mn_h,2,'0',STR_PAD_LEFT) . '-' . str_pad($m[1],2,'0',STR_PAD_LEFT);
        }
        if (!$op_fecha_entrega_iso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $op_fecha_entrega_txt)) {
            $op_fecha_entrega_iso = $op_fecha_entrega_txt;
        }
        $op_fecha_entrega = $op_fecha_entrega_iso ?: null; // lo que va a fecha_compromiso en BD
        $op_obs           = trim($_POST['op_obs']           ?? '');
        $ord_id           = intval($_POST['ord_id']         ?? 0);
        $ord_estado       = in_array($_POST['ord_estado'] ?? '', ['borrador','activa']) ? $_POST['ord_estado'] : 'borrador';

        // Estado ANTES de este guardado -- para saber si esto es un Borrador->Activa real
        // (difusión de verdad) o solo un guardado de una OP que ya estaba activa desde antes
        // (para no repetir el aviso cada vez que se reedita una OP ya difundida).
        $ord_estado_anterior = null;
        if ($ord_id > 0) {
            $stmtEstadoAnt = $db->prepare("SELECT estado FROM ordenes_produccion WHERE id = ?");
            $stmtEstadoAnt->execute([$ord_id]);
            $ord_estado_anterior = $stmtEstadoAnt->fetchColumn();
        }

        $tags  = $_POST['op_tag']  ?? [];
        $qtys  = $_POST['op_qty']  ?? [];
        $descs = $_POST['op_desc'] ?? [];

        // Filtrar filas vacías
        $items_arr = [];
        $item_num  = 1;
        foreach ($tags as $i => $tag) {
            $qty  = trim($qtys[$i]  ?? '');
            $desc = trim($descs[$i] ?? '');
            $tag  = trim($tag);
            if ($tag === '' && $qty === '' && $desc === '') continue;
            $items_arr[] = ['item' => $item_num++, 'tag' => $tag, 'qty' => $qty, 'desc' => $desc];
        }

        $checklist_arr = [
            'maquinaria'  => array_values($_POST['cl_maq']   ?? []),
            'control'     => array_values($_POST['cl_ctrl']  ?? []),
            'infra'       => array_values($_POST['cl_infra'] ?? []),
            'seguimiento' => array_values($_POST['cl_seg']   ?? []),
            'personal'    => array_values($_POST['cl_pers']  ?? []),
            'liberacion'  => array_values($_POST['cl_lib']   ?? []),
        ];

        $items_json     = json_encode($items_arr,    JSON_UNESCAPED_UNICODE);
        $checklist_json = json_encode($checklist_arr, JSON_UNESCAPED_UNICODE);

        $proyecto_id_ord = 0;

        try {
            // Si se va a difundir, crear o encontrar el proyecto asociado
            if ($ord_estado === 'activa' && $op_num_proyecto) {
                $stmtBusca = $db->prepare("SELECT id FROM proyectos WHERE nombre = ? LIMIT 1");
                $stmtBusca->execute([$op_num_proyecto]);
                $proy_existente = $stmtBusca->fetchColumn();

                if ($proy_existente !== false) {
                    $proyecto_id_ord = (int)$proy_existente;
                } else {
                    $proyecto_id_ord = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM proyectos")->fetchColumn();
                    $db->prepare("INSERT INTO proyectos (id, nombre, descripcion, creado_por, tipo, fecha_creacion) VALUES (?, ?, ?, ?, 'ingenieria', NOW())")
                       ->execute([
                           $proyecto_id_ord,
                           $op_num_proyecto,
                           $op_cliente ? "Cliente: $op_cliente" . ($op_descripcion ? " — $op_descripcion" : '') : $op_descripcion,
                           $_SESSION['user_id'],
                       ]);
                    registrarEventoCalendario($proyecto_id_ord, $op_num_proyecto, 'ingenieria');
                    notificarRoles(
                        ['ingenieria', 'almacen', 'compras'],
                        'nuevo_proyecto',
                        "Nuevo proyecto desde Orden de Producción: $op_num_proyecto",
                        "Proyectos difundió la Orden de Producción \"$op_num_proyecto\". El proyecto ya está disponible en todas las áreas.",
                        'ingenieria.php?seccion=proyectos'
                    );
                }

                // ── BOM EMPRESA EJEMPLO: auto-notificar + crear OT si no hay stock ──
                if ($proyecto_id_ord) {
                    $stmt_empresa = $db->prepare("
                        SELECT bi.descripcion, bi.cantidad, bi.marca, bl.pieza_nombre, bl.id as boom_id,
                               COALESCE(m.stock_disponible, 0) as stock
                        FROM boom_listas bl
                        JOIN boom_items bi ON bi.boom_id = bl.id
                        LEFT JOIN materiales m ON m.id = bi.material_id
                        WHERE bl.proyecto_id = ?
                          AND LOWER(bi.marca) LIKE '%empresa%'
                    ");
                    $stmt_empresa->execute([$proyecto_id_ord]);
                    $items_empresa = $stmt_empresa->fetchAll();

                    if (!empty($items_empresa)) {
                        $ot_count = 0;
                        foreach ($items_empresa as $ii) {
                            if ($ii['stock'] < $ii['cantidad']) {
                                // Ya existe una OT automática para este ítem del BOM (p.ej. se
                                // volvió a difundir la misma OP) -- no duplicar (pedido explícito,
                                // 2026-09-07: se reprodujo con qa_test_ot_dup, folio repetido
                                // OT-qa_test_ot_dup-AUTO01 en dos filas distintas).
                                $ya_existe = $db->prepare("SELECT id FROM ordenes_trabajo WHERE boom_lista_id = ? AND estado != 'cancelado' LIMIT 1");
                                $ya_existe->execute([$ii['boom_id']]);
                                if ($ya_existe->fetchColumn()) continue;

                                $folio_ot_auto = "OT-{$op_num_proyecto}-AUTO" . str_pad(++$ot_count, 2, '0', STR_PAD_LEFT);
                                $db->prepare("INSERT INTO ordenes_trabajo (folio, boom_lista_id, proyecto_id, titulo, descripcion, estado, creado_por, fecha_creacion)
                                              VALUES (?,?,?,?,?,'pendiente',?,NOW())")
                                   ->execute([
                                       $folio_ot_auto,
                                       $ii['boom_id'],
                                       $proyecto_id_ord,
                                       "Fabricar: " . ($ii['pieza_nombre'] ?: $ii['descripcion']),
                                       "Material EMPRESA EJEMPLO sin stock. Descripción: {$ii['descripcion']}. Cantidad requerida: {$ii['cantidad']}.",
                                       $_SESSION['user_id'],
                                   ]);
                            }
                        }
                        // Solo se avisa a manufactura si de verdad se crearon OTs nuevas en esta
                        // difusión -- si ya existían todas, re-difundir no debe reenviar el aviso.
                        if ($ot_count > 0) {
                            notificarRoles(
                                ['manufactura'],
                                'bom_empresa',
                                "Fabricación interna requerida: $op_num_proyecto",
                                count($items_empresa) . " ítem(s) de marca EMPRESA EJEMPLO detectados en el BOM del proyecto \"$op_num_proyecto\". $ot_count OT(s) creadas automáticamente por falta de stock.",
                                "produccion.php?seccion=ordenes"
                            );
                        }
                    }
                }
            }

            if ($ord_id > 0) {
                // UPDATE base (sin proyecto_id para compatibilidad)
                $db->prepare("UPDATE ordenes_produccion SET
                    numero_proyecto=?, fecha=?, cliente=?, orden_compra=?,
                    descripcion=?, fecha_compromiso=?, items=?, checklist=?,
                    observaciones=?, estado=? WHERE id=?")
                   ->execute([
                       $op_num_proyecto, $op_fecha ?: null, $op_cliente, $op_oc,
                       $op_descripcion, $op_fecha_entrega, $items_json, $checklist_json,
                       $op_obs, $ord_estado, $ord_id,
                   ]);
                // Intentar actualizar proyecto_id y difusion si la columna existe
                if ($ord_estado === 'activa') {
                    try {
                        $db->prepare("UPDATE ordenes_produccion SET difundido_por=?, fecha_difusion=NOW(), proyecto_id=? WHERE id=?")
                           ->execute([$_SESSION['user_id'], $proyecto_id_ord ?: null, $ord_id]);
                    } catch (Exception $ef) {}
                }
            } else {
                // ordenes_produccion.id ahora es AUTO_INCREMENT (antes MAX(id)+1 a mano).
                $db->prepare("INSERT INTO ordenes_produccion
                    (numero_proyecto, fecha, cliente, orden_compra, descripcion,
                     fecha_compromiso, items, checklist, observaciones, estado,
                     creado_por, fecha_creacion)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $op_num_proyecto, $op_fecha ?: null, $op_cliente,
                       $op_oc, $op_descripcion, $op_fecha_entrega, $items_json,
                       $checklist_json, $op_obs, $ord_estado,
                       $_SESSION['user_id'], date('Y-m-d H:i:s'),
                   ]);
                $ord_id = (int)$db->lastInsertId();
                // Intentar guardar proyecto_id si la columna existe (puede fallar silenciosamente)
                if ($ord_estado === 'activa' && $proyecto_id_ord) {
                    try {
                        $db->prepare("UPDATE ordenes_produccion SET proyecto_id=?, difundido_por=?, fecha_difusion=NOW() WHERE id=?")
                           ->execute([$proyecto_id_ord, $_SESSION['user_id'], $ord_id]);
                    } catch (Exception $ef) {}
                }
            }

            // Aviso de "OP difundida" (2026-09-21, bug real reportado: antes solo se avisaba
            // cuando la difusión de paso creaba un proyecto nuevo -- notificarRoles(['ingenieria',
            // 'almacen','compras'], 'nuevo_proyecto', ...) más arriba -- así que re-difundir una OP
            // de un proyecto YA existente, o difundir una segunda/tercera OP del mismo proyecto,
            // no avisaba a NADIE. Aquí sí se manda siempre que sea una transición real
            // Borrador->Activa (no en cada reedición de una OP que ya estaba activa), a las 5
            // áreas que la necesitan -- Manufactura y Calidad no estaban cubiertas ni siquiera en
            // el caso de proyecto nuevo.
            if ($ord_estado === 'activa' && $ord_estado_anterior !== 'activa') {
                notificarRoles(
                    ['ingenieria', 'almacen', 'compras', 'manufactura', 'calidad'],
                    'orden_produccion_difundida',
                    "Orden de Producción difundida: $op_num_proyecto",
                    "Proyectos difundió la Orden de Producción \"$op_num_proyecto\" a todas las áreas.",
                    'ver_orden_produccion.php?id=' . $ord_id,
                    $_SESSION['user_id']
                );
            }
        } catch (Exception $e) {
            // En caso de error igual redireccionamos
        }
        header('Location: ?seccion=formato_trabajo');
        exit();
    }

    // ── Subir PDF(s) para resumen de proyecto ───────────────────────────────
    if ($_POST['accion'] === 'subir_pdf_resumen') {
        $nombre_proy = trim($_POST['nombre_proyecto'] ?? $_POST['proyecto_id'] ?? '');
        $nombre_proy = strip_tags($nombre_proy); // no escapar htmlentities — guardamos el nombre limpio
        if (!$nombre_proy) {
            $_SESSION['resumen_error'] = 'Escribe el nombre del proyecto.';
            header('Location: ?seccion=resumen_proyecto'); exit();
        }

        try {
            // Buscar proyecto existente por nombre exacto, o crear uno nuevo
            $stmt_busca = $db->prepare("SELECT id FROM proyectos WHERE nombre = ? LIMIT 1");
            $stmt_busca->execute([$nombre_proy]);
            $pid = (int)$stmt_busca->fetchColumn();

            if (!$pid) {
                // Crear proyecto nuevo — usar MAX(id)+1 igual que el resto del sistema
                $pid = (int)$db->query("SELECT COALESCE(MAX(id),0)+1 FROM proyectos")->fetchColumn();
                $db->prepare("INSERT INTO proyectos (id, nombre, creado_por, tipo, estado, fecha_creacion) VALUES (?,?,?,'ingenieria','activo',NOW())")
                   ->execute([$pid, $nombre_proy, $_SESSION['user_id']]);
                if (!$pid) throw new Exception('No se pudo obtener el ID del proyecto.');
            }

            // Crear directorio de uploads
            $dir = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'proyectos' . DIRECTORY_SEPARATOR . $pid . DIRECTORY_SEPARATOR;
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new Exception('No se pudo crear el directorio: ' . $dir);
            }

            $ruta_po = ''; $ruta_ov = '';

            foreach (['pdf_po' => 'po', 'pdf_ov' => 'ov'] as $campo => $tipo) {
                if (empty($_FILES[$campo]['name']) || $_FILES[$campo]['error'] !== 0) continue;
                $file = $_FILES[$campo];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf') { $_SESSION['resumen_error'] = "El archivo $campo debe ser PDF."; continue; }
                if ($file['size'] > 20 * 1024 * 1024) { $_SESSION['resumen_error'] = "El PDF $campo supera 20 MB."; continue; }
                $nombre_archivo = $tipo . '_' . time() . '_' . preg_replace('/[^a-z0-9._-]/i', '_', basename($file['name']));
                $destino = $dir . $nombre_archivo;
                if (!move_uploaded_file($file['tmp_name'], $destino)) {
                    throw new Exception("No se pudo mover el archivo $campo a: $destino");
                }
                $ruta_relativa = 'proyectos/' . $pid . '/' . $nombre_archivo;
                if ($tipo === 'po') $ruta_po = $ruta_relativa;
                else                $ruta_ov = $ruta_relativa;
            }

            if (!$ruta_po && !$ruta_ov) {
                throw new Exception('No se subió ningún PDF. Verifica que el archivo sea válido y pese menos de 20 MB.');
            }

            // Upsert en proyecto_resumen
            $stmt_ex = $db->prepare("SELECT id, archivo_po, archivo_ov FROM proyecto_resumen WHERE proyecto_id = ? LIMIT 1");
            $stmt_ex->execute([$pid]);
            $row_ex = $stmt_ex->fetch();

            if ($row_ex) {
                $db->prepare("UPDATE proyecto_resumen SET archivo_po=?, archivo_ov=?, fecha_actualizacion=NOW() WHERE id=?")
                   ->execute([$ruta_po ?: $row_ex['archivo_po'], $ruta_ov ?: $row_ex['archivo_ov'], $row_ex['id']]);
            } else {
                $db->prepare("INSERT INTO proyecto_resumen (proyecto_id, archivo_po, archivo_ov, creado_por, fecha_creacion) VALUES (?,?,?,?,NOW())")
                   ->execute([$pid, $ruta_po ?: null, $ruta_ov ?: null, $_SESSION['user_id']]);
            }

            header('Location: ?seccion=resumen_proyecto&proyecto_id=' . $pid);
            exit();

        } catch (Exception $e) {
            $_SESSION['resumen_error'] = 'Error: ' . $e->getMessage();
            header('Location: ?seccion=resumen_proyecto'); exit();
        }
    }

    // ── Guardar resumen de proyecto (form completo) ──────────────────────────
    if ($_POST['accion'] === 'guardar_resumen_proyecto') {
        // strip_tags (no htmlspecialchars/sanitize()) a propósito -- mismo criterio que
        // op_descripcion en guardar_orden_produccion (línea ~677 de este archivo). sanitize()
        // aquí causaba doble escape: guardaba "6&quot; - 2500#" y luego cada pantalla que
        // muestra el resumen (línea 3037, 3779, ver_orden_produccion.php, el Protocolo FAT)
        // vuelve a escapar con htmlspecialchars() al mostrarlo -- así salía literal
        // "6&amp;quot; - 2500#" en vez de 6". El escape real va SOLO en cada punto de
        // visualización, nunca al guardar.
        $pid                = intval($_POST['proyecto_id'] ?? 0);
        $numero_po          = strip_tags(trim($_POST['numero_po']     ?? ''));
        $cliente            = strip_tags(trim($_POST['cliente']       ?? ''));
        $descripcion        = strip_tags(trim($_POST['descripcion']   ?? ''));
        $fecha_entrega      = strip_tags(trim($_POST['fecha_entrega'] ?? ''));
        $fecha_anticipo     = $_POST['fecha_anticipo']      !== '' ? ($_POST['fecha_anticipo']      ?? null) : null;
        $fecha_aprobacion_ing = $_POST['fecha_aprobacion_ing'] !== '' ? ($_POST['fecha_aprobacion_ing'] ?? null) : null;
        $semanas_plazo      = max(1, intval($_POST['semanas_plazo'] ?? 16));
        $semanas_plazo_po       = max(1, intval($_POST['semanas_plazo_po']       ?? 16));
        $semanas_plazo_anticipo = max(1, intval($_POST['semanas_plazo_anticipo'] ?? 16));
        $condiciones        = strip_tags(trim($_POST['condiciones']   ?? ''));
        $observaciones      = strip_tags(trim($_POST['observaciones'] ?? ''));
        $items_json         = $_POST['items_json']    ?? '[]';
        $normas_json        = $_POST['normas_json']   ?? '[]';
        $plan_json          = $_POST['plan_inspeccion_json'] ?? '[]';

        // Validar JSON
        if (json_decode($items_json)  === null) $items_json  = '[]';
        if (json_decode($normas_json) === null) $normas_json = '[]';
        if (json_decode($plan_json)   === null) $plan_json   = '[]';

        if ($pid) {
            $existente = $db->prepare("SELECT id FROM proyecto_resumen WHERE proyecto_id=? LIMIT 1");
            $existente->execute([$pid]);
            $row = $existente->fetch();
            if ($row) {
                $db->prepare("UPDATE proyecto_resumen SET numero_po=?,cliente=?,descripcion=?,items_json=?,fecha_entrega=?,fecha_anticipo=?,fecha_aprobacion_ing=?,semanas_plazo=?,semanas_plazo_po=?,semanas_plazo_anticipo=?,normas_json=?,condiciones=?,observaciones=?,plan_inspeccion_json=?,analizado_ia=1,fecha_actualizacion=NOW() WHERE id=?")
                   ->execute([$numero_po,$cliente,$descripcion,$items_json,$fecha_entrega,$fecha_anticipo,$fecha_aprobacion_ing,$semanas_plazo,$semanas_plazo_po,$semanas_plazo_anticipo,$normas_json,$condiciones,$observaciones,$plan_json,$row['id']]);
            } else {
                $db->prepare("INSERT INTO proyecto_resumen (proyecto_id,numero_po,cliente,descripcion,items_json,fecha_entrega,fecha_anticipo,fecha_aprobacion_ing,semanas_plazo,semanas_plazo_po,semanas_plazo_anticipo,normas_json,condiciones,observaciones,plan_inspeccion_json,analizado_ia,creado_por,fecha_creacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())")
                   ->execute([$pid,$numero_po,$cliente,$descripcion,$items_json,$fecha_entrega,$fecha_anticipo,$fecha_aprobacion_ing,$semanas_plazo,$semanas_plazo_po,$semanas_plazo_anticipo,$normas_json,$condiciones,$observaciones,$plan_json,$_SESSION['user_id']]);
            }
            // Actualizar nombre/descripción del proyecto si está vacío
            $db->prepare("UPDATE proyectos SET descripcion=COALESCE(NULLIF(descripcion,''),?) WHERE id=?")
               ->execute([$descripcion ?: null, $pid]);
            // Ya NO se empuja sola a ordenes_produccion.fecha_compromiso (pedido explícito,
            // 2026-09-07): ahora la Orden de Producción elige a mano cuál de los 3 escenarios
            // usar, vía el selector agregado en su propio formulario (ver calcularEscenariosFecha()).
        }
        header('Location: ?seccion=resumen_proyecto&proyecto_id=' . $pid . '&guardado=1');
        exit();
    }

    if ($_POST['accion'] === 'rechazar_oc_proyectos' && (hasAcceso('proyectos') || isAdmin())) {
        $oc_id = intval($_POST['oc_id'] ?? 0);
        $nota  = sanitize($_POST['nota_rechazo'] ?? '');
        if (!$oc_id || !$nota) {
            $_SESSION['proy_error'] = "OC o motivo no válido.";
        } else {
            try {
                // Info de la OC
                $stmt_oc = $db->prepare("SELECT oc.*, p.nombre AS proyecto_nombre FROM ordenes_compra oc LEFT JOIN proyectos p ON p.id=oc.proyecto_id WHERE oc.id=?");
                $stmt_oc->execute([$oc_id]);
                $oc_data = $stmt_oc->fetch();
                if (!$oc_data) throw new Exception("OC no encontrada.");
                // Antes no validaba el estado previo -- se podía rechazar una OC ya aprobada
                // o recibida (regenerando requisición de recompra y ocultando el gasto real).
                // Mismo estado de origen que api/rechazar_oc.php.
                if ($oc_data['estado'] !== 'pendiente_aprobacion') throw new Exception("La OC ya fue procesada, no se puede rechazar.");

                // Rechazar la OC
                $db->prepare("UPDATE ordenes_compra SET estado='rechazada', motivo_rechazo=? WHERE id=?")
                   ->execute(["[Proyectos] $nota", $oc_id]);

                // orden_compra.php descuenta stock al CREAR la OC (no al recibirla) --
                // si se rechaza, ese stock nunca salió de verdad y hay que restituirlo.
                // Mismo mecanismo que api/rechazar_oc.php: revertir exactamente lo que
                // quedó registrado para ESTA OC en historial_movimientos (las OCs
                // generadas desde cotizaciones no tocan stock al crearse, así que aquí
                // simplemente no hay nada que revertir para esas).
                $stmtMovsProy = $db->prepare("SELECT material_id, cantidad FROM historial_movimientos WHERE referencia_tipo = 'orden_compra' AND referencia_id = ? AND tipo_movimiento = 'salida'");
                $stmtMovsProy->execute([$oc_id]);
                foreach ($stmtMovsProy->fetchAll() as $movProy) {
                    registrarMovimientoAlmacen($db, (int)$movProy['material_id'], 'entrada', (float)$movProy['cantidad'], 'orden_compra_rechazada', $oc_id, "Reversa por rechazo de OC (Proyectos)");
                }

                // Ítems de la OC rechazada
                $items_oc = $db->prepare("SELECT * FROM orden_compra_detalle WHERE orden_id=?");
                $items_oc->execute([$oc_id]);
                $items_oc = $items_oc->fetchAll();

                // Excluir materiales ya cubiertos por otras OCs aprobadas del mismo proyecto
                $covered = [];
                if ($oc_data['proyecto_id']) {
                    $stmt_cov = $db->prepare("SELECT DISTINCT ocd.material_id FROM orden_compra_detalle ocd JOIN ordenes_compra oc2 ON oc2.id=ocd.orden_id WHERE oc2.proyecto_id=? AND oc2.estado IN ('aprobada','recibida') AND oc2.id!=? AND ocd.material_id IS NOT NULL");
                    $stmt_cov->execute([$oc_data['proyecto_id'], $oc_id]);
                    $covered = array_column($stmt_cov->fetchAll(), 'material_id');
                }
                $items_nuevos = array_values(array_filter($items_oc, fn($it) => empty($it['material_id']) || !in_array($it['material_id'], $covered)));

                $folio_req = '';
                if (!empty($items_nuevos)) {
                    $items_req = array_map(fn($it) => [
                        'material_id'   => $it['material_id'] ?: null,
                        'descripcion'   => $it['descripcion'] ?? '',
                        'cantidad'      => $it['cantidad'],
                        'unidad_medida' => $it['unidad_medida'] ?? 'pza',
                    ], $items_nuevos);
                    $req = crearRequisicion($db, [
                        'usuario_id'      => $_SESSION['user_id'],
                        'proyecto'        => $oc_data['proyecto_nombre'] ?? '',
                        'proyecto_id'     => $oc_data['proyecto_id'],
                        'area'            => 'Compras',
                        'solicitante'     => 'Proyectos',
                        'prioridad'       => 'alta',
                        'notas_generales' => "Re-cotización por OC rechazada ({$oc_data['folio']}). Motivo: $nota",
                    ], $items_req, null); // null: notificación manual abajo (mensaje trae el folio)
                    $folio_req = $req['folio'];
                    avisarDuplicado($req['duplicados'] ?? []);
                    notificarRoles(['compras'], 'oc_recotizacion', "OC rechazada por Proyectos: {$oc_data['folio']}", "La OC {$oc_data['folio']} fue rechazada por Proyectos. Motivo: $nota. Requisición $folio_req generada para re-cotizar " . count($items_nuevos) . " ítem(s).", "compras.php", $_SESSION['user_id']);
                    $_SESSION['proy_message'] = "OC {$oc_data['folio']} rechazada. Requisición de re-cotización $folio_req creada y notificada a Compras.";
                } else {
                    $_SESSION['proy_message'] = "OC {$oc_data['folio']} rechazada. Todos sus materiales ya están cubiertos por otras OCs aprobadas.";
                }
            } catch (Exception $e) {
                $_SESSION['proy_error'] = "Error: " . $e->getMessage();
            }
        }
        header("Location: ?seccion=proyectos"); exit();
    }

    if ($_POST['accion'] === 'crear_requisicion_area') {
        $resultado = procesarCrearRequisicionArea($db, 'Proyectos');
        if ($resultado['ok']) { $_SESSION['proy_message'] = $resultado['mensaje']; }
        else { $_SESSION['proy_error'] = $resultado['mensaje']; }
        // tab=mis: abre directo en "Mis Requisiciones" para ver de una vez la que se acaba de crear.
        header("Location: ?seccion=generar_requisicion&tab=mis"); exit();
    }

    // Confirma que la fecha de entrega de una OC le sirve al proyecto -- sin esto,
    // Gerencia no puede aprobarla (ver el gate en api/aprobar_oc.php). Antes no
    // existía ningún candado real entre las dos, solo un resaltado visual.
    if ($_POST['accion'] === 'confirmar_fecha_oc' && (hasAcceso('proyectos') || isAdmin())) {
        $oc_id = intval($_POST['oc_id'] ?? 0);
        if ($oc_id) {
            $db->prepare("UPDATE ordenes_compra SET revision_proyectos_ok=1, revision_proyectos_por=?, revision_proyectos_fecha=NOW() WHERE id=?")
               ->execute([$_SESSION['user_id'], $oc_id]);
            $_SESSION['proy_message'] = "Fecha de entrega confirmada. Gerencia ya puede aprobar la OC.";
        }
        header("Location: ?seccion=proyectos"); exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proyectos — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; text-decoration: none !important; }
        input, textarea { -webkit-user-select: text; user-select: text; }
        body { font-family: 'Montserrat', Arial, sans-serif; background: #ffffff; height: 100vh; overflow: hidden; }

        .top-header { position: fixed; top: 0; left: 0; width: 100%; height: 80px; background: #2E5CAE; box-shadow: 0 2px 10px rgba(46,92,174,0.25); border-bottom: 2px solid #c9a227; z-index: 1000; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }
        /* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
        .header-logo { height: 66px; width: auto; object-fit: contain; }
        .header-right { display: flex; align-items: center; gap: 20px; }
        .back-button-header { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 40px; padding: 8px 20px; cursor: pointer; font-weight: 600; color: white; display: flex; align-items: center; gap: 8px; transition: all 0.2s; font-size: 14px; }
        .back-button-header:hover { background: rgba(255,255,255,0.25); transform: translateX(-5px); }
        /* Campana de notificaciones -- mismo patrón/clases que calidad.php/produccion.php.
           Proyecto.php nunca la tuvo (bug real, 2026-09-21): notificarRoles(['proyectos'],...)
           ya se manda desde varios lados (bom_finalizado, calidad_ot_aprobada, proyecto_cerrado,
           y ahora requisicion_actualizada), pero no había ningún ícono/lista donde verlas. */
        .empty-chat { text-align:center; padding:40px 20px; color:#999; }
        .empty-chat i { font-size:48px; margin-bottom:15px; color:#ddd; }
        .notification-item { background:#e8f4fc; border-left:4px solid #FF9800; padding:12px; margin-bottom:10px; border-radius:8px; cursor:pointer; }
        .notification-item:hover { background:#e0eef8; transform:translateX(3px); }
        .notif-unread { background:#fff8e1 !important; border-left-color:#FF9800 !important; font-weight:600; }
        .notif-unread::after { content:''; display:inline-block; width:8px; height:8px; background:#FF9800; border-radius:50%; float:right; margin-top:4px; }
        .notification-title { font-weight:600; font-size:13px; color:#003d5c; margin-bottom:5px; }
        .notification-message { font-size:12px; color:#666; }
        .notification-time { font-size:10px; color:#999; margin-top:5px; }
        .user-dropdown { position: relative; display: inline-block; }
        .user-trigger { display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.15); padding: 5px 15px 5px 5px; border-radius: 40px; cursor: pointer; border: 1px solid rgba(255,255,255,0.3); transition: all 0.2s; }
        .user-trigger:hover { background: rgba(255,255,255,0.25); }
        .user-avatar { width: 35px; height: 35px; border-radius: 50%; background: #FF9800; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; color: white; }
        .user-info-text { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; color: white; font-size: 13px; line-height: 1.2; }
        .user-role { font-size: 10px; color: rgba(255,255,255,0.8); text-transform: uppercase; }
        .user-dropdown-menu { position: absolute; top: calc(100% + 5px); right: 0; width: 200px; background: white; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); opacity: 0; visibility: hidden; transform: translateY(-5px); transition: all 0.2s; border: 1px solid #e0e0e0; overflow: hidden; z-index: 1000; }
        .user-dropdown:hover .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-item { padding: 12px 20px; display: flex; align-items: center; gap: 10px; color: #333; transition: all 0.2s; cursor: pointer; border: none; background: none; width: 100%; font-size: 13px; font-family: 'Montserrat', Arial, sans-serif; }
        .dropdown-item:hover { background: #f5f5f5; color: #f44336; }
        .dropdown-item i { width: 18px; color: #666; }

        .app-container { height: 100vh; width: 100vw; padding-top: 80px; position: relative; }

        /* DASHBOARD */
        .main-center { height: calc(100vh - 140px); overflow-y: auto; display: flex; flex-direction: column; justify-content: flex-start; align-items: center; padding: 20px 20px 100px 20px; }
        .page-header { text-align: center; margin-bottom: 40px; }
        .page-title { font-size: 2.2rem; color: #003d5c; font-weight: 300; margin-bottom: 10px; }
        .page-title strong { font-weight: 800; background: linear-gradient(135deg, #003d5c, #0077b3); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 2.4rem; }
        .page-subtitle { color: #666; font-size: 1rem; }
        .cards-grid { display: flex; gap: 30px; justify-content: center; align-items: center; flex-wrap: wrap; max-width: 900px; margin: 0 auto; }
        .nav-card { width: 180px; cursor: pointer; transition: all 0.3s ease; text-align: center; }
        .nav-card:hover { transform: translateY(-8px); }
        .nav-card:hover .card-icon { border-color: #FF9800; box-shadow: 0 15px 30px rgba(255,152,0,0.25); }
        .nav-card:hover .card-title { color: #FF9800; }
        .card-icon { width: 100%; aspect-ratio: 1/1; background: linear-gradient(135deg, #f8f9fa, #ffffff); border-radius: 20px; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; border: 3px solid #e0e0e0; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(0,0,0,0.08); position: relative; overflow: hidden; }
        .card-icon::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); transition: left 0.5s ease; }
        .nav-card:hover .card-icon::before { left: 100%; }
        .card-icon i { font-size: 4rem; color: #005686; transition: all 0.3s ease; z-index: 2; }
        .nav-card:hover .card-icon i { color: #FF9800; transform: scale(1.1); }
        .card-title { font-size: 1.2rem; font-weight: 600; color: #333; transition: color 0.3s ease; margin-bottom: 5px; }
        .card-desc { font-size: 0.8rem; color: #888; font-weight: 400; }

        /* FOOTER */
        .dashboard-footer { position: fixed; bottom: 0; left: 0; right: 0; display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; border-top: 2px solid #e0e0e0; background: white; height: 60px; z-index: 1000; }
        .footer-left { display: flex; gap: 30px; color: #555; font-size: 13px; font-weight: 500; }
        .footer-left i { color: #003d5c; width: 16px; margin-right: 5px; }
        .footer-right img { height: 28px; }

        /* SECTION */
        .section-content { height: calc(100vh - 140px); padding: 0 30px 30px; overflow-y: auto; }
        .sticky-container { position: sticky; top: 0; background: white; z-index: 90; padding: 20px 0; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
        .header-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header-row h2 { font-size: 1.5rem; color: #003d5c; display: flex; align-items: center; gap: 10px; }

        /* ── ALERTS ── */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: .85rem; }
        .alert-success { background: #e8f5e9; color: #2E7D32; border-left: 4px solid #2E7D32; }
        .alert-error   { background: #fce4ec; color: #b71c1c; border-left: 4px solid #b71c1c; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }

        /* FILTROS */
        .filtros-bar { display: flex; gap: 10px; flex-wrap: wrap; padding: 12px 0 0; align-items: flex-end; }
        .filtro-grupo { display: flex; flex-direction: column; gap: 3px; }
        .filtro-label { font-size: .72rem; font-weight: 700; color: #666; }
        .filtro-input { padding: 6px 10px; border: 1.5px solid #ddd; border-radius: 7px; font-family: 'Montserrat', sans-serif; font-size: .8rem; min-width: 150px; }
        .filtro-input:focus { outline: none; border-color: #005686; }
        .btn-filtro-clear { font-size: .78rem; color: #666; cursor: pointer; text-decoration: underline !important; background: none; border: none; padding: 6px; font-family: 'Montserrat', sans-serif; }

        /* PROJECT CARDS */
        .proyectos-list { display: flex; flex-direction: column; gap: 16px; padding-bottom: 20px; }
        .proy-card { background: #fff; border-radius: 12px; box-shadow: 0 3px 12px rgba(0,0,0,0.07); border-left: 4px solid #005686; overflow: hidden; }
        .proy-card.vence-pronto { border-left-color: #e65100; }
        .proy-card.retrasado    { border-left-color: #b71c1c; }
        .proy-card-header { padding: 16px 20px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; cursor: pointer; transition: background 0.15s; }
        .proy-card-header:hover { background: #f9fafb; }
        .proy-info { flex: 1; }
        .proy-nombre { font-size: 1rem; font-weight: 700; color: #003d5c; margin-bottom: 4px; }
        .proy-desc { font-size: 0.82rem; color: #777; line-height: 1.5; margin-bottom: 6px; }
        .proy-meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .toggle-icon { font-size: 1rem; color: #999; transition: transform 0.2s; flex-shrink: 0; padding-top: 3px; }
        .proy-card.open .toggle-icon { transform: rotate(180deg); }

        /* PROGRESO */
        .progress-wrap { margin-top: 8px; }
        .progress-bar-bg { height: 5px; background: #e8ecf0; border-radius: 3px; }
        .progress-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #005686, #42a5f5); transition: width .4s; }
        .progress-label { font-size: .7rem; color: #888; margin-top: 2px; }

        /* BADGES - ESTADOS ACTUALIZADOS */
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
        .badge-verde             { background: #e8f5e9; color: #2E7D32; }
        .badge-rojo              { background: #fdecea; color: #c0392b; }
        
        .badge-estado-pendiente  { background: #fff8e1; color: #f57f17; }
        .badge-estado-en_proceso { background: #e3f2fd; color: #003d5c; }
        .badge-estado-completado { background: #e8f5e9; color: #2E7D32; }
        .badge-estado-cancelado  { background: #fdecea; color: #c0392b; }
        
        .badge-bom               { background: #f3e5f5; color: #7b1fa2; }
        /* OT badges */
        .badge-pendiente   { background: #fff3e0; color: #e65100; }
        .badge-en_proceso  { background: #e3f2fd; color: #1565c0; }
        .badge-completado  { background: #e8f5e9; color: #2E7D32; }
        .badge-cancelado   { background: #f5f5f5; color: #616161; }
        .badge-urgente     { background: #fce4ec; color: #b71c1c; }
        .badge-alta        { background: #fff8e1; color: #f57f17; }
        .badge-normal      { background: #e8eaf6; color: #3949ab; }
        .badge-baja        { background: #f3e5f5; color: #7b1fa2; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:8px; border:none; cursor:pointer; font-family:'Montserrat',sans-serif; font-size:.8rem; font-weight:600; text-decoration:none; transition:all .2s; }
        .btn-primary { background:#005686; color:#fff; } .btn-primary:hover { background:#003d5c; }
        .btn-success  { background:#2e7d32; color:#fff; } .btn-success:hover  { background:#1b5e20; }
        .btn-danger   { background:#d32f2f; color:#fff; } .btn-danger:hover   { background:#b71c1c; }
        .btn-outline  { background:transparent; color:#005686; border:1.5px solid #005686; } .btn-outline:hover { background:#005686; color:#fff; }
        .btn-sm { padding:4px 8px; font-size:.74rem; }
        .empty-state { text-align:center; padding:60px 20px; color:#aaa; }
        .empty-state i { font-size:3rem; display:block; margin-bottom:12px; color:#ccc; }
        .alert-rojo { background:#fdecea; color:#c0392b; border-left:4px solid #c0392b; padding:11px 16px; border-radius:8px; margin-bottom:14px; font-size:.84rem; }
        .badge-compras           { padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; color: white; }

        /* BOM PANEL */
        .bom-panel { display: none; border-top: 1px solid #f0f0f0; }
        .proy-card.open .bom-panel { display: block; }
        .bom-inner { padding: 16px 20px 20px; }
        .bom-lista-title { font-size: 0.85rem; font-weight: 700; color: #5c35a0; margin: 14px 0 8px; display: flex; align-items: center; gap: 6px; }
        .bom-lista-title:first-child { margin-top: 0; }
        .bom-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
        .bom-table th { background: #f4f7fb; padding: 8px 12px; text-align: left; color: #003d5c; font-weight: 600; border-bottom: 2px solid #e0e0e0; }
        .bom-table td { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; color: #333; }
        .bom-table tr:last-child td { border-bottom: none; }
        .bom-table tr:hover td { background: #f9fafb; }

        /* BOM ACTIONS */
        .bom-actions { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 12px; margin-top: 12px; border-top: 1px dashed #e0e8f0; }

        /* EMPTY */
        .empty-state { text-align: center; padding: 60px 20px; color: #aaa; }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 12px; color: #ccc; }

        /* ACTIVIDAD */
        .act-feed { margin-top: 14px; border-top: 1px solid #e8ecf0; padding-top: 12px; }
        .act-feed-title { font-size: .8rem; font-weight: 700; color: #555; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .act-item-feed { display: flex; gap: 10px; padding: 6px 0; border-bottom: 1px dashed #f0f0f0; font-size: .78rem; color: #555; }
        .act-item-feed:last-child { border-bottom: none; }
        .act-item-feed .act-icon { color: #005686; flex-shrink: 0; width: 16px; margin-top: 2px; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 8px; border: none; cursor: pointer; font-family: 'Montserrat', sans-serif; font-size: .8rem; font-weight: 600; text-decoration: none; transition: all .2s; }
        .btn-primary { background: #005686; color: #fff; } .btn-primary:hover { background: #003d5c; }
        .btn-outline  { background: transparent; color: #005686; border: 1.5px solid #005686; } .btn-outline:hover { background: #005686; color: #fff; }
        .btn-sm { padding: 4px 8px; font-size: .74rem; }

        .no-bom { padding: 20px; text-align: center; color: #aaa; font-size: 0.88rem; }

        /* BADGE draft */
        .badge-draft { background: #fff3e0; color: #e65100; font-size: .65rem; padding: 1px 6px; border-radius: 10px; font-weight: 700; vertical-align: middle; }
        .badge-saved { background: #e8f5e9; color: #2E7D32; font-size: .65rem; padding: 1px 6px; border-radius: 10px; font-weight: 700; vertical-align: middle; }

        /* ALERTA PRONTO */
        .alerta-fecha { background: #fff3e0; border: 1px solid #ffe0b2; border-radius: 8px; padding: 8px 12px; font-size: .8rem; color: #e65100; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }

        /* ══════ SECCIÓN RESUMEN DE PROYECTO ══════ */
        .resumen-upload-card { background:#fff; border-radius:14px; box-shadow:0 3px 16px rgba(0,86,134,.1); padding:36px 40px; max-width:640px; margin:0 auto; }
        .resumen-upload-card h2 { font-size:1.25rem; color:#003d5c; margin-bottom:6px; display:flex; align-items:center; gap:10px; }
        .resumen-upload-card p { color:#666; font-size:.88rem; margin-bottom:24px; }
        .upload-field { margin-bottom:18px; }
        .upload-field label { display:block; font-size:.78rem; font-weight:700; color:#555; margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px; }
        .upload-field input[type=file] { width:100%; border:2px dashed #c5d8e8; border-radius:10px; padding:14px 16px; font-family:'Montserrat',sans-serif; font-size:.84rem; color:#333; background:#f8fbff; cursor:pointer; transition:border-color .2s; }
        .upload-field input[type=file]:hover { border-color:#005686; }
        .upload-field .file-hint { font-size:.72rem; color:#999; margin-top:4px; }
        .upload-field .file-actual { font-size:.78rem; color:#2e7d32; margin-top:5px; display:flex; align-items:center; gap:5px; }

        /* Split horizontal (izquierda/derecha) */
        .proy-split-wrap { display:flex; gap:0; height:calc(100vh - 220px); overflow:hidden; }
        .proy-split-panel { display:flex; flex-direction:column; background:#fff; border:1px solid #dde4ec; border-radius:12px; min-width:0; transition:flex .3s ease; overflow:hidden; }
        .proy-split-panel.panel-form { flex:1; margin-right:8px; }
        .proy-split-panel.panel-pdf  { flex:1; margin-left:8px; }
        .proy-split-panel.maximized  { flex:2 !important; }
        .proy-split-panel.minimized  { flex:0 !important; border:none; opacity:0; pointer-events:none; overflow:hidden; }
        .psp-header { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; background:#f4f7fb; border-bottom:2px solid #e0e8f0; flex-shrink:0; gap:8px; }
        .psp-header h3 { font-size:.92rem; font-weight:700; color:#003d5c; display:flex; align-items:center; gap:8px; margin:0; white-space:nowrap; }
        .psp-body { flex:1; overflow-y:auto; padding:18px 20px; }
        .psp-body.no-pad { padding:0; }
        .psp-body iframe { width:100%; height:100%; border:none; display:block; }
        .btn-max { background:none; border:1px solid #c5d4e0; border-radius:6px; padding:4px 8px; cursor:pointer; color:#555; font-size:.78rem; transition:all .15s; }
        .btn-max:hover { background:#005686; color:#fff; border-color:#005686; }

        /* Tabs internos del panel izquierdo */
        .psp-tabs { display:flex; gap:3px; padding:12px 20px 0; background:#f4f7fb; border-bottom:1px solid #e0e8f0; flex-shrink:0; }
        .psp-tab  { padding:7px 16px; border-radius:7px 7px 0 0; font-size:.8rem; font-weight:600; color:#666; cursor:pointer; border:1.5px solid transparent; border-bottom:none; transition:all .2s; }
        .psp-tab.active { background:#005686; color:#fff; border-color:#005686; }
        .psp-tab:hover:not(.active) { background:#e8f0f8; color:#003d5c; }

        /* Formulario del resumen */
        .form-resumen .fg { margin-bottom:14px; }
        .form-resumen label { display:block; font-size:.74rem; font-weight:700; color:#556; margin-bottom:4px; text-transform:uppercase; letter-spacing:.3px; }
        .form-resumen input, .form-resumen textarea, .form-resumen select { width:100%; border:1.5px solid #d5e0ea; border-radius:8px; padding:8px 12px; font-family:'Montserrat',sans-serif; font-size:.84rem; color:#333; background:#fff; transition:border-color .2s; }
        .form-resumen input:focus, .form-resumen textarea:focus { outline:none; border-color:#005686; }
        .form-resumen textarea { resize:vertical; min-height:60px; }

        /* Tabla de ítems */
        .items-res-table { width:100%; border-collapse:collapse; font-size:.8rem; margin-top:8px; }
        .items-res-table th { background:#f0f4f8; color:#003d5c; font-weight:700; padding:8px 10px; text-align:left; border-bottom:2px solid #dde4ec; }
        .items-res-table td { padding:7px 10px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
        .items-res-table td input { border:1px solid #dde; border-radius:5px; padding:4px 7px; width:100%; font-size:.78rem; font-family:'Montserrat',sans-serif; }
        .items-res-table tr:hover td { background:#fafcff; }
        .btn-add-row { background:none; border:1.5px dashed #005686; color:#005686; border-radius:7px; padding:5px 14px; font-size:.78rem; font-weight:600; cursor:pointer; margin-top:8px; }
        .btn-add-row:hover { background:#005686; color:#fff; }
        .btn-del-row { background:none; border:none; color:#d32f2f; cursor:pointer; font-size:.9rem; padding:2px 5px; }

        /* Tabla plan de inspección — formato EMPRESA EJEMPLO */
        .plan-table { width:100%; border-collapse:collapse; font-size:.76rem; min-width:900px; }
        .plan-table th { background:#003d5c; color:#fff; font-weight:700; padding:7px 9px; text-align:center; border:1px solid #002d47; font-size:.72rem; letter-spacing:.3px; }
        .plan-table th.left { text-align:left; }
        .plan-table td { padding:5px 7px; border:1px solid #dde4ec; vertical-align:top; }
        .plan-table tr:nth-child(even) td { background:#f8fbff; }
        .plan-table tr:hover td { background:#e8f0f8; }
        .plan-table td input, .plan-table td select, .plan-table td textarea { border:1px solid #c5d4e0; border-radius:4px; padding:3px 6px; width:100%; font-size:.74rem; font-family:'Montserrat',sans-serif; background:#fff; }
        .plan-table td textarea { resize:vertical; min-height:38px; }
        .plan-table .col-item { width:52px; min-width:52px; text-align:center; }
        .plan-table .col-req { width:22%; }
        .plan-table .col-ref { width:16%; }
        .plan-table .col-met { width:18%; }
        .plan-table .col-cri { width:22%; }
        .plan-table .col-cum { width:70px; text-align:center; }
        .plan-table .col-com { width:12%; }
        .plan-table .col-del { width:28px; }
        .cumple-falso { background:#fdecea!important; color:#c0392b!important; font-weight:700; font-size:.7rem; }
        .cumple-verdadero { background:#e8f5e9!important; color:#2e7d32!important; font-weight:700; font-size:.7rem; }
        .cumple-na { background:#f5f5f5!important; color:#666!important; font-weight:700; font-size:.7rem; }

        /* Botón analizar IA */
        .btn-ia { background:linear-gradient(135deg,#5c35a0,#7b52c0); color:#fff; border:none; border-radius:8px; padding:7px 16px; font-size:.8rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:all .2s; font-family:'Montserrat',sans-serif; }
        .btn-ia:hover { background:linear-gradient(135deg,#4a2880,#6340a8); transform:translateY(-1px); }
        .btn-ia:disabled { opacity:.5; cursor:wait; }
        .ia-spinner { display:none; align-items:center; gap:8px; font-size:.8rem; color:#7b52c0; font-weight:600; }
        .ia-spinner.show { display:flex; }
        @keyframes ia-spin { to { transform:rotate(360deg); } }
        .ia-spinner i { animation:ia-spin .8s linear infinite; }
        .badge-ia { background:#f3e5f5; color:#7b1fa2; font-size:.68rem; padding:2px 8px; border-radius:10px; font-weight:700; }
        .alert-guardado { background:#e8f5e9; color:#2e7d32; border-left:4px solid #2e7d32; padding:10px 16px; border-radius:8px; margin-bottom:14px; font-size:.84rem; display:flex; align-items:center; gap:8px; }

        /* Overlay fijo para la sección resumen (fuera del app-container) */
        .resumen-overlay { position:fixed; top:80px; left:0; right:0; bottom:60px; background:#fff; z-index:500; overflow-y:auto; padding:0 30px 20px; }

        /* ══════ PANEL DETALLE PROYECTO ══════ */
        .detalle-overlay { position:fixed; inset:0; z-index:800; background:#f4f7fb; overflow-y:auto; padding:80px 20px 80px; }
        .detalle-card { max-width:860px; margin:0 auto; background:#fff; border-radius:14px; box-shadow:0 4px 20px rgba(0,86,134,.12); overflow:hidden; }
        .detalle-header { background:linear-gradient(135deg,#003d5c,#005686); color:#fff; padding:22px 28px; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .detalle-title { font-size:1.15rem; font-weight:700; margin-bottom:4px; }
        .detalle-cliente { font-size:.84rem; opacity:.85; }
        .detalle-close { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.35); border-radius:8px; color:#fff; padding:6px 12px; cursor:pointer; font-size:.82rem; font-weight:600; font-family:'Montserrat',sans-serif; display:flex; align-items:center; gap:5px; transition:all .2s; flex-shrink:0; }
        .detalle-close:hover { background:rgba(255,255,255,.3); }
        .detalle-body { padding:24px 28px; }
        .detalle-section-title { font-size:.82rem; font-weight:700; color:#003d5c; text-transform:uppercase; letter-spacing:.5px; margin:0 0 14px; display:flex; align-items:center; gap:7px; }
        .detalle-section-title::after { content:''; flex:1; height:1px; background:#e0e8f0; }

        /* Barras de fase */
        .fase-bar-wrap { margin-bottom:16px; }
        .fase-bar-label { display:flex; justify-content:space-between; font-size:.82rem; font-weight:700; color:#003d5c; margin-bottom:4px; }
        .fase-bar-bg { height:10px; background:#e8ecf0; border-radius:5px; overflow:hidden; }
        .fase-bar-fill { height:100%; border-radius:5px; transition:width .4s; }
        .fase-sub { margin-left:20px; margin-top:6px; }
        .fase-sub .fase-bar-label { font-size:.74rem; font-weight:600; color:#666; }
        .fase-sub .fase-bar-bg { height:7px; }

        /* Timeline */
        .timeline-wrap { display:flex; gap:0; align-items:flex-start; overflow-x:auto; padding:10px 0; margin-bottom:4px; }
        .tl-item { flex:1; min-width:90px; text-align:center; position:relative; }
        .tl-item::before { content:''; position:absolute; top:14px; left:0; right:0; height:2px; background:#dde4ec; z-index:0; }
        .tl-item:first-child::before { left:50%; }
        .tl-item:last-child::before  { right:50%; }
        .tl-dot { width:12px; height:12px; border-radius:50%; background:#dde4ec; margin:0 auto 6px; position:relative; z-index:1; border:2px solid #fff; }
        .tl-dot.pasado  { background:#2e7d32; }
        .tl-dot.futuro  { background:#1565c0; }
        .tl-dot.retraso { background:#b71c1c; }
        .tl-label { font-size:.68rem; font-weight:700; color:#555; }
        .tl-fecha { font-size:.65rem; color:#888; margin-top:2px; }

        /* Cards resumen de área */
        .area-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-top:8px; }
        .area-card { background:#f8fbff; border:1px solid #dde4ec; border-radius:10px; padding:14px 16px; }
        .area-card-title { font-size:.74rem; font-weight:700; color:#003d5c; text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
        .area-card-val { font-size:1.35rem; font-weight:800; color:#005686; margin-bottom:2px; }
        .area-card-sub { font-size:.72rem; color:#888; }

        /* Botón Ver detalle en tarjeta */
        .btn-ver-detalle { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:7px; border:1.5px solid #005686; color:#005686; background:transparent; font-family:'Montserrat',sans-serif; font-size:.74rem; font-weight:600; cursor:pointer; text-decoration:none; transition:all .2s; }
        .btn-ver-detalle:hover { background:#005686; color:#fff; }

        /* RESPONSIVO */
        @media (max-width: 768px) {
            .section-content { padding: 0 12px 20px; }
            .sticky-container { padding: 10px 0; }
            .header-row { flex-direction: column; align-items: flex-start; }
            .filtros-bar { flex-direction: column; }
            .filtro-input { min-width: 100%; }
            .proy-card-header { flex-wrap: wrap; }
            .bom-table { font-size: 0.72rem; }
            .bom-table th, .bom-table td { padding: 5px 7px; }
            .area-cards { grid-template-columns: 1fr; }
            .detalle-body { padding: 16px; }
            .detalle-header { padding: 16px; }
        }
        @media (max-width: 480px) {
            .proy-nombre { font-size: 0.88rem; }
            .badge { font-size: 0.65rem; padding: 2px 7px; }
        }
        #textLayer {
    position: absolute;
    left: 0;
    top: 0;
    right: 0;
    bottom: 0;
    overflow: auto;
    opacity: 0.2;
    pointer-events: none;
    line-height: 1;
}
#textLayer span {
    white-space: pre;
}
mark {
    background-color: yellow;
    color: black;
}
        /* ── Documentos de Referencia ─────────────────────────────── */
        .docref-section { position:fixed; top:80px; left:0; right:0; bottom:0; background:#f1f5f9; z-index:500; overflow-y:auto; }
        .docref-inner   { max-width:1100px; margin:0 auto; padding:24px 20px 80px; }
        .docref-card { background: #fff; border-radius: 12px; border: 1px solid #dde4ec; padding: 20px 24px; margin-bottom: 24px; }
        .docref-card h3 { font-size: 1rem; font-weight: 700; color: #003d5c; margin: 0 0 16px; display: flex; align-items: center; gap: 8px; }
        .docref-table { width: 100%; border-collapse: collapse; font-size: .83rem; }
        .docref-table th { background: #f4f7fb; padding: 9px 12px; text-align: left; color: #003d5c; font-weight: 600; border-bottom: 2px solid #e0e8f0; }
        .docref-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; color: #333; }
        .docref-table tr:last-child td { border-bottom: none; }
        .docref-table tr:hover td { background: #f9fafb; }
        .docref-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: .72rem; font-weight: 600; }
        .docref-badge.procesado { background: #e8f5e9; color: #2e7d32; }
        .docref-badge.pendiente { background: #fff3e0; color: #e65100; }
        .docref-badge.sin_texto { background: #fce4ec; color: #c62828; }
        .docref-badge.error     { background: #fce4ec; color: #c62828; }
        .docref-upload-form { background: #f8fafc; border: 1.5px dashed #c5d4e0; border-radius: 10px; padding: 18px 20px; margin-top: 16px; }
        .docref-upload-form .fg { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
        .docref-upload-form label { font-size: .8rem; font-weight: 600; color: #003d5c; }
        .docref-upload-form input, .docref-upload-form select { border: 1.5px solid #d5e0ea; border-radius: 8px; padding: 7px 10px; font-size: .84rem; font-family: 'Montserrat', sans-serif; }
        .docref-upload-form .fg-row { display: flex; gap: 10px; }
        .docref-upload-form .fg-row .fg { flex: 1; }
        .docref-progress { display: none; background: #f4f7fb; border-radius: 8px; padding: 10px 14px; margin-top: 10px; font-size: .82rem; color: #005686; }
        .docref-progress.show { display: flex; align-items: center; gap: 8px; }
    </style>
</head>
<body>
    <!-- TOP HEADER -->
    <div class="top-header">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo"
             onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
        <div class="header-right">
            <?php if ($seccion !== ''): ?>
            <?php
                $from = $_GET['from'] ?? '';
                $back_url   = '?seccion=';
                $back_label = 'Volver al Menú';
                if ($from === 'manufactura') { $back_url = 'produccion.php'; $back_label = 'Volver a Producción'; }
            ?>
            <a href="<?= htmlspecialchars($back_url) ?>" class="back-button-header">
                <i class="fas fa-arrow-left"></i> <?= $back_label ?>
            </a>
            <?php endif; ?>

            <div style="position:relative;">
                <div id="notifBellBtn" onclick="toggleNotifHeaderDropdown()" style="position:relative;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer;flex-shrink:0;transition:background .2s;" onmouseenter="this.style.background='rgba(255,255,255,.28)'" onmouseleave="this.style.background='rgba(255,255,255,.15)'">
                    <i class="fas fa-bell"></i>
                    <span id="notifBellBadge" style="position:absolute;top:-4px;right:-4px;background:#e53935;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:.7rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #2E5CAE;">0</span>
                </div>
                <div id="notifHeaderDropdown" style="display:none;position:absolute;top:52px;right:0;z-index:9999;width:380px;max-height:70vh;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.3);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #eee;">
                        <span style="font-size:12px;font-weight:700;color:#003d5c;"><i class="fas fa-folder-tree"></i> Notificaciones de Proyectos</span>
                        <div style="display:flex;gap:6px;">
                            <button onclick="marcarTodasLeidasProyectos()" class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                                <i class="fas fa-check-double"></i> Marcar leído
                            </button>
                            <button onclick="borrarNotificacionesProyectos()" class="btn btn-sm" style="background:#ffebee;color:#c62828;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
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

        <?php if ($seccion === ''): ?>
        <!-- DASHBOARD -->
        <div class="main-center">
            <div class="page-header">
                <h1 class="page-title"><strong>Proyectos</strong><br>Panel de Control</h1>
                <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
            </div>
            <div class="cards-grid">
                <div class="nav-card" onclick="location.href='?seccion=proyectos'">
                    <div class="card-icon"><i class="fas fa-folder-open"></i></div>
                    <div class="card-title">Seguimiento</div>
                    <div class="card-desc">Avance de proyectos</div>
                </div>

                <div class="nav-card" onclick="location.href='calendario.php'">
                    <div class="card-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="card-title">Calendario</div>
                    <div class="card-desc">Eventos y fechas clave</div>
                </div>
                <div class="nav-card" onclick="location.href='?seccion=formato_trabajo'">
                    <div class="card-icon"><i class="fas fa-industry"></i></div>
                    <div class="card-title">Orden de Producción</div>
                    <div class="card-desc">DOC-PRY-FO-02B · Formato oficial</div>
                </div>
                <div class="nav-card" onclick="location.href='?seccion=resumen_proyecto'">
                    <div class="card-icon"><i class="fas fa-file-contract"></i></div>
                    <div class="card-title">Resumen IA</div>
                    <div class="card-desc">Análisis de PO y OV con IA</div>
                </div>
                <div class="nav-card" onclick="location.href='?seccion=fat'">
                    <div class="card-icon"><i class="fas fa-clipboard-check"></i></div>
                    <div class="card-title">Pruebas FAT</div>
                    <div class="card-desc">Firma de Proyectos</div>
                </div>
                <div class="nav-card" onclick="location.href='?seccion=documentos'">
                    <div class="card-icon"><i class="fas fa-folder-open"></i></div>
                    <div class="card-title">Documentos</div>
                    <div class="card-desc">OP · Resumen IA · Plan de Inspección</div>
                </div>
                <div class="nav-card" onclick="location.href='?seccion=docs_referencia'">
                    <div class="card-icon"><i class="fas fa-book-open"></i></div>
                    <div class="card-title">Docs de Referencia</div>
                    <div class="card-desc">Normas · POs · SPIs · Biblioteca</div>
                </div>
                <div class="nav-card" onclick="location.href='?seccion=indicadores'">
                    <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="card-title">Indicadores</div>
                    <div class="card-desc">DOC-GEN-FO-15 · Reporte trimestral</div>
                </div>
                <?php renderEquiposFabricadosNavCard('proyecto.php'); ?>
                <?php renderGenerarRequisicionNavCard('proyecto.php'); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'generar_requisicion'): ?>
        <?php if (!empty($_SESSION['proy_message'])): ?>
            <div class="alert alert-success" style="margin:14px 0;"><?= htmlspecialchars($_SESSION['proy_message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['proy_message']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['proy_error'])): ?>
            <div class="alert alert-error" style="margin:14px 0;"><?= htmlspecialchars($_SESSION['proy_error'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['proy_error']); ?>
        <?php endif; ?>
        <?php renderRequisicionesSeccion($db, 'proyecto.php', 'accion', 'Proyectos', 'proyectos'); ?>
        <?php endif; ?>

        <?php if ($seccion === 'indicadores'): ?>
        <div class="section-content">
            <?php require_once 'includes/indicadores.php'; renderIndicadoresSeccion($db, 'proyectos', 'proyecto.php'); ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'equipos_fabricados'): renderEquiposFabricadosSeccion($db, 'proyecto.php', 'proyectos'); endif; ?>

        <?php if ($seccion === 'proyectos' && $ver_id > 0 && $ver_proyecto): ?>
        <!-- ══════ PANEL DETALLE PROYECTO ══════ -->
        <div class="detalle-overlay">
            <div class="detalle-card">
                <!-- Header -->
                <div class="detalle-header">
                    <div>
                        <div class="detalle-title"><?= htmlspecialchars($ver_proyecto['nombre']) ?></div>
                        <?php
                            $d_cliente = $ver_resumen['cliente'] ?? ($ver_op['cliente'] ?? '');
                        ?>
                        <?php if ($d_cliente): ?>
                        <div class="detalle-cliente"><i class="fas fa-building"></i> <?= htmlspecialchars($d_cliente) ?></div>
                        <?php endif; ?>
                        <div style="margin-top:8px;">
                            <?php $dest = $ver_proyecto['estado'] ?? 'pendiente'; ?>
                            <span class="badge badge-estado-<?= $dest ?>"><?= ucfirst(str_replace('_',' ',$dest)) ?></span>
                        </div>
                    </div>
                    <a href="?seccion=proyectos<?= $filtro_estado ? '&filtro_estado='.urlencode($filtro_estado) : '' ?>" class="detalle-close">
                        <i class="fas fa-times"></i> Cerrar
                    </a>
                </div>

                <div class="detalle-body">
                    <!-- Barras de fase -->
                    <div class="detalle-section-title"><i class="fas fa-project-diagram"></i> Avance por Fase</div>

                    <!-- INGENIERÍA -->
                    <?php $f_ing = $ver_fases_calc['ingenieria'] ?? 0; ?>
                    <div class="fase-bar-wrap">
                        <div class="fase-bar-label">
                            <span><i class="fas fa-drafting-compass" style="color:#005686;"></i> Ingeniería</span>
                            <span><?= $f_ing ?>%</span>
                        </div>
                        <div class="fase-bar-bg">
                            <div class="fase-bar-fill" style="width:<?= $f_ing ?>%;background:#005686;"></div>
                        </div>
                        <div style="font-size:.7rem;color:#888;margin-top:3px;"><?= $ver_fases_calc['_bom_guardadas'] ?> de <?= $ver_fases_calc['_bom_total'] ?> BOMs guardados</div>
                    </div>

                    <!-- PROCURA -->
                    <?php $f_proc = $ver_fases_calc['procura'] ?? 0; $f_oc = $ver_fases_calc['_oc'] ?? 0; $f_alm = $ver_fases_calc['_alm'] ?? 0; ?>
                    <div class="fase-bar-wrap">
                        <div class="fase-bar-label">
                            <span><i class="fas fa-shopping-cart" style="color:#7b1fa2;"></i> Procura</span>
                            <span><?= $f_proc ?>%</span>
                        </div>
                        <div class="fase-bar-bg">
                            <div class="fase-bar-fill" style="width:<?= $f_proc ?>%;background:#7b1fa2;"></div>
                        </div>
                        <!-- Sub-barra OCs -->
                        <div class="fase-sub">
                            <div class="fase-bar-label">
                                <span><i class="fas fa-file-invoice-dollar" style="color:#9c27b0;"></i> OCs (nivel: <?= $nivel_labels[$ver_fases_calc['_nivel_c'] ?? 0] ?? 'Sin gestión' ?>)</span>
                                <span><?= $f_oc ?>%</span>
                            </div>
                            <div class="fase-bar-bg">
                                <div class="fase-bar-fill" style="width:<?= $f_oc ?>%;background:#9c27b0;"></div>
                            </div>
                        </div>
                        <!-- Sub-barra Materiales -->
                        <div class="fase-sub">
                            <div class="fase-bar-label">
                                <span><i class="fas fa-boxes" style="color:#e65100;"></i> Materiales en almacén</span>
                                <span><?= $f_alm ?>%</span>
                            </div>
                            <div class="fase-bar-bg">
                                <div class="fase-bar-fill" style="width:<?= $f_alm ?>%;background:#e65100;"></div>
                            </div>
                            <div style="font-size:.68rem;color:#aaa;margin-top:2px;"><?= $ver_fases_calc['_alm_done'] ?> de <?= $ver_fases_calc['_alm_tot'] ?> ítems asignados</div>
                        </div>
                    </div>

                    <!-- PRODUCCIÓN -->
                    <?php
                    $f_mfg = $ver_fases_calc['manufactura'] ?? 0;
                    // Cargar OTs del proyecto agrupadas por página
                    $stmt_ots_ver = $db->prepare("
                        SELECT ot.id, ot.folio, ot.titulo, ot.estado, ot.maquina,
                               ot.visto_produccion, ot.visto_calidad, ot.pausado,
                               ot.plano_pagina_id, pp.nombre AS pagina_nombre,
                               bl.pieza_nombre,
                               u.nombre_completo AS operario_nombre
                        FROM ordenes_trabajo ot
                        JOIN boom_listas bl ON bl.id = ot.boom_lista_id
                        LEFT JOIN usuarios u ON u.id = ot.operario_id
                        LEFT JOIN plano_paginas pp ON pp.id = ot.plano_pagina_id
                        WHERE ot.proyecto_id = ? AND (pp.ignorada IS NULL OR pp.ignorada = 0)
                        ORDER BY pp.numero_pagina ASC, ot.fecha_creacion ASC
                    ");
                    $stmt_ots_ver->execute([$ver_id]);
                    $ots_ver = $stmt_ots_ver->fetchAll();

                    // Agrupar por página para la vista de carpetas
                    $ots_ver_por_pagina = [];
                    foreach ($ots_ver as $ot_v) {
                        $pag_id_v  = $ot_v['plano_pagina_id'] ?: 0;
                        $pag_nom_v = $ot_v['pagina_nombre'] ?: 'Sin página';
                        if (!isset($ots_ver_por_pagina[$pag_id_v])) {
                            $ots_ver_por_pagina[$pag_id_v] = ['nombre' => $pag_nom_v, 'ots' => []];
                        }
                        $ots_ver_por_pagina[$pag_id_v]['ots'][] = $ot_v;
                    }
                    ?>
                    <div class="fase-bar-wrap">
                        <div class="fase-bar-label">
                            <span><i class="fas fa-industry" style="color:#2e7d32;"></i> Producción</span>
                            <span><?= $f_mfg ?>%</span>
                        </div>
                        <div class="fase-bar-bg">
                            <div class="fase-bar-fill" style="width:<?= $f_mfg ?>%;background:#2e7d32;"></div>
                        </div>
                        <div style="font-size:.7rem;color:#888;margin-top:3px;"><?= $ver_fases_calc['_ot_done'] ?> de <?= $ver_fases_calc['_ot_tot'] ?> OTs completadas</div>

                        <!-- Carpetas por página -->
                        <?php if (!empty($ots_ver_por_pagina)): ?>
                        <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                        <?php foreach ($ots_ver_por_pagina as $pag_id_v => $pag_grupo_v):
                            $pag_ots_v = $pag_grupo_v['ots'];
                            $pag_tot_v = count($pag_ots_v);
                            $pag_ok_v  = count(array_filter($pag_ots_v, fn($o) => $o['estado'] === 'completado'));
                        ?>
                        <div style="border:1px solid #e0e8f0;border-radius:8px;overflow:hidden;">
                            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f0f5fc;cursor:pointer;" onclick="toggleVerPagina(<?= (int)$pag_id_v ?>)">
                                <i class="fas fa-folder" style="color:#1565c0;font-size:.85rem;"></i>
                                <span style="font-size:.78rem;font-weight:700;color:#1565c0;flex:1;"><?= htmlspecialchars($pag_grupo_v['nombre']) ?></span>
                                <span style="font-size:.68rem;font-weight:700;color:<?= $pag_ok_v === $pag_tot_v ? '#2e7d32' : '#607d8b' ?>;"><?= $pag_ok_v ?>/<?= $pag_tot_v ?> OTs</span>
                                <i class="fas fa-chevron-down" id="verPagChev_<?= (int)$pag_id_v ?>" style="color:#aaa;font-size:.7rem;transition:transform .2s;"></i>
                            </div>
                            <div id="verPagBody_<?= (int)$pag_id_v ?>" style="display:none;padding:8px 12px;flex-direction:column;gap:6px;">
                            <?php foreach ($pag_ots_v as $ot_v):
                                $pct_v  = 0;
                                if ($ot_v['estado']==='en_proceso'||$ot_v['estado']==='completado') $pct_v+=20;
                                if ($ot_v['visto_produccion']) $pct_v+=20;
                                if ($ot_v['visto_calidad'])    $pct_v+=20;
                                if ($ot_v['estado']==='completado') $pct_v = 100;
                                $col_v = $ot_v['estado']==='completado' ? '#2E7D32' : ($ot_v['estado']==='en_proceso' ? '#1565c0' : '#FF9800');
                                $ico_v = $ot_v['estado']==='completado' ? 'fa-check-circle' : ($ot_v['estado']==='en_proceso' ? 'fa-spinner' : 'fa-clock');
                            ?>
                            <div style="background:#f8faff;border:1px solid #e8ecf0;border-radius:8px;padding:7px 10px;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                    <i class="fas <?= $ico_v ?>" style="color:<?= $col_v ?>;font-size:.8rem;"></i>
                                    <?php if ($ot_v['folio']): ?><span style="font-size:.65rem;font-weight:800;color:#005686;background:#e8f4fd;padding:1px 6px;border-radius:10px;"><?= htmlspecialchars($ot_v['folio']) ?></span><?php endif; ?>
                                    <span style="font-size:.75rem;font-weight:700;color:#003d5c;flex:1;"><?= htmlspecialchars($ot_v['pieza_nombre'] ?? $ot_v['titulo']) ?></span>
                                    <span style="font-size:.68rem;font-weight:700;color:<?= $col_v ?>;"><?= $pct_v ?>%</span>
                                </div>
                                <div style="height:4px;background:#e8ecf0;border-radius:2px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $pct_v ?>%;background:<?= $col_v ?>;border-radius:2px;transition:width .4s;"></div>
                                </div>
                                <!-- Pasos mini -->
                                <div style="display:flex;gap:4px;margin-top:5px;flex-wrap:wrap;">
                                    <?php
                                    $pasos_v = [
                                        ['Fab.', $ot_v['estado']!=='pendiente'],
                                        ['VB Prod.', (bool)$ot_v['visto_produccion']],
                                        ['VB Cal.', (bool)$ot_v['visto_calidad']],
                                        ['Completo', $ot_v['estado']==='completado'],
                                    ];
                                    foreach ($pasos_v as [$lbl_v, $ok_v]):
                                    ?>
                                    <span style="font-size:.6rem;padding:1px 6px;border-radius:10px;font-weight:700;background:<?= $ok_v ? '#e8f5e9' : '#f5f5f5' ?>;color:<?= $ok_v ? '#2E7D32' : '#bbb' ?>;">
                                        <?= $ok_v ? '✓' : '○' ?> <?= $lbl_v ?>
                                    </span>
                                    <?php endforeach; ?>
                                    <?php if ($ot_v['operario_nombre']): ?>
                                    <span style="font-size:.6rem;color:#aaa;margin-left:auto;"><?= htmlspecialchars($ot_v['operario_nombre']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        <script>
                        function toggleVerPagina(id) {
                            const body = document.getElementById('verPagBody_' + id);
                            const chev = document.getElementById('verPagChev_' + id);
                            if (!body) return;
                            const abierta = body.style.display === 'flex';
                            body.style.display = abierta ? 'none' : 'flex';
                            if (chev) chev.style.transform = abierta ? '' : 'rotate(180deg)';
                        }
                        </script>
                        <?php endif; ?>
                    </div>

                    <!-- FAT -->
                    <?php
                    $f_fat = $ver_fases_calc['fat'] ?? 0;
                    $fat_fase_existe = isset($vfases_map['fat']) && $vfases_map['fat']['pct_avance'] > 0;
                    $fat_accesible = $f_mfg >= 100 || $fat_fase_existe;
                    ?>
                    <div class="fase-bar-wrap">
                        <div class="fase-bar-label">
                            <span><i class="fas fa-vials" style="color:#f57f17;"></i> Pruebas FAT</span>
                            <span><?= $f_fat ?>%</span>
                        </div>
                        <div class="fase-bar-bg">
                            <div class="fase-bar-fill" style="width:<?= $f_fat ?>%;background:#f57f17;"></div>
                        </div>
                        <?php if (!$fat_accesible): ?>
                        <div style="font-size:.68rem;color:#aaa;margin-top:2px;"><i class="fas fa-lock"></i> Disponible cuando Producción llegue al 100%</div>
                        <?php endif; ?>
                    </div>

                    <!-- Timeline de fechas -->
                    <?php
                    $tl_po        = $ver_proyecto['fecha_creacion'] ?? null;
                    $tl_ingFin    = null; // no disponible directamente
                    $tl_entrega   = $ver_op['fecha_compromiso'] ?? ($ver_resumen['fecha_entrega'] ?? ($ver_proyecto['fecha_finiquito'] ?? null));
                    $hoy_ts = time();

                    function tl_dot_class($fecha_str, $hoy_ts) {
                        if (!$fecha_str) return '';
                        $ts = strtotime($fecha_str);
                        return $ts < $hoy_ts ? 'pasado' : 'futuro';
                    }
                    ?>
                    <div class="detalle-section-title" style="margin-top:20px;"><i class="fas fa-stream"></i> Fechas clave</div>
                    <div class="timeline-wrap">
                        <?php
                        // Obtener fecha real de cada fase: si completado usar fecha_actualizacion, si no usar fecha_inicio
                        $tl_ing_fase = $vfases_map['ingenieria'] ?? null;
                        $tl_proc_fase = $vfases_map['procura'] ?? null;
                        $tl_mfg_fase  = $vfases_map['manufactura'] ?? null;
                        $tl_fat_fase  = $vfases_map['fat'] ?? null;

                        $tl_ing  = $tl_ing_fase  ? (($tl_ing_fase['estado'] === 'completado'  ? $tl_ing_fase['fecha_actualizacion']  : $tl_ing_fase['fecha_inicio'])  ?: null) : null;
                        $tl_proc = $tl_proc_fase ? (($tl_proc_fase['estado'] === 'completado' ? $tl_proc_fase['fecha_actualizacion'] : $tl_proc_fase['fecha_inicio']) ?: null) : null;
                        // Manufactura: intentar obtener la fecha del último OT completado
                        $tl_mfg = null;
                        if ($tl_mfg_fase) {
                            $tl_mfg = ($tl_mfg_fase['estado'] === 'completado' ? $tl_mfg_fase['fecha_actualizacion'] : $tl_mfg_fase['fecha_inicio']) ?: null;
                        }
                        if (!$tl_mfg && $v_pct_mfg >= 100) {
                            // Buscar la fecha de actualización del último OT completado
                            $stmt_mfg_fecha = $db->prepare("SELECT MAX(fecha_creacion) FROM ordenes_trabajo WHERE proyecto_id=? AND estado='completado'");
                            $stmt_mfg_fecha->execute([$ver_id]);
                            $tl_mfg = $stmt_mfg_fecha->fetchColumn() ?: date('Y-m-d');
                        }
                        $tl_fat  = $tl_fat_fase  ? (($tl_fat_fase['estado'] === 'completado'  ? $tl_fat_fase['fecha_actualizacion']  : $tl_fat_fase['fecha_inicio'])  ?: null) : null;

                        // Completado basado en porcentaje calculado (no en proyecto_fases guardado) --
                        // nada escribe nunca una fila proyecto_fases para fase='fat', así que $tl_fat_fase
                        // siempre es null; $v_pct_fat ya sabe si el Protocolo FAT fue aprobado.
                        $tl_ing_completado  = ($v_pct_ing  >= 100);
                        $tl_proc_completado = ($v_pct_proc >= 100);
                        $tl_mfg_completado  = ($v_pct_mfg  >= 100);
                        $tl_fat_completado  = ($v_pct_fat  >= 100);
                        if ($tl_fat_completado && !$tl_fat) {
                            $stmt_fat_fecha = $db->prepare("
                                SELECT MAX(pf.fecha_realizada)
                                FROM pruebas_fat pf
                                JOIN fat_protocolos fp ON fp.pruebas_fat_id = pf.id
                                WHERE pf.proyecto_id = ? AND fp.revision_estado = 'aprobado'
                            ");
                            $stmt_fat_fecha->execute([$ver_id]);
                            $tl_fat = $stmt_fat_fecha->fetchColumn() ?: date('Y-m-d H:i:s');
                        }

                        // Fecha de Procura completada: usar fecha de la OC recibida
                        if ($tl_proc_completado && !$tl_proc) {
                            $stmt_proc_fecha = $db->prepare("SELECT MAX(fecha_aprobacion) FROM ordenes_compra WHERE proyecto_id=? AND estado IN ('aprobada','recibida') AND fecha_aprobacion IS NOT NULL");
                            $stmt_proc_fecha->execute([$ver_id]);
                            $tl_proc = $stmt_proc_fecha->fetchColumn() ?: date('Y-m-d H:i:s');
                        }
                        if ($tl_ing_completado && !$tl_ing) {
                            $tl_ing = date('Y-m-d H:i:s');
                        }

                        $tl_items = [
                            ['PO recibida',   $tl_po,      false],
                            ['Ingeniería',    $tl_ing,     $tl_ing_completado],
                            ['Procura',       $tl_proc,    $tl_proc_completado],
                            ['Producción',    $tl_mfg,     $tl_mfg_completado],
                            ['FAT',           $tl_fat,     $tl_fat_completado],
                            ['Entrega',       $tl_entrega, false],
                        ];
                        foreach ($tl_items as [$tl_label, $tl_fecha, $tl_completado]):
                            $dot_class = $tl_completado ? 'pasado' : ($tl_fecha ? tl_dot_class($tl_fecha, $hoy_ts) : '');
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot <?= $dot_class ?>"></div>
                            <div class="tl-label"><?= htmlspecialchars($tl_label) ?><?= $tl_completado ? ' <i class="fas fa-check" style="color:#2e7d32;font-size:.65rem;"></i>' : '' ?></div>
                            <?php if ($tl_fecha): ?>
                            <div class="tl-fecha">
                                <?= date('d/m/Y', strtotime($tl_fecha)) ?>
                                <?php if ($tl_completado): ?>
                                <span style="color:#2e7d32;font-size:.62rem;font-weight:700;display:block;margin-top:1px;"><?= date('H:i', strtotime($tl_fecha)) ?> ✓</span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="tl-fecha" style="color:#ccc;">—</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Resumen por área -->
                    <div class="detalle-section-title" style="margin-top:20px;"><i class="fas fa-th-large"></i> Resumen por Área</div>
                    <div class="area-cards">
                        <!-- Ingeniería -->
                        <div class="area-card">
                            <div class="area-card-title"><i class="fas fa-drafting-compass" style="color:#005686;"></i> Ingeniería</div>
                            <div class="area-card-val"><?= $ver_fases_calc['_bom_guardadas'] ?>/<?= $ver_fases_calc['_bom_total'] ?></div>
                            <div class="area-card-sub">BOMs guardados</div>
                        </div>
                        <!-- Compras -->
                        <?php $vc = $ver_fases_calc['_nivel_c'] ?? 0; ?>
                        <div class="area-card">
                            <div class="area-card-title"><i class="fas fa-shopping-cart" style="color:#7b1fa2;"></i> Compras</div>
                            <div style="margin-bottom:6px;">
                                <span class="badge-compras" style="background:<?= $nivel_colors[$vc] ?? '#9e9e9e' ?>;"><?= htmlspecialchars($nivel_labels[$vc] ?? 'Sin gestión') ?></span>
                            </div>
                            <div class="area-card-sub">Nivel <?= $vc ?> de 6</div>
                        </div>
                        <!-- Producción -->
                        <div class="area-card">
                            <div class="area-card-title"><i class="fas fa-industry" style="color:#2e7d32;"></i> Producción</div>
                            <div class="area-card-val"><?= $ver_fases_calc['_ot_done'] ?>/<?= $ver_fases_calc['_ot_tot'] ?></div>
                            <div class="area-card-sub">OTs completadas</div>
                        </div>
                        <!-- Almacén -->
                        <div class="area-card">
                            <div class="area-card-title"><i class="fas fa-warehouse" style="color:#00796b;"></i> Almacén</div>
                            <div class="area-card-val"><?= $ver_fases_calc['_alm_done'] ?>/<?= $ver_fases_calc['_alm_tot'] ?></div>
                            <div class="area-card-sub">Ítems asignados</div>
                        </div>
                    </div>

                    <!-- Navegación -->
                    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e8ecf0;display:flex;flex-wrap:wrap;gap:8px;">
                        <a href="?seccion=proyectos<?= $filtro_estado ? '&filtro_estado='.urlencode($filtro_estado) : '' ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver a lista</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'proyectos'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-binoculars"></i> Seguimiento</h2>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:.8rem;color:#666;"><?= count($proyectos) ?> proyecto(s)</span>
                        <form method="POST" action="?seccion=proyectos" style="margin:0;">
                            <input type="hidden" name="accion" value="recalcular_progreso_ing">
                            <button type="submit" class="btn btn-outline btn-sm" title="Recalcular avance de Ingeniería en todos los proyectos">
                                <i class="fas fa-sync-alt"></i> Actualizar Progreso
                            </button>
                        </form>
                    </div>
                </div>
                <?php if (!empty($_SESSION['proy_message'])): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['proy_message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php unset($_SESSION['proy_message']); ?>
                <?php endif; ?>
                <?php if (!empty($_SESSION['proy_error'])): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['proy_error'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php unset($_SESSION['proy_error']); ?>
                <?php endif; ?>
                <?php renderAvisoDuplicado(); ?>
                <!-- FILTROS - Corregido a 4 estados -->
                <form method="GET" action="" id="formFiltros">
                    <input type="hidden" name="seccion" value="proyectos">
                    <div class="filtros-bar">
                        <div class="filtro-grupo">
                            <span class="filtro-label">Buscar</span>
                            <input type="text" name="busqueda" class="filtro-input" placeholder="Nombre o descripción…" value="<?= htmlspecialchars($filtro_busqueda) ?>">
                        </div>
                        <div class="filtro-grupo">
                            <span class="filtro-label">Estado</span>
                            <select name="filtro_estado" class="filtro-input" onchange="this.form.submit()">
                                <option value="">— Todos —</option>
                                <?php foreach (['pendiente','en_proceso','completado','cancelado'] as $est): ?>
                                <option value="<?= $est ?>" <?= $filtro_estado === $est ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$est)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filtro-grupo">
                            <span class="filtro-label">Responsable</span>
                            <select name="filtro_resp" class="filtro-input" onchange="this.form.submit()">
                                <option value="0">— Todos —</option>
                                <?php foreach ($usuarios_filtro as $uf): ?>
                                <option value="<?= $uf['id'] ?>" <?= $filtro_responsable == $uf['id'] ? 'selected' : '' ?>><?= htmlspecialchars($uf['nombre_completo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filtro-grupo">
                            <span class="filtro-label">OC entrega antes de</span>
                            <input type="date" name="filtro_fecha_oc" class="filtro-input" value="<?= htmlspecialchars($filtro_fecha_oc) ?>" title="Mostrar proyectos con OC a entregar antes de esta fecha">
                        </div>
                        <div class="filtro-grupo" style="justify-content:flex-end;">
                            <span class="filtro-label">&nbsp;</span>
                            <div style="display:flex;gap:6px;">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrar</button>
                                <?php if ($filtro_estado || $filtro_busqueda || $filtro_responsable || $filtro_fecha_oc): ?>
                                <a href="?seccion=proyectos" class="btn-filtro-clear">Limpiar filtros</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <?php
            // Alerta de proyectos críticos por fecha
            $criticos = array_filter($proyectos, fn($p) => !empty($p['dias_clase']) && $p['dias_clase'] === 'badge-rojo' && !in_array($p['estado'], ['cancelado','completado']));
            if (!empty($criticos)):
            ?>
            <div class="alerta-fecha">
                <i class="fas fa-exclamation-triangle"></i>
                <strong><?= count($criticos) ?> proyecto(s) con fechas críticas:</strong>
                <?= implode(', ', array_map(fn($p) => htmlspecialchars($p['nombre']), $criticos)) ?>
            </div>
            <?php endif; ?>

            <?php if (empty($proyectos)): ?>
            <div class="empty-state"><i class="fas fa-folder-open"></i><p>No hay proyectos que coincidan con los filtros.</p></div>
            <?php else: ?>
            <div class="proyectos-list">
                <?php foreach ($proyectos as $p):
                    $estado    = $p['estado'] ?? 'pendiente';
                    $bom_proy  = $boms_por_proyecto[$p['id']] ?? [];
                    $tiene_bom = !empty($bom_proy);
                    $nivel_c   = $p['nivel_compras'] ?? -1;
                    
                    // CONGRUENCIA: La barra principal toma el progreso de las fases (si existen)
                    $fases_p = $fases_por_proyecto[$p['id']] ?? [];
                    if (!empty($fases_p)) {
                        $pct_avance = round(array_sum(array_column($fases_p,'pct_avance')) / count($fases_p));
                    } else {
                        $pct_avance = $p['porcentaje_avance'] ?? 0;
                    }

                    $card_class = '';
                    if (!empty($p['dias_clase']) && $p['dias_clase'] === 'badge-rojo') {
                        if (str_contains($p['dias_texto'], 'retraso')) $card_class = 'retrasado';
                        else $card_class = 'vence-pronto';
                    }
                ?>
                <div class="proy-card <?= $card_class ?>" id="card-<?= $p['id'] ?>">
                    <div class="proy-card-header" onclick="toggleCard(<?= $p['id'] ?>)">
                        <div class="proy-info">
                            <div class="proy-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
                            <?php if (!empty($p['descripcion'])): ?>
                            <div class="proy-desc"><?= htmlspecialchars(mb_substr($p['descripcion'],0,120)) ?><?= mb_strlen($p['descripcion'])>120?'…':'' ?></div>
                            <?php endif; ?>
                            <div class="proy-meta">
                                <span class="badge badge-estado-<?= $estado ?>"><?= ucfirst(str_replace('_',' ',$estado)) ?></span>
                                <?php if (!empty($p['fecha_inicio'])): ?>
                                <span style="font-size:.78rem;color:#666;"><i class="fas fa-play-circle" style="color:#005686;"></i> <?= date('d/m/Y',strtotime($p['fecha_inicio'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($p['fecha_finiquito'])): ?>
                                <span style="font-size:.78rem;color:#666;"><i class="fas fa-flag-checkered" style="color:#FF9800;"></i> <?= date('d/m/Y',strtotime($p['fecha_finiquito'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($p['dias_texto'])): ?>
                                <span class="badge <?= $p['dias_clase'] ?>"><i class="fas fa-clock"></i> <?= htmlspecialchars($p['dias_texto']) ?></span>
                                <?php endif; ?>
                                <?php if ($tiene_bom): ?>
                                <span class="badge badge-bom"><i class="fas fa-list-check"></i>
                                <?php
                                    $n_listas = count(array_unique(array_column($bom_proy,'lista_id')));
                                    echo $n_listas . ' lista' . ($n_listas>1?'s':'');
                                ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($nivel_c >= 0): ?>
                                <span class="badge-compras" style="background:<?= $nivel_colors[$nivel_c] ?>;">
                                    <i class="fas fa-shopping-cart"></i> <?= $nivel_labels[$nivel_c] ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <!-- Barra de avance sincronizada -->
                            <div class="progress-wrap">
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-fill" style="width:<?= $pct_avance ?>%;"></div>
                                </div>
                                <div class="progress-label"><?= $pct_avance ?>% avance del proyecto</div>
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;">
                            <i class="fas fa-chevron-down toggle-icon"></i>
                            <a href="?seccion=proyectos&ver=<?= $p['id'] ?>" class="btn-ver-detalle" onclick="event.stopPropagation();" title="Ver detalle del proyecto">
                                <i class="fas fa-chart-bar"></i> Ver detalle
                            </a>
                        </div>
                    </div>

                    <div class="bom-panel">
                        <div class="bom-inner">

                            <!-- ── GANTT DE FASES ───────────────────────────────── -->
                            <?php
                            $fases_def_labels = ['ingenieria'=>'Ingeniería','procura'=>'Procura','manufactura'=>'Producción','fat'=>'Pruebas FAT'];
                            $fases_est_colors = ['pendiente'=>'#9e9e9e','en_proceso'=>'#1565c0','completado'=>'#2E7D32','cancelado'=>'#b71c1c'];
                            
                            // Re-utilizar el porcentaje ya sincronizado
                            $pct_global = $pct_avance;
                            ?>
                            <div style="margin-bottom:16px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                    <span style="font-size:.82rem;font-weight:700;color:#003d5c;"><i class="fas fa-project-diagram"></i> Avance por Fases</span>
                                    <span style="font-size:.78rem;color:#555;font-weight:700;"><?= $pct_global ?>% global</span>
                                    <?php if (empty($fases_p) && (hasAcceso('proyectos') || isAdmin())): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="accion" value="crear_fases_proyecto">
                                        <input type="hidden" name="proyecto_id" value="<?= $p['id'] ?>">
                                        <button type="submit" style="font-size:.72rem;background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:3px 9px;cursor:pointer;font-family:inherit;font-weight:600;">
                                            <i class="fas fa-plus"></i> Activar fases
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($fases_p)): ?>
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($fases_p as $fase): ?>
                                <div style="display:grid;grid-template-columns:110px 1fr 80px 70px;gap:8px;align-items:center;">
                                    <span style="font-size:.75rem;font-weight:600;color:#555;"><?= htmlspecialchars($fases_def_labels[$fase['fase']] ?? $fase['fase']) ?></span>
                                    <div style="position:relative;height:14px;background:#e8ecf0;border-radius:7px;overflow:hidden;">
                                        <div style="width:<?= $fase['pct_avance'] ?>%;height:100%;background:<?= $fases_est_colors[$fase['estado']] ?? '#005686' ?>;border-radius:7px;transition:width .3s;"></div>
                                        <span style="position:absolute;right:4px;top:0;height:100%;display:flex;align-items:center;font-size:.6rem;font-weight:700;color:<?= $fase['pct_avance'] > 60 ? '#fff' : '#555' ?>;"><?= $fase['pct_avance'] ?>%</span>
                                    </div>
                                    <span style="font-size:.7rem;color:<?= $fases_est_colors[$fase['estado']] ?? '#555' ?>;font-weight:700;"><?= ucfirst(str_replace('_',' ',$fase['estado'])) ?></span>
                                    <?php if (hasAcceso('proyectos') || isAdmin()): ?>
                                    <button onclick="abrirEditarFase(<?= $fase['id'] ?>, <?= $fase['pct_avance'] ?>, '<?= $fase['estado'] ?>', '<?= $fase['fecha_inicio'] ?? '' ?>', '<?= $fase['fecha_fin_plan'] ?? '' ?>')"
                                            style="font-size:.7rem;background:#f0f4f8;color:#003d5c;border:1px solid #dde;border-radius:5px;padding:2px 7px;cursor:pointer;font-family:inherit;">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div style="font-size:.78rem;color:#aaa;padding:6px 0;">Sin fases configuradas — pulsa "Activar fases" para habilitarlas.</div>
                                <?php endif; ?>
                            </div>

                            <?php if (!$tiene_bom): ?>
                            <div class="no-bom"><i class="fas fa-info-circle"></i> Sin BOM registrado.</div>
                            <?php else:
                                $por_lista = [];
                                foreach ($bom_proy as $r) $por_lista[$r['lista_id']][] = $r;
                                foreach ($por_lista as $lid => $items):
                                    $lst_nombre  = $items[0]['lista_nombre'];
                                    $lst_guardado = $listas_guardado[$lid] ?? 0;
                                    $lst_tipo     = $items[0]['tipo_lista'] ?? 'principal';
                            ?>
                            <div class="bom-lista-title">
                                <i class="fas fa-<?= $lst_tipo === 'plano' ? 'drafting-compass' : 'layer-group' ?>"></i>
                                <?= htmlspecialchars($lst_nombre) ?>
                                <?php if ($lst_guardado): ?>
                                <span class="badge-saved"><i class="fas fa-lock"></i> Guardado</span>
                                <?php else: ?>
                                <span class="badge-draft"><i class="fas fa-pencil-alt"></i> Borrador</span>
                                <?php endif; ?>
                                <?php if (hasAcceso('ingenieria') || isAdmin()): ?>
                                <a href="ingenieria.php?seccion=boom&proyecto_boom=<?= $p['id'] ?>&boom_id=<?= $lid ?>" class="btn btn-outline btn-sm" style="margin-left:8px;" title="Editar BOM">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <div style="overflow-x:auto;">
                            <table class="bom-table">
                                <thead><tr><th>#</th><th>Código</th><th>Material</th><th>Descripción</th><th style="text-align:center">Cant.</th></tr></thead>
                                <tbody>
                                <?php foreach ($items as $bi): ?>
                                <tr>
                                    <td><?= intval($bi['item']) ?></td>
                                    <td><?= htmlspecialchars($bi['codigo'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($bi['mat_nombre'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($bi['descripcion'] ?? '—') ?></td>
                                    <td style="text-align:center"><?= intval($bi['cantidad']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <?php endforeach; endif; ?>

                            <!-- ── OCs del proyecto ── -->
                            <?php
                            $ocs_p = $ocs_por_proyecto[$p['id']] ?? [];
                            if (!empty($ocs_p)):
                                $oc_est_cfg = [
                                    'pendiente_aprobacion' => ['Pend. aprobación','#92400e','#fffbeb','#fde68a'],
                                    'aprobada'             => ['Aprobada','#166534','#f0fdf4','#86efac'],
                                    'recibida'             => ['Recibida','#1e3a8a','#eff6ff','#93c5fd'],
                                    'rechazada'            => ['Rechazada','#991b1b','#fef2f2','#fca5a5'],
                                ];
                                $hay_tardias = array_filter($ocs_p, fn($o) =>
                                    !empty($o['fecha_entrega_estimada']) && !empty($p['fecha_finiquito'])
                                    && $o['fecha_entrega_estimada'] > $p['fecha_finiquito']);
                            ?>
                            <div style="margin-top:16px;border-top:1.5px solid #e2e8f0;padding-top:14px;">

                                <!-- Header OC -->
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                                    <div style="display:flex;align-items:center;gap:7px;">
                                        <div style="width:24px;height:24px;background:linear-gradient(135deg,#023760 0%,#0369a1 100%);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <i class="fas fa-file-invoice-dollar" style="color:#fff;font-size:.58rem;"></i>
                                        </div>
                                        <span style="font-size:.74rem;font-weight:800;color:#0f172a;letter-spacing:.05em;text-transform:uppercase;">Órdenes de Compra</span>
                                        <span style="background:#023760;color:#e0f0ff;font-size:.62rem;font-weight:700;padding:1px 7px;border-radius:20px;line-height:1.7;"><?= count($ocs_p) ?></span>
                                    </div>
                                    <?php if ($hay_tardias): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;background:#fffbeb;border:1px solid #f59e0b;color:#92400e;font-size:.64rem;font-weight:700;padding:2px 8px;border-radius:20px;">
                                        <i class="fas fa-exclamation-triangle" style="font-size:.58rem;"></i><?= count($hay_tardias) ?> con retraso
                                    </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Lista OCs -->
                                <div style="display:flex;flex-direction:column;gap:5px;">
                                <?php foreach ($ocs_p as $oc_r):
                                    $es_tarde = !empty($oc_r['fecha_entrega_estimada']) && !empty($p['fecha_finiquito'])
                                                && $oc_r['fecha_entrega_estimada'] > $p['fecha_finiquito'];
                                    [$oc_lbl,$oc_c,$oc_bg,$oc_border] = $oc_est_cfg[$oc_r['estado']] ?? ['Otro','#6b7280','#f9fafb','#e5e7eb'];
                                    $puede_rechazar = (hasAcceso('proyectos') || isAdmin()) && $oc_r['estado'] !== 'rechazada';
                                    $puede_confirmar_fecha = (hasAcceso('proyectos') || isAdmin()) && $oc_r['estado'] === 'pendiente_aprobacion' && !$oc_r['revision_proyectos_ok'];
                                ?>
                                <div style="background:<?= $es_tarde ? '#fffbeb' : '#f8fafc' ?>;border:1px solid <?= $es_tarde ? '#fde68a' : '#e2e8f0' ?>;border-left:3px solid <?= $es_tarde ? '#f59e0b' : '#023760' ?>;border-radius:9px;padding:9px 12px;transition:box-shadow .15s;"
                                     onmouseover="this.style.boxShadow='0 2px 10px rgba(2,55,96,.1)'" onmouseout="this.style.boxShadow='none'">

                                    <!-- Fila 1: Folio + Estado -->
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px;">
                                        <div style="font-size:.76rem;font-weight:800;color:#0f172a;font-family:'Courier New',monospace;letter-spacing:.03em;line-height:1;"><?= htmlspecialchars($oc_r['folio']) ?></div>
                                        <span style="flex-shrink:0;background:<?= $oc_bg ?>;color:<?= $oc_c ?>;border:1px solid <?= $oc_border ?>;font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:.02em;"><?= $oc_lbl ?></span>
                                    </div>

                                    <!-- Fila 2: Proveedor -->
                                    <div style="font-size:.7rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:8px;" title="<?= htmlspecialchars($oc_r['proveedor']) ?>">
                                        <i class="fas fa-building" style="font-size:.6rem;margin-right:3px;color:#94a3b8;"></i><?= htmlspecialchars($oc_r['proveedor']) ?>
                                    </div>

                                    <!-- Fila 3: Fecha + Total + Botones -->
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <?php if ($oc_r['fecha_entrega_estimada']): ?>
                                            <div style="display:flex;align-items:center;gap:3px;">
                                                <i class="fas fa-calendar-check" style="font-size:.6rem;color:<?= $es_tarde ? '#f59e0b' : '#94a3b8' ?>;"></i>
                                                <span style="font-size:.69rem;font-weight:600;color:<?= $es_tarde ? '#92400e' : '#475569' ?>;"><?= date('d/m/y', strtotime($oc_r['fecha_entrega_estimada'])) ?></span>
                                                <?php if ($es_tarde): ?><span style="font-size:.62rem;color:#ef4444;font-weight:700;margin-left:2px;">⚠ tarde</span><?php endif; ?>
                                            </div>
                                            <?php else: ?>
                                            <span style="font-size:.68rem;color:#cbd5e1;"><i class="fas fa-calendar" style="font-size:.58rem;margin-right:2px;"></i>Sin fecha</span>
                                            <?php endif; ?>
                                            <div style="font-size:.74rem;font-weight:800;color:#0f172a;">
                                                $<?= number_format($oc_r['total'],2) ?>
                                                <span style="font-size:.61rem;font-weight:600;color:#94a3b8;margin-left:1px;"><?= htmlspecialchars($oc_r['moneda']) ?></span>
                                            </div>
                                        </div>
                                        <div style="display:flex;gap:4px;align-items:center;flex-shrink:0;">
                                            <a href="orden_compra.php?orden_id=<?= intval($oc_r['id']) ?>" target="_blank"
                                               style="display:inline-flex;align-items:center;gap:4px;background:#023760;color:#fff;font-size:.66rem;font-weight:700;padding:5px 11px;border-radius:7px;text-decoration:none;letter-spacing:.02em;transition:background .15s;line-height:1;"
                                               onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#023760'">
                                                <i class="fas fa-eye" style="font-size:.58rem;"></i> Ver OC
                                            </a>
                                            <?php if ($oc_r['revision_proyectos_ok']): ?>
                                            <span style="display:inline-flex;align-items:center;gap:3px;color:#166534;font-size:.64rem;font-weight:700;" title="Fecha confirmada por Proyectos">
                                                <i class="fas fa-calendar-check" style="font-size:.58rem;"></i> Fecha OK
                                            </span>
                                            <?php elseif ($puede_confirmar_fecha): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="accion" value="confirmar_fecha_oc">
                                                <input type="hidden" name="oc_id" value="<?= intval($oc_r['id']) ?>">
                                                <button type="submit"
                                                        style="display:inline-flex;align-items:center;gap:3px;background:#fff;color:#166534;border:1.5px solid #86efac;font-size:.66rem;font-weight:700;padding:4px 9px;border-radius:7px;cursor:pointer;letter-spacing:.02em;transition:all .15s;line-height:1;"
                                                        onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='#fff'"
                                                        onclick="return confirm('¿Confirmar que la fecha de entrega de esta OC le sirve al proyecto? Gerencia podrá aprobarla después de esto.')">
                                                    <i class="fas fa-calendar-check" style="font-size:.58rem;"></i> Confirmar fecha
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($puede_rechazar): ?>
                                            <button onclick="abrirRechazarOC(<?= intval($oc_r['id']) ?>, '<?= htmlspecialchars(addslashes($oc_r['folio']), ENT_QUOTES) ?>')"
                                                    style="display:inline-flex;align-items:center;gap:3px;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;font-size:.66rem;font-weight:700;padding:4px 9px;border-radius:7px;cursor:pointer;letter-spacing:.02em;transition:all .15s;line-height:1;"
                                                    onmouseover="this.style.background='#fef2f2';this.style.borderColor='#dc2626'" onmouseout="this.style.background='#fff';this.style.borderColor='#fca5a5'">
                                                <i class="fas fa-ban" style="font-size:.58rem;"></i> Rechazar
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Acciones del proyecto -->
                            <div class="bom-actions">
                                <?php if ($tiene_bom): ?>
                                <a href="<?= SITE_URL ?>/api/exportar_bom.php?proyecto_id=<?= $p['id'] ?>" target="_blank" class="btn btn-outline btn-sm">
                                    <i class="fas fa-file-excel"></i> Exportar BOM
                                </a>
                                <?php endif; ?>
                            </div>

                            <!-- Feed de actividad reciente -->
                            <?php
                            try {
                                $feed_stmt = $db->prepare("SELECT pa.titulo, pa.tipo, pa.fecha_creacion FROM proyecto_actividad pa WHERE pa.proyecto_id = ? ORDER BY pa.fecha_creacion DESC LIMIT 5");
                                $feed_stmt->execute([$p['id']]);
                                $feed = $feed_stmt->fetchAll();
                                if (!empty($feed)):
                            ?>
                            <div class="act-feed">
                                <div class="act-feed-title"><i class="fas fa-history"></i> Actividad reciente</div>
                                <?php foreach ($feed as $fi): ?>
                                <div class="act-item-feed">
                                    <i class="fas fa-circle act-icon" style="font-size:.5rem;margin-top:5px;"></i>
                                    <div>
                                        <?= htmlspecialchars($fi['titulo']) ?>
                                        <small style="color:#aaa;margin-left:6px;"><?= date('d/m/Y H:i',strtotime($fi['fecha_creacion'])) ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; } catch (Exception $e) { /* tabla no existe aún */ } ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'proyectos'): ?>
        <!-- El botón Nueva Proyecto se agrega en el sticky header de esa sección -->
        <?php endif; ?>

        <?php if ($seccion === 'ordenes') { header('Location: produccion.php?seccion=ordenes'); exit(); } ?>

        <!-- ══════════════ SECCIÓN ORDEN DE PRODUCCIÓN / SERVICIO (DOC-PRY-FO-02B) ══════════════ -->
        <?php
        // ── Cargar datos de órdenes de producción ──────────────────────────
        $ordenes_produccion_list = [];
        try {
            $ordenes_produccion_list = $db->query(
                "SELECT id, numero_proyecto, cliente, fecha, estado FROM ordenes_produccion ORDER BY fecha_creacion DESC"
            )->fetchAll();
        } catch(Exception $e) {}

        // Proyectos con resumen guardado pero sin Orden de Producción aún
        $resumenes_pendientes = [];
        try {
            $resumenes_pendientes = $db->query(
                "SELECT pr.proyecto_id, pr.cliente, pr.numero_po, pr.descripcion,
                        p.nombre AS nombre_proyecto,
                        COALESCE(pr.fecha_actualizacion, pr.fecha_creacion) AS fecha_mod
                 FROM proyecto_resumen pr
                 JOIN proyectos p ON p.id = pr.proyecto_id
                 WHERE (pr.cliente IS NOT NULL OR pr.numero_po IS NOT NULL OR pr.descripcion IS NOT NULL)
                   AND NOT EXISTS (
                       SELECT 1 FROM ordenes_produccion op
                       WHERE op.numero_proyecto COLLATE utf8mb4_unicode_ci = p.nombre
                   )
                 ORDER BY fecha_mod DESC"
            )->fetchAll();
        } catch(Exception $e) { /* tabla puede no tener datos aún */ }

        $orden_edit = null;
        $op_escenarios = []; // hasta 3 fechas calculadas (Resumen de Proyecto) para el selector
        if (isset($_GET['edit_ord'])) {
            try {
                $s = $db->prepare("SELECT * FROM ordenes_produccion WHERE id = ?");
                $s->execute([intval($_GET['edit_ord'])]);
                $orden_edit = $s->fetch();
                if ($orden_edit) {
                    $orden_edit['items_arr']    = json_decode($orden_edit['items'],    true) ?: [];
                    $orden_edit['checklist_arr'] = json_decode($orden_edit['checklist'], true) ?: [];
                    // Cargar los 3 escenarios desde proyecto_resumen (por proyecto_id o nombre)
                    try {
                        $s_esc = $db->prepare("SELECT fecha_entrega, fecha_anticipo, fecha_aprobacion_ing, semanas_plazo, semanas_plazo_po, semanas_plazo_anticipo FROM proyecto_resumen WHERE proyecto_id=? OR proyecto_id=(SELECT id FROM proyectos WHERE nombre COLLATE utf8mb4_general_ci=? LIMIT 1) LIMIT 1");
                        $s_esc->execute([$orden_edit['proyecto_id'] ?? 0, $orden_edit['numero_proyecto'] ?? '']);
                        $r_esc = $s_esc->fetch();
                        if ($r_esc) $op_escenarios = calcularEscenariosFecha($r_esc);
                    } catch (Exception $e) {}
                }
            } catch(Exception $e) {}

        } elseif (isset($_GET['desde_proyecto'])) {
            // ── Pre-llenar desde el resumen de proyecto ──────────────────────
            $dp_id = intval($_GET['desde_proyecto']);
            try {
                $stmt_dp = $db->prepare(
                    "SELECT pr.*, p.nombre AS nombre_proyecto
                     FROM proyecto_resumen pr
                     JOIN proyectos p ON p.id = pr.proyecto_id
                     WHERE pr.proyecto_id = ? LIMIT 1"
                );
                $stmt_dp->execute([$dp_id]);
                $dp = $stmt_dp->fetch();

                if ($dp) {
                    // Convertir items del resumen al formato de la Orden de Producción
                    $r_items_dp = json_decode($dp['items_json'] ?? '[]', true) ?: [];
                    $items_op   = [];
                    foreach ($r_items_dp as $i => $it) {
                        $qty  = trim(($it['cantidad'] ?? '') . ' ' . ($it['unidad'] ?? ''));
                        $items_op[] = [
                            'item' => $it['item'] ?? ($i + 1),
                            'tag'  => $it['tag']  ?? '',
                            'qty'  => trim($qty),
                            'desc' => $it['descripcion'] ?? '',
                        ];
                    }

                    // Ya no se pre-llena sola (pedido explícito, 2026-09-07): el usuario elige a
                    // mano cuál de los 3 escenarios usar en el selector de más abajo.
                    $op_escenarios = calcularEscenariosFecha($dp);

                    // Autocompletar Observaciones/Notas adicionales con las condiciones comerciales
                    // del resumen (pago, entrega, garantía) además de sus propias observaciones --
                    // sin esto, Proyectos tenía que volver a escribirlas a mano en la Orden de
                    // Producción aunque ya las hubiera capturado antes en el Resumen del Proyecto.
                    $dp_obs_texto = trim($dp['observaciones'] ?? '');
                    if (!empty(trim($dp['condiciones'] ?? ''))) {
                        $dp_obs_texto = trim("Condiciones comerciales: " . trim($dp['condiciones']) . ($dp_obs_texto !== '' ? "\n\n" . $dp_obs_texto : ''));
                    }

                    $orden_edit = [
                        'id'              => 0,
                        'numero_proyecto' => $dp['nombre_proyecto'] ?? '',
                        'cliente'         => $dp['cliente']         ?? '',
                        'orden_compra'    => $dp['numero_po']       ?? '',
                        'descripcion'     => $dp['descripcion']     ?? '',
                        'fecha_compromiso'=> '',
                        'observaciones'   => $dp_obs_texto,
                        'fecha'           => date('Y-m-d'),
                        'estado'          => 'borrador',
                        'items'           => json_encode($items_op, JSON_UNESCAPED_UNICODE),
                        'checklist'       => '{}',
                        'items_arr'       => $items_op,
                        'checklist_arr'   => [],
                        '_desde_proyecto' => $dp_id,
                        '_fecha_entrega_texto' => $dp['fecha_entrega'] ?? '',
                    ];
                }
            } catch(Exception $e) {}
        }
        ?>
        <?php if ($seccion === 'formato_trabajo'): ?>
        <style>
            /* ── Variables de color ── */
            :root {
                --op-azul: #003d5c;
                --op-borde: #222;
                --op-bg-header: #d0dce8;
                --op-bg-title: #002d46;
                --op-bg-cl-header: #b8cdd8;
                --op-font: 'Montserrat', 'Segoe UI', Arial, sans-serif;
            }

            /* ── Wrapper pantalla ── */
            .op-screen-wrap {
                max-width: 960px;
                margin: 0 auto;
                background: #f0f4f8;
                padding: 18px;
            }

            /* ── Documento principal ── */
            .op-doc {
                background: #fff;
                border: 2px solid var(--op-borde);
                font-family: var(--op-font);
                font-size: 0.78rem;
                color: #111;
            }

            /* ── Encabezado logo + título + logo ── */
            .op-header {
                display: grid;
                grid-template-columns: 140px 1fr 140px;
                border-bottom: 2px solid var(--op-borde);
            }
            .op-header-logo {
                border-right: 1.5px solid var(--op-borde);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 8px;
                min-height: 72px;
            }
            .op-header-logo img {
                max-width: 120px;
                max-height: 56px;
                object-fit: contain;
            }
            .op-header-logo-fallback {
                font-size: 1rem;
                font-weight: 800;
                color: var(--op-azul);
                letter-spacing: 2px;
                text-align: center;
            }
            .op-header-title {
                display: flex;
                align-items: center;
                justify-content: center;
                background: var(--op-bg-title);
                color: #fff;
                font-size: 1.05rem;
                font-weight: 800;
                letter-spacing: 1.5px;
                text-align: center;
                padding: 10px 6px;
                text-transform: uppercase;
            }
            .op-header-logo-right {
                border-left: 1.5px solid var(--op-borde);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 8px;
                min-height: 72px;
            }
            .op-header-logo-right img {
                max-width: 120px;
                max-height: 56px;
                object-fit: contain;
            }

            /* ── Campos de cabecera técnica ── */
            .op-fields {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                border-bottom: 1.5px solid var(--op-borde);
            }
            .op-field {
                border-right: 1px solid var(--op-borde);
                padding: 5px 8px;
            }
            .op-field:last-child { border-right: none; }
            .op-field-full {
                grid-column: 1 / -1;
                border-right: none;
                border-top: 1px solid var(--op-borde);
            }
            .op-field label {
                display: block;
                font-size: 0.65rem;
                font-weight: 700;
                color: #444;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 3px;
            }
            .op-field input[type="text"],
            .op-field input[type="date"],
            .op-field textarea {
                width: 100%;
                border: none;
                border-bottom: 1px solid #aaa;
                background: transparent;
                font-family: var(--op-font);
                font-size: 0.8rem;
                color: #000;
                padding: 2px 0;
                outline: none;
                resize: none;
                box-sizing: border-box;
            }
            .op-field input:focus, .op-field textarea:focus {
                border-bottom-color: var(--op-azul);
            }

            /* ── Tabla de productos ── */
            .op-section-title {
                background: var(--op-bg-header);
                border-bottom: 1.5px solid var(--op-borde);
                border-top: 1.5px solid var(--op-borde);
                padding: 5px 10px;
                font-weight: 800;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                color: var(--op-azul);
            }
            .op-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.75rem;
            }
            .op-table th {
                background: var(--op-bg-header);
                border: 1px solid var(--op-borde);
                padding: 5px 7px;
                font-weight: 700;
                text-align: center;
                text-transform: uppercase;
                font-size: 0.68rem;
                letter-spacing: 0.5px;
            }
            .op-table td {
                border: 1px solid #bbb;
                padding: 3px 5px;
                vertical-align: middle;
            }
            .op-table td textarea,
            .op-table td input[type="text"],
            .op-table td input[type="number"] {
                width: 100%;
                border: none;
                background: transparent;
                font-family: var(--op-font);
                font-size: 0.75rem;
                color: #000;
                outline: none;
                padding: 1px 2px;
                text-align: center;
            }
            .op-table td.op-td-desc input,
            .op-table td.op-td-desc textarea { text-align: left; }
            .op-table td textarea {
                resize: none;
                overflow: hidden;
                min-height: 1.6em;
                line-height: 1.4;
                vertical-align: top;
                padding: 2px;
                font-family: var(--op-font);
                font-size: 0.78rem;
                color: #000;
                box-sizing: border-box;
                outline: none;
            }
            .op-field textarea.op-auto { resize: none; overflow: hidden; }
            .op-table td.op-td-num { width: 44px; text-align: center; }
            .op-table td.op-td-tag { width: 90px; }
            .op-table td.op-td-qty { width: 58px; }
            .op-table td.op-td-del { width: 28px; text-align: center; border-color: #ccc; }
            .op-table tr:nth-child(even) td { background: #f7fafc; }
            .op-table tr:hover td { background: #eef5fb; }
            .btn-add-row {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin: 7px 10px;
                padding: 4px 12px;
                background: var(--op-azul);
                color: #fff;
                border: none;
                border-radius: 5px;
                font-family: var(--op-font);
                font-size: 0.72rem;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            .btn-add-row:hover { background: #005686; }
            .btn-del-row {
                background: none;
                border: none;
                color: #c0392b;
                cursor: pointer;
                font-size: 0.8rem;
                padding: 0 2px;
                line-height: 1;
            }

            /* ── Sección de checklists ── */
            .op-cl-wrapper {
                border-top: 1.5px solid var(--op-borde);
                display: grid;
                grid-template-columns: repeat(3, 1fr);
            }
            .op-cl-col {
                border-right: 1.5px solid var(--op-borde);
                display: flex;
                flex-direction: column;
            }
            .op-cl-col:last-child { border-right: none; }
            .op-cl-col-title {
                background: var(--op-bg-cl-header);
                border-bottom: 1px solid var(--op-borde);
                padding: 5px 8px;
                font-weight: 800;
                font-size: 0.68rem;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                color: var(--op-azul);
                text-align: center;
            }
            .op-cl-item {
                display: flex;
                align-items: center;
                gap: 7px;
                padding: 5px 9px;
                border-bottom: 1px solid #e0e8f0;
                cursor: pointer;
                transition: background 0.15s;
                user-select: none;
            }
            .op-cl-item:last-child { border-bottom: none; }
            .op-cl-item:hover { background: #eef5fb; }
            .op-cl-item input[type="checkbox"] {
                width: 14px;
                height: 14px;
                accent-color: var(--op-azul);
                cursor: pointer;
                flex-shrink: 0;
            }
            .op-cl-item label {
                font-size: 0.72rem;
                color: #222;
                cursor: pointer;
                line-height: 1.35;
                margin: 0;
            }
            .op-cl-item input[type="checkbox"]:checked + label {
                color: var(--op-azul);
                font-weight: 700;
            }

            /* ── Observaciones ── */
            .op-obs {
                border-top: 1.5px solid var(--op-borde);
                padding: 6px 10px;
            }
            .op-obs label {
                font-size: 0.67rem;
                font-weight: 700;
                color: #444;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                display: block;
                margin-bottom: 3px;
            }
            .op-obs textarea {
                width: 100%;
                border: 1px solid #ccc;
                background: #fafcff;
                font-family: var(--op-font);
                font-size: 0.78rem;
                color: #000;
                padding: 5px 7px;
                outline: none;
                resize: vertical;
                min-height: 54px;
                box-sizing: border-box;
                border-radius: 3px;
            }
            .op-obs textarea:focus { border-color: var(--op-azul); }

            /* ── Footer del documento ── */
            .op-doc-footer {
                background: var(--op-azul);
                color: #fff;
                display: grid;
                grid-template-columns: 1fr auto 1fr;
                align-items: center;
                padding: 5px 12px;
                font-size: 0.63rem;
                border-top: 2px solid var(--op-borde);
                gap: 4px;
            }
            .op-doc-footer .op-df-code { font-weight: 800; font-size: 0.72rem; }
            .op-doc-footer .op-df-center { text-align: center; font-size: 0.6rem; opacity: 0.85; }
            .op-doc-footer .op-df-right { text-align: right; font-size: 0.6rem; opacity: 0.8; }

            /* ── Botones pantalla ── */
            .op-action-bar {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                margin-bottom: 14px;
            }

            /* ══════════════ ESTILOS DE IMPRESIÓN ══════════════ */
            /* ── Footer tabla editable ── */
            .op-footer-table {
                width: 100%;
                border-collapse: collapse;
                border-top: 2px solid var(--op-borde);
                font-size: 0.72rem;
                font-family: var(--op-font);
            }
            .op-footer-table td {
                border: 1px solid var(--op-borde);
                padding: 5px 10px;
                font-weight: 700;
                text-align: center;
                color: #111;
            }
            .op-footer-table .footer-nota {
                font-weight: 400;
                font-style: italic;
                font-size: 0.67rem;
                color: #333;
            }
            .footer-edit {
                display: inline-block;
                min-width: 28px;
                outline: none;
                border-bottom: 1.5px dashed #999;
                cursor: text;
                padding: 0 2px;
                font-weight: 700;
            }
            .footer-edit:focus { border-bottom-color: #003d5c; background: #f0f8ff; }

            @media print {
                /* Márgenes mínimos: suprime cabeceras del navegador y gana espacio vertical */
                @page { size: Letter portrait; margin: 3mm; }

                html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
                .top-header, .sticky-container, .no-print, .dashboard-footer,
                .btn-add-row, .btn-del-row, .op-action-bar, .op-td-del { display: none !important; }
                .app-container { padding-top: 0 !important; height: auto !important; }
                .section-content { height: auto !important; overflow: visible !important; padding: 0 !important; }

                /* Wrapper sin padding extra */
                .op-screen-wrap {
                    background: #fff !important;
                    padding: 0 !important;
                    max-width: 100% !important;
                }

                /* Documento: fuente ligeramente menor para ganar líneas */
                .op-doc {
                    border: 1.5px solid #000 !important;
                    font-size: 0.68rem !important;
                    page-break-inside: avoid !important;
                }

                /* Encabezado más compacto */
                .op-header-logo,
                .op-header-logo-right { min-height: 58px !important; padding: 6px !important; }
                .op-header-title { font-size: 0.92rem !important; padding: 7px 6px !important; }

                /* Campos cabecera */
                .op-field { padding: 4px 8px !important; }
                .op-field-val { font-size: 0.75rem !important; }
                .op-field input, .op-field textarea { border-bottom: 1px solid #888 !important; font-size: 0.75rem !important; }

                /* Títulos de sección */
                .op-section-title { padding: 3px 8px !important; font-size: 0.68rem !important; }

                /* Tabla de productos */
                .op-table th { padding: 3px 5px !important; font-size: 0.62rem !important; }
                .op-table td { padding: 3px 5px !important; }
                .op-table td input { color: #000 !important; font-size: 0.68rem !important; }

                /* Checklists */
                .op-cl-item { padding: 3px 8px !important; }
                .op-cl-item:hover { background: transparent !important; }
                .op-cl-item span { font-size: 0.65rem !important; }
                .op-cl-col-title { padding: 3px 7px !important; font-size: 0.62rem !important; }

                /* Observaciones */
                .op-obs { padding: 5px 10px !important; }
                .op-obs textarea { border: none !important; background: transparent !important; resize: none !important; font-size: 0.68rem !important; }

                /* Un campo vacío no debe mostrar su placeholder de ejemplo como si fuera
                   dato real -- esto ya es el documento oficial impreso/PDF, no el formulario
                   de captura (pedido explícito, 2026-09-07). */
                .op-doc input::placeholder, .op-doc textarea::placeholder { color: transparent !important; }

                /* Footer: nunca partir en dos páginas */
                .op-footer-table { page-break-inside: avoid !important; }
                .op-footer-table td { padding: 4px 8px !important; font-size: 0.67rem !important; }
                .footer-edit { border-bottom: none !important; background: transparent !important; }

                /* Checkboxes con color */
                .op-cl-item input[type="checkbox"] {
                    print-color-adjust: exact;
                    -webkit-print-color-adjust: exact;
                }
            }
        </style>

        <style>
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;}
        .modal-overlay.active{display:flex;}
        .modal-overlay .modal{background:#fff;border-radius:12px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;}
        .modal-overlay .modal-header{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;}
        .modal-overlay .modal-title{font-size:.95rem;font-weight:700;}
        .modal-overlay .modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;line-height:1;opacity:.7;}
        .modal-overlay .modal-body{padding:20px;}
        .modal-overlay .modal-footer{padding:14px 20px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #eee;}
        </style>

        <!-- Modal confirmación Difundir -->
        <div class="modal-overlay" id="modalDifundir">
            <div class="modal" style="max-width:440px;">
                <div class="modal-header" style="background:linear-gradient(135deg,#1b5e20,#2e7d32);">
                    <div class="modal-title" style="color:#fff;"><i class="fas fa-broadcast-tower"></i> Difundir Orden de Producción</div>
                    <button class="modal-close" onclick="cerrarModal('modalDifundir')" style="color:#fff;">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="margin:0 0 10px;font-size:14px;color:#333;">¿Deseas difundir esta Orden de Producción a todas las áreas?</p>
                    <ul style="font-size:13px;color:#555;padding-left:18px;margin:0;line-height:1.8;">
                        <li>Se creará el proyecto en Ingeniería automáticamente</li>
                        <li>Todas las áreas podrán ver la orden en modo lectura</li>
                        <li>El estado cambiará de <strong>Borrador</strong> a <strong>Difundida</strong></li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline" onclick="cerrarModal('modalDifundir')">Cancelar</button>
                    <button class="btn btn-success" onclick="confirmarDifundir()" style="background:#2e7d32;color:#fff;">
                        <i class="fas fa-broadcast-tower"></i> Sí, Difundir
                    </button>
                </div>
            </div>
        </div>

        <div class="section-content" style="padding:0;height:calc(100vh - 140px);display:flex;flex-direction:column;overflow:hidden;">
            <!-- Barra de acciones (no se imprime) -->
            <div class="sticky-container no-print" style="padding:10px 16px;border-bottom:1px solid #e0e0e0;flex-shrink:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <h2 style="font-size:1rem;color:#003d5c;margin:0;"><i class="fas fa-industry"></i> Orden de Producción / Servicio</h2>
                    <div style="display:flex;gap:8px;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="guardarBorrador()"><i class="fas fa-save"></i> Guardar Borrador</button>
                        <button type="button" class="btn btn-sm" onclick="difundirOrden()" style="background:#2e7d32;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:.78rem;font-weight:600;"><i class="fas fa-broadcast-tower"></i> Difundir</button>
                        <button class="btn btn-outline btn-sm" onclick="limpiarOrden()"><i class="fas fa-eraser"></i> Limpiar</button>
                        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                    </div>
                </div>
            </div>

            <!-- Layout: sidebar + área principal -->
            <div style="display:flex;flex:1;overflow:hidden;">

                <!-- BARRA LATERAL: lista de órdenes -->
                <div class="no-print" style="width:230px;min-width:230px;background:#f4f6f9;border-right:1px solid #dde4ec;display:flex;flex-direction:column;overflow:hidden;">
                    <div style="background:#003d5c;color:#fff;padding:10px 12px;font-size:.75rem;font-weight:700;letter-spacing:.5px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                        <span><i class="fas fa-folder-open"></i> Órdenes</span>
                        <a href="?seccion=formato_trabajo" style="color:#90caf9;font-size:.7rem;text-decoration:none!important;" title="Nueva orden"><i class="fas fa-plus"></i> Nueva</a>
                    </div>
                    <div style="overflow-y:auto;flex:1;padding:6px 0;">

                        <?php if (!empty($resumenes_pendientes)): ?>
                        <!-- Sugerencias desde Resumen IA -->
                        <div style="padding:8px 12px 4px;font-size:.63rem;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.07em;display:flex;align-items:center;gap:5px;">
                            <i class="fas fa-robot"></i> Desde Resumen IA
                        </div>
                        <?php foreach ($resumenes_pendientes as $rp):
                            $es_actual_rp = !empty($orden_edit['_desde_proyecto']) && $orden_edit['_desde_proyecto'] == $rp['proyecto_id'];
                        ?>
                        <a href="?seccion=formato_trabajo&desde_proyecto=<?= $rp['proyecto_id'] ?>"
                           style="display:flex;align-items:center;gap:9px;padding:8px 12px;border-bottom:1px solid #ede9fe;text-decoration:none!important;background:<?= $es_actual_rp ? '#ede9fe' : '#faf8ff' ?>;border-left:3px solid #7c3aed;transition:background .15s;"
                           onmouseover="this.style.background='#ede9fe'"
                           onmouseout="this.style.background='<?= $es_actual_rp ? '#ede9fe' : '#faf8ff' ?>'">
                            <div style="width:28px;height:28px;background:#ede9fe;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-robot" style="color:#7c3aed;font-size:.65rem;"></i>
                            </div>
                            <div style="min-width:0;flex:1;">
                                <div style="font-weight:700;font-size:.75rem;color:#5b21b6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($rp['nombre_proyecto']) ?></div>
                                <div style="font-size:.67rem;color:#7c3aed;margin-top:1px;">Crear OP →</div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                        <div style="height:1px;background:#e2e8f0;margin:6px 0;"></div>
                        <?php endif; ?>

                        <?php if (empty($ordenes_produccion_list)): ?>
                        <div style="padding:24px 12px;color:#94a3b8;font-size:.75rem;text-align:center;">
                            <i class="fas fa-inbox" style="display:block;font-size:1.6rem;margin-bottom:8px;opacity:.4;"></i>
                            Sin órdenes guardadas
                        </div>
                        <?php else: ?>
                        <div style="padding:8px 12px 4px;font-size:.63rem;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.07em;">
                            Órdenes guardadas
                        </div>
                        <?php foreach ($ordenes_produccion_list as $op):
                            $es_activa = $op['estado'] === 'activa';
                            $es_actual = isset($orden_edit) && $orden_edit && $orden_edit['id'] == $op['id'];
                            $bg_base   = $es_actual ? '#e0f2fe' : '#fff';
                            $borde_izq = $es_actual ? '#0284c7' : ($es_activa ? '#16a34a' : '#e2e8f0');
                        ?>
                        <a href="?seccion=formato_trabajo&edit_ord=<?= $op['id'] ?>"
                           style="display:flex;align-items:center;gap:9px;padding:8px 12px;border-bottom:1px solid #f1f5f9;text-decoration:none!important;background:<?= $bg_base ?>;border-left:3px solid <?= $borde_izq ?>;transition:background .12s;"
                           onmouseover="this.style.background='<?= $es_actual ? '#bae6fd' : '#f0f9ff' ?>'"
                           onmouseout="this.style.background='<?= $bg_base ?>'">
                            <!-- Ícono estado -->
                            <div style="width:30px;height:30px;border-radius:8px;background:<?= $es_activa ? '#dcfce7' : '#f1f5f9' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas <?= $es_activa ? 'fa-broadcast-tower' : 'fa-file-alt' ?>" style="color:<?= $es_activa ? '#16a34a' : '#94a3b8' ?>;font-size:.7rem;"></i>
                            </div>
                            <!-- Info -->
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;font-size:.78rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:monospace;letter-spacing:.01em;">
                                    <?= htmlspecialchars($op['numero_proyecto'] ?? 'Sin N°') ?>
                                </div>
                                <div style="font-size:.67rem;color:#64748b;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars(mb_strimwidth($op['cliente'] ?? '—', 0, 28, '…')) ?>
                                </div>
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:3px;">
                                    <span style="font-size:.62rem;color:#94a3b8;"><?= $op['fecha'] ? date('d/m/Y', strtotime($op['fecha'])) : '—' ?></span>
                                    <span style="font-size:.6rem;font-weight:700;padding:1px 7px;border-radius:20px;background:<?= $es_activa ? '#dcfce7' : '#f1f5f9' ?>;color:<?= $es_activa ? '#166534' : '#64748b' ?>;">
                                        <?= $es_activa ? 'Difundida' : 'Borrador' ?>
                                    </span>
                                </div>
                            </div>
                            <!-- Ver icono -->
                            <a href="ver_orden_produccion.php?id=<?= $op['id'] ?>" target="_blank"
                               onclick="event.stopPropagation()"
                               style="width:24px;height:24px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;text-decoration:none!important;"
                               onmouseover="this.style.background='#e0f2fe'" onmouseout="this.style.background='#f1f5f9'"
                               title="Ver OP">
                                <i class="fas fa-external-link-alt" style="color:#64748b;font-size:.6rem;"></i>
                            </a>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ÁREA PRINCIPAL: formulario -->
                <div style="flex:1;overflow-y:auto;padding-bottom:24px;">
                <form id="formOrdenProduccion" method="POST" action="?seccion=formato_trabajo">
                <input type="hidden" name="accion" value="guardar_orden_produccion">
                <input type="hidden" name="ord_id" id="ord_id_hidden" value="<?= intval($orden_edit['id'] ?? 0) ?>">
                <input type="hidden" name="ord_estado" id="ord_estado_hidden" value="borrador">
                <?php if (!empty($orden_edit['_desde_proyecto'])): ?>
                <div class="no-print" style="background:#e8f5e9;border-left:4px solid #2e7d32;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:.82rem;color:#1b5e20;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-magic"></i>
                    <span>Datos pre-llenados desde el <strong>Resumen del Proyecto</strong>. Revisa y edita lo que necesites antes de guardar.</span>
                    <?php if (!empty($orden_edit['_fecha_entrega_texto'])): ?>
                    <span style="margin-left:auto;background:#c8e6c9;padding:3px 10px;border-radius:8px;font-weight:700;">
                        <i class="fas fa-calendar-alt"></i> Entrega: <?= htmlspecialchars($orden_edit['_fecha_entrega_texto']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <div class="op-screen-wrap">
                <div class="op-doc" id="op-imprimible">

                    <!-- ── ENCABEZADO ── -->
                    <div class="op-header">
                        <div class="op-header-logo">
                            <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO"
                                 onerror="this.style.display='none';document.getElementById('op-logo-fb').style.display='flex'">
                            <div id="op-logo-fb" class="op-header-logo-fallback" style="display:none;">EMPRESA EJEMPLO</div>
                        </div>
                        <div class="op-header-title">ORDEN DE PRODUCCIÓN / SERVICIO</div>
                        <div class="op-header-logo-right">
                            <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO isotipo"
                                 onerror="this.style.display='none'">
                        </div>
                    </div>

                    <!-- ── CAMPOS TÉCNICOS DE CABECERA ── -->
                    <div class="op-fields">
                        <div class="op-field">
                            <label>Fecha</label>
                            <input type="date" id="op_fecha" name="op_fecha"
                                   value="<?= htmlspecialchars($orden_edit['fecha'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="op-field">
                            <label>N° de Proyecto</label>
                            <input type="text" id="op_num_proyecto" name="op_num_proyecto" placeholder="Ej. T-496"
                                   value="<?= htmlspecialchars($orden_edit['numero_proyecto'] ?? '') ?>">
                        </div>
                        <div class="op-field">
                            <label>Orden de Compra</label>
                            <textarea id="op_oc" name="op_oc" placeholder="N° OC del cliente"
                                      rows="1" class="op-auto" oninput="autoResize(this)"
                                      ><?= htmlspecialchars($orden_edit['orden_compra'] ?? '') ?></textarea>
                        </div>
                        <div class="op-field op-field-full">
                            <label>Cliente</label>
                            <textarea id="op_cliente" name="op_cliente" placeholder="Nombre del cliente o empresa"
                                      rows="1" class="op-auto" oninput="autoResize(this)"
                                      ><?= htmlspecialchars($orden_edit['cliente'] ?? '') ?></textarea>
                        </div>
                        <div class="op-field op-field-full" style="border-top:1px solid var(--op-borde);">
                            <label>Descripción del Proyecto</label>
                            <textarea id="op_descripcion" name="op_descripcion" rows="2" class="op-auto"
                                      oninput="autoResize(this)"
                                      placeholder="Descripción general del alcance del proyecto o servicio..."><?= htmlspecialchars($orden_edit['descripcion'] ?? '') ?></textarea>
                        </div>
                        <div class="op-field op-field-full" style="border-top:1px solid var(--op-borde);">
                            <label>Fecha Compromiso de Entrega</label>
                            <?php if (!empty($op_escenarios)): ?>
                            <!-- Autocompleta el campo de texto de abajo con el mismo texto de la caja verde
                                 del escenario elegido en Resumen IA -- el campo sigue siendo 100% manual,
                                 esto solo evita tener que volver a escribirlo a mano (pedido explícito,
                                 2026-09-07: ya no se auto-conecta sola al guardar el Resumen, ver
                                 calcularEscenariosFecha()). -->
                            <select id="op_escenario_select" class="no-print" onchange="if(this.value) document.getElementById('op_fecha_entrega').value = this.value;"
                                    style="width:100%;margin-bottom:6px;border:1.5px solid #d5e0ea;border-radius:8px;padding:7px 10px;font-family:inherit;font-size:.85rem;">
                                <option value="">— Elegir fecha calculada en Resumen IA —</option>
                                <?php foreach ($op_escenarios as $esc): ?>
                                <option value="<?= htmlspecialchars($esc) ?>"><?= htmlspecialchars($esc) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <?php
                            // Si ya hay una fecha ISO guardada (orden existente) y no se ha elegido ningún
                            // escenario todavía, se muestra formateada como texto legible plano.
                            $existing_iso = $orden_edit['fecha_compromiso'] ?? '';
                            $field_display = '';
                            if ($existing_iso) {
                                $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
                                $dt_ex = DateTime::createFromFormat('Y-m-d', $existing_iso);
                                $field_display = $dt_ex ? $dt_ex->format('d') . '-' . $meses_es[(int)$dt_ex->format('n')] . '-' . $dt_ex->format('Y') : $existing_iso;
                            }
                            ?>
                            <input type="text" id="op_fecha_entrega" name="op_fecha_entrega"
                                   value="<?= htmlspecialchars($field_display) ?>"
                                   placeholder="Ej: 16 Semanas desde aprobación de ingeniería (05-Enero-2027)">
                        </div>
                    </div>

                    <!-- ── TABLA DE PRODUCTOS / SERVICIOS ── -->
                    <div class="op-section-title">Productos y/o Servicios</div>
                    <table class="op-table" id="op-tabla-productos">
                        <thead>
                            <tr>
                                <th class="op-td-num">Item</th>
                                <th class="op-td-tag">ID / TAG</th>
                                <th class="op-td-qty">Cantidad</th>
                                <th>Producto y/o Servicio &nbsp;(Descripción)</th>
                                <th class="op-td-del no-print"></th>
                            </tr>
                        </thead>
                        <tbody id="op-tabla-body">
                            <?php
                            $edit_items = $orden_edit['items_arr'] ?? [];
                            $filas_render = max(5, count($edit_items));
                            for ($i = 0; $i < $filas_render; $i++):
                                $ei = $edit_items[$i] ?? [];
                                $num = $i + 1;
                            ?>
                            <tr>
                                <td class="op-td-num"><input type="text" value="<?= $num ?>" readonly
                                    style="color:#666;font-size:0.7rem;text-align:center;"></td>
                                <td class="op-td-tag"><input type="text" name="op_tag[]" placeholder=""
                                    value="<?= htmlspecialchars($ei['tag'] ?? '') ?>"></td>
                                <td class="op-td-qty"><input type="text" name="op_qty[]" placeholder=""
                                    value="<?= htmlspecialchars($ei['qty'] ?? '') ?>"></td>
                                <td class="op-td-desc"><textarea name="op_desc[]" rows="1" class="op-auto" oninput="autoResize(this)"
                                    placeholder=""><?= htmlspecialchars($ei['desc'] ?? '') ?></textarea></td>
                                <td class="op-td-del no-print">
                                    <button type="button" class="btn-del-row" onclick="eliminarFila(this)" title="Eliminar fila">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                    <div class="no-print">
                        <button type="button" class="btn-add-row" onclick="agregarFila()">
                            <i class="fas fa-plus"></i> Agregar fila
                        </button>
                    </div>

                    <!-- ── CHECKLISTS ── -->
                    <div class="op-section-title">Recursos y Control de Proceso</div>
                    <div class="op-cl-wrapper">

                        <!-- Col 1: Maquinaria / Equipo -->
                        <div class="op-cl-col">
                            <div class="op-cl-col-title">Maquinaria / Equipo</div>
                            <?php
                            $maq = ['Equipo de Izaje','Medición lineal','Medición de presión','Medición eléctrica'];
                            $cl_maq_checked = $orden_edit['checklist_arr']['maquinaria'] ?? [];
                            foreach ($maq as $item):
                            ?>
                            <label class="op-cl-item">
                                <input type="checkbox" name="cl_maq[]" value="<?= htmlspecialchars($item) ?>"
                                    <?= in_array($item, $cl_maq_checked) ? 'checked' : '' ?>>
                                <label><?= htmlspecialchars($item) ?></label>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Col 2: Control de proceso -->
                        <div class="op-cl-col">
                            <div class="op-cl-col-title">Control de Proceso</div>
                            <?php
                            $ctrl = ['Plan general','Plan de calidad','Protocolo de pruebas (FAT/OSAT)','Hoja viajera','Reporte de servicio'];
                            $cl_ctrl_checked = $orden_edit['checklist_arr']['control'] ?? [];
                            foreach ($ctrl as $item):
                            ?>
                            <label class="op-cl-item">
                                <input type="checkbox" name="cl_ctrl[]" value="<?= htmlspecialchars($item) ?>"
                                    <?= in_array($item, $cl_ctrl_checked) ? 'checked' : '' ?>>
                                <label><?= htmlspecialchars($item) ?></label>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Col 3: Infraestructura -->
                        <div class="op-cl-col">
                            <div class="op-cl-col-title">Infraestructura</div>
                            <?php
                            $infra = ['Planta principal','Instalaciones del cliente'];
                            $cl_infra_checked = $orden_edit['checklist_arr']['infra'] ?? [];
                            foreach ($infra as $item):
                            ?>
                            <label class="op-cl-item">
                                <input type="checkbox" name="cl_infra[]" value="<?= htmlspecialchars($item) ?>"
                                    <?= in_array($item, $cl_infra_checked) ? 'checked' : '' ?>>
                                <label><?= htmlspecialchars($item) ?></label>
                            </label>
                            <?php endforeach; ?>

                            <div class="op-cl-col-title" style="margin-top:auto;border-top:1px solid var(--op-borde);border-bottom:1px solid var(--op-borde);">Seguimiento</div>
                            <?php
                            $seg = ['Pruebas internas','Reporte / Bitácora','Pruebas ante el cliente'];
                            $cl_seg_checked = $orden_edit['checklist_arr']['seguimiento'] ?? [];
                            foreach ($seg as $item):
                            ?>
                            <label class="op-cl-item">
                                <input type="checkbox" name="cl_seg[]" value="<?= htmlspecialchars($item) ?>"
                                    <?= in_array($item, $cl_seg_checked) ? 'checked' : '' ?>>
                                <label><?= htmlspecialchars($item) ?></label>
                            </label>
                            <?php endforeach; ?>
                        </div>

                    </div><!-- /op-cl-wrapper fila 1 -->

                    <!-- Segunda fila de checklists -->
                    <div class="op-cl-wrapper" style="border-top:1px solid var(--op-borde);">

                        <!-- Col 1: Personal -->
                        <div class="op-cl-col">
                            <div class="op-cl-col-title">Personal</div>
                            <?php
                            $pers = ['Gerente de Producción','Personal de Producción','Gerente de Servicios','Ingeniero de Servicios'];
                            $cl_pers_checked = $orden_edit['checklist_arr']['personal'] ?? [];
                            foreach ($pers as $item):
                            ?>
                            <label class="op-cl-item">
                                <input type="checkbox" name="cl_pers[]" value="<?= htmlspecialchars($item) ?>"
                                    <?= in_array($item, $cl_pers_checked) ? 'checked' : '' ?>>
                                <label><?= htmlspecialchars($item) ?></label>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Col 2: Liberación -->
                        <div class="op-cl-col">
                            <div class="op-cl-col-title">Liberación</div>
                            <?php
                            $lib = ['Lista de embarque','Reporte / Pruebas OSAT'];
                            $cl_lib_checked = $orden_edit['checklist_arr']['liberacion'] ?? [];
                            foreach ($lib as $item):
                            ?>
                            <label class="op-cl-item">
                                <input type="checkbox" name="cl_lib[]" value="<?= htmlspecialchars($item) ?>"
                                    <?= in_array($item, $cl_lib_checked) ? 'checked' : '' ?>>
                                <label><?= htmlspecialchars($item) ?></label>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Col 3: vacía / espacio -->
                        <div class="op-cl-col"></div>

                    </div><!-- /op-cl-wrapper fila 2 -->

                    <!-- ── OBSERVACIONES ── -->
                    <div class="op-obs">
                        <label>Observaciones / Notas adicionales</label>
                        <textarea id="op_obs" name="op_obs" rows="3" class="op-auto"
                                  oninput="autoResize(this)"
                                  placeholder="Instrucciones especiales, restricciones, materiales especiales..."><?= htmlspecialchars($orden_edit['observaciones'] ?? '') ?></textarea>
                    </div>

                    <!-- ── FOOTER DEL DOCUMENTO ── -->
                    <table class="op-footer-table">
                        <tr>
                            <td style="width:25%">DOC-PRY-FO-02B</td>
                            <td style="width:25%">Revisión No:&nbsp;<span class="footer-edit" contenteditable="true" title="Editable antes de imprimir">6</span></td>
                            <td style="width:25%">Fecha:&nbsp;<span class="footer-edit" contenteditable="true" title="Editable antes de imprimir">09/Ene/25</span></td>
                            <td style="width:25%">Página:&nbsp;<span class="footer-edit" contenteditable="true" title="Editable antes de imprimir">1</span></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="footer-nota">
                                Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización.
                            </td>
                        </tr>
                    </table>

                </div><!-- /.op-doc -->
            </div><!-- /.op-screen-wrap -->
            </form><!-- /#formOrdenProduccion -->
                </div><!-- /.área principal -->
            </div><!-- /.layout flex -->
        </div><!-- /.section-content -->

        <script>
        (function() {
            let filaContador = Math.max(5, document.querySelectorAll('#op-tabla-body tr').length);

            window.agregarFila = function() {
                filaContador++;
                const tbody = document.getElementById('op-tabla-body');
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="op-td-num"><input type="text" value="${filaContador}" readonly style="color:#666;font-size:0.7rem;text-align:center;"></td>
                    <td class="op-td-tag"><input type="text" name="op_tag[]" placeholder=""></td>
                    <td class="op-td-qty"><input type="text" name="op_qty[]" placeholder=""></td>
                    <td class="op-td-desc"><textarea name="op_desc[]" rows="1" class="op-auto" oninput="autoResize(this)" placeholder=""></textarea></td>
                    <td class="op-td-del no-print">
                        <button type="button" class="btn-del-row" onclick="eliminarFila(this)" title="Eliminar fila">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>`;
                tbody.appendChild(tr);
                renumerarFilas();
            };

            window.eliminarFila = function(btn) {
                const tr = btn.closest('tr');
                if (document.querySelectorAll('#op-tabla-body tr').length <= 1) return;
                tr.remove();
                renumerarFilas();
            };

            function renumerarFilas() {
                document.querySelectorAll('#op-tabla-body tr').forEach(function(tr, i) {
                    const inp = tr.querySelector('td:first-child input');
                    if (inp) inp.value = i + 1;
                });
                filaContador = document.querySelectorAll('#op-tabla-body tr').length;
            }

            window.limpiarOrden = function() {
                if (!confirm('¿Limpiar todos los datos del formulario?')) return;
                document.getElementById('op_fecha').value = new Date().toISOString().split('T')[0];
                ['op_num_proyecto','op_oc','op_cliente','op_descripcion','op_fecha_entrega','op_obs']
                    .forEach(function(id) {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                document.querySelectorAll('#op-tabla-body input').forEach(function(i) {
                    if (!i.readOnly) i.value = '';
                });
                document.querySelectorAll('.op-doc input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = false;
                });
            };

            window.cerrarModal = function(id) {
                var el = document.getElementById(id);
                if (el) el.classList.remove('active');
            };
            // Cerrar modal al hacer clic fuera
            document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) overlay.classList.remove('active');
                });
            });

            // Auto-resize para todos los textareas expandibles
            window.autoResize = function(el) {
                el.style.height = 'auto';
                el.style.height = (el.scrollHeight) + 'px';
            };

            // Inicializar auto-resize en los campos ya cargados
            document.querySelectorAll('textarea.op-auto').forEach(function(ta) {
                autoResize(ta);
            });

            window.guardarBorrador = function() {
                document.getElementById('ord_estado_hidden').value = 'borrador';
                document.getElementById('formOrdenProduccion').submit();
            };

            window.difundirOrden = function() {
                document.getElementById('modalDifundir').classList.add('active');
            };

            window.confirmarDifundir = function() {
                cerrarModal('modalDifundir');
                document.getElementById('ord_estado_hidden').value = 'activa';
                document.getElementById('formOrdenProduccion').submit();
            };
        })();
        </script>
        <?php endif; ?>

        <?php if ($seccion === 'fat'):
            $fats_proy = [];
            $stmt_fp = $db->query("SELECT pf.*, p.nombre as proyecto_nombre,
                ot.titulo as ot_titulo, bl.pieza_nombre as ot_pieza,
                up.nombre_completo as firma_produccion_nombre,
                fp.archivo_subido_ruta as fp_archivo_ruta, fp.archivo_subido_nombre as fp_archivo_nombre,
                fp.orden_produccion_id, fp.revision_proyectos_estado, fp.revision_proyectos_por,
                fp.revision_proyectos_fecha, fp.revision_proyectos_motivo
                FROM pruebas_fat pf
                LEFT JOIN proyectos p ON p.id = pf.proyecto_id
                LEFT JOIN ordenes_trabajo ot ON ot.id = pf.orden_trabajo_id
                LEFT JOIN boom_listas bl ON bl.id = ot.boom_lista_id
                LEFT JOIN usuarios up ON up.id = pf.firma_produccion_por
                -- Enlazado por Orden de Producción del proyecto, NO por pruebas_fat_id (ver
                -- produccion.php para el detalle del bug real que esto corrige, 2026-09-20).
                LEFT JOIN fat_protocolos fp ON fp.orden_produccion_id = (
                    SELECT op.id FROM ordenes_produccion op WHERE op.proyecto_id = pf.proyecto_id ORDER BY op.id DESC LIMIT 1
                ) AND fp.revision_estado = 'aprobado'
                -- pf.estado es la cadena de firma digital vieja (Ingeniería->Producción->Proyectos->
                -- Calidad), que ya no avanza porque esas firmas se quitaron de la UI el 2026-08-28
                -- (ahora son en físico). Sin el OR, un Protocolo FAT ya firmado por Calidad se
                -- queda atorado en pendiente_proyectos y nunca llega aquí.
                WHERE pf.estado IN ('pendiente_proyectos','completado') OR fp.revision_estado = 'aprobado'
                ORDER BY FIELD(pf.estado,'pendiente_proyectos','completado'), pf.fecha_creacion DESC");
            $fats_proy = $stmt_fp->fetchAll();
        ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-clipboard-check"></i> Pruebas FAT — Firma Proyectos</h2>
                    <a href="proyecto.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                </div>
            </div>
            <?php if (empty($fats_proy)): ?>
            <div style="text-align:center;padding:60px 20px;color:#aaa;">
                <i class="fas fa-clipboard-check" style="font-size:3rem;display:block;margin-bottom:12px;color:#ccc;"></i>
                <p>No hay pruebas FAT pendientes de firma de Proyectos.</p>
            </div>
            <?php else: foreach ($fats_proy as $fat):
                $checklist = json_decode($fat['checklist'] ?? '[]', true) ?? [];
                $ok_ch     = count(array_filter($checklist, fn($i) => !empty($i['resultado'])));
                // pf.estado (cadena de firma digital vieja) y firma_proyectos_ruta ya no avanzan
                // -- dejaron de ser el criterio real desde que las firmas son en físico (2026-08-30).
                // Lo que sí es cierto para una fila visible aquí es si ya tiene su Protocolo FAT
                // aprobado (fp_archivo_ruta).
                $es_turno       = ($fat['estado'] === 'pendiente_proyectos');
                $fat_disponible = !empty($fat['fp_archivo_ruta']);
                // Botón "PDF": solo si el Protocolo FAT subido es efectivamente un PDF -- ya no
                // exporta el checklist de Etapa 5 (código muerto quitado, api/vista_pdf_fat.php).
                $esPdfSubido    = $fat_disponible && strtolower(pathinfo($fat['fp_archivo_ruta'], PATHINFO_EXTENSION)) === 'pdf';
            ?>
            <!-- Card FAT 100% inline para no depender de clases externas -->
            <div style="background:#fafcff;border:1.5px solid #e0e8f0;border-radius:12px;margin-bottom:16px;overflow:hidden;border-left:4px solid <?= $fat_disponible?'#2e7d32':($es_turno?'#ff9800':'#9e9e9e') ?>;">
                <div style="padding:16px 20px;display:flex;align-items:flex-start;gap:12px;">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                            <span style="font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:700;
                                background:<?= $fat_disponible?'#e8f5e9':($es_turno?'#fff3e0':'#f5f5f5') ?>;
                                color:<?= $fat_disponible?'#2e7d32':($es_turno?'#e65100':'#757575') ?>;">
                                <?= $fat_disponible ? 'Protocolo FAT disponible' : ($es_turno ? 'Pendiente firma Proyectos' : 'En espera') ?>
                            </span>
                            <strong style="font-size:.95rem;color:#003d5c;"><?= htmlspecialchars($fat['folio']) ?></strong>
                            <span style="color:#666;">— <?= htmlspecialchars($fat['titulo']) ?></span>
                        </div>
                        <div style="font-size:.77rem;color:#666;display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
                            <?php if ($fat['proyecto_nombre']): ?><span><i class="fas fa-folder" style="color:#1B2D4E;"></i> <?= htmlspecialchars($fat['proyecto_nombre']) ?></span><?php endif; ?>
                            <?php if ($fat['ot_titulo']): ?><span><i class="fas fa-tasks" style="color:#1B2D4E;"></i> <?= htmlspecialchars($fat['ot_titulo']) ?><?= $fat['ot_pieza'] ? ' — '.$fat['ot_pieza'] : '' ?></span><?php endif; ?>
                            <?php if (count($checklist)>0): ?><span><i class="fas fa-list-check" style="color:#1B2D4E;"></i> <?= $ok_ch ?>/<?= count($checklist) ?> ítems</span><?php endif; ?>
                        </div>
                        <span style="font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:700;background:#e8f5e9;color:#2e7d32;">
                            <i class="fas fa-check"></i> Producción firmó<?php if ($fat['firma_produccion_nombre']): ?> — <?= htmlspecialchars($fat['firma_produccion_nombre']) ?><?php endif; ?>
                        </span>
                        <?php if ($esPdfSubido): ?>
                        <button type="button" onclick="descargarPdfFat(<?= htmlspecialchars(json_encode($fat['fp_archivo_ruta']), ENT_QUOTES) ?>)" style="background:#666;color:#fff;border:none;border-radius:8px;padding:5px 12px;font-size:.72rem;font-weight:700;cursor:pointer;margin-left:6px;"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($fat['fp_archivo_ruta']):
                    $extFp = strtolower(pathinfo($fat['fp_archivo_ruta'], PATHINFO_EXTENSION));
                    $esVisualFp = in_array($extFp, ['pdf', 'png', 'jpg', 'jpeg']);
                ?>
                <!-- Protocolo FAT firmado en papel -- Proyectos es solo lectura (2026-09-17,
                     pedido explícito del usuario: ya no aprueba/rechaza, Calidad es quien sube
                     el documento firmado). -->
                <div style="padding:14px 20px;border-top:1px solid #e8ecf0;">
                    <div style="font-size:.78rem;font-weight:700;color:#003d5c;margin-bottom:8px;"><i class="fas fa-file-shield"></i> Protocolo FAT (firmado en papel)</div>
                    <?php if ($extFp === 'pdf'): ?>
                    <iframe src="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" style="width:100%;height:85vh;border:1px solid #e0e8f0;border-radius:8px;margin-bottom:8px;"></iframe>
                    <?php elseif (in_array($extFp, ['png','jpg','jpeg'])): ?>
                    <img src="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" style="max-width:100%;max-height:85vh;display:block;border:1px solid #e0e8f0;border-radius:8px;margin-bottom:8px;">
                    <?php else: ?>
                    <p style="font-size:.8rem;color:#888;"><i class="fas fa-file-word"></i> Documento Word — vista previa no disponible todavía.</p>
                    <?php endif; ?>
                    <a href="uploads/<?= htmlspecialchars($fat['fp_archivo_ruta']) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:10px;"><i class="fas fa-<?= $esVisualFp ? 'up-right-from-square' : 'download' ?>"></i> <?= htmlspecialchars($fat['fp_archivo_nombre'] ?? 'Ver archivo') ?></a>
                    <div>
                        <!-- El JOIN de arriba (fp.revision_estado = 'aprobado') ya garantiza que si
                             llegamos aquí es porque Calidad subió el documento firmado. -->
                        <span style="font-size:.75rem;color:#2e7d32;font-weight:600;"><i class="fas fa-check-circle"></i> Documento firmado subido por Calidad</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.app-container -->

    <div class="dashboard-footer">
        <div class="footer-left">
            <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
            <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
        </div>
        <div class="footer-right"><img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO" onerror="this.style.display='none'"></div>
    </div>

    <!-- Modal editar fase -->
    <div id="modalEditarFase" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:3000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:14px;padding:24px;width:90%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <div style="font-size:1rem;font-weight:700;color:#003d5c;margin-bottom:16px;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-project-diagram"></i> Editar Fase
            </div>
            <form method="POST" id="formEditarFase">
                <input type="hidden" name="accion" value="actualizar_fase">
                <input type="hidden" name="fase_id" id="ef_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="font-size:.75rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">% Avance</label>
                        <input type="number" name="pct_avance" id="ef_pct" min="0" max="100" step="5"
                               style="width:100%;padding:7px 10px;border:1.5px solid #ddd;border-radius:7px;font-family:inherit;font-size:.85rem;">
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Estado</label>
                        <select name="estado_fase" id="ef_estado"
                                style="width:100%;padding:7px 10px;border:1.5px solid #ddd;border-radius:7px;font-family:inherit;font-size:.85rem;">
                            <option value="pendiente">Pendiente</option>
                            <option value="en_proceso">En proceso</option>
                            <option value="completado">Completado</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Fecha inicio</label>
                        <input type="date" name="fecha_inicio" id="ef_fi"
                               style="width:100%;padding:7px 10px;border:1.5px solid #ddd;border-radius:7px;font-family:inherit;font-size:.85rem;">
                    </div>
                    <div>
                        <label style="font-size:.75rem;font-weight:700;color:#555;display:block;margin-bottom:3px;">Fecha fin planeada</label>
                        <input type="date" name="fecha_fin_plan" id="ef_ff"
                               style="width:100%;padding:7px 10px;border:1.5px solid #ddd;border-radius:7px;font-family:inherit;font-size:.85rem;">
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;">
                    <button type="button" onclick="document.getElementById('modalEditarFase').style.display='none'"
                            style="padding:7px 16px;border-radius:8px;border:1.5px solid #005686;background:transparent;color:#005686;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;">
                        Cancelar
                    </button>
                    <button type="submit"
                            style="padding:7px 16px;border-radius:8px;border:none;background:#005686;color:#fff;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;">
                        <i class="fas fa-check"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function abrirRechazarOC(ocId, folio) {
        document.getElementById('oc_rechazo_id').value = ocId;
        document.getElementById('oc_rechazo_folio').textContent = folio;
        document.getElementById('modalRechazarOC').style.display = 'flex';
    }

    function toggleCard(id) {
        document.getElementById('card-' + id).classList.toggle('open');
    }
    // Auto-submit búsqueda con Enter
    document.querySelector('input[name="busqueda"]')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') document.getElementById('formFiltros').submit();
    });

    function openModalOT() {
        const m = document.getElementById('modalNuevaOT');
        if (m) m.style.display = 'flex';
    }
    function onOTPlanoSelect(sel) {
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('ot_boom_id').value     = opt.value || '';
        document.getElementById('ot_proyecto_id').value = opt.dataset.proy || '';
        document.querySelector('#formNuevaOT [name="boom_id"]').value = opt.value || '';
        document.querySelector('#formNuevaOT [name="proyecto_id"]').value = opt.dataset.proy || '';
    }
    function toggleOT(id) {
        const body = document.getElementById('body-ot-' + id);
        const icon = document.getElementById('icon-ot-' + id);
        if (!body) return;
        const open = body.style.display !== 'none';
        body.style.display = open ? 'none' : 'block';
        if (icon) icon.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    }
    function openHoras(actId, nombre) {
        document.getElementById('horas_act_id').value      = actId;
        document.getElementById('horas_act_nombre').textContent = 'Actividad: ' + nombre;
        document.getElementById('modalHoras').style.display = 'flex';
    }
    document.querySelectorAll('#modalNuevaOT,#modalHoras').forEach(el => {
        el.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    });

    function abrirEditarFase(id, pct, estado, fi, ff) {
        document.getElementById('ef_id').value     = id;
        document.getElementById('ef_pct').value    = pct;
        document.getElementById('ef_estado').value = estado;
        document.getElementById('ef_fi').value     = fi;
        document.getElementById('ef_ff').value     = ff;
        document.getElementById('modalEditarFase').style.display = 'flex';
    }
    document.getElementById('modalEditarFase')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    // JS AUTOMATIZACIÓN: 100% automático al completar la fase
    document.getElementById('ef_estado')?.addEventListener('change', function() {
        const pctInput = document.getElementById('ef_pct');
        if (this.value === 'completado') {
            pctInput.value = 100;
        } else if (this.value === 'pendiente') {
            pctInput.value = 0;
        }
    });
    </script>

<script>
// La firma digital de Pruebas FAT se quitó (2026-08-30, mismo criterio que produccion.php --
// las firmas ya son solo en físico, ver [[project_fat_protocolo]]). api/fat_firma.php y las
// columnas firma_*_ruta de pruebas_fat se dejan intactos por si algo más los usa.
function descargarPdfFat(rutaArchivo){var frame=document.getElementById('fatIframeImprimir');if(!frame){frame=document.createElement('iframe');frame.id='fatIframeImprimir';frame.style.display='none';document.body.appendChild(frame);}frame.onload=function(){frame.contentWindow.focus();frame.contentWindow.print();};frame.src='uploads/'+rutaArchivo;}

// ── Campana de notificaciones de Proyectos (2026-09-21) -- nunca existió en esta página
// (bug real reportado), mismo patrón/clases que calidad.php/produccion.php. Filtra solo los
// tipos que ya se mandan con notificarRoles(['proyectos'], ...) en el resto de la app.
const PROYECTOS_NOTIF_TIPOS = ['proyecto_cerrado', 'requisicion_actualizada', 'fat_protocolo_firmado', 'oc_pendiente_confirmar'];
let _notifHeaderAbierta = false;

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function cargarNotificaciones() {
    fetch('includes/ajax_chat.php?action=obtener_notificaciones')
    .then(response => response.json())
    .then(data => {
        if (data.success && data.notificaciones) {
            const lista = data.notificaciones.filter(n => PROYECTOS_NOTIF_TIPOS.includes(n.tipo));
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
        'proyecto_cerrado':        '<i class="fas fa-lock"            style="color:#2e7d32"></i>',
        'requisicion_actualizada': '<i class="fas fa-file-alt"        style="color:#1565c0"></i>',
        'fat_protocolo_firmado':   '<i class="fas fa-file-shield"     style="color:#2e7d32"></i>',
        'oc_pendiente_confirmar':  '<i class="fas fa-calendar-check"  style="color:#f57c00"></i>',
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

async function marcarTodasLeidasProyectos() {
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const ids = res.notificaciones.filter(n => PROYECTOS_NOTIF_TIPOS.includes(n.tipo) && n.leido == 0).map(n => n.id);
    await Promise.all(ids.map(id => fetch('includes/ajax_chat.php?action=marcar_notificacion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })));
    cargarNotificaciones();
    actualizarBadgeNotifHeader();
}

async function borrarNotificacionesProyectos() {
    if (!confirm('¿Borrar todas las notificaciones de Proyectos? Esta acción no se puede deshacer.')) return;
    const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
    if (!res.success) return;
    const ids = res.notificaciones.filter(n => PROYECTOS_NOTIF_TIPOS.includes(n.tipo)).map(n => n.id);
    await Promise.all(ids.map(id => fetch('includes/ajax_chat.php?action=borrar_notificacion', {
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
        const noLeidas = (data.notificaciones || []).filter(n => n.leido == 0 && PROYECTOS_NOTIF_TIPOS.includes(n.tipo)).length;
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

<!-- Modal: Ver texto extraído de documento de referencia -->
<div id="modalVerTexto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:760px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;">
        <div style="background:#023760;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div>
                <span id="mvt_nombre" style="color:#fff;font-size:.9rem;font-weight:700;"></span>
                <span id="mvt_meta" style="color:#93c5fd;font-size:.78rem;margin-left:10px;"></span>
            </div>
            <button onclick="document.getElementById('modalVerTexto').style.display='none'" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;">×</button>
        </div>
        <div style="padding:16px 20px;overflow-y:auto;flex:1;">
            <pre id="mvt_texto" style="white-space:pre-wrap;word-break:break-word;font-family:'Courier New',monospace;font-size:.78rem;color:#1e293b;margin:0;line-height:1.6;"></pre>
            <p id="mvt_truncado" style="display:none;color:#94a3b8;font-size:.78rem;margin-top:12px;border-top:1px dashed #e2e8f0;padding-top:10px;">
                <i class="fas fa-info-circle"></i> Mostrando primeros 3,000 caracteres del texto extraído.
            </p>
        </div>
    </div>
</div>

<!-- Modal: Rechazar OC desde Proyectos -->
<div id="modalRechazarOC" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;">
        <div style="background:linear-gradient(135deg,#b71c1c,#c62828);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;">
            <span style="color:#fff;font-size:.95rem;font-weight:700;"><i class="fas fa-times-circle"></i> Rechazar OC — <span id="oc_rechazo_folio"></span></span>
            <button onclick="document.getElementById('modalRechazarOC').style.display='none'" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;">×</button>
        </div>
        <div style="padding:20px;">
            <form method="POST" action="?seccion=proyectos" id="formRechazarOC">
                <input type="hidden" name="accion" value="rechazar_oc_proyectos">
                <input type="hidden" name="oc_id" id="oc_rechazo_id" value="">
                <label style="font-size:.83rem;font-weight:600;color:#555;display:block;margin-bottom:6px;">Motivo del rechazo *</label>
                <textarea name="nota_rechazo" required rows="3" placeholder="Ej: La fecha de entrega no cumple con el plazo del cliente..." style="width:100%;border:1.5px solid #d5e0ea;border-radius:8px;padding:8px;font-family:inherit;font-size:.83rem;resize:vertical;box-sizing:border-box;"></textarea>
                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;border-top:1px solid #eee;padding-top:14px;">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('modalRechazarOC').style.display='none'">Cancelar</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Confirmar rechazo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($seccion === 'resumen_proyecto'):
    $pid_res = intval($_GET['proyecto_id'] ?? 0);
    $resumen = null;
    if ($pid_res) {
        $stmt_res = $db->prepare("SELECT * FROM proyecto_resumen WHERE proyecto_id=? LIMIT 1");
        $stmt_res->execute([$pid_res]);
        $resumen = $stmt_res->fetch();
    }
    $pdf_po_url = (!empty($resumen['archivo_po'])) ? SITE_URL . '/uploads/' . htmlspecialchars($resumen['archivo_po']) : '';
    $pdf_ov_url = (!empty($resumen['archivo_ov'])) ? SITE_URL . '/uploads/' . htmlspecialchars($resumen['archivo_ov']) : '';
    $tiene_pdf  = $pdf_po_url || $pdf_ov_url;
    $pdf_activo_url = $pdf_po_url ?: $pdf_ov_url;

    // Datos del resumen para pre-llenar el formulario
    $r_items  = (!empty($resumen['items_json']))           ? json_decode($resumen['items_json'],true)           : [];
    $r_normas = (!empty($resumen['normas_json']))           ? json_decode($resumen['normas_json'],true)           : [];
    $r_plan   = (!empty($resumen['plan_inspeccion_json'])) ? json_decode($resumen['plan_inspeccion_json'],true) : [];
?>
<div class="resumen-overlay">

    <!-- Encabezado -->
    <div class="sticky-container" style="background:#fff;">
        <div class="header-row">
            <h2><i class="fas fa-file-contract" style="color:#7b1fa2;"></i> Resumen de Proyecto con IA</h2>
            <a href="?seccion=" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <?php if (isset($_GET['guardado'])): ?>
    <div class="alert-guardado"><i class="fas fa-check-circle"></i> Resumen guardado correctamente.</div>
    <?php endif; ?>

    <?php if (!$tiene_pdf): ?>
    <!-- ══ ESTADO 1: Seleccionar proyecto y subir PDFs ══ -->
    <div class="main-center" style="height:auto;padding-top:20px;">
    <div class="resumen-upload-card">
    <?php if (!empty($_SESSION['resumen_error'])): ?>
    <div class="alert-rojo" style="margin-bottom:16px;">
        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['resumen_error']) ?>
    </div>
    <?php unset($_SESSION['resumen_error']); endif; ?>
        <h2><i class="fas fa-cloud-upload-alt" style="color:#005686;"></i> Subir documentos del proyecto</h2>
        <p>Sube el PDF de la Orden de Compra del cliente (PO) y opcionalmente la Orden de Venta interna (OV/SPI). La IA los analizará para generar el resumen automáticamente.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="subir_pdf_resumen">
            <div class="upload-field">
                <label><i class="fas fa-folder-open"></i> Número / Nombre del Proyecto</label>
                <input type="text" name="nombre_proyecto" id="inputNombreProy"
                       placeholder="Ej: T-496" required
                       style="width:100%;border:1.5px solid #d5e0ea;border-radius:8px;padding:9px 12px;font-family:'Montserrat',sans-serif;font-size:.88rem;"
                       list="proyectos-list">
                <datalist id="proyectos-list">
                    <?php foreach ($proyectos as $proy): ?>
                    <option value="<?= htmlspecialchars($proy['nombre']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <div class="file-hint">Escribe el número de proyecto. Si ya existe en el sistema se vinculará automáticamente; si no, se creará.</div>
            </div>
            <div class="upload-field">
                <label><i class="fas fa-file-pdf" style="color:#d32f2f;"></i> PO del cliente <span style="color:#d32f2f;">*</span></label>
                <input type="file" name="pdf_po" accept=".pdf" required>
                <div class="file-hint">PDF · máx 20 MB · Orden de Compra del cliente</div>
            </div>
            <div class="upload-field">
                <label><i class="fas fa-file-pdf" style="color:#e65100;"></i> Orden de Venta / SPI <span style="color:#999;font-weight:400;">(opcional)</span></label>
                <input type="file" name="pdf_ov" accept=".pdf">
                <div class="file-hint">PDF · máx 20 MB · Especificación técnica interna</div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
                <i class="fas fa-upload"></i> Subir documentos y continuar
            </button>
        </form>
    </div>
    </div>

    <?php else: ?>
    <!-- ══ ESTADO 2: Split view — formulario + visor PDF ══ -->
    <div class="proy-split-wrap">

        <!-- ── PANEL IZQUIERDO: Formulario ── -->
        <div class="proy-split-panel panel-form" id="panelFormRes">
            <div class="psp-header">
                <h3><i class="fas fa-file-alt"></i> Resumen del Proyecto</h3>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="ia-spinner" id="iaSpinner"><i class="fas fa-sync-alt"></i> …</span>
                    <?php if (!empty($resumen['analizado_ia'])): ?>
                    <span class="badge-ia"><i class="fas fa-check"></i> Analizado con IA</span>
                    <?php endif; ?>
                    <button class="btn-max" onclick="toggleSplitMax('panelFormRes','panelPdfRes')" title="Maximizar">
                        <i class="fas fa-expand-alt"></i>
                    </button>
                </div>
            </div>

            <!-- Pestañas -->
            <div class="psp-tabs">
                <div class="psp-tab active" id="tabResBtn" onclick="mostrarTabRes('resumen')">
                    <i class="fas fa-clipboard-list"></i> Resumen
                </div>
                <div class="psp-tab" id="tabPlanBtn" onclick="mostrarTabRes('plan')">
                    <i class="fas fa-tasks"></i> Plan de Inspección
                </div>
            </div>

            <div class="psp-body">
            <form id="formGuardarResumen" method="POST">
                <input type="hidden" name="accion" value="guardar_resumen_proyecto">
                <input type="hidden" name="proyecto_id" value="<?= $pid_res ?>">
                <input type="hidden" name="items_json" id="items_json_hidden" value="<?= htmlspecialchars($resumen['items_json'] ?? '[]') ?>">
                <input type="hidden" name="normas_json" id="normas_json_hidden" value="<?= htmlspecialchars($resumen['normas_json'] ?? '[]') ?>">
                <input type="hidden" name="plan_inspeccion_json" id="plan_json_hidden" value="<?= htmlspecialchars($resumen['plan_inspeccion_json'] ?? '[]') ?>">

                <!-- ── TAB: RESUMEN ── -->
                <div id="tabResumen" class="form-resumen">
                    <div style="background:#f4f7fb;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-size:.8rem;color:#555;flex:1;">Analiza el PDF de la <strong>PO del cliente</strong> para auto-llenar los datos del proyecto.</span>
                        <button class="btn-ia" id="btnAnalizarResumen" onclick="verificarOCRYAnalizar('resumen')">
                            <i class="fas fa-robot"></i> Analizar PO
                        </button>
                    </div>
                    <div class="fg">
                        <label>Número de PO / Contrato</label>
                        <input type="text" name="numero_po" id="f_numero_po" value="<?= htmlspecialchars($resumen['numero_po'] ?? '') ?>" placeholder="Ej: 202690660">
                    </div>
                    <div class="fg">
                        <label>Cliente</label>
                        <input type="text" name="cliente" id="f_cliente" value="<?= htmlspecialchars($resumen['cliente'] ?? '') ?>" placeholder="Nombre de la empresa cliente">
                    </div>
                    <div class="fg">
                        <label>Descripción del suministro</label>
                        <textarea name="descripcion" id="f_descripcion" rows="3" placeholder="Descripción principal del equipo o servicio"><?= htmlspecialchars($resumen['descripcion'] ?? '') ?></textarea>
                    </div>
                    <!-- ── Escenarios de fecha ── -->
                    <div class="fg" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:4px;">
                        <div style="font-size:.75rem;font-weight:700;color:#023760;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">
                            <i class="fas fa-calendar-alt" style="margin-right:5px;"></i> Escenarios de fecha de entrega
                        </div>

                        <!-- Escenario 1: Fecha PO -->
                        <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e2e8f0;">
                            <div style="width:22px;height:22px;background:#0284c7;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">
                                <span style="color:#fff;font-size:.65rem;font-weight:700;">1</span>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:.7rem;font-weight:700;color:#475569;margin-bottom:6px;">Fecha según PO del cliente</div>
                                <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;margin-bottom:8px;">
                                    <div>
                                        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:3px;">Fecha según la PO</div>
                                        <input type="date" name="fecha_entrega" id="f_fecha_entrega"
                                               value="<?= htmlspecialchars($resumen['fecha_entrega'] ?? '') ?>"
                                               onchange="calcEscenario1()"
                                               style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:.8rem;box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:3px;">Semanas de plazo</div>
                                        <input type="number" name="semanas_plazo_po" id="f_semanas_plazo_po"
                                               value="<?= intval($resumen['semanas_plazo_po'] ?? 16) ?>"
                                               min="1" max="104"
                                               onchange="calcEscenario1()" oninput="calcEscenario1()"
                                               style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:.8rem;box-sizing:border-box;text-align:center;">
                                    </div>
                                </div>
                                <!-- Resultado calculado -->
                                <div id="esc1_display" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;font-size:.8rem;color:#166534;font-weight:600;min-height:34px;display:flex;align-items:center;">
                                    <i class="fas fa-calculator" style="margin-right:6px;font-size:.7rem;opacity:.7;"></i>
                                    <span id="esc1_result">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Escenario 2: Fecha anticipo -->
                        <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e2e8f0;">
                            <div style="width:22px;height:22px;background:#d97706;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">
                                <span style="color:#fff;font-size:.65rem;font-weight:700;">2</span>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:.7rem;font-weight:700;color:#475569;margin-bottom:6px;">Fecha anticipo del cliente</div>
                                <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;margin-bottom:8px;">
                                    <div>
                                        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:3px;">Fecha anticipo</div>
                                        <input type="date" name="fecha_anticipo" id="f_fecha_anticipo"
                                               value="<?= htmlspecialchars($resumen['fecha_anticipo'] ?? '') ?>"
                                               onchange="calcEscenario2()"
                                               style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:.8rem;box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:3px;">Semanas de plazo</div>
                                        <input type="number" name="semanas_plazo_anticipo" id="f_semanas_plazo_anticipo"
                                               value="<?= intval($resumen['semanas_plazo_anticipo'] ?? 16) ?>"
                                               min="1" max="104"
                                               onchange="calcEscenario2()" oninput="calcEscenario2()"
                                               style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:.8rem;box-sizing:border-box;text-align:center;">
                                    </div>
                                </div>
                                <!-- Resultado calculado -->
                                <div id="esc2_display" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;font-size:.8rem;color:#166534;font-weight:600;min-height:34px;display:flex;align-items:center;">
                                    <i class="fas fa-calculator" style="margin-right:6px;font-size:.7rem;opacity:.7;"></i>
                                    <span id="esc2_result">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Escenario 3: Aprobación ingeniería + semanas -->
                        <div style="display:flex;align-items:flex-start;gap:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #e2e8f0;">
                            <div style="width:22px;height:22px;background:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">
                                <span style="color:#fff;font-size:.65rem;font-weight:700;">3</span>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:.7rem;font-weight:700;color:#475569;margin-bottom:6px;">Aprobación ingeniería</div>
                                <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;margin-bottom:8px;">
                                    <div>
                                        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:3px;">Fecha aprobación ingeniería</div>
                                        <input type="date" name="fecha_aprobacion_ing" id="f_fecha_aprobacion_ing"
                                               value="<?= htmlspecialchars($resumen['fecha_aprobacion_ing'] ?? '') ?>"
                                               onchange="calcEscenario3()"
                                               style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:.8rem;box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <div style="font-size:.68rem;color:#94a3b8;margin-bottom:3px;">Semanas de plazo</div>
                                        <input type="number" name="semanas_plazo" id="f_semanas_plazo"
                                               value="<?= intval($resumen['semanas_plazo'] ?? 16) ?>"
                                               min="1" max="104"
                                               onchange="calcEscenario3()" oninput="calcEscenario3()"
                                               style="width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:.8rem;box-sizing:border-box;text-align:center;">
                                    </div>
                                </div>
                                <!-- Resultado calculado -->
                                <div id="esc3_display" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;font-size:.8rem;color:#166534;font-weight:600;min-height:34px;display:flex;align-items:center;">
                                    <i class="fas fa-calculator" style="margin-right:6px;font-size:.7rem;opacity:.7;"></i>
                                    <span id="esc3_result">—</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fg">
                        <label>Condiciones comerciales</label>
                        <textarea name="condiciones" id="f_condiciones" rows="2" placeholder="Condiciones de pago, entrega, garantía..."><?= htmlspecialchars($resumen['condiciones'] ?? '') ?></textarea>
                    </div>
                    <div class="fg">
                        <label>Observaciones / Penalizaciones</label>
                        <textarea name="observaciones" id="f_observaciones" rows="2" placeholder="Notas importantes, penalizaciones, requisitos especiales..."><?= htmlspecialchars($resumen['observaciones'] ?? '') ?></textarea>
                    </div>

                    <!-- Tabla de ítems -->
                    <div class="fg">
                        <label>Ítems del suministro</label>
                        <div style="overflow-x:auto;">
                        <table class="items-res-table" id="tablaItems">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th style="width:70px;">Tag</th>
                                    <th style="width:60px;">Cant.</th>
                                    <th style="width:55px;">Unidad</th>
                                    <th>Descripción</th>
                                    <th style="width:30px;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                            <?php foreach ($r_items as $it): ?>
                            <tr>
                                <td><input type="text" value="<?= htmlspecialchars($it['item'] ?? '') ?>" placeholder="#" style="width:36px;"></td>
                                <td><input type="text" value="<?= htmlspecialchars($it['tag'] ?? '') ?>" placeholder="TAG"></td>
                                <td><input type="number" value="<?= htmlspecialchars($it['cantidad'] ?? '') ?>" step="any" placeholder="0"></td>
                                <td><input type="text" value="<?= htmlspecialchars($it['unidad'] ?? '') ?>" placeholder="PZA"></td>
                                <td><input type="text" value="<?= htmlspecialchars($it['descripcion'] ?? '') ?>" placeholder="Descripción del ítem"></td>
                                <td><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncItemsJSON()"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($r_items)): ?>
                            <tr>
                                <td><input type="text" placeholder="#" style="width:36px;"></td>
                                <td><input type="text" placeholder="TAG"></td>
                                <td><input type="number" step="any" placeholder="0"></td>
                                <td><input type="text" placeholder="PZA"></td>
                                <td><input type="text" placeholder="Descripción del ítem"></td>
                                <td><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncItemsJSON()"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                        <button type="button" class="btn-add-row" onclick="agregarFilaItem()"><i class="fas fa-plus"></i> Agregar ítem</button>
                    </div>

                    <!-- Normas -->
                    <div class="fg">
                        <label>Normas aplicables</label>
                        <div id="normasWrap" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
                        <?php foreach ($r_normas as $nrm): ?>
                            <span class="badge badge-bom" style="cursor:pointer;user-select:none;" onclick="eliminarNorma(this)">
                                <?= htmlspecialchars($nrm['codigo'] ?? '') ?>
                                <?= !empty($nrm['descripcion']) ? '· '.htmlspecialchars($nrm['descripcion']) : '' ?>
                                <i class="fas fa-times" style="margin-left:5px;font-size:.65rem;"></i>
                            </span>
                        <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <input type="text" id="nuevaNormaCode" placeholder="Código (ASME B16.34)" style="flex:0 0 160px;border:1.5px solid #d5e0ea;border-radius:8px;padding:6px 10px;font-size:.82rem;font-family:'Montserrat',sans-serif;">
                            <input type="text" id="nuevaNormaDesc" placeholder="Descripción (opcional)" style="flex:1;border:1.5px solid #d5e0ea;border-radius:8px;padding:6px 10px;font-size:.82rem;font-family:'Montserrat',sans-serif;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="agregarNorma()"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                </div>

                <!-- ── TAB: PLAN DE INSPECCIÓN ── -->
                <div id="tabPlan" style="display:none;" class="form-resumen">
                    <!-- ── Selector de documentos de referencia ── -->
                    <div style="background:#e8f5e9;border:1.5px solid #a5d6a7;border-radius:10px;padding:12px 16px;margin-bottom:14px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;"
                             onclick="toggleDocrefPanel()">
                            <span style="font-weight:700;color:#1b5e20;font-size:.85rem;">
                                <i class="fas fa-book-open"></i> Documentos de referencia
                                <span id="docrefSelCount" style="background:#2e7d32;color:#fff;border-radius:20px;padding:1px 8px;font-size:.72rem;margin-left:6px;">0</span>
                            </span>
                            <i class="fas fa-chevron-down" id="docrefChevron" style="color:#2e7d32;font-size:.8rem;transition:transform .2s;"></i>
                        </div>
                        <div id="docrefPanel" style="display:none;margin-top:12px;">
                            <p style="font-size:.78rem;color:#555;margin-bottom:10px;">
                                Selecciona los documentos cuyo texto se incluir&aacute; como contexto al generar el plan.
                                Los documentos del proyecto est&aacute;n pre-seleccionados.
                            </p>
                            <div id="docrefCheckboxes" style="display:flex;flex-direction:column;gap:6px;">
                                <p style="color:#aaa;font-size:.8rem;">Cargando documentos&hellip;</p>
                            </div>
                        </div>
                    </div>
                    <div style="background:#f3e5f5;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-size:.8rem;color:#555;flex:1;">
                            Cambia el PDF del lado derecho al <strong>documento OV/SPI</strong> y analízalo para generar el plan de inspección automáticamente.
                            <?php if ($pdf_ov_url): ?>
                            <a href="#" onclick="cambiarPDF('<?= htmlspecialchars($pdf_ov_url) ?>');return false;" style="color:#7b1fa2;font-weight:700;">← Ver OV/SPI</a>
                            <?php endif; ?>
                        </span>
                        <button class="btn-ia" id="btnAnalizarPlan" onclick="verificarOCRYAnalizar('plan')">
                            <i class="fas fa-robot"></i> Analizar OV/SPI
                        </button>
                    </div>
                    <div style="overflow-x:auto;">
                    <table class="plan-table" id="tablaPlan">
                        <thead>
                            <tr>
                                <th class="col-item">ITEM</th>
                                <th class="col-req left">REQUISITO</th>
                                <th class="col-ref left">REFERENCIA EN ESPECIFICACIÓN</th>
                                <th class="col-met left">MÉTODO DE INSPECCIÓN / NORMA</th>
                                <th class="col-cri left">CRITERIOS DE CONFORMIDAD</th>
                                <th class="col-cum">CUMPLE</th>
                                <th class="col-com left">COMENTARIOS</th>
                                <th class="col-del"></th>
                            </tr>
                        </thead>
                        <tbody id="planBody">
                        <?php foreach ($r_plan as $pl):
                            $cumple = $pl['cumple'] ?? 'FALSO';
                            $cumClase = $cumple === 'VERDADERO' ? 'cumple-verdadero' : ($cumple === 'N/A' ? 'cumple-na' : 'cumple-falso');
                        ?>
                        <tr>
                            <td class="col-item"><input type="number" value="<?= intval($pl['item'] ?? $pl['no'] ?? 0) ?>" style="width:46px;text-align:center;"></td>
                            <td class="col-req"><textarea><?= htmlspecialchars($pl['requisito'] ?? $pl['actividad'] ?? '') ?></textarea></td>
                            <td class="col-ref"><input type="text" value="<?= htmlspecialchars($pl['referencia'] ?? $pl['norma'] ?? '') ?>"></td>
                            <td class="col-met"><input type="text" value="<?= htmlspecialchars($pl['metodo_norma'] ?? $pl['tipo_prueba'] ?? '') ?>"></td>
                            <td class="col-cri"><textarea><?= htmlspecialchars($pl['criterios'] ?? $pl['criterio'] ?? $pl['resultado_esperado'] ?? '') ?></textarea></td>
                            <td class="col-cum">
                                <select class="<?= $cumClase ?>" onchange="this.className=this.value==='VERDADERO'?'cumple-verdadero':this.value==='N/A'?'cumple-na':'cumple-falso'">
                                    <option <?= $cumple==='FALSO'?'selected':'' ?>>FALSO</option>
                                    <option <?= $cumple==='VERDADERO'?'selected':'' ?>>VERDADERO</option>
                                    <option <?= $cumple==='N/A'?'selected':'' ?>>N/A</option>
                                </select>
                            </td>
                            <td class="col-com"><input type="text" value="<?= htmlspecialchars($pl['comentarios'] ?? '') ?>"></td>
                            <td class="col-del"><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncPlanJSON()"><i class="fas fa-times"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($r_plan)): ?>
                        <tr>
                            <td class="col-item"><input type="number" value="1" style="width:46px;text-align:center;"></td>
                            <td class="col-req"><textarea placeholder="Descripción del requisito a verificar"></textarea></td>
                            <td class="col-ref"><input type="text" placeholder="Cot 00000000636, pág 2, parr 1"></td>
                            <td class="col-met"><input type="text" placeholder="Visual / ASME B16.34"></td>
                            <td class="col-cri"><textarea placeholder="Criterio de conformidad específico"></textarea></td>
                            <td class="col-cum"><select class="cumple-falso" onchange="this.className=this.value==='VERDADERO'?'cumple-verdadero':this.value==='N/A'?'cumple-na':'cumple-falso'"><option>FALSO</option><option>VERDADERO</option><option>N/A</option></select></td>
                            <td class="col-com"><input type="text" placeholder=""></td>
                            <td class="col-del"><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncPlanJSON()"><i class="fas fa-times"></i></button></td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <button type="button" class="btn-add-row" onclick="agregarFilaPlan()"><i class="fas fa-plus"></i> Agregar fila</button>
                </div>

                <!-- Botón guardar (visible siempre) -->
                <div style="margin-top:18px;padding-top:14px;border-top:1px solid #eee;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-success" onclick="syncAll()">
                        <i class="fas fa-save"></i> Guardar resumen
                    </button>
                    <?php if ($pid_res): ?>
                    <a href="?seccion=formato_trabajo&desde_proyecto=<?= $pid_res ?>"
                       class="btn btn-primary" onclick="syncAll()" style="background:linear-gradient(135deg,#003d5c,#005686);">
                        <i class="fas fa-arrow-right"></i> Continuar a Orden de Producción
                    </a>
                    <?php endif; ?>
                    <a href="?seccion=resumen_proyecto" class="btn btn-outline btn-sm">
                        <i class="fas fa-plus"></i> Nuevo proyecto
                    </a>
                    <?php if ($pdf_po_url && $pdf_ov_url): ?>
                    <span style="margin-left:auto;font-size:.78rem;color:#666;">Ver en PDF:</span>
                    <button type="button" class="btn btn-outline btn-sm" onclick="cambiarPDF('<?= htmlspecialchars($pdf_po_url) ?>')">PO</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="cambiarPDF('<?= htmlspecialchars($pdf_ov_url) ?>')">OV/SPI</button>
                    <?php endif; ?>
                </div>
            </form>
            </div>
        </div><!-- /panel-form -->

        <!-- ── PANEL DERECHO: Visor PDF con buscador ── -->
<div class="proy-split-panel panel-pdf" id="panelPdfRes">
    <div class="psp-header">
        <div style="display:flex;align-items:center;gap:8px;">
            <h3><i class="fas fa-file-pdf" style="color:#d32f2f;"></i> Documento de referencia</h3>
            <!-- Indicador de progreso OCR -->
            <span id="ocrProgressBadge" style="display:none;font-size:0.72rem;font-weight:600;padding:2px 8px;border-radius:10px;background:#fff3cd;color:#856404;border:1px solid #ffc107;white-space:nowrap;"></span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?php if ($pdf_po_url && $pdf_ov_url): ?>
            <button class="btn-max" onclick="cambiarPDF('<?= htmlspecialchars($pdf_po_url) ?>')" title="Ver PO">PO</button>
            <button class="btn-max" onclick="cambiarPDF('<?= htmlspecialchars($pdf_ov_url) ?>')" title="Ver OV/SPI">OV</button>
            <?php endif; ?>
            <!-- Buscador -->
            <div style="display:flex;align-items:center;gap:5px;border:1px solid #ccc;border-radius:6px;padding:2px 6px;background:#fff;">
                <input type="text" id="pdfSearchInput" placeholder="Buscar..." style="border:none;padding:4px;width:140px;font-size:0.8rem;">
                <button id="pdfSearchBtn" class="btn-max" title="Buscar"><i class="fas fa-search"></i></button>
                <button id="pdfPrevBtn" class="btn-max" title="Anterior"><i class="fas fa-chevron-up"></i></button>
                <button id="pdfNextBtn" class="btn-max" title="Siguiente"><i class="fas fa-chevron-down"></i></button>
                <span id="searchResultInfo" style="font-size:0.7rem;min-width:50px;color:#555;"></span>
            </div>
            <!-- Zoom -->
            <div style="display:flex;align-items:center;gap:3px;border:1px solid #ccc;border-radius:6px;padding:2px 6px;background:#fff;font-size:0.8rem;">
                <button onclick="zoomPdf(-0.15)" class="btn-max" title="Reducir" style="font-size:1rem;padding:0 4px;">−</button>
                <span id="pdfZoomLabel" style="min-width:38px;text-align:center;color:#333;font-weight:600;">100%</span>
                <button onclick="zoomPdf(+0.15)" class="btn-max" title="Ampliar" style="font-size:1rem;padding:0 4px;">+</button>
                <button onclick="zoomPdf(0)" class="btn-max" title="Restablecer" style="font-size:0.72rem;padding:2px 6px;border-left:1px solid #ddd;margin-left:2px;border-radius:4px;">Restablecer</button>
            </div>
            <button class="btn-max" onclick="toggleSplitMax('panelPdfRes','panelFormRes')" title="Maximizar">
                <i class="fas fa-expand-alt"></i>
            </button>
        </div>
    </div>
    <div class="psp-body no-pad" id="pdfScrollBody" style="background:#e9ecef;overflow:auto;padding:12px;">
        <div id="pdfPagesWrap" style="display:flex;flex-direction:column;align-items:flex-start;gap:10px;min-width:min-content;"></div>
    </div>
</div><!-- /panel-pdf -->

    </div><!-- /proy-split-wrap -->
    <?php endif; ?>

</div><!-- /resumen-overlay -->

<script>
// ── Maximizar/restaurar paneles ──────────────────────────────────────────────
// ── Zoom del visor PDF ───────────────────────────────────────────────────────
function zoomPdf(delta) {
    if (delta === 0) {
        zoomFactor = 1.0;
    } else {
        zoomFactor = Math.min(4.0, Math.max(0.25, zoomFactor + delta));
    }
    actualizarLabelZoom();
    if (!pdfDoc) return;
    // Re-renderizar todas las páginas ya cargadas
    Object.keys(pageContainers).forEach(function(num) {
        const pc = pageContainers[num];
        if (pc.rendered) {
            pc.rendered  = false;
            pc.rendering = false;
            renderPage(parseInt(num));
        } else {
            // Ajustar tamaño del placeholder proporcionalmente
            if (baseScale && pc.pageDiv) {
                const newW = Math.round(pc.pageDiv.offsetWidth * (zoomFactor / (zoomFactor - delta || 1)));
                // recalcular se hará cuando el observer lo active
            }
        }
    });
}

function actualizarLabelZoom() {
    const lbl = document.getElementById('pdfZoomLabel');
    if (lbl) lbl.textContent = Math.round(zoomFactor * 100) + '%';
}

function toggleSplitMax(panelId, otherId) {
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
    // Re-renderizar todas las páginas tras la transición CSS
    setTimeout(function() {
        if (pdfDoc) { baseScale = null; zoomPdf(0); }
    }, 350);
}

// ── Cambiar PDF en el visor ──────────────────────────────────────────────────
function cambiarPDF(url) {
    if (url && url !== '') {
        loadPDF(url);
    }
}

// Formatea una fecha (Date) como "dd-Mes-yyyy" en español -- compartida por los 3 escenarios,
// ahora que los 3 leen su fecha base de un input type="date" (ISO yyyy-mm-dd), no de texto libre.
const ESC_MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
function fmtFechaEsc(d) {
    return String(d.getDate()).padStart(2,'0') + '-' + ESC_MESES[d.getMonth()] + '-' + d.getFullYear();
}

// ── Escenario 1: calcular fecha a partir de la fecha de la PO + N semanas
function calcEscenario1() {
    const fechaPO = document.getElementById('f_fecha_entrega')?.value;
    const semanas = parseInt(document.getElementById('f_semanas_plazo_po')?.value) || 16;
    const el = document.getElementById('esc1_result');
    if (!el) return;
    if (!fechaPO || fechaPO.length < 8) { el.textContent = '—'; return; }
    const base = new Date(fechaPO + 'T00:00:00');
    base.setDate(base.getDate() + semanas * 7);
    el.textContent = semanas + ' Semanas desde PO del cliente (' + fmtFechaEsc(base) + ')';
}

// ── Escenario 2: calcular fecha a partir de la fecha de anticipo + N semanas
function calcEscenario2() {
    const anticipo = document.getElementById('f_fecha_anticipo')?.value;
    const semanas = parseInt(document.getElementById('f_semanas_plazo_anticipo')?.value) || 16;
    const el = document.getElementById('esc2_result');
    if (!el) return;
    if (!anticipo || anticipo.length < 8) { el.textContent = '—'; return; }
    const base = new Date(anticipo + 'T00:00:00');
    base.setDate(base.getDate() + semanas * 7);
    el.textContent = semanas + ' Semanas desde anticipo del cliente (' + fmtFechaEsc(base) + ')';
}

// ── Escenario 3: calcular fecha a partir de la fecha de aprobación de ingeniería + N semanas --
// ya NO depende de la fecha de anticipo (pedido explícito, 2026-09-07): cada escenario es un
// valor independiente ahora, no una combinación/MAX de dos fechas.
function calcEscenario3() {
    const aprobIng = document.getElementById('f_fecha_aprobacion_ing')?.value;
    const semanas  = parseInt(document.getElementById('f_semanas_plazo')?.value) || 16;
    const el       = document.getElementById('esc3_result');
    if (!el) return;
    if (!aprobIng || aprobIng.length < 8) { el.textContent = '—'; return; }
    const base = new Date(aprobIng + 'T00:00:00');
    base.setDate(base.getDate() + semanas * 7);
    el.textContent = semanas + ' Semanas desde aprobación de ingeniería (' + fmtFechaEsc(base) + ')';
}

// Inicializar al cargar (si ya hay datos guardados)
document.addEventListener('DOMContentLoaded', function () { calcEscenario1(); calcEscenario2(); calcEscenario3(); });

// ── Pestañas del panel izquierdo ─────────────────────────────────────────────
function mostrarTabRes(tab) {
    document.getElementById('tabResumen').style.display = tab === 'resumen' ? 'block' : 'none';
    document.getElementById('tabPlan').style.display    = tab === 'plan'    ? 'block' : 'none';
    document.getElementById('tabResBtn').classList.toggle('active', tab === 'resumen');
    document.getElementById('tabPlanBtn').classList.toggle('active', tab === 'plan');
}

// ── Serializar tablas a JSON hidden ─────────────────────────────────────────
function syncItemsJSON() {
    const rows = document.querySelectorAll('#itemsBody tr');
    const items = [];
    rows.forEach(tr => {
        const inputs = tr.querySelectorAll('input');
        if (inputs.length < 5) return;
        items.push({ item: inputs[0].value, tag: inputs[1].value, cantidad: parseFloat(inputs[2].value)||0, unidad: inputs[3].value, descripcion: inputs[4].value });
    });
    document.getElementById('items_json_hidden').value = JSON.stringify(items);
}

function syncNormasJSON() {
    const chips = document.querySelectorAll('#normasWrap .badge');
    const normas = [];
    chips.forEach(ch => {
        const txt = ch.textContent.trim().replace(/×$/, '').trim();
        const parts = txt.split('·');
        normas.push({ codigo: parts[0].trim(), descripcion: (parts[1] || '').trim() });
    });
    document.getElementById('normas_json_hidden').value = JSON.stringify(normas);
}

function syncPlanJSON() {
    const rows = document.querySelectorAll('#planBody tr');
    const plan = [];
    rows.forEach((tr, idx) => {
        const inputs    = tr.querySelectorAll('input, textarea');
        const selects   = tr.querySelectorAll('select');
        if (inputs.length < 2) return;
        plan.push({
            item:         parseInt(inputs[0]?.value) || idx + 1,
            requisito:    inputs[1]?.value || '',
            referencia:   inputs[2]?.value || '',
            metodo_norma: inputs[3]?.value || '',
            criterios:    inputs[4]?.value || '',
            cumple:       selects[0]?.value || 'FALSO',
            comentarios:  inputs[5]?.value || ''
        });
    });
    document.getElementById('plan_json_hidden').value = JSON.stringify(plan);
}

function syncAll() { syncItemsJSON(); syncNormasJSON(); syncPlanJSON(); }

// ── Agregar filas ────────────────────────────────────────────────────────────
function agregarFilaItem() {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input type="text" placeholder="#" style="width:36px;"></td>
        <td><input type="text" placeholder="TAG"></td>
        <td><input type="number" step="any" placeholder="0"></td>
        <td><input type="text" placeholder="PZA"></td>
        <td><input type="text" placeholder="Descripción del ítem"></td>
        <td><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncItemsJSON()"><i class="fas fa-times"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
}

function agregarFilaPlan() {
    const tbody = document.getElementById('planBody');
    const n = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="col-item"><input type="number" value="${n}" style="width:46px;text-align:center;"></td>
        <td class="col-req"><textarea placeholder="Descripción del requisito"></textarea></td>
        <td class="col-ref"><input type="text" placeholder="Ref. especificación"></td>
        <td class="col-met"><input type="text" placeholder="Visual / Norma"></td>
        <td class="col-cri"><textarea placeholder="Criterio de conformidad"></textarea></td>
        <td class="col-cum"><select class="cumple-falso" onchange="this.className=this.value==='VERDADERO'?'cumple-verdadero':this.value==='N/A'?'cumple-na':'cumple-falso'"><option>FALSO</option><option>VERDADERO</option><option>N/A</option></select></td>
        <td class="col-com"><input type="text" placeholder=""></td>
        <td class="col-del"><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncPlanJSON()"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(tr);
}

// ── Normas: agregar/eliminar chips ───────────────────────────────────────────
function agregarNorma() {
    const code = document.getElementById('nuevaNormaCode').value.trim();
    if (!code) return;
    const desc = document.getElementById('nuevaNormaDesc').value.trim();
    const chip = document.createElement('span');
    chip.className = 'badge badge-bom';
    chip.style.cursor = 'pointer';
    chip.innerHTML = code + (desc ? ' · ' + desc : '') + ' <i class="fas fa-times" style="margin-left:5px;font-size:.65rem;"></i>';
    chip.onclick = () => { eliminarNorma(chip); };
    document.getElementById('normasWrap').appendChild(chip);
    document.getElementById('nuevaNormaCode').value = '';
    document.getElementById('nuevaNormaDesc').value = '';
    syncNormasJSON();
}

function eliminarNorma(el) { el.remove(); syncNormasJSON(); }

// ── PDF.js: configurar worker ─────────────────────────────────────────────────
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

async function pdfAPaginasProyecto(blob) {
    if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js no disponible');
    const arrayBuffer = await blob.arrayBuffer();
    const pdf      = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const numPages = pdf.numPages;
    const scale    = 1.5;
    const MAX_IMG  = 5; // límite de Groq

    const spinner = document.getElementById('iaSpinner');

    // 1. Renderizar todas las páginas a canvas individuales
    const canvases = [];
    for (let i = 1; i <= numPages; i++) {
        if (spinner) spinner.innerHTML = `<i class="fas fa-sync-alt"></i> Renderizando página ${i}/${numPages}…`;
        const page     = await pdf.getPage(i);
        const viewport = page.getViewport({ scale });
        const c = document.createElement('canvas');
        c.width  = viewport.width;
        c.height = viewport.height;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, c.width, c.height);
        await page.render({ canvasContext: ctx, viewport }).promise;
        canvases.push(c);
    }

    // 2. Si caben en el límite, retornarlas directo
    if (canvases.length <= MAX_IMG) {
        return canvases.map(c => c.toDataURL('image/jpeg', 0.82).split(',')[1]);
    }

    // 3. Agrupar y unir verticalmente para no superar MAX_IMG imágenes
    const pxGrupo = Math.ceil(canvases.length / MAX_IMG);
    const paginas = [];

    for (let g = 0; g < MAX_IMG; g++) {
        const inicio = g * pxGrupo;
        const fin    = Math.min(inicio + pxGrupo, canvases.length);
        if (inicio >= canvases.length) break;

        const grupo     = canvases.slice(inicio, fin);
        const maxAncho  = Math.max(...grupo.map(c => c.width));
        const altoTotal = grupo.reduce((s, c) => s + c.height, 0);

        const union = document.createElement('canvas');
        union.width  = maxAncho;
        union.height = altoTotal;
        const uctx = union.getContext('2d');
        uctx.fillStyle = '#ffffff';
        uctx.fillRect(0, 0, maxAncho, altoTotal);

        let y = 0;
        for (const c of grupo) {
            uctx.drawImage(c, 0, y);
            y += c.height;
        }

        if (spinner) spinner.innerHTML = `<i class="fas fa-sync-alt"></i> Preparando imagen ${g+1}/${MAX_IMG}…`;
        paginas.push(union.toDataURL('image/jpeg', 0.78).split(',')[1]);
    }

    return paginas;
}

// ── Docs de Referencia — selector panel ──────────────────────────────────────
var _docrefAllDocs = { global: [], proyecto: [] };

function toggleDocrefPanel() {
    var panel   = document.getElementById('docrefPanel');
    var chevron = document.getElementById('docrefChevron');
    var visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    chevron.style.transform = visible ? 'rotate(0deg)' : 'rotate(180deg)';
    if (!visible && !_docrefAllDocs.global.length && !_docrefAllDocs.proyecto.length) {
        cargarDocrefSelector();
    }
}

function cargarDocrefSelector() {
    var pid = <?= intval($pid_res) ?> || '';
    fetch('ajax/listar_documentos_ref.php?proyecto_id=' + pid + '&solo_procesados=1')
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            _docrefAllDocs = { global: data.global || [], proyecto: data.proyecto || [] };
            renderDocrefCheckboxes();
        });
}

function renderDocrefCheckboxes() {
    var el = document.getElementById('docrefCheckboxes');
    var allDocs = _docrefAllDocs.proyecto.map(function(d){ return Object.assign({}, d, {_proy: true}); })
        .concat(_docrefAllDocs.global.map(function(d){ return Object.assign({}, d, {_proy: false}); }));
    if (!allDocs.length) {
        el.innerHTML = '<p style="color:#aaa;font-size:.8rem;">No hay documentos procesados disponibles. '
            + '<a href="?seccion=docs_referencia" target="_blank">Subir documentos \u2192</a></p>';
        return;
    }
    el.innerHTML = allDocs.map(function(d) {
        var idiomaLabel = d.idioma === 'en' ? 'EN' : 'ES';
        var pags = d.paginas ? ' \u00b7 ' + d.paginas + ' p\u00e1gs' : '';
        var origen = d._proy ? '\u00b7 Proyecto' : '\u00b7 Global';
        return '<label style="display:flex;align-items:center;gap:8px;font-size:.82rem;cursor:pointer;">'
            + '<input type="checkbox" class="docref-chk" value="' + d.id + '"'
            + (d._proy ? ' checked' : '')
            + ' onchange="actualizarConteoDocref()">'
            + '<span><strong>' + escHtmlDocref(d.nombre) + '</strong>'
            + ' <span style="color:#888;font-size:.76rem;">' + origen + ' \u00b7 ' + idiomaLabel + pags + '</span></span>'
            + '</label>';
    }).join('');
    actualizarConteoDocref();
}

function actualizarConteoDocref() {
    var checked = document.querySelectorAll('.docref-chk:checked').length;
    var el = document.getElementById('docrefSelCount');
    if (el) el.textContent = checked;
}

function getDocrefSeleccionados() {
    return Array.from(document.querySelectorAll('.docref-chk:checked')).map(function(c){ return c.value; });
}

function escHtmlDocref(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Analizar con IA ──────────────────────────────────────────────────────────
async function analizarProyectoIA(modo) {
    // modo: 'resumen' | 'plan'
    const pdfSrc = currentPdfUrl || '';
    if (!pdfSrc || pdfSrc.endsWith('undefined')) { alert('No hay PDF visible para analizar. Asegúrate de que el visor muestre el documento correcto.'); return; }

    const btnId  = modo === 'plan' ? 'btnAnalizarPlan' : 'btnAnalizarResumen';
    const btn    = document.getElementById(btnId);
    const spinner = document.getElementById('iaSpinner');
    if (btn) btn.disabled = true;
    spinner.classList.add('show');
    spinner.innerHTML = '<i class="fas fa-sync-alt"></i> Convirtiendo PDF…';

    try {
        const resp = await fetch(pdfSrc);
        if (!resp.ok) throw new Error('No se pudo descargar el PDF del visor');
        const blob = await resp.blob();

        const fd = new FormData();
        fd.append('modo', modo);
        // Adjuntar IDs de documentos de referencia seleccionados (solo modo plan)
        if (modo === 'plan') {
            const docIds = getDocrefSeleccionados();
            if (docIds.length) fd.append('doc_ref_ids', JSON.stringify(docIds));
        }

        // ── Intentar usar el texto del OCR cache (Tesseract.js ya corrió en el visor) ──
        let textoOCR = '';
        if (pdfDoc) {
            for (let i = 1; i <= pdfDoc.numPages; i++) {
                const cached = ocrCache[i + '_' + currentPdfUrl];
                if (cached && cached.fullText && cached.fullText.trim().length > 20) {
                    textoOCR += '--- Página ' + i + ' ---\n' + cached.fullText.trim() + '\n\n';
                }
            }
        }

        if (textoOCR.trim().length > 100) {
            // Tenemos texto del OCR — mandarlo directamente (sin imágenes, sin tokens de visión)
            spinner.innerHTML = '<i class="fas fa-sync-alt"></i> Analizando texto…';
            fd.append('texto_ocr', textoOCR.substring(0, 15000));
            fd.append('archivo', new File([''], 'doc.txt', { type: 'text/plain' }));
        } else {
            // Sin OCR cache — mandar imágenes como antes
            try {
                const paginas = await pdfAPaginasProyecto(blob);
                spinner.innerHTML = `<i class="fas fa-sync-alt"></i> Analizando ${paginas.length} imagen(es)…`;
                fd.append('paginas_pdf', JSON.stringify(paginas));
                fd.append('archivo', new File([''], 'doc.jpg', { type: 'image/jpeg' }));
                fd.append('pdf_original', blob, 'documento.pdf');
            } catch (pdfErr) {
                fd.append('archivo', blob, 'documento.pdf');
            }
        }

        const iaResp = await fetch('api/analizar_proyecto_ia.php', { method: 'POST', body: fd });
        const data   = await iaResp.json();

        if (btn) btn.disabled = false;
        spinner.classList.remove('show');

        if (!data.success) { alert('Error IA: ' + (data.error || 'Sin respuesta')); return; }

        poblarFormulario(data.datos, modo);

    } catch (err) {
        if (btn) btn.disabled = false;
        spinner.classList.remove('show');
        alert('Error al analizar: ' + err.message);
    }
}

function poblarFormulario(d, modo) {
    if (!d) return;
    modo = modo || 'resumen';

    // ── Campos del resumen (solo en modo resumen) ─────────────────────────────
    if (modo === 'resumen') {
        if (d.numero_po)     document.getElementById('f_numero_po').value     = d.numero_po;
        if (d.cliente)       document.getElementById('f_cliente').value       = d.cliente;
        if (d.descripcion)   document.getElementById('f_descripcion').value   = d.descripcion;
        if (d.fecha_entrega) document.getElementById('f_fecha_entrega').value = d.fecha_entrega;
        if (d.condiciones)   document.getElementById('f_condiciones').value   = d.condiciones;
        if (d.observaciones) document.getElementById('f_observaciones').value = d.observaciones;
    }
    // (los campos de resumen NO se tocan en modo plan)

    // Ítems y normas (solo modo resumen)
    if (modo === 'resumen' && d.items && d.items.length) {
        const tbody = document.getElementById('itemsBody');
        tbody.innerHTML = '';
        d.items.forEach(it => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><input type="text" value="${esc(it.item)}" style="width:36px;"></td>
                <td><input type="text" value="${esc(it.tag)}"></td>
                <td><input type="number" value="${it.cantidad||''}" step="any"></td>
                <td><input type="text" value="${esc(it.unidad)}"></td>
                <td><input type="text" value="${esc(it.descripcion)}"></td>
                <td><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncItemsJSON()"><i class="fas fa-times"></i></button></td>`;
            tbody.appendChild(tr);
        });
        syncItemsJSON();
    }

    // Normas (solo modo resumen)
    if (modo === 'resumen' && d.normas && d.normas.length) {
        const wrap = document.getElementById('normasWrap');
        wrap.innerHTML = '';
        d.normas.forEach(n => {
            const chip = document.createElement('span');
            chip.className = 'badge badge-bom';
            chip.style.cursor = 'pointer';
            const label = n.codigo + (n.descripcion ? ' · ' + n.descripcion : '');
            chip.innerHTML = label + ' <i class="fas fa-times" style="margin-left:5px;font-size:.65rem;"></i>';
            chip.onclick = () => { eliminarNorma(chip); };
            wrap.appendChild(chip);
        });
        syncNormasJSON();
    }

    // Plan de inspección (siempre que venga, pero se muestra en modo plan)
    if (d.plan_inspeccion && d.plan_inspeccion.length) {
        const tbody = document.getElementById('planBody');
        tbody.innerHTML = '';
        d.plan_inspeccion.forEach((pl, idx) => {
            const cumple   = pl.cumple || 'FALSO';
            const cumClase = cumple === 'VERDADERO' ? 'cumple-verdadero' : cumple === 'N/A' ? 'cumple-na' : 'cumple-falso';
            const selOpts  = ['FALSO','VERDADERO','N/A'].map(o => `<option${o===cumple?' selected':''}>${o}</option>`).join('');
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="col-item"><input type="number" value="${pl.item||idx+1}" style="width:46px;text-align:center;"></td>
                <td class="col-req"><textarea>${esc(pl.requisito||pl.actividad||'')}</textarea></td>
                <td class="col-ref"><input type="text" value="${esc(pl.referencia||pl.norma||'')}"></td>
                <td class="col-met"><input type="text" value="${esc(pl.metodo_norma||pl.tipo_prueba||'')}"></td>
                <td class="col-cri"><textarea>${esc(pl.criterios||pl.criterio||pl.resultado_esperado||'')}</textarea></td>
                <td class="col-cum"><select class="${cumClase}" onchange="this.className=this.value==='VERDADERO'?'cumple-verdadero':this.value==='N/A'?'cumple-na':'cumple-falso'">${selOpts}</select></td>
                <td class="col-com"><input type="text" value="${esc(pl.comentarios||'')}"></td>
                <td class="col-del"><button type="button" class="btn-del-row" onclick="this.closest('tr').remove();syncPlanJSON()"><i class="fas fa-times"></i></button></td>`;
            tbody.appendChild(tr);
        });
        syncPlanJSON();
        if (modo === 'plan') mostrarTabRes('plan');
    }

    // Flash visual para confirmar que se llenó
    document.getElementById('panelFormRes').style.boxShadow = '0 0 0 3px #7b52c0';
    setTimeout(() => { document.getElementById('panelFormRes').style.boxShadow = ''; }, 1200);
}

function esc(s) { return (s||'').toString().replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
// ====================== VISOR PDF CON BÚSQUEDA Y RESALTADO ======================
let pdfDoc        = null,
    pageNum        = 1,      // página activa para OCR/búsqueda
    scale          = 1.0,
    canvas         = null,   // apunta al canvas de la página activa
    ctx            = null,
    textLayerDiv   = null,   // apunta al textLayer de la página activa
    currentMatches    = [],
    currentMatchIndex = -1,
    searchText     = '',
    totalMatches   = 0,
    highlightColor = 'rgba(255, 180, 0, 0.85)',
    currentPdfUrl  = '',
    currentViewport = null,
    baseScale      = null,
    zoomFactor     = 1.0,
    pageContainers = {},     // { num: { pageDiv, canvas, ctx, textLayer, ovCanvas, rendered, viewport } }
    pageObserver   = null,
    ocrPaginasTotal     = 0,   // total de páginas imagen que necesitan OCR
    ocrPaginasListas    = 0;   // cuántas ya terminaron

// Verificar si el OCR terminó antes de analizar; advertir si no
function verificarOCRYAnalizar(modo) {
    if (ocrPaginasTotal > 0 && ocrPaginasListas < ocrPaginasTotal) {
        const faltantes = ocrPaginasTotal - ocrPaginasListas;
        const ok = confirm(
            '⚠️ El OCR aún está procesando ' + faltantes + ' página(s).\n\n' +
            'Si analizas ahora puede que falte texto de algunas páginas.\n\n' +
            '¿Continuar de todas formas?'
        );
        if (!ok) return;
    }
    analizarProyectoIA(modo);
}

// ── VISOR CONTINUO: renderizar una página en su contenedor ──────────────────
async function renderPage(num) {
    const pc = pageContainers[num];
    if (!pc || pc.rendering) return;
    pc.rendering = true;

    // Capturar referencias LOCALES antes de cualquier await
    // (evita race conditions cuando varias páginas renderizan en paralelo)
    const localCanvas = pc.canvas;
    const localCtx    = pc.ctx;
    const localTL     = pc.textLayer;

    try {
        const page      = await pdfDoc.getPage(num);
        const defaultVp = page.getViewport({ scale: 1 });

        if (!baseScale) {
            const pdfPanel = document.getElementById('panelPdfRes');
            baseScale = Math.max(0.4, ((pdfPanel ? pdfPanel.clientWidth : 600) - 24) / defaultVp.width);
        }
        const viewport = page.getViewport({ scale: baseScale * zoomFactor });
        pc.viewport = viewport;

        localCanvas.width  = Math.round(viewport.width);
        localCanvas.height = Math.round(viewport.height);
        pc.pageDiv.style.width     = localCanvas.width  + 'px';
        pc.pageDiv.style.height    = localCanvas.height + 'px';
        pc.pageDiv.style.alignSelf = 'center';
        localTL.style.width  = localCanvas.width  + 'px';
        localTL.style.height = localCanvas.height + 'px';
        localTL.innerHTML = '';

        if (pc.ovCanvas) {
            pc.ovCanvas.width  = localCanvas.width;
            pc.ovCanvas.height = localCanvas.height;
            pc.ovCanvas.getContext('2d').clearRect(0, 0, pc.ovCanvas.width, pc.ovCanvas.height);
        }

        const [, textContent] = await Promise.all([
            page.render({ canvasContext: localCtx, viewport, intent: 'display' }).promise,
            page.getTextContent()
        ]);

        if (pc.loader) { pc.loader.remove(); pc.loader = null; }
        pc.rendered  = true;
        pc.rendering = false;

        // Apuntar globals sincrónicamente (seguro: crearSpansTexto no tiene awaits)
        canvas = localCanvas; ctx = localCtx; textLayerDiv = localTL;
        currentViewport = viewport; pageNum = num;

        const textoTotal = (textContent.items || []).map(function(i){ return i.str||''; }).join('').trim();
        if (textoTotal.length > 10) {
            crearSpansTexto(textContent);
        } else {
            await ocrPagina(localCanvas, viewport, num, localTL);
        }

        // Si hay búsqueda activa, agregar resultados de esta página y actualizar contador
        if (searchText) {
            highlightPageMatches(num);
            totalMatches = currentMatches.length;
            const idx = currentMatchIndex >= 0 ? currentMatchIndex : 0;
            document.getElementById('searchResultInfo').innerText = (idx + 1) + '/' + totalMatches;
        }

    } catch(e) {
        pc.rendering = false;
        console.warn('renderPage error p.' + num, e);
    }
}

// Configura todas las páginas como placeholders y arranca el IntersectionObserver
async function setupAllPages() {
    const wrap  = document.getElementById('pdfPagesWrap');
    wrap.innerHTML = '';
    pageContainers = {};

    const pdfPanel = document.getElementById('panelPdfRes');
    const panelW   = (pdfPanel ? pdfPanel.clientWidth : 600) - 24;

    // Calcular baseScale con la primera página
    const firstPage = await pdfDoc.getPage(1);
    const defaultVp1 = firstPage.getViewport({ scale: 1 });
    baseScale = Math.max(0.4, panelW / defaultVp1.width);
    actualizarLabelZoom();

    for (let i = 1; i <= pdfDoc.numPages; i++) {
        const page  = await pdfDoc.getPage(i);
        const dv    = page.getViewport({ scale: 1 });
        const ratio = dv.height / dv.width;
        const w     = Math.round(panelW * zoomFactor);
        const h     = Math.round(w * ratio);

        const pageDiv = document.createElement('div');
        pageDiv.id = 'pdfPage_' + i;
        pageDiv.dataset.pageNum = i;
        pageDiv.style.cssText = `position:relative;width:${w}px;height:${h}px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.15);flex-shrink:0;align-self:center;`;

        const cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        cv.style.display = 'block';

        const tl = document.createElement('div');
        tl.style.cssText = `position:absolute;left:0;top:0;width:${w}px;height:${h}px;overflow:hidden;line-height:1;pointer-events:none;`;

        const loader = document.createElement('div');
        loader.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#f0f0f0;';
        loader.innerHTML = `<span style="color:#bbb;font-size:.75rem;font-family:sans-serif;">Página ${i} / ${pdfDoc.numPages}</span>`;

        pageDiv.appendChild(cv);
        pageDiv.appendChild(tl);
        pageDiv.appendChild(loader);
        wrap.appendChild(pageDiv);

        pageContainers[i] = {
            pageDiv, canvas: cv, ctx: cv.getContext('2d'),
            textLayer: tl, loader, ovCanvas: null,
            rendered: false, rendering: false, viewport: null
        };
    }

    // Renderizar página 1 directamente (garantiza que el primer contenido aparezca)
    renderPage(1);

    // IntersectionObserver para el resto de páginas (root: null = viewport del navegador)
    if (pageObserver) pageObserver.disconnect();
    pageObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            const num = parseInt(entry.target.dataset.pageNum);
            if (num && pageContainers[num] && !pageContainers[num].rendered && !pageContainers[num].rendering) {
                renderPage(num);
            }
        });
    }, { rootMargin: '400px 0px' }); // root:null = viewport; 400px de margen adelante

    // Observar páginas 2+ (la 1 ya se lanzó directamente)
    Object.keys(pageContainers).forEach(function(num) {
        if (parseInt(num) > 1) pageObserver.observe(pageContainers[num].pageDiv);
    });
}

// Wrapper para compatibilidad con código que llama renderPage(num) directamente
function queueRenderPage(num) { renderPage(num); }

// Crea spans de texto desde los items de PDF.js con coordenadas correctas (PDF→canvas)
function crearSpansTexto(textContent) {
    if (!currentViewport) return;
    const vm = currentViewport.transform; // [a,b,c,d,e,f] viewport matrix

    for (const item of (textContent.items || [])) {
        if (!item.str) continue;
        const span = document.createElement('span');
        span.textContent = item.str + (item.hasEOL ? ' ' : '');
        span.style.cssText = 'position:absolute;color:transparent;white-space:pre;pointer-events:none;';

        if (item.transform) {
            const t = item.transform; // [a,b,c,d,tx,ty] en espacio PDF
            // Aplicar viewport transform: combinar matrices para convertir PDF→canvas
            const canvasX    = vm[0]*t[4] + vm[2]*t[5] + vm[4];
            const canvasY    = vm[1]*t[4] + vm[3]*t[5] + vm[5];
            const fontSize   = Math.hypot(vm[0]*t[0] + vm[2]*t[1],
                                          vm[1]*t[0] + vm[3]*t[1]) || 12;
            span.style.left      = Math.round(canvasX) + 'px';
            span.style.top       = Math.round(canvasY - fontSize) + 'px';
            span.style.fontSize  = Math.round(fontSize) + 'px';
        }
        textLayerDiv.appendChild(span);
    }
}

// OCR con Tesseract.js — bounding boxes exactos por palabra
const ocrCache = {};
let tWorker = null;

async function getTesseractWorker() {
    if (tWorker) return tWorker;
    tWorker = await Tesseract.createWorker('eng', 1, {
        workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
        langPath:   'https://tessdata.projectnaptha.com/4.0.0_best',
        corePath:   'https://cdn.jsdelivr.net/npm/tesseract.js-core@5/tesseract-core-simd-lstm.wasm.js',
        logger: function() {}
    });
    // PSM 3 = Fully automatic (mejor para documentos estructurados multi-columna)
    await tWorker.setParameters({ tessedit_pageseg_mode: '3' });
    return tWorker;
}

// Híbrido: Tesseract (posiciones) + Groq (conteo completo)
// pageNumParam y tlParam evitan race conditions cuando múltiples páginas renderizan en paralelo
async function ocrPagina(canvasEl, vp, pageNumParam, tlParam) {
    const myNum    = (pageNumParam !== undefined) ? pageNumParam : pageNum;
    const myTL     = tlParam || textLayerDiv;
    const cacheKey = myNum + '_' + currentPdfUrl;

    if (ocrCache[cacheKey]) {
        construirSpansConBbox(ocrCache[cacheKey].words, ocrCache[cacheKey].lines, vp, myTL);
        return;
    }

    // Registrar que esta página necesita OCR
    ocrPaginasTotal++;
    actualizarProgresoOCR();

    const info = document.getElementById('searchResultInfo');
    if (info) info.innerText = 'OCR…';

    try {
        const OCR_SCALE = 3.0;
        const page    = await pdfDoc.getPage(myNum);
        const ocrVp   = page.getViewport({ scale: OCR_SCALE });
        const ocrCanvas = document.createElement('canvas');
        ocrCanvas.width  = ocrVp.width;
        ocrCanvas.height = ocrVp.height;
        await page.render({
            canvasContext: ocrCanvas.getContext('2d'),
            viewport: ocrVp,
            intent: 'print'   // renderizado de mayor calidad
        }).promise;

        // ── Preprocesar: escala de grises + contraste ─────────────────
        const prepCanvas = preprocessParaOCR(ocrCanvas);

        const worker = await getTesseractWorker();
        const { data } = await worker.recognize(prepCanvas);

        // Normalizar bboxes a fracción (0-1) del canvas OCR
        // Así se recalculan correctamente a cualquier zoom en construirSpansConBbox
        function normBbox(bbox) {
            return {
                x0: bbox.x0 / ocrVp.width,  y0: bbox.y0 / ocrVp.height,
                x1: bbox.x1 / ocrVp.width,  y1: bbox.y1 / ocrVp.height
            };
        }

        const scaledWords = (data.words || [])
            .filter(function(w) { return w.text.trim().length > 0; })
            .map(function(w) { return { text: w.text, bbox: normBbox(w.bbox), level: 'word' }; });

        const scaledLines = (data.lines || [])
            .filter(function(l) { return l.text.trim().length > 1; })
            .map(function(l) { return { text: l.text.trim(), bbox: normBbox(l.bbox), level: 'line' }; });

        // Usar data.text de Tesseract directamente como texto completo (sin Groq)
        ocrCache[cacheKey] = {
            words:    scaledWords,
            lines:    scaledLines,
            fullText: data.text || ''
        };
        construirSpansConBbox(scaledWords, scaledLines, vp, myTL);

    } catch(e) {
        console.warn('Tesseract OCR falló:', e);
    } finally {
        if (info) info.innerText = '';
        // Marcar página como lista y actualizar indicador
        ocrPaginasListas++;
        actualizarProgresoOCR();
    }
}

// Coloca spans con posiciones exactas del bbox de Tesseract
// Bboxes normalizadas (0-1); tlParam evita usar la global textLayerDiv en contexto multi-página
function construirSpansConBbox(words, lines, vp, tlParam) {
    if (!vp) { vp = lines; lines = []; }
    const tl = tlParam || textLayerDiv;
    if (!tl) return;
    tl.innerHTML = '';
    const sx = vp.width;
    const sy = vp.height;
    for (const word of words) {
        if (!word.text || !word.bbox) continue;
        const x0 = Math.round(word.bbox.x0 * sx);
        const y0 = Math.round(word.bbox.y0 * sy);
        const x1 = Math.round(word.bbox.x1 * sx);
        const y1 = Math.round(word.bbox.y1 * sy);
        const span = document.createElement('span');
        span.textContent = word.text;
        span.dataset.ocr = 'true';
        span.dataset.x0  = x0; span.dataset.y0 = y0;
        span.dataset.x1  = x1; span.dataset.y1 = y1;
        span.style.cssText = [
            'position:absolute',
            'left:'   + x0 + 'px',
            'top:'    + y0 + 'px',
            'width:'  + (x1 - x0) + 'px',
            'height:' + (y1 - y0) + 'px',
            'color:transparent',
            'white-space:pre',
            'pointer-events:none',
            'font-size:' + Math.max(8, y1 - y0 - 2) + 'px',
            'line-height:1'
        ].join(';');
        tl.appendChild(span);
    }
    // Líneas completas como respaldo — data-ocr-line="true"
    for (const line of (lines || [])) {
        if (!line.text) continue;
        const x0 = Math.round(line.bbox.x0 * sx);
        const y0 = Math.round(line.bbox.y0 * sy);
        const x1 = Math.round(line.bbox.x1 * sx);
        const y1 = Math.round(line.bbox.y1 * sy);
        const span = document.createElement('span');
        span.textContent = line.text;
        span.dataset.ocr     = 'true';
        span.dataset.ocrLine = 'true';
        span.dataset.x0 = x0; span.dataset.y0 = y0;
        span.dataset.x1 = x1; span.dataset.y1 = y1;
        span.style.cssText = 'position:absolute;left:'+x0+'px;top:'+y0+'px;width:'+(x1-x0)+'px;height:'+(y1-y0)+'px;color:transparent;white-space:pre;pointer-events:none;font-size:1px;';
        tl.appendChild(span);
    }
}

// Fallback: Groq cuando Tesseract no está disponible

function loadPDF(url) {
    currentPdfUrl    = url;
    zoomFactor       = 1.0;
    baseScale        = null;
    ocrPaginasTotal  = 0;
    ocrPaginasListas = 0;
    actualizarLabelZoom();
    actualizarProgresoOCR();
    clearHighlights();
    actualizarBannerOcr(0);
    currentMatches    = [];
    currentMatchIndex = -1;
    document.getElementById('searchResultInfo').innerText = '';
    searchText = '';
    document.getElementById('pdfSearchInput').value = '';

    pdfjsLib.getDocument(url).promise.then(async function(pdfDoc_) {
        pdfDoc = pdfDoc_;
        await setupAllPages();
    }).catch(function(err) {
        console.error('Error cargando PDF:', err);
        alert('No se pudo cargar el PDF.');
    });
}

// Preprocesa canvas para mejorar OCR: escala de grises + aumento de contraste
// ── Indicador de progreso OCR ────────────────────────────────────────────────
function actualizarProgresoOCR() {
    const badge = document.getElementById('ocrProgressBadge');
    if (!badge) return;

    if (ocrPaginasTotal === 0) {
        badge.style.display = 'none';
        return;
    }

    if (ocrPaginasListas < ocrPaginasTotal) {
        badge.style.display = 'inline-block';
        badge.style.background = '#fff3cd';
        badge.style.color = '#856404';
        badge.style.borderColor = '#ffc107';
        badge.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:4px;"></i>OCR ' + ocrPaginasListas + '/' + ocrPaginasTotal;
    } else {
        // Todas listas
        badge.style.display = 'inline-block';
        badge.style.background = '#d1e7dd';
        badge.style.color = '#0a3622';
        badge.style.borderColor = '#a3cfbb';
        badge.innerHTML = '<i class="fas fa-check" style="margin-right:4px;"></i>OCR listo';
        // Ocultar después de 4 segundos
        setTimeout(function() { badge.style.display = 'none'; }, 4000);
    }
}

function preprocessParaOCR(src) {
    const out = document.createElement('canvas');
    out.width  = src.width;
    out.height = src.height;
    const ctx = out.getContext('2d');

    // Fondo blanco antes de copiar (evita transparencias que confunden a Tesseract)
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, out.width, out.height);
    ctx.drawImage(src, 0, 0);

    const img  = ctx.getImageData(0, 0, out.width, out.height);
    const d    = img.data;
    const cont = 1.5; // factor de contraste (1=sin cambio, >1=más contraste)

    for (let i = 0; i < d.length; i += 4) {
        // Convertir a escala de grises (luminancia)
        const g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
        // Aplicar contraste centrado en 128
        const v = Math.min(255, Math.max(0, cont * (g - 128) + 128));
        d[i] = d[i + 1] = d[i + 2] = v;
    }

    ctx.putImageData(img, 0, 0);
    return out;
}

// Normaliza errores OCR típicos para comparación fuzzy
function normOcr(str) {
    return str.toLowerCase()
        .replace(/0/g, 'o').replace(/1/g, 'l').replace(/\|/g, 'l')
        .replace(/\$/g, 's').replace(/@/g, 'a').replace(/5/g, 's')
        .replace(/8/g, 'b').replace(/6/g, 'g').replace(/rn/g, 'm')
        .replace(/[^a-z]/g, '');  // solo letras para comparación
}

// Busca en todas las páginas renderizadas
function highlightAllMatches() {
    if (!searchText) return;
    clearHighlights();
    currentMatches = [];
    Object.keys(pageContainers).forEach(function(num) {
        highlightPageMatches(parseInt(num));
    });
    totalMatches = currentMatches.length;
    if (totalMatches > 0) {
        currentMatchIndex = 0;
        document.getElementById('searchResultInfo').innerText = '1/' + totalMatches;
        if (currentMatches[0] && currentMatches[0].element) scrollToMatch(0);
    } else {
        document.getElementById('searchResultInfo').innerText = '0';
    }
}

// Busca y resalta en una página específica
function highlightPageMatches(num) {
    if (!searchText) return;
    const pc = pageContainers[num];
    if (!pc || !pc.rendered || !pc.textLayer) return;

    // Apuntar globals a esta página para que las funciones auxiliares funcionen
    canvas       = pc.canvas;
    ctx          = pc.ctx;
    textLayerDiv = pc.textLayer;
    currentViewport = pc.viewport;
    pageNum      = num;

    const escaped   = searchText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const spans     = Array.from(pc.textLayer.getElementsByTagName('span'));
    const isOcrPage = spans.some(function(s) { return s.dataset.ocr === 'true'; });

    if (isOcrPage) {
        clearCanvasHighlights(num);

        const wordSpans     = spans.filter(function(s) { return !s.dataset.ocrLine; });
        const lineSpans     = spans.filter(function(s) { return  s.dataset.ocrLine; });
        const zonasCubiertas = [];

        function matchesSearch(text) {
            const r = new RegExp(escaped, 'gi');
            return r.test(text) || normOcr(text).includes(normOcr(searchText));
        }

        function anadirMatch(div) {
            const x0 = parseInt(div.dataset.x0) || 0;
            const y0 = parseInt(div.dataset.y0) || 0;
            const x1 = parseInt(div.dataset.x1) || x0 + 50;
            const y1 = parseInt(div.dataset.y1) || y0 + 16;
            dibujarHighlightExacto(x0, y0, x1 - x0, y1 - y0, num);
            zonasCubiertas.push({ x0, y0, x1, y1 });
            const ref = document.createElement('div');
            ref.className = 'ocr-match-bar';
            ref.style.cssText = 'position:absolute;left:'+x0+'px;top:'+y0+'px;width:1px;height:1px;pointer-events:none;';
            pc.textLayer.appendChild(ref);
            currentMatches.push({ pageIdx: num, element: ref, isOcr: true });
        }

        for (const div of wordSpans) {
            if (!div.textContent.trim() || !matchesSearch(div.textContent)) continue;
            anadirMatch(div);
        }
        for (const div of lineSpans) {
            if (!div.textContent.trim() || !matchesSearch(div.textContent)) continue;
            const ly0 = parseInt(div.dataset.y0) || 0;
            const ly1 = parseInt(div.dataset.y1) || ly0 + 16;
            if (!zonasCubiertas.some(function(z) { return ly0 < z.y1 && ly1 > z.y0; })) anadirMatch(div);
        }

        // Groq corrige el conteo
        const cached = ocrCache[num + '_' + currentPdfUrl];
        if (cached && cached.fullText) {
            const groqCount = (cached.fullText.match(new RegExp(escaped, 'gi')) || []).length;
            while (currentMatches.filter(function(m){ return m.pageIdx === num; }).length < groqCount) {
                currentMatches.push({ pageIdx: num, element: null, isOcr: true });
            }
        }

    } else {
        for (const div of spans) {
            const originalText = div.textContent;
            if (!originalText) continue;
            const regex = new RegExp(escaped, 'gi');
            const newHtml = originalText.replace(regex, function(match) {
                return `<mark style="background:${highlightColor};color:black;border-radius:2px;padding:0 1px;">${match}</mark>`;
            });
            if (newHtml !== originalText) {
                div.innerHTML = newHtml;
                for (const mark of div.getElementsByTagName('mark')) {
                    currentMatches.push({ pageIdx: num, element: mark });
                }
            }
        }
    }
}

function clearHighlights() {
    // Limpiar todas las páginas
    Object.values(pageContainers).forEach(function(pc) {
        if (!pc.textLayer) return;
        pc.textLayer.querySelectorAll('mark').forEach(function(m) { m.replaceWith(document.createTextNode(m.textContent)); });
        pc.textLayer.querySelectorAll('.ocr-match-bar').forEach(function(b) { b.remove(); });
    });
    clearCanvasHighlights();
    actualizarBannerOcr(0);
}

// Canvas overlay por página para dibujar highlights OCR
function getOverlayCanvas(num) {
    num = num || pageNum;
    const pc = pageContainers[num];
    if (!pc) return null;
    if (!pc.ovCanvas) {
        pc.ovCanvas = document.createElement('canvas');
        pc.ovCanvas.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none;z-index:5;';
        pc.pageDiv.appendChild(pc.ovCanvas);
    }
    if (pc.canvas && (pc.ovCanvas.width !== pc.canvas.width || pc.ovCanvas.height !== pc.canvas.height)) {
        pc.ovCanvas.width  = pc.canvas.width;
        pc.ovCanvas.height = pc.canvas.height;
    }
    return pc.ovCanvas;
}

function clearCanvasHighlights(num) {
    if (num) {
        const pc = pageContainers[num];
        if (pc && pc.ovCanvas) pc.ovCanvas.getContext('2d').clearRect(0, 0, pc.ovCanvas.width, pc.ovCanvas.height);
    } else {
        // Limpiar todas las páginas
        Object.values(pageContainers).forEach(function(pc) {
            if (pc.ovCanvas) pc.ovCanvas.getContext('2d').clearRect(0, 0, pc.ovCanvas.width, pc.ovCanvas.height);
        });
    }
}

function dibujarHighlightExacto(x, y, w, h, num) {
    const ov = getOverlayCanvas(num || pageNum);
    if (!ov) return;
    const octx = ov.getContext('2d');
    octx.fillStyle = 'rgba(255, 180, 0, 0.45)';
    octx.fillRect(x, y, w, h);
    octx.strokeStyle = 'rgba(200, 110, 0, 0.9)';
    octx.lineWidth = 1.5;
    octx.strokeRect(x + 0.75, y + 0.75, w - 1.5, h - 1.5);
}

function dibujarHighlightPalabra(cx, cy) {
    dibujarHighlightExacto(Math.max(0, cx - 30), Math.max(0, cy - 8), 60, 18);
}

function actualizarBannerOcr(count) {
    let banner = document.getElementById('ocrMatchBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'ocrMatchBanner';
        banner.style.cssText = [
            'position:absolute', 'top:8px', 'left:50%',
            'transform:translateX(-50%)',
            'background:rgba(40,40,40,0.82)',
            'color:#fff', 'font-size:12px', 'font-weight:600',
            'padding:4px 14px', 'border-radius:20px',
            'pointer-events:none', 'z-index:20',
            'display:none', 'white-space:nowrap',
            'box-shadow:0 2px 8px rgba(0,0,0,.35)',
            'transition:opacity .4s ease'
        ].join(';');
        const container = document.getElementById('pdfContainer');
        if (container) container.appendChild(banner);
    }

    // Cancelar cualquier timer previo
    if (banner._hideTimer) { clearTimeout(banner._hideTimer); banner._hideTimer = null; }

    if (count > 0) {
        banner.textContent = '🔍 ' + count + ' coincidencia' + (count > 1 ? 's' : '') + ' en esta página';
        banner.style.borderLeft = '4px solid ' + highlightColor;
        banner.style.opacity = '1';
        banner.style.display = 'block';
        // Auto-ocultar después de 3.5 segundos
        banner._hideTimer = setTimeout(function() {
            banner.style.opacity = '0';
            banner._hideTimer = setTimeout(function() {
                banner.style.display = 'none';
                banner.style.opacity = '1';
            }, 420);
        }, 3500);
    } else {
        banner.style.display = 'none';
    }
}

function scrollToMatch(index) {
    if (index < 0 || index >= currentMatches.length) return;
    let match = currentMatches[index];
    if (match && match.element) {
        match.element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Opcional: añadir un borde temporal
        match.element.style.outline = '2px solid orange';
        setTimeout(() => {
            if (match.element) match.element.style.outline = '';
        }, 1000);
    }
    document.getElementById('searchResultInfo').innerText = `${index+1}/${totalMatches}`;
}

function buscarTexto() {
    let newText = document.getElementById('pdfSearchInput').value.trim();
    if (newText === '') {
        clearHighlights();
        currentMatches = [];
        currentMatchIndex = -1;
        document.getElementById('searchResultInfo').innerText = '';
        searchText = '';
        return;
    }
    searchText = newText;
    if (!pdfDoc) return;

    // Buscar en páginas ya renderizadas
    highlightAllMatches();

    // Disparar render de todas las páginas pendientes para que también se busquen
    Object.keys(pageContainers).forEach(function(n) {
        const pc = pageContainers[parseInt(n)];
        if (pc && !pc.rendered && !pc.rendering) renderPage(parseInt(n));
    });
}

// Búsqueda visual enfocada: pregunta a la IA dónde está exactamente la palabra

function siguienteResultado() {
    if (currentMatches.length === 0) return;
    let newIdx = currentMatchIndex + 1;
    if (newIdx >= currentMatches.length) newIdx = 0;
    currentMatchIndex = newIdx;
    scrollToMatch(currentMatchIndex);
}

function anteriorResultado() {
    if (currentMatches.length === 0) return;
    let newIdx = currentMatchIndex - 1;
    if (newIdx < 0) newIdx = currentMatches.length - 1;
    currentMatchIndex = newIdx;
    scrollToMatch(currentMatchIndex);
}

// Eventos
// Navegación por teclas en el visor continuo (ya no hay botones Prev/Next página)
document.getElementById('pdfScrollBody')?.addEventListener('scroll', function() {
    // Actualizar página activa según qué página es la más visible
    let bestNum = 1, bestVis = 0;
    Object.keys(pageContainers).forEach(function(num) {
        const pc = pageContainers[num];
        if (!pc.pageDiv) return;
        const rect = pc.pageDiv.getBoundingClientRect();
        const scrollRect = document.getElementById('pdfScrollBody').getBoundingClientRect();
        const vis = Math.min(rect.bottom, scrollRect.bottom) - Math.max(rect.top, scrollRect.top);
        if (vis > bestVis) { bestVis = vis; bestNum = parseInt(num); }
    });
    pageNum = bestNum;
});
document.getElementById('pdfSearchBtn')?.addEventListener('click', buscarTexto);
document.getElementById('pdfPrevBtn')?.addEventListener('click', anteriorResultado);
document.getElementById('pdfNextBtn')?.addEventListener('click', siguienteResultado);
document.getElementById('pdfSearchInput')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') buscarTexto();
});

// Cargar PDF inicial
const initialPdfUrl = <?= json_encode($pdf_activo_url) ?>;
if (initialPdfUrl) {
    loadPDF(initialPdfUrl);
}

// Re-renderizar todas las páginas al redimensionar ventana
let resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
        if (pdfDoc) { baseScale = null; zoomPdf(0); }
    }, 300);
});
</script>

<?php endif; // seccion === resumen_proyecto ?>

<?php if ($seccion === 'documentos'): ?>
<style>
.docs-wrap { position:fixed; top:80px; left:0; right:0; bottom:0; display:flex; overflow:hidden; z-index:500; }
.docs-sidebar { width:280px; min-width:240px; background:#fff; border-right:1px solid #e2e8f0; overflow-y:auto; display:flex; flex-direction:column; }
.docs-sidebar-hdr { padding:16px; border-bottom:1px solid #e2e8f0; background:#023760; }
.docs-sidebar-hdr h3 { color:#fff; font-size:.88rem; font-weight:700; margin:0; }
.docs-sidebar-hdr p { color:#93c5fd; font-size:.72rem; margin:4px 0 0; }
.docs-proj-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; text-decoration:none; transition:background .12s; }
.docs-proj-item:hover { background:#f0f7ff; }
.docs-proj-item.active { background:#dbeafe; border-left:3px solid #023760; }
.docs-proj-item .proj-name { font-size:.78rem; font-weight:600; color:#0f172a; line-height:1.3; }
.docs-proj-item .proj-badges { display:flex; gap:3px; margin-top:3px; }
.doc-dot { width:7px; height:7px; border-radius:50%; }
.docs-main { flex:1; overflow-y:auto; padding:24px; background:#f1f5f9; }
.docs-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:#94a3b8; }
.docs-empty i { font-size:3rem; margin-bottom:12px; }
.docs-empty p { font-size:.88rem; }
.doc-card { background:#fff; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:18px; overflow:hidden; }
.doc-card-hdr { display:flex; align-items:center; gap:12px; padding:14px 18px; cursor:pointer; user-select:none; border-bottom:1px solid transparent; transition:border-color .15s; }
.doc-card-hdr:hover { background:#f8fafc; }
.doc-card-hdr.open { border-bottom-color:#e2e8f0; }
.doc-card-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.doc-card-title { font-size:.88rem; font-weight:700; color:#0f172a; }
.doc-card-sub { font-size:.72rem; color:#64748b; margin-top:2px; }
.doc-card-chevron { margin-left:auto; color:#94a3b8; font-size:.75rem; transition:transform .2s; }
.doc-card-chevron.open { transform:rotate(180deg); }
.doc-card-body { padding:18px; display:none; }
.doc-card-body.open { display:block; }
.doc-field-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 20px; margin-bottom:16px; }
.doc-field { }
.doc-field label { font-size:.68rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:2px; }
.doc-field span { font-size:.82rem; color:#1e293b; }
.doc-table { width:100%; border-collapse:collapse; font-size:.78rem; margin-top:8px; }
.doc-table th { background:#f8fafc; padding:6px 10px; text-align:left; font-weight:700; color:#475569; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e2e8f0; }
.doc-table td { padding:6px 10px; border-bottom:1px solid #f1f5f9; color:#1e293b; vertical-align:top; }
.doc-table tr:last-child td { border-bottom:none; }
.doc-no-data { text-align:center; padding:32px 16px; color:#94a3b8; font-size:.82rem; }
.doc-no-data i { font-size:1.8rem; display:block; margin-bottom:8px; }
.doc-plan-item { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #f1f5f9; }
.doc-plan-item:last-child { border-bottom:none; }
.doc-plan-num { width:24px; height:24px; background:#023760; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.65rem; font-weight:700; flex-shrink:0; margin-top:1px; }
.doc-plan-text { font-size:.8rem; color:#1e293b; line-height:1.4; }
.doc-plan-sub { font-size:.7rem; color:#64748b; margin-top:2px; }
.docs-proy-hdr { background:#fff; border-radius:12px; padding:16px 20px; margin-bottom:18px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
.cl-section { margin-bottom:12px; }
.cl-section-title { font-size:.72rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.cl-tag { display:inline-block; background:#f0f7ff; color:#023760; border:1px solid #bfdbfe; font-size:.72rem; padding:2px 9px; border-radius:20px; margin:2px; font-weight:600; }
</style>

<div class="docs-wrap">
    <!-- Sidebar: lista de proyectos -->
    <div class="docs-sidebar">
        <div class="docs-sidebar-hdr">
            <h3><i class="fas fa-folder-open"></i> Documentos</h3>
            <p>Selecciona un proyecto</p>
        </div>
        <?php foreach ($doc_proyectos_con_docs as $dp):
            $is_active = ($dp['id'] == $doc_proyecto_id);
            $has_op    = $dp['tiene_op'] > 0;
            $has_res   = $dp['tiene_resumen'] > 0;
        ?>
        <a class="docs-proj-item <?= $is_active ? 'active' : '' ?>"
           href="?seccion=documentos&proyecto_id=<?= $dp['id'] ?>">
            <div style="flex:1;min-width:0;">
                <div class="proj-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($dp['nombre']) ?>"><?= htmlspecialchars($dp['nombre']) ?></div>
                <div class="proj-badges">
                    <div class="doc-dot" style="background:<?= $has_op ? '#16a34a' : '#e2e8f0' ?>;" title="<?= $has_op ? 'Orden de Producción' : 'Sin OP' ?>"></div>
                    <div class="doc-dot" style="background:<?= $has_res ? '#7c3aed' : '#e2e8f0' ?>;" title="<?= $has_res ? 'Resumen IA' : 'Sin Resumen IA' ?>"></div>
                    <div class="doc-dot" style="background:<?= $dp['tiene_plan'] > 0 ? '#0284c7' : '#e2e8f0' ?>;" title="<?= $dp['tiene_plan'] > 0 ? 'Plan de Inspección' : 'Sin Plan' ?>"></div>
                </div>
            </div>
            <?php if ($is_active): ?>
            <i class="fas fa-chevron-right" style="color:#023760;font-size:.65rem;flex-shrink:0;"></i>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (empty($doc_proyectos_con_docs)): ?>
        <div style="padding:20px;text-align:center;color:#94a3b8;font-size:.78rem;">Sin proyectos registrados</div>
        <?php endif; ?>
    </div>

    <!-- Main: documentos del proyecto seleccionado -->
    <div class="docs-main">
        <?php if (!$doc_proyecto_id || !$doc_proyecto): ?>
        <div class="docs-empty">
            <i class="fas fa-hand-point-left"></i>
            <p>Selecciona un proyecto para ver sus documentos</p>
        </div>

        <?php else: ?>
        <!-- Header del proyecto -->
        <div class="docs-proy-hdr">
            <div style="width:42px;height:42px;background:#023760;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-industry" style="color:#fff;font-size:1rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:1rem;font-weight:700;color:#0f172a;"><?= htmlspecialchars($doc_proyecto['nombre']) ?></div>
                <?php if ($doc_proyecto['descripcion']): ?>
                <div style="font-size:.78rem;color:#64748b;margin-top:2px;"><?= htmlspecialchars($doc_proyecto['descripcion']) ?></div>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
                <?php
                $estado_doc = $doc_proyecto['estado'] ?? 'activo';
                $e_colors = ['activo'=>['#dcfce7','#166534'],'completado'=>['#dbeafe','#1e40af'],'cancelado'=>['#fee2e2','#991b1b']];
                [$e_bg,$e_c] = $e_colors[$estado_doc] ?? ['#f3f4f6','#374151'];
                ?>
                <span style="background:<?=$e_bg?>;color:<?=$e_c?>;font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:20px;"><?= ucfirst($estado_doc) ?></span>
            </div>
        </div>

        <!-- Leyenda de puntos -->
        <div style="display:flex;gap:14px;margin-bottom:16px;font-size:.72rem;color:#64748b;">
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:4px;"></span>Orden de Producción</span>
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#7c3aed;margin-right:4px;"></span>Resumen IA</span>
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#0284c7;margin-right:4px;"></span>Plan de Inspección</span>
        </div>

        <!-- ══ TARJETA 1: Orden de Producción ══ -->
        <div class="doc-card">
            <div class="doc-card-hdr open" onclick="toggleDocCard(this)" id="hdr-op">
                <div class="doc-card-icon" style="background:#dcfce7;">
                    <i class="fas fa-industry" style="color:#16a34a;font-size:.88rem;"></i>
                </div>
                <div>
                    <div class="doc-card-title">Orden de Producción</div>
                    <div class="doc-card-sub">
                        <?= $doc_op ? htmlspecialchars($doc_op['numero_proyecto'] ?? '—') . ' · ' . ($doc_op['estado'] === 'activa' ? 'Difundida' : 'Borrador') : 'Sin orden de producción' ?>
                    </div>
                </div>
                <i class="fas fa-chevron-down doc-card-chevron open"></i>
            </div>
            <div class="doc-card-body open">
                <?php if ($doc_op): ?>
                <div class="doc-field-grid">
                    <div class="doc-field">
                        <label>N° Proyecto</label>
                        <span><?= htmlspecialchars($doc_op['numero_proyecto'] ?? '—') ?></span>
                    </div>
                    <div class="doc-field">
                        <label>Cliente</label>
                        <span><?= htmlspecialchars($doc_op['cliente'] ?? '—') ?></span>
                    </div>
                    <div class="doc-field">
                        <label>Fecha</label>
                        <span><?= $doc_op['fecha'] ? date('d/m/Y', strtotime($doc_op['fecha'])) : '—' ?></span>
                    </div>
                    <div class="doc-field">
                        <label>Fecha compromiso</label>
                        <span><?= $doc_op['fecha_compromiso'] ? date('d/m/Y', strtotime($doc_op['fecha_compromiso'])) : '—' ?></span>
                    </div>
                    <div class="doc-field">
                        <label>Orden de Compra</label>
                        <span><?= htmlspecialchars($doc_op['orden_compra'] ?? '—') ?></span>
                    </div>
                    <div class="doc-field">
                        <label>Estado</label>
                        <span><?= $doc_op['estado'] === 'activa' ? '<span style="color:#16a34a;font-weight:700;">Difundida</span>' : '<span style="color:#64748b;">Borrador</span>' ?></span>
                    </div>
                    <?php if (!empty($doc_op['descripcion'])): ?>
                    <div class="doc-field" style="grid-column:1/-1;">
                        <label>Descripción</label>
                        <span><?= htmlspecialchars($doc_op['descripcion']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php $op_items_arr = json_decode($doc_op['items'] ?? '[]', true) ?: []; ?>
                <?php if (!empty($op_items_arr)): ?>
                <div style="font-size:.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Equipos / Partidas</div>
                <table class="doc-table">
                    <thead><tr>
                        <th style="width:40px;">#</th>
                        <th>Tag</th>
                        <th>Descripción</th>
                        <th style="width:60px;text-align:right;">Cant.</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($op_items_arr as $it): ?>
                    <tr>
                        <td><?= intval($it['item'] ?? 0) ?></td>
                        <td style="font-family:monospace;font-weight:600;"><?= htmlspecialchars($it['tag'] ?? '') ?></td>
                        <td><?= htmlspecialchars($it['desc'] ?? '') ?></td>
                        <td style="text-align:right;"><?= htmlspecialchars($it['qty'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($doc_checklist)): ?>
                <div style="font-size:.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin:16px 0 8px;">Checklist de recursos</div>
                <?php
                $cl_labels = ['maquinaria'=>'Maquinaria','control'=>'Control','infra'=>'Infraestructura','seguimiento'=>'Seguimiento','personal'=>'Personal','liberacion'=>'Liberación'];
                foreach ($doc_checklist as $grp => $items):
                    if (empty($items)) continue;
                ?>
                <div class="cl-section">
                    <div class="cl-section-title"><?= $cl_labels[$grp] ?? $grp ?></div>
                    <?php foreach ($items as $cl_item): ?>
                    <span class="cl-tag"><i class="fas fa-check" style="font-size:.6rem;margin-right:3px;"></i><?= htmlspecialchars($cl_item) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($doc_op['observaciones'])): ?>
                <div style="margin-top:14px;background:#f8fafc;border-radius:8px;padding:10px 14px;font-size:.8rem;color:#334155;">
                    <strong style="display:block;margin-bottom:4px;font-size:.7rem;color:#64748b;text-transform:uppercase;">Observaciones</strong>
                    <?= nl2br(htmlspecialchars($doc_op['observaciones'])) ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="doc-no-data">
                    <i class="fas fa-industry"></i>
                    La Orden de Producción aún no ha sido creada para este proyecto.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ TARJETA 2: Resumen IA ══ -->
        <div class="doc-card">
            <div class="doc-card-hdr" onclick="toggleDocCard(this)" id="hdr-ia">
                <div class="doc-card-icon" style="background:#ede9fe;">
                    <i class="fas fa-robot" style="color:#7c3aed;font-size:.88rem;"></i>
                </div>
                <div>
                    <div class="doc-card-title">Resumen IA</div>
                    <div class="doc-card-sub">
                        <?= $doc_resumen ? (($doc_resumen['analizado_ia'] ? 'Analizado · ' : 'Sin análisis · ') . htmlspecialchars($doc_resumen['cliente'] ?? '—')) : 'Sin resumen generado' ?>
                    </div>
                </div>
                <i class="fas fa-chevron-down doc-card-chevron"></i>
            </div>
            <div class="doc-card-body">
                <?php if ($doc_resumen): ?>
                <div class="doc-field-grid">
                    <?php if (!empty($doc_resumen['numero_po'])): ?>
                    <div class="doc-field">
                        <label>N° PO del cliente</label>
                        <span><?= htmlspecialchars($doc_resumen['numero_po']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['cliente'])): ?>
                    <div class="doc-field">
                        <label>Cliente</label>
                        <span><?= htmlspecialchars($doc_resumen['cliente']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Misma lógica/redacción que las cajas verdes del Resumen IA y el
                    // dropdown de la OP -- reusa calcularEscenariosFecha() en vez de
                    // recalcular aquí para no volver a desincronizarse (pasó con el
                    // cambio de Escenario 3 a valor independiente, 2026-09-07).
                    $escenarios_doc = calcularEscenariosFecha($doc_resumen);
                    $esc_colores = ['#0284c7', '#d97706', '#16a34a'];
                    ?>
                    <?php if (!empty($doc_resumen['fecha_anticipo'])): ?>
                    <div class="doc-field">
                        <label>Fecha de anticipo</label>
                        <span><?= date('d/m/Y', strtotime($doc_resumen['fecha_anticipo'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['fecha_aprobacion_ing'])): ?>
                    <div class="doc-field">
                        <label>Aprobación de ingeniería</label>
                        <span><?= date('d/m/Y', strtotime($doc_resumen['fecha_aprobacion_ing'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($escenarios_doc)): ?>
                    <div class="doc-field" style="grid-column:1/-1;">
                        <label>Escenarios de fecha de entrega</label>
                        <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                            <?php foreach ($escenarios_doc as $i => $esc_txt): ?>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="width:18px;height:18px;background:<?= $esc_colores[$i] ?? '#64748b' ?>;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;color:#fff;flex-shrink:0;"><?= $i + 1 ?></span>
                                <span style="font-size:.8rem;color:#1e293b;"><?= htmlspecialchars($esc_txt) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['analizado_ia'])): ?>
                    <div class="doc-field">
                        <label>Análisis IA</label>
                        <span style="color:#7c3aed;font-weight:700;"><i class="fas fa-check-circle"></i> Completado</span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['descripcion'])): ?>
                    <div class="doc-field" style="grid-column:1/-1;">
                        <label>Descripción</label>
                        <span><?= htmlspecialchars($doc_resumen['descripcion']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['condiciones'])): ?>
                    <div class="doc-field" style="grid-column:1/-1;">
                        <label>Condiciones</label>
                        <span><?= nl2br(htmlspecialchars($doc_resumen['condiciones'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['observaciones'])): ?>
                    <div class="doc-field" style="grid-column:1/-1;">
                        <label>Observaciones</label>
                        <span><?= nl2br(htmlspecialchars($doc_resumen['observaciones'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($doc_items)): ?>
                <div style="font-size:.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Equipos / Items de la PO</div>
                <table class="doc-table">
                    <thead><tr>
                        <th>#</th><th>Tag / Código</th><th>Descripción</th><th style="text-align:right;">Cant.</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($doc_items as $di): ?>
                    <tr>
                        <td><?= intval($di['item'] ?? 0) ?></td>
                        <td style="font-family:monospace;font-weight:600;"><?= htmlspecialchars($di['tag'] ?? $di['codigo'] ?? '') ?></td>
                        <td><?= htmlspecialchars($di['descripcion'] ?? $di['desc'] ?? '') ?></td>
                        <td style="text-align:right;"><?= htmlspecialchars($di['cantidad'] ?? $di['qty'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($doc_normas)): ?>
                <div style="font-size:.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin:16px 0 6px;">Normas aplicables</div>
                <div>
                    <?php foreach ($doc_normas as $n): ?>
                    <span class="cl-tag" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;"><?= htmlspecialchars(is_array($n) ? ($n['codigo'] ?? '') : $n) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($doc_resumen['archivo_po']) || !empty($doc_resumen['archivo_ov'])): ?>
                <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
                    <?php if (!empty($doc_resumen['archivo_po'])): ?>
                    <a href="<?= htmlspecialchars(SITE_URL . '/uploads/' . $doc_resumen['archivo_po']) ?>" target="_blank"
                       style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-size:.75rem;font-weight:600;padding:6px 12px;border-radius:8px;text-decoration:none;">
                        <i class="fas fa-file-pdf" style="color:#dc2626;"></i> Ver PO (PDF)
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($doc_resumen['archivo_ov'])): ?>
                    <a href="<?= htmlspecialchars(SITE_URL . '/uploads/' . $doc_resumen['archivo_ov']) ?>" target="_blank"
                       style="display:inline-flex;align-items:center;gap:6px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:.75rem;font-weight:600;padding:6px 12px;border-radius:8px;text-decoration:none;">
                        <i class="fas fa-file-pdf" style="color:#ea580c;"></i> Ver OV/SPI (PDF)
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="doc-no-data">
                    <i class="fas fa-robot"></i>
                    El Resumen IA no ha sido generado para este proyecto.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ TARJETA 3: Plan de Inspección ══ -->
        <div class="doc-card">
            <div class="doc-card-hdr" onclick="toggleDocCard(this)" id="hdr-plan">
                <div class="doc-card-icon" style="background:#e0f2fe;">
                    <i class="fas fa-tasks" style="color:#0284c7;font-size:.88rem;"></i>
                </div>
                <div>
                    <div class="doc-card-title">Plan de Inspección</div>
                    <div class="doc-card-sub">
                        <?= !empty($doc_plan) ? count($doc_plan) . ' ítem(s) registrados' : 'Sin plan generado' ?>
                    </div>
                </div>
                <i class="fas fa-chevron-down doc-card-chevron"></i>
            </div>
            <div class="doc-card-body">
                <?php if (!empty($doc_plan)): ?>
                <div>
                    <?php foreach ($doc_plan as $idx => $pi):
                        // Soporta múltiples estructuras de JSON (legado y actual)
                        if (is_array($pi)) {
                            $pi_desc     = $pi['requisito']    ?? $pi['descripcion'] ?? $pi['item'] ?? '';
                            $pi_ref      = $pi['referencia']   ?? '';
                            $pi_metodo   = $pi['metodo_norma'] ?? $pi['metodo']      ?? $pi['norma'] ?? '';
                            $pi_criterio = $pi['criterios']    ?? $pi['criterio_aceptacion'] ?? $pi['criterio'] ?? '';
                            $pi_cumple   = $pi['cumple']       ?? '';
                        } else {
                            $pi_desc = $pi; $pi_ref = $pi_metodo = $pi_criterio = $pi_cumple = '';
                        }
                        $cumple_color = match(strtoupper($pi_cumple)) {
                            'VERDADERO', 'TRUE', 'SI', 'SÍ', '1' => '#16a34a',
                            'FALSO', 'FALSE', 'NO', '0'           => '#dc2626',
                            default                                => '#94a3b8',
                        };
                    ?>
                    <div class="doc-plan-item">
                        <div class="doc-plan-num"><?= $idx + 1 ?></div>
                        <div style="flex:1;">
                            <div class="doc-plan-text"><?= htmlspecialchars($pi_desc) ?></div>
                            <?php if ($pi_ref || $pi_metodo || $pi_criterio): ?>
                            <div class="doc-plan-sub">
                                <?php if ($pi_ref): ?>
                                <span style="margin-right:10px;"><strong>Ref:</strong> <?= htmlspecialchars($pi_ref) ?></span>
                                <?php endif; ?>
                                <?php if ($pi_metodo): ?>
                                <span style="margin-right:10px;"><strong>Método:</strong> <?= htmlspecialchars($pi_metodo) ?></span>
                                <?php endif; ?>
                                <?php if ($pi_criterio): ?>
                                <span><strong>Criterio:</strong> <?= htmlspecialchars($pi_criterio) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($pi_cumple !== ''): ?>
                        <span style="font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:20px;background:<?= $cumple_color ?>1a;color:<?= $cumple_color ?>;border:1px solid <?= $cumple_color ?>33;white-space:nowrap;flex-shrink:0;margin-top:3px;">
                            <?= in_array(strtoupper($pi_cumple), ['VERDADERO','TRUE','SI','SÍ','1']) ? '✓ Cumple' : '✗ No cumple' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="doc-no-data">
                    <i class="fas fa-tasks"></i>
                    El Plan de Inspección aún no ha sido generado. Ve al módulo <strong>Resumen IA</strong> y analiza el PDF de la PO para generarlo automáticamente.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; // doc_proyecto_id ?>
    </div><!-- /docs-main -->
</div><!-- /docs-wrap -->

<script>
function toggleDocCard(hdr) {
    const body    = hdr.nextElementSibling;
    const chevron = hdr.querySelector('.doc-card-chevron');
    const isOpen  = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    hdr.classList.toggle('open', !isOpen);
    chevron.classList.toggle('open', !isOpen);
}
</script>
<?php endif; // seccion === documentos ?>

<?php if ($seccion === 'docs_referencia'): ?>
<div class="docref-section">
<div class="docref-inner">
    <div class="header-row" style="margin-bottom:20px;">
        <h2 style="font-size:1.4rem;color:#003d5c;display:flex;align-items:center;gap:10px;">
            <i class="fas fa-book-open"></i> Documentos de Referencia
        </h2>
    </div>

    <?php
    $docref_pid  = intval($_GET['proyecto_id'] ?? 0);
    $docref_proy = null;
    if ($docref_pid) {
        $s = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $s->execute([$docref_pid]);
        $docref_proy = $s->fetchColumn();
    }
    ?>

    <?php if ($docref_pid): ?>
    <div class="docref-card">
        <h3><i class="fas fa-folder-open" style="color:#005686;"></i>
            Documentos del Proyecto &mdash; <?= htmlspecialchars($docref_proy ?: "Proyecto #$docref_pid") ?>
        </h3>
        <div id="tablaDocsProy"><p style="color:#999;font-size:.85rem;">Cargando&hellip;</p></div>
        <div class="docref-upload-form" style="margin-top:20px;">
            <div style="font-weight:700;color:#003d5c;font-size:.88rem;margin-bottom:12px;">
                <i class="fas fa-upload"></i> Subir documento del proyecto (PO, SPI, especificaci&oacute;n)
            </div>
            <div class="fg">
                <label>Nombre del documento *</label>
                <input type="text" id="proy_nombre" placeholder="Ej: PO T-496, SPI-2024-101" style="width:100%;">
            </div>
            <div class="fg">
                <label>Descripci&oacute;n (opcional)</label>
                <input type="text" id="proy_desc" placeholder="Descripci&oacute;n breve" style="width:100%;">
            </div>
            <div class="fg">
                <label>Archivo PDF *</label>
                <input type="file" id="proy_archivo" accept=".pdf">
            </div>
            <div class="docref-progress" id="proy_progress">
                <i class="fas fa-sync-alt fa-spin"></i>
                <span id="proy_progress_msg">Subiendo&hellip;</span>
            </div>
            <button class="btn btn-primary" style="margin-top:10px;"
                    onclick="subirDocRef(<?= intval($docref_pid) ?>, 'proy')">
                <i class="fas fa-upload"></i> Subir documento
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="docref-card">
        <h3><i class="fas fa-globe" style="color:#2e7d32;"></i> Biblioteca de Normas Globales</h3>
        <div id="tablaDocsGlobal"><p style="color:#999;font-size:.85rem;">Cargando&hellip;</p></div>
        <div class="docref-upload-form" style="margin-top:20px;">
            <div style="font-weight:700;color:#003d5c;font-size:.88rem;margin-bottom:12px;">
                <i class="fas fa-upload"></i> Subir documento (ASME, API, ISO, NOM, ENI&hellip;)
            </div>
            <div class="fg">
                <label>Nombre del documento *</label>
                <input type="text" id="global_nombre" placeholder="Ej: ASME B31.3, API 570, ENI 29000" style="width:100%;">
            </div>
            <div class="fg">
                <label>Descripci&oacute;n (opcional)</label>
                <input type="text" id="global_desc" placeholder="Ej: Piping and Pressure Vessels" style="width:100%;">
            </div>
            <div class="fg">
                <label>Archivo PDF *</label>
                <input type="file" id="global_archivo" accept=".pdf">
            </div>
            <div class="docref-progress" id="global_progress">
                <i class="fas fa-sync-alt fa-spin"></i>
                <span id="global_progress_msg">Subiendo&hellip;</span>
            </div>
            <button class="btn btn-primary" style="margin-top:10px;" onclick="subirDocRef(null, 'global')">
                <i class="fas fa-upload"></i> Subir documento
            </button>
        </div>
    </div>
</div><!-- /docref-inner -->
</div><!-- /docref-section -->

<script>
(function () {
    var DOCREF_PID = <?= intval($docref_pid) ?> || null;

    function escH(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderDocrefTable(docs, containerId) {
        var el = document.getElementById(containerId);
        if (!docs || !docs.length) {
            el.innerHTML = '<p style="color:#aaa;font-size:.84rem;padding:10px 0;">Sin documentos a&uacute;n.</p>';
            return;
        }
        var badgeLbl = { procesado:'Procesado', pendiente:'Pendiente', sin_texto:'Sin texto', error:'Error' };
        var html = '<table class="docref-table"><thead><tr>'
            + '<th>Nombre</th><th>P&aacute;gs</th><th>Estado</th><th>Fecha</th><th></th>'
            + '</tr></thead><tbody>';
        docs.forEach(function(d) {
            var cl  = d.estado || 'pendiente';
            var lbl = d.estado === 'pendiente'
                ? '<i class="fas fa-sync-alt fa-spin" style="margin-right:4px;color:#f59e0b;"></i>Procesando&hellip;'
                : (badgeLbl[d.estado] || d.estado);
            var fecha = d.fecha_creacion ? d.fecha_creacion.substring(0, 10) : '';
            var rescanBtn = d.estado !== 'pendiente'
                ? '<button onclick="reescanearDoc(' + d.id + ',this)" title="Volver a extraer texto" style="background:none;border:none;cursor:pointer;color:#64748b;padding:2px 5px;border-radius:4px;transition:color .15s;" onmouseover="this.style.color=\'#005686\'" onmouseout="this.style.color=\'#64748b\'"><i class="fas fa-redo-alt" style="font-size:.75rem;"></i></button>'
                : '';
            var btnStyle = 'background:none;border:none;cursor:pointer;color:#64748b;padding:2px 5px;border-radius:4px;transition:color .15s;text-decoration:none;display:inline-flex;align-items:center;';
            var verBtn = d.archivo_ruta
                ? '<a href="uploads/' + escH(d.archivo_ruta) + '" target="_blank" title="Ver PDF" style="' + btnStyle + '" onmouseover="this.style.color=\'#005686\'" onmouseout="this.style.color=\'#64748b\'"><i class="fas fa-eye" style="font-size:.75rem;"></i></a>'
                : '';
            var textoBtn = d.estado === 'procesado'
                ? '<button onclick="verTextoDoc(' + d.id + ')" title="Ver texto extraído" style="' + btnStyle + '" onmouseover="this.style.color=\'#005686\'" onmouseout="this.style.color=\'#64748b\'"><i class="fas fa-align-left" style="font-size:.75rem;"></i></button>'
                : '';
            html += '<tr>'
                + '<td><strong>' + escH(d.nombre) + '</strong>'
                + (d.descripcion ? '<br><span style="color:#888;font-size:.78rem;">' + escH(d.descripcion) + '</span>' : '')
                + '</td>'
                + '<td style="text-align:center;">' + (d.paginas || '&mdash;') + '</td>'
                + '<td><span class="docref-badge ' + cl + '">' + lbl + '</span></td>'
                + '<td>' + fecha + '</td>'
                + '<td style="display:flex;gap:2px;align-items:center;">'
                + verBtn
                + textoBtn
                + rescanBtn
                + '<button onclick="eliminarDocRef(' + d.id + ')" class="btn-del-row" title="Eliminar"><i class="fas fa-trash"></i></button>'
                + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    var _pollTimer = null;

    function hayPendientes(docs) {
        return docs && docs.some(function(d){ return d.estado === 'pendiente'; });
    }

    function iniciarPoll() {
        if (_pollTimer) return;
        _pollTimer = setInterval(function(){
            var url = 'ajax/listar_documentos_ref.php?proyecto_id=' + (DOCREF_PID || '');
            fetch(url).then(function(r){ return r.json(); }).then(function(d) {
                if (!d.success) return;
                var tienePend = hayPendientes(d.global) || hayPendientes(d.proyecto);
                if (DOCREF_PID) renderDocrefTable(d.proyecto, 'tablaDocsProy');
                renderDocrefTable(d.global, 'tablaDocsGlobal');
                if (!tienePend) { clearInterval(_pollTimer); _pollTimer = null; }
            });
        }, 3000);
    }

    window.cargarDocsRef = function () {
        var url = 'ajax/listar_documentos_ref.php?proyecto_id=' + (DOCREF_PID || '');
        fetch(url).then(function(r){ return r.json(); }).then(function(d) {
            if (!d.success) return;
            if (DOCREF_PID) renderDocrefTable(d.proyecto, 'tablaDocsProy');
            renderDocrefTable(d.global, 'tablaDocsGlobal');
            if (hayPendientes(d.global) || hayPendientes(d.proyecto)) iniciarPoll();
        });
    };

    window.subirDocRef = async function (proyId, prefix) {
        var nombre  = (document.getElementById(prefix + '_nombre') || {}).value || '';
        nombre = nombre.trim();
        var desc    = (document.getElementById(prefix + '_desc')   || {}).value || '';
        var fileEl  = document.getElementById(prefix + '_archivo');
        var progEl  = document.getElementById(prefix + '_progress');
        var msgEl   = document.getElementById(prefix + '_progress_msg');

        if (!nombre)               { alert('El nombre es requerido'); return; }
        if (!fileEl || !fileEl.files.length) { alert('Selecciona un archivo PDF'); return; }

        var fd = new FormData();
        fd.append('nombre',    nombre);
        fd.append('descripcion', desc.trim());
        if (proyId) fd.append('proyecto_id', proyId);
        fd.append('archivo', fileEl.files[0]);

        progEl.classList.add('show');
        msgEl.textContent = 'Subiendo archivo\u2026';

        try {
            var r    = await fetch('ajax/subir_documento_ref.php', { method: 'POST', body: fd });
            var data = await r.json();
            if (!data.success) {
                alert('Error: ' + data.error);
                progEl.classList.remove('show');
                return;
            }

            // Limpiar formulario y mostrar el doc como Pendiente de inmediato
            progEl.classList.remove('show');
            if (document.getElementById(prefix + '_nombre')) document.getElementById(prefix + '_nombre').value = '';
            if (document.getElementById(prefix + '_desc'))   document.getElementById(prefix + '_desc').value   = '';
            fileEl.value = '';
            cargarDocsRef();

            // Extraer texto en segundo plano (sin bloquear UI)
            var fd2 = new FormData();
            fd2.append('doc_id', data.id);
            fetch('ajax/extraer_texto_doc.php', { method: 'POST', body: fd2 })
                .then(function(r2){ return r2.json(); })
                .then(function(d2){
                    cargarDocsRef(); // refrescar lista cuando termine
                })
                .catch(function(){}); // silencioso en background

        } catch (err) {
            progEl.classList.remove('show');
            alert('Error de red: ' + err.message);
        }
    };

    window.extraerTextoDoc = async function (docId, btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i>';
        var fd = new FormData();
        fd.append('doc_id', docId);
        try {
            var r = await fetch('ajax/extraer_texto_doc.php', { method: 'POST', body: fd });
            var d = await r.json();
            if (d.estado === 'procesado') alert('\u2713 Texto extraído correctamente.');
            else alert('Estado: ' + d.estado);
            cargarDocsRef();
        } catch (e) {
            alert('Error: ' + e.message);
            btn.disabled = false;
        }
    };

    window.verTextoDoc = async function (docId) {
        var modal = document.getElementById('modalVerTexto');
        document.getElementById('mvt_nombre').textContent = 'Cargando…';
        document.getElementById('mvt_meta').textContent = '';
        document.getElementById('mvt_texto').textContent = '';
        document.getElementById('mvt_truncado').style.display = 'none';
        modal.style.display = 'flex';
        try {
            var r = await fetch('ajax/ver_texto_doc.php?doc_id=' + docId);
            var d = await r.json();
            if (!d.success) { document.getElementById('mvt_texto').textContent = 'Error: ' + d.error; return; }
            document.getElementById('mvt_nombre').textContent = d.nombre;
            document.getElementById('mvt_meta').textContent = d.paginas + ' págs · ' + (d.chars_total || 0).toLocaleString() + ' chars';
            document.getElementById('mvt_texto').textContent = d.preview || '(sin texto extraído)';
            if (d.truncado) document.getElementById('mvt_truncado').style.display = 'block';
        } catch(e) {
            document.getElementById('mvt_texto').textContent = 'Error de red: ' + e.message;
        }
    };

    window.reescanearDoc = async function (docId, btn) {
        btn.style.pointerEvents = 'none';
        btn.innerHTML = '<i class="fas fa-sync-alt fa-spin" style="font-size:.75rem;"></i>';
        var fd = new FormData();
        fd.append('doc_id', docId);
        try {
            var r = await fetch('ajax/extraer_texto_doc.php', { method: 'POST', body: fd });
            var d = await r.json();
            cargarDocsRef();
        } catch (e) {
            btn.style.pointerEvents = '';
            btn.innerHTML = '<i class="fas fa-redo-alt" style="font-size:.75rem;"></i>';
        }
    };

    window.eliminarDocRef = async function (docId) {
        if (!confirm('¿Eliminar este documento? Esta acción no se puede deshacer.')) return;
        var fd = new FormData();
        fd.append('doc_id', docId);
        var r = await fetch('ajax/eliminar_documento_ref.php', { method: 'POST', body: fd });
        var d = await r.json();
        if (d.success) cargarDocsRef();
        else alert('Error: ' + d.error);
    };

    // Cargar al iniciar
    cargarDocsRef();
}());
</script>
<?php endif; // seccion === docs_referencia ?>

</body>
</html>