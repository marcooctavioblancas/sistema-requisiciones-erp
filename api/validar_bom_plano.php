<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/../includes/config.php';
ob_end_clean();

header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }

$boom_id  = intval($_POST['boom_id'] ?? 0);
$bom_json = $_POST['bom_data'] ?? '';
if (!$boom_id || !$bom_json) { echo json_encode(['success'=>false,'error'=>'Datos incompletos']); exit; }

$boms = json_decode($bom_json, true);
if (!is_array($boms)) { echo json_encode(['success'=>false,'error'=>'JSON inválido']); exit; }

$db = getDB();

// Obtener proyecto_id y pieza del boom padre
$stmt = $db->prepare("SELECT proyecto_id, pieza_nombre FROM boom_listas WHERE id = ?");
$stmt->execute([$boom_id]);
$boom_orig = $stmt->fetch();
$proy_id   = $boom_orig['proyecto_id'] ?? null;
$pieza     = $boom_orig['pieza_nombre'] ?? 'Pieza';

// Revertir en almacén lo que la validación anterior ya había empujado -- se borra y
// se vuelve a crear el BOM completo abajo, así que sin esto cada re-validación (corregir
// un material, reprocesar con IA de nuevo) duplicaría la cantidad requerida en vez de
// reemplazarla.
if ($proy_id) {
    $stmt_prev = $db->prepare("SELECT bi.material_id, bi.cantidad FROM boom_items bi JOIN boom_listas bl ON bi.boom_id = bl.id WHERE bl.padre_boom_id = ? AND bi.guardado = 1");
    $stmt_prev->execute([$boom_id]);
    foreach ($stmt_prev->fetchAll() as $prev) {
        revertirAlmacenItem($db, (int)$proy_id, $prev['material_id'] ? (int)$prev['material_id'] : null, (float)$prev['cantidad'], 'produccion');
    }
}

// Limpiar BOMs IA anteriores
$db->prepare("DELETE bi FROM boom_items bi JOIN boom_listas bl ON bi.boom_id = bl.id WHERE bl.padre_boom_id = ?")->execute([$boom_id]);
$db->prepare("DELETE FROM boom_listas WHERE padre_boom_id = ?")->execute([$boom_id]);

// Matcher contra catálogo
$stmtMatch = $db->prepare(
    "SELECT id, descripcion, marca, codigo FROM materiales
     WHERE activo=1 AND LOWER(TRIM(marca))=LOWER(TRIM(?)) AND LOWER(TRIM(codigo))=LOWER(TRIM(?)) LIMIT 1"
);
$stmtMatchDesc = $db->prepare(
    "SELECT id, descripcion, marca, codigo FROM materiales
     WHERE activo=1 AND LOWER(TRIM(marca))=LOWER(TRIM(?)) AND LOWER(TRIM(descripcion)) LIKE LOWER(CONCAT('%',TRIM(?),'%')) LIMIT 1"
);

$boms_guardados = 0;
foreach ($boms as $bom) {
    $hoja  = trim(strip_tags($bom['hoja'] ?? '1')) ?: '1';
    $mats  = $bom['materiales'] ?? [];
    if (empty($mats)) continue;

    // guardado=1 de una vez -- "Validar BOM" es la acción terminal de este flujo (no hay
    // ningún paso posterior que la confirme), así que debe empujarse a almacén aquí mismo.
    $db->prepare("INSERT INTO boom_listas (proyecto_id,nombre,num_equipos,creado_por,fecha_creacion,guardado,tipo_lista,pieza_nombre,hoja_plano,padre_boom_id)
                  VALUES (?,?,1,?,NOW(),1,'plano_ia',?,?,?)")
       ->execute([$proy_id, "$pieza — BOM Hoja $hoja", $_SESSION['user_id'], "$pieza — Hoja $hoja", $hoja, $boom_id]);
    $new_boom_id = (int)$db->lastInsertId();

    foreach ($mats as $mat) {
        $clean  = function($v){ return trim(strip_tags((string)$v)); };
        $desc   = $clean($mat['descripcion'] ?? '');
        $cant   = floatval($mat['cantidad'] ?? 1);
        $unidad = $clean($mat['unidad'] ?? 'PZA') ?: 'PZA';
        $marca  = $clean($mat['marca'] ?? '');
        $modelo = $clean($mat['modelo'] ?? '');
        if (!$desc) continue;

        // Si el frontend ya resolvió el match (via match_bom_materiales.php), usarlo directamente
        $mat_id_pre = intval($mat['material_id'] ?? 0) ?: null;

        if ($mat_id_pre) {
            $material_id = $mat_id_pre;
        } else {
            // Fallback: intentar match en backend (mismo algoritmo)
            $mat_cat = null;
            if ($marca !== '' && $modelo !== '') {
                $stmtMatch->execute([$marca, $modelo]);
                $mat_cat = $stmtMatch->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$mat_cat && $marca !== '' && $desc !== '') {
                $desc_corta = implode(' ', array_slice(explode(' ', $desc), 0, 3));
                $stmtMatchDesc->execute([$marca, $desc_corta]);
                $mat_cat = $stmtMatchDesc->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($mat_cat) {
                $material_id = (int)$mat_cat['id'];
                $desc   = $mat_cat['descripcion'];
                $marca  = $mat_cat['marca'];
                $modelo = $mat_cat['codigo'];
            } else {
                $material_id = null;
            }
        }

        $db->prepare("INSERT INTO boom_items (boom_id,proyecto_id,descripcion,cantidad,unidad_medida,marca,modelo,material_id,guardado)
                      VALUES (?,?,?,?,?,?,?,?,1)")
           ->execute([$new_boom_id, $proy_id, $desc, $cant, $unidad, $marca, $modelo, $material_id]);

        if ($proy_id) {
            sincronizarAlmacenItem($db, (int)$proy_id, [
                'material_id' => $material_id,
                'item'        => $desc,
                'descripcion' => $desc,
                'modelo'      => $modelo,
                'marca'       => $marca,
                'cantidad'    => $cant,
            ], (int)$_SESSION['user_id'], 'produccion');
            $huboItemsAlmacen = true;
        }
    }
    $boms_guardados++;
}

if ($proy_id && !empty($huboItemsAlmacen)) {
    $db->prepare("UPDATE proyectos SET almacen_activo = 1 WHERE id = ?")->execute([$proy_id]);
}

try { $db->prepare("UPDATE boom_listas SET ia_procesado=1, ia_fecha=NOW() WHERE id=?")->execute([$boom_id]); } catch(Exception $e){}

echo json_encode(['success'=>true, 'boms_guardados'=>$boms_guardados]);
