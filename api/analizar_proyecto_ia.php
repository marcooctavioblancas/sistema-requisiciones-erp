<?php
/**
 * Analizar Documento de Proyecto con IA
 * Soporta: Anthropic API, Groq, Google Gemini y LM Studio (OpenAI-compatible)
 *
 * Acepta: Orden de Compra (PO) del cliente o Especificación Técnica / Orden de Venta interna.
 * NO guarda en BD — solo analiza y retorna los datos estructurados del proyecto.
 */
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../includes/config.php';

// Los modelos locales de visión pueden tardar varios minutos en CPU
set_time_limit(600); // 10 minutos máximo
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

// ── Captura errores fatales que de otro modo dejarían respuesta vacía ────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // Solo si aún no se emitió JSON válido
        if (!headers_sent()) header('Content-Type: application/json');
        file_put_contents(
            __DIR__ . '/../logs/ia_debug.log',
            date('Y-m-d H:i:s') . " [FATAL] " . $err['message'] . " en " . $err['file'] . ":" . $err['line'] . "\n",
            FILE_APPEND
        );
        echo json_encode(['success' => false, 'error' => 'Error interno del servidor: ' . $err['message']]);
    }
});

if (!isLoggedIn() || (!hasAcceso('proyectos') && !isAdmin())) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$tiene_archivo = !empty($_FILES['archivo']) && ($_FILES['archivo']['error'] ?? 1) === 0;
$tiene_paginas = !empty($_POST['paginas_pdf']);
$texto_ocr     = trim($_POST['texto_ocr'] ?? '');   // texto ya extraído por Tesseract.js en el browser

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!$tiene_archivo && !$tiene_paginas && $texto_ocr === '')) {
    echo json_encode(['success' => false, 'error' => 'No se recibió ningún archivo ni páginas PDF']);
    exit;
}

$file     = $_FILES['archivo'] ?? ['name'=>'doc.jpg','type'=>'image/jpeg','tmp_name'=>'','error'=>0,'size'=>0];
$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$is_pdf   = ($ext === 'pdf' || ($file['type'] ?? '') === 'application/pdf');
$is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);

// Si viene texto OCR del browser, no validar tipo de archivo (el dummy es .txt)
if (!$tiene_paginas && $texto_ocr === '') {
    if (!$is_pdf && !$is_image) {
        echo json_encode(['success' => false, 'error' => 'Tipo de archivo no permitido. Use PDF, JPG, PNG o WEBP.']);
        exit;
    }
    if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'El archivo es demasiado grande (máx 20 MB).']);
        exit;
    }
}

$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'anthropic';
$modo     = $_POST['modo'] ?? 'resumen'; // 'resumen' | 'plan'

// ── Extrae párrafos relevantes a inspección de un texto largo ────────────────
function extraer_parrafos_inspeccion(string $texto, int $max_chars): string {
    // Palabras clave de inspección/prueba en español e inglés
    $keywords = [
        'inspect','inspecc','test','prueba','verif','accept','aceptac',
        'requir','requisit','norma','standard','code','codigo','criterio',
        'paint','pintura','recubrim','coating','NDT','NDE','radiograf',
        'ultrason','presion','pressure','weld','soldad','dimension',
        'certificad','certif','hold point','witness','review','reject',
        'rechazo','method','metodo','ensayo','medic','lectura','especi',
        'shall','debe','must','toleran','acabado','surface','superfic',
    ];

    $lineas   = preg_split('/\r?\n/', $texto);
    $total    = count($lineas);
    $marcadas = array_fill(0, $total, false);

    // Marcar líneas que contienen alguna keyword (case-insensitive)
    $pattern = '/(' . implode('|', array_map('preg_quote', $keywords)) . ')/iu';
    foreach ($lineas as $i => $linea) {
        if (preg_match($pattern, $linea)) {
            // Incluir 2 líneas de contexto antes y después
            for ($j = max(0, $i - 2); $j <= min($total - 1, $i + 2); $j++) {
                $marcadas[$j] = true;
            }
        }
    }

    // Reconstruir solo las líneas marcadas, con "..." entre bloques discontinuos
    $resultado   = '';
    $en_bloque   = false;
    foreach ($lineas as $i => $linea) {
        if ($marcadas[$i]) {
            $resultado .= $linea . "\n";
            $en_bloque  = true;
        } elseif ($en_bloque) {
            $resultado .= "...\n";
            $en_bloque  = false;
        }
        if (strlen($resultado) >= $max_chars) {
            $resultado .= "\n[TEXTO TRUNCADO — documento demasiado largo]";
            break;
        }
    }

    // Si el filtro no encontró nada relevante, devolver inicio del doc
    if (strlen(trim($resultado)) < 200) {
        return mb_substr($texto, 0, $max_chars, 'UTF-8') . "\n[TEXTO TRUNCADO]";
    }

    return trim($resultado);
}

