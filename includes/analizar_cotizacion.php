<?php
/**
 * Analizar Hoja de Cotización con IA
 * Soporta: Anthropic API (cloud) y LM Studio (local, OpenAI-compatible)
 *
 * Proveedores:
 *   anthropic  → soporta PDF e imágenes (vision)
 *   lmstudio   → soporta PDF (extrae texto) e imágenes solo si el modelo cargado tiene visión
 */
require_once __DIR__ . '/config.php';

// Los modelos locales de visión pueden tardar varios minutos en CPU
set_time_limit(600); // 10 minutos máximo
ini_set('max_execution_time', 600);

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Aceptar archivos directos O paginas_pdf (multi-page)
$tiene_archivo  = !empty($_FILES['archivo']);
$tiene_paginas  = !empty($_POST['paginas_pdf']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!$tiene_archivo && !$tiene_paginas)) {
    echo json_encode(['success' => false, 'error' => 'No se recibió ningún archivo']);
    exit;
}

// cotizacion_id opcional – si se pasa, guarda resultado en tabla cotizaciones
$cotizacion_id_para_guardar = intval($_POST['cotizacion_id'] ?? 0);

// Si el navegador ya extrajo texto nativo del PDF (0 tokens de visión), 'archivo' es
// solo un placeholder (doc.txt) — no debe pasar por la validación de tipo/tamaño real.
$tiene_texto_ocr = trim($_POST['texto_ocr'] ?? '') !== '';

$file      = $_FILES['archivo'];
$ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$is_pdf    = ($ext === 'pdf' || $file['type'] === 'application/pdf');
$is_image  = in_array($ext, ['jpg','jpeg','png','webp','gif']);

if (!$tiene_texto_ocr && !$is_pdf && !$is_image) {
    echo json_encode(['success' => false, 'error' => 'Tipo de archivo no permitido. Use PDF, JPG, PNG o WEBP.']);
    exit;
}
if (!$tiene_texto_ocr && $file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'El archivo es demasiado grande (máx 10 MB).']);
    exit;
}

$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'anthropic';

$prompt_instruccion = <<<'PROMPT'
Eres un experto extractor de datos de cotizaciones, facturas y presupuestos en español mexicano.
El texto que recibirás puede venir de un PDF con columnas múltiples, por lo que el orden puede estar mezclado. Usa el contexto para reconstruir la información correctamente.

CAMPOS A BUSCAR:
- Proveedor/empresa: nombre en el encabezado o membrete del documento
- Fecha: fecha de emisión del documento
- Referencia: número de cotización, folio, No. de cotización (ej: COT-2026-0170)
- Subtotal: importe antes de IVA
- Descuento: cualquier descuento aplicado (0 si no hay)
- IVA: impuesto (puede decir IVA, I.V.A., 16%)
- Total: importe total final
- Productos/servicios: cada partida o renglón de la tabla

COLUMNAS DE TABLA COMUNES: Partida, Cantidad, Unidad, Descripción, Identificación, Servicio, Observaciones, Precio Unitario, Precio Total, Importe, Total

Devuelve ÚNICAMENTE este JSON válido (sin markdown, sin texto extra, sin explicaciones antes o después):
{"proveedor":"nombre empresa emisora","fecha":"DD/MM/YYYY","referencia":"folio o número","subtotal":0.00,"descuento":0.00,"iva":0.00,"total":0.00,"notas":"vigencia, condiciones pago, comentarios (máx 150 chars)","productos":[{"descripcion":"desc completa con marca modelo serie","cantidad":1,"precio_unitario":0.00,"subtotal":0.00}]}

REGLAS ESTRICTAS:
1. Valores monetarios: solo números sin $ ni comas (1907.04 NO "$1,907.04")
2. Cada fila/partida de la tabla es un elemento en "productos", aunque sea un servicio
3. "Precio Total" o "Importe" por fila = subtotal del producto
4. "Precio Unitario" por fila = precio_unitario del producto
5. Fecha en formato DD/MM/YYYY siempre
6. Si el dato no existe: null
7. El proveedor es quien EMITE la cotización (la empresa del membrete/logo), NO el cliente
8. Si hay número de cotización, folio o referencia, ponlo en "referencia"
PROMPT;

