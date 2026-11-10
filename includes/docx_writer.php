<?php
/**
 * Generador mínimo de .docx (OOXML) sin dependencias externas -- un .docx es un .zip con XML
 * adentro, así que se arma con ZipArchive (extensión nativa de PHP). No usa named styles: todo
 * el formato va inline en cada run/párrafo, igual que hace Word en un documento simple. Se
 * eligió generar el documento completo por request (no un archivo plantilla con tokens) porque
 * la Etapa 5 del protocolo FAT tiene un número variable de pasos -- un template estático no
 * puede representar eso.
 *
 * Encabezado/pie repetidos en cada página van en word/header1.xml / word/footer1.xml reales
 * (referenciados desde w:sectPr) -- NO como tablas normales al inicio del cuerpo, que solo
 * aparecerían una vez en la primera página (a diferencia del PDF/HTML, donde thead/tfoot sí
 * se repiten en cada página impresa).
 */

function docxEsc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Una <w:tc> cuyo último hijo es <w:tbl> (sin un <w:p> después) es OOXML inválido -- el esquema
 * exige que una celda cierre con párrafo. Word lo marca como "contenido no legible" y ofrece
 * recuperarlo; LibreOffice lo repara en silencio al abrir, así que este bug no se ve en un render
 * hecho con LibreOffice (como los que usa este proyecto para verificar) -- solo en Word real. Se
 * usa en docxCelda()/docxCeldaSinBorde() para blindar cualquier celda que reciba una fracción u
 * otra tabla anidada como contenido, sin que cada llamador tenga que acordarse de agregar el
 * párrafo final a mano. */
function docxSanearCeldaXml(string $contenidoXml): string
{
    if ($contenidoXml !== '' && str_ends_with(rtrim($contenidoXml), '</w:tbl>')) {
        $contenidoXml .= docxP('', ['spacingAfter' => 0]);
    }
    return $contenidoXml;
}

/** rPr compartido: Arial en todo el documento por default (pedido explícito) -- centralizado
 * para no repetir el rFonts en cada función que arma runs. $font: override puntual (ej. "Arial
 * Narrow" en la fórmula de indicadores) -- Arial sigue siendo el default en todos lados. */
function docxRFonts(string $font = 'Arial'): string
{
    return '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="' . $font . '"/>';
}

/** Uno o más <w:r> a partir de texto plano; los saltos de línea reales se vuelven <w:br/> y los
 * tabuladores reales se vuelven <w:tab/> (salto a la siguiente tabulación de Word, no un carácter
 * de texto -- necesario para las líneas "ETAPA N.-<tab>Título" con tabulación real).
 * $opts['font']: fuente del run (default 'Arial', ver docxRFonts()). */
function docxRun(string $texto, array $opts = []): string
{
    // Orden de hijos de <w:rPr> obligatorio por el esquema OOXML (CT_RPr): rFonts, b, bCs, i,
    // ..., color, ..., sz, szCs, ..., u, ... -- fuera de orden, Word abre el documento pidiendo
    // "recuperar contenido" en vez de mostrar un error claro.
    $rpr = docxRFonts($opts['font'] ?? 'Arial');
    // b===false (no solo ausente) cancela negrita heredada de un pStyle con <w:b/> -- necesario
    // para "ETAPA N.-" (usa Heading2 por el nivel de esquema, pero en el original NO es negrita).
    if (array_key_exists('b', $opts)) $rpr .= $opts['b'] ? '<w:b/><w:bCs/>' : '<w:b w:val="0"/><w:bCs w:val="0"/>';
    if (!empty($opts['i'])) $rpr .= '<w:i/>';
    if (!empty($opts['color'])) $rpr .= '<w:color w:val="' . $opts['color'] . '"/>';
    $sz = $opts['size'] ?? 18; // half-points: 18 = 9pt (Arial 9, pedido explícito)
    $rpr .= '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
    if (!empty($opts['u'])) $rpr .= '<w:u w:val="single"/>';
    $rprXml = "<w:rPr>{$rpr}</w:rPr>";

    $lineas = explode("\n", $texto);
    $out = '';
    foreach ($lineas as $i => $linea) {
        if ($i > 0) $out .= "<w:r>{$rprXml}<w:br/></w:r>";
        foreach (explode("\t", $linea) as $j => $trozo) {
            if ($j > 0) $out .= "<w:r>{$rprXml}<w:tab/></w:r>";
            if ($trozo === '') continue;
            $out .= '<w:r>' . $rprXml . '<w:t xml:space="preserve">' . docxEsc($trozo) . '</w:t></w:r>';
        }
    }
    return $out;
}

/** Un párrafo completo. $opts: b,i,color,size (heredados al run), align (left|center|right|both),
 * spacingAfter (twips), lineSpacing (240 = sencillo, 276 = 1.15, 288 = 1.2 -- unidad w:line con
 * lineRule="auto", como en Word). */
function docxP(string $texto = '', array $opts = []): string
{
    // Orden obligatorio de <w:pPr> (CT_PPr): ...spacing...jc... -- spacing va ANTES que jc.
    $spAfter = $opts['spacingAfter'] ?? 120;
    $lineSpacing = $opts['lineSpacing'] ?? 240;
    $ppr = '<w:spacing w:after="' . $spAfter . '" w:line="' . $lineSpacing . '" w:lineRule="auto"/>';
    // Justificado por defecto -- medido en el .docx real (T490-FAT_R2.docx): TODO el cuerpo usa
    // jc="both", no alineación izquierda. align=false explícito lo desactiva (casos sin bordes
    // donde no importa, como celdas de una sola palabra).
    $align = $opts['align'] ?? 'both';
    if ($align) $ppr .= '<w:jc w:val="' . $align . '"/>';
    $run = $texto !== '' ? docxRun($texto, $opts) : '';
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $run . '</w:p>';
}

