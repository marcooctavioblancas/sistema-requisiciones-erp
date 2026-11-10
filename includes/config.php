<?php
/**
 * Configuración del Sistema de Requisiciones
 * Conexión a base de datos y constantes globales
 */

// Errores nunca al navegador (fugaban rutas de servidor, queries, etc. -- confirmado
// con un fatal real de mis_requisiciones.php) -- se loguean a archivo en su lugar,
// protegido igual que el resto de logs/ (ver logs/.htaccess). Un mensaje genérico
// se muestra si un fatal deja la página a medias sin que el código propio ya haya
// mandado su propia respuesta de error.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        http_response_code(500);
        echo 'Ocurrió un error inesperado. Ya quedó registrado; intenta de nuevo o contacta a soporte.';
    }
});

// Ruta al intérprete de Python (PyMuPDF para extracción de texto de PDFs)
// En Docker usa la variable de entorno; en XAMPP usa la ruta local
define('PYTHON_PATH', getenv('PYTHON_PATH') ?: 'python3');
define('PYMUPDF_SCRIPT', __DIR__ . '/../scripts/extract_pdf.py');

// Configuración de la base de datos
// Lee de variables de entorno si existen (Docker), sino usa defaults de XAMPP
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'sistema_requisiciones');

// ── Configuración de IA para análisis de cotizaciones ──────────────────────
// Proveedor activo: 'groq' (Groq Cloud, GRATIS, sin tarjeta, muy rápido).
// 'lmstudio' (LLM local, sin costo pero requiere GPU) se deja disponible por
// si se necesita en algún momento sin pasar por una API externa -- no tiene
// key real (corre en la propia máquina), solo URL/modelo.
// 'gemini' y 'anthropic' se quitaron (2026-08-14): no se usaban en ningún
// ambiente real, sus API keys reales quedaron expuestas en config.php sin
// necesidad -- si algún día se vuelven a necesitar, se agregan de nuevo aquí
// y su key real va en .env, nunca hardcodeada en este archivo.
define('AI_PROVIDER', 'groq');

// La key real vive en .env (raíz del proyecto, fuera de git -- ver .env.example
// para la plantilla). Docker Compose la inyecta como variable de entorno real
// del contenedor (env_file: .env en docker-compose.yml).
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');

// LM Studio (solo se usa si AI_PROVIDER = 'lmstudio')
define('LMSTUDIO_API_URL', 'http://127.0.0.1:1234/v1/chat/completions');
define('LMSTUDIO_MODEL', 'zai-org/glm-4.6v-flash');

// Configuración del sistema
define('SITE_NAME', 'Sistema de Requisiciones');

// SITE_URL se construye dinámicamente para funcionar desde cualquier IP o dominio
// En XAMPP: /sistema_requisiciones (subdirectorio). En Docker: '' (raíz).
$_sr_protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$_sr_host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_sr_dir      = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '/sistema_requisiciones';
$_sr_base     = ($_sr_dir === '/' || $_sr_dir === '\\') ? '' : $_sr_dir;
define('SITE_URL', $_sr_protocol . '://' . $_sr_host . $_sr_base);
unset($_sr_protocol, $_sr_host, $_sr_dir, $_sr_base);

define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// Sesiones en Redis cuando está disponible (Docker) -- así no quedan atadas al
// disco de un solo contenedor/servidor si algún día hay más de una instancia
// de la app detrás de un balanceador. En XAMPP (sin Redis configurado) sigue
// usando archivos locales sin ningún cambio de comportamiento.
$_sr_redis_host = getenv('REDIS_HOST');
if ($_sr_redis_host && extension_loaded('redis')) {
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', 'tcp://' . $_sr_redis_host . ':6379');
}
unset($_sr_redis_host);

// Expiración de sesión por inactividad -- antes era el default de PHP (1440s = 24 min,
// nunca ajustado a propósito para este proyecto). session.gc_maxlifetime es lo que el
// handler de Redis usa como TTL real de la sesión (se renueva en cada escritura -- ver
// el "touch" justo después de session_start() más abajo, necesario porque session.lazy_write
// hace que PHP NO vuelva a escribir/renovar el TTL si $_SESSION no cambió en el request).
define('SESSION_TIMEOUT_SEGUNDOS', 3600); // 1 hora
define('SESSION_AVISO_SEGUNDOS', 300);    // avisar 5 min antes de expirar

// proceso.php es la tablet de planta (uso intermitente durante todo el turno,
// no se puede pedir re-login cada hora ahí) -- sin timeout práctico solo para
// ese script. $_sr_es_proceso se reutiliza más abajo para tampoco inyectar el
// aviso JS de "sesión por expirar" en esa pantalla.
$_sr_es_proceso = strpos($_SERVER['SCRIPT_FILENAME'] ?? '', DIRECTORY_SEPARATOR . 'proceso.php') !== false;
ini_set('session.gc_maxlifetime', (string) ($_sr_es_proceso ? 86400 * 7 : SESSION_TIMEOUT_SEGUNDOS));

// Configuración de sesión
// httponly=true evita que JS lea la cookie (cierra el robo de sesión vía XSS);
// samesite=Lax evita que la cookie viaje en requests cross-site; secure solo
// se activa si la conexión real es HTTPS -- forzarlo en HTTP (como el Docker
// dev de hoy) haría que el navegador nunca mande la cookie y nadie pudiera
// iniciar sesión.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
]);
session_start();

// Renueva el TTL de la sesión en cada request autenticado. Con session.lazy_write (default
// de PHP) el handler de Redis solo vuelve a escribir/renovar el TTL si $_SESSION cambió en
// este request -- sin esto, navegar entre páginas (que en su mayoría solo LEEN la sesión)
// no la mantenía viva, y el usuario podía quedar deslogueado a la hora exacta de haber
// iniciado sesión sin importar qué tan activo estuviera.
if (isLoggedIn()) {
    $_SESSION['ultima_actividad'] = time();
}

// Zona horaria
date_default_timezone_set('America/Mexico_City');

// Conexión a la base de datos
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
    "mysql:host=" . DB_HOST . ";port=3306;dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]
);
            // Sincronizar timezone de MySQL con PHP (America/Mexico_City)
            // date('P') devuelve el offset actual con DST, ej: '-06:00' o '-05:00'
            $db->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            die("Error de conexión: " . $e->getMessage());
        }
    }
    return $db;
}

// Función para verificar si el usuario está logueado
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Función para verificar el tipo de usuario
function isUserType($type) {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === $type;
}

// Retorna true si el usuario es de tipo admin
function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

// Función para verificar si el usuario tiene un acceso específico.
// Los admin siempre tienen acceso a todo.
function hasAcceso($acceso) {
    if (isAdmin()) return true;
    return isset($_SESSION['user_accesos']) && in_array($acceso, $_SESSION['user_accesos']);
}

// Función para redireccionar
function redirect($url): never {
    header("Location: " . $url);
    exit();
}

// Función para sanitizar input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Función para generar folio de requisición
// Consecutivo basado en el máximo folio ya usado ese año (no en COUNT), para no
// repetir un folio si una requisición intermedia del año fue borrada.
function generarFolioRequisicion() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(folio, '-', -1) AS UNSIGNED)) as max_consec FROM requisiciones WHERE folio LIKE ?");
    $stmt->execute(["REQ-$year-%"]);
    $consecutivo = str_pad((int)$stmt->fetch()['max_consec'] + 1, 4, '0', STR_PAD_LEFT);
    return "REQ-$year-$consecutivo";
}

// Folio para requisiciones externas generadas desde Producción (imprevistas)
function generarFolioRequisicionExterna() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(folio, '-', -1) AS UNSIGNED)) as max_consec FROM requisiciones WHERE folio LIKE ?");
    $stmt->execute(["REQ-EXT-$year-%"]);
    $consecutivo = str_pad((int)$stmt->fetch()['max_consec'] + 1, 4, '0', STR_PAD_LEFT);
    return "REQ-EXT-$year-$consecutivo";
}

// ── Control de acceso: quién puede vincular una requisición externa a un
// proyecto real ──────────────────────────────────────────────────────────
// Punto único de decisión para el selector de proyecto en "Generar
// Requisiciones" (includes/generar_requisicion.php). Hoy sin restricción --
// cualquier área puede vincular su requisición externa a cualquier proyecto
// activo (decisión explícita del usuario, 2026-08-03: "cimentación" primero
// -- este punto único -- permisos reales después). Cuando se decida
// restringir (por rol, por si el área ya participa en ese proyecto, etc.),
// el cambio va SOLO aquí -- no hay que tocar cada pantalla que usa el
// selector, todas ya pasan por esta función tanto para pintar el dropdown
// como para validar el POST real.
function puedeVincularRequisicionAProyecto(int $usuarioId, string $area, int $proyectoId): bool {
    return true;
}

// ── Nivel jerárquico por departamento: 'trabajador' o 'jefe' ────────────────
// Independiente de hasAcceso() (eso decide SI puede entrar a un módulo; esto
// decide qué tan limitado está DENTRO de él). Un usuario tipo 'multiple' con
// varios accesos puede ser jefe en uno y trabajador en otro -- por eso vive
// en su propia tabla (usuario_niveles), no como un campo único en usuarios.
// admin (isAdmin()) no usa esta tabla, está por encima de cualquier
// jefe/trabajador. Sin fila = 'trabajador' (default más restrictivo, un
// usuario nuevo sin nivel asignado no es jefe por accidente).
function nivelUsuario(int $usuarioId, string $modulo): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT nivel FROM usuario_niveles WHERE usuario_id = ? AND modulo = ?");
    $stmt->execute([$usuarioId, $modulo]);
    return $stmt->fetchColumn() ?: 'trabajador';
}

// true si el usuario en sesión es jefe de ese módulo (o admin, que siempre puede todo).
function esJefeDe(string $modulo): bool {
    if (isAdmin()) return true;
    return nivelUsuario((int) ($_SESSION['user_id'] ?? 0), $modulo) === 'jefe';
}

// Igual que esJefeDe() pero acepta una lista de módulos separados por coma
// (ver material_reemplazo_solicitudes.area_responsable, que puede quedar
// "ingenieria,manufactura" cuando un ítem se pidió entre las dos áreas) --
// basta con ser jefe de UNA de ellas.
function esJefeDeAlguna(string $modulosCsv): bool {
    if (isAdmin()) return true;
    foreach (explode(',', $modulosCsv) as $modulo) {
        if ($modulo !== '' && esJefeDe($modulo)) return true;
    }
    return false;
}

