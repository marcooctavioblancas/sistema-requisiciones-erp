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
$fecha_file   = date('Ymd');
$proyecto_nombre_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $boom['proyecto_nombre']);
$num_equipos = $boom['num_equipos'] ?? 1;
// Rev. de arriba de la tabla: editable por BOM (boom_listas.revision_numero).
// Revisión No. del pie de página DOC-ING-FO-01C: fija, documento de control oficial.
$revision        = (int)($boom['revision_numero'] ?? 0);
$revision_footer = 6;

// PENDIENTE: se intentó embeber el logo real (mismo data URI base64 que usa
// api/vista_pdf_bom.php) pero Excel NO renderiza <img src="data:..."> en este tipo de
// archivo (HTML disfrazado de .xls) -- lo trata como una imagen VINCULADA a una ruta de
// archivo externa, no como una imagen embebida, y como "data:..." no es una ruta real
// muestra el ícono roto "No se pudo mostrar la imagen vinculada". Confirmado con una
// captura real de Excel, no solo lectura de código. Para lograr una imagen de verdad
// embebida haría falta convertir todo el archivo a formato MHTML (multipart/related con
// límites MIME exactos, referenciando la imagen por Content-ID) -- se deja pendiente a
// propósito, nunca se ha usado ese formato en este proyecto y un error ahí podría romper
// el archivo completo, no solo el logo. Por ahora se vuelve al texto plano que sí funciona.
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="BOM_' . $proyecto_nombre_safe . '_' . $fecha_file . '.xls"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<!--[if gte mso 9]>
<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>BOM</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
<![endif]-->
<style>
body { font-family: Arial, sans-serif; font-size: 11pt; }
.col-header { background-color: #003d5c; color: white; font-weight: bold; font-size: 10pt; text-align: center; border: 1px solid #ccc; }
td, th { border: 1px solid #cccccc; padding: 4px 8px; }
.title-cell { font-size: 16pt; font-weight: bold; text-align: center; vertical-align: middle; border: 1px solid #333; }
.rev-cell { font-size: 12pt; font-weight: bold; font-style: italic; text-align: center; vertical-align: middle; border: 1px solid #333; }
.tb-cell { border: 1px solid #333; text-align: center; font-size: 11pt; padding: 3px 6px; }
</style>
</head>
<body>
<table style="border-collapse:collapse;">
    <tr>
        <td rowspan="2" style="border:1px solid #333;text-align:center;padding:4px;font-size:13pt;font-weight:bold;color:#003d5c;">
            EMPRESA EJEMPLO
        </td>
        <td colspan="4" class="title-cell">LISTA DE MATERIALES (BILL OF MATERIALS)</td>
        <td class="rev-cell">Rev <?= $revision ?></td>
    </tr>
    <tr>
        <td colspan="5" style="border:1px solid #333;text-align:center;font-size:12pt;">
            PROYECTO: <?= htmlspecialchars($boom['proyecto_nombre']) ?> (<?= $num_equipos ?> EQUIPO<?= $num_equipos == 1 ? '' : 'S' ?>)<?php if (!empty($boom['tipo_valvula'])): ?> (<?= htmlspecialchars(strtoupper($boom['tipo_valvula'])) ?>)<?php endif; ?>
        </td>
    </tr>
    <tr>
        <th class="col-header">CANTIDAD POR EQUIPO</th>
        <th class="col-header">DESCRIPCIÓN</th>
        <th class="col-header">No. DE PARTE</th>
        <th class="col-header">MARCA</th>
        <th class="col-header">CATEGORIA</th>
        <th class="col-header">NOTA</th>
    </tr>
    <?php if (empty($items)): ?>
    <tr><td colspan="6" style="text-align:center;color:#999;">Sin items</td></tr>
    <?php else: ?>
    <?php foreach ($items as $item):
        $cd = $item['categoria_doc'] ?? '';
        $cdMeta = $CATEGORIAS_DOC[$cd] ?? null;
        $bg = $cdMeta['bg'] ?? '#F5F5F5';
        $nota = trim($item['nota_libre'] ?? '');
        if (!empty($item['observacion'])) {
            $nota = trim($nota . ' (Obs: ' . $item['observacion'] . ')');
        }
    ?>
    <?php
        // El fondo va en cada <td>, NUNCA en el <tr> -- Excel (a diferencia de un
        // navegador) no confina el background-color de una fila HTML a las columnas
        // reales de la tabla, lo derrama hacia todas las columnas "fantasma" que
        // muestra más allá del contenido, pintando toda la hoja de esa fila en adelante.
        $bgStyle = 'background-color:' . $bg . ';';
    ?>
    <tr>
        <td style="<?= $bgStyle ?>text-align:center;"><?= htmlspecialchars($item['cantidad']) ?></td>
        <td style="<?= $bgStyle ?>"><?= htmlspecialchars($item['descripcion']) ?></td>
        <td style="<?= $bgStyle ?>text-align:center;"><?= htmlspecialchars($item['modelo'] ?? '') ?></td>
        <td style="<?= $bgStyle ?>"><?= htmlspecialchars($item['marca'] ?? '') ?></td>
        <td style="<?= $bgStyle ?><?= $cdMeta ? 'color:' . $cdMeta['color'] . ';font-weight:bold;' : '' ?>"><?= $cdMeta ? htmlspecialchars($cdMeta['label']) : htmlspecialchars($item['cat_nombre'] ?? '') ?></td>
        <td style="<?= $bgStyle ?>"><?= htmlspecialchars($nota) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <!-- Mismo <table> que los items (no uno aparte) -- así el pie queda con
         las mismas columnas que Excel ya calculó para la tabla de arriba en
         vez de tener su propio ancho de columnas independiente y quedar
         desalineado. -->
    <tr><td colspan="6" style="border:none;height:6px;"></td></tr>
    <tr>
        <td class="tb-cell" style="font-weight:bold;">DOC-ING-FO-01C</td>
        <td colspan="2" class="tb-cell">Revisión No: <?= $revision_footer ?></td>
        <td colspan="2" class="tb-cell">Fecha: <strong><?= $fecha_export ?></strong></td>
        <td class="tb-cell">Página: <strong>1</strong></td>
    </tr>
    <tr>
        <td colspan="6" class="tb-cell">
            Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
        </td>
    </tr>
</table>
</body>
</html>
