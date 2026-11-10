<?php
/**
 * api/importar_bom.php
 * Importa items de BOM desde un archivo CSV subido via AJAX o formulario.
 * Columnas CSV esperadas: codigo,descripcion,cantidad,marca,modelo,observacion
 * Primera fila es encabezado y se omite.
 */
require_once '../includes/config.php';

if (!isLoggedIn() || (!hasAcceso('ingenieria') && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Acceso denegado']);
    exit();
}

$db = getDB();

$boom_id    = intval($_POST['boom_id']    ?? 0);
$proyecto_id = intval($_POST['proyecto_id'] ?? 0);

if (!$boom_id || !$proyecto_id) {
    echo json_encode(['ok'=>false,'error'=>'boom_id y proyecto_id son requeridos']);
    exit();
}

// Verify BOM is not finalized
$chk = $db->prepare("SELECT finalizado,tipo_lista FROM boom_listas WHERE id=?");
$chk->execute([$boom_id]);
$brow = $chk->fetch();
if (!$brow) { echo json_encode(['ok'=>false,'error'=>'BOM no encontrado']); exit(); }
if ($brow['finalizado'] == 1 && $brow['tipo_lista'] === 'principal') {
    echo json_encode(['ok'=>false,'error'=>'El BOM principal está finalizado']); exit();
}

if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== 0) {
    echo json_encode(['ok'=>false,'error'=>'No se recibió un archivo válido']); exit();
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
if (!$handle) { echo json_encode(['ok'=>false,'error'=>'No se pudo leer el archivo']); exit(); }

// Skip header row
fgetcsv($handle);

$imported = 0;
$skipped  = 0;
$log_msgs = [];

$db->beginTransaction();
try {
    while (($row = fgetcsv($handle, 2000, ',')) !== false) {
        if (count($row) < 2) { $skipped++; continue; }

        $csv_codigo  = trim($row[0] ?? '');
        $csv_desc    = trim($row[1] ?? '');
        $csv_qty     = intval(trim($row[2] ?? '0'));
        $csv_marca   = trim($row[3] ?? '');
        $csv_modelo  = trim($row[4] ?? '');
        $csv_obs     = trim($row[5] ?? '');

        if (empty($csv_desc) || $csv_qty <= 0) { $skipped++; continue; }

        // Find material in catalog
        $mat_id = null;
        if ($csv_codigo) {
            $ml = $db->prepare("SELECT id FROM materiales WHERE codigo = ? LIMIT 1");
            $ml->execute([$csv_codigo]);
            $mat_id = $ml->fetchColumn() ?: null;
        }
        if (!$mat_id && $csv_desc) {
            $ml2 = $db->prepare("SELECT id FROM materiales WHERE descripcion = ? LIMIT 1");
            $ml2->execute([$csv_desc]);
            $mat_id = $ml2->fetchColumn() ?: null;
        }

        // Check for existing item in BOM
        $ex = $db->prepare("SELECT id,cantidad FROM boom_items WHERE boom_id=? AND (
            (material_id IS NOT NULL AND material_id=?) OR
            (material_id IS NULL AND LOWER(descripcion)=LOWER(?))
        )");
        $ex->execute([$boom_id, $mat_id, $csv_desc]);
        $ex_row = $ex->fetch();

        if ($ex_row) {
            $db->prepare("UPDATE boom_items SET cantidad=? WHERE id=?")->execute([$csv_qty, $ex_row['id']]);
            try {
                $db->prepare("INSERT INTO boom_items_log (boom_item_id,boom_id,proyecto_id,usuario_id,accion,campo,valor_anterior,valor_nuevo) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$ex_row['id'],$boom_id,$proyecto_id,$_SESSION['user_id'],'editar','cantidad (CSV)',$ex_row['cantidad'],$csv_qty]);
            } catch (Exception $le) {}
            $log_msgs[] = "Actualizado: $csv_desc (cant. → $csv_qty)";
        } else {
            $db->prepare("INSERT INTO boom_items (boom_id,proyecto_id,item,descripcion,marca,modelo,cantidad,material_id,observacion,fecha_agregado,agregado_por,guardado)
                          VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,0)")
               ->execute([$boom_id,$proyecto_id,$csv_desc,$csv_desc,$csv_marca,$csv_modelo,$csv_qty,$mat_id,$csv_obs,$_SESSION['user_id']]);
            $new_id = $db->lastInsertId();
            try {
                $db->prepare("INSERT INTO boom_items_log (boom_item_id,boom_id,proyecto_id,usuario_id,accion,campo,valor_anterior,valor_nuevo) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$new_id,$boom_id,$proyecto_id,$_SESSION['user_id'],'agregar','importado CSV',null,$csv_desc]);
            } catch (Exception $le) {}
            $log_msgs[] = "Agregado: $csv_desc ($csv_qty u.)";
        }
        $imported++;
    }
    $db->commit();
    fclose($handle);
    echo json_encode(['ok'=>true,'imported'=>$imported,'skipped'=>$skipped,'log'=>$log_msgs]);
} catch (Exception $e) {
    $db->rollBack();
    fclose($handle);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
