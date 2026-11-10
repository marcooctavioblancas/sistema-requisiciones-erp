<?php
/**
 * Módulo compartido "Generar Requisiciones" — requisiciones externas a proyecto que
 * siguen el MISMO proceso de comparación de stock que ya usan los proyectos
 * (almacen_items): por cada material se compara cantidad vs stock_disponible, se
 * notifica siempre a Almacén, y a Compras solo si algo no alcanza.
 *
 * Usado por produccion.php, compras.php, calidad.php, proyecto.php. Ingeniería tiene
 * su propia copia ya integrada al flujo de BOM (ver ingenieria.php, acción
 * crear_requisicion_ingenieria) y no usa este archivo.
 *
 * Todo el CSS/JS/ids va con prefijo genreq-/genReq para no chocar con nada ya
 * existente en los archivos que lo incluyen (varios ya tienen su propio "material-card",
 * "modal-overlay", "cerrarModal", etc. con convenciones distintas).
 */

const GENREQ_MARCAS_IMAGENES = [
    'adalet'          => 'uploads/logo/Marcas/ADALET.png',
    'adsen'           => 'uploads/logo/Marcas/Adsens.png',
    'adsens'          => 'uploads/logo/Marcas/Adsens.png',
    'ashcroft'        => 'uploads/logo/Marcas/ashcroft.png',
    'cmp'             => 'uploads/logo/Marcas/CMP (1).png',
    'dewit'           => 'uploads/logo/Marcas/dewit.png',
    'ds-lok by pmt'   => 'uploads/logo/Marcas/ds-lok.png',
    'ds-lok'          => 'uploads/logo/Marcas/ds-lok.png',
    'eaton'           => 'uploads/logo/Marcas/Eaton.png',
    'famaga'          => 'uploads/logo/Marcas/famaga.png',
    'farris'          => 'uploads/logo/Marcas/farris-logo.png',
    'hanson'          => 'uploads/logo/Marcas/HANSON2.png',
    'hy-lok'          => 'uploads/logo/Marcas/HYLOK.png',
    'hylok'           => 'uploads/logo/Marcas/HYLOK.png',
    'idec'            => 'uploads/logo/Marcas/IDEC.png',
    'klemsan'         => 'uploads/logo/Marcas/KLEMSAN.png',
    'power&genex'     => 'uploads/logo/Marcas/POWERGENEX.png',
    'powergenex'      => 'uploads/logo/Marcas/POWERGENEX.png',
    'ruelco'          => 'uploads/logo/Marcas/Ruelco.png',
    'seter'           => 'uploads/logo/Marcas/Seter.png',
    'superlok'        => 'uploads/logo/Marcas/superlok-logo.png',
    'topworx'         => 'uploads/logo/Marcas/topworks-logo.png',
    'valcontrols'     => 'uploads/logo/Marcas/VALCONTROLS.png',
    'warom'           => 'uploads/logo/Marcas/WAROM.png',
    'weldfit'         => 'uploads/logo/Marcas/Weldfit.png',
    'crouse-hinds'    => 'uploads/logo/Marcas/Eaton.png',
    'versa'           => 'uploads/logo/Marcas/VERSA.png',
    'wedmüller'       => 'uploads/logo/Marcas/weidmueller.png',
    'wedmuller'       => 'uploads/logo/Marcas/weidmueller.png',
];

function genReqMarcaImagen(?string $marca): ?string {
    if (!$marca) return null;
    return GENREQ_MARCAS_IMAGENES[strtolower(trim($marca))] ?? null;
}

/** Crea la requisición + compara stock igual que finalizar_proyecto (ingenieria.php). */
function procesarCrearRequisicionArea(PDO $db, string $area): array {
    $items_recibidos = $_POST['items'] ?? [];
    if (empty($items_recibidos)) {
        return ['ok' => false, 'mensaje' => 'No se seleccionaron materiales'];
    }
    try {
        $db->beginTransaction();
        $folio = generarFolioRequisicion();
        $prioridad = sanitize($_POST['prioridad'] ?? 'media');
        if (!in_array($prioridad, ['baja', 'media', 'alta', 'urgente'], true)) $prioridad = 'media';
        $fecha_requerida = $_POST['fecha_requerida'] ?: null;
        $notas = sanitize($_POST['notas'] ?? '');

        $proyecto_id = !empty($_POST['proyecto_id']) ? intval($_POST['proyecto_id']) : null;
        $proyecto_nombre = "Requisición externa ($area)";
        if ($proyecto_id) {
            // Revalidar aquí (no solo confiar en que el dropdown ya lo filtró) --
            // un POST armado a mano podría mandar cualquier proyecto_id.
            if (!puedeVincularRequisicionAProyecto((int) ($_SESSION['user_id'] ?? 0), $area, $proyecto_id)) {
                $db->rollBack();
                return ['ok' => false, 'mensaje' => 'No tienes permiso para vincular esta requisición a ese proyecto.'];
            }
            $stmtP = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
            $stmtP->execute([$proyecto_id]);
            $proyecto_nombre = $stmtP->fetchColumn() ?: $proyecto_nombre;
        }

        $db->prepare("INSERT INTO requisiciones (folio, usuario_id, proyecto, proyecto_id, area, solicitante, fecha_requerida, prioridad, notas_generales)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$folio, $_SESSION['user_id'], $proyecto_nombre, $proyecto_id, $area, $_SESSION['user_name'], $fecha_requerida, $prioridad, $notas]);
        $requisicion_id = (int)$db->lastInsertId();

        $item_num = 1;
        $faltantes = [];
        $material_ids_insertados = [];
        foreach ($items_recibidos as $material_id => $datos) {
            if (!isset($datos['cantidad'])) continue;
            $cantidad = intval($datos['cantidad']);
            $material_id = intval($material_id);
            if ($cantidad <= 0) continue;

            $stmtM = $db->prepare("SELECT descripcion, marca, modelo, unidad_medida, stock_disponible FROM materiales WHERE id = ?");
            $stmtM->execute([$material_id]);
            $mat = $stmtM->fetch();
            if (!$mat) continue;

            $db->prepare("INSERT INTO requisicion_detalle (requisicion_id, item_numero, material_id, cantidad, unidad_medida, descripcion, marca, modelo)
                VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$requisicion_id, $item_num, $material_id, $cantidad, $mat['unidad_medida'], $mat['descripcion'], $mat['marca'], $mat['modelo']]);

            $stock_actual = (int)$mat['stock_disponible'];
            $diferencia   = $stock_actual - $cantidad;
            $db->prepare("INSERT INTO almacen_items (proyecto_id, requisicion_id, material_id, item, descripcion, modelo, marca, cantidad_requerida, stock_actual, diferencia, fecha_agregado, agregado_por)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
               ->execute([$requisicion_id, $material_id, $mat['descripcion'], $mat['descripcion'], $mat['modelo'], $mat['marca'], $cantidad, $stock_actual, $diferencia, $_SESSION['user_id']]);

            if ($stock_actual < $cantidad) {
                $faltantes[] = "• {$mat['descripcion']}: requiere $cantidad, stock $stock_actual";
            }
            $material_ids_insertados[] = $material_id;
            $item_num++;
        }

        if ($item_num === 1) {
            $db->rollBack();
            return ['ok' => false, 'mensaje' => 'Selecciona al menos un material con cantidad mayor a 0.'];
        }

        registrarEventoRequisicion($db, $requisicion_id, 'creada', null, $_SESSION['user_id'] ?? null);

        $db->commit();

        notificarRoles(['almacen'], 'nueva_requisicion', "Nueva requisición: $folio",
            "$area generó la requisición $folio (" . ($item_num - 1) . " material(es)).",
            'almacen2.php?seccion=ver_requisicion&id=' . $requisicion_id, $_SESSION['user_id']);
        if (!empty($faltantes)) {
            notificarRoles(['compras'], 'stock_insuficiente', "Stock insuficiente: $folio",
                "Se requieren órdenes de compra:\n" . implode("\n", $faltantes), 'compras.php', $_SESSION['user_id']);
        }

        if ($proyecto_id) {
            $duplicados = detectarRequisicionesDuplicadas($db, $proyecto_id, $material_ids_insertados, $area, $requisicion_id);
            avisarDuplicado($duplicados);
            notificarDuplicado($duplicados, $folio, $area);
        }

        return ['ok' => true, 'mensaje' => "Requisición $folio generada correctamente."];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()];
    }
}

