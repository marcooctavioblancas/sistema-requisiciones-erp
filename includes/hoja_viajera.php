<?php
/**
 * Módulo Hoja Viajera — control de proceso/actividad por pieza individual (formato DOC-PRD-FO-01A).
 * Compartido por produccion.php y api/hoja_viajera.php.
 * Un proyecto puede tener varias hojas viajeras (una por cada pieza/equipo individual que
 * lleva su propio No. de serie/ID-TAG) — ver [[proyectoListoParaEquipos]] en
 * includes/equipos_fabricados.php, que exige TODAS aceptadas, no solo una.
 */

const HOJA_VIAJERA_PASOS_FIJOS = [
    1  => ['nombre' => 'Recubrimientos',                    'tipo' => 'recubrimientos'],
    2  => ['nombre' => 'Pre-Ensamble de Control',           'tipo' => 'simple'],
    3  => ['nombre' => 'Barrenado en Base',                 'tipo' => 'detallado'],
    4  => ['nombre' => 'Fijación con Tornillería',          'tipo' => 'detallado'],
    5  => ['nombre' => 'Colocación de Conectores',          'tipo' => 'detallado'],
    6  => ['nombre' => 'Colocación de Tubing',               'tipo' => 'detallado'],
    7  => ['nombre' => 'Etiquetado',                         'tipo' => 'detallado'],
    8  => ['nombre' => 'Ensamble Control en Actuador',       'tipo' => 'detallado'],
    9  => ['nombre' => 'Tubeado de Control en Actuador',     'tipo' => 'detallado'],
    10 => ['nombre' => 'Colocación de Instalación Eléctrica', 'tipo' => 'detallado'],
    11 => ['nombre' => 'Prueba de Control',                  'tipo' => 'simple'],
    12 => ['nombre' => 'Pruebas Finales',                    'tipo' => 'simple'],
    13 => ['nombre' => 'Empaque',                            'tipo' => 'simple'],
    14 => ['nombre' => 'Almacenaje',                         'tipo' => 'simple'],
    15 => ['nombre' => 'Envío',                              'tipo' => 'simple'],
];

/** Acceso al módulo (leer/entrar): Producción o Calidad, cada quien solo actúa en su columna. */
function hv_valida_acceso(): bool {
    return isAdmin() || hasAcceso('manufactura') || hasAcceso('calidad');
}

/** Puede editar el contenido de la hoja (encabezado, pasos, crear/finalizar) y dar Vo.Bo. de Producción. */
function hv_puede_editar_produccion(): bool {
    return isAdmin() || hasAcceso('manufactura');
}

/** Puede dar el Vo.Bo. de Calidad. */
function hv_puede_actuar_calidad(): bool {
    return isAdmin() || hasAcceso('calidad');
}

