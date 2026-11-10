<?php
/**
 * API de Materiales
 * GET  ?action=check_codigo&codigo=XXX[&exclude_id=N]  → {disponible: bool}
 * GET  ?action=carpetas                                 → [{id,nombre,padre_id}]
 * POST action=crear_carpeta   nombre, padre_id?         → {success, id}
 * POST action=mover_material  material_id, carpeta_id   → {success}
 * GET  ?action=sugerir_codigo&descripcion=XXX            → {codigo: string|null}
 * GET  ?action=prefijos                                  → [{prefijo,n,ejemplo}] (solo jefe Almacén/admin)
 * GET  ?action=obtener&id=N                              → {id,codigo,codigo_verificado,descripcion,...}
 * POST action=marcar_visto   id                          → {success} (fija primera_vista, saca el material del frente del Inventario)
 * GET  ?action=buscar_modelos&q=XXX                      → [string] (hasta 30 modelos existentes que coinciden, para el datalist de "Modelo" al agregar material)
 * GET  (sin action)                                     → lista filtrada de materiales (comportamiento original)
 */

require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit();
}

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? '';

// ── Routing ─────────────────────────────────────────────────────
if ($action === 'check_codigo') {
    $codigo     = trim($_GET['codigo'] ?? '');
    $exclude_id = intval($_GET['exclude_id'] ?? 0);

    if ($codigo === '') {
        echo json_encode(['disponible' => false, 'error' => 'Código vacío']);
        exit();
    }

    $sql    = "SELECT id FROM materiales WHERE codigo = ? AND activo = 1";
    $params = [$codigo];
    if ($exclude_id > 0) {
        $sql    .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['disponible' => $stmt->rowCount() === 0]);
    exit();
}

if ($action === 'buscar_modelos') {
    // 1547 modelos distintos en el catálogo real (2026-08-25) -- muy pesado para un
    // <datalist> precargado completo, y el usuario pidió explícitamente que se filtre en
    // el servidor conforme se escribe, mismo patrón que el resto de los buscadores del
    // sistema, para evitar altas duplicadas del mismo modelo con distinta capitalización/
    // espacios que nadie nota a simple vista entre miles de opciones.
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q, 'UTF-8') < 2) {
        echo json_encode([]);
        exit();
    }
    $stmt = $db->prepare("
        SELECT DISTINCT modelo FROM materiales
        WHERE modelo IS NOT NULL AND modelo LIKE CONCAT('%', ?, '%')
        ORDER BY modelo LIMIT 30
    ");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    exit();
}

if ($action === 'sugerir_codigo') {
    $descripcion = trim($_GET['descripcion'] ?? '');
    echo json_encode(['codigo' => $descripcion !== '' ? sugerirCodigoMaterial($db, $descripcion) : null]);
    exit();
}

if ($action === 'prefijos') {
    if (!esJefeDe('almacen') && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Solo el jefe de Almacén puede ver/asignar prefijos de código']);
        exit();
    }
    echo json_encode(['success' => true, 'data' => prefijosMaterialesExistentes($db)]);
    exit();
}

if ($action === 'marcar_visto' && $method === 'POST') {
    // Saca al material del frente del Inventario -- se llama en cuanto alguien lo abre
    // (abrirModalEditarMaterial en almacen2.php), sin importar si termina guardando cambios
    // o no. Idempotente: si ya tenía primera_vista, no la vuelve a tocar.
    $id = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'id requerido']); exit(); }
    $db->prepare("UPDATE materiales SET primera_vista = NOW() WHERE id = ? AND primera_vista IS NULL")->execute([$id]);
    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'obtener') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'id requerido']); exit(); }
    $stmt = $db->prepare("SELECT id, codigo, codigo_verificado, descripcion, categoria_id, marca, modelo, unidad_medida, ubicacion, stock_minimo, stock_disponible FROM materiales WHERE id = ?");
    $stmt->execute([$id]);
    $mat = $stmt->fetch();
    echo json_encode($mat ? ['success' => true, 'data' => $mat] : ['success' => false, 'error' => 'No encontrado']);
    exit();
}

if ($action === 'carpetas') {
    $stmt = $db->query("SELECT id, nombre, padre_id FROM carpetas_materiales ORDER BY nombre");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit();
}

