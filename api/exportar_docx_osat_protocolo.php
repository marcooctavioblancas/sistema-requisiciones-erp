<?php
/**
 * Protocolo OSAT (Pruebas de Aceptación en Sitio / On Site Acceptance Test) -- copia intencional
 * de api/exportar_docx_fat_protocolo.php con los textos cambiados según el documento de
 * referencia real (T491-OSAT_R0.docx, misma carpeta que T490-FAT_R2.docx): mismo diseño/etapas/
 * tablas, solo cambia "Fábrica/FAT" por "Sitio/OSAT" en los puntos puntuales de abajo, el código
 * de formato del pie (DOC-ING-FO-01E, no 01D) y que la ubicación de la prueba en 3.1 se deja en
 * blanco para llenar a mano (el OSAT se hace en el sitio del cliente, no en una dirección fija
 * de EMPRESA EJEMPLO como el FAT). Se duplica el archivo completo a propósito en vez de parametrizar
 * uno solo -- mismo criterio ya usado en todo este proyecto para documentos paralelos (cada uno
 * su propia copia independiente, ver includes/docx_writer.php y patrón de secciones de este
 * sistema).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/fat_protocolo.php';
require_once __DIR__ . '/../includes/docx_writer.php';

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

$codigo_protocolo = trim($op['numero_proyecto'] ?? '') !== '' ? $op['numero_proyecto'] . '-OSAT' : 'OSAT';
$val = fn(?string $s) => $s !== null && $s !== '' ? $s : null;

// Anchos de columna reutilizados (twips, carta con márgenes de 1" = 9360 de ancho útil) -- deben
// ser los mismos que usan los defaults de docxFilaEtiquetaValorLibre() en includes/docx_writer.php.
// docxTabla/docxTablaSinBordes necesitan esto explícito -- sin <w:tblGrid> Word auto-ajusta
// cada tabla al contenido en vez de respetar el ancho declarado (queda angosta y despareja).
$anchosLibreCol = [3340, 6020];

// LOGOFAT (carpeta Marcas) -- mismo logo que el FAT, mismo ajuste a la celda real del
// encabezado (ver exportar_docx_fat_protocolo.php).
$logo_path = UPLOAD_PATH . 'logo/logo_fat.jpg';
$tieneLogo = file_exists($logo_path);
const DOCX_HEADER_LOGO_CELDA_TWIPS = 3180;
[$logoEmuW, $logoEmuH] = $tieneLogo
    ? docxEmuManteniendoAspecto($logo_path, (DOCX_HEADER_LOGO_CELDA_TWIPS - 200) * 635)
    : [0, 0];

// ── Encabezado repetido en cada página (word/header1.xml real) ─────────────
// Medido directamente de T491-OSAT_R0.docx -- mismo layout que el FAT (logo + título en 2
// párrafos + fila Documento/Referencia/Cliente/No. página), solo cambia el texto del título.
$tituloHeaderPrincipal = 'PROTOCOLO PARA PRUEBAS DE ACEPTACIÓN EN SITIO "OSAT"';
$tituloHeaderSub = '(ON SITE ACCEPTANCE TEST)';
$tituloCorto = $tituloHeaderPrincipal . ' ' . $tituloHeaderSub; // usado en <title> y portada
$padYHeader = 0;
$header = docxTabla(
    docxFila(
        docxCelda($tieneLogo ? docxParrafoImagen($logoEmuW, $logoEmuH, 'rIdLogoHeader') : docxP('EMPRESA EJEMPLO', ['b' => true, 'align' => 'center', 'spacingAfter' => 0]), 3180, ['padY' => $padYHeader])
        . docxCelda(docxP($tituloHeaderPrincipal, ['b' => true, 'align' => 'center', 'size' => 28, 'spacingAfter' => 0]) . docxP($tituloHeaderSub, ['b' => true, 'align' => 'center', 'size' => 24, 'spacingAfter' => 0]), 6180, ['padY' => $padYHeader])
    ),
    [3180, 6180]
);
$header .= docxTabla(
    docxFila(
        docxCelda(docxP('DOCUMENTO:', ['size' => 14, 'spacingAfter' => 0]) . docxP($codigo_protocolo . ' R0', ['align' => 'center', 'size' => 18, 'spacingAfter' => 0]), 1540, ['padY' => $padYHeader])
        . docxCelda(docxP('REFERENCIA:', ['size' => 14, 'spacingAfter' => 0]) . docxP($op['orden_compra'] ?? '—', ['align' => 'center', 'size' => 18, 'spacingAfter' => 0]), 1540, ['padY' => $padYHeader])
        . docxCelda(docxP('CLIENTE:', ['size' => 14, 'spacingAfter' => 0]) . docxP($op['cliente'] ?? '—', ['align' => 'center', 'size' => 18, 'spacingAfter' => 0]), 5000, ['padY' => $padYHeader])
        . docxCelda(docxP('No. PÁGINA:', ['size' => 14, 'align' => 'center', 'spacingAfter' => 0]) . docxParrafoPaginaDeTotal(['align' => 'center', 'size' => 18, 'spacingAfter' => 0]), 1280, ['padY' => $padYHeader])
    ),
    [1540, 1540, 5000, 1280]
);

// ── Pie repetido en cada página (word/footer1.xml real) ─────────────────────
// DOC-ING-FO-01E (no 01D, ese es del FAT) -- medido en T491-OSAT_R0.docx; Revisión/Fecha iguales.
$footer = docxP('DOC-ING-FO-01E; Revisión N°: 6; Fecha: 10 / Sep / 2018.', ['b' => true, 'size' => 12, 'align' => 'left', 'spacingAfter' => 40]);
$footer .= docxP('Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización', ['size' => 12, 'align' => 'left']);

$body = '';

// ── Portada (solo página 1 -- el encabezado de control ya va en el header repetido) ──
$body .= docxP('', ['spacingAfter' => 400]);
$body .= docxP(mb_strtoupper($tituloCorto, 'UTF-8'), ['align' => 'center', 'size' => 32]);
$body .= docxP($codigo_protocolo, ['align' => 'center', 'b' => true, 'size' => 96, 'spacingAfter' => 300]);
$body .= docxP('FECHA DE PRUEBA OSAT: _____________________________', ['align' => 'center', 'b' => true, 'size' => 32, 'spacingAfter' => 200]);
$body .= docxTablaSinBordes(
    docxFilaEtiquetaValorLibre('Cliente:', $op['cliente'])
    . docxFilaEtiquetaValorLibre('Orden de Compra:', $op['orden_compra'])
    . docxFilaEtiquetaValorLibre('Producto:', $op['descripcion'])
    . docxFilaEtiquetaValorLibre('Marca:', 'EMPRESA EJEMPLO')
    . docxFilaEtiquetaValorLibre('Modelo:', $dg['actuador']['modelo']),
    $anchosLibreCol
);
$body .= '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';

// ── Índice (armado a mano, ver docxLineaIndice()) ───────────────────────────────────────────
$body .= docxP('CONTENIDO', ['align' => 'center', 'b' => true, 'size' => 32]);
$body .= docxLineaIndice('1', 'INTRODUCCIÓN', 'bm_intro');
$body .= docxLineaIndice('1.1', 'ALCANCE', 'bm_alcance');
$body .= docxLineaIndice('2', 'DEFINICIONES', 'bm_definiciones');
$body .= docxLineaIndice('3', 'PLANEACIÓN DE LAS PRUEBAS', 'bm_planeacion');
$body .= docxLineaIndice('3.1', 'PROGRAMACIÓN', 'bm_programacion');
$body .= docxLineaIndice('3.2', 'PERSONAL', 'bm_personal');
$body .= docxLineaIndice('3.3', 'CRITERIO DE ACEPTACIÓN PARA LAS PRUEBAS OSAT', 'bm_criterio');
$body .= docxLineaIndice('3.4', 'TIPOS DE DEFECTOS', 'bm_tipos');
$body .= docxLineaIndice('3.4.1', 'ACCIONES PARA ATENDER LOS DEFECTOS', 'bm_acciones');
$body .= docxLineaIndice('4.', 'DESARROLLO DE LAS PRUEBAS OSAT', 'bm_desarrollo');
$body .= docxLineaIndice('ETAPA 1.-', 'REGISTRO DE PERSONAL', 'bm_etapa1');
$body .= docxLineaIndice('ETAPA 2.-', 'DATOS GENERALES', 'bm_etapa2');
$body .= docxLineaIndice('ETAPA 3.-', 'REVISIÓN DOCUMENTAL', 'bm_etapa3');
$body .= docxLineaIndice('ETAPA 4.-', 'INSPECCIÓN VISUAL', 'bm_etapa4');
$body .= docxLineaIndice('ETAPA 5.-', 'PRUEBAS DE FUNCIONALIDAD', 'bm_etapa5');
$body .= docxLineaIndice('ETAPA 6.-', 'ACUERDOS Y/O CONCLUSIONES', 'bm_etapa6');
$body .= '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';

// ── Secciones 1-4 (boilerplate institucional) ───────────────────────────
$body .= docxTitulo('1.- Introducción', 0, ['bookmark' => 'bm_intro']);
// "y" (no "e") antes del nombre del cliente -- medido en T491-OSAT_R0.docx, distinto del FAT.
$body .= docxParrafoMixto([
    ['texto' => 'El fin de este documento es manifestar los acuerdos alcanzados entre Empresa Ejemplo S.A. de C.V y '],
    ['texto' => $val($op['cliente']) ?? '—', 'u' => true, 'color' => '0000CC'],
    ['texto' => ' quienes en lo sucesivo serán designados como "EMPRESA EJEMPLO" y "EL CLIENTE" respectivamente; describe y establece los parámetros para la revisión y aprobación de los productos a suministrar por parte de "EMPRESA EJEMPLO" a "EL CLIENTE".'],
]);
$body .= docxTitulo('1.1.- Alcance', 1, ['bookmark' => 'bm_alcance']);
$body .= docxP('Las Pruebas de Aceptación en Sitio (On Site Acceptance Test "OSAT") demostrarán que el producto suministrado y sus componentes funcionan correctamente. Además de que se cumple, en todos los aspectos, con los requisitos de la orden de compra, contrato y/o la(s) hoja(s) de especificaciones proporcionada(s) por "EL CLIENTE". Por ende, cualquier otro requerimiento que no esté debidamente estipulado en estos queda fuera del alcance de este protocolo.');

$body .= docxTitulo('2.- Definiciones', 0, ['bookmark' => 'bm_definiciones']);
$definiciones = [
    'DEFECTO.' => 'No conformidad relativa a un uso previsto o especificado.',
    'EQUIPO DE MEDICIÓN.' => 'Instrumento de medición, material de referencia o equipos auxiliares que permiten determinar el valor de una magnitud.',
    'ERROR.' => 'Diferencia entre el valor medido o calculado y el real.',
    'FILOSOFÍA DE OPERACIÓN.' => 'Enunciado que define las condiciones críticas de operación que debe satisfacer el producto a suministrar. Esta detalla la función de cada uno de los componentes que integran el producto final.',
    'ESPECIFICACIÓN TÉCNICA.' => 'Documento que define las características que se requieren de un producto o un servicio. Información proporcionada de un producto, la cual describe componentes, características y su funcionamiento.',
    'INCIDENTE.' => 'Suceso que surge del trabajo que podría tener o tiene como resultado lesiones o deterioro de la salud.',
    'NO CONFORMIDAD.' => 'Incumplimiento de un requisito.',
    'PRODUCTO.' => 'Bien material, servicio o idea que puede satisfacer una necesidad o requerimiento. Este puede ser integrado por más de un equipo y/o accesorio.',
    // Única definición cuyo texto cambia -- "EN SITIO (OSAT)" en vez de "EN FÁBRICA (FAT)",
    // cuerpo de la definición idéntico (así está también en T491-OSAT_R0.docx, sin actualizar
    // la mención a "instalaciones de EMPRESA EJEMPLO" pese a que el OSAT es en el sitio del cliente).
    'PRUEBAS DE ACEPTACIÓN EN SITIO (OSAT).' => 'Procedimiento de pruebas específico aplicable al producto a suministrar para verificar y garantizar que la filosofía de operación o especificación técnica satisface el requerimiento de aplicación final señalado por "EL CLIENTE". Las pruebas se llevan a cabo en las instalaciones de "EMPRESA EJEMPLO" antes de la instalación final del producto en campo.',
];
foreach ($definiciones as $termino => $desc) {
    $body .= docxP($termino . ' ' . $desc, ['spacingAfter' => 100]);
    $body .= docxP('', ['spacingAfter' => 0]);
}

$body .= docxTitulo('3.- Planeación de las pruebas', 0, ['bookmark' => 'bm_planeacion']);
$body .= docxTitulo('3.1.- Programación', 1, ['bookmark' => 'bm_programacion']);
// Ubicación en blanco -- pedido explícito/medido en T491-OSAT_R0.docx: a diferencia del FAT (que
// siempre es en las instalaciones fijas de EMPRESA EJEMPLO), el OSAT se hace en el sitio del cliente,
// que varía por proyecto -- no hay una dirección fija que auto-llenar aquí.
$fechaProgramada = !empty($fatUsada['fecha_programada']) ? date('d/m/Y', strtotime($fatUsada['fecha_programada'])) : null;
$body .= docxParrafoMixto([
    ['texto' => 'Las Pruebas de Aceptación en Sitio (OSAT) se realizan el día '],
    $fechaProgramada !== null
        ? ['texto' => $fechaProgramada, 'u' => true, 'color' => '0000CC']
        : ['texto' => '________________________'],
    ['texto' => ' en ________________________________________________.'],
]);
$body .= docxTitulo('3.2.- Personal', 1, ['bookmark' => 'bm_personal']);
$body .= docxP('"EL CLIENTE" tendrá participantes / representantes que testifiquen las pruebas y serán quienes estarán autorizados para aceptar las pruebas a realizar. "EMPRESA EJEMPLO" tendrá personal capacitado acorde a los requerimientos del producto para la realización de estas. Solo el personal de "EL CLIENTE" o de "EMPRESA EJEMPLO" con conocimiento de causa en el desarrollo de esta tendrá permitido el acceso al producto en todo momento.');
$body .= docxTitulo('3.3.- Criterio de aceptación para las pruebas OSAT', 1, ['bookmark' => 'bm_criterio']);
$body .= docxP('La(s) prueba(s) se considerarán satisfactorias cuando "EL CLIENTE" e "EMPRESA EJEMPLO", estén de acuerdo en ello. Cualquier defecto de los resultados esperados requerirá de interpretación para determinar si es un comportamiento normal o anormal. Tal interpretación se hará objetivamente por el personal de "EMPRESA EJEMPLO", hasta lograr un entendimiento satisfactorio por "EL CLIENTE".');
$body .= docxP('Si una prueba es interrumpida por cualquier defecto, la prueba puede reiniciarse total o parcialmente, sujeta a un acuerdo entre "EL CLIENTE" e "EMPRESA EJEMPLO". Cualquier cambio o ajuste en las pruebas, deberá ser mutuamente acordado entre "EL CLIENTE" e "EMPRESA EJEMPLO" y deberán ser plasmadas en la sección de "Acuerdos y/o Conclusiones" de este protocolo.');
$body .= docxTitulo('3.4.- Tipos de defectos', 1, ['bookmark' => 'bm_tipos']);
$body .= docxP('Los defectos probables que se pueden encontrar durante la revisión y/o desarrollo de las pruebas OSAT son:');
$body .= docxP('Clase 1: Las pruebas no se pueden continuar hasta que se resuelva el defecto (Ejemplo: El producto no realiza ninguna de las acciones o actividades requeridas por "EL CLIENTE").');
$body .= docxP('Clase 2: Las pruebas no se interrumpen, pero el producto no puede ser aceptado hasta que el problema sea resuelto (Ejemplo: Fallas por defectos en el diseño o manufactura del producto o el producto puede generar incidentes).');
$body .= docxP('Clase 3: Las pruebas se aceptan, pero se deberán atender las Observaciones o No Conformidades encontradas (Ejemplo: Las etiquetas de identificación no corresponden, alguna modificación solicitada por "EL CLIENTE").');
$body .= docxTitulo('3.4.1.- Acciones para atender los defectos', 1, ['bookmark' => 'bm_acciones']);
$body .= docxP('Cualquier Observación o No Conformidad, así como las acciones a realizar para atenderlas, dependiendo del grado y tipo de defectos pueden ser registradas en la sección de Acuerdos y/o Conclusiones de este protocolo o en una minuta adjunta.');

$body .= docxTitulo('4.- Desarrollo de las pruebas OSAT', 0, ['bookmark' => 'bm_desarrollo']);
$body .= docxP('Las pruebas OSAT se desarrollarán de acuerdo con las siguientes etapas. En estas etapas se manifestará la SATISFACCIÓN de los resultados y del producto suministrado, así como los nombres y firmas de las personas que avalaron dicha decisión.');
foreach ([
    'Registro de personal', 'Datos generales', 'Revisión documental',
    'Inspección visual', 'Pruebas de funcionalidad', 'Acuerdos y/o conclusiones',
] as $i => $tituloEtapa) {
    $body .= docxParrafoMixto([
        ['texto' => 'ETAPA ' . ($i + 1) . '.-'],
        ['texto' => "\t" . mb_strtoupper($tituloEtapa, 'UTF-8')],
    ], ['spacingAfter' => 0, 'indLeft' => 360]);
}

// ── Etapa 1 ──────────────────────────────────────────────────────────────
$body .= docxTitulo('Etapa 1.- Registro de personal', 1, ['align' => 'center', 'u' => true, 'bookmark' => 'bm_etapa1']);
$body .= docxP('Los datos del personal participante en la realización de las pruebas OSAT son:');
$body .= docxTablaVacia(['Nombre', 'Cargo, Área y/o Departamento', 'Teléfono / Ext.'], 8);

// ── Etapa 2 ──────────────────────────────────────────────────────────────
// Rejilla fina de 12 columnas -- ver el comentario largo en exportar_docx_fat_protocolo.php
// (misma estructura exacta, medida contra T491-OSAT_R0.docx).
$anchosGrid = array_fill(0, 12, DOCX_ETAPA2_COL);
$body .= docxTitulo('Etapa 2.- Datos generales', 1, ['align' => 'center', 'u' => true, 'bookmark' => 'bm_etapa2']);

$filasEtapa2 = docxFilaBarraColspan('DATOS GENERALES DEL CLIENTE', 9360, 12)
    . docxFilasClienteContrato($op['cliente'], $op['numero_proyecto'], $op['orden_compra'])
    . docxFilaBarraColspan('DATOS DEL ACTUADOR', 9360, 12)
    . docxFilaCamposGrid([['Marca / Modelo del actuador', 'EMPRESA EJEMPLO / ' . ($val($dg['actuador']['modelo']) ?? ''), 2, 4], ['No. de serie', $dg['actuador']['no_serie'], 2, 4]])
    . docxFilaCamposGrid([['TAG', $dg['actuador']['tag'], 2, 4], ['Tipo', $dg['actuador']['tipo'], 2, 4]])
    . docxFilaCamposGrid([['Presión de operación', $dg['actuador']['presion_operacion'], 2, 4], ['Tipo de suministro', $dg['actuador']['tipo_suministro'], 2, 4]])
    . docxFilaCamposGrid([['Falla segura', $dg['actuador']['falla_segura'], 2, 4], ['Tipo de operación manual', $dg['actuador']['tipo_operacion_manual'], 2, 4]])
    . docxFilaBarraColspan('DATOS DE LA VÁLVULA ACCIONADA', 9360, 12)
    . docxFilaCamposGrid([['Marca', $dg['valvula']['marca'], 2, 4], ['TAG', $dg['valvula']['tag'], 2, 4]])
    // Primer par a 2+4 (igual que Marca/TAG arriba) para que "Tamaño:" arranque exactamente
    // donde arranca "TAG:" en el renglón de arriba -- pedido explícito (2026-09-03). Etiqueta de
    // "Tamaño" a 2 columnas (no 1) para que no se parta en 2 líneas ("Tamaño:" no cabe en 780
    // twips); "Clase:" sí cabe en 1 columna, se le deja el resto a su valor.
    . docxFilaCamposGrid([['Tipo de válvula', $dg['valvula']['tipo'], 2, 4], ['Tamaño', $dg['valvula']['tamano'], 2, 1], ['Clase', $dg['valvula']['clase'], 1, 2]])
    . docxFilaCamposGrid([['Modelo', $dg['valvula']['modelo'], 2, 4], ['No. de serie', $dg['valvula']['no_serie'], 2, 4]]);
$body .= docxTabla($filasEtapa2, $anchosGrid);

// ── Etapa 3 ──────────────────────────────────────────────────────────────
$body .= docxTitulo('Etapa 3.- Revisión documental', 1, ['align' => 'center', 'u' => true, 'bookmark' => 'bm_etapa3']);
$body .= docxP('Los documentos que forman parte del producto son los siguientes:');
$body .= docxTablaVacia(['Documento', 'Sí', 'No', 'No aplica'], 4);

// ── Etapa 4 ──────────────────────────────────────────────────────────────
$body .= docxTitulo('Etapa 4.- Inspección visual', 1, ['align' => 'center', 'u' => true, 'bookmark' => 'bm_etapa4']);
$body .= docxP('"EL CLIENTE" e "EMPRESA EJEMPLO" deben realizar una inspección visual del producto a suministrar y los elementos que formarán parte de las pruebas a realizar.');
$body .= docxTablaVacia(['Materiales', 'Sí', 'No', 'No aplica'], 4);

// ── Etapa 5 (reusa pruebas_fat.checklist -- mismo checklist que el FAT) ──────
$body .= docxTitulo('Etapa 5.- Pruebas de funcionalidad', 1, ['align' => 'center', 'u' => true, 'bookmark' => 'bm_etapa5']);
if (empty($pasos)) {
    $body .= docxP('No hay pasos de Etapa 5 (checklist) vinculados a esta Orden de Producción todavía.', ['color' => '999999']);
} else {
    $filasEtapa5 = '';
    foreach ($pasos as $i => $paso) {
        $filasEtapa5 .= docxPasoFatFila($paso, $i + 1);
    }
    $body .= docxTabla($filasEtapa5, [500, 8860]);
}
$body .= docxP('Este listado y/o formato puede sufrir cambios de acuerdo con el alcance específico del proyecto y a las actividades a realizar en las Pruebas de Funcionalidad.', ['align' => 'center', 'b' => true, 'i' => true, 'size' => 14]);

// ── Etapa 6 + Participantes ───────────────────────────────────────────────
$body .= docxTitulo('Etapa 6.- Acuerdos y/o conclusiones', 1, ['align' => 'center', 'u' => true, 'bookmark' => 'bm_etapa6']);
$anchosEtapa6 = [2340, 2340, 2340, 2340];
$filasEtapa6 = docxTablaVaciaFilas(['No.', 'Descripción', 'Nombre', 'Firma'], $anchosEtapa6, 5, 700);
$filasEtapa6 .= docxFilaBarraColspan('PARTICIPANTES', 9360, 4, 'F2F2F2');
for ($i = 0; $i < 8; $i++) {
    $filasEtapa6 .= docxFila(
        docxCelda(docxP('Nombre, Área/Cargo y Firma', ['b' => true, 'color' => '0000CC', 'align' => 'center']), 4680, ['colspan' => 2])
        . docxCelda(docxP('Nombre, Área/Cargo y Firma', ['b' => true, 'color' => '0000CC', 'align' => 'center']), 4680, ['colspan' => 2])
    );
    $filasEtapa6 .= docxFila(
        docxCelda('', 4680, ['colspan' => 2]) . docxCelda('', 4680, ['colspan' => 2]),
        1300
    );
}
$body .= docxTabla($filasEtapa6, $anchosEtapa6);

$bytes = docxGenerar($body, $tieneLogo ? $logo_path : null, $header, $footer);

$nombre_archivo = 'Protocolo_OSAT_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $codigo_protocolo) . '.docx';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
