<?php
require_once '../includes/config.php';
require_once '../includes/hoja_viajera.php';

if (!isLoggedIn() || !hv_valida_acceso()) {
    http_response_code(403);
    exit('Acceso denegado');
}

$hoja_id = intval($_GET['hoja_id'] ?? 0);
if (!$hoja_id) { http_response_code(400); exit('hoja_id requerido'); }

$db = getDB();

$stmt = $db->prepare("SELECT hv.*, p.nombre AS proyecto_nombre
                       FROM hoja_viajera hv JOIN proyectos p ON p.id = hv.proyecto_id
                       WHERE hv.id = ?");
$stmt->execute([$hoja_id]);
$hoja = $stmt->fetch();
if (!$hoja) { http_response_code(404); exit('Hoja viajera no encontrada'); }

$stmtP = $db->prepare("SELECT hvp.*, up.nombre_completo AS vobo_produccion_por_nombre, uc.nombre_completo AS vobo_calidad_por_nombre
                        FROM hoja_viajera_pasos hvp
                        LEFT JOIN usuarios up ON up.id = hvp.vobo_produccion_por
                        LEFT JOIN usuarios uc ON uc.id = hvp.vobo_calidad_por
                        WHERE hvp.hoja_id = ? ORDER BY hvp.numero ASC");
$stmtP->execute([$hoja_id]);
$pasos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$estado_vobo = function (?string $estado, ?string $por, ?string $fecha): string {
    if ($estado === 'conforme') return 'Conforme' . ($por ? " ($por)" : '');
    if ($estado === 'no_aplica') return 'No Aplica';
    return '—';
};

// Logo embebido como base64 — mismo criterio que vista_pdf_bom.php (evita depender de que
// la imagen cargue a tiempo antes de que se dispare la impresión dentro del iframe oculto).
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
<title>Hoja Viajera <?= htmlspecialchars($hoja['proyecto_nombre']) ?></title>
<style>
    * { box-sizing: border-box; }
    @page { size: letter landscape; margin: 8mm; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; padding: 10px; color: #222; font-size: 10px; }
    .hv-header { border: 1px solid #333; margin-bottom: 8px; }
    .hv-header .top { display: flex; border-bottom: 1px solid #333; }
    .hv-header .top .logo { flex: 0 0 130px; border-right: 1px solid #333; padding: 8px; text-align: center; font-weight: 800; font-size: 16px; color: #003d5c; display: flex; align-items: center; justify-content: center; }
    .hv-header .top .logo img { max-height: 34px; }
    .hv-header .top .titulo { flex: 1; padding: 8px; text-align: center; font-weight: 800; font-size: 18px; display: flex; align-items: center; justify-content: center; }
    .hv-header .row { display: flex; border-bottom: 1px solid #333; font-size: 11px; }
    .hv-header .row:last-child { border-bottom: none; }
    .hv-header .row div { flex: 1; padding: 4px 8px; border-right: 1px solid #333; }
    .hv-header .row div:last-child { border-right: none; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.items th { background: #003d5c; color: #fff; font-size: 9px; padding: 4px 6px; border: 1px solid #ccc; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.items td { border: 1px solid #ccc; padding: 3px 6px; font-size: 8.5px; vertical-align: top; }
    .pie { border: 1px solid #333; }
    .pie .tb { display: flex; }
    .pie .tb div { flex: 1; border-right: 1px solid #333; border-bottom: 1px solid #333; text-align: center; padding: 3px 4px; font-size: 9px; }
    .pie .tb div:last-child { border-right: none; }
    .pie .disclaimer { text-align: center; padding: 3px 6px; font-size: 9px; }
    .pie2 { border: 1px solid #333; border-top: none; }
    .pie2 .row { display: flex; }
    .pie2 .row div { flex: 1; border-right: 1px solid #333; text-align: center; padding: 4px 8px; font-size: 9px; }
    .pie2 .row div:last-child { border-right: none; }
    .pie2 .row.labels div { font-weight: bold; border-top: 1px solid #333; }
</style>
</head>
<body>
    <div class="hv-header">
        <div class="top">
            <div class="logo">
                <?php if ($logo_data_uri): ?><img src="<?= $logo_data_uri ?>" alt="EMPRESA EJEMPLO"><?php else: ?>EMPRESA EJEMPLO<?php endif; ?>
            </div>
            <div class="titulo">Hoja Viajera</div>
        </div>
        <div class="row"><div>Cliente: <?= htmlspecialchars($hoja['cliente'] ?? '') ?></div></div>
        <div class="row">
            <div>No. proyecto: <?= htmlspecialchars($hoja['proyecto_nombre']) ?></div>
            <div>No. de serie: <?= htmlspecialchars($hoja['no_serie'] ?? '') ?></div>
        </div>
        <div class="row">
            <div>Producto: <?= htmlspecialchars($hoja['producto'] ?? '') ?></div>
            <div>ID / TAG: <?= htmlspecialchars($hoja['id_tag'] ?? '') ?></div>
        </div>
        <div class="row">
            <div>Pasadas de recubrimiento (mano): <?= $hoja['pasadas_recubrimiento'] !== null ? htmlspecialchars($hoja['pasadas_recubrimiento']) : '—' ?></div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:4%;">Número</th>
                <th style="width:34%;">Proceso o actividad</th>
                <th style="width:15%;">Vo.Bo. Producción</th>
                <th style="width:9%;">Fecha Producción</th>
                <th style="width:15%;">Vo.Bo. Calidad</th>
                <th style="width:9%;">Fecha Calidad</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pasos as $p): ?>
            <tr>
                <td style="text-align:center;font-weight:bold;"><?= $p['numero'] ?></td>
                <td>
                    <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                    <?php if ($p['tipo'] === 'recubrimientos'): ?>
                        <br>
                        <?php foreach (['sub_valvula'=>'Válvula','sub_actuador'=>'Actuador','sub_accesorios'=>'Accesorios'] as $campo=>$label): ?>
                            <?= $p[$campo] ? '☑' : '☐' ?> <?= $label ?><br>
                        <?php endforeach; ?>
                    <?php elseif ($p['tipo'] === 'detallado'): ?>
                        <br>Actividad: <?= $p['actividad_estado'] === 'conforme' ? 'Conforme' : ($p['actividad_estado'] === 'no_conforme' ? 'No Conforme' : '—') ?>
                        <br>Acción realizada: <?= ['retrabajo'=>'Retrabajo','reproceso'=>'Reproceso','no_se_requirio'=>'No se requirió'][$p['accion_realizada']] ?? '—' ?>
                        <br>NC N° Levantada: <?= htmlspecialchars($p['nc_numero'] ?: '—') ?>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;"><?= $estado_vobo($p['vobo_produccion'], $p['vobo_produccion_por_nombre'], $p['vobo_produccion_fecha']) ?></td>
                <td style="text-align:center;"><?= $p['vobo_produccion_fecha'] ? date('d/m/Y H:i', strtotime($p['vobo_produccion_fecha'])) : '—' ?></td>
                <td style="text-align:center;"><?= $estado_vobo($p['vobo_calidad'], $p['vobo_calidad_por_nombre'], $p['vobo_calidad_fecha']) ?></td>
                <td style="text-align:center;"><?= $p['vobo_calidad_fecha'] ? date('d/m/Y H:i', strtotime($p['vobo_calidad_fecha'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pie2">
        <div class="row">
            <div>N° retrabajos / 8 = <?= number_format($hoja['n_retrabajos'] / 8, 2) ?></div>
            <div>N° reprocesos / 8 = <?= number_format($hoja['n_reprocesos'] / 8, 2) ?></div>
        </div>
        <div class="row">
            <div>Resultado Producción: <?= ucfirst($hoja['resultado_produccion']) ?></div>
            <div>Resultado Calidad: <?= ucfirst($hoja['resultado_calidad']) ?></div>
        </div>
        <div class="row labels">
            <div>Producción</div>
            <div>Calidad</div>
        </div>
    </div>

    <div class="pie" style="margin-top:6px;">
        <div class="tb">
            <div style="font-weight:bold;">DOC-PRD-FO-01A</div>
            <div>Revisión No: <strong>3</strong></div>
            <div>Fecha: <strong>15 / Ago / 21</strong></div>
            <div>Página: <strong>1</strong></div>
        </div>
        <div class="disclaimer">
            Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
        </div>
    </div>

    <script>
        // Si esta página se abre directamente (fuera del iframe de impresión de produccion.php/
        // calidad.php), igual dispara la vista de impresión nativa por su cuenta.
        if (window.top === window.self) {
            window.addEventListener('load', function () { window.print(); });
        }
    </script>
</body>
</html>