// ── Aviso de tiempo excedido en una OT (2026-08-20) ─────────────────────────────
// El usuario pidió avisar cuando un proceso "se tardó mucho" -- pero el tiempo
// normal no debe ser un valor capturado a mano (se desactualiza y nadie lo
// mantiene), sino el promedio real de OTs anteriores ya completadas del MISMO
// proceso -- se ajusta solo conforme crece el histórico. Exige al menos 3 OTs
// previas del mismo proceso para no disparar avisos con muestras chicas/ruidosas
// (los primeros procesos de un proceso nuevo nunca tienen "promedio" confiable).
function avisarSiTiempoExcedido(PDO $db, int $otId, int $usuarioId): void {
    $stmt = $db->prepare("SELECT folio, titulo, proceso, tiempo_regular_min FROM ordenes_trabajo WHERE id = ?");
    $stmt->execute([$otId]);
    $ot = $stmt->fetch();
    if (!$ot || !$ot['proceso'] || (int)$ot['tiempo_regular_min'] <= 0) return;

    $stmtProm = $db->prepare("
        SELECT AVG(tiempo_regular_min) AS promedio, COUNT(*) AS muestras
        FROM ordenes_trabajo
        WHERE proceso = ? AND estado = 'completado' AND tiempo_regular_min > 0 AND id != ?
    ");
    $stmtProm->execute([$ot['proceso'], $otId]);
    $prom = $stmtProm->fetch();
    if (!$prom || (int)$prom['muestras'] < 3) return; // sin histórico suficiente todavía

    $promedio = (float)$prom['promedio'];
    $real = (int)$ot['tiempo_regular_min'];
    if ($promedio <= 0 || $real <= $promedio * 1.3) return; // dentro de lo normal (margen 30%)

    $pctExceso = round((($real / $promedio) - 1) * 100);
    notificarRoles(['manufactura'], 'ot_tiempo_excedido',
        'Tiempo excedido: ' . ($ot['folio'] ?: $ot['titulo']),
        "El proceso \"{$ot['proceso']}\" tardó " . round($real / 60, 1) . "h -- {$pctExceso}% más que el promedio histórico (" . round($promedio / 60, 1) . "h) para ese mismo proceso.",
        'produccion.php?seccion=actividades_maquinas', $usuarioId);
}

// ── Registro contable al cierre de un proyecto (2026-08-20) ─────────────────────
// El usuario pidió que al cerrar totalmente un proyecto (proyectos.cierre_total=1,
// la señal REAL de "terminado" -- ver cerrar_proyecto_total en proyecto.php) quede
// un registro con cuánto se gastó, el tiempo invertido y las horas trabajadas --
// pensado como insumo real para KPIs más adelante. El costo usa el MISMO patrón de
// 3 rutas de match ya establecido en compras.php (proyectos_costos/realizados):
// una OC puede estar ligada al proyecto directo, o a través de la requisición
// (por proyecto_id o por el nombre de texto que guardaba el flujo viejo).
// NOTA (2026-08-20, revisado con el usuario): Ingeniería (BOM/planos) y la vía
// directa de Almacén (BOM → almacen_items) se quedan FUERA a propósito -- ninguna
// tiene hoy una fecha real de "aquí empecé"/"aquí terminé" (boom_listas.fecha_finalizado
// existe pero solo la escribe bom.php, código muerto sin caller real; almacen_items
// no tiene ninguna fecha de cierre por ítem). Meter un número ahí sería un cálculo
// inventado. Pendiente real para cuando se formalice: Ingeniería podría arrancar
// cuando Proyectos lanza la orden de producción y cerrar cuando se formalice el botón
// "Enviar a compras" de Ingeniería (hoy sin ese significado); Almacén necesitaría
// un tiempo "general" (con esperas) y otro de trabajo real invertido por separado.
function registrarCierreContable(PDO $db, int $proyectoId): void {
    $stmtGasto = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
    $stmtGasto->execute([$proyectoId]);
    $nombreProy = $stmtGasto->fetchColumn();
    if (!$nombreProy) return;

    $stmtCosto = $db->prepare("
        SELECT SUM(oc.total) AS gasto
        FROM ordenes_compra oc
        LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
        WHERE oc.estado != 'rechazada'
          AND (oc.proyecto_id = ? OR r.proyecto_id = ? OR r.proyecto = ?)
    ");
    $stmtCosto->execute([$proyectoId, $proyectoId, $nombreProy]);
    $gastoTotal = (float)($stmtCosto->fetchColumn() ?: 0);

    $stmtTiempo = $db->prepare("
        SELECT COUNT(*) AS total_ots,
               COALESCE(SUM(tiempo_regular_min), 0) AS tiempo_regular,
               COALESCE(SUM(tiempo_extra_min), 0) AS tiempo_extra
        FROM ordenes_trabajo WHERE proyecto_id = ?
    ");
    $stmtTiempo->execute([$proyectoId]);
    $tiempo = $stmtTiempo->fetch();

    // Almacén: solo requisiciones ligadas al proyecto (incluye las externas generadas
    // desde una OT, ver crearRequisicion() en produccion.php) -- fecha_creacion hasta
    // fecha_actualizacion SOLO cuando quedó completada (si sigue pendiente/en_proceso,
    // no hay "fin" real todavía, no se cuenta).
    $stmtAlmacen = $db->prepare("
        SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, fecha_creacion, fecha_actualizacion)), 0) AS minutos
        FROM requisiciones WHERE proyecto_id = ? AND estado = 'completada'
    ");
    $stmtAlmacen->execute([$proyectoId]);
    $almacenMin = (int)$stmtAlmacen->fetchColumn();

    // Calidad: solo revisión de OTs (visto_produccion_fecha -> calidad_fecha). La de
    // OCs se descarta -- ver nota de la Fase 46 en aplicar_migraciones.php.
    $stmtCalidad = $db->prepare("
        SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, visto_produccion_fecha, calidad_fecha)), 0) AS minutos
        FROM ordenes_trabajo
        WHERE proyecto_id = ? AND calidad_fecha IS NOT NULL AND visto_produccion_fecha IS NOT NULL
    ");
    $stmtCalidad->execute([$proyectoId]);
    $calidadMin = (int)$stmtCalidad->fetchColumn();

    // Gerencia: fecha_creacion -> fecha_aprobacion de la OC (hasta que Gerencia aprueba,
    // no hasta la recepción -- eso ya no es tiempo de Gerencia). Mismo patrón de match
    // de 3 rutas que el gasto.
    $stmtGerencia = $db->prepare("
        SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, oc.fecha_creacion, oc.fecha_aprobacion)), 0) AS minutos
        FROM ordenes_compra oc
        LEFT JOIN requisiciones r ON oc.requisicion_id = r.id
        WHERE oc.fecha_aprobacion IS NOT NULL
          AND (oc.proyecto_id = ? OR r.proyecto_id = ? OR r.proyecto = ?)
    ");
    $stmtGerencia->execute([$proyectoId, $proyectoId, $nombreProy]);
    $gerenciaMin = (int)$stmtGerencia->fetchColumn();

    // Ingeniería (2026-08-20, confirmado con el usuario): inicio = fecha_difusion de la
    // Orden de Producción (cuando Proyectos difunde el proyecto de verdad, ver
    // proyecto.php::guardar_orden_produccion), fin = proyecto_fases.fecha_fin_real
    // (fase='ingenieria', fijada en ingenieria.php::finalizar_proyecto -- confiable desde
    // el fix de ese botón, ver commit de esa misma fecha).
    $stmtIng = $db->prepare("
        SELECT TIMESTAMPDIFF(MINUTE, op.fecha_difusion, pf.fecha_fin_real) AS minutos
        FROM ordenes_produccion op
        JOIN proyecto_fases pf ON pf.proyecto_id = op.proyecto_id AND pf.fase = 'ingenieria'
        WHERE op.proyecto_id = ? AND op.fecha_difusion IS NOT NULL AND pf.fecha_fin_real IS NOT NULL
        ORDER BY op.fecha_difusion ASC LIMIT 1
    ");
    $stmtIng->execute([$proyectoId]);
    $ingenieriaMin = max(0, (int)$stmtIng->fetchColumn());

    // Almacén-directo (BOM -> almacen_items, sin pasar por una requisición -- cubre tanto
    // el BOM de Ingeniería como cualquier BOM que Producción empuje directo vía plano_ia):
    // inicio = primer material del BOM que entra a almacen_items, fin =
    // proyectos.almacen_directo_fecha_fin (fijada al apagar almacen_activo, ver
    // almacen2.php::finalizar_proyecto/finalizar_proyecto_sin_compras -- fecha_estado NO
    // sirve aquí, la pisan muchas otras acciones a lo largo de la vida del proyecto).
    $stmtAlmDir = $db->prepare("
        SELECT TIMESTAMPDIFF(MINUTE, (SELECT MIN(fecha_agregado) FROM almacen_items WHERE proyecto_id = ?), p.almacen_directo_fecha_fin) AS minutos
        FROM proyectos p WHERE p.id = ? AND p.almacen_directo_fecha_fin IS NOT NULL
    ");
    $stmtAlmDir->execute([$proyectoId, $proyectoId]);
    $almacenDirectoMin = max(0, (int)$stmtAlmDir->fetchColumn());

    $db->prepare("
        INSERT INTO proyecto_registro_contable
            (proyecto_id, gasto_total, tiempo_regular_min, tiempo_extra_min, total_ots, almacen_tiempo_min, calidad_tiempo_min, gerencia_tiempo_min, ingenieria_tiempo_min, almacen_directo_tiempo_min, fecha_cierre)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE gasto_total = VALUES(gasto_total), tiempo_regular_min = VALUES(tiempo_regular_min),
            tiempo_extra_min = VALUES(tiempo_extra_min), total_ots = VALUES(total_ots),
            almacen_tiempo_min = VALUES(almacen_tiempo_min), calidad_tiempo_min = VALUES(calidad_tiempo_min),
            gerencia_tiempo_min = VALUES(gerencia_tiempo_min), ingenieria_tiempo_min = VALUES(ingenieria_tiempo_min),
            almacen_directo_tiempo_min = VALUES(almacen_directo_tiempo_min), fecha_cierre = VALUES(fecha_cierre)
    ")->execute([$proyectoId, $gastoTotal, (int)$tiempo['tiempo_regular'], (int)$tiempo['tiempo_extra'], (int)$tiempo['total_ots'], $almacenMin, $calidadMin, $gerenciaMin, $ingenieriaMin, $almacenDirectoMin]);
}

// ── Asistencia de operadores + reasignación (2026-08-20) ────────────────────────
// Diseño híbrido acordado con el usuario: "Iniciar"/"Reanudar" en proceso.php
// marca presencia automática (sin UI nueva para el caso normal, ver
// marcarAsistenciaActiva()); un jefe revisa quién sigue "sin_marcar" en
// produccion.php?seccion=asistencia y reasigna sus OTs pendientes. No es una
// lista de horarios/turnos, solo un estado por operador+día.

// Se llama en cuanto un operador arranca o reanuda una OT de verdad -- confirma
// presencia real, incluso si un jefe ya lo había marcado "inactivo" por error
// (alguien trabajando ahora mismo es evidencia más fuerte que una suposición).
function marcarAsistenciaActiva(PDO $db, int $usuarioId): void {
    $db->prepare("
        INSERT INTO asistencia_operadores (usuario_id, fecha, estado, fecha_marcado)
        VALUES (?, CURDATE(), 'activo', NOW())
        ON DUPLICATE KEY UPDATE estado = 'activo', fecha_marcado = NOW()
    ")->execute([$usuarioId]);
}

// Quién se espera que trabaje hoy -- mismas cuentas que ya usa produccion.php para
// el datalist de operador en "Generar OT" (línea ~623), pero incluyendo
// 'operador-proceso' explícito: ese tipo NO tiene 'proceso' en su columna
// `accesos` (su acceso se fija en sesión al hacer login, ver index.php), así que
// el fallback LIKE '%proceso%' por sí solo los deja fuera.
function operadoresProcesoEsperadosHoy(PDO $db): array {
    return $db->query("
        SELECT id, nombre_completo FROM usuarios
        WHERE activo = 1
          AND (tipo_usuario IN ('operador-proceso','administrador-proceso')
               OR (accesos IS NOT NULL AND accesos LIKE '%proceso%'))
        ORDER BY nombre_completo
    ")->fetchAll();
}

// Aviso automático (2026-08-20) de operadores que siguen "sin_marcar" pasada la
// hora de corte, lunes a viernes. Sin cron de servidor -- mismo límite ya
// documentado para el auto-pausa por horario de calcularTiempoBloque(): el
// disparo depende de que ALGUIEN tenga cualquier módulo abierto para que su
// poll de la campana (cada 15s, ajax_chat.php::obtener_notificaciones) llegue
// hasta esta función. Se avisa una sola vez por día -- antes de insertar, se
// revisa si ya existe una notificación de hoy con este tipo.
define('ASISTENCIA_HORA_CORTE', '10:00');

function avisarAsistenciaPendiente(PDO $db, ?DateTimeImmutable $ahora = null): void {
    $ahora = $ahora ?? new DateTimeImmutable('now');
    if ((int)$ahora->format('N') > 5) return; // solo lunes(1) a viernes(5)
    if ($ahora->format('H:i') < ASISTENCIA_HORA_CORTE) return;

    $yaAvisado = $db->query("
        SELECT 1 FROM notificaciones
        WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()
        LIMIT 1
    ")->fetchColumn();
    if ($yaAvisado) return;

    $operadores = operadoresProcesoEsperadosHoy($db);
    if (!$operadores) return;

    $marcadosHoy = $db->query("SELECT usuario_id FROM asistencia_operadores WHERE fecha = CURDATE()")->fetchAll(PDO::FETCH_COLUMN);
    $sinMarcar = array_filter($operadores, fn($op) => !in_array($op['id'], $marcadosHoy));
    if (!$sinMarcar) return;

    $nombres = implode(', ', array_column($sinMarcar, 'nombre_completo'));
    notificarRoles(['manufactura'], 'asistencia_pendiente',
        count($sinMarcar) . ' operador(es) sin marcar asistencia hoy',
        "Después de las " . ASISTENCIA_HORA_CORTE . " siguen sin marcar asistencia: $nombres",
        'produccion.php?seccion=asistencia');
}

// ── Alternativas de material (padre-hijo) + reemplazo con aprobación ───────────
// El usuario pidió (2026-08-19) que Almacén pueda proponer un material equivalente
// al surtir un BOM/requisición -- pero el swap no se aplica solo: el área dueña de
// la especificación técnica (Ingeniería para BOM de proyecto, o el área que generó
// la requisición) debe aprobarlo primero, porque a veces el material es específico
// y no es intercambiable aunque a Almacén le parezca equivalente.

// Catálogo reusable y bidireccional: una vez que un par de materiales se propuso
// como alternativa, queda disponible para sugerirse de nuevo en cualquier BOM
// futuro (A es alternativa de B tanto como B lo es de A).
function buscarAlternativasMaterial(PDO $db, int $materialId): array {
    $stmt = $db->prepare("
        SELECT m.*
        FROM material_alternativas ma
        JOIN materiales m ON m.id = IF(ma.material_id = ?, ma.alternativa_id, ma.material_id)
        WHERE (ma.material_id = ? OR ma.alternativa_id = ?) AND m.activo = 1
        ORDER BY m.descripcion
    ");
    $stmt->execute([$materialId, $materialId, $materialId]);
    return $stmt->fetchAll();
}

// Mismo mapeo área→módulo ya usado por notificarDuplicado() -- se repite aquí
// (en vez de extraerlo) para no tocar esa función ya probada.
function rolPorAreaRequisicion(string $area): ?string {
    $mapa = ['Producción'=>'manufactura','Compras'=>'compras','Calidad'=>'calidad','Proyectos'=>'proyectos','Ingeniería'=>'ingenieria','Almacén'=>'almacen'];
    return $mapa[$area] ?? null;
}

// Abre la solicitud de aprobación y registra el par en el catálogo -- NO aplica
// el swap todavía, eso solo pasa si el jefe del área responsable aprueba (ver
// resolverReemplazoMaterial()).
function proponerReemplazoMaterial(PDO $db, string $origenTipo, int $origenId, int $usuarioId, array $alternativaIds, string $nota = ''): array {
    $alternativaIds = array_values(array_unique(array_filter(array_map('intval', $alternativaIds))));
    if (empty($alternativaIds)) return ['ok'=>false,'error'=>'Selecciona al menos un material alterno.'];
    if ($origenTipo === 'almacen_item') {
        $stmt = $db->prepare("SELECT id, material_id, cantidad_requerida, cantidad_ingenieria, cantidad_produccion, proyecto_id FROM almacen_items WHERE id = ?");
        $stmt->execute([$origenId]);
        $origen = $stmt->fetch();
        if (!$origen || !$origen['material_id']) return ['ok'=>false,'error'=>'Ítem no encontrado o sin material de catálogo.'];
        $materialOriginalId = (int)$origen['material_id'];
        $cantidad = (float)$origen['cantidad_requerida'];
        $proyectoId = $origen['proyecto_id'] ?: null;
        $requisicionId = null;
        // La fila puede venir del BOM de Ingeniería, de una petición directa de
        // Producción (cantidad_produccion, ej. material de OT), o de ambas a la vez --
        // el área que debe aprobar es quien realmente pidió el material, no siempre
        // Ingeniería. Si las dos aportaron, se notifica a ambas (cualquiera de los
        // dos jefes puede resolver). Filas viejas sin desglose (ambas en 0) se quedan
        // en Ingeniería por default, como dueña de la spec técnica del BOM.
        $areas = [];
        if ((int)($origen['cantidad_ingenieria'] ?? 0) > 0) $areas[] = 'ingenieria';
        if ((int)($origen['cantidad_produccion'] ?? 0) > 0) $areas[] = 'manufactura';
        $areaResponsable = $areas ? implode(',', $areas) : 'ingenieria';
    } elseif ($origenTipo === 'requisicion_detalle') {
        $stmt = $db->prepare("SELECT rd.id, rd.material_id, rd.cantidad, rd.requisicion_id, r.area, r.proyecto_id FROM requisicion_detalle rd JOIN requisiciones r ON r.id = rd.requisicion_id WHERE rd.id = ?");
        $stmt->execute([$origenId]);
        $origen = $stmt->fetch();
        if (!$origen || !$origen['material_id']) return ['ok'=>false,'error'=>'Ítem no encontrado o sin material de catálogo.'];
        $materialOriginalId = (int)$origen['material_id'];
        $cantidad = (float)$origen['cantidad'];
        $proyectoId = $origen['proyecto_id'] ?: null;
        $requisicionId = (int)$origen['requisicion_id'];
        $areaResponsable = rolPorAreaRequisicion($origen['area'] ?? '') ?? 'ingenieria';
    } else {
        return ['ok'=>false,'error'=>'Origen inválido.'];
    }

    if (in_array($materialOriginalId, $alternativaIds, true)) return ['ok'=>false,'error'=>'El material alterno no puede ser el mismo material original.'];

    $phAlt = implode(',', array_fill(0, count($alternativaIds), '?'));
    $chkAlt = $db->prepare("SELECT id FROM materiales WHERE id IN ($phAlt) AND activo = 1");
    $chkAlt->execute($alternativaIds);
    if (count($chkAlt->fetchAll(PDO::FETCH_COLUMN)) !== count($alternativaIds)) {
        return ['ok'=>false,'error'=>'Uno o más materiales alternos no son válidos.'];
    }

    $insAlt = $db->prepare("INSERT IGNORE INTO material_alternativas (material_id, alternativa_id, creado_por, fecha_creacion) VALUES (?, ?, ?, NOW())");
    foreach ($alternativaIds as $altId) $insAlt->execute([$materialOriginalId, $altId, $usuarioId]);

    // material_alternativa_id se queda como el primer candidato (compatibilidad con
    // todo el código que ya lee ese campo cuando solo hay una opción); opciones_json
    // solo se llena si Almacén ofreció más de una -- el jefe elige cuál aplicar al
    // aprobar (ver resolverReemplazoMaterial()).
    $alternativaIdPrincipal = $alternativaIds[0];
    $opcionesJson = count($alternativaIds) > 1 ? json_encode($alternativaIds) : null;

    $db->prepare("INSERT INTO material_reemplazo_solicitudes
            (origen_tipo, origen_id, material_original_id, material_alternativa_id, opciones_json, cantidad, proyecto_id, requisicion_id, area_responsable, propuesto_por, nota_solicitud)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$origenTipo, $origenId, $materialOriginalId, $alternativaIdPrincipal, $opcionesJson, $cantidad, $proyectoId, $requisicionId, $areaResponsable, $usuarioId, sanitize($nota)]);
    $solicitudId = (int)$db->lastInsertId();

    $stmtDesc = $db->prepare("SELECT id, descripcion FROM materiales WHERE id IN (?, $phAlt)");
    $stmtDesc->execute([$materialOriginalId, ...$alternativaIds]);
    $descs = [];
    foreach ($stmtDesc->fetchAll() as $m) $descs[$m['id']] = $m['descripcion'];
    $descsAlternativas = implode('", "', array_map(fn($id) => $descs[$id] ?? "material #$id", $alternativaIds));

    // El mensaje debe dejar claro DE QUÉ proyecto (o requisición, si no hay proyecto)
    // salió la propuesta -- sin esto, el jefe que recibe la notificación no tiene
    // forma de saber a qué trabajo pertenece antes de entrar a aprobar/rechazar.
    $origenTxt = '';
    if ($proyectoId) {
        $nombreProy = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $nombreProy->execute([$proyectoId]);
        $nombreProy = $nombreProy->fetchColumn();
        if ($nombreProy) $origenTxt = " (proyecto \"$nombreProy\")";
    } elseif ($requisicionId) {
        $folioReq = $db->prepare("SELECT folio FROM requisiciones WHERE id = ?");
        $folioReq->execute([$requisicionId]);
        $folioReq = $folioReq->fetchColumn();
        if ($folioReq) $origenTxt = " (requisición $folioReq)";
    }

    $porTexto = count($alternativaIds) > 1 ? "por alguno de: \"$descsAlternativas\"" : "por \"$descsAlternativas\"";
    notificarRoles(explode(',', $areaResponsable), 'reemplazo_material_pendiente', 'Reemplazo de material pendiente de tu aprobación',
        "Almacén propone sustituir \"" . ($descs[$materialOriginalId] ?? '') . "\" $porTexto$origenTxt -- necesita tu confirmación antes de comprar/surtir así.",
        'reemplazos_material.php', $usuarioId);

    return ['ok'=>true,'id'=>$solicitudId];
}

// Aplica (o descarta) una solicitud ya evaluada por el jefe del área responsable.
// Al aprobar, el swap se hace directo sobre la fila origen (almacen_items o
// requisicion_detalle) -- así todo el código que ya lee material_id (stock,
// surtido, fincado) sigue funcionando sin tocar nada más. La solicitud resuelta
// + el catálogo de material_alternativas quedan como el rastro de qué se
// sustituyó, por quién y por qué.
function resolverReemplazoMaterial(PDO $db, int $solicitudId, bool $aprobar, int $usuarioId, string $nota = '', ?int $materialElegidoId = null): array {
    $stmt = $db->prepare("SELECT * FROM material_reemplazo_solicitudes WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$solicitudId]);
    $sol = $stmt->fetch();
    if (!$sol) return ['ok'=>false,'error'=>'Solicitud no encontrada o ya resuelta.'];

    if (!esJefeDeAlguna($sol['area_responsable'])) return ['ok'=>false,'error'=>'No tienes permiso para resolver esta solicitud.'];

    // Con varios candidatos (opciones_json), el jefe DEBE decir cuál de los que
    // Almacén ofreció es el que aplica -- sin esto se aprobaría a ciegas el primero
    // de la lista, que es justo lo que esta función quería evitar.
    $opciones = $sol['opciones_json'] ? (json_decode($sol['opciones_json'], true) ?: []) : null;
    $materialAAplicar = (int) $sol['material_alternativa_id'];
    if ($aprobar && $opciones) {
        if (!$materialElegidoId || !in_array($materialElegidoId, array_map('intval', $opciones), true)) {
            return ['ok'=>false,'error'=>'Elige cuál de los materiales propuestos vas a aplicar.'];
        }
        $materialAAplicar = $materialElegidoId;
    }

    $db->beginTransaction();
    try {
        if ($aprobar) {
            if ($sol['origen_tipo'] === 'almacen_item') {
                $stmtMat = $db->prepare("SELECT descripcion, marca, modelo, stock_disponible FROM materiales WHERE id = ?");
                $stmtMat->execute([$materialAAplicar]);
                $matAlt = $stmtMat->fetch();
                if (!$matAlt) throw new Exception('El material alterno ya no existe en el catálogo.');
                $db->prepare("UPDATE almacen_items SET material_id = ?, item = ?, descripcion = ?, marca = ?, modelo = ?, stock_actual = ?, diferencia = ? - cantidad_requerida WHERE id = ?")
                   ->execute([$materialAAplicar, $matAlt['descripcion'], $matAlt['descripcion'], $matAlt['marca'], $matAlt['modelo'], $matAlt['stock_disponible'], $matAlt['stock_disponible'], $sol['origen_id']]);
            } else {
                $db->prepare("UPDATE requisicion_detalle SET material_id = ?, notas = TRIM(CONCAT(COALESCE(notas,''), ' [Material sustituido con autorización, solicitud #', ?, ']')) WHERE id = ?")
                   ->execute([$materialAAplicar, $solicitudId, $sol['origen_id']]);
            }
        }
        $db->prepare("UPDATE material_reemplazo_solicitudes SET estado = ?, resuelto_por = ?, fecha_resolucion = NOW(), nota_resolucion = ?, material_elegido_id = ? WHERE id = ?")
           ->execute([$aprobar ? 'aprobado' : 'rechazado', $usuarioId, sanitize($nota), $aprobar ? $materialAAplicar : null, $solicitudId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok'=>false,'error'=>'Error al resolver: '.$e->getMessage()];
    }

    if (!empty($sol['propuesto_por'])) {
        $estadoTxt = $aprobar ? 'aprobada' : 'rechazada';
        $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, link) VALUES (?, 'reemplazo_material_resuelto', ?, ?, ?)")
           ->execute([$sol['propuesto_por'], "Tu solicitud de reemplazo fue $estadoTxt",
               "La solicitud de sustituir material #$solicitudId fue $estadoTxt.", 'almacen2.php']);
    }

    return ['ok'=>true];
}

// true si algún material de este BOM tiene un reemplazo pendiente de aprobación --
// no hay columna boom_id en almacen_items, así que se cruza por proyecto+material_id
// (el mismo material puede venir de más de un BOM del proyecto; basta con que
// aparezca en uno). Usado para bloquear guardar/editar el BOM en ingenieria.php
// mientras Almacén espera respuesta -- el material puede cambiar antes de fabricar.
function boomTieneReemplazoPendiente(PDO $db, int $boomId): bool {
    $stmt = $db->prepare("
        SELECT 1
        FROM material_reemplazo_solicitudes mrs
        JOIN almacen_items ai ON mrs.origen_tipo = 'almacen_item' AND mrs.origen_id = ai.id
        JOIN boom_items bi ON bi.proyecto_id = ai.proyecto_id AND bi.material_id = ai.material_id AND bi.boom_id = ?
        WHERE mrs.estado = 'pendiente'
        LIMIT 1
    ");
    $stmt->execute([$boomId]);
    return (bool) $stmt->fetchColumn();
}

// true si el proyecto tiene algún reemplazo de material pendiente de aprobación --
// a diferencia de boomTieneReemplazoPendiente(), aquí sí sirve proyecto_id directo
// (la solicitud ya lo guarda), sin necesidad de cruzar con boom_items. Usado para
// bloquear "finalizar sin compras" (cerraría Almacén con material aún en el aire).
function proyectoTieneReemplazoPendiente(PDO $db, int $proyectoId): bool {
    $stmt = $db->prepare("SELECT 1 FROM material_reemplazo_solicitudes WHERE proyecto_id = ? AND estado = 'pendiente' LIMIT 1");
    $stmt->execute([$proyectoId]);
    return (bool) $stmt->fetchColumn();
}

// Sincroniza materiales.precio_unitario con el precio real de la última OC
// generada para ese material (validarItemsContraCotizacion(), en
// includes/solicitar_cotizacion.php, ya blinda ese precio contra
// manipulación -- siempre sale de cotizaciones.datos_ia, nunca del POST
// crudo). Antes el precio del catálogo se quedaba con lo que alguien haya
// escrito a mano en el editor de materiales (o 0 por default), sin importar
// cuántas veces se hubiera comprado ya a un precio distinto.
function sincronizarPrecioMaterial(PDO $db, ?int $materialId, float $precio): void {
    if (!$materialId || $precio <= 0) return;
    $db->prepare("UPDATE materiales SET precio_unitario = ? WHERE id = ?")->execute([$precio, $materialId]);
}

// ── BOM imprimible por proyecto (Compras/Almacén) ───────────────────────────
// Guarda una copia congelada de los items de un proyecto en bom_snapshots.
// $items: array de filas con al menos descripcion/cantidad_requerida/modelo/
// marca/cat_nombre (mismo shape que el query de almacen_items usado en
// almacen2.php). $origen: 'impresion' (click manual) o 'finalizado' (automático
// al finalizar el proyecto en Almacén) — así el BOM sigue disponible para
// descargar aunque el proyecto se finalice o los materiales cambien después.
function guardarSnapshotBom(PDO $db, int $proyectoId, array $items, string $origen, ?int $usuarioId): void {
    if (!$proyectoId) return;
    $db->prepare("INSERT INTO bom_snapshots (proyecto_id, snapshot_json, origen, creado_por) VALUES (?, ?, ?, ?)")
       ->execute([$proyectoId, json_encode($items, JSON_UNESCAPED_UNICODE), $origen, $usuarioId]);
}

// Clasificación de documentos del BOM (boom_items.categoria_doc) -- independiente del
// catálogo real, orden y colores fijos pedidos por el usuario para PDF/Excel/pantalla.
function bomCategoriasDocMeta(): array {
    return [
        'componente_principal' => ['label' => 'Componente Principal', 'color' => '#c62828', 'bg' => '#fdecea'],
        'conector'              => ['label' => 'Conectores',           'color' => '#1565c0', 'bg' => '#e3f2fd'],
        'material_electrico'    => ['label' => 'Material Eléctrico',   'color' => '#2e7d32', 'bg' => '#e8f5e9'],
        'tornilleria'           => ['label' => 'Tornillería',          'color' => '#000000', 'bg' => '#f0f0f0'],
    ];
}

// Ordena items de BOM según el orden fijo de categoría de documento; sin clasificar
// al final. usort es estable desde PHP 8 -- conserva el orden original dentro de
// cada grupo (por fecha_agregado/id, como ya venían ordenados por la query).
function bomOrdenarItemsPorCategoriaDoc(array $items): array {
    $orden = array_flip(array_keys(bomCategoriasDocMeta()));
    usort($items, function($a, $b) use ($orden) {
        $oa = $orden[$a['categoria_doc'] ?? ''] ?? 999;
        $ob = $orden[$b['categoria_doc'] ?? ''] ?? 999;
        return $oa <=> $ob;
    });
    return $items;
}

// Imprime el mismo formato "LISTA DE MATERIALES (BILL OF MATERIALS)" que usa
// Ingeniería (api/vista_pdf_bom.php), pero a partir de un array de items ya
// armado en vez de un boom_id — usado por Compras/Almacén, donde el BOM que se
// ve es el agregado de almacen_items (varios BOMs + ajustes de Producción), no
// una sola lista de Ingeniería.
function renderBomImprimibleProyecto(string $proyectoNombre, array $items, ?string $notaPie = null): void {
    $logo_data_uri = '';
    $logo_path = UPLOAD_PATH . 'logo/logo.png';
    if (file_exists($logo_path)) {
        $logo_data_uri = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path));
    }
    // Fecha, Revisión No. y Página del pie DOC-ING-FO-01C son fijas -- son
    // parte del formato oficial del documento (igual que en
    // api/vista_pdf_bom.php de Ingeniería), no datos en vivo del proyecto.
    $fecha_export = '09 / Jul / 20';
    $BOM_REVISION_FOOTER = 6;
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>BOM <?= htmlspecialchars($proyectoNombre) ?></title>
<style>
    * { box-sizing: border-box; }
    @page { size: letter landscape; margin: 8mm; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; padding: 0; color: #222; font-size: 10px; }
    /* Encabezado y pie viven dentro del thead/tfoot de la MISMA tabla que los
       items -- display:table-header-group / table-footer-group es el
       mecanismo de impresión más confiable para que algo se repita igual en
       cada página (más confiable que position:fixed, que en Chromium no
       siempre respeta la geometría real de cada página impresa). Mismo
       criterio que api/vista_pdf_bom.php de Ingeniería. */
    table.hoja { width:100%; border-collapse:collapse; table-layout:fixed; }
    table.hoja thead { display:table-header-group; }
    table.hoja tfoot { display:table-footer-group; }
    table.hoja tbody tr { page-break-inside:avoid; }
    .hoja-header-fila td { border:none; padding:0 0 8px; }
    .hoja-header { display:flex; align-items:center; border:1px solid #333; }
    .hoja-header .logo { padding:4px 10px; border-right:1px solid #333; }
    .hoja-header .logo img { height:42px; }
    .hoja-header .titulo { flex:1; text-align:center; }
    .hoja-header .titulo h1 { font-size:14px; margin:0 0 3px; }
    .hoja-header .titulo div { font-size:11px; }
    /* table-layout:fixed + word-break: sin esto, una descripción larga o una
       palabra sin espacios empujaba la tabla más ancha que la página impresa. */
    table.hoja th.items-th { background:#003d5c; color:#fff; font-size:9px; padding:4px 6px; border:1px solid #ccc; text-align:center; -webkit-print-color-adjust:exact; print-color-adjust:exact; word-break:break-word; overflow-wrap:break-word; }
    table.hoja td.items-td { border:1px solid #ccc; padding:3px 6px; font-size:9px; word-break:break-word; overflow-wrap:break-word; }
    .pie-fila td { border:none; padding:8px 0 0; }
    .pie { border:1px solid #333; }
    .pie .tb { display:flex; }
    .pie .tb div { flex:1; border-right:1px solid #333; border-bottom:1px solid #333; text-align:center; padding:3px 4px; font-size:9px; }
    .pie .tb div:last-child { border-right:none; }
    .pie .disclaimer { text-align:center; padding:3px 6px; font-size:9px; }
</style>
</head>
<body>
    <table class="hoja">
        <colgroup>
            <col style="width:8%"><col style="width:38%"><col style="width:14%">
            <col style="width:12%"><col style="width:14%"><col style="width:14%">
        </colgroup>
        <thead>
            <tr class="hoja-header-fila">
                <td colspan="6">
                    <div class="hoja-header">
                        <div class="logo">
                            <?php if ($logo_data_uri): ?>
                            <img src="<?= $logo_data_uri ?>" alt="EMPRESA EJEMPLO">
                            <?php else: ?>
                            EMPRESA EJEMPLO
                            <?php endif; ?>
                        </div>
                        <div class="titulo">
                            <h1>LISTA DE MATERIALES (BILL OF MATERIALS)</h1>
                            <div>PROYECTO: <?= htmlspecialchars($proyectoNombre) ?></div>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th class="items-th">CANTIDAD</th>
                <th class="items-th">DESCRIPCIÓN</th>
                <th class="items-th">No. DE PARTE</th>
                <th class="items-th">MARCA</th>
                <th class="items-th">CATEGORIA</th>
                <th class="items-th">ESTADO</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td class="items-td" colspan="6" style="text-align:center;color:#999;">Sin items</td></tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
            <tr>
                <td class="items-td" style="text-align:center;"><?= htmlspecialchars($item['cantidad_requerida'] ?? $item['cantidad'] ?? '') ?></td>
                <td class="items-td"><?= htmlspecialchars($item['descripcion'] ?? '') ?></td>
                <td class="items-td" style="text-align:center;"><?= htmlspecialchars($item['modelo'] ?? '') ?></td>
                <td class="items-td"><?= htmlspecialchars($item['marca'] ?? '') ?></td>
                <td class="items-td"><?= htmlspecialchars($item['cat_nombre'] ?? '') ?></td>
                <td class="items-td"><?= htmlspecialchars($item['estado_lbl'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="pie-fila">
                <td colspan="6">
                    <div class="pie">
                        <div class="tb">
                            <div style="font-weight:bold;">DOC-ING-FO-01C</div>
                            <div>Revisión No: <?= (int) $BOM_REVISION_FOOTER ?></div>
                            <div>Fecha: <strong><?= htmlspecialchars($fecha_export) ?></strong></div>
                            <div>Página: <strong>1</strong></div>
                        </div>
                        <div class="disclaimer">
                            <?= $notaPie ? htmlspecialchars($notaPie) . ' — ' : '' ?>Este documento es propiedad de EMPRESA EJEMPLO y no podrá ser reproducido total ni parcialmente sin previa autorización
                        </div>
                    </div>
                </td>
            </tr>
        </tfoot>
    </table>

    <script>
        if (window.top === window.self) {
            window.addEventListener('load', function () { window.print(); });
        }
    </script>
</body>
</html>
    <?php
}

// ── Punto único de creación de requisiciones ────────────────────────────────
// Antes cada pantalla (requisición manual, Producción x3, Ingeniería, Compras/
// Proyectos, Almacén...) armaba su propio INSERT INTO requisiciones a mano, con
// distinto set de columnas cada vez — por eso hubo bugs reales de campos NULL
// que nadie llenaba (area/solicitante en Compras/Proyectos, proyecto_id casi
// en todos). Esta función no decide de dónde salen los materiales ni qué
// validaciones aplican (eso sigue siendo responsabilidad de cada punto de
// entrada) — solo garantiza que toda requisición, sin importar quién la cree,
// tenga el mismo set de columnas llenado explícitamente (NULL a propósito
// cuando no aplica, nunca "se me olvidó").
//
// $datos:  usuario_id (obligatorio), proyecto, proyecto_id, area, solicitante,
//          fecha_requerida, prioridad (default 'media'), notas_generales,
//          ot_id, folio_externo (bool, usa generarFolioRequisicionExterna())
// $items:  cada uno con descripcion, cantidad y opcionalmente material_id,
//          unidad_medida, marca, modelo, notas
// $notificar_roles: roles a notificar de "nueva_requisicion" (null = ninguno)
// Retorna ['id' => requisicion_id, 'folio' => folio]
function crearRequisicion($db, array $datos, array $items, ?array $notificar_roles = ['compras', 'almacen']) {
    $folio = !empty($datos['folio_externo']) ? generarFolioRequisicionExterna() : generarFolioRequisicion();
    $stmt = $db->prepare(
        "INSERT INTO requisiciones
            (folio, usuario_id, proyecto, proyecto_id, area, solicitante, fecha_requerida, prioridad, notas_generales, estado, ot_id, fecha_creacion)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())"
    );
    $stmt->execute([
        $folio,
        $datos['usuario_id'],
        $datos['proyecto'] ?? null,
        $datos['proyecto_id'] ?? null,
        $datos['area'] ?? null,
        $datos['solicitante'] ?? null,
        $datos['fecha_requerida'] ?? null,
        $datos['prioridad'] ?? 'media',
        $datos['notas_generales'] ?? null,
        'pendiente',
        $datos['ot_id'] ?? null,
    ]);
    $req_id = (int)$db->lastInsertId();

    $item_numero = 1;
    $material_ids_insertados = [];
    $stmt_det = $db->prepare(
        "INSERT INTO requisicion_detalle (requisicion_id, item_numero, material_id, descripcion, cantidad, unidad_medida, marca, modelo, notas) VALUES (?,?,?,?,?,?,?,?,?)"
    );
    foreach ($items as $it) {
        $stmt_det->execute([
            $req_id,
            $item_numero++,
            $it['material_id'] ?? null,
            $it['descripcion'] ?? '',
            $it['cantidad'] ?? 0,
            $it['unidad_medida'] ?? '',
            $it['marca'] ?? null,
            $it['modelo'] ?? null,
            $it['notas'] ?? '',
        ]);
        if (!empty($it['material_id'])) $material_ids_insertados[] = $it['material_id'];
    }

    registrarEventoRequisicion($db, $req_id, 'creada', null, $datos['usuario_id'] ?? null);

    if (!empty($notificar_roles)) {
        $titulo  = $datos['notif_titulo']  ?? "Nueva requisición: " . ($datos['proyecto'] ?? $folio);
        $mensaje = $datos['notif_mensaje'] ?? "Se generó la requisición $folio.";
        foreach ($notificar_roles as $rol) {
            $url = $rol === 'compras' ? 'compras.php' : 'almacen2.php';
            notificarRoles([$rol], 'nueva_requisicion', $titulo, $mensaje, $url, $datos['usuario_id']);
        }
    }

    $duplicados = [];
    if (!empty($datos['proyecto_id']) && !empty($material_ids_insertados)) {
        $duplicados = detectarRequisicionesDuplicadas($db, (int)$datos['proyecto_id'], $material_ids_insertados, $datos['area'] ?? '', $req_id);
        notificarDuplicado($duplicados, $folio, $datos['area'] ?? '');
    }

    return ['id' => $req_id, 'folio' => $folio, 'duplicados' => $duplicados];
}

// Detecta requisiciones activas de OTRO departamento para el mismo proyecto que
// ya piden alguno de los mismos materiales -- para advertir de una posible
// compra duplicada (no bloquea, solo informa). Solo compara por material_id
// (los ítems de texto libre sin catálogo no se pueden comparar).
function detectarRequisicionesDuplicadas(PDO $db, int $proyecto_id, array $material_ids, string $area_excluir, ?int $excluir_requisicion_id = null): array {
    $material_ids = array_values(array_unique(array_filter(array_map('intval', $material_ids))));
    if (empty($material_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($material_ids), '?'));
    $sql = "SELECT r.folio, r.area, r.solicitante, r.fecha_creacion, m.descripcion
            FROM requisicion_detalle rd
            JOIN requisiciones r ON r.id = rd.requisicion_id
            JOIN materiales m ON m.id = rd.material_id
            WHERE r.proyecto_id = ?
              AND r.estado NOT IN ('completada','cancelada','rechazada')
              AND r.area != ?
              AND rd.material_id IN ($placeholders)";
    $params = array_merge([$proyecto_id, $area_excluir], $material_ids);
    if ($excluir_requisicion_id) {
        $sql .= " AND r.id != ?";
        $params[] = $excluir_requisicion_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Notifica al/los departamento(s) que YA tenían una requisición activa para el
// mismo proyecto+material, cuando otro departamento genera una nueva. Ver
// detectarRequisicionesDuplicadas() / avisarDuplicado() (ese otro solo avisa a
// quien está creando la nueva; este avisa al lado contrario).
function notificarDuplicado(array $duplicados, string $folio_nuevo, string $area_nueva): void {
    if (empty($duplicados)) return;
    $rol_por_area = [
        'Producción'  => 'manufactura',
        'Compras'     => 'compras',
        'Calidad'     => 'calidad',
        'Proyectos'   => 'proyectos',
        'Ingeniería'  => 'ingenieria',
        'Almacén'     => 'almacen',
    ];
    foreach (array_unique(array_column($duplicados, 'area')) as $area_existente) {
        $rol = $rol_por_area[$area_existente] ?? null;
        if (!$rol) continue;
        notificarRoles([$rol], 'requisicion_posible_duplicada', "Posible requisición duplicada",
            "$area_nueva generó la requisición $folio_nuevo pidiendo el mismo material que ustedes ya tienen en una requisición activa de este proyecto — revisen si es necesario duplicar.",
            'almacen2.php');
    }
}

// Guarda el aviso de duplicado en sesión -- sobrevive cualquier tipo de
// redirect que usen las distintas pantallas que crean requisiciones (header
// Location, redirect por JS, o ninguno). Ver renderAvisoDuplicado().
function avisarDuplicado(array $duplicados): void {
    if (!empty($duplicados)) $_SESSION['aviso_duplicado'] = $duplicados;
}

// Pinta (si hay) el aviso guardado por avisarDuplicado() y lo limpia. La
// página que lo llama debe tener definida la clase CSS .alert-warning.
function renderAvisoDuplicado(): void {
    if (empty($_SESSION['aviso_duplicado'])) return;
    $items = $_SESSION['aviso_duplicado'];
    unset($_SESSION['aviso_duplicado']);
    echo '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Posible requisición duplicada: ya existe una requisición activa en este proyecto para:<ul style="margin:6px 0 0 20px;">';
    foreach ($items as $it) {
        $fecha = htmlspecialchars(substr($it['fecha_creacion'] ?? '', 0, 10), ENT_QUOTES, 'UTF-8');
        echo '<li>' . htmlspecialchars($it['descripcion'] ?? '', ENT_QUOTES, 'UTF-8')
           . ' — folio ' . htmlspecialchars($it['folio'] ?? '', ENT_QUOTES, 'UTF-8')
           . ', pedida por ' . htmlspecialchars($it['area'] ?? '', ENT_QUOTES, 'UTF-8')
           . ' (' . htmlspecialchars($it['solicitante'] ?? '', ENT_QUOTES, 'UTF-8') . ') el ' . $fecha
           . '</li>';
    }
    echo '</ul></div>';
}

// Inserta o acumula un ítem en almacen_items para un proyecto — mismo patrón que
// usaban por separado "Liberar Producción" y la requisición individual por
// plano (si el material ya tiene fila para ese proyecto, suma cantidad en vez
// de duplicar la fila).
// $it: item, descripcion, cantidad y opcionalmente material_id, marca, modelo
// $origen: 'ingenieria' o 'produccion' -- alimenta cantidad_ingenieria/cantidad_produccion,
// el desglose que usa la barra de progreso dividida por departamento en "Items del proyecto".
function sincronizarAlmacenItem($db, int $proyecto_id, array $it, int $usuario_id, string $origen = 'produccion') {
    $mat_id      = $it['material_id'] ?? null;
    $stock       = 0;
    $modelo      = $it['modelo'] ?? '';
    $marca       = $it['marca'] ?? '';
    $item_nombre = $it['item'] ?? '';
    $col_origen  = $origen === 'ingenieria' ? 'cantidad_ingenieria' : 'cantidad_produccion';

    if ($mat_id) {
        $s_mat = $db->prepare("SELECT stock_disponible, descripcion FROM materiales WHERE id = ?");
        $s_mat->execute([$mat_id]);
        $mat_row = $s_mat->fetch();
        $stock = (int)($mat_row['stock_disponible'] ?? 0);
        if (!$item_nombre) $item_nombre = $mat_row['descripcion'] ?? '';

        $s_ex = $db->prepare("SELECT id, cantidad_requerida FROM almacen_items WHERE proyecto_id = ? AND material_id = ?");
        $s_ex->execute([$proyecto_id, $mat_id]);
        if ($ex = $s_ex->fetch()) {
            $nueva = $ex['cantidad_requerida'] + $it['cantidad'];
            $db->prepare("UPDATE almacen_items SET cantidad_requerida = ?, diferencia = stock_actual - ?, $col_origen = $col_origen + ? WHERE id = ?")
               ->execute([$nueva, $nueva, $it['cantidad'], $ex['id']]);
            return;
        }
    }

    $item_display = $item_nombre ?: ($it['descripcion'] ?? '');
    $db->prepare(
        "INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, modelo, marca, cantidad_requerida, $col_origen, stock_actual, diferencia, fecha_agregado, agregado_por) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?)"
    )->execute([$proyecto_id, $mat_id, $item_display, $it['descripcion'] ?? '', $modelo, $marca, $it['cantidad'], $it['cantidad'], $stock, $stock - $it['cantidad'], $usuario_id]);
}

// Inversa de sincronizarAlmacenItem() -- resta lo que ya se había sumado antes, para los
// flujos que borran y vuelven a crear su BOM en cada re-validación (ver api/validar_bom_plano.php:
// cada vez que Producción reprocesa/corrige un plano, el BOM anterior se descarta por completo,
// así que sin esto cada re-validación duplicaría la cantidad ya empujada a almacén en vez de
// reemplazarla). Sin material_id no hay fila que localizar -- nada que revertir.
function revertirAlmacenItem($db, int $proyecto_id, ?int $material_id, float $cantidad, string $origen = 'produccion'): void {
    if (!$material_id || $cantidad <= 0) return;
    $col_origen = $origen === 'ingenieria' ? 'cantidad_ingenieria' : 'cantidad_produccion';
    $s_ex = $db->prepare("SELECT id, cantidad_requerida FROM almacen_items WHERE proyecto_id = ? AND material_id = ?");
    $s_ex->execute([$proyecto_id, $material_id]);
    if (!($ex = $s_ex->fetch())) return;
    $nueva = max(0, $ex['cantidad_requerida'] - $cantidad);
    $db->prepare("UPDATE almacen_items SET cantidad_requerida = ?, diferencia = stock_actual - ?, $col_origen = GREATEST(0, $col_origen - ?) WHERE id = ?")
       ->execute([$nueva, $nueva, $cantidad, $ex['id']]);
}

// ── Consecutivos de código de material (2026-08-21) ──────────────────────────
// Diseñado con el usuario: solo el jefe de Almacén (o admin) puede escribir código de
// verdad -- Compras/Ingeniería solo ven una sugerencia de solo lectura al crear un material
// nuevo, que se guarda con codigo_verificado=0 hasta que Almacén lo revise (ver
// almacen2.php::editar_material). No bloquea el uso del material mientras tanto, decisión
// explícita para no frenar a Ingeniería/Producción.

// Mismo criterio ya usado para agrupar los 368 prefijos reales del catálogo: primeras 4
// letras de la descripción, ignorando espacios (si la primera palabra tiene menos de 4
// letras, sigue tomando de la siguiente sin contar el espacio -- "KIT DE EMPAQUE" -> "KITD").
function prefijoDescripcionMaterial(string $descripcion): string {
    $limpio = preg_replace('/\s+/u', '', trim($descripcion)) ?? '';
    return mb_strtoupper(mb_substr($limpio, 0, 4, 'UTF-8'), 'UTF-8');
}

// Sugiere el siguiente código libre para una descripción: prefijo (arriba) + MAX(consecutivo
// ya usado por ese prefijo)+1 a 6 dígitos. No garantiza que el prefijo sea la subcategoría
// "correcta" (el código real usa subcategorías más finas que la descripción sola no distingue,
// ej. TRAL/TRHX/TORX para distintos tipos de tornillo bajo la misma palabra "TORNILLO") --
// por eso siempre queda como sugerencia editable/revisable por Almacén, nunca definitivo.
// Null si la descripción es muy corta para sacar un prefijo real (<4 caracteres sin espacios).
function sugerirCodigoMaterial(PDO $db, string $descripcion): ?string {
    $prefijo = prefijoDescripcionMaterial($descripcion);
    if (mb_strlen($prefijo, 'UTF-8') < 4) return null;

    $stmt = $db->prepare("SELECT codigo FROM materiales WHERE codigo LIKE CONCAT(?, '%')");
    $stmt->execute([$prefijo]);
    $max = 0;
    $patron = '/^' . preg_quote($prefijo, '/') . '(\d{6})$/';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cod) {
        if (preg_match($patron, $cod, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefijo . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

// Decide qué código/codigo_verificado usar al crear o editar un material, según quién lo
// esté guardando -- llamado desde compras.php/ingenieria.php/almacen2.php::guardar_material
// y ::editar_material. Solo jefe de Almacén (o admin) puede escribir el código real; a
// cualquier otro se le ignora lo que haya mandado el formulario: si es un material nuevo se
// le calcula una sugerencia (sin verificar), si es una edición se conserva el código y el
// estado de verificación que el material ya tenía (no se toca a ciegas).
function resolverCodigoMaterial(PDO $db, ?string $codigoActual, ?int $verificadoActual, string $codigoEnviado, string $descripcion, bool $esNuevo): array {
    if (esJefeDe('almacen') || isAdmin()) {
        $codigo = trim($codigoEnviado) !== '' ? trim($codigoEnviado) : null;
        return ['codigo' => $codigo, 'verificado' => 1];
    }
    if ($esNuevo) {
        return ['codigo' => sugerirCodigoMaterial($db, $descripcion), 'verificado' => 0];
    }
    return ['codigo' => $codigoActual, 'verificado' => $verificadoActual ?? 1];
}

// Prefijos reales ya en uso en el catálogo (para el selector de Almacén) -- agrupados con
// un ejemplo de descripción de cada uno, para que el jefe reconozca a qué corresponde cada
// prefijo sin tener que adivinar (ej. "TRAL" -> "TORNILLO ALLEN...").
function prefijosMaterialesExistentes(PDO $db): array {
    return $db->query("
        SELECT LEFT(codigo, 4) AS prefijo, COUNT(*) AS n, MIN(descripcion) AS ejemplo
        FROM materiales
        WHERE codigo REGEXP '^[A-Z0-9]{4}[0-9]{6}$'
        GROUP BY prefijo
        ORDER BY prefijo
    ")->fetchAll();
}

// Cuando Almacén asigna/entrega cantidad de un material a un proyecto (vía almacen_items,
// botón "Asignar cantidad" en la vista de Proyectos), esto NO tocaba requisicion_detalle —
// el folio de una requisición con proyecto (ej. generada por Producción desde un plano) se
// quedaba mostrando "pendiente" para siempre aunque el material ya se hubiera entregado,
// porque ese surtido pasa por almacen_items, no por el botón "Surtir" de requisiciones.
// Reparte la cantidad recién entregada FIFO (requisición más antigua primero) entre los
// renglones de requisicion_detalle de ese proyecto/material que sigan pendientes — puede
// no haber ninguno (ej. el material solo vino del BOM de Ingeniería, que nunca genera
// folio), en cuyo caso no hay nada que marcar y no es un error.
function entregarRequisicionesDeProyecto(PDO $db, int $proyecto_id, int $material_id, float $cantidad_delta): void {
    if ($cantidad_delta <= 0) return;
    $stmt = $db->prepare(
        "SELECT rd.id, rd.requisicion_id, rd.cantidad, rd.cantidad_entregada
         FROM requisicion_detalle rd
         JOIN requisiciones r ON r.id = rd.requisicion_id
         WHERE r.proyecto_id = ? AND rd.material_id = ? AND rd.cantidad > rd.cantidad_entregada
         ORDER BY r.fecha_creacion ASC, rd.id ASC"
    );
    $stmt->execute([$proyecto_id, $material_id]);
    $pendientes = $stmt->fetchAll();

    $restante = $cantidad_delta;
    $requisiciones_afectadas = [];
    foreach ($pendientes as $det) {
        if ($restante <= 0) break;
        $pendiente_fila = $det['cantidad'] - $det['cantidad_entregada'];
        $aplicar = min($pendiente_fila, $restante);
        $db->prepare("UPDATE requisicion_detalle SET cantidad_entregada = cantidad_entregada + ? WHERE id = ?")
           ->execute([$aplicar, $det['id']]);
        $restante -= $aplicar;
        $requisiciones_afectadas[$det['requisicion_id']] = true;
    }
    foreach (array_keys($requisiciones_afectadas) as $req_id) {
        recalcularEstadoRequisicion($db, (int)$req_id);
    }
}

// Función para generar folio de orden de compra
// Formato: OC-{año}-{nombre_proyecto}{000}{consecutivo_por_proyecto}
function generarFolioOC($proyecto_id = null, $proyecto_nombre = null) {
    $db   = getDB();
    $year = date('Y');

    // Resolver nombre del proyecto
    if (!$proyecto_nombre && $proyecto_id) {
        $s = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $s->execute([$proyecto_id]);
        $proyecto_nombre = $s->fetchColumn();
    }

    if ($proyecto_nombre) {
        // Limpiar nombre: quitar espacios y caracteres problemáticos para el folio
        $slug = preg_replace('/\s+/', '', $proyecto_nombre);

        // Consecutivo por proyecto en el año actual
        $s = $db->prepare("SELECT COUNT(*) FROM ordenes_compra WHERE proyecto_id = ? AND YEAR(fecha_creacion) = ?");
        if ($proyecto_id) {
            $s->execute([$proyecto_id, $year]);
        } else {
            // Buscar por nombre en la tabla de proyectos para obtener el id
            $sp = $db->prepare("SELECT id FROM proyectos WHERE nombre = ? LIMIT 1");
            $sp->execute([$proyecto_nombre]);
            $pid_found = $sp->fetchColumn();
            if ($pid_found) {
                $s->execute([$pid_found, $year]);
            } else {
                // Sin proyecto en BD: contar por folio que empiece igual
                $s = $db->prepare("SELECT COUNT(*) FROM ordenes_compra WHERE folio LIKE ? AND YEAR(fecha_creacion) = ?");
                $s->execute(["OC-$year-{$slug}%", $year]);
            }
        }
        $consecutivo = str_pad((int)$s->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
        return "OC-$year-{$slug}{$consecutivo}";
    }

    // Sin proyecto: formato anterior como fallback
    $s = $db->prepare("SELECT COUNT(*) FROM ordenes_compra WHERE YEAR(fecha_creacion) = ?");
    $s->execute([$year]);
    $consecutivo = str_pad((int)$s->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
    return "OC-$year-$consecutivo";
}

// Función para generar folio de orden de trabajo
function generarFolioOT($proyecto_id = null, $proyecto_nombre = null) {
    $db   = getDB();
    $year = date('Y');
    if (!$proyecto_nombre && $proyecto_id) {
        $s = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $s->execute([$proyecto_id]);
        $proyecto_nombre = $s->fetchColumn();
    }
    $slug = $proyecto_nombre ? preg_replace('/\s+/', '', $proyecto_nombre) : 'GEN';
    $s = $db->prepare("SELECT COUNT(*) FROM ordenes_trabajo WHERE proyecto_id = ? AND YEAR(fecha_creacion) = ?");
    $s->execute([$proyecto_id ?: 0, $year]);
    $consecutivo = str_pad((int)$s->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);
    return "OT-{$slug}-{$consecutivo}";
}

// Función para generar folio de prueba FAT
function generarFolioFAT() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM pruebas_fat WHERE YEAR(fecha_creacion) = ?");
    $stmt->execute([$year]);
    $result = $stmt->fetch();
    $consecutivo = str_pad($result['total'] + 1, 4, '0', STR_PAD_LEFT);
    return "FAT-$year-$consecutivo";
}

/**
 * Valida y sanea el JSON de pasos de la Etapa 5 (Pruebas de Funcionalidad) de una prueba
 * FAT -- pruebas_fat.checklist y fat_plantillas.checklist_json usan la misma forma:
 * [{titulo, instrucciones, campos:[{tipo:'texto'|'opciones', etiqueta, unidad?, opciones?, valor}], resultado}].
 * Todo texto libre pasa por sanitize() aquí (nunca confiar en lo que ya venía saneado del
 * lado del cliente) -- mismo criterio reforzado tras el hallazgo de XSS en fatnCargarPlantilla.
 * $conservarValores=false (creación por Ingeniería): los campos siempre arrancan vacíos.
 * $conservarValores=true (captura por Producción): conserva lo capturado, validando que el
 * valor de un campo 'opciones' sea una de sus propias opciones declaradas.
 * Devuelve el JSON ya saneado, o null si no queda ningún paso válido (sin título).
 */
function fatSanitizarPasosJson(string $rawJson, bool $conservarValores = false): ?string {
    $pasos = json_decode($rawJson, true);
    if (!is_array($pasos)) return null;
    $limpios = [];
    foreach ($pasos as $p) {
        if (!is_array($p)) continue;
        $titulo = sanitize($p['titulo'] ?? '');
        if ($titulo === '') continue;
        $instrucciones = sanitize($p['instrucciones'] ?? '');
        $campos = [];
        foreach (($p['campos'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $etiqueta = sanitize($c['etiqueta'] ?? '');
            if ($etiqueta === '') continue;
            if (($c['tipo'] ?? '') === 'opciones') {
                $opciones = array_values(array_filter(array_map('sanitize', array_map('strval', (array)($c['opciones'] ?? [])))));
                if (!$opciones) $opciones = ['Abierto', 'Cerrado'];
                $valor = '';
                if ($conservarValores) {
                    $valorCandidato = sanitize((string)($c['valor'] ?? ''));
                    if (in_array($valorCandidato, $opciones, true)) $valor = $valorCandidato;
                }
                $campos[] = ['tipo' => 'opciones', 'etiqueta' => $etiqueta, 'opciones' => $opciones, 'valor' => $valor];
            } else {
                $unidad = sanitize($c['unidad'] ?? '');
                $valor  = $conservarValores ? sanitize((string)($c['valor'] ?? '')) : '';
                $campos[] = ['tipo' => 'texto', 'etiqueta' => $etiqueta, 'unidad' => $unidad, 'valor' => $valor];
            }
        }
        $resultado = null;
        if ($conservarValores) {
            $resultadoRaw = $p['resultado'] ?? null;
            $resultado = in_array($resultadoRaw, ['satisfactoria', 'no_satisfactoria'], true) ? $resultadoRaw : null;
        }
        $limpios[] = ['titulo' => $titulo, 'instrucciones' => $instrucciones, 'campos' => $campos, 'resultado' => $resultado];
    }
    if (!$limpios) return null;
    return json_encode($limpios, JSON_UNESCAPED_UNICODE);
}

// Número de serie de un Equipo Fabricado, asignado por Ingeniería: proyecto-año-consecutivo
// (mismo patrón que generarFolioOT). Consecutivo por proyecto+año, nunca reinicia entre proyectos.
function generarNumeroSerieEquipo(int $proyecto_id): string {
    $db   = getDB();
    $year = date('Y');
    $s = $db->prepare("SELECT nombre FROM proyectos WHERE id = ?");
    $s->execute([$proyecto_id]);
    $nombre = $s->fetchColumn();
    $slug = $nombre ? preg_replace('/\s+/', '', $nombre) : 'GEN';
    $stmt = $db->prepare("SELECT COUNT(*) FROM equipos_fabricados WHERE proyecto_id = ? AND YEAR(fecha_creacion) = ?");
    $stmt->execute([$proyecto_id, $year]);
    $consecutivo = str_pad((int)$stmt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);
    return "{$slug}-{$year}-{$consecutivo}";
}

function generarFolioSerieFabricada() {
    $db = getDB();
    $year = date('Y');
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM series_lotes WHERE origen = 'fabricado' AND YEAR(fecha_ingreso) = ?");
    $stmt->execute([$year]);
    $result = $stmt->fetch();
    $consecutivo = str_pad($result['total'] + 1, 5, '0', STR_PAD_LEFT);
    return "SN-FAB-$year-$consecutivo";
}

// Flash message queue — renderFlash() outputs with explicit htmlspecialchars (Semgrep-safe)
if (!isset($GLOBALS['_flash'])) $GLOBALS['_flash'] = [];

// Función para mostrar mensajes
function showMessage($message, $type = 'info') {
    $class = match($type) {
        'success' => 'alert-success',
        'error' => 'alert-error',
        'warning' => 'alert-warning',
        default => 'alert-info'
    };
    $GLOBALS['_flash'][] = ['text' => $message, 'class' => $class];
    return "<div class='alert $class'>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</div>";
}

// Render all queued flash messages with explicit escaping at output point
function renderFlash() {
    foreach ($GLOBALS['_flash'] as $f) {
        echo '<div class="alert ' . $f['class'] . '">'
           . htmlspecialchars($f['text'], ENT_QUOTES, 'UTF-8')
           . '</div>';
    }
    $GLOBALS['_flash'] = [];
}

// Función para formatear moneda
function formatMoney($amount, $currency = 'MXN') {
    $symbol = $currency === 'USD' ? '$' : '$';
    return $symbol . number_format($amount, 2) . ' ' . $currency;
}

// Función para subir archivos
function uploadFile($file, $directory = 'materiales/') {
    $uploadDir = UPLOAD_PATH . $directory;
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // El Content-Type del $_FILES lo manda el navegador y es falsificable, así que
    // no se usa para decidir nada: se detectan los magic bytes reales del archivo
    // y la extensión final sale solo de ese resultado, nunca del nombre original.
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $realMime = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $realMime = mime_content_type($file['tmp_name']);
    }

    if (!$realMime || !isset($allowedMimes[$realMime]) || !@getimagesize($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Tipo de archivo no permitido'];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'El archivo es demasiado grande (máx 5MB)'];
    }

    $fileName = time() . '_' . uniqid() . '.' . $allowedMimes[$realMime];
    $targetFile = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'file' => $directory . $fileName];
    }

    return ['success' => false, 'message' => 'Error al subir el archivo'];
}

// ===== FUNCIÓN PARA NOTIFICACIONES DEL CHAT =====
// Agregar al final del archivo, antes del cierre de PHP si existe

function crearNotificacion($usuario_id, $tipo, $titulo, $mensaje, $link = null) {
    $db = getDB();
    try {
        $stmt = $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, link) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$usuario_id, $tipo, $titulo, $mensaje, $link]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Envía una notificación a todos los usuarios activos con alguno de los roles dados.
 * El admin siempre recibe todas las notificaciones.
 * $exclude_id: id del usuario que disparó el evento (no se notifica a sí mismo).
 */
function notificarRoles(array $roles, string $tipo, string $titulo, string $mensaje, string $link = '', int $exclude_id = 0): void {
    $db = getDB();
    try {
        if (!in_array('admin', $roles)) $roles[] = 'admin';
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $params = $roles;
        $sql = "SELECT id FROM usuarios WHERE tipo_usuario IN ($placeholders) AND activo = 1";
        if ($exclude_id) {
            // Admin nunca se auto-excluye -- si el propio admin es quien disparó la
            // acción (ej. propone su propio reemplazo de material), igual debe
            // enterarse; la auto-exclusión solo tiene sentido para el rol que
            // realmente actuó (no le avisamos a Almacén de su propia requisición).
            $sql .= " AND (id != ? OR tipo_usuario = 'admin')";
            $params[] = $exclude_id;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $destinatarios = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Usuarios tipo 'multiple' con el rol dentro de su campo accesos (JSON o CSV, igual
        // que el login en index.php) — el filtro de arriba solo compara tipo_usuario exacto
        // y los dejaba fuera de TODAS las notificaciones de rol, sin importar el tipo.
        $sqlMult = "SELECT id, accesos FROM usuarios WHERE tipo_usuario = 'multiple' AND activo = 1";
        $paramsMult = [];
        if ($exclude_id) {
            $sqlMult .= " AND id != ?";
            $paramsMult[] = $exclude_id;
        }
        $stmtMult = $db->prepare($sqlMult);
        $stmtMult->execute($paramsMult);
        foreach ($stmtMult->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (empty($u['accesos'])) continue;
            $decoded = json_decode($u['accesos'], true);
            $accesosUsuario = is_array($decoded) ? $decoded : explode(',', $u['accesos']);
            if (array_intersect($roles, $accesosUsuario)) {
                $destinatarios[] = $u['id'];
            }
        }

        // administrador-proceso/operador-proceso tienen su acceso fijado en el login
        // (index.php: $_SESSION['user_accesos'] = ['manufactura','proceso'] / ['proceso']),
        // no en su campo accesos como los 'multiple' -- sin esto, notificarRoles(['manufactura'])
        // nunca les llegaba nada aunque su sesión sí les dé la vista de Producción/Proceso.
        $accesosImplicitos = [
            'administrador-proceso' => ['manufactura', 'proceso'],
            'operador-proceso'      => ['proceso'],
        ];
        foreach ($accesosImplicitos as $tipoImp => $rolesImplicitos) {
            if (!array_intersect($roles, $rolesImplicitos)) continue;
            $sqlImp = "SELECT id FROM usuarios WHERE tipo_usuario = ? AND activo = 1";
            $paramsImp = [$tipoImp];
            if ($exclude_id) {
                $sqlImp .= " AND id != ?";
                $paramsImp[] = $exclude_id;
            }
            $stmtImp = $db->prepare($sqlImp);
            $stmtImp->execute($paramsImp);
            foreach ($stmtImp->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $destinatarios[] = $uid;
            }
        }

        $link_val = $link ?: null;
        foreach (array_unique($destinatarios) as $uid) {
            crearNotificacion($uid, $tipo, $titulo, $mensaje, $link_val);
        }
    } catch (Exception $e) {
        // silently fail
    }
}

// ── Helpers de Proyectos y Calendario ─────────────────────────

function getColorProyecto(string $tipo): string {
    return $tipo === 'ingenieria' ? '#023760' : '#2E7D32';
}

/**
 * Retorna el fragmento SQL WHERE para filtrar proyectos según el rol activo.
 * No incluye la palabra AND — el llamador decide si la necesita.
 */
function getFiltroTipoProyecto(): string {
    if (isAdmin()) return '';
    if (hasAcceso('servicios')) return "tipo = 'servicios'";
    return "tipo = 'ingenieria'";
}

/**
 * Crea o cierra un evento de calendario para un proyecto.
 * Idempotente: si ya existe evento para el proyecto, solo actualiza fecha_fin.
 * $fecha_fin null = abrir evento (inicio). string 'Y-m-d' = cerrar evento.
 */
function registrarEventoCalendario(int $proyecto_id, string $titulo, string $tipo, ?string $fecha_fin = null): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM calendario_eventos WHERE proyecto_id = ? LIMIT 1");
    $stmt->execute([$proyecto_id]);
    $evento = $stmt->fetch();

    if ($fecha_fin !== null) {
        if ($evento) {
            $db->prepare("UPDATE calendario_eventos SET fecha_fin = ? WHERE proyecto_id = ?")
               ->execute([$fecha_fin, $proyecto_id]);
        }
        return;
    }

    if (!$evento) {
        $db->prepare("INSERT INTO calendario_eventos (proyecto_id, titulo, tipo, fecha_inicio) VALUES (?, ?, ?, CURDATE())")
           ->execute([$proyecto_id, $titulo, $tipo]);
    }
}

// ── Validación real de tipo de archivo por magic bytes ──────────────────────
/**
 * $_FILES['type'] lo manda el navegador y es falsificable; la extensión del
 * nombre tampoco prueba nada. Esta función decide por el contenido real del
 * archivo (finfo + firma), mismo criterio ya usado en uploadFile() para imágenes
 * de materiales. $allowedMimes: mime real => extensión permitida.
 * Devuelve la extensión real detectada, o false si no matchea ningún tipo permitido.
 */
function validarTipoArchivoReal(string $tmpName, array $allowedMimes) {
    $realMime = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $realMime = mime_content_type($tmpName);
    }
    if (!$realMime || !isset($allowedMimes[$realMime])) {
        return false;
    }
    if (strpos($realMime, 'image/') === 0 && !@getimagesize($tmpName)) {
        return false;
    }
    if ($realMime === 'application/pdf' && @file_get_contents($tmpName, false, null, 0, 5) !== '%PDF-') {
        return false;
    }
    return $allowedMimes[$realMime];
}

// ── Helper para subir planos (manufactura interna) ─────────────────────────
function uploadPlano(array $file): array {
    $uploadDir = UPLOAD_PATH . 'planos/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $realExt = validarTipoArchivoReal($file['tmp_name'], [
        'application/pdf' => 'pdf',
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
    ]);
    if (!$realExt) {
        return ['success' => false, 'message' => 'Solo se permiten PDF, JPG o PNG'];
    }
    if ($file['size'] > 15 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Archivo demasiado grande (máx 15 MB)'];
    }
    $fileName   = time() . '_' . uniqid() . '.' . $realExt;
    $targetFile = $uploadDir . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'file' => 'planos/' . $fileName];
    }
    return ['success' => false, 'message' => 'Error al mover el archivo'];
}

// ── Ledger unificado de movimientos de Almacén (Entradas/Salidas/Reingresos) ───────────
/**
 * Registra un movimiento de inventario de forma atómica: actualiza materiales.stock_disponible
 * y deja una fila en historial_movimientos. $delta positivo = entra stock, negativo = sale.
 * El stock nunca baja de 0 (mismo criterio que el resto del sistema). No abre su propia
 * transacción — si el llamador necesita atomicidad con otras operaciones, debe envolver la
 * llamada en su propio beginTransaction()/commit().
 * Devuelve el id del movimiento insertado en historial_movimientos.
 */
function registrarMovimientoAlmacen(PDO $db, int $materialId, string $tipo, float $delta, ?string $referenciaTipo = null, ?int $referenciaId = null, ?string $observaciones = null, ?int $proveedorId = null): int {
    // SELECT ... FOR UPDATE bloquea la fila del material hasta que termine la
    // transacción, así que un segundo movimiento concurrente sobre el mismo
    // material espera a que este termine en vez de leer el stock viejo (TOCTOU).
    // Si el caller ya tiene una transacción abierta (varios callers sí), nos unimos
    // a esa en vez de abrir una propia, para no romper su atomicidad.
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmtSel = $db->prepare("SELECT stock_disponible FROM materiales WHERE id = ? FOR UPDATE");
        $stmtSel->execute([$materialId]);
        $stockAnterior = (float) $stmtSel->fetchColumn();
        $stockNuevo    = max(0, $stockAnterior + $delta);

        $db->prepare("UPDATE materiales SET stock_disponible = ? WHERE id = ?")->execute([$stockNuevo, $materialId]);

        $stmtIns = $db->prepare("INSERT INTO historial_movimientos (tipo_movimiento, referencia_id, referencia_tipo, material_id, cantidad, stock_anterior, stock_nuevo, usuario_id, observaciones, proveedor_id, fecha_movimiento) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmtIns->execute([$tipo, $referenciaId, $referenciaTipo, $materialId, abs($delta), $stockAnterior, $stockNuevo, $_SESSION['user_id'] ?? null, $observaciones, $proveedorId]);
        $id = (int) $db->lastInsertId();

        if ($ownTransaction) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownTransaction) {
            $db->rollBack();
        }
        throw $e;
    }
}

// ── Cronograma/trazabilidad de requisiciones ────────────────────────────────
// Historial de pasos por los que va pasando una requisición (additivo: no
// reemplaza requisiciones.estado/estado_compra, que siguen siendo la fuente
// de verdad que usan Compras/Almacén -- esto solo registra cada transición
// con fecha/hora + quién + detalle opcional para poder pintar un cronograma).
// El $detalle (ej. qué materiales se surtieron) es sensible: solo Almacén/
// admin lo deben ver, quien solicitó la requisición no.
function registrarEventoRequisicion(PDO $db, int $requisicionId, string $estadoClave, ?string $detalle = null, ?int $usuarioId = null): void {
    $actorId = $usuarioId ?? ($_SESSION['user_id'] ?? null);
    $db->prepare("INSERT INTO requisicion_eventos (requisicion_id, estado_clave, detalle, usuario_id) VALUES (?, ?, ?, ?)")
       ->execute([$requisicionId, $estadoClave, $detalle, $actorId]);

    // Avisar a quien generó la requisición cada vez que avanza en su cronograma (2026-09-21,
    // pedido explícito del usuario -- se agrega aquí, en el único lugar por el que pasan TODAS
    // las transiciones, en vez de en cada uno de los llamadores). 'creada' se excluye (uno no se
    // auto-notifica de lo que acaba de crear) y 'vista' también (es una señal silenciosa interna
    // para el badge "Visto"/"No visto", no un avance real que interese avisar -- además su
    // $detalle es sensible, ver comentario de la función). Tampoco se notifica si quien disparó
    // el evento es la misma persona que generó la requisición.
    if (!in_array($estadoClave, ['creada', 'vista'], true)) {
        $stmtReq = $db->prepare("SELECT usuario_id, folio FROM requisiciones WHERE id = ?");
        $stmtReq->execute([$requisicionId]);
        $req = $stmtReq->fetch();
        if ($req && $req['usuario_id'] && (int)$req['usuario_id'] !== (int)$actorId) {
            $info = requisicionEstadoLabel($estadoClave);
            $db->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, link) VALUES (?, 'requisicion_actualizada', ?, ?, ?)")
               ->execute([
                   $req['usuario_id'],
                   "Requisición {$req['folio']}: {$info['label']}",
                   "Tu requisición {$req['folio']} avanzó a \"{$info['label']}\".",
                   'ver_requisicion.php?id=' . $requisicionId,
               ]);
        }
    }
}

// Marca "vista" la PRIMERA vez que alguien de Almacén o Compras (no quien la creó)
// abre el detalle de una requisición -- así el badge de "Mis Requisiciones" puede
// distinguir "No visto" de "Visto" en vez de mostrar siempre "Pendiente". No hace
// nada si ya tenía un evento 'vista' (evita duplicados en cada re-visita) ni si
// quien la ve es el propio solicitante (verla uno mismo no cuenta).
function marcarRequisicionVistaSiNueva(PDO $db, int $requisicionId, int $creadorUsuarioId): void {
    $viewerId = (int) ($_SESSION['user_id'] ?? 0);
    if ($viewerId && $viewerId === $creadorUsuarioId) return;
    $ya = $db->prepare("SELECT 1 FROM requisicion_eventos WHERE requisicion_id = ? AND estado_clave = 'vista' LIMIT 1");
    $ya->execute([$requisicionId]);
    if (!$ya->fetch()) {
        registrarEventoRequisicion($db, $requisicionId, 'vista');
    }
}

// Etiqueta + ícono legible para cada clave de estado del cronograma.
function requisicionEstadoLabel(string $clave): array {
    static $mapa = [
        'creada'              => ['label' => 'No vista',                 'icon' => 'fa-inbox'],
        'vista'                => ['label' => 'Vista',                   'icon' => 'fa-eye'],
        'cotizacion_enviada'   => ['label' => 'Cotizándose',              'icon' => 'fa-file-invoice-dollar'],
        'cotizacion_recibida'  => ['label' => 'Cotización recibida',      'icon' => 'fa-envelope-open-text'],
        'aprobada'             => ['label' => 'Aprobada',                 'icon' => 'fa-user-check'],
        'rechazada'            => ['label' => 'Rechazada',                'icon' => 'fa-times-circle'],
        'orden_generada'       => ['label' => 'Orden de compra generada', 'icon' => 'fa-file-signature'],
        'en_proceso'           => ['label' => 'En proceso de compra',     'icon' => 'fa-truck-loading'],
        'recepcion_parcial'    => ['label' => 'En recepción (parcial)',   'icon' => 'fa-dolly'],
        'recibido'             => ['label' => 'Recibido en almacén',      'icon' => 'fa-warehouse'],
        'en_entrega'           => ['label' => 'En entrega',               'icon' => 'fa-shipping-fast'],
        'completada'           => ['label' => 'Entregada / Completada',   'icon' => 'fa-check-double'],
        'cancelada'            => ['label' => 'Cancelada',                'icon' => 'fa-ban'],
    ];
    return $mapa[$clave] ?? ['label' => ucfirst(str_replace('_', ' ', $clave)), 'icon' => 'fa-circle'];
}

// Recalcula requisiciones.estado ('pendiente'/'en_proceso'/'completada') a partir de cuánto de
// requisicion_detalle ya está entregado — usado tanto por Surtir (almacen2.php) como por la
// recepción de OC para requisiciones sin proyecto, para que ambos caminos de entrega coincidan.
function recalcularEstadoRequisicion(PDO $db, int $requisicionId, ?string $detalleSurtido = null): string {
    $stmt = $db->prepare("SELECT cantidad, cantidad_entregada FROM requisicion_detalle WHERE requisicion_id = ?");
    $stmt->execute([$requisicionId]);
    $total_pend = 0;
    $total_entregado = 0;
    foreach ($stmt->fetchAll() as $t) {
        $total_pend += ($t['cantidad'] - $t['cantidad_entregada']);
        $total_entregado += $t['cantidad_entregada'];
    }
    $nuevo = $total_pend <= 0 ? 'completada' : ($total_entregado > 0 ? 'en_proceso' : 'pendiente');
    $db->prepare("UPDATE requisiciones SET estado = ? WHERE id = ?")->execute([$nuevo, $requisicionId]);
    if ($detalleSurtido !== null) {
        registrarEventoRequisicion($db, $requisicionId, $nuevo === 'completada' ? 'completada' : 'en_entrega', $detalleSurtido);
    }
    return $nuevo;
}

// ── Bloqueo de proveedor por proyecto ───────────────────────────────────────
// Cuando una recepción queda parcial o con incidencia de mal estado/otro
// problema, el proveedor responsable se bloquea PARA ESE PROYECTO -- Compras
// no debería poder volver a seleccionarlo al re-cotizar lo pendiente, salvo
// que alguien lo desbloquee a mano (los proveedores también se equivocan,
// no es un baneo permanente).
function bloquearProveedorProyecto(PDO $db, int $proveedor_id, int $proyecto_id, string $motivo, int $usuario_id): void {
    $stmt = $db->prepare("SELECT id FROM proveedor_bloqueado WHERE proveedor_id = ? AND proyecto_id = ? AND activo = 1");
    $stmt->execute([$proveedor_id, $proyecto_id]);
    if ($stmt->fetch()) return; // ya bloqueado, no duplicar
    $db->prepare("INSERT INTO proveedor_bloqueado (proveedor_id, proyecto_id, motivo, creado_por) VALUES (?,?,?,?)")
       ->execute([$proveedor_id, $proyecto_id, $motivo, $usuario_id]);
}

function proveedorBloqueadoEnProyecto(PDO $db, int $proveedor_id, int $proyecto_id): bool {
    $stmt = $db->prepare("SELECT id FROM proveedor_bloqueado WHERE proveedor_id = ? AND proyecto_id = ? AND activo = 1");
    $stmt->execute([$proveedor_id, $proyecto_id]);
    return (bool)$stmt->fetch();
}

// ── Caché de aplicación (Redis) ──────────────────────────────────────────────
// Solo pensado para catálogos casi estáticos que se consultan en CADA carga de
// página de varios módulos (categorías, etc.) -- no para datos que cambian
// seguido (nunca cachear proyectos/requisiciones/stock). Sin Redis disponible
// (XAMPP, o si el contenedor de Redis está caído) cache_remember() simplemente
// ejecuta el callback cada vez -- nunca rompe nada, en el peor caso no cachea.
function cache_redis(): ?Redis {
    static $redis = false; // false = todavía no se intentó conectar, null = no disponible
    if ($redis === false) {
        $host = getenv('REDIS_HOST');
        if ($host && extension_loaded('redis')) {
            try {
                $redis = new Redis();
                $redis->connect($host, 6379, 1.0);
            } catch (Throwable $e) {
                $redis = null;
            }
        } else {
            $redis = null;
        }
    }
    return $redis;
}

function cache_remember(string $key, int $ttlSegundos, callable $callback) {
    $redis = cache_redis();
    if (!$redis) return $callback();
    try {
        $cached = $redis->get($key);
        if ($cached !== false) return json_decode($cached, true);
    } catch (Throwable $e) {
        return $callback();
    }
    $valor = $callback();
    try { $redis->setex($key, $ttlSegundos, json_encode($valor)); } catch (Throwable $e) {}
    return $valor;
}

function cache_forget(string $key): void {
    $redis = cache_redis();
    if ($redis) { try { $redis->del($key); } catch (Throwable $e) {} }
}

// ── Rate-limit de login por IP ────────────────────────────────────────────────
// El bloqueo de cuenta (usuarios.bloqueado_hasta) es POR CUENTA -- alguien en la
// red interna podría probar muchos usuarios distintos desde la misma IP sin
// disparar ningún bloqueo individual. Esto cierra ese hueco, independiente del
// bloqueo por cuenta. Sin Redis disponible (XAMPP) simplemente no limita nada --
// nunca bloquea a nadie por un problema de infraestructura, es una capa extra.
function loginIpBloqueada(string $ip): bool {
    $redis = cache_redis();
    if (!$redis) return false;
    try {
        return (bool) $redis->exists("login_ip_block:$ip");
    } catch (Throwable $e) {
        return false;
    }
}

/** Cuenta un intento fallido para esta IP. Devuelve true si ESTE intento acaba de disparar el bloqueo. */
function registrarIntentoFallidoIp(string $ip, int $maxIntentos = 15, int $ventanaSegundos = 600, int $bloqueoSegundos = 1800): bool {
    $redis = cache_redis();
    if (!$redis) return false;
    try {
        $key = "login_ip_fails:$ip";
        $intentos = $redis->incr($key);
        if ($intentos === 1) $redis->expire($key, $ventanaSegundos);
        if ($intentos >= $maxIntentos) {
            $redis->setex("login_ip_block:$ip", $bloqueoSegundos, '1');
            $redis->del($key);
            return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function limpiarIntentosFallidosIp(string $ip): void {
    $redis = cache_redis();
    if (!$redis) return;
    try { $redis->del("login_ip_fails:$ip"); } catch (Throwable $e) {}
}

// Ejemplo de uso del caché de arriba: categorías activas se consultaba con la
// MISMA query en 5 archivos, en cada carga de página de esos módulos, para una
// tabla de 9 filas que casi nunca cambia.
function getCategoriasActivas(PDO $db): array {
    return cache_remember('categorias_activas', 300, function () use ($db) {
        return $db->query("SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre")->fetchAll();
    });
}

// ── Protección CSRF ──────────────────────────────────────────────────────────
// Token único por sesión (no por formulario) -- se genera una vez y se reutiliza
// mientras dure la sesión, sin importar cuántas pestañas/formularios tenga
// abiertos el usuario en simultáneo.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
define('CSRF_TOKEN', $_SESSION['csrf_token']);

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(CSRF_TOKEN, ENT_QUOTES, 'UTF-8') . '">';
}

// Verificación centralizada: se aplica a CUALQUIER POST de un usuario ya
// logueado, sin importar en qué archivo aterrice -- así no hace falta agregar
// la validación a mano en cada uno de los ~50 endpoints que reciben POST.
// El login (todavía sin sesión) queda exento a propósito: no hay token de
// sesión previo que validar, y ya está protegido con CAPTCHA aparte.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isLoggedIn()) {
    $csrf_recibido = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(CSRF_TOKEN, (string)$csrf_recibido)) {
        // Un token AUSENTE (nadie mandó nada) es la firma de un POST forjado --
        // eso se queda con el 403 duro de siempre, sin importar el origen.
        // Un token PRESENTE pero que no coincide es distinto: caso real más
        // común, la sesión expiró por inactividad (1h, ver
        // SESSION_TIMEOUT_SEGUNDOS) mientras el usuario llenaba un formulario
        // largo (ej. "Generar Requisición" con ~3600 materiales para elegir,
        // todo cliente-side hasta el submit final -- sin ningún request al
        // servidor que renueve la sesión mientras tanto). El token embebido en
        // la página queda viejo porque la sesión murió y se creó una nueva
        // vacía. Para un <form> normal (no AJAX -- nuestro propio fetch()
        // siempre manda X-CSRF-Token, ver más abajo) mejor mandar a loguearse
        // de nuevo con un mensaje claro que soltar el JSON crudo en pantalla.
        if ($csrf_recibido !== '' && !isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            header('Location: index.php?sesion_expirada=1');
            exit;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'ok' => false, 'error' => 'Token de seguridad inválido o ausente. Recarga la página e intenta de nuevo.']);
        exit;
    }
}

// Inyecta el token automáticamente en cada <form method="POST"> (incluso los
// creados dinámicamente por JS) y en cada fetch() -- así no hace falta tocar
// a mano los ~130 formularios ni los ~46 archivos con fetch() del sistema.
// No se aplica dentro de api/ ni ajax/: esos nunca renderizan HTML (ahí viven
// las descargas de archivos grandes como PDFs/Excel vía readfile()), y
// bufferear esa salida completa en memoria para buscar "</body>" sería puro
// desperdicio -- además nunca la van a tener.
//
// IMPORTANTE (bug real encontrado 2026-08-04): la versión anterior solo
// inyectaba el token al escuchar el evento "submit" -- pero
// HTMLFormElement.prototype.submit() (a diferencia de un click real en un
// botón submit, o .requestSubmit()) NUNCA dispara ese evento, por diseño del
// estándar web. Varias pantallas del sistema llaman form.submit() a mano
// (Generar Requisiciones, eliminar BOM, reutilizar BOM, crear OC manual,
// formOrdenProduccion) -- en todas ellas el token nunca se agregaba y el
// POST siempre fallaba con "Token de seguridad inválido o ausente". Ahora se
// inyecta a TODOS los <form method=POST> que ya existen en cuanto carga la
// página (no depende de ningún evento), y un MutationObserver cubre los que
// se crean dinámicamente después (ej. document.createElement('form') +
// appendChild + submit()). El listener de "submit" se deja como red de
// seguridad extra, no hace daño reinyectar sobre un form que ya lo tiene.
$_sr_script_path = $_SERVER['SCRIPT_FILENAME'] ?? '';
if (strpos($_sr_script_path, DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR) === false
    && strpos($_sr_script_path, DIRECTORY_SEPARATOR . 'ajax' . DIRECTORY_SEPARATOR) === false) {
    $_sr_inyectar_timeout = isLoggedIn() && !$_sr_es_proceso;
    ob_start(function ($html) use ($_sr_inyectar_timeout) {
        if (strpos($html, '</body>') === false) return $html; // no es una página HTML (JSON, texto, etc.)
        $tokenJs = json_encode(CSRF_TOKEN);
        $script = '<script>(function(){var t=' . $tokenJs . ';
function srInjectCsrf(f){
  if (f && f.tagName==="FORM" && (f.method||"").toLowerCase()==="post" && !f.querySelector("input[name=csrf_token]")) {
    var i=document.createElement("input"); i.type="hidden"; i.name="csrf_token"; i.value=t; f.appendChild(i);
  }
}
document.querySelectorAll("form").forEach(srInjectCsrf);
document.addEventListener("submit",function(e){ srInjectCsrf(e.target); }, true);
new MutationObserver(function(muts){
  muts.forEach(function(m){
    m.addedNodes.forEach(function(n){
      if (n.nodeType !== 1) return;
      if (n.tagName === "FORM") srInjectCsrf(n);
      else if (n.querySelectorAll) n.querySelectorAll("form").forEach(srInjectCsrf);
    });
  });
}).observe(document.documentElement, {childList:true, subtree:true});
var _fetch=window.fetch;
window.fetch=function(input, init){
  init = init || {};
  var method = (init.method || "GET").toUpperCase();
  if (method !== "GET" && method !== "HEAD") {
    init.headers = new Headers(init.headers || {});
    if (!init.headers.has("X-CSRF-Token")) init.headers.set("X-CSRF-Token", t);
  }
  return _fetch(input, init);
};
})();</script>';

        // Aviso de expiración de sesión por inactividad -- el timer del cliente arranca de
        // cero en cada carga de página real (el servidor es quien de verdad expira la sesión,
        // vía session.gc_maxlifetime). Solo se inyecta para usuarios ya logueados.
        if ($_sr_inyectar_timeout) {
            $total  = SESSION_TIMEOUT_SEGUNDOS;
            $aviso  = SESSION_AVISO_SEGUNDOS;
            $script .= '<script>(function(){
var TOTAL=' . $total . ',AVISO=' . $aviso . ',restante=TOTAL,avisado=false,cajaAviso=null;
var iv=setInterval(function(){
  restante--;
  if(!avisado && restante<=AVISO){ avisado=true; mostrarAvisoSesion(); }
  if(restante<=0){ clearInterval(iv); window.location.href="index.php"; }
},1000);
function mostrarAvisoSesion(){
  var mins=Math.max(1,Math.ceil(restante/60));
  cajaAviso=document.createElement("div");
  cajaAviso.style.cssText="position:fixed;bottom:20px;right:20px;background:#1B2D4E;color:#fff;padding:16px 20px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.3);z-index:999999;font-family:Montserrat,Arial,sans-serif;font-size:14px;max-width:320px;";
  cajaAviso.innerHTML="<div style=\"font-weight:700;margin-bottom:6px;\">⏱ Tu sesión está por expirar</div>"+
    "<div style=\"opacity:.85;margin-bottom:10px;\">Por inactividad, se cerrará en "+mins+" minuto(s).</div>"+
    "<button type=\"button\" style=\"background:#BE893B;border:none;color:#fff;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:600;\">Seguir conectado</button>";
  document.body.appendChild(cajaAviso);
  cajaAviso.querySelector("button").addEventListener("click",function(){
    fetch("ping_sesion.php").then(function(){
      if(cajaAviso){ cajaAviso.remove(); cajaAviso=null; }
      avisado=false; restante=TOTAL;
    });
  });
}
})();</script>';
        }

        return str_replace('</body>', $script . '</body>', $html);
    });
}
unset($_sr_script_path);