/** Recalcula n_retrabajos/n_reprocesos de una hoja — solo cuentan los pasos 3 al 10 (los "detallado"). */
function hv_recalcular_conteos(PDO $db, int $hojaId): void {
    $stmt = $db->prepare("SELECT
            SUM(numero BETWEEN 3 AND 10 AND accion_realizada = 'retrabajo') AS retrabajos,
            SUM(numero BETWEEN 3 AND 10 AND accion_realizada = 'reproceso') AS reprocesos
        FROM hoja_viajera_pasos WHERE hoja_id = ?");
    $stmt->execute([$hojaId]);
    $r = $stmt->fetch();
    $db->prepare("UPDATE hoja_viajera SET n_retrabajos = ?, n_reprocesos = ? WHERE id = ?")
       ->execute([(int)($r['retrabajos'] ?? 0), (int)($r['reprocesos'] ?? 0), $hojaId]);
}

/** Vuelve a poner en 'pendiente' todos los pasos de una hoja — usado cuando Calidad rechaza el cierre final. */
function hv_reiniciar_pasos(PDO $db, int $hojaId): void {
    $db->prepare("UPDATE hoja_viajera_pasos SET
            actividad_estado = NULL, accion_realizada = NULL, nc_numero = NULL,
            sub_valvula = 0, sub_actuador = 0, sub_accesorios = 0,
            vobo_produccion = 'pendiente', vobo_produccion_por = NULL, vobo_produccion_fecha = NULL,
            vobo_calidad = 'pendiente', vobo_calidad_por = NULL, vobo_calidad_fecha = NULL
        WHERE hoja_id = ?")->execute([$hojaId]);
    $db->prepare("UPDATE hoja_viajera SET n_retrabajos = 0, n_reprocesos = 0 WHERE id = ?")->execute([$hojaId]);
}

/** Campos de encabezado editables inline — solo Producción, vía api/hoja_viajera.php::guardar_campo.
 *  Cliente y Producto se autocompletan desde la Orden de Producción al crear la hoja y ya no son
 *  editables después (deben coincidir con la orden real); No. de serie sigue editable.
 *  pasadas_recubrimiento: total de pasadas de mano dadas en el recubrimiento, dato que solo se
 *  conoce ya avanzada la producción, no al crear la hoja — igual que no_serie, editable inline.
 *  id_tag: mismo criterio que no_serie (no siempre se conoce al crear la hoja). */
const HV_CAMPOS_ENCABEZADO = ['no_serie', 'pasadas_recubrimiento', 'id_tag'];

/** ¿Ya se resolvió (conforme o no_aplica) el Vo.Bo. de Producción en los 15 pasos? Requisito para habilitar la firma final. */
function hv_produccion_completa(PDO $db, int $hojaId): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM hoja_viajera_pasos WHERE hoja_id = ? AND vobo_produccion = 'pendiente'");
    $stmt->execute([$hojaId]);
    return (int)$stmt->fetchColumn() === 0;
}

/** ¿Ya se resolvió el Vo.Bo. de Calidad en todos los pasos que sí corrobora (vobo_produccion='conforme')? */
function hv_calidad_completa(PDO $db, int $hojaId): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM hoja_viajera_pasos WHERE hoja_id = ? AND vobo_produccion = 'conforme' AND vobo_calidad = 'pendiente'");
    $stmt->execute([$hojaId]);
    return (int)$stmt->fetchColumn() === 0;
}

function hv_campo_editable(string $campo, string $valor, int $hojaId, string $returnUrl, bool $disabled): string {
    ob_start();
    ?>
    <form method="POST" action="api/hoja_viajera.php" style="flex:1;">
        <input type="hidden" name="accion" value="guardar_campo_encabezado">
        <input type="hidden" name="campo" value="<?= htmlspecialchars($campo) ?>">
        <input type="hidden" name="hoja_id" value="<?= $hojaId ?>">
        <input type="hidden" name="return_url" value="<?= $returnUrl ?>&hoja_id=<?= $hojaId ?>">
        <input type="text" name="valor" value="<?= htmlspecialchars($valor) ?>"
               style="width:100%;border:1px solid transparent;background:transparent;font-size:15px;font-family:inherit;padding:2px 4px;border-radius:4px;"
               <?= $disabled ? 'disabled' : '' ?>
               onfocus="this.style.borderColor='#ccc';this.style.background='#fff';"
               onblur="this.form.submit()">
    </form>
    <?php
    return ob_get_clean();
}

function renderHojaViajeraNavCard(string $baseUrl): void {
    ?>
    <div class="nav-card" onclick="location.href='<?= htmlspecialchars($baseUrl) ?>?seccion=hoja_viajera'">
        <div class="card-icon"><i class="fas fa-route"></i></div>
        <div class="card-title">Hoja Viajera</div>
        <div class="card-desc">Control de proceso DOC-PRD-FO-01A</div>
    </div>
    <?php
}

function renderHojaViajeraSeccion(PDO $db, string $baseUrl, string $rolActor = 'produccion'): void {
    $hojaId = intval($_GET['hoja_id'] ?? 0);
    if ($hojaId) {
        renderHojaViajeraDetalle($db, $baseUrl, $hojaId, $rolActor);
        return;
    }
    renderHojaViajeraLista($db, $baseUrl, $rolActor);
}

