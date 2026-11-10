<?php
/**
 * Capa de datos compartida del Protocolo de Pruebas FAT -- la usan tanto
 * api/vista_pdf_fat_protocolo.php como api/exportar_docx_fat_protocolo.php para no duplicar
 * las consultas ni el criterio de "cuál pruebas_fat usar" en dos lados.
 */

/** Campos de Etapa 2 (Datos Generales) que puede llenar la mini-IA o corregir un humano. */
function fatProtocoloCamposDatosGenerales(): array
{
    return [
        'actuador' => ['marca', 'modelo', 'no_serie', 'tag', 'tipo', 'presion_operacion', 'tipo_suministro', 'falla_segura', 'tipo_operacion_manual'],
        'valvula'  => ['marca', 'tag', 'tipo', 'tamano', 'clase', 'modelo', 'no_serie'],
    ];
}

/** Normaliza datos_generales a la forma esperada, rellenando con null lo que falte. */
function fatProtocoloNormalizarDatosGenerales($decoded): array
{
    $campos = fatProtocoloCamposDatosGenerales();
    $out = [];
    foreach ($campos as $grupo => $lista) {
        $out[$grupo] = [];
        foreach ($lista as $campo) {
            $v = $decoded[$grupo][$campo] ?? null;
            $out[$grupo][$campo] = (is_string($v) && trim($v) !== '') ? trim($v) : null;
        }
    }
    return $out;
}

/**
 * Reúne todo lo necesario para renderizar el protocolo (PDF o Word) de una Orden de Producción.
 * Devuelve null si la Orden de Producción no existe.
 */
function obtenerDatosFatProtocolo(PDO $db, int $ordenProduccionId): ?array
{
    $stmt = $db->prepare("SELECT * FROM ordenes_produccion WHERE id = ?");
    $stmt->execute([$ordenProduccionId]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$op) return null;

    $items = json_decode($op['items'] ?? '[]', true) ?: [];

    $fpStmt = $db->prepare("SELECT * FROM fat_protocolos WHERE orden_produccion_id = ?");
    $fpStmt->execute([$ordenProduccionId]);
    $protocolo = $fpStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $datosGenerales = fatProtocoloNormalizarDatosGenerales(
        $protocolo ? (json_decode($protocolo['datos_generales'] ?? '{}', true) ?: []) : []
    );

    $fatUsada = null;
    $pruebasFatId = $protocolo['pruebas_fat_id'] ?? null;
    if ($pruebasFatId) {
        $f = $db->prepare("SELECT * FROM pruebas_fat WHERE id = ?");
        $f->execute([$pruebasFatId]);
        $fatUsada = $f->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (!empty($op['proyecto_id'])) {
        // Sin FAT vinculada todavía -- toma la más reciente del proyecto como default.
        $f = $db->prepare("SELECT * FROM pruebas_fat WHERE proyecto_id = ? ORDER BY id DESC LIMIT 1");
        $f->execute([$op['proyecto_id']]);
        $fatUsada = $f->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $pasos = $fatUsada ? (json_decode($fatUsada['checklist'] ?? '[]', true) ?: []) : [];

    return [
        'orden_produccion' => $op,
        'items'            => $items,
        'protocolo_id'     => $protocolo['id'] ?? null,
        'datos_generales'  => $datosGenerales,
        'pruebas_fat'      => $fatUsada,
        'pasos'            => $pasos,
        'archivo_subido'   => !empty($protocolo['archivo_subido_ruta']) ? [
            'ruta'            => $protocolo['archivo_subido_ruta'],
            'nombre'          => $protocolo['archivo_subido_nombre'],
            'fecha'           => $protocolo['archivo_subido_fecha'],
            'revision_estado' => $protocolo['revision_estado'] ?? 'pendiente',
            'revision_motivo' => $protocolo['revision_motivo'] ?? null,
            'revision_fecha'  => $protocolo['revision_fecha'] ?? null,
        ] : null,
    ];
}

/**
 * Etapa 5 completa = la estructura de pasos (título/instrucciones/campos) ya está armada -- el
 * documento se imprime y se llena a mano, así que "completa" no es sobre valores capturados,
 * es sobre que el cascarón exista.
 */
function fatProtocoloEtapa5Completa(array $pasos): bool
{
    return !empty($pasos);
}

/** Pruebas FAT del proyecto dueño de una Orden de Producción, para que el usuario elija cuál usar. */
function listarPruebasFatDeProyecto(PDO $db, int $proyectoId): array
{
    $stmt = $db->prepare("SELECT id, folio, titulo, estado FROM pruebas_fat WHERE proyecto_id = ? ORDER BY id DESC");
    $stmt->execute([$proyectoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Texto fuente para la mini-IA de Etapa 2: descripción general de la OP + descripción de
 * TODOS los items -- los datos técnicos (presión, falla segura, tamaño/clase de válvula, etc.)
 * casi siempre viven en la descripción del item, no en la descripción general de la OP.
 */
function textoParaAnalisisFat(array $op, array $items): string
{
    $partes = [];
    if (!empty($op['descripcion'])) $partes[] = trim($op['descripcion']);
    foreach ($items as $it) {
        if (!empty($it['desc'])) $partes[] = trim($it['desc']);
    }
    return implode("\n\n", $partes);
}