// ── Contexto de documentos de referencia (solo modo plan) ───────────────────
$contexto_docs = '';
if ($modo === 'plan' && !empty($_POST['doc_ref_ids']) && strlen($_POST['doc_ref_ids']) <= 2000) {
    $doc_ids_raw = json_decode($_POST['doc_ref_ids'], true);
    if (is_array($doc_ids_raw) && !empty($doc_ids_raw)) {
        $doc_ids_raw = array_slice($doc_ids_raw, 0, 100);
        $doc_ids = array_filter(array_map('intval', $doc_ids_raw), function($id){ return $id > 0; });
        if (!empty($doc_ids)) {
            $placeholders = implode(',', array_fill(0, count($doc_ids), '?'));
            $db_ia = getDB();
            $stmt_docs = $db_ia->prepare(
                "SELECT nombre, idioma, texto_extraido FROM documentos_referencia" // nosemgrep
                . " WHERE id IN ($placeholders) AND estado = 'procesado' AND texto_extraido IS NOT NULL" // nosemgrep
            );
            $stmt_docs->execute(array_values($doc_ids));
            $docs_rows = $stmt_docs->fetchAll();

            // Límite fijo: 20 000 chars (~5 000 tokens) por documento.
            // Dev tier Groq: límite alto para capturar más requisitos del documento de referencia.
            $max_por_doc = 80000;

            $bloques = [];
            foreach ($docs_rows as $d) {
                $texto        = extraer_parrafos_inspeccion($d['texto_extraido'], $max_por_doc);
                $idioma_label = $d['idioma'] === 'en' ? 'English' : ($d['idioma'] === 'es' ? 'Espanol' : ($d['idioma'] ?? ''));
                $bloques[]    = "=== DOCUMENTO: " . $d['nombre'] . " (" . $idioma_label . ") ===\n" . $texto . "\n===";
            }

            if (!empty($bloques)) {
                $contexto_docs  = "Los siguientes documentos de referencia pueden estar en ingles o espanol. ";
                $contexto_docs .= "Usalos como contexto para generar el plan de inspeccion. ";
                $contexto_docs .= "El plan de inspeccion DEBE estar completamente en espanol, ";
                $contexto_docs .= "independientemente del idioma de los documentos fuente.\n\n";
                $contexto_docs .= implode("\n\n", $bloques) . "\n\n";
            }
        }
    }
}

// ── PROMPT MODO RESUMEN: extrae datos generales del proyecto desde la PO ─────
$prompt_resumen = <<<'PROMPT'
Eres un experto extractor de datos de Órdenes de Compra (PO/Purchase Order) de proyectos de ingeniería industrial en México.

EXTRAE ÚNICAMENTE estos campos del documento:
- numero_po: número de contrato, PO, pedido o referencia principal
- cliente: nombre completo de la empresa que emite la orden de compra
- descripcion: descripción principal del equipo, suministro o servicio a fabricar/entregar
- items: TODOS los ítems o partidas de la orden (busca en tablas, listas numeradas, cuadros)
- fecha_entrega: plazo o fecha de entrega (tal como aparece: "16 semanas", "01-Junio-2026", etc.)
- normas: códigos y normas técnicas aplicables mencionadas (ASME, API, ANSI, IEC, NOM, etc.)
- condiciones: condiciones de pago, garantía, penalizaciones, lugar de entrega
- observaciones: requisitos especiales, notas, instrucciones adicionales

Devuelve ÚNICAMENTE este JSON válido (sin markdown, sin texto adicional):
{"numero_po":"","cliente":"","descripcion":"","items":[{"item":"1","tag":"","cantidad":1,"unidad":"PZA","descripcion":""}],"fecha_entrega":"","normas":[{"codigo":"","descripcion":""}],"condiciones":"","observaciones":"","plan_inspeccion":[]}

REGLAS:
1. Si un dato no existe en el documento: null o [] según corresponda
2. Extrae TODOS los ítems de la tabla de suministro, incluyendo refacciones y accesorios.
   Identifica cada item por su número de renglón/fila en la tabla (columna "Item" o "Partida"),
   NO por cada componente, marca o modelo mencionado dentro del párrafo de descripción de un
   mismo renglón. Un renglón de tabla con una descripción larga que menciona varios componentes
   (ej. actuador + solenoides + interruptores) sigue siendo UN SOLO item — no lo separes.
   Las refacciones/repuestos casi siempre son su PROPIO renglón (item aparte) al final de la
   tabla — no los omitas ni los mezcles con el item anterior.
