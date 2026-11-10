<?php
/**
 * Repositorio de Auditorías SGI (Sistema de Gestión Integrado) -- 11 departamentos, cada uno
 * con su propia "Lista de verificación" (DOC-SGI-FO-03C, plantillas reales en
 * plantillas/auditoria_sgi/). Al generar, se copia la plantilla real y solo se sustituyen
 * "No. DE AUDITORÍA" y "FECHA" -- el resto (checklist, Responsable, Auditor) se llena a mano en
 * papel, pedido explícito del usuario (2026-09-21): no se reconstruye el documento desde cero
 * como el FAT/OSAT (docx_writer.php), son 11 checklists distintos y de todos modos se firman
 * en papel.
 */

const AUDITORIA_SGI_DEPARTAMENTOS = [
    'RRHH'        => 'DOC-SGI-FO-03C Listas de verificación RRHH.docx',
    'Servicios'   => 'DOC-SGI-FO-03C Listas de verificación Servicios.docx',
    'SGAS'        => 'DOC-SGI-FO-03C Listas de verificación SGAS.docx',
    'SGC'         => 'DOC-SGI-FO-03C Listas de verificación SGC.docx',
    'Ventas'      => 'DOC-SGI-FO-03C Listas de verificación Ventas.docx',
    'Almacén'     => 'DOC-SGI-FO-03C Listas de verificación Almacen.docx',
    'Compras'     => 'DOC-SGI-FO-03C Listas de verificación Compras.docx',
    'Dirección G' => 'DOC-SGI-FO-03C Listas de verificación Direccion G.docx',
    'Ingeniería'  => 'DOC-SGI-FO-03C Listas de verificación Ingenieria.docx',
    'Producción'  => 'DOC-SGI-FO-03C Listas de verificación Produccion.docx',
    'Proyectos'   => 'DOC-SGI-FO-03C Listas de verificación Proyectos.docx',
];

function auditoriaSgiCalcularNumero(PDO $db, string $fecha): string {
    $anio = date('Y', strtotime($fecha));
    $stmt = $db->prepare("SELECT COUNT(*) FROM auditorias_sgi WHERE YEAR(fecha) = ?");
    $stmt->execute([$anio]);
    $consecutivo = (int)$stmt->fetchColumn() + 1;
    return $anio . '-' . str_pad((string)$consecutivo, 2, '0', STR_PAD_LEFT);
}

/**
 * Reemplaza el contenido (runs) de la celda de tabla inmediatamente siguiente a la que contiene
 * $etiqueta -- funciona sin importar cuántos <w:r> tenga esa celda actualmente (en las
 * plantillas reales el número de auditoría existente quedó partido en 3-4 runs por cómo Word
 * lo guardó, y la celda de Fecha está vacía). Probado contra los 11 documentos reales antes de
 * usarse aquí.
 */
function auditoriaSgiReemplazarCeldaSiguiente(string $xml, string $etiqueta, string $nuevoValor): string {
    $posEtiqueta = strpos($xml, $etiqueta);
    if ($posEtiqueta === false) return $xml;
    $posCierreCelda = strpos($xml, '</w:tc>', $posEtiqueta);
    if ($posCierreCelda === false) return $xml;
    $posSiguienteTc = strpos($xml, '<w:tc>', $posCierreCelda);
    if ($posSiguienteTc === false) return $xml;
    $posPPrCierre = strpos($xml, '</w:pPr>', $posSiguienteTc);
    if ($posPPrCierre === false) return $xml;
    $posPPrCierre += strlen('</w:pPr>');
    $posPCierre = strpos($xml, '</w:p>', $posPPrCierre);
    if ($posPCierre === false) return $xml;

    $nuevoRun = '<w:r><w:t xml:space="preserve">' . htmlspecialchars($nuevoValor, ENT_XML1 | ENT_QUOTES) . '</w:t></w:r>';
    return substr($xml, 0, $posPPrCierre) . $nuevoRun . substr($xml, $posPCierre);
}

/** Genera el .docx de un departamento para una auditoría específica y lo manda al navegador. */
function auditoriaSgiGenerarYEnviar(string $departamento, string $numero, string $fechaFormateada): void {
    if (!isset(AUDITORIA_SGI_DEPARTAMENTOS[$departamento])) {
        http_response_code(404);
        exit('Departamento no encontrado');
    }
    $rutaPlantilla = __DIR__ . '/../plantillas/auditoria_sgi/' . AUDITORIA_SGI_DEPARTAMENTOS[$departamento];
    if (!file_exists($rutaPlantilla)) {
        http_response_code(404);
        exit('Plantilla no encontrada en el servidor');
    }

    $tmpDocx = tempnam(sys_get_temp_dir(), 'aud_sgi_') . '.docx';
    copy($rutaPlantilla, $tmpDocx);

    $zip = new ZipArchive();
    if ($zip->open($tmpDocx) !== true) {
        @unlink($tmpDocx);
        http_response_code(500);
        exit('No se pudo abrir la plantilla');
    }
    $xml = $zip->getFromName('word/document.xml');
    $xml = auditoriaSgiReemplazarCeldaSiguiente($xml, 'No. DE AUDITOR', $numero);
    $xml = auditoriaSgiReemplazarCeldaSiguiente($xml, 'FECHA', $fechaFormateada);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();

    $nombreDescarga = 'DOC-SGI-FO-03C Lista de verificacion ' . $departamento . ' ' . $numero . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');
    header('Content-Length: ' . filesize($tmpDocx));
    readfile($tmpDocx);
    @unlink($tmpDocx);
}
