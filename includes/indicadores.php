<?php
/**
 * Módulo Indicadores — reporte trimestral de KPIs (formato DOC-GEN-FO-15).
 * Compartido por compras.php, ingenieria.php, produccion.php y proyecto.php.
 * Áreas de tipo "tiempo": % = días reales ÷ días programados × 100 (criterio Tiempo ≤ 100%).
 */

const INDICADORES_CONFIG = [
    'compras' => [
        'nombre_area'       => 'Compras',
        'acceso'            => 'compras',
        'nombre_indicador'  => 'Entregas a tiempo de productos',
        'criterio'          => 'Tiempo ≤ 100%',
        'label_real'        => 'Fecha real de entrega "proveedor estratégico"',
        'label_programada'  => 'Fecha programada de entrega "proveedor estratégico"',
        'label_referencia'  => 'Fecha de la Orden de Compra',
        'label_etiqueta'    => 'Orden de Compra / Proveedor',
        'color'             => '#005686',
    ],
    'ingenieria' => [
        'nombre_area'       => 'Ingeniería',
        'acceso'            => 'ingenieria',
        'nombre_indicador'  => 'Entregas a tiempo de dibujos (ingeniería)',
        'criterio'          => 'Tiempo ≤ 100%',
        'label_real'        => 'Fecha real de entrega de la ingeniería (1ª emisión)',
        'label_programada'  => 'Fecha programada de entrega de la ingeniería (1ª emisión)',
        'label_referencia'  => 'Fecha inicio del proyecto (notificación de ventas)',
        'label_etiqueta'    => 'Proyecto',
        'color'             => '#023760',
    ],
    'produccion' => [
        'nombre_area'       => 'Producción',
        'acceso'            => 'manufactura',
        'nombre_indicador'  => 'Sistema de actuación, producto y/o servicio en planta',
        'criterio'          => 'Tiempo ≤ 100%',
        'label_real'        => 'Fecha real de realización de pruebas con el cliente (FAT)',
        'label_programada'  => 'Fecha programada de entrega del producto o servicio',
        'label_referencia'  => 'Fecha de orden de producción',
        'label_etiqueta'    => 'Proyecto / Orden de Producción',
        'color'             => '#2E7D32',
    ],
    'proyectos' => [
        'nombre_area'       => 'Proyectos',
        'acceso'            => 'proyectos',
        'nombre_indicador'  => 'Entregas a tiempo de proyectos',
        'criterio'          => 'Tiempo ≤ 100%',
        'label_real'        => 'Fecha real de realización de pruebas con el cliente (FAT)',
        'label_programada'  => 'Fecha programada de entrega del proyecto',
        'label_referencia'  => 'Fecha inicio del proyecto (notificación de ventas)',
        'label_etiqueta'    => 'Proyecto',
        'color'             => '#7B3F00',
    ],
];

/** Valida que el usuario tenga acceso al área del reporte (o sea admin). */
function ind_valida_acceso(string $area): bool {
    if (isAdmin()) return true;
    $cfg = INDICADORES_CONFIG[$area] ?? null;
    return $cfg && hasAcceso($cfg['acceso']);
}

/** (días_reales, días_programados, porcentaje) a partir de 3 fechas. */
function calcularPorcentajeTiempo(?string $fechaReal, ?string $fechaProgramada, ?string $fechaReferencia): array {
    if (!$fechaReal || !$fechaProgramada || !$fechaReferencia) return [null, null, null];
    $diasReales      = (int) round((strtotime($fechaReal) - strtotime($fechaReferencia)) / 86400);
    $diasProgramados = (int) round((strtotime($fechaProgramada) - strtotime($fechaReferencia)) / 86400);
    if ($diasProgramados === 0) return [$diasReales, $diasProgramados, null];
    $porcentaje = round($diasReales / $diasProgramados * 100, 2);
    return [$diasReales, $diasProgramados, $porcentaje];
}

/** Promedio de los % ya calculados de las entradas de un reporte. */
function recalcularPromedioReporte(PDO $db, int $reporteId): ?float {
    $stmt = $db->prepare("SELECT porcentaje FROM indicadores_entradas WHERE reporte_id = ? AND porcentaje IS NOT NULL");
    $stmt->execute([$reporteId]);
    $vals = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($vals)) return null;
    return round(array_sum($vals) / count($vals), 2);
}

