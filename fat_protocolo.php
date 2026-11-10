<?php
/**
 * Vista dividida para generar el Protocolo FAT: la Orden de Producción tal cual (sin tocar,
 * embebida en iframe) a la izquierda, el panel de generación del Protocolo FAT a la derecha --
 * mismo patrón de split-view ya usado en proyecto.php (Resumen del Proyecto + PDF).
 */
require_once 'includes/config.php';
require_once 'includes/fat_protocolo.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$id = intval($_GET['id'] ?? 0);
$db = getDB();

$orden = null;
if ($id > 0) {
    $s = $db->prepare("SELECT * FROM ordenes_produccion WHERE id = ?");
    $s->execute([$id]);
    $orden = $s->fetch();
}

if (!$orden) {
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;">
        <h2>Orden de Producción no encontrada</h2>
        <a href="ingenieria.php?seccion=fat">← Volver</a>
    </body></html>';
    exit();
}

$fatDisponibles    = !empty($orden['proyecto_id']) ? listarPruebasFatDeProyecto($db, (int)$orden['proyecto_id']) : [];
$fatProtocoloDatos = obtenerDatosFatProtocolo($db, (int)$orden['id']);
$fatPlantillas     = $db->query("SELECT id, nombre, checklist_json FROM fat_plantillas ORDER BY nombre")->fetchAll();

