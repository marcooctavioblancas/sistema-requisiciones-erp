<?php
require_once 'includes/config.php';
require_once 'includes/equipos_fabricados.php';

$marcas_imagenes = [
    'adalet'          => 'uploads/logo/Marcas/ADALET.png',
    'adsen'           => 'uploads/logo/Marcas/Adsens.png',
    'adsens'          => 'uploads/logo/Marcas/Adsens.png',
    'ashcroft'        => 'uploads/logo/Marcas/ashcroft.png',
    'cmp'             => 'uploads/logo/Marcas/CMP (1).png',
    'dewit'           => 'uploads/logo/Marcas/dewit.png',
    'ds-lok by pmt'   => 'uploads/logo/Marcas/ds-lok.png',
    'ds-lok'          => 'uploads/logo/Marcas/ds-lok.png',
    'eaton'           => 'uploads/logo/Marcas/Eaton.png',
    'famaga'          => 'uploads/logo/Marcas/famaga.png',
    'farris'          => 'uploads/logo/Marcas/farris-logo.png',
    'hanson'          => 'uploads/logo/Marcas/HANSON2.png',
    'hy-lok'          => 'uploads/logo/Marcas/HYLOK.png',
    'hylok'           => 'uploads/logo/Marcas/HYLOK.png',
    'idec'            => 'uploads/logo/Marcas/IDEC.png',
    'klemsan'         => 'uploads/logo/Marcas/KLEMSAN.png',
    'power&genex'     => 'uploads/logo/Marcas/POWERGENEX.png',
    'powergenex'      => 'uploads/logo/Marcas/POWERGENEX.png',
    'ruelco'          => 'uploads/logo/Marcas/Ruelco.png',
    'seter'           => 'uploads/logo/Marcas/Seter.png',
    'superlok'        => 'uploads/logo/Marcas/superlok-logo.png',
    'topworx'         => 'uploads/logo/Marcas/topworks-logo.png',
    'valcontrols'     => 'uploads/logo/Marcas/VALCONTROLS.png',
    'warom'           => 'uploads/logo/Marcas/WAROM.png',
    'weldfit'         => 'uploads/logo/Marcas/Weldfit.png',
    'crouse-hinds'    => 'uploads/logo/Marcas/Eaton.png',
    'versa'           => 'uploads/logo/Marcas/VERSA.png',
    'wedmüller'       => 'uploads/logo/Marcas/weidmueller.png',
    'wedmuller'       => 'uploads/logo/Marcas/weidmueller.png',
];
function getMarcaImagen($marca, $map) {
    if (empty($marca)) return null;
    $key = mb_strtolower(trim($marca), 'UTF-8');
    return $map[$key] ?? null;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');

    
    exit();
}

if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$user_type = isset($_SESSION['user_type']) ? trim($_SESSION['user_type']) : '';

$mostrar_boton_menu = isset($_SESSION['desde_menu']) && $_SESSION['desde_menu'] === true;

if (!hasAcceso('almacen')) {
    header('Location: sin_acceso.php');
    exit();
}