// ─────────────────────────────────────────────────────────────────
//  PROVEEDOR: ANTHROPIC
// ─────────────────────────────────────────────────────────────────
if ($provider === 'anthropic') {

    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'ANTHROPIC_API_KEY no configurada en config.php']);
        exit;
    }

    $base64_data = base64_encode(file_get_contents($file['tmp_name']));
    $media_type_map = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
                       'png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
    $media_type = $media_type_map[$ext] ?? $file['type'];

    $file_block = $is_pdf
        ? ['type'=>'document','source'=>['type'=>'base64','media_type'=>$media_type,'data'=>$base64_data]]
        : ['type'=>'image',   'source'=>['type'=>'base64','media_type'=>$media_type,'data'=>$base64_data]];

    $payload = [
        'model'      => 'claude-opus-4-5',
        'max_tokens' => 2048,
        'messages'   => [['role'=>'user','content'=>[
            ['type'=>'text','text'=>$prompt_instruccion],
            $file_block
        ]]]
    ];

    $response_text = llamar_api(
        'https://api.anthropic.com/v1/messages',
        json_encode($payload),
        [
            'x-api-key: '        . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ]
    );

    if ($response_text['error']) {
        echo json_encode(['success' => false, 'error' => $response_text['error']]);
        exit;
    }

    $resp = json_decode($response_text['body'], true);
    if (empty($resp['content'][0]['text'])) {
        $api_err = $resp['error']['message'] ?? ('HTTP ' . $response_text['status']);
        echo json_encode(['success' => false, 'error' => 'Error Anthropic: ' . $api_err]);
        exit;
    }

    $texto_ia = $resp['content'][0]['text'];