// ── Filtro: Etapa 2 (datos generales) guardada + Etapa 5 (checklist) completa, para no
//    dejar generar un Word/PDF a medias.
$etapa2Guardada = !empty($fatProtocoloDatos['protocolo_id']);
$pasosEtapa5    = $fatProtocoloDatos['pasos'] ?? [];
$etapa5Completa = fatProtocoloEtapa5Completa($pasosEtapa5);
$puedeGenerar   = $etapa2Guardada && $etapa5Completa;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Protocolo FAT — <?= htmlspecialchars($orden['numero_proyecto'] ?? $orden['id']) ?> — EMPRESA EJEMPLO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif; background: #f0f4f8; min-height: 100vh; color: #111; }

        .top-bar { background: linear-gradient(135deg, #003d5c, #005686); padding: 14px 30px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 10px rgba(0,61,92,.25); }
        .top-bar h1 { color: #fff; font-size: 1.05rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .btn-action { display: inline-flex; align-items: center; gap: 7px; padding: 8px 18px; border-radius: 8px; font-family: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; border: 2px solid rgba(255,255,255,.4); color: #fff; background: rgba(255,255,255,.15); transition: background .2s; text-decoration: none !important; }
        .btn-action:hover { background: rgba(255,255,255,.28); }

        .page-wrap { padding: 16px 16px 0; }

        .proy-split-wrap { display: flex; gap: 0; width: 100%; height: calc(100vh - 78px); overflow: hidden; }
        .proy-split-panel { display: flex; flex-direction: column; background: #fff; border: 1px solid #dde4ec; border-radius: 12px; min-width: 0; transition: flex .3s ease; overflow: hidden; }
        .proy-split-panel.panel-izq { flex: 1; margin-right: 8px; }
        .proy-split-panel.panel-der { flex: 1; margin-left: 8px; }
        .proy-split-panel.maximized { flex: 2 !important; }
        .proy-split-panel.minimized { flex: 0 !important; border: none; opacity: 0; pointer-events: none; overflow: hidden; }

        .psp-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: #f4f7fb; border-bottom: 2px solid #e0e8f0; flex-shrink: 0; gap: 8px; }
        .psp-header h3 { font-size: .92rem; font-weight: 700; color: #003d5c; display: flex; align-items: center; gap: 8px; margin: 0; white-space: nowrap; }
        .psp-body { flex: 1; overflow-y: auto; padding: 18px 20px; }
        .psp-body.no-pad { padding: 0; }
        .psp-body iframe { width: 100%; height: 100%; border: none; display: block; }
        .btn-max { background: none; border: 1px solid #c5d4e0; border-radius: 6px; padding: 4px 8px; cursor: pointer; color: #555; font-size: .78rem; transition: all .15s; }
        .btn-max:hover { background: #005686; color: #fff; border-color: #005686; }

        /* ── Panel derecho: generación del Protocolo FAT ── */
        .fat-protocolo-empty { font-size: .82rem; color: #666; }
        .fat-protocolo-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
        .fat-protocolo-row label { font-size: .78rem; font-weight: 600; color: #444; }
        .fat-protocolo-row select { padding: 6px 10px; border: 1.5px solid #ccc; border-radius: 6px; font-family: inherit; font-size: .8rem; min-width: 220px; flex: 1; }
        .fat-protocolo-hint { font-size: .72rem; color: #888; margin-bottom: 14px; }
        .fat-protocolo-grupo-title { font-weight: 700; font-size: .78rem; color: #003d5c; text-transform: uppercase; letter-spacing: .5px; margin: 14px 0 6px; border-bottom: 1.5px solid #d0dce8; padding-bottom: 4px; }
        .fat-protocolo-campo { margin-bottom: 8px; }
        .fat-protocolo-campo label { display: block; font-size: .68rem; font-weight: 600; color: #555; margin-bottom: 2px; }
        .fat-protocolo-campo input { width: 100%; padding: 6px 8px; border: 1.5px solid #ccc; border-radius: 5px; font-family: inherit; font-size: .78rem; }
        .fat-protocolo-acciones { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .btn-op { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 8px; font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer; border: 1.5px solid #003d5c; color: #003d5c; background: #fff; text-decoration: none !important; }
        .btn-op:hover { background: #f0f6fb; }
        .btn-op.primario { background: #005686; color: #fff; border-color: #005686; }
        .btn-op.primario:hover { background: #003d5c; }
        .btn-op:disabled { opacity: .6; cursor: default; }
        .fat-protocolo-msg { font-size: .78rem; color: #2e7d32; font-weight: 600; }
        .fat-protocolo-msg.error { color: #c62828; }
        .btn-op.disabled { opacity: .5; cursor: not-allowed; pointer-events: none; }

        /* ── Pestañas del panel derecho ── */
        .fat-tabs-bar { display: flex; gap: 4px; padding: 0 20px; border-bottom: 1px solid #e0e8f0; background: #f8fafc; flex-shrink: 0; }
        .fat-tab-btn { padding: 10px 16px; border: none; background: none; font-family: inherit; font-size: .8rem; font-weight: 700; color: #667; cursor: pointer; border-bottom: 3px solid transparent; }
        .fat-tab-btn.active { color: #003d5c; border-bottom-color: #005686; }
        .fat-tab-btn:disabled { color: #bbb; cursor: not-allowed; }
        .fat-tab-panel { display: none; }
        .fat-tab-panel.active { display: block; }
        .fat-tab-locked { font-size: .82rem; color: #888; }

        /* ── Etapa 5: constructor de pasos (título/instrucciones/campos -- sin captura de
           valores, el documento se imprime y se llena a mano) ── */
        .etapa5-paso-card { border: 1px solid #d0dce8; border-radius: 8px; padding: 12px 14px; background: #f9fbfc; }
        .etapa5-paso-header { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
        .etapa5-paso-header .num { flex: 0 0 22px; text-align: center; font-weight: 700; color: #003d5c; font-size: .85rem; }
        .etapa5-paso-header input[type=text] { flex: 1; font-weight: 600; padding: 6px 8px; border: 1.5px solid #ccc; border-radius: 5px; font-family: inherit; font-size: .82rem; }
        .etapa5-paso-card textarea { width: 100%; padding: 6px 8px; border: 1.5px solid #ccc; border-radius: 5px; font-family: inherit; font-size: .78rem; margin-bottom: 8px; resize: vertical; }
        .etapa5-campo-row { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; flex-wrap: wrap; }
        .etapa5-campo-row select, .etapa5-campo-row input[type=text] { padding: 5px 8px; border: 1.5px solid #ccc; border-radius: 5px; font-family: inherit; font-size: .76rem; }
        .btn-icon-danger { background: #fdecea; color: #c62828; border: 1px solid #f3b8b3; border-radius: 5px; padding: 4px 8px; cursor: pointer; font-size: .75rem; line-height: 1; }
        .btn-icon-danger:hover { background: #fadbd8; }
        .btn-op.small { padding: 5px 12px; font-size: .74rem; }
    </style>
</head>
<body>

<div class="top-bar">
    <h1><i class="fas fa-clipboard-check"></i> Protocolo FAT — <?= htmlspecialchars($orden['numero_proyecto'] ?? '') ?></h1>
    <a href="ingenieria.php?seccion=fat" class="btn-action"><i class="fas fa-arrow-left"></i> Volver</a>
</div>

<div class="page-wrap">
<div class="proy-split-wrap">

    <!-- ── PANEL IZQUIERDO: Orden de Producción (sin tocar) ── -->
    <div class="proy-split-panel panel-izq" id="panelOpFat">
        <div class="psp-header">
            <h3><i class="fas fa-file-invoice"></i> Orden de Producción</h3>
            <button class="btn-max" onclick="toggleSplitMax('panelOpFat','panelFatGen')" title="Maximizar">
                <i class="fas fa-expand-alt"></i>
            </button>
        </div>
        <div class="psp-body no-pad">
            <iframe src="ver_orden_produccion.php?id=<?= (int)$orden['id'] ?>"></iframe>
        </div>
    </div>

    <!-- ── PANEL DERECHO: Generación del Protocolo FAT ── -->
    <div class="proy-split-panel panel-der" id="panelFatGen">
        <div class="psp-header">
            <h3><i class="fas fa-file-shield"></i> Generar FAT</h3>
            <button class="btn-max" onclick="toggleSplitMax('panelFatGen','panelOpFat')" title="Maximizar">
                <i class="fas fa-expand-alt"></i>
            </button>
        </div>
        <?php if (!empty($fatDisponibles)): ?>
        <div class="fat-tabs-bar">
            <button type="button" class="fat-tab-btn active" id="tabBtnFat" onclick="cambiarTabFat('fat')"><i class="fas fa-file-shield"></i> Generar FAT</button>
            <button type="button" class="fat-tab-btn" id="tabBtnEtapa5" onclick="cambiarTabFat('etapa5')" <?= $etapa2Guardada ? '' : 'disabled title="Guarda primero los datos de la Etapa 2"' ?>><i class="fas fa-list-check"></i> Generar Etapa 5</button>
            <button type="button" class="fat-tab-btn" id="tabBtnSubirFat" onclick="cambiarTabFat('subirfat')"><i class="fas fa-upload"></i> Subir FAT</button>
        </div>
        <?php endif; ?>
        <div class="psp-body">
            <?php if (empty($fatDisponibles)): ?>
            <!-- ── Crear la primera Prueba FAT del proyecto -- aquí mismo, sin salir a Ingeniería ── -->
            <div class="fat-protocolo-grupo-title" style="margin-top:0;">Crear Prueba FAT</div>
            <div class="fat-protocolo-campo">
                <label>Título *</label>
                <input type="text" id="fp5CrearTitulo" value="FAT — <?= htmlspecialchars($orden['numero_proyecto'] ?? '') ?>">
            </div>
            <div style="display:flex;gap:10px;">
                <div class="fat-protocolo-campo" style="flex:1;">
                    <label>Fecha programada</label>
                    <input type="date" id="fp5CrearFechaProg">
                </div>
                <div class="fat-protocolo-campo" style="flex:1;">
                    <label>Fecha realizada</label>
                    <input type="date" id="fp5CrearFechaReal">
                </div>
            </div>
            <div class="fat-protocolo-campo">
                <label>Descripción / Notas</label>
                <textarea id="fp5CrearDescripcion" rows="2" style="width:100%;padding:6px 8px;border:1.5px solid #ccc;border-radius:5px;font-family:inherit;font-size:.78rem;resize:vertical;" placeholder="Observaciones generales..."></textarea>
            </div>

            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:14px 0 12px;">
                <div>
                    <div class="fat-protocolo-grupo-title" style="margin:0;border:none;padding:0;">Pasos de la prueba — Etapa 5</div>
                    <p class="fat-protocolo-hint" style="margin:2px 0 0;">Solo se define título, instrucciones y campos de captura de cada prueba.</p>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <select id="fp5PlantillaSel" onchange="fp5CargarPlantilla(this.value)">
                        <option value="">— Cargar desde plantilla —</option>
                        <?php foreach ($fatPlantillas as $pl): ?>
                        <option value='<?= htmlspecialchars($pl['checklist_json'], ENT_QUOTES) ?>' data-id="<?= (int)$pl['id'] ?>" data-nombre="<?= htmlspecialchars($pl['nombre'], ENT_QUOTES) ?>"><?= htmlspecialchars($pl['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($fatPlantillas): ?>
                    <button type="button" class="btn-icon-danger" onclick="fp5EliminarPlantillaSel()" title="Eliminar la plantilla seleccionada"><i class="fas fa-trash"></i></button>
                    <?php endif; ?>
                </div>
            </div>
            <div id="fp5PasosContainer" style="display:flex;flex-direction:column;gap:12px;margin-bottom:10px;"></div>
            <button type="button" class="btn-op small" onclick="fp5AddPaso()"><i class="fas fa-plus"></i> Agregar prueba</button>

            <div class="fat-protocolo-acciones">
                <button type="button" class="btn-op" onclick="fp5GuardarPlantilla()"><i class="fas fa-save"></i> Guardar estos pasos como plantilla</button>
                <button type="button" class="btn-op primario" id="btnCrearFat"><i class="fas fa-check"></i> Crear FAT</button>
            </div>
            <span id="crearFatMsg" class="fat-protocolo-msg"></span>
            <?php else: ?>

            <!-- ── Pestaña: Generar FAT (Etapa 2 + exportación) ── -->
            <div class="fat-tab-panel active" id="tabFat">
                <div class="fat-protocolo-row">
                    <label for="fatSelect">Prueba FAT (Etapa 5):</label>
                    <select id="fatSelect">
                        <?php foreach ($fatDisponibles as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= ((int)($fatProtocoloDatos['pruebas_fat']['id'] ?? 0) === (int)$f['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['folio']) ?> — <?= htmlspecialchars($f['titulo']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn-op primario" id="btnGenerarIA" style="margin-bottom:6px;"><i class="fas fa-wand-magic-sparkles"></i> Generar datos con IA</button>
                <p class="fat-protocolo-hint">La IA solo llena lo que encuentre explícito en la descripción de la Orden de Producción — revisa y corrige antes de exportar.</p>

                <div class="fat-protocolo-grupo-title">Actuador</div>
                <?php foreach (['marca'=>'Marca','modelo'=>'Modelo','no_serie'=>'No. de serie','tag'=>'TAG','tipo'=>'Tipo','presion_operacion'=>'Presión de operación','tipo_suministro'=>'Tipo de suministro','falla_segura'=>'Falla segura','tipo_operacion_manual'=>'Tipo de operación manual'] as $campo => $etiqueta): ?>
                <div class="fat-protocolo-campo">
                    <label><?= $etiqueta ?></label>
                    <input type="text" data-grupo="actuador" data-campo="<?= $campo ?>" value="<?= htmlspecialchars($fatProtocoloDatos['datos_generales']['actuador'][$campo] ?? '') ?>">
                </div>
                <?php endforeach; ?>

                <div class="fat-protocolo-grupo-title">Válvula</div>
                <?php foreach (['marca'=>'Marca','tag'=>'TAG','tipo'=>'Tipo de válvula','tamano'=>'Tamaño','clase'=>'Clase','modelo'=>'Modelo','no_serie'=>'No. de serie'] as $campo => $etiqueta): ?>
                <div class="fat-protocolo-campo">
                    <label><?= $etiqueta ?></label>
                    <input type="text" data-grupo="valvula" data-campo="<?= $campo ?>" value="<?= htmlspecialchars($fatProtocoloDatos['datos_generales']['valvula'][$campo] ?? '') ?>">
                </div>
                <?php endforeach; ?>

                <div class="fat-protocolo-acciones">
                    <button type="button" class="btn-op" id="btnGuardarDatosFat"><i class="fas fa-save"></i> Guardar datos</button>
                    <?php if ($puedeGenerar): ?>
                    <a class="btn-op" id="linkDocxFat" href="api/exportar_docx_fat_protocolo.php?orden_produccion_id=<?= (int)$orden['id'] ?>"><i class="fas fa-file-word"></i> Descargar Word FAT</a>
                    <a class="btn-op" id="linkDocxOsat" href="api/exportar_docx_osat_protocolo.php?orden_produccion_id=<?= (int)$orden['id'] ?>"><i class="fas fa-file-word"></i> Descargar Word OSAT</a>
                    <?php else: ?>
                    <span class="btn-op disabled" title="Faltan datos"><i class="fas fa-file-word"></i> Descargar Word FAT</span>
                    <span class="btn-op disabled" title="Faltan datos"><i class="fas fa-file-word"></i> Descargar Word OSAT</span>
                    <?php endif; ?>
                </div>
                <?php if (!$puedeGenerar): ?>
                <p class="fat-protocolo-hint" style="color:#c62828;">
                    <i class="fas fa-triangle-exclamation"></i>
                    Para poder descargar el Word necesitas: <?= $etapa2Guardada ? '' : '<strong>guardar los datos de la Etapa 2</strong>' ?><?= (!$etapa2Guardada && !$etapa5Completa) ? ' y ' : '' ?><?= $etapa5Completa ? '' : '<strong>definir los pasos de la Etapa 5</strong>' ?>.
                </p>
                <?php endif; ?>
                <span id="fatProtocoloMsg" class="fat-protocolo-msg"></span>
            </div>

            <!-- ── Pestaña: Generar Etapa 5 (constructor de pasos -- título/instrucciones/campos,
                 sin captura de valores: el documento se imprime y se llena a mano) ── -->
            <div class="fat-tab-panel" id="tabEtapa5">
                <?php if (!$etapa2Guardada): ?>
                <p class="fat-tab-locked">Guarda primero los datos de la Etapa 2 en la pestaña "Generar FAT".</p>
                <?php elseif (empty($fatProtocoloDatos['pruebas_fat'])): ?>
                <p class="fat-tab-locked">Selecciona y guarda una Prueba FAT en la pestaña "Generar FAT" primero.</p>
                <?php else: ?>
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                    <div>
                        <div class="fat-protocolo-grupo-title" style="margin:0;border:none;padding:0;">Pasos de la prueba</div>
                        <p class="fat-protocolo-hint" style="margin:2px 0 0;">Solo se define título, instrucciones y campos de captura de cada prueba — este documento se imprime y se llena a mano durante la prueba física.</p>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <select id="fp5PlantillaSel" onchange="fp5CargarPlantilla(this.value)">
                            <option value="">— Cargar desde plantilla —</option>
                            <?php foreach ($fatPlantillas as $pl): ?>
                            <option value='<?= htmlspecialchars($pl['checklist_json'], ENT_QUOTES) ?>' data-id="<?= (int)$pl['id'] ?>" data-nombre="<?= htmlspecialchars($pl['nombre'], ENT_QUOTES) ?>"><?= htmlspecialchars($pl['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($fatPlantillas): ?>
                        <button type="button" class="btn-icon-danger" onclick="fp5EliminarPlantillaSel()" title="Eliminar la plantilla seleccionada"><i class="fas fa-trash"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="fp5PasosContainer" style="display:flex;flex-direction:column;gap:12px;margin-bottom:10px;"></div>
                <button type="button" class="btn-op small" onclick="fp5AddPaso()"><i class="fas fa-plus"></i> Agregar prueba</button>
                <div class="fat-protocolo-acciones">
                    <button type="button" class="btn-op" onclick="fp5GuardarPlantilla()"><i class="fas fa-save"></i> Guardar estos pasos como plantilla</button>
                    <button type="button" class="btn-op primario" id="btnGuardarEtapa5"><i class="fas fa-save"></i> Guardar Etapa 5</button>
                </div>
                <span id="etapa5Msg" class="fat-protocolo-msg"></span>
                <script>var fp5PasosIniciales = <?= json_encode($pasosEtapa5, JSON_UNESCAPED_UNICODE) ?>;</script>
                <?php endif; ?>
            </div>

            <!-- ── Pestaña: Subir FAT (adjuntar el FAT ya impreso, llenado a mano y firmado en
                 la prueba física -- escaneo/foto o el mismo Word/PDF firmado digitalmente) ── -->
            <div class="fat-tab-panel" id="tabSubirFat">
                <?php $archivoSubido = $fatProtocoloDatos['archivo_subido'] ?? null; ?>
                <?php $archivoFirmado = $archivoSubido && $archivoSubido['revision_estado'] === 'aprobado'; ?>
                <?php if ($archivoSubido): ?>
                <p class="fat-protocolo-hint" style="color:#2e7d32;margin-bottom:8px;">
                    <i class="fas fa-circle-check"></i>
                    Ya hay un FAT subido: <a href="uploads/<?= htmlspecialchars($archivoSubido['ruta']) ?>" target="_blank"><?= htmlspecialchars($archivoSubido['nombre']) ?></a>
                    (<?= htmlspecialchars(date('d/m/Y H:i', strtotime($archivoSubido['fecha']))) ?>)
                </p>
                <?php if ($archivoFirmado): ?>
                <p class="fat-protocolo-hint" style="color:#2e7d32;font-weight:600;">
                    <i class="fas fa-check-circle"></i> Calidad ya subió el documento firmado -- Ingeniería ya no puede reemplazarlo.
                </p>
                <?php else: ?>
                <p class="fat-protocolo-hint"><i class="fas fa-clock"></i> Pendiente de que Calidad suba el documento realmente firmado (después de la prueba física).</p>
                <p class="fat-protocolo-hint">Subir otro archivo reemplaza este.</p>
                <?php endif; ?>
                <?php else: ?>
                <p class="fat-protocolo-hint">Sube el FAT ya impreso y llenado a mano (sin firmar todavía) -- escaneo/foto, o el mismo Word/PDF. Formatos: PDF, Word (doc/docx), imagen (png/jpg). Cuando ya se haga la prueba física, Calidad sube el documento realmente firmado.</p>
                <?php endif; ?>
                <?php if (!$archivoFirmado): ?>
                <div class="fat-protocolo-campo">
                    <input type="file" id="fatArchivoInput" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
                </div>
                <div class="fat-protocolo-acciones">
                    <button type="button" class="btn-op primario" id="btnSubirFat"><i class="fas fa-upload"></i> Subir FAT</button>
                </div>
                <span id="subirFatMsg" class="fat-protocolo-msg"></span>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<script>
// Declarado arriba de todo -- el IIFE de abajo llama a fp5AddPaso() de forma síncrona al cargar
// la página, así que fp5PasoCount debe existir ya inicializado (var sin asignación aquí abajo
// quedaría en undefined hasta que la línea de asignación se ejecutara, dando NaN en el número).
var fp5PasoCount = 0;

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
}

function cambiarTabFat(tab) {
    document.getElementById('tabFat').classList.toggle('active', tab === 'fat');
    document.getElementById('tabEtapa5').classList.toggle('active', tab === 'etapa5');
    document.getElementById('tabSubirFat').classList.toggle('active', tab === 'subirfat');
    document.getElementById('tabBtnFat').classList.toggle('active', tab === 'fat');
    document.getElementById('tabBtnEtapa5').classList.toggle('active', tab === 'etapa5');
    document.getElementById('tabBtnSubirFat').classList.toggle('active', tab === 'subirfat');
}

// ── Protocolo FAT ──────────────────────────────────────────────────────────
(function () {
    var ordenId = <?= json_encode((int)$orden['id']) ?>;
    var btnIA   = document.getElementById('btnGenerarIA');
    var btnGuardar = document.getElementById('btnGuardarDatosFat');
    var msg     = document.getElementById('fatProtocoloMsg');

    function mostrarMsg(texto, esError) {
        if (!msg) return;
        msg.textContent = texto;
        msg.className = 'fat-protocolo-msg' + (esError ? ' error' : '');
    }

    function leerCampos() {
        // Escopado a #tabFat -- el input type=file de la pestaña "Subir FAT" (línea ~323)
        // comparte la clase .fat-protocolo-campo pero no tiene data-grupo/data-campo, así que
        // un selector sin escopar aquí tronaba con "Cannot set properties of undefined".
        var datos = { actuador: {}, valvula: {} };
        document.querySelectorAll('#tabFat .fat-protocolo-campo input').forEach(function (input) {
            datos[input.dataset.grupo][input.dataset.campo] = input.value.trim() || null;
        });
        return datos;
    }

    function llenarCampos(datos) {
        document.querySelectorAll('#tabFat .fat-protocolo-campo input').forEach(function (input) {
            var v = (datos && datos[input.dataset.grupo] && datos[input.dataset.grupo][input.dataset.campo]) || '';
            input.value = v;
        });
    }

    if (btnIA) {
        btnIA.addEventListener('click', function () {
            btnIA.disabled = true;
            btnIA.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analizando…';
            mostrarMsg('');
            var fd = new FormData();
            fd.append('orden_produccion_id', ordenId);
            fetch('api/analizar_fat_datos.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        llenarCampos(d.datos_generales);
                        mostrarMsg('Datos generados. Revisa y corrige antes de guardar.');
                    } else {
                        mostrarMsg(d.error || 'No se pudo analizar', true);
                    }
                })
                .catch(function () { mostrarMsg('Error de conexión', true); })
                .finally(function () {
                    btnIA.disabled = false;
                    btnIA.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generar datos con IA';
                });
        });
    }

    if (btnGuardar) {
        btnGuardar.addEventListener('click', function () {
            btnGuardar.disabled = true;
            mostrarMsg('');
            var fd = new FormData();
            fd.append('orden_produccion_id', ordenId);
            fd.append('pruebas_fat_id', document.getElementById('fatSelect').value);
            fd.append('datos_generales', JSON.stringify(leerCampos()));
            fetch('api/fat_protocolo_datos.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        // Recarga para recalcular el filtro de Etapa 2/5 (habilita pestaña y descargas).
                        window.location.reload();
                    } else {
                        mostrarMsg(d.error || 'No se pudo guardar', true);
                        btnGuardar.disabled = false;
                    }
                })
                .catch(function () { mostrarMsg('Error de conexión', true); btnGuardar.disabled = false; });
        });
    }

    var btnGuardarEtapa5 = document.getElementById('btnGuardarEtapa5');
    if (btnGuardarEtapa5) {
        (typeof fp5PasosIniciales !== 'undefined' ? fp5PasosIniciales : []).forEach(fp5AddPaso);

        btnGuardarEtapa5.addEventListener('click', function () {
            var pasos = fp5RecolectarPasos();
            var msgE5 = document.getElementById('etapa5Msg');
            if (!pasos.length) {
                msgE5.textContent = 'Agrega al menos una prueba con título antes de guardar.';
                msgE5.className = 'fat-protocolo-msg error';
                return;
            }
            btnGuardarEtapa5.disabled = true;
            var fd = new FormData();
            fd.append('fat_id', <?= json_encode((int)($fatProtocoloDatos['pruebas_fat']['id'] ?? 0)) ?>);
            fd.append('orden_produccion_id', ordenId);
            fd.append('pasos_json', JSON.stringify(pasos));
            fetch('api/fat_protocolo_etapa5.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        // Recarga para recalcular el filtro (habilita descarga si ya quedó completa).
                        window.location.reload();
                    } else {
                        msgE5.textContent = d.error || 'No se pudo guardar';
                        msgE5.className = 'fat-protocolo-msg error';
                        btnGuardarEtapa5.disabled = false;
                    }
                })
                .catch(function () {
                    msgE5.textContent = 'Error de conexión';
                    msgE5.className = 'fat-protocolo-msg error';
                    btnGuardarEtapa5.disabled = false;
                });
        });
    }

    var btnSubirFat = document.getElementById('btnSubirFat');
    if (btnSubirFat) {
        btnSubirFat.addEventListener('click', function () {
            var input = document.getElementById('fatArchivoInput');
            var msgSubir = document.getElementById('subirFatMsg');
            if (!input.files.length) {
                msgSubir.textContent = 'Elige un archivo primero.';
                msgSubir.className = 'fat-protocolo-msg error';
                return;
            }
            btnSubirFat.disabled = true;
            msgSubir.textContent = '';
            var fd = new FormData();
            fd.append('orden_produccion_id', ordenId);
            fd.append('depto', 'ingenieria');
            fd.append('fat_archivo', input.files[0]);
            fetch('api/fat_protocolo_subir_archivo.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        // Recarga para reflejar el archivo ya subido en la parte de arriba de la pestaña.
                        window.location.reload();
                    } else {
                        msgSubir.textContent = d.error || 'No se pudo subir el archivo';
                        msgSubir.className = 'fat-protocolo-msg error';
                        btnSubirFat.disabled = false;
                    }
                })
                .catch(function () {
                    msgSubir.textContent = 'Error de conexión';
                    msgSubir.className = 'fat-protocolo-msg error';
                    btnSubirFat.disabled = false;
                });
        });
    }

    var btnCrearFat = document.getElementById('btnCrearFat');
    if (btnCrearFat) {
        btnCrearFat.addEventListener('click', function () {
            var titulo = document.getElementById('fp5CrearTitulo').value.trim();
            var pasos = fp5RecolectarPasos();
            var msgCrear = document.getElementById('crearFatMsg');
            if (!titulo) {
                msgCrear.textContent = 'El título es requerido.';
                msgCrear.className = 'fat-protocolo-msg error';
                return;
            }
            if (!pasos.length) {
                msgCrear.textContent = 'Agrega al menos una prueba con título antes de crear la FAT.';
                msgCrear.className = 'fat-protocolo-msg error';
                return;
            }
            btnCrearFat.disabled = true;
            var fd = new FormData();
            fd.append('orden_produccion_id', ordenId);
            fd.append('fat_titulo', titulo);
            fd.append('fecha_programada', document.getElementById('fp5CrearFechaProg').value);
            fd.append('fecha_realizada', document.getElementById('fp5CrearFechaReal').value);
            fd.append('fat_descripcion', document.getElementById('fp5CrearDescripcion').value);
            fd.append('pasos_json', JSON.stringify(pasos));
            fetch('api/fat_protocolo_crear.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        // Recarga: ahora ya hay una Prueba FAT -- muestra las pestañas normales.
                        window.location.reload();
                    } else {
                        msgCrear.textContent = d.error || 'No se pudo crear la FAT';
                        msgCrear.className = 'fat-protocolo-msg error';
                        btnCrearFat.disabled = false;
                    }
                })
                .catch(function () {
                    msgCrear.textContent = 'Error de conexión';
                    msgCrear.className = 'fat-protocolo-msg error';
                    btnCrearFat.disabled = false;
                });
        });
    }
})();

// ── Etapa 5: constructor de pasos (mismo patrón que ingenieria.php al crear una FAT) ──────────
// DOM APIs en vez de innerHTML para todo lo que trae texto de usuario/plantilla.

function _fp5CampoRow(campo) {
    campo = campo || { tipo: 'texto', etiqueta: '', unidad: '', valor: '' };
    var row = document.createElement('div');
    row.className = 'etapa5-campo-row';

    var tipoSel = document.createElement('select');
    tipoSel.className = 'fp5-campo-tipo';
    [['texto', 'Texto/número'], ['opciones', 'Opciones']].forEach(function (par) {
        var o = document.createElement('option');
        o.value = par[0]; o.textContent = par[1];
        tipoSel.appendChild(o);
    });
    tipoSel.value = campo.tipo === 'opciones' ? 'opciones' : 'texto';

    var etiquetaInp = document.createElement('input');
    etiquetaInp.type = 'text';
    etiquetaInp.className = 'fp5-campo-etiqueta';
    etiquetaInp.placeholder = 'Etiqueta (ej. Tiempo de operación)';
    etiquetaInp.style.cssText = 'flex:1;min-width:160px;';
    etiquetaInp.value = campo.etiqueta || '';

    var unidadInp = document.createElement('input');
    unidadInp.type = 'text';
    unidadInp.className = 'fp5-campo-unidad';
    unidadInp.placeholder = 'Unidad (opcional, ej. PSI)';
    unidadInp.style.cssText = 'flex:0 0 120px;';
    unidadInp.value = campo.unidad || '';

    var opcionesInp = document.createElement('input');
    opcionesInp.type = 'text';
    opcionesInp.className = 'fp5-campo-opciones';
    opcionesInp.placeholder = 'Opciones separadas por coma';
    opcionesInp.style.cssText = 'flex:0 0 220px;';
    opcionesInp.value = (campo.opciones || ['Abierto', 'Cerrado']).join(',');

    function actualizarVisibilidad() {
        var esOpciones = tipoSel.value === 'opciones';
        unidadInp.style.display = esOpciones ? 'none' : '';
        opcionesInp.style.display = esOpciones ? '' : 'none';
    }
    tipoSel.onchange = actualizarVisibilidad;
    actualizarVisibilidad();

    var quitarBtn = document.createElement('button');
    quitarBtn.type = 'button';
    quitarBtn.className = 'btn-icon-danger';
    quitarBtn.title = 'Quitar campo';
    quitarBtn.onclick = function () { row.remove(); };
    quitarBtn.innerHTML = '<i class="fas fa-times"></i>';

    row.appendChild(tipoSel);
    row.appendChild(etiquetaInp);
    row.appendChild(unidadInp);
    row.appendChild(opcionesInp);
    row.appendChild(quitarBtn);
    return row;
}

function _fp5PasoCard(paso) {
    paso = paso || { titulo: '', instrucciones: '', campos: [] };
    fp5PasoCount++;
    var card = document.createElement('div');
    card.className = 'etapa5-paso-card';

    var header = document.createElement('div');
    header.className = 'etapa5-paso-header';
    var numSpan = document.createElement('div');
    numSpan.className = 'num';
    numSpan.textContent = fp5PasoCount;
    var tituloInp = document.createElement('input');
    tituloInp.type = 'text';
    tituloInp.className = 'fp5-paso-titulo';
    tituloInp.placeholder = 'Título de la prueba (ej. Operación local del actuador)';
    tituloInp.value = paso.titulo || '';
    var quitarPasoBtn = document.createElement('button');
    quitarPasoBtn.type = 'button';
    quitarPasoBtn.className = 'btn-icon-danger';
    quitarPasoBtn.title = 'Quitar prueba';
    quitarPasoBtn.onclick = function () { card.remove(); };
    quitarPasoBtn.innerHTML = '<i class="fas fa-times"></i>';
    header.appendChild(numSpan);
    header.appendChild(tituloInp);
    header.appendChild(quitarPasoBtn);

    var instrTa = document.createElement('textarea');
    instrTa.className = 'fp5-paso-instrucciones';
    instrTa.rows = 2;
    instrTa.placeholder = 'Instrucciones / procedimiento de esta prueba';
    instrTa.value = paso.instrucciones || '';

    var camposDiv = document.createElement('div');
    camposDiv.className = 'fp5-paso-campos';
    camposDiv.style.marginBottom = '6px';
    (paso.campos || []).forEach(function (c) { camposDiv.appendChild(_fp5CampoRow(c)); });

    var addCampoBtn = document.createElement('button');
    addCampoBtn.type = 'button';
    addCampoBtn.className = 'btn-op small';
    addCampoBtn.innerHTML = '<i class="fas fa-plus"></i> Agregar campo de captura';
    addCampoBtn.onclick = function () { camposDiv.appendChild(_fp5CampoRow()); };

    card.appendChild(header);
    card.appendChild(instrTa);
    card.appendChild(camposDiv);
    card.appendChild(addCampoBtn);
    return card;
}

function fp5AddPaso(paso) {
    document.getElementById('fp5PasosContainer').appendChild(_fp5PasoCard(paso));
}

function fp5RecolectarPasos() {
    var pasos = [];
    document.querySelectorAll('#fp5PasosContainer .etapa5-paso-card').forEach(function (card) {
        var titulo = card.querySelector('.fp5-paso-titulo').value.trim();
        if (!titulo) return;
        var instrucciones = card.querySelector('.fp5-paso-instrucciones').value.trim();
        var campos = [];
        card.querySelectorAll('.etapa5-campo-row').forEach(function (row) {
            var etiqueta = row.querySelector('.fp5-campo-etiqueta').value.trim();
            if (!etiqueta) return;
            if (row.querySelector('.fp5-campo-tipo').value === 'opciones') {
                var opciones = row.querySelector('.fp5-campo-opciones').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                campos.push({ tipo: 'opciones', etiqueta: etiqueta, opciones: opciones.length ? opciones : ['Abierto', 'Cerrado'], valor: '' });
            } else {
                campos.push({ tipo: 'texto', etiqueta: etiqueta, unidad: row.querySelector('.fp5-campo-unidad').value.trim(), valor: '' });
            }
        });
        pasos.push({ titulo: titulo, instrucciones: instrucciones, campos: campos, resultado: null });
    });
    return pasos;
}

function fp5CargarPlantilla(jsonStr) {
    if (!jsonStr) return;
    var pasos;
    try { pasos = JSON.parse(jsonStr); } catch (e) { return; }
    var cont = document.getElementById('fp5PasosContainer');
    cont.innerHTML = '';
    fp5PasoCount = 0;
    pasos.forEach(fp5AddPaso);
}

function fp5GuardarPlantilla() {
    var pasos = fp5RecolectarPasos();
    if (!pasos.length) { alert('Agrega al menos una prueba con título antes de guardar la plantilla.'); return; }
    var nombre = prompt('Nombre de la plantilla (ej. "Actuador doble acción", "Válvula de bola"):');
    if (!nombre || !nombre.trim()) return;
    var fd = new FormData();
    fd.append('nombre', nombre.trim());
    fd.append('pasos_json', JSON.stringify(pasos));
    fetch('api/fat_protocolo_plantilla_guardar.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { alert(d.error || 'No se pudo guardar la plantilla'); return; }
            var sel = document.getElementById('fp5PlantillaSel');
            var opt = document.createElement('option');
            opt.value = d.checklist_json;
            opt.dataset.id = d.id;
            opt.dataset.nombre = d.nombre;
            opt.textContent = d.nombre;
            sel.appendChild(opt);
        })
        .catch(function () { alert('Error de conexión al guardar la plantilla'); });
}

function fp5EliminarPlantillaSel() {
    var sel = document.getElementById('fp5PlantillaSel');
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.dataset.id) { alert('Selecciona primero una plantilla de la lista.'); return; }
    if (!confirm('¿Eliminar la plantilla "' + opt.dataset.nombre + '"? Esto no afecta las pruebas FAT ya creadas con ella.')) return;
    var fd = new FormData();
    fd.append('plantilla_id', opt.dataset.id);
    fetch('api/fat_protocolo_plantilla_eliminar.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { alert(d.error || 'No se pudo eliminar'); return; }
            opt.remove();
        })
        .catch(function () { alert('Error de conexión al eliminar la plantilla'); });
}
</script>

</body>
</html>
