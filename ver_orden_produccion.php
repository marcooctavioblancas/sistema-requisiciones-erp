<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$db = getDB();
$id = intval($_GET['id'] ?? 0);

$orden = null;
if ($id > 0) {
    try {
        $s = $db->prepare("SELECT * FROM ordenes_produccion WHERE id = ?");
        $s->execute([$id]);
        $orden = $s->fetch();
        if ($orden) {
            $orden['items_arr']    = json_decode($orden['items'],    true) ?: [];
            $orden['checklist_arr'] = json_decode($orden['checklist'], true) ?: [];
        }
    } catch (Exception $e) {}
}

if (!$orden) {
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;">
        <h2>Orden no encontrada</h2>
        <p>La orden de producción solicitada no existe o no está disponible.</p>
        <a href="proyecto.php?seccion=formato_trabajo">← Volver</a>
    </body></html>';
    exit();
}

// Helper: checar si item está marcado
function clChecked(array $arr, string $key, string $item): bool {
    return in_array($item, $arr[$key] ?? []);
}

$cl = $orden['checklist_arr'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden de Producción #<?= htmlspecialchars($orden['numero_proyecto'] ?? $orden['id']) ?> — EMPRESA EJEMPLO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            color: #111;
        }

        /* ── Top bar ── */
        .top-bar {
            background: linear-gradient(135deg, #003d5c, #005686);
            padding: 14px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,61,92,.25);
        }
        .top-bar-logo img { height: 48px; object-fit: contain; }
        .top-bar-logo-fallback { font-size: 1.1rem; font-weight: 800; color: #fff; letter-spacing: 2px; }
        .top-bar-actions { display: flex; gap: 10px; align-items: center; }
        .btn-action {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 18px; border-radius: 8px; font-family: inherit;
            font-size: .82rem; font-weight: 600; cursor: pointer;
            border: 2px solid rgba(255,255,255,.4); color: #fff;
            background: rgba(255,255,255,.15); transition: background .2s;
            text-decoration: none !important;
        }
        .btn-action:hover { background: rgba(255,255,255,.28); }
        .btn-action.print { background: rgba(255,255,255,.25); }

        /* ── Estado badge ── */
        .estado-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 20px;
            font-size: .78rem; font-weight: 700;
        }
        .estado-badge.activa  { background: #e8f5e9; color: #2e7d32; border: 1.5px solid #a5d6a7; }
        .estado-badge.borrador { background: #f0f0f0; color: #555;   border: 1.5px solid #ccc; }

        /* ── Wrapper pantalla ── */
        .op-screen-wrap {
            max-width: 960px;
            margin: 24px auto;
            background: #f0f4f8;
            padding: 18px;
        }

        /* ── Variables de color ── */
        :root {
            --op-azul: #003d5c;
            --op-borde: #222;
            --op-bg-header: #d0dce8;
            --op-bg-title: #002d46;
            --op-bg-cl-header: #b8cdd8;
            --op-font: 'Montserrat', 'Segoe UI', Arial, sans-serif;
        }

        /* ── Documento ── */
        .op-doc {
            background: #fff;
            border: 2px solid var(--op-borde);
            font-family: var(--op-font);
            font-size: 0.78rem;
        }

        /* ── Encabezado logo + título + logo ── */
        .op-header {
            display: grid;
            grid-template-columns: 140px 1fr 140px;
            border-bottom: 2px solid var(--op-borde);
        }
        .op-header-logo {
            border-right: 1.5px solid var(--op-borde);
            display: flex; align-items: center; justify-content: center;
            padding: 8px; min-height: 72px;
        }
        .op-header-logo img { max-width: 120px; max-height: 56px; object-fit: contain; }
        .op-header-logo-fallback {
            font-size: 1rem; font-weight: 800; color: var(--op-azul);
            letter-spacing: 2px; text-align: center;
        }
        .op-header-title {
            display: flex; align-items: center; justify-content: center;
            background: var(--op-bg-title); color: #fff;
            font-size: 1.05rem; font-weight: 800; letter-spacing: 1.5px;
            text-align: center; padding: 10px 6px; text-transform: uppercase;
        }
        .op-header-logo-right {
            border-left: 1.5px solid var(--op-borde);
            display: flex; align-items: center; justify-content: center;
            padding: 8px; min-height: 72px;
        }
        .op-header-logo-right img { max-width: 120px; max-height: 56px; object-fit: contain; }

        /* ── Estado header row ── */
        .op-estado-row {
            background: #f8fafc;
            border-bottom: 1.5px solid var(--op-borde);
            padding: 7px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .74rem;
            color: #555;
        }

        /* ── Campos cabecera ── */
        .op-fields {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            border-bottom: 1.5px solid var(--op-borde);
        }
        .op-field {
            border-right: 1px solid var(--op-borde);
            padding: 6px 10px;
        }
        .op-field:last-child { border-right: none; }
        .op-field-full { grid-column: 1 / -1; border-right: none; border-top: 1px solid var(--op-borde); }
        .op-field label {
            display: block; font-size: .65rem; font-weight: 700;
            color: #444; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px;
        }
        .op-field-val {
            font-size: .82rem; color: #111; min-height: 1.4em;
            padding: 1px 0; line-height: 1.5;
        }

        /* ── Título sección ── */
        .op-section-title {
            background: var(--op-bg-header);
            border-bottom: 1.5px solid var(--op-borde);
            border-top: 1.5px solid var(--op-borde);
            padding: 5px 10px;
            font-weight: 800; font-size: .75rem; text-transform: uppercase;
            letter-spacing: .8px; color: var(--op-azul);
        }

        /* ── Tabla ── */
        .op-table { width: 100%; border-collapse: collapse; font-size: .75rem; }
        .op-table th {
            background: var(--op-bg-header); border: 1px solid var(--op-borde);
            padding: 5px 7px; font-weight: 700; text-align: center;
            text-transform: uppercase; font-size: .68rem; letter-spacing: .5px;
        }
        .op-table td {
            border: 1px solid #bbb; padding: 4px 7px; vertical-align: middle;
        }
        .op-table .td-num { width: 44px; text-align: center; color: #666; font-size: .7rem; }
        .op-table .td-tag { width: 90px; text-align: center; }
        .op-table .td-qty { width: 58px; text-align: center; }
        .op-table tr:nth-child(even) td { background: #f7fafc; }
        .op-table .empty-row td { color: #bbb; font-style: italic; text-align: center; }

        /* ── Checklists ── */
        .op-cl-wrapper {
            border-top: 1.5px solid var(--op-borde);
            display: grid; grid-template-columns: repeat(3, 1fr);
        }
        .op-cl-col {
            border-right: 1.5px solid var(--op-borde);
            display: flex; flex-direction: column;
        }
        .op-cl-col:last-child { border-right: none; }
        .op-cl-col-title {
            background: var(--op-bg-cl-header);
            border-bottom: 1px solid var(--op-borde);
            padding: 5px 8px; font-weight: 800; font-size: .68rem;
            text-transform: uppercase; letter-spacing: .6px;
            color: var(--op-azul); text-align: center;
        }
        .op-cl-item {
            display: flex; align-items: center; gap: 7px;
            padding: 5px 9px; border-bottom: 1px solid #e0e8f0;
        }
        .op-cl-item:last-child { border-bottom: none; }
        .op-cl-item input[type="checkbox"] {
            width: 14px; height: 14px; accent-color: var(--op-azul); flex-shrink: 0;
        }
        .op-cl-item span {
            font-size: .72rem; color: #222; line-height: 1.35;
        }
        .op-cl-item input[type="checkbox"]:checked + span {
            color: var(--op-azul); font-weight: 700;
        }

        /* ── Observaciones ── */
        .op-obs {
            border-top: 1.5px solid var(--op-borde); padding: 8px 12px;
        }
        .op-obs label {
            font-size: .67rem; font-weight: 700; color: #444;
            text-transform: uppercase; letter-spacing: .5px;
            display: block; margin-bottom: 4px;
        }
        .op-obs-val {
            font-size: .8rem; color: #222; line-height: 1.6;
            white-space: pre-wrap; min-height: 2em;
        }

        /* ── Footer tabla editable ── */
        .op-footer-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 2px solid var(--op-borde);
            font-size: .72rem;
            font-family: var(--op-font);
        }
        .op-footer-table td {
            border: 1px solid var(--op-borde);
            padding: 5px 10px;
            font-weight: 700;
            text-align: center;
            color: #111;
        }
        .op-footer-table .footer-nota {
            font-weight: 400;
            font-style: italic;
            font-size: .67rem;
            color: #333;
        }
        .footer-edit {
            display: inline-block;
            min-width: 28px;
            outline: none;
            border-bottom: 1.5px dashed #999;
            cursor: text;
            padding: 0 2px;
            font-weight: 700;
        }
        .footer-edit:focus { border-bottom-color: #003d5c; background: #f0f8ff; }

        /* ── Print ── */
        @media print {
            /* Márgenes mínimos: suprime cabeceras del navegador y gana espacio vertical */
            @page { size: Letter portrait; margin: 3mm; }

            html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .top-bar, .no-print { display: none !important; }

            /* Wrapper sin padding extra — los 3mm del @page son suficientes */
            .op-screen-wrap {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                max-width: 100% !important;
            }

            /* Documento: fuente ligeramente menor para ganar líneas */
            .op-doc {
                border: 1.5px solid #000 !important;
                font-size: 0.68rem !important;
                page-break-inside: avoid !important;
            }

            /* Encabezado más compacto */
            .op-header-logo,
            .op-header-logo-right { min-height: 58px !important; padding: 6px !important; }
            .op-header-title { font-size: 0.92rem !important; padding: 7px 6px !important; }

            /* Campos cabecera */
            .op-field { padding: 4px 8px !important; }
            .op-field-val { font-size: 0.75rem !important; }

            /* Títulos de sección */
            .op-section-title { padding: 3px 8px !important; font-size: 0.68rem !important; }

            /* Tabla de productos */
            .op-table th { padding: 3px 5px !important; font-size: 0.62rem !important; }
            .op-table td { padding: 3px 5px !important; }

            /* Checklists */
            .op-cl-item { padding: 3px 8px !important; }
            .op-cl-item span { font-size: 0.65rem !important; }
            .op-cl-col-title { padding: 3px 7px !important; font-size: 0.62rem !important; }

            /* Observaciones */
            .op-obs { padding: 5px 10px !important; }

            /* Footer: nunca partir en dos páginas */
            .op-footer-table { page-break-inside: avoid !important; }
            .op-footer-table td { padding: 4px 8px !important; font-size: 0.67rem !important; }
            .footer-edit { border-bottom: none !important; background: transparent !important; }

            /* Checkboxes con color */
            .op-cl-item input[type="checkbox"] {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>

<!-- Top bar -->
<div class="top-bar no-print">
    <div class="top-bar-logo">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO"
             onerror="this.style.display='none';document.getElementById('vop-logo-fb').style.display='block'">
        <span id="vop-logo-fb" class="top-bar-logo-fallback" style="display:none;">EMPRESA EJEMPLO</span>
    </div>
    <div class="top-bar-actions">
        <span class="estado-badge <?= $orden['estado'] === 'activa' ? 'activa' : 'borrador' ?>">
            <i class="fas fa-<?= $orden['estado'] === 'activa' ? 'broadcast-tower' : 'file-alt' ?>"></i>
            <?= $orden['estado'] === 'activa' ? 'Difundida' : 'Borrador' ?>
        </span>
        <button class="btn-action print" onclick="window.print()">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <button class="btn-action print" id="btnDescargarPDF" onclick="descargarPDF()">
            <i class="fas fa-file-pdf"></i> Descargar PDF
        </button>
        <a href="proyecto.php?seccion=formato_trabajo" class="btn-action">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
</div>

<!-- Documento -->
<div class="op-screen-wrap">
    <div class="op-doc" id="op-doc">

        <!-- ── ENCABEZADO ── -->
        <div class="op-header">
            <div class="op-header-logo">
                <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO"
                     onerror="this.style.display='none';document.getElementById('vop-doc-logo-fb').style.display='flex'">
                <div id="vop-doc-logo-fb" class="op-header-logo-fallback" style="display:none;">EMPRESA EJEMPLO</div>
            </div>
            <div class="op-header-title">ORDEN DE PRODUCCIÓN / SERVICIO</div>
            <div class="op-header-logo-right">
                <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO isotipo" onerror="this.style.display='none'">
            </div>
        </div>

        <!-- ── Estado ── -->
        <div class="op-estado-row no-print">
            <i class="fas fa-info-circle"></i>
            <strong>Vista de solo lectura</strong> —
            Estado: <strong><?= $orden['estado'] === 'activa' ? 'Difundida a todas las áreas' : 'Borrador' ?></strong>
            <?php if ($orden['fecha_difusion']): ?>
                &nbsp;·&nbsp; Difundida el <?= date('d/m/Y H:i', strtotime($orden['fecha_difusion'])) ?>
            <?php endif; ?>
        </div>

        <!-- ── CAMPOS TÉCNICOS ── -->
        <div class="op-fields">
            <div class="op-field">
                <label>Fecha</label>
                <div class="op-field-val"><?= $orden['fecha'] ? date('d/m/Y', strtotime($orden['fecha'])) : '—' ?></div>
            </div>
            <div class="op-field">
                <label>N° de Proyecto</label>
                <div class="op-field-val"><?= htmlspecialchars($orden['numero_proyecto'] ?? '—') ?></div>
            </div>
            <div class="op-field">
                <label>Orden de Compra</label>
                <div class="op-field-val"><?= htmlspecialchars($orden['orden_compra'] ?? '—') ?></div>
            </div>
            <div class="op-field op-field-full">
                <label>Cliente</label>
                <div class="op-field-val"><?= htmlspecialchars($orden['cliente'] ?? '—') ?></div>
            </div>
            <div class="op-field op-field-full">
                <label>Descripción del Proyecto</label>
                <div class="op-field-val" style="white-space:pre-wrap;"><?= htmlspecialchars($orden['descripcion'] ?? '') ?></div>
            </div>
            <div class="op-field op-field-full">
                <label>Fecha Compromiso de Entrega</label>
                <div class="op-field-val"><?= htmlspecialchars($orden['fecha_compromiso'] ?? '—') ?></div>
            </div>
        </div>

        <!-- ── TABLA DE PRODUCTOS ── -->
        <div class="op-section-title">Productos y/o Servicios</div>
        <table class="op-table">
            <thead>
                <tr>
                    <th class="td-num">Item</th>
                    <th class="td-tag">ID / TAG</th>
                    <th class="td-qty">Cantidad</th>
                    <th>Producto y/o Servicio &nbsp;(Descripción)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orden['items_arr'])): ?>
                <tr class="empty-row"><td colspan="4">Sin productos registrados</td></tr>
            <?php else: ?>
                <?php foreach ($orden['items_arr'] as $it): ?>
                <tr>
                    <td class="td-num"><?= intval($it['item']) ?></td>
                    <td class="td-tag"><?= htmlspecialchars($it['tag'] ?? '') ?></td>
                    <td class="td-qty"><?= htmlspecialchars($it['qty'] ?? '') ?></td>
                    <td><?= htmlspecialchars($it['desc'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- ── CHECKLISTS ── -->
        <div class="op-section-title">Recursos y Control de Proceso</div>
        <div class="op-cl-wrapper">

            <!-- Col 1: Maquinaria -->
            <div class="op-cl-col">
                <div class="op-cl-col-title">Maquinaria / Equipo</div>
                <?php foreach (['Equipo de Izaje','Medición lineal','Medición de presión','Medición eléctrica'] as $item): ?>
                <div class="op-cl-item">
                    <input type="checkbox" disabled <?= clChecked($cl, 'maquinaria', $item) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($item) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Col 2: Control de proceso -->
            <div class="op-cl-col">
                <div class="op-cl-col-title">Control de Proceso</div>
                <?php foreach (['Plan general','Plan de calidad','Protocolo de pruebas (FAT/OSAT)','Hoja viajera','Reporte de servicio'] as $item): ?>
                <div class="op-cl-item">
                    <input type="checkbox" disabled <?= clChecked($cl, 'control', $item) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($item) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Col 3: Infraestructura + Seguimiento -->
            <div class="op-cl-col">
                <div class="op-cl-col-title">Infraestructura</div>
                <?php foreach (['Planta principal','Instalaciones del cliente'] as $item): ?>
                <div class="op-cl-item">
                    <input type="checkbox" disabled <?= clChecked($cl, 'infra', $item) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($item) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="op-cl-col-title" style="margin-top:auto;border-top:1px solid var(--op-borde);border-bottom:1px solid var(--op-borde);">Seguimiento</div>
                <?php foreach (['Pruebas internas','Reporte / Bitácora','Pruebas ante el cliente'] as $item): ?>
                <div class="op-cl-item">
                    <input type="checkbox" disabled <?= clChecked($cl, 'seguimiento', $item) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($item) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

        </div><!-- /op-cl-wrapper fila 1 -->

        <!-- Segunda fila checklists -->
        <div class="op-cl-wrapper" style="border-top:1px solid var(--op-borde);">

            <!-- Col 1: Personal -->
            <div class="op-cl-col">
                <div class="op-cl-col-title">Personal</div>
                <?php foreach (['Gerente de Producción','Personal de Producción','Gerente de Servicios','Ingeniero de Servicios'] as $item): ?>
                <div class="op-cl-item">
                    <input type="checkbox" disabled <?= clChecked($cl, 'personal', $item) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($item) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Col 2: Liberación -->
            <div class="op-cl-col">
                <div class="op-cl-col-title">Liberación</div>
                <?php foreach (['Lista de embarque','Reporte / Pruebas OSAT'] as $item): ?>
                <div class="op-cl-item">
                    <input type="checkbox" disabled <?= clChecked($cl, 'liberacion', $item) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($item) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Col 3: vacía -->
            <div class="op-cl-col"></div>

        </div><!-- /op-cl-wrapper fila 2 -->

        <!-- ── OBSERVACIONES ── -->
        <div class="op-obs">
            <label>Observaciones / Notas adicionales</label>
            <div class="op-obs-val"><?= htmlspecialchars($orden['observaciones'] ?? '') ?></div>
        </div>

        <!-- ── FOOTER DEL DOCUMENTO ── -->
        <table class="op-footer-table">
            <tr>
                <td style="width:25%">DOC-PRY-FO-02B</td>
                <td style="width:25%">Revisión No:&nbsp;<span class="footer-edit" contenteditable="true" title="Editable antes de imprimir">6</span></td>
                <td style="width:25%">Fecha:&nbsp;<span class="footer-edit" contenteditable="true" title="Editable antes de imprimir">09/Ene/25</span></td>
                <td style="width:25%">Página:&nbsp;<span class="footer-edit" contenteditable="true" title="Editable antes de imprimir">1</span></td>
            </tr>
            <tr>
                <td colspan="4" class="footer-nota">
                    Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización.
                </td>
            </tr>
        </table>

    </div><!-- /.op-doc -->
</div><!-- /.op-screen-wrap -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function descargarPDF() {
    var elemento = document.getElementById('op-doc');
    var numProy  = <?= json_encode($orden['numero_proyecto'] ?? 'OP') ?>;
    window.scrollTo(0, 0);
    html2pdf()
        .set({
            margin:      0,
            filename:    'Orden_Produccion_' + numProy + '.pdf',
            image:       { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 1, useCORS: false, logging: false, scrollX: 0, scrollY: 0 },
            jsPDF:       { unit: 'in', format: 'letter', orientation: 'portrait', compress: true }
        })
        .from(elemento)
        .save();
}
</script>

</body>
</html>
