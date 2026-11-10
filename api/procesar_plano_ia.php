<?php
// Silenciar errores PHP ANTES de cualquier include para que no contaminen el JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Shutdown handler: si el script muere sin output, devuelve JSON de error
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'PHP fatal: '.$err['message'].' en '.$err['file'].':'.$err['line']]);
    }
});

ob_start();
require_once __DIR__ . '/../includes/config.php';
ob_end_clean(); // Limpiar cualquier output de config.php y TERMINAR el buffer

set_time_limit(300);
header('Content-Type: application/json');

if (!isLoggedIn() || (!hasAcceso('manufactura') && !isAdmin())) { echo json_encode(['success'=>false,'error'=>'No autorizado']); exit; }

$boom_id    = intval($_POST['boom_id'] ?? 0);
$texto_ocr  = trim($_POST['texto_ocr'] ?? '');
$tiene_pags = !empty($_POST['paginas_pdf']);
$tiene_arch = !empty($_FILES['archivo']) && ($_FILES['archivo']['error'] ?? 1) === 0;

if (!$boom_id) { echo json_encode(['success'=>false,'error'=>'boom_id requerido']); exit; }
if (!$texto_ocr && !$tiene_pags && !$tiene_arch) {
    echo json_encode(['success'=>false,'error'=>'No se recibió contenido del plano']); exit;
}

$db   = getDB();
$file = $_FILES['archivo'] ?? ['name'=>'doc.txt','type'=>'text/plain','tmp_name'=>'','error'=>0,'size'=>0];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$is_pdf   = ($ext === 'pdf' || ($file['type'] ?? '') === 'application/pdf');
$is_image = in_array($ext, ['jpg','jpeg','png','webp']);

// ── PROMPT ────────────────────────────────────────────────────────────────────
$prompt = <<<'PROMPT'
Eres un experto en lectura de planos de manufactura e ingeniería industrial en México.

Tu tarea: extraer TODAS las listas de materiales (BOM - Bill of Materials) que aparezcan en el documento.

── FORMATO COMPUESTO (el más común en estos planos) ──
La columna DESCRIPCIÓN contiene todo junto en este orden:
  DESCRIPCION_REAL, MARCA, MODELO

El símbolo " (pulgadas) dentro de una descripción NO es separador. Ejemplos reales de este plano:

  "CAJA DE CONEXIONES ELÉCTRICA, WAROM, BXT-III-W"
  → descripcion: "CAJA DE CONEXIONES ELÉCTRICA"   marca: "WAROM"      modelo: "BXT-III-W"

  "CLEMA DE DOBLE PISO, PASO 2.5MM, KLEMSAN, SRD2.5-2F"
  → descripcion: "CLEMA DE DOBLE PISO, PASO 2.5MM"  marca: "KLEMSAN"   modelo: "SRD2.5-2F"

  "INTERRUPTOR TERMOMAGNETICO 4A, EATON, PLS6-C4-DC-MW"
  → descripcion: "INTERRUPTOR TERMOMAGNETICO 4A"   marca: "EATON"      modelo: "PLS6-C4-DC-MW"

  "CLEMA PORTAFUSIBLE CON FUSIBLE, KLEMSAN, YBK SLD"
  → descripcion: "CLEMA PORTAFUSIBLE CON FUSIBLE"  marca: "KLEMSAN"    modelo: "YBK SLD"

  "TOPE PARA CLEMAS, KLEMSAN, KD3"
  → descripcion: "TOPE PARA CLEMAS"               marca: "KLEMSAN"    modelo: "KD3"

  "RIEL DIN 7" DE LONG."         → descripcion: "RIEL DIN 7\" DE LONG."   marca: ""   modelo: ""
  "RIEL DIN 4.5" DE LONG."       → descripcion: "RIEL DIN 4.5\" DE LONG." marca: ""   modelo: ""
  "TORNILLO CABEZA DE GOTA ALLEN 3/16" - 24 UNC X 1/4" DE LONG. ACERO INOXIDABLE"
  → descripcion: (todo el texto)  marca: ""   modelo: ""

