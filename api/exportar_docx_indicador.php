<?php
/**
 * Reporte de indicadores (DOC-GEN-FO-15) como .docx real (editable en Word), usando
 * includes/docx_writer.php -- mismo motor y mismo motivo que api/exportar_docx_fat_protocolo.php:
 * el número de entradas (servicios/proyectos) por reporte es variable, así que se genera el
 * documento completo por request en vez de plantillar un .docx estático.
 *
 * Layout medido contra un reporte real ya firmado (Ingeniería, T-512/T-515/T-520, Revisión N° 6):
 * la fórmula y cada entrada de "Sustitución de Términos" se dibujan como fracción real
 * (numerador/línea/denominador vía docxCelda 'borders' parcial), no como líneas de texto sueltas;
 * la celda "Fecha" del encabezado es UNA sola caja fusionada verticalmente (vMerge) sobre las 3
 * filas, no un valor repetido 3 veces.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/indicadores.php';
require_once __DIR__ . '/../includes/docx_writer.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Acceso denegado');
}

$reporte_id = intval($_GET['reporte_id'] ?? 0);
if (!$reporte_id) { http_response_code(400); exit('reporte_id requerido'); }

$db = getDB();
$stmt = $db->prepare("SELECT * FROM indicadores_reportes WHERE id = ?");
$stmt->execute([$reporte_id]);
$reporte = $stmt->fetch();
if (!$reporte) { http_response_code(404); exit('Reporte no encontrado'); }

$cfg = INDICADORES_CONFIG[$reporte['area']] ?? null;
if (!$cfg) { http_response_code(400); exit('Área no configurada'); }
if (!ind_valida_acceso($reporte['area'])) { http_response_code(403); exit('No autorizado'); }

$stmt2 = $db->prepare("SELECT * FROM indicadores_entradas WHERE reporte_id = ? ORDER BY orden ASC, id ASC");
$stmt2->execute([$reporte_id]);
$entradas = $stmt2->fetchAll();

$promedio  = $reporte['porcentaje_obtenido'] !== null ? $reporte['porcentaje_obtenido'] : recalcularPromedioReporte($db, $reporte_id);
$requerido = $reporte['porcentaje_requerido'] !== null ? $reporte['porcentaje_requerido'] : 100.00;

$v  = fn($s) => $s !== null && $s !== '' ? $s : '—';
$fd = fn(?string $f) => $f ? date('d/m/y', strtotime($f)) : '________';

// ── Helpers locales de layout (específicos de este documento, no de uso general -- por eso no
// viven en includes/docx_writer.php, que solo trae las primitivas de bajo nivel). ──────────────

/** Fracción real: numerador arriba, línea horizontal, denominador abajo -- sin caja alrededor
 * (solo la raya de en medio, vía el 'borders' parcial de docxCelda agregado para esto). Texto
 * centrado (pedido explícito) y sin padding de celda (padY=0, sin espaciado en ninguna tabla). */
function indDocxFraccion(string $numTexto, string $denTexto, int $ancho): string
{
    $filaNum = docxFila(docxCelda(docxP($numTexto, ['align' => 'center', 'spacingAfter' => 0, 'size' => 16]), $ancho, ['padY' => 0, 'borders' => []]));
    $filaDen = docxFila(docxCelda(docxP($denTexto, ['align' => 'center', 'spacingAfter' => 0, 'size' => 16]), $ancho, ['padY' => 0, 'borders' => ['top' => true]]));
    return docxTabla($filaNum . $filaDen, [$ancho]);
}

/** Fila libre (sin bordes) con celdas de ancho variable -- para poner la fracción y su resultado
 * lado a lado sin que se vea como una tabla de verdad. $pares: [[xml, ancho], ...]. $align: igual
 * que docxTabla() -- 'center' para que el bloque completo (más angosto que el ancho disponible)
 * se vea centrado en la página en vez de pegado a la izquierda (pedido explícito). */
