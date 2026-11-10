<?php
/**
 * Módulo Equipos Fabricados — registro del equipo final (con su número de serie/TAG) y
 * vínculo con las series de sus componentes/sub-ensambles (páginas de plano fabricadas).
 * Compartido por ingenieria.php (rolActor='ingenieria': define cuántos equipos van y les
 * asigna número de serie automático + ID/TAG editable -- ahí termina su trabajo, corre ANTES
 * de que existan OTs), produccion.php (rolActor='produccion': solo puede vincular componentes
 * y marcar equipos como terminados, bloqueado hasta que proyectoListoParaEquipos() sea true)
 * y proyecto.php (rolActor='proyectos': solo lectura + botón de cierre total).
 */

/**
 * Genera (nunca borra ni reduce) los equipos_fabricados que le falten al proyecto,
 * agrupando TODAS sus BOMs principales guardadas por el nombre exacto de su campo
 * "Equipo:" (boom_listas.tipo_valvula) y sumando su num_equipos -- dos BOMs con el
 * MISMO nombre de equipo (mismo texto exacto, por eso el datalist en el campo) son
 * más unidades del mismo equipo, no equipos distintos. Sin nombre (tipo_valvula vacío)
 * se agrupan aparte como un solo lote sin nombre, mismo criterio que antes.
 * Se llama automáticamente al guardar cualquier BOM principal -- no requiere que
 * alguien entre a Equipos Fabricados a darle "Generar" a mano.
 */
