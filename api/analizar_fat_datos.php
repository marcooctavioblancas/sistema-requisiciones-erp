<?php
/**
 * Mini-IA de la Etapa 2 (Datos Generales) del Protocolo FAT: lee la descripción de la Orden de
 * Producción + la descripción de todos sus items y extrae los campos técnicos del actuador y
 * la válvula. NO guarda nada -- solo analiza y regresa el JSON; guardar es responsabilidad de
 * api/fat_protocolo_datos.php, para que el humano pueda corregir antes de persistir.
 */
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/fat_protocolo.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !(isAdmin() || hasAcceso('ingenieria') || hasAcceso('manufactura') || hasAcceso('proyectos') || hasAcceso('calidad'))) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$orden_produccion_id = intval($_POST['orden_produccion_id'] ?? 0);
if (!$orden_produccion_id) {
    echo json_encode(['success' => false, 'error' => 'orden_produccion_id requerido']);
    exit;
}

$db = getDB();
$datos = obtenerDatosFatProtocolo($db, $orden_produccion_id);
if (!$datos) {
    echo json_encode(['success' => false, 'error' => 'Orden de Producción no encontrada']);
    exit;
}

$texto = textoParaAnalisisFat($datos['orden_produccion'], $datos['items']);
if (trim($texto) === '') {
    echo json_encode(['success' => false, 'error' => 'La Orden de Producción no tiene descripción para analizar']);
    exit;
}

$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'groq';
if ($provider !== 'groq') {
    echo json_encode(['success' => false, 'error' => 'Proveedor de IA no soportado para esta función']);
    exit;
}

$api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
if (empty($api_key)) {
    echo json_encode(['success' => false, 'error' => 'GROQ_API_KEY no configurada']);
    exit;
}

$prompt = <<<'PROMPT'
Eres un experto leyendo Órdenes de Producción de equipos de actuación industrial (actuadores neumáticos/hidráulicos/gas-aceite con válvula) en México.

Del siguiente texto, extrae ÚNICAMENTE los campos que aparezcan EXPLÍCITAMENTE. Si un campo no está declarado con esas palabras o un sinónimo directo, déjalo en null -- NO lo infieras, NO lo calcules ni conviertas unidades (ej. si dice "32 BAR" no lo pases a PSI, copia "32 BAR" tal cual).

Devuelve ÚNICAMENTE este JSON válido (sin markdown, sin texto adicional):
{"actuador":{"marca":null,"modelo":null,"no_serie":null,"tag":null,"tipo":null,"presion_operacion":null,"tipo_suministro":null,"falla_segura":null,"tipo_operacion_manual":null},"valvula":{"marca":null,"tag":null,"tipo":null,"tamano":null,"clase":null,"modelo":null,"no_serie":null}}

Guía de qué significa cada campo:
- actuador.tipo: mecanismo del actuador (ej. "yugo escocés", "piñón y cremallera", "doble acción", "simple acción")
- actuador.presion_operacion: presión de suministro tal como aparece en el texto, con su unidad original
- actuador.tipo_suministro: qué energiza al actuador (ej. "neumático", "hidráulico", "gas/aceite")
- actuador.falla_segura: posición a la que va el actuador ante falla de suministro (ej. "cierra", "abre", "última posición")
- actuador.tipo_operacion_manual: cómo se opera manualmente si aplica (ej. "bomba hidráulica", "volante manual")
- valvula.tipo: tipo de válvula (ej. "bola", "globo", "mariposa")
- valvula.tamano: tamaño nominal de la válvula (ej. "6\"", "24\"")
- valvula.clase: clase de presión de la válvula (ej. "600#", "2500#")
PROMPT;

$payload = [
    'model'       => 'openai/gpt-oss-120b',
    'messages'    => [['role' => 'user', 'content' => $prompt . "\n\n--- TEXTO ---\n" . mb_substr($texto, 0, 12000)]],
    'temperature' => 0.1,
    'max_tokens'  => 2000,
    'stream'      => false,
    'reasoning_effort' => 'low',
];

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
]);
$response  = curl_exec($ch);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    file_put_contents(__DIR__ . '/../logs/ia_debug.log', date('Y-m-d H:i:s') . " [FAT_DATOS_CURL_ERROR] " . $curl_err . "\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => 'No se pudo conectar con el servicio de IA. Intenta de nuevo en unos minutos.']); // nosemgrep
    exit;
}

$resp = json_decode($response, true);
if (empty($resp['choices'][0]['message']['content'])) {
    file_put_contents(__DIR__ . '/../logs/ia_debug.log', date('Y-m-d H:i:s') . " [FAT_DATOS_ERROR] " . substr($response, 0, 500) . "\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => 'El servicio de IA no pudo procesar la solicitud.']);
    exit;
}

$raw = trim($resp['choices'][0]['message']['content']);
$raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
$raw = preg_replace('/\s*```$/m', '', $raw);
if (preg_match('/\{[\s\S]*\}/u', $raw, $m)) $raw = $m[0];

$extraido = json_decode($raw, true);
if (!is_array($extraido)) {
    echo json_encode(['success' => false, 'error' => 'Respuesta de IA inválida', 'raw' => $raw]);
    exit;
}

echo json_encode(['success' => true, 'datos_generales' => fatProtocoloNormalizarDatosGenerales($extraido)], JSON_UNESCAPED_UNICODE);