function indDocxFilaLibre(array $pares, string $align = 'left'): string
{
    $celdas = '';
    $anchos = [];
    foreach ($pares as [$xml, $ancho]) {
        $celdas .= docxCeldaSinBorde($xml, $ancho);
        $anchos[] = $ancho;
    }
    return docxTablaSinBordes(docxFila($celdas), $anchos, $align);
}

/** Fracción con su resultado a la derecha, en UNA sola tabla plana, sin bordes visibles (solo la
 * raya entre numerador/denominador) -- numerador = "$textoA  –  $textoB" repartido en 3 columnas
 * (frase / guion central / frase) con las DOS columnas de frase del MISMO ancho, para que el "–"
 * quede centrado como separador propio en vez de perderse en medio de un párrafo envuelto a 2
 * líneas, y para que ninguno de los dos lados se vea más ancho que el otro (pedido explícito).
 * Denominador = "$textoC  –  $textoB" (misma referencia B, otra frase C) en la fila de abajo, con
 * raya (borde superior) corrida en sus 3 columnas para que se vea continua de punta a punta.
 * Columna de resultado fusionada verticalmente (vMerge), centrada a media altura. Un solo nivel
 * de tabla (no anidada dentro de una fila libre) -- con dos niveles, un texto largo que se
 * envuelve a 2 líneas desincroniza la altura de fila contra la celda vecina y el resultado cae
 * debajo en vez de al lado (visto con un render real); en un solo nivel, Word/LibreOffice
 * sincronizan bien. */
function indDocxFraccionConResultado(string $textoA, string $textoB, string $textoC, string $resultadoTexto, int $anchoFrase, int $anchoGuion, int $anchoResultado): string
{
    $anchos = [$anchoFrase, $anchoGuion, $anchoFrase, $anchoResultado];
    // Arial Narrow solo en el texto de la fórmula (pedido explícito) -- el resto del documento
    // sigue en Arial normal (default de docxRun()/docxP() cuando no se manda 'font').
    $op = ['align' => 'center', 'spacingAfter' => 0, 'size' => 16, 'font' => 'Arial Narrow'];
    $opGuion = $op;
    $fila1 = docxFila(
        docxCelda(docxP($textoA, $op), $anchos[0], ['padY' => 0, 'borders' => []])
        . docxCelda(docxP('–', $opGuion), $anchos[1], ['padY' => 0, 'borders' => []])
        . docxCelda(docxP($textoB, $op), $anchos[2], ['padY' => 0, 'borders' => []])
        . docxCelda(docxP($resultadoTexto, $op), $anchos[3], ['padY' => 0, 'borders' => [], 'vMerge' => 'restart'])
    );
    $fila2 = docxFila(
        docxCelda(docxP($textoC, $op), $anchos[0], ['padY' => 0, 'borders' => ['top' => true]])
        . docxCelda(docxP('–', $opGuion), $anchos[1], ['padY' => 0, 'borders' => ['top' => true]])
        . docxCelda(docxP($textoB, $op), $anchos[2], ['padY' => 0, 'borders' => ['top' => true]])
        // 'borders' => [] también aquí -- sin esto, la celda "fantasma" de continuación de vMerge
        // se queda con el default de docxCelda() (los 4 lados dibujados), y Word (a diferencia de
        // LibreOffice, que la ignora al estar vacía) SÍ la pinta -- ahí es donde se ve como tabla.
        . docxCelda('', $anchos[3], ['vMerge' => 'continue', 'borders' => []])
    );
    // align='center' -- el ancho declarado (anchoFrase*2+anchoGuion+anchoResultado) es menor que
    // el disponible a propósito, para que el bloque quede centrado en la página en vez de pegado
    // a la izquierda (pedido explícito).
    return docxTabla($fila1 . $fila2, $anchos, 'center');
}

