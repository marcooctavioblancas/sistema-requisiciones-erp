<?php
/**
 * importar_inventario.php
 * Importa el catálogo real de materiales desde db_init/inventario_import.json
 * (generado a partir del Excel de inventario, hoja INVENTARIO — ver
 * extraer_inventario.py).
 *
 * Herramienta admin de un solo uso, mismo patrón que aplicar_migraciones.php:
 * no enlazada desde ningún menú, requiere admin, y requiere ?confirmar=1
 * explícito para escribir (sin eso solo muestra un preview, no toca la BD).
 *
 * No borra ni modifica los materiales que ya existen -- solo agrega los que
 * no chocan por `codigo`. Respaldo previo: db_init/backups/pre_import_inventario_2026-08-03.sql
 */
require_once 'includes/config.php';

if (!isAdmin() && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    header('Location: index.php'); exit();
}

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$jsonPath = __DIR__ . '/db_init/inventario_import.json';
if (!file_exists($jsonPath)) {
    die('No se encontró db_init/inventario_import.json. Corre primero extraer_inventario.py sobre el Excel.');
}
$filas = json_decode(file_get_contents($jsonPath), true);
if (!is_array($filas)) {
    die('El JSON de importación no se pudo leer.');
}

$confirmar = isset($_GET['confirmar']) && $_GET['confirmar'] === '1';

// ── Categorías: cuáles ya existen, cuáles hay que crear ─────────────────────
$categoriasExistentes = [];
foreach ($db->query('SELECT id, nombre FROM categorias')->fetchAll() as $c) {
    $categoriasExistentes[$c['nombre']] = (int) $c['id'];
}
$categoriasUsadas = array_values(array_unique(array_filter(array_column($filas, 'categoria'))));
$categoriasNuevas = array_values(array_diff($categoriasUsadas, array_keys($categoriasExistentes)));

// ── Materiales: cuáles códigos ya existen en BD (no se tocan, no se duplican) ─
$codigosExistentes = [];
foreach ($db->query("SELECT codigo FROM materiales WHERE codigo IS NOT NULL AND codigo <> ''")->fetchAll(PDO::FETCH_COLUMN) as $cod) {
    $codigosExistentes[$cod] = true;
}

$aInsertar = [];
$omitidosPorCodigoExistente = 0;
foreach ($filas as $f) {
    if (isset($codigosExistentes[$f['codigo']])) {
        $omitidosPorCodigoExistente++;
        continue;
    }
    $aInsertar[] = $f;
}

$insertados = 0;
$categoriasCreadas = 0;
$errorFatal = null;