function renderHojaViajeraLista(PDO $db, string $baseUrl, string $rolActor = 'produccion'): void {
    // Calidad no crea hojas viajeras, solo firma las que Producción ya generó -- por eso solo ve
    // proyectos que ya tienen al menos una (JOIN); Producción ve todos los proyectos, tengan o no.
    // Un proyecto puede tener varias hojas (una por pieza/equipo individual), así que la query trae
    // TODAS las filas de hoja_viajera del proyecto y se agrupan en PHP.
    $sql = "SELECT p.id AS proyecto_id, p.nombre AS proyecto_nombre,
                   hv.id AS hoja_id, hv.no_serie, hv.id_tag, hv.resultado, hv.fecha_creacion
            FROM proyectos p
            " . ($rolActor === 'calidad' ? "JOIN" : "LEFT JOIN") . " hoja_viajera hv ON hv.proyecto_id = p.id
            ORDER BY p.id DESC, hv.fecha_creacion DESC";
    $filas = $db->query($sql)->fetchAll();

    $porProyecto = [];
    foreach ($filas as $f) {
        $pid = $f['proyecto_id'];
        if (!isset($porProyecto[$pid])) {
            $porProyecto[$pid] = ['id' => $pid, 'nombre' => $f['proyecto_nombre'], 'hojas' => []];
        }
        if ($f['hoja_id']) {
            $porProyecto[$pid]['hojas'][] = $f;
        }
    }

    // Absoluta — hv_redirect_back() solo sigue return_url si apunta al mismo host (anti open-redirect).
    $baseUrlAbs = rtrim(SITE_URL, '/') . '/' . ltrim($baseUrl, '/');
    $badge = ['pendiente'=>['En proceso','#e65100','#fff3e0'],'aceptado'=>['Aceptado','#2E7D32','#e8f5e9'],'rechazado'=>['Rechazado','#c62828','#ffebee']];
    ?>
    <div class="section-content">
        <div class="sticky-container">
            <div class="header-row">
                <h2><i class="fas fa-route"></i> Hoja Viajera</h2>
            </div>
        </div>

        <?php if (empty($porProyecto)): ?>
        <div class="empty-state"><i class="fas fa-route"></i><p><?= $rolActor === 'calidad' ? 'No hay hojas viajeras pendientes de revisión.' : 'No hay proyectos registrados.' ?></p></div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($porProyecto as $proy): ?>
            <div style="border:1px solid #e0e8f0;border-radius:8px;padding:12px 16px;background:#fff;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:<?= empty($proy['hojas']) ? '0' : '10px' ?>;">
                    <strong style="color:#003d5c;"><i class="fas fa-folder"></i> <?= htmlspecialchars($proy['nombre']) ?></strong>
                    <?php if ($rolActor === 'produccion'): ?>
                    <button class="btn btn-warning btn-sm" onclick="abrirModalCrearHojaViajera(<?= (int)$proy['id'] ?>, <?= htmlspecialchars(json_encode($proy['nombre'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-plus"></i> Nueva Hoja Viajera</button>
                    <?php endif; ?>
                </div>
                <?php if (empty($proy['hojas'])): ?>
                    <?php if ($rolActor !== 'produccion'): ?>
                    <div style="font-size:.78rem;color:#999;">Sin hojas viajeras todavía.</div>
                    <?php endif; ?>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach ($proy['hojas'] as $h): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f7f9fb;border-radius:6px;padding:8px 12px;">
                        <div style="font-size:.82rem;color:#333;">
                            <i class="fas fa-route" style="color:#888;"></i>
                            <?= $h['no_serie'] ? htmlspecialchars($h['no_serie']) : '<span style="color:#999;">— sin No. de serie —</span>' ?>
                            <?php if ($h['id_tag']): ?><span style="color:#888;"> · ID/TAG: <?= htmlspecialchars($h['id_tag']) ?></span><?php endif; ?>
                            <span style="margin-left:8px;font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $badge[$h['resultado']][2] ?>;color:<?= $badge[$h['resultado']][1] ?>;"><?= $badge[$h['resultado']][0] ?></span>
                        </div>
                        <a href="<?= htmlspecialchars($baseUrl) ?>?seccion=hoja_viajera&hoja_id=<?= $h['hoja_id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver</a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal-overlay" id="modalCrearHojaViajera">
        <div class="modal-box" style="max-width:420px;">
            <div class="modal-title"><i class="fas fa-route"></i> Crear Hoja Viajera — <span id="hv_crear_proyecto_nombre"></span></div>
            <form method="POST" action="api/hoja_viajera.php">
                <div style="padding:16px;">
                    <input type="hidden" name="accion" value="crear_hoja_viajera">
                    <input type="hidden" name="proyecto_id" id="hv_crear_proyecto_id" value="">
                    <input type="hidden" name="return_url" value="<?= htmlspecialchars($baseUrlAbs) ?>?seccion=hoja_viajera">
                    <div style="display:flex;gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">No. de serie</label>
                            <input type="text" name="no_serie" class="form-input">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label class="form-label">ID / TAG</label>
                            <input type="text" name="id_tag" class="form-input">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:0 16px 16px;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('modalCrearHojaViajera')">Cancelar</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-plus"></i> Crear</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalCrearHojaViajera(proyectoId, proyectoNombre) {
            document.getElementById('hv_crear_proyecto_id').value = proyectoId;
            document.getElementById('hv_crear_proyecto_nombre').textContent = proyectoNombre;
            openModal('modalCrearHojaViajera');
        }
    </script>
    <?php
}