// ── Encabezado repetido en cada página: logo + "REPORTE DE INDICADORES", igual que el .docx real
// (DOC-GEN-FO-15) -- 2 columnas, logo a la izquierda, título centrado a la derecha. logo_fat.jpg --
// el logo pensado para documentos Word (el mismo que usa api/exportar_docx_fat_protocolo.php),
// no logo.png (ese es para PDF/pantalla). Tamaño del logo fijo (no por relación de aspecto):
// alto 1.56cm x ancho 5.73cm, pedido explícito -- 1cm = 360000 EMU.
$logo_path = UPLOAD_PATH . 'logo/logo_fat.jpg';
$tieneLogo = file_exists($logo_path);
$logoEmuW = (int) round(5.73 * 360000);
$logoEmuH = (int) round(1.56 * 360000);

// Columna del logo a 3400 twips (~6cm) -- debe ser más ancha que los 5.73cm (~3249 twips) del
// logo fijo, si no la imagen se desborda y la celda la recorta (visto con un render real).
// Sin negritas -- pedido explícito (ninguna parte del documento real lleva negritas).
$header = docxTabla(
    docxFila(
        docxCelda($tieneLogo ? docxParrafoImagen($logoEmuW, $logoEmuH, 'rIdLogoHeader') : docxP('EMPRESA EJEMPLO', ['align' => 'center', 'spacingAfter' => 0]), 3400, ['padY' => 0])
        . docxCelda(docxP('REPORTE DE INDICADORES', ['align' => 'center', 'size' => 26, 'spacingAfter' => 0]), 5960, ['padY' => 0])
    ),
    [3400, 5960]
);

// ── Pie repetido en cada página -- Revisión N° 6 / 03-Abr-25, la que de verdad está en uso
// (medida contra un reporte real ya firmado), no la N° 5 / 18-Sep-21 del .docx plantilla original.
$footer = docxP('DOC-GEN-FO-15; Revisión N°: 6; Fecha: 03 / Abr / 25; Página: 1.', ['size' => 14, 'align' => 'left', 'spacingAfter' => 40]);
$footer .= docxP('Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización', ['size' => 14, 'align' => 'left']);