// ─────────────────────────────────────────────────────────────────
//  PROVEEDOR: GROQ (gratuito, rápido, visión con Llama)
// ─────────────────────────────────────────────────────────────────
} elseif ($provider === 'groq') {

    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'GROQ_API_KEY no configurada. Agrégala en el archivo .env (gratis en console.groq.com)']);
        exit;
    }

    // Groq descontinuó llama-4-scout (visión) y llama-3.3-70b-versatile (texto) --
    // confirmado en vivo (2026-08-25/26), ambos daban 404 model_not_found. Modelo separado por
    // tarea (rate-limit propio por modelo en Groq + más barato): texto -> gpt-oss-120b,
    // visión -> qwen3.6-27b (único multimodal del catálogo). Ver reasoning_effort más abajo.
    $model = 'qwen/qwen3.6-27b';

    // ── Extracción de texto, misma cascada que produccion.php (procesar_plano_ia.php) ──
    // 1) texto ya extraído por el navegador (PDF.js getTextContent, 0 tokens de visión)
    // 2) PyMuPDF server-side (respaldo si el navegador no mandó texto o no alcanzó)
    // 3) parser PHP propio (último respaldo de texto)
    // 4) páginas JPEG / imagen directa (visión — solo si nada de lo anterior sirvió)
    $texto_ocr_browser = trim($_POST['texto_ocr'] ?? '');

    $texto_extraido = '';
    if ($texto_ocr_browser !== '' && mb_strlen($texto_ocr_browser) > 50) {
        $texto_extraido = mb_substr($texto_ocr_browser, 0, 15000);
    } else {
        $pdf_path = (!empty($_FILES['pdf_original']) && $_FILES['pdf_original']['error'] === 0)
            ? $_FILES['pdf_original']['tmp_name']
            : (($is_pdf && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) ? $file['tmp_name'] : null);

        if ($pdf_path) {
            $texto_extraido = extraer_texto_pymupdf($pdf_path);
            if (empty($texto_extraido)) {
                $txt = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ',
                    mb_convert_encoding(extraer_texto_pdf($pdf_path) ?? '', 'UTF-8', 'UTF-8')));
                if (texto_es_legible($txt)) $texto_extraido = mb_substr($txt, 0, 10000);
            }
        }
    }

    if ($texto_extraido !== '') {
        // ── Texto disponible (navegador o server-side): modo texto ───
        $model = 'openai/gpt-oss-120b';
        $messages = [['role' => 'user', 'content' =>
            $prompt_instruccion . "\n\n--- TEXTO DEL PDF ---\n" . $texto_extraido]];

    } elseif (!empty($_POST['paginas_pdf'])) {
        // ── PDF imagen: páginas JPEG de PDF.js ───────────────────────
        $paginas = json_decode($_POST['paginas_pdf'], true) ?? [];
        $content = [['type' => 'text', 'text' => $prompt_instruccion]];
        foreach ($paginas as $b64) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]];
        }
        $messages = [['role' => 'user', 'content' => $content]];

    } elseif ($is_image) {
        // ── Imagen directa ────────────────────────────────────────────
        $base64_data = base64_encode(file_get_contents($file['tmp_name']));
        $mt_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        $media_type = $mt_map[$ext] ?? 'image/jpeg';
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $prompt_instruccion],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $media_type . ';base64,' . $base64_data]],
        ]]];

    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo leer el PDF.']);
        exit;
    }

    // Ambos son modelos "thinking". reasoning_effort SÍ evita que se generen tokens de
    // razonamiento (a diferencia de reasoning_format:hidden, que los genera igual y solo los
    // oculta) -- confirmado en vivo. gpt-oss-120b no soporta 'none', su mínimo es 'low'; el
    // razonamiento sale en message.reasoning (campo aparte, no contamina el content).
    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.1,
        'max_tokens'  => 8192,
        'stream'      => false,
        'reasoning_effort' => ($model === 'openai/gpt-oss-120b') ? 'low' : 'none',
    ];

    $result = llamar_api(
        'https://api.groq.com/openai/v1/chat/completions',
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]
    );

    if ($result['error']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    $resp = json_decode($result['body'], true);

    if (empty($resp['choices'][0]['message']['content'])) {
        $api_err = $resp['error']['message'] ?? ('HTTP ' . $result['status'] . ' — ' . mb_substr($result['body'], 0, 300));
        echo json_encode(['success' => false, 'error' => 'Error Groq: ' . $api_err]);
        exit;
    }

    $texto_ia = $resp['choices'][0]['message']['content'];

// ─────────────────────────────────────────────────────────────────
//  PROVEEDOR: GOOGLE GEMINI FLASH (gratuito)
// ─────────────────────────────────────────────────────────────────
} elseif ($provider === 'gemini') {

    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'GEMINI_API_KEY no configurada (gratis en aistudio.google.com/apikey)']);
        exit;
    }

    $parts = [['text' => $prompt_instruccion]];

    if (!empty($_POST['paginas_pdf'])) {
        // Páginas JPEG enviadas desde PDF.js
        $paginas = json_decode($_POST['paginas_pdf'], true) ?? [];
        foreach ($paginas as $b64) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $b64]];
        }
    } else {
        $media_type_map = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'webp' => 'image/webp', 'gif' => 'image/gif',
        ];
        $media_type  = $media_type_map[$ext] ?? $file['type'];
        $parts[]     = ['inline_data' => ['mime_type' => $media_type, 'data' => base64_encode(file_get_contents($file['tmp_name']))]];
    }

    $payload = [
        'contents'         => [['parts' => $parts]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 1024],
    ];

    $gemini_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $api_key;

    $result = llamar_api($gemini_url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);

    if ($result['error']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    $resp = json_decode($result['body'], true);

    if (empty($resp['candidates'][0]['content']['parts'][0]['text'])) {
        $api_err = $resp['error']['message'] ?? ('HTTP ' . $result['status'] . ' — ' . mb_substr($result['body'], 0, 300));
        echo json_encode(['success' => false, 'error' => 'Error Gemini: ' . $api_err]);
        exit;
    }

    $texto_ia = $resp['candidates'][0]['content']['parts'][0]['text'];

// ─────────────────────────────────────────────────────────────────
//  PROVEEDOR: LM STUDIO (OpenAI-compatible local)
// ─────────────────────────────────────────────────────────────────
} elseif ($provider === 'lmstudio') {

    $base_url = defined('LMSTUDIO_API_URL')
        ? preg_replace('|/v1/chat/completions$|', '', LMSTUDIO_API_URL)
        : 'http://localhost:1234';

    $chat_url = rtrim($base_url, '/') . '/v1/chat/completions';
    $model    = obtener_modelo_lmstudio($base_url);

    $prompt_json = <<<'PROMPT'
Extrae los datos de esta cotización. Devuelve SOLO este JSON (sin markdown, sin explicaciones):
{"proveedor":"empresa que cotiza","fecha":"DD/MM/YYYY","referencia":"folio","subtotal":0.00,"descuento":0.00,"iva":0.00,"total":0.00,"notas":"condiciones breves","productos":[{"descripcion":"descripcion completa con marca y modelo","cantidad":0,"precio_unitario":0.00,"subtotal":0.00}]}
Reglas: valores monetarios sin símbolo ni comas. Cada fila/partida de la tabla es un producto. Columna Precio Total o Importe = subtotal del producto. null si no existe el dato.
PROMPT;

    if ($is_image) {
        // Imagen directa → visión
        $raw_img    = file_get_contents($file['tmp_name']);
        $base64_img = base64_encode($raw_img);
        $mt_map     = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        $media_type = $mt_map[$ext] ?? 'image/jpeg';

        $messages = construir_mensaje_vision($prompt_json, $base64_img, $media_type);

    } else {
        // PDF: intentar texto primero
        $texto_pdf = extraer_texto_pdf($file['tmp_name']);
        $texto_limpio = mb_convert_encoding($texto_pdf ?? '', 'UTF-8', 'UTF-8');
        $texto_limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $texto_limpio);
        $texto_limpio = trim($texto_limpio);

        if (strlen($texto_limpio) >= 80) {
            // Texto suficiente → modo texto
            $messages = [['role' => 'user', 'content' => $prompt_json . "\n\nCOTIZACIÓN:\n" . mb_substr($texto_limpio, 0, 2000)]];

        } else {
            // PDF sin texto (escaneado / visual) → convertir a imagen y usar visión
            $img_tmp = pdf_a_imagen($file['tmp_name']);

            if ($img_tmp) {
                $base64_img = base64_encode(file_get_contents($img_tmp));
                @unlink($img_tmp);
                $messages = construir_mensaje_vision($prompt_json, $base64_img, 'image/png');
            } else {
                echo json_encode(['success' => false,
                    'error' => 'El PDF no tiene texto seleccionable y no se pudo convertir a imagen automáticamente. '
                             . 'Solución: toma una captura de pantalla del PDF y súbela como PNG o JPG.']);
                exit;
            }
        }
    }

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.1,
        'max_tokens'  => 800,
        'stream'      => false,
    ];

    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_payload === false) {
        echo json_encode(['success' => false,
            'error' => 'Error al preparar el texto del PDF (caracteres inválidos). Intenta con otro archivo.']);
        exit;
    }

    $response_text = llamar_api($chat_url, $json_payload, ['Content-Type: application/json']);

    if ($response_text['error']) {
        echo json_encode(['success' => false,
            'error' => 'No se pudo conectar con LM Studio: ' . $response_text['error'] . '. Verifica que el servidor esté corriendo en ' . $base_url]);
        exit;
    }

    $resp = json_decode($response_text['body'], true);

    if (empty($resp['choices'][0]['message']['content'])) {
        $api_err = $resp['error']['message']
            ?? ($resp['error']['type'] ?? null)
            ?? ('HTTP ' . $response_text['status'] . ' — ' . mb_substr($response_text['body'], 0, 400));
        echo json_encode(['success' => false, 'error' => 'LM Studio respondió con error: ' . $api_err]);
        exit;
    }

    $texto_ia = $resp['choices'][0]['message']['content'];

} else {
    echo json_encode(['success' => false, 'error' => 'AI_PROVIDER no válido en config.php (usa "anthropic" o "lmstudio")']);
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  Parsear respuesta JSON de la IA
// ─────────────────────────────────────────────────────────────────
$texto_ia = trim($texto_ia);

// Quitar bloques markdown ```json ... ``` o ``` ... ```
$texto_ia = preg_replace('/^```(?:json)?\s*/i', '', $texto_ia);
$texto_ia = preg_replace('/\s*```$/m', '', $texto_ia);

// Algunos modelos reasoning añaden <think>...</think> antes del JSON
$texto_ia = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $texto_ia);
$texto_ia = trim($texto_ia);

