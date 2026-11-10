<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Acceso denegado');
}
// Ingeniería y Admin ven/editan el BOM; Proyectos solo necesita consultarlo (qué materiales se
// están usando). Ningún otro rol (compras, almacén, ventas, calidad, manufactura...) entra aquí.
if (!isAdmin() && !hasAcceso('ingenieria') && !hasAcceso('proyectos')) {
    http_response_code(403);
    exit('Acceso denegado');
}

$boom_id = intval($_GET['boom_id'] ?? 0);
if (!$boom_id) {
    http_response_code(400);
    exit('boom_id requerido');
}

$db = getDB();

$stmt = $db->prepare("SELECT bl.*, p.nombre as proyecto_nombre
                      FROM boom_listas bl
                      JOIN proyectos p ON p.id = bl.proyecto_id
                      WHERE bl.id = ?");
$stmt->execute([$boom_id]);
$boom = $stmt->fetch();
if (!$boom) { http_response_code(404); exit('BOM no encontrado'); }

$stmt2 = $db->prepare("SELECT bi.*, m.descripcion as mat_nombre, m.codigo as mat_codigo,
                               c.nombre as cat_nombre, c.id as cat_id
                        FROM boom_items bi
                        LEFT JOIN materiales m ON m.id = bi.material_id
                        LEFT JOIN categorias c ON c.id = m.categoria_id
                        WHERE bi.boom_id = ?
                        ORDER BY bi.id");
$stmt2->execute([$boom_id]);
$items = bomOrdenarItemsPorCategoriaDoc($stmt2->fetchAll());
$CATEGORIAS_DOC = bomCategoriasDocMeta();

$fecha_export = '09 / Jul / 20';
$num_equipos  = $boom['num_equipos'] ?? 1;
// Rev. de arriba de la tabla: editable por BOM (boom_listas.revision_numero).
// Revisión No. del pie de página DOC-ING-FO-01C: fija, documento de control oficial.
$revision        = (int)($boom['revision_numero'] ?? 0);
$revision_footer = 6;

// Logo embebido como base64 — evita depender de que la imagen cargue a tiempo
// antes de que se dispare la impresión (y funciona igual dentro de un iframe).
$logo_data_uri = '';
$logo_path = UPLOAD_PATH . 'logo/logo.png';
if (file_exists($logo_path)) {
    $logo_data_uri = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>BOM <?= htmlspecialchars($boom['proyecto_nombre']) ?></title>
<style>
    * { box-sizing: border-box; }
    @page { size: letter landscape; margin: 8mm; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; padding: 0; color: #222; font-size: 10px; }
    /* Encabezado (logo+título) y pie (DOC-ING-FO-01C) viven dentro del
       thead/tfoot de la MISMA tabla que los items -- display:table-header-
       group / table-footer-group es el mecanismo de impresión más confiable
       para que algo se repita igual en cada página (más confiable que
       position:fixed, que en Chromium no siempre respeta la geometría real
       de cada página impresa). Así el encabezado sale en todas las hojas y
       el pie siempre queda pegado hasta abajo, no solo tras el último renglón. */
    table.hoja { width:100%; border-collapse:collapse; }
    table.hoja thead { display:table-header-group; }
    table.hoja tfoot { display:table-footer-group; }
    table.hoja tbody tr { page-break-inside:avoid; }
    .hoja-header-fila td { border:none; padding:0 0 8px; }
    .hoja-header { display:flex; align-items:center; border:1px solid #333; }
    .hoja-header .logo { padding:4px 10px; border-right:1px solid #333; }
    .hoja-header .logo img { height:42px; }
    .hoja-header .titulo { flex:1; text-align:center; }
    .hoja-header .titulo h1 { font-size:14px; margin:0 0 3px; }
    .hoja-header .titulo div { font-size:11px; }
    .hoja-header .rev { padding:4px 12px; border-left:1px solid #333; font-weight:bold; font-style:italic; font-size:12px; }
    /* table-layout:fixed obliga a las columnas a respetar el width declarado en
       vez de estirarse con el contenido -- sin esto, una descripción larga o
       una palabra sin espacios empujaba la tabla entera más ancha que la
       página impresa. word-break evita que esa misma descripción se salga de
       su celda en vez de bajar de línea. */
    table.hoja { table-layout:fixed; }
    table.hoja th.items-th { background:#003d5c; color:#fff; font-size:9px; padding:4px 6px; border:1px solid #ccc; text-align:center; -webkit-print-color-adjust:exact; print-color-adjust:exact; word-break:break-word; overflow-wrap:break-word; }
    table.hoja td.items-td { border:1px solid #ccc; padding:3px 6px; font-size:9px; word-break:break-word; overflow-wrap:break-word; }
    table.hoja tbody tr { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .pie-fila td { border:none; padding:8px 0 0; }
    .pie { border:1px solid #333; }
    .pie .tb { display:flex; }
    .pie .tb div { flex:1; border-right:1px solid #333; border-bottom:1px solid #333; text-align:center; padding:3px 4px; font-size:9px; }
    .pie .tb div:last-child { border-right:none; }
    .pie .disclaimer { text-align:center; padding:3px 6px; font-size:9px; }
</style>
</head>
<body>
    <table class="hoja">
        <colgroup>
            <col style="width:8%"><col style="width:38%"><col style="width:14%">
            <col style="width:12%"><col style="width:14%"><col style="width:14%">
        </colgroup>
        <thead>
            <tr class="hoja-header-fila">
                <td colspan="6">
                    <div class="hoja-header">
                        <div class="logo">
                            <?php if ($logo_data_uri): ?>
                            <img src="<?= $logo_data_uri ?>" alt="EMPRESA EJEMPLO">
                            <?php else: ?>
                            EMPRESA EJEMPLO
                            <?php endif; ?>
                        </div>
                        <div class="titulo">
                            <h1>LISTA DE MATERIALES (BILL OF MATERIALS)</h1>
                            <div>PROYECTO: <?= htmlspecialchars($boom['proyecto_nombre']) ?> (<?= $num_equipos ?> EQUIPO<?= $num_equipos == 1 ? '' : 'S' ?>)</div>
                        </div>
                        <div class="rev">Rev <?= $revision ?></div>
                    </div>
                </td>
            </tr>
            <tr>
                <th class="items-th">CANTIDAD<br>POR EQUIPO</th>
                <th class="items-th">DESCRIPCIÓN</th>
                <th class="items-th">No. DE PARTE</th>
                <th class="items-th">MARCA</th>
                <th class="items-th">CATEGORIA</th>
                <th class="items-th">NOTA</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td class="items-td" colspan="6" style="text-align:center;color:#999;">Sin items</td></tr>
        <?php else: ?>
            <?php foreach ($items as $item):
                $nota = trim($item['nota_libre'] ?? '');
                if (!empty($item['observacion'])) {
                    $nota = trim($nota . ' (Obs: ' . $item['observacion'] . ')');
                }
                $cd_bg = $CATEGORIAS_DOC[$item['categoria_doc'] ?? '']['bg'] ?? '';
            ?>
            <tr<?= $cd_bg ? ' style="background:' . $cd_bg . ';"' : '' ?>>
                <td class="items-td" style="text-align:center;"><?= htmlspecialchars($item['cantidad']) ?></td>
                <td class="items-td"><?= htmlspecialchars($item['descripcion']) ?></td>
                <td class="items-td" style="text-align:center;"><?= htmlspecialchars($item['modelo'] ?? '') ?></td>
                <td class="items-td"><?= htmlspecialchars($item['marca'] ?? '') ?></td>
                <td class="items-td">
                    <?php $cd = $item['categoria_doc'] ?? ''; ?>
                    <?php if ($cd && isset($CATEGORIAS_DOC[$cd])): ?>
                        <span style="color:<?= $CATEGORIAS_DOC[$cd]['color'] ?>;font-weight:700;"><?= htmlspecialchars($CATEGORIAS_DOC[$cd]['label']) ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($item['cat_nombre'] ?? '') ?>
                    <?php endif; ?>
                </td>
                <td class="items-td"><?= htmlspecialchars($nota) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="pie-fila">
                <td colspan="6">
                    <div class="pie">
                        <div class="tb">
                            <div style="font-weight:bold;">DOC-ING-FO-01C</div>
                            <div>Revisión No: <?= $revision_footer ?></div>
                            <div>Fecha: <strong><?= $fecha_export ?></strong></div>
                            <div>Página: <strong>1</strong></div>
                        </div>
                        <div class="disclaimer">
                            Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                        </div>
                    </div>
                </td>
            </tr>
        </tfoot>
    </table>

    <script>
        // Si esta página se abre directamente (fuera del iframe de impresión de
        // ingenieria.php), igual dispara la vista de impresión nativa por su cuenta.
        if (window.top === window.self) {
            window.addEventListener('load', function () { window.print(); });
        }
    </script>
</body>
</html>