/**
 * Párrafo con varios "runs" de formato distinto dentro del mismo bloque de texto -- para resaltar
 * un dato auto-llenado (ej. el nombre del cliente) en medio de un párrafo de texto fijo, sin darle
 * formato especial al párrafo entero. $partes: [['texto' => ..., ...opts de docxRun], ...].
 * $pOpts: align/spacingAfter/lineSpacing del párrafo (igual que docxP).
 */
function docxParrafoMixto(array $partes, array $pOpts = []): string
{
    // spacing antes que jc (orden obligatorio de CT_PPr). Justificado por defecto, igual que docxP().
    $spAfter = $pOpts['spacingAfter'] ?? 120;
    $lineSpacing = $pOpts['lineSpacing'] ?? 240;
    $ppr = '<w:spacing w:after="' . $spAfter . '" w:line="' . $lineSpacing . '" w:lineRule="auto"/>';
    if (!empty($pOpts['indLeft'])) $ppr .= '<w:ind w:left="' . (int)$pOpts['indLeft'] . '"/>';
    $align = $pOpts['align'] ?? 'both';
    if ($align) $ppr .= '<w:jc w:val="' . $align . '"/>';
    $runs = '';
    foreach ($partes as $parte) {
        $texto = $parte['texto'] ?? '';
        if ($texto === '') continue;
        $runs .= docxRun($texto, $parte);
    }
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $runs . '</w:p>';
}

/**
 * Campo de Word (ej. "PAGE", "NUMPAGES") como secuencia begin/instrText/separate/end -- a
 * diferencia del PDF/HTML (donde Chromium no expone el total de páginas antes de imprimir),
 * Word SÍ soporta numeración de página en vivo de forma nativa: el campo se recalcula solo
 * cada vez que el documento pagina, sin que este generador sepa cuántas páginas va a tener.
 * $textoCache es el valor que se ve antes de que Word recalcule el campo (F9/al abrir);
 * conviene un número plausible, nunca se muestra si Word actualiza campos al abrir.
 * w:dirty="true" en el fldChar "begin" -- pedido real (2026-09-03): sin esto, varias
 * instalaciones de Word NO recalculan el campo solas al abrir pese a w:updateFields=true en
 * settings.xml, y se queda mostrando literal el texto de caché ('1') en TODOS los campos de
 * este generador (número de página, total de páginas, y cada PAGEREF del índice) -- de ahí que
 * se viera "1" en todos lados. w:dirty marca el campo como desactualizado, forzando el cálculo.
 */
function docxCampo(string $instr, string $textoCache, array $opts = []): string
{
    $rpr = docxRFonts();
    if (!empty($opts['b'])) $rpr .= '<w:b/><w:bCs/>';
    if (!empty($opts['color'])) $rpr .= '<w:color w:val="' . $opts['color'] . '"/>';
    $sz = $opts['size'] ?? 18;
    $rpr .= '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
    $rprXml = "<w:rPr>{$rpr}</w:rPr>";

    return '<w:r>' . $rprXml . '<w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r>'
        . '<w:r>' . $rprXml . '<w:instrText xml:space="preserve"> ' . docxEsc($instr) . ' </w:instrText></w:r>'
        . '<w:r>' . $rprXml . '<w:fldChar w:fldCharType="separate"/></w:r>'
        . '<w:r>' . $rprXml . '<w:t>' . docxEsc($textoCache) . '</w:t></w:r>'
        . '<w:r>' . $rprXml . '<w:fldChar w:fldCharType="end"/></w:r>';
}

/** Párrafo "Página X de Y" con campos PAGE/NUMPAGES que Word recalcula solo en cada página. */
function docxParrafoPaginaDeTotal(array $opts = []): string
{
    // spacing antes que jc (orden obligatorio de CT_PPr).
    $ppr = '<w:spacing w:after="' . ($opts['spacingAfter'] ?? 120) . '" w:line="240" w:lineRule="auto"/>';
    if (!empty($opts['align'])) $ppr .= '<w:jc w:val="' . $opts['align'] . '"/>';
    $runs = docxCampo('PAGE', '1', $opts) . docxRun(' de ', $opts) . docxCampo('NUMPAGES', '1', $opts);
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $runs . '</w:p>';
}

/** Título de sección numerada ("1.- Introducción", "1.1.- Alcance", ..., "Etapa N.- ..."). Medido
 * contra T490-FAT_R2.docx: NO son más grandes que el cuerpo -- mismo tamaño (9pt), negrita,
 * MAYÚSCULAS y justificado, sin color ni subrayado. $nivel=0 -> Heading1 (1., 2., 3., 4. -- los
 * 4 numerales principales), $nivel=1 -> Heading2 (1.1., 3.1.-3.4.1., y también "Etapa N.-", todos
 * al mismo nivel de sangría en el índice real). El pStyle es lo que docxIndice() (campo TOC real)
 * usa para detectar el nivel de esquema -- por eso sigue existiendo aunque el tamaño visual ya no
 * distinga niveles.
 * $opts['b']: negrita (default true) -- solo aplica cuando el texto NO trae un número detectable
 * al inicio (ver más abajo); con número, el número siempre es negrita y el resto nunca la lleva
 * como propiedad de RUN explícita (aunque en el cuerpo se ve igual de negrita, heredada del
 * estilo Heading1/Heading2, que sí tiene <w:b/> por default).
 * $opts['u']: subrayado (default false).
 * $opts['align']: alineación del párrafo (default 'both' -- "Etapa N.-" pide 'center' explícito).
 * $opts['mayus']: false para mantener el texto tal cual (por defecto se pasa a MAYÚSCULAS,
 * igual que el original -- "Etapa N.- Registro de personal" en el real NO va en mayúsculas).
 *
 * Número vs. resto del título -- pedido explícito (2026-09-03): en el índice (campo TOC real)
 * solo el número ("1.-", "3.4.1.-", "Etapa 5.-") debe verse en negritas, el título no. El campo
 * TOC de Word copia la negrita EXPLÍCITA de cada run del encabezado real, pero NO la negrita
 * heredada solo del estilo del párrafo -- por eso aquí el número se manda con <w:b/> explícito
 * (se ve negrita en el cuerpo Y se copia al índice) y el resto del título se manda SIN esa
 * propiedad en absoluto (se ve negrita en el cuerpo solo porque Heading1/Heading2 ya la trae por
 * default, pero como no es explícita por-run, el índice no la copia). Así el cuerpo se ve igual
 * que antes (todo en negritas) y el índice queda con el número resaltado nada más. */