function sincronizarEquiposFabricados(PDO $db, int $proyectoId, int $usuarioId): void {
    if (!$proyectoId) return;
    $stmt = $db->prepare("
        SELECT NULLIF(TRIM(tipo_valvula), '') AS nombre_equipo, SUM(num_equipos) AS total
        FROM boom_listas
        WHERE proyecto_id = ? AND tipo_lista = 'principal' AND guardado = 1
        GROUP BY NULLIF(TRIM(tipo_valvula), '')
    ");
    $stmt->execute([$proyectoId]);
    foreach ($stmt->fetchAll() as $grupo) {
        $nombre = $grupo['nombre_equipo'];
        $totalNecesario = max(0, (int)$grupo['total']);
        if ($totalNecesario === 0) continue;

        if ($nombre === null) {
            $stmtExist = $db->prepare("SELECT COUNT(*) FROM equipos_fabricados WHERE proyecto_id = ? AND nombre IS NULL");
            $stmtExist->execute([$proyectoId]);
        } else {
            $stmtExist = $db->prepare("SELECT COUNT(*) FROM equipos_fabricados WHERE proyecto_id = ? AND nombre = ?");
            $stmtExist->execute([$proyectoId, $nombre]);
        }
        $existentes = (int)$stmtExist->fetchColumn();
        if ($existentes >= $totalNecesario) continue;

        $ins = $db->prepare("INSERT INTO equipos_fabricados (proyecto_id, numero_serie, nombre, creado_por) VALUES (?, ?, ?, ?)");
        for ($i = $existentes; $i < $totalNecesario; $i++) {
            $ins->execute([$proyectoId, generarNumeroSerieEquipo($proyectoId), $nombre, $usuarioId]);
        }
    }
}

/** ¿El proyecto ya tuvo su cierre total (todos los equipos fabricados)? Bloqueo indefinido para Ingeniería. */
function proyectoCierreTotal(PDO $db, int $proyecto_id): bool {
    if (!$proyecto_id) return false;
    $stmt = $db->prepare("SELECT cierre_total FROM proyectos WHERE id = ?");
    $stmt->execute([$proyecto_id]);
    return (bool)$stmt->fetchColumn();
}

/** ¿El proyecto ya cerró todo lo necesario para poder registrar sus equipos fabricados? */
function proyectoListoParaEquipos(PDO $db, int $proyecto_id): array {
    $s1 = $db->prepare("SELECT
            SUM(CASE WHEN estado != 'cancelado' AND NOT (produccion_aprobacion='aprobado' AND calidad_aprobacion='aprobado') THEN 1 ELSE 0 END) AS pendientes
        FROM ordenes_trabajo WHERE proyecto_id = ?");
    $s1->execute([$proyecto_id]);
    $ots_ok = ((int)$s1->fetchColumn() === 0);

    // Un proyecto puede tener varias hojas viajeras (una por pieza/equipo individual) -- deben
    // estar TODAS aceptadas en ambas etapas, no solo una. Sin ninguna hoja, no está listo (a
    // diferencia de FAT, que si no hay ninguna no bloquea).
    $s2 = $db->prepare("SELECT COUNT(*) AS total,
            SUM(resultado_produccion = 'aceptado' AND resultado_calidad = 'aceptado') AS ok
        FROM hoja_viajera WHERE proyecto_id = ?");
    $s2->execute([$proyecto_id]);
    $hv = $s2->fetch();
    $hv_existe = ((int)$hv['total']) > 0;
    $hv_ok = $hv_existe && ((int)$hv['total'] === (int)$hv['ok']);

    $s3 = $db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN estado != 'completado' THEN 1 ELSE 0 END) AS pendientes
        FROM pruebas_fat WHERE proyecto_id = ?");
    $s3->execute([$proyecto_id]);
    $fat = $s3->fetch();
    $fat_ok = ((int)$fat['pendientes']) === 0; // proyecto sin FAT registrada no bloquea

    return [
        'listo'    => $ots_ok && $hv_ok && $fat_ok,
        'ots_ok'   => $ots_ok,
        'hv_ok'    => $hv_ok,
        'hv_existe'=> $hv_existe,
        'fat_ok'   => $fat_ok,
    ];
}

function renderEquiposFabricadosNavCard(string $baseUrl): void {
    ?>
    <div class="nav-card" onclick="location.href='<?= htmlspecialchars($baseUrl) ?>?seccion=equipos_fabricados'">
        <div class="card-icon"><i class="fas fa-microchip"></i></div>
        <div class="card-title">Equipos Fabricados</div>
        <div class="card-desc">Serie final y trazabilidad de componentes</div>
    </div>
    <?php
}

function renderEquiposFabricadosSeccion(PDO $db, string $baseUrl, string $rolActor = 'produccion'): void {
    $proyectoId = intval($_GET['proyecto_id'] ?? 0);
    if ($proyectoId) {
        renderEquiposFabricadosDetalle($db, $baseUrl, $proyectoId, $rolActor);
        return;
    }
    renderEquiposFabricadosLista($db, $baseUrl);
}

function renderEquiposFabricadosLista(PDO $db, string $baseUrl): void {
    $proyectos = $db->query("SELECT id, nombre, cierre_total FROM proyectos ORDER BY id DESC")->fetchAll();
    ?>
    <div class="section-content">
        <div class="sticky-container">
            <div class="header-row">
                <h2><i class="fas fa-microchip"></i> Equipos Fabricados</h2>
            </div>
        </div>
        <?php if (empty($proyectos)): ?>
        <div class="empty-state"><i class="fas fa-microchip"></i><p>No hay proyectos registrados.</p></div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($proyectos as $p):
                $estadoInfo = proyectoListoParaEquipos($db, (int)$p['id']);
                $cntEq = $db->prepare("SELECT COUNT(*) AS total, SUM(estado='completado') AS completados FROM equipos_fabricados WHERE proyecto_id=?");
                $cntEq->execute([$p['id']]);
                $eq = $cntEq->fetch();
                $totalEq = (int)($eq['total'] ?? 0);
                $compEq  = (int)($eq['completados'] ?? 0);
                if ($p['cierre_total'])            { $badgeTxt = 'Cerrado';                       $badgeBg = '#e8f5e9'; $badgeColor = '#2e7d32'; }
                elseif ($totalEq > 0)               { $badgeTxt = "$compEq/$totalEq equipos completados"; $badgeBg = '#fff3e0'; $badgeColor = '#e65100'; }
                elseif ($estadoInfo['listo'])        { $badgeTxt = 'Listo para generar equipos';   $badgeBg = '#e3f2fd'; $badgeColor = '#1565c0'; }
                else                                 { $badgeTxt = 'Bloqueado';                    $badgeBg = '#f5f5f5'; $badgeColor = '#757575'; }
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #e0e8f0;border-radius:8px;padding:12px 16px;background:#fff;">
                <div>
                    <strong style="color:#003d5c;"><i class="fas fa-folder"></i> <?= htmlspecialchars($p['nombre']) ?></strong>
                    <span style="margin-left:8px;font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;"><?= htmlspecialchars($badgeTxt) ?></span>
                </div>
                <a href="?seccion=equipos_fabricados&proyecto_id=<?= (int)$p['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function renderEquiposFabricadosDetalle(PDO $db, string $baseUrl, int $proyectoId, string $rolActor = 'produccion'): void {
    // Ingeniería: define cuántos equipos van y les pone serie/TAG -- termina su trabajo ahí.
    // Producción: solo vincula componentes y marca terminado, gateado por proyectoListoParaEquipos().
    // Proyectos: solo lectura + cierre total.
    $puedeCrear     = ($rolActor === 'ingenieria');
    $puedeGestionar = ($rolActor === 'produccion');
    $puedeCerrar    = ($rolActor === 'proyectos');

    $stmtP = $db->prepare("SELECT id, nombre, cierre_total, cierre_total_fecha FROM proyectos WHERE id = ?");
    $stmtP->execute([$proyectoId]);
    $proyecto = $stmtP->fetch();
    if (!$proyecto) { echo '<div class="section-content"><div class="empty-state"><p>Proyecto no encontrado.</p></div></div>'; return; }

    $estado = proyectoListoParaEquipos($db, $proyectoId);

    $stmtEq = $db->prepare("SELECT ef.*, u.nombre_completo AS completado_por_nombre
        FROM equipos_fabricados ef LEFT JOIN usuarios u ON u.id = ef.completado_por
        WHERE ef.proyecto_id = ? ORDER BY ef.id ASC");
    $stmtEq->execute([$proyectoId]);
    $equipos = $stmtEq->fetchAll();

    $stmtNum = $db->prepare("SELECT num_equipos FROM boom_listas WHERE proyecto_id = ? AND tipo_lista='principal' ORDER BY id ASC LIMIT 1");
    $stmtNum->execute([$proyectoId]);
    $numEquiposDefault = (int)($stmtNum->fetchColumn() ?: 1);

    $todosCompletados = count($equipos) > 0 && count(array_filter($equipos, fn($e) => $e['estado'] === 'completado')) === count($equipos);

    $stmtSeries = $db->prepare("SELECT sl.id, sl.numero_serie, COALESCE(pp.nombre, m.descripcion) AS descripcion
        FROM series_lotes sl
        LEFT JOIN plano_paginas pp ON pp.id = sl.plano_pagina_id
        LEFT JOIN materiales m ON m.id = sl.material_id
        WHERE sl.proyecto_id = ? AND sl.estado IN ('Fabricado','En Uso')
          AND sl.id NOT IN (SELECT serie_componente_id FROM equipos_fabricados_componentes)
        ORDER BY sl.origen DESC, sl.fecha_ingreso ASC");
    $stmtSeries->execute([$proyectoId]);
    $seriesDisponibles = $stmtSeries->fetchAll();
    ?>
    <div class="section-content">
        <div class="sticky-container">
            <div class="header-row">
                <h2><i class="fas fa-microchip"></i> Equipos Fabricados — <?= htmlspecialchars($proyecto['nombre']) ?></h2>
                <a href="?seccion=equipos_fabricados" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
            </div>
        </div>

        <?php if ($proyecto['cierre_total']): ?>
        <div class="empty-state" style="color:#2e7d32;">
            <i class="fas fa-lock" style="color:#2e7d32;"></i>
            <p><strong>Proyecto cerrado</strong> — <?= date('d/m/Y H:i', strtotime($proyecto['cierre_total_fecha'])) ?>. Ya no se requieren más acciones de manufactura/ingeniería sobre este proyecto.</p>
        </div>
        <?php endif; ?>

        <?php if (empty($equipos)): ?>
            <?php if ($puedeCrear): ?>
            <div style="margin-bottom:16px;">
                <form method="POST" onsubmit="return confirm('¿Generar <?= $numEquiposDefault ?> equipo<?= $numEquiposDefault == 1 ? '' : 's' ?> (según el número de equipos del BOM principal de este proyecto)?')">
                    <input type="hidden" name="action" value="crear_equipos_fabricados">
                    <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-plus"></i> Generar <?= $numEquiposDefault ?> equipo<?= $numEquiposDefault == 1 ? '' : 's' ?> (según el BOM)</button>
                </form>
                <p style="font-size:12px;color:#777;margin-top:6px;">A cada equipo se le asigna número de serie automático (proyecto-año-consecutivo). El ID/TAG se pone después, por equipo.</p>
            </div>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-microchip"></i><p>Ingeniería todavía no ha definido los equipos de este proyecto.</p></div>
            <?php endif; ?>
        <?php else: ?>

        <?php if ($puedeGestionar && !$estado['listo']): ?>
        <div class="empty-state" style="margin-bottom:16px;">
            <i class="fas fa-lock"></i>
            <p>Todavía no se puede vincular componentes ni marcar equipos como terminados. Falta:</p>
            <ul style="text-align:left;display:inline-block;margin-top:8px;">
                <?php if (!$estado['ots_ok']): ?><li>Todas las OTs deben estar aprobadas por Producción y Calidad.</li><?php endif; ?>
                <?php if (!$estado['hv_ok']): ?><li>Todas las Hojas Viajeras del proyecto deben estar finalizadas en ambas etapas<?= !$estado['hv_existe'] ? ' (todavía no existe ninguna)' : '' ?>.</li><?php endif; ?>
                <?php if (!$estado['fat_ok']): ?><li>Todas las pruebas FAT del proyecto deben estar completadas.</li><?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px;">
            <?php foreach ($equipos as $eq):
                $compsStmt = $db->prepare("SELECT efc.id, sl.numero_serie, sl.origen, COALESCE(pp.nombre, m.descripcion) AS descripcion
                    FROM equipos_fabricados_componentes efc
                    JOIN series_lotes sl ON sl.id = efc.serie_componente_id
                    LEFT JOIN plano_paginas pp ON pp.id = sl.plano_pagina_id
                    LEFT JOIN materiales m ON m.id = sl.material_id
                    WHERE efc.equipo_fabricado_id = ?");
                $compsStmt->execute([$eq['id']]);
                $comps = $compsStmt->fetchAll();
                $puedeEditar   = $puedeGestionar && !$proyecto['cierre_total'] && $eq['estado'] !== 'completado' && $estado['listo'];
                $puedeEditarId = $puedeCrear && !$proyecto['cierre_total'] && $eq['estado'] !== 'completado';
            ?>
            <div style="border:1px solid #e0e8f0;border-radius:10px;padding:16px;background:#fff;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                    <div>
                        <strong style="color:#003d5c;"><i class="fas fa-microchip"></i> <?= htmlspecialchars($eq['nombre'] ?: ('Equipo #' . $eq['id'])) ?></strong>
                        <span style="margin-left:8px;font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $eq['estado'] === 'completado' ? '#e8f5e9' : '#fff3e0' ?>;color:<?= $eq['estado'] === 'completado' ? '#2e7d32' : '#e65100' ?>;"><?= $eq['estado'] === 'completado' ? 'Completado' : 'Borrador' ?></span>
                    </div>
                    <?php if ($puedeEditarId): ?>
                    <button class="btn btn-outline btn-sm" onclick="efAbrirModalSerie(<?= (int)$eq['id'] ?>, <?= htmlspecialchars(json_encode($eq['id_tag'] ?: '', JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($eq['nombre'] ?: '', JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-pen"></i> ID/TAG</button>
                    <?php endif; ?>
                </div>
                <div style="font-size:.85rem;color:#333;margin-bottom:4px;">
                    <i class="fas fa-barcode"></i> Serie: <strong><?= htmlspecialchars($eq['numero_serie'] ?: '— sin asignar —') ?></strong>
                </div>
                <div style="font-size:.85rem;color:#333;margin-bottom:10px;">
                    <i class="fas fa-tag"></i> ID/TAG: <strong><?= htmlspecialchars($eq['id_tag'] ?: '— sin asignar —') ?></strong>
                </div>
                <div style="font-size:.78rem;font-weight:700;color:#003d5c;margin-bottom:6px;">Componentes vinculados (<?= count($comps) ?>)</div>
                <?php if (empty($comps)): ?>
                <div style="font-size:.78rem;color:#999;margin-bottom:8px;">Sin componentes vinculados todavía.</div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">
                    <?php foreach ($comps as $c): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:.78rem;background:#f7f9fb;border-radius:6px;padding:5px 10px;">
                        <span><i class="fas fa-<?= $c['origen'] === 'fabricado' ? 'cog' : 'box' ?>" style="color:#666;"></i> <?= htmlspecialchars($c['numero_serie']) ?><?= $c['descripcion'] ? ' — ' . htmlspecialchars($c['descripcion']) : '' ?></span>
                        <?php if ($puedeEditar): ?>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('¿Quitar este componente del equipo?')">
                            <input type="hidden" name="accion" value="desvincular_componente_equipo">
                            <input type="hidden" name="componente_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="padding:2px 8px;"><i class="fas fa-times"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($puedeEditar): ?>
                <button class="btn btn-outline btn-sm" onclick="efAbrirModalComponentes(<?= (int)$eq['id'] ?>)"><i class="fas fa-link"></i> Vincular componente</button>
                <form method="POST" style="display:inline;margin-left:6px;" onsubmit="return confirm('¿Marcar este equipo como terminado? No podrás editarlo después.')">
                    <input type="hidden" name="accion" value="completar_equipo_fabricado">
                    <input type="hidden" name="equipo_id" value="<?= (int)$eq['id'] ?>">
                    <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                    <button type="submit" class="btn btn-warning btn-sm" <?= $eq['numero_serie'] ? '' : 'disabled title="Falta la serie del equipo"' ?>><i class="fas fa-check"></i> Marcar como terminado</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; // empty($equipos) ?>

        <?php if ($todosCompletados && !$proyecto['cierre_total'] && $puedeCerrar): ?>
        <div style="border:2px dashed #2e7d32;border-radius:10px;padding:16px;text-align:center;">
            <p style="margin-bottom:10px;color:#2e7d32;font-weight:700;"><i class="fas fa-check-circle"></i> Todos los equipos de este proyecto están completados.</p>
            <form method="POST" onsubmit="return confirm('¿Cerrar este proyecto? Ingeniería quedará bloqueada de forma indefinida y se notificará a todos los roles involucrados. Esta acción no se puede revertir.')">
                <input type="hidden" name="accion" value="cerrar_proyecto_total">
                <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                <button type="submit" class="btn btn-warning"><i class="fas fa-lock"></i> Cerrar proyecto</button>
            </form>
        </div>
        <?php elseif ($todosCompletados && !$proyecto['cierre_total']): ?>
        <div class="empty-state" style="color:#1565c0;"><i class="fas fa-check-circle" style="color:#1565c0;"></i><p>Todos los equipos están completados. Falta que Proyectos confirme el cierre del proyecto.</p></div>
        <?php endif; ?>
    </div>

    <?php if ($puedeCrear): ?>
    <!-- Modal: ID/TAG del equipo -->
    <div class="modal-overlay" id="modalSerieEquipo">
        <div class="modal">
            <div class="modal-header"><span class="modal-title"><i class="fas fa-tag"></i> ID/TAG del equipo</span>
                <button type="button" class="modal-close" onclick="efCerrarModal('modalSerieEquipo')">&times;</button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="guardar_id_tag_equipo">
                    <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                    <input type="hidden" name="equipo_id" id="ef_serie_equipo_id" value="">
                    <div class="form-group">
                        <label class="form-label">ID / TAG</label>
                        <input type="text" name="id_tag" id="ef_serie_numero" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nombre (opcional)</label>
                        <input type="text" name="nombre" id="ef_serie_nombre" class="form-input">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="efCerrarModal('modalSerieEquipo')">Cancelar</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; // puedeCrear ?>

    <?php if ($puedeGestionar): ?>
    <!-- Modal: Vincular componente -->
    <div class="modal-overlay" id="modalComponentesEquipo">
        <div class="modal">
            <div class="modal-header"><span class="modal-title"><i class="fas fa-link"></i> Vincular componente</span>
                <button type="button" class="modal-close" onclick="efCerrarModal('modalComponentesEquipo')">&times;</button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="accion" value="vincular_componente_equipo">
                    <input type="hidden" name="proyecto_id" value="<?= $proyectoId ?>">
                    <input type="hidden" name="equipo_id" id="ef_comp_equipo_id" value="">
                    <div class="form-group">
                        <label class="form-label">Serie disponible del proyecto</label>
                        <select name="serie_id" id="ef_comp_select" class="form-input" required>
                            <option value="">Selecciona una serie…</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="efCerrarModal('modalComponentesEquipo')">Cancelar</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-link"></i> Vincular</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; // puedeGestionar ?>

    <?php if ($puedeCrear || $puedeGestionar): ?>
    <script>
        const EF_SERIES_DISPONIBLES = <?= json_encode($seriesDisponibles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        // Este componente se usa en produccion.php (modal-overlay.open) e ingenieria.php
        // (modal-overlay.active) -- convenciones distintas por archivo, se ponen las dos
        // clases para que funcione en cualquiera de los dos contextos.
        function efAbrirModal(id) { document.getElementById(id).classList.add('open', 'active'); }
        function efCerrarModal(id) { document.getElementById(id).classList.remove('open', 'active'); }

        function efAbrirModalSerie(equipoId, serieActual, nombreActual) {
            document.getElementById('ef_serie_equipo_id').value = equipoId;
            document.getElementById('ef_serie_numero').value = serieActual || '';
            document.getElementById('ef_serie_nombre').value = nombreActual || '';
            efAbrirModal('modalSerieEquipo');
        }

        function efAbrirModalComponentes(equipoId) {
            document.getElementById('ef_comp_equipo_id').value = equipoId;
            const sel = document.getElementById('ef_comp_select');
            sel.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = EF_SERIES_DISPONIBLES.length ? 'Selecciona una serie…' : 'No hay series disponibles para vincular';
            sel.appendChild(placeholder);
            for (const s of EF_SERIES_DISPONIBLES) {
                const o = document.createElement('option');
                o.value = s.id;
                o.textContent = s.numero_serie + (s.descripcion ? ' — ' + s.descripcion : '');
                sel.appendChild(o);
            }
            efAbrirModal('modalComponentesEquipo');
        }
    </script>
    <?php endif; // puedeCrear || puedeGestionar (JS) ?>
    <?php
}