3. No inventes datos — solo extrae lo que está explícitamente en el documento
4. Si hay múltiples páginas, revisa todas para encontrar los items completos
PROMPT;

// ── PROMPT MODO PLAN: genera plan de inspección combinando OV + docs de referencia ──
$prompt_plan = <<<'PROMPT'
Eres un experto en planes de inspección y pruebas de proyectos de ingeniería industrial en México.
Tienes DOS fuentes de información:

FUENTE A — DOCUMENTOS DE REFERENCIA (normas/especificaciones técnicas del cliente):
Están incluidos al inicio de este prompt como "=== DOCUMENTO: ... ===".
Estos documentos definen TODOS los requisitos de inspección aplicables: métodos, criterios de aceptación,
sistemas de pintura, pruebas requeridas, documentos a entregar, puntos de hold/witness, etc.

FUENTE B — DOCUMENTO DE PROYECTO (OV/SPI/Cotización):
Está al final marcado como "--- DOCUMENTO ---".
Este documento describe el equipo o material ESPECÍFICO a fabricar/suministrar:
lista de ítems solicitados, modelos, tags, tamaños, presiones, temperaturas, conexiones, accesorios, etc.

LÓGICA DE GENERACIÓN DEL PLAN:
1. Lee FUENTE B para identificar QUÉ se va a fabricar o suministrar (cada ítem, material, accesorio).
2. Para CADA ítem/material encontrado en FUENTE B, busca en FUENTE A TODAS las inspecciones, pruebas
   y criterios que apliquen a ese tipo de equipo o material.
3. Genera un renglón del plan por cada combinación ítem+requisito que aplique.
4. Además, recorre FUENTE A completamente y agrega todos los requisitos de inspección que no hayas
   cubierto aún pero que apliquen al equipo descrito en FUENTE B.

Ejemplo: si FUENTE B dice "Válvula de bola 6 pulg. clase 2500" y FUENTE A tiene 30 requisitos para válvulas
(identificación, dimensional, materiales, 4 capas de pintura, actuador, pruebas de presión, documentación,
puntos de hold, etc.), debes generar un ítem del plan para CADA UNO de esos 30 requisitos aplicados a esa válvula.

El formato tiene estas columnas para cada ítem:
- item: número consecutivo (1, 2, 3...)
- requisito: descripción del requisito o característica a verificar
  (ej: "VALVULA DE BOLA 6 pulg. clase 2500 — Modelo N-BT-106K", "Espesor capa imprimante zinc-rich", "Prueba hidrostática asiento")
- referencia: fuente donde se indica el requisito (ej: "SPI Cot 636 pág 2" o "ENI 29000 Sec 4.3" o ambas separadas por " / ")
- metodo_norma: método de inspección Y norma (ej: "Visual / API 6D", "Positector / ISO 19840", "Hidrostática / ASME B16.34")
- criterios: criterios de aceptación ESPECÍFICOS y medibles — valores numéricos, rangos, normas concretas
  (ej: "Espesor ≥ 90 μm, DFT total ≥ 320 μm", "Sin fugas a 1.5× presión de diseño por 15 min")
- cumple: siempre "FALSO"
- comentarios: ""

CATEGORÍAS A CUBRIR (en este orden):
1. IDENTIFICACIÓN DEL EQUIPO: modelo, marca, número de serie, tag, número de ítem del SPI
2. CARACTERÍSTICAS DIMENSIONALES: tamaño nominal, rating, clase de presión, extremos (RTJ/FF/RF), face-to-face
3. MATERIALES: cuerpo, trim, sellos, empaquetaduras — verificar contra certificados MTR
4. PINTURA Y RECUBRIMIENTO: cada capa del sistema (imprimante, intermedia, acabado), espesores DFT, color, norma
5. ACCESORIOS: actuador, solenoides, switches de posición, filtro-regulador, posicionador, accesorios de la OV
6. PRUEBAS FUNCIONALES: actuación neumática, señal de control, stroking parcial (PST), SIL, OSAT
7. PRUEBAS DE PRESIÓN: hidrostática cuerpo, hidrostática asiento, prueba neumática de baja presión (seat leakage)
8. PUNTOS HOLD/WITNESS: todos los hold points y witness points definidos en FUENTE A
9. DOCUMENTACIÓN: MTR, datasheet, planos, certificados de calibración, reporte FAT, dossier de calidad