function docxTitulo(string $texto, int $nivel = 0, array $opts = []): string
{
    $pStyle = $nivel === 0 ? '<w:pStyle w:val="Heading1"/>' : '<w:pStyle w:val="Heading2"/>';
    $align = $opts['align'] ?? 'both';
    // spacing antes que jc (orden obligatorio de CT_PPr).
    $ppr = $pStyle . '<w:spacing w:after="120" w:line="240" w:lineRule="auto"/><w:jc w:val="' . $align . '"/>';
    $u = $opts['u'] ?? false;
    $mayus = $opts['mayus'] ?? true;
    $textoFinal = $mayus ? mb_strtoupper($texto, 'UTF-8') : $texto;

    if (preg_match('/^((?:Etapa\s+)?\d+(?:\.\d+)*\.-)\s*(.*)$/iu', $textoFinal, $m) && $m[2] !== '') {
        $run = docxRun($m[1] . ' ', ['b' => true, 'u' => $u, 'size' => 18]);
        $run .= docxRun($m[2], ['u' => $u, 'size' => 18]);
    } else {
        $b = $opts['b'] ?? true;
        $run = docxRun($textoFinal, ['b' => $b, 'u' => $u, 'size' => 18]);
    }
    $p = '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $run . '</w:p>';
    // $opts['bookmark']: nombre único para que docxLineaIndice() pueda apuntarle un PAGEREF real.
    if (!empty($opts['bookmark'])) $p = docxBookmark($p, $opts['bookmark']);
    return $p;
}

/** Contador global de ids de bookmark (deben ser únicos en todo el .docx). */
function docxNextBookmarkId(): int
{
    static $id = 0;
    return $id++;
}

/** Envuelve un bloque XML (normalmente un <w:p> completo) en bookmarkStart/End -- para poder
 * referenciarlo después con un campo PAGEREF real (número de página en vivo). */
function docxBookmark(string $innerXml, string $nombre): string
{
    $id = docxNextBookmarkId();
    return '<w:bookmarkStart w:id="' . $id . '" w:name="' . $nombre . '"/>' . $innerXml . '<w:bookmarkEnd w:id="' . $id . '"/>';
}

/**
 * Línea de índice armada a mano -- reemplaza al campo TOC automático de Word (\o), que no
 * permitía controlar de forma confiable cuál parte queda en negritas (Word copia la negrita
 * heredada del estilo del encabezado real de forma inconsistente, pedido explícito 2026-09-03
 * de que el número SÍ se vea en negritas y el título NO, sin depender de esa ambigüedad).
 * El número de página sigue siendo un campo PAGEREF real (vivo, apunta al bookmark del título
 * real vía docxTitulo($texto, $nivel, ['bookmark' => $bookmarkNombre])) -- no un número fijo que
 * se desactualice si el contenido de arriba cambia. Sin puntitos (leader="none", pedido
 * explícito) y con la misma sangría (708 twips) para todas las líneas, sin escalonar por nivel.
 */
function docxLineaIndice(string $numero, string $titulo, string $bookmarkNombre): string
{
    $run = docxRun($numero . ' ', ['b' => true, 'size' => 18])
        . docxRun($titulo, ['size' => 18])
        . docxRun("\t")
        . docxCampo('PAGEREF ' . $bookmarkNombre . ' \h', '1', ['size' => 18]);
    $ppr = '<w:tabs><w:tab w:val="right" w:leader="none" w:pos="9972"/></w:tabs>'
        . '<w:spacing w:after="120" w:line="240" w:lineRule="auto"/><w:ind w:left="708"/>';
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . $run . '</w:p>';
}

/** Barra de sección de ancho completo (Etapa 2: "DATOS GENERALES DEL CLIENTE", etc.). Shade
 * D9D9D9 (gris) medido en T490-FAT_R2.docx -- no el azul D9E2EC que traía antes. */
function docxBarraSeccion(string $texto): string
{
    return docxTabla(
        docxFila(docxCelda(docxP($texto, ['b' => true, 'align' => 'center']), 9360, ['shade' => 'D9D9D9'])),
        [9360]
    );
}

/** Ancho de columna base de la rejilla fina de la Etapa 2 (twips) -- 12 columnas x 780 = 9360
 * (ancho útil de la hoja). Permite que una fila tenga 2 pares Etiqueta/Valor (como la mayoría de
 * los renglones) o 3 pares más angostos (Tipo de Válvula/Tamaño/Clase) dentro de la MISMA tabla
 * -- Word exige que todas las filas de una tabla compartan el mismo <w:tblGrid>, así que cada
 * fila reparte sus celdas en múltiplos de esta columna fina vía colspan en vez de anchos fijos.
 * Medido en T490-FAT_R2.docx: esa tabla real tiene 9 columnas finas repartidas de forma parecida
 * (algunos renglones van a 2 pares, otros a 3 más angostos, y "Contrato / Orden de Compra" pone
 * su etiqueta en un renglón y su valor debajo, no al lado -- ver docxFilaClienteContrato()). */
const DOCX_ETAPA2_COL = 780;

