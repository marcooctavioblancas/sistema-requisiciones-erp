<?php
require_once 'includes/config.php';

if (!isLoggedIn()) redirect('index.php');

$db = getDB();

// Filtro opcional por BOM (?boom_id=N) -- el botón "Bloqueado por reemplazo" de
// ingenieria.php manda aquí en vez de solo mostrar el candado sin acción; se
// resuelve a los material_id de ESE BOM y se filtran las 3 listas de abajo por
// esos materiales, en vez de duplicar toda esta pantalla en un archivo aparte.
$boomIdFiltro = intval($_GET['boom_id'] ?? 0);
$boomFiltroNombre = null;
$boomFiltroProyectoId = null;
$materialesDelBomFiltro = null;
if ($boomIdFiltro) {
    $stmtBomF = $db->prepare("SELECT nombre, proyecto_id FROM boom_listas WHERE id = ?");
    $stmtBomF->execute([$boomIdFiltro]);
    $bomFRow = $stmtBomF->fetch();
    $boomFiltroNombre = $bomFRow['nombre'] ?? null;
    $boomFiltroProyectoId = $bomFRow ? (int)$bomFRow['proyecto_id'] : null;
    $stmtMatBomF = $db->prepare("SELECT DISTINCT material_id FROM boom_items WHERE boom_id = ? AND guardado = 1 AND material_id IS NOT NULL");
    $stmtMatBomF->execute([$boomIdFiltro]);
    $materialesDelBomFiltro = $stmtMatBomF->fetchAll(PDO::FETCH_COLUMN);
}
// El mismo material_id puede repetirse en BOMs de OTROS proyectos -- sin exigir
// también el proyecto_id, el filtro por BOM mezclaba solicitudes ajenas que solo
// coincidían por usar el mismo material de catálogo.
function _rmFiltrarPorBom(array $filas, ?array $materialesDelBom, ?int $proyectoId): array {
    if ($materialesDelBom === null) return $filas;
    return array_values(array_filter($filas, fn($f) =>
        in_array((int)$f['material_original_id'], $materialesDelBom, true) && (int)$f['proyecto_id'] === $proyectoId
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'resolver') {
    $solicitud_id = intval($_POST['solicitud_id'] ?? 0);
    $aprobar = ($_POST['decision'] ?? '') === 'aprobar';
    $nota = trim($_POST['nota'] ?? '');
    $material_elegido_id = !empty($_POST['material_elegido_id']) ? intval($_POST['material_elegido_id']) : null;
    $resultado = resolverReemplazoMaterial($db, $solicitud_id, $aprobar, (int)$_SESSION['user_id'], $nota, $material_elegido_id);
    $_SESSION['reemplazo_msg'] = $resultado['ok']
        ? ['tipo' => 'success', 'texto' => $aprobar ? 'Reemplazo aprobado y aplicado.' : 'Reemplazo rechazado.']
        : ['tipo' => 'error', 'texto' => $resultado['error']];
    header('Location: reemplazos_material.php');
    exit();
}

$stmt = $db->query("
    SELECT s.*, mo.descripcion AS desc_original, mo.codigo AS codigo_original,
           ma.descripcion AS desc_alternativa, ma.codigo AS codigo_alternativa,
           u.nombre_completo AS propuesto_por_nombre,
           p.nombre AS proyecto_nombre, r.folio AS requisicion_folio
    FROM material_reemplazo_solicitudes s
    LEFT JOIN materiales mo ON mo.id = s.material_original_id
    LEFT JOIN materiales ma ON ma.id = s.material_alternativa_id
    LEFT JOIN usuarios u ON u.id = s.propuesto_por
    LEFT JOIN proyectos p ON p.id = s.proyecto_id
    LEFT JOIN requisiciones r ON r.id = s.requisicion_id
    WHERE s.estado = 'pendiente'
    ORDER BY s.fecha_propuesta ASC
");
$pendientes = array_values(array_filter($stmt->fetchAll(), fn($s) => esJefeDeAlguna($s['area_responsable'])));
$pendientes = _rmFiltrarPorBom($pendientes, $materialesDelBomFiltro, $boomFiltroProyectoId);

// Cuando Almacén propuso más de un candidato (opciones_json), hay que resolver sus
// descripciones para las tarjetas de "elige cuál aplicar" -- una sola consulta por
// todos los ids de todas las solicitudes pendientes en vez de una por tarjeta.
$idsCandidatos = [];
foreach ($pendientes as $s) {
    if (!empty($s['opciones_json'])) {
        foreach ((json_decode($s['opciones_json'], true) ?: []) as $oid) $idsCandidatos[(int)$oid] = true;
    }
}
$descCandidatos = [];
if ($idsCandidatos) {
    $ph = implode(',', array_fill(0, count($idsCandidatos), '?'));
    $stmtC = $db->prepare("SELECT id, descripcion, codigo FROM materiales WHERE id IN ($ph)");
    $stmtC->execute(array_keys($idsCandidatos));
    foreach ($stmtC->fetchAll() as $m) $descCandidatos[$m['id']] = $m;
}

// area_responsable puede ser CSV ("ingenieria,manufactura") cuando un ítem lo pidieron
// entre dos áreas -- FIND_IN_SET() funciona igual para un valor solo o para una lista,
// así que basta con OR uno por cada módulo del que el usuario sea jefe.
$stmtMod = $db->prepare("SELECT modulo FROM usuario_niveles WHERE usuario_id = ? AND nivel = 'jefe'");
$stmtMod->execute([(int)$_SESSION['user_id']]);
$jefeModulos = $stmtMod->fetchAll(PDO::FETCH_COLUMN);

if (isAdmin()) {
    $condHist = '1=1';
    $paramsHist = [];
} elseif ($jefeModulos) {
    $condHist = '(' . implode(' OR ', array_fill(0, count($jefeModulos), 'FIND_IN_SET(?, s.area_responsable)')) . ')';
    $paramsHist = $jefeModulos;
} else {
    $condHist = '0=1';
    $paramsHist = [];
}

$stmt_hist = $db->prepare("
    SELECT s.*, mo.descripcion AS desc_original, ma.descripcion AS desc_alternativa,
           ur.nombre_completo AS resuelto_por_nombre,
           p.nombre AS proyecto_nombre, r.folio AS requisicion_folio
    FROM material_reemplazo_solicitudes s
    LEFT JOIN materiales mo ON mo.id = s.material_original_id
    LEFT JOIN materiales ma ON ma.id = s.material_alternativa_id
    LEFT JOIN usuarios ur ON ur.id = s.resuelto_por
    LEFT JOIN proyectos p ON p.id = s.proyecto_id
    LEFT JOIN requisiciones r ON r.id = s.requisicion_id
    WHERE s.estado != 'pendiente'
      AND $condHist
    ORDER BY s.fecha_resolucion DESC LIMIT 50
");
$stmt_hist->execute($paramsHist);
$historial = _rmFiltrarPorBom($stmt_hist->fetchAll(), $materialesDelBomFiltro, $boomFiltroProyectoId);

// Solicitudes que YO propuse, sin importar mi rol -- sin esto, quien no es
// jefe del área responsable (ej. Almacén proponiendo un reemplazo que debe
// aprobar Ingeniería) no tiene ninguna forma de corroborar que su propuesta
// quedó registrada ni ver si ya se resolvió.
$stmt_mias = $db->prepare("
    SELECT s.*, mo.descripcion AS desc_original, ma.descripcion AS desc_alternativa,
           ur.nombre_completo AS resuelto_por_nombre,
           p.nombre AS proyecto_nombre, r.folio AS requisicion_folio
    FROM material_reemplazo_solicitudes s
    LEFT JOIN materiales mo ON mo.id = s.material_original_id
    LEFT JOIN materiales ma ON ma.id = s.material_alternativa_id
    LEFT JOIN usuarios ur ON ur.id = s.resuelto_por
    LEFT JOIN proyectos p ON p.id = s.proyecto_id
    LEFT JOIN requisiciones r ON r.id = s.requisicion_id
    WHERE s.propuesto_por = ?
    ORDER BY s.fecha_propuesta DESC LIMIT 20
");
$stmt_mias->execute([(int)$_SESSION['user_id']]);
$misSolicitudes = _rmFiltrarPorBom($stmt_mias->fetchAll(), $materialesDelBomFiltro, $boomFiltroProyectoId);

$msg = $_SESSION['reemplazo_msg'] ?? null;
unset($_SESSION['reemplazo_msg']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reemplazos de Material — Aprobación</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Montserrat', sans-serif; background: #f4f7fa; color: #333; padding: 30px 20px 60px; }
        .rm-header { max-width: 960px; margin: 0 auto 24px; display: flex; align-items: center; justify-content: space-between; }
        .rm-header h1 { color: #023760; font-size: 1.5rem; }
        .rm-header h1 i { color: #BE893B; margin-right: 8px; }
        .rm-back { color: #005686; font-weight: 600; text-decoration: none; font-size: .85rem; }
        .rm-container { max-width: 960px; margin: 0 auto; }
        .rm-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; font-weight: 600; }
        .rm-alert.success { background: #d4edda; color: #155724; }
        .rm-alert.error { background: #f8d7da; color: #721c24; }
        .rm-section-title { color: #023760; font-size: 1.05rem; margin: 24px 0 12px; font-weight: 700; }
        .rm-card { background: #fff; border: 1px solid #e0e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 2px 6px rgba(0,0,0,.04); }
        .rm-origen { font-size: .78rem; font-weight: 700; color: #BE893B; margin-bottom: 8px; }
        .rm-origen i { margin-right: 5px; }
        .rm-swap { display: flex; align-items: center; gap: 10px; font-size: .95rem; margin-bottom: 8px; flex-wrap: wrap; }
        .rm-swap .mat { padding: 4px 10px; border-radius: 6px; font-weight: 600; }
        .rm-swap .original { background: #fdecea; color: #c62828; }
        .rm-swap .alterno { background: #e8f5e9; color: #2e7d32; }
        .rm-meta { font-size: .78rem; color: #666; margin-bottom: 10px; }
        .rm-meta strong { color: #444; }
        .rm-nota { font-size: .8rem; color: #555; font-style: italic; margin-bottom: 10px; }
        .rm-actions { display: flex; gap: 8px; align-items: center; }
        .rm-actions input[type=text] { flex: 1; padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: .8rem; }
        .rm-btn { border: none; padding: 7px 16px; border-radius: 6px; font-weight: 600; font-size: .8rem; cursor: pointer; }
        .rm-btn.aprobar { background: #2e7d32; color: #fff; }
        .rm-btn.rechazar { background: #c62828; color: #fff; }
        .rm-empty { text-align: center; color: #999; padding: 24px; }
        .rm-badge { display: inline-block; padding: 3px 9px; border-radius: 10px; font-size: .72rem; font-weight: 700; }
        .rm-badge.aprobado { background: #d4edda; color: #155724; }
        .rm-badge.rechazado { background: #f8d7da; color: #721c24; }
        .rm-badge.pendiente { background: #fff3cd; color: #856404; }
        table.rm-hist { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,.04); }
        table.rm-hist th, table.rm-hist td { padding: 8px 12px; text-align: left; font-size: .8rem; border-bottom: 1px solid #eee; }
        table.rm-hist th { background: #023760; color: #fff; }
    </style>
</head>
<body>
    <div class="rm-header">
        <h1><i class="fas fa-exchange-alt"></i> Reemplazos de Material</h1>
        <a href="Menu.php" class="rm-back"><i class="fas fa-arrow-left"></i> Volver al menú</a>
    </div>
    <div class="rm-container">
        <?php if ($boomIdFiltro): ?>
        <div class="rm-alert" style="background:#e3f2fd;color:#023760;display:flex;justify-content:space-between;align-items:center;">
            <span><i class="fas fa-filter"></i> Mostrando solo los reemplazos del BOM <strong><?= htmlspecialchars($boomFiltroNombre ?: "#$boomIdFiltro") ?></strong>.</span>
            <a href="reemplazos_material.php" style="color:#023760;font-weight:700;text-decoration:underline;">Ver todos</a>
        </div>
        <?php endif; ?>
        <?php if ($msg): ?>
        <div class="rm-alert <?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
        <?php endif; ?>

        <div class="rm-section-title">Pendientes de tu aprobación (<?= count($pendientes) ?>)</div>
        <?php if (empty($pendientes)): ?>
        <div class="rm-card rm-empty"><i class="fas fa-check-circle" style="color:#2e7d32;"></i> No tienes reemplazos pendientes de aprobar.</div>
        <?php else: foreach ($pendientes as $s): ?>
        <div class="rm-card">
            <div class="rm-origen">
                <i class="fas fa-folder-open"></i>
                <?php if ($s['proyecto_nombre']): ?>
                    Proyecto: <strong><?= htmlspecialchars($s['proyecto_nombre']) ?></strong>
                <?php elseif ($s['requisicion_folio']): ?>
                    Requisición <strong><?= htmlspecialchars($s['requisicion_folio']) ?></strong> (sin proyecto)
                <?php else: ?>
                    Sin proyecto ni requisición asociada
                <?php endif; ?>
            </div>
            <?php $opciones = !empty($s['opciones_json']) ? (json_decode($s['opciones_json'], true) ?: []) : null; ?>
            <div class="rm-swap">
                <span class="mat original"><?= htmlspecialchars($s['desc_original'] ?: 'Material #' . $s['material_original_id']) ?></span>
                <?php if (!$opciones): ?>
                <i class="fas fa-arrow-right" style="color:#999;"></i>
                <span class="mat alterno"><?= htmlspecialchars($s['desc_alternativa'] ?: 'Material #' . $s['material_alternativa_id']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($opciones): ?>
            <div style="font-size:.82rem;color:#555;margin-bottom:8px;">
                <div style="font-weight:600;margin-bottom:4px;">Almacén propuso <?= count($opciones) ?> candidatos -- elige cuál aplicar al aprobar:</div>
                <?php foreach ($opciones as $oid): $mc = $descCandidatos[$oid] ?? null; ?>
                <label style="display:flex;align-items:center;gap:6px;padding:3px 0;cursor:pointer;">
                    <input type="radio" name="material_elegido_id" value="<?= (int)$oid ?>" form="rm-form-<?= (int)$s['id'] ?>">
                    <span class="mat alterno"><?= htmlspecialchars(($mc['codigo'] ? '[' . $mc['codigo'] . '] ' : '') . ($mc['descripcion'] ?? "Material #$oid")) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="rm-meta">
                <strong>Cantidad:</strong> <?= (float)$s['cantidad'] + 0 ?>
                <?php if ($s['proyecto_nombre'] && $s['requisicion_folio']): ?> · <strong>Requisición:</strong> <?= htmlspecialchars($s['requisicion_folio']) ?><?php endif; ?>
                · <strong>Propuesto por:</strong> <?= htmlspecialchars($s['propuesto_por_nombre'] ?: '—') ?>
                · <?= htmlspecialchars(substr($s['fecha_propuesta'], 0, 16)) ?>
            </div>
            <?php if (!empty($s['nota_solicitud'])): ?>
            <div class="rm-nota"><i class="fas fa-comment-dots"></i> <?= htmlspecialchars($s['nota_solicitud']) ?></div>
            <?php endif; ?>
            <form method="POST" class="rm-actions" id="rm-form-<?= (int)$s['id'] ?>">
                <input type="hidden" name="accion" value="resolver">
                <input type="hidden" name="solicitud_id" value="<?= (int)$s['id'] ?>">
                <input type="text" name="nota" placeholder="Nota (opcional)">
                <button type="submit" name="decision" value="aprobar" class="rm-btn aprobar"><i class="fas fa-check"></i> Aprobar</button>
                <button type="submit" name="decision" value="rechazar" class="rm-btn rechazar"><i class="fas fa-times"></i> Rechazar</button>
            </form>
        </div>
        <?php endforeach; endif; ?>

        <?php if ($misSolicitudes): ?>
        <div class="rm-section-title">Tus solicitudes propuestas</div>
        <table class="rm-hist">
            <thead><tr><th>Proyecto / Requisición</th><th>Original</th><th>Alterno</th><th>Estado</th><th>Resuelto por</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach ($misSolicitudes as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['proyecto_nombre'] ?: ($s['requisicion_folio'] ?: '—')) ?></td>
                    <td><?= htmlspecialchars($s['desc_original'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($s['desc_alternativa'] ?: '—') ?></td>
                    <td><span class="rm-badge <?= htmlspecialchars($s['estado']) ?>"><?= htmlspecialchars(ucfirst($s['estado'])) ?></span></td>
                    <td><?= htmlspecialchars($s['resuelto_por_nombre'] ?: '—') ?></td>
                    <td><?= htmlspecialchars(substr($s['fecha_resolucion'] ?: $s['fecha_propuesta'], 0, 16)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="rm-section-title">Historial reciente</div>
        <?php if (empty($historial)): ?>
        <div class="rm-card rm-empty">Sin resoluciones todavía.</div>
        <?php else: ?>
        <table class="rm-hist">
            <thead><tr><th>Proyecto / Requisición</th><th>Original</th><th>Alterno</th><th>Estado</th><th>Resuelto por</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['proyecto_nombre'] ?: ($h['requisicion_folio'] ?: '—')) ?></td>
                    <td><?= htmlspecialchars($h['desc_original'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($h['desc_alternativa'] ?: '—') ?></td>
                    <td><span class="rm-badge <?= htmlspecialchars($h['estado']) ?>"><?= htmlspecialchars(ucfirst($h['estado'])) ?></span></td>
                    <td><?= htmlspecialchars($h['resuelto_por_nombre'] ?: '—') ?></td>
                    <td><?= htmlspecialchars(substr($h['fecha_resolucion'] ?: '', 0, 16)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>