// ── Cuerpo ───────────────────────────────────────────────────────────────
// Tabla 1 (datos de quien crea el reporte) -- 2 columnas: la izquierda trae etiqueta y, en el
// renglón de abajo, el valor indentado ~4 tabs (pedido explícito); la derecha es UNA sola caja
// "Fecha" fusionada verticalmente sobre las 3 filas (vMerge), con su etiqueta pegada ARRIBA
// (vAlign top, no centrada en toda la altura fusionada) y el valor centrado horizontalmente.
// Todo en negro (sin el azul que traía antes) y Arial 10 (size=20 half-points) -- pedido explícito.
// La fecha es la de FINALIZACIÓN del reporte (fecha_finalizado), no la de creación/borrador.
// Sin espaciado vertical (padY=0 en todas las celdas de todas las tablas) -- pedido explícito.
// La sangría de 0.3cm es DENTRO de cada celda (padXLeft), no la tabla completa corrida del margen
// de la página -- eso se probó primero (tblInd) y no era lo que se pedía.
const IND_CELDA_SANGRIA = 170; // 0.3cm = 0.3 * 566.929 twips/cm
$IND_T1_SIZE = 20;
$indentValor = "\t\t";
$fechaTxt = $fd($reporte['fecha_finalizado'] ?? $reporte['fecha_reporte']);
$anchosInfo = [6660, 2700];
// Perímetro exterior sólido, divisiones internas punteadas (pedido explícito, medido contra un
// reporte real ya firmado) -- cada celda declara sus 4 lados a mano porque docxCelda() no hereda
// el estilo de línea de la tabla (una celda que no manda 'borders' siempre dibuja sólido).
$body = docxTabla(
    docxFila(
        docxCelda(
            docxP('Departamento o área:', ['align' => 'left', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE])
            . docxP($indentValor . mb_strtoupper($cfg['nombre_area'], 'UTF-8'), ['align' => 'left', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE]),
            $anchosInfo[0], ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA, 'borders' => ['top' => true, 'left' => true, 'right' => 'dotted', 'bottom' => 'dotted']]
        )
        . docxCelda(
            docxP('Fecha:', ['align' => 'center', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE])
            . docxP($fechaTxt, ['align' => 'center', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE]),
            // bottom=false -- esta es la fila 1 de una celda fusionada en 3, no lleva borde de
            // abajo (ese lo pone la ÚLTIMA fila de la fusión, más abajo); sin esto se veía como
            // una línea fantasma a la mitad del recuadro.
            $anchosInfo[1], ['vMerge' => 'restart', 'vAlign' => 'top', 'padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA, 'borders' => ['top' => true, 'left' => 'dotted', 'right' => true, 'bottom' => false]]
        )
    )
    . docxFila(
        docxCelda(
            docxP('Nombre del representante:', ['align' => 'left', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE])
            . docxP($indentValor . $v($reporte['representante_nombre']), ['align' => 'left', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE]),
            $anchosInfo[0], ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA, 'borders' => ['top' => 'dotted', 'left' => true, 'right' => 'dotted', 'bottom' => 'dotted']]
        )
        // Fila de en medio de la fusión: SOLO el lado derecho (borde exterior de la tabla, tiene
        // que correr sin cortes por las 3 filas) y el izquierdo (línea punteada contra la columna
        // de al lado) -- top/bottom en false para no crear una línea fantasma a la mitad del
        // recuadro (a diferencia de LibreOffice, Word SÍ pinta los lados de una celda de
        // continuación vacía si no se le dice explícitamente que no).
        . docxCelda('', $anchosInfo[1], ['vMerge' => 'continue', 'padY' => 0, 'borders' => ['top' => false, 'left' => 'dotted', 'right' => true, 'bottom' => false]])
    )
    . docxFila(
        docxCelda(
            docxP('Cargo:', ['align' => 'left', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE])
            . docxP($indentValor . $v($reporte['representante_cargo']), ['align' => 'left', 'spacingAfter' => 0, 'size' => $IND_T1_SIZE]),
            $anchosInfo[0], ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA, 'borders' => ['top' => 'dotted', 'left' => true, 'right' => 'dotted', 'bottom' => true]]
        )
        // Última fila de la fusión: SÍ lleva bottom (cierra el recuadro por abajo).
        . docxCelda('', $anchosInfo[1], ['vMerge' => 'continue', 'padY' => 0, 'borders' => ['top' => false, 'left' => 'dotted', 'right' => true, 'bottom' => true]])
    ),
    $anchosInfo
);
$body .= docxP('', ['spacingAfter' => 0]);

// Tabla 2 -- por ahora, todo lo restante (Fórmula, Sustitución de Términos, Promedio, Indicador
// del Departamento) junto en UNA sola tabla continua de 4 filas (pedido explícito), igual que el
// .docx real (TABLE 18, rows=4 cols=1 en el original) -- no 3 cajas sueltas con espacio entre
// ellas como antes.

// Fila 1: Fórmula del departamento -- fracción real (numerador/línea/denominador), no una
// rejilla con columnas separadas. Misma redacción que ya usan la vista en pantalla y el PDF
// (INDICADORES_CONFIG).
// Un <w:tc> que termina en <w:tbl> sin un <w:p> después es OOXML inválido (el esquema exige que
// una celda de tabla cierre con párrafo) -- sin este docxP() final, Word/LibreOffice "reparan" la
// celda en silencio y se pierde el borde de toda la fila/tabla envolvente (visto con un render real).
// Salto de línea (un párrafo en blanco) cada vez que termina un párrafo dentro de esta tabla --
// pedido explícito, excluyendo la Tabla 1 y el cálculo de las fechas (esos se quedan compactos,
// ya están bien). $saltoLinea es del mismo tamaño que el texto de alrededor para que se vea como
// una línea en blanco real, no un espacio suelto.
$saltoLinea = docxP('', ['size' => $IND_T1_SIZE, 'spacingAfter' => 0]);