Devuelve ÚNICAMENTE este JSON válido (sin markdown, sin texto adicional).
IMPORTANTE: todos los ítems van en "plan_inspeccion". El array "items" queda VACÍO [].

{"numero_po":null,"cliente":null,"descripcion":null,"items":[],"fecha_entrega":null,"normas":[],"condiciones":null,"observaciones":null,"plan_inspeccion":[{"item":1,"requisito":"...","referencia":"...","metodo_norma":"...","criterios":"...","cumple":"FALSO","comentarios":""},{"item":2,"requisito":"...","referencia":"...","metodo_norma":"...","criterios":"...","cumple":"FALSO","comentarios":""}]}

REGLAS:
1. NUNCA colapses varios requisitos en un solo ítem. Cada combinación [componente + requisito específico] es un ítem separado.
   MAL: "Pintura válvula: Sistema 12, DFT total ≥ 320 µm" (colapsa todo el sistema en 1 ítem)
   BIEN: 5 ítems: (a) preparación superficie Sa3, (b) espesor capa imprimante, (c) espesor capa intermedia, (d) espesor + color capa acabado, (e) DFT total + adherencia
2. Mínimo 35 ítems en total — cubre TODAS las categorías listadas arriba.
   Cada sistema de pintura (Sistema 12, Sistema 4, etc.) genera mínimo 5 ítems independientes.
   Cada prueba de presión (cuerpo hidrostática, asiento hidrostática, asiento neumática) es un ítem separado.
3. Para los sistemas de pintura de FUENTE A, genera en este orden por sistema:
   (a) Preparación de superficie: norma Sa, perfil de rugosidad, contenido de sal
   (b) Condiciones de aplicación: temperatura, humedad, punto de rocío
   (c) Capa imprimante: DFT mínimo, tipo de producto, tiempo entre capas
   (d) Capa(s) intermedia(s): DFT mínimo por capa, tipo
   (e) Capa de acabado: DFT mínimo, color RAL, brillo
   (f) DFT total verificado con Positector según ISO 19840
   (g) Prueba de adherencia según ASTM D4541 o ISO 4624
4. Los criterios deben ser ESPECÍFICOS y medibles: valores numéricos, rangos, normas concretas.
   NUNCA uses solo "Sin daños visibles" o "Funcionamiento correcto" sin criterio cuantitativo.
5. En "referencia" cita siempre la FUENTE REAL: sección del doc de referencia O "Cot XXX pág Y" O ambos separados con " / ".
6. "cumple" siempre "FALSO".
7. NUNCA uses el símbolo " (comilla doble) dentro de los textos — escribe pulg. para pulgadas (ej: 6 pulg.).
8. Detente cuando hayas cubierto todos los requisitos aplicables. No inventes ítems sin base en los documentos.
PROMPT;

$prompt_instruccion = ($modo === 'plan') ? $contexto_docs . $prompt_plan : $prompt_resumen;

