<?php
require_once __DIR__ . '/includes/config.php';
if (!isLoggedIn()) { redirect('index.php'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Todos los Iconos - Font Awesome 5</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 24px; }
        h1 { text-align: center; color: #1e3a5f; margin-bottom: 6px; font-size: 22px; }
        .sub { text-align: center; color: #666; font-size: 13px; margin-bottom: 20px; }
        .top-bar {
            display: flex; gap: 12px; justify-content: center;
            flex-wrap: wrap; margin-bottom: 18px; align-items: center;
        }
        .top-bar input {
            padding: 9px 16px; border: 1px solid #ccc; border-radius: 6px;
            font-size: 14px; width: 300px; outline: none;
        }
        .top-bar input:focus { border-color: #2196F3; }
        .top-bar select {
            padding: 9px 12px; border: 1px solid #ccc; border-radius: 6px;
            font-size: 14px; background: #fff; outline: none; cursor: pointer;
        }
        .count { text-align: center; color: #888; font-size: 13px; margin-bottom: 16px; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .icon-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 16px 8px 12px;
            display: flex; flex-direction: column;
            align-items: center; gap: 8px;
            cursor: default;
            transition: box-shadow 0.15s, border-color 0.15s;
            position: relative;
        }
        .icon-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.12); border-color: #2196F3; }
        .icon-card i { font-size: 28px; color: #1e3a5f; }
        .icon-name { font-size: 10px; color: #555; text-align: center; word-break: break-all; line-height: 1.3; }
        .prefix-badge {
            font-size: 9px; padding: 2px 5px; border-radius: 3px;
            font-weight: bold; text-transform: uppercase;
        }
        .badge-fas { background: #e3f2fd; color: #1565c0; }
        .badge-far { background: #e8f5e9; color: #2e7d32; }
        .badge-fab { background: #fff3e0; color: #e65100; }
        .copy-btn {
            font-size: 10px; padding: 3px 8px;
            background: #e3f2fd; border: 1px solid #90caf9;
            border-radius: 4px; cursor: pointer; color: #1565c0;
            opacity: 0; transition: opacity 0.15s;
        }
        .icon-card:hover .copy-btn { opacity: 1; }
        .copy-btn:hover { background: #bbdefb; }
        .no-results { text-align: center; color: #999; font-size: 15px; margin-top: 40px; grid-column: 1/-1; }
        .error-msg { text-align: center; color: #c62828; background: #ffebee; padding: 16px; border-radius: 8px; margin: 20px auto; max-width: 600px; }
        .loading { text-align: center; color: #666; font-size: 15px; padding: 40px; }
    </style>
</head>
<body>
<h1>Todos los Iconos — Font Awesome 5 Free</h1>
<?php
$json_url = 'https://raw.githubusercontent.com/FortAwesome/Font-Awesome/5.15.4/metadata/icons.json';
$ctx = stream_context_create(['http' => ['timeout' => 10]]);
$json_raw = @file_get_contents($json_url, false, $ctx);

if (!$json_raw) {
    echo '<p class="error-msg"><strong>No se pudo cargar la metadata de FA5 desde internet.</strong><br>
    Asegúrate de que XAMPP tenga acceso a internet o descarga el archivo <code>icons.json</code> de
    <a href="https://github.com/FortAwesome/Font-Awesome/blob/5.x/metadata/icons.json" target="_blank">GitHub FA5</a>
    y colócalo junto a este archivo como <code>fa_icons.json</code>.</p>';
    // Intentar desde archivo local si existe
    if (file_exists(__DIR__ . '/fa_icons.json')) {
        $json_raw = file_get_contents(__DIR__ . '/fa_icons.json');
        echo '<p style="text-align:center;color:#2e7d32;margin-bottom:16px;">✓ Cargado desde archivo local.</p>';
    } else {
        echo '</body></html>';
        exit;
    }
}

$icons_data = json_decode($json_raw, true);
if (!$icons_data) {
    echo '<p class="error-msg">Error al parsear el JSON de iconos.</p>';
    echo '</body></html>';
    exit;
}

// Construir lista flat con prefijo correcto
$iconos = [];
foreach ($icons_data as $name => $data) {
    $styles = $data['styles'] ?? [];
    foreach ($styles as $style) {
        $prefix = match($style) {
            'solid'   => 'fas',
            'regular' => 'far',
            'brands'  => 'fab',
            default   => 'fas',
        };
        $iconos[] = ['name' => $name, 'prefix' => $prefix, 'label' => $data['label'] ?? $name];
    }
}

// Ordenar por nombre
usort($iconos, fn($a, $b) => strcmp($a['name'], $b['name']));

$total = count($iconos);
$categorias = ['all' => 'Todos', 'fas' => 'Solid', 'far' => 'Regular', 'fab' => 'Brands'];
?>
<p class="sub">Font Awesome 5.15.4 Free — <?php echo $total; ?> iconos disponibles</p>

<div class="top-bar">
    <input type="text" id="buscar" placeholder="Buscar icono... ej: arrow, file, user" oninput="filtrar()">
    <select id="filtroTipo" onchange="filtrar()">
        <?php foreach ($categorias as $val => $lbl): ?>
        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="count" id="conteo"><?php echo $total; ?> iconos en total</div>

<div class="grid" id="grid">
<?php foreach ($iconos as $ic): ?>
    <div class="icon-card" data-name="<?php echo $ic['name']; ?>" data-prefix="<?php echo $ic['prefix']; ?>">
        <i class="<?php echo $ic['prefix']; ?> fa-<?php echo $ic['name']; ?>"></i>
        <span class="prefix-badge badge-<?php echo $ic['prefix']; ?>"><?php echo $ic['prefix']; ?></span>
        <span class="icon-name"><?php echo $ic['name']; ?></span>
        <button class="copy-btn" onclick="copiar('<?php echo $ic['prefix']; ?> fa-<?php echo $ic['name']; ?>', this)">copiar</button>
    </div>
<?php endforeach; ?>
</div>

<script>
function filtrar() {
    const q     = document.getElementById('buscar').value.toLowerCase().trim();
    const tipo  = document.getElementById('filtroTipo').value;
    const cards = document.querySelectorAll('.icon-card');
    let visible = 0;

    cards.forEach(c => {
        const nameMatch   = q === '' || c.dataset.name.includes(q);
        const tipoMatch   = tipo === 'all' || c.dataset.prefix === tipo;
        const show = nameMatch && tipoMatch;
        c.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    let txt = visible + ' icono' + (visible !== 1 ? 's' : '');
    if (q)    txt += ` para "${q}"`;
    if (tipo !== 'all') txt += ` (${tipo})`;
    document.getElementById('conteo').textContent = txt;

    const old = document.getElementById('no-results');
    if (old) old.remove();
    if (visible === 0) {
        const d = document.createElement('div');
        d.id = 'no-results';
        d.className = 'no-results';
        d.textContent = 'Sin resultados. Intenta con otro término.';
        document.getElementById('grid').appendChild(d);
    }
}

function copiar(clase, btn) {
    navigator.clipboard.writeText(clase).then(() => {
        const orig = btn.textContent;
        btn.textContent = '¡copiado!';
        setTimeout(() => btn.textContent = orig, 1500);
    });
}
</script>
</body>
</html>