/**
 * Fila con 2 o 3 pares Etiqueta/Valor lado a lado (Etapa 2 real: varios campos por renglón,
 * no uno solo). $pares: [['Cliente', $valor, $spanEtiqueta, $spanValor], ...] -- los spans son
 * cantidad de columnas finas (DOCX_ETAPA2_COL cada una), no twips directos; deben sumar 12 entre
 * todos los pares de la fila. Alineación izquierda (no justificado) -- medido en T490-FAT_R2.docx:
 * las celdas de datos de la Etapa 2 no llevan jc="both". Celdas de etiqueta sin sombreado --
 * pedido explícito (2026-09-03): solo las barras de sección (docxFilaBarraColspan) llevan gris,
 * las filas de datos van en blanco igual que las de valor.
 * Etiqueta SIN negrita, valor en negrita azul (0000CC) -- pedido explícito (2026-09-03): lo que
 * va "dentro del recuadro" (el dato/valor) se resalta en negrita y azul, la etiqueta que lo
 * describe (lo de "afuera") se queda en texto normal -- medido igual en T490-FAT_R2.docx.
 * Padding vertical de celda en 0 (en vez del estándar DOCX_CELDA_PAD_Y) y spacing after del
 * párrafo también en 0 (en vez del default de docxP(), 120 twips = 6pt) para que esta tabla
 * quede más compacta -- pedido explícito (2026-09-03).
 */
function docxFilaCamposGrid(array $pares): string
{
    $celdas = '';
    foreach ($pares as [$etiqueta, $valor, $spanEtiqueta, $spanValor]) {
        $wEtiqueta = $spanEtiqueta * DOCX_ETAPA2_COL;
        $wValor = $spanValor * DOCX_ETAPA2_COL;
        if ($etiqueta === '') {
            $celdas .= docxCelda('', $wEtiqueta, ['padY' => 0, 'colspan' => $spanEtiqueta]) . docxCelda('', $wValor, ['padY' => 0, 'colspan' => $spanValor]);
            continue;
        }
        $celdas .= docxCelda(docxP($etiqueta . ':', ['align' => 'left', 'spacingAfter' => 0]), $wEtiqueta, ['padY' => 0, 'colspan' => $spanEtiqueta]);
        $celdas .= docxCelda(docxP($valor !== null && $valor !== '' ? $valor : '—', ['align' => 'left', 'b' => true, 'color' => '0000CC', 'spacingAfter' => 0]), $wValor, ['padY' => 0, 'colspan' => $spanValor]);
    }
    return docxFila($celdas);
}

/**
 * Bloque especial Cliente/Proyecto + Contrato-Orden de Compra -- pedido explícito (2026-09-03),
 * medido en T490-FAT_R2.docx: a diferencia de los demás campos, "Contrato / Orden de Compra"
 * pone su ETIQUETA en el renglón de Cliente y su VALOR en el renglón de Proyecto (una fila
 * menos de celdas en el primer renglón, la etiqueta no tiene un valor al lado, "comparte" la
 * columna con el valor de abajo en vez de ir junto a su propia etiqueta). Devuelve las 2 filas
 * ya armadas (para concatenar dentro del mismo docxTabla() de la Etapa 2).
 */
function docxFilasClienteContrato(?string $cliente, ?string $proyecto, ?string $ordenCompra): string
{
    $val = fn(?string $s) => $s !== null && $s !== '' ? $s : '—';
    $etiqueta = fn(string $s) => docxP($s, ['align' => 'left', 'spacingAfter' => 0]);
    $dato = fn(string $s) => docxP($s, ['align' => 'left', 'b' => true, 'color' => '0000CC', 'spacingAfter' => 0]);
    $fila1 = docxCelda($etiqueta('Cliente:'), 2 * DOCX_ETAPA2_COL, ['padY' => 0, 'colspan' => 2])
        . docxCelda($dato($val($cliente)), 4 * DOCX_ETAPA2_COL, ['padY' => 0, 'colspan' => 4])
        . docxCelda($etiqueta('Contrato / Orden de Compra:'), 6 * DOCX_ETAPA2_COL, ['padY' => 0, 'colspan' => 6]);
    $fila2 = docxCelda($etiqueta('Proyecto:'), 2 * DOCX_ETAPA2_COL, ['padY' => 0, 'colspan' => 2])
        . docxCelda($dato($val($proyecto)), 4 * DOCX_ETAPA2_COL, ['padY' => 0, 'colspan' => 4])
        . docxCelda($dato($val($ordenCompra)), 6 * DOCX_ETAPA2_COL, ['padY' => 0, 'colspan' => 6]);
    return docxFila($fila1) . docxFila($fila2);
}

/** Barra de sección como UNA FILA dentro de una tabla más grande (colspan sobre todas las
 * columnas) -- para que la Etapa 2 (Cliente/Actuador/Válvula) y la Etapa 6 + Participantes
 * queden en una sola tabla continua en vez de varias tablas separadas por espacios en blanco,
 * igual que en T490-FAT_R2.docx. $anchoTotal: suma de los anchos de columna que cubre. */
function docxFilaBarraColspan(string $texto, int $anchoTotal, int $colspan, string $shade = 'D9D9D9'): string
{
    return docxFila(docxCelda(docxP($texto, ['b' => true, 'align' => 'center']), $anchoTotal, ['shade' => $shade, 'colspan' => $colspan]));
}

/** Margen superior/inferior estándar de toda celda del documento (twips) -- sin esto el texto
 * queda pegado al borde de la celda (el margen de celda de Word es 0 por defecto). Centralizado
 * aquí para que todas las tablas del documento respiren igual, no solo la que se quejó primero. */
const DOCX_CELDA_PAD_Y = 120;

/** Una celda de tabla. $contenidoXml ya debe ser XML de párrafo(s) (usar docxP).
 * $opts['padY']: override del margen superior/inferior en twips (0 para quitarlo).
 * $opts['colspan']: número de columnas de <w:tblGrid> que ocupa (gridSpan) -- para barras de
 * sección de ancho completo dentro de una tabla con más de una columna real.
 * $opts['borders']: override de qué lados dibujar y con qué estilo, ej. ['top'=>false,
 * 'left'=>false,'bottom'=>true,'right'=>false] -- para simular una barra de fracción (raya solo
 * entre numerador/denominador, sin caja completa alrededor). Cada lado acepta true (línea sólida),
 * false/ausente (sin línea, 'nil') o un string con el estilo exacto de OOXML (ej. 'dotted', para
 * un perímetro sólido con divisiones internas punteadas). Sin este opt, los 4 lados se dibujan
 * sólidos (comportamiento de siempre).
 * $opts['vMerge']: 'restart' (primera fila de una celda fusionada verticalmente) o 'continue'
 * (filas siguientes -- deben seguir mandando un <w:tc> en esa columna, vacío, con vMerge sin
 * w:val; Word extiende el contenido de la celda 'restart' sobre todas las 'continue' de abajo).
 * $opts['vAlign']: 'top'|'center'|'bottom' (default 'center') -- para una celda fusionada donde
 * la etiqueta debe quedar pegada arriba en vez de centrada en toda la altura fusionada.
 * $opts['padXLeft']: margen izquierdo interno de la celda en twips (default 0) -- una sangría
 * DENTRO de la celda, para que el texto no arranque pegado al borde izquierdo, sin mover la
 * tabla completa (eso es tblInd/$align en docxTabla(), algo distinto). */
