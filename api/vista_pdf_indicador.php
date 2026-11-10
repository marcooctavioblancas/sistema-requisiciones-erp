<?php
/**
 * Reporte de indicadores (DOC-GEN-FO-15) -- réplica del formato Word real
 * (DOC-GEN-FO-15 Reporte de indicadores r5 1Trim.docx), no una tabla simplificada.
 * Generado con mPDF, mismo criterio que api/vista_pdf_fat_protocolo.php (PDF real, no
 * "HTML + window.print() del navegador").
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/indicadores.php';
require_once __DIR__ . '/../vendor/autoload.php';

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

$promedio  = $reporte['porcentaje_obtenido'] !== null ? $reporte['porcentaje_obtenido'] : recalcularPromedioReporte($db, $reporte_id);
$requerido = $reporte['porcentaje_requerido'] !== null ? $reporte['porcentaje_requerido'] : 100.00;

$v  = fn($s) => $s !== null && $s !== '' ? htmlspecialchars($s) : '________________';
$fd = fn(?string $f) => $f ? date('d/m/y', strtotime($f)) : '________';

$logo_data_uri = '';
$logo_path = UPLOAD_PATH . 'logo/logo.png';
if (file_exists($logo_path)) {
    $logo_data_uri = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

// ── Encabezado repetido en cada página -- logo + título, medido del .docx real (header: celda de
// logo a la izquierda, "REPORTE DE INDICADORES" centrado a la derecha). Estilo inline: SetHTMLHeader()
// no interpreta <style>, lo deja pasar como texto plano (verificado en vista_pdf_fat_protocolo.php).
$logoCelda = $logo_data_uri
    ? '<img src="' . $logo_data_uri . '" alt="EMPRESA EJEMPLO" style="max-height:34px;">'
    : '<span style="font-weight:800;">EMPRESA EJEMPLO</span>';
$headerHtml = '
<table style="width:100%;border-collapse:collapse;border:1px solid #000;">
  <tr>
    <td style="width:30%;border-right:1px solid #000;text-align:center;padding:6px;vertical-align:middle;">' . $logoCelda . '</td>
    <td style="text-align:center;font-weight:800;font-size:13pt;padding:6px;vertical-align:middle;">REPORTE DE INDICADORES</td>
  </tr>
</table>';

// ── Pie repetido en cada página -- mismo pie fijo que ya usa la vista en pantalla
// (includes/indicadores.php, ind_render_detalle) -- DOC-GEN-FO-15, Revisión N° 5, 18/Sep/21.
$footerHtml = '
<div style="border-top:1px solid #999;font-size:7pt;color:#222;text-align:left;padding-top:3px;">
  <div style="font-weight:700;">DOC-GEN-FO-15; Revisión N&deg; 5; Fecha: 18 / Sep / 21; P&aacute;gina: {PAGENO}</div>
  <div>Este documento es propiedad de EMPRESA EJEMPLO y no podr&aacute; ser reproducido total ni parcialmente sin previa autorizaci&oacute;n</div>
</div>';

ob_start();
?>
<style>
    body { font-family: Arial, sans-serif; color: #222; font-size: 10pt; }
    .caja { border: 1px solid #000; padding: 8px 10px; margin-bottom: 8px; }
    table.info-tabla { width: 100%; border-collapse: collapse; }
    table.info-tabla td { border: 1px solid #000; padding: 6px 8px; vertical-align: top; font-size: 9.5pt; }
    table.info-tabla td.etiqueta { font-weight: 700; width: 30%; }
    table.info-tabla td.fecha-celda { width: 22%; text-align: center; font-weight: 700; }
    .formula-titulo { font-weight: 700; margin-bottom: 4px; }
    .formula-linea { font-size: 9.5pt; margin: 3px 0; }
    .servicio-caja { border: 1px solid #000; padding: 6px 10px; margin-bottom: 6px; }
    .servicio-titulo { font-weight: 700; font-size: 9.5pt; margin-bottom: 4px; }
    table.calc-tabla { border-collapse: collapse; }
    table.calc-tabla td { padding: 1px 4px; font-size: 9pt; vertical-align: middle; }
    table.calc-tabla td.fraccion { text-align: center; border-left: 1px solid #000; padding-left: 10px; }
    table.fraccion-int { border-collapse: collapse; margin: 0 auto; }
    table.fraccion-int td { text-align: center; padding: 0 4px; font-size: 9pt; }
    table.fraccion-int tr.linea td { border-top: 1px solid #000; }
    .nota-linea { font-size: 8.5pt; color: #444; margin-top: 3px; }
    .promedio-linea { font-weight: 700; margin: 6px 0; }
    .resultado-tabla { width: 100%; border-collapse: collapse; }
    .resultado-tabla td { padding: 3px 0; font-size: 9.5pt; }
    .firma-linea { margin-top: 26px; border-top: 1px solid #000; width: 260px; }
    .firma-caption { font-size: 8.5pt; color: #444; }
</style>

<div class="caja">
<table class="info-tabla">
    <tr>
        <td class="etiqueta">Departamento o área:</td>
        <td><?= htmlspecialchars(strtoupper($cfg['nombre_area'])) ?></td>
        <td class="fecha-celda" rowspan="3">Fecha:<br><?= htmlspecialchars($fd($reporte['fecha_reporte'])) ?></td>
    </tr>
    <tr>
        <td class="etiqueta">Nombre del representante:</td>
        <td><?= $v($reporte['representante_nombre']) ?></td>
    </tr>
    <tr>
        <td class="etiqueta">Cargo:</td>
        <td><?= $v($reporte['representante_cargo']) ?></td>
    </tr>
</table>
</div>

<div class="caja">
    <div class="formula-titulo">Formula que aplica al departamento:</div>
    <div class="formula-linea"><?= htmlspecialchars($cfg['label_real']) ?>" &nbsp;–&nbsp; <?= htmlspecialchars($cfg['label_referencia']) ?> &nbsp;X 100 = %</div>
    <div class="formula-linea"><?= htmlspecialchars($cfg['label_programada']) ?>" &nbsp;–&nbsp; <?= htmlspecialchars($cfg['label_referencia']) ?> &nbsp;X 100 = %</div>
</div>

<div class="caja">
    <div class="formula-titulo">Sustitución de Términos por valores</div>
    <?php if (empty($entradas)): ?>
    <p style="color:#888;">Sin entradas.</p>
    <?php else: foreach ($entradas as $e):
        $diasR = $e['dias_reales'];
        $diasP = $e['dias_programados'];
        $pct   = $e['porcentaje'];
    ?>
    <div class="servicio-caja">
        <div class="servicio-titulo"><?= htmlspecialchars($cfg['label_etiqueta']) ?>: <?= htmlspecialchars($e['etiqueta'] ?? '') ?></div>
        <table class="calc-tabla">
            <tr>
                <td>(<?= htmlspecialchars($fd($e['fecha_real'])) ?> &nbsp;–&nbsp; <?= htmlspecialchars($fd($e['fecha_referencia'])) ?>) &nbsp;X 100 =</td>
                <td rowspan="2" class="fraccion">
                    <table class="fraccion-int">
                        <tr><td><?= $diasR !== null ? $diasR : '—' ?></td></tr>
                        <tr class="linea"><td><?= $diasP !== null ? $diasP : '—' ?></td></tr>
                    </table>
                </td>
                <td rowspan="2" style="text-align:center;">&nbsp;X 100 =&nbsp;<strong<?= $pct !== null && $pct > 100 ? ' style="color:#b71c1c;"' : '' ?>><?= $pct !== null ? number_format($pct, 2) . '%' : '—' ?></strong></td>
            </tr>
            <tr>
                <td>(<?= htmlspecialchars($fd($e['fecha_programada'])) ?> &nbsp;–&nbsp; <?= htmlspecialchars($fd($e['fecha_referencia'])) ?>) &nbsp;X 100 =</td>
            </tr>
        </table>
        <?php if (!empty($e['nota'])): ?><div class="nota-linea">Nota: <?= htmlspecialchars($e['nota']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; endif; ?>

    <div class="promedio-linea">
        Promedio de porcentajes (Σ porcentajes / total de porcentajes):
        <?php if ($promedio !== null && !empty($entradas)):
            $sumaTexto = implode(' + ', array_map(fn($e) => $e['porcentaje'] !== null ? number_format($e['porcentaje'], 2) : '—', $entradas));
        ?>
        <?= htmlspecialchars($sumaTexto) ?> / <?= count($entradas) ?> = <strong<?= $promedio > 100 ? ' style="color:#b71c1c;"' : '' ?>><?= number_format($promedio, 2) ?>%</strong>
        <?php else: ?>
        —
        <?php endif; ?>
    </div>
</div>

<div class="caja">
    <table class="resultado-tabla">
        <tr><td><strong>Indicador del Departamento o área:</strong></td></tr>
        <tr><td>% obtenido = <strong><?= $promedio !== null ? number_format($promedio, 2) . '%' : '—' ?></strong>, contra el porcentaje requerido que aplica para el departamento <strong><?= number_format($requerido, 2) ?>%</strong></td></tr>
        <tr><td>Resultado:
            <?php if ($reporte['estado'] === 'finalizado'): ?>
                <?= $reporte['cumple'] ? '__X__ Cumple &nbsp;&nbsp; _____ No cumple' : '_____ Cumple &nbsp;&nbsp; __X__ No cumple' ?>
            <?php else: ?>
                _____ Cumple &nbsp;&nbsp; _____ No cumple
            <?php endif; ?>
            con los objetivos del departamento o área.</td></tr>
    </table>
    <p style="margin-top:16px;">Atentamente</p>
    <div class="firma-linea"></div>
    <div class="firma-caption">Nombre, firma y cargo</div>
</div>
<?php
$bodyHtml = ob_get_clean();

$mpdfConfig = [
    'format' => 'Letter',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 26,
    'margin_bottom' => 16,
    'margin_header' => 6,
    'margin_footer' => 6,
    'useSubstitutions' => true,
];
$arialDir = __DIR__ . '/../fonts/arial';
$arialArchivos = ['R' => 'arial.ttf', 'B' => 'arialbd.ttf', 'I' => 'ariali.ttf', 'BI' => 'arialbi.ttf'];
$arialCompleto = true;
foreach ($arialArchivos as $archivo) {
    if (!file_exists($arialDir . '/' . $archivo)) { $arialCompleto = false; break; }
}
if ($arialCompleto) {
    $fontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $fontDirs[] = $arialDir;
    $fontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
    $fontData['arial'] = $arialArchivos;
    $mpdfConfig['fontDir'] = $fontDirs;
    $mpdfConfig['fontdata'] = $fontData;
    $mpdfConfig['default_font'] = 'arial';
}
$mpdf = new \Mpdf\Mpdf($mpdfConfig);
$mpdf->SetCompression(false); // reporte de 1 página, no vale la pena comprimir a cambio de que el texto ya no sea buscable en el PDF
$mpdf->SetHTMLHeader($headerHtml);
$mpdf->SetHTMLFooter($footerHtml);
$mpdf->WriteHTML($bodyHtml);

$pdfBytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

$nombre_archivo = 'Indicadores_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $cfg['nombre_area']) . '_T' . (int)$reporte['trimestre'] . '_' . (int)$reporte['anio'] . '.pdf';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($pdfBytes));
echo $pdfBytes;