// Extraer primer bloque JSON si hay texto adicional
if (preg_match('/\{[\s\S]*\}/u', $texto_ia, $m)) {
    $texto_ia = $m[0];
}

$cotizacion = json_decode($texto_ia, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($cotizacion)) {
    echo json_encode(['success' => false, 'error' => 'La IA no devolvió datos válidos. Intenta con otro archivo o revisa que el modelo esté bien cargado.']);
    exit;
}

// Sanitizar
$cotizacion['proveedor']  = htmlspecialchars(strip_tags($cotizacion['proveedor']  ?? ''), ENT_QUOTES, 'UTF-8');
$cotizacion['fecha']      = htmlspecialchars(strip_tags($cotizacion['fecha']      ?? ''), ENT_QUOTES, 'UTF-8');
$cotizacion['referencia'] = htmlspecialchars(strip_tags($cotizacion['referencia'] ?? ''), ENT_QUOTES, 'UTF-8');
$cotizacion['notas']      = htmlspecialchars(strip_tags($cotizacion['notas']      ?? ''), ENT_QUOTES, 'UTF-8');
$cotizacion['subtotal']   = is_numeric($cotizacion['subtotal']  ?? null) ? floatval($cotizacion['subtotal'])  : null;
$cotizacion['descuento']  = is_numeric($cotizacion['descuento'] ?? null) ? floatval($cotizacion['descuento']) : null;
$cotizacion['iva']        = is_numeric($cotizacion['iva']       ?? null) ? floatval($cotizacion['iva'])       : null;
$cotizacion['total']      = is_numeric($cotizacion['total']     ?? null) ? floatval($cotizacion['total'])     : null;