$filaFormulaXml = indDocxFraccionConResultado(
    $cfg['label_real'],
    $cfg['label_referencia'],
    $cfg['label_programada'],
    'X 100 =   %',
    2600, 300, 1500
) . docxP('', ['spacingAfter' => 0]);
$fila1 = docxP('Formula que aplica al departamento:', ['align' => 'left', 'size' => $IND_T1_SIZE, 'spacingAfter' => 0])
    . $saltoLinea
    . $filaFormulaXml
    . $saltoLinea;

// Fila 2: Sustitución de términos por valores -- una entrada por servicio/proyecto capturado.
// Cada entrada es solo el folio (sin prefijo "Proyecto:", que no está en el documento real)
// seguido de su fracción de fechas → fracción de días → resultado, con flecha.
$entradasXml = '';
if (empty($entradas)) {
    $entradasXml = docxP('Sin entradas.', ['color' => '888888', 'spacingAfter' => 0]);
} else {
    foreach ($entradas as $e) {
        $diasR = $e['dias_reales'];
        $diasP = $e['dias_programados'];
        $pct   = $e['porcentaje'];
        $fraccionFechas = indDocxFraccion(
            '(' . $fd($e['fecha_real']) . '  –  ' . $fd($e['fecha_referencia']) . ')',
            '(' . $fd($e['fecha_programada']) . '  –  ' . $fd($e['fecha_referencia']) . ')',
            3200
        );
        $fraccionDias = indDocxFraccion((string)($diasR ?? '—'), (string)($diasP ?? '—'), 600);
        // Etiqueta a la izquierda, negro (no azul) y Arial 10 -- pedido explícito: no va
        // centrada, y el color/tamaño deben ser los mismos que el resto de la tabla (lo único
        // que se queda en size=16 es lo que está dentro del cálculo de fechas, más abajo).
        $entradasXml .= docxP($e['etiqueta'] ?? '', ['align' => 'left', 'size' => $IND_T1_SIZE, 'spacingAfter' => 0]);
        // align='center' -- el ancho declarado de esta fila es menor que el disponible a
        // propósito, para que el bloque quede centrado en la página (pedido explícito).
        $entradasXml .= indDocxFilaLibre([
            [$fraccionFechas, 3200],
            [docxP('X 100 =', ['align' => 'center', 'size' => 16, 'spacingAfter' => 0]), 800],
            [docxP('→', ['align' => 'center', 'size' => 28, 'spacingAfter' => 0]), 450],
            [$fraccionDias, 600],
            [docxP('X 100 = ' . ($pct !== null ? number_format($pct, 2) : '—') . ' %', ['align' => 'center', 'size' => 16, 'spacingAfter' => 0]), 3150],
        ], 'center');
        if (!empty($e['nota'])) {
            $entradasXml .= docxP('Nota: ' . $e['nota'], ['i' => true, 'size' => 16, 'color' => '444444', 'spacingAfter' => 0]);
        }
        // Separador entre entradas -- una línea en blanco igual que $saltoLinea, no un
        // spacingAfter suelto (pedido explícito: nada de espaciado residual en los párrafos).
        $entradasXml .= $saltoLinea;
    }
}
$fila2 = docxP('Sustitución de Términos por valores', ['align' => 'left', 'size' => $IND_T1_SIZE, 'spacingAfter' => 0]) . $saltoLinea . $entradasXml;

// Fila 3: Promedio de porcentajes.
$sumaTexto = ($promedio !== null && !empty($entradas))
    ? implode(' + ', array_map(fn($e) => $e['porcentaje'] !== null ? number_format($e['porcentaje'], 2) : '—', $entradas)) . ' / ' . count($entradas) . ' = ' . number_format($promedio, 2) . '%'
    : '—';
