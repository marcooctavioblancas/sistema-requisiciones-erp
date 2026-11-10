<?php
/**
 * Extrae texto de un PDF en uploads/documentos_ref/ usando PyMuPDF (Python).
 * - Texto nativo: extracción directa por página.
 * - Páginas escaneadas: OCR con Tesseract si está disponible, se omiten si no.
 * Actualiza texto_extraido, paginas y estado en documentos_referencia.
 * Retorna { success, estado, chars, paginas, chars_por_pagina }
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/config.php';
set_time_limit(300);
ini_set('memory_limit', '256M');
header('Content-Type: application/json');

function extraer_texto_pymupdf(string $path): string { // nosemgrep
    if (!defined('PYTHON_PATH') || !defined('PYMUPDF_SCRIPT')) return '';
    if (!file_exists(PYTHON_PATH) || !file_exists(PYMUPDF_SCRIPT)) return '';
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open([PYTHON_PATH, PYMUPDF_SCRIPT, $path], $desc, $pipes); // nosemgrep
    if (!is_resource($proc)) return '';
    fclose($pipes[0]);
    $out  = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc) === 0 ? (string)$out : '';
}

function contar_paginas_fitz(string $path): int { // nosemgrep
    if (!defined('PYTHON_PATH') || !file_exists(PYTHON_PATH)) return 1;
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open( // nosemgrep
        [PYTHON_PATH, '-c', 'import fitz,sys;print(len(fitz.open(sys.argv[1])))', $path],
        $desc, $pipes
    );
    if (!is_resource($proc)) return 1;
    fclose($pipes[0]);
    $n = (int)trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return max(1, $n);
}

if (!isLoggedIn() || (!hasAcceso('proyectos') && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'No autorizado']);
    exit;
}

$doc_id = intval($_POST['doc_id'] ?? 0);
if (!$doc_id) {
    echo json_encode(['success'=>false,'error'=>'doc_id requerido']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, archivo_ruta, paginas FROM documentos_referencia WHERE id = ?");
$stmt->execute([$doc_id]);
$doc  = $stmt->fetch();

if (!$doc) {
    echo json_encode(['success'=>false,'error'=>'Documento no encontrado']);
    exit;
}

$fullPath = UPLOAD_PATH . $doc['archivo_ruta'];
if (!file_exists($fullPath)) {
    $db->prepare("UPDATE documentos_referencia SET estado='error' WHERE id=?")->execute([$doc_id]);
    echo json_encode(['success'=>false,'error'=>'Archivo no encontrado en disco']);
    exit;
}

// Liberar session lock para no bloquear otras páginas durante la extracción
session_write_close();

// ── Contar páginas con fitz (confiable para cualquier PDF) ───────────────────
$paginas_detectadas = contar_paginas_fitz($fullPath);

// ── Extracción con PyMuPDF ───────────────────────────────────────────────────
$text = extraer_texto_pymupdf($fullPath);

// ── Fallback PHP puro si PyMuPDF no devolvió nada ───────────────────────────
if (trim($text) === '') {
    $raw = @file_get_contents($fullPath);
    if ($raw !== false) {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);
        foreach ($streams[1] as $stream) {
            $dec = @gzuncompress($stream) ?: @gzinflate($stream);
            if ($dec === false && strlen($stream) > 2) $dec = @gzinflate(substr($stream, 2));
            $src = $dec ?: $stream;

            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $src, $m1);
            foreach ($m1[1] as $t) {
                $text .= str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], [' ',' ',' ','(',')',' '], $t) . ' ';
            }
            preg_match_all('/\[((?:[^\[\]]*|\[[^\[\]]*\])*)\]\s*TJ/s', $src, $m2);
            foreach ($m2[1] as $tj) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $tj, $pts);
                foreach ($pts[1] as $p) {
                    $text .= str_replace(['\\n','\\r','\\(','\\)','\\\\'], [' ',' ','(',')',' '], $p);
                }
                $text .= ' ';
            }
        }
        unset($raw);
    }
}

// ── Limpiar y evaluar texto ──────────────────────────────────────────────────
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
$text = preg_replace('/[ \t]{2,}/', ' ', $text);
$text = trim($text);

$chars   = strlen($text);
$paginas = $paginas_detectadas;

$letras = $chars > 0 ? preg_match_all('/[a-zA-Z]/u', $text) : 0;

// Legible si ≥ 20 % de los caracteres son letras y hay texto mínimo
$es_legible = $chars > 40 && ($letras / max(1,$chars)) >= 0.20;

if ($chars > 0 && $es_legible) {
    // Con PyMuPDF en PDFs mixtos el ratio puede bajar: usar umbral reducido (30 chars/pág)
    $estado = ($chars / $paginas >= 30) ? 'procesado' : 'sin_texto';
} else {
    $estado = 'sin_texto';
}

$db->prepare("UPDATE documentos_referencia SET texto_extraido=?, estado=?, paginas=? WHERE id=?")
   ->execute([$chars > 0 ? $text : null, $estado, $paginas, $doc_id]);

echo json_encode([
    'success'          => true,
    'estado'           => $estado,
    'chars'            => $chars,
    'paginas'          => $paginas,
    'chars_por_pagina' => $paginas > 0 ? (int)round($chars / $paginas) : 0,
]);
