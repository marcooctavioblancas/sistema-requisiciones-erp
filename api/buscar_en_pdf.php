<?php
/**
 * Localiza visualmente un término de búsqueda en una imagen de página PDF
 * Devuelve posiciones y,x como % de las dimensiones de la imagen
 */
require_once __DIR__ . '/../includes/config.php';
set_time_limit(30);
header('Content-Type: application/json');

// Nada en el sistema llama este endpoint actualmente (verificado por grep) --
// sin gating de rol, cualquier usuario logueado podía golpearlo directo y
// gastar cuota real de la API de IA configurada. Se deja solo para admin
// (no se borra, por si se retoma la funcionalidad).
if (!isLoggedIn() || !isAdmin()) { echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['imagen']) || empty($_POST['termino'])) {
    echo json_encode(['success'=>false,'error'=>'Faltan parámetros']); exit;
}

$b64      = $_POST['imagen'];
$termino  = trim($_POST['termino']);
$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'groq';

$prompt = 'In this document image, locate ALL visible occurrences of the text: "' . addslashes($termino) . '" (case-insensitive).
For EACH occurrence return its center position.
Return ONLY valid JSON (no markdown, no explanation):
{"count":N,"positions":[{"y":TOP_PCT,"x":LEFT_PCT}]}
Where y and x are integers 0-100 (percentage of image height/width from top-left).
If not found: {"count":0,"positions":[]}';

if ($provider === 'groq') {
    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    // Groq descontinuó llama-4-scout -- confirmado en vivo (2026-08-25/26), 404
    // model_not_found. qwen/qwen3.6-27b es el reemplazo multimodal; reasoning_effort=none
    // evita que el modelo "thinking" genere tokens de razonamiento (confirmado en vivo:
    // reasoning_format=hidden los genera igual y solo los oculta del content).
    $payload = json_encode([
        'model'    => 'qwen/qwen3.6-27b',
        'messages' => [['role'=>'user','content'=>[
            ['type'=>'text',      'text'=>$prompt],
            ['type'=>'image_url', 'image_url'=>['url'=>'data:image/jpeg;base64,'.$b64]]
        ]]],
        'temperature'=>0, 'max_tokens'=>2048, 'stream'=>false, 'reasoning_effort'=>'none'
    ]);
    $headers = ['Content-Type: application/json','Authorization: Bearer '.$api_key];
    $url     = 'https://api.groq.com/openai/v1/chat/completions';
} elseif ($provider === 'gemini') {
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $payload = json_encode([
        'contents'=>[['parts'=>[
            ['text'=>$prompt],
            ['inline_data'=>['mime_type'=>'image/jpeg','data'=>$b64]]
        ]]],
        'generationConfig'=>['temperature'=>0,'maxOutputTokens'=>512]
    ]);
    $headers = ['Content-Type: application/json'];
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='.$api_key;
} else {
    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    $payload = json_encode([
        'model'=>'claude-haiku-4-5-20251001','max_tokens'=>512,
        'messages'=>[['role'=>'user','content'=>[
            ['type'=>'text', 'text'=>$prompt],
            ['type'=>'image','source'=>['type'=>'base64','media_type'=>'image/jpeg','data'=>$b64]]
        ]]]
    ]);
    $headers = ['x-api-key: '.$api_key,'anthropic-version: 2023-06-01','content-type: application/json'];
    $url     = 'https://api.anthropic.com/v1/messages';
}

$ch = curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>$headers,
    CURLOPT_TIMEOUT=>25,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
$resp = curl_exec($ch); curl_close($ch);

if (!$resp) { echo json_encode(['success'=>false,'error'=>'Sin respuesta']); exit; }
$data = json_decode($resp, true);

if ($provider === 'groq')       $raw = $data['choices'][0]['message']['content'] ?? '';
elseif ($provider === 'gemini') $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
else                            $raw = $data['content'][0]['text'] ?? '';

$raw = trim(preg_replace('/^```(?:json)?\s*/i','',preg_replace('/\s*```$/m','',$raw)));
if (preg_match('/\{[\s\S]*\}/u',$raw,$m)) $raw = $m[0];

$result = json_decode($raw, true);
if (!is_array($result)) { echo json_encode(['success'=>false,'error'=>'Respuesta inválida','raw'=>$raw]); exit; }

echo json_encode(['success'=>true,'count'=>intval($result['count']??0),'positions'=>$result['positions']??[]]);
