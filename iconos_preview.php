<?php
require_once __DIR__ . '/includes/config.php';
if (!isLoggedIn()) { redirect('index.php'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iconos del Proyecto</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 30px; }
        h1 { text-align: center; color: #1e3a5f; margin-bottom: 8px; font-size: 24px; }
        p.sub { text-align: center; color: #666; margin-bottom: 24px; font-size: 14px; }
        .search-bar { display: flex; justify-content: center; margin-bottom: 24px; }
        .search-bar input {
            width: 340px; padding: 10px 16px; border: 1px solid #ccc;
            border-radius: 6px; font-size: 15px; outline: none;
        }
        .search-bar input:focus { border-color: #2196F3; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 14px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .icon-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 18px 10px 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: default;
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .icon-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.12); border-color: #2196F3; }
        .icon-card i { font-size: 32px; color: #1e3a5f; }
        .icon-name {
            font-size: 11px;
            color: #555;
            text-align: center;
            word-break: break-all;
            line-height: 1.3;
        }
        .copy-btn {
            font-size: 10px;
            padding: 3px 8px;
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 4px;
            cursor: pointer;
            color: #1565c0;
            display: none;
        }
        .icon-card:hover .copy-btn { display: block; }
        .copy-btn:hover { background: #bbdefb; }
        .no-results { text-align: center; color: #999; font-size: 16px; margin-top: 40px; grid-column: 1/-1; }
        .count { text-align: center; color: #888; font-size: 13px; margin-bottom: 16px; }
    </style>
</head>
<body>
<h1>Iconos del Proyecto</h1>
<p class="sub">Font Awesome 5 — todos los iconos actualmente usados en el sistema</p>
<div class="search-bar">
    <input type="text" id="buscar" placeholder="Buscar icono... ej: truck, file, user" oninput="filtrar()">
</div>
<div class="count" id="conteo"></div>
<div class="grid" id="grid">
<?php
$iconos = [
    'fa-arrow-down','fa-arrow-left','fa-arrow-right','fa-arrow-up',
    'fa-balance-scale','fa-ban','fa-bell','fa-bell-slash','fa-binoculars',
    'fa-box','fa-boxes','fa-box-open','fa-broadcast-tower','fa-building',
    'fa-calculator','fa-calendar','fa-calendar-alt','fa-calendar-check','fa-calendar-plus',
    'fa-chart-bar','fa-chart-line','fa-chart-pie',
    'fa-check','fa-check-circle','fa-check-double',
    'fa-chevron-down','fa-chevron-left','fa-chevron-right','fa-chevron-up',
    'fa-circle','fa-clipboard-check','fa-clipboard-list','fa-clock',
    'fa-cloud-upload-alt','fa-cogs','fa-coins','fa-comment','fa-comment-alt',
    'fa-comment-dots','fa-comments','fa-compress-alt','fa-copy','fa-credit-card',
    'fa-cubes','fa-database','fa-dollar-sign','fa-download','fa-drafting-compass',
    'fa-edit','fa-envelope','fa-eraser','fa-exchange-alt',
    'fa-exclamation','fa-exclamation-circle','fa-exclamation-triangle',
    'fa-expand','fa-expand-alt','fa-external-link-alt',
    'fa-eye','fa-eye-slash',
    'fa-file','fa-file-alt','fa-file-contract','fa-file-excel','fa-file-exclamation',
    'fa-file-import','fa-file-invoice','fa-file-invoice-dollar','fa-file-pdf',
    'fa-file-plus','fa-file-slash','fa-filter','fa-flag-checkered','fa-flask',
    'fa-folder','fa-folder-open','fa-folder-plus','fa-forward',
    'fa-globe','fa-handshake','fa-hard-hat','fa-history',
    'fa-id-card','fa-image','fa-inbox','fa-industry','fa-info-circle',
    'fa-keyboard','fa-layer-group','fa-list','fa-list-ol','fa-lock',
    'fa-magic','fa-microscope','fa-minus','fa-moon',
    'fa-paperclip','fa-paper-plane','fa-pencil-alt','fa-phone','fa-play-circle',
    'fa-plus','fa-plus-circle','fa-print','fa-project-diagram',
    'fa-question','fa-question-circle','fa-redo','fa-robot',
    'fa-save','fa-search','fa-shopping-cart',
    'fa-sign-in-alt','fa-sign-out-alt','fa-spinner','fa-stamp','fa-star',
    'fa-star-half-alt','fa-sticky-note','fa-stopwatch','fa-stream','fa-sun',
    'fa-sync-alt','fa-table','fa-tag','fa-tags','fa-tasks',
    'fa-th','fa-th-large','fa-times','fa-times-circle','fa-trash',
    'fa-truck','fa-truck-loading','fa-unlock-alt','fa-upload',
    'fa-user','fa-user-check','fa-user-cog','fa-user-plus','fa-users',
    'fa-user-shield','fa-user-tie','fa-vials','fa-warehouse',
];
foreach ($iconos as $ic) {
    $clase = "fas $ic";
    echo "<div class=\"icon-card\" data-name=\"$ic\">
        <i class=\"$clase\"></i>
        <span class=\"icon-name\">$ic</span>
        <button class=\"copy-btn\" onclick=\"copiar('$ic')\">copiar clase</button>
    </div>\n";
}
?>
</div>

<script>
function filtrar() {
    const q = document.getElementById('buscar').value.toLowerCase();
    const cards = document.querySelectorAll('.icon-card');
    let visible = 0;
    cards.forEach(c => {
        const match = c.dataset.name.includes(q);
        c.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('conteo').textContent = visible + ' iconos' + (q ? ` para "${q}"` : ' en total');
    const noRes = document.getElementById('no-results');
    if (noRes) noRes.remove();
    if (visible === 0) {
        const d = document.createElement('div');
        d.id = 'no-results';
        d.className = 'no-results';
        d.textContent = 'No se encontraron iconos con ese nombre.';
        document.getElementById('grid').appendChild(d);
    }
}
function copiar(clase) {
    navigator.clipboard.writeText('fas ' + clase).then(() => {
        const btn = event.target;
        btn.textContent = '¡copiado!';
        setTimeout(() => btn.textContent = 'copiar clase', 1500);
    });
}
window.onload = () => filtrar();
</script>
</body>
</html>