function docxCelda(string $contenidoXml, int $widthTwips, array $opts = []): string
{
    $shade = !empty($opts['shade']) ? '<w:shd w:val="clear" w:fill="' . $opts['shade'] . '"/>' : '';
    $vAlign = '<w:vAlign w:val="' . ($opts['vAlign'] ?? 'center') . '"/>';
    $ladoXml = function (string $lado) use ($opts) {
        if (!isset($opts['borders'])) $val = 'single';
        else {
            $o = $opts['borders'][$lado] ?? false;
            if ($o === false) return '<w:' . $lado . ' w:val="nil"/>';
            $val = $o === true ? 'single' : $o;
        }
        return '<w:' . $lado . ' w:val="' . $val . '" w:sz="4" w:space="0" w:color="333333"/>';
    };
    $borders = '<w:tcBorders>' . $ladoXml('top') . $ladoXml('left') . $ladoXml('bottom') . $ladoXml('right') . '</w:tcBorders>';
    $padY = $opts['padY'] ?? DOCX_CELDA_PAD_Y;
    $padXLeft = $opts['padXLeft'] ?? 0;
    $tcMar = ($padY > 0 || $padXLeft > 0)
        ? '<w:tcMar><w:top w:w="' . $padY . '" w:type="dxa"/><w:left w:w="' . $padXLeft . '" w:type="dxa"/><w:bottom w:w="' . $padY . '" w:type="dxa"/></w:tcMar>'
        : '';
    $gridSpan = !empty($opts['colspan']) ? '<w:gridSpan w:val="' . (int)$opts['colspan'] . '"/>' : '';
    $vMerge = !empty($opts['vMerge']) ? '<w:vMerge' . ($opts['vMerge'] === 'restart' ? ' w:val="restart"' : '') . '/>' : '';
    $tcPr = '<w:tcPr><w:tcW w:w="' . $widthTwips . '" w:type="dxa"/>' . $gridSpan . $vMerge . $borders . $shade . $tcMar . $vAlign . '</w:tcPr>';
    if (($opts['vMerge'] ?? null) === 'continue') $contenidoXml = '';
    $contenidoXml = docxSanearCeldaXml($contenidoXml);
    if ($contenidoXml === '') $contenidoXml = docxP('');
    return '<w:tc>' . $tcPr . $contenidoXml . '</w:tc>';
}

/** Una fila con celdas ya armadas (docxCelda). */
/** $alturaTwips: alto mínimo del renglón (w:hRule="atLeast") -- para renglones que se llenan a
 * mano después de imprimir (ej. Etapa 6), donde el alto por defecto de Word queda muy apretado. */
function docxFila(string $celdasXml, ?int $alturaTwips = null): string
{
    $trPr = $alturaTwips !== null ? '<w:trPr><w:trHeight w:val="' . $alturaTwips . '" w:hRule="atLeast"/></w:trPr>' : '';
    return '<w:tr>' . $trPr . $celdasXml . '</w:tr>';
}

/** <w:tblGrid> -- OBLIGATORIO para que Word respete los anchos declarados en vez de auto-ajustar
 * al contenido (sin esto, cada tabla queda angosta según lo que "cree" que necesita). */
function docxTblGrid(array $anchosColumnas): string
{
    $cols = '';
    foreach ($anchosColumnas as $a) $cols .= '<w:gridCol w:w="' . $a . '"/>';
    return '<w:tblGrid>' . $cols . '</w:tblGrid>';
}

/** Envuelve filas ya armadas (docxFila) en una tabla con bordes. $anchos: ancho de cada columna
 * en twips (debe sumar el ancho total deseado, ej. [2800,6560] = 9360 = carta con márgenes de 1").
 * $align: 'left' (default) o 'center' -- centra la tabla completa dentro de su contenedor cuando
 * su ancho declarado es menor que el disponible (ej. una fracción angosta dentro de una celda
 * ancha, en vez de quedar pegada al margen izquierdo).
 * $indentTwips: sangría de la tabla completa desde el margen izquierdo de la página (dxa) -- para
 * que una tabla de nivel superior no arranque pegada al margen (pedido explícito: ~1cm = 567).
 * $insideStyle: estilo de las líneas INTERNAS (insideH/insideV) -- 'single' (default) o 'dotted'
 * para una tabla con el perímetro exterior sólido pero las divisiones internas punteadas (pedido
 * explícito, medido contra un reporte real ya firmado). El perímetro exterior siempre es sólido. */
function docxTabla(string $filasXml, array $anchos = [9360], string $align = 'left', int $indentTwips = 0, string $insideStyle = 'single'): string
{
    // Orden obligatorio de <w:tblPr> (CT_TblPr): ...tblW...jc...tblInd...tblBorders...tblLayout...
    // -- jc y tblInd van después de tblW y antes de tblBorders; tblBorders va ANTES que tblLayout
    // (al revés dispara el aviso de "recuperar contenido" al abrir en Word).
    $anchoTotal = array_sum($anchos);
    $jc = $align === 'center' ? '<w:jc w:val="center"/>' : '';
    $tblInd = $indentTwips > 0 ? '<w:tblInd w:w="' . $indentTwips . '" w:type="dxa"/>' : '';
    $tblPr = '<w:tblPr><w:tblW w:w="' . $anchoTotal . '" w:type="dxa"/>' . $jc . $tblInd . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="333333"/><w:left w:val="single" w:sz="4" w:color="333333"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="333333"/><w:right w:val="single" w:sz="4" w:color="333333"/>'
        . '<w:insideH w:val="' . $insideStyle . '" w:sz="4" w:color="333333"/><w:insideV w:val="' . $insideStyle . '" w:sz="4" w:color="333333"/>'
        . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>';
    return '<w:tbl>' . $tblPr . docxTblGrid($anchos) . $filasXml . '</w:tbl>';
}