REGLAS DE PARSEO:
1. Leer los tokens separados por coma de derecha a izquierda
2. Si el último token es un código de parte (letras+números+guiones, sin espacios o con pocos) → es el MODELO
3. Si el penúltimo token es un nombre de empresa/fabricante (una sola palabra en mayúsculas, marca conocida) → es la MARCA
4. Todo lo anterior es la DESCRIPCION (puede contener comas si la descripción técnica las requiere)
5. Si no se detecta patrón MARCA+MODELO, dejar ambos vacíos y poner todo en descripcion
6. El símbolo " (pulgadas) dentro de cualquier campo NO es separador
7. Si la tabla ya tiene columnas separadas MARCA / MODELO, usarlas directamente

IMPORTANTE:
- Puede haber UNO o VARIOS BOMs en distintas hojas
- Extrae SOLO lo que está en el documento — NO inventes materiales
- No uses entidades HTML (&quot; &amp; etc.), escribe los caracteres directamente

Devuelve ÚNICAMENTE este JSON válido (sin markdown, sin texto adicional):
{
  "boms": [
    {
      "hoja": "1",
      "titulo": "BOM hoja 1",
      "materiales": [
        {
          "no": "1",
          "descripcion": "Descripción del material",
          "cantidad": 1,
          "unidad": "PZA",
          "marca": "",
          "modelo": "",
          "norma": ""
        }
      ]
    }
  ]
}

REGLAS JSON:
1. cantidad siempre como número
2. Si no hay marca o modelo dejar cadena vacía ""
3. Sin BOM en el documento: {"boms":[],"sin_bom":true}
PROMPT;

// ── SELECCIÓN PROVEEDOR ────────────────────────────────────────────────────────
$provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'groq';
$texto_ia = null;

// Función helper — misma lógica que analizar_proyecto_ia.php (con reintentos e IPv4 forzado)
function llamar_api(string $url, string $body, array $headers, int $max_reintentos = 2): array {
    $espera = 5;
    for ($intento = 1; $intento <= $max_reintentos; $intento++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            return ['error' => 'CURL: '.$curl_err, 'body' => '', 'status' => 0];
        }
        if ($http_code === 429 || $http_code >= 500) {
            if ($intento < $max_reintentos) { sleep($espera); $espera *= 2; continue; }
        }
        return ['error' => null, 'body' => $response ?: '', 'status' => $http_code];
    }
    return ['error' => 'Sin respuesta tras reintentos', 'body' => '', 'status' => 0];
}

// Función de extracción con PyMuPDF
function extraer_texto_plano(string $pdf_path): string {
    if (!defined('PYTHON_PATH') || !defined('PYMUPDF_SCRIPT')) return '';
    if (!file_exists($pdf_path) || !file_exists(PYMUPDF_SCRIPT)) return '';
    $cmd    = escapeshellcmd(PYTHON_PATH) . ' ' . escapeshellarg(PYMUPDF_SCRIPT) . ' ' . escapeshellarg($pdf_path) . ' 2>&1';
    $output = ''; $ret = 0;
    exec($cmd, $lines, $ret);
    return implode("\n", $lines);
}