if ($action === 'crear_carpeta' && $method === 'POST') {
    $roles_permitidos = ['admin','ingenieria','compras','almacen'];
    $tiene_permiso    = false;
    foreach ($roles_permitidos as $r) { if (hasAcceso($r)) { $tiene_permiso = true; break; } }
    if (!$tiene_permiso) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Sin permiso']); exit(); }

    $nombre   = trim(sanitize($_POST['nombre'] ?? ''));
    $padre_id = intval($_POST['padre_id'] ?? 0) ?: null;

    if ($nombre === '') { echo json_encode(['success' => false, 'error' => 'Nombre requerido']); exit(); }

    $nextId = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM carpetas_materiales")->fetchColumn();
    $stmt = $db->prepare("INSERT INTO carpetas_materiales (id, nombre, padre_id, creado_por) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nextId, $nombre, $padre_id, $_SESSION['user_id']]);
    echo json_encode(['success' => true, 'id' => $nextId, 'nombre' => $nombre]);
    exit();
}

if ($action === 'organizar_por_marca' && $method === 'POST') {
    $roles_permitidos = ['admin','ingenieria','compras','almacen'];
    $tiene_permiso = false;
    foreach ($roles_permitidos as $r) { if (hasAcceso($r)) { $tiene_permiso = true; break; } }
    if (!$tiene_permiso) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Sin permiso']); exit(); }

    try {
        // Limpiar filas corruptas (id=0) de ejecuciones anteriores
        $db->exec("UPDATE materiales SET carpeta_id = NULL WHERE carpeta_id = 0");
        $db->exec("DELETE FROM carpetas_materiales WHERE id = 0");

        $stmt = $db->query("SELECT DISTINCT TRIM(marca) as marca FROM materiales WHERE activo = 1 AND marca IS NOT NULL AND TRIM(marca) != ''");
        $marcas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $carpetas_creadas = 0;
        $materiales_asignados = 0;

        foreach ($marcas as $marca) {
            // Buscar carpeta válida (id > 0)
            $stmtC = $db->prepare("SELECT id FROM carpetas_materiales WHERE nombre = ? AND id > 0 LIMIT 1");
            $stmtC->execute([$marca]);
            $carpeta = $stmtC->fetch();

            if ($carpeta) {
                $carpeta_id = (int)$carpeta['id'];
            } else {
                // Generar ID explícito para evitar el problema de AUTO_INCREMENT
                $carpeta_id = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM carpetas_materiales")->fetchColumn();
                $db->prepare("INSERT INTO carpetas_materiales (id, nombre, padre_id, creado_por) VALUES (?, ?, NULL, ?)")
                   ->execute([$carpeta_id, $marca, $_SESSION['user_id']]);
                $carpetas_creadas++;
            }

            $stmtU = $db->prepare("UPDATE materiales SET carpeta_id = ? WHERE TRIM(marca) = ? AND activo = 1");
            $stmtU->execute([$carpeta_id, $marca]);
            $materiales_asignados += $stmtU->rowCount();
        }

        echo json_encode(['success' => true, 'carpetas_creadas' => $carpetas_creadas, 'materiales_asignados' => $materiales_asignados]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

if ($action === 'mover_material' && $method === 'POST') {
    $roles_permitidos = ['admin','ingenieria','compras','almacen'];
    $tiene_permiso    = false;
    foreach ($roles_permitidos as $r) { if (hasAcceso($r)) { $tiene_permiso = true; break; } }
    if (!$tiene_permiso) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Sin permiso']); exit(); }

    $material_id = intval($_POST['material_id'] ?? 0);
    $carpeta_id  = intval($_POST['carpeta_id']  ?? 0) ?: null;

    if ($material_id === 0) { echo json_encode(['success' => false, 'error' => 'material_id requerido']); exit(); }

    $db->prepare("UPDATE materiales SET carpeta_id = ? WHERE id = ?")
       ->execute([$carpeta_id, $material_id]);
    echo json_encode(['success' => true]);
    exit();
}

// ── Comportamiento original (sin action) ─────────────────────────
$categorias_param = $_GET['categorias']  ?? '';
$categoria_param  = $_GET['categoria']   ?? '';
$busqueda         = $_GET['busqueda']    ?? '';
$stock_minimo     = isset($_GET['stock_minimo']);
$carpeta_id_param = intval($_GET['carpeta_id'] ?? -1);

$sql    = "SELECT m.*, c.nombre as categoria_nombre, cf.nombre as carpeta_nombre
           FROM materiales m
           LEFT JOIN categorias c  ON m.categoria_id = c.id
           LEFT JOIN carpetas_materiales cf ON m.carpeta_id = cf.id
           WHERE m.activo = 1";
$params = [];

if ($categoria_param) {
    $sql .= " AND m.categoria_id = ?";
    $params[] = $categoria_param;
} elseif ($categorias_param) {
    $cats         = explode(',', $categorias_param);
    $placeholders = implode(',', array_fill(0, count($cats), '?'));
    $sql         .= " AND m.categoria_id IN ($placeholders)";
    $params       = $cats;
}

if ($busqueda) {
    $sql     .= " AND (m.descripcion LIKE ? OR m.codigo LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

if ($stock_minimo) {
    $sql .= " AND m.stock_disponible <= m.stock_minimo";
}

if ($carpeta_id_param === 0) {
    $sql .= " AND m.carpeta_id IS NULL";
} elseif ($carpeta_id_param > 0) {
    $sql     .= " AND m.carpeta_id = ?";
    $params[] = $carpeta_id_param;
}

$sql .= " ORDER BY m.descripcion";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $materiales = $stmt->fetchAll();
    echo json_encode(['success' => true, 'count' => count($materiales), 'data' => $materiales]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