/** Fila de tabla tipo "Etiqueta: Valor" (Etapa 2, con bordes/sombreado -- estilo "ficha"). */
function docxFilaEtiquetaValor(string $etiqueta, ?string $valor, int $wEtiqueta = 2800, int $wValor = 6560): string
{
    $celdaEtiqueta = docxCelda(docxP($etiqueta, ['b' => true]), $wEtiqueta, ['shade' => 'EFEFEF']);
    $celdaValor    = docxCelda(docxP($valor !== null && $valor !== '' ? $valor : '—'), $wValor);
    return docxFila($celdaEtiqueta . $celdaValor);
}

/** Celda sin bordes (portada -- lista de campos estilo "Etiqueta:   Valor" sin rejilla).
 * Sin w:vAlign (queda "top", el default de Word) -- medido en T490-FAT_R2.docx: la tabla de
 * datos generales de la portada no fuerza centrado vertical, el texto arranca al tope de la fila. */
function docxCeldaSinBorde(string $contenidoXml, int $widthTwips): string
{
    $tcPr = '<w:tcPr><w:tcW w:w="' . $widthTwips . '" w:type="dxa"/>'
        . '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
        . '<w:tcMar><w:top w:w="' . DOCX_CELDA_PAD_Y . '" w:type="dxa"/><w:bottom w:w="' . DOCX_CELDA_PAD_Y . '" w:type="dxa"/></w:tcMar>'
        . '</w:tcPr>';
    $contenidoXml = docxSanearCeldaXml($contenidoXml);
    if ($contenidoXml === '') $contenidoXml = docxP('');
    return '<w:tc>' . $tcPr . $contenidoXml . '</w:tc>';
}

/** Fila "Etiqueta:   Valor" sin bordes, etiqueta alineada a la derecha (SIN negrita) y valor en
 * negrita azul -- medido en la portada real (T490-FAT_R2.docx): la etiqueta no lleva negrita y el
 * azul real es 0000CC, no el 00BBFF que traía antes (ese nunca se midió contra el documento). */
function docxFilaEtiquetaValorLibre(string $etiqueta, ?string $valor, int $wEtiqueta = 3340, int $wValor = 6020): string
{
    // Espacio final antes de alinear a la derecha -- sin él, la etiqueta queda pegada al valor
    // de la celda vecina (el margen por defecto de una celda sin bordes es casi nulo).
    // size=32 (16pt) -- el mismo tamaño que usa la portada real (T490-FAT_R2.docx), no el
    // tamaño de cuerpo (18=9pt) que se veía enano en una hoja de portada.
    $celdaEtiqueta = docxCeldaSinBorde(docxP($etiqueta . '  ', ['align' => 'right', 'size' => 32]), $wEtiqueta);
    $celdaValor    = docxCeldaSinBorde(docxP($valor !== null && $valor !== '' ? $valor : '', ['b' => true, 'color' => '0000CC', 'size' => 32]), $wValor);
    return docxFila($celdaEtiqueta . $celdaValor);
}

/** Tabla sin bordes exteriores/interiores (portada). $anchos: igual que docxTabla().
 * $align: igual que docxTabla() ('left'|'center'). */
function docxTablaSinBordes(string $filasXml, array $anchos = [9360], string $align = 'left'): string
{
    // jc después de tblW y antes de tblBorders; tblBorders antes que tblLayout (mismo orden
    // obligatorio que docxTabla()).
    $anchoTotal = array_sum($anchos);
    $jc = $align === 'center' ? '<w:jc w:val="center"/>' : '';
    $tblPr = '<w:tblPr><w:tblW w:w="' . $anchoTotal . '" w:type="dxa"/>' . $jc . '<w:tblBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
        . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>';
    return '<w:tbl>' . $tblPr . docxTblGrid($anchos) . $filasXml . '</w:tbl>';
}

/** Igual que docxTablaVacia() pero devuelve solo las FILAS (sin envolver en <w:tbl>) -- para
 * poder seguir agregando más filas (ej. la barra "PARTICIPANTES") a la misma tabla continua
 * antes de envolver todo en un solo docxTabla(). $anchos: ancho de cada columna (debe tener
 * count($encabezados) elementos). */
function docxTablaVaciaFilas(array $encabezados, array $anchos, int $filasVacias = 8, ?int $alturaFilaTwips = null): string
{
    $n = count($encabezados);
    $celdasHead = '';
    foreach ($encabezados as $i => $h) {
        $celdasHead .= docxCelda(docxP($h, ['b' => true, 'align' => 'center']), $anchos[$i]);
    }
    $filas = docxFila($celdasHead);
    for ($i = 0; $i < $filasVacias; $i++) {
        $celdas = '';
        for ($c = 0; $c < $n; $c++) $celdas .= docxCelda('', $anchos[$c]);
        $filas .= docxFila($celdas, $alturaFilaTwips);
    }
    return $filas;
}

/** Tabla con encabezados y N filas vacías (Etapas 1, 3, 4). Encabezado sin sombreado -- medido
 * en T490-FAT_R2.docx: solo negrita centrada, ningún fill de color (el azul que traía antes
 * nunca se comparó contra el documento real). */