if (!empty($cotizacion['productos']) && is_array($cotizacion['productos'])) {
    foreach ($cotizacion['productos'] as &$p) {
        $p['descripcion']     = htmlspecialchars(strip_tags($p['descripcion']     ?? ''), ENT_QUOTES, 'UTF-8');
        $p['cantidad']        = is_numeric($p['cantidad']        ?? null) ? floatval($p['cantidad'])        : null;
        $p['precio_unitario'] = is_numeric($p['precio_unitario'] ?? null) ? floatval($p['precio_unitario']) : null;
        $p['subtotal']        = is_numeric($p['subtotal']        ?? null) ? floatval($p['subtotal'])        : null;
    }
    unset($p);
}

// Si se pasó cotizacion_id, guardar resultado en la tabla cotizaciones
if ($cotizacion_id_para_guardar > 0) {
    try {
        $db_cot = getDB();
        $datos_json = json_encode($cotizacion, JSON_UNESCAPED_UNICODE);

        // Guardar PDF en carpeta del proyecto
        $archivo_ruta_guardada = null;
        if (!empty($_FILES['archivo']['tmp_name'])
            && is_uploaded_file($_FILES['archivo']['tmp_name'])
            && ($_FILES['archivo']['size'] ?? 0) > 0
            && strtolower(pathinfo($_FILES['archivo']['name'] ?? '', PATHINFO_EXTENSION)) === 'pdf') {
            $stmt_info = $db_cot->prepare("SELECT c.proyecto_id, p.nombre as prov_nombre FROM cotizaciones c JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = ?");
            $stmt_info->execute([$cotizacion_id_para_guardar]);
            $info = $stmt_info->fetch(PDO::FETCH_ASSOC);
            if ($info) {
                $dir = __DIR__ . '/../uploads/cotizaciones/' . intval($info['proyecto_id']) . '/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($info['prov_nombre'] ?? 'proveedor'));
                $nombre_archivo = $cotizacion_id_para_guardar . '_' . $slug . '.pdf';
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $dir . $nombre_archivo)) {
                    $archivo_ruta_guardada = 'uploads/cotizaciones/' . intval($info['proyecto_id']) . '/' . $nombre_archivo;
                }
            }
        }

        $stmt_cot = $db_cot->prepare("UPDATE cotizaciones SET datos_ia = ?, archivo_ruta = COALESCE(?, archivo_ruta), estado = 'recibida', fecha_respuesta = NOW() WHERE id = ?");
        $stmt_cot->execute([$datos_json, $archivo_ruta_guardada, $cotizacion_id_para_guardar]);
        // Actualizar estado del proyecto/requisición a cotizacion_recibida
        $stmt_cot2 = $db_cot->prepare("SELECT proyecto_id, requisicion_id FROM cotizaciones WHERE id = ?");
        $stmt_cot2->execute([$cotizacion_id_para_guardar]);
        $row_cot = $stmt_cot2->fetch(PDO::FETCH_ASSOC);
        if ($row_cot) {
            if ($row_cot['requisicion_id']) {
                $db_cot->prepare("UPDATE requisiciones SET estado_compra = 'cotizacion_recibida' WHERE id = ?")->execute([$row_cot['requisicion_id']]);
                registrarEventoRequisicion($db_cot, (int) $row_cot['requisicion_id'], 'cotizacion_recibida');
            }
            $db_cot->prepare("UPDATE proyectos SET estado = 'cotizacion_recibida' WHERE id = ? AND estado = 'cotizacion_enviada'")->execute([$row_cot['proyecto_id']]);

            // Notificación — cotización recibida
            try {
                $stmt_pn = $db_cot->prepare("SELECT p.nombre, pr.nombre as prov_nombre FROM proyectos p LEFT JOIN cotizaciones c ON c.id = ? LEFT JOIN proveedores pr ON pr.id = c.proveedor_id WHERE p.id = ?");
                $stmt_pn->execute([$cotizacion_id_para_guardar, $row_cot['proyecto_id']]);
                $row_pn = $stmt_pn->fetch(PDO::FETCH_ASSOC);
                $proy_n = $row_pn['nombre'] ?? "ID {$row_cot['proyecto_id']}";
                $prov_n = $row_pn['prov_nombre'] ?? 'proveedor';
                notificarRoles(
                    ['compras'],
                    'cotizacion_recibida',
                    "Cotización recibida: $proy_n",
                    "Se recibió la cotización de \"$prov_n\" para \"$proy_n\".",
                    'compras.php',
                    0
                );
            } catch (Exception $ignored) {}
        }
    } catch (Exception $e) {
        // No bloquear la respuesta si falla el guardado
    }
}

