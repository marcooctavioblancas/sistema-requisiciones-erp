<?php
/**
 * OCR de una página de PDF vía IA (Groq / Gemini / Anthropic)
 * Recibe: imagen base64 del canvas renderizado
 * Devuelve: array JSON de {text, x, y} con posiciones como % del tamaño de imagen
 */
require_once __DIR__ . '/../includes/config.php';

set_time_limit(60);
header('Content-Type: application/json');

// Nada en el sistema llama este endpoint actualmente (verificado por grep) --
// sin gating de rol, cualquier usuario logueado podía golpearlo directo y
// gastar cuota real de la API de IA configurada. Se deja solo para admin
// (no se borra, por si se retoma la funcionalidad).
if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['imagen'])) {
    echo json_encode(['success' => false, 'error' => 'No se recibió imagen']);
    exit;
}

$b64      = $_POST['imagen'];
$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'groq';

$prompt = <<<'PROMPT'
Extract EVERY word and text element visible in this document image. Do not skip any text.
Return ONLY a valid JSON array (no markdown, no explanation):
[{"text":"word or short phrase","x":LEFT_PERCENT,"y":TOP_PERCENT}]
Rules:
- x = left edge as integer 0-100 (% of image width)
- y = top edge as integer 0-100 (% of image height)
- Each row/line of the document = separate items
- Repeated labels like "Name", "IBAN", "Swift" that appear multiple times must each be a separate item
- Include ALL text: titles, labels, values, column headers, footers, page numbers
- Do NOT merge separate lines into one item
- If a label like "Name" appears 3 times in the document, return 3 separate {"text":"Name",...} items with different y values
PROMPT;

if ($provider === 'groq') {

    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    // Groq descontinuó llama-4-scout -- confirmado en vivo (2026-08-25/26), 404
    // model_not_found. qwen/qwen3.6-27b es el reemplazo multimodal; reasoning_effort=none
    // evita que el modelo "thinking" genere tokens de razonamiento (confirmado en vivo:
    // reasoning_format=hidden los genera igual y solo los oculta del content).
    $payload = json_encode([
        'model'    => 'qwen/qwen3.6-27b',
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text',      'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]]
        ]]],
        'temperature' => 0,
        'max_tokens'  => 8192,
        'stream'      => false,
        'reasoning_effort' => 'none',
    ]);
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
    $url     = 'https://api.groq.com/openai/v1/chat/completions';

} elseif ($provider === 'gemini') {

    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $payload = json_encode([
        'contents'         => [['parts' => [
            ['text' => $prompt],
            ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $b64]]
        ]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 4096]
    ]);
    $headers = ['Content-Type: application/json'];
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $api_key;

} elseif ($provider === 'anthropic') {

    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 4096,
        'messages'   => [['role' => 'user', 'content' => [
            ['type' => 'text',  'text' => $prompt],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $b64]]
        ]]]
    ]);
    $headers = [
        'x-api-key: '        . $api_key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json'
    ];
    $url = 'https://api.anthropic.com/v1/messages';

} else {
    echo json_encode(['success' => false, 'error' => 'Provider no soportado para OCR']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
]);
$resp = curl_exec($ch);
curl_close($ch);

if (!$resp) {
    echo json_encode(['success' => false, 'error' => 'Sin respuesta de la IA']);
    exit;
}

$data = json_decode($resp, true);

if ($provider === 'groq') {
    $raw = $data['choices'][0]['message']['content'] ?? '';
} elseif ($provider === 'gemini') {
    $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
} else {
    $raw = $data['content'][0]['text'] ?? '';
}

// Limpiar markdown si el modelo lo añadió
$raw = trim($raw);
$raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
$raw = preg_replace('/\s*```$/m', '', $raw);
$raw = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $raw);
$raw = trim($raw);

// Extraer el array JSON
if (preg_match('/\[[\s\S]*\]/u', $raw, $m)) {
    $raw = $m[0];
}

$items = json_decode($raw, true);

if (!is_array($items)) {
    // Fallback: devolver texto plano convertido a items sin posición
    $lineas = array_filter(explode("\n", strip_tags($raw)));
    $items  = [];
    foreach (array_values($lineas) as $idx => $linea) {
        $linea = trim($linea);
        if ($linea) $items[] = ['text' => $linea, 'x' => 0, 'y' => round($idx / max(count($lineas), 1) * 100)];
    }
}

echo json_encode(['success' => true, 'items' => $items]);