function docxTablaVacia(array $encabezados, int $filasVacias = 8, ?int $alturaFilaTwips = null): string
{
    $n = count($encabezados);
    $ancho = intdiv(9360, $n);
    $anchos = array_fill(0, $n, $ancho);
    return docxTabla(docxTablaVaciaFilas($encabezados, $anchos, $filasVacias, $alturaFilaTwips), $anchos);
}

/**
 * Un paso de la Etapa 5 como FILA de tabla -- el llamador junta todas las filas en una sola
 * docxTabla() para que el borde corra continuo por toda la sección y cada paso quede separado
 * solo por una línea horizontal, igual que el FAT original en papel: texto plano en mayúsculas,
 * casillas ☐ para las opciones/resultado y un renglón en blanco para el dato de texto -- nada de
 * tarjetas de color ni "Resultado: Pendiente", este documento se imprime y se llena a mano con
 * pluma durante la prueba física (nunca lleva valores capturados).
 * $numero: columna angosta a la izquierda con el número de paso (1..n) -- medido en
 * T490-FAT_R2.docx, cada renglón trae su propia numeración en una columna aparte.
 */
function docxPasoFatFila(array $paso, int $numero): string
{
    $contenido = docxP(mb_strtoupper($paso['titulo'] ?? '', 'UTF-8'), ['b' => true, 'spacingAfter' => 140, 'lineSpacing' => 276]);

    if (!empty($paso['instrucciones'])) {
        $contenido .= docxP(mb_strtoupper($paso['instrucciones'], 'UTF-8'), ['spacingAfter' => 180, 'lineSpacing' => 276]);
    }

    foreach ($paso['campos'] ?? [] as $campo) {
        $etiqueta = mb_strtoupper($campo['etiqueta'] ?? '', 'UTF-8');
        if (($campo['tipo'] ?? '') === 'opciones') {
            $texto = $etiqueta . ':  ';
            foreach (($campo['opciones'] ?? ['Abierto', 'Cerrado']) as $op) {
                $texto .= '☐ ' . mb_strtoupper($op, 'UTF-8') . '   ';
            }
            $contenido .= docxP(rtrim($texto), ['spacingAfter' => 140, 'lineSpacing' => 276]);
        } else {
            $unidad = !empty($campo['unidad']) ? ' ' . mb_strtoupper($campo['unidad'], 'UTF-8') : '';
            $contenido .= docxP($etiqueta . ': ________________________' . $unidad, ['spacingAfter' => 140, 'lineSpacing' => 276]);
        }
    }

    $contenido .= docxP('RESULTADO DE LA PRUEBA:  ☐ SATISFACTORIA   ☐ NO SATISFACTORIA', ['b' => true, 'spacingAfter' => 0]);

    // Columna de numeración angosta (500 twips) + columna de contenido (8860) = 9360.
    // padY estándar (DOCX_CELDA_PAD_Y, aplicado por defecto en docxCelda).
    $celdaNum = docxCelda(docxP((string)$numero, ['align' => 'center']), 500);
    return docxFila($celdaNum . docxCelda($contenido, 8860));
}

/** Imagen embebida como párrafo. $align: left|center|right. $rId: id de relación de la imagen
 * (distinto según la parte del documento -- document.xml vs header1.xml tienen cada uno su
 * propio archivo .rels, así que el mismo "rIdLogo" en dos partes distintas no es el mismo id).
 * Spacing explícito en 0 -- sin esto Word le mete su espaciado por default (no declarado en
 * ningún lado de este documento) al párrafo de la imagen, y eso desalineaba el encabezado
 * contra la celda vecina del título (que sí trae spacingAfter=0 explícito). */
function docxParrafoImagen(int $anchoEmu, int $altoEmu, string $rId = 'rIdLogo', string $align = 'center'): string
{
    return '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="' . $align . '"/></w:pPr><w:r><w:drawing>'
        . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
        . '<wp:extent cx="' . $anchoEmu . '" cy="' . $altoEmu . '"/>'
        . '<wp:docPr id="1" name="logo"/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:nvPicPr><pic:cNvPr id="1" name="logo"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="' . $rId . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
        . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $anchoEmu . '" cy="' . $altoEmu . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
}

/** Tamaño real de un PNG/JPEG (ancho,alto en px) leyendo solo la cabecera -- para no deformar el
 * logo si su relación de aspecto no coincide con un ancho/alto fijo adivinado a mano. */
function docxTamanoImagenPx(string $path): array
{
    $info = @getimagesize($path);
    return $info ? [$info[0], $info[1]] : [1, 1];
}

/** Convierte un ancho deseado en EMU (914400 EMU = 1 pulgada) manteniendo la relación de aspecto
 * real de la imagen; devuelve [anchoEmu, altoEmu]. */
function docxEmuManteniendoAspecto(string $path, int $anchoEmuDeseado): array
{
    [$wPx, $hPx] = docxTamanoImagenPx($path);
    $altoEmu = (int) round($anchoEmuDeseado * ($hPx / max(1, $wPx)));
    return [$anchoEmuDeseado, $altoEmu];
}

const DOCX_NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
    . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
    . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
    . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

/**
 * Arma el .docx completo y devuelve los bytes binarios del archivo.
 * $bodyXml: contenido del cuerpo (párrafos/tablas ya armados con los helpers de arriba).
 * $logoPath: ruta a un PNG/JPEG en disco para embeber en el header (opcional).
 * $headerXml/$footerXml: contenido que se repite en cada página -- si $headerXml usa la imagen,
 * debe referenciarla con r:embed="rIdLogoHeader" (id fijo, distinto del usado en el cuerpo).
 */
/** $pgMarTop: margen superior de la página en twips -- default 1418 (el medido contra el .docx
 * real del Protocolo FAT); un caller distinto puede pedir uno más chico (ej. indicadores). */