echo json_encode(['success' => true, 'cotizacion' => $cotizacion]);

// ─────────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────────

/**
 * Consulta /v1/models de LM Studio y devuelve el ID del primer modelo cargado.
 * Si falla, usa el valor de LMSTUDIO_MODEL configurado en config.php.
 */
function obtener_modelo_lmstudio(string $base_url): string {
    $fallback = defined('LMSTUDIO_MODEL') ? LMSTUDIO_MODEL : 'local-model';
    $url = rtrim($base_url, '/') . '/v1/models';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return $fallback;
    $data = json_decode($resp, true);

    // LM Studio devuelve {"data":[{"id":"modelo-id",...},...]}
    if (!empty($data['data'][0]['id'])) {
        return $data['data'][0]['id'];
    }
    return $fallback;
}

/**
 * Verifica si el texto extraído de un PDF es legible (no basura de fuentes embebidas).
 * Requiere: mínimo 40 chars Y al menos 65% de caracteres legibles en español.
 */
function texto_es_legible(string $text): bool {
    if (strlen(trim($text)) < 40) return false;
    $legibles = preg_match_all(
        '/[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ0-9\s\.\,\$\%\-\/\:\;\(\)\+\*\'\"]/u',
        $text, $m
    );
    $total = mb_strlen($text);
    return $total > 0 && ($legibles / $total) >= 0.65;
}

