<?php
/**
 * API Calendario — Devuelve eventos en formato FullCalendar v6
 * Filtrado por rol: ingenieria/proyectos → solo 'ingenieria'
 *                  admin                 → todos
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

if (!hasAcceso('ingenieria') && !hasAcceso('proyectos') && !isAdmin()) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$db = getDB();

$tipo_valor = null;
if (!isAdmin()) {
    $tipo_valor = hasAcceso('servicios') ? 'servicios' : 'ingenieria';
}

if ($tipo_valor !== null) {
    $sql = "SELECT ce.*, p.nombre as proyecto_nombre, p.estado as proyecto_estado
            FROM calendario_eventos ce
            JOIN proyectos p ON ce.proyecto_id = p.id
            WHERE ce.tipo = ?
            ORDER BY ce.fecha_inicio";
    $stmt = $db->prepare($sql);
    $stmt->execute([$tipo_valor]);
} else {
    $sql = "SELECT ce.*, p.nombre as proyecto_nombre, p.estado as proyecto_estado
            FROM calendario_eventos ce
            JOIN proyectos p ON ce.proyecto_id = p.id
            ORDER BY ce.fecha_inicio";
    $stmt = $db->query($sql);
}

$eventos = [];
foreach ($stmt->fetchAll() as $e) {
    $color  = getColorProyecto($e['tipo']);
    $estado = $e['proyecto_estado'] ?? 'activo';

    $hoy        = new DateTime();
    $dias_texto = '';
    if ($e['fecha_fin']) {
        $fin        = new DateTime($e['fecha_fin']);
        $diff       = $hoy->diff($fin);
        $dias       = (int)$diff->format('%r%a');
        $dias_texto = $dias >= 0 ? "$dias días restantes" : abs($dias) . " días de retraso";
    } elseif ($e['fecha_inicio']) {
        $ini        = new DateTime($e['fecha_inicio']);
        $diff       = $hoy->diff($ini);
        $dias_desde = (int)$diff->format('%r%a') * -1;
        $dias_texto = $dias_desde >= 0 ? "Iniciado hace $dias_desde días" : "Inicio en " . abs($dias_desde) . " días";
    }

    $eventos[] = [
        'id'    => $e['id'],
        'title' => $e['proyecto_nombre'],
        'start' => $e['fecha_inicio'],
        'end'   => $e['fecha_fin'] ?? null,
        'color' => $color,
        'extendedProps' => [
            'tipo'        => $e['tipo'],
            'estado'      => $estado,
            'dias_texto'  => $dias_texto,
            'proyecto_id' => $e['proyecto_id'],
        ],
    ];
}

echo json_encode($eventos);