/** Lista de proyectos/órdenes existentes para elegir como referencia rápida (etiqueta + fechas). */
function ind_obtener_opciones_referencia(PDO $db, string $area): array {
    if ($area === 'compras') {
        $stmt = $db->query("SELECT oc.id, oc.folio, p.nombre AS proveedor_nombre
                             FROM ordenes_compra oc
                             LEFT JOIN proveedores p ON p.id = oc.proveedor_id
                             ORDER BY oc.fecha_creacion DESC LIMIT 200");
        return array_map(fn($r) => ['id' => $r['id'], 'label' => $r['folio'] . ($r['proveedor_nombre'] ? ' — ' . $r['proveedor_nombre'] : '')], $stmt->fetchAll());
    }
    // ingenieria, produccion, proyectos: todas parten del catálogo de proyectos
    $stmt = $db->query("SELECT id, nombre FROM proyectos WHERE tipo = 'ingenieria' ORDER BY fecha_creacion DESC LIMIT 200");
    return array_map(fn($r) => ['id' => $r['id'], 'label' => $r['nombre']], $stmt->fetchAll());
}

/**
 * Fechas reales del sistema para una referencia elegida, por área — evita capturar a mano
 * lo que la aplicación ya sabe. Devuelve null en los campos que no se puedan derivar
 * (el usuario los completa a mano en ese caso).
 */
function ind_obtener_fechas_referencia(PDO $db, string $area, int $refId): array {
    $out = ['fecha_referencia' => null, 'fecha_real' => null, 'fecha_programada' => null];

    if ($area === 'compras') {
        $stmt = $db->prepare("SELECT fecha_oc, fecha_entrega_estimada FROM ordenes_compra WHERE id = ?");
        $stmt->execute([$refId]);
        if ($r = $stmt->fetch()) {
            $out['fecha_referencia'] = $r['fecha_oc'];
            $out['fecha_programada'] = $r['fecha_entrega_estimada'];
        }
        $stmtH = $db->prepare("SELECT MIN(fecha_movimiento) FROM historial_movimientos WHERE referencia_id = ? AND referencia_tipo = 'orden_compra'");
        $stmtH->execute([$refId]);
        $fr = $stmtH->fetchColumn();
        if ($fr) $out['fecha_real'] = substr($fr, 0, 10);
        return $out;
    }

    if ($area === 'ingenieria') {
        $stmt = $db->prepare("SELECT fecha_inicio FROM proyectos WHERE id = ?");
        $stmt->execute([$refId]);
        $out['fecha_referencia'] = $stmt->fetchColumn() ?: null;
        $stmtF = $db->prepare("SELECT fecha_fin_plan, fecha_fin_real FROM proyecto_fases WHERE proyecto_id = ? AND fase = 'ingenieria' LIMIT 1");
        $stmtF->execute([$refId]);
        if ($f = $stmtF->fetch()) {
            $out['fecha_programada'] = $f['fecha_fin_plan'];
            $out['fecha_real']       = $f['fecha_fin_real'];
        }
        return $out;
    }

    if ($area === 'produccion') {
        $stmt = $db->prepare("SELECT fecha_inicio_mfg FROM proyectos WHERE id = ?");
        $stmt->execute([$refId]);
        $out['fecha_referencia'] = $stmt->fetchColumn() ?: null;
        $stmtOP = $db->prepare("SELECT fecha FROM ordenes_produccion WHERE proyecto_id = ? ORDER BY fecha_creacion DESC LIMIT 1");
        $stmtOP->execute([$refId]);
        $fop = $stmtOP->fetchColumn();
        if ($fop) $out['fecha_referencia'] = $fop;
        $stmtFat = $db->prepare("SELECT fecha_programada, fecha_realizada FROM pruebas_fat WHERE proyecto_id = ? ORDER BY fecha_creacion DESC LIMIT 1");
        $stmtFat->execute([$refId]);
        if ($f = $stmtFat->fetch()) {
            $out['fecha_programada'] = $f['fecha_programada'];
            $out['fecha_real']       = $f['fecha_realizada'];
        }
        return $out;
    }

    if ($area === 'proyectos') {
        $stmt = $db->prepare("SELECT fecha_inicio, fecha_finiquito FROM proyectos WHERE id = ?");
        $stmt->execute([$refId]);
        if ($r = $stmt->fetch()) {
            $out['fecha_referencia'] = $r['fecha_inicio'];
            $out['fecha_programada'] = $r['fecha_finiquito'];
        }
        $stmtFat = $db->prepare("SELECT fecha_realizada FROM pruebas_fat WHERE proyecto_id = ? ORDER BY fecha_creacion DESC LIMIT 1");
        $stmtFat->execute([$refId]);
        $out['fecha_real'] = $stmtFat->fetchColumn() ?: null;
        return $out;
    }

    return $out;
}

/** Tarjeta de navegación "Indicadores" — mismo patrón .nav-card usado en todas las páginas host. */
function renderIndicadoresNavCard(string $urlBase): void {
    ?>
    <div class="nav-card" onclick="location.href='<?= htmlspecialchars($urlBase) ?>?seccion=indicadores'">
        <div class="card-icon"><i class="fas fa-chart-line"></i></div>
        <div class="card-title">Indicadores</div>
        <div class="card-desc">Reporte trimestral de KPIs</div>
    </div>
    <?php
}

function ind_css(): void {
    ?>
    <style>
    .ind-wrap { max-width: 1150px; margin: 0 auto; padding-top: 4px; font-size: 15px; }
    .ind-list { display:flex; flex-direction:column; gap:10px; }
    .ind-row { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; background:#fff; border:1.5px solid #e0e8f0; border-radius:10px; padding:16px 20px; cursor:pointer; transition:all .15s; }
    .ind-row:hover { border-color:#005686; box-shadow:0 2px 8px rgba(0,61,92,.08); }
    .ind-row-titulo { font-weight:700; color:#003d5c; font-size:1.05rem; }
    .ind-row-meta { font-size:.85rem; color:#888; margin-top:3px; }
    .ind-badge { font-size:.8rem; font-weight:800; padding:5px 14px; border-radius:20px; white-space:nowrap; }
    .ind-badge.borrador { background:#fff8e1; color:#f57f17; }
    .ind-badge.cumple { background:#e8f5e9; color:#2e7d32; }
    .ind-badge.nocumple { background:#ffebee; color:#b71c1c; }
    .ind-empty { text-align:center; color:#aaa; padding:50px 20px; font-style:italic; font-size:.95rem; }
    .ind-card { background:#fff; border:1px solid #e0e8f0; border-radius:12px; padding:20px 24px; margin-bottom:16px; }
    .ind-card h3 { font-size:1.05rem; color:#003d5c; margin:0 0 14px; }
    .ind-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
    .ind-form-grid label { display:block; font-size:.82rem; font-weight:700; color:#555; margin-bottom:5px; }
    .ind-form-grid input, .ind-form-grid textarea { width:100%; border:1.5px solid #ddd; border-radius:6px; padding:8px 10px; font-family:inherit; font-size:.92rem; }
    .ind-table { width:100%; border-collapse:collapse; font-size:.88rem; }
    .ind-table th { background:#f4f7fb; color:#003d5c; text-align:left; padding:9px 12px; border-bottom:1.5px solid #e0e8f0; }
    .ind-table td { padding:9px 12px; border-bottom:1px solid #f0f0f0; }
    .ind-resultado { display:flex; flex-wrap:wrap; gap:24px; align-items:center; background:#f4f7fb; border-radius:10px; padding:18px 22px; margin-top:14px; }
    .ind-resultado .valor { font-size:1.5rem; font-weight:800; }
    .ind-pie { border-top:1px solid #333; margin-top:20px; font-family:Arial,sans-serif; padding-top:6px; }
    .ind-pie .tb-fija { text-align:left; padding:2px 4px; font-size:11px; }
    .ind-pie .disclaimer { text-align:left; padding:2px 4px; font-size:11px; color:#333; }
    .ind-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:5000; align-items:center; justify-content:center; }
    .ind-modal-overlay.active { display:flex; }
    .ind-modal { background:#fff; border-radius:10px; width:min(440px,92vw); overflow:hidden; box-shadow:0 8px 40px rgba(0,0,0,.25); }
    .ind-modal-header { padding:14px 18px; color:#fff; font-weight:700; font-size:1rem; display:flex; justify-content:space-between; align-items:center; }
    .ind-modal-header button { background:none; border:none; color:#fff; font-size:1.2rem; cursor:pointer; }
    .ind-modal-body { padding:18px; font-size:.92rem; color:#444; }
    .ind-modal-footer { padding:14px 18px; display:flex; justify-content:flex-end; gap:8px; border-top:1px solid #eee; }
    .ind-btn-icon { background:none; border:none; cursor:pointer; padding:4px 6px; border-radius:5px; font-size:.9rem; transition:background .15s; }
    .ind-btn-icon:hover { background:#fce4ec; }
    .ind-btn-danger { background:#b71c1c; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-size:.85rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-family:inherit; }
    .ind-btn-danger:hover { background:#911515; }
    </style>
    <?php
}

function renderIndicadoresSeccion(PDO $db, string $area, string $urlBase): void {
    $cfg = INDICADORES_CONFIG[$area] ?? null;
    if (!$cfg) { echo '<div class="alert alert-error">Área de indicadores no configurada.</div>'; return; }

    ind_css();

    $reporte_id = intval($_GET['reporte_id'] ?? 0);
    if ($reporte_id) {
        ind_render_detalle($db, $area, $cfg, $reporte_id, $urlBase);
    } else {
        ind_render_lista($db, $area, $cfg, $urlBase);
    }
}

function ind_render_lista(PDO $db, string $area, array $cfg, string $urlBase): void {
    $stmt = $db->prepare("SELECT * FROM indicadores_reportes WHERE area = ? ORDER BY anio DESC, trimestre DESC");
    $stmt->execute([$area]);
    $reportes = $stmt->fetchAll();

    $mesActual   = (int) date('n');
    $trimActual  = (int) ceil($mesActual / 3);
    $anioActual  = (int) date('Y');
    $urlBaseAbs  = rtrim(SITE_URL, '/') . '/' . ltrim($urlBase, '/');

    // Aviso de periodo por finalizar (2026-09-21, pedido explícito del usuario) -- último día
    // del trimestre actual según su mes de cierre (Mar/Jun/Sep/Dic), y si ya existe un reporte
    // 'finalizado' (no borrador) para ESE trimestre/año -- si ya se evaluó, no hace falta avisar.
    $mesFinTrim   = [1 => 3, 2 => 6, 3 => 9, 4 => 12][$trimActual];
    $finTrimestre = date('Y-m-t', strtotime("$anioActual-$mesFinTrim-01"));
    $diasParaFin  = (int) ceil((strtotime($finTrimestre) - strtotime(date('Y-m-d'))) / 86400);
    $yaFinalizadoTrimActual = false;
    foreach ($reportes as $r) {
        if ((int)$r['trimestre'] === $trimActual && (int)$r['anio'] === $anioActual && $r['estado'] === 'finalizado') {
            $yaFinalizadoTrimActual = true;
            break;
        }
    }
    ?>
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-chart-line" style="color:<?= $cfg['color'] ?>;"></i> Indicadores — <?= htmlspecialchars($cfg['nombre_area']) ?></h2>
            <div style="display:flex;gap:8px;">
                <a href="<?= htmlspecialchars($urlBase) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                <button class="btn btn-primary btn-sm" onclick="abrirModalNuevoIndicador()"><i class="fas fa-plus"></i> Nuevo Reporte</button>
            </div>
        </div>
    </div>
    <div class="ind-wrap">
        <?php if ($diasParaFin >= 0 && $diasParaFin <= 15 && !$yaFinalizadoTrimActual): ?>
        <div style="background:#fff3e0;border:1.5px solid #ffb74d;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;color:#e65100;">
            <i class="fas fa-triangle-exclamation" style="font-size:1.4rem;flex-shrink:0;"></i>
            <div>
                <strong>El periodo de evaluación (Trimestre <?= $trimActual ?> — <?= $anioActual ?>) está por finalizar</strong><br>
                <span style="font-size:.85rem;">Quedan <?= $diasParaFin ?> día<?= $diasParaFin === 1 ? '' : 's' ?> (cierra el <?= date('d/m/Y', strtotime($finTrimestre)) ?>) y todavía no hay un reporte finalizado para este trimestre.</span>
            </div>
        </div>
        <?php endif; ?>
        <?php if (empty($reportes)): ?>
        <div class="ind-empty"><i class="fas fa-chart-line" style="font-size:2rem;display:block;margin-bottom:8px;"></i>Aún no hay reportes de indicadores para esta área.</div>
        <?php else: ?>
        <div class="ind-list">
            <?php foreach ($reportes as $r): ?>
            <div class="ind-row" onclick="location.href='?seccion=indicadores&reporte_id=<?= $r['id'] ?>'">
                <div>
                    <div class="ind-row-titulo">Trimestre <?= (int)$r['trimestre'] ?> — <?= (int)$r['anio'] ?></div>
                    <div class="ind-row-meta"><?= $r['representante_nombre'] ? htmlspecialchars($r['representante_nombre']) : 'Sin representante asignado' ?></div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if ($r['estado'] === 'borrador'): ?>
                    <span class="ind-badge borrador"><i class="fas fa-pencil-alt"></i> Borrador</span>
                    <?php else: ?>
                    <span class="ind-badge <?= $r['cumple'] ? 'cumple' : 'nocumple' ?>">
                        <i class="fas <?= $r['cumple'] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                        <span<?= $r['porcentaje_obtenido'] !== null && $r['porcentaje_obtenido'] > 100 ? ' style="color:#b71c1c;font-weight:800;"' : '' ?>><?= $r['porcentaje_obtenido'] !== null ? number_format($r['porcentaje_obtenido'],2).'%' : '—' ?></span>
                        — <?= $r['cumple'] ? 'Cumple' : 'No cumple' ?>
                    </span>
                    <?php endif; ?>
                    <button type="button" class="ind-btn-icon" title="Eliminar reporte"
                            onclick="event.stopPropagation(); abrirModalEliminarIndicador(<?= (int)$r['id'] ?>, 'Trimestre <?= (int)$r['trimestre'] ?> — <?= (int)$r['anio'] ?>')">
                        <i class="fas fa-trash" style="color:#b71c1c;"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal: Eliminar Reporte -->
    <div class="ind-modal-overlay" id="indModalEliminar">
        <div class="ind-modal">
            <div class="ind-modal-header" style="background:#b71c1c;">
                <span><i class="fas fa-trash"></i> Eliminar Reporte</span>
                <button onclick="cerrarIndModal('indModalEliminar')">&times;</button>
            </div>
            <div class="ind-modal-body">
                <p>¿Eliminar el reporte <strong id="indEliminarNombre"></strong>? Se borrarán también todas sus entradas. Esta acción no se puede deshacer.</p>
            </div>
            <div class="ind-modal-footer">
                <button type="button" class="btn btn-outline" onclick="cerrarIndModal('indModalEliminar')">Cancelar</button>
                <form method="POST" action="api/indicadores.php" id="indFormEliminar">
                    <input type="hidden" name="accion" value="eliminar_reporte">
                    <input type="hidden" name="reporte_id" id="indEliminarId" value="">
                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($urlBaseAbs) ?>?seccion=indicadores">
                    <button type="submit" class="ind-btn-danger"><i class="fas fa-trash"></i> Eliminar</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function abrirModalEliminarIndicador(id, nombre) {
        document.getElementById('indEliminarId').value = id;
        document.getElementById('indEliminarNombre').textContent = nombre;
        document.getElementById('indModalEliminar').classList.add('active');
    }
    </script>

    <!-- Modal: Nuevo Reporte -->
    <div class="ind-modal-overlay" id="indModalNuevo">
        <div class="ind-modal">
            <div class="ind-modal-header" style="background:<?= $cfg['color'] ?>;">
                <span><i class="fas fa-plus"></i> Nuevo Reporte de Indicadores</span>
                <button onclick="cerrarIndModal('indModalNuevo')">&times;</button>
            </div>
            <div class="ind-modal-body">
                <form method="POST" action="api/indicadores.php" id="indFormNuevo">
                    <input type="hidden" name="accion" value="crear_reporte">
                    <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($urlBaseAbs) ?>?seccion=indicadores">
                    <div class="ind-form-grid">
                        <div>
                            <label>Trimestre</label>
                            <select name="trimestre" class="form-input">
                                <?php for ($t = 1; $t <= 4; $t++): ?>
                                <option value="<?= $t ?>" <?= $t === $trimActual ? 'selected' : '' ?>>Trimestre <?= $t ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label>Año</label>
                            <input type="number" name="anio" value="<?= $anioActual ?>" min="2020" max="2100">
                        </div>
                    </div>
                    <p style="font-size:.75rem;color:#888;margin-top:8px;">Solo puede existir un reporte por área/trimestre/año.</p>
                </form>
            </div>
            <div class="ind-modal-footer">
                <button type="button" class="btn btn-outline" onclick="cerrarIndModal('indModalNuevo')">Cancelar</button>
                <button type="submit" form="indFormNuevo" class="btn btn-primary"><i class="fas fa-check"></i> Crear</button>
            </div>
        </div>
    </div>

    <script>
    function abrirModalNuevoIndicador() { document.getElementById('indModalNuevo').classList.add('active'); }
    function cerrarIndModal(id) { document.getElementById(id).classList.remove('active'); }
    </script>
    <?php
}

function ind_render_detalle(PDO $db, string $area, array $cfg, int $reporteId, string $urlBase): void {
    $stmt = $db->prepare("SELECT * FROM indicadores_reportes WHERE id = ? AND area = ?");
    $stmt->execute([$reporteId, $area]);
    $reporte = $stmt->fetch();
    if (!$reporte) { echo '<div class="alert alert-error">Reporte no encontrado.</div>'; return; }

    $stmtE = $db->prepare("SELECT * FROM indicadores_entradas WHERE reporte_id = ? ORDER BY orden ASC, id ASC");
    $stmtE->execute([$reporteId]);
    $entradas = $stmtE->fetchAll();

    $editable    = ($reporte['estado'] === 'borrador');

    // Respaldo para reportes que quedaron sin estos datos (creados antes de que se
    // auto-llenaran, o nunca guardados): se muestran como valor por defecto en el input,
    // no se pisa lo que el usuario ya haya escrito.
    if (empty($reporte['representante_nombre'])) {
        $stmtU = $db->prepare("SELECT nombre_completo FROM usuarios WHERE id = ?");
        $stmtU->execute([$_SESSION['user_id'] ?? 0]);
        $reporte['representante_nombre'] = $stmtU->fetchColumn() ?: '';
    }
    if (empty($reporte['representante_cargo']))  $reporte['representante_cargo']  = $cfg['nombre_area'];
    if (empty($reporte['fecha_reporte']))         $reporte['fecha_reporte']        = date('Y-m-d');
    if ($reporte['porcentaje_requerido'] === null) $reporte['porcentaje_requerido'] = 100.00;

    $urlBaseAbs  = rtrim(SITE_URL, '/') . '/' . ltrim($urlBase, '/');
    $volverUrl   = htmlspecialchars($urlBase) . '?seccion=indicadores';
    $accionUrl   = 'api/indicadores.php';
    // Absoluta: api/indicadores.php redirige de vuelta con header Location, que resuelve
    // rutas relativas contra SU PROPIO directorio (/api/), no contra la página que la mandó.
    $returnField = htmlspecialchars($urlBaseAbs) . '?seccion=indicadores&reporte_id=' . $reporteId;
    ?>
    <div class="sticky-container">
        <div class="header-row">
            <h2><i class="fas fa-chart-line" style="color:<?= $cfg['color'] ?>;"></i> <?= htmlspecialchars($cfg['nombre_area']) ?> — Trimestre <?= (int)$reporte['trimestre'] ?> / <?= (int)$reporte['anio'] ?></h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="<?= $volverUrl ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                <?php if (!$editable): ?>
                <a class="btn btn-outline btn-sm" href="api/exportar_docx_indicador.php?reporte_id=<?= $reporteId ?>" target="_blank"><i class="fas fa-file-word"></i> Descargar Word</a>
                <button class="btn btn-sm" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;" onclick="abrirIndModalConfirm('reabrir_reporte','¿Reabrir este reporte para editarlo?','Reabrir Reporte')"><i class="fas fa-unlock"></i> Reabrir</button>
                <?php else: ?>
                <button class="btn btn-success btn-sm" onclick="abrirIndModalConfirm('finalizar_reporte','¿Finalizar el reporte? Se calculará el resultado final y quedará bloqueado para edición.','Finalizar Reporte')"><i class="fas fa-check-circle"></i> Finalizar Reporte</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="ind-wrap">
        <!-- Encabezado -->
        <div class="ind-card">
            <h3><i class="fas fa-id-card"></i> Datos del reporte</h3>
            <form method="POST" action="<?= $accionUrl ?>" id="indFormHeader">
                <input type="hidden" name="accion" value="actualizar_header">
                <input type="hidden" name="reporte_id" value="<?= $reporteId ?>">
                <input type="hidden" name="return_url" value="<?= $returnField ?>">
                <div class="ind-form-grid">
                    <div>
                        <label>Fecha del reporte</label>
                        <input type="date" name="fecha_reporte" value="<?= htmlspecialchars($reporte['fecha_reporte'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>>
                    </div>
                    <div>
                        <label>Nombre del representante</label>
                        <input type="text" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" disabled title="Se toma del perfil de quien está viendo el reporte, no es editable.">
                    </div>
                    <div>
                        <label>Cargo</label>
                        <input type="text" value="<?= htmlspecialchars($cfg['nombre_area']) ?>" disabled title="Se toma del área del indicador, no es editable.">
                    </div>
                    <div>
                        <label>% requerido para el área</label>
                        <input type="number" step="0.01" name="porcentaje_requerido" value="<?= htmlspecialchars($reporte['porcentaje_requerido'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>>
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <label style="display:block;font-size:.75rem;font-weight:700;color:#555;margin-bottom:3px;">Notas / aclaraciones</label>
                    <textarea name="notas" rows="2" <?= $editable ? '' : 'disabled' ?>><?= htmlspecialchars($reporte['notas'] ?? '') ?></textarea>
                </div>
                <?php if ($editable): ?>
                <div style="margin-top:10px;text-align:right;">
                    <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-save"></i> Guardar datos</button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Fórmula / sustitución de términos -->
        <div class="ind-card">
            <h3><i class="fas fa-calculator"></i> Sustitución de términos por valores</h3>
            <p style="font-size:.78rem;color:#777;margin-bottom:10px;">
                <strong><?= htmlspecialchars($cfg['nombre_indicador']) ?></strong> — Indicador: <?= htmlspecialchars($cfg['criterio']) ?><br>
                % por entrada = (<?= htmlspecialchars($cfg['label_real']) ?> − <?= htmlspecialchars($cfg['label_referencia']) ?>) ÷ (<?= htmlspecialchars($cfg['label_programada']) ?> − <?= htmlspecialchars($cfg['label_referencia']) ?>) × 100
            </p>
            <div style="overflow-x:auto;">
            <table class="ind-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($cfg['label_etiqueta']) ?></th>
                        <th><?= htmlspecialchars($cfg['label_referencia']) ?></th>
                        <th><?= htmlspecialchars($cfg['label_real']) ?></th>
                        <th><?= htmlspecialchars($cfg['label_programada']) ?></th>
                        <th style="text-align:center;">Días reales</th>
                        <th style="text-align:center;">Días programados</th>
                        <th style="text-align:center;">%</th>
                        <th>Nota</th>
                        <?php if ($editable): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($entradas)): ?>
                    <tr><td colspan="9" style="text-align:center;color:#aaa;padding:16px;">Sin entradas aún</td></tr>
                <?php else: foreach ($entradas as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars($e['etiqueta'] ?? '') ?></td>
                        <td><?= htmlspecialchars($e['fecha_referencia'] ?? '') ?></td>
                        <td><?= htmlspecialchars($e['fecha_real'] ?? '') ?></td>
                        <td><?= htmlspecialchars($e['fecha_programada'] ?? '') ?></td>
                        <td style="text-align:center;"><?= $e['dias_reales'] !== null ? $e['dias_reales'] : '—' ?></td>
                        <td style="text-align:center;"><?= $e['dias_programados'] !== null ? $e['dias_programados'] : '—' ?></td>
                        <td style="text-align:center;font-weight:700;<?= $e['porcentaje'] !== null && $e['porcentaje'] > 100 ? 'color:#b71c1c;' : '' ?>"><?= $e['porcentaje'] !== null ? number_format($e['porcentaje'],2).'%' : '—' ?></td>
                        <td><?= htmlspecialchars($e['nota'] ?? '') ?></td>
                        <?php if ($editable): ?>
                        <td>
                            <form method="POST" action="<?= $accionUrl ?>" style="display:inline;" onsubmit="return confirm('¿Eliminar esta entrada?');">
                                <input type="hidden" name="accion" value="eliminar_entrada">
                                <input type="hidden" name="entrada_id" value="<?= $e['id'] ?>">
                                <input type="hidden" name="reporte_id" value="<?= $reporteId ?>">
                                <input type="hidden" name="return_url" value="<?= $returnField ?>">
                                <button type="submit" class="ind-btn-icon" title="Eliminar"><i class="fas fa-trash" style="color:#b71c1c;"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($editable): ?>
            <form method="POST" action="<?= $accionUrl ?>" class="ind-form-grid" style="margin-top:14px;border-top:1px dashed #ddd;padding-top:12px;">
                <input type="hidden" name="accion" value="agregar_entrada">
                <input type="hidden" name="reporte_id" value="<?= $reporteId ?>">
                <input type="hidden" name="return_url" value="<?= $returnField ?>">
                <div style="grid-column:1/-1;">
                    <label><i class="fas fa-magic"></i> Elegir de la lista (autocompleta las fechas)</label>
                    <select id="indEtiquetaSelect" onchange="indSeleccionarReferencia(this)">
                        <option value="">— Selecciona un existente, o escribe abajo a mano —</option>
                        <?php foreach (ind_obtener_opciones_referencia($db, $area) as $op): ?>
                        <option value="<?= (int)$op['id'] ?>"><?= htmlspecialchars($op['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= htmlspecialchars($cfg['label_etiqueta']) ?></label>
                    <input type="text" name="etiqueta" id="indEtiquetaInput" placeholder="Ej. T-416" required>
                </div>
                <div>
                    <label><?= htmlspecialchars($cfg['label_referencia']) ?></label>
                    <input type="date" name="fecha_referencia" id="indFechaReferencia" required>
                </div>
                <div>
                    <label><?= htmlspecialchars($cfg['label_real']) ?></label>
                    <input type="date" name="fecha_real" id="indFechaReal" required>
                </div>
                <div>
                    <label><?= htmlspecialchars($cfg['label_programada']) ?></label>
                    <input type="date" name="fecha_programada" id="indFechaProgramada" required>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Nota (opcional)</label>
                    <input type="text" name="nota" placeholder="Ej. El cliente retrasó el proyecto">
                </div>
                <div style="grid-column:1/-1;text-align:right;">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Agregar entrada</button>
                </div>
            </form>
            <script>
            function indSeleccionarReferencia(select) {
                const id = select.value;
                if (!id) return;
                document.getElementById('indEtiquetaInput').value = select.options[select.selectedIndex].text;
                fetch('api/indicadores.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'accion=obtener_fechas&area=<?= urlencode($area) ?>&ref_id=' + encodeURIComponent(id)
                })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) return;
                    if (d.fecha_referencia) document.getElementById('indFechaReferencia').value = d.fecha_referencia;
                    if (d.fecha_real)       document.getElementById('indFechaReal').value = d.fecha_real;
                    if (d.fecha_programada) document.getElementById('indFechaProgramada').value = d.fecha_programada;
                });
            }
            </script>
            <?php endif; ?>
        </div>

        <!-- Resultado -->
        <div class="ind-resultado">
            <div>
                <div style="font-size:.75rem;color:#777;">% obtenido (promedio)</div>
                <?php
                    $ind_pct_mostrado = $reporte['porcentaje_obtenido'] !== null ? $reporte['porcentaje_obtenido'] : recalcularPromedioReporte($db, $reporteId);
                    $ind_pct_color = ($ind_pct_mostrado !== null && $ind_pct_mostrado > 100) ? '#b71c1c' : $cfg['color'];
                ?>
                <div class="valor" style="color:<?= $ind_pct_color ?>;"><?= $reporte['porcentaje_obtenido'] !== null ? number_format($reporte['porcentaje_obtenido'],2).'%' : ($ind_pct_mostrado !== null ? number_format($ind_pct_mostrado,2).'% (preliminar)' : '—') ?></div>
            </div>
            <div>
                <div style="font-size:.75rem;color:#777;">% requerido</div>
                <div class="valor"><?= $reporte['porcentaje_requerido'] !== null ? number_format($reporte['porcentaje_requerido'],2).'%' : '—' ?></div>
            </div>
            <?php if (!$editable): ?>
            <div>
                <div style="font-size:.75rem;color:#777;">Resultado</div>
                <div class="valor" style="color:<?= $reporte['cumple'] ? '#2e7d32' : '#b71c1c' ?>;">
                    <i class="fas <?= $reporte['cumple'] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                    <?= $reporte['cumple'] ? 'Cumple' : 'No cumple' ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pie ISA — fijo, sin excepción (DOC-GEN-FO-15, Revisión N° 5, 18/Sep/21) -->
        <div class="ind-pie">
            <div class="tb-fija">DOC-GEN-FO-15; Revisión N&deg; 5; Fecha: 18 / Sep / 21; Página: 1</div>
            <div class="disclaimer">Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización</div>
        </div>
    </div>

    <!-- Modal: Confirmar acción (finalizar / reabrir) -->
    <div class="ind-modal-overlay" id="indModalConfirm">
        <div class="ind-modal">
            <div class="ind-modal-header" style="background:<?= $cfg['color'] ?>;">
                <span id="indModalConfirmTitulo">Confirmar</span>
                <button onclick="cerrarIndModal('indModalConfirm')">&times;</button>
            </div>
            <div class="ind-modal-body" id="indModalConfirmMsg"></div>
            <div class="ind-modal-footer">
                <button type="button" class="btn btn-outline" onclick="cerrarIndModal('indModalConfirm')">Cancelar</button>
                <form method="POST" action="<?= $accionUrl ?>" id="indFormConfirm">
                    <input type="hidden" name="accion" id="indFormConfirmAccion" value="">
                    <input type="hidden" name="reporte_id" value="<?= $reporteId ?>">
                    <input type="hidden" name="return_url" value="<?= $returnField ?>">
                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirmar</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function cerrarIndModal(id) { document.getElementById(id).classList.remove('active'); }
    function abrirIndModalConfirm(accion, mensaje, titulo) {
        document.getElementById('indModalConfirmTitulo').textContent = titulo;
        document.getElementById('indModalConfirmMsg').textContent = mensaje;
        document.getElementById('indFormConfirmAccion').value = accion;
        document.getElementById('indModalConfirm').classList.add('active');
    }
    </script>
    <?php
}