/** Construye el bloque de mensaje OpenAI con imagen en base64 */
function construir_mensaje_vision(string $prompt, string $base64, string $media_type): array {
    return [['role' => 'user', 'content' => [
        ['type' => 'text',      'text' => $prompt],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $media_type . ';base64,' . $base64]],
    ]]];
}

/**
 * Convierte la primera página de un PDF a PNG.
 * Intenta: 1) Imagick (PHP extension), 2) GhostScript, 3) MuPDF (mutool)
 * Devuelve la ruta del PNG temporal, o null si ningún método funciona.
 */
function pdf_a_imagen(string $pdf_path): ?string {
    $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cot_' . uniqid() . '.png';

    // ── Método 1: Imagick ──────────────────────────────────────────
    if (extension_loaded('imagick')) {
        try {
            $img = new Imagick();
            $img->setResolution(150, 150);
            $img->readImage($pdf_path . '[0]');
            $img->setImageFormat('png');
            $img->flattenImages()->writeImage($out);
            $img->clear();
            if (file_exists($out) && filesize($out) > 1000) return $out;
        } catch (Throwable $e) { /* continuar */ }
    }

    // ── Método 2: GhostScript ──────────────────────────────────────
    $gs_candidates = ['gswin64c', 'gswin32c', 'gs'];
    foreach (glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [] as $p) $gs_candidates[] = '"' . $p . '"';
    foreach (glob('C:\\Program Files (x86)\\gs\\gs*\\bin\\gswin32c.exe') ?: [] as $p) $gs_candidates[] = '"' . $p . '"';

    foreach ($gs_candidates as $gs) {
        $cmd = $gs . ' -dNOPAUSE -dBATCH -dFirstPage=1 -dLastPage=1 '
             . '-sDEVICE=png16m -r150 '
             . '-sOutputFile=' . escapeshellarg($out) . ' '
             . escapeshellarg($pdf_path) . ' 2>&1';
        @exec($cmd, $o, $code);
        if ($code === 0 && file_exists($out) && filesize($out) > 1000) return $out;
    }

    // ── Método 3: mutool (MuPDF) ───────────────────────────────────
    $mutool_candidates = ['mutool', 'C:\\Program Files\\MuPDF\\mutool.exe'];
    foreach ($mutool_candidates as $mt) {
        $cmd = $mt . ' draw -F png -o ' . escapeshellarg($out) . ' '
             . escapeshellarg($pdf_path) . ' 1 2>&1';
        @exec($cmd, $o, $code);
        if ($code === 0 && file_exists($out) && filesize($out) > 1000) return $out;
    }

    return null;
}


function llamar_api(string $url, string $body, array $headers, int $max_reintentos = 3): array {
    $espera = 5; // segundos entre reintentos (se duplica cada vez)

    for ($intento = 1; $intento <= $max_reintentos; $intento++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 540,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $msg = 'No se pudo conectar con LM Studio en ' . $url . '. '
                 . 'Verifica que: 1) LM Studio esté abierto, 2) El servidor local esté iniciado (botón "Start Server"), '
                 . '3) El modelo esté cargado. Detalle: ' . $curl_err;
            return ['error' => $msg, 'body' => '', 'status' => 0];
        }

        // Rate limit (429) o error de servidor (5xx) → reintentar con espera
        if ($http_code === 429 || $http_code >= 500) {
            if ($intento < $max_reintentos) {
                // Leer el retry-after del cuerpo si Groq lo indica
                $decoded = json_decode($response, true);
                $retry_after = intval($decoded['error']['message'] ?? 0);
                $segundos = $retry_after > 0 ? $retry_after : $espera;
                sleep($segundos);
                $espera *= 2; // backoff exponencial
                continue;
            }
            // Agotados los reintentos
            $decoded = json_decode($response, true);
            $msg_error = $decoded['error']['message'] ?? 'Rate limit alcanzado. Intenta en unos segundos.';
            return ['error' => 'Rate limit de Groq: ' . $msg_error, 'body' => $response, 'status' => $http_code];
        }

        return ['error' => null, 'body' => $response, 'status' => $http_code];
    }

    return ['error' => 'No se pudo completar la solicitud tras ' . $max_reintentos . ' intentos.', 'body' => '', 'status' => 0];
}