if ($confirmar) {
    try {
        $db->beginTransaction();

        // Crea las categorías que falten y completa el mapa nombre -> id
        $stmtCat = $db->prepare('INSERT INTO categorias (nombre, activo) VALUES (?, 1)');
        foreach ($categoriasNuevas as $nombreCat) {
            $stmtCat->execute([$nombreCat]);
            $categoriasExistentes[$nombreCat] = (int) $db->lastInsertId();
            $categoriasCreadas++;
        }

        $stmtMat = $db->prepare(
            'INSERT INTO materiales (codigo, descripcion, categoria_id, marca, modelo, unidad_medida, stock_disponible, ubicacion, activo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        foreach ($aInsertar as $f) {
            $categoriaId = $f['categoria'] ? ($categoriasExistentes[$f['categoria']] ?? null) : null;
            $stmtMat->execute([
                $f['codigo'],
                $f['descripcion'],
                $categoriaId,
                $f['marca'] ?: null,
                $f['modelo'] ?: null,
                $f['unidad_medida'] ?: 'PZA',
                $f['stock_disponible'],
                $f['ubicacion'] ?: null,
            ]);
            $insertados++;
        }

        $db->commit();
        if ($categoriasCreadas > 0) cache_forget('categorias_activas');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $errorFatal = $e->getMessage();
        $insertados = 0;
        $categoriasCreadas = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Importar Inventario — EMPRESA EJEMPLO</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Montserrat',sans-serif;background:#f5f7fa;padding:30px 16px;}
.wrap{max-width:760px;margin:0 auto;}
.hdr{background:linear-gradient(135deg,#003d5c,#005686);color:#fff;border-radius:12px 12px 0 0;padding:22px 28px;}
.hdr h1{font-size:1.3rem;font-weight:700;}
.hdr p{font-size:.85rem;opacity:.8;margin-top:4px;}
.summary{display:flex;gap:14px;padding:18px 28px;background:#fff;border-bottom:2px solid #e0e8f0;flex-wrap:wrap;}
.stat{display:flex;align-items:center;gap:8px;font-size:.9rem;font-weight:600;}
.stat.ok{color:#2E7D32;} .stat.skip{color:#555;} .stat.err{color:#b71c1c;} .stat.new{color:#005686;}
.log-wrap{background:#fff;border-radius:0 0 12px 12px;padding:20px 28px;box-shadow:0 2px 8px rgba(0,0,0,.07);}
.cat-list{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 18px;}
.cat-chip{background:#e3f2fd;color:#0d47a1;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:600;}
.err-box{margin-top:16px;background:#fce4ec;border:1.5px solid #e57373;border-radius:8px;padding:14px 18px;}
.err-box p{font-size:.82rem;color:#b71c1c;}
.done-banner{background:linear-gradient(135deg,#e8f5e9,#c8e6c9);border:1.5px solid #2E7D32;border-radius:8px;padding:14px 18px;display:flex;align-items:center;gap:10px;color:#1B5E20;font-weight:600;margin-bottom:16px;}
.confirm-banner{background:linear-gradient(135deg,#fff3cd,#ffe8a1);border:1.5px solid #BE893B;border-radius:8px;padding:16px 20px;margin-bottom:16px;}
.confirm-banner p{font-size:.85rem;color:#6d4c00;margin-bottom:10px;}
.btn-confirm{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#BE893B;color:#fff;border-radius:8px;font-size:.85rem;font-weight:700;text-decoration:none;}
.btn-confirm:hover{background:#a3742f;}
.back{display:inline-flex;align-items:center;gap:6px;margin-top:20px;padding:9px 18px;background:#005686;color:#fff;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;}
.back:hover{background:#003d5c;}
</style>
</head>
<body>
<div class="wrap">
    <div class="hdr">
        <h1><i class="fas fa-boxes-stacked"></i> Importar Inventario (Excel)</h1>
        <p>Catálogo real desde INVENTARIOS EMPRESA EJEMPLO — hoja INVENTARIO</p>
    </div>

    <div class="log-wrap">
        <?php if ($errorFatal): ?>
        <div class="err-box"><p><strong>Error, se revirtió todo:</strong> <?= htmlspecialchars($errorFatal) ?></p></div>

        <?php elseif ($confirmar): ?>
        <div class="done-banner">
            <i class="fas fa-check-circle" style="font-size:1.4rem;"></i>
            Importación completada.
        </div>
        <div class="summary" style="padding:0 0 18px;border:none;">
            <div class="stat new"><i class="fas fa-tags"></i> <?= $categoriasCreadas ?> categorías nuevas</div>
            <div class="stat ok"><i class="fas fa-check-circle"></i> <?= $insertados ?> materiales insertados</div>
            <div class="stat skip"><i class="fas fa-forward"></i> <?= $omitidosPorCodigoExistente ?> omitidos (código ya existía)</div>
        </div>

        <?php else: ?>
        <p style="font-size:.85rem;color:#555;margin-bottom:14px;">Vista previa — nada se ha escrito todavía.</p>
        <div class="summary" style="padding:0 0 18px;border:none;">
            <div class="stat"><i class="fas fa-file-lines"></i> <?= count($filas) ?> filas en el Excel</div>
            <div class="stat new"><i class="fas fa-tags"></i> <?= count($categoriasNuevas) ?> categorías nuevas</div>
            <div class="stat ok"><i class="fas fa-plus-circle"></i> <?= count($aInsertar) ?> materiales a insertar</div>
            <div class="stat skip"><i class="fas fa-forward"></i> <?= $omitidosPorCodigoExistente ?> se omiten (código ya existe en BD)</div>
        </div>

        <?php if ($categoriasNuevas): ?>
        <p style="font-size:.82rem;font-weight:700;color:#333;">Categorías que se crearán:</p>
        <div class="cat-list">
            <?php foreach ($categoriasNuevas as $c): ?>
            <span class="cat-chip"><?= htmlspecialchars($c) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="confirm-banner">
            <p><i class="fas fa-triangle-exclamation"></i> Los 132 materiales que ya existen en la BD NO se tocan. Respaldo ya guardado en <code>db_init/backups/pre_import_inventario_2026-08-03.sql</code>.</p>
            <a class="btn-confirm" href="?confirmar=1"><i class="fas fa-check"></i> Confirmar e importar los <?= count($aInsertar) ?> materiales</a>
        </div>
        <?php endif; ?>

        <a href="almacen2.php" class="back"><i class="fas fa-arrow-left"></i> Ir a Almacén</a>
    </div>
</div>
</body>
</html>
