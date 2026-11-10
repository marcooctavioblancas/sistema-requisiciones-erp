<?php
require_once '../includes/config.php';
require_once '../includes/indicadores.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Acceso denegado');
}

$reporte_id = intval($_GET['reporte_id'] ?? 0);
if (!$reporte_id) { http_response_code(400); exit('reporte_id requerido'); }

$db = getDB();
$stmt = $db->prepare("SELECT * FROM indicadores_reportes WHERE id = ?");
$stmt->execute([$reporte_id]);
$reporte = $stmt->fetch();
if (!$reporte) { http_response_code(404); exit('Reporte no encontrado'); }

$cfg = INDICADORES_CONFIG[$reporte['area']] ?? null;
if (!$cfg) { http_response_code(400); exit('Área no configurada'); }
if (!ind_valida_acceso($reporte['area'])) { http_response_code(403); exit('No autorizado'); }

$stmt2 = $db->prepare("SELECT * FROM indicadores_entradas WHERE reporte_id = ? ORDER BY orden ASC, id ASC");
$stmt2->execute([$reporte_id]);
$entradas = $stmt2->fetchAll();

$promedio     = $reporte['porcentaje_obtenido'] !== null ? $reporte['porcentaje_obtenido'] : recalcularPromedioReporte($db, $reporte_id);
$requerido    = $reporte['porcentaje_requerido'];
$fileSafe     = 'Indicadores_' . preg_replace('/[^a-zA-Z0-9_-]/','_', $cfg['nombre_area']) . '_T' . $reporte['trimestre'] . '_' . $reporte['anio'];

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $fileSafe . '.xls"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<!--[if gte mso 9]>
<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>Indicadores</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
<![endif]-->
<style>
body { font-family: Arial, sans-serif; font-size: 11pt; }
.col-header { background-color: #003d5c; color: white; font-weight: bold; font-size: 10pt; text-align: center; border: 1px solid #ccc; }
td, th { border: 1px solid #cccccc; padding: 4px 8px; }
.title-cell { font-size: 15pt; font-weight: bold; text-align: center; vertical-align: middle; border: 1px solid #333; }
.tb-cell { border: 1px solid #333; text-align: center; font-size: 11pt; padding: 3px 6px; }
</style>
</head>
<body>
<table style="border-collapse:collapse;">
    <tr>
        <td colspan="8" class="title-cell">REPORTE DE INDICADORES — <?= htmlspecialchars(strtoupper($cfg['nombre_area'])) ?></td>
    </tr>
    <tr>
        <td colspan="8" style="border:1px solid #333;text-align:center;font-size:12pt;">
            Trimestre <?= (int)$reporte['trimestre'] ?> / <?= (int)$reporte['anio'] ?> &mdash; <?= htmlspecialchars($cfg['nombre_indicador']) ?> (<?= htmlspecialchars($cfg['criterio']) ?>)
        </td>
    </tr>
    <tr>
        <td colspan="4" class="tb-cell">Representante: <strong><?= htmlspecialchars($reporte['representante_nombre'] ?? '') ?></strong></td>
        <td colspan="4" class="tb-cell">Cargo: <strong><?= htmlspecialchars($reporte['representante_cargo'] ?? '') ?></strong></td>
    </tr>
    <tr>
        <th class="col-header"><?= htmlspecialchars($cfg['label_etiqueta']) ?></th>
        <th class="col-header"><?= htmlspecialchars($cfg['label_referencia']) ?></th>
        <th class="col-header"><?= htmlspecialchars($cfg['label_real']) ?></th>
        <th class="col-header"><?= htmlspecialchars($cfg['label_programada']) ?></th>
        <th class="col-header">Días reales</th>
        <th class="col-header">Días programados</th>
        <th class="col-header">%</th>
        <th class="col-header">Nota</th>
    </tr>
    <?php if (empty($entradas)): ?>
    <tr><td colspan="8" style="text-align:center;color:#999;">Sin entradas</td></tr>
    <?php else: foreach ($entradas as $e): ?>
    <tr>
        <td><?= htmlspecialchars($e['etiqueta'] ?? '') ?></td>
        <td style="text-align:center;"><?= htmlspecialchars($e['fecha_referencia'] ?? '') ?></td>
        <td style="text-align:center;"><?= htmlspecialchars($e['fecha_real'] ?? '') ?></td>
        <td style="text-align:center;"><?= htmlspecialchars($e['fecha_programada'] ?? '') ?></td>
        <td style="text-align:center;"><?= $e['dias_reales'] !== null ? $e['dias_reales'] : '' ?></td>
        <td style="text-align:center;"><?= $e['dias_programados'] !== null ? $e['dias_programados'] : '' ?></td>
        <td style="text-align:center;<?= $e['porcentaje'] !== null && $e['porcentaje'] > 100 ? 'color:#b71c1c;font-weight:bold;' : '' ?>"><?= $e['porcentaje'] !== null ? number_format($e['porcentaje'],2).'%' : '' ?></td>
        <td><?= htmlspecialchars($e['nota'] ?? '') ?></td>
    </tr>
    <?php endforeach; endif; ?>
</table>
<table style="border-collapse:collapse;margin-top:6px;">
    <tr>
        <td class="tb-cell">% obtenido (promedio): <strong<?= $promedio !== null && $promedio > 100 ? ' style="color:#b71c1c;"' : '' ?>><?= $promedio !== null ? number_format($promedio,2).'%' : '—' ?></strong></td>
        <td class="tb-cell">% requerido: <strong><?= $requerido !== null ? number_format($requerido,2).'%' : '—' ?></strong></td>
        <td class="tb-cell">Resultado: <strong><?= $reporte['estado'] === 'finalizado' ? ($reporte['cumple'] ? 'CUMPLE' : 'NO CUMPLE') : 'BORRADOR' ?></strong></td>
    </tr>
    <?php if (!empty($reporte['notas'])): ?>
    <tr><td colspan="3" class="tb-cell">Notas: <?= htmlspecialchars($reporte['notas']) ?></td></tr>
    <?php endif; ?>
</table>
<table style="border-collapse:collapse;margin-top:6px;">
    <tr>
        <td colspan="4" style="text-align:left;font-size:11pt;padding:3px 6px;">DOC-GEN-FO-15; Revisión N&deg; 5; Fecha: 18 / Sep / 21; Página: 1</td>
    </tr>
    <tr>
        <td colspan="4" style="text-align:left;font-size:11pt;padding:3px 6px;">
            Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
        </td>
    </tr>
</table>
</body>
</html>