/**
 * Extrae texto de un PDF sin herramientas externas.
 * Método 1: pdftotext si está disponible.
 * Método 2: Descomprime streams FlateDecode (zlib) y lee operadores PDF Tj/TJ.
 * Método 3: Strings literales en el binario crudo.
 */
/**
 * Extrae texto de un PDF usando PyMuPDF (Python).
 * Mucho más preciso que el parser PHP. Devuelve '' si el PDF es imagen/escaneado.
 */
function extraer_texto_pymupdf(string $path): string {
    if (!defined('PYTHON_PATH') || !defined('PYMUPDF_SCRIPT')) return '';
    if (!file_exists($path) || !file_exists(PYMUPDF_SCRIPT)) return '';
    $cmd = escapeshellcmd(PYTHON_PATH) . ' ' . escapeshellarg(PYMUPDF_SCRIPT) . ' ' . escapeshellarg($path) . ' 2>&1';
    exec($cmd, $lines, $ret);
    $texto = implode("\n", $lines);
    return strlen(trim($texto)) > 40 ? $texto : '';
}

function extraer_texto_pdf(string $path): string {
    // ── Método 1: pdftotext ───────────────────────────────────────
    $pdftotext = 'pdftotext';
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (['C:\\poppler\\bin\\pdftotext.exe',
                  'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
                  'C:\\Program Files (x86)\\poppler\\bin\\pdftotext.exe'] as $r) {
            if (file_exists($r)) { $pdftotext = '"' . $r . '"'; break; }
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
    @exec($pdftotext . ' -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>&1', $o, $c);
    if ($c === 0 && file_exists($tmp)) {
        $t = file_get_contents($tmp); @unlink($tmp);
        if (strlen(trim($t)) > 30) return $t;
    }
    @unlink($tmp);

    // ── Método 2: Descomprimir streams FlateDecode (zlib) ────────
    $raw  = file_get_contents($path);
    $text = '';
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);

    foreach ($streams[1] as $stream) {
        $dec = false;
        if ($dec === false) $dec = @gzuncompress($stream);
        if ($dec === false) $dec = @gzinflate($stream);
        if ($dec === false && strlen($stream) > 2) $dec = @gzinflate(substr($stream, 2));

        $src = $dec ?: $stream;

        // Tj: (texto)Tj
        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $src, $m1);
        foreach ($m1[1] as $t) {
            $t = str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], [' ',' ',' ','(',')',' '], $t);
            $text .= $t . ' ';
        }
        // TJ: [(texto)spacing(texto)]TJ
        preg_match_all('/\[((?:[^\[\]]*|\[[^\[\]]*\])*)\]\s*TJ/s', $src, $m2);
        foreach ($m2[1] as $tj) {
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $tj, $pts);
            foreach ($pts[1] as $p) {
                $p = str_replace(['\\n','\\r','\\(','\\)','\\\\'], [' ',' ','(',')',' '], $p);
                $text .= $p;
            }
            $text .= ' ';
        }
        if ($dec) $text .= "\n";
    }

    // ── Método 3: strings literales en binario crudo ──────────────
    if (strlen(trim($text)) < 30) {
        preg_match_all('/\(([^)\\\\]{2,})\)/', $raw, $m);
        foreach ($m[1] as $s) {
            $s = str_replace(['\\n','\\r'], ' ', $s);
            $text .= $s . ' ';
        }
    }

    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    return trim($text);
}
