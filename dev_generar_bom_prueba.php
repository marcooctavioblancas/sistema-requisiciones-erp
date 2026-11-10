<?php
/**
 * dev_generar_bom_prueba.php
 * Herramienta de desarrollo: genera (o borra) un proyecto + BOM de prueba con
 * materiales reales del catálogo, sin tener que abrir Ingeniería y asignar
 * materiales a mano cada vez que se necesita un BOM para probar algo.
 * No enlazada desde ningún menú a propósito -- se abre tecleando la URL.
 *
 * Uso:
 *   dev_generar_bom_prueba.php                     -> genera un BOM con 60 materiales (los de descripción más larga, buen caso extremo)
 *   ?materiales=20                                  -> cantidad de materiales (default 60)
 *   ?orden=random                                    -> materiales al azar en vez de los de descripción más larga (default 'largo')
 *   ?cantidad=5                                       -> cantidad por material en el BOM (default 3)
 *   ?nombre=qa_test_bom_mi_prueba                     -> nombre del proyecto (default autogenerado con fecha/hora)
 *   ?borrar_todos=1                                   -> borra TODOS los proyectos qa_test_bom_* generados por esta herramienta
 */
require_once 'includes/config.php';

if (!isAdmin() && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    header('Location: index.php');
    exit();
}

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$resultado = null;
$borrados  = null;