if ($provider === 'groq') {
    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    if (!$api_key) { echo json_encode(['success'=>false,'error'=>'GROQ_API_KEY no configurada']); exit; }

    // Groq descontinuó llama-3.3-70b-versatile y meta-llama/llama-4-scout-17b-16e-instruct --
    // confirmado en vivo (2026-08-25/26), ambos daban 404 model_not_found. Modelo separado por
    // tarea (rate-limit propio por modelo en Groq + más barato): texto -> gpt-oss-120b,
    // visión -> qwen3.6-27b (único multimodal del catálogo). Ver reasoning_effort más abajo.
    $model    = 'qwen/qwen3.6-27b';
    $messages = null;

    // Opción 1: texto OCR del browser (0 tokens extra)
    if ($texto_ocr !== '' && strlen($texto_ocr) > 50) {
        $model    = 'openai/gpt-oss-120b';
        $messages = [['role'=>'user','content'=> $prompt . "\n\n--- PLANO (texto extraído) ---\n" . mb_substr($texto_ocr, 0, 15000)]];

    // Opción 2: PyMuPDF texto nativo
    } elseif ($is_pdf && $tiene_arch) {
        $txt = extraer_texto_plano($file['tmp_name']);
        if (strlen($txt) > 50) {
            $model    = 'openai/gpt-oss-120b';
            $messages = [['role'=>'user','content'=> $prompt . "\n\n--- PLANO (texto nativo) ---\n" . mb_substr($txt, 0, 15000)]];
        }
    }

    // Opción 3: páginas JPEG (visión)
    if (!$messages && $tiene_pags) {
        $paginas = json_decode($_POST['paginas_pdf'], true) ?? [];
        $content = [['type'=>'text','text'=>$prompt]];
        foreach ($paginas as $b64) {
            $content[] = ['type'=>'image_url','image_url'=>['url'=>'data:image/jpeg;base64,'.$b64]];
        }
        $messages = [['role'=>'user','content'=>$content]];
    }

    // Opción 4: imagen directa
    if (!$messages && $is_image && $tiene_arch) {
        $b64     = base64_encode(file_get_contents($file['tmp_name']));
        $mtype   = $file['type'] ?? 'image/jpeg';
        $messages = [['role'=>'user','content'=>[
            ['type'=>'text','text'=>$prompt],
            ['type'=>'image_url','image_url'=>['url'=>"data:$mtype;base64,$b64"]],
        ]]];
    }

    if (!$messages) { echo json_encode(['success'=>false,'error'=>'No hay contenido para analizar']); exit; }

    // Ambos son modelos "thinking". reasoning_effort SÍ evita que se generen tokens de
    // razonamiento (a diferencia de reasoning_format:hidden, que los genera igual y solo los
    // oculta) -- confirmado en vivo. gpt-oss-120b no soporta 'none', su mínimo es 'low'; el
    // razonamiento sale en message.reasoning (campo aparte, no contamina el content).
    $payload = ['model'=>$model,'messages'=>$messages,'temperature'=>0.1,'max_tokens'=>8192,'stream'=>false,'reasoning_effort'=>($model==='openai/gpt-oss-120b'?'low':'none')];
    $result = llamar_api(
        'https://api.groq.com/openai/v1/chat/completions',
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]
    );

    if ($result['error']) {
        echo json_encode(['success'=>false,'error'=>$result['error']]); exit;
    }

    $resp = json_decode($result['body'], true);
    if (empty($resp['choices'][0]['message']['content'])) {
        $api_err = $resp['error']['message'] ?? ('HTTP '.$result['status'].' — '.mb_substr($result['body'],0,300));
        echo json_encode(['success'=>false,'error'=>'Groq: '.$api_err]); exit;
    }
    $texto_ia = $resp['choices'][0]['message']['content'];

} else {
    echo json_encode(['success'=>false,'error'=>"Proveedor '$provider' no soportado para planos. Usa Groq."]); exit;
}

// ── PARSEAR RESPUESTA ─────────────────────────────────────────────────────────
$texto_ia = trim($texto_ia);
// Limpiar markdown si viene
$texto_ia = preg_replace('/^```(?:json)?\s*/i', '', $texto_ia);
$texto_ia = preg_replace('/\s*```$/i', '', $texto_ia);

$datos = json_decode($texto_ia, true);
if (!is_array($datos)) {
    echo json_encode(['success'=>false,'error'=>'La IA no devolvió JSON válido','raw'=>substr($texto_ia,0,500)]); exit;
}