function renderHojaViajeraDetalle(PDO $db, string $baseUrl, int $hojaId, string $rolActor = 'produccion'): void {
    $stmt = $db->prepare("SELECT hv.*, p.nombre AS proyecto_nombre
                           FROM hoja_viajera hv JOIN proyectos p ON p.id = hv.proyecto_id
                           WHERE hv.id = ?");
    $stmt->execute([$hojaId]);
    $hoja = $stmt->fetch();
    if (!$hoja) { echo '<div class="section-content"><div class="empty-state"><p>Hoja viajera no encontrada.</p></div></div>'; return; }

    $stmtP = $db->prepare("SELECT hvp.*, up.nombre_completo AS vobo_produccion_por_nombre, uc.nombre_completo AS vobo_calidad_por_nombre
                            FROM hoja_viajera_pasos hvp
                            LEFT JOIN usuarios up ON up.id = hvp.vobo_produccion_por
                            LEFT JOIN usuarios uc ON uc.id = hvp.vobo_calidad_por
                            WHERE hvp.hoja_id = ? ORDER BY hvp.numero ASC");
    $stmtP->execute([$hojaId]);
    $pasos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Absoluta — hv_redirect_back() solo sigue return_url si apunta al mismo host (anti open-redirect).
    $returnUrl = htmlspecialchars(rtrim(SITE_URL, '/') . '/' . ltrim($baseUrl, '/')) . '?seccion=hoja_viajera';
    $bloqueada = $hoja['resultado'] !== 'pendiente';
    // Los pasos (recubrimientos/detallado) y el encabezado los llena Producción — Calidad solo firma su columna.
    $hvDisabled = $bloqueada || $rolActor !== 'produccion';

    $accion_label = ['retrabajo'=>'Retrabajo','reproceso'=>'Reproceso','no_se_requirio'=>'No se requirió'];

    // Mismo logo embebido que usa api/vista_pdf_hoja_viajera.php.
    $hvLogoPath = defined('UPLOAD_PATH') ? UPLOAD_PATH . 'logo/logo.png' : '';
    $hvLogoUri  = ($hvLogoPath && file_exists($hvLogoPath)) ? 'data:image/png;base64,' . base64_encode(file_get_contents($hvLogoPath)) : '';
    ?>
    <div class="section-content">
        <div class="sticky-container">
            <div class="header-row">
                <h2><i class="fas fa-route"></i> Hoja Viajera — <?= htmlspecialchars($hoja['proyecto_nombre']) ?></h2>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="descargarPdfHojaViajera(<?= $hojaId ?>)"><i class="fas fa-file-pdf"></i> Descargar PDF</button>
                    <a href="<?= $returnUrl ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                </div>
            </div>
        </div>
        <script>
            function descargarPdfHojaViajera(hojaId) {
                let frame = document.getElementById('hvIframeImprimir');
                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.id = 'hvIframeImprimir';
                    frame.style.display = 'none';
                    document.body.appendChild(frame);
                }
                frame.onload = function () {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                };
                frame.src = 'api/vista_pdf_hoja_viajera.php?hoja_id=' + hojaId;
            }
        </script>

        <div style="border:1px solid #333;font-family:Arial, sans-serif;background:#fff;margin-bottom:14px;">
            <div style="display:flex;border-bottom:1px solid #333;">
                <div style="flex:0 0 160px;border-right:1px solid #333;padding:10px;text-align:center;font-weight:800;font-size:20px;color:#003d5c;display:flex;align-items:center;justify-content:center;">
                    <?php if ($hvLogoUri): ?><img src="<?= $hvLogoUri ?>" alt="EMPRESA EJEMPLO" style="max-height:38px;max-width:100%;"><?php else: ?>EMPRESA EJEMPLO<?php endif; ?>
                </div>
                <div style="flex:1;padding:10px;text-align:center;font-weight:800;font-size:22px;display:flex;align-items:center;justify-content:center;">Hoja Viajera</div>
            </div>
            <div style="display:flex;border-bottom:1px solid #333;font-size:15px;">
                <div style="flex:1;padding:6px 10px;border-right:1px solid #333;">
                    <strong>Cliente:</strong> <?= htmlspecialchars($hoja['cliente'] ?? '') ?>
                </div>
            </div>
            <div style="display:flex;border-bottom:1px solid #333;font-size:15px;">
                <div style="flex:1;padding:6px 10px;border-right:1px solid #333;"><strong>No. proyecto:</strong> <?= htmlspecialchars($hoja['proyecto_nombre']) ?></div>
                <div style="flex:1;padding:6px 10px;display:flex;align-items:center;gap:6px;">
                    <strong>No. de serie:</strong>
                    <?= hv_campo_editable('no_serie', $hoja['no_serie'] ?? '', $hojaId, $returnUrl, $hvDisabled) ?>
                </div>
            </div>
            <div style="display:flex;border-bottom:1px solid #333;font-size:15px;">
                <div style="flex:1;padding:6px 10px;border-right:1px solid #333;">
                    <strong>Producto:</strong> <?= htmlspecialchars($hoja['producto'] ?? '') ?>
                </div>
                <div style="flex:1;padding:6px 10px;display:flex;align-items:center;gap:6px;">
                    <strong>ID / TAG:</strong>
                    <?= hv_campo_editable('id_tag', $hoja['id_tag'] ?? '', $hojaId, $returnUrl, $hvDisabled) ?>
                </div>
            </div>
            <div style="display:flex;font-size:15px;">
                <div style="flex:1;padding:6px 10px;display:flex;align-items:center;gap:6px;">
                    <strong>Pasadas de recubrimiento (mano):</strong>
                    <?= hv_campo_editable('pasadas_recubrimiento', (string)($hoja['pasadas_recubrimiento'] ?? ''), $hojaId, $returnUrl, $hvDisabled) ?>
                </div>
            </div>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:12px;">
            <thead>
                <tr style="background:#003d5c;color:#fff;">
                    <th style="border:1px solid #333;padding:6px;width:36px;">Número</th>
                    <th style="border:1px solid #333;padding:6px;">Proceso o actividad</th>
                    <th style="border:1px solid #333;padding:6px;width:150px;">Vo.Bo. Producción</th>
                    <th style="border:1px solid #333;padding:6px;width:95px;">Fecha Producción</th>
                    <th style="border:1px solid #333;padding:6px;width:150px;">Vo.Bo. Calidad</th>
                    <th style="border:1px solid #333;padding:6px;width:95px;">Fecha Calidad</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pasos as $p): ?>
                <tr style="vertical-align:top;">
                    <td style="border:1px solid #ccc;padding:6px;text-align:center;font-weight:700;"><?= $p['numero'] ?></td>
                    <td style="border:1px solid #ccc;padding:6px;">
                        <div style="font-weight:700;margin-bottom:4px;"><?= htmlspecialchars($p['nombre']) ?></div>
                        <?php if ($p['tipo'] === 'recubrimientos'): ?>
                        <form method="POST" action="api/hoja_viajera.php" class="hv-inline-form">
                            <input type="hidden" name="accion" value="guardar_recubrimientos">
                            <input type="hidden" name="paso_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="return_url" value="<?= $returnUrl ?>&hoja_id=<?= $hojaId ?>">
                            <?php foreach (['sub_valvula'=>'Válvula','sub_actuador'=>'Actuador','sub_accesorios'=>'Accesorios'] as $campo=>$label): ?>
                            <label style="display:block;font-size:13px;">
                                <input type="checkbox" name="<?= $campo ?>" <?= $p[$campo] ? 'checked' : '' ?> <?= ($bloqueada || $rolActor !== 'produccion')?'disabled':'' ?> onchange="this.form.submit()"> <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </form>
                        <?php elseif ($p['tipo'] === 'detallado'):
                            // "No se requirió" es una respuesta válida de Acción realizada (no se necesitó
                            // retrabajo) -- no implica que Actividad/NC deban congelarse, son independientes.
                            $hvActividadDisabled = $hvDisabled;
                        ?>
                        <form method="POST" action="api/hoja_viajera.php" class="hv-inline-form" style="font-size:13px;">
                            <input type="hidden" name="accion" value="guardar_paso_detalle">
                            <input type="hidden" name="paso_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="return_url" value="<?= $returnUrl ?>&hoja_id=<?= $hojaId ?>">
                            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:4px;">
                                <div>
                                    <div style="font-weight:700;">Actividad</div>
                                    <label><input type="radio" name="actividad_estado" value="conforme" <?= $p['actividad_estado']==='conforme'?'checked':'' ?> <?= $hvActividadDisabled?'disabled':'' ?> onchange="this.form.submit()"> Conforme</label><br>
                                    <label><input type="radio" name="actividad_estado" value="no_conforme" <?= $p['actividad_estado']==='no_conforme'?'checked':'' ?> <?= $hvActividadDisabled?'disabled':'' ?> onchange="this.form.submit()"> No Conforme</label>
                                </div>
                                <div>
                                    <div style="font-weight:700;">Acción realizada</div>
                                    <label><input type="radio" name="accion_realizada" value="retrabajo" <?= $p['accion_realizada']==='retrabajo'?'checked':'' ?> <?= $hvDisabled?'disabled':'' ?> onchange="this.form.submit()"> Retrabajo</label><br>
                                    <label><input type="radio" name="accion_realizada" value="reproceso" <?= $p['accion_realizada']==='reproceso'?'checked':'' ?> <?= $hvDisabled?'disabled':'' ?> onchange="this.form.submit()"> Reproceso</label><br>
                                    <label><input type="radio" name="accion_realizada" value="no_se_requirio" <?= $p['accion_realizada']==='no_se_requirio'?'checked':'' ?> <?= $hvDisabled?'disabled':'' ?> onchange="this.form.submit()"> No se requirió</label>
                                </div>
                            </div>
                            <div>
                                <label style="font-weight:700;">NC N° Levantada
                                <input type="text" name="nc_numero" value="<?= htmlspecialchars($p['nc_numero'] ?? '') ?>" style="width:80px;" <?= $hvActividadDisabled?'disabled':'' ?> onblur="this.form.submit()">
                                </label>
                            </div>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php
                    // Un paso "detallado" sin Actividad ni Acción realizada todavía no tiene nada que
                    // corroborar — Producción puede marcarlo "No Aplica" pero no "Firmar" como conforme.
                    $hvSinDatos = $p['tipo'] === 'detallado' && $p['actividad_estado'] === null && $p['accion_realizada'] === null;
                    foreach (['produccion','calidad'] as $rol):
                        $estado = $p["vobo_{$rol}"];
                        $por    = $p["vobo_{$rol}_por"];
                        $fecha  = $p["vobo_{$rol}_fecha"];
                        // Orden: Calidad solo puede actuar si Producción dio Conforme — un "No Aplica" de
                        // Producción no es una corroboración real, así que tampoco habilita a Calidad.
                        $orden_ok     = $rol === 'produccion' ? true : ($p['vobo_produccion'] === 'conforme');
                        // Cada rol solo firma su propia columna — Producción no puede dar el Vo.Bo. de Calidad ni viceversa.
                        $puede_actuar = $orden_ok && ($rolActor === $rol);
                    ?>
                    <td style="border:1px solid #ccc;padding:6px;text-align:center;">
                        <?php if ($estado === 'pendiente'): ?>
                            <?php if ($bloqueada): ?>
                                <span style="color:#bbb;">—</span>
                            <?php elseif ($puede_actuar): ?>
                                <form method="POST" action="api/hoja_viajera.php" style="display:flex;flex-direction:column;gap:3px;">
                                    <input type="hidden" name="accion" value="vobo_hoja_paso">
                                    <input type="hidden" name="paso_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="rol" value="<?= $rol ?>">
                                    <input type="hidden" name="return_url" value="<?= $returnUrl ?>&hoja_id=<?= $hojaId ?>">
                                    <?php if (!($rol === 'produccion' && $hvSinDatos)): ?>
                                    <button type="submit" name="valor" value="conforme" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Firmar</button>
                                    <?php endif; ?>
                                    <button type="submit" name="valor" value="no_aplica" class="btn btn-outline btn-sm">No Aplica</button>
                                </form>
                                <?php if ($rol === 'produccion' && $hvSinDatos): ?>
                                <div style="font-size:11.5px;color:#999;margin-top:2px;">Falta Actividad o Acción para poder Firmar</div>
                                <?php endif; ?>
                            <?php elseif (!$orden_ok && $rol === 'calidad' && $p['vobo_produccion'] === 'no_aplica'): ?>
                                <span style="color:#666;font-size:13px;"><i class="fas fa-ban"></i> No Aplica (Producción) — no corrobora Calidad</span>
                            <?php elseif (!$orden_ok): ?>
                                <span style="color:#e65100;font-size:13px;"><i class="fas fa-lock"></i> Espera Producción</span>
                            <?php else: ?>
                                <span style="color:#999;font-size:13px;"><i class="fas fa-hourglass-half"></i> Pendiente de <?= $rol === 'produccion' ? 'Producción' : 'Calidad' ?></span>
                            <?php endif; ?>
                        <?php elseif ($estado === 'no_aplica'): ?>
                            <span style="font-size:13px;color:#666;"><i class="fas fa-minus-circle"></i> No Aplica</span>
                        <?php else: ?>
                            <span style="font-size:13px;color:#2E7D32;"><i class="fas fa-check-circle"></i> Conforme</span><br>
                            <span style="font-size:12px;color:#888;"><?= htmlspecialchars($p["vobo_{$rol}_por_nombre"] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="border:1px solid #ccc;padding:6px;text-align:center;font-size:13px;">
                        <?= $fecha ? date('d/m/Y H:i', strtotime($fecha)) : '—' ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // La firma final solo se habilita cuando ya no queda ningún paso sin resolver en la
        // columna correspondiente (conforme o no aplica en los 15 renglones).
        $hvProduccionCompleta = hv_produccion_completa($db, $hojaId);
        $hvCalidadCompleta    = hv_calidad_completa($db, $hojaId);
        ?>
        <div style="border:1px solid #333;background:#fff;font-size:12px;">
            <div style="display:flex;">
                <div style="flex:1;padding:6px 10px;border-right:1px solid #333;">N° retrabajos / 8 = <strong><?= number_format($hoja['n_retrabajos'] / 8, 2) ?></strong></div>
                <div style="flex:1;padding:6px 10px;">N° reprocesos / 8 = <strong><?= number_format($hoja['n_reprocesos'] / 8, 2) ?></strong></div>
            </div>
            <?php if ($hoja['ciclo_rechazo'] > 0 && $hoja['resultado'] === 'pendiente'): ?>
            <div style="border-top:1px solid #333;background:#fff3e0;color:#e65100;padding:6px 10px;font-size:11px;">
                <i class="fas fa-rotate-left"></i> Calidad rechazó el cierre (<?= $hoja['ciclo_rechazo'] ?>×) — se reiniciaron todos los pasos, hay que volver a hacer las pruebas.
            </div>
            <?php endif; ?>
            <?php if ($hoja['resultado'] === 'aceptado'): ?>
            <div style="border-top:1px solid #333;background:#e8f5e9;color:#2E7D32;padding:8px 10px;text-align:center;font-weight:800;">
                <i class="fas fa-check-circle"></i> ACEPTADA — Hoja Viajera cerrada
            </div>
            <?php else: ?>
            <div style="display:flex;border-top:1px solid #333;">
                <div style="flex:1;padding:8px 10px;text-align:center;border-right:1px solid #333;">
                    <?php if ($hoja['resultado_produccion'] === 'pendiente' && $rolActor === 'produccion' && $hvProduccionCompleta): ?>
                    <form method="POST" action="api/hoja_viajera.php" style="display:inline-flex;gap:8px;">
                        <input type="hidden" name="accion" value="finalizar_produccion">
                        <input type="hidden" name="hoja_id" value="<?= $hojaId ?>">
                        <input type="hidden" name="return_url" value="<?= $returnUrl ?>&hoja_id=<?= $hojaId ?>">
                        <button type="submit" name="resultado" value="rechazado" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Rechazado</button>
                        <button type="submit" name="resultado" value="aceptado" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Aceptado</button>
                    </form>
                    <?php elseif ($hoja['resultado_produccion'] === 'pendiente' && !$hvProduccionCompleta): ?>
                        <span style="color:#999;font-size:11px;"><i class="fas fa-list-check"></i> Faltan pasos por resolver</span>
                    <?php elseif ($hoja['resultado_produccion'] === 'pendiente'): ?>
                        <span style="color:#999;font-size:12px;"><i class="fas fa-hourglass-half"></i> Pendiente</span>
                    <?php else: ?>
                        <strong style="color:<?= $hoja['resultado_produccion']==='aceptado'?'#2E7D32':'#c62828' ?>;"><?= ucfirst($hoja['resultado_produccion']) ?></strong>
                    <?php endif; ?>
                </div>
                <div style="flex:1;padding:8px 10px;text-align:center;">
                    <?php if ($hoja['resultado_produccion'] !== 'aceptado'): ?>
                        <span style="color:#999;font-size:12px;"><i class="fas fa-lock"></i> Espera Producción</span>
                    <?php elseif ($hoja['resultado_calidad'] === 'pendiente' && $rolActor === 'calidad' && $hvCalidadCompleta): ?>
                    <form method="POST" action="api/hoja_viajera.php" style="display:inline-flex;gap:8px;">
                        <input type="hidden" name="accion" value="finalizar_calidad">
                        <input type="hidden" name="hoja_id" value="<?= $hojaId ?>">
                        <input type="hidden" name="return_url" value="<?= $returnUrl ?>&hoja_id=<?= $hojaId ?>">
                        <button type="submit" name="resultado" value="rechazado" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Rechazado</button>
                        <button type="submit" name="resultado" value="aceptado" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Aceptado</button>
                    </form>
                    <?php elseif ($hoja['resultado_calidad'] === 'pendiente' && !$hvCalidadCompleta): ?>
                        <span style="color:#999;font-size:11px;"><i class="fas fa-list-check"></i> Faltan pasos por resolver</span>
                    <?php elseif ($hoja['resultado_calidad'] === 'pendiente'): ?>
                        <span style="color:#999;font-size:12px;"><i class="fas fa-hourglass-half"></i> Pendiente</span>
                    <?php else: ?>
                        <strong style="color:<?= $hoja['resultado_calidad']==='aceptado'?'#2E7D32':'#c62828' ?>;"><?= ucfirst($hoja['resultado_calidad']) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div style="display:flex;border-top:1px solid #333;">
                <div style="flex:1;padding:6px 10px;border-right:1px solid #333;text-align:center;font-weight:700;">Producción</div>
                <div style="flex:1;padding:6px 10px;text-align:center;font-weight:700;">Calidad</div>
            </div>
        </div>

        <div style="border:1px solid #333;margin-top:6px;font-family:Arial, sans-serif;">
            <div style="display:flex;">
                <div style="flex:1;border-right:1px solid #333;text-align:center;padding:3px 6px;font-size:11px;font-weight:bold;">DOC-PRD-FO-01A</div>
                <div style="flex:1;border-right:1px solid #333;text-align:center;padding:3px 6px;font-size:11px;">Revisión No: <strong>3</strong></div>
                <div style="flex:1;border-right:1px solid #333;text-align:center;padding:3px 6px;font-size:11px;">Fecha: <strong>15 / Ago / 21</strong></div>
                <div style="flex:1;text-align:center;padding:3px 6px;font-size:11px;">Página: <strong>1</strong></div>
            </div>
            <div style="text-align:center;padding:4px 8px;font-size:11px;color:#333;">
                Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
            </div>
        </div>
    </div>
    <?php
}
