<?php
/**
 * Protocolo formal de Pruebas de Aceptación en Fábrica (FAT) -- documento completo de 6 etapas.
 * Estructura y texto institucional tomados del documento real T490-FAT_R2.docx.
 *
 * Generado con mPDF (motor de PDF real, no "HTML + window.print() del navegador" como el resto
 * de los vista_pdf_*.php) -- Chromium nunca soportó renderizar contenido dentro de las cajas de
 * margen de página (@top-right, etc.), así que un número de página en vivo dentro del encabezado
 * repetido, o un índice con números de página reales y enlazados, son imposibles de lograr solo
 * con CSS impreso vía window.print(). mPDF sí soporta ambas cosas de forma nativa: {PAGENO}/{nbpg}
 * en el encabezado/pie (ver SetHTMLHeader/Footer más abajo) y un índice real con <tocpagebreak> +
 * <tocentry> (ver más abajo) que arma el TOC con página y enlace reales en una segunda pasada
 * (<bookmark> se deja además para el panel de marcadores del lector de PDF, es un mecanismo
 * distinto de mPDF que no rellena el índice impreso).
 *
 * Verificado con un render real (contenedor Docker de este proyecto, `docker exec ... phpunit`
 * + `pdftoppm` para revisar cada página como imagen) -- dos cosas que la documentación de mPDF no
 * deja claras y que solo salieron al renderizar de verdad:
 * - SetHTMLHeader()/SetHTMLFooter() NO interpretan un bloque <style>, lo dejan pasar como texto
 *   plano visible en cada página -- todo el estilo del encabezado/pie va inline.
 * - <bookmark> solo llena el panel de marcadores del lector de PDF; el índice IMPRESO (la lista
 *   con puntos y número de página en la página de "Contenido") lo llena <tocentry>, una etiqueta
 *   distinta -- fácil de confundir porque ambas usan los mismos atributos content/level.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/fat_protocolo.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (!isLoggedIn() || !(isAdmin() || hasAcceso('ingenieria') || hasAcceso('manufactura') || hasAcceso('proyectos') || hasAcceso('calidad'))) {
    http_response_code(403);
    exit('Acceso denegado');
}

$orden_produccion_id = intval($_GET['orden_produccion_id'] ?? 0);
if (!$orden_produccion_id) { http_response_code(400); exit('orden_produccion_id requerido'); }

$db = getDB();
$datos = obtenerDatosFatProtocolo($db, $orden_produccion_id);
if (!$datos) { http_response_code(404); exit('Orden de Producción no encontrada'); }

$op       = $datos['orden_produccion'];
$dg       = $datos['datos_generales'];
$pasos    = $datos['pasos'];
$fatUsada = $datos['pruebas_fat'];
$fechaProgramada = !empty($fatUsada['fecha_programada']) ? date('d/m/Y', strtotime($fatUsada['fecha_programada'])) : null;

$pie_codigo   = 'DOC-ING-FO-01D';
$pie_revision = 6;
$pie_fecha    = '10 / Sep / 2018';

$logo_data_uri = '';
$logo_path = UPLOAD_PATH . 'logo/logo.png';
if (file_exists($logo_path)) {
    $logo_data_uri = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
}

$v = fn(?string $s) => $s !== null && $s !== '' ? htmlspecialchars($s) : '—';

$codigo_protocolo = trim($op['numero_proyecto'] ?? '') !== '' ? $op['numero_proyecto'] . '-FAT' : 'FAT';

// ── Encabezado repetido en cada página -- vía SetHTMLHeader(), no un thead de tabla como en el
// resto de vista_pdf_*.php. {PAGENO} y {nbpg} los sustituye mPDF por el número de página real y
// el total real en cada página -- son los tokens de mPDF para eso, no texto literal.
// Proporciones (34/66 arriba, 16.45/16.45/53.42/13.68 abajo) medidas del .docx real
// (header2.xml: celdas de 3513/6801 twips arriba, 1696/1701/5642/1275 abajo).
// TODO el estilo va inline (sin <style>) -- verificado contra un render real: SetHTMLHeader() NO
// interpreta un bloque <style>, lo deja pasar como texto plano y aparece literal en cada página.
$logoCelda = $logo_data_uri
    ? '<img src="' . $logo_data_uri . '" alt="EMPRESA EJEMPLO" style="max-height:40px;">'
    : '<span style="font-weight:800;">EMPRESA EJEMPLO</span>';
// <div>, no <span style="display:block">: verificado con un render real -- mPDF no respeta
// display:block en un <span> dentro del encabezado, la etiqueta y el valor salían pegados en un
// solo renglón en vez de uno arriba del otro (ej. "REFERENCIA:202690660").
$labelEstilo = 'font-weight:400;font-size:7pt;text-align:left;';
$headerHtml = '
<table style="width:100%;border-collapse:collapse;border:1px solid #000;">
  <tr>
    <td style="width:34%;border-right:1px solid #000;text-align:center;padding:6px;vertical-align:middle;">' . $logoCelda . '</td>
    <td style="text-align:center;font-weight:800;font-size:14pt;text-transform:uppercase;line-height:1.2;padding:6px;vertical-align:middle;">
      <div>Protocolo para Pruebas de Aceptación en Fábrica "FAT"</div>
      <div style="font-size:12pt;">(Factory Acceptance Test)</div>
    </td>
  </tr>
</table>
<table style="width:100%;border-collapse:collapse;border:1px solid #000;border-top:none;font-size:9pt;margin-bottom:6px;">
  <tr>
    <td style="width:16.45%;border-right:1px solid #000;padding:4px 8px;text-align:center;vertical-align:middle;"><div style="' . $labelEstilo . '">DOCUMENTO:</div><div>' . htmlspecialchars($codigo_protocolo) . ' R0</div></td>
    <td style="width:16.45%;border-right:1px solid #000;padding:4px 8px;text-align:center;vertical-align:middle;"><div style="' . $labelEstilo . '">REFERENCIA:</div><div>' . $v($op['orden_compra']) . '</div></td>
    <td style="width:53.42%;border-right:1px solid #000;padding:4px 8px;text-align:center;vertical-align:middle;"><div style="' . $labelEstilo . '">CLIENTE:</div><div>' . $v($op['cliente']) . '</div></td>
    <td style="width:13.68%;padding:4px 8px;text-align:center;vertical-align:middle;"><div style="' . $labelEstilo . '">No. PÁGINA:</div><div>{PAGENO} de {nbpg}</div></td>
  </tr>
</table>';

// ── Pie repetido en cada página -- vía SetHTMLFooter(). 6pt, negro -- medido en el .docx real.
$footerHtml = '
<div style="border-top:1px solid #999;font-size:6pt;color:#222;text-align:center;padding-top:4px;">
  <div style="font-weight:700;">' . $pie_codigo . '; Revisión N°: ' . $pie_revision . '; Fecha: ' . $pie_fecha . '.</div>
  <div>Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización</div>
</div>';

// ── Cuerpo del documento ─────────────────────────────────────────────────────
ob_start();
?>
<style>
    /* Justificado -- medido en el .docx real (T490-FAT_R2.docx): todo el cuerpo usa jc="both".
       line-height 1.2 -- el Word real usa interlineado "sencillo" (w:line="240" w:lineRule="auto"),
       más apretado que un 1.4/1.5 típico de HTML; iguala el conteo de páginas contra el Word. */
    body { font-family: Arial, sans-serif; color: #222; font-size: 12px; line-height: 1.2; text-align: justify; }
    p { margin: 0 0 8px; }
    h1, h2, h3 { color: #222; }
    /* Dato auto-llenado dentro de un párrafo de texto fijo (cliente/fecha/dirección) -- se
       subraya y se pinta de azul (0000CC, medido en el .docx real) para que se note a simple
       vista, igual que en el Word. */
    .resaltado { color: #0000CC; text-decoration: underline; font-weight: 700; }
    .portada-cuerpo { page-break-after: always; text-align: center; padding: 0 10px; }
    .portada-cuerpo .logo img { max-height: 60px; margin-bottom: 24px; }
    /* Título de portada 16pt SIN negrita, código grande 48pt CON negrita -- medido en el .docx real. */
    .portada-cuerpo h1 { font-size: 16pt; font-weight: 400; text-transform: uppercase; margin-bottom: 12px; line-height: 1.25; }
    .portada-cuerpo .codigo { font-size: 48pt; font-weight: 800; color: #222; margin-bottom: 18px; }
    /* "Fecha de prueba FAT: ____" -- párrafo centrado aparte en MAYÚSCULAS con línea en blanco
       como guiones bajos literales -- así está en el original, no como fila de la tabla de abajo. */
    .fecha-prueba { font-size: 16pt; font-weight: 700; text-transform: uppercase; margin-bottom: 16px; }
    /* Lista de campos de portada como tabla (no flex -- mPDF no soporta flexbox de forma
       confiable). Etiqueta SIN negrita, valor en negrita azul 0000CC -- medido en el .docx real. */
    table.campos-portada { width: 100%; max-width: 640px; margin: 0 auto; font-size: 16pt; }
    table.campos-portada td { padding-bottom: 8px; }
    table.campos-portada td.etiqueta { width: 42%; text-align: right; padding-right: 10px; font-weight: 400; }
    table.campos-portada td.valor { text-align: left; font-weight: 700; color: #0000CC; }
    /* Mismo tamaño que el cuerpo (12px = 9pt) -- medido en el .docx real: NINGÚN título numerado
       (1., 1.1., 2., 3.x, 4.) es más grande que el texto normal. Negrita + MAYÚSCULAS +
       justificado, también medidos. */
    .seccion { page-break-inside: avoid; margin-bottom: 14px; }
    .seccion h2 { font-size: 12px; font-weight: 700; text-transform: uppercase; text-align: justify; margin: 16px 0 6px; }
    .seccion h3 { font-size: 12px; font-weight: 700; text-transform: uppercase; text-align: justify; margin: 10px 0 4px; }
    /* "Etapa N.-" -- negrita, subrayada y centrada -- pedido explícito del usuario (2026-08-28),
       distinto de cómo va en T490-FAT_R2.docx (ahí no lleva negrita/subrayado y va justificada). */
    .etapa { page-break-before: always; }
    .etapa h2 { font-size: 12px; font-weight: 700; text-decoration: underline; text-align: center; padding-bottom: 4px; }
    /* Mini-índice de las 6 etapas dentro de "4.- Desarrollo de las pruebas FAT" -- bloque de
       vista previa (mismo criterio que el .docx: "ETAPA N.-" en negrita + título normal, SIN
       número de página falso). El índice real de la portada (tocpagebreak) ya tiene los números
       correctos y enlazados. */
    .mini-indice-etapas p { margin: 0 0 2px; }
    table.datos-grid { width: 100%; border-collapse: collapse; margin: 0 0 14px; }
    table.datos-grid td { border: 1px solid #333; padding: 5px 8px; font-size: 12px; width: 25%; }
    table.datos-grid td.etiqueta { font-weight: bold; background: #eee; width: 20%; }
    /* D9D9D9 (gris) -- medido en el .docx real, no un azul inventado. */
    .barra-seccion { background: #d9d9d9; border: 1px solid #333; border-bottom: none; padding: 5px 8px; font-weight: 800; text-align: center; font-size: 12px; }
    table.vacia { width: 100%; border-collapse: collapse; margin: 8px 0; }
    table.vacia th, table.vacia td { border: 1px solid #333; padding: 6px 8px; font-size: 12px; }
    /* Sin sombreado y centrado -- medido en el .docx real (encabezado "Nombre" de Etapa 1:
       negrita centrada, sin ningún fill de color). */
    table.vacia th { text-align: center; }
    table.vacia td { height: 24px; }
    /* Etapa 5: texto plano en mayúsculas con casillas "[ ]", no tarjetas de color -- el documento
       se imprime y se llena a mano con pluma, igual que el FAT original. "[ ]" y no el glifo ☐
       real -- verificado con un render real: al usar Arial, mPDF mete el respaldo Unicode
       (dejavusanscondensed) solo para ese glifo y el texto de esa misma línea salía duplicado. */
    .pasos-etapa5 { border: 1px solid #333; margin-bottom: 8px; }
    .paso { padding: 8px 10px 10px; page-break-inside: avoid; }
    .paso + .paso { border-top: 1px solid #333; }
    .paso .num { font-weight: 800; font-size: 9pt; margin-bottom: 5px; text-transform: uppercase; }
    .paso .instr { font-size: 9pt; margin: 2px 0 8px; line-height: 1.15; text-transform: uppercase; }
    .paso .campo-linea { font-size: 9pt; margin-bottom: 6px; }
    .paso .resultado { display: block; font-size: 9pt; font-weight: 700; margin-top: 6px; text-transform: uppercase; }
    /* Sin page-break-inside:avoid en la tabla completa -- verificado con un render real: las 8
       firmas (con su fila de encabezado) suman más alto que una página completa, así que "no
       partir nunca" no tenía dónde caber y mPDF terminaba metiendo una página en blanco antes de
       rendirse y partirla de todos modos. Cada fila SÍ evita partirse a la mitad (ver tr abajo). */
    .firmas-tabla { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .firmas-tabla tr { page-break-inside: avoid; }
    .firmas-tabla th, .firmas-tabla td { border: 1px solid #333; padding: 5px 8px; font-size: 12px; }
    /* Sin sombreado, texto azul centrado -- el original usa un efecto de texto degradado en ese
       mismo azul (0000CC); aquí se simplifica a color sólido, visualmente equivalente. */
    .firmas-tabla th { color: #0000CC; text-align: center; }
    table.vacia.acuerdos td { height: 46px; }
    .firmas-tabla td { height: 90px; }
</style>

<div class="portada-cuerpo">
    <h1>Protocolo para Pruebas de Aceptación en Fábrica "FAT"<br>(Factory Acceptance Test)</h1>
    <div class="codigo"><?= htmlspecialchars($codigo_protocolo) ?></div>
    <div class="fecha-prueba">FECHA DE PRUEBA FAT: _____________________________</div>

    <table class="campos-portada">
        <tr><td class="etiqueta">Cliente:</td><td class="valor"><?= $v($op['cliente']) ?></td></tr>
        <tr><td class="etiqueta">Orden de Compra:</td><td class="valor"><?= $v($op['orden_compra']) ?></td></tr>
        <tr><td class="etiqueta">Producto:</td><td class="valor"><?= $v($op['descripcion']) ?></td></tr>
        <tr><td class="etiqueta">Marca:</td><td class="valor">EMPRESA EJEMPLO</td></tr>
        <tr><td class="etiqueta">Modelo:</td><td class="valor"><?= $v($dg['actuador']['modelo']) ?></td></tr>
    </table>
</div>

<!-- Índice real: mPDF reserva página(s) aquí y las rellena en una segunda pasada con los
     <bookmark> que aparecen más abajo -- título, nivel de sangría, número de página y enlace,
     todo real (a diferencia de la lista de texto fija que traía antes, sin páginas). -->
<!-- toc-preHTML necesita las etiquetas como entidades (&lt;/&gt;) -- verificado contra un render
     real: con "<h2>" literal dentro del atributo, el parser de mPDF no las reconoce como HTML y
     el texto "h2 /CONTENIDO/h2" sale tal cual en la página. -->
<tocpagebreak toc-preHTML="&lt;h2 style='text-align:center;font-size:16pt;font-weight:700;text-transform:none;'&gt;CONTENIDO&lt;/h2&gt;" />

<div class="seccion">
    <bookmark content="1.- Introducción" level="0" />
    <tocentry content="1.- Introducción" level="0" />
    <h2>1.- Introducción</h2>
    <p>El fin de este documento es manifestar los acuerdos alcanzados entre Empresa Ejemplo S.A. de C.V e <span class="resaltado"><?= $v($op['cliente']) ?></span> quienes en lo sucesivo serán designados como "EMPRESA EJEMPLO" y "EL CLIENTE" respectivamente; describe y establece los parámetros para la revisión y aprobación de los productos a suministrar por parte de "EMPRESA EJEMPLO" a "EL CLIENTE".</p>

    <bookmark content="1.1.- Alcance" level="1" />
    <tocentry content="1.1.- Alcance" level="1" />
    <h3>1.1.- Alcance</h3>
    <p>Las Pruebas de Aceptación en Fábrica (Factory Acceptance Test "FAT") demostrarán que el producto suministrado y sus componentes funcionan correctamente. Además de que se cumple, en todos los aspectos, con los requisitos de la orden de compra, contrato y/o la(s) hoja(s) de especificaciones proporcionada(s) por "EL CLIENTE". Por ende, cualquier otro requerimiento que no esté debidamente estipulado en estos queda fuera del alcance de este protocolo.</p>

    <bookmark content="2.- Definiciones" level="0" />
    <tocentry content="2.- Definiciones" level="0" />
    <h2>2.- Definiciones</h2>
    <p>DEFECTO. No conformidad relativa a un uso previsto o especificado.</p>
    <p>EQUIPO DE MEDICIÓN. Instrumento de medición, material de referencia o equipos auxiliares que permiten determinar el valor de una magnitud.</p>
    <p>ERROR. Diferencia entre el valor medido o calculado y el real.</p>
    <p>FILOSOFÍA DE OPERACIÓN. Enunciado que define las condiciones críticas de operación que debe satisfacer el producto a suministrar. Esta detalla la función de cada uno de los componentes que integran el producto final.</p>
    <p>ESPECIFICACIÓN TÉCNICA. Documento que define las características que se requieren de un producto o un servicio. Información proporcionada de un producto, la cual describe componentes, características y su funcionamiento.</p>
    <p>INCIDENTE. Suceso que surge del trabajo que podría tener o tiene como resultado lesiones o deterioro de la salud.</p>
    <p>NO CONFORMIDAD. Incumplimiento de un requisito.</p>
    <p>PRODUCTO. Bien material, servicio o idea que puede satisfacer una necesidad o requerimiento. Este puede ser integrado por más de un equipo y/o accesorio.</p>
    <p>PRUEBAS DE ACEPTACIÓN EN FÁBRICA (FAT). Procedimiento de pruebas específico aplicable al producto a suministrar para verificar y garantizar que la filosofía de operación o especificación técnica satisface el requerimiento de aplicación final señalado por "EL CLIENTE". Las pruebas se llevan a cabo en las instalaciones de "EMPRESA EJEMPLO" antes de la instalación final del producto en campo.</p>

    <bookmark content="3.- Planeación de las pruebas" level="0" />
    <tocentry content="3.- Planeación de las pruebas" level="0" />
    <h2>3.- Planeación de las pruebas</h2>
    <bookmark content="3.1.- Programación" level="1" />
    <tocentry content="3.1.- Programación" level="1" />
    <h3>3.1.- Programación</h3>
    <p>Las Pruebas de Aceptación en Fábrica (FAT) se realizan el día <?= $fechaProgramada !== null ? '<span class="resaltado">' . htmlspecialchars($fechaProgramada) . '</span>' : '________________________' ?> en las instalaciones de "EMPRESA EJEMPLO" ubicadas en: <span class="resaltado">Calle Ejemplo No. 123; Col. Centro; Ciudad de M&eacute;xico; C. P. 00000.</span></p>

    <bookmark content="3.2.- Personal" level="1" />
    <tocentry content="3.2.- Personal" level="1" />
    <h3>3.2.- Personal</h3>
    <p>"EL CLIENTE" tendrá participantes / representantes que testifiquen las pruebas y serán quienes estarán autorizados para aceptar las pruebas a realizar. "EMPRESA EJEMPLO" tendrá personal capacitado acorde a los requerimientos del producto para la realización de estas. Solo el personal de "EL CLIENTE" o de "EMPRESA EJEMPLO" con conocimiento de causa en el desarrollo de esta tendrá permitido el acceso al producto en todo momento.</p>

    <bookmark content="3.3.- Criterio de aceptación para las pruebas FAT" level="1" />
    <tocentry content="3.3.- Criterio de aceptación para las pruebas FAT" level="1" />
    <h3>3.3.- Criterio de aceptación para las pruebas FAT</h3>
    <p>La(s) prueba(s) se considerarán satisfactorias cuando "EL CLIENTE" e "EMPRESA EJEMPLO", estén de acuerdo en ello. Cualquier defecto de los resultados esperados requerirá de interpretación para determinar si es un comportamiento normal o anormal. Tal interpretación se hará objetivamente por el personal de "EMPRESA EJEMPLO", hasta lograr un entendimiento satisfactorio por "EL CLIENTE".</p>
    <p>Si una prueba es interrumpida por cualquier defecto, la prueba puede reiniciarse total o parcialmente, sujeta a un acuerdo entre "EL CLIENTE" e "EMPRESA EJEMPLO". Cualquier cambio o ajuste en las pruebas, deberá ser mutuamente acordado entre "EL CLIENTE" e "EMPRESA EJEMPLO" y deberán ser plasmadas en la sección de "Acuerdos y/o Conclusiones" de este protocolo.</p>

    <bookmark content="3.4.- Tipos de defectos" level="1" />
    <tocentry content="3.4.- Tipos de defectos" level="1" />
    <h3>3.4.- Tipos de defectos</h3>
    <p>Los defectos probables que se pueden encontrar durante la revisión y/o desarrollo de las pruebas FAT son:</p>
    <p>Clase 1: Las pruebas no se pueden continuar hasta que se resuelva el defecto (Ejemplo: El producto no realiza ninguna de las acciones o actividades requeridas por "EL CLIENTE").</p>
    <p>Clase 2: Las pruebas no se interrumpen, pero el producto no puede ser aceptado hasta que el problema sea resuelto (Ejemplo: Fallas por defectos en el diseño o manufactura del producto o el producto puede generar incidentes).</p>
    <p>Clase 3: Las pruebas se aceptan, pero se deberán atender las Observaciones o No Conformidades encontradas (Ejemplo: Las etiquetas de identificación no corresponden, alguna modificación solicitada por "EL CLIENTE").</p>

    <bookmark content="3.4.1.- Acciones para atender los defectos" level="1" />
    <tocentry content="3.4.1.- Acciones para atender los defectos" level="1" />
    <h3>3.4.1.- Acciones para atender los defectos</h3>
    <p>Cualquier Observación o No Conformidad, así como las acciones a realizar para atenderlas, dependiendo del grado y tipo de defectos pueden ser registradas en la sección de Acuerdos y/o Conclusiones de este protocolo o en una minuta adjunta.</p>

    <bookmark content="4.- Desarrollo de las pruebas FAT" level="0" />
    <tocentry content="4.- Desarrollo de las pruebas FAT" level="0" />
    <h2>4.- Desarrollo de las pruebas FAT</h2>
    <p>Las pruebas FAT se desarrollarán de acuerdo con las siguientes etapas. En estas etapas se manifestará la SATISFACCIÓN de los resultados y del producto suministrado, así como los nombres y firmas de las personas que avalaron dicha decisión.</p>
    <div class="mini-indice-etapas" style="page-break-inside: avoid;">
        <p><b>ETAPA 1.-</b> Registro de personal</p>
        <p><b>ETAPA 2.-</b> Datos generales</p>
        <p><b>ETAPA 3.-</b> Revisión documental</p>
        <p><b>ETAPA 4.-</b> Inspección visual</p>
        <p><b>ETAPA 5.-</b> Pruebas de funcionalidad</p>
        <p><b>ETAPA 6.-</b> Acuerdos y/o conclusiones</p>
    </div>
</div>

<div class="etapa">
    <bookmark content="Etapa 1.- Registro de personal" level="1" />
    <tocentry content="Etapa 1.- Registro de personal" level="1" />
    <h2>Etapa 1.- Registro de personal</h2>
    <p>Los datos del personal participante en la realización de las pruebas FAT son:</p>
    <table class="vacia">
        <tr><th>Nombre</th><th>Cargo, Área y/o Departamento</th><th>Teléfono / Ext.</th></tr>
        <?php for ($i = 0; $i < 8; $i++): ?><tr><td></td><td></td><td></td></tr><?php endfor; ?>
    </table>
</div>

<div class="etapa">
    <bookmark content="Etapa 2.- Datos generales" level="1" />
    <tocentry content="Etapa 2.- Datos generales" level="1" />
    <h2>Etapa 2.- Datos generales</h2>

    <div class="barra-seccion">DATOS GENERALES DEL CLIENTE</div>
    <table class="datos-grid">
        <tr><td class="etiqueta">Cliente</td><td><?= $v($op['cliente']) ?></td><td class="etiqueta">Contrato / Orden de Compra</td><td><?= $v($op['orden_compra']) ?></td></tr>
        <tr><td class="etiqueta">Proyecto</td><td><?= $v($op['numero_proyecto']) ?></td><td></td><td></td></tr>
    </table>

    <div class="barra-seccion">DATOS DEL ACTUADOR</div>
    <table class="datos-grid">
        <tr><td class="etiqueta">Marca / Modelo del actuador</td><td>EMPRESA EJEMPLO / <?= $v($dg['actuador']['modelo']) ?></td><td class="etiqueta">No. de serie</td><td><?= $v($dg['actuador']['no_serie']) ?></td></tr>
        <tr><td class="etiqueta">TAG</td><td><?= $v($dg['actuador']['tag']) ?></td><td class="etiqueta">Tipo</td><td><?= $v($dg['actuador']['tipo']) ?></td></tr>
        <tr><td class="etiqueta">Presión de operación</td><td><?= $v($dg['actuador']['presion_operacion']) ?></td><td class="etiqueta">Tipo de suministro</td><td><?= $v($dg['actuador']['tipo_suministro']) ?></td></tr>
        <tr><td class="etiqueta">Falla segura</td><td><?= $v($dg['actuador']['falla_segura']) ?></td><td class="etiqueta">Tipo de operación manual</td><td><?= $v($dg['actuador']['tipo_operacion_manual']) ?></td></tr>
    </table>

    <div class="barra-seccion">DATOS DE LA VÁLVULA ACCIONADA</div>
    <table class="datos-grid">
        <tr><td class="etiqueta">Marca</td><td><?= $v($dg['valvula']['marca']) ?></td><td class="etiqueta">TAG</td><td><?= $v($dg['valvula']['tag']) ?></td></tr>
        <tr><td class="etiqueta">Tipo de válvula</td><td><?= $v($dg['valvula']['tipo']) ?></td><td class="etiqueta">Tamaño</td><td><?= $v($dg['valvula']['tamano']) ?></td></tr>
        <tr><td class="etiqueta">Clase</td><td><?= $v($dg['valvula']['clase']) ?></td><td class="etiqueta">Modelo</td><td><?= $v($dg['valvula']['modelo']) ?></td></tr>
        <tr><td class="etiqueta">No. de serie</td><td><?= $v($dg['valvula']['no_serie']) ?></td><td></td><td></td></tr>
    </table>
</div>

<div class="etapa">
    <bookmark content="Etapa 3.- Revisión documental" level="1" />
    <tocentry content="Etapa 3.- Revisión documental" level="1" />
    <h2>Etapa 3.- Revisión documental</h2>
    <p>Los documentos que forman parte del producto son los siguientes:</p>
    <table class="vacia">
        <tr><th>Documento</th><th>No.</th><th>Sí</th><th>No</th><th>No aplica</th></tr>
        <?php for ($i = 0; $i < 4; $i++): ?><tr><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </table>
</div>

<div class="etapa">
    <bookmark content="Etapa 4.- Inspección visual" level="1" />
    <tocentry content="Etapa 4.- Inspección visual" level="1" />
    <h2>Etapa 4.- Inspección visual</h2>
    <p>"EL CLIENTE" e "EMPRESA EJEMPLO" deben realizar una inspección visual del producto a suministrar y los elementos que formarán parte de las pruebas a realizar.</p>
    <table class="vacia">
        <tr><th>Materiales</th><th>Sí</th><th>No</th><th>No aplica</th></tr>
        <?php for ($i = 0; $i < 4; $i++): ?><tr><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </table>
</div>

<div class="etapa">
    <bookmark content="Etapa 5.- Pruebas de funcionalidad" level="1" />
    <tocentry content="Etapa 5.- Pruebas de funcionalidad" level="1" />
    <h2>Etapa 5.- Pruebas de funcionalidad</h2>
    <?php if (empty($pasos)): ?>
        <p style="color:#999;">No hay una Prueba FAT (checklist de Etapa 5) vinculada a esta Orden de Producción todavía.</p>
    <?php else: ?>
    <div class="pasos-etapa5">
        <?php foreach ($pasos as $paso): ?>
        <div class="paso">
            <div class="num"><?= mb_strtoupper(htmlspecialchars($paso['titulo'] ?? ''), 'UTF-8') ?></div>
            <?php if (!empty($paso['instrucciones'])): ?><div class="instr"><?= nl2br(mb_strtoupper(htmlspecialchars($paso['instrucciones']), 'UTF-8')) ?></div><?php endif; ?>
            <?php foreach (($paso['campos'] ?? []) as $campo):
                $etiqueta = mb_strtoupper(htmlspecialchars($campo['etiqueta'] ?? ''), 'UTF-8');
            ?>
            <div class="campo-linea">
                <?php if (($campo['tipo'] ?? '') === 'opciones'): ?>
                    <?= $etiqueta ?>:
                    <?php foreach (($campo['opciones'] ?? ['Abierto', 'Cerrado']) as $op): ?>
                        [ ] <?= mb_strtoupper(htmlspecialchars($op), 'UTF-8') ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?= $etiqueta ?>: ________________________<?php if (!empty($campo['unidad'])): ?> <?= mb_strtoupper(htmlspecialchars($campo['unidad']), 'UTF-8') ?><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="resultado">RESULTADO DE LA PRUEBA: [ ] SATISFACTORIA [ ] NO SATISFACTORIA</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p style="font-size:9px;color:#777;">Este listado y/o formato puede sufrir cambios de acuerdo con el alcance específico del proyecto y a las actividades a realizar en las Pruebas de Funcionalidad.</p>
</div>

<div class="etapa">
    <bookmark content="Etapa 6.- Acuerdos y/o conclusiones" level="1" />
    <tocentry content="Etapa 6.- Acuerdos y/o conclusiones" level="1" />
    <h2>Etapa 6.- Acuerdos y/o conclusiones</h2>
    <table class="vacia acuerdos">
        <tr><th>No.</th><th>Descripción</th><th>Nombre</th><th>Firma</th></tr>
        <?php for ($i = 0; $i < 5; $i++): ?><tr><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </table>
</div>

<!-- Hoja propia para Participantes -- pedido explícito del usuario (2026-08-28): antes el título se quedaba solo al fondo de la pagina de Acuerdos y la tabla de firmas arrancaba hasta la siguiente. -->
<div class="etapa">
    <h3>Participantes</h3>
    <?php /* Una tabla de 2 filas por pareja, no una sola tabla larga -- verificado con un render
             real: page-break-inside:avoid en <tr> no evita que el encabezado de una pareja quede
             solo al fondo de una página y su renglón en blanco se vaya a la siguiente; con tablas
             separadas mPDF nunca corta una pareja a la mitad. */ ?>
    <?php for ($i = 0; $i < 8; $i++): ?>
    <table class="firmas-tabla">
        <tr><th style="width:50%;">Nombre, Área/Cargo y Firma</th><th style="width:50%;">Nombre, Área/Cargo y Firma</th></tr>
        <tr><td></td><td></td></tr>
    </table>
    <?php endfor; ?>
</div>
<?php
$bodyHtml = ob_get_clean();

// ── Render ────────────────────────────────────────────────────────────────
// Márgenes izq/der = 20mm, medidos del .docx real. Arriba/abajo = 32mm (no los 25mm medidos):
// el encabezado real mide ~25mm de "distancia al margen" en Word, pero nuestro encabezado (título
// en 2 tamaños + fila de control) es más alto que eso -- verificado con un render real (Docker):
// con margin_top=32 el encabezado SÍ se encimaba con el cuerpo (el título de portada y el primer
// título de cada página quedaban debajo de la fila de control); 40mm ya no se encima.
// Arial real -- mPDF no la trae incluida (licencia de Microsoft, no se puede redistribuir), así
// que si el archivo no está ahí (falta copiarlo de C:\Windows\Fonts\ -- ver fonts/.gitignore) esta
// config no se toca y mPDF cae de vuelta a su fuente libre, sin tronar.
// Se arma ANTES de crear el Mpdf y se pasa en el constructor (no AddFontDirectory()/
// $mpdf->fontdata[] después) -- verificado con un render real: agregarlo después de construir no
// alcanza a afectar la resolución de "Arial" en el CSS por default; el PDF seguía saliendo con
// DejaVu. Se parte de getDefaults() de mPDF y se le agrega 'arial' encima para no perder acceso
// a sus demás fuentes internas (dejavusanscondensed, el respaldo de useSubstitutions abajo).
$mpdfConfig = [
    'format' => 'Letter',
    'margin_left' => 20,
    'margin_right' => 20,
    'margin_top' => 40,
    'margin_bottom' => 32,
    'margin_header' => 8,
    'margin_footer' => 8,
    // Sin esto, mPDF no tiene de dónde sacar un glifo que Arial no trae (el ☐ que usa la Etapa 5)
    // -- cae automáticamente a su fuente de respaldo (dejavusanscondensed) solo para esos
    // caracteres puntuales, el resto sigue en Arial.
    'useSubstitutions' => true,
];
$arialDir = __DIR__ . '/../fonts/arial';
$arialArchivos = ['R' => 'arial.ttf', 'B' => 'arialbd.ttf', 'I' => 'ariali.ttf', 'BI' => 'arialbi.ttf'];
$arialCompleto = true;
foreach ($arialArchivos as $archivo) {
    if (!file_exists($arialDir . '/' . $archivo)) { $arialCompleto = false; break; }
}
if ($arialCompleto) {
    $fontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $fontDirs[] = $arialDir;
    $fontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
    $fontData['arial'] = $arialArchivos;
    $mpdfConfig['fontDir'] = $fontDirs;
    $mpdfConfig['fontdata'] = $fontData;
    $mpdfConfig['default_font'] = 'arial';
}
$mpdf = new \Mpdf\Mpdf($mpdfConfig);
$mpdf->SetHTMLHeader($headerHtml);
$mpdf->SetHTMLFooter($footerHtml);
$mpdf->WriteHTML($bodyHtml);

$pdfBytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

$nombre_archivo = 'Protocolo_FAT_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $codigo_protocolo) . '.pdf';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($pdfBytes));
echo $pdfBytes;