// ── MODO SOLO EXTRAER: devolver JSON sin guardar en BD ───────────────────────
if (!empty($_POST['solo_extraer'])) {
    echo json_encode(['success'=>true, 'solo_extraer'=>true, 'datos'=>$datos]);
    exit;
}

// ── GUARDAR BOMs EN BD ────────────────────────────────────────────────────────
// Limpiar BOMs IA anteriores de este plano antes de guardar los nuevos
$db->prepare("DELETE bi FROM boom_items bi JOIN boom_listas bl ON bi.boom_id = bl.id WHERE bl.padre_boom_id = ?")
   ->execute([$boom_id]);
$db->prepare("DELETE FROM boom_listas WHERE padre_boom_id = ?")
   ->execute([$boom_id]);

$boms_guardados = 0;
if (!empty($datos['boms']) && is_array($datos['boms'])) {
    foreach ($datos['boms'] as $bom) {
        if (empty($bom['materiales'])) continue;
        $hoja   = sanitize($bom['hoja'] ?? '1');
        $titulo = sanitize($bom['titulo'] ?? "BOM hoja $hoja");

        // Obtener proyecto_id del boom original (siempre)
        $stmtProy = $db->prepare("SELECT proyecto_id, pieza_nombre FROM boom_listas WHERE id = ?");
        $stmtProy->execute([$boom_id]);
        $boom_orig = $stmtProy->fetch();
        $proy_id   = $boom_orig['proyecto_id'] ?? null;
        $pieza     = $boom_orig['pieza_nombre'] ?? 'Pieza';

        // Crear boom_lista nueva para esta hoja (los anteriores ya fueron borrados arriba)
        $db->prepare("INSERT INTO boom_listas (proyecto_id, nombre, num_equipos, creado_por, fecha_creacion, guardado, tipo_lista, pieza_nombre, hoja_plano, padre_boom_id)
                      VALUES (?,?,1,?,NOW(),0,'plano_ia',?,?,?)")
           ->execute([$proy_id, "$pieza — BOM Hoja $hoja", $_SESSION['user_id'], "$pieza — Hoja $hoja", $hoja, $boom_id]);
        $new_boom_id = (int)$db->lastInsertId();
        $boom_target_id = $new_boom_id;

        // Insertar materiales
        $stmtMatch = $db->prepare(
            "SELECT id, descripcion, marca, codigo FROM materiales
             WHERE activo = 1
               AND LOWER(TRIM(marca))  = LOWER(TRIM(?))
               AND LOWER(TRIM(codigo)) = LOWER(TRIM(?))
             LIMIT 1"
        );
        $stmtMatchDesc = $db->prepare(
            "SELECT id, descripcion, marca, codigo FROM materiales
             WHERE activo = 1
               AND LOWER(TRIM(marca))       = LOWER(TRIM(?))
               AND LOWER(TRIM(descripcion)) LIKE LOWER(CONCAT('%', TRIM(?), '%'))
             LIMIT 1"
        );
        $stmtFuzzyMarca = $db->prepare(
            "SELECT id, descripcion, marca, codigo FROM materiales
             WHERE activo = 1 AND LOWER(TRIM(marca)) = LOWER(TRIM(?))
             LIMIT 300"
        );
        $stmtFuzzyDesc = $db->prepare(
            "SELECT id, descripcion, marca, codigo FROM materiales
             WHERE activo = 1 AND LOWER(descripcion) LIKE LOWER(?)
             LIMIT 300"
        );

        foreach ($bom['materiales'] as $idx => $mat) {
            // Para campos BOM usamos trim+strip_tags sin htmlspecialchars para preservar " y otros caracteres
            $clean  = function($v) { return trim(strip_tags((string)$v)); };
            $desc   = $clean($mat['descripcion'] ?? '');
            $cant   = floatval($mat['cantidad'] ?? 1);
            $unidad = $clean($mat['unidad'] ?? 'PZA') ?: 'PZA';
            $marca  = $clean($mat['marca'] ?? '');
            $modelo = $clean($mat['modelo'] ?? '');
            if (!$desc) continue;

            // Buscar en catálogo: 3 intentos en cascada
            $material_id  = null;
            $mat_catalogo = null;

            // Intento 1: marca + código exacto
            if ($marca !== '' && $modelo !== '') {
                $stmtMatch->execute([$marca, $modelo]);
                $mat_catalogo = $stmtMatch->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            // Intento 2: marca + primeras 3 palabras (LIKE)
            if (!$mat_catalogo && $marca !== '' && $desc !== '') {
                $desc_corta = implode(' ', array_slice(explode(' ', $desc), 0, 3));
                $stmtMatchDesc->execute([$marca, $desc_corta]);
                $mat_catalogo = $stmtMatchDesc->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            // Intento 3: fuzzy similar_text (cubre sin-marca y variaciones plurales)
            if (!$mat_catalogo && $desc !== '') {
                $candidatos = [];
                if ($marca !== '') {
                    $stmtFuzzyMarca->execute([$marca]);
                    $candidatos = $stmtFuzzyMarca->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // Sin marca: primera palabra significativa (≥4 chars) como filtro SQL
                    $palabras = preg_split('/\s+/', preg_replace('/[^\w\s]/u', ' ', $desc));
                    $kw = '';
                    foreach ($palabras as $pw) { if (mb_strlen($pw) >= 4) { $kw = $pw; break; } }
                    if ($kw !== '') {
                        $stmtFuzzyDesc->execute(['%' . $kw . '%']);
                        $candidatos = $stmtFuzzyDesc->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
                $best_pct = 0.0; $best_row = null;
                $desc_norm = mb_strtolower($desc);
                foreach ($candidatos as $row) {
                    similar_text($desc_norm, mb_strtolower($row['descripcion']), $pct);
                    if ($pct > $best_pct) { $best_pct = $pct; $best_row = $row; }
                }
                if ($best_row !== null && $best_pct >= 75.0) {
                    $mat_catalogo = $best_row;
                }
            }

            // Si hay match, usar los datos del catálogo (corrección ortográfica)
            if ($mat_catalogo) {
                $material_id = (int)$mat_catalogo['id'];
                $desc  = $mat_catalogo['descripcion'];
                $marca = $mat_catalogo['marca'];
                $modelo = $mat_catalogo['codigo'] ?: $modelo;
            }

            $db->prepare("INSERT INTO boom_items (boom_id, proyecto_id, descripcion, cantidad, unidad_medida, marca, modelo, material_id, guardado)
                          VALUES (?,?,?,?,?,?,?,?,0)")
               ->execute([$boom_target_id, $proy_id ?? null, $desc, $cant, $unidad, $marca, $modelo, $material_id]);
        }
        $boms_guardados++;
    }

    // Marcar el boom original como procesado con IA
    try { $db->prepare("UPDATE boom_listas SET ia_procesado=1, ia_fecha=NOW() WHERE id=?")->execute([$boom_id]); } catch(Exception $e){}
}

// Contar ítems ligados al catálogo en todos los BOMs guardados
$items_ligados = 0;
if ($boms_guardados > 0) {
    try {
        $stmtLigados = $db->prepare(
            "SELECT COUNT(*) FROM boom_items bi
             JOIN boom_listas bl ON bi.boom_id = bl.id
             WHERE bl.padre_boom_id = ? AND bi.material_id IS NOT NULL"
        );
        $stmtLigados->execute([$boom_id]);
        $items_ligados = (int)$stmtLigados->fetchColumn();
    } catch (Exception $e) {}
}

echo json_encode([
    'success'        => true,
    'boms_detectados'=> count($datos['boms'] ?? []),
    'boms_guardados' => $boms_guardados,
    'items_ligados'  => $items_ligados,
    'sin_bom'        => $datos['sin_bom'] ?? false,
    'datos'          => $datos,
]);