// Una sola tarjeta/sección para Generar Requisición + Mis Requisiciones (con
// cronograma) -- van juntas a propósito para no duplicar tarjetas de nav por
// algo que en el fondo es la misma cosa (pedir materiales / ver en qué van).
function renderGenerarRequisicionNavCard(string $baseUrl): void {
    ?>
    <div class="nav-card" onclick="location.href='<?= htmlspecialchars($baseUrl) ?>?seccion=generar_requisicion'">
        <div class="card-icon"><i class="fas fa-file-circle-plus"></i></div>
        <div class="card-title">Requisiciones</div>
        <div class="card-desc">Generar y ver el estado de tus solicitudes</div>
    </div>
    <?php
}

/**
 * $campoAccion: nombre del campo POST que despacha las acciones en el archivo que incluye esto ('accion' o 'action').
 * $area: mismo nombre de área que el archivo ya le pasa a procesarCrearRequisicionArea() (ej. 'Producción', 'Compras') --
 *        se usa para filtrar el selector de proyecto vía puedeVincularRequisicionAProyecto().
 */
function renderGenerarRequisicionSeccion(PDO $db, string $baseUrl, string $campoAccion = 'accion', string $area = ''): void {
    $materiales = $db->query("SELECT m.*, c.nombre as categoria_nombre, c.icono as categoria_icono FROM materiales m LEFT JOIN categorias c ON m.categoria_id = c.id WHERE m.activo = 1 ORDER BY m.descripcion")->fetchAll();
    $categorias = getCategoriasActivas($db);
    $proyectos = $db->query("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre")->fetchAll();
    $usuarioIdSesion = (int) ($_SESSION['user_id'] ?? 0);
    $proyectos = array_values(array_filter($proyectos, fn($p) => puedeVincularRequisicionAProyecto($usuarioIdSesion, $area, (int) $p['id'])));
    $marcas = array_values(array_unique(array_filter(array_map(fn($m) => $m['marca'] ?? '', $materiales))));
    sort($marcas);
    ?>
    <style>
        .genreq-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .genreq-search-wrapper { position:relative; width:280px; }
        .genreq-search-input { width:100%; padding:10px 35px 10px 15px; border:2px solid #e0e0e0; border-radius:40px; font-size:14px; }
        .genreq-search-input:focus { outline:none; border-color:#005686; box-shadow:0 0 0 3px rgba(0,86,134,.1); }
        .genreq-search-clear { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:#999; cursor:pointer; font-size:16px; display:none; }
        .genreq-search-clear.visible { display:block; }
        .genreq-btn-cat { background:#fff; border:2px solid #e0e0e0; border-radius:40px; padding:10px 20px; cursor:pointer; display:flex; align-items:center; gap:8px; font-weight:600; color:#003d5c; }
        .genreq-btn-cat:hover { border-color:#005686; color:#005686; background:#f0f8ff; }
        .genreq-select-filtro { background:#fff; border:2px solid #e0e0e0; border-radius:40px; padding:10px 16px; cursor:pointer; font-weight:600; color:#003d5c; font-size:14px; }
        .genreq-select-filtro:hover, .genreq-select-filtro:focus { border-color:#005686; color:#005686; outline:none; }
        .genreq-contador { font-size:13px; color:#777; white-space:nowrap; }
        .genreq-resumen-seleccion { font-size:13px; font-weight:700; color:#005686; background:#e3f2fd; padding:8px 16px; border-radius:20px; white-space:nowrap; display:none; }
        .genreq-btn { padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-size:14px; display:inline-flex; align-items:center; gap:8px; }
        .genreq-btn-success { background:#4CAF50; color:#fff; }
        .genreq-btn-success:hover { background:#388E3C; }
        /* Panel izquierdo (seleccionados, siempre visible) + derecho (resultados de
           búsqueda, vacío hasta que el buscador tenga texto) -- reemplaza la cuadrícula
           de tarjetas anterior por listas de filas, mismo criterio visual que un
           explorador de archivos: miniatura + nombre a la izquierda, datos secundarios
           alineados a la derecha. */
        /* align-items:flex-start (no stretch) a propósito: cada panel debe crecer según
           SU PROPIO contenido hasta SU PROPIO límite -- con stretch, el panel corto se
           forzaría a igualar la altura del largo aunque tuviera poco que mostrar. */
        .genreq-panels { display:flex; gap:16px; align-items:flex-start; margin-top:14px; }
        .genreq-panel { background:#fff; border:1px solid #e6e6e6; border-radius:10px; overflow:hidden; display:flex; flex-direction:column; }
        .genreq-panel-izq { width:340px; flex-shrink:0; }
        .genreq-panel-der { flex:1; min-width:0; }
        /* Altura medida en vivo contra la página real (no un número al tanteo): 140px ya
           los reserva .section-content para el header fijo (mismo criterio ya usado en
           produccion.php/ingenieria.php); los 204px extra son el alto real del toolbar
           sticky + la fila Proyecto/Fecha/Prioridad/Notas que va arriba de estos paneles,
           medido con getBoundingClientRect() -- constante entre los 3 módulos porque los
           tres renderizan exactamente el mismo HTML de esa parte. max-height (no height
           fijo) -- con pocos items el panel se achica a su contenido real; el scroll
           interno solo se activa si el contenido llega a ese límite de la página. */
        .genreq-panel-izq, .genreq-panel-der { max-height:calc(100vh - 344px); min-height:200px; }
        .genreq-panel-header { font-size:13px; font-weight:700; color:#003d5c; padding:12px 16px; border-bottom:1px solid #eee; background:#f7f9fb; flex-shrink:0; }
        /* Único hijo scrollable de cada panel -- el vacío/la lista/"sin resultados"/"cargar
           más" viven todos aquí adentro como bloques normales (no flex-items compitiendo
           por espacio), así que solo uno necesita estar visible a la vez y el resto no
           empuja nada. min-height:0 es necesario para que el overflow funcione dentro de
           un contenedor flex-column (si no, el navegador lo deja crecer sin límite). */
        .genreq-panel-body { flex:1; min-height:0; overflow-y:auto; }
        .genreq-panel-vacio { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:50px 24px; color:#999; height:100%; }
        .genreq-panel-vacio i { font-size:30px; margin-bottom:10px; color:#c4c4c4; }
        .genreq-panel-vacio p { font-size:13px; line-height:1.5; margin:0; }
        .genreq-fila { display:flex; align-items:center; gap:12px; padding:9px 14px; border-bottom:1px solid #f2f2f2; }
        .genreq-fila:last-child { border-bottom:none; }
        .genreq-fila:hover { background:#f7fbff; }
        .genreq-fila-img { width:38px; height:38px; flex-shrink:0; border-radius:6px; background:#f2f2f2; display:flex; align-items:center; justify-content:center; color:#b0b0b0; font-size:15px; overflow:hidden; }
        .genreq-fila-img img { width:100%; height:100%; object-fit:cover; }
        .genreq-fila-info { flex:1; min-width:0; }
        .genreq-fila-nombre { font-size:13px; font-weight:600; color:#222; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .genreq-fila-sub { font-size:11px; color:#999; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
        .genreq-fila-marca { width:150px; flex-shrink:0; font-size:11.5px; color:#777; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:none; }
        .genreq-fila-stock { width:80px; flex-shrink:0; font-size:11.5px; font-weight:600; color:#888; white-space:nowrap; display:none; }
        .genreq-fila-stock.sin-stock { color:#c62828; font-weight:700; }
        .genreq-fila-quitar { background:none; border:none; color:#c62828; cursor:pointer; font-size:14px; padding:4px 6px; flex-shrink:0; }
        .genreq-fila-quitar:hover { color:#8e0000; }
        @media (min-width:900px) {
            .genreq-fila-marca, .genreq-fila-stock { display:block; }
        }
        .genreq-cantidad-control { display:flex; align-items:center; gap:6px; flex-shrink:0; }
        .genreq-cantidad-botones { display:flex; align-items:center; gap:4px; }
        .genreq-cantidad-botones button { width:22px; height:22px; border:none; background:#003d5c; color:#fff; border-radius:4px; cursor:pointer; font-size:13px; line-height:1; }
        .genreq-cantidad-botones button:hover { background:#002c44; }
        .genreq-cantidad-valor { font-size:13px; font-weight:600; min-width:20px; text-align:center; cursor:pointer; }
        .genreq-form-group { margin:0; }
        .genreq-form-label { display:block; margin-bottom:5px; font-size:13px; font-weight:600; color:#333; }
        .genreq-form-input { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:4px; font-size:14px; }
        .genreq-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:5000; }
        .genreq-modal-overlay.active { display:flex; }
        .genreq-modal { background:#fff; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow:hidden; }
        .genreq-modal-header { background:#003d5c; color:#fff; padding:15px 20px; display:flex; justify-content:space-between; align-items:center; }
        .genreq-modal-body { padding:20px; max-height:60vh; overflow-y:auto; }
        .genreq-modal-footer { padding:15px 20px; background:#f5f5f5; display:flex; justify-content:flex-end; gap:10px; }
        .genreq-modal-close { background:none; border:none; color:#fff; font-size:20px; cursor:pointer; }
        .genreq-categoria-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:15px; }
        .genreq-categoria-card { border:2px solid #ddd; border-radius:8px; padding:20px; text-align:center; cursor:pointer; transition:all .2s; }
        .genreq-categoria-card:hover { border-color:#2196F3; background:#f0f8ff; }
        .genreq-categoria-card.selected { border-color:#2196F3; background:#e3f2fd; }
        .genreq-categoria-card i { font-size:32px; color:#666; margin-bottom:10px; display:block; }
        .genreq-categoria-card img { width:60px; height:44px; object-fit:contain; margin-bottom:6px; }
        .genreq-cat-nombre { font-weight:600; color:#333; }
    </style>

    <div class="section-content">
        <?php renderFlash(); renderAvisoDuplicado(); ?>
        <div class="sticky-container">
            <div class="header-row">
                <h2><i class="fas fa-file-circle-plus"></i> Generar Requisición</h2>
                <div class="genreq-toolbar">
                    <div class="genreq-search-wrapper">
                        <input type="text" class="genreq-search-input" id="genReqSearch" placeholder="Buscar material..." oninput="genReqBuscar(this.value)">
                        <button type="button" class="genreq-search-clear" id="genReqClearSearch" onclick="document.getElementById('genReqSearch').value='';genReqBuscar('');this.classList.remove('visible')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <span class="genreq-contador" id="genReqContador">Mostrando <?= count($materiales) ?> de <?= count($materiales) ?></span>
                    <select id="genReqCategoriaSelect" class="genreq-select-filtro" onchange="genReqCategoriaSel = this.value || null; genReqAplicarFiltros();">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="genreq-btn-cat" onclick="genReqAbrirModalMarcas()" style="background:#005686;color:#fff;"><i class="fas fa-industry"></i> Marcas</button>
                    <span class="genreq-resumen-seleccion" id="genReqResumenSeleccion"></span>
                    <button type="button" class="genreq-btn genreq-btn-success" onclick="genReqGenerar()"><i class="fas fa-paper-plane"></i> Generar Requisición</button>
                </div>
            </div>
        </div>

        <form id="genReqForm" method="POST" action="?seccion=generar_requisicion">
            <input type="hidden" name="<?= htmlspecialchars($campoAccion) ?>" value="crear_requisicion_area">

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:14px 0;background:#f7f9fb;border:1px solid #e0e8f0;border-radius:8px;padding:12px 16px;">
                <div class="genreq-form-group">
                    <label class="genreq-form-label">Proyecto (opcional)</label>
                    <select name="proyecto_id" class="genreq-form-input">
                        <option value="">Sin proyecto (requisición general)</option>
                        <?php foreach ($proyectos as $proy): ?>
                        <option value="<?= (int)$proy['id'] ?>"><?= htmlspecialchars($proy['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="genreq-form-group">
                    <label class="genreq-form-label">Fecha requerida</label>
                    <input type="date" name="fecha_requerida" class="genreq-form-input">
                </div>
                <div class="genreq-form-group">
                    <label class="genreq-form-label">Prioridad</label>
                    <select name="prioridad" class="genreq-form-input">
                        <option value="baja">Baja</option>
                        <option value="media" selected>Media</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
                <div class="genreq-form-group" style="flex:1;min-width:220px;">
                    <label class="genreq-form-label">Notas (opcional)</label>
                    <input type="text" name="notas" class="genreq-form-input" placeholder="Notas / aclaraciones">
                </div>
            </div>

            <div class="genreq-panels">
                <div class="genreq-panel genreq-panel-izq">
                    <div class="genreq-panel-header" id="genReqSelHeader">Materiales seleccionados (0)</div>
                    <div class="genreq-panel-body">
                        <div class="genreq-lista" id="genReqSeleccionList"></div>
                        <div id="genReqSelVacio" class="genreq-panel-vacio">
                            <i class="fas fa-clipboard-list"></i>
                            <p>Aún no has agregado materiales.<br>Búscalos en el panel de la derecha.</p>
                        </div>
                    </div>
                </div>
                <div class="genreq-panel genreq-panel-der">
                    <div class="genreq-panel-body">
                        <div id="genReqDerVacio" class="genreq-panel-vacio">
                            <i class="fas fa-search"></i>
                            <p>Escribe en el buscador para ver los materiales disponibles.</p>
                        </div>
                        <div class="genreq-lista" id="genReqGrid"></div>
                        <div id="genReqSinResultados" style="display:none;text-align:center;padding:50px 20px;color:#999;">
                            <i class="fas fa-box-open" style="font-size:34px;display:block;margin-bottom:12px;"></i>
                            Ningún material coincide con el filtro.
                        </div>
                        <div id="genReqCargarMasWrap" style="display:none;text-align:center;padding:16px 0;">
                            <button type="button" class="genreq-btn" id="genReqCargarMasBtn" style="background:#eef4f9;color:#003d5c;font-weight:700;" onclick="genReqCargarMas()">Cargar más materiales</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!--
        Los ~3600 materiales se mandan como datos (no como HTML repetido por
        cada tarjeta) -- antes esto pesaba ~7.5MB de HTML renderizado de una
        sola vez. Las tarjetas se arman en el navegador, en bloques de 60, a
        medida que se necesitan (filtro nuevo o "Cargar más").
    -->
    <script type="application/json" id="genReqDatosMateriales"><?= json_encode(array_map(function ($mat) {
        return [
            'id'              => (int) $mat['id'],
            'nombre'          => $mat['descripcion'] ?? '',
            'codigo'          => $mat['codigo'] ?? '',
            'categoriaId'     => (int) $mat['categoria_id'],
            'categoriaNombre' => $mat['categoria_nombre'] ?? '',
            'categoriaIcono'  => $mat['categoria_icono'] ?? 'box',
            'marca'           => $mat['marca'] ?? '',
            'modelo'          => $mat['modelo'] ?? '',
            'stock'           => (int) $mat['stock_disponible'],
            'imgMarca'        => genReqMarcaImagen($mat['marca'] ?? ''),
            'imagen'          => !empty($mat['imagen']) ? ('uploads/' . $mat['imagen']) : null,
        ];
    }, $materiales), JSON_UNESCAPED_UNICODE) ?></script>

    <!-- Modal: Marcas -->
    <div class="genreq-modal-overlay" id="genReqModalMarcas">
        <div class="genreq-modal">
            <div class="genreq-modal-header">
                <span><i class="fas fa-industry"></i> Filtrar por Marca</span>
                <button type="button" class="genreq-modal-close" onclick="genReqCerrarModal('genReqModalMarcas')">&times;</button>
            </div>
            <div class="genreq-modal-body">
                <input type="text" class="genreq-form-input" id="genReqMarcaBuscar" placeholder="Buscar marca..." oninput="genReqFiltrarMarcasModal(this.value)" style="margin-bottom:14px;">
                <div class="genreq-categoria-grid" id="genReqMarcasGrid">
                    <?php foreach ($marcas as $mrc): $imgM = genReqMarcaImagen($mrc); ?>
                    <div class="genreq-categoria-card" data-marca="<?= strtolower(htmlspecialchars($mrc)) ?>" onclick="genReqSeleccionarMarca(this)">
                        <?php if ($imgM): ?>
                        <img src="<?= htmlspecialchars($imgM) ?>" alt="<?= htmlspecialchars($mrc) ?>">
                        <?php else: ?>
                        <i class="fas fa-industry"></i>
                        <?php endif; ?>
                        <div class="genreq-cat-nombre"><?= htmlspecialchars($mrc) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="genReqSinMarcas" style="display:none;text-align:center;color:#999;padding:24px;">Sin marcas que coincidan.</div>
            </div>
            <div class="genreq-modal-footer">
                <button type="button" class="genreq-btn" style="background:#f5f5f5;color:#666;" onclick="genReqLimpiarMarca()"><i class="fas fa-eraser"></i> Limpiar</button>
                <button type="button" class="genreq-btn" style="background:transparent;border:1px solid #ddd;" onclick="genReqCerrarModal('genReqModalMarcas')">Cancelar</button>
                <button type="button" class="genreq-btn" style="background:#2196F3;color:#fff;" onclick="genReqAplicarMarca()">Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Modal: Alerta simple -->
    <div class="genreq-modal-overlay" id="genReqModalAlerta">
        <div class="genreq-modal" style="max-width:400px;">
            <div class="genreq-modal-header">
                <span><i class="fas fa-exclamation-triangle"></i> Aviso</span>
                <button type="button" class="genreq-modal-close" onclick="genReqCerrarModal('genReqModalAlerta')">&times;</button>
            </div>
            <div class="genreq-modal-body" id="genReqModalAlertaMsg"></div>
            <div class="genreq-modal-footer">
                <button type="button" class="genreq-btn" style="background:#2196F3;color:#fff;" onclick="genReqCerrarModal('genReqModalAlerta')">Entendido</button>
            </div>
        </div>
    </div>

    <script>
        let genReqCategoriaSel = null;
        let genReqMarcaSel = null;

        function genReqAbrirModal(id) { document.getElementById(id).classList.add('active'); }
        function genReqCerrarModal(id) { document.getElementById(id).classList.remove('active'); }
        function genReqMostrarAlerta(msg) { document.getElementById('genReqModalAlertaMsg').textContent = msg; genReqAbrirModal('genReqModalAlerta'); }

        // 359 marcas sin buscador obligaba a scrollear el modal entero para
        // encontrar una -- se limpia el buscador cada vez que se abre para no
        // dejar un filtro viejo escondido de una visita anterior.
        function genReqAbrirModalMarcas() {
            const buscar = document.getElementById('genReqMarcaBuscar');
            buscar.value = '';
            genReqFiltrarMarcasModal('');
            genReqAbrirModal('genReqModalMarcas');
            buscar.focus();
        }

        function genReqFiltrarMarcasModal(valor) {
            const termino = valor.toLowerCase();
            let visibles = 0;
            document.querySelectorAll('#genReqMarcasGrid .genreq-categoria-card').forEach(function(card) {
                const coincide = card.dataset.marca.includes(termino);
                card.style.display = coincide ? '' : 'none';
                if (coincide) visibles++;
            });
            document.getElementById('genReqSinMarcas').style.display = visibles === 0 ? 'block' : 'none';
        }

        // ── Datos + render en bloques ───────────────────────────────────────
        // Los materiales viajan como JSON (no como HTML repetido), y las
        // tarjetas se arman en el navegador solo para el bloque que se ve --
        // evita mandar ~7.5MB de HTML de una sola vez para 3600+ materiales.
        const genReqTodosMateriales = JSON.parse(document.getElementById('genReqDatosMateriales').textContent);
        const genReqCantidades = {}; // { materialId: cantidad }, sobrevive aunque la fila se deje de renderizar
        const genReqPorId = {}; // lookup para el panel de seleccionados, que no depende del filtro/scroll actual
        genReqTodosMateriales.forEach(function(m) { genReqPorId[m.id] = m; });
        const GENREQ_CHUNK = 60;
        let genReqFiltrados = [];
        let genReqRenderizados = 0;

        // El truco de textContent->innerHTML solo escapa & < > -- no comillas,
        // así que un valor usado dentro de un atributo (src="...", alt="...",
        // title="...") podía romper el atributo si traía una comilla. La
        // descripción/marca/modelo se saneaban en compras.php (sanitize(),
        // ENT_QUOTES) para ediciones manuales, pero el import masivo del Excel
        // (importar_inventario.php) nunca pasó por ahí -- datos reales podían
        // llegar con comillas literales. Escapar aquí, en el punto de salida,
        // en vez de confiar en cómo entró el dato.
        function genReqEscapar(valor) {
            return String(valor == null ? '' : valor)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Miniatura compartida por ambos paneles: logo de marca > foto propia del
        // material > icono de categoría como último recurso.
        function genReqImagenHtml(mat) {
            if (mat.imgMarca) {
                return '<img src="' + genReqEscapar(mat.imgMarca) + '" alt="' + genReqEscapar(mat.marca) + '" style="object-fit:contain;padding:4px;">';
            } else if (mat.imagen) {
                return '<img src="' + genReqEscapar(mat.imagen) + '" alt="">';
            }
            return '<i class="fas fa-' + genReqEscapar(mat.categoriaIcono || 'box') + '"></i>';
        }

        // Fila del panel derecho (resultado de búsqueda) -- miniatura + nombre/código a la
        // izquierda, marca·modelo y stock como columnas (se ocultan en pantallas angostas,
        // ver media query), control de cantidad a la derecha.
        function genReqFilaResultadoHtml(mat) {
            const cantidad = genReqCantidades[mat.id] || 0;
            const marcaModelo = [mat.marca, mat.modelo].filter(Boolean).join(' · ') || '—';
            const sinStock = mat.stock <= 0;
            return '<div class="genreq-fila" data-id="' + mat.id + '">'
                + '<div class="genreq-fila-img">' + genReqImagenHtml(mat) + '</div>'
                + '<div class="genreq-fila-info">'
                + '<div class="genreq-fila-nombre" title="' + genReqEscapar(mat.nombre) + '">' + genReqEscapar(mat.nombre) + '</div>'
                + '<div class="genreq-fila-sub">' + genReqEscapar(mat.codigo || mat.categoriaNombre || '') + '</div>'
                + '</div>'
                + '<div class="genreq-fila-marca" title="' + genReqEscapar(marcaModelo) + '">' + genReqEscapar(marcaModelo) + '</div>'
                + '<div class="genreq-fila-stock' + (sinStock ? ' sin-stock' : '') + '">' + (sinStock ? 'Sin stock' : 'Stock: ' + mat.stock) + '</div>'
                + '<div class="genreq-cantidad-control">'
                + '<span class="genreq-cantidad-valor" id="genReqCant-' + mat.id + '" onclick="genReqEditarCantidad(this, ' + mat.id + ')" title="Clic para escribir la cantidad">' + cantidad + '</span>'
                + '<div class="genreq-cantidad-botones">'
                + '<button type="button" onclick="genReqCambiarCantidad(' + mat.id + ', -1)">-</button>'
                + '<button type="button" onclick="genReqCambiarCantidad(' + mat.id + ', 1)">+</button>'
                + '</div></div></div>';
        }

        // Fila del panel izquierdo (ya seleccionado) -- mismo estilo de miniatura+nombre,
        // sin columnas de marca/stock (ya se decidió al agregarlo), con botón de quitar.
        function genReqFilaSeleccionHtml(mat, cantidad) {
            const marcaModelo = [mat.marca, mat.modelo].filter(Boolean).join(' · ') || '—';
            return '<div class="genreq-fila" data-id="' + mat.id + '">'
                + '<div class="genreq-fila-img">' + genReqImagenHtml(mat) + '</div>'
                + '<div class="genreq-fila-info">'
                + '<div class="genreq-fila-nombre" title="' + genReqEscapar(mat.nombre) + '">' + genReqEscapar(mat.nombre) + '</div>'
                + '<div class="genreq-fila-sub">' + genReqEscapar(marcaModelo) + '</div>'
                + '</div>'
                + '<div class="genreq-cantidad-control">'
                + '<span class="genreq-cantidad-valor" id="genReqCantSel-' + mat.id + '" onclick="genReqEditarCantidad(this, ' + mat.id + ')" title="Clic para escribir la cantidad">' + cantidad + '</span>'
                + '<div class="genreq-cantidad-botones">'
                + '<button type="button" onclick="genReqCambiarCantidad(' + mat.id + ', -1)">-</button>'
                + '<button type="button" onclick="genReqCambiarCantidad(' + mat.id + ', 1)">+</button>'
                + '</div></div>'
                + '<button type="button" class="genreq-fila-quitar" onclick="genReqSetCantidad(' + mat.id + ', 0)" title="Quitar de la selección"><i class="fas fa-times"></i></button>'
                + '</div>';
        }

        function genReqRenderizarChunk() {
            const grid = document.getElementById('genReqGrid');
            const siguientes = genReqFiltrados.slice(genReqRenderizados, genReqRenderizados + GENREQ_CHUNK);
            grid.insertAdjacentHTML('beforeend', siguientes.map(genReqFilaResultadoHtml).join(''));
            genReqRenderizados += siguientes.length;
            const wrap = document.getElementById('genReqCargarMasWrap');
            const restantes = genReqFiltrados.length - genReqRenderizados;
            if (restantes <= 0) {
                wrap.style.display = 'none';
            } else {
                wrap.style.display = '';
                document.getElementById('genReqCargarMasBtn').textContent = 'Cargar más materiales (' + restantes + ' restantes)';
            }
        }

        function genReqCargarMas() { genReqRenderizarChunk(); }

        // Panel izquierdo -- se re-arma completo en cada cambio de cantidad (la lista de
        // seleccionados suele ser chica, a diferencia del catálogo completo, así que no
        // hace falta el paginado por bloques que sí necesita el panel de resultados).
        function genReqRenderizarSeleccion() {
            const cont = document.getElementById('genReqSeleccionList');
            const vacio = document.getElementById('genReqSelVacio');
            const header = document.getElementById('genReqSelHeader');
            const ids = Object.keys(genReqCantidades).filter(function(id) { return genReqCantidades[id] > 0; });
            if (header) header.textContent = 'Materiales seleccionados (' + ids.length + ')';
            if (ids.length === 0) {
                cont.innerHTML = '';
                vacio.style.display = 'flex';
                return;
            }
            vacio.style.display = 'none';
            cont.innerHTML = ids.map(function(id) {
                const mat = genReqPorId[id];
                return mat ? genReqFilaSeleccionHtml(mat, genReqCantidades[id]) : '';
            }).join('');
        }

        function genReqCambiarCantidad(materialId, delta) {
            const actual = genReqCantidades[materialId] || 0;
            genReqSetCantidad(materialId, Math.max(0, actual + delta));
        }

        function genReqSetCantidad(materialId, cantidad) {
            if (cantidad > 0) genReqCantidades[materialId] = cantidad; else delete genReqCantidades[materialId];
            const span = document.getElementById('genReqCant-' + materialId);
            if (span) span.textContent = cantidad;
            genReqActualizarResumenSeleccion();
            genReqRenderizarSeleccion();
        }

        // "N materiales · M unidades" -- sin esto no había forma de saber qué se
        // llevaba armado sin hacer scroll manual entre miles de tarjetas. Lee de
        // genReqCantidades (no del DOM) porque una tarjeta con cantidad puede
        // dejar de estar renderizada si se cambia de filtro.
        function genReqActualizarResumenSeleccion() {
            const resumen = document.getElementById('genReqResumenSeleccion');
            if (!resumen) return;
            let materiales = 0, unidades = 0;
            for (const id in genReqCantidades) {
                const v = genReqCantidades[id];
                if (v > 0) { materiales++; unidades += v; }
            }
            if (materiales === 0) {
                resumen.style.display = 'none';
                return;
            }
            resumen.style.display = 'inline-block';
            resumen.innerHTML = '<i class="fas fa-clipboard-list"></i> ' + materiales + (materiales === 1 ? ' material' : ' materiales')
                + ' · ' + unidades + (unidades === 1 ? ' unidad' : ' unidades');
        }

        function genReqEditarCantidad(span, materialId) {
            if (span.querySelector('input')) return;
            const actual = span.textContent.trim();
            span.innerHTML = '<input type="number" min="0" value="' + actual + '" style="width:44px;text-align:center;font-size:inherit;font-weight:inherit;border:1px solid #ccc;border-radius:4px;padding:1px 2px;" onclick="event.stopPropagation()" onblur="genReqGuardarCantidad(this, ' + materialId + ')" onkeydown="if(event.key===\'Enter\'){this.blur();}">';
            const inp = span.querySelector('input');
            inp.focus(); inp.select();
        }

        function genReqGuardarCantidad(inputEl, materialId) {
            let cantidad = parseInt(inputEl.value, 10);
            if (isNaN(cantidad) || cantidad < 0) cantidad = 0;
            genReqSetCantidad(materialId, cantidad);
            const span = document.getElementById('genReqCant-' + materialId);
            if (span) span.setAttribute('onclick', 'genReqEditarCantidad(this, ' + materialId + ')');
        }

        // "Mostrando X de Y" -- cuenta el total que cumple el filtro, no solo lo
        // que ya se renderizó (eso es "Cargar más", otra cosa). Sin buscador con
        // texto no hay filtro que contar, el panel derecho ni siquiera se pinta.
        function genReqActualizarContador() {
            const cont = document.getElementById('genReqContador');
            if (!cont) return;
            const termino = document.getElementById('genReqSearch').value.trim();
            if (!termino) {
                cont.textContent = 'Escribe para buscar entre ' + genReqTodosMateriales.length + ' materiales';
            } else {
                cont.textContent = genReqFiltrados.length + (genReqFiltrados.length === 1 ? ' resultado' : ' resultados') + ' de ' + genReqTodosMateriales.length;
            }
        }

        // El panel derecho arranca vacío a propósito (antes pintaba los 3600+ materiales
        // de una vez) -- solo se llena cuando el BUSCADOR trae texto; elegir categoría o
        // marca sin haber escrito nada todavía no revela resultados, son refinamientos de
        // la búsqueda, no un modo de exploración aparte.
        function genReqAplicarFiltros() {
            const termino = document.getElementById('genReqSearch').value.toLowerCase().trim();
            const grid = document.getElementById('genReqGrid');
            const vacio = document.getElementById('genReqDerVacio');
            const sinResultados = document.getElementById('genReqSinResultados');
            const cargarMasWrap = document.getElementById('genReqCargarMasWrap');

            if (!termino) {
                genReqFiltrados = [];
                genReqRenderizados = 0;
                grid.innerHTML = '';
                sinResultados.style.display = 'none';
                cargarMasWrap.style.display = 'none';
                vacio.style.display = 'flex';
                genReqActualizarContador();
                return;
            }

            vacio.style.display = 'none';
            genReqFiltrados = genReqTodosMateriales.filter(function(mat) {
                const matchCat = !genReqCategoriaSel || String(mat.categoriaId) === String(genReqCategoriaSel);
                const matchMarca = !genReqMarcaSel || (mat.marca || '').toLowerCase() === genReqMarcaSel;
                const matchSearch = (mat.nombre || '').toLowerCase().includes(termino) || (mat.codigo || '').toLowerCase().includes(termino);
                return matchCat && matchMarca && matchSearch;
            });
            genReqRenderizados = 0;
            grid.innerHTML = '';
            sinResultados.style.display = genReqFiltrados.length === 0 ? 'block' : 'none';
            genReqRenderizarChunk();
            genReqActualizarContador();
        }

        function genReqBuscar(valor) {
            const clearBtn = document.getElementById('genReqClearSearch');
            if (valor.length > 0) clearBtn.classList.add('visible'); else clearBtn.classList.remove('visible');
            genReqAplicarFiltros();
        }

        function genReqSeleccionarMarca(card) {
            document.querySelectorAll('#genReqModalMarcas .genreq-categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            genReqMarcaSel = card.dataset.marca;
        }
        function genReqAplicarMarca() { genReqCerrarModal('genReqModalMarcas'); genReqAplicarFiltros(); }
        function genReqLimpiarMarca() {
            genReqMarcaSel = null;
            document.querySelectorAll('#genReqModalMarcas .genreq-categoria-card').forEach(c => c.classList.remove('selected'));
            genReqAplicarFiltros(); genReqCerrarModal('genReqModalMarcas');
        }

        // Arma los <input> de items[] justo antes de enviar, a partir de
        // genReqCantidades -- ya no depende de qué tarjetas están renderizadas
        // en ese momento (un material pudo quedar seleccionado y luego
        // "desaparecer" del DOM por un cambio de filtro o por scroll/paginado).
        function genReqGenerar() {
            const idsConCantidad = Object.keys(genReqCantidades).filter(function(id) { return genReqCantidades[id] > 0; });

            if (idsConCantidad.length === 0) {
                genReqMostrarAlerta('Selecciona al menos un material con cantidad mayor a 0.');
                return;
            }
            if (!confirm('¿Generar esta requisición? Se notificará a Almacén (y a Compras si algún material no alcanza en stock).')) return;

            const form = document.getElementById('genReqForm');
            form.querySelectorAll('input[name^="items["]').forEach(function(el) { el.remove(); });
            idsConCantidad.forEach(function(id) {
                const inpMaterial = document.createElement('input');
                inpMaterial.type = 'hidden'; inpMaterial.name = 'items[' + id + '][material_id]'; inpMaterial.value = id;
                const inpCantidad = document.createElement('input');
                inpCantidad.type = 'hidden'; inpCantidad.name = 'items[' + id + '][cantidad]'; inpCantidad.value = genReqCantidades[id];
                form.appendChild(inpMaterial);
                form.appendChild(inpCantidad);
            });
            form.submit();
        }

        genReqAplicarFiltros();     // deja el panel derecho en su estado vacío inicial
        genReqRenderizarSeleccion(); // deja el panel izquierdo en su estado vacío inicial
    </script>
    <?php
}

/**
 * "Mis Requisiciones" con cronograma -- pensado para quien GENERA requisiciones
 * (Producción, Calidad, Ingeniería, Proyectos), no para Almacén (Almacén ya sabe
 * en qué va cada una porque las está trabajando él mismo). El detalle de qué se
 * surtió (fecha/hora + materiales) solo se muestra si quien ve la página tiene
 * acceso de Almacén o es admin -- ver registrarEventoRequisicion()/
 * requisicionEstadoLabel() en includes/config.php.
 *
 * $volverKey: clave para el botón "Volver" de ver_requisicion.php (whitelist ahí
 * mismo) -- 'manufactura'|'calidad'|'ingenieria'|'proyectos'.
 */
function renderMisRequisicionesSeccion(PDO $db, string $baseUrl, string $volverKey, ?string $message = null): void {
    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
    $stmt = $db->prepare("SELECT r.*, COUNT(rd.id) as total_items FROM requisiciones r LEFT JOIN requisicion_detalle rd ON rd.requisicion_id = r.id WHERE r.usuario_id = ? GROUP BY r.id ORDER BY r.fecha_creacion DESC");
    $stmt->execute([$usuarioId]);
    $mis_requisiciones = $stmt->fetchAll();

    $mis_req_eventos = [];
    if (!empty($mis_requisiciones)) {
        $req_ids = array_column($mis_requisiciones, 'id');
        $ph = implode(',', array_fill(0, count($req_ids), '?'));
        $stmt_ev = $db->prepare("SELECT * FROM requisicion_eventos WHERE requisicion_id IN ($ph) ORDER BY fecha ASC");
        $stmt_ev->execute($req_ids);
        foreach ($stmt_ev->fetchAll() as $ev) {
            $mis_req_eventos[$ev['requisicion_id']][] = $ev;
        }
    }
    $ver_detalle_surtido = hasAcceso('almacen') || isAdmin();
    ?>
    <style>
    .misreq-table { width:100%; border-collapse:collapse; font-size:.82rem; }
    .misreq-table th { background:#f0f4f8; padding:9px 12px; text-align:left; border-bottom:2px solid #dde3ea; color:#003d5c; font-weight:700; }
    .misreq-table td { padding:8px 12px; border-bottom:1px solid #f0f0f0; }
    .misreq-badge { display:inline-flex; align-items:center; gap:3px; padding:3px 9px; border-radius:20px; font-size:.7rem; font-weight:700; }
    .misreq-badge-pendiente  { background:#fff3e0; color:#e65100; }
    .misreq-badge-en_proceso { background:#e3f2fd; color:#1565c0; }
    .misreq-badge-completado { background:#e8f5e9; color:#2E7D32; }
    .misreq-empty { text-align:center; padding:60px 20px; color:#aaa; }
    .misreq-empty i { font-size:3rem; display:block; margin-bottom:12px; color:#ccc; }
    .misreq-row { cursor:pointer; }
    .misreq-row td:first-child i.misreq-caret { width:14px; display:inline-block; transition:transform .15s; color:#888; }
    .misreq-row.open td:first-child i.misreq-caret { transform:rotate(90deg); }
    .misreq-timeline-row { display:none; }
    .misreq-timeline-row.open { display:table-row; }
    .misreq-timeline-row td { background:#f9fafb; padding:16px 20px; }
    .misreq-timeline { list-style:none; margin:0; padding:0; display:flex; flex-wrap:wrap; gap:0; }
    .misreq-tl-step { display:flex; align-items:center; gap:8px; padding:8px 14px; background:#fff; border:1px solid #e0e0e0; border-radius:20px; margin:4px 8px 4px 0; font-size:.78rem; }
    .misreq-tl-step i { color:#005686; }
    .misreq-tl-step .misreq-tl-fecha { color:#999; font-size:.7rem; display:block; }
    .misreq-tl-step .misreq-tl-detalle { color:#b71c1c; font-size:.72rem; display:block; margin-top:2px; max-width:220px; white-space:normal; }
    </style>
    <div class="section-content">
        <div class="sticky-container">
            <div class="header-row"><h2><i class="fas fa-file-alt"></i> Mis Requisiciones</h2></div>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if (empty($mis_requisiciones)): ?>
        <div class="misreq-empty"><i class="fas fa-file-alt"></i><p>No has generado requisiciones.</p></div>
        <?php else: ?>
        <table class="misreq-table">
            <thead><tr><th></th><th>Folio</th><th>Proyecto</th><th>Items</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($mis_requisiciones as $r):
                $tl_id = 'misreqtl_' . $r['id'];
                $eventos_r = $mis_req_eventos[$r['id']] ?? [];
                // Mientras sigue 'pendiente' (nadie la ha aprobado/cotizado/etc. todavía),
                // el badge principal distingue "No visto" de "Visto" en vez de mostrar
                // siempre "Pendiente" a secas -- ver marcarRequisicionVistaSiNueva().
                if ($r['estado'] === 'pendiente') {
                    $fue_vista = false;
                    foreach ($eventos_r as $ev_chk) { if ($ev_chk['estado_clave'] === 'vista') { $fue_vista = true; break; } }
                    $badge_clase = 'pendiente';
                    $badge_label = $fue_vista ? 'Visto' : 'No visto';
                } else {
                    $badge_clase = $r['estado'] === 'aprobada' ? 'completado' : 'en_proceso';
                    $badge_label = ucfirst($r['estado']);
                }
            ?>
            <tr class="misreq-row" onclick="toggleMisReqTimeline('<?= $tl_id ?>', this)">
                <td style="width:20px;"><i class="fas fa-chevron-right misreq-caret"></i></td>
                <td><?= htmlspecialchars($r['folio']) ?></td>
                <td><?= htmlspecialchars($r['proyecto'] ?? '') ?></td>
                <td><?= $r['total_items'] ?></td>
                <td><span class="misreq-badge misreq-badge-<?= $badge_clase ?>"><?= htmlspecialchars($badge_label) ?></span></td>
                <td><?= date('d/m/Y', strtotime($r['fecha_creacion'])) ?></td>
                <td onclick="event.stopPropagation()"><a href="ver_requisicion.php?id=<?= (int)$r['id'] ?>&volver=<?= urlencode($volverKey) ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver</a></td>
            </tr>
            <tr class="misreq-timeline-row" id="<?= $tl_id ?>">
                <td colspan="7">
                    <?php if (empty($eventos_r)): ?>
                        <span style="color:#999;font-size:.8rem;">Sin cronograma todavía.</span>
                    <?php else: ?>
                    <ul class="misreq-timeline">
                        <?php foreach ($eventos_r as $ev):
                            $info = requisicionEstadoLabel($ev['estado_clave']);
                        ?>
                        <li class="misreq-tl-step">
                            <i class="fas <?= htmlspecialchars($info['icon']) ?>"></i>
                            <div>
                                <strong><?= htmlspecialchars($info['label']) ?></strong>
                                <span class="misreq-tl-fecha"><?= date('d/m/Y H:i', strtotime($ev['fecha'])) ?></span>
                                <?php if ($ver_detalle_surtido && $ev['detalle']): ?>
                                <span class="misreq-tl-detalle"><i class="fas fa-box"></i> <?= htmlspecialchars($ev['detalle']) ?></span>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <script>
    function toggleMisReqTimeline(id, headerEl) {
        const row = document.getElementById(id);
        const open = row.classList.toggle('open');
        headerEl.classList.toggle('open', open);
    }
    </script>
    <?php
}

/**
 * Una sola sección con pestañas: "Generar Requisición" (formulario) y
 * "Mis Requisiciones" (listado + cronograma) -- van juntas bajo UNA tarjeta de
 * nav en vez de dos, para no duplicar tarjetas por algo que es la misma feature
 * vista desde dos ángulos. ?tab=mis en la URL abre directo en esa pestaña (lo
 * usa el botón "Volver" de ver_requisicion.php).
 */
function renderRequisicionesSeccion(PDO $db, string $baseUrl, string $campoAccion, string $area, string $volverKey, ?string $message = null): void {
    $tab_inicial = ($_GET['tab'] ?? '') === 'mis' ? 'mis' : 'generar';
    ?>
    <style>
    .genreq-tabs { display:flex; gap:8px; padding:16px 20px 0; }
    .genreq-tab-btn { background:#f0f4f8; border:none; border-radius:8px 8px 0 0; padding:10px 20px; font-size:.85rem; font-weight:700; color:#666; cursor:pointer; }
    .genreq-tab-btn.active { background:#fff; color:#003d5c; box-shadow:0 -2px 4px rgba(0,0,0,.04); }
    </style>
    <div class="genreq-tabs">
        <button type="button" class="genreq-tab-btn <?= $tab_inicial === 'generar' ? 'active' : '' ?>" data-genreqtab="generar" onclick="genReqCambiarTab('generar')"><i class="fas fa-file-circle-plus"></i> Generar Requisición</button>
        <button type="button" class="genreq-tab-btn <?= $tab_inicial === 'mis' ? 'active' : '' ?>" data-genreqtab="mis" onclick="genReqCambiarTab('mis')"><i class="fas fa-file-alt"></i> Mis Requisiciones</button>
    </div>
    <div id="genReqTabPanelGenerar" style="<?= $tab_inicial === 'generar' ? '' : 'display:none;' ?>">
        <?php renderGenerarRequisicionSeccion($db, $baseUrl, $campoAccion, $area); ?>
    </div>
    <div id="genReqTabPanelMis" style="<?= $tab_inicial === 'mis' ? '' : 'display:none;' ?>">
        <?php renderMisRequisicionesSeccion($db, $baseUrl, $volverKey, $message); ?>
    </div>
    <script>
    function genReqCambiarTab(tab) {
        document.getElementById('genReqTabPanelGenerar').style.display = tab === 'generar' ? '' : 'none';
        document.getElementById('genReqTabPanelMis').style.display = tab === 'mis' ? '' : 'none';
        document.querySelectorAll('.genreq-tab-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.genreqtab === tab);
        });
    }
    </script>
    <?php
}