// ─────────────────────────────────────────────────────────────────
//  PROVEEDOR: ANTHROPIC
// ─────────────────────────────────────────────────────────────────
if ($provider === 'anthropic') {

    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'ANTHROPIC_API_KEY no configurada en config.php']);
        exit;
    }

    $content_blocks = [['type'=>'text','text'=>$prompt_instruccion]];

    if ($tiene_paginas) {
        // Páginas renderizadas por PDF.js → imágenes base64
        $paginas = json_decode($_POST['paginas_pdf'], true) ?? [];
        foreach ($paginas as $b64) {
            $content_blocks[] = ['type'=>'image','source'=>['type'=>'base64','media_type'=>'image/jpeg','data'=>$b64]];
        }
    } else {
        $base64_data = base64_encode(file_get_contents($file['tmp_name']));
        $media_type_map = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
                           'png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        $media_type = $media_type_map[$ext] ?? $file['type'];
        $content_blocks[] = $is_pdf
            ? ['type'=>'document','source'=>['type'=>'base64','media_type'=>$media_type,'data'=>$base64_data]]
            : ['type'=>'image',   'source'=>['type'=>'base64','media_type'=>$media_type,'data'=>$base64_data]];
    }

    $payload = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 4096,
        'messages'   => [['role'=>'user','content'=>$content_blocks]]
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

    // Groq descontinuó meta-llama/llama-4-scout-17b-16e-instruct (visión) y qwen/qwen3-32b
    // (texto) -- confirmado en vivo (2026-08-25/26), ambos daban 404 model_not_found.
    // Se mantiene el diseño original de modelo separado por tipo de tarea (rate-limit
    // propio por modelo en Groq + más barato): texto -> gpt-oss-120b, visión -> qwen3.6-27b
    // (único modelo multimodal del catálogo). Ver nota de reasoning_effort más abajo.
    $model = 'qwen/qwen3.6-27b';

    // ── 1ª opción: texto del OCR de Tesseract.js (ya extraído en el browser, 0 tokens extra) ──
    $texto_extraido = ($texto_ocr !== '') ? mb_substr($texto_ocr, 0, 12000) : '';

    // ── 2ª opción: PyMuPDF para PDFs con texto nativo ──
    if (empty($texto_extraido)) {
        $pdf_path = (!empty($_FILES['pdf_original']) && $_FILES['pdf_original']['error'] === 0)
            ? $_FILES['pdf_original']['tmp_name']
            : (($tiene_archivo && $is_pdf) ? $file['tmp_name'] : null);

        if ($pdf_path) {
            $texto_extraido = extraer_texto_pymupdf($pdf_path);
            if (empty($texto_extraido)) {
                $txt = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ',
                    mb_convert_encoding(extraer_texto_pdf($pdf_path) ?? '', 'UTF-8', 'UTF-8')));
                if (texto_es_legible($txt)) $texto_extraido = mb_substr($txt, 0, 12000);
            }
        }
    }

    if ($texto_extraido !== '') {
        // ── PDF con texto nativo: modo texto (mínimo de tokens) ──────
        $model = 'openai/gpt-oss-120b';
        $messages = [['role' => 'user', 'content' =>
            $prompt_instruccion . "\n\n--- DOCUMENTO ---\n" . $texto_extraido]];

    } elseif ($tiene_paginas) {
        // ── PDF imagen: enviar páginas JPEG (visión) ──────────────────
        $paginas = json_decode($_POST['paginas_pdf'], true) ?? [];
        $content = [['type'=>'text','text'=>$prompt_instruccion]];
        foreach ($paginas as $b64) {
            $content[] = ['type'=>'image_url','image_url'=>['url'=>'data:image/jpeg;base64,'.$b64]];
        }
        $messages = [['role'=>'user','content'=>$content]];

    } elseif ($is_image) {
        // ── Imagen directa (JPG/PNG) ──────────────────────────────────
        $base64_data = base64_encode(file_get_contents($file['tmp_name']));
        $mt_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'];
        $media_type = $mt_map[$ext] ?? 'image/jpeg';
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $prompt_instruccion],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $media_type . ';base64,' . $base64_data]],
        ]]];

    } else {
        echo json_encode(['success' => false,
            'error' => 'No se pudo leer el PDF. Intenta tomar una captura (Win+Shift+S) y subirla como PNG.']);
        exit;
    }

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.1,
        // qwen/qwen3.6-27b rechaza max_tokens > 16384 (era 32768 con el modelo viejo) --
        // confirmado en vivo, Groq responde 400 "max_tokens must be less than or equal to
        // 16384" si se deja el valor anterior. gpt-oss-120b soporta hasta 65536 pero no hace
        // falta más aquí.
        'max_tokens'  => 16000,
        'stream'      => false,
        // Ambos son modelos "thinking". reasoning_effort SÍ evita que se generen tokens de
        // razonamiento (a diferencia de reasoning_format:hidden, que los genera igual y solo
        // los oculta del content) -- confirmado en vivo: 'none' bajó una extracción real de
        // ~2900 a 278 tokens en qwen3.6-27b con la misma calidad de salida. gpt-oss-120b no
        // soporta 'none', su mínimo es 'low'; el razonamiento sale en message.reasoning
        // (campo aparte, no contamina el JSON de content).
        'reasoning_effort' => ($model === 'openai/gpt-oss-120b') ? 'low' : 'none',
    ];

    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload_json === false) {
        echo json_encode(['success' => false, 'error' => 'Error al codificar prompt: ' . json_last_error_msg()]);
        exit;
    }

    $result = llamar_api(
        'https://api.groq.com/openai/v1/chat/completions',
        $payload_json,
        ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]
    );

    if ($result['error']) {
        // El detalle real (host, error de curl) va al log, no a la respuesta -- mismo criterio
        // ya usado más abajo para errores de JSON de la IA (no exponer detalle interno del backend).
        file_put_contents(
            __DIR__ . '/../logs/ia_debug.log',
            date('Y-m-d H:i:s') . " [CURL_ERROR] " . $result['error'] . "\n",
            FILE_APPEND
        );
        echo json_encode(['success' => false, 'error' => 'No se pudo conectar con el servicio de IA. Intenta de nuevo en unos minutos.']); // nosemgrep
        exit;
    }

    $resp = json_decode($result['body'], true);

    if (empty($resp['choices'][0]['message']['content'])) {
        $api_err = $resp['error']['message'] ?? ('HTTP ' . $result['status'] . ' — ' . mb_substr($result['body'], 0, 300));
        file_put_contents(
            __DIR__ . '/../logs/ia_debug.log',
            date('Y-m-d H:i:s') . " [GROQ_ERROR] " . $api_err . "\n",
            FILE_APPEND
        );
        echo json_encode(['success' => false, 'error' => 'El servicio de IA no pudo procesar la solicitud. Intenta de nuevo o revisa el archivo.']); // nosemgrep
        exit;
    }

    $texto_ia      = $resp['choices'][0]['message']['content'];
    $finish_reason = $resp['choices'][0]['finish_reason'] ?? '';
    // Log temporal de depuración — borrar cuando el plan funcione correctamente
    $log_chars = strlen($texto_ia);
    file_put_contents(
        __DIR__ . '/../logs/ia_debug.log',
        date('Y-m-d H:i:s') . " [modo=$modo finish=$finish_reason tokens=" . ($resp['usage']['total_tokens'] ?? '?') . " chars=$log_chars]\n"
        . mb_substr($texto_ia, 0, 15000) . "\n---\n",
        FILE_APPEND
    );

