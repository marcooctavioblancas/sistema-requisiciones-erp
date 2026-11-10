<?php
/**
 * POST materiales (JSON array de {descripcion, marca, modelo, cantidad, unidad})
 * Devuelve el mismo array enriquecido con:
 *   material_id  → int|null
 *   matched      → bool
 *   match_score  → float (% similitud del intento 3, 0 si no aplica)
 *   (y descripcion/marca/modelo sustituidos con datos del catálogo si hubo match)
 */
require_once '../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }

$raw = $_POST['materiales'] ?? '';
if (!$raw) { echo json_encode(['success'=>true,'data'=>[]]); exit; }

$materiales = json_decode($raw, true);
if (!is_array($materiales)) { echo json_encode(['success'=>false,'error'=>'JSON inválido']); exit; }

$db = getDB();
$clean = function($v) { return trim(strip_tags((string)$v)); };

// Intento 1: marca + código exacto
$stmtByCode = $db->prepare(
    "SELECT id, descripcion, marca, codigo
     FROM materiales
     WHERE activo = 1
       AND LOWER(TRIM(marca))  = LOWER(TRIM(?))
       AND LOWER(TRIM(codigo)) = LOWER(TRIM(?))
     LIMIT 1"
);

// Intento 2: marca + primeras 3 palabras de descripción (LIKE)
$stmtByDesc = $db->prepare(
    "SELECT id, descripcion, marca, codigo
     FROM materiales
     WHERE activo = 1
       AND LOWER(TRIM(marca)) = LOWER(TRIM(?))
       AND LOWER(TRIM(descripcion)) LIKE LOWER(CONCAT('%', TRIM(?), '%'))
     LIMIT 1"
);

// Intento 3 — fuzzy: candidatos por marca O por primera palabra significativa
$stmtFuzzyMarca = $db->prepare(
    "SELECT id, descripcion, marca, codigo
     FROM materiales
     WHERE activo = 1
       AND LOWER(TRIM(marca)) = LOWER(TRIM(?))
     LIMIT 300"
);
$stmtFuzzyDesc = $db->prepare(
    "SELECT id, descripcion, marca, codigo
     FROM materiales
     WHERE activo = 1
       AND LOWER(descripcion) LIKE LOWER(?)
     LIMIT 300"
);

/**
 * Devuelve la primera palabra significativa (≥4 chars, sin puntuación) de $str,
 * o '' si no hay ninguna.
 */
function primera_palabra_clave(string $str): string {
    $palabras = preg_split('/\s+/', preg_replace('/[^\w\s]/u', ' ', $str));
    foreach ($palabras as $p) {
        if (mb_strlen($p) >= 4) return $p;
    }
    return '';
}

const FUZZY_THRESHOLD = 75.0;

$result = [];
foreach ($materiales as $mat) {
    $marca  = $clean($mat['marca']  ?? '');
    $modelo = $clean($mat['modelo'] ?? '');
    $desc   = $clean($mat['descripcion'] ?? '');

    $cat        = null;
    $match_score = 0.0;

    // ── Intento 1: marca + código exacto ─────────────────────────────────────
    if ($marca !== '' && $modelo !== '') {
        $stmtByCode->execute([$marca, $modelo]);
        $cat = $stmtByCode->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Intento 2: marca + primeras 3 palabras (LIKE) ────────────────────────
    if (!$cat && $marca !== '' && $desc !== '') {
        $desc_corta = implode(' ', array_slice(explode(' ', $desc), 0, 3));
        $stmtByDesc->execute([$marca, $desc_corta]);
        $cat = $stmtByDesc->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Intento 3: fuzzy similar_text ────────────────────────────────────────
    if (!$cat && $desc !== '') {
        $candidates = [];

        if ($marca !== '') {
            // Con marca: filtrar candidatos por marca exacta, fuzzy en descripción
            $stmtFuzzyMarca->execute([$marca]);
            $candidates = $stmtFuzzyMarca->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Sin marca (tornillería, genéricos): filtrar por primera palabra clave
            $kw = primera_palabra_clave($desc);
            if ($kw !== '') {
                $stmtFuzzyDesc->execute(['%' . $kw . '%']);
                $candidates = $stmtFuzzyDesc->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $best_pct = 0.0;
        $best_row = null;
        $desc_norm = mb_strtolower($desc);
        foreach ($candidates as $row) {
            similar_text($desc_norm, mb_strtolower($row['descripcion']), $pct);
            if ($pct > $best_pct) {
                $best_pct = $pct;
                $best_row = $row;
            }
        }

        if ($best_row !== null && $best_pct >= FUZZY_THRESHOLD) {
            $cat         = $best_row;
            $match_score = round($best_pct, 1);
        }
    }

    if ($cat) {
        $mat['material_id'] = (int)$cat['id'];
        $mat['matched']     = true;
        $mat['match_score'] = $match_score;
        $mat['descripcion'] = $cat['descripcion'];
        $mat['marca']       = $cat['marca'];
        $mat['modelo']      = $cat['codigo'] ?: ($mat['modelo'] ?? '');
    } else {
        $mat['material_id'] = null;
        $mat['matched']     = false;
        $mat['match_score'] = 0.0;
    }

    $result[] = $mat;
}

echo json_encode(['success' => true, 'data' => $result]);