function docxGenerar(string $bodyXml, ?string $logoPath = null, string $headerXml = '', string $footerXml = '', int $pgMarTop = 1418): string
{
    $tieneLogo = $logoPath && file_exists($logoPath);
    $logoExt   = $tieneLogo ? strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) : 'png';
    $logoMime  = $logoExt === 'jpg' || $logoExt === 'jpeg' ? 'image/jpeg' : 'image/png';
    $tieneHeader = $headerXml !== '';
    $tieneFooter = $footerXml !== '';

    $overrides = '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>';
    if ($tieneHeader) $overrides .= '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>';
    if ($tieneFooter) $overrides .= '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="' . $logoExt . '" ContentType="' . $logoMime . '"/>'
        . $overrides
        . '</Types>';

    $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdDoc" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $docRelsExtra = '';
    $sectPrRefs = '';
    if ($tieneHeader) {
        $docRelsExtra .= '<Relationship Id="rIdHeader" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>';
        $sectPrRefs .= '<w:headerReference w:type="default" r:id="rIdHeader"/>';
    }
    if ($tieneFooter) {
        $docRelsExtra .= '<Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
        $sectPrRefs .= '<w:footerReference w:type="default" r:id="rIdFooter"/>';
    }
    // rIdLogo (cuerpo, ej. portada) -- el header usa su propio rIdLogoHeader vía header1.xml.rels.
    $relsDoc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . ($tieneLogo ? '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.' . $logoExt . '"/>' : '')
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
        . $docRelsExtra
        . '</Relationships>';

    // Arial 9 (18 half-points) por defecto para cualquier run sin rPr explícito, y Heading1/2
    // con los mismos tamaños/color que usa docxTitulo() -- necesarios para que el campo TOC
    // (docxIndice()) tenga niveles de esquema que rastrear, ya que este generador no usa un
    // documento plantilla con estilos predefinidos por Word.
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles ' . DOCX_NS . '>'
        . '<w:docDefaults><w:rPrDefault><w:rPr>' . docxRFonts() . '<w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:rPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        // sz=18 (9pt) en ambos -- medido en T490-FAT_R2.docx: los títulos numerados NO son más
        // grandes que el cuerpo, a diferencia de lo que traía antes (22/20 = 11pt/10pt, nunca
        // medido contra el documento real). El <w:b/> del estilo es el default; docxTitulo()
        // lo cancela explícitamente por-run para "Etapa N.-" (única excepción sin negrita).
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
        . '<w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:rPr>' . docxRFonts() . '<w:b/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
        . '<w:pPr><w:outlineLvl w:val="1"/></w:pPr><w:rPr>' . docxRFonts() . '<w:b/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        // TOC1/TOC2/TOC3 con la MISMA sangría (708 twips) -- medido en T490-FAT_R2.docx: el
        // índice real es plano, ningún nivel se ve más indentado que otro (a diferencia del
        // estilo TOC por default de Word, que escalona la sangría por nivel de esquema).
        // w:tabs con leader="none" -- pedido explícito: ocultar los puntitos antes del número de
        // página. 9972 = ancho útil de la hoja carta con márgenes de 1.134cm (12240 - 1134*2).
        . '<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:autoRedefine/><w:uiPriority w:val="39"/>'
        . '<w:pPr><w:tabs><w:tab w:val="right" w:leader="none" w:pos="9972"/></w:tabs><w:ind w:left="708"/></w:pPr><w:rPr>' . docxRFonts() . '<w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="TOC2"><w:name w:val="toc 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:autoRedefine/><w:uiPriority w:val="39"/>'
        . '<w:pPr><w:tabs><w:tab w:val="right" w:leader="none" w:pos="9972"/></w:tabs><w:ind w:left="708"/></w:pPr><w:rPr>' . docxRFonts() . '<w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="TOC3"><w:name w:val="toc 3"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:autoRedefine/><w:uiPriority w:val="39"/>'
        . '<w:pPr><w:tabs><w:tab w:val="right" w:leader="none" w:pos="9972"/></w:tabs><w:ind w:left="708"/></w:pPr><w:rPr>' . docxRFonts() . '<w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:style>'
        . '</w:styles>';

    // w:updateFields -- Word recalcula TOC/PAGE/NUMPAGES al abrir el documento en vez de dejar
    // el texto de caché (siempre "1") hasta que el usuario haga clic derecho > Actualizar campo.
    $settingsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:settings ' . DOCX_NS . '><w:updateFields w:val="true"/></w:settings>';

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document ' . DOCX_NS . '>'
        . '<w:body>' . $bodyXml
        . '<w:sectPr>' . $sectPrRefs
        // Márgenes tomados del documento real (T490-FAT_R2.docx) -- los de antes (1080/560)
        // dejaban muy poco margen superior real (top - header = 520 twips), así que el cuerpo
        // arrancaba pegado al encabezado en cuanto el encabezado ocupaba más de una línea.
        . '<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="' . $pgMarTop . '" w:right="1134" w:bottom="1418" w:left="1134" w:header="709" w:footer="153" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $headerXmlDoc = $tieneHeader
        ? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . DOCX_NS . '>' . $headerXml . '</w:hdr>'
        : null;
    $footerXmlDoc = $tieneFooter
        ? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . DOCX_NS . '>' . $footerXml . '</w:ftr>'
        : null;
    $headerRels = ($tieneHeader && $tieneLogo)
        ? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdLogoHeader" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.' . $logoExt . '"/>'
            . '</Relationships>'
        : null;

    $tmpZip = tempnam(sys_get_temp_dir(), 'fatdoc_') . '.docx';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $relsRoot);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/_rels/document.xml.rels', $relsDoc);
    $zip->addFromString('word/styles.xml', $stylesXml);
    $zip->addFromString('word/settings.xml', $settingsXml);
    if ($tieneLogo) {
        $zip->addFile($logoPath, 'word/media/image1.' . $logoExt);
    }
    if ($headerXmlDoc !== null) $zip->addFromString('word/header1.xml', $headerXmlDoc);
    if ($footerXmlDoc !== null) $zip->addFromString('word/footer1.xml', $footerXmlDoc);
    if ($headerRels !== null) $zip->addFromString('word/_rels/header1.xml.rels', $headerRels);
    $zip->close();

    $bytes = file_get_contents($tmpZip);
    unlink($tmpZip);
    return $bytes;
}