// ─────────────────────────────────────────────────────────────────
//  PROVEEDOR: GOOGLE GEMINI FLASH (gratuito)
// ─────────────────────────────────────────────────────────────────
} elseif ($provider === 'gemini') {

    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'error' => 'GEMINI_API_KEY no configurada (gratis en aistudio.google.com/apikey)']);
        exit;
    }

    // Construir partes del contenido
    $parts = [['text' => $prompt_instruccion]];

    if ($tiene_paginas) {
        // Páginas enviadas como imágenes JPEG desde PDF.js
        $paginas = json_decode($_POST['paginas_pdf'], true) ?? [];
        foreach ($paginas as $b64) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $b64]];
        }
    } else {
        // Archivo directo (PDF o imagen)
        $media_type_map = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'webp' => 'image/webp', 'gif' => 'image/gif',
        ];
        $media_type   = $media_type_map[$ext] ?? $file['type'];
        $base64_data  = base64_encode(file_get_contents($file['tmp_name']));
        $parts[]      = ['inline_data' => ['mime_type' => $media_type, 'data' => $base64_data]];
    }

    $payload = [
        'contents'         => [['parts' => $parts]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 4096],
    ];

    $gemini_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $api_key;
    $result     = llamar_api($gemini_url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);

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
Extrae los datos de este documento de proyecto industrial. Devuelve SOLO este JSON (sin markdown, sin explicaciones):
{"numero_po":"número de PO o especificación","cliente":"nombre del cliente","descripcion":"descripción del suministro principal","items":[{"item":"1","tag":"","cantidad":1,"unidad":"PZA","descripcion":"descripción completa del ítem"}],"fecha_entrega":"plazo o fecha tal como aparece","normas":[{"codigo":"ASME B16.34","descripcion":"Válvulas"}],"condiciones":"condiciones relevantes","observaciones":"notas importantes","plan_inspeccion":[]}
Reglas: null o [] si no existe el dato. Extrae todos los ítems de tablas o listas. El plan_inspeccion solo si hay especificación técnica explícita.
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
            $messages = [['role' => 'user', 'content' => $prompt_json . "\n\nDOCUMENTO:\n" . mb_substr($texto_limpio, 0, 4000)]];

        } else {
            // PDF sin texto (escaneado / visual) → convertir a imagen y usar visión
            $img_tmp = pdf_a_imagen($file['tmp_name']);

            if ($img_tmp) {
                $base64_img = base64_encode(file_get_contents($img_tmp));
                @unlink($img_tmp); // nosemgrep
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
        'max_tokens'  => 2048,
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
    echo json_encode(['success' => false, 'error' => 'AI_PROVIDER no válido en config.php (usa "anthropic", "groq", "gemini" o "lmstudio")']);
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

// Reparar comillas de pulgadas sin escapar dentro de strings JSON.
// El modelo frecuentemente escribe 6" en lugar de 6 pulg. rompiendo el JSON.
// Patrones ordenados de más seguro a más agresivo:

// A) digit" seguido de coma/punto y coma + letra o dígito (ej: 6", clase  o  6", 8")
$texto_ia = preg_replace('/(\d)"([,;]\s*[\da-zA-Z\xc0-\xff])/', '$1 pulg.$2', $texto_ia);
// B) digit" seguido de espacio(s) + letra o dígito (ej: 6" de largo  o  6" 2500)
$texto_ia = preg_replace('/(\d)"\s+([\da-zA-Z\xc0-\xff])/', '$1 pulg. $2', $texto_ia);
// C) digit" seguido directamente por letra sin separador (ej: 1/2"NPT, 6"RF)
$texto_ia = preg_replace('/(\d)"([a-zA-Z\xc0-\xff])/', '$1 pulg.$2', $texto_ia);
// D) digit"" doble cierre — inch mark justo antes del cierre del string (ej: 10"")
$texto_ia = preg_replace('/(\d)""/', '$1 pulg."', $texto_ia);

$datos_proyecto = json_decode($texto_ia, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos_proyecto)) {
    // Loguear el fragmento que falla y el mensaje de error para diagnóstico
    file_put_contents(
        __DIR__ . '/../logs/ia_debug.log',
        date('Y-m-d H:i:s') . " [JSON_ERROR] " . json_last_error_msg() . "\n"
        . "--- TEXTO_IA (primeros 6000) ---\n" . mb_substr($texto_ia, 0, 6000) . "\n--- FIN ---\n",
        FILE_APPEND
    );
    echo json_encode(['success' => false, 'error' => 'La IA no devolvió datos válidos. Intenta con otro archivo o revisa que el modelo esté bien cargado.']); // nosemgrep
    exit;
}
file_put_contents(
    __DIR__ . '/../logs/ia_debug.log',
    date('Y-m-d H:i:s') . " [CHECKPOINT] json_decode OK — plan_items=" . count($datos_proyecto['plan_inspeccion'] ?? []) . "\n",
    FILE_APPEND
);

// Fallback modo plan: si el modelo puso los requisitos de inspección en "items"
// (se detecta porque tienen el campo "requisito" o "cumple" en lugar de "tag"/"cantidad"),
// moverlos a "plan_inspeccion" y dejar "items" vacío.
if ($modo === 'plan'
    && empty($datos_proyecto['plan_inspeccion'])
    && !empty($datos_proyecto['items'])
    && is_array($datos_proyecto['items'])
    && isset($datos_proyecto['items'][0])
    && (array_key_exists('requisito', $datos_proyecto['items'][0]) || array_key_exists('cumple', $datos_proyecto['items'][0]))
) {
    $datos_proyecto['plan_inspeccion'] = $datos_proyecto['items'];
    $datos_proyecto['items']           = [];
}

// ─────────────────────────────────────────────────────────────────
//  Sanitizar campos de texto
// ─────────────────────────────────────────────────────────────────
$campos_texto = ['numero_po', 'cliente', 'descripcion', 'fecha_entrega', 'condiciones', 'observaciones'];
foreach ($campos_texto as $campo) {
    if (isset($datos_proyecto[$campo]) && $datos_proyecto[$campo] !== null) {
        $datos_proyecto[$campo] = htmlspecialchars(strip_tags($datos_proyecto[$campo]), ENT_QUOTES, 'UTF-8');
    }
}

// Sanitizar items
if (!empty($datos_proyecto['items']) && is_array($datos_proyecto['items'])) {
    foreach ($datos_proyecto['items'] as &$item) {
        $item['item']      = htmlspecialchars(strip_tags($item['item']      ?? ''), ENT_QUOTES, 'UTF-8');
        $item['tag']       = htmlspecialchars(strip_tags($item['tag']       ?? ''), ENT_QUOTES, 'UTF-8');
        $item['unidad']    = htmlspecialchars(strip_tags($item['unidad']    ?? ''), ENT_QUOTES, 'UTF-8');
        $item['descripcion'] = htmlspecialchars(strip_tags($item['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $item['cantidad']  = is_numeric($item['cantidad'] ?? null) ? floatval($item['cantidad']) : null;
    }
    unset($item);
}

// Sanitizar normas — el modelo puede retornar array plano ["ASME","API"] o array de objetos [{"codigo":"","descripcion":""}]
if (!empty($datos_proyecto['normas']) && is_array($datos_proyecto['normas'])) {
    foreach ($datos_proyecto['normas'] as &$norma) {
        if (is_array($norma)) {
            $norma['codigo']      = htmlspecialchars(strip_tags($norma['codigo']      ?? ''), ENT_QUOTES, 'UTF-8');
            $norma['descripcion'] = htmlspecialchars(strip_tags($norma['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8');
        } else {
            $norma = htmlspecialchars(strip_tags((string)$norma), ENT_QUOTES, 'UTF-8');
        }
    }
    unset($norma);
}

// Sanitizar plan de inspección
if (!empty($datos_proyecto['plan_inspeccion']) && is_array($datos_proyecto['plan_inspeccion'])) {
    foreach ($datos_proyecto['plan_inspeccion'] as &$paso) {
        $paso['no']               = is_numeric($paso['no'] ?? null) ? intval($paso['no']) : null;
        $paso['actividad']        = htmlspecialchars(strip_tags($paso['actividad']        ?? ''), ENT_QUOTES, 'UTF-8');
        $paso['criterio']         = htmlspecialchars(strip_tags($paso['criterio']         ?? ''), ENT_QUOTES, 'UTF-8');
        $paso['norma']            = htmlspecialchars(strip_tags($paso['norma']            ?? ''), ENT_QUOTES, 'UTF-8');
        $paso['tipo_prueba']      = htmlspecialchars(strip_tags($paso['tipo_prueba']      ?? ''), ENT_QUOTES, 'UTF-8');
        $paso['responsable']      = htmlspecialchars(strip_tags($paso['responsable']      ?? ''), ENT_QUOTES, 'UTF-8');
        $paso['resultado_esperado'] = htmlspecialchars(strip_tags($paso['resultado_esperado'] ?? ''), ENT_QUOTES, 'UTF-8');
    }
    unset($paso);
}

// ─────────────────────────────────────────────────────────────────
//  Retornar datos sin guardar en BD
// ─────────────────────────────────────────────────────────────────
file_put_contents(
    __DIR__ . '/../logs/ia_debug.log',
    date('Y-m-d H:i:s') . " [CHECKPOINT] Llegó al json_encode final — mem=" . round(memory_get_usage()/1024/1024,1) . "MB\n",
    FILE_APPEND
);
$json_out = json_encode(
    ['success' => true, 'datos' => $datos_proyecto],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
if ($json_out === false) {
    // UTF-8 inválido u otro error de codificación — limpiar y reintentar
    array_walk_recursive($datos_proyecto, function (&$v) {
        if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    });
    $json_out = json_encode(
        ['success' => true, 'datos' => $datos_proyecto],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
if ($json_out === false) {
    echo json_encode(['success' => false, 'error' => 'Error al codificar respuesta: ' . json_last_error_msg()]);
} else {
    echo $json_out; // nosemgrep
}
exit;

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
    $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'proy_' . uniqid() . '.png';

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
        @exec($cmd, $o, $code); // nosemgrep
        if ($code === 0 && file_exists($out) && filesize($out) > 1000) return $out;
    }

    // ── Método 3: mutool (MuPDF) ───────────────────────────────────
    $mutool_candidates = ['mutool', 'C:\\Program Files\\MuPDF\\mutool.exe'];
    foreach ($mutool_candidates as $mt) {
        $cmd = $mt . ' draw -F png -o ' . escapeshellarg($out) . ' '
             . escapeshellarg($pdf_path) . ' 1 2>&1';
        @exec($cmd, $o, $code); // nosemgrep
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
            $msg = 'No se pudo conectar con el servicio en ' . $url . '. '
                 . 'Verifica tu conexión o configuración. Detalle: ' . $curl_err;
            return ['error' => $msg, 'body' => '', 'status' => 0];
        }

        // Rate limit (429) o error de servidor (5xx) → reintentar con espera
        if ($http_code === 429 || $http_code >= 500) {
            if ($intento < $max_reintentos) {
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
            return ['error' => 'Rate limit: ' . $msg_error, 'body' => $response, 'status' => $http_code];
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
function extraer_texto_pymupdf(string $path): string {
    if (!defined('PYTHON_PATH') || !defined('PYMUPDF_SCRIPT')) return '';
    if (!file_exists(PYTHON_PATH) || !file_exists(PYMUPDF_SCRIPT)) return '';

    $cmd  = escapeshellarg(PYTHON_PATH) . ' ' . escapeshellarg(PYMUPDF_SCRIPT) . ' ' . escapeshellarg($path);
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $proc  = proc_open($cmd, $desc, $pipes); // nosemgrep
    if (!is_resource($proc)) return '';

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $texto = ''; $t = microtime(true);
    while (microtime(true) - $t < 8.0) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') $texto .= $chunk;
        if (feof($pipes[1])) break;
        usleep(50000);
    }

    fclose($pipes[1]); fclose($pipes[2]);
    $st = proc_get_status($proc);
    if ($st['running']) proc_terminate($proc);
    proc_close($proc);

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
    @exec($pdftotext . ' -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>&1', $o, $c); // nosemgrep
    if ($c === 0 && file_exists($tmp)) {
        $t = file_get_contents($tmp); @unlink($tmp); // nosemgrep
        if (strlen(trim($t)) > 30) return $t;
    }
    @unlink($tmp); // nosemgrep

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