if (isset($_GET['borrar_todos'])) {
    $ids = $db->query("SELECT id FROM proyectos WHERE nombre LIKE 'qa\\_test\\_bom\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $pid) {
        $db->prepare("DELETE FROM bom_snapshots WHERE proyecto_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM almacen_items WHERE proyecto_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM boom_items WHERE proyecto_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM boom_listas WHERE proyecto_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM requisiciones WHERE proyecto_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM proyectos WHERE id = ?")->execute([$pid]);
    }
    $borrados = count($ids);
} elseif (isset($_GET['generar']) || $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['borrar_todos'])) {
    $num_materiales   = max(1, min(3700, intval($_GET['materiales'] ?? 60)));
    $cantidad_x_item  = max(1, intval($_GET['cantidad'] ?? 3));
    // Whitelist explícito de ORDER BY -- nunca se interpola texto del request
    // directo en el SQL, solo se usa como índice hacia uno de estos 2 literales.
    $ordenes_permitidos = [
        'largo'  => 'LENGTH(descripcion) DESC',
        'random' => 'RAND()',
    ];
    $orden_sql = $ordenes_permitidos[$_GET['orden'] ?? 'largo'] ?? $ordenes_permitidos['largo'];
    $nombre    = trim($_GET['nombre'] ?? '') ?: ('qa_test_bom_' . date('Ymd_His'));
    if (strpos($nombre, 'qa_test_') !== 0) $nombre = 'qa_test_' . $nombre;

    $db->prepare("INSERT INTO proyectos (nombre, descripcion, creado_por, fecha_creacion, estado, tipo) VALUES (?, ?, ?, NOW(), 'finalizado', 'ingenieria')")
       ->execute([$nombre, "Proyecto de prueba generado por dev_generar_bom_prueba.php ($num_materiales materiales)", $_SESSION['user_id']]);
    $proyecto_id = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO boom_listas (nombre, proyecto_id, fecha_creacion, num_equipos, guardado, creado_por, tipo_lista, revision_numero) VALUES (?, ?, NOW(), 1, 1, ?, 'principal', 1)")
       ->execute(['BOM de prueba', $proyecto_id, $_SESSION['user_id']]);
    $boom_id = (int) $db->lastInsertId();

    $stmt_mats = $db->prepare("SELECT id, descripcion, codigo, marca FROM materiales WHERE activo = 1 ORDER BY $orden_sql LIMIT :lim");
    $stmt_mats->bindValue(':lim', $num_materiales, PDO::PARAM_INT);
    $stmt_mats->execute();
    $materiales = $stmt_mats->fetchAll();
    $stmt = $db->prepare("INSERT INTO boom_items (proyecto_id, boom_id, item, descripcion, modelo, marca, cantidad, material_id, fecha_agregado, agregado_por, guardado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)");
    foreach ($materiales as $m) {
        $stmt->execute([$proyecto_id, $boom_id, 'ITEM' . $m['id'], $m['descripcion'], $m['codigo'], $m['marca'], $cantidad_x_item, $m['id'], $_SESSION['user_id']]);
    }

    $resultado = [
        'proyecto_id' => $proyecto_id,
        'boom_id'     => $boom_id,
        'nombre'      => $nombre,
        'materiales'  => count($materiales),
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Generar BOM de prueba — EMPRESA EJEMPLO</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Montserrat',sans-serif;background:#f5f7fa;padding:30px 16px;}
.wrap{max-width:640px;margin:0 auto;}
.hdr{background:linear-gradient(135deg,#003d5c,#005686);color:#fff;border-radius:12px 12px 0 0;padding:22px 28px;}
.hdr h1{font-size:1.3rem;font-weight:700;}
.hdr p{font-size:.85rem;opacity:.8;margin-top:4px;}
.body{background:#fff;border-radius:0 0 12px 12px;padding:24px 28px;box-shadow:0 2px 10px rgba(0,0,0,.06);}
.ok{background:#e8f5e9;border:1px solid #a5d6a7;color:#2e7d32;border-radius:8px;padding:16px 18px;margin-bottom:18px;font-size:.9rem;}
.ok strong{display:block;margin-bottom:6px;font-size:1rem;}
.links a{display:inline-block;margin:4px 8px 4px 0;padding:8px 14px;background:#005686;color:#fff;text-decoration:none;border-radius:6px;font-size:.82rem;font-weight:600;}
.links a.outline{background:#fff;color:#005686;border:1.5px solid #005686;}
form{margin-top:22px;padding-top:18px;border-top:1px solid #eee;}
label{display:block;font-size:.78rem;font-weight:700;color:#555;margin:10px 0 4px;}
input,select{width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.85rem;font-family:inherit;}
button{margin-top:16px;padding:10px 18px;background:#005686;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:.85rem;cursor:pointer;}
.danger{margin-top:14px;font-size:.8rem;}
.danger a{color:#c62828;}
</style>
</head>
<body>
<div class="wrap">
    <div class="hdr">
        <h1> Generar BOM de prueba</h1>
        <p>Crea un proyecto + BOM con materiales reales del catálogo, sin pasar por Ingeniería a mano.</p>
    </div>
    <div class="body">
        <?php if ($borrados !== null): ?>
        <div class="ok"><strong>Listo</strong>Se borraron <?= $borrados ?> proyecto(s) qa_test_bom_*.</div>
        <?php endif; ?>

        <?php if ($resultado): ?>
        <div class="ok">
            <strong>BOM generado: "<?= htmlspecialchars($resultado['nombre']) ?>"</strong>
            proyecto_id=<?= $resultado['proyecto_id'] ?> · boom_id=<?= $resultado['boom_id'] ?> · <?= $resultado['materiales'] ?> materiales
        </div>
        <div class="links">
            <a href="ingenieria.php?seccion=boom&proyecto_boom=<?= $resultado['proyecto_id'] ?>&boom_id=<?= $resultado['boom_id'] ?>">Ver BOM en Ingeniería</a>
            <a class="outline" href="api/vista_pdf_bom.php?boom_id=<?= $resultado['boom_id'] ?>" target="_blank">Imprimir PDF</a>
            <a class="outline" href="api/exportar_bom.php?boom_id=<?= $resultado['boom_id'] ?>" target="_blank">Exportar Excel</a>
        </div>
        <?php endif; ?>

        <form method="GET">
            <input type="hidden" name="generar" value="1">
            <label>Cantidad de materiales</label>
            <input type="number" name="materiales" value="60" min="1" max="3700">
            <label>Orden</label>
            <select name="orden">
                <option value="largo">Los de descripción más larga (caso extremo, recomendado)</option>
                <option value="random">Al azar</option>
            </select>
            <label>Cantidad por material en el BOM</label>
            <input type="number" name="cantidad" value="3" min="1">
            <label>Nombre del proyecto (opcional, se le agrega el prefijo qa_test_ si no lo trae)</label>
            <input type="text" name="nombre" placeholder="qa_test_bom_YYYYMMDD_HHMMSS (autogenerado si se deja vacío)">
            <button type="submit">Generar BOM de prueba</button>
        </form>

        <div class="danger">
            <a href="?borrar_todos=1" onclick="return confirm('¿Borrar TODOS los proyectos qa_test_bom_* generados por esta herramienta?');">🗑 Borrar todos los BOMs de prueba (qa_test_bom_*)</a>
        </div>
    </div>
</div>
</body>
</html>