$fila3 = $saltoLinea;
$fila3 .= docxParrafoMixto([
    ['texto' => 'Promedio de porcentajes (Σ porcentajes / total de porcentajes)' . "\n", 'size' => $IND_T1_SIZE],
    ['texto' => $sumaTexto, 'size' => $IND_T1_SIZE],
], ['align' => 'left', 'spacingAfter' => 0]);
$fila3 .= $saltoLinea;

// Fila 4: Indicador del departamento / resultado / firma.
$resultadoTexto = $reporte['estado'] === 'finalizado'
    ? ($reporte['cumple'] ? 'Cumple  X   No cumple ___' : 'Cumple ___   No cumple  X')
    : 'Cumple ___   No cumple ___';
$fila4 = docxP('Indicador del Departamento o área:', ['align' => 'left', 'size' => $IND_T1_SIZE, 'spacingAfter' => 0]);
$fila4 .= $saltoLinea;
$fila4 .= docxParrafoMixto([
    ['texto' => '% obtenido = ', 'size' => $IND_T1_SIZE],
    ['texto' => $promedio !== null ? number_format($promedio, 2) . '%' : '—', 'size' => $IND_T1_SIZE, 'u' => true],
    ['texto' => ', contra el porcentaje requerido que aplica para el departamento ', 'size' => $IND_T1_SIZE],
    ['texto' => number_format($requerido, 2) . '%', 'size' => $IND_T1_SIZE, 'u' => true],
], ['spacingAfter' => 0]);
$fila4 .= $saltoLinea;
$fila4 .= docxP('Resultado: ' . $resultadoTexto . ' con los objetivos del departamento o área.', ['size' => $IND_T1_SIZE, 'spacingAfter' => 0]);
// Espacio considerable antes de "Atentamente" (pedido explícito) -- pero ya NO un separador fijo
// que empuje al fondo de la página sin importar cuánto contenido haya arriba (eso se probó antes
// y con distinto número de entradas por reporte no siempre terminaba cerca del pie).
$fila4 .= $saltoLinea . $saltoLinea . $saltoLinea;
$fila4 .= docxP('Atentamente', ['align' => 'center', 'size' => $IND_T1_SIZE, 'spacingAfter' => 0]);
$fila4 .= $saltoLinea;
$fila4 .= docxP('_______________________________________', ['align' => 'center', 'size' => $IND_T1_SIZE, 'spacingAfter' => 0]);
$fila4 .= $saltoLinea;
$fila4 .= docxP('Nombre, firma y cargo', ['align' => 'center', 'size' => $IND_T1_SIZE, 'color' => '444444', 'spacingAfter' => 0]);

$body .= docxTabla(
    docxFila(docxCelda($fila1, 9360, ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA]))
    . docxFila(docxCelda($fila2, 9360, ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA]))
    . docxFila(docxCelda($fila3, 9360, ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA]))
    . docxFila(docxCelda($fila4, 9360, ['padY' => 0, 'padXLeft' => IND_CELDA_SANGRIA])),
    [9360]
);

// Margen superior de página muy angosto (0.1cm ≈ 57 twips) -- pedido explícito, solo para este
// documento (docxGenerar() sigue con su default de 1418 para el Protocolo FAT, sin tocarlo).
$bytes = docxGenerar($body, $tieneLogo ? $logo_path : null, $header, $footer, (int) round(0.1 * 566.929));

// iconv//TRANSLIT primero -- si no, el regex trabaja byte por byte y una tilde (2 bytes UTF-8,
// ej. "í" en "Ingeniería") sale como "__" (un guion bajo por cada byte) en vez de una sola letra
// sin acento (visto con un nombre de archivo real: "Indicadores_Ingenier__a_T3_2026.docx").
$areaAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cfg['nombre_area']) ?: $cfg['nombre_area'];
$nombre_archivo = 'Indicadores_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $areaAscii) . '_T' . (int)$reporte['trimestre'] . '_' . (int)$reporte['anio'] . '.docx';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
