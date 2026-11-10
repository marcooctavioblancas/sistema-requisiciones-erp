<?php
require_once '../includes/config.php';

if (!isLoggedIn() || (!hasAcceso('ingenieria') && !hasAcceso('admin'))) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$item_id = intval($data['item_id'] ?? 0);

if (!$item_id) {
    echo json_encode(['error' => 'item_id inválido']);
    exit();
}

$db = getDB();

if (array_key_exists('certificados', $data) || array_key_exists('nota_libre', $data)) {
    $certificados = trim($data['certificados'] ?? '');
    $nota_libre   = trim($data['nota_libre']   ?? '');
    $db->prepare("UPDATE boom_items SET certificados = ?, nota_libre = ? WHERE id = ?")
       ->execute([$certificados ?: null, $nota_libre ?: null, $item_id]);
}

if (array_key_exists('categoria_doc', $data)) {
    // Clasificación para documentos (BOM impreso/Excel) -- independiente del catálogo real,
    // whitelist estricta porque es un ENUM.
    $categorias_doc_validas = ['componente_principal', 'conector', 'material_electrico', 'tornilleria'];
    $categoria_doc = trim($data['categoria_doc'] ?? '');
    if ($categoria_doc === '' || in_array($categoria_doc, $categorias_doc_validas, true)) {
        $db->prepare("UPDATE boom_items SET categoria_doc = ? WHERE id = ?")
           ->execute([$categoria_doc ?: null, $item_id]);
    }
}

if (isset($data['cantidad'])) {
    $cantidad = intval($data['cantidad']);
    if ($cantidad > 0) {
        $stmt = $db->prepare("SELECT cantidad, boom_id, proyecto_id, material_id FROM boom_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        if ($item) {
            $db->prepare("UPDATE boom_items SET cantidad = ? WHERE id = ?")->execute([$cantidad, $item_id]);

            if ($item['material_id']) {
                $stmt_bl = $db->prepare("SELECT num_equipos FROM boom_listas WHERE id = ?");
                $stmt_bl->execute([$item['boom_id']]);
                $bl = $stmt_bl->fetch();
                if ($bl) {
                    $delta = ($cantidad - $item['cantidad']) * $bl['num_equipos'];
                    $stmt_alm = $db->prepare("SELECT id, cantidad_requerida, cantidad_ingenieria FROM almacen_items WHERE proyecto_id = ? AND material_id = ?");
                    $stmt_alm->execute([$item['proyecto_id'], $item['material_id']]);
                    if ($alm = $stmt_alm->fetch()) {
                        $nueva = max(0, $alm['cantidad_requerida'] + $delta);
                        // Este item es del BOM de Ingeniería -- el ajuste de cantidad va a su
                        // columna del desglose por departamento, no rompe lo que ya trae Producción.
                        $nueva_ing = max(0, $alm['cantidad_ingenieria'] + $delta);
                        $db->prepare("UPDATE almacen_items SET cantidad_requerida = ?, cantidad_ingenieria = ?, diferencia = stock_actual - ? WHERE id = ?")
                           ->execute([$nueva, $nueva_ing, $nueva, $alm['id']]);
                    }
                }
            }
        }
    }
}

echo json_encode(['ok' => true]);