$db = getDB();
$categorias = getCategoriasActivas($db);
$message = '';
$error = '';
// Solo el jefe de Almacén (o admin) puede escribir código de verdad -- ver
// resolverCodigoMaterial() en includes/config.php.
$puedeAsignarCodigoMaterial = esJefeDe('almacen') || isAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        
        case 'ajustar_stock':
            $material_id = intval($_POST['material_id']);
            $tipo = $_POST['tipo'];
            $cantidad = intval($_POST['cantidad']);

            try {
                $stmt = $db->prepare("SELECT stock_disponible, descripcion FROM materiales WHERE id = ?");
                $stmt->execute([$material_id]);
                $material = $stmt->fetch();

                if ($material) {
                    $stock_anterior = $material['stock_disponible'];
                    $delta = ($tipo == 'entrada') ? $cantidad : -$cantidad;

                    if ($tipo != 'entrada' && $cantidad > $stock_anterior) {
                        $error = showMessage("Error: El stock no puede ser negativo", 'error');
                    } else {
                        registrarMovimientoAlmacen($db, $material_id, $tipo, $delta, 'ajuste_manual', null, "Ajuste manual de inventario: {$material['descripcion']}");

                        $stmt = $db->prepare("SELECT stock_disponible FROM materiales WHERE id = ?");
                        $stmt->execute([$material_id]);
                        $nuevo_stock = $stmt->fetchColumn();

                        $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'ajustar_stock', 'materiales', ?, ?)")
                           ->execute([$_SESSION['user_id'], $material_id, "Stock ajustado: {$material['descripcion']} - $tipo de $cantidad unidades"]);

                        $message = showMessage("Stock de '{$material['descripcion']}' actualizado: $stock_anterior → $nuevo_stock", 'success');
                    }
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'guardar_material':
            try {
                $codigo = sanitize($_POST['codigo'] ?? '');
                $descripcion = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar, ver comentario en proyecto.php:1005
                $categoria_id = $_POST['categoria_id'];
                $marca = sanitize($_POST['marca'] ?? '');
                $modelo = sanitize($_POST['modelo'] ?? '');
                $unidad = sanitize($_POST['unidad_medida'] ?? 'pza');
                $ubicacion = sanitize($_POST['ubicacion'] ?? '');
                $stock_minimo = intval($_POST['stock_minimo'] ?? 0);
                $imagen = null;
                if (!empty($_FILES['imagen']['name'])) {
                    $upload = uploadFile($_FILES['imagen'], 'materiales/');
                    if ($upload['success']) {
                        $imagen = $upload['file'];
                    }
                }

                // Solo jefe de Almacén (o admin) puede escribir código de verdad -- ver
                // resolverCodigoMaterial() en includes/config.php. A cualquier otro se le
                // ignora lo que haya mandado el formulario y se le calcula una sugerencia
                // (sin verificar).
                $res = resolverCodigoMaterial($db, null, null, $codigo, $descripcion, true);
                $codigo = $res['codigo'];

                if ($codigo !== null) {
                    $chk = $db->prepare("SELECT COUNT(*) FROM materiales WHERE codigo = ?");
                    $chk->execute([$codigo]);
                    if ($chk->fetchColumn() > 0) {
                        throw new Exception("El código '$codigo' ya está en uso por otro material");
                    }
                }

                $stmt = $db->prepare("INSERT INTO materiales
                    (codigo, codigo_verificado, descripcion, categoria_id, marca, modelo, precio_unitario, stock_disponible, stock_minimo, unidad_medida, imagen, ubicacion)
                    VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?)");
                $stmt->execute([$codigo, $res['verificado'], $descripcion, $categoria_id, $marca, $modelo, $stock_minimo, $unidad, $imagen, $ubicacion]);
                $new_id = (int) $db->lastInsertId();

                if ($marca) {
                    $stmtC = $db->prepare("SELECT id FROM carpetas_materiales WHERE nombre = ? AND padre_id IS NULL");
                    $stmtC->execute([$marca]);
                    $carpeta = $stmtC->fetch();
                    if ($carpeta) {
                        $cid = $carpeta['id'];
                    } else {
                        $db->prepare("INSERT INTO carpetas_materiales (nombre, padre_id, creado_por) VALUES (?, NULL, ?)")
                           ->execute([$marca, $_SESSION['user_id']]);
                        $cid = (int) $db->lastInsertId();
                    }
                    $db->prepare("UPDATE materiales SET carpeta_id = ? WHERE id = ?")->execute([$cid, $new_id]);
                }

                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'crear', 'materiales', ?, ?)")
                   ->execute([$_SESSION['user_id'], $new_id, "Material creado desde Almacén: $descripcion"]);

                if ($res['verificado'] === 0) {
                    notificarRoles(['almacen'], 'material_sin_verificar', "Código sin verificar: $descripcion",
                        "Se agregó el material \"$descripcion\" con código sugerido \"{$codigo}\" -- solo el jefe de Almacén puede confirmarlo.",
                        'almacen2.php?seccion=inventario', $_SESSION['user_id']);
                }

                $message = showMessage("Material agregado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'editar_material':
            try {
                $material_id = intval($_POST['id']);
                $codigo = sanitize($_POST['codigo'] ?? '');
                $descripcion = strip_tags(trim($_POST['descripcion'] ?? '')); // no htmlspecialchars aquí -- se escapa al mostrar, ver comentario en proyecto.php:1005
                $categoria_id = $_POST['categoria_id'];
                $marca = sanitize($_POST['marca'] ?? '');
                $modelo = sanitize($_POST['modelo'] ?? '');
                $unidad = sanitize($_POST['unidad_medida'] ?? 'pza');
                $ubicacion = sanitize($_POST['ubicacion'] ?? '');
                $stock_minimo = intval($_POST['stock_minimo'] ?? 0);

                // Solo jefe de Almacén (o admin) puede tocar código -- si lo es y guarda una
                // edición, el material queda VERIFICADO (confirmó el dato con su propia
                // acción, con o sin cambiarlo). Si no, se conserva código y verificación tal
                // cual estaban -- resolverCodigoMaterial() en includes/config.php.
                $stmtActual = $db->prepare("SELECT codigo, codigo_verificado FROM materiales WHERE id = ?");
                $stmtActual->execute([$material_id]);
                $actual = $stmtActual->fetch();
                if (!$actual) { throw new Exception("Material no encontrado"); }
                $res = resolverCodigoMaterial($db, $actual['codigo'], (int)$actual['codigo_verificado'], $codigo, $descripcion, false);
                $codigo = $res['codigo'];

                if ($codigo !== null) {
                    $chk = $db->prepare("SELECT COUNT(*) FROM materiales WHERE codigo = ? AND id != ?");
                    $chk->execute([$codigo, $material_id]);
                    if ($chk->fetchColumn() > 0) {
                        throw new Exception("El código '$codigo' ya está en uso por otro material");
                    }
                }

                $imagen_sql = '';
                $params = [$codigo, $res['verificado'], $descripcion, $categoria_id, $marca, $modelo, $unidad, $ubicacion, $stock_minimo];
                if (!empty($_FILES['imagen']['name'])) {
                    $upload = uploadFile($_FILES['imagen'], 'materiales/');
                    if ($upload['success']) {
                        $imagen_sql = ', imagen = ?';
                        $params[] = $upload['file'];
                    }
                }
                $params[] = $material_id;

                $stmt = $db->prepare("UPDATE materiales SET codigo = ?, codigo_verificado = ?, descripcion = ?, categoria_id = ?, marca = ?, modelo = ?, unidad_medida = ?, ubicacion = ?, stock_minimo = ? $imagen_sql WHERE id = ?");
                $stmt->execute($params);

                $db->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle) VALUES (?, 'editar', 'materiales', ?, ?)")
                   ->execute([$_SESSION['user_id'], $material_id, "Material editado desde Almacén: $descripcion"]);

                $message = showMessage("Material actualizado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'registrar_entrada':
            try {
                $material_id = intval($_POST['material_id']);
                $cantidad = intval($_POST['cantidad']);
                $proveedor_id = !empty($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : null;
                $orden_compra_id = !empty($_POST['orden_compra_id']) ? intval($_POST['orden_compra_id']) : null;
                $ubicacion = sanitize($_POST['ubicacion'] ?? '');
                $observaciones = sanitize($_POST['observaciones'] ?? '');
                $series_raw = trim($_POST['numeros_serie'] ?? '');
                $codigo_entrada = sanitize($_POST['codigo_material'] ?? '');

                if ($material_id <= 0 || $cantidad <= 0) {
                    throw new Exception("Selecciona un material y una cantidad válida");
                }

                if ($codigo_entrada !== '') {
                    $chkCod = $db->prepare("SELECT COUNT(*) FROM materiales WHERE codigo = ? AND id != ?");
                    $chkCod->execute([$codigo_entrada, $material_id]);
                    if ($chkCod->fetchColumn() > 0) {
                        throw new Exception("El código '$codigo_entrada' ya está en uso por otro material");
                    }
                    $db->prepare("UPDATE materiales SET codigo = ? WHERE id = ?")->execute([$codigo_entrada, $material_id]);
                }

                $series = [];
                if ($series_raw !== '') {
                    $series = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $series_raw))));
                    if (count($series) !== $cantidad) {
                        throw new Exception("Capturaste " . count($series) . " número(s) de serie pero la cantidad es $cantidad. Deben coincidir uno a uno, o deja el campo vacío si el material no se serializa.");
                    }
                    if (count($series) !== count(array_unique($series))) {
                        throw new Exception("Hay números de serie repetidos en la captura");
                    }
                    $ph = implode(',', array_fill(0, count($series), '?'));
                    $chk = $db->prepare("SELECT numero_serie FROM series_lotes WHERE numero_serie IN ($ph)");
                    $chk->execute($series);
                    $dupes = $chk->fetchAll(PDO::FETCH_COLUMN);
                    if ($dupes) {
                        throw new Exception("Número(s) de serie ya registrados en el sistema: " . implode(', ', $dupes));
                    }
                }

                $movId = registrarMovimientoAlmacen($db, $material_id, 'entrada', $cantidad, 'entrada_almacen', $orden_compra_id, $observaciones ?: null, $proveedor_id);

                if ($ubicacion !== '') {
                    $db->prepare("UPDATE materiales SET ubicacion = ? WHERE id = ?")->execute([$ubicacion, $material_id]);
                }

                foreach ($series as $num) {
                    $db->prepare("INSERT INTO series_lotes (material_id, numero_serie, orden_compra_id, proveedor_id, estado, fecha_ingreso, usuario_entrada_id, movimiento_entrada_id) VALUES (?, ?, ?, ?, 'En Almacén', NOW(), ?, ?)")
                       ->execute([$material_id, $num, $orden_compra_id, $proveedor_id, $_SESSION['user_id'], $movId]);
                }

                $message = showMessage("Entrada registrada correctamente" . (count($series) ? " (" . count($series) . " número(s) de serie)" : ""), 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'registrar_salida':
            try {
                $material_id = intval($_POST['material_id']);
                $cantidad = intval($_POST['cantidad']);
                $ot_id = !empty($_POST['ot_id']) ? intval($_POST['ot_id']) : null;
                $proyecto_id_salida = !empty($_POST['proyecto_id']) ? intval($_POST['proyecto_id']) : null;
                $destino_texto = sanitize($_POST['destino_texto'] ?? '');
                $observaciones = sanitize($_POST['observaciones'] ?? '');
                $series_raw = trim($_POST['numeros_serie'] ?? '');

                if ($material_id <= 0 || $cantidad <= 0) {
                    throw new Exception("Selecciona un material y una cantidad válida");
                }

                $stmt = $db->prepare("SELECT stock_disponible, descripcion FROM materiales WHERE id = ?");
                $stmt->execute([$material_id]);
                $mat = $stmt->fetch();
                if (!$mat) throw new Exception("Material no encontrado");
                if ($cantidad > $mat['stock_disponible']) {
                    throw new Exception("Stock insuficiente: disponible {$mat['stock_disponible']}, solicitado $cantidad");
                }

                $series = [];
                if ($series_raw !== '') {
                    $series = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $series_raw))));
                    if (count($series) !== $cantidad) {
                        throw new Exception("Capturaste " . count($series) . " número(s) de serie pero la cantidad es $cantidad.");
                    }
                    $ph = implode(',', array_fill(0, count($series), '?'));
                    $stmtS = $db->prepare("SELECT numero_serie FROM series_lotes WHERE material_id = ? AND estado = 'En Almacén' AND numero_serie IN ($ph)");
                    $stmtS->execute(array_merge([$material_id], $series));
                    $validas = $stmtS->fetchAll(PDO::FETCH_COLUMN);
                    $faltantes = array_diff($series, $validas);
                    if ($faltantes) {
                        throw new Exception("Número(s) de serie no disponibles en almacén para este material: " . implode(', ', $faltantes));
                    }
                }

                $refTipo = $ot_id ? 'ot' : ($proyecto_id_salida ? 'proyecto' : 'salida_manual');
                $refId = $ot_id ?: $proyecto_id_salida;
                $obsCompleta = trim($observaciones . ($destino_texto !== '' ? " Destino: $destino_texto" : ''));

                $movId = registrarMovimientoAlmacen($db, $material_id, 'salida', -$cantidad, $refTipo, $refId, $obsCompleta !== '' ? $obsCompleta : null);

                $folio = 'SAL-' . str_pad($movId, 6, '0', STR_PAD_LEFT);
                $db->prepare("UPDATE historial_movimientos SET folio = ? WHERE id = ?")->execute([$folio, $movId]);

                if ($series) {
                    $phU = implode(',', array_fill(0, count($series), '?'));
                    $db->prepare("UPDATE series_lotes SET estado = 'En Uso', fecha_salida = NOW(), ot_id = ?, usuario_salida_id = ?, movimiento_salida_id = ? WHERE material_id = ? AND numero_serie IN ($phU)")
                       ->execute(array_merge([$ot_id, $_SESSION['user_id'], $movId, $material_id], $series));
                }

                $message = showMessage("Salida registrada correctamente — Folio: $folio" . (count($series) ? " (" . count($series) . " número(s) de serie)" : ""), 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'registrar_reingreso':
            try {
                $historial_id = intval($_POST['historial_id']);
                $stmt = $db->prepare("SELECT * FROM historial_movimientos WHERE id = ? AND tipo_movimiento = 'salida'");
                $stmt->execute([$historial_id]);
                $mov = $stmt->fetch();
                if (!$mov) throw new Exception("Movimiento de salida no encontrado");

                $chkR = $db->prepare("SELECT COUNT(*) FROM historial_movimientos WHERE referencia_tipo = 'reingreso' AND referencia_id = ?");
                $chkR->execute([$historial_id]);
                if ($chkR->fetchColumn() > 0) throw new Exception("Esta salida ya fue reingresada anteriormente");

                $cantidad = abs($mov['cantidad']);
                $folioOrigen = $mov['folio'] ?: ('#' . $historial_id);
                registrarMovimientoAlmacen($db, $mov['material_id'], 'reingreso', $cantidad, 'reingreso', $historial_id, "Reingreso de salida $folioOrigen — material no utilizado");

                $db->prepare("UPDATE series_lotes SET estado = 'En Almacén', fecha_salida = NULL, ot_id = NULL, usuario_salida_id = NULL, movimiento_salida_id = NULL WHERE movimiento_salida_id = ?")
                   ->execute([$historial_id]);

                $message = showMessage("Reingreso registrado correctamente", 'success');
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;

        case 'pasar_a_almacen':
            $proyecto_id = intval($_POST['proyecto_id']);
            $success = false;

            try {
                // Si el proyecto ya tiene items en almacén, solo reactivar
                $stmt_check = $db->prepare("SELECT COUNT(*) FROM almacen_items WHERE proyecto_id = ?");
                $stmt_check->execute([$proyecto_id]);
                $tiene_items = $stmt_check->fetchColumn() > 0;

                if ($tiene_items) {
                    $error = showMessage("Este proyecto ya fue enviado a Almacén anteriormente. Usa el botón 'Sincronizar Materiales' para actualizar los cambios del BOM.", 'error');
                    break;
                } else {
                // Aggregate across all BOMs: same material_id → sum(cantidad * num_equipos).
                // Incluye plano_ia (los planos que valida Producción) además de principal/adicional
                // (Ingeniería) -- sin esto, este push ignoraba los materiales de Producción por no
                // venir del BOM de Ingeniería, y de paso perdía a qué área atribuirlos
                // (cantidad_ingenieria/cantidad_produccion quedaban en 0 = "Sin clasificar" en la
                // barra de origen). LEFT JOIN (no INNER) a materiales: un ítem de plano que la IA no
                // logró matchear contra el catálogo (material_id NULL) igual debe llegar a Almacén
                // -- el camino normal de Ingeniería (sincronizarAlmacenItem) ya soporta ese caso,
                // esta agregación no tiene por qué ser más estricta y perderlos en silencio.
                // GROUP BY con COALESCE: los matcheados se agrupan por material_id (sin importar
                // pequeñas variaciones de texto entre BOMs); los sin match nunca comparten
                // material_id (todos son NULL), así que se agrupan por su propia descripción para
                // no mezclar materiales distintos en una sola fila.
                $stmt = $db->prepare("
                    SELECT bi.material_id,
                           COALESCE(MAX(m.descripcion), MAX(bi.descripcion)) AS descripcion,
                           COALESCE(MAX(m.codigo), MAX(bi.modelo)) AS modelo,
                           COALESCE(MAX(m.marca), MAX(bi.marca)) AS marca,
                           SUM(bi.cantidad * bl.num_equipos) AS cantidad,
                           SUM(CASE WHEN bl.tipo_lista = 'plano_ia' THEN 0 ELSE bi.cantidad * bl.num_equipos END) AS cantidad_ingenieria,
                           SUM(CASE WHEN bl.tipo_lista = 'plano_ia' THEN bi.cantidad * bl.num_equipos ELSE 0 END) AS cantidad_produccion
                    FROM boom_items bi
                    JOIN boom_listas bl ON bi.boom_id = bl.id
                    LEFT JOIN materiales m ON m.id = bi.material_id
                    WHERE bl.proyecto_id = ? AND bl.guardado = 1 AND bi.guardado = 1
                      AND bl.tipo_lista IN ('principal','adicional','plano_ia')
                    GROUP BY COALESCE(bi.material_id, CONCAT('_sinmatch_', bi.descripcion))
                ");
                $stmt->execute([$proyecto_id]);
                $items = $stmt->fetchAll();

                if (empty($items)) {
                    $error = showMessage("El proyecto no tiene items guardados. Asegúrate de haber guardado el BOM en Ingeniería.", 'error');
                } else {
                    $db->prepare("UPDATE proyectos SET almacen_activo = 1, fecha_estado = NOW() WHERE id = ?")
                       ->execute([$proyecto_id]);

                    foreach ($items as $item) {
                        $stmt2 = $db->prepare("SELECT stock_disponible FROM materiales WHERE id = ?");
                        $stmt2->execute([$item['material_id']]);
                        $stock = $stmt2->fetchColumn();

                        $diferencia = $stock - $item['cantidad'];
                        $db->prepare("INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, modelo, marca, cantidad_requerida, cantidad_ingenieria, cantidad_produccion, stock_actual, diferencia, fecha_agregado, agregado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)")
                           ->execute([$proyecto_id, $item['material_id'], $item['descripcion'], $item['descripcion'], $item['modelo'], $item['marca'], $item['cantidad'], $item['cantidad_ingenieria'], $item['cantidad_produccion'], $stock, $diferencia, $_SESSION['user_id']]);
                    }
                    
                    $_SESSION['almacen_proyecto_activo'] = $proyecto_id;

                    $stmt_pn = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                    $stmt_pn->execute([$proyecto_id]);
                    $proy_nombre_notif = $stmt_pn->fetchColumn() ?: "ID $proyecto_id";
                    notificarRoles(
                        ['compras'],
                        'proyecto_almacen',
                        "Asignación iniciada: $proy_nombre_notif",
                        "El proyecto \"$proy_nombre_notif\" entró a almacén para asignación de stock (" . count($items) . " material(es)). La requisición se generará al finalizar.",
                        'compras.php'
                    );

                    // Detectar materiales con stock insuficiente y notificar a compras
                    $faltantes = [];
                    foreach ($items as $item) {
                        $stmt_stk = $db->prepare("SELECT stock_disponible FROM materiales WHERE id = ?");
                        $stmt_stk->execute([$item['material_id']]);
                        $stock_actual = $stmt_stk->fetchColumn();
                        if ($stock_actual < $item['cantidad']) {
                            $faltante = $item['cantidad'] - $stock_actual;
                            $faltantes[] = "• {$item['item']}: requiere {$item['cantidad']}, stock actual {$stock_actual} (faltan {$faltante})";
                        }
                    }

                    if (!empty($faltantes)) {
                        $lista_faltantes = implode("\n", $faltantes);
                        notificarRoles(
                            ['compras'],
                            'stock_insuficiente',
                            "Stock insuficiente: $proy_nombre_notif",
                            "El proyecto \"$proy_nombre_notif\" requiere materiales sin stock suficiente. Se requiere orden de compra:\n$lista_faltantes",
                            'compras.php'
                        );
                    }

                    $message = showMessage("Proyecto pasado a Almacén correctamente", 'success');
                    $success = true;
                }
                } // end else (no tiene_items)
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }

            if ($success) {
                $_SESSION['message'] = $message;
                header('Location: almacen2.php?seccion=almacen');
                exit();
            }
            break;
            
        case 'agregar_a_proyecto':
            $proyecto_id = intval($_POST['proyecto_id']);
            $material_id = intval($_POST['material_id']);
            $cantidad = intval($_POST['cantidad']);
            
            try {
                $stmt = $db->prepare("SELECT descripcion, modelo, marca, stock_disponible FROM materiales WHERE id = ?");
                $stmt->execute([$material_id]);
                $material = $stmt->fetch();
                
                if ($material) {
                    $stmt = $db->prepare("SELECT id, cantidad_requerida, cantidad_asignada FROM almacen_items WHERE proyecto_id = ? AND material_id = ?");
                    $stmt->execute([$proyecto_id, $material_id]);
                    $existente = $stmt->fetch();
                    
                    if ($existente) {
                        $nueva_cantidad = $existente['cantidad_requerida'] + $cantidad;
                        $diferencia = $material['stock_disponible'] - $nueva_cantidad;
                        $stmt = $db->prepare("UPDATE almacen_items SET cantidad_requerida = ?, diferencia = ? WHERE id = ?");
                        $stmt->execute([$nueva_cantidad, $diferencia, $existente['id']]);
                    } else {
                        $diferencia = $material['stock_disponible'] - $cantidad;
                        $stmt = $db->prepare("INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, modelo, marca, cantidad_requerida, stock_actual, diferencia, fecha_agregado, agregado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                        $stmt->execute([
                            $proyecto_id,
                            $material_id,
                            $material['descripcion'],
                            $material['descripcion'],
                            $material['modelo'],
                            $material['marca'],
                            $cantidad,
                            $material['stock_disponible'],
                            $diferencia,
                            $_SESSION['user_id']
                        ]);
                    }
                    
                    $message = showMessage("Item agregado al proyecto", 'success');
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'asignar_cantidad':
            $item_id = intval($_POST['item_id']);
            $cantidad = intval($_POST['cantidad']);
            
            try {
                $stmt = $db->prepare("SELECT ai.*, m.stock_disponible FROM almacen_items ai JOIN materiales m ON ai.material_id = m.id WHERE ai.id = ?");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch();
                
                if ($item) {
                    if ($cantidad > $item['stock_disponible']) {
                        $error = showMessage("Error: No hay suficiente stock (Disponible: {$item['stock_disponible']})", 'error');
                    } else {
                        $delta_entregado = $cantidad - (float)($item['cantidad_asignada'] ?? 0);

                        $stmt = $db->prepare("UPDATE almacen_items SET cantidad_asignada = ? WHERE id = ?");
                        $stmt->execute([$cantidad, $item_id]);

                        // Ledger unificado: descuenta stock real y deja rastro en historial_movimientos
                        // (antes solo quedaba en almacen_movimientos, sin aparecer en la bitácora global).
                        registrarMovimientoAlmacen($db, $item['material_id'], 'salida', -$cantidad, 'almacen_item', $item_id, 'Asignación de cantidad a proyecto');

                        $stmtM = $db->prepare("SELECT stock_disponible FROM materiales WHERE id = ?");
                        $stmtM->execute([$item['material_id']]);
                        $nuevo_stock = (int) $stmtM->fetchColumn();

                        $stmt = $db->prepare("INSERT INTO almacen_movimientos (almacen_item_id, tipo, cantidad, stock_anterior, stock_nuevo, fecha, usuario_id) VALUES (?, 'salida', ?, ?, ?, NOW(), ?)");
                        $stmt->execute([$item_id, $cantidad, $item['stock_disponible'], $nuevo_stock, $_SESSION['user_id']]);

                        // diferencia = stock_actual - cantidad_requerida, igual que en TODO el resto
                        // del sistema (creación de almacen_items, guardar_boom, guardar_item_bom.php,
                        // etc.) -- antes esta acción era la única que comparaba contra cantidad_asignada
                        // en vez de cantidad_requerida.
                        $stmt = $db->prepare("UPDATE almacen_items SET stock_actual = ?, diferencia = ? - cantidad_requerida WHERE id = ?");
                        $stmt->execute([$nuevo_stock, $nuevo_stock, $item_id]);

                        // Refleja esta entrega en el folio de requisición que la haya pedido
                        // (si la hay — el material puede venir solo del BOM de Ingeniería, sin folio).
                        if ($item['proyecto_id'] && $delta_entregado > 0) {
                            entregarRequisicionesDeProyecto($db, (int)$item['proyecto_id'], (int)$item['material_id'], $delta_entregado);
                        }

                        $message = showMessage("Cantidad asignada correctamente. Stock actualizado.", 'success');
                    }
                }
            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'finalizar_proyecto':
            $proyecto_id = intval($_POST['proyecto_id']);

            try {
                // Snapshot automático del BOM antes de finalizar -- si nadie
                // usó el botón "Imprimir BOM" a mano, este es el respaldo que
                // evita que los materiales se pierdan de vista al finalizar.
                $stmt_snap = $db->prepare("
                    SELECT ai.descripcion, ai.modelo, ai.marca, ai.cantidad_requerida,
                           COALESCE(c.nombre, ai.categoria_nombre, 'Sin categoría') AS cat_nombre
                    FROM almacen_items ai
                    LEFT JOIN materiales m ON ai.material_id = m.id
                    LEFT JOIN categorias c ON m.categoria_id = c.id
                    WHERE ai.proyecto_id = ?
                    ORDER BY cat_nombre, ai.fecha_agregado
                ");
                $stmt_snap->execute([$proyecto_id]);
                guardarSnapshotBom($db, $proyecto_id, $stmt_snap->fetchAll(), 'finalizado', $_SESSION['user_id'] ?? null);

                // almacen_directo_fecha_fin: fin real del tiempo de Almacén-directo (BOM ->
                // almacen_items) para el registro contable (registrarCierreContable()) --
                // dedicada porque fecha_estado la pisan muchas otras acciones a lo largo de
                // la vida del proyecto, no sobrevive hasta el cierre total.
                $db->prepare("UPDATE proyectos SET almacen_activo = 0, fecha_estado = NOW(), almacen_directo_fecha_fin = NOW() WHERE id = ?")
                   ->execute([$proyecto_id]);

                unset($_SESSION['almacen_proyecto_activo']);

                $_SESSION['proyecto_requisicion_almacen'] = $proyecto_id;

                header('Location: almacen2.php?seccion=requisicion_almacen');
                exit();

            } catch (Exception $e) {
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
            
        case 'eliminar_almacen_item':
            $ai_id = intval($_POST['almacen_item_id'] ?? 0);
            if ($ai_id) {
                try {
                    $db->prepare("DELETE FROM almacen_items WHERE id = ?")->execute([$ai_id]);
                } catch(Exception $e) {}
            }
            exit(); // respuesta vacía — solo AJAX

        case 'cancelar_proyecto':
            $proyecto_id = isset($_SESSION['almacen_proyecto_activo']) ? $_SESSION['almacen_proyecto_activo'] : 0;
            if ($proyecto_id) {
                $db->prepare("DELETE FROM almacen_items WHERE proyecto_id = ?")->execute([$proyecto_id]);
                $db->prepare("UPDATE proyectos SET estado = NULL, almacen_activo = 0 WHERE id = ?")->execute([$proyecto_id]);
                unset($_SESSION['almacen_proyecto_activo']);
                $message = showMessage("Proyecto cancelado y datos eliminados", 'info');
            }
            break;
            
        case 'nueva_requisicion_proyecto':
            $_SESSION['proyecto_requisicion_almacen'] = intval($_POST['proyecto_id']);
            header('Location: ?seccion=requisicion_almacen');
            exit();
            
case 'sincronizar_proyecto':
            $proyecto_id = intval($_POST['proyecto_id']);
            try {
                // Bloquear si el proyecto está finalizado
                $stmt_check_fin = $db->prepare("SELECT estado, realizada FROM proyectos WHERE id = ?");
                $stmt_check_fin->execute([$proyecto_id]);
                $proy_check = $stmt_check_fin->fetch();
                if ($proy_check && in_array($proy_check['estado'], ['realizado', 'cancelado'])) {
                    $error = showMessage("No se puede sincronizar: el proyecto está finalizado.", 'error');
                    break;
                }

                $db->beginTransaction();

                // Aggregate across all BOMs: same material_id → sum(cantidad * num_equipos).
                // Incluye plano_ia (los planos que valida Producción) además de principal/adicional
                // (Ingeniería) -- sin esto, este push ignoraba los materiales de Producción por no
                // venir del BOM de Ingeniería, y de paso perdía a qué área atribuirlos
                // (cantidad_ingenieria/cantidad_produccion quedaban en 0 = "Sin clasificar" en la
                // barra de origen). LEFT JOIN (no INNER) a materiales: un ítem de plano que la IA no
                // logró matchear contra el catálogo (material_id NULL) igual debe llegar a Almacén
                // -- el camino normal de Ingeniería (sincronizarAlmacenItem) ya soporta ese caso,
                // esta agregación no tiene por qué ser más estricta y perderlos en silencio.
                // GROUP BY con COALESCE: los matcheados se agrupan por material_id (sin importar
                // pequeñas variaciones de texto entre BOMs); los sin match nunca comparten
                // material_id (todos son NULL), así que se agrupan por su propia descripción para
                // no mezclar materiales distintos en una sola fila.
                $stmt = $db->prepare("
                    SELECT bi.material_id,
                           COALESCE(MAX(m.descripcion), MAX(bi.descripcion)) AS descripcion,
                           COALESCE(MAX(m.codigo), MAX(bi.modelo)) AS modelo,
                           COALESCE(MAX(m.marca), MAX(bi.marca)) AS marca,
                           SUM(bi.cantidad * bl.num_equipos) AS cantidad,
                           SUM(CASE WHEN bl.tipo_lista = 'plano_ia' THEN 0 ELSE bi.cantidad * bl.num_equipos END) AS cantidad_ingenieria,
                           SUM(CASE WHEN bl.tipo_lista = 'plano_ia' THEN bi.cantidad * bl.num_equipos ELSE 0 END) AS cantidad_produccion
                    FROM boom_items bi
                    JOIN boom_listas bl ON bi.boom_id = bl.id
                    LEFT JOIN materiales m ON m.id = bi.material_id
                    WHERE bl.proyecto_id = ? AND bl.guardado = 1 AND bi.guardado = 1
                      AND bl.tipo_lista IN ('principal','adicional','plano_ia')
                    GROUP BY COALESCE(bi.material_id, CONCAT('_sinmatch_', bi.descripcion))
                ");
                $stmt->execute([$proyecto_id]);
                $boom_items = $stmt->fetchAll();

                $agregados = 0;
                $actualizados = 0;
                $eliminados = 0;

                foreach ($boom_items as $item) {
                    // Sin material_id (no matcheado al catálogo) no hay id que buscar -- se
                    // localiza por descripción, mismo criterio que agrupó el SELECT de arriba.
                    if ($item['material_id']) {
                        $stmt_check = $db->prepare("SELECT id, cantidad_requerida, cantidad_asignada, cantidad_ingenieria, cantidad_produccion FROM almacen_items WHERE proyecto_id = ? AND material_id = ?");
                        $stmt_check->execute([$proyecto_id, $item['material_id']]);
                    } else {
                        $stmt_check = $db->prepare("SELECT id, cantidad_requerida, cantidad_asignada, cantidad_ingenieria, cantidad_produccion FROM almacen_items WHERE proyecto_id = ? AND material_id IS NULL AND descripcion = ?");
                        $stmt_check->execute([$proyecto_id, $item['descripcion']]);
                    }
                    $existe = $stmt_check->fetch();

                    if (!$existe) {
                        // Material nuevo: insertar con cantidad del BOM
                        $stmt2 = $db->prepare("SELECT stock_disponible FROM materiales WHERE id = ?");
                        $stmt2->execute([$item['material_id']]);
                        $stock = $stmt2->fetchColumn();
                        $diferencia = $stock - $item['cantidad'];
                        $db->prepare("INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, modelo, marca, cantidad_requerida, cantidad_ingenieria, cantidad_produccion, cantidad_asignada, stock_actual, diferencia, fecha_agregado, agregado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), ?)")
                           ->execute([$proyecto_id, $item['material_id'], $item['descripcion'], $item['descripcion'], $item['modelo'], $item['marca'], $item['cantidad'], $item['cantidad_ingenieria'], $item['cantidad_produccion'], $stock, $diferencia, $_SESSION['user_id']]);
                        $agregados++;
                    } else if (abs((float)$existe['cantidad_requerida'] - (float)$item['cantidad']) > 0.001
                            || abs((float)$existe['cantidad_ingenieria'] - (float)$item['cantidad_ingenieria']) > 0.001
                            || abs((float)$existe['cantidad_produccion'] - (float)$item['cantidad_produccion']) > 0.001) {
                        // Material existente: actualizar cantidad_requerida al total del BOM -- también entra
                        // aquí si solo cambió el desglose ingeniería/producción (ej. una fila que se había
                        // quedado en 0/0 por un bug anterior), no solo si cambió el total.
                        // La cantidad_asignada ya hecha se conserva; la diferencia es lo nuevo que falta asignar
                        $nueva_cantidad = (float)$item['cantidad'];
                        $db->prepare("UPDATE almacen_items SET cantidad_requerida = ?, cantidad_ingenieria = ?, cantidad_produccion = ?, diferencia = stock_actual - ? WHERE id = ?")
                           ->execute([$nueva_cantidad, $item['cantidad_ingenieria'], $item['cantidad_produccion'], $nueva_cantidad, $existe['id']]);
                        $actualizados++;
                    }
                }

                // Materiales que estaban en almacen_items pero YA NO están en el BOM actual
                // (se quitaron en Ingeniería) deben eliminarse — de lo contrario quedan como
                // basura "fantasma" para siempre y contaminan la requisición/cotización.
                // array_filter quita los NULL (materiales sin catálogo) -- un NULL colado en el
                // "NOT IN (...)" de abajo lo vuelve NULL para toda fila que no matchee ningún id,
                // y eso apaga la limpieza de obsoletos por completo (NULL no es TRUE en WHERE).
                $boom_material_ids = array_values(array_filter(array_column($boom_items, 'material_id')));
                if (!empty($boom_material_ids)) {
                    $ph = implode(',', array_fill(0, count($boom_material_ids), '?'));
                    $stmt_obs = $db->prepare("SELECT id, material_id, cantidad_asignada FROM almacen_items WHERE proyecto_id = ? AND material_id IS NOT NULL AND material_id NOT IN ($ph)");
                    $stmt_obs->execute(array_merge([$proyecto_id], $boom_material_ids));
                } else {
                    $stmt_obs = $db->prepare("SELECT id, material_id, cantidad_asignada FROM almacen_items WHERE proyecto_id = ? AND material_id IS NOT NULL");
                    $stmt_obs->execute([$proyecto_id]);
                }
                foreach ($stmt_obs->fetchAll() as $obsoleto) {
                    if ($obsoleto['cantidad_asignada'] > 0) {
                        // Ya se había descontado stock real para este item: se regresa antes de borrar la línea.
                        registrarMovimientoAlmacen($db, $obsoleto['material_id'], 'reingreso', (float)$obsoleto['cantidad_asignada'], 'sync_bom_removido', $proyecto_id, "Material quitado del BOM al sincronizar — se libera el stock ya asignado");
                    }
                    $db->prepare("DELETE FROM almacen_items WHERE id = ?")->execute([$obsoleto['id']]);
                    $eliminados++;
                }

                if ($agregados > 0 || $actualizados > 0 || $eliminados > 0) {
                    // Regresar el proyecto a Almacén Activo para que se le asigne stock
                    $db->prepare("UPDATE proyectos SET almacen_activo = 1 WHERE id = ?")->execute([$proyecto_id]);
                    $_SESSION['almacen_proyecto_activo'] = $proyecto_id;

                    // Guardar snapshot del BOM como nueva versión
                    $snapshot_items = $boom_items;
                    try {
                        $ver_stmt = $db->prepare("SELECT MAX(version) FROM boom_versiones WHERE boom_lista_id IN (SELECT id FROM boom_listas WHERE proyecto_id = ?)");
                        $ver_stmt->execute([$proyecto_id]);
                        $max_ver = intval($ver_stmt->fetchColumn()) + 1;
                        // Una versión por proyecto (no por lista individual para simplificar)
                        $db->prepare("INSERT INTO boom_versiones (boom_lista_id, version, snapshot_json, cambios_resumen, creado_por) SELECT id, ?, ?, ?, ? FROM boom_listas WHERE proyecto_id = ? AND guardado = 1 LIMIT 1")
                           ->execute([$max_ver, json_encode($snapshot_items), "Sync: +$agregados nuevos, ~$actualizados actualizados, -$eliminados obsoletos", $_SESSION['user_id'], $proyecto_id]);
                    } catch (Exception $e_ver) { /* tabla boom_versiones puede no existir aún */ }

                    // Notificar a ingeniería y compras de los cambios
                    $stmt_pn2 = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                    $stmt_pn2->execute([$proyecto_id]);
                    $pn2 = $stmt_pn2->fetchColumn() ?: "ID $proyecto_id";
                    notificarRoles(
                        ['ingenieria', 'compras'],
                        'bom_sincronizado',
                        "BOM sincronizado: $pn2",
                        "El BOM del proyecto \"$pn2\" fue sincronizado desde almacén. Se agregaron $agregados items nuevos, se actualizaron $actualizados y se quitaron $eliminados que ya no están en el BOM. Revisa los cambios.",
                        'almacen2.php?seccion=almacen'
                    );

                    $db->commit();

                    // Mostrar popup
                    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
                    <style>
                        body { margin:0; font-family: Arial, sans-serif; background: rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; height:100vh; }
                        .popup { background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.25); padding:36px 32px; max-width:460px; width:90%; text-align:center; }
                        .popup-icon { font-size:52px; color:#4CAF50; margin-bottom:16px; }
                        .popup-title { font-size:20px; font-weight:700; color:#1a237e; margin-bottom:12px; }
                        .popup-msg { font-size:15px; color:#444; line-height:1.6; margin-bottom:8px; }
                        .popup-note { font-size:12px; color:#7b1fa2; background:#f3e5f5; border-radius:6px; padding:8px 12px; margin-bottom:20px; }
                        .popup-btn { background:#2196F3; color:#fff; border:none; border-radius:8px; padding:12px 36px; font-size:16px; font-weight:600; cursor:pointer; transition:background 0.2s; }
                        .popup-btn:hover { background:#1565C0; }
                    </style></head><body>
                    <div class='popup'>
                        <div class='popup-icon'>&#10003;</div>
                        <div class='popup-title'>Sincronización exitosa</div>
                        <div class='popup-msg'>Se agregaron <strong>$agregados</strong> items nuevos, se actualizaron <strong>$actualizados</strong> y se quitaron <strong>$eliminados</strong> que ya no están en el BOM.<br>El proyecto regresó a Almacén Activo para asignar el stock.</div>
                        <div class='popup-note'>Las cantidades ya asignadas se conservaron. Solo se ajustaron las cantidades requeridas. Ingeniería y Compras fueron notificados.</div>
                        <button class='popup-btn' onclick=\"window.location.href='?seccion=almacen'\">Continuar en Almacén</button>
                    </div>
                    </body></html>";
                    exit();
                } else {
                    // BOM ya sincronizado — mover igualmente a Almacén Activo para que el usuario pueda continuar
                    $db->prepare("UPDATE proyectos SET almacen_activo = 1 WHERE id = ?")->execute([$proyecto_id]);
                    $_SESSION['almacen_proyecto_activo'] = $proyecto_id;
                    $db->commit();
                    header('Location: almacen2.php?seccion=almacen');
                    exit();
                }

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = showMessage("Error al sincronizar: " . $e->getMessage(), 'error');
            }
            break;

        case 'abrir_en_almacen':
            $proyecto_id = intval($_POST['proyecto_id']);
            $_SESSION['almacen_proyecto_activo'] = $proyecto_id;
            header('Location: almacen2.php?seccion=almacen');
            exit();
            break;

        case 'cancelar_requisicion_almacen':
            $cancel_proy_id = intval($_SESSION['proyecto_requisicion_almacen'] ?? 0);
            if ($cancel_proy_id) {
                $db->prepare("UPDATE proyectos SET almacen_activo = 1 WHERE id = ?")
                   ->execute([$cancel_proy_id]);
                $_SESSION['almacen_proyecto_activo'] = $cancel_proy_id;
            }
            unset($_SESSION['proyecto_requisicion_almacen']);
            header('Location: almacen2.php?seccion=almacen');
            exit();
            break;

        case 'guardar_requisicion_almacen':
            try {
                $db->beginTransaction();

                $req_proy_id_save = intval($_SESSION['proyecto_requisicion_almacen'] ?? 0);
                $proyecto = sanitize($_POST['proyecto'] ?? '');
                $area = sanitize($_POST['area'] ?? '');
                $solicitante = sanitize($_POST['solicitante'] ?? $_SESSION['user_name']);
                $fecha_requerida = $_POST['fecha_requerida'] ?? null;
                $notas = sanitize($_POST['notas_generales'] ?? '');

                $items_req = [];
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['cantidad']) && !empty($item['descripcion'])) {
                            $items_req[] = [
                                'material_id'   => (!empty($item['material_id']) && is_numeric($item['material_id'])) ? intval($item['material_id']) : null,
                                'descripcion'   => sanitize($item['descripcion']),
                                'cantidad'      => intval($item['cantidad']),
                                'unidad_medida' => 'pza',
                                'marca'         => sanitize($item['marca'] ?? ''),
                                'modelo'        => sanitize($item['modelo'] ?? ''),
                                'notas'         => sanitize($item['notas'] ?? ''),
                            ];
                        }
                    }
                }

                $req = crearRequisicion($db, [
                    'usuario_id'      => $_SESSION['user_id'],
                    'proyecto'        => $proyecto,
                    'proyecto_id'     => $req_proy_id_save ?: null,
                    'area'            => $area,
                    'solicitante'     => $solicitante,
                    'fecha_requerida' => $fecha_requerida,
                    'notas_generales' => $notas,
                ], $items_req, null); // null: este flujo nunca notificó a nadie, se mantiene igual
                $folio = $req['folio'];
                $requisicion_id = $req['id'];
                avisarDuplicado($req['duplicados'] ?? []);

                // Descontar stock de los materiales que tienen suficiente existencia
                if ($req_proy_id_save) {
                    $stmt_ai = $db->prepare("SELECT material_id, cantidad_requerida FROM almacen_items WHERE proyecto_id = ? AND material_id IS NOT NULL");
                    $stmt_ai->execute([$req_proy_id_save]);
                    foreach ($stmt_ai->fetchAll() as $ai) {
                        $mid  = $ai['material_id'];
                        $cant = $ai['cantidad_requerida'];
                        // Solo descontar lo que haya disponible (el resto lo cubre la OC).
                        // Ledger unificado: antes este descuento no dejaba ningún rastro.
                        registrarMovimientoAlmacen($db, $mid, 'salida', -$cant, 'requisicion', $requisicion_id, 'Consumo al generar requisición de almacén para proyecto ' . $req_proy_id_save);
                    }
                }

                // Avanzar el proyecto a 'espera' para que compras lo tome
                if ($req_proy_id_save) {
                    $db->prepare("UPDATE proyectos SET estado = 'espera', fecha_estado = NOW() WHERE id = ?")
                       ->execute([$req_proy_id_save]);
                }

                $db->commit();

                unset($_SESSION['proyecto_requisicion_almacen']);

                // Antes se perdía siempre: el redirect (JS o header) termina la
                // respuesta antes de que showMessage()/renderFlash() (mismo request)
                // alcance a mostrar nada. Usar sesión para que sí sobreviva.
                $_SESSION['almacen_msg_exito'] = "Requisición $folio guardada correctamente";

                header('Location: ?seccion=proyectos');
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = showMessage("Error al guardar requisición: " . $e->getMessage(), 'error');
            }
            break;

        case 'finalizar_proyecto_sin_compras':
            // Stock cubre todo — descontar stock y cerrar la parte de Almacén.
            // Función vieja de las primeras versiones: ANTES marcaba aquí mismo el proyecto
            // como 'realizado' (terminado de verdad), pero eso es responsabilidad exclusiva
            // de Proyectos vía cierre_total (que exige OTs+Hoja Viajera+FAT+Equipos Fabricados,
            // ver includes/equipos_fabricados.php) -- Almacén declarando el proyecto completo
            // solo porque el catálogo ya tenía stock suficiente permitía cerrar proyectos que
            // Producción ni siquiera había empezado. Ahora hace exactamente lo mismo que su
            // caso hermano 'finalizar_proyecto' (arriba): solo apaga almacen_activo, nunca toca
            // proyectos.estado/realizada.
            $proy_id_fin = intval($_SESSION['proyecto_requisicion_almacen'] ?? 0);
            if ($proy_id_fin && proyectoTieneReemplazoPendiente($db, $proy_id_fin)) {
                $error = showMessage("Este proyecto tiene material(es) con reemplazo pendiente de aprobación -- no se puede finalizar hasta que se resuelva en Reemplazos de Material.", 'error');
                break;
            }
            if ($proy_id_fin) {
                $stmt_ai = $db->prepare("SELECT material_id, cantidad_requerida FROM almacen_items WHERE proyecto_id = ? AND material_id IS NOT NULL");
                $stmt_ai->execute([$proy_id_fin]);
                foreach ($stmt_ai->fetchAll() as $ai) {
                    // Ledger unificado: antes este descuento no dejaba ningún rastro.
                    registrarMovimientoAlmacen($db, $ai['material_id'], 'salida', -$ai['cantidad_requerida'], 'proyecto', $proy_id_fin, 'Consumo al finalizar proyecto sin compras (stock cubrió todo)');
                }
                $db->prepare("UPDATE proyectos SET almacen_activo = 0, fecha_estado = NOW(), almacen_directo_fecha_fin = NOW() WHERE id = ?")
                   ->execute([$proy_id_fin]);
                unset($_SESSION['proyecto_requisicion_almacen']);
                unset($_SESSION['almacen_proyecto_activo']);
                $message = showMessage("Stock descontado, todo estaba disponible y no se requirió compra. La parte de Almacén queda cerrada.", 'success');
                echo '<script>window.location.href="?seccion=proyectos";</script>';
                exit();
            }
            break;

        case 'surtir_requisicion':
            $requisicion_id_surtir = intval($_POST['requisicion_id'] ?? 0);
            $entregas = $_POST['entregas'] ?? [];
            if (!$requisicion_id_surtir || empty($entregas)) {
                $error = showMessage("No se especificaron cantidades a entregar.", 'error');
                break;
            }
            try {
                $db->beginTransaction();
                $stmtReq = $db->prepare("SELECT * FROM requisiciones WHERE id = ?");
                $stmtReq->execute([$requisicion_id_surtir]);
                $req_surtir = $stmtReq->fetch();
                if (!$req_surtir) throw new Exception("Requisición no encontrada.");

                $items_entregados = 0;
                $detalle_surtido_lineas = [];
                foreach ($entregas as $detalle_id => $cant_raw) {
                    $cantidad_entregar = floatval($cant_raw);
                    if ($cantidad_entregar <= 0) continue;
                    $detalle_id = intval($detalle_id);

                    $stmtD = $db->prepare("SELECT rd.*, m.stock_disponible FROM requisicion_detalle rd LEFT JOIN materiales m ON m.id = rd.material_id WHERE rd.id = ? AND rd.requisicion_id = ?");
                    $stmtD->execute([$detalle_id, $requisicion_id_surtir]);
                    $det = $stmtD->fetch();
                    if (!$det) continue;

                    $pendiente = $det['cantidad'] - $det['cantidad_entregada'];
                    if ($cantidad_entregar > $pendiente) $cantidad_entregar = $pendiente;
                    if ($cantidad_entregar <= 0) continue;

                    if ($det['material_id']) {
                        if ($cantidad_entregar > $det['stock_disponible']) {
                            $cantidad_entregar = $det['stock_disponible'];
                        }
                        if ($cantidad_entregar <= 0) continue;
                        registrarMovimientoAlmacen($db, (int)$det['material_id'], 'salida', -$cantidad_entregar, 'requisicion', $requisicion_id_surtir, "Surtido de requisición {$req_surtir['folio']}: {$det['descripcion']}");
                    }

                    $db->prepare("UPDATE requisicion_detalle SET cantidad_entregada = cantidad_entregada + ? WHERE id = ?")
                       ->execute([$cantidad_entregar, $detalle_id]);
                    $items_entregados++;
                    $detalle_surtido_lineas[] = "{$det['descripcion']}: {$cantidad_entregar} {$det['unidad_medida']}";
                }

                // Recalcular estado de la requisición según lo que quedó pendiente -- el detalle de
                // qué se surtió queda en requisicion_eventos, visible SOLO para Almacén/admin (ver
                // sección "requisiciones" de produccion.php).
                $detalle_surtido_txt = $detalle_surtido_lineas ? implode('; ', $detalle_surtido_lineas) : null;
                $nuevo_estado = recalcularEstadoRequisicion($db, $requisicion_id_surtir, $detalle_surtido_txt);

                $db->commit();

                if (!empty($req_surtir['usuario_id'])) {
                    $estadoTxt = $nuevo_estado === 'completada' ? 'completada' : 'parcialmente surtida';
                    $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, link) VALUES (?, 'requisicion_surtida', ?, ?, ?)")
                       ->execute([
                           $req_surtir['usuario_id'],
                           "Requisición $estadoTxt: {$req_surtir['folio']}",
                           "Almacén entregó material de tu requisición {$req_surtir['folio']} ($estadoTxt).",
                           'almacen2.php?seccion=ver_requisicion&id=' . $requisicion_id_surtir
                       ]);
                }

                $message = showMessage($items_entregados > 0 ? "Entrega registrada correctamente." : "No había cantidades pendientes por entregar.", 'success');
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = showMessage("Error: " . $e->getMessage(), 'error');
            }
            break;
    }
}

// Material nunca abierto (primera_vista IS NULL -- nuevo o con código sin verificar, mismo
// criterio del sello rojo/verde de la tarjeta) va AL FRENTE, para no perderse entre 3700+
// materiales. En cuanto alguien lo abre (marcar_visto en api/materiales.php) regresa a su
// orden alfabético normal.
$materiales = $db->query("SELECT m.*, c.nombre as categoria_nombre FROM materiales m LEFT JOIN categorias c ON m.categoria_id = c.id WHERE m.activo = 1 ORDER BY (m.primera_vista IS NULL) DESC, m.descripcion")->fetchAll();

// Marcas distintas para el filtro de marca del inventario de Almacén
$marcas_inventario_almacen = [];
foreach ($materiales as $m) {
    $marca = trim($m['marca'] ?? '');
    if ($marca && !in_array($marca, $marcas_inventario_almacen)) {
        $marcas_inventario_almacen[] = $marca;
    }
}
sort($marcas_inventario_almacen);

// Datos de soporte para los módulos de Entradas / Salidas
$proveedores_almacen = $db->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll();
$ordenes_compra_almacen = $db->query("SELECT id, folio, proveedor_id FROM ordenes_compra ORDER BY fecha_oc DESC LIMIT 200")->fetchAll();
$ots_almacen = $db->query("SELECT id, folio, titulo FROM ordenes_trabajo WHERE estado != 'cancelado' ORDER BY fecha_creacion DESC LIMIT 200")->fetchAll();
$proyectos_para_salida = $db->query("SELECT id, nombre FROM proyectos WHERE activo = 1 ORDER BY nombre")->fetchAll();

// p.estado='finalizado' significa "Ingeniería liberó su BOM principal" -- pero los planos de
// Producción (tipo_lista='plano_ia') se sincronizan a almacen_items independientemente de ese
// gate (se procesan durante la fabricación, no antes). Sin el OR, un proyecto con materiales de
// plano ya en Almacén (almacen_activo=1) pero cuyo BOM principal de Ingeniería sigue sin
// liberarse quedaba totalmente invisible aquí, aunque Almacén ya tuviera algo real que gestionar.
$proyectos_ingenieria = $db->query("SELECT DISTINCT p.*, u.nombre_completo as creador_nombre
    FROM proyectos p
    LEFT JOIN usuarios u ON p.creado_por = u.id
    INNER JOIN boom_items b ON p.id = b.proyecto_id
    WHERE b.guardado = 1 AND (p.estado = 'finalizado' OR p.almacen_activo = 1)
    ORDER BY p.fecha_creacion DESC")->fetchAll();

$proyectos_almacen = $db->query("SELECT p.*, u.nombre_completo as creador_nombre FROM proyectos p LEFT JOIN usuarios u ON p.creado_por = u.id WHERE p.almacen_activo = 1 ORDER BY p.fecha_estado DESC")->fetchAll();

// Detectar proyectos con cambios en BOM vs almacen_items -- antes eran 2
// queries POR PROYECTO en este foreach, corriendo en CADA carga de
// almacen2.php sin importar la sección. Ahora se traen ambas tablas
// completas de una sola vez y se agrupan por proyecto_id en PHP antes del
// mismo cálculo de siempre.
$proyectos_bom_cambios = [];
$proyectos_tiene_items = [];

// Proyectos con algún reemplazo de material pendiente de aprobación -- badge
// informativo en la lista, no oculta el proyecto (Almacén sigue pudiendo
// gestionar el resto de sus materiales mientras se resuelve).
$proyectos_reemplazo_pendiente = array_fill_keys(
    $db->query("SELECT DISTINCT proyecto_id FROM material_reemplazo_solicitudes WHERE estado = 'pendiente' AND proyecto_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN),
    true
);

$bom_rows_por_proy = [];
$stmt_bom_all = $db->query("
    SELECT bl.proyecto_id, bi.material_id, bi.item, SUM(bi.cantidad * bl.num_equipos) as total_bom
    FROM boom_items bi
    JOIN boom_listas bl ON bi.boom_id = bl.id
    WHERE bl.guardado = 1 AND bi.guardado = 1
    GROUP BY bl.proyecto_id, bi.material_id, bi.item
");
foreach ($stmt_bom_all->fetchAll() as $row) {
    $bom_rows_por_proy[$row['proyecto_id']][] = $row;
}

$ai_rows_por_proy = [];
$stmt_ai_all2 = $db->query("SELECT proyecto_id, material_id, item, cantidad_requerida FROM almacen_items");
foreach ($stmt_ai_all2->fetchAll() as $row) {
    $ai_rows_por_proy[$row['proyecto_id']][] = $row;
}

foreach ($proyectos_ingenieria as $proy) {
    $pid = $proy['id'];
    $bom_rows = $bom_rows_por_proy[$pid] ?? [];
    $ai_rows  = $ai_rows_por_proy[$pid] ?? [];
    $proyectos_tiene_items[$pid] = count($ai_rows) > 0;

    $ai_map = [];
    foreach ($ai_rows as $row) {
        $key = $row['material_id'] ?: $row['item'];
        $ai_map[$key] = (float)$row['cantidad_requerida'];
    }

    $cambios = 0;
    foreach ($bom_rows as $bom) {
        $key = $bom['material_id'] ?: $bom['item'];
        if (!isset($ai_map[$key]) || abs($ai_map[$key] - (float)$bom['total_bom']) > 0.001) {
            $cambios++;
        }
    }
    $proyectos_bom_cambios[$pid] = $cambios;
}

$seccion = isset($_GET['seccion']) ? $_GET['seccion'] : '';

if ($seccion === 'entradas') {
    $historial_entradas = $db->query("SELECT h.*, m.descripcion as material_descripcion, m.codigo as material_codigo, m.unidad_medida,
            p.nombre as proveedor_nombre, oc.folio as oc_folio, u.nombre_completo as usuario_nombre,
            GROUP_CONCAT(sl.numero_serie SEPARATOR ', ') as series
        FROM historial_movimientos h
        LEFT JOIN materiales m ON m.id = h.material_id
        LEFT JOIN ordenes_compra oc ON oc.id = h.referencia_id AND h.referencia_tipo = 'entrada_almacen'
        LEFT JOIN proveedores p ON p.id = h.proveedor_id
        LEFT JOIN usuarios u ON u.id = h.usuario_id
        LEFT JOIN series_lotes sl ON sl.movimiento_entrada_id = h.id
        WHERE h.tipo_movimiento = 'entrada'
        GROUP BY h.id
        ORDER BY h.fecha_movimiento DESC
        LIMIT 300")->fetchAll();
}

if ($seccion === 'salidas') {
    // "Destino" también debe resolver salidas que vienen de Surtir (requisición, referencia_tipo=
    // 'requisicion') y de asignación de cantidad a proyecto (almacen_items, referencia_tipo=
    // 'almacen_item') — antes ninguna de las dos tenía join propio y caían en "Sin especificar".
    $historial_salidas = $db->query("SELECT h.*, m.descripcion as material_descripcion, m.codigo as material_codigo, m.unidad_medida,
            u.nombre_completo as usuario_nombre,
            ot.folio as ot_folio, ot.titulo as ot_titulo, py.nombre as proyecto_nombre,
            rq_directa.folio as req_folio_directa,
            py_ai.nombre as ai_proyecto_nombre, rq_ai.folio as ai_req_folio,
            GROUP_CONCAT(sl.numero_serie SEPARATOR ', ') as series,
            (SELECT COUNT(*) FROM historial_movimientos r WHERE r.referencia_tipo = 'reingreso' AND r.referencia_id = h.id) as ya_reingresado
        FROM historial_movimientos h
        LEFT JOIN materiales m ON m.id = h.material_id
        LEFT JOIN usuarios u ON u.id = h.usuario_id
        LEFT JOIN series_lotes sl ON sl.movimiento_salida_id = h.id
        LEFT JOIN ordenes_trabajo ot ON ot.id = h.referencia_id AND h.referencia_tipo = 'ot'
        LEFT JOIN proyectos py ON py.id = h.referencia_id AND h.referencia_tipo = 'proyecto'
        LEFT JOIN requisiciones rq_directa ON rq_directa.id = h.referencia_id AND h.referencia_tipo = 'requisicion'
        LEFT JOIN almacen_items ai ON ai.id = h.referencia_id AND h.referencia_tipo = 'almacen_item'
        LEFT JOIN proyectos py_ai ON py_ai.id = ai.proyecto_id
        LEFT JOIN requisiciones rq_ai ON rq_ai.id = ai.requisicion_id
        WHERE h.tipo_movimiento = 'salida'
        GROUP BY h.id
        ORDER BY h.fecha_movimiento DESC
        LIMIT 300")->fetchAll();
}

if ($seccion === 'todas_requisiciones') {
    // Almacén es quien recibe todas las requisiciones que se generan en el sistema (sin importar
    // desde qué módulo/flujo se hayan creado) — a diferencia de "Mis Requisiciones" en otros
    // módulos, aquí se listan TODAS, no solo las creadas por el usuario en sesión.
    $todas_requisiciones = $db->query("SELECT r.*, u.nombre_completo AS solicitante_nombre, COUNT(rd.id) AS total_items
        FROM requisiciones r
        LEFT JOIN usuarios u ON u.id = r.usuario_id
        LEFT JOIN requisicion_detalle rd ON rd.requisicion_id = r.id
        GROUP BY r.id
        ORDER BY r.fecha_creacion DESC")->fetchAll();
}

if ($seccion === 'surtir_requisicion') {
    $surtir_req_id = intval($_GET['id'] ?? 0);
    $stmtSurtirReq = $db->prepare("SELECT r.*, u.nombre_completo AS solicitante_nombre FROM requisiciones r LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.id = ?");
    $stmtSurtirReq->execute([$surtir_req_id]);
    $surtir_requisicion = $stmtSurtirReq->fetch();

    $stmtSurtirItems = $db->prepare("SELECT rd.*, m.stock_disponible, m.unidad_medida AS mat_unidad
        FROM requisicion_detalle rd
        LEFT JOIN materiales m ON m.id = rd.material_id
        WHERE rd.requisicion_id = ?
        ORDER BY rd.item_numero ASC, rd.id ASC");
    $stmtSurtirItems->execute([$surtir_req_id]);
    $surtir_items = $stmtSurtirItems->fetchAll();

    $stmtSurtirOcs = $db->prepare("SELECT id FROM ordenes_compra WHERE requisicion_id = ? AND estado != 'recibida'");
    $stmtSurtirOcs->execute([$surtir_req_id]);
    $surtir_ocs = $stmtSurtirOcs->fetchAll();
}

$proyecto_activo_id = isset($_SESSION['almacen_proyecto_activo']) ? $_SESSION['almacen_proyecto_activo'] : 0;
$proyecto_activo_nombre = '';

if ($proyecto_activo_id) {
    $stmt = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
    $stmt->execute([$proyecto_activo_id]);
    $proyecto_activo_nombre = $stmt->fetchColumn();
}

$items_proyecto = [];
if ($proyecto_activo_id) {
    $stmt = $db->prepare("
        SELECT ai.*,
               COALESCE(m.stock_disponible, 0) AS stock_disponible_actual,
               COALESCE(c.nombre, ai.categoria_nombre, 'Sin categoría') AS cat_nombre,
               COALESCE(c.id, ai.categoria_id, 0) AS cat_id,
               m.codigo AS material_codigo, m.codigo_verificado AS material_codigo_verificado,
               (SELECT COUNT(*) FROM material_reemplazo_solicitudes mrs
                 WHERE mrs.origen_tipo = 'almacen_item' AND mrs.origen_id = ai.id AND mrs.estado = 'pendiente') AS reemplazo_pendiente
        FROM almacen_items ai
        LEFT JOIN materiales m ON ai.material_id = m.id
        LEFT JOIN categorias c ON m.categoria_id = c.id
        WHERE ai.proyecto_id = ?
        ORDER BY cat_nombre, ai.fecha_agregado DESC
    ");
    $stmt->execute([$proyecto_activo_id]);
    $items_proyecto = $stmt->fetchAll();
}

// Categorías presentes en el proyecto activo (para filtros)
$cats_proyecto = [];
foreach ($items_proyecto as $it) {
    $cid = $it['cat_id'];
    if (!isset($cats_proyecto[$cid])) {
        $cats_proyecto[$cid] = $it['cat_nombre'];
    }
}

$hay_proyecto_activo = ($proyecto_activo_id > 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" href="favicon.ico?v=3">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Almacén - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            text-decoration: none !important;
        }
        
        input, textarea {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }
        
        body {
            font-family: 'Montserrat', Arial, sans-serif;
            background: #ffffff;
            height: 100vh;
            overflow: hidden;
        }
        
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: #2E5CAE;
            box-shadow: 0 2px 10px rgba(46,92,174,0.25);
            border-bottom: 2px solid #c9a227;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        /* Logo sin tratamiento extra -- la banda #2E5CAE ya da contraste suficiente. */
        .header-logo {
            height: 66px;
            width: auto;
            object-fit: contain;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .back-button-header {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 40px;
            padding: 8px 20px;
            cursor: pointer;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .back-button-header:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
            transform: translateX(-5px);
        }
        
        .back-button-header i {
            font-size: 14px;
        }
        
        .user-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .user-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.15);
            padding: 5px 15px 5px 5px;
            border-radius: 40px;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.2s;
        }
        
        .user-trigger:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #FF9800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: white;
        }
        
        .user-info-text {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: white;
            font-size: 13px;
            line-height: 1.2;
        }
        
        .user-role {
            font-size: 10px;
            color: rgba(255,255,255,0.8);
            text-transform: uppercase;
        }
        
        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 5px);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-5px);
            transition: all 0.2s;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            z-index: 1000;
        }
        
        .user-dropdown:hover .user-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            font-size: 13px;
        }
        
        .dropdown-item:hover {
            background: #f5f5f5;
            color: #f44336;
        }
        
        .dropdown-item i {
            width: 18px;
            color: #666;
        }
        
        .app-container {
            height: 100vh;
            width: 100vw;
            padding-top: 80px;
            position: relative;
        }
        
        .main-center {
            height: calc(100vh - 140px);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding: 20px 20px 100px 20px;
        }

        .page-header { text-align: center; margin-bottom: 40px; }
        .page-title { font-size: 2.2rem; color: #003d5c; font-weight: 300; margin-bottom: 10px; }
        .page-title strong { font-weight: 800; background: linear-gradient(135deg, #003d5c, #0077b3); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 2.4rem; }
        .page-subtitle { color: #666; font-size: 1rem; }

        .cards-grid {
            display: flex;
            gap: 30px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .nav-card {
            width: 180px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .nav-card:hover {
            transform: translateY(-8px);
        }
        
        .nav-card:active {
            transform: scale(0.95);
        }
        
        .nav-card:hover .card-icon {
            border-color: #FF9800;
            box-shadow: 0 15px 30px rgba(255, 152, 0, 0.25);
        }
        
        .nav-card:hover .card-title {
            color: #FF9800;
        }
        
        .card-icon {
            width: 100%;
            aspect-ratio: 1/1;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            border: 3px solid #e0e0e0;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        
        .card-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .nav-card:hover .card-icon::before {
            left: 100%;
        }
        
        .card-icon i {
            font-size: 4rem;
            color: #005686;
            transition: all 0.3s ease;
            z-index: 2;
        }
        
        .nav-card:hover .card-icon i {
            color: #FF9800;
            transform: scale(1.1);
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            transition: color 0.3s ease;
            margin-bottom: 5px;
        }
        
        .card-desc {
            font-size: 0.8rem;
            color: #888;
            font-weight: 400;
        }
        
        .dashboard-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 40px;
            border-top: 2px solid #e0e0e0;
            background: white;
            height: 60px;
            z-index: 1000;
        }
        
        .footer-left {
            display: flex;
            gap: 30px;
            color: #555;
            font-size: 13px;
            font-weight: 500;
        }
        
        .footer-left i {
            color: #003d5c;
            width: 16px;
            margin-right: 5px;
        }
        
        .footer-right img {
            height: 25px;
            width: auto;
            object-fit: contain;
        }
        
        .section-content {
            height: calc(100vh - 140px);
            overflow-y: auto;
            padding: 0 30px 30px 30px;
            margin-top: 0;
            position: relative;
        }
        
        .sticky-container {
            position: sticky;
            top: 0;
            background: white;
            z-index: 100;
            padding: 20px 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-row h2 {
            font-size: 1.5rem;
            color: #003d5c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-wrapper {
            position: relative;
            width: 300px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 35px 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 40px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #005686;
            box-shadow: 0 0 0 3px rgba(0,86,134,0.1);
        }
        
        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 16px;
            display: none;
        }
        
        .search-clear.visible {
            display: block;
        }
        
        .search-clear:hover {
            color: #f44336;
        }
        
        .btn-categorias {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 40px;
            padding: 10px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #003d5c;
            transition: all 0.2s;
        }
        
        .btn-categorias:hover {
            border-color: #005686;
            color: #005686;
            background: #f0f8ff;
        }
        
        .btn-categorias i {
            color: #FF9800;
        }
        
        .content-panel {
            height: calc(100vh - 280px);
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .proyecto-info {
            background: #003d5c;
            color: white;
            padding: 15px 20px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 95;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .btn-outline-light {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.5);
            color: white;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            margin-left: 10px;
        }
        
        .btn-outline-light:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.8);
            transform: translateY(-2px);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary { background: #2196F3; color: white; }
        .btn-primary:hover { background: #1976D2; }
        .btn-success { background: #4CAF50; color: white; }
        .btn-success:hover { background: #388E3C; }
        .btn-warning { background: #FF9800; color: white; }
        .btn-warning:hover { background: #F57C00; }
        .btn-danger { background: #f44336; color: white; }
        .btn-danger:hover { background: #d32f2f; }
        .btn-outline { background: transparent; border: 1px solid #ddd; color: #666; }
        .btn-outline:hover { background: #f5f5f5; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .section {
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #003d5c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #333; }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        select.form-input { background: white; }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 12px 10px;
            background: #f5f5f5;
            color: #333;
            font-weight: 600;
            font-size: 13px;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }
        tr:hover td { background: #f9f9f9; }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
        }
        .modal-header {
            background: #003d5c;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body { padding: 20px; max-height: 60vh; overflow-y: auto; }
        .modal-footer {
            padding: 15px 20px;
            background: #f5f5f5;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .categoria-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .categoria-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .categoria-card:hover {
            border-color: #2196F3;
            background: #f0f8ff;
            transform: translateY(-2px);
        }
        
        .categoria-card.selected {
            border-color: #2196F3;
            background: #e3f2fd;
        }
        
        .categoria-card i {
            font-size: 32px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .categoria-card.selected i {
            color: #2196F3;
        }
        
        .categoria-card .cat-nombre {
            font-weight: 600;
            color: #333;
        }
        
        .materiales-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .material-card {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        .material-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
            border-color: #2196F3;
        }
        .material-card.selected {
            border-color: #2196F3;
            box-shadow: 0 0 0 3px rgba(33,150,243,0.3);
        }
        .material-imagen {
            height: 140px;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 40px;
            overflow: hidden;
            position: relative;
        }
        .material-watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .3px;
            color: #fff;
            padding: 6px;
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
        }
        .material-watermark.watermark-rojo { background: rgba(198,40,40,.82); }
        .material-watermark.watermark-verde { background: rgba(46,125,50,.72); }
        .material-watermark i { margin-right: 4px; }
        .material-imagen img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .material-content {
            padding: 15px;
        }
        .material-nombre { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 5px; }
        .material-descripcion { font-size: 13px; color: #666; margin-bottom: 8px; }
        .material-detalles {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #666;
            flex-wrap: wrap;
        }
        .material-stock {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }
        .stock-value {
            font-size: 18px;
            font-weight: 700;
            color: #003d5c;
        }
        .stock-actions {
            display: flex;
            gap: 5px;
        }
        .stock-btn {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 4px;
            background: #f0f0f0;
            color: #333;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stock-btn.plus:hover { background: #4CAF50; color: white; }
        .stock-btn.minus:hover { background: #f44336; color: white; }

        /* ── Layout de carpetas del inventario de Almacén (igual a Ingeniería) ── */
        .inventario-layout {
            display: flex;
            height: calc(100vh - 140px);
            overflow: hidden;
        }
        .carpetas-panel {
            width: 220px;
            min-width: 220px;
            border-right: 1px solid #e0e0e0;
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .carpetas-header {
            padding: 12px 15px;
            background: #003d5c;
            color: white;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .carpetas-header button {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            border-radius: 4px;
            padding: 3px 8px;
            cursor: pointer;
            font-size: 12px;
        }
        .carpetas-header button:hover { background: rgba(255,255,255,0.35); }
        .carpetas-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }
        .carpeta-item {
            padding: 8px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #333;
            border-left: 3px solid transparent;
            transition: all 0.15s;
        }
        .carpeta-item:hover { background: #e9ecef; }
        .carpeta-item.active { background: #e3f2fd; border-left-color: #003d5c; color: #003d5c; font-weight: 600; }
        .carpeta-item i { color: #FF9800; width: 16px; }
        .carpetas-inventario-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .btn-mover-carpeta {
            background: #f0f4f8;
            border: 1px solid #c0c8d0;
            border-radius: 4px;
            padding: 3px 8px;
            font-size: 11px;
            cursor: pointer;
            color: #003d5c;
            margin-top: 5px;
        }
        .btn-mover-carpeta:hover { background: #e3f2fd; }

        /* ── Semáforo de stock en la ficha de material (solo Almacén) ── */
        .material-semaforo {
            display: flex;
            gap: 3px;
            padding: 6px 10px 0;
        }
        .semaforo-luz {
            flex: 1;
            height: 5px;
            border-radius: 3px;
            background: #e0e0e0;
        }
        .material-semaforo.stock-alto .luz-verde  { background: #4CAF50; }
        .material-semaforo.stock-bajo .luz-amarilla { background: #FFC107; }
        .material-semaforo.stock-cero .luz-roja   { background: #f44336; }

        .proyecto-activo-badge {
            background: #FF9800;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .boom-guardado {
            margin-bottom: 30px;
            border: 1px solid #FF9800;
            border-radius: 4px;
            overflow: hidden;
        }
        .boom-guardado-header {
            background: #FF9800;
            color: white;
            padding: 15px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: none;
            background: #f44336;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .btn-icon:hover {
            background: #d32f2f;
            transform: scale(1.1);
        }
        
        .btn-icon i {
            font-size: 14px;
        }
        
        .reutilizar-btn {
            background: white;
            color: #4CAF50;
            border: 2px solid #4CAF50;
            padding: 8px 15px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .reutilizar-btn:hover {
            background: #4CAF50;
            color: white;
            transform: translateY(-2px);
        }
        
        .cantidad-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .cantidad-btn {
            width: 40px;
            height: 40px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 18px;
        }
        
        .cantidad-input {
            width: 80px;
            height: 40px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 18px;
            font-weight: bold;
        }
        
        .btn-limpiar {
            background: #f5f5f5;
            color: #666;
            border: 1px solid #ddd;
        }
        
        .btn-limpiar:hover {
            background: #e0e0e0;
            color: #333;
        }
        
        .search-guardados {
            width: 100%;
            max-width: 400px;
        }
        
        .stock-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        .comparativa-panel {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .checklist-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: white;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        
        /* Estilos de requisición */
        .req-header {
            display: flex;
            border: 2px solid #333;
            margin-bottom: 20px;
        }
        .req-logo-section {
            width: 250px;
            border-right: 2px solid #333;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f9f9f9;
        }
        .req-title-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #003d5c;
        }
        .req-title-section h1 {
            color: white;
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            border: 2px solid #333;
        }
        .info-table td, .info-table th {
            border: 1px solid #333;
            padding: 8px 12px;
            font-size: 14px;
        }
        .info-table td:first-child {
            background: #f0f0f0;
            font-weight: normal;
            width: 30%;
        }
        .info-table input {
            width: 100%;
            border: none;
            padding: 4px;
            font-size: 14px;
            font-family: Arial, sans-serif;
        }
        .info-table input:focus { outline: none; background: #e8f4fc; }
        .info-table input[readonly] { background: #f5f5f5; cursor: not-allowed; }
        .materiales-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #333;
            margin-bottom: 20px;
        }
        .materiales-table th {
            background: #e0e0e0;
            border: 1px solid #333;
            padding: 10px 8px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
        }
        .materiales-table td {
            border: 1px solid #333;
            padding: 6px 8px;
            font-size: 13px;
        }
        .materiales-table input, .materiales-table select {
            width: 100%;
            border: none;
            padding: 4px;
            font-size: 13px;
            font-family: Arial, sans-serif;
        }
        .materiales-table input:focus, .materiales-table select:focus {
            outline: none;
            background: #e8f4fc;
        }
        .col-item { width: 50px; text-align: center; }
        .col-cantidad { width: 80px; }
        .col-articulo { width: 120px; }
        .col-descripcion { width: auto; }
        .col-marca { width: 120px; }
        .col-modelo { width: 120px; }
        .col-nota { width: 100px; }
        .col-accion { width: 40px; text-align: center; }
        .req-actions-bar { display: flex; gap: 10px; margin-bottom: 20px; }
        .fixed-actions {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 3px solid #003d5c;
            padding: 15px 30px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            z-index: 2000;
            box-shadow: 0 -5px 15px rgba(0,0,0,0.1);
        }
        .req-footer-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #333;
            margin-top: 30px;
        }
        .req-footer-table td {
            border: 1px solid #333;
            padding: 8px 12px;
            font-size: 13px;
        }
        .req-footer-table .footer-input {
            border: none;
            border-bottom: 1px solid #999;
            background: transparent;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            width: 40px;
            text-align: center;
        }
        .req-footer-table .footer-input:focus {
            background: #e8f4fc;
            border-bottom-color: #003d5c;
        }
        
        /* Ajuste para el padding inferior en la sección de requisición */
        .section-content.requisicion-padding {
            padding-bottom: 100px; /* Para que el contenido no quede detrás del panel fijo */
        }
        /* ===== ESTILOS PARA PANELES DIVIDIDOS (ALMACÉN) ===== */
        .split-view-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            height: calc(100vh - 240px); 
            width: 100%;
        }

        .split-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            transition: all 0.3s ease;
            min-height: 0;
        }

        .split-panel.maximized { flex: 100% !important; }
        .split-panel.minimized {
            flex: 0 !important; border: none; margin: 0; padding: 0;
            opacity: 0; overflow: hidden; pointer-events: none;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
        }

        .panel-header h2, .panel-header h3 {
            margin: 0; font-size: 1.2rem; color: #003d5c;
            display: flex; align-items: center; gap: 10px;
        }

        .panel-body {
            flex: 1; overflow-y: auto; padding: 20px; background: #ffffff;
        }

        .btn-maximize {
            background: #ffffff; border: 1px solid #ccc; color: #555;
            width: 32px; height: 32px; border-radius: 4px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; margin-left: 15px;
        }

        .btn-maximize:hover { background: #e0e0e0; color: #003d5c; border-color: #003d5c; }

        /* ===== TARJETAS COMPACTAS PARA ALMACÉN ===== */
        #materialesAlmacenGrid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        #materialesAlmacenGrid .material-imagen { height: 90px; font-size: 30px; }
        #materialesAlmacenGrid .material-content { padding: 10px; }
        #materialesAlmacenGrid .material-nombre {
            font-size: 13px; font-weight: 700; margin-bottom: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        #materialesAlmacenGrid .material-descripcion {
            font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        #materialesAlmacenGrid .material-stock {
            font-size: 12px; margin-top: 8px; padding-top: 8px;
        }
        /* ===== FOOTER ANCLADO PARA PANELES ===== */
        .sticky-panel-footer {
            position: sticky;
            bottom: -20px; /* Compensa el padding exacto del panel-body */
            background: #f8f9fa;
            padding: 15px 20px;
            margin: 20px -20px -20px -20px; /* Estira el fondo gris hasta los bordes */
            border-top: 1px solid #e0e0e0;
            text-align: right;
            z-index: 10;
            box-shadow: 0 -4px 10px rgba(0,0,0,0.03); /* Ligera sombra hacia arriba */
        }
        /* ===== ESTILOS PARA LA CARPETA DEL PROYECTO ===== */
        .carpeta-content {
            display: none;
            background: #f4f7f9;
            border: 1px solid #c8d4dd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 20px;
            animation: fadeInDown 0.3s ease-out;
        }

        .carpeta-content.active {
            display: block;
        }

        .btn-carpeta-toggle {
            background: transparent;
            border: none;
            color: #003d5c;
            cursor: pointer;
            font-size: 16px;
            padding: 5px;
            transition: transform 0.3s;
        }

        .btn-carpeta-toggle.open {
            transform: rotate(180deg);
        }

        .documento-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 15px;
            height: 100%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===== ESTILOS BASE DEL CHAT ===== */
        .chat-bubble {
            position: fixed;
            bottom: 80px;
            right: 20px;
            z-index: 9999;
        }
        .chat-button {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #003d5c, #005686);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            position: relative;
        }
        .chat-button i {
            font-size: 28px;
            color: white;
        }
        .chat-status {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
            background-color: #4caf50;
        }
        .chat-status.offline {
            background-color: #f44336;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #f44336;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .chat-window {
            position: fixed;
            bottom: 150px;
            right: 20px;
            width: 380px;
            height: 550px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            z-index: 9998;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        .chat-window.open {
            display: flex;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .chat-header {
            background: linear-gradient(135deg, #003d5c, #005686);
            color: white;
            padding: 12px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header h4 {
            margin: 0;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chat-header-actions {
            display: flex;
            gap: 10px;
        }
        .chat-header-actions button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
        }
        .chat-tabs {
            display: flex;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }
        .chat-tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #666;
            border-bottom: 2px solid transparent;
        }
        .chat-tab.active {
            color: #003d5c;
            border-bottom-color: #003d5c;
        }
        .chat-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-view {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .chat-view-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .chat-view-title {
            font-weight: 600;
            font-size: 16px;
            color: #333;
        }
        .chat-users-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        .chat-user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #f0f0f0;
        }
        .chat-user-item:hover {
            background: #f5f5f5;
        }
        .chat-user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: #FF9800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }
        .chat-user-info {
            flex: 1;
            min-width: 0;
        }
        .chat-user-name {
            font-weight: 600;
            font-size: 15px;
            color: #333;
            margin-bottom: 3px;
        }
        .chat-user-role {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }
        .chat-user-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .chat-user-status.online {
            background: #4caf50;
        }
        .chat-user-status.offline {
            background: #9e9e9e;
        }
        .chat-user-badge {
            background: #f44336;
            color: white;
            border-radius: 12px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 8px;
        }
        .btn-back-chat {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #003d5c;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-back-chat:hover {
            background: #e0e0e0;
        }
        .chat-conversation {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-conversation-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .chat-conversation-header .chat-user-avatar {
            width: 40px;
            height: 40px;
            font-size: 16px;
        }
        .chat-conversation-header .chat-user-info {
            flex: 1;
        }
        .chat-conversation-header .chat-user-name {
            font-size: 16px;
            margin-bottom: 0;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .message-item {
            display: flex;
            flex-direction: column;
            max-width: 85%;
        }
        .message-sent {
            align-items: flex-end;
            align-self: flex-end;
        }
        .message-received {
            align-items: flex-start;
            align-self: flex-start;
        }
        .message-bubble {
            padding: 10px 12px;
            border-radius: 18px;
            font-size: 13px;
            word-wrap: break-word;
            max-width: 100%;
        }
        .message-sent .message-bubble {
            background: #003d5c;
            color: white;
            border-bottom-right-radius: 5px;
        }
        .message-received .message-bubble {
            background: white;
            border: 1px solid #e0e0e0;
            color: #333;
            border-bottom-left-radius: 5px;
        }
        .message-time {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
            margin-left: 8px;
            margin-right: 8px;
        }
        .message-file {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0,0,0,0.05);
            padding: 6px 10px;
            border-radius: 12px;
            cursor: pointer;
        }
        .message-file i {
            font-size: 20px;
        }
        .message-file span {
            font-size: 12px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .message-file-actions {
            display: flex;
            gap: 8px;
            margin-top: 5px;
        }
        .message-file-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 12px;
        }
        .message-file-actions button:hover {
            background: rgba(0,0,0,0.1);
        }
        .chat-input-area {
            padding: 12px;
            border-top: 1px solid #e0e0e0;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .chat-input-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .chat-input-row textarea {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 13px;
            outline: none;
            resize: vertical;
            font-family: inherit;
            min-height: 40px;
            max-height: 120px;
        }
        .chat-input-row textarea:focus {
            border-color: #003d5c;
        }
        .chat-input-row button {
            background: #003d5c;
            border: none;
            color: white;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            cursor: pointer;
            flex-shrink: 0;
        }
        .chat-file-buttons {
            display: flex;
            gap: 8px;
            padding-left: 5px;
        }
        .chat-file-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 50%;
        }
        .chat-file-btn:hover {
            background: #f0f0f0;
            color: #003d5c;
        }
        .empty-chat {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-chat i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }
        .image-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .image-preview-modal.active {
            display: flex;
        }
        .image-preview-modal img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }
        .image-preview-modal .close-preview {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 30px;
            cursor: pointer;
        }
        .file-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: white;
            z-index: 10000;
            display: none;
            flex-direction: column;
        }
        .file-preview-modal.active {
            display: flex;
        }
        .file-preview-header {
            background: #003d5c;
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .file-preview-body {
            flex: 1;
            overflow: auto;
            padding: 20px;
        }
        .file-preview-body iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        .notification-item {
            background: #e8f4fc;
            border-left: 4px solid #FF9800;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 8px;
            cursor: pointer;
        }
        .notification-item:hover {
            background: #e0eef8;
            transform: translateX(3px);
        }
        .notif-unread {
            background: #fff8e1 !important;
            border-left-color: #FF9800 !important;
            font-weight: 600;
        }
        .notif-unread::after {
            content: '';
            display: inline-block;
            width: 8px; height: 8px;
            background: #FF9800;
            border-radius: 50%;
            float: right;
            margin-top: 4px;
        }
        .notification-title {
            font-weight: 600;
            font-size: 13px;
            color: #003d5c;
            margin-bottom: 5px;
        }
        .notification-message {
            font-size: 12px;
            color: #666;
        }
        .notification-time {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }

        /* ===== MODERNIZACIÓN VISTA REQUISICIÓN ===== */
        #zona-pdf {
            border-radius: 8px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.10);
            font-family: Arial, Helvetica, sans-serif;
        }

        /* ── Tabla de metadatos (solicitante) ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            border: 1px solid #b0c4de;
            border-radius: 6px;
            overflow: hidden;
            font-size: 12px;
        }
        .info-table td {
            padding: 9px 13px;
            border: 1px solid #b0c4de;
            vertical-align: middle;
        }
        .info-table .label-cell {
            background: #eef2f7;
            font-weight: 700;
            color: #1a3a5c;
            width: 26%;
            white-space: nowrap;
        }
        .info-table .value-cell {
            color: #222;
            background: #fff;
        }

        /* ══ MATRIZ RÍGIDA PDF-SAFE ══ */
        .req-rigid-matrix-table {
            table-layout: fixed !important;
            width: 100% !important;
            border-collapse: collapse !important;
            margin-bottom: 20px !important;
            border: none !important;
            font-family: 'Helvetica Neue', Arial, sans-serif;
        }
        .req-rigid-matrix-table th {
            background: #0056b3 !important;
            color: white !important;
            font-weight: bold !important;
            padding: 12px 6px !important;
            text-align: center !important;
            border: none !important;
        }
        .req-rigid-matrix-table td {
            padding: 10px 8px !important;
            border-bottom: 1px solid #e2e8f0 !important;
            border-left: none !important;
            border-right: none !important;
            border-top: none !important;
            vertical-align: middle !important;
            overflow: hidden !important;
        }
        .req-rigid-matrix-table tbody tr:nth-child(even) td { background: #f8fafd !important; }
        .req-rigid-matrix-table tbody tr:last-child td { border-bottom: none !important; }

        /* ── Escudo de texto: nowrap para encabezados, desc-mode para descripción ── */
        .cell-text-shield {
            display: block !important;
            width: 100% !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.3 !important;
            font-size: 11px !important;
            box-sizing: border-box !important;
        }
        .cell-text-shield.desc-mode {
            white-space: normal !important;
            word-break: keep-all !important;
            overflow-wrap: break-word !important;
        }
        /* Copia de solo texto de la Descripción, solo para impresión -- ver
           comentario junto al <div class="solo-impresion"> en la tabla. */
        .solo-impresion { display: none !important; }
        /* Mismo truco para Rev./Fecha/Página del pie DOC-COM-FO-01B: esos
           <input> (decorativos, sin name -- no se envían con el formulario)
           también se veían con letra más grande que su etiqueta al imprimir,
           el font-size en CSS no bajaba igual que en el resto de la celda. */
        .solo-impresion-inline { display: none !important; }

        /* ── Input camuflado como texto plano ── */
        .clean-camuflaje-input {
            border: none !important;
            background: transparent !important;
            text-align: center !important;
            font-size: inherit !important;
            outline: none !important;
            box-shadow: none !important;
            width: 100%;
            padding: 0 !important;
            margin: 0 !important;
            font-family: inherit !important;
        }
        /* Descripción va en <textarea> (no <input>) porque un <input> nunca baja
           de línea sin importar el CSS del padre -- con texto largo simplemente
           se recortaba en vez de envolver. resize:none + oninput ajustando la
           altura imita el comportamiento de una celda normal. */
        .desc-textarea { resize: none; overflow: hidden; line-height: 1.35; min-height: 1.35em; }

        /* ── Destrucción absoluta de columna de acción en impresión ── */
        @media print {
            .col-accion, .col-accion *, button.btn-danger, th.col-accion, td.col-accion {
                display: none !important;
                visibility: hidden !important;
                width: 0 !important;
                max-width: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
            }
        }

        /* ── Impresión ── */
        @media print {
            /* Horizontal -- en vertical la tabla de materiales (7 columnas) no
               tenía espacio real para la Descripción y terminaba tragándose
               texto largo, igual que pasaba con los BOMs antes de ese fix. */
            @page { size: letter landscape; margin: 1.2cm 1cm; }
            .no-print,
            .no-print *,
            tr.no-print,
            td.no-print,
            .col-accion,
            .col-accion * { display: none !important; visibility: hidden !important; height: 0 !important; padding: 0 !important; margin: 0 !important; }
            .top-header  { display: none !important; }
            /* window.print() (generarPDF()) confía en este bloque para limpiar TODO
               el chrome de la app -- antes se quedaban colados el botón flotante del
               chat (con su badge de notificaciones), la barra fija de botones
               "Guardar PDF/Limpiar/Guardar Requisición" y el footer genérico del
               dashboard (correo/logo, no confundir con el footer institucional
               propio del documento que sí debe imprimirse). También la barra
               "Proyecto: X / Cancelar" y el botón "Seleccionar del Catálogo" --
               son controles de la pantalla, no parte del documento. */
            .chat-bubble, .chat-window, .dashboard-footer, .fixed-actions,
            .proyecto-info, .req-actions-bar { display: none !important; }
            .app-container   { padding-top: 0 !important; height: auto !important; overflow: visible !important; }
            .section-content { height: auto !important; overflow: visible !important; }
            #zona-pdf    { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; }
            tr { page-break-inside: avoid; }
            .req-rigid-matrix-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            /* Tamaños pensados para pantalla (título 24px, celdas 14px, logo de
               250px, notas de 80px de alto) se ven enormes en una hoja impresa --
               misma escala compacta que ya usan los PDFs de BOM (9-11px). */
            .req-header { margin-bottom: 8px !important; }
            .req-logo-section { width: 130px !important; padding: 6px !important; }
            .req-logo-section img { max-height: 40px !important; }
            .req-title-section h1 { font-size: 15px !important; letter-spacing: 1px !important; }
            .info-table { margin-bottom: 8px !important; }
            .info-table td, .info-table th { padding: 4px 8px !important; font-size: 10px !important; }
            .info-table input { font-size: 10px !important; padding: 2px !important; }
            .req-rigid-matrix-table { margin-bottom: 8px !important; }
            .req-rigid-matrix-table th { padding: 5px 6px !important; }
            .req-rigid-matrix-table td { padding: 2px 6px !important; }
            .cell-text-shield { font-size: 9px !important; }
            /* El <textarea> editable de Descripción se reemplaza por texto
               plano al imprimir -- ver comentario junto al <div
               class="solo-impresion"> en la tabla (un <textarea> puede quedar
               como campo de formulario dentro del PDF con su propio motor de
               layout, cortando el texto de forma impredecible). */
            .solo-impresion { display: block !important; }
            .notas-generales-label { font-size: 10px !important; }
            textarea[name="notas_generales"] { height: 30px !important; padding: 4px !important; font-size: 9px !important; }
            /* Pie DOC-COM-FO-01B y footer institucional (dirección/tel/logo) --
               misma escala compacta que el resto (9px), no los 10-11px + padding
               generoso con los que se ven grandes junto a la tabla ya reducida. */
            .req-iso-footer { margin-top: 8px !important; font-size: 9px !important; }
            .req-iso-footer td { padding: 3px 6px !important; font-size: 9px !important; }
            .req-iso-footer input { font-size: 9px !important; }
            .solo-impresion-inline { display: inline !important; font-size: 9px !important; }
            .req-institucional-footer { margin-top: 6px !important; padding-top: 6px !important; font-size: 8px !important; }
            .req-institucional-footer div { line-height: 1.4 !important; }
            .req-institucional-footer img { max-height: 20px !important; }
            /* Ese padding-bottom es solo para que el contenido no quede tapado
               por la barra fija de botones en pantalla -- en impresión esa
               barra ya está oculta (.fixed-actions arriba), así que este
               espacio en blanco solo empujaba el pie a una página aparte. */
            .requisicion-padding { padding-bottom: 0 !important; }
            body {
                font-family: 'Helvetica Neue', Arial, sans-serif;
                font-size: 11px;
                background: white;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                height: auto !important;
                overflow: visible !important;
            }
        }
    </style>
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body>
    <div class="top-header">
        <img src="uploads/logo/logo.png" alt="EMPRESA EJEMPLO" class="header-logo" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'60\' viewBox=\'0 0 200 60\'><rect width=\'200\' height=\'60\' fill=\'%23005b96\'/><text x=\'20\' y=\'40\' font-family=\'Arial\' font-size=\'24\' fill=\'white\'>EMPRESA EJEMPLO</text></svg>'">
        
        <div class="header-right">
            <?php if ($seccion !== ''): ?>
            <a href="?seccion=" class="back-button-header">
                <i class="fas fa-arrow-left"></i> Volver al Menú
            </a>
            <?php endif; ?>
            
            <div style="position:relative;">
                <div id="notifBellBtn" onclick="toggleNotifHeaderDropdown()" style="position:relative;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer;flex-shrink:0;transition:background .2s;" onmouseenter="this.style.background='rgba(255,255,255,.28)'" onmouseleave="this.style.background='rgba(255,255,255,.15)'">
                    <i class="fas fa-bell"></i>
                    <span id="notifBellBadge" style="position:absolute;top:-4px;right:-4px;background:#e53935;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:.7rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #005686;">0</span>
                </div>
                <div id="notifHeaderDropdown" style="display:none;position:absolute;top:52px;right:0;z-index:9999;width:380px;max-height:70vh;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.3);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid #eee;">
                        <span style="font-size:12px;font-weight:700;color:#005686;"><i class="fas fa-warehouse"></i> Notificaciones de Almacén</span>
                        <div style="display:flex;gap:6px;">
                            <button onclick="marcarTodasLeidasAlmacen()" class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                                <i class="fas fa-check-double"></i> Marcar leído
                            </button>
                            <button onclick="borrarNotificacionesAlmacen()" class="btn btn-sm" style="background:#ffebee;color:#c62828;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">
                                <i class="fas fa-trash"></i> Borrar
                            </button>
                        </div>
                    </div>
                    <div id="notifHeaderList" style="padding:6px;">
                        <div class="empty-chat">
                            <i class="fas fa-bell-slash"></i>
                            <p>No hay notificaciones</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-dropdown">
                <div class="user-trigger">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <div class="user-info-text">
                        <div class="user-name"><?php echo $_SESSION['user_name']; ?></div>
                        <div class="user-role"><?php echo $_SESSION['user_type']; ?></div>
                    </div>
                </div>
                <div class="user-dropdown-menu">
    <?php if ($mostrar_boton_menu): ?>
    <a href="Menu.php" class="dropdown-item" style="color: #4CAF50;">
        <i class="fas fa-arrow-left"></i> Volver al Menú de Perfiles
    </a>
    <div style="height: 1px; background: #e0e0e0; margin: 5px 0;"></div>
    <?php endif; ?>
    
    <a href="?logout=1" class="dropdown-item">
        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
    </a>
</div>
            </div>
        </div>
    </div>

    <div id="globalFlashToast" style="position:fixed;top:70px;right:20px;z-index:5000;max-width:420px;">
        <?php renderFlash(); ?>
    </div>
    <script>
        (function() {
            const toast = document.getElementById('globalFlashToast');
            if (toast && toast.children.length > 0) {
                setTimeout(() => {
                    toast.style.transition = 'opacity .4s ease';
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 400);
                }, 5000);
            }
        })();
    </script>

    <div class="app-container">
        <?php if ($seccion === 'ver_requisicion'):
            $req_id = intval($_GET['id'] ?? 0);
            
            // CORRECCIÓN: Adaptamos las consultas a la estructura real
            try {
                $stmt = $db->prepare("SELECT * FROM requisiciones WHERE id = ?");
                $stmt->execute([$req_id]);
                $requisicion = $stmt->fetch();
                if ($requisicion) {
                    marcarRequisicionVistaSiNueva($db, $req_id, (int) $requisicion['usuario_id']);
                }

                // Leemos de requisicion_detalle en lugar de requisicion_items
                $stmt_items = $db->prepare("SELECT rd.*, m.codigo FROM requisicion_detalle rd LEFT JOIN materiales m ON rd.material_id = m.id WHERE rd.requisicion_id = ?");
                $stmt_items->execute([$req_id]);
                $req_items_saved = $stmt_items->fetchAll();
            } catch (Exception $e) {
                $requisicion = false;
                $req_items_saved = [];
            }
        ?>
        <!-- ══════════════════════════════════════════════════════════
             HOJA DE REQUISICIÓN — sección ver_requisicion
             Reconstruida: table-layout:fixed + div-wrapper anti-corte
        ══════════════════════════════════════════════════════════ -->
        <div class="section-content" style="max-width:1020px; margin:0 auto; padding-top:20px;">

            <!-- Barra de herramientas (oculta en impresión) -->
            <div class="no-print sticky-container" style="background:transparent; border:none; margin-bottom:0;">
                <div class="header-row">
                    <h2 style="display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-file-invoice"></i>
                        Requisición Folio: <?php echo $req_id; ?>
                    </h2>
                    <div class="action-buttons">
                        <?php if ($requisicion && !in_array($requisicion['estado'], ['completada', 'cancelada', 'rechazada'], true)): ?>
                        <a href="?seccion=surtir_requisicion&id=<?= (int)$req_id ?>" class="btn btn-success">
                            <i class="fas fa-dolly"></i> Surtir
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn" style="background:#17a2b8; color:#fff; border:none;" onclick="generarPDF()">
                            <i class="fas fa-file-pdf"></i> Descargar PDF
                        </button>
                        <button type="button" class="btn btn-outline" onclick="history.back()">
                            <i class="fas fa-arrow-left"></i> Volver
                        </button>
                    </div>
                </div>
            </div>

            <?php if (!$requisicion): ?>
                <div class="alert alert-error text-center">No se encontró la requisición. Verifica la base de datos.</div>
            <?php else: ?>

            <div id="zona-pdf" style="background:#fff; padding:32px 36px; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,.09); margin-bottom:40px; font-family:'Helvetica Neue',Arial,sans-serif;">

                <!-- ══ 1. CABECERA CORPORATIVA ══ -->
                <table style="width:100%; border-collapse:collapse; border:2px solid #003d5c; margin-bottom:16px; border-radius:6px; overflow:hidden;">
                    <tr>
                        <td style="width:210px; border-right:2px solid #003d5c; padding:14px 18px; background:#f4f7fb; text-align:center; vertical-align:middle;">
                            <img src="uploads/logo/logo.png"
                                 style="max-width:170px; max-height:65px; object-fit:contain;"
                                 alt="EMPRESA EJEMPLO"
                                 onerror="this.style.display='none'">
                        </td>
                        <td style="background:#003d5c; text-align:center; vertical-align:middle; padding:12px 20px;">
                            <div style="color:#fff; font-size:20px; font-weight:800; letter-spacing:3px; text-transform:uppercase; line-height:1.2;">
                                HOJA DE REQUISICIÓN
                            </div>
                            <div style="color:rgba(255,255,255,.65); font-size:10px; letter-spacing:1px; margin-top:4px;">
                                Folio&nbsp;#<?php echo $req_id; ?>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- ══ 2. METADATOS DEL SOLICITANTE ══ -->
                <table class="info-table" style="margin-bottom:18px;">
                    <tr>
                        <td class="label-cell">Proyecto / Cliente destino</td>
                        <td class="value-cell">
                            <?php echo htmlspecialchars($requisicion['proyecto'] ?? $requisicion['proyecto_nombre'] ?? 'N/A'); ?>
                        </td>
                        <td class="label-cell" style="width:24%;">Fecha máx. requerida</td>
                        <td class="value-cell" style="width:14%; text-align:center;">
                            <?php echo !empty($requisicion['fecha_requerida'])
                                ? date('d/m/Y', strtotime($requisicion['fecha_requerida']))
                                : '—'; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label-cell">Departamento solicitante</td>
                        <td class="value-cell" colspan="3">
                            <?php echo htmlspecialchars($requisicion['area'] ?? 'Almacén'); ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label-cell">Nombre del solicitante</td>
                        <td class="value-cell" colspan="3">
                            <?php echo htmlspecialchars($requisicion['solicitante'] ?? 'N/A'); ?>
                        </td>
                    </tr>
                </table>

                <!-- ══ 3. TABLA DE ARTÍCULOS ══ -->
                <table class="req-rigid-matrix-table">
                    <thead>
                        <tr>
                            <th style="width:5%;"><div class="cell-text-shield">Ítem</div></th>
                            <th style="width:8%;"><div class="cell-text-shield">Cantidad</div></th>
                            <th style="width:15%;"><div class="cell-text-shield">Artículo</div></th>
                            <th style="width:32%; text-align:left; padding-left:12px !important;"><div class="cell-text-shield desc-mode">Descripción</div></th>
                            <th style="width:13%;"><div class="cell-text-shield">Marca</div></th>
                            <th style="width:13%;"><div class="cell-text-shield">Modelo</div></th>
                            <th style="width:14%;"><div class="cell-text-shield">Nota</div></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($req_items_saved)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; color:#999; padding:22px !important;">
                                Sin artículos registrados
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php $idx = 1; foreach ($req_items_saved as $item):
                                $i_num   = $idx++;
                                $i_cant  = $item['cantidad'];
                                $i_cod   = !empty($item['codigo'])        ? htmlspecialchars($item['codigo'])        : '-';
                                $i_desc  = !empty($item['descripcion'])   ? htmlspecialchars($item['descripcion'])   : '-';
                                $i_marca = !empty($item['marca'])         ? htmlspecialchars($item['marca'])         : '-';
                                $i_mod   = !empty($item['modelo'])        ? htmlspecialchars($item['modelo'])        : '-';
                                $i_nota  = !empty($item['notas'])         ? htmlspecialchars($item['notas'])         : '-';
                            ?>
                            <tr>
                                <td style="text-align:center;"><div class="cell-text-shield" style="font-weight:700;"><?php echo $i_num; ?></div></td>
                                <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_cant; ?></div></td>
                                <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_cod; ?></div></td>
                                <td style="text-align:left; padding-left:12px !important;"><div class="cell-text-shield desc-mode"><?php echo $i_desc; ?></div></td>
                                <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_marca; ?></div></td>
                                <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_mod; ?></div></td>
                                <td style="text-align:center;"><div class="cell-text-shield"><?php echo $i_nota; ?></div></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- ══ 4. NOTAS GENERALES ══ -->
                <?php if (!empty($requisicion['notas_generales'])): ?>
                <div style="margin-top:16px; margin-bottom:20px; border:1px solid #ccc; border-radius:4px; padding:12px 14px; background:#fafafa;">
                    <strong style="display:block; margin-bottom:4px; color:#333; font-size:11px;">Notas Generales:</strong>
                    <p style="margin:0; font-size:11px; color:#333; line-height:1.5;"><?php echo nl2br(htmlspecialchars($requisicion['notas_generales'])); ?></p>
                </div>
                <?php else: ?>
                <div style="height:20px;"></div>
                <?php endif; ?>

                <!-- ══ 5. PIE DE CONTROL DE CALIDAD ══ -->
                <table style="width:100%; border-collapse:collapse; border:1px solid #aaa; margin-top:20px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:11px;">
                    <tr>
                        <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px; font-weight:700; letter-spacing:.5px;">
                            DOC-COM-FO-01B
                        </td>
                        <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                            Rev.&nbsp;<input type="text" value="4"
                                style="width:20px; text-align:left; border:none; background:transparent; outline:none; font-size:inherit; box-shadow:none; padding:0; margin:0; font-family:inherit;">
                        </td>
                        <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                            Fecha:&nbsp;<input type="text" value="20 - dic - 21"
                                style="width:85px; border:none; background:transparent; text-align:center; outline:none; font-size:inherit; box-shadow:none; padding:0; margin:0; font-family:inherit;">
                        </td>
                        <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                            Página:&nbsp;<input type="text" value="1"
                                style="width:20px; border:none; background:transparent; text-align:center; outline:none; font-size:inherit; box-shadow:none; padding:0; margin:0; font-family:inherit;">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="4" style="text-align:center; padding:8px 12px; border-top:1px solid #aaa; font-size:10px; color:#444; line-height:1.4;">
                            Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                        </td>
                    </tr>
                </table>

                <!-- ══ 6. BLOQUE DE CONTACTO INSTITUCIONAL ══ -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; padding-top:10px; border-top:1px solid #e0e0e0; font-size:10px; color:#333; gap:10px; font-family:'Helvetica Neue',Arial,sans-serif;">
                    <div style="flex:1; text-align:left; line-height:1.7;">
                        Calle Ejemplo No. 123<br>
                        Col. Centro. C.P. 00000<br>
                        Ciudad de M&eacute;xico<br>
                        <span style="color:#0056b3; font-weight:600;">www.ejemplo.com</span>
                    </div>
                    <div style="flex:1; text-align:center; line-height:1.7;">
                        Tel. 52 55 0000 0000<br>
                        contacto@ejemplo.com<br>
                        <strong>Sistema de Gesti&oacute;n de Calidad</strong>
                    </div>
                    <div style="flex:0 0 auto; text-align:right; display:flex; align-items:center; justify-content:flex-end;">
                        <img src="../uploads/logo/isotipo.png"
                             style="width:30px; height:auto;"
                             alt="EMPRESA EJEMPLO"
                             onerror="this.src='uploads/logo/isotipo.png'">
                    </div>
                </div>

            </div><!-- /#zona-pdf -->
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($seccion === ''): ?>
        <div class="main-center">
            <div class="page-header">
                <h1 class="page-title"><strong>Almacén</strong><br>Panel de Control</h1>
                <p class="page-subtitle">Selecciona el módulo al que deseas acceder</p>
            </div>
            <div class="cards-grid">
                <div class="nav-card" onclick="window.location.href='?seccion=inventario'">
                    <div class="card-icon">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div class="card-title">Inventario</div>
                    <div class="card-desc">Gestionar existencias</div>
                </div>
                
                <div class="nav-card" onclick="window.location.href='?seccion=proyectos'">
                    <div class="card-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="card-title">Proyectos</div>
                    <div class="card-desc">Proyectos de ingeniería</div>
                </div>
                
                <div class="nav-card" onclick="window.location.href='?seccion=almacen'">
                    <div class="card-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="card-title">Almacén Activo</div>
                    <div class="card-desc">Proyecto en curso</div>
                </div>
                
                <div class="nav-card" onclick="window.location.href='?seccion=requisicion_almacen'">
                    <div class="card-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="card-title">Requisición</div>
                    <div class="card-desc">Generar hoja</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=todas_requisiciones'" style="border-top: 3px solid #1565c0;">
                    <div class="card-icon" style="color:#1565c0">
                        <i class="fas fa-list-alt"></i>
                    </div>
                    <div class="card-title">Todas las Requisiciones</div>
                    <div class="card-desc">Todo lo que se solicita en el sistema</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=recepcion_pedidos'" style="border-top: 3px solid #e64a19;">
                    <div class="card-icon" style="color:#e64a19">
                        <i class="fas fa-truck-loading"></i>
                    </div>
                    <div class="card-title">Recepción</div>
                    <div class="card-desc">Confirmar llegada de pedidos</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=entradas'" style="border-top: 3px solid #4CAF50;">
                    <div class="card-icon" style="color:#4CAF50">
                        <i class="fas fa-dolly"></i>
                    </div>
                    <div class="card-title">Entradas</div>
                    <div class="card-desc">Registrar ingreso de material</div>
                </div>

                <div class="nav-card" onclick="window.location.href='?seccion=salidas'" style="border-top: 3px solid #f44336;">
                    <div class="card-icon" style="color:#f44336">
                        <i class="fas fa-dolly-flatbed"></i>
                    </div>
                    <div class="card-title">Salidas</div>
                    <div class="card-desc">Registrar salida de material</div>
                </div>

                <?php renderEquiposFabricadosNavCard('almacen2.php'); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'equipos_fabricados'): renderEquiposFabricadosSeccion($db, 'almacen2.php', 'almacen'); endif; ?>

        <?php if ($seccion === 'todas_requisiciones'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-list-alt"></i> Todas las Requisiciones</h2>
                </div>
            </div>
            <?php if (empty($todas_requisiciones)): ?>
            <div class="empty-state"><i class="fas fa-list-alt"></i><p>No hay requisiciones registradas.</p></div>
            <?php else: ?>
            <table>
                <thead><tr><th>Folio</th><th>Proyecto</th><th>Área</th><th>Solicitante</th><th>Items</th><th>Prioridad</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($todas_requisiciones as $r):
                    $badgeEstado = [
                        'pendiente'  => ['#fff3e0', '#e65100'],
                        'aprobada'   => ['#e3f2fd', '#1565c0'],
                        'en_proceso' => ['#e8f5e9', '#2e7d32'],
                        'completada' => ['#e8f5e9', '#2e7d32'],
                        'rechazada'  => ['#ffebee', '#c62828'],
                        'cancelada'  => ['#f5f5f5', '#757575'],
                    ][$r['estado']] ?? ['#f5f5f5', '#757575'];
                    $badgePrioridad = [
                        'urgente' => ['#ffebee', '#c62828'],
                        'alta'    => ['#fff3e0', '#e65100'],
                        'media'   => ['#e3f2fd', '#1565c0'],
                        'baja'    => ['#f5f5f5', '#757575'],
                    ][$r['prioridad']] ?? ['#f5f5f5', '#757575'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['folio']) ?></td>
                    <td><?= htmlspecialchars($r['proyecto'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['area'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['solicitante'] ?? $r['solicitante_nombre'] ?? '—') ?></td>
                    <td><?= (int)$r['total_items'] ?></td>
                    <td><span style="font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $badgePrioridad[0] ?>;color:<?= $badgePrioridad[1] ?>;"><?= ucfirst($r['prioridad']) ?></span></td>
                    <td><span style="font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $badgeEstado[0] ?>;color:<?= $badgeEstado[1] ?>;"><?= ucfirst(str_replace('_', ' ', $r['estado'])) ?></span></td>
                    <td><?= date('d/m/Y', strtotime($r['fecha_creacion'])) ?></td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a href="?seccion=ver_requisicion&id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-eye"></i> Ver</a>
                        <?php if (!in_array($r['estado'], ['completada', 'cancelada', 'rechazada'], true)): ?>
                        <a href="?seccion=surtir_requisicion&id=<?= (int)$r['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-dolly"></i> Surtir</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'surtir_requisicion'): ?>
        <div class="section-content">
            <?php if (!$surtir_requisicion): ?>
            <div class="alert alert-error">No se encontró la requisición.</div>
            <?php else: ?>
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-dolly"></i> Surtir Requisición <?= htmlspecialchars($surtir_requisicion['folio']) ?></h2>
                    <div class="action-buttons">
                        <a href="?seccion=ver_requisicion&id=<?= (int)$surtir_req_id ?>" class="btn btn-outline btn-sm"><i class="fas fa-file-invoice"></i> Ver hoja</a>
                        <a href="?seccion=todas_requisiciones" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:14px 0;background:#f7f9fb;border:1px solid #e0e8f0;border-radius:8px;padding:12px 16px;font-size:.85rem;">
                <div><strong>Área:</strong> <?= htmlspecialchars($surtir_requisicion['area'] ?? '—') ?></div>
                <div><strong>Solicitante:</strong> <?= htmlspecialchars($surtir_requisicion['solicitante'] ?? $surtir_requisicion['solicitante_nombre'] ?? '—') ?></div>
                <div><strong>Prioridad:</strong> <?= ucfirst($surtir_requisicion['prioridad'] ?? '—') ?></div>
                <div><strong>Estado:</strong> <?= ucfirst(str_replace('_', ' ', $surtir_requisicion['estado'])) ?></div>
            </div>

            <?php if (in_array($surtir_requisicion['estado'], ['completada', 'cancelada', 'rechazada'], true)): ?>
            <div class="empty-state"><i class="fas fa-check-circle" style="color:#2e7d32;"></i><p>Esta requisición ya está <?= str_replace('_', ' ', $surtir_requisicion['estado']) ?> — no admite más entregas.</p></div>
            <?php else: ?>

            <form method="POST" id="formSurtirRequisicion">
                <input type="hidden" name="action" value="surtir_requisicion">
                <input type="hidden" name="requisicion_id" value="<?= (int)$surtir_req_id ?>">
                <table>
                    <thead>
                        <tr>
                            <th>Ítem</th><th>Descripción</th><th>Marca / Modelo</th><th>Pedido</th><th>Entregado</th><th>Pendiente</th><th>Stock</th><th>Entregar ahora</th><th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($surtir_items as $it):
                        $pendiente_it = max(0, $it['cantidad'] - $it['cantidad_entregada']);
                        $tiene_material = !empty($it['material_id']);
                        $tope = $tiene_material ? min($pendiente_it, (float)$it['stock_disponible']) : $pendiente_it;
                        $tope = max(0, $tope);
                    ?>
                        <tr>
                            <td><?= (int)($it['item_numero'] ?: 0) ?></td>
                            <td><?= htmlspecialchars($it['descripcion'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(trim(($it['marca'] ?? '') . ' ' . ($it['modelo'] ?? '')) ?: '—') ?></td>
                            <td style="text-align:center;"><?= $it['cantidad'] + 0 ?> <?= htmlspecialchars($it['unidad_medida'] ?? '') ?></td>
                            <td style="text-align:center;"><?= $it['cantidad_entregada'] + 0 ?></td>
                            <td style="text-align:center;font-weight:700;"><?= $pendiente_it ?></td>
                            <td style="text-align:center;">
                                <?php if ($tiene_material): ?>
                                    <?= (int)$it['stock_disponible'] ?>
                                <?php else: ?>
                                    <span title="Ítem sin material de catálogo asociado — no descuenta stock" style="color:#999;">— </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($pendiente_it > 0): ?>
                                <input type="number" class="form-input surtir-input" style="width:90px;text-align:center;" name="entregas[<?= (int)$it['id'] ?>]" min="0" max="<?= $tope ?>" step="any" value="0" data-max="<?= $tope ?>">
                                <?php else: ?>
                                <span style="color:#2e7d32;"><i class="fas fa-check"></i> Completo</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($tiene_material): ?>
                                <button type="button" class="btn btn-outline btn-sm" style="font-size:.7rem;padding:4px 8px;"
                                        onclick="abrirModalReemplazo('requisicion_detalle', <?= (int)$it['id'] ?>, <?= htmlspecialchars(json_encode($it['descripcion'] ?: ''), ENT_QUOTES) ?>, <?= (int)$it['material_id'] ?>)">
                                    <i class="fas fa-exchange-alt"></i> Reemplazar
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="display:flex;gap:10px;margin-top:16px;">
                    <button type="button" class="btn btn-outline" onclick="document.querySelectorAll('.surtir-input').forEach(i => i.value = i.dataset.max)">
                        <i class="fas fa-magic"></i> Llenar máximo posible
                    </button>
                    <button type="submit" class="btn btn-success" onclick="return confirm('¿Registrar esta entrega? Se descontará el stock correspondiente.')">
                        <i class="fas fa-dolly"></i> Registrar Entrega
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php if (!empty($surtir_ocs)): ?>
            <div style="margin-top:24px;background:#f0f8ff;border:1px solid #b3d9ff;border-radius:8px;padding:14px 18px;font-size:.85rem;color:#003d5c;">
                <i class="fas fa-truck"></i> Esta requisición tiene <?= count($surtir_ocs) ?> orden(es) de compra en camino. Cuando lleguen, confírmalas en
                <a href="?seccion=recepcion_pedidos" style="font-weight:700;">Recepción de pedidos</a> — igual que las de proyectos.
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'inventario'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-warehouse"></i> Gestión de Inventario</h2>
                    <div class="action-buttons">
                        <div class="search-wrapper">
                            <input type="text" class="search-input" id="searchInventario" placeholder="Buscar material...">
                            <button class="search-clear" id="clearSearchInventario" onclick="clearSearch('inventario')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <button class="btn-categorias" onclick="abrirModalCategoriasInventario()">
                            <i class="fas fa-tags"></i> Categorías
                        </button>
                        <button class="btn-categorias" onclick="abrirModalMarcasInventario()">
                            <i class="fas fa-industry"></i> Marca
                        </button>
                        <button class="btn btn-primary" onclick="abrirModalNuevoMaterial()">
                            <i class="fas fa-plus"></i> Nuevo Material
                        </button>
                    </div>
                </div>
            </div>

            <div class="inventario-layout">
                <!-- Panel de carpetas -->
                <div class="carpetas-panel">
                    <div class="carpetas-header">
                        <span><i class="fas fa-folder"></i> Carpetas</span>
                        <div style="display:flex;gap:4px;">
                            <button onclick="organizarPorMarca()" title="Organizar por marca automáticamente"><i class="fas fa-tags"></i></button>
                            <button onclick="crearCarpeta()" title="Nueva carpeta"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                    <div style="padding:6px 8px;">
                        <input type="text" id="searchCarpetas" placeholder="Buscar carpeta..." oninput="filtrarCarpetas(this.value)"
                               style="width:100%;padding:5px 8px;border:1.5px solid #dde3ea;border-radius:6px;font-size:12px;font-family:'Montserrat',sans-serif;outline:none;">
                    </div>
                    <div class="carpetas-list" id="carpetasList">
                        <div class="carpeta-item active" data-id="0" onclick="filtrarPorCarpeta(0, this)">
                            <i class="fas fa-inbox"></i> Todas
                        </div>
                        <div class="carpeta-item" data-id="-1" onclick="filtrarPorCarpeta(-1, this)">
                            <i class="fas fa-folder-open"></i> Sin carpeta
                        </div>
                        <!-- Carpetas dinámicas cargadas por JS -->
                    </div>
                </div>

                <!-- Grid de materiales -->
                <div class="carpetas-inventario-content">
                    <div class="content-panel" style="flex:1;overflow-y:auto;">
                        <div class="materiales-grid" id="inventarioGrid">
                            <?php foreach ($materiales as $mat):
                                $stock_disp = (int) $mat['stock_disponible'];
                                $stock_min  = (int) $mat['stock_minimo'];
                                if ($stock_disp <= 0) {
                                    $clase_semaforo = 'stock-cero';
                                } elseif ($stock_disp <= $stock_min) {
                                    $clase_semaforo = 'stock-bajo';
                                } else {
                                    $clase_semaforo = 'stock-alto';
                                }
                            ?>
                            <div class="material-card"
                                 data-descripcion="<?php echo strtolower($mat['descripcion'] ?? ''); ?>"
                                 data-codigo="<?php echo strtolower($mat['codigo'] ?? ''); ?>"
                                 data-categoria="<?php echo $mat['categoria_id']; ?>"
                                 data-carpeta="<?php echo $mat['carpeta_id'] ?? ''; ?>"
                                 data-marca="<?php echo strtolower(htmlspecialchars($mat['marca'] ?? '')); ?>"
                                 onclick="abrirModalEditarMaterial(<?php echo htmlspecialchars(json_encode($mat), ENT_QUOTES); ?>)">
                                <div class="material-semaforo <?php echo $clase_semaforo; ?>">
                                    <div class="semaforo-luz luz-verde"></div>
                                    <div class="semaforo-luz luz-amarilla"></div>
                                    <div class="semaforo-luz luz-roja"></div>
                                </div>
                                <div class="material-imagen">
                                    <?php $img_marca = getMarcaImagen($mat['marca'] ?? '', $marcas_imagenes); ?>
                                    <?php if ($img_marca): ?>
                                        <img src="<?php echo $img_marca; ?>" alt="<?php echo htmlspecialchars($mat['marca'] ?? ''); ?>" style="object-fit:contain;padding:6px;">
                                    <?php elseif (!empty($mat['imagen'])): ?>
                                        <img src="uploads/<?php echo $mat['imagen']; ?>" alt="<?php echo htmlspecialchars($mat['descripcion'] ?? ''); ?>">
                                    <?php else: ?>
                                        <i class="fas fa-box"></i>
                                    <?php endif; ?>
                                    <?php if ((int)($mat['codigo_verificado'] ?? 1) === 0): ?>
                                    <div class="material-watermark watermark-rojo"><i class="fas fa-triangle-exclamation"></i> VERIFICAR CÓDIGO</div>
                                    <?php elseif (!empty($mat['fecha_creacion']) && strtotime($mat['fecha_creacion']) >= strtotime('-7 days')): ?>
                                    <div class="material-watermark watermark-verde"><i class="fas fa-star"></i> NUEVO</div>
                                    <?php endif; ?>
                                </div>
                                <div class="material-content">
                                    <div class="material-nombre"><?php echo htmlspecialchars($mat['descripcion'] ?? ''); ?></div>
                                    <div class="material-detalles">
                                        <span><strong>Cód:</strong> <?php echo $mat['codigo'] ?: '-'; ?></span>
                                        <span><strong>Marca:</strong> <?php echo $mat['marca'] ?: '-'; ?></span>
                                        <span><strong>Modelo:</strong> <?php echo $mat['modelo'] ?: '-'; ?></span>
                                        <?php if ($mat['categoria_nombre']): ?>
                                        <span><strong>Categoría:</strong> <?php echo $mat['categoria_nombre']; ?></span>
                                        <?php endif; ?>
                                        <span><strong>Ubic.:</strong> <?php echo $mat['ubicacion'] ?: '-'; ?></span>
                                    </div>
                                    <div class="material-stock">
                                        <span class="stock-value"><?php echo $mat['stock_disponible']; ?> <?php echo $mat['unidad_medida']; ?></span>
                                        <div class="stock-actions">
                                            <button class="stock-btn plus" onclick="event.stopPropagation(); abrirModalAjusteStock(<?php echo $mat['id']; ?>, <?php echo htmlspecialchars(json_encode($mat['descripcion'] ?? ''), ENT_QUOTES); ?>, 'entrada')">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button class="stock-btn minus" onclick="event.stopPropagation(); abrirModalAjusteStock(<?php echo $mat['id']; ?>, <?php echo htmlspecialchars(json_encode($mat['descripcion'] ?? ''), ENT_QUOTES); ?>, 'salida')">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <button class="btn-mover-carpeta"
                                            onclick="event.stopPropagation(); abrirMoverMaterial(<?php echo $mat['id']; ?>, '<?php echo htmlspecialchars(addslashes($mat['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', <?php echo $mat['carpeta_id'] ?? 'null'; ?>)">
                                        <i class="fas fa-folder-arrow-up"></i> Mover a carpeta
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'entradas'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-dolly"></i> Entradas de Almacén</h2>
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="abrirModalEntrada()">
                            <i class="fas fa-plus"></i> Registrar Entrada
                        </button>
                    </div>
                </div>
            </div>
            <div class="content-panel">
                <table class="info-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Material</th>
                            <th>Cantidad</th>
                            <th>Proveedor</th>
                            <th>OC</th>
                            <th>Número(s) de serie</th>
                            <th>Usuario</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historial_entradas)): ?>
                        <tr><td colspan="8" style="text-align:center;color:#999;padding:20px;">Aún no hay entradas registradas</td></tr>
                        <?php else: foreach ($historial_entradas as $h): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($h['fecha_movimiento'])); ?></td>
                            <td><?php echo htmlspecialchars($h['material_codigo'] ? $h['material_codigo'].' — '.$h['material_descripcion'] : ($h['material_descripcion'] ?? '-')); ?></td>
                            <td><?php echo $h['cantidad']; ?> <?php echo $h['unidad_medida']; ?></td>
                            <td><?php echo htmlspecialchars($h['proveedor_nombre'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($h['oc_folio'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($h['series'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($h['usuario_nombre'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($h['observaciones'] ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'salidas'): ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-dolly-flatbed"></i> Salidas de Almacén</h2>
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="abrirModalSalida()">
                            <i class="fas fa-plus"></i> Registrar Salida
                        </button>
                    </div>
                </div>
            </div>
            <div class="content-panel">
                <table class="info-table">
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Fecha</th>
                            <th>Material</th>
                            <th>Cantidad</th>
                            <th>Destino</th>
                            <th>Usuario</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historial_salidas)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#999;padding:20px;">Aún no hay salidas registradas</td></tr>
                        <?php else: foreach ($historial_salidas as $h):
                            if ($h['ot_folio']) {
                                $destino_txt = 'OT ' . $h['ot_folio'] . ($h['ot_titulo'] ? ' — ' . $h['ot_titulo'] : '');
                            } elseif ($h['proyecto_nombre']) {
                                $destino_txt = 'Proyecto: ' . $h['proyecto_nombre'];
                            } elseif ($h['req_folio_directa']) {
                                $destino_txt = 'Requisición: ' . $h['req_folio_directa'];
                            } elseif ($h['ai_proyecto_nombre']) {
                                $destino_txt = 'Proyecto: ' . $h['ai_proyecto_nombre'];
                            } elseif ($h['ai_req_folio']) {
                                $destino_txt = 'Requisición: ' . $h['ai_req_folio'];
                            } elseif (stripos($h['observaciones'] ?? '', 'Destino:') !== false) {
                                $destino_txt = trim(substr($h['observaciones'], stripos($h['observaciones'], 'Destino:') + 8));
                            } else {
                                $destino_txt = 'Sin especificar';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['folio'] ?: '-'); ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($h['fecha_movimiento'])); ?></td>
                            <td><?php echo htmlspecialchars($h['material_codigo'] ? $h['material_codigo'].' — '.$h['material_descripcion'] : ($h['material_descripcion'] ?? '-')); ?></td>
                            <td><?php echo abs($h['cantidad']); ?> <?php echo $h['unidad_medida']; ?></td>
                            <td><?php echo htmlspecialchars($destino_txt); ?></td>
                            <td><?php echo htmlspecialchars($h['usuario_nombre'] ?: '-'); ?></td>
                            <td style="display:flex;gap:6px;flex-wrap:wrap;">
                                <button class="btn-mover-carpeta" onclick='abrirTrazabilidad(<?php echo json_encode([
                                    "folio" => $h['folio'],
                                    "fecha" => date('d/m/Y H:i', strtotime($h['fecha_movimiento'])),
                                    "material" => $h['material_codigo'] ? $h['material_codigo'].' — '.$h['material_descripcion'] : ($h['material_descripcion'] ?? '-'),
                                    "cantidad" => abs($h['cantidad']) . ' ' . $h['unidad_medida'],
                                    "destino" => $destino_txt,
                                    "series" => $h['series'] ?: '-',
                                    "usuario" => $h['usuario_nombre'] ?: '-',
                                    "observaciones" => $h['observaciones'] ?: '-',
                                    "reingresado" => $h['ya_reingresado'] > 0,
                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                    <i class="fas fa-route"></i> Trazabilidad
                                </button>
                                <?php if ($h['ya_reingresado'] > 0): ?>
                                    <span style="color:#4CAF50;font-size:12px;align-self:center;"><i class="fas fa-check-circle"></i> Reingresado</span>
                                <?php else: ?>
                                    <button class="btn-mover-carpeta" onclick="confirmarReingreso(<?php echo $h['id']; ?>, <?php echo htmlspecialchars(json_encode($h['material_descripcion'] ?? ''), ENT_QUOTES); ?>)">
                                        <i class="fas fa-undo"></i> Reingresar
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'proyectos'): ?>
        <div class="section-content">
            <?php if (!empty($_SESSION['almacen_msg_exito'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['almacen_msg_exito'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['almacen_msg_exito']); endif; ?>
            <?php renderAvisoDuplicado(); ?>
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-folder"></i> Proyectos de Ingeniería</h2>
                    <div class="action-buttons">
                        <div class="search-wrapper">
                            <input type="text" class="search-input" id="searchProyectos" placeholder="Buscar proyecto...">
                            <button class="search-clear" id="clearSearchProyectos" onclick="clearSearch('proyectos')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content-panel">
                <?php if (empty($proyectos_ingenieria)): ?>
                <div style="text-align: center; padding: 60px; color: #999;">
                    <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <p>No hay proyectos de ingeniería disponibles</p>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Creado por</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="proyectosTableBody">
                        <?php foreach ($proyectos_ingenieria as $proy):
                            $pid = $proy['id'];
                            $en_almacen = ($proy['almacen_activo'] == 1);
                            $tiene_items = $proyectos_tiene_items[$pid] ?? false;
                            $cambios = $proyectos_bom_cambios[$pid] ?? 0;
                            $proy_finalizado = ($proy['estado'] === 'realizado' || $proy['realizada'] == 1);
                        ?>
                        <tr data-nombre="<?php echo strtolower(htmlspecialchars($proy['nombre'])); ?>" data-descripcion="<?php echo strtolower(htmlspecialchars($proy['descripcion'] ?? '')); ?>">
                            <td><strong><?php echo htmlspecialchars($proy['nombre']); ?></strong></td>
                            <td><?php echo $proy['descripcion'] ? htmlspecialchars($proy['descripcion']) : 'Sin descripción'; ?></td>
                            <td><?php echo $proy['creador_nombre'] ?? 'Desconocido'; ?></td>
                            <td><?php echo date('d/m/Y', strtotime($proy['fecha_creacion'])); ?></td>
                            <td>
                                <?php if ($proy_finalizado): ?>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <span style="background:#757575;color:white;padding:4px 10px;border-radius:12px;font-size:12px;">
                                            <i class="fas fa-lock"></i> Proyecto finalizado
                                        </span>
                                        <a href="api/descargar_bom_snapshot.php?proyecto_id=<?php echo $pid; ?>" target="_blank" class="btn btn-outline btn-sm">
                                            <i class="fas fa-download"></i> Descargar BOM
                                        </a>
                                    </div>
                                <?php elseif ($en_almacen): ?>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <span style="background:#4CAF50;color:white;padding:4px 10px;border-radius:12px;font-size:12px;">
                                            <i class="fas fa-warehouse"></i> En Almacén
                                        </span>
                                        <?php if (!empty($proyectos_reemplazo_pendiente[$pid])): ?>
                                        <span style="background:#fff3cd;color:#856404;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;" title="Tiene un reemplazo de material esperando aprobación de Ingeniería.">
                                            <i class="fas fa-exclamation-triangle"></i> Reemplazo pendiente
                                        </span>
                                        <?php endif; ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="abrir_en_almacen">
                                            <input type="hidden" name="proyecto_id" value="<?php echo $pid; ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="fas fa-folder-open"></i> Gestionar
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <?php if (!$tiene_items): // Solo mostrar Pasar a Almacén si nunca se ha pasado ?>
                                        <button class="btn btn-success btn-sm" onclick="confirmarPasarAlmacen(<?php echo $pid; ?>, '<?php echo htmlspecialchars(addslashes($proy['nombre']), ENT_QUOTES, 'UTF-8'); ?>')">
                                            <i class="fas fa-arrow-right"></i> Pasar a Almacén
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($tiene_items): ?>
                                            <?php if ($cambios > 0): ?>
                                            <button class="btn btn-warning btn-sm" onclick="abrirModalSincronizar(<?php echo $pid; ?>)" title="El BOM tiene <?php echo $cambios; ?> cambio(s) pendiente(s)">
                                                <i class="fas fa-sync-alt"></i> Sincronizar Materiales
                                                <span style="background:white;color:#FF9800;border-radius:50%;padding:1px 6px;font-size:11px;margin-left:4px;"><?php echo $cambios; ?></span>
                                            </button>
                                            <?php else: ?>
                                            <button class="btn btn-sm" style="background:#e0e0e0;color:#999;cursor:not-allowed;" disabled title="El BOM ya está sincronizado">
                                                <i class="fas fa-check-circle"></i> Sincronizado
                                            </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($seccion === 'almacen'): ?>
        <div class="section-content">
            <?php if (!$proyecto_activo_id): ?>
            <div class="alert alert-error" style="text-align: center;">
                <i class="fas fa-exclamation-triangle"></i>
                No hay un proyecto activo. Selecciona un proyecto desde la sección "Proyectos de Ingeniería".
            </div>
            <?php else: ?>
            
            <!-- Barra azul del proyecto activo (sticky) -->
            <div class="proyecto-info">
                <div>
                    <i class="fas fa-folder-open"></i> 
                    <strong>Proyecto activo:</strong> <?php echo $proyecto_activo_nombre; ?>
                </div>
                <div>
                    <button class="btn-outline-light" onclick="confirmarCancelarProyecto()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </div>
            
            <!-- Panel de items del proyecto en almacén -->
<div>

                <div id="panelAlmacenTabla">
                    <div class="panel-header">
                        <h3><i class="fas fa-list-ol"></i> Items del proyecto</h3>
                        <div class="action-buttons">
                            <?php if (!empty($items_proyecto)): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="imprimirBomProyecto(<?php echo $proyecto_activo_id; ?>)">
                                <i class="fas fa-print"></i> Imprimir BOM
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($items_proyecto)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">No hay items agregados a este proyecto</p>
                        <?php else:
                            $completos = 0;
                            $insuficientes = 0;
                        ?>

                        <!-- ── Filtros por Categoría ─────────────────────────────── -->
                        <?php if (count($cats_proyecto) > 1): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;align-items:center;">
                            <span style="font-size:.75rem;font-weight:700;color:#555;">Filtrar:</span>
                            <button class="cat-filter-btn active" data-cat="all"
                                    onclick="filtrarCatAlmacen('all',this)"
                                    style="padding:4px 12px;border-radius:20px;border:1.5px solid #005686;background:#005686;color:#fff;font-size:.75rem;font-weight:600;cursor:pointer;">
                                Todos
                            </button>
                            <?php foreach ($cats_proyecto as $cid => $cnombre): ?>
                            <button class="cat-filter-btn" data-cat="<?php echo $cid; ?>"
                                    onclick="filtrarCatAlmacen('<?php echo $cid; ?>',this)"
                                    style="padding:4px 12px;border-radius:20px;border:1.5px solid #005686;background:transparent;color:#005686;font-size:.75rem;font-weight:600;cursor:pointer;">
                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($cnombre); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex;gap:14px;align-items:center;margin-bottom:10px;font-size:.75rem;color:#555;">
                            <span style="font-weight:700;">Origen del material:</span>
                            <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:3px;background:#005686;display:inline-block;"></span> Ingeniería</span>
                            <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:3px;background:#BE893B;display:inline-block;"></span> Producción</span>
                            <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;border-radius:3px;background:#ccc;display:inline-block;"></span> Sin clasificar</span>
                        </div>
                        <table id="tablaItemsAlmacen" style="margin-bottom: 20px;">
                            <thead>
                                <tr>
                                    <th>Categoría</th>
                                    <th>Item</th>
                                    <th>Descripción</th>
                                    <th>Modelo/Marca</th>
                                    <th>Requerido</th>
                                    <th>Origen</th>
                                    <th>Asignados</th>
                                    <th>Stock Actual</th>
                                    <th>Estado</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items_proyecto as $item):
                                    $asignado = $item['cantidad_asignada'] ?? 0;
                                    $diferencia = $item['diferencia'];
                                    $clase_diferencia = $diferencia >= 0 ? 'badge-success' : 'badge-danger';
                                    $estado_asignacion = ($asignado >= $item['cantidad_requerida']) ? 'badge-success' : 'badge-warning';
                                    $estado_lbl = $diferencia >= 0 ? 'Completo' : 'Insuficiente';

                                    if ($diferencia >= 0) { $completos++; }
                                    else                  { $insuficientes++; }

                                    // Barra de origen: qué porcentaje de lo requerido pidió cada
                                    // departamento. "Sin clasificar" cubre filas creadas antes de
                                    // este desglose (cantidad_ingenieria/cantidad_produccion en 0).
                                    $req_total = max(1, (int)$item['cantidad_requerida']);
                                    $c_ing = (int)($item['cantidad_ingenieria'] ?? 0);
                                    $c_prod = (int)($item['cantidad_produccion'] ?? 0);
                                    $c_sin = max(0, $item['cantidad_requerida'] - $c_ing - $c_prod);
                                    $pct_ing  = round($c_ing  / $req_total * 100, 1);
                                    $pct_prod = round($c_prod / $req_total * 100, 1);
                                    $pct_sin  = max(0, 100 - $pct_ing - $pct_prod);
                                ?>
                                <tr data-cat="<?php echo $item['cat_id']; ?>">
                                    <td><span style="font-size:.72rem;background:#f3e5f5;color:#7b1fa2;padding:2px 7px;border-radius:10px;font-weight:600;"><?php echo htmlspecialchars($item['cat_nombre']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($item['item']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['descripcion'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars(($item['modelo'] ?: '-') . ' / ' . ($item['marca'] ?: '-')); ?></td>
                                    <td><?php echo $item['cantidad_requerida']; ?></td>
                                    <td>
                                        <div title="Ingeniería: <?php echo $c_ing; ?> · Producción: <?php echo $c_prod; ?><?php echo $c_sin > 0 ? ' · Sin clasificar: ' . $c_sin : ''; ?>"
                                             style="display:flex;width:70px;height:9px;border-radius:5px;overflow:hidden;background:#eee;">
                                            <?php if ($pct_ing  > 0): ?><span style="width:<?php echo $pct_ing; ?>%;background:#005686;"></span><?php endif; ?>
                                            <?php if ($pct_prod > 0): ?><span style="width:<?php echo $pct_prod; ?>%;background:#BE893B;"></span><?php endif; ?>
                                            <?php if ($pct_sin  > 0): ?><span style="width:<?php echo $pct_sin; ?>%;background:#ccc;"></span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="stock-badge <?php echo $estado_asignacion; ?>">
                                            <?php echo $asignado; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="stock-badge <?php echo $clase_diferencia; ?>">
                                            <?php echo $item['stock_actual']; ?> (<?php echo $diferencia >= 0 ? '+' : ''; ?><?php echo $diferencia; ?>)
                                        </span>
                                    </td>
                                    <td><span class="stock-badge <?php echo $clase_diferencia; ?>"><?php echo $estado_lbl; ?></span></td>
                                    <td>
                                        <?php if (!empty($item['reemplazo_pendiente'])): ?>
                                        <span class="stock-badge badge-warning" style="font-size:.68rem;" title="Almacén ya propuso un reemplazo para este material -- esperando respuesta del área responsable. No pasa a Compras hasta que se resuelva.">
                                            <i class="fas fa-lock"></i> Pendiente de respuesta
                                        </span>
                                        <?php elseif (!empty($item['material_id'])): ?>
                                        <button type="button" class="btn btn-outline btn-sm" style="font-size:.7rem;padding:4px 8px;"
                                                onclick="abrirModalReemplazo('almacen_item', <?php echo (int)$item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['descripcion'] ?: $item['item']), ENT_QUOTES); ?>, <?php echo (int)$item['material_id']; ?>)">
                                            <i class="fas fa-exchange-alt"></i> Reemplazar
                                        </button>
                                        <?php if ($puedeAsignarCodigoMaterial && (int)($item['material_codigo_verificado'] ?? 1) === 0): ?>
                                        <button type="button" class="btn btn-outline btn-sm" style="font-size:.7rem;padding:4px 8px;color:#c62828;border-color:#c62828;"
                                                onclick="event.stopPropagation(); verificarCodigoDesdeComparativa(<?php echo (int)$item['material_id']; ?>)">
                                            <i class="fas fa-triangle-exclamation"></i> Verificar código
                                        </button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="comparativa-panel">
                            <h4 style="margin-bottom: 10px; color: #003d5c;">📊 Comparativa por Material</h4>
                            <table style="width: 100%; margin-bottom: 15px;">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th>Requerido</th>
                                        <th>Disponible</th>
                                        <th>Diferencia</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items_proyecto as $item): 
                                        $diferencia = $item['stock_actual'] - $item['cantidad_requerida'];
                                        $estado = ($diferencia >= 0) ? 'Completo' : 'Insuficiente';
                                        $clase_estado = ($diferencia >= 0) ? 'badge-success' : 'badge-danger';
                                    ?>
                                    <tr>
                                        <td><?php echo $item['item']; ?></td>
                                        <td><?php echo $item['cantidad_requerida']; ?></td>
                                        <td><?php echo $item['stock_actual']; ?></td>
                                        <td class="<?php echo ($diferencia < 0) ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo ($diferencia >= 0 ? '+' : '') . $diferencia; ?>
                                        </td>
                                        <td><span class="stock-badge <?php echo $clase_estado; ?>"><?php echo $estado; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <div style="display: flex; gap: 15px; justify-content: center; margin-top: 10px;">
                                <span class="stock-badge badge-success">Completos: <?php echo $completos; ?></span>
                                <span class="stock-badge badge-danger">Insuficientes: <?php echo $insuficientes; ?></span>
                            </div>
                            
                            <?php if ($insuficientes > 0): ?>
                            <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; text-align: center; margin-top: 15px;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>¡Faltan materiales por asignar en <?php echo $insuficientes; ?> ítem(s)!</strong>
                            </div>
                            <?php else: ?>
                            <div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; text-align: center; margin-top: 15px;">
                                <i class="fas fa-check-circle"></i>
                                <strong>Todos los materiales tienen stock suficiente.</strong>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="sticky-panel-footer">
    <button class="btn btn-warning" onclick="confirmarFinalizarProyecto(<?php echo $proyecto_activo_id; ?>)">
        <i class="fas fa-check"></i> Finalizar y generar Requisición
    </button>
</div>
                        <?php endif; ?>
                    </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($seccion === 'requisicion_almacen'): ?>
        <div class="section-content requisicion-padding">
            <?php 
            $req_proyecto_id = isset($_SESSION['proyecto_requisicion_almacen']) ? intval($_SESSION['proyecto_requisicion_almacen']) : 0;
            $req_proyecto_nombre = '';
            $req_items = [];
            $req_items_bloqueados_count = 0;
            if ($req_proyecto_id) {
                $stmt = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
                $stmt->execute([$req_proyecto_id]);
                $req_proyecto_nombre = $stmt->fetchColumn();
                // CORRECCIÓN: solo faltantes (diferencia < 0) y excluye lo que YA se le asignó por
                // completo a este proyecto (con stock que sí había en el catálogo) -- diferencia
                // compara contra el stock general, no contra lo ya entregado a este proyecto, así
                // que sin ese filtro un material ya 100% asignado se seguía colando aquí cada vez
                // que el catálogo anduviera bajo.
                // cantidad_faltante = cantidad_requerida - cantidad_asignada (lo que de verdad le
                // falta a ESTE proyecto), no ABS(diferencia) (que es stock general vs requerido y
                // no baja cuando se asigna algo parcial desde stock existente).
                $stmt2 = $db->prepare("
                    SELECT ai.*,
                           (ai.cantidad_requerida - COALESCE(ai.cantidad_asignada,0)) AS cantidad_faltante,
                           COALESCE(c.nombre, ai.categoria_nombre, 'Sin categoría') AS cat_nombre,
                           m.codigo AS mat_codigo
                    FROM almacen_items ai
                    LEFT JOIN materiales m ON ai.material_id = m.id
                    LEFT JOIN categorias c ON m.categoria_id = c.id
                    WHERE ai.proyecto_id = ? AND ai.diferencia < 0
                      AND COALESCE(ai.cantidad_asignada,0) < ai.cantidad_requerida
                      -- Con reemplazo pendiente de aprobación no se manda a Compras --
                      -- el material puede cambiar antes de comprar, se retoma solo hasta
                      -- que Ingeniería/Producción resuelva (ver material_reemplazo_solicitudes).
                      AND NOT EXISTS (
                          SELECT 1 FROM material_reemplazo_solicitudes mrs
                          WHERE mrs.origen_tipo = 'almacen_item' AND mrs.origen_id = ai.id AND mrs.estado = 'pendiente'
                      )
                    ORDER BY cat_nombre, ai.item
                ");
                $stmt2->execute([$req_proyecto_id]);
                $req_items = $stmt2->fetchAll();

                // Faltantes que existen pero se excluyeron arriba por tener reemplazo
                // pendiente -- sin esto, si TODOS los faltantes están bloqueados, $req_items
                // sale vacío y la pantalla dice "¡Stock completo!" cuando en realidad hay
                // materiales trabados esperando respuesta de Ingeniería/Producción.
                $stmt3 = $db->prepare("
                    SELECT COUNT(*) FROM almacen_items ai
                    WHERE ai.proyecto_id = ? AND ai.diferencia < 0
                      AND COALESCE(ai.cantidad_asignada,0) < ai.cantidad_requerida
                      AND EXISTS (
                          SELECT 1 FROM material_reemplazo_solicitudes mrs
                          WHERE mrs.origen_tipo = 'almacen_item' AND mrs.origen_id = ai.id AND mrs.estado = 'pendiente'
                      )
                ");
                $stmt3->execute([$req_proyecto_id]);
                $req_items_bloqueados_count = (int) $stmt3->fetchColumn();
            }
            ?>
            
            <?php if (!$req_proyecto_id): ?>
            <div class="alert alert-error" style="text-align: center; padding: 40px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #FF9800; margin-bottom: 15px; display: block;"></i>
                <h3 style="margin-bottom: 10px;">No hay un proyecto seleccionado</h3>
                <p style="margin-bottom: 20px;">Finaliza un proyecto desde "Almacén Activo" para generar la requisición.</p>
                <a href="?seccion=proyectos" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Ir a Proyectos
                </a>
            </div>
            <?php elseif (empty($req_items) && $req_items_bloqueados_count > 0): ?>
            <!-- ── Todos los faltantes están bloqueados por reemplazo pendiente -- no es "stock completo" ── -->
            <div class="proyecto-info">
                <div><i class="fas fa-folder-open"></i> <strong>Proyecto:</strong> <?php echo htmlspecialchars($req_proyecto_nombre); ?></div>
                <div>
                    <form method="POST" action="" style="display:inline;">
                        <input type="hidden" name="action" value="cancelar_requisicion_almacen">
                        <button type="submit" class="btn-outline-light"><i class="fas fa-times"></i> Cancelar</button>
                    </form>
                </div>
            </div>
            <div style="text-align:center;padding:60px 20px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin-top:20px;">
                <i class="fas fa-lock" style="font-size:4rem;color:#856404;margin-bottom:20px;display:block;"></i>
                <h2 style="color:#856404;margin-bottom:10px;">Esperando respuesta de Ingeniería/Producción</h2>
                <p style="color:#555;font-size:15px;margin-bottom:10px;"><?php echo $req_items_bloqueados_count; ?> material(es) faltante(s) tienen un reemplazo propuesto y están pendientes de aprobación.<br>No se puede finalizar el proyecto ni generar la requisición hasta que se resuelvan.</p>
                <a href="reemplazos_material.php" class="btn btn-outline btn-sm"><i class="fas fa-exchange-alt"></i> Ver en Reemplazos de Material</a>
            </div>
            <?php elseif (empty($req_items)): ?>
            <!-- ── Sin faltantes: todo el stock disponible ── -->
            <div class="proyecto-info">
                <div><i class="fas fa-folder-open"></i> <strong>Proyecto:</strong> <?php echo htmlspecialchars($req_proyecto_nombre); ?></div>
                <div>
                    <form method="POST" action="" style="display:inline;">
                        <input type="hidden" name="action" value="cancelar_requisicion_almacen">
                        <button type="submit" class="btn-outline-light"><i class="fas fa-times"></i> Cancelar</button>
                    </form>
                </div>
            </div>
            <div style="text-align:center;padding:60px 20px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin-top:20px;">
                <i class="fas fa-check-circle" style="font-size:4rem;color:#2e7d32;margin-bottom:20px;display:block;"></i>
                <h2 style="color:#2e7d32;margin-bottom:10px;">¡Stock completo!</h2>
                <p style="color:#555;font-size:15px;margin-bottom:30px;">Todos los materiales del proyecto están disponibles en almacén.<br>No se necesita hacer ninguna compra.</p>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="finalizar_proyecto_sin_compras">
                    <button type="submit" class="btn btn-success" style="font-size:16px;padding:12px 36px;">
                        <i class="fas fa-flag-checkered"></i> Finalizar Proyecto
                    </button>
                </form>
            </div>

            <?php else: ?>

            <div class="proyecto-info">
                <div>
                    <i class="fas fa-folder-open"></i>
                    <strong>Proyecto:</strong> <?php echo $req_proyecto_nombre; ?>
                </div>
                <div>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="cancelar_requisicion_almacen">
                        <button type="submit" class="btn-outline-light">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </form>
                </div>
            </div>

            <!-- Los items se cargan automáticamente -->
            
           <div id="zona-pdf" style="background: white; padding: 20px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <form method="POST" action="" id="reqAlmacenForm">
                    <input type="hidden" name="action" value="guardar_requisicion_almacen">
                    <input type="hidden" name="proyecto_id" value="<?php echo $req_proyecto_id; ?>">
                    
                    <div class="req-header">
                        <div class="req-logo-section">
                            <img src="uploads/logo/logo.png" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                        </div>
                        <div class="req-title-section">
                            <h1>HOJA DE REQUISICION</h1>
                        </div>
                    </div>
                    
                    <table class="info-table">
                        <tr>
                            <td>Proyecto área y/o cliente destino</td>
                            <td><input type="text" name="proyecto" value="<?php echo $req_proyecto_nombre; ?>" required readonly style="background: #f5f5f5;"></td>
                            <td style="width: 25%;">Fecha máxima requerida</td>
                            <td style="width: 20%;"><input type="date" name="fecha_requerida"></td>
                        </tr>
                        <tr>
                            <td>Departamento solicitante</td>
                            <td colspan="3"><input type="text" name="area" value="Almacén" required></td>
                        </tr>
                        <tr>
                            <td>Nombre del solicitante</td>
                            <td colspan="3"><input type="text" name="solicitante" value="<?php echo $_SESSION['user_name']; ?>" required></td>
                        </tr>
                    </table>
                    
                    <div class="req-actions-bar">
                        <button type="button" class="btn btn-success" onclick="abrirModalCategoriasAlmacen()">
                            <i class="fas fa-boxes"></i> Seleccionar del Catálogo
                        </button>
                    </div>
                    
                    <table class="req-rigid-matrix-table" id="reqTablaMateriales">
                        <thead>
                            <tr>
                                <th style="width:5%;"><div class="cell-text-shield">Item</div></th>
                                <th style="width:10%;"><div class="cell-text-shield">Cantidad</div></th>
                                <th style="width:14%;"><div class="cell-text-shield">Artículo</div></th>
                                <th style="width:32%;"><div class="cell-text-shield desc-mode">Descripción</div></th>
                                <th style="width:12%;"><div class="cell-text-shield">Marca</div></th>
                                <th style="width:12%;"><div class="cell-text-shield">Modelo</div></th>
                                <th style="width:10%;"><div class="cell-text-shield">Nota</div></th>
                                <th class="col-accion" style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="reqMaterialesBody">
                            <?php $req_item_index = 1; ?>
                            <?php foreach ($req_items as $ritem):
                                $cant_comprar = intval($ritem['cantidad_faltante']);
                                $r_codigo  = !empty($ritem['mat_codigo'])  ? htmlspecialchars($ritem['mat_codigo'])  : '-';
                                $r_desc    = !empty($ritem['item'])        ? htmlspecialchars($ritem['item'])        : '-';
                                $r_marca   = !empty($ritem['marca'])       ? htmlspecialchars($ritem['marca'])       : '-';
                                $r_modelo  = !empty($ritem['modelo'])      ? htmlspecialchars($ritem['modelo'])      : '-';
                                $r_nota    = !empty($ritem['cat_nombre'])  ? htmlspecialchars($ritem['cat_nombre'])  : '-';
                            ?>
                            <tr data-item="<?php echo $req_item_index; ?>" data-material-id="<?php echo $ritem['material_id']; ?>" data-ai-id="<?php echo $ritem['id']; ?>">
                                <td style="text-align:center;">
                                    <div class="cell-text-shield" style="font-weight:700;"><?php echo $req_item_index; ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="cell-text-shield">
                                        <input type="number"
                                               name="items[<?php echo $req_item_index; ?>][cantidad]"
                                               value="<?php echo $cant_comprar; ?>"
                                               min="1" required
                                               class="clean-camuflaje-input"
                                               title="Req: <?php echo $ritem['cantidad_requerida']; ?> | Stock: <?php echo $ritem['stock_actual']; ?> | Falta: <?php echo $cant_comprar; ?>">
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="cell-text-shield">
                                        <input type="text" name="items[<?php echo $req_item_index; ?>][articulo]"
                                               value="<?php echo $r_codigo; ?>" class="clean-camuflaje-input">
                                    </div>
                                </td>
                                <td style="text-align:left; padding-left:10px !important;">
                                    <div class="cell-text-shield desc-mode no-print">
                                        <input type="hidden" name="items[<?php echo $req_item_index; ?>][material_id]" value="<?php echo $ritem['material_id']; ?>">
                                        <textarea name="items[<?php echo $req_item_index; ?>][descripcion]"
                                               required rows="1" class="clean-camuflaje-input desc-textarea"
                                               style="text-align:left !important;"
                                               oninput="this.style.height='';this.style.height=this.scrollHeight+'px';"><?php echo $r_desc; ?></textarea>
                                    </div>
                                    <!-- Copia de solo texto para impresión -- un <textarea> puede
                                         quedar como campo de formulario dentro del PDF con su propio
                                         motor de layout (distinto al que mide scrollHeight en el DOM),
                                         cortando el texto de forma impredecible. Un <div> de puro
                                         texto usa el mismo ajuste de línea ya probado en la hoja de
                                         solo lectura (ver_requisicion), sin ese riesgo. -->
                                    <div class="cell-text-shield desc-mode solo-impresion"><?php echo $r_desc; ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="cell-text-shield">
                                        <input type="text" name="items[<?php echo $req_item_index; ?>][marca]"
                                               value="<?php echo ($r_marca === '-' ? '' : $r_marca); ?>"
                                               placeholder="<?php echo $r_marca; ?>"
                                               class="clean-camuflaje-input">
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="cell-text-shield">
                                        <input type="text" name="items[<?php echo $req_item_index; ?>][modelo]"
                                               value="<?php echo ($r_modelo === '-' ? '' : $r_modelo); ?>"
                                               placeholder="<?php echo $r_modelo; ?>"
                                               class="clean-camuflaje-input">
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="cell-text-shield">
                                        <input type="text" name="items[<?php echo $req_item_index; ?>][notas]"
                                               placeholder="<?php echo $r_nota; ?>"
                                               class="clean-camuflaje-input">
                                    </div>
                                </td>
                                <td class="col-accion" style="text-align:center;">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="reqEliminarFila(this)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php
                                $req_item_index++;
                            endforeach;
                            ?>
                        </tbody>
                    </table>
                    
                    <div style="margin-bottom: 20px;">
                        <label class="notas-generales-label" style="display: block; margin-bottom: 5px; font-weight: bold;">Notas Generales:</label>
                        <textarea name="notas_generales" style="width: 100%; height: 80px; padding: 10px; border: 1px solid #333; resize: vertical;"></textarea>
                    </div>

                    <!-- ══ PIE DE CONTROL DE CALIDAD ISO ══ -->
                    <table class="req-iso-footer" style="width:100%; border-collapse:collapse; border:1px solid #aaa; margin-top:20px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:11px;">
                        <tr>
                            <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px; font-weight:700; letter-spacing:.5px;">DOC-COM-FO-01B</td>
                            <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                                <span class="no-print">Rev.&nbsp;<input type="text" value="4" class="clean-camuflaje-input" style="width:20px; text-align:left !important;"></span>
                                <span class="solo-impresion-inline">Rev. 4</span>
                            </td>
                            <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                                <span class="no-print">Fecha:&nbsp;<input type="text" value="20 - dic - 21" class="clean-camuflaje-input" style="width:85px;"></span>
                                <span class="solo-impresion-inline">Fecha: 20 - dic - 21</span>
                            </td>
                            <td style="width:25%; text-align:center; border:1px solid #aaa; padding:7px 8px;">
                                <span class="no-print">Página:&nbsp;<input type="text" value="1" class="clean-camuflaje-input" style="width:20px;"></span>
                                <span class="solo-impresion-inline">Página: 1</span>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:8px 12px; border-top:1px solid #aaa; font-size:10px; color:#444; line-height:1.4;">
                                Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                            </td>
                        </tr>
                    </table>

                    <!-- ══ FOOTER INSTITUCIONAL (3 columnas) ══ -->
                    <div class="req-institucional-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; padding-top:10px; border-top:1px solid #e0e0e0; font-size:10px; color:#333; gap:10px; font-family:'Helvetica Neue',Arial,sans-serif;">
                        <div style="flex:1; text-align:left; line-height:1.7;">
                            Calle Ejemplo No. 123<br>
                            Col. Centro. C.P. 00000<br>
                            Ciudad de M&eacute;xico<br>
                            <span style="color:#0056b3; font-weight:600;">www.ejemplo.com</span>
                        </div>
                        <div style="flex:1; text-align:center; line-height:1.7;">
                            Tel. 52 55 0000 0000<br>
                            contacto@ejemplo.com<br>
                            <strong>Sistema de Gesti&oacute;n de Calidad</strong>
                        </div>
                        <div style="flex:0 0 auto; text-align:right; display:flex; align-items:center; justify-content:flex-end;">
                            <img src="../uploads/logo/isotipo.png" style="width:30px; height:auto;" alt="EMPRESA EJEMPLO"
                                 onerror="this.src='uploads/logo/logo_horizontal.png'">
                        </div>
                    </div>
                </form>
            </div>            
            <!-- Panel fijo con botones -->
<div class="fixed-actions">
    <button type="button" class="btn" style="background: #17a2b8; color: white; border: none;" onclick="generarPDF()">
        <i class="fas fa-file-pdf"></i> Guardar PDF
    </button>
    
    <button type="button" class="btn btn-primary" onclick="window.location.href='almacen2.php?seccion=requisicion_almacen'">
        <i class="fas fa-eraser"></i> Limpiar
    </button>
    <button type="submit" class="btn btn-success" form="reqAlmacenForm">
        <i class="fas fa-save"></i> Guardar Requisición
    </button>
</div>
            
            <?php endif; ?>
        </div>
        <?php endif; ?>
        

        <!-- ══════════════════════════════════════════════════════════════════════
             SECCIÓN: Recepción de pedidos (proyectos en estado orden_generada)
        ═══════════════════════════════════════════════════════════════════════ -->
        <?php if ($seccion === 'recepcion_pedidos'):
            // Calidad ya NO es filtro para recibir (2026-09-17, pedido explícito del usuario --
            // antes exigía oc.inspeccion_ok=1, aprobado por Calidad, antes de poder recibir).
            // Ahora Almacén recibe en cuanto Gerencia aprobó la OC; Calidad revisa cada material
            // DESPUÉS, por separado (ver calidad.php?seccion=aprobacion_materiales), sin bloquear.
            //
            // Bug real encontrado y corregido (2026-08-21): antes filtraba también por
            // p.estado IN ('orden_generada','recepcion_parcial') -- pero ese estado SOLO lo
            // pone generar_ordenes (la vía BOM->cotización de Ingeniería/Compras). Una OC
            // generada desde una requisición externa CON proyecto asignado (generar_ordenes_req,
            // usada por Producción/Calidad/etc. vía el módulo "Generar Requisiciones") hereda
            // bien el proyecto_id en la OC, pero esa función solo actualiza
            // requisiciones.estado_compra, nunca proyectos.estado -- el proyecto se quedaba
            // fuera de este filtro para siempre aunque su OC ya estuviera aprobada por Gerencia.
            // Caía en un hueco: no encajaba en "Proyectos" (estado incorrecto) ni en
            // "Requisiciones sin proyecto" (sí tenía proyecto_id, la excluía de ese grupo). El
            // EXISTS de abajo ya es la condición real de "hay algo que recibir" -- el filtro de
            // estado del proyecto era una restricción redundante e incorrecta encima de eso.
            $proyectos_pedido = $db->query(
                "SELECT DISTINCT p.*, u.nombre_completo AS creador_nombre
                 FROM proyectos p
                 LEFT JOIN usuarios u ON p.creado_por = u.id
                 WHERE EXISTS (
                       SELECT 1 FROM ordenes_compra oc
                       WHERE (oc.proyecto_id = p.id
                              OR oc.requisicion_id IN (SELECT id FROM requisiciones WHERE proyecto = p.nombre))
                         AND oc.estado NOT IN ('recibida')
                   )
                 ORDER BY p.fecha_estado ASC"
            )->fetchAll();

            // Requisiciones sin proyecto con OC aprobada por gerencia, pendiente de recibir
            $requisiciones_pedido = $db->query(
                "SELECT DISTINCT r.*
                 FROM requisiciones r
                 WHERE EXISTS (
                       SELECT 1 FROM ordenes_compra oc
                       WHERE oc.requisicion_id = r.id AND oc.proyecto_id IS NULL
                         AND oc.estado NOT IN ('recibida')
                   )
                 ORDER BY r.fecha_creacion ASC"
            )->fetchAll();

            // Las OCs y sus detalles se traían con 2 queries por proyecto/requisición dentro de
            // cada foreach de abajo (N+1 doblemente anidado) -- se traen aquí de una sola vez y
            // se agrupan en PHP, igual que el patrón ya usado en otras secciones de este archivo.
            $ocs_por_proyecto = [];
            $proy_ids = array_column($proyectos_pedido, 'id');
            if ($proy_ids) {
                $proy_nombres = array_column($proyectos_pedido, 'nombre');
                $nombre_a_id = [];
                foreach ($proyectos_pedido as $pp) { $nombre_a_id[$pp['nombre']] = $pp['id']; }
                $phIds = implode(',', array_fill(0, count($proy_ids), '?'));
                $phNombres = implode(',', array_fill(0, count($proy_nombres), '?'));
                $stmtOcsProy = $db->prepare(
                    "SELECT oc.*, pv.nombre as prov_nombre, r.proyecto_id AS req_proyecto_id, r.proyecto AS req_proyecto_nombre
                     FROM ordenes_compra oc
                     LEFT JOIN proveedores pv ON oc.proveedor_id = pv.id
                     LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
                     WHERE oc.estado != 'recibida'
                       AND (oc.proyecto_id IN ($phIds) OR r.proyecto_id IN ($phIds) OR r.proyecto IN ($phNombres))
                     ORDER BY oc.fecha_creacion ASC"
                );
                $stmtOcsProy->execute(array_merge($proy_ids, $proy_ids, $proy_nombres));
                foreach ($stmtOcsProy->fetchAll() as $ocRow) {
                    $matchIds = [];
                    if ($ocRow['proyecto_id'] && in_array($ocRow['proyecto_id'], $proy_ids)) $matchIds[] = (int)$ocRow['proyecto_id'];
                    if ($ocRow['req_proyecto_id'] && in_array($ocRow['req_proyecto_id'], $proy_ids)) $matchIds[] = (int)$ocRow['req_proyecto_id'];
                    if ($ocRow['req_proyecto_nombre'] && isset($nombre_a_id[$ocRow['req_proyecto_nombre']])) $matchIds[] = (int)$nombre_a_id[$ocRow['req_proyecto_nombre']];
                    foreach (array_unique($matchIds) as $mid) { $ocs_por_proyecto[$mid][] = $ocRow; }
                }
            }

            $ocs_por_requisicion = [];
            $req_ids = array_column($requisiciones_pedido, 'id');
            if ($req_ids) {
                $phReqIds = implode(',', array_fill(0, count($req_ids), '?'));
                $stmtOcsReq = $db->prepare(
                    "SELECT oc.*, pv.nombre as prov_nombre
                     FROM ordenes_compra oc
                     LEFT JOIN proveedores pv ON oc.proveedor_id = pv.id
                     WHERE oc.requisicion_id IN ($phReqIds) AND oc.proyecto_id IS NULL AND oc.estado != 'recibida'
                     ORDER BY oc.fecha_creacion ASC"
                );
                $stmtOcsReq->execute($req_ids);
                foreach ($stmtOcsReq->fetchAll() as $ocRow) {
                    $ocs_por_requisicion[(int)$ocRow['requisicion_id']][] = $ocRow;
                }
            }

            // Detalle de TODAS las OCs recolectadas arriba (de ambas listas), en una sola query
            $todos_oc_ids = [];
            foreach ($ocs_por_proyecto as $lista) { foreach ($lista as $oc) { $todos_oc_ids[] = $oc['id']; } }
            foreach ($ocs_por_requisicion as $lista) { foreach ($lista as $oc) { $todos_oc_ids[] = $oc['id']; } }
            $todos_oc_ids = array_unique($todos_oc_ids);
            $detalles_por_oc = [];
            if ($todos_oc_ids) {
                $phOcIds = implode(',', array_fill(0, count($todos_oc_ids), '?'));
                $stmtDet = $db->prepare("SELECT * FROM orden_compra_detalle WHERE orden_id IN ($phOcIds) ORDER BY orden_id, item_numero");
                $stmtDet->execute($todos_oc_ids);
                foreach ($stmtDet->fetchAll() as $detRow) {
                    $detalles_por_oc[(int)$detRow['orden_id']][] = $detRow;
                }
            }
        ?>
        <div class="section-content">
            <div class="sticky-container">
                <div class="header-row">
                    <h2><i class="fas fa-truck-loading" style="color:#e64a19"></i> Recepción de pedidos</h2>
                </div>
            </div>

            <?php if (empty($proyectos_pedido) && empty($requisiciones_pedido)): ?>
            <div style="text-align:center;padding:60px;color:#999;">
                <i class="fas fa-truck-loading" style="font-size:48px;margin-bottom:16px;color:#ddd;display:block;"></i>
                <p>No hay pedidos pendientes de recepción.</p>
                <p style="font-size:13px;">Cuando Compras genere una orden de compra, aparecerá aquí para que confirmes la llegada.</p>
            </div>
            <?php endif; ?>
            <?php if (!empty($proyectos_pedido)): ?>
            <div style="padding:20px;">
            <?php foreach ($proyectos_pedido as $proy):
                $ocs_r = $ocs_por_proyecto[$proy['id']] ?? [];
                $ocs_para_js = [];
            ?>
            <div style="border:1px solid #eee;border-radius:8px;margin-bottom:20px;overflow:hidden;">
                <div style="background:#e64a19;color:white;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong><i class="fas fa-folder"></i> <?php echo htmlspecialchars($proy['nombre']); ?></strong>
                        <?php if ($proy['estado'] === 'recepcion_parcial'): ?>
                        <span style="background:rgba(255,255,255,0.25);padding:2px 10px;border-radius:20px;font-size:11px;margin-left:8px;">Recepción parcial</span>
                        <?php endif; ?>
                    </div>
                    <small>Pedido el <?php echo date('d/m/Y', strtotime($proy['fecha_estado'])); ?></small>
                </div>

                <?php if (empty($ocs_r)): ?>
                <div style="padding:20px;color:#999;font-size:13px;">Sin órdenes de compra registradas.</div>
                <?php else: ?>
                <?php foreach ($ocs_r as $oc):
                    $dets_r = $detalles_por_oc[$oc['id']] ?? [];
                    $items_js = [];
                    foreach ($dets_r as $det_js) {
                        $items_js[] = [
                            'descripcion'     => $det_js['descripcion'] ?? '',
                            'cantidad'        => floatval($det_js['cantidad'] ?? 0),
                            'unidad'          => $det_js['unidad_medida'] ?? 'pza',
                            'precio_unitario' => floatval($det_js['precio_unitario'] ?? 0),
                            'material_id'     => $det_js['material_id'] ? (int)$det_js['material_id'] : null,
                        ];
                    }
                    $ocs_para_js[] = [
                        'oc_id'        => $oc['id'],
                        'folio'        => $oc['folio'],
                        'proveedor'    => $oc['prov_nombre'] ?? '',
                        'proveedor_id' => $oc['proveedor_id'] ? (int)$oc['proveedor_id'] : null,
                        'items'        => $items_js,
                    ];
                ?>
                <div style="padding:16px 18px;border-bottom:1px solid #f0f0f0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <div>
                            <strong style="color:#003d5c"><?php echo htmlspecialchars($oc['folio']); ?></strong>
                            <span style="margin-left:10px;color:#555;font-size:13px;">Proveedor: <?php echo htmlspecialchars($oc['prov_nombre'] ?? '—'); ?></span>
                            <?php if ($oc['fecha_entrega_estimada']): ?>
                            <span style="margin-left:10px;font-size:12px;color:#888;">Entrega est.: <?php echo date('d/m/Y', strtotime($oc['fecha_entrega_estimada'])); ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong style="color:#1565c0">$<?php echo number_format($oc['total'] ?? 0, 2); ?> <?php echo $oc['moneda'] ?? 'MXN'; ?></strong>
                            <?php if ($oc['estado'] === 'recibida'): ?>
                            <span style="background:#e8f5e9;color:#2e7d32;padding:2px 10px;border-radius:20px;font-size:11px;margin-left:8px;"><i class="fas fa-check"></i> Recibido</span>
                            <?php else: ?>
                            <span style="background:#fff8e1;color:#f57f17;padding:2px 10px;border-radius:20px;font-size:11px;margin-left:8px;"><i class="fas fa-truck"></i> En tránsito</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($dets_r)): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead><tr style="background:#f5f5f5;"><th style="padding:6px 8px;text-align:left;">#</th><th style="padding:6px 8px;text-align:left;">Descripción</th><th style="padding:6px 8px;text-align:center;">Cant. Ordenada</th><th style="padding:6px 8px;text-align:right;">Precio Unit.</th><th style="padding:6px 8px;text-align:right;">Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($dets_r as $det_r): ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:6px 8px"><?php echo $det_r['item_numero']; ?></td>
                            <td style="padding:6px 8px"><?php echo htmlspecialchars($det_r['descripcion'] ?? ''); ?></td>
                            <td style="padding:6px 8px;text-align:center"><?php echo $det_r['cantidad']; ?> <?php echo $det_r['unidad_medida']; ?></td>
                            <td style="padding:6px 8px;text-align:right">$<?php echo number_format($det_r['precio_unitario'] ?? 0, 2); ?></td>
                            <td style="padding:6px 8px;text-align:right">$<?php echo number_format($det_r['subtotal'] ?? 0, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <script>
                if (!window._recepcionData) window._recepcionData = {};
                window._recepcionData[<?php echo $proy['id']; ?>] = <?php echo json_encode($ocs_para_js); ?>;
                </script>
                <?php endif; ?>

                <div style="padding:14px 18px;background:#fafafa;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-success" onclick="abrirModalRecepcionCompleta(<?php echo $proy['id']; ?>, '<?php echo addslashes(htmlspecialchars($proy['nombre'])); ?>')" style="font-size:13px;">
                        <i class="fas fa-check-double"></i> Confirmar recepción completa
                    </button>
                    <button class="btn btn-outline" onclick="abrirModalRecepcionParcial(<?php echo $proy['id']; ?>, '<?php echo addslashes(htmlspecialchars($proy['nombre'])); ?>')" style="font-size:13px;color:#f57c00;border-color:#f57c00;">
                        <i class="fas fa-exclamation-triangle"></i> Recepción parcial / con faltantes
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($requisiciones_pedido)): ?>
            <h3 style="margin:24px 0 12px;color:#e64a19;"><i class="fas fa-file-invoice-dollar"></i> Requisiciones (sin proyecto)</h3>
            <div style="padding-bottom:20px;">
            <?php foreach ($requisiciones_pedido as $rq):
                $ocs_rq = $ocs_por_requisicion[$rq['id']] ?? [];
                $ocs_para_js_rq = [];
            ?>
            <div style="border:1px solid #eee;border-radius:8px;margin-bottom:20px;overflow:hidden;">
                <div style="background:#e64a19;color:white;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong><i class="fas fa-file-alt"></i> <?= htmlspecialchars($rq['folio']) ?></strong>
                        <?php if ($rq['estado_compra'] === 'recepcion_parcial'): ?>
                        <span style="background:rgba(255,255,255,0.25);padding:2px 10px;border-radius:20px;font-size:11px;margin-left:8px;">Recepción parcial</span>
                        <?php endif; ?>
                    </div>
                    <small>Creada el <?= date('d/m/Y', strtotime($rq['fecha_creacion'])) ?></small>
                </div>

                <?php if (empty($ocs_rq)): ?>
                <div style="padding:20px;color:#999;font-size:13px;">Sin órdenes de compra registradas.</div>
                <?php else: ?>
                <?php foreach ($ocs_rq as $oc):
                    $dets_rq = $detalles_por_oc[$oc['id']] ?? [];
                    $items_js_rq = [];
                    foreach ($dets_rq as $det_js) {
                        $items_js_rq[] = [
                            'descripcion'     => $det_js['descripcion'] ?? '',
                            'cantidad'        => floatval($det_js['cantidad'] ?? 0),
                            'unidad'          => $det_js['unidad_medida'] ?? 'pza',
                            'material_id'     => $det_js['material_id'] ? (int)$det_js['material_id'] : null,
                            'precio_unitario' => floatval($det_js['precio_unitario'] ?? 0),
                        ];
                    }
                    $ocs_para_js_rq[] = [
                        'oc_id'    => $oc['id'],
                        'folio'    => $oc['folio'],
                        'proveedor'=> $oc['prov_nombre'] ?? '',
                        'items'    => $items_js_rq,
                    ];
                ?>
                <div style="padding:16px 18px;border-bottom:1px solid #f0f0f0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <div>
                            <strong style="color:#003d5c"><?= htmlspecialchars($oc['folio']) ?></strong>
                            <span style="margin-left:10px;color:#555;font-size:13px;">Proveedor: <?= htmlspecialchars($oc['prov_nombre'] ?? '—') ?></span>
                            <?php if ($oc['fecha_entrega_estimada']): ?>
                            <span style="margin-left:10px;font-size:12px;color:#888;">Entrega est.: <?= date('d/m/Y', strtotime($oc['fecha_entrega_estimada'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong style="color:#1565c0">$<?= number_format($oc['total'] ?? 0, 2) ?> <?= $oc['moneda'] ?? 'MXN' ?></strong>
                            <span style="background:#fff8e1;color:#f57f17;padding:2px 10px;border-radius:20px;font-size:11px;margin-left:8px;"><i class="fas fa-truck"></i> En tránsito</span>
                        </div>
                    </div>
                    <?php if (!empty($dets_rq)): ?>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead><tr style="background:#f5f5f5;"><th style="padding:6px 8px;text-align:left;">#</th><th style="padding:6px 8px;text-align:left;">Descripción</th><th style="padding:6px 8px;text-align:center;">Cant. Ordenada</th><th style="padding:6px 8px;text-align:right;">Precio Unit.</th><th style="padding:6px 8px;text-align:right;">Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($dets_rq as $det_r): ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:6px 8px"><?= $det_r['item_numero'] ?></td>
                            <td style="padding:6px 8px"><?= htmlspecialchars($det_r['descripcion'] ?? '') ?></td>
                            <td style="padding:6px 8px;text-align:center"><?= $det_r['cantidad'] ?> <?= htmlspecialchars($det_r['unidad_medida'] ?? '') ?></td>
                            <td style="padding:6px 8px;text-align:right">$<?= number_format($det_r['precio_unitario'] ?? 0, 2) ?></td>
                            <td style="padding:6px 8px;text-align:right">$<?= number_format($det_r['subtotal'] ?? 0, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <script>
                if (!window._recepcionDataReq) window._recepcionDataReq = {};
                window._recepcionDataReq[<?= (int)$rq['id'] ?>] = <?= json_encode($ocs_para_js_rq) ?>;
                </script>
                <?php endif; ?>

                <div style="padding:14px 18px;background:#fafafa;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-success" onclick="abrirModalRecepcionCompletaReq(<?= (int)$rq['id'] ?>, '<?= addslashes(htmlspecialchars($rq['folio'])) ?>')" style="font-size:13px;">
                        <i class="fas fa-check-double"></i> Confirmar recepción completa
                    </button>
                    <button class="btn btn-outline" onclick="abrirModalRecepcionParcialReq(<?= (int)$rq['id'] ?>, '<?= addslashes(htmlspecialchars($rq['folio'])) ?>')" style="font-size:13px;color:#f57c00;border-color:#f57c00;">
                        <i class="fas fa-exclamation-triangle"></i> Recepción parcial / con faltantes
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <div class="dashboard-footer">
        <div class="footer-left">
            <div><i class="fas fa-envelope"></i> contacto@ejemplo.com</div>
            <div><i class="fas fa-globe"></i> www.ejemplo.com</div>
        </div>
        <div class="footer-right">
            <img src="uploads/logo/logo_horizontal.png" alt="EMPRESA EJEMPLO">
        </div>
    </div>
    
    <!-- Modales -->
    <div class="modal-overlay" id="modalAjusteStock">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #2196F3;">
                <div class="modal-title"><i class="fas fa-balance-scale"></i> Ajustar Stock</div>
                <button class="modal-close" onclick="cerrarModal('modalAjusteStock')">&times;</button>
            </div>
            <form method="POST" action="" id="formAjusteStock">
                <input type="hidden" name="action" value="ajustar_stock">
                <input type="hidden" name="material_id" id="ajuste_material_id" value="">
                <input type="hidden" name="tipo" id="ajuste_tipo" value="">
                
                <div class="modal-body">
                    <p><strong>Material:</strong> <span id="ajuste_material_nombre"></span></p>
                    <p><strong>Tipo:</strong> <span id="ajuste_tipo_texto"></span></p>
                    <div class="form-group">
                        <label class="form-label">Cantidad:</label>
                        <input type="number" name="cantidad" class="form-input" min="1" value="1" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalAjusteStock')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Aplicar</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalAsignar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #4CAF50;">
                <div class="modal-title"><i class="fas fa-check-circle"></i> Asignar Cantidad</div>
                <button class="modal-close" onclick="cerrarModal('modalAsignar')">&times;</button>
            </div>
            <form method="POST" action="" id="formAsignar">
                <input type="hidden" name="action" value="asignar_cantidad">
                <input type="hidden" name="item_id" id="asignar_item_id" value="">
                
                <div class="modal-body">
                    <p><strong>Item:</strong> <span id="asignar_item_nombre"></span></p>
                    <p><strong>Stock disponible:</strong> <span id="asignar_stock_max"></span></p>
                    <div class="form-group">
                        <label class="form-label">Cantidad a asignar:</label>
                        <input type="number" name="cantidad" id="asignar_cantidad" class="form-input" min="1" value="1" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalAsignar')">Cancelar</button>
                    <button type="submit" class="btn btn-success">Asignar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modalReemplazoMaterial">
        <div class="modal" style="max-width: 480px;">
            <div class="modal-header" style="background: #005686;">
                <div class="modal-title"><i class="fas fa-exchange-alt"></i> Proponer material alterno</div>
                <button class="modal-close" onclick="cerrarModal('modalReemplazoMaterial')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:.85rem;margin-bottom:12px;">Material original: <strong id="reemplazo_origen_original"></strong></p>
                <div class="form-group">
                    <label class="form-label">Buscar material(es) alterno(s):</label>
                    <input type="text" class="form-input" id="reemplazoSearchInput" placeholder="Descripción o código..." oninput="reemplazoBuscarInput()">
                    <p style="font-size:.72rem;color:#999;margin-top:4px;">Puedes marcar más de uno si no estás seguro cuál encaja mejor -- Ingeniería elegirá cuál aplicar.</p>
                </div>
                <div id="reemplazoResults" style="max-height:220px;overflow-y:auto;border:1px solid #eee;border-radius:6px;margin-top:6px;"></div>
                <div id="reemplazoSeleccionado" style="display:none;margin-top:10px;padding:8px 10px;background:#e8f5e9;border-radius:6px;font-size:.85rem;">
                    <i class="fas fa-check-circle" style="color:#2e7d32;"></i> Seleccionado: <strong id="reemplazoSeleccionadoTexto"></strong>
                </div>
                <div class="form-group" style="margin-top:12px;">
                    <label class="form-label">Nota (opcional):</label>
                    <input type="text" class="form-input" id="reemplazoNota" placeholder="Por qué se propone este material...">
                </div>
                <p style="font-size:.75rem;color:#999;margin-top:8px;">El área responsable de la especificación técnica debe aprobar antes de que el reemplazo se aplique.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="cerrarModal('modalReemplazoMaterial')">Cancelar</button>
                <button type="button" class="btn btn-success" id="btnConfirmarReemplazo" disabled onclick="confirmarProponerReemplazo()">Proponer reemplazo</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalConfirmarPasar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #4CAF50;">
                <div class="modal-title"><i class="fas fa-arrow-right"></i> Confirmar</div>
                <button class="modal-close" onclick="cerrarModal('modalConfirmarPasar')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-question-circle" style="font-size: 48px; color: #4CAF50; margin: 20px 0;"></i>
                <p id="confirmar_pasar_mensaje" style="margin-bottom: 20px;">¿Pasar este proyecto a Almacén?</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="?seccion=proyectos" id="formPasarAlmacen">
                    <input type="hidden" name="action" value="pasar_a_almacen">
                    <input type="hidden" name="proyecto_id" id="pasar_proyecto_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalConfirmarPasar')">Cancelar</button>
                    <button type="submit" class="btn btn-success">Sí, Pasar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalConfirmarCancelar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #f44336;">
                <div class="modal-title"><i class="fas fa-times-circle"></i> Confirmar</div>
                <button class="modal-close" onclick="cerrarModal('modalConfirmarCancelar')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f44336; margin: 20px 0;"></i>
                <p style="margin-bottom: 20px;">¿Cancelar el proyecto activo? Se eliminarán todos los items y el proyecto volverá a estar disponible en Ingeniería.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="cancelar_proyecto">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalConfirmarCancelar')">No</button>
                    <button type="submit" class="btn btn-danger">Sí, Cancelar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalConfirmarFinalizar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #FF9800;">
                <div class="modal-title"><i class="fas fa-clock"></i> Confirmar</div>
                <button class="modal-close" onclick="cerrarModal('modalConfirmarFinalizar')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-question-circle" style="font-size: 48px; color: #FF9800; margin: 20px 0;"></i>
                <p style="margin-bottom: 20px;">¿Finalizar proyecto? Se generará la hoja de requisición.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="formFinalizar">
                    <input type="hidden" name="action" value="finalizar_proyecto">
                    <input type="hidden" name="proyecto_id" id="finalizar_proyecto_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalConfirmarFinalizar')">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Sí, Finalizar</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Categorías Inventario -->
    <div class="modal-overlay" id="modalCategoriasInventario">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Seleccionar Categoría</h3>
                <button class="modal-close" onclick="cerrarModal('modalCategoriasInventario')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($categorias as $cat): ?>
                    <div class="categoria-card" data-id="<?php echo $cat['id']; ?>" onclick="seleccionarCategoriaInventario(this)">
                        <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                        <div class="cat-nombre"><?php echo $cat['nombre']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="limpiarFiltroInventario()">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button class="btn" onclick="cerrarModal('modalCategoriasInventario')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroInventario()">Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Modal Marca Inventario Almacén -->
    <div class="modal-overlay" id="modalMarcasInventarioAlmacen">
        <div class="modal" style="max-width:600px;">
            <div class="modal-header">
                <h3><i class="fas fa-industry"></i> Filtrar por Marca</h3>
                <button class="modal-close" onclick="cerrarModal('modalMarcasInventarioAlmacen')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($marcas_inventario_almacen as $mrc):
                        $img_mrc = getMarcaImagen($mrc, $marcas_imagenes);
                    ?>
                    <div class="categoria-card" data-marca="<?php echo strtolower(htmlspecialchars($mrc)); ?>" onclick="seleccionarMarcaInventario(this)">
                        <?php if ($img_mrc): ?>
                            <img src="<?php echo $img_mrc; ?>" alt="<?php echo htmlspecialchars($mrc); ?>" style="width:60px;height:44px;object-fit:contain;">
                        <?php else: ?>
                            <i class="fas fa-industry"></i>
                        <?php endif; ?>
                        <div class="cat-nombre"><?php echo htmlspecialchars($mrc); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="limpiarFiltroMarcaInventario()">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button class="btn" onclick="cerrarModal('modalMarcasInventarioAlmacen')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroMarcaInventario()">Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo / Editar Material (Almacén) -->
    <div class="modal-overlay" id="modalMaterialAlmacen">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-box"></i> <span id="materialAlmacenModalTitle">Nuevo Material</span></div>
                <button class="modal-close" onclick="cerrarModal('modalMaterialAlmacen')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="formMaterialAlmacen">
                <input type="hidden" name="action" id="materialAlmacenAction" value="guardar_material">
                <input type="hidden" name="id" id="materialAlmacenId">

                <div class="modal-body">
                    <div class="grid-2">
                        <?php if ($puedeAsignarCodigoMaterial): ?>
                        <div class="form-group">
                            <label class="form-label">Código</label>
                            <div style="display:flex;gap:6px;">
                                <input type="text" id="materialAlmacenPrefijo" class="form-input" list="prefijosMaterialDatalist" placeholder="Prefijo (4)" maxlength="4" style="width:38%;text-transform:uppercase;" autocomplete="off">
                                <input type="text" id="materialAlmacenConsecutivo" class="form-input" placeholder="Consecutivo" style="width:62%;" autocomplete="off">
                            </div>
                            <datalist id="prefijosMaterialDatalist"></datalist>
                            <input type="hidden" name="codigo" id="campoCodigo">
                            <small id="materialAlmacenCodigoInfo" style="display:block;margin-top:4px;font-size:.78rem;color:#999;"></small>
                        </div>
                        <?php else: ?>
                        <div class="form-group">
                            <label class="form-label">Código <small style="font-weight:400;color:#999;">(sugerido -- solo el jefe de Almacén lo confirma)</small></label>
                            <input type="text" name="codigo" id="campoCodigo" class="form-input" readonly placeholder="Se sugiere al escribir la descripción">
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Categoría *</label>
                            <select name="categoria_id" id="materialAlmacenCategoria" class="form-input" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Marca</label>
                            <input type="text" name="marca" id="materialAlmacenMarca" class="form-input" placeholder="Marca" list="marcasAlmacenDatalist" autocomplete="off">
                            <datalist id="marcasAlmacenDatalist">
                                <?php foreach ($marcas_inventario_almacen as $marca_dl): ?>
                                <option value="<?php echo htmlspecialchars($marca_dl); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Modelo</label>
                            <input type="text" name="modelo" id="materialAlmacenModelo" class="form-input" placeholder="Modelo" list="modelosAlmacenDatalist" autocomplete="off">
                            <datalist id="modelosAlmacenDatalist"></datalist>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unidad de Medida</label>
                            <select name="unidad_medida" id="materialAlmacenUnidad" class="form-input">
                                <option value="pza">Pieza</option>
                                <option value="kg">Kilogramo</option>
                                <option value="m">Metro</option>
                                <option value="l">Litro</option>
                                <option value="caja">Caja</option>
                                <option value="rollo">Rollo</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ubicación</label>
                            <input type="text" name="ubicacion" id="materialAlmacenUbicacion" class="form-input" placeholder="Ej. Rack A-3">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock mínimo</label>
                            <input type="number" name="stock_minimo" id="materialAlmacenStockMinimo" class="form-input" min="0" value="0">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Descripción *</label>
                            <textarea name="descripcion" id="materialAlmacenDescripcion" class="form-input" rows="2" placeholder="Descripción del material" required></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Imagen</label>
                            <input type="file" name="imagen" class="form-input" accept="image/*">
                        </div>
                        <div class="form-group full" id="materialAlmacenStockInfo" style="display:none;color:#666;font-size:13px;"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalMaterialAlmacen')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Registrar Entrada -->
    <div class="modal-overlay" id="modalEntrada">
        <div class="modal">
            <div class="modal-header" style="background:#4CAF50;">
                <div class="modal-title"><i class="fas fa-dolly"></i> Registrar Entrada</div>
                <button class="modal-close" onclick="cerrarModal('modalEntrada')">&times;</button>
            </div>
            <form method="POST" action="?seccion=entradas" id="formEntrada">
                <input type="hidden" name="action" value="registrar_entrada">
                <div class="modal-body">
                    <div class="grid-2">
                        <div class="form-group full">
                            <label class="form-label">Material *</label>
                            <select name="material_id" id="entradaMaterial" class="form-input" required onchange="sincronizarCodigoPorMaterial()">
                                <option value="">Seleccione...</option>
                                <?php foreach ($materiales as $mopt): ?>
                                <option value="<?php echo $mopt['id']; ?>" data-codigo="<?php echo htmlspecialchars($mopt['codigo'] ?? ''); ?>"><?php echo htmlspecialchars(($mopt['codigo'] ? $mopt['codigo'].' — ' : '') . $mopt['descripcion']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad *</label>
                            <input type="number" name="cantidad" id="entradaCantidad" class="form-input" min="1" value="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Código <span style="font-weight:400;color:#888;">(el material se identifica y etiqueta aquí, al recibirlo)</span></label>
                            <input type="text" name="codigo_material" id="entradaCodigo" class="form-input" placeholder="Ej. MAT-0001" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Orden de Compra</label>
                            <select name="orden_compra_id" id="entradaOrdenCompra" class="form-input" onchange="sincronizarProveedorPorOC()">
                                <option value="">Sin especificar</option>
                                <?php foreach ($ordenes_compra_almacen as $oc): ?>
                                <option value="<?php echo $oc['id']; ?>" data-proveedor="<?php echo (int) $oc['proveedor_id']; ?>"><?php echo htmlspecialchars($oc['folio']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Proveedor</label>
                            <select name="proveedor_id" id="entradaProveedor" class="form-input">
                                <option value="">Sin especificar</option>
                                <?php foreach ($proveedores_almacen as $prov): ?>
                                <option value="<?php echo $prov['id']; ?>"><?php echo htmlspecialchars($prov['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small id="entradaProveedorHint" style="display:none;color:#888;">Autocompletado desde la OC seleccionada</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ubicación</label>
                            <input type="text" name="ubicacion" class="form-input" placeholder="Ej. Rack A-3 (actualiza la del material)">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Número(s) de serie <span style="font-weight:400;color:#888;">(opcional — uno por línea, deben ser exactamente igual a la cantidad)</span></label>
                            <textarea name="numeros_serie" class="form-input" rows="3" placeholder="Déjalo vacío si el material no se serializa"></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Observaciones</label>
                            <textarea name="observaciones" class="form-input" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalEntrada')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Registrar Salida -->
    <div class="modal-overlay" id="modalSalida">
        <div class="modal">
            <div class="modal-header" style="background:#f44336;">
                <div class="modal-title"><i class="fas fa-dolly-flatbed"></i> Registrar Salida</div>
                <button class="modal-close" onclick="cerrarModal('modalSalida')">&times;</button>
            </div>
            <form method="POST" action="?seccion=salidas" id="formSalida">
                <input type="hidden" name="action" value="registrar_salida">
                <div class="modal-body">
                    <div class="grid-2">
                        <div class="form-group full">
                            <label class="form-label">Material *</label>
                            <select name="material_id" class="form-input" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($materiales as $mopt): ?>
                                <option value="<?php echo $mopt['id']; ?>"><?php echo htmlspecialchars(($mopt['codigo'] ? $mopt['codigo'].' — ' : '') . $mopt['descripcion']); ?> (stock: <?php echo $mopt['stock_disponible']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cantidad *</label>
                            <input type="number" name="cantidad" class="form-input" min="1" value="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Orden de Trabajo (OT)</label>
                            <select name="ot_id" class="form-input">
                                <option value="">Sin especificar</option>
                                <?php foreach ($ots_almacen as $ot): ?>
                                <option value="<?php echo $ot['id']; ?>"><?php echo htmlspecialchars($ot['folio'] . ' — ' . $ot['titulo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Proyecto</label>
                            <select name="proyecto_id" class="form-input">
                                <option value="">Sin especificar</option>
                                <?php foreach ($proyectos_para_salida as $py): ?>
                                <option value="<?php echo $py['id']; ?>"><?php echo htmlspecialchars($py['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Otro destino <span style="font-weight:400;color:#888;">(texto libre, opcional)</span></label>
                            <input type="text" name="destino_texto" class="form-input" placeholder="Ej. Mantenimiento, préstamo, etc.">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Número(s) de serie <span style="font-weight:400;color:#888;">(opcional — uno por línea, deben existir en almacén para este material)</span></label>
                            <textarea name="numeros_serie" class="form-input" rows="3" placeholder="Déjalo vacío si el material no se serializa"></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Observaciones</label>
                            <textarea name="observaciones" class="form-input" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalSalida')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Confirmar Reingreso -->
    <div class="modal-overlay" id="modalReingreso">
        <div class="modal" style="max-width:440px">
            <div class="modal-header" style="background:#FF9800;">
                <div class="modal-title"><i class="fas fa-undo"></i> Confirmar Reingreso</div>
                <button class="modal-close" onclick="cerrarModal('modalReingreso')">&times;</button>
            </div>
            <form method="POST" action="?seccion=salidas" id="formReingreso">
                <input type="hidden" name="action" value="registrar_reingreso">
                <input type="hidden" name="historial_id" id="reingresoHistorialId">
                <div class="modal-body">
                    <p>¿Confirmas el reingreso de <strong id="reingresoMaterialNombre"></strong> a almacén? Se sumará de nuevo al stock disponible y, si tenía número de serie, volverá a quedar disponible.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalReingreso')">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Confirmar Reingreso</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Trazabilidad de Salida -->
    <div class="modal-overlay" id="modalTrazabilidad">
        <div class="modal" style="max-width:480px">
            <div class="modal-header" style="background:#003d5c;">
                <div class="modal-title"><i class="fas fa-route"></i> Trazabilidad de Salida</div>
                <button class="modal-close" onclick="cerrarModal('modalTrazabilidad')">&times;</button>
            </div>
            <div class="modal-body">
                <table class="info-table">
                    <tr><td><strong>Folio</strong></td><td id="trzFolio"></td></tr>
                    <tr><td><strong>Fecha</strong></td><td id="trzFecha"></td></tr>
                    <tr><td><strong>Material</strong></td><td id="trzMaterial"></td></tr>
                    <tr><td><strong>Cantidad</strong></td><td id="trzCantidad"></td></tr>
                    <tr><td><strong>Destino / uso</strong></td><td id="trzDestino"></td></tr>
                    <tr><td><strong>Número(s) de serie</strong></td><td id="trzSeries"></td></tr>
                    <tr><td><strong>Entregado por</strong></td><td id="trzUsuario"></td></tr>
                    <tr><td><strong>Observaciones</strong></td><td id="trzObservaciones"></td></tr>
                    <tr><td><strong>Estado</strong></td><td id="trzEstado"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="cerrarModal('modalTrazabilidad')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Categorías Almacén Materiales -->
    <div class="modal-overlay" id="modalCategoriasAlmacenMateriales">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Seleccionar Categoría</h3>
                <button class="modal-close" onclick="cerrarModal('modalCategoriasAlmacenMateriales')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($categorias as $cat): ?>
                    <div class="categoria-card" data-id="<?php echo $cat['id']; ?>" onclick="seleccionarCategoriaAlmacenMateriales(this)">
                        <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                        <div class="cat-nombre"><?php echo $cat['nombre']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="limpiarFiltroAlmacenMateriales()">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button class="btn" onclick="cerrarModal('modalCategoriasAlmacenMateriales')">Cancelar</button>
                <button class="btn btn-primary" onclick="aplicarFiltroAlmacenMateriales()">Aplicar</button>
            </div>
        </div>
    </div>
    
    <!-- Modales Requisición Almacén -->
    <div class="modal-overlay" id="modalCategoriasAlmacen">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-tags"></i> Seleccionar Categoría</div>
                <button class="modal-close" onclick="cerrarModal('modalCategoriasAlmacen')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="categoria-grid">
                    <?php foreach ($categorias as $cat): ?>
                    <div class="categoria-card" data-id="<?php echo $cat['id']; ?>" onclick="reqSeleccionarCategoria(this)">
                        <i class="fas fa-<?php echo $cat['icono'] ?? 'box'; ?>"></i>
                        <div class="cat-nombre"><?php echo $cat['nombre']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-limpiar" onclick="cerrarModal('modalCategoriasAlmacen')">Cancelar</button>
                <button class="btn btn-primary" onclick="reqCargarCatalogo()" id="reqBtnContinuar" disabled>
                    <i class="fas fa-arrow-right"></i> Continuar
                </button>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalCatalogoAlmacen">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-boxes"></i> Catálogo de Materiales</div>
                <button class="modal-close" onclick="cerrarModal('modalCatalogoAlmacen')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="text" id="reqBuscarMaterial" placeholder="Buscar material..." class="search-input" style="margin-bottom: 15px;">
                <div class="catalogo-grid" id="reqCatalogoGrid"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="cerrarModal('modalCatalogoAlmacen')">Cancelar</button>
                <button class="btn btn-primary" onclick="reqSeleccionarMaterialCatalogo()" id="reqBtnSeleccionar" disabled>
                    <i class="fas fa-check"></i> Seleccionar
                </button>
            </div>
        </div>
    </div>
    
    <div class="modal-overlay" id="modalCantidadAlmacen">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-calculator"></i> Cantidad</div>
                <button class="modal-close" onclick="cerrarModal('modalCantidadAlmacen')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <h4 id="reqNombreMaterialCantidad"></h4>
                <p style="color: #666;">Stock disponible: <span id="reqStockDisponible"></span></p>
                <div class="cantidad-wrapper">
                    <button class="cantidad-btn" onclick="reqAjustarCantidad(-1)">-</button>
                    <input type="number" class="cantidad-input" id="reqCantidadInput" value="1" min="1">
                    <button class="cantidad-btn" onclick="reqAjustarCantidad(1)">+</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="cerrarModal('modalCantidadAlmacen')">Cancelar</button>
                <button class="btn btn-success" onclick="reqConfirmarCantidad()">
                    <i class="fas fa-check"></i> Agregar
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalSincronizar">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #FF9800;">
                <div class="modal-title"><i class="fas fa-sync-alt"></i> Confirmar Sincronización</div>
                <button class="modal-close" onclick="cerrarModal('modalSincronizar')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-sync-alt" style="font-size: 48px; color: #FF9800; margin: 20px 0;"></i>
                <p style="margin-bottom: 15px; font-size: 16px;">¿Sincronizar este proyecto?</p>
                <p style="font-size: 13px; color: #666;">Los materiales nuevos o con cantidad actualizada se agregarán. Las asignaciones ya realizadas se conservan. El proyecto pasará a Almacén Activo para asignar el stock faltante.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="sincronizar_proyecto">
                    <input type="hidden" name="proyecto_id" id="sync_proyecto_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalSincronizar')">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Sí, Sincronizar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalNuevaReq">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #003d5c;">
                <div class="modal-title"><i class="fas fa-file-alt"></i> Nueva Requisición</div>
                <button class="modal-close" onclick="cerrarModal('modalNuevaReq')">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <i class="fas fa-file-invoice" style="font-size: 48px; color: #003d5c; margin: 20px 0;"></i>
                <p style="margin-bottom: 15px; font-size: 16px;">¿Crear una nueva Hoja de Requisición?</p>
                <p style="font-size: 13px; color: #666;">Serás redirigido al creador de requisiciones. Recuerda borrar de la lista los materiales que ya hayas pedido anteriormente.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="nueva_requisicion_proyecto">
                    <input type="hidden" name="proyecto_id" id="req_proyecto_id" value="">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal('modalNuevaReq')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="background: #003d5c;">Continuar</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Variables globales
        let categoriaSeleccionadaInventario = null;
        let categoriaSeleccionadaAlmacenMateriales = null;
        let materialSeleccionado = null;
        let materialStockActual = 0;
        let marcaSeleccionadaInventario = null;
        let carpetaActivaId = 0; // 0 = todas, -1 = sin carpeta, N = carpeta N
        let carpetasData = [];
        let moverMaterialId = 0;
        
        // Bloquear Ctrl+S
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                return false;
            }
        });

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('dragstart', (e) => e.preventDefault());
            });
            
            initSearch('inventario');
            initSearch('proyectos');
            initSearch('almacenMateriales');

            // Altura inicial de los textarea de Descripción -- oninput solo
            // ajusta cuando el usuario edita, sin esto el texto largo que ya
            // viene precargado (materiales faltantes del proyecto) se ve
            // recortado hasta que alguien le da clic.
            document.querySelectorAll('.desc-textarea').forEach(function(ta) {
                ta.style.height = ta.scrollHeight + 'px';
            });
        });

        // Funciones genéricas de búsqueda
        function initSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            
            if (!input) return;
            
            input.addEventListener('input', function() {
                if (this.value.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }
                
                if (section === 'inventario') {
                    filtrarInventario(this.value);
                } else if (section === 'proyectos') {
                    filtrarProyectos(this.value);
                } else if (section === 'almacenMateriales') {
                    filtrarAlmacenMateriales(this.value);
                }
            });
        }

        function clearSearch(section) {
            const input = document.getElementById(`search${section.charAt(0).toUpperCase() + section.slice(1)}`);
            const clearBtn = document.getElementById(`clearSearch${section.charAt(0).toUpperCase() + section.slice(1)}`);
            
            if (input) {
                input.value = '';
                clearBtn.classList.remove('visible');
                
                if (section === 'inventario') {
                    filtrarInventario('');
                } else if (section === 'proyectos') {
                    filtrarProyectos('');
                } else if (section === 'almacenMateriales') {
                    filtrarAlmacenMateriales('');
                }
            }
        }
        
        // Filtros específicos
        function aplicarFiltrosInventarioCompleto() {
            const searchEl = document.getElementById('searchInventario');
            const termino   = searchEl ? searchEl.value.toLowerCase() : '';
            const catId     = categoriaSeleccionadaInventario;
            const marca     = marcaSeleccionadaInventario;
            const carpId    = carpetaActivaId;

            document.querySelectorAll('#inventarioGrid .material-card').forEach(function(card) {
                let matchSearch  = true;
                let matchCat     = true;
                let matchMarca   = true;
                let matchCarpeta = true;

                if (termino) {
                    const desc = card.dataset.descripcion || '';
                    const cod  = card.dataset.codigo      || '';
                    matchSearch = desc.includes(termino) || cod.includes(termino);
                }
                if (catId) {
                    matchCat = (card.dataset.categoria == catId);
                }
                if (marca) {
                    matchMarca = (card.dataset.marca === marca);
                }
                if (carpId === 0 || carpId === '0') {
                    matchCarpeta = true;
                } else if (carpId === -1 || carpId === '-1') {
                    matchCarpeta = (!card.dataset.carpeta || card.dataset.carpeta === '');
                } else {
                    matchCarpeta = (card.dataset.carpeta === String(carpId));
                }

                card.style.display = (matchSearch && matchCat && matchMarca && matchCarpeta) ? '' : 'none';
            });
        }

        function filtrarInventario(termino) {
            aplicarFiltrosInventarioCompleto();
        }

        function filtrarProyectos(termino) {
            const rows = document.querySelectorAll('#proyectosTableBody tr');
            termino = termino.toLowerCase();
            
            rows.forEach(row => {
                const nombre = row.dataset.nombre || '';
                const descripcion = row.dataset.descripcion || '';
                
                if (nombre.includes(termino) || descripcion.includes(termino)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filtrarAlmacenMateriales(termino) {
            const cards = document.querySelectorAll('#materialesAlmacenGrid .material-card');
            termino = termino.toLowerCase();
            
            cards.forEach(card => {
                const nombre = card.dataset.nombre || '';
                const codigo = card.dataset.codigo || '';
                
                if (nombre.includes(termino) || codigo.includes(termino)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        
        // Funciones para categorías en inventario
        function abrirModalCategoriasInventario() {
            document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected'));
            categoriaSeleccionadaInventario = null;
            document.getElementById('modalCategoriasInventario').classList.add('active');
        }
        
        function seleccionarCategoriaInventario(card) {
            document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            categoriaSeleccionadaInventario = card.dataset.id;
        }
        
        function aplicarFiltroInventario() {
            cerrarModal('modalCategoriasInventario');
            aplicarFiltrosInventarioCompleto();
        }

        function limpiarFiltroInventario() {
            categoriaSeleccionadaInventario = null;
            document.querySelectorAll('#modalCategoriasInventario .categoria-card').forEach(c => c.classList.remove('selected'));
            cerrarModal('modalCategoriasInventario');
            aplicarFiltrosInventarioCompleto();
        }

        // Funciones para filtro de Marca del inventario de Almacén
        function abrirModalMarcasInventario() {
            document.querySelectorAll('#modalMarcasInventarioAlmacen .categoria-card').forEach(c => c.classList.remove('selected'));
            document.getElementById('modalMarcasInventarioAlmacen').classList.add('active');
        }

        function seleccionarMarcaInventario(card) {
            document.querySelectorAll('#modalMarcasInventarioAlmacen .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            marcaSeleccionadaInventario = card.dataset.marca;
        }

        function aplicarFiltroMarcaInventario() {
            cerrarModal('modalMarcasInventarioAlmacen');
            aplicarFiltrosInventarioCompleto();
        }

        function limpiarFiltroMarcaInventario() {
            marcaSeleccionadaInventario = null;
            document.querySelectorAll('#modalMarcasInventarioAlmacen .categoria-card').forEach(c => c.classList.remove('selected'));
            cerrarModal('modalMarcasInventarioAlmacen');
            aplicarFiltrosInventarioCompleto();
        }

        /* ── Carpetas de materiales (Inventario Almacén) ── */
        (function() {
            if (!document.getElementById('carpetasList')) return;
            fetch('api/materiales.php?action=carpetas')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    carpetasData = data.data || [];
                    renderCarpetas();
                });
        })();

        function renderCarpetas() {
            const list = document.getElementById('carpetasList');
            if (!list) return;
            list.querySelectorAll('.carpeta-item[data-dinamica]').forEach(function(el) { el.remove(); });

            carpetasData.forEach(function(c) {
                const div = document.createElement('div');
                div.className = 'carpeta-item';
                div.dataset.id = c.id;
                div.dataset.dinamica = '1';
                div.innerHTML = '<i class="fas fa-folder"></i> ' + escapeHtml(c.nombre);
                div.addEventListener('click', function() { filtrarPorCarpeta(c.id, div); });
                list.appendChild(div);
            });
        }

        function filtrarCarpetas(q) {
            q = q.toLowerCase().trim();
            document.querySelectorAll('.carpeta-item[data-dinamica]').forEach(function(el) {
                el.style.display = (!q || el.textContent.toLowerCase().includes(q)) ? '' : 'none';
            });
        }

        function filtrarPorCarpeta(id, el) {
            carpetaActivaId = id;
            document.querySelectorAll('.carpeta-item').forEach(function(i) { i.classList.remove('active'); });
            if (el) el.classList.add('active');
            aplicarFiltrosInventarioCompleto();
        }

        function organizarPorMarca() {
            if (!confirm('¿Organizar automáticamente los materiales en carpetas por marca?\n\nSe crearán carpetas para cada marca y cada material se asignará a la suya.')) return;
            const fd = new FormData();
            fd.append('action', 'organizar_por_marca');
            fetch('api/materiales.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        alert('¡Listo!\n• Carpetas creadas: ' + data.carpetas_creadas + '\n• Materiales asignados: ' + data.materiales_asignados);
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'No se pudo organizar'));
                    }
                });
        }

        function crearCarpeta() {
            const nombre = prompt('Nombre de la nueva carpeta:');
            if (!nombre || !nombre.trim()) return;

            const fd = new FormData();
            fd.append('action', 'crear_carpeta');
            fd.append('nombre', nombre.trim());

            fetch('api/materiales.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        carpetasData.push({ id: data.id, nombre: nombre.trim(), padre_id: null });
                        renderCarpetas();
                    } else {
                        alert('Error: ' + (data.error || 'No se pudo crear la carpeta'));
                    }
                });
        }

        function abrirMoverMaterial(materialId, materialNombre, carpetaActual) {
            moverMaterialId = materialId;
            document.getElementById('moverMaterialNombre').textContent = 'Material: ' + materialNombre;

            const sel = document.getElementById('moverCarpetaSelect');
            sel.innerHTML = '<option value="">Sin carpeta (raíz)</option>';
            carpetasData.forEach(function(c) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.nombre;
                if (carpetaActual && String(c.id) === String(carpetaActual)) opt.selected = true;
                sel.appendChild(opt);
            });

            document.getElementById('modalMoverCarpeta').style.display = 'flex';
        }

        function cerrarMoverCarpeta() {
            document.getElementById('modalMoverCarpeta').style.display = 'none';
            moverMaterialId = 0;
        }

        function confirmarMoverMaterial() {
            const carpetaId = document.getElementById('moverCarpetaSelect').value;
            const fd = new FormData();
            fd.append('action', 'mover_material');
            fd.append('material_id', moverMaterialId);
            fd.append('carpeta_id', carpetaId);

            fetch('api/materiales.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        document.querySelectorAll('#inventarioGrid .material-card').forEach(function(c) {
                            const btn = c.querySelector('.btn-mover-carpeta');
                            if (btn && btn.getAttribute('onclick').includes('(' + moverMaterialId + ',')) {
                                c.dataset.carpeta = carpetaId;
                            }
                        });
                        cerrarMoverCarpeta();
                        filtrarPorCarpeta(carpetaActivaId, null);
                    } else {
                        alert('Error al mover: ' + (data.error || 'desconocido'));
                    }
                });
        }

        /* ── Modal Nuevo / Editar Material (Inventario Almacén) ── */
        /* ── Código de material: prefijo+consecutivo (solo jefe de Almacén) ── */
        function _escMat(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        (function() {
            const pre = document.getElementById('materialAlmacenPrefijo');
            const con = document.getElementById('materialAlmacenConsecutivo');
            if (pre && con) {
                pre.addEventListener('blur', sugerirConsecutivoMaterial);
                con.addEventListener('input', actualizarCampoCodigoDesdePrefijo);
                return;
            }
            // Sin permiso: sugerencia de solo lectura al escribir la Descripción, solo
            // para material NUEVO (no pisar el código real de uno que ya se está editando).
            const desc = document.getElementById('materialAlmacenDescripcion');
            const cod  = document.getElementById('campoCodigo');
            if (!desc || !cod) return;
            let timer = null;
            desc.addEventListener('input', function() {
                if (document.getElementById('materialAlmacenId').value) return;
                clearTimeout(timer);
                timer = setTimeout(function() {
                    const texto = desc.value.trim();
                    if (!texto) { cod.value = ''; return; }
                    fetch('api/materiales.php?action=sugerir_codigo&descripcion=' + encodeURIComponent(texto))
                        .then(function(r) { return r.json(); })
                        .then(function(data) { cod.value = data.codigo || ''; })
                        .catch(function() {});
                }, 400);
            });
        })();

        // Buscador de modelos ya existentes en el catálogo (1547 distintos, 2026-08-25) --
        // se filtra en el servidor conforme se escribe, para detectar que un modelo ya
        // existe antes de darlo de alta duplicado con otra capitalización/espacios.
        (function() {
            const campo = document.getElementById('materialAlmacenModelo');
            const dl = document.getElementById('modelosAlmacenDatalist');
            if (!campo || !dl) return;
            let timer = null;
            campo.addEventListener('input', function() {
                clearTimeout(timer);
                const q = campo.value.trim();
                if (q.length < 2) { dl.innerHTML = ''; return; }
                timer = setTimeout(function() {
                    fetch('api/materiales.php?action=buscar_modelos&q=' + encodeURIComponent(q))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            dl.innerHTML = (data || []).map(function(m) { return '<option value="' + _escMat(m) + '">'; }).join('');
                        })
                        .catch(function() {});
                }, 300);
            });
        })();

        // Botón "Verificar código" en la tabla "Items del proyecto" (junto a Reemplazar) --
        // abre el mismo modal de editar material sin salir de esta pantalla, para no tener
        // que ir hasta Inventario y volver.
        function verificarCodigoDesdeComparativa(materialId) {
            fetch('api/materiales.php?action=obtener&id=' + materialId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) abrirModalEditarMaterial(data.data);
                });
        }

        function actualizarCampoCodigoDesdePrefijo() {
            const pre = document.getElementById('materialAlmacenPrefijo');
            const con = document.getElementById('materialAlmacenConsecutivo');
            const cod = document.getElementById('campoCodigo');
            if (!pre || !con || !cod) return;
            const p = pre.value.trim().toUpperCase();
            const c = con.value.trim();
            cod.value = (p && c) ? (p + c) : '';
        }

        function cargarPrefijosMaterial() {
            const dl = document.getElementById('prefijosMaterialDatalist');
            if (!dl || dl.dataset.cargado) return;
            dl.dataset.cargado = '1';
            fetch('api/materiales.php?action=prefijos')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) return;
                    dl.innerHTML = data.data.map(function(p) {
                        return '<option value="' + _escMat(p.prefijo) + '">' + _escMat(p.prefijo) + ' — ' + _escMat(p.ejemplo) + ' (' + p.n + ')</option>';
                    }).join('');
                });
        }

        function sugerirConsecutivoMaterial() {
            const pre = document.getElementById('materialAlmacenPrefijo');
            const con = document.getElementById('materialAlmacenConsecutivo');
            const info = document.getElementById('materialAlmacenCodigoInfo');
            if (!pre || !con) return;
            const p = pre.value.trim().toUpperCase();
            pre.value = p;
            if (p.length !== 4) { actualizarCampoCodigoDesdePrefijo(); return; }
            fetch('api/materiales.php?action=sugerir_codigo&descripcion=' + encodeURIComponent(p))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.codigo) {
                        con.value = data.codigo.substring(4);
                        if (info) info.textContent = 'Sugerido: ' + data.codigo + ' (editable)';
                    }
                    actualizarCampoCodigoDesdePrefijo();
                });
        }

        function abrirModalNuevoMaterial() {
            document.getElementById('materialAlmacenModalTitle').textContent = 'Nuevo Material';
            document.getElementById('materialAlmacenAction').value = 'guardar_material';
            document.getElementById('formMaterialAlmacen').reset();
            document.getElementById('materialAlmacenId').value = '';
            document.getElementById('materialAlmacenStockInfo').style.display = 'none';
            document.getElementById('campoCodigo').value = '';
            const pre = document.getElementById('materialAlmacenPrefijo');
            if (pre) { pre.value = ''; document.getElementById('materialAlmacenConsecutivo').value = ''; cargarPrefijosMaterial(); }
            document.getElementById('modalMaterialAlmacen').classList.add('active');
        }

        function abrirModalEditarMaterial(mat) {
            // Saca al material del frente del Inventario (ver Fase 51) en cuanto se abre, sin
            // importar si al final se guardan cambios o no -- "hasta que se acceda al material".
            fetch('api/materiales.php?action=marcar_visto', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'id=' + mat.id });
            document.getElementById('materialAlmacenModalTitle').textContent = 'Editar Material';
            document.getElementById('materialAlmacenAction').value = 'editar_material';
            document.getElementById('formMaterialAlmacen').reset();
            document.getElementById('materialAlmacenId').value = mat.id;
            document.getElementById('campoCodigo').value = mat.codigo || '';
            const pre = document.getElementById('materialAlmacenPrefijo');
            if (pre) {
                cargarPrefijosMaterial();
                const cod = mat.codigo || '';
                pre.value = cod.substring(0, 4);
                document.getElementById('materialAlmacenConsecutivo').value = cod.substring(4);
            }
            document.getElementById('materialAlmacenCategoria').value = mat.categoria_id || '';
            document.getElementById('materialAlmacenMarca').value = mat.marca || '';
            document.getElementById('materialAlmacenModelo').value = mat.modelo || '';
            document.getElementById('materialAlmacenUnidad').value = mat.unidad_medida || 'pza';
            document.getElementById('materialAlmacenUbicacion').value = mat.ubicacion || '';
            document.getElementById('materialAlmacenStockMinimo').value = mat.stock_minimo || 0;
            document.getElementById('materialAlmacenDescripcion').value = mat.descripcion || '';
            const info = document.getElementById('materialAlmacenStockInfo');
            info.style.display = 'block';
            info.innerHTML = '<i class="fas fa-info-circle"></i> Stock disponible actual: <strong>' + (mat.stock_disponible || 0) + ' ' + (mat.unidad_medida || 'pza') + '</strong> — usa los botones +/- de la ficha para ajustarlo.';
            document.getElementById('modalMaterialAlmacen').classList.add('active');
        }

        /* ── Entradas / Salidas ── */
        function abrirModalEntrada() {
            document.getElementById('formEntrada').reset();
            const provSelect = document.getElementById('entradaProveedor');
            provSelect.style.pointerEvents = '';
            provSelect.style.opacity = '';
            document.getElementById('entradaProveedorHint').style.display = 'none';
            document.getElementById('entradaCodigo').value = '';
            document.getElementById('modalEntrada').classList.add('active');
        }

        function sincronizarCodigoPorMaterial() {
            const matSelect = document.getElementById('entradaMaterial');
            const opt = matSelect.options[matSelect.selectedIndex];
            document.getElementById('entradaCodigo').value = opt ? (opt.dataset.codigo || '') : '';
        }

        function sincronizarProveedorPorOC() {
            const ocSelect = document.getElementById('entradaOrdenCompra');
            const provSelect = document.getElementById('entradaProveedor');
            const hint = document.getElementById('entradaProveedorHint');
            const opt = ocSelect.options[ocSelect.selectedIndex];
            const proveedorId = opt ? opt.dataset.proveedor : '';

            if (ocSelect.value && proveedorId && proveedorId !== '0') {
                provSelect.value = proveedorId;
                // No se usa disabled: un <select disabled> no se envía con el formulario.
                // Se bloquea visualmente pero el valor sigue viajando en el POST.
                provSelect.style.pointerEvents = 'none';
                provSelect.style.opacity = '0.6';
                hint.style.display = 'block';
            } else {
                provSelect.style.pointerEvents = '';
                provSelect.style.opacity = '';
                hint.style.display = 'none';
            }
        }

        function abrirModalSalida() {
            document.getElementById('formSalida').reset();
            document.getElementById('modalSalida').classList.add('active');
        }

        function confirmarReingreso(historialId, materialNombre) {
            document.getElementById('reingresoHistorialId').value = historialId;
            document.getElementById('reingresoMaterialNombre').textContent = materialNombre;
            document.getElementById('modalReingreso').classList.add('active');
        }

        function abrirTrazabilidad(data) {
            document.getElementById('trzFolio').textContent = data.folio || '-';
            document.getElementById('trzFecha').textContent = data.fecha || '-';
            document.getElementById('trzMaterial').textContent = data.material || '-';
            document.getElementById('trzCantidad').textContent = data.cantidad || '-';
            document.getElementById('trzDestino').textContent = data.destino || '-';
            document.getElementById('trzSeries').textContent = data.series || '-';
            document.getElementById('trzUsuario').textContent = data.usuario || '-';
            document.getElementById('trzObservaciones').textContent = data.observaciones || '-';
            document.getElementById('trzEstado').textContent = data.reingresado ? 'Reingresado a almacén' : 'En uso / consumido';
            document.getElementById('modalTrazabilidad').classList.add('active');
        }

        // Funciones para categorías en almacén activo
        function abrirModalCategoriasAlmacenMateriales() {
            document.querySelectorAll('#modalCategoriasAlmacenMateriales .categoria-card').forEach(c => c.classList.remove('selected'));
            categoriaSeleccionadaAlmacenMateriales = null;
            document.getElementById('modalCategoriasAlmacenMateriales').classList.add('active');
        }
        
        function seleccionarCategoriaAlmacenMateriales(card) {
            document.querySelectorAll('#modalCategoriasAlmacenMateriales .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            categoriaSeleccionadaAlmacenMateriales = card.dataset.id;
        }
        
        function aplicarFiltroAlmacenMateriales() {
            cerrarModal('modalCategoriasAlmacenMateriales');
            
            const cards = document.querySelectorAll('#materialesAlmacenGrid .material-card');
            
            if (!categoriaSeleccionadaAlmacenMateriales) {
                cards.forEach(card => card.style.display = '');
                return;
            }
            
            cards.forEach(card => {
                if (card.dataset.categoria == categoriaSeleccionadaAlmacenMateriales) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function limpiarFiltroAlmacenMateriales() {
            categoriaSeleccionadaAlmacenMateriales = null;
            document.querySelectorAll('#modalCategoriasAlmacenMateriales .categoria-card').forEach(c => c.classList.remove('selected'));
            aplicarFiltroAlmacenMateriales();
            cerrarModal('modalCategoriasAlmacenMateriales');
        }
        
        // Funciones de modales generales
        function cerrarModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        // Reemplazo de material (padre-hijo) al surtir un BOM/requisición --
        // solo propone, el swap real lo aplica el jefe del área responsable
        // en reemplazos_material.php.
        let _reemplazoOrigen = null;
        let _reemplazoSeleccionados = new Map(); // id -> descripcion -- varios candidatos, Ingeniería elige cuál aplicar
        let _reemplazoBuscarTimeout = null;

        function abrirModalReemplazo(origenTipo, origenId, descripcionOriginal, materialOriginalId) {
            _reemplazoOrigen = { tipo: origenTipo, id: origenId, materialOriginalId: materialOriginalId };
            _reemplazoSeleccionados = new Map();
            document.getElementById('reemplazo_origen_original').textContent = descripcionOriginal;
            document.getElementById('reemplazoSearchInput').value = '';
            document.getElementById('reemplazoResults').innerHTML = '<div style="padding:10px;color:#999;font-size:.8rem;">Escribe para buscar material(es) alterno(s)...</div>';
            document.getElementById('reemplazoNota').value = '';
            reemplazoRenderizarSeleccionados();
            document.getElementById('modalReemplazoMaterial').classList.add('active');
            setTimeout(function() { document.getElementById('reemplazoSearchInput').focus(); }, 80);
        }

        function reemplazoBuscarInput() {
            clearTimeout(_reemplazoBuscarTimeout);
            _reemplazoBuscarTimeout = setTimeout(reemplazoBuscar, 300);
        }

        function reemplazoBuscar() {
            const q = document.getElementById('reemplazoSearchInput').value.trim();
            const box = document.getElementById('reemplazoResults');
            if (q.length < 2) { box.innerHTML = '<div style="padding:10px;color:#999;font-size:.8rem;">Escribe al menos 2 caracteres.</div>'; return; }
            box.innerHTML = '<div style="padding:10px;color:#999;font-size:.8rem;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            fetch('api/materiales.php?busqueda=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    const items = (d.data || []).filter(function(m) { return String(m.id) !== String(_reemplazoOrigen.materialOriginalId); });
                    if (!items.length) { box.innerHTML = '<div style="padding:10px;color:#999;font-size:.8rem;">Sin resultados.</div>'; return; }
                    box.innerHTML = items.slice(0, 25).map(function(m) {
                        const marcado = _reemplazoSeleccionados.has(String(m.id));
                        return '<div style="padding:8px 10px;border-bottom:1px solid #eee;cursor:pointer;font-size:.82rem;display:flex;align-items:flex-start;gap:8px;' + (marcado ? 'background:#e8f5e9;' : '') + '" '
                            + 'onmouseover="if(!' + marcado + ')this.style.background=\'#f5f9fc\'" onmouseout="this.style.background=\'' + (marcado ? '#e8f5e9' : '') + '\'" '
                            + 'onclick="reemplazoToggleSeleccion(' + m.id + ', ' + JSON.stringify(m.descripcion || '').replace(/"/g, '&quot;') + ')">'
                            + '<i class="fas ' + (marcado ? 'fa-check-square' : 'fa-square') + '" style="color:' + (marcado ? '#2e7d32' : '#ccc') + ';margin-top:2px;"></i>'
                            + '<div><strong>' + (m.codigo ? '[' + escapeHtmlAlmacen(m.codigo) + '] ' : '') + escapeHtmlAlmacen(m.descripcion || '') + '</strong>'
                            + '<div style="font-size:.72rem;color:#666;">' + escapeHtmlAlmacen(m.marca || '—') + ' / ' + escapeHtmlAlmacen(m.modelo || '—') + ' · Stock: ' + (m.stock_disponible ?? 0) + '</div></div>'
                            + '</div>';
                    }).join('');
                })
                .catch(function() { box.innerHTML = '<div style="padding:10px;color:#c62828;font-size:.8rem;">Error al buscar.</div>'; });
        }

        function escapeHtmlAlmacen(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Varios candidatos porque Almacén no siempre sabe cuál encaja mejor
        // técnicamente -- Ingeniería elige entre las opciones al aprobar.
        function reemplazoToggleSeleccion(id, descripcion) {
            const key = String(id);
            if (_reemplazoSeleccionados.has(key)) _reemplazoSeleccionados.delete(key);
            else _reemplazoSeleccionados.set(key, descripcion);
            reemplazoRenderizarSeleccionados();
            reemplazoBuscar();
        }

        function reemplazoQuitarSeleccion(id) {
            _reemplazoSeleccionados.delete(String(id));
            reemplazoRenderizarSeleccionados();
        }

        function reemplazoRenderizarSeleccionados() {
            const cont = document.getElementById('reemplazoSeleccionado');
            const btn = document.getElementById('btnConfirmarReemplazo');
            if (_reemplazoSeleccionados.size === 0) {
                cont.style.display = 'none';
                btn.disabled = true;
                return;
            }
            cont.style.display = 'block';
            cont.innerHTML = '<div style="font-weight:600;margin-bottom:4px;"><i class="fas fa-check-circle" style="color:#2e7d32;"></i> Candidato(s) seleccionado(s):</div>'
                + Array.from(_reemplazoSeleccionados.entries()).map(function([id, desc]) {
                    return '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:2px 0;">'
                        + '<span>' + escapeHtmlAlmacen(desc) + '</span>'
                        + '<button type="button" onclick="reemplazoQuitarSeleccion(' + id + ')" style="background:none;border:none;color:#c62828;cursor:pointer;font-size:.8rem;" title="Quitar"><i class="fas fa-times"></i></button>'
                        + '</div>';
                }).join('');
            btn.disabled = false;
        }

        function confirmarProponerReemplazo() {
            if (!_reemplazoOrigen || _reemplazoSeleccionados.size === 0) return;
            const btn = document.getElementById('btnConfirmarReemplazo');
            btn.disabled = true;
            fetch('api/reemplazo_material.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    origen_tipo: _reemplazoOrigen.tipo,
                    origen_id: _reemplazoOrigen.id,
                    alternativa_ids: Array.from(_reemplazoSeleccionados.keys()).map(Number),
                    nota: document.getElementById('reemplazoNota').value.trim()
                })
            })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        cerrarModal('modalReemplazoMaterial');
                        alert(d.message);
                        window.location.reload();
                    } else {
                        alert(d.error || 'No se pudo proponer el reemplazo.');
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    alert('Error de red al proponer el reemplazo.');
                    btn.disabled = false;
                });
        }

        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });

        // Funciones específicas de Almacén
        function abrirModalAjusteStock(id, descripcion, tipo) {
            document.getElementById('ajuste_material_id').value = id;
            document.getElementById('ajuste_material_nombre').textContent = descripcion;
            document.getElementById('ajuste_tipo').value = tipo;
            document.getElementById('ajuste_tipo_texto').textContent = tipo === 'entrada' ? 'Entrada (Agregar)' : 'Salida (Quitar)';
            document.getElementById('modalAjusteStock').classList.add('active');
        }

        function abrirModalAsignar(id, descripcion, stock) {
            document.getElementById('asignar_item_id').value = id;
            document.getElementById('asignar_item_nombre').textContent = descripcion;
            document.getElementById('asignar_stock_max').textContent = stock;
            document.getElementById('asignar_cantidad').max = stock;
            document.getElementById('modalAsignar').classList.add('active');
        }
        
        function confirmarPasarAlmacen(id, nombre) {
            document.getElementById('pasar_proyecto_id').value = id;
            document.getElementById('confirmar_pasar_mensaje').textContent = '¿Pasar el proyecto "' + nombre + '" a Almacén?';
            document.getElementById('modalConfirmarPasar').classList.add('active');
        }
        
        function confirmarCancelarProyecto() {
            document.getElementById('modalConfirmarCancelar').classList.add('active');
        }
        
        function confirmarFinalizarProyecto(id) {
            document.getElementById('finalizar_proyecto_id').value = id;
            document.getElementById('modalConfirmarFinalizar').classList.add('active');
        }

        // Imprime el BOM del proyecto activo: se carga en un iframe oculto y se
        // dispara la vista de impresión nativa (Guardar como PDF / Imprimir),
        // igual que el patrón ya usado en Ingeniería para exportar BOMs.
        function imprimirBomProyecto(proyectoId) {
            let frame = document.getElementById('iframeImprimirBomProyecto');
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = 'iframeImprimirBomProyecto';
                frame.style.display = 'none';
                document.body.appendChild(frame);
            }
            frame.onload = function () {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            };
            frame.src = 'api/imprimir_bom_proyecto.php?proyecto_id=' + proyectoId;
        }
        
        // seleccionarMaterial y cancelarSeleccion eliminados (panel de materiales quitado)
        
        // JS para Requisición Almacén
        let reqItemCounter = <?php echo isset($req_items) ? count($req_items) : 0; ?>;
        let reqCategoriaSeleccionada = null;
        let reqMaterialSeleccionado = null;
        
        const reqMaterialesCatalogo = <?php
            $req_materiales_json = [];
            foreach ($materiales as $mat) {
                $req_materiales_json[] = [
                    'id'              => $mat['id'],
                    'descripcion'     => $mat['descripcion'] ?? '',
                    'codigo'          => $mat['codigo'] ?? '',
                    'marca'           => $mat['marca'] ?? '',
                    'modelo'          => $mat['modelo'] ?? '',
                    'stock_disponible'=> $mat['stock_disponible'],
                    'imagen'          => $mat['imagen'] ?? '',
                    'categoria_id'    => $mat['categoria_id'],
                ];
            }
            echo json_encode($req_materiales_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        ?>;
        
        function abrirModalCategoriasAlmacen() {
            document.getElementById('modalCategoriasAlmacen').classList.add('active');
        }
        
        function reqSeleccionarCategoria(card) {
            document.querySelectorAll('#modalCategoriasAlmacen .categoria-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            reqCategoriaSeleccionada = card.dataset.id;
            document.getElementById('reqBtnContinuar').disabled = false;
        }
        
        function reqCargarCatalogo() {
            cerrarModal('modalCategoriasAlmacen');
            let filtrados = reqCategoriaSeleccionada
                ? reqMaterialesCatalogo.filter(m => m.categoria_id == reqCategoriaSeleccionada)
                : reqMaterialesCatalogo;
            reqRenderizarCatalogo(filtrados);
            document.getElementById('modalCatalogoAlmacen').classList.add('active');
        }
        
        function reqRenderizarCatalogo(materiales) {
            const grid = document.getElementById('reqCatalogoGrid');
            if (materiales.length === 0) {
                grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:40px;color:#999;"><i class="fas fa-box-open" style="font-size:48px;margin-bottom:15px;display:block;"></i><p>No hay materiales disponibles</p></div>`;
                return;
            }
            grid.innerHTML = materiales.map(m => `
                <div class="material-card-catalogo" data-id="${m.id}" onclick="reqSeleccionarMaterial(this, ${m.id})">
                    <div class="material-img">
                        ${m.imagen ? `<img src="uploads/${m.imagen}" alt="${m.descripcion}" style="width:100%;height:100%;object-fit:cover;">` : '<i class="fas fa-box"></i>'}
                    </div>
                    <div class="material-info">
                        <div class="material-nombre-catalogo">${m.descripcion}</div>
                        <div style="font-size:12px;color:#666;">Stock: ${m.stock_disponible}</div>
                    </div>
                </div>
            `).join('');
        }
        
        function reqSeleccionarMaterial(card, id) {
            document.querySelectorAll('#reqCatalogoGrid .material-card-catalogo').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            reqMaterialSeleccionado = reqMaterialesCatalogo.find(m => m.id == id);
            document.getElementById('reqBtnSeleccionar').disabled = false;
        }
        
        function reqSeleccionarMaterialCatalogo() {
            cerrarModal('modalCatalogoAlmacen');
            document.getElementById('reqNombreMaterialCantidad').textContent = reqMaterialSeleccionado.descripcion;
            document.getElementById('reqStockDisponible').textContent = reqMaterialSeleccionado.stock_disponible;
            document.getElementById('reqCantidadInput').value = 1;
            document.getElementById('reqCantidadInput').max = reqMaterialSeleccionado.stock_disponible;
            document.getElementById('modalCantidadAlmacen').classList.add('active');
        }
        
        function reqAjustarCantidad(delta) {
            const input = document.getElementById('reqCantidadInput');
            const nuevo = parseInt(input.value) + delta;
            if (nuevo >= 1) input.value = nuevo;
        }
        
        function reqConfirmarCantidad() {
            const cantidad = parseInt(document.getElementById('reqCantidadInput').value);
            reqAgregarFila(reqMaterialSeleccionado, cantidad);
            cerrarModal('modalCantidadAlmacen');
            reqMaterialSeleccionado = null;
            document.getElementById('reqBtnSeleccionar').disabled = true;
        }
        
        function reqAgregarFila(materialData, cantidad) {
            reqItemCounter++;
            const tbody = document.getElementById('reqMaterialesBody');
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="col-item">${reqItemCounter}</td>
                <td class="col-cantidad"><input type="number" name="items[${reqItemCounter}][cantidad]" value="${cantidad}" min="1" required></td>
                <td class="col-articulo"><input type="text" name="items[${reqItemCounter}][articulo]" value="${materialData.codigo || ''}"></td>
                <td class="col-descripcion">
                    <input type="hidden" name="items[${reqItemCounter}][material_id]" value="${materialData.id}">
                    <input type="text" name="items[${reqItemCounter}][descripcion]" value="${materialData.descripcion}" required>
                </td>
                <td class="col-marca"><input type="text" name="items[${reqItemCounter}][marca]" value="${materialData.marca || ''}" placeholder="Marca"></td>
                <td class="col-modelo"><input type="text" name="items[${reqItemCounter}][modelo]" value="${materialData.modelo || ''}" placeholder="Modelo"></td>
                <td class="col-nota"><input type="text" name="items[${reqItemCounter}][notas]" placeholder="Notas"></td>
                <td class="col-accion">
                    <button type="button" class="btn btn-sm btn-danger" onclick="reqEliminarFila(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(row);
            reqReenumerar();
        }
        
        // ── Filtro por categoría en Almacén Activo ─────────────────
        function filtrarCatAlmacen(catId, btn) {
            // Actualizar botones activos
            document.querySelectorAll('.cat-filter-btn').forEach(function(b) {
                b.style.background = 'transparent';
                b.style.color = '#005686';
            });
            btn.style.background = '#005686';
            btn.style.color = '#fff';
            // Filtrar filas
            document.querySelectorAll('#tablaItemsAlmacen tbody tr').forEach(function(row) {
                if (catId === 'all' || row.dataset.cat == catId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function reqEliminarFila(btn) {
            const tr   = btn.closest('tr');
            const aiId = tr.dataset.aiId;
            tr.remove();
            reqReenumerar();
            // Eliminar de almacen_items para que no reaparezca
            if (aiId) {
                const fd = new FormData();
                fd.append('accion',        'eliminar_almacen_item');
                fd.append('almacen_item_id', aiId);
                fetch(window.location.pathname + '?seccion=requisicion_almacen', { method: 'POST', body: fd })
                    .catch(() => {}); // silencioso
            }
        }
        
        function reqReenumerar() {
            const rows = document.querySelectorAll('#reqMaterialesBody tr');
            rows.forEach((row, idx) => {
                row.querySelector('.col-item').textContent = idx + 1;
            });
            reqItemCounter = rows.length;
        }
        
        document.getElementById('reqBuscarMaterial')?.addEventListener('input', function() {
            const termino = this.value.toLowerCase();
            const filtrados = reqMaterialesCatalogo.filter(m =>
                (m.descripcion && m.descripcion.toLowerCase().includes(termino)) ||
                (m.codigo      && m.codigo.toLowerCase().includes(termino))
            );
            reqRenderizarCatalogo(filtrados);
        });
        function toggleMaximize(panelId) {
    // Panel único, función mantenida por compatibilidad pero sin efecto split
    const targetPanel = document.getElementById(panelId);
    if (!targetPanel) return;
    const isMaximized = targetPanel.classList.contains('maximized');
    if (isMaximized) {
        targetPanel.classList.remove('maximized');
    } else {
        targetPanel.classList.add('maximized');
    }
}
function generarPDF() {
    // html2pdf.js (captura por canvas) generaba PDFs en blanco de forma intermitente —
    // mismo problema ya visto antes en la exportación de BOM. La página ya tiene CSS
    // @media print que oculta botones/columnas de acción (.no-print, .col-accion), así
    // que basta con el diálogo de impresión nativo del navegador (Guardar como PDF).
    window.print();
}

// Función para abrir/cerrar la carpeta
    function toggleCarpeta(proyectoId) {
        const carpeta = document.getElementById('carpeta-' + proyectoId);
        const btn = document.getElementById('btn-toggle-' + proyectoId);
        
        carpeta.classList.toggle('active');
        btn.classList.toggle('open');
    }

    // Funciones para Modales
    function abrirModalSincronizar(proyectoId) {
        document.getElementById('sync_proyecto_id').value = proyectoId;
        document.getElementById('modalSincronizar').classList.add('active');
    }

    function abrirModalNuevaReq(proyectoId) {
        document.getElementById('req_proyecto_id').value = proyectoId;
        document.getElementById('modalNuevaReq').classList.add('active');
    }

    // ===== VARIABLES DEL CHAT =====
    let chatInterval = null;
    let contactoActivo = null;
    let archivoAdjunto = null;
    let archivoTipo = null;
    let usuariosLista = [];
    let usuarioActualId = <?php echo $_SESSION['user_id']; ?>;
    let usuarioActualNombre = '<?php echo addslashes($_SESSION['user_name']); ?>';

    function actualizarEstadoConexion() {
        fetch('includes/ajax_chat.php?action=actualizar_estado')
            .then(() => {
                const dot = document.getElementById('chatStatusDot');
                if (dot) {
                    dot.classList.remove('offline', 'away');
                    dot.classList.add('online');
                }
            });
    }

    function iniciarPollingChat() {
        if (chatInterval) clearInterval(chatInterval);
        chatInterval = setInterval(verificarNuevosMensajes, 5000);
        setInterval(actualizarEstadoConexion, 30000);
        actualizarEstadoConexion();
        cargarUsuarios();
        actualizarBadgeNotifHeader();
    }

    function verificarNuevosMensajes() {
        fetch('includes/ajax_chat.php?action=obtener_nuevos')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const totalNoLeidos = (data.total_no_leidos || 0) + (data.notificaciones ? data.notificaciones.length : 0);
                const badge = document.getElementById('chatNotificationBadge');

                if (totalNoLeidos > 0) {
                    badge.textContent = totalNoLeidos > 99 ? '99+' : totalNoLeidos;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
                
                if (data.contactos_con_no_leidos && usuariosLista.length) {
                    actualizarBadgesUsuarios(data.contactos_con_no_leidos);
                }

                const bellBadge = document.getElementById('notifBellBadge');
                if (bellBadge) {
                    const noLeidasNotif = data.notificaciones ? data.notificaciones.filter(n => ALMACEN_NOTIF_TIPOS.includes(n.tipo)).length : 0;
                    if (noLeidasNotif > 0) {
                        bellBadge.textContent = noLeidasNotif > 99 ? '99+' : noLeidasNotif;
                        bellBadge.style.display = 'flex';
                    } else {
                        bellBadge.style.display = 'none';
                    }
                }

                if (document.getElementById('chatWindow').classList.contains('open')) {
                    if (document.querySelector('.chat-tab.active').getAttribute('data-tab') === 'conversaciones' && contactoActivo) {
                        cargarHistorial(contactoActivo);
                    } else if (document.querySelector('.chat-tab.active').getAttribute('data-tab') === 'notificaciones') {
                        cargarNotificaciones();
                    }
                    if (contactoActivo) {
                        marcarMensajesComoLeidos(contactoActivo);
                    }
                }
            }
        });
    }

    function actualizarBadgesUsuarios(noLeidosPorContacto) {
        const items = document.querySelectorAll('.chat-user-item');
        items.forEach(item => {
            const userId = parseInt(item.dataset.id);
            const noLeidos = noLeidosPorContacto[userId] || 0;
            let badgeSpan = item.querySelector('.chat-user-badge');
            if (noLeidos > 0) {
                if (!badgeSpan) {
                    badgeSpan = document.createElement('span');
                    badgeSpan.className = 'chat-user-badge';
                    item.querySelector('.chat-user-info').appendChild(badgeSpan);
                }
                badgeSpan.textContent = noLeidos;
                badgeSpan.style.display = 'inline-block';
            } else if (badgeSpan) {
                badgeSpan.style.display = 'none';
            }
        });
    }

    function toggleChat() {
        const chatWindow = document.getElementById('chatWindow');
        if (chatWindow.classList.contains('open')) {
            closeChat();
        } else {
            openChat();
        }
    }

    function openChat() {
        const chatWindow = document.getElementById('chatWindow');
        chatWindow.classList.add('open');
        cargarUsuarios();
        volverAListaUsuarios();
    }

    function closeChat() {
        const chatWindow = document.getElementById('chatWindow');
        chatWindow.classList.remove('open');
        contactoActivo = null;
    }

    function minimizeChat() {
        closeChat();
    }

    function switchChatTab(tab) {
        const tabs = document.querySelectorAll('.chat-tab');
        const panels = document.querySelectorAll('.chat-panel');
        
        tabs.forEach(t => t.classList.remove('active'));
        panels.forEach(p => p.style.display = 'none');
        
        if (tab === 'conversaciones') {
            document.querySelector('.chat-tab[data-tab="conversaciones"]').classList.add('active');
            document.getElementById('chatPanelConversaciones').style.display = 'flex';
            cargarUsuarios();
            volverAListaUsuarios();
        } else {
            document.querySelector('.chat-tab[data-tab="notificaciones"]').classList.add('active');
            document.getElementById('chatPanelNotificaciones').style.display = 'block';
            cargarNotificaciones();
        }
    }

    function volverAListaUsuarios() {
        contactoActivo = null;
        document.getElementById('vistaUsuarios').style.display = 'flex';
        document.getElementById('vistaChat').style.display = 'none';
        cargarUsuarios();
    }

    function abrirChatConUsuario(usuario) {
        contactoActivo = usuario.id;
        
        const inicial = usuario.nombre_completo.charAt(0).toUpperCase();
        document.getElementById('chatAvatar').textContent = inicial;
        document.getElementById('chatUserName').textContent = usuario.nombre_completo;
        document.getElementById('chatUserRole').textContent = usuario.tipo_usuario;
        
        const statusDot = document.getElementById('chatUserStatus');
        if (usuario.estado === 'online') {
            statusDot.className = 'chat-user-status online';
        } else {
            statusDot.className = 'chat-user-status offline';
        }
        
        document.getElementById('vistaUsuarios').style.display = 'none';
        document.getElementById('vistaChat').style.display = 'flex';
        
        cargarHistorial(usuario.id);
        marcarMensajesComoLeidos(usuario.id).then(() => verificarNuevosMensajes());
    }

    function cargarUsuarios() {
        fetch('includes/ajax_chat.php?action=obtener_contactos')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.contactos) {
                usuariosLista = data.contactos;
                renderizarUsuarios(data.contactos, data.no_leidos_por_contacto);
            }
        });
    }

    function renderizarUsuarios(usuarios, noLeidosPorContacto) {
        const container = document.getElementById('usersList');
        
        if (!usuarios || usuarios.length === 0) {
            container.innerHTML = `
                <div class="empty-chat" style="padding: 40px; text-align: center;">
                    <i class="fas fa-users"></i>
                    <p>No hay otros usuarios en el sistema</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        usuarios.forEach(usuario => {
            const noLeidos = noLeidosPorContacto ? (noLeidosPorContacto[usuario.id] || 0) : 0;
            const estadoClass = usuario.estado === 'online' ? 'online' : 'offline';
            const inicial = usuario.nombre_completo.charAt(0).toUpperCase();
            
            html += `
                <div class="chat-user-item" data-id="${usuario.id}" onclick='abrirChatConUsuario(${JSON.stringify(usuario)})'>
                    <div class="chat-user-avatar">${inicial}</div>
                    <div class="chat-user-info">
                        <div class="chat-user-name">${escapeHtml(usuario.nombre_completo)}</div>
                        <div class="chat-user-role">${escapeHtml(usuario.tipo_usuario)}</div>
                    </div>
                    <div class="chat-user-status ${estadoClass}"></div>
                    ${noLeidos > 0 ? `<span class="chat-user-badge">${noLeidos}</span>` : ''}
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    function cargarHistorial(contactoId) {
        fetch(`includes/ajax_chat.php?action=historial&con=${contactoId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.historial) {
                renderizarHistorial(data.historial);
            }
        });
    }

    function renderizarHistorial(mensajes) {
        const container = document.getElementById('chatMessagesList');
        
        if (!mensajes || mensajes.length === 0) {
            container.innerHTML = `
                <div class="empty-chat">
                    <i class="fas fa-comment-dots"></i>
                    <p>No hay mensajes aún. ¡Envía el primero!</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        mensajes.forEach(msg => {
            const esPropio = msg.es_propio;
            const hora = new Date(msg.fecha_envio).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const fecha = new Date(msg.fecha_envio).toLocaleDateString();
            
            html += `
                <div class="message-item ${esPropio ? 'message-sent' : 'message-received'}">
                    <div class="message-bubble">
                        ${!esPropio ? '<strong>' + escapeHtml(msg.remitente_nombre) + '</strong><br>' : ''}
                        ${escapeHtml(msg.mensaje)}
                    `;
            
            if (msg.archivo_ruta) {
                const esImagen = msg.archivo_tipo === 'imagen' || /\.(jpg|jpeg|png|gif|webp)$/i.test(msg.archivo_nombre);
                const esPdf = /\.pdf$/i.test(msg.archivo_nombre);
                
                if (esImagen) {
                    html += `
                        <div class="message-file" onclick="verImagen('${msg.archivo_ruta}')">
                            <i class="fas fa-image"></i>
                            <span>${escapeHtml(msg.archivo_nombre)}</span>
                            <i class="fas fa-expand" style="margin-left: auto;"></i>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="message-file">
                            <i class="fas ${esPdf ? 'fa-file-pdf' : 'fa-file-alt'}"></i>
                            <span>${escapeHtml(msg.archivo_nombre)}</span>
                        </div>
                        <div class="message-file-actions">
                            <button onclick="descargarArchivo('${msg.archivo_ruta}', '${msg.archivo_nombre}')">
                                <i class="fas fa-download"></i> Descargar
                            </button>
                            ${esPdf ? `<button onclick="verPdf('${msg.archivo_ruta}')"><i class="fas fa-eye"></i> Ver</button>` : ''}
                        </div>
                    `;
                }
            }
            
            html += `
                    </div>
                    <div class="message-time">${fecha} ${hora}</div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        container.scrollTop = container.scrollHeight;
    }

    function enviarMensaje() {
        if (!contactoActivo) return;
        
        const input = document.getElementById('chatInput');
        const mensaje = input.value.trim();
        
        if (!mensaje && !archivoAdjunto) return;
        
        const formData = new FormData();
        formData.append('action', 'enviar');
        formData.append('destinatario_id', contactoActivo);
        formData.append('mensaje', mensaje);
        if (archivoAdjunto) {
            formData.append('archivo', archivoAdjunto);
            formData.append('archivo_tipo', archivoTipo);
        }
        
        fetch('includes/ajax_chat.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                archivoAdjunto = null;
                archivoTipo = null;
                document.getElementById('chatFileInfo').innerHTML = '';
                cargarHistorial(contactoActivo);
                cargarUsuarios();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }

    function adjuntarArchivo(input, tipo) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                alert('El archivo no puede superar los 10MB');
                input.value = '';
                return;
            }
            archivoAdjunto = file;
            archivoTipo = tipo;
            document.getElementById('chatFileInfo').innerHTML = `<i class="fas fa-paperclip"></i> ${file.name}`;
        }
    }

    // Tipos de notificación que sí le corresponden a Almacén — la campana del header solo
    // muestra esto (a diferencia del panel de notificaciones del chat, que sigue mostrando
    // todo). Sin este filtro, una cuenta Admin (que recibe TODAS las notificaciones del
    // sistema) ve de todo ahí — aprobaciones de OT, FAT, etc. — y los links llevan a
    // pantallas de otros módulos que no pintan nada en Almacén.
    const ALMACEN_NOTIF_TIPOS = ['nueva_requisicion', 'material_nuevo', 'material_sin_verificar', 'mercancia_recibida', 'proyecto_almacen', 'stock_insuficiente', 'orden_generada', 'pieza_fabricada', 'equipo_fabricado_completado', 'reemplazo_material_pendiente', 'reemplazo_material_resuelto', 'orden_produccion_difundida', 'nuevo_proyecto', 'nuevo_boom', 'bom_finalizado', 'bom_modificado', 'cotizacion_enviada', 'inspeccion_rechazada', 'calidad_material_aprobado', 'calidad_material_rechazado'];

    function cargarNotificaciones(containerId) {
        containerId = containerId || 'notificacionesList';
        fetch('includes/ajax_chat.php?action=obtener_notificaciones')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.notificaciones) {
                const lista = containerId === 'notifHeaderList'
                    ? data.notificaciones.filter(n => ALMACEN_NOTIF_TIPOS.includes(n.tipo))
                    : data.notificaciones;
                renderizarNotificaciones(lista, containerId);
            }
        });
    }

    function marcarTodasLeidas() {
        fetch('includes/ajax_chat.php?action=marcar_todas_leidas')
        .then(r => r.json()).then(d => { if (d.success) { cargarNotificaciones('notificacionesList'); cargarNotificaciones('notifHeaderList'); actualizarBadgeNotifHeader(); } });
    }

    async function borrarNotificaciones() {
        const ok = await confirmarModal('¿Borrar todas las notificaciones?');
        if (!ok) return;
        fetch('includes/ajax_chat.php?action=borrar_notificaciones')
        .then(r => r.json()).then(d => { if (d.success) { cargarNotificaciones('notificacionesList'); cargarNotificaciones('notifHeaderList'); actualizarBadgeNotifHeader(); } });
    }

    function renderizarNotificaciones(notificaciones, containerId) {
        const container = document.getElementById(containerId || 'notificacionesList');
        
        if (!notificaciones || notificaciones.length === 0) {
            container.innerHTML = `
                <div class="empty-chat">
                    <i class="fas fa-bell-slash"></i>
                    <p>No hay notificaciones</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        notificaciones.forEach(notif => {
            const fecha = new Date(notif.fecha_creacion).toLocaleString();
            const iconos = {
                'nuevo_proyecto':    '<i class="fas fa-folder-plus"         style="color:#003d5c"></i>',
                'nuevo_boom':        '<i class="fas fa-layer-group"         style="color:#FF9800"></i>',
                'boom_guardado':     '<i class="fas fa-clipboard-check"     style="color:#4CAF50"></i>',
                'nueva_requisicion': '<i class="fas fa-file-alt"            style="color:#7b1fa2"></i>',
                'cotizacion_enviada':'<i class="fas fa-paper-plane"         style="color:#0097a7"></i>',
                'orden_generada':    '<i class="fas fa-truck"               style="color:#e64a19"></i>',
                'proyecto_almacen':  '<i class="fas fa-warehouse"           style="color:#ff5722"></i>',
                'mercancia_recibida':'<i class="fas fa-box-open"            style="color:#2e7d32"></i>',
                'stock_insuficiente':'<i class="fas fa-exclamation-triangle" style="color:#f44336"></i>',
                'material_nuevo':    '<i class="fas fa-cube"                style="color:#6a1b9a"></i>',
                'material_sin_verificar':'<i class="fas fa-question-circle" style="color:#f57c00"></i>',
                'reemplazo_material_pendiente':'<i class="fas fa-exchange-alt" style="color:#f57c00"></i>',
                'reemplazo_material_resuelto':'<i class="fas fa-check-double" style="color:#2e7d32"></i>',
                'pieza_fabricada':   '<i class="fas fa-cog"                 style="color:#455a64"></i>',
                'equipo_fabricado_completado': '<i class="fas fa-microchip" style="color:#00695c"></i>',
                'orden_produccion_difundida': '<i class="fas fa-broadcast-tower" style="color:#003d5c"></i>',
                'bom_finalizado':    '<i class="fas fa-clipboard-check"     style="color:#2e7d32"></i>',
                'bom_modificado':    '<i class="fas fa-unlock"              style="color:#f57c00"></i>',
                'inspeccion_rechazada':'<i class="fas fa-ban"               style="color:#c62828"></i>',
                'calidad_material_aprobado': '<i class="fas fa-check-circle" style="color:#2e7d32"></i>',
                'calidad_material_rechazado':'<i class="fas fa-vial"        style="color:#c62828"></i>',
            };
            const icono = iconos[notif.tipo] || '<i class="fas fa-bell" style="color:#999"></i>';
            const leido = notif.leido == 1;
            html += `
                <div class="notification-item${leido ? '' : ' notif-unread'}" data-id="${Number(notif.id)}" data-link="${escapeHtml(notif.link || '')}">
                    <div class="notification-title">${icono} ${escapeHtml(notif.titulo)}</div>
                    <div class="notification-message">${escapeHtml(notif.mensaje)}</div>
                    <div class="notification-time">${fecha}</div>
                </div>
            `;
        });

        container.innerHTML = html;
        container.querySelectorAll('.notification-item').forEach(el => {
            el.addEventListener('click', () => marcarNotificacion(el.dataset.id, el.dataset.link));
        });
    }

    function marcarNotificacion(id, link) {
        fetch('includes/ajax_chat.php?action=marcar_notificacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`
        })
        .then(() => {
            if (link) {
                window.location.href = link;
            } else {
                cargarNotificaciones('notificacionesList');
                cargarNotificaciones('notifHeaderList');
                actualizarBadgeNotifHeader();
            }
        });
    }

    // Marca como leídas SOLO las notificaciones de Almacén (no toca las de otros módulos,
    // a diferencia de "marcar_todas_leidas" que es global para toda la cuenta).
    async function marcarTodasLeidasAlmacen() {
        const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
        if (!res.success) return;
        const idsAlmacen = res.notificaciones.filter(n => ALMACEN_NOTIF_TIPOS.includes(n.tipo) && n.leido == 0).map(n => n.id);
        await Promise.all(idsAlmacen.map(id => fetch('includes/ajax_chat.php?action=marcar_notificacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`
        })));
        cargarNotificaciones('notifHeaderList');
        actualizarBadgeNotifHeader();
    }

    // Borra (no solo marca) las notificaciones de Almacén ya mostradas en la campana —
    // evita que la tabla notificaciones crezca indefinidamente. Solo afecta este mismo
    // scope de tipos, igual que "Marcar leído".
    async function borrarNotificacionesAlmacen() {
        const ok = await confirmarModal('¿Borrar todas las notificaciones de Almacén? Esta acción no se puede deshacer.');
        if (!ok) return;
        const res = await fetch('includes/ajax_chat.php?action=obtener_notificaciones').then(r => r.json());
        if (!res.success) return;
        const idsAlmacen = res.notificaciones.filter(n => ALMACEN_NOTIF_TIPOS.includes(n.tipo)).map(n => n.id);
        await Promise.all(idsAlmacen.map(id => fetch('includes/ajax_chat.php?action=borrar_notificacion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`
        })));
        cargarNotificaciones('notifHeaderList');
        actualizarBadgeNotifHeader();
    }

    // ── Campana de notificaciones en el header (mismo origen de datos que el
    // panel de notificaciones del chat — solo un lugar más visible para verlas) ──
    let _notifHeaderAbierta = false;
    function toggleNotifHeaderDropdown() {
        _notifHeaderAbierta = !_notifHeaderAbierta;
        const dd = document.getElementById('notifHeaderDropdown');
        dd.style.display = _notifHeaderAbierta ? 'block' : 'none';
        if (_notifHeaderAbierta) cargarNotificaciones('notifHeaderList');
    }
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('notifHeaderDropdown');
        const btn = document.getElementById('notifBellBtn');
        if (_notifHeaderAbierta && dd && !dd.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
            _notifHeaderAbierta = false;
            dd.style.display = 'none';
        }
    });
    function actualizarBadgeNotifHeader() {
        fetch('includes/ajax_chat.php?action=obtener_notificaciones')
        .then(r => r.json())
        .then(data => {
            const badge = document.getElementById('notifBellBadge');
            if (!badge || !data.success) return;
            const noLeidas = (data.notificaciones || []).filter(n => n.leido == 0 && ALMACEN_NOTIF_TIPOS.includes(n.tipo)).length;
            if (noLeidas > 0) {
                badge.textContent = noLeidas > 99 ? '99+' : noLeidas;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        });
    }

    function marcarMensajesComoLeidos(contactoId) {
        if (!contactoId) return Promise.resolve();
        return fetch(`includes/ajax_chat.php?action=marcar_leidos&contacto=${contactoId}`);
    }

    function handleChatKeyPress(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            enviarMensaje();
        }
    }

    const chatInputField = document.getElementById('chatInput');
    if (chatInputField) {
        chatInputField.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }

    function verImagen(ruta) {
        const modal = document.getElementById('imagePreviewModal');
        const img = document.getElementById('previewImage');
        img.src = ruta;
        modal.classList.add('active');
    }

    function cerrarPreviewImagen(event) {
        if (!event || event.target === document.getElementById('imagePreviewModal') || event.target.classList.contains('close-preview')) {
            document.getElementById('imagePreviewModal').classList.remove('active');
            document.getElementById('previewImage').src = '';
        }
    }

    function verPdf(ruta) {
        const modal = document.getElementById('filePreviewModal');
        const iframe = document.getElementById('filePreviewFrame');
        iframe.src = ruta;
        modal.classList.add('active');
    }

    function cerrarPreviewArchivo() {
        const modal = document.getElementById('filePreviewModal');
        const iframe = document.getElementById('filePreviewFrame');
        iframe.src = '';
        modal.classList.remove('active');
    }

    function descargarArchivo(ruta, nombre) {
        const link = document.createElement('a');
        link.href = ruta;
        link.download = nombre;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        // innerHTML solo escapa &/</> (seguro para contenido de texto) — las comillas se
        // codifican aparte para que este mismo helper también sea seguro dentro de atributos
        // (ej. data-link="${escapeHtml(...)}"), donde una comilla sin escapar rompería el atributo.
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    document.addEventListener('DOMContentLoaded', function() {
        iniciarPollingChat();
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarPreviewImagen();
                cerrarPreviewArchivo();
            }
        });
        
        window.addEventListener('beforeunload', function() {
            fetch('includes/ajax_chat.php?action=actualizar_estado&estado=offline', { keepalive: true });
        });
    });

    // ── Helpers de modal ────────────────────────────────────────────────────
    function mostrarModalAlerta(tipo, titulo, mensaje, onAceptar) {
        const modal = document.getElementById('modalAlertaGenerico');
        if (!modal) { alert((titulo ? titulo + ': ' : '') + mensaje); if (onAceptar) onAceptar(); return; }
        const icon  = tipo === 'error' ? 'fa-times-circle' : (tipo === 'success' ? 'fa-check-circle' : 'fa-info-circle');
        const color = tipo === 'error' ? '#c62828' : (tipo === 'success' ? '#2e7d32' : '#1565c0');
        modal.querySelector('.modal-alerta-titulo').innerHTML = `<i class="fas ${icon}" style="color:${color}"></i> ${titulo}`;
        modal.querySelector('.modal-alerta-mensaje').innerHTML = mensaje.replace(/\n/g, '<br>');
        modal.classList.add('active');
        if (onAceptar) {
            modal.querySelector('.modal-footer .btn-primary').addEventListener('click', onAceptar, { once: true });
        }
    }
    function confirmarModal(mensaje) {
        return new Promise(resolve => {
            const modal = document.getElementById('modalConfirmarGenerico');
            if (!modal) { resolve(confirm(mensaje)); return; }
            document.getElementById('modalConfirmarMensaje').textContent = mensaje;
            modal.classList.add('active');
            const si = document.getElementById('btnConfirmarSi');
            const no = document.getElementById('btnConfirmarNo');
            const cleanup = (val) => { modal.classList.remove('active'); si.onclick = null; no.onclick = null; resolve(val); };
            si.onclick = () => cleanup(true);
            no.onclick = () => cleanup(false);
        });
    }

    // ── Confirmar recepción de pedido ────────────────────────────────────────
    async function confirmarRecepcion(proyectoId, completo) {
        const msg = completo
            ? '¿Confirmar que TODOS los productos llegaron completos?\nEl proyecto se marcará como completado.'
            : '¿Registrar una recepción PARCIAL o con faltantes?\nEl proyecto quedará en estado "Recepción parcial" hasta confirmación total.';
        const ok = await confirmarModal(msg);
        if (!ok) return;

        const fd = new FormData();
        fd.append('action', 'confirmar_recepcion');
        fd.append('proyecto_id', proyectoId);
        fd.append('completo', completo);

        try {
            const r = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
            const txt = await r.text();
            let res;
            try { res = JSON.parse(txt); } catch(e) { mostrarModalAlerta('error', 'Error del servidor', txt.substring(0, 300)); return; }

            if (res.success) {
                mostrarModalAlerta('success', 'Recepción confirmada', res.message, () => window.location.reload());
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
            }
        } catch(e) {
            mostrarModalAlerta('error', 'Error de conexión', e.message);
        }
    }

    let _recepParcialProyId = 0;
    let _recepCompletaProyId = 0;

    function abrirModalRecepcionParcial(proyectoId, proyNombre) {
        document.getElementById('btnGuardarRecepcionParcial').onclick = guardarRecepcionParcial;
        _recepParcialProyId = proyectoId;
        const data = (window._recepcionData || {})[proyectoId] || [];
        document.getElementById('recepParcialTitulo').textContent = proyNombre;
        document.getElementById('recepParcialNotas').value = '';
        const container = document.getElementById('recepParcialItems');
        container.innerHTML = '';

        if (!data.length) {
            container.innerHTML = '<p style="color:#999;padding:12px;">No hay ítems registrados en las órdenes de compra.</p>';
        } else {
            data.forEach(oc => {
                const ocHeader = document.createElement('div');
                ocHeader.style.cssText = 'background:#f0f4f8;padding:8px 12px;border-radius:4px;margin-bottom:6px;font-size:13px;font-weight:600;color:#003d5c;';
                ocHeader.innerHTML = `<i class="fas fa-file-invoice"></i> ${oc.folio} — ${oc.proveedor}`;
                container.appendChild(ocHeader);

                const table = document.createElement('table');
                table.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px;';
                table.innerHTML = `
                    <thead>
                        <tr style="background:#f5f5f5;">
                            <th style="padding:7px 8px;text-align:left;">Descripción</th>
                            <th style="padding:7px 8px;text-align:center;white-space:nowrap;">Cant. ordenada</th>
                            <th style="padding:7px 8px;text-align:center;white-space:nowrap;">Cant. recibida</th>
                            <th style="padding:7px 8px;text-align:center;white-space:nowrap;">No. de serie</th>
                            <th style="padding:7px 8px;text-align:center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody></tbody>`;
                const tbody = table.querySelector('tbody');

                oc.items.forEach((item, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid #f0f0f0';
                    const uid = `rp_${oc.oc_id}_${idx}`;
                    tr.innerHTML = `
                        <td style="padding:7px 8px;" data-desc="${item.descripcion.replace(/"/g,'&quot;')}">${item.descripcion}</td>
                        <td style="padding:7px 8px;text-align:center;">${item.cantidad} ${item.unidad}</td>
                        <td style="padding:7px 8px;text-align:center;">
                            <input type="number" id="${uid}" min="0" max="${item.cantidad}" step="1"
                                value="${item.cantidad}"
                                data-ordenada="${item.cantidad}" data-unidad="${item.unidad}" data-matid="${item.material_id ?? ''}"
                                style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:13px;text-align:center;"
                                oninput="actualizarEstadoItem('${uid}')">
                        </td>
                        <td style="padding:7px 8px;text-align:center;">
                            <input type="text" id="${uid}_serie" placeholder="Opcional"
                                style="width:110px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:13px;text-align:center;">
                        </td>
                        <td style="padding:7px 8px;text-align:center;" id="${uid}_estado">
                            <span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#e8f5e9;color:#2e7d32;">Completo</span>
                        </td>`;
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                container.appendChild(table);
            });
        }

        document.getElementById('modalRecepcionParcial').classList.add('active');
    }

    function actualizarEstadoItem(uid) {
        const inp = document.getElementById(uid);
        const estadoCell = document.getElementById(uid + '_estado');
        if (!inp || !estadoCell) return;
        const recibida = parseFloat(inp.value) || 0;
        const ordenada = parseFloat(inp.dataset.ordenada) || 0;
        let html;
        if (recibida <= 0) {
            html = '<span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#ffebee;color:#c62828;">Faltante</span>';
        } else if (recibida < ordenada) {
            html = `<span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#fff3e0;color:#e65100;">Parcial (${recibida}/${ordenada})</span>`;
        } else {
            html = '<span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#e8f5e9;color:#2e7d32;">Completo</span>';
        }
        estadoCell.innerHTML = html;
    }

    async function guardarRecepcionParcial() {
        const proyectoId = _recepParcialProyId;
        const notas = document.getElementById('recepParcialNotas').value;
        const data = (window._recepcionData || {})[proyectoId] || [];

        const detalleItems = [];
        let todasCompletas = true;
        let algunaRecibida = false;
        const provsIncompletos = new Set();

        data.forEach(oc => {
            oc.items.forEach((item, idx) => {
                const uid = `rp_${oc.oc_id}_${idx}`;
                const inp = document.getElementById(uid);
                const serieInp = document.getElementById(uid + '_serie');
                const recibida = inp ? (parseFloat(inp.value) || 0) : item.cantidad;
                const ordenada = item.cantidad;
                if (recibida < ordenada) {
                    todasCompletas = false;
                    if (oc.proveedor_id) provsIncompletos.add(oc.proveedor_id);
                }
                if (recibida > 0) algunaRecibida = true;
                detalleItems.push({
                    oc_id: oc.oc_id,
                    folio: oc.folio,
                    proveedor: oc.proveedor,
                    proveedor_id: oc.proveedor_id,
                    descripcion: item.descripcion,
                    cantidad_ordenada: ordenada,
                    cantidad_recibida: recibida,
                    unidad: item.unidad,
                    material_id: item.material_id,
                    numero_serie: serieInp ? serieInp.value.trim() : '',
                    estado: recibida <= 0 ? 'faltante' : (recibida < ordenada ? 'parcial' : 'completo')
                });
            });
        });

        if (!algunaRecibida) {
            mostrarModalAlerta('error', 'Sin datos', 'Debes registrar al menos una pieza recibida.');
            return;
        }

        document.getElementById('modalRecepcionParcial').classList.remove('active');

        const fd = new FormData();
        fd.append('action', 'confirmar_recepcion');
        fd.append('proyecto_id', proyectoId);
        fd.append('completo', todasCompletas ? 1 : 0);
        fd.append('items_detalle', JSON.stringify(detalleItems));
        fd.append('notas_recepcion', notas);
        const incidencias = [];
        if (document.getElementById('inc_llegada_tardia')?.checked) incidencias.push('llegada_tardia');
        if (document.getElementById('inc_mal_estado')?.checked) incidencias.push('mal_estado');
        if (document.getElementById('inc_otro')?.checked) incidencias.push('otro');
        fd.append('incidencias', JSON.stringify(incidencias));
        // Con qué proveedor(es) quedó incompleta la entrega -- el backend decide si bloquear
        // y regenerar requisición según incidencias/estado, esto solo le dice a quién.
        fd.append('proveedores_incompletos', JSON.stringify(Array.from(provsIncompletos)));

        try {
            const r = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
            const txt = await r.text();
            let res;
            try { res = JSON.parse(txt); } catch(e) { mostrarModalAlerta('error', 'Error del servidor', txt.substring(0,300)); return; }
            if (res.success) {
                mostrarModalAlerta('success', 'Recepción registrada', res.message, () => window.location.reload());
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
            }
        } catch(e) {
            mostrarModalAlerta('error', 'Error de conexión', e.message);
        }
    }

    function abrirModalRecepcionCompleta(proyectoId, proyNombre) {
        document.getElementById('btnConfirmarRecepcionCompleta').onclick = confirmarRecepcionCompleta;
        _recepCompletaProyId = proyectoId;
        document.getElementById('recepCompletaTitulo').textContent = proyNombre;
        document.getElementById('recepCompletaNotas').value = '';
        document.getElementById('cinc_llegada_tardia').checked = false;
        document.getElementById('cinc_mal_estado').checked = false;
        document.getElementById('cinc_otro').checked = false;
        document.getElementById('modalRecepcionCompleta').classList.add('active');
    }

    async function confirmarRecepcionCompleta() {
        document.getElementById('modalRecepcionCompleta').classList.remove('active');
        const notas = document.getElementById('recepCompletaNotas').value;
        const incidencias = [];
        if (document.getElementById('cinc_llegada_tardia').checked) incidencias.push('llegada_tardia');
        if (document.getElementById('cinc_mal_estado').checked) incidencias.push('mal_estado');
        if (document.getElementById('cinc_otro').checked) incidencias.push('otro');

        // Todo se recibió completo (por eso este botón, no el de parcial) -- pero igual hay
        // que mandar items_detalle con material_id, si no la recepción nunca sube el stock
        // real del catálogo (antes este botón no mandaba nada de esto).
        const data = (window._recepcionData || {})[_recepCompletaProyId] || [];
        const detalleItems = [];
        data.forEach(oc => {
            oc.items.forEach(item => {
                detalleItems.push({
                    oc_id: oc.oc_id, folio: oc.folio, proveedor: oc.proveedor, proveedor_id: oc.proveedor_id,
                    descripcion: item.descripcion, cantidad_ordenada: item.cantidad, cantidad_recibida: item.cantidad,
                    unidad: item.unidad, material_id: item.material_id, estado: 'completo'
                });
            });
        });

        // Si de todos modos marcó mal estado / otro problema en una recepción "completa",
        // no hay detalle por ítem para saber cuál proveedor tuvo la falla (este modal es
        // todo-o-nada) -- se manda la lista completa de proveedores de esta recepción y el
        // backend decide si vale la pena bloquear con esa incidencia.
        const provsTodos = Array.from(new Set(data.map(oc => oc.proveedor_id).filter(Boolean)));

        const fd = new FormData();
        fd.append('action', 'confirmar_recepcion');
        fd.append('proyecto_id', _recepCompletaProyId);
        fd.append('completo', 1);
        fd.append('items_detalle', JSON.stringify(detalleItems));
        fd.append('notas_recepcion', notas);
        fd.append('incidencias', JSON.stringify(incidencias));
        fd.append('proveedores_incompletos', JSON.stringify(provsTodos));
        try {
            const r = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
            const txt = await r.text();
            let res;
            try { res = JSON.parse(txt); } catch(e) { mostrarModalAlerta('error', 'Error del servidor', txt.substring(0,300)); return; }
            if (res.success) {
                mostrarModalAlerta('success', 'Recepción confirmada', res.message, () => window.location.reload());
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
            }
        } catch(e) {
            mostrarModalAlerta('error', 'Error de conexión', e.message);
        }
    }

    // ── Recepción de OCs de requisiciones sin proyecto (misma UI que proyectos,
    // pero llave por requisicion_id y accion confirmar_recepcion_req) ──────────
    let _recepParcialReqId = 0;
    let _recepCompletaReqId = 0;

    function abrirModalRecepcionParcialReq(reqId, folio) {
        document.getElementById('btnGuardarRecepcionParcial').onclick = guardarRecepcionParcialReq;
        _recepParcialReqId = reqId;
        const data = (window._recepcionDataReq || {})[reqId] || [];
        document.getElementById('recepParcialTitulo').textContent = folio;
        document.getElementById('recepParcialNotas').value = '';
        const container = document.getElementById('recepParcialItems');
        container.innerHTML = '';

        if (!data.length) {
            container.innerHTML = '<p style="color:#999;padding:12px;">No hay ítems registrados en las órdenes de compra.</p>';
        } else {
            data.forEach(oc => {
                const ocHeader = document.createElement('div');
                ocHeader.style.cssText = 'background:#f0f4f8;padding:8px 12px;border-radius:4px;margin-bottom:6px;font-size:13px;font-weight:600;color:#003d5c;';
                ocHeader.innerHTML = `<i class="fas fa-file-invoice"></i> ${oc.folio} — ${oc.proveedor}`;
                container.appendChild(ocHeader);

                const table = document.createElement('table');
                table.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px;';
                table.innerHTML = `
                    <thead>
                        <tr style="background:#f5f5f5;">
                            <th style="padding:7px 8px;text-align:left;">Descripción</th>
                            <th style="padding:7px 8px;text-align:center;white-space:nowrap;">Cant. ordenada</th>
                            <th style="padding:7px 8px;text-align:center;white-space:nowrap;">Cant. recibida</th>
                            <th style="padding:7px 8px;text-align:center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody></tbody>`;
                const tbody = table.querySelector('tbody');

                oc.items.forEach((item, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid #f0f0f0';
                    const uid = `rpr_${oc.oc_id}_${idx}`;
                    tr.innerHTML = `
                        <td style="padding:7px 8px;">${item.descripcion}</td>
                        <td style="padding:7px 8px;text-align:center;">${item.cantidad} ${item.unidad}</td>
                        <td style="padding:7px 8px;text-align:center;">
                            <input type="number" id="${uid}" min="0" max="${item.cantidad}" step="1"
                                value="${item.cantidad}"
                                data-ordenada="${item.cantidad}" data-unidad="${item.unidad}" data-matid="${item.material_id ?? ''}"
                                style="width:80px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:13px;text-align:center;"
                                oninput="actualizarEstadoItem('${uid}')" ${item.material_id ? '' : 'disabled title="Sin material de catálogo — no se puede recibir automáticamente"'}>
                        </td>
                        <td style="padding:7px 8px;text-align:center;" id="${uid}_estado">
                            <span style="padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#e8f5e9;color:#2e7d32;">Completo</span>
                        </td>`;
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                container.appendChild(table);
            });
        }

        document.getElementById('modalRecepcionParcial').classList.add('active');
    }

    async function guardarRecepcionParcialReq() {
        const reqId = _recepParcialReqId;
        const notas = document.getElementById('recepParcialNotas').value;
        const data = (window._recepcionDataReq || {})[reqId] || [];

        const detalleItems = [];
        let todasCompletas = true;
        let algunaRecibida = false;
        let ocIdPrincipal = 0;

        data.forEach(oc => {
            if (!ocIdPrincipal) ocIdPrincipal = oc.oc_id;
            oc.items.forEach((item, idx) => {
                if (!item.material_id) return;
                const uid = `rpr_${oc.oc_id}_${idx}`;
                const inp = document.getElementById(uid);
                const recibida = inp ? (parseFloat(inp.value) || 0) : 0;
                const ordenada = item.cantidad;
                if (recibida < ordenada) todasCompletas = false;
                if (recibida > 0) algunaRecibida = true;
                detalleItems.push({ material_id: item.material_id, cantidad_recibida: recibida });
            });
        });

        if (!algunaRecibida) {
            mostrarModalAlerta('error', 'Sin datos', 'Debes registrar al menos una pieza recibida.');
            return;
        }

        document.getElementById('modalRecepcionParcial').classList.remove('active');

        const fd = new FormData();
        fd.append('action', 'confirmar_recepcion_req');
        fd.append('requisicion_id', reqId);
        fd.append('orden_id', ocIdPrincipal);
        fd.append('completo', todasCompletas ? 1 : 0);
        fd.append('items_detalle', JSON.stringify(detalleItems));
        fd.append('notas_recepcion', notas);

        try {
            const r = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
            const txt = await r.text();
            let res;
            try { res = JSON.parse(txt); } catch(e) { mostrarModalAlerta('error', 'Error del servidor', txt.substring(0,300)); return; }
            if (res.success) {
                mostrarModalAlerta('success', 'Recepción registrada', 'Recepción registrada correctamente.', () => window.location.reload());
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
            }
        } catch(e) {
            mostrarModalAlerta('error', 'Error de conexión', e.message);
        }
    }

    function abrirModalRecepcionCompletaReq(reqId, folio) {
        document.getElementById('btnConfirmarRecepcionCompleta').onclick = confirmarRecepcionCompletaReq;
        _recepCompletaReqId = reqId;
        document.getElementById('recepCompletaTitulo').textContent = folio;
        document.getElementById('recepCompletaNotas').value = '';
        document.getElementById('cinc_llegada_tardia').checked = false;
        document.getElementById('cinc_mal_estado').checked = false;
        document.getElementById('cinc_otro').checked = false;
        document.getElementById('modalRecepcionCompleta').classList.add('active');
    }

    async function confirmarRecepcionCompletaReq() {
        document.getElementById('modalRecepcionCompleta').classList.remove('active');
        const notas = document.getElementById('recepCompletaNotas').value;
        const reqId = _recepCompletaReqId;
        const data = (window._recepcionDataReq || {})[reqId] || [];

        const detalleItems = [];
        let ocIdPrincipal = 0;
        data.forEach(oc => {
            if (!ocIdPrincipal) ocIdPrincipal = oc.oc_id;
            oc.items.forEach(item => {
                if (!item.material_id) return;
                detalleItems.push({ material_id: item.material_id, cantidad_recibida: item.cantidad });
            });
        });

        const fd = new FormData();
        fd.append('action', 'confirmar_recepcion_req');
        fd.append('requisicion_id', reqId);
        fd.append('orden_id', ocIdPrincipal);
        fd.append('completo', 1);
        fd.append('items_detalle', JSON.stringify(detalleItems));
        fd.append('notas_recepcion', notas);
        try {
            const r = await fetch('includes/solicitar_cotizacion.php', { method: 'POST', body: fd });
            const txt = await r.text();
            let res;
            try { res = JSON.parse(txt); } catch(e) { mostrarModalAlerta('error', 'Error del servidor', txt.substring(0,300)); return; }
            if (res.success) {
                mostrarModalAlerta('success', 'Recepción confirmada', 'Recepción confirmada correctamente.', () => window.location.reload());
            } else {
                mostrarModalAlerta('error', 'Error', res.error || 'Error desconocido');
            }
        } catch(e) {
            mostrarModalAlerta('error', 'Error de conexión', e.message);
        }
    }
    </script>
    <!-- ===== CHAT BUBBLE CON ESTADO ===== -->
    <div class="chat-bubble">
        <div class="chat-button" onclick="toggleChat()">
            <i class="fas fa-comment-dots"></i>
            <span class="chat-status" id="chatStatusDot"></span>
            <span class="notification-badge" id="chatNotificationBadge" style="display: none;">0</span>
        </div>
    </div>

    <!-- ===== CHAT WINDOW ===== -->
    <div class="chat-window" id="chatWindow">
        <div class="chat-header">
            <h4><i class="fas fa-comments"></i> Mensajes</h4>
            <div class="chat-header-actions">
                <button onclick="minimizeChat()"><i class="fas fa-minus"></i></button>
                <button onclick="closeChat()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        
        <div class="chat-tabs">
            <div class="chat-tab active" data-tab="conversaciones" onclick="switchChatTab('conversaciones')">
                <i class="fas fa-users"></i> Conversaciones
            </div>
            <div class="chat-tab" data-tab="notificaciones" onclick="switchChatTab('notificaciones')">
                <i class="fas fa-bell"></i> Notificaciones
            </div>
        </div>
        
        <!-- Panel de Conversaciones -->
        <div id="chatPanelConversaciones" class="chat-panel">
            
            <!-- VISTA DE USUARIOS (lista de contactos) -->
            <div id="vistaUsuarios" class="chat-view">
                <div class="chat-view-header">
                    <span class="chat-view-title"><i class="fas fa-users"></i> Usuarios activos</span>
                </div>
                <div class="chat-users-list" id="usersList">
                    <div class="empty-chat">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Cargando usuarios...</p>
                    </div>
                </div>
            </div>
            
            <!-- VISTA DE CHAT (conversación con un usuario) -->
            <div id="vistaChat" class="chat-conversation" style="display: none;">
                <div class="chat-conversation-header">
                    <button class="btn-back-chat" onclick="volverAListaUsuarios()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div class="chat-user-avatar" id="chatAvatar">U</div>
                    <div class="chat-user-info">
                        <div class="chat-user-name" id="chatUserName">Cargando...</div>
                        <div class="chat-user-role" id="chatUserRole"></div>
                    </div>
                    <div class="chat-user-status" id="chatUserStatus"></div>
                </div>
                <div class="chat-messages" id="chatMessagesList">
                    <div class="empty-chat">
                        <i class="fas fa-comment-dots"></i>
                        <p>No hay mensajes aún</p>
                    </div>
                </div>
                <div class="chat-input-area">
                    <div class="chat-input-row">
                        <textarea id="chatInput" placeholder="Escribe un mensaje..." rows="1" onkeypress="handleChatKeyPress(event)"></textarea>
                        <button onclick="enviarMensaje()"><i class="fas fa-paper-plane"></i></button>
                    </div>
                    <div class="chat-file-buttons">
                        <button class="chat-file-btn" onclick="document.getElementById('chatFileInput').click()" title="Adjuntar archivo">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <button class="chat-file-btn" onclick="document.getElementById('chatImageInput').click()" title="Adjuntar imagen">
                            <i class="fas fa-image"></i>
                        </button>
                        <input type="file" id="chatFileInput" style="display: none;" accept=".pdf,.xls,.xlsx,.doc,.docx,.txt" onchange="adjuntarArchivo(this, 'archivo')">
                        <input type="file" id="chatImageInput" style="display: none;" accept="image/*" onchange="adjuntarArchivo(this, 'imagen')">
                        <span id="chatFileInfo" style="font-size: 11px; color: #666;"></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Panel de Notificaciones -->
        <div id="chatPanelNotificaciones" class="chat-panel" style="display: none; overflow-y: auto; padding: 15px;">
            <div style="display:flex;gap:8px;margin-bottom:10px;">
                <button onclick="marcarTodasLeidas()" class="btn btn-sm" style="flex:1;background:#e3f2fd;color:#1565c0;border:none;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;">
                    <i class="fas fa-check-double"></i> Marcar todo leído
                </button>
                <button onclick="borrarNotificaciones()" class="btn btn-sm" style="flex:1;background:#fce4ec;color:#c62828;border:none;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;">
                    <i class="fas fa-trash"></i> Borrar todo
                </button>
            </div>
            <div id="notificacionesList">
                <div class="empty-chat">
                    <i class="fas fa-bell-slash"></i>
                    <p>No hay notificaciones</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modales para vista previa de archivos -->
    <div class="image-preview-modal" id="imagePreviewModal" onclick="cerrarPreviewImagen(event)">
        <span class="close-preview" onclick="cerrarPreviewImagen()">&times;</span>
        <img id="previewImage" src="">
    </div>
    <div class="file-preview-modal" id="filePreviewModal">
        <div class="file-preview-header">
            <span><i class="fas fa-file-alt"></i> Vista previa</span>
            <button onclick="cerrarPreviewArchivo()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        <div class="file-preview-body">
            <iframe id="filePreviewFrame" src=""></iframe>
        </div>
    </div>

    <!-- ===== MODAL ALERTA GENÉRICO ===== -->
    <div class="modal-overlay" id="modalAlertaGenerico" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="modal" style="max-width:440px">
            <div class="modal-header"><h3 class="modal-alerta-titulo" style="margin:0;font-size:1rem"><i class="fas fa-info-circle"></i> Aviso</h3>
                <button onclick="document.getElementById('modalAlertaGenerico').classList.remove('active')" style="background:none;border:none;color:white;font-size:20px;cursor:pointer;line-height:1">&times;</button></div>
            <div class="modal-body"><p class="modal-alerta-mensaje" style="margin:0;white-space:pre-wrap;line-height:1.6"></p></div>
            <div class="modal-footer"><button class="btn btn-primary" onclick="document.getElementById('modalAlertaGenerico').classList.remove('active')">Aceptar</button></div>
        </div>
    </div>

    <!-- ===== MODAL RECEPCIÓN COMPLETA ===== -->
    <div class="modal-overlay" id="modalRecepcionCompleta" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="modal" style="max-width:480px;width:95%;">
            <div class="modal-header" style="background:#2e7d32;">
                <div class="modal-title"><i class="fas fa-check-double"></i> Recepción completa — <span id="recepCompletaTitulo" style="font-weight:400;"></span></div>
                <button class="modal-close" onclick="document.getElementById('modalRecepcionCompleta').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:#555;margin-bottom:14px;">Se confirmará que todos los productos llegaron en la cantidad ordenada. ¿Hubo algún problema adicional?</p>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;border:1px solid #ddd;border-radius:6px;user-select:none;">
                        <input type="checkbox" id="cinc_llegada_tardia" style="width:15px;height:15px;"> <i class="fas fa-clock" style="color:#f57c00;"></i> Llegada tardía
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;border:1px solid #ddd;border-radius:6px;user-select:none;">
                        <input type="checkbox" id="cinc_mal_estado" style="width:15px;height:15px;"> <i class="fas fa-times-circle" style="color:#c62828;"></i> Productos en mal estado
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;border:1px solid #ddd;border-radius:6px;user-select:none;">
                        <input type="checkbox" id="cinc_otro" style="width:15px;height:15px;"> <i class="fas fa-comment" style="color:#555;"></i> Otro
                    </label>
                </div>
                <div>
                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">Descripción del problema (si aplica):</label>
                    <textarea id="recepCompletaNotas" rows="2" style="width:100%;border:1px solid #ddd;border-radius:4px;padding:8px;font-size:13px;" placeholder="Observaciones..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="document.getElementById('modalRecepcionCompleta').classList.remove('active')">Cancelar</button>
                <button class="btn btn-success" id="btnConfirmarRecepcionCompleta" onclick="confirmarRecepcionCompleta()"><i class="fas fa-check-double"></i> Confirmar recepción</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL RECEPCIÓN PARCIAL ===== -->
    <div class="modal-overlay" id="modalRecepcionParcial" onclick="if(event.target===this)this.classList.remove('active')">
        <div class="modal" style="max-width:700px;width:95%;max-height:90vh;display:flex;flex-direction:column;">
            <div class="modal-header" style="background:#f57c00;">
                <div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Recepción parcial — <span id="recepParcialTitulo" style="font-weight:400;"></span></div>
                <button class="modal-close" onclick="document.getElementById('modalRecepcionParcial').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body" style="overflow-y:auto;flex:1;">
                <p style="font-size:13px;color:#666;margin-bottom:14px;">Indica cuántas piezas llegaron de cada producto. Si un artículo no llegó, pon <strong>0</strong>.</p>
                <div id="recepParcialItems"></div>
                <div style="margin-top:12px;">
                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">Notas / Observaciones:</label>
                    <textarea id="recepParcialNotas" rows="2" style="width:100%;border:1px solid #ddd;border-radius:4px;padding:8px;font-size:13px;" placeholder="Ej: Faltaron 5 válvulas, se esperan la próxima semana..."></textarea>
                </div>
            <div style="border-top:1px solid #eee;margin-top:14px;padding-top:14px;">
                <div style="font-size:13px;font-weight:600;color:#555;margin-bottom:8px;"><i class="fas fa-exclamation-circle" style="color:#f57c00;"></i> Reportar problemas (opcional):</div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;border:1px solid #ddd;border-radius:6px;user-select:none;">
                        <input type="checkbox" id="inc_llegada_tardia" style="width:15px;height:15px;"> <i class="fas fa-clock" style="color:#f57c00;"></i> Llegada tardía
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;border:1px solid #ddd;border-radius:6px;user-select:none;">
                        <input type="checkbox" id="inc_mal_estado" style="width:15px;height:15px;"> <i class="fas fa-times-circle" style="color:#c62828;"></i> Productos en mal estado
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:6px 12px;border:1px solid #ddd;border-radius:6px;user-select:none;">
                        <input type="checkbox" id="inc_otro" style="width:15px;height:15px;"> <i class="fas fa-comment" style="color:#555;"></i> Otro
                    </label>
                </div>
            </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="document.getElementById('modalRecepcionParcial').classList.remove('active')">Cancelar</button>
                <button class="btn btn-warning" id="btnGuardarRecepcionParcial" onclick="guardarRecepcionParcial()"><i class="fas fa-save"></i> Registrar recepción</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL CONFIRMAR GENÉRICO ===== -->
    <div class="modal-overlay" id="modalConfirmarGenerico">
        <div class="modal" style="max-width:440px">
            <div class="modal-header"><h3 style="margin:0;font-size:1rem"><i class="fas fa-question-circle"></i> Confirmar</h3></div>
            <div class="modal-body"><p id="modalConfirmarMensaje" style="margin:0;line-height:1.6"></p></div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="btnConfirmarNo">Cancelar</button>
                <button class="btn btn-primary" id="btnConfirmarSi">Confirmar</button>
            </div>
        </div>
    </div>

    <!-- Modal Mover Material a Carpeta (Inventario Almacén) -->
    <div id="modalMoverCarpeta" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:3000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:2rem;width:90%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 1rem;color:#003d5c;font-size:1.1rem;"><i class="fas fa-folder-arrow-up"></i> Mover Material</h3>
            <p style="font-size:0.85rem;color:#666;margin-bottom:1rem;" id="moverMaterialNombre"></p>
            <select id="moverCarpetaSelect" style="width:100%;padding:0.5rem;border:1.5px solid #ddd;border-radius:6px;font-size:0.9rem;box-sizing:border-box;">
                <option value="">Sin carpeta (raíz)</option>
            </select>
            <div style="display:flex;gap:0.6rem;justify-content:flex-end;margin-top:1.2rem;">
                <button onclick="cerrarMoverCarpeta()" style="padding:0.5rem 1rem;border:1.5px solid #003d5c;background:transparent;color:#003d5c;border-radius:6px;cursor:pointer;font-size:0.9rem;">Cancelar</button>
                <button onclick="confirmarMoverMaterial()" style="padding:0.5rem 1rem;background:#003d5c;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.9rem;font-weight:600;">Mover</button>
            </div>
        </div>
    </div>
<script>
/* Validación unicidad código de material */
(function() {
    var campo = document.getElementById('campoCodigo') || document.querySelector('input[name="codigo"]');
    if (!campo) return;
    if (!campo.id) campo.id = 'campoCodigo';

    var feedback = document.createElement('small');
    feedback.style.cssText = 'display:block;margin-top:4px;font-size:0.8rem';
    campo.parentNode.appendChild(feedback);

    campo.addEventListener('blur', function() {
        var codigo    = this.value.trim();
        var excludeId = this.dataset.excludeId || 0;
        if (!codigo) { feedback.textContent = ''; return; }

        feedback.textContent = '⏳ Verificando...';
        feedback.style.color = '#888';

        fetch('api/materiales.php?action=check_codigo&codigo=' + encodeURIComponent(codigo) + '&exclude_id=' + excludeId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.disponible) {
                    feedback.textContent = '✓ Código disponible';
                    feedback.style.color = '#2E7D32';
                } else {
                    feedback.textContent = '✗ Este código ya está en uso';
                    feedback.style.color = '#c0392b';
                }
            })
            .catch(function() { feedback.textContent = ''; });
    });
})();
</script>
</body>
</html>