<?php
/**
 * aplicar_migraciones.php
 * Aplica todas las migraciones pendientes de forma segura e idempotente.
 * Acceder desde el navegador: http://localhost/sistema_requisiciones/aplicar_migraciones.php
 */
require_once 'includes/config.php';

// Solo admin o acceso local
if (!isAdmin() && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1','::1'])) {
    header('Location: index.php'); exit();
}

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log   = [];   // mensajes de lo aplicado
$errs  = [];   // errores no fatales

function ejecutar($db, $sql, $desc, &$log, &$errs) {
    try {
        $db->exec($sql);
        $log[] = "✅ $desc";
    } catch (PDOException $e) {
        // Duplicados de FK/índice son esperados (ya existen)
        $msg = $e->getMessage();
        if (str_contains($msg,'Duplicate') || str_contains($msg,'already exists') || str_contains($msg,'1060') || str_contains($msg,'1061') || str_contains($msg,'1068')) {
            $log[] = "⏭ $desc (ya existía)";
        } else {
            $errs[] = "⚠️ $desc → $msg";
        }
    }
}

function columnaExiste($db, $tabla, $columna) {
    $r = $db->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'")->rowCount();
    return $r > 0;
}
function tablaExiste($db, $tabla) {
    $r = $db->query("SHOW TABLES LIKE '$tabla'")->rowCount();
    return $r > 0;
}
function constraintExiste($db, $tabla, $nombre) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?");
    $stmt->execute([$tabla, $nombre]);
    return (bool) $stmt->fetchColumn();
}
function primaryKeyExiste($db, $tabla) {
    $stmt = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'");
    $stmt->execute([$tabla]);
    return (bool) $stmt->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════
// FASE 1 — Materiales, Proyectos, Calendario
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 1: Proyectos y Calendario ──</strong>';

ejecutar($db, "CREATE TABLE IF NOT EXISTS carpetas_materiales (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(100) NOT NULL,
    padre_id      INT NULL DEFAULT NULL,
    creado_por    INT NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla carpetas_materiales", $log, $errs);

if (!columnaExiste($db,'materiales','carpeta_id'))
    ejecutar($db,"ALTER TABLE materiales ADD COLUMN carpeta_id INT NULL DEFAULT NULL", "materiales.carpeta_id", $log, $errs);
else $log[]="⏭ materiales.carpeta_id (ya existía)";

if (!columnaExiste($db,'proyectos','tipo'))
    ejecutar($db,"ALTER TABLE proyectos ADD COLUMN tipo ENUM('ingenieria','servicios') NOT NULL DEFAULT 'ingenieria'", "proyectos.tipo", $log, $errs);
else $log[]="⏭ proyectos.tipo (ya existía)";

if (!columnaExiste($db,'proyectos','fecha_inicio'))
    ejecutar($db,"ALTER TABLE proyectos ADD COLUMN fecha_inicio DATE NULL DEFAULT NULL", "proyectos.fecha_inicio", $log, $errs);
else $log[]="⏭ proyectos.fecha_inicio (ya existía)";

if (!columnaExiste($db,'proyectos','fecha_finiquito'))
    ejecutar($db,"ALTER TABLE proyectos ADD COLUMN fecha_finiquito DATE NULL DEFAULT NULL", "proyectos.fecha_finiquito", $log, $errs);
else $log[]="⏭ proyectos.fecha_finiquito (ya existía)";

ejecutar($db,"CREATE TABLE IF NOT EXISTS calendario_eventos (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id       INT NOT NULL,
    titulo            VARCHAR(200) NOT NULL,
    tipo              ENUM('ingenieria','servicios') NOT NULL,
    fecha_inicio      DATE NOT NULL,
    fecha_fin         DATE NULL,
    creado_automatico TINYINT(1) DEFAULT 1,
    fecha_creacion    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla calendario_eventos", $log, $errs);

// `categorias` no tenía PRIMARY KEY ni UNIQUE en nombre — el INSERT IGNORE de abajo nunca
// ignoraba nada y cada corrida de este script volvía a sembrar las 6 categorías base,
// dejando filas huérfanas duplicadas con id=0. Se limpia y se blinda ANTES de sembrar,
// para que el INSERT IGNORE de abajo por fin funcione como se esperaba.
try { $db->exec("DELETE FROM categorias WHERE id = 0"); } catch (PDOException $e) {}
ejecutar($db, "ALTER TABLE categorias MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "categorias: PRIMARY KEY + AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE categorias ADD UNIQUE KEY uq_categoria_nombre (nombre)", "categorias: UNIQUE(nombre)", $log, $errs);
try { $db->exec("UPDATE categorias SET nombre = 'Material Eléctrico' WHERE nombre = 'Conector Eléctrico'"); $log[] = "✅ Categoría renombrada: Conector Eléctrico → Material Eléctrico"; } catch (PDOException $e) { $errs[] = "⚠️ Rename categoría → " . $e->getMessage(); }

// Agregar categorías base si no existen
$cats = [['Materia Prima',1],['Material Eléctrico',1],['Conector Neumático',1],['Empresa Ejemplo',1],['Herramienta',1],['Equipo',1]];
foreach ($cats as [$nombre,$activo]) {
    try {
        $db->prepare("INSERT IGNORE INTO categorias (nombre,activo) VALUES (?,?)")->execute([$nombre,$activo]);
    } catch (PDOException $e) {}
}
$log[] = "⏭ Categorías base (INSERT IGNORE)";

// Icono en categorias
if (!columnaExiste($db,'categorias','icono'))
    ejecutar($db,"ALTER TABLE categorias ADD COLUMN icono VARCHAR(50) NULL DEFAULT 'box'", "categorias.icono", $log, $errs);
else $log[]="⏭ categorias.icono (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 2A — BOM Mejorado + Manufactura
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 2A: BOM + Manufactura ──</strong>';

$cols_boom_items = ['certificados'=>"VARCHAR(500) NULL",'observacion'=>"TEXT NULL",'nota_libre'=>"TEXT NULL",'es_manufactura'=>"TINYINT(1) DEFAULT 0"];
foreach ($cols_boom_items as $col=>$def) {
    if (!columnaExiste($db,'boom_items',$col))
        ejecutar($db,"ALTER TABLE boom_items ADD COLUMN $col $def","boom_items.$col",$log,$errs);
    else $log[]="⏭ boom_items.$col (ya existía)";
}

$cols_boom_listas = [
    'finalizado'      => "TINYINT(1) DEFAULT 0",
    'fecha_finalizado'=> "DATETIME NULL",
    'finalizado_por'  => "INT NULL",
    'tipo_lista'      => "ENUM('principal','adicional','plano') DEFAULT 'principal'",
    'pieza_nombre'    => "VARCHAR(200) NULL",
    'plano_ruta'      => "VARCHAR(255) NULL",
    'observacion_fab' => "TEXT NULL",
];
foreach ($cols_boom_listas as $col=>$def) {
    if (!columnaExiste($db,'boom_listas',$col))
        ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN $col $def","boom_listas.$col",$log,$errs);
    else $log[]="⏭ boom_listas.$col (ya existía)";
}

ejecutar($db,"CREATE TABLE IF NOT EXISTS subproyectos_manufactura (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id    INT NOT NULL,
    boom_item_id   INT NOT NULL,
    nombre         VARCHAR(200) NOT NULL,
    observacion    TEXT NULL,
    plano_ruta     VARCHAR(255) NULL,
    estado         ENUM('pendiente','en_proceso','completado') DEFAULT 'pendiente',
    creado_por     INT NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proyecto_id)  REFERENCES proyectos(id)  ON DELETE CASCADE,
    FOREIGN KEY (boom_item_id) REFERENCES boom_items(id) ON DELETE CASCADE,
    FOREIGN KEY (creado_por)   REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla subproyectos_manufactura",$log,$errs);

ejecutar($db,"CREATE TABLE IF NOT EXISTS subproyecto_materiales (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    subproyecto_id INT NOT NULL,
    material_id    INT NULL,
    descripcion    VARCHAR(300) NOT NULL,
    cantidad       DECIMAL(10,2) NOT NULL DEFAULT 1,
    unidad_medida  VARCHAR(50) NULL,
    notas          TEXT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subproyecto_id) REFERENCES subproyectos_manufactura(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id)    REFERENCES materiales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla subproyecto_materiales",$log,$errs);

// ═══════════════════════════════════════════════════════════════
// FASE 2B — Usuarios teléfono
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 2B: Usuarios ──</strong>';
if (!columnaExiste($db,'usuarios','telefono'))
    ejecutar($db,"ALTER TABLE usuarios ADD COLUMN telefono VARCHAR(20) NULL","usuarios.telefono",$log,$errs);
else $log[]="⏭ usuarios.telefono (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 2C — Órdenes de compra estados
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 2C: Órdenes de Compra ──</strong>';
$cols_oc = [
    'estado'           => "ENUM('pendiente_aprobacion','aprobada','rechazada') NOT NULL DEFAULT 'pendiente_aprobacion'",
    'aprobado_por'     => "INT NULL",
    'fecha_aprobacion' => "DATETIME NULL",
    'firma_ruta'       => "VARCHAR(300) NULL",
    'motivo_rechazo'   => "TEXT NULL",
    'recibida_parcial' => "TINYINT(1) DEFAULT 0",
    'proyecto_id'      => "INT NULL",
];
foreach ($cols_oc as $col=>$def) {
    if (!columnaExiste($db,'ordenes_compra',$col))
        ejecutar($db,"ALTER TABLE ordenes_compra ADD COLUMN $col $def","ordenes_compra.$col",$log,$errs);
    else $log[]="⏭ ordenes_compra.$col (ya existía)";
}

// ═══════════════════════════════════════════════════════════════
// FASE 3 — Mejoras integrales
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 3: Mejoras integrales ──</strong>';
$cols_proy3 = [
    'responsable_id'   => "INT NULL",
    'supervisor_unlock'=> "TINYINT(1) DEFAULT 0",
    'unlock_expiry'    => "DATETIME NULL",
    'unlock_by'        => "INT NULL",
    'porcentaje_avance'=> "DECIMAL(5,2) DEFAULT 0",
];
foreach ($cols_proy3 as $col=>$def) {
    if (!columnaExiste($db,'proyectos',$col))
        ejecutar($db,"ALTER TABLE proyectos ADD COLUMN $col $def","proyectos.$col",$log,$errs);
    else $log[]="⏭ proyectos.$col (ya existía)";
}

$cols_bl3 = ['version'=>"INT DEFAULT 1",'version_anterior'=>"INT NULL"];
foreach ($cols_bl3 as $col=>$def) {
    if (!columnaExiste($db,'boom_listas',$col))
        ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN $col $def","boom_listas.$col",$log,$errs);
    else $log[]="⏭ boom_listas.$col (ya existía)";
}

ejecutar($db,"CREATE TABLE IF NOT EXISTS ordenes_trabajo (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    boom_lista_id   INT NOT NULL,
    proyecto_id     INT NOT NULL,
    titulo          VARCHAR(300) NOT NULL,
    descripcion     TEXT NULL,
    estado          ENUM('pendiente','en_proceso','completado','cancelado') DEFAULT 'pendiente',
    prioridad       ENUM('baja','normal','alta','urgente') DEFAULT 'normal',
    responsable_id  INT NULL,
    fecha_inicio    DATE NULL,
    fecha_compromiso DATE NULL,
    fecha_completado DATETIME NULL,
    creado_por      INT NOT NULL,
    fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (boom_lista_id) REFERENCES boom_listas(id) ON DELETE CASCADE,
    FOREIGN KEY (proyecto_id)  REFERENCES proyectos(id)   ON DELETE CASCADE,
    FOREIGN KEY (creado_por)   REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla ordenes_trabajo",$log,$errs);

ejecutar($db,"CREATE TABLE IF NOT EXISTS boom_versiones (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    boom_lista_id   INT NOT NULL,
    version         INT NOT NULL,
    snapshot_json   LONGTEXT NOT NULL,
    cambios_resumen TEXT NULL,
    creado_por      INT NOT NULL,
    fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (boom_lista_id) REFERENCES boom_listas(id) ON DELETE CASCADE,
    FOREIGN KEY (creado_por)   REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla boom_versiones",$log,$errs);

ejecutar($db,"CREATE TABLE IF NOT EXISTS proyecto_actividad (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id     INT NOT NULL,
    usuario_id      INT NULL,
    tipo            VARCHAR(80) NOT NULL,
    titulo          VARCHAR(300) NOT NULL,
    detalle         TEXT NULL,
    fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla proyecto_actividad",$log,$errs);

ejecutar($db,"CREATE TABLE IF NOT EXISTS boom_items_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    boom_item_id    INT NULL,
    boom_id         INT NULL,
    proyecto_id     INT NULL,
    usuario_id      INT NULL,
    accion          ENUM('agregar','editar','eliminar') NOT NULL,
    campo           VARCHAR(100) NULL,
    valor_anterior  TEXT NULL,
    valor_nuevo     TEXT NULL,
    fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla boom_items_log",$log,$errs);

// ═══════════════════════════════════════════════════════════════
// FASE 4 — Fases de proyecto y documentación
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 4: Fases y Documentación ──</strong>';
$cols_proy4 = [
    'fecha_inicio_ing' =>"DATE NULL",
    'fecha_fin_ing'    =>"DATE NULL",
    'fecha_inicio_proc'=>"DATE NULL",
    'fecha_fin_proc'   =>"DATE NULL",
    'fecha_inicio_mfg' =>"DATE NULL",
    'fecha_fin_mfg'    =>"DATE NULL",
    'fecha_inicio_fat' =>"DATE NULL",
    'fecha_fin_fat'    =>"DATE NULL",
];
foreach ($cols_proy4 as $col=>$def) {
    if (!columnaExiste($db,'proyectos',$col))
        ejecutar($db,"ALTER TABLE proyectos ADD COLUMN $col $def","proyectos.$col",$log,$errs);
    else $log[]="⏭ proyectos.$col (ya existía)";
}

ejecutar($db,"CREATE TABLE IF NOT EXISTS proyecto_fases (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id     INT NOT NULL,
    fase            ENUM('ingenieria','procura','manufactura','fat') NOT NULL,
    nombre_display  VARCHAR(100) NOT NULL DEFAULT '',
    pct_avance      DECIMAL(5,2) NOT NULL DEFAULT 0,
    estado          ENUM('pendiente','en_proceso','completado','cancelado') DEFAULT 'pendiente',
    fecha_inicio    DATE NULL,
    fecha_fin_plan  DATE NULL,
    fecha_fin_real  DATE NULL,
    responsable_id  INT NULL,
    notas           TEXT NULL,
    actualizado_por INT NULL,
    fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pf_fase (proyecto_id, fase),
    FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla proyecto_fases",$log,$errs);

ejecutar($db,"CREATE TABLE IF NOT EXISTS proyecto_documentos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id     INT NOT NULL,
    tipo            ENUM('plano','diagrama_electrico','diagrama_neumatico','manual','especificacion','memoria_calculo','certificado','foto','otro') DEFAULT 'otro',
    nombre          VARCHAR(300) NOT NULL,
    descripcion     TEXT NULL,
    ruta_archivo    VARCHAR(500) NULL,
    requerido       TINYINT(1) DEFAULT 1,
    recibido        TINYINT(1) DEFAULT 0,
    fecha_recibido  DATETIME NULL,
    recibido_por    INT NULL,
    revision        VARCHAR(20) NULL,
    fase            ENUM('ingenieria','procura','manufactura','fat','entrega') DEFAULT 'ingenieria',
    creado_por      INT NULL,
    fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla proyecto_documentos",$log,$errs);

$cols_ai = [
    'fincado'          =>"TINYINT(1) DEFAULT 0",
    'fincado_oc_id'    =>"INT NULL",
    'recibido_almacen' =>"TINYINT(1) DEFAULT 0",
    'categoria_id'     =>"INT NULL",
    'categoria_nombre' =>"VARCHAR(100) NULL",
];
foreach ($cols_ai as $col=>$def) {
    if (!columnaExiste($db,'almacen_items',$col))
        ejecutar($db,"ALTER TABLE almacen_items ADD COLUMN $col $def","almacen_items.$col",$log,$errs);
    else $log[]="⏭ almacen_items.$col (ya existía)";
}

// ═══════════════════════════════════════════════════════════════
// FASE 5 — BOM plantillas, estado plano, log
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 5: Plantillas BOM y estado plano ──</strong>';
$cols_bl5 = [
    'estado_plano'          => "ENUM('borrador','enviado','en_revision','aprobado','rechazado') DEFAULT 'borrador'",
    'plano_comentario'      => "TEXT NULL",
    'plano_comentario_fecha'=> "DATETIME NULL",
    'plano_comentario_por'  => "INT NULL",
];
foreach ($cols_bl5 as $col=>$def) {
    if (!columnaExiste($db,'boom_listas',$col))
        ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN $col $def","boom_listas.$col",$log,$errs);
    else $log[]="⏭ boom_listas.$col (ya existía)";
}

ejecutar($db,"CREATE TABLE IF NOT EXISTS bom_plantillas (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(300) NOT NULL,
    descripcion    TEXT NULL,
    num_equipos    INT DEFAULT 1,
    creado_por     INT NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    activo         TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla bom_plantillas",$log,$errs);

ejecutar($db,"CREATE TABLE IF NOT EXISTS bom_plantilla_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    plantilla_id INT NOT NULL,
    material_id  INT NULL,
    descripcion  VARCHAR(500) NOT NULL,
    marca        VARCHAR(200) NULL,
    modelo       VARCHAR(200) NULL,
    cantidad     INT NOT NULL DEFAULT 1,
    certificados VARCHAR(300) NULL,
    observacion  TEXT NULL,
    FOREIGN KEY (plantilla_id) REFERENCES bom_plantillas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Tabla bom_plantilla_items",$log,$errs);

// ═══════════════════════════════════════════════════════════════
// FASE 6 — FAT mejorado: protocolo, fechas programada/realizada
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 6: FAT mejorado ──</strong>';
$cols_fat6 = [
    'protocolo_existe' => "TINYINT(1) NULL",
    'fecha_programada' => "DATE NULL",
    'fecha_realizada'  => "DATE NULL",
];
foreach ($cols_fat6 as $col => $def) {
    if (!columnaExiste($db, 'pruebas_fat', $col))
        ejecutar($db, "ALTER TABLE pruebas_fat ADD COLUMN $col $def", "pruebas_fat.$col", $log, $errs);
    else $log[] = "⏭ pruebas_fat.$col (ya existía)";
}

// Directorio de planos
if (!is_dir(__DIR__ . '/uploads/planos')) {
    if (mkdir(__DIR__ . '/uploads/planos', 0755, true))
        $log[] = "✅ Carpeta uploads/planos creada";
    else
        $errs[] = "⚠️ No se pudo crear uploads/planos (créala manualmente)";
} else {
    $log[] = "⏭ Carpeta uploads/planos (ya existía)";
}

// ═══════════════════════════════════════════════════════════════
// FASE 7 — Liberación de BOM individual (Ingeniería)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 7: Liberación de BOM individual ──</strong>';
if (!columnaExiste($db,'boom_listas','enviado_almacen'))
    ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN enviado_almacen TINYINT(1) NOT NULL DEFAULT 0","boom_listas.enviado_almacen",$log,$errs);
else $log[]="⏭ boom_listas.enviado_almacen (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 8 — Número de revisión del BOM (pie de página / exportación)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 8: Revisión de BOM ──</strong>';
if (!columnaExiste($db,'boom_listas','revision_numero'))
    ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN revision_numero INT NOT NULL DEFAULT 0","boom_listas.revision_numero",$log,$errs);
else {
    ejecutar($db,"ALTER TABLE boom_listas MODIFY COLUMN revision_numero INT NOT NULL DEFAULT 0","boom_listas.revision_numero (default → 0)",$log,$errs);
    try { $db->exec("UPDATE boom_listas SET revision_numero = 0 WHERE revision_numero = 1"); $log[] = "✅ boom_listas.revision_numero: reseteados a 0 los que seguían en el default viejo (1)"; } catch (PDOException $e) { $errs[] = "⚠️ Reset revision_numero → " . $e->getMessage(); }
}

// ═══════════════════════════════════════════════════════════════
// FASE 9 — Módulo Indicadores (reporte trimestral DOC-GEN-FO-15)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 9: Indicadores ──</strong>';

ejecutar($db, "CREATE TABLE IF NOT EXISTS indicadores_reportes (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    area                  VARCHAR(20) NOT NULL,
    trimestre             TINYINT NOT NULL,
    anio                  INT NOT NULL,
    fecha_reporte         DATE DEFAULT NULL,
    representante_nombre  VARCHAR(150) DEFAULT NULL,
    representante_cargo   VARCHAR(150) DEFAULT NULL,
    porcentaje_requerido  DECIMAL(6,2) DEFAULT NULL,
    porcentaje_obtenido   DECIMAL(6,2) DEFAULT NULL,
    cumple                TINYINT(1) DEFAULT NULL,
    notas                 TEXT DEFAULT NULL,
    estado                ENUM('borrador','finalizado') NOT NULL DEFAULT 'borrador',
    revision_numero       INT NOT NULL DEFAULT 1,
    creado_por            INT DEFAULT NULL,
    fecha_creacion        DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_finalizado      DATETIME DEFAULT NULL,
    UNIQUE KEY uq_area_trim_anio (area, trimestre, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla indicadores_reportes", $log, $errs);

ejecutar($db, "CREATE TABLE IF NOT EXISTS indicadores_entradas (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    reporte_id         INT NOT NULL,
    etiqueta           VARCHAR(150) DEFAULT NULL,
    fecha_real         DATE DEFAULT NULL,
    fecha_programada   DATE DEFAULT NULL,
    fecha_referencia   DATE DEFAULT NULL,
    dias_reales        INT DEFAULT NULL,
    dias_programados   INT DEFAULT NULL,
    porcentaje         DECIMAL(6,2) DEFAULT NULL,
    nota               VARCHAR(500) DEFAULT NULL,
    orden              INT NOT NULL DEFAULT 0,
    KEY idx_reporte (reporte_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla indicadores_entradas", $log, $errs);

// ═══════════════════════════════════════════════════════════════
// FASE 10 — Orden manual de proyectos en Ver Proceso
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 10: Orden de proyectos en Ver Proceso ──</strong>';
if (!columnaExiste($db,'proyectos','orden_ver_proceso'))
    ejecutar($db,"ALTER TABLE proyectos ADD COLUMN orden_ver_proceso INT NOT NULL DEFAULT 0","proyectos.orden_ver_proceso",$log,$errs);
else $log[]="⏭ proyectos.orden_ver_proceso (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 11 — Varias requisiciones externas por OT
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 11: Requisiciones externas múltiples por OT ──</strong>';
if (!columnaExiste($db,'requisiciones','ot_id'))
    ejecutar($db,"ALTER TABLE requisiciones ADD COLUMN ot_id INT NULL","requisiciones.ot_id",$log,$errs);
else $log[]="⏭ requisiciones.ot_id (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 12 — Rediseño de Almacén: ubicación, ledger unificado, número de serie/lote
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 12: Almacén — ubicación, ledger, series/lotes ──</strong>';

if (!columnaExiste($db,'materiales','ubicacion'))
    ejecutar($db,"ALTER TABLE materiales ADD COLUMN ubicacion VARCHAR(100) NULL","materiales.ubicacion",$log,$errs);
else $log[]="⏭ materiales.ubicacion (ya existía)";

// Ledger unificado: agrega 'reingreso' al catálogo de tipos ya existente.
ejecutar($db,"ALTER TABLE historial_movimientos MODIFY COLUMN tipo_movimiento ENUM('entrada','salida','ajuste','requisicion','orden_compra','reingreso') NOT NULL","historial_movimientos: tipo 'reingreso'",$log,$errs);

// series_lotes existía en el esquema desde antes pero sin PRIMARY KEY y sin usarse en
// ningún lugar del código (0 filas) — se blinda y se extiende para trazabilidad completa:
// de qué proveedor/OC entró, en qué OT se consumió, quién la entregó y quién la recibió.
ejecutar($db,"ALTER TABLE series_lotes MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","series_lotes: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);
if (!columnaExiste($db,'series_lotes','material_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN material_id INT NULL AFTER item_id","series_lotes.material_id",$log,$errs);
else $log[]="⏭ series_lotes.material_id (ya existía)";
if (!columnaExiste($db,'series_lotes','proveedor_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN proveedor_id INT NULL","series_lotes.proveedor_id",$log,$errs);
else $log[]="⏭ series_lotes.proveedor_id (ya existía)";
if (!columnaExiste($db,'series_lotes','ot_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN ot_id INT NULL","series_lotes.ot_id",$log,$errs);
else $log[]="⏭ series_lotes.ot_id (ya existía)";
if (!columnaExiste($db,'series_lotes','usuario_entrada_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN usuario_entrada_id INT NULL","series_lotes.usuario_entrada_id",$log,$errs);
else $log[]="⏭ series_lotes.usuario_entrada_id (ya existía)";
if (!columnaExiste($db,'series_lotes','usuario_salida_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN usuario_salida_id INT NULL","series_lotes.usuario_salida_id",$log,$errs);
else $log[]="⏭ series_lotes.usuario_salida_id (ya existía)";
if (!columnaExiste($db,'series_lotes','movimiento_entrada_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN movimiento_entrada_id INT NULL","series_lotes.movimiento_entrada_id",$log,$errs);
else $log[]="⏭ series_lotes.movimiento_entrada_id (ya existía)";
if (!columnaExiste($db,'series_lotes','movimiento_salida_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN movimiento_salida_id INT NULL","series_lotes.movimiento_salida_id",$log,$errs);
else $log[]="⏭ series_lotes.movimiento_salida_id (ya existía)";

// historial_movimientos y almacen_movimientos también carecían de PRIMARY KEY/AUTO_INCREMENT
// (mismo problema que categorias y series_lotes) — sin esto, lastInsertId() siempre da 0 y
// nada puede referenciar un movimiento específico (ej. series_lotes.movimiento_entrada_id).
// Ambas están vacías (0 filas) en producción, así que el ALTER es 100% seguro.
ejecutar($db,"ALTER TABLE historial_movimientos MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","historial_movimientos: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);
ejecutar($db,"ALTER TABLE almacen_movimientos MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","almacen_movimientos: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);
// carpetas_materiales ya tenía ids únicos (sin choques) — blindarla antes de agregar la UI
// de carpetas al inventario de Almacén. material_proveedor está vacía, también segura.
ejecutar($db,"ALTER TABLE carpetas_materiales MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","carpetas_materiales: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);
ejecutar($db,"ALTER TABLE material_proveedor MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","material_proveedor: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);

// ═══════════════════════════════════════════════════════════════
// FASE 13 — Módulos Entradas y Salidas (número de serie / lote)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 13: Entradas/Salidas — series_lotes.item_id opcional ──</strong>';
// item_id venía NOT NULL desde el diseño original (pensado para anclar en almacen_items,
// una línea de proyecto). El rastreo por número de serie ahora ancla en material_id (la
// pieza física del catálogo), así que item_id debe poder quedar vacío en las entradas/salidas
// nuevas. No tiene FK ni datos existentes, así que el cambio es seguro.
ejecutar($db,"ALTER TABLE series_lotes MODIFY COLUMN item_id INT NULL","series_lotes.item_id ahora es opcional",$log,$errs);

// El proveedor de una entrada se guardaba SOLO cuando había OC seleccionada (se derivaba
// vía JOIN a ordenes_compra), así que si el usuario elegía proveedor sin OC ese dato se
// perdía. Ahora se guarda directo en el ledger, independiente de si hay OC o no.
if (!columnaExiste($db,'historial_movimientos','proveedor_id'))
    ejecutar($db,"ALTER TABLE historial_movimientos ADD COLUMN proveedor_id INT NULL","historial_movimientos.proveedor_id",$log,$errs);
else $log[]="⏭ historial_movimientos.proveedor_id (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 14 — Folio de trazabilidad en Salidas
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 14: Folio de Salidas ──</strong>';
if (!columnaExiste($db,'historial_movimientos','folio'))
    ejecutar($db,"ALTER TABLE historial_movimientos ADD COLUMN folio VARCHAR(30) NULL, ADD UNIQUE KEY uq_historial_folio (folio)","historial_movimientos.folio",$log,$errs);
else $log[]="⏭ historial_movimientos.folio (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 15 — proyectos.id sin PRIMARY KEY (causa raíz de contaminación cruzada)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 15: proyectos.id — PRIMARY KEY + AUTO_INCREMENT ──</strong>';
// proyectos.id no tenía PRIMARY KEY: varios puntos de creación calculan el siguiente id con
// SELECT COALESCE(MAX(id),0)+1, así que un proyecto nuevo podía terminar con el MISMO id que
// un proyecto ya borrado. Como eliminar_proyecto no limpiaba almacen_items (ver Fase de código),
// el proyecto nuevo "heredaba" materiales fantasma del proyecto viejo. 0 duplicados confirmados
// en producción antes de este cambio, así que el ALTER es seguro.
ejecutar($db,"ALTER TABLE proyectos MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","proyectos: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);

// ═══════════════════════════════════════════════════════════════
// FASE 16 — Hoja Viajera (Producción)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 16: Hoja Viajera ──</strong>';

ejecutar($db, "CREATE TABLE IF NOT EXISTS hoja_viajera (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id       INT NOT NULL,
    cliente           VARCHAR(200) NULL,
    no_serie          VARCHAR(100) NULL,
    id_tag            VARCHAR(100) NULL,
    producto          VARCHAR(300) NULL,
    n_retrabajos      INT NOT NULL DEFAULT 0,
    n_reprocesos      INT NOT NULL DEFAULT 0,
    resultado         ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
    creado_por        INT NULL,
    fecha_creacion    DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_finalizado  DATETIME NULL,
    UNIQUE KEY uq_hoja_viajera_proyecto (proyecto_id),
    FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
    FOREIGN KEY (creado_por)  REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla hoja_viajera", $log, $errs);

ejecutar($db, "CREATE TABLE IF NOT EXISTS hoja_viajera_pasos (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    hoja_id                INT NOT NULL,
    numero                 TINYINT NOT NULL,
    nombre                 VARCHAR(150) NOT NULL,
    tipo                   ENUM('simple','recubrimientos','detallado') NOT NULL DEFAULT 'simple',
    sub_valvula            TINYINT(1) NULL,
    sub_actuador           TINYINT(1) NULL,
    sub_accesorios         TINYINT(1) NULL,
    actividad_estado       ENUM('conforme','no_conforme') NULL,
    accion_realizada       ENUM('retrabajo','reproceso','no_se_requirio') NULL,
    nc_numero              VARCHAR(50) NULL,
    vobo_produccion        ENUM('pendiente','conforme','no_aplica') NOT NULL DEFAULT 'pendiente',
    vobo_produccion_por    INT NULL,
    vobo_produccion_fecha  DATETIME NULL,
    vobo_calidad           ENUM('pendiente','conforme','no_aplica') NOT NULL DEFAULT 'pendiente',
    vobo_calidad_por       INT NULL,
    vobo_calidad_fecha     DATETIME NULL,
    KEY idx_hoja (hoja_id),
    FOREIGN KEY (hoja_id) REFERENCES hoja_viajera(id) ON DELETE CASCADE,
    FOREIGN KEY (vobo_produccion_por) REFERENCES usuarios(id),
    FOREIGN KEY (vobo_calidad_por)     REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla hoja_viajera_pasos", $log, $errs);

if (!columnaExiste($db,'hoja_viajera','resultado_produccion'))
    ejecutar($db,"ALTER TABLE hoja_viajera
        ADD COLUMN resultado_produccion ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
        ADD COLUMN resultado_calidad ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
        ADD COLUMN ciclo_rechazo INT NOT NULL DEFAULT 0","hoja_viajera: cierre en dos etapas (Producción/Calidad)",$log,$errs);
else $log[]="⏭ hoja_viajera.resultado_produccion (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 17 — Aprobar OTs: aprobación de Producción antes que Calidad
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 17: Aprobación de Producción en OTs ──</strong>';
if (!columnaExiste($db,'ordenes_trabajo','produccion_aprobacion'))
    ejecutar($db,"ALTER TABLE ordenes_trabajo
        ADD COLUMN produccion_aprobacion ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
        ADD COLUMN produccion_fecha DATETIME NULL,
        ADD COLUMN produccion_nota VARCHAR(500) NULL","ordenes_trabajo: aprobación de Producción",$log,$errs);
else $log[]="⏭ ordenes_trabajo.produccion_aprobacion (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 18 — notificaciones.id sin PRIMARY KEY (mismo problema que proyectos.id
// en la Fase 15): al no tener AUTO_INCREMENT, la inmensa mayoría de las filas
// quedaron con id=0, así que "marcar como leída" una notificación marcaba de
// golpe todas las demás notificaciones con id=0 del mismo usuario.
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 18: notificaciones.id — PRIMARY KEY + AUTO_INCREMENT ──</strong>';
try {
    $db->exec("SET @maxid = (SELECT COALESCE(MAX(id),0) FROM notificaciones)");
    $db->exec("SET @rownum = 0");
    $afectadas = $db->exec("UPDATE notificaciones SET id = @maxid + (@rownum := @rownum + 1) WHERE id = 0 ORDER BY fecha_creacion");
    if ($afectadas > 0) $log[] = "✅ notificaciones: reasignados ids únicos a $afectadas fila(s) que seguían en id=0";
    else $log[] = "⏭ notificaciones: sin filas en id=0 por reasignar";
} catch (PDOException $e) {
    $errs[] = "⚠️ Reasignar ids de notificaciones → " . $e->getMessage();
}
ejecutar($db,"ALTER TABLE notificaciones MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)","notificaciones: PRIMARY KEY + AUTO_INCREMENT",$log,$errs);

// ═══════════════════════════════════════════════════════════════
// FASE 19 — Equipos Fabricados: series de piezas (por página de plano) + registro
// del equipo final con sus componentes vinculados + cierre total de proyecto
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 19: Equipos Fabricados ──</strong>';

if (!columnaExiste($db,'series_lotes','plano_pagina_id'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN plano_pagina_id INT NULL AFTER ot_id","series_lotes.plano_pagina_id",$log,$errs);
else $log[]="⏭ series_lotes.plano_pagina_id (ya existía)";

if (!columnaExiste($db,'series_lotes','origen'))
    ejecutar($db,"ALTER TABLE series_lotes ADD COLUMN origen ENUM('comprado','fabricado') NOT NULL DEFAULT 'comprado' AFTER material_id","series_lotes.origen",$log,$errs);
else $log[]="⏭ series_lotes.origen (ya existía)";

// series_lotes.estado ya es VARCHAR libre (no ENUM). Se agregan por convención de valor:
// 'Fabricado' (pieza producida internamente, disponible para integrarse a un equipo) y
// 'Integrado' (ya se usó como componente de un equipo_fabricado). No requiere ALTER de tipo.

ejecutar($db, "CREATE TABLE IF NOT EXISTS equipos_fabricados (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id       INT NOT NULL,
    numero_serie      VARCHAR(100) NULL,
    nombre            VARCHAR(200) NULL,
    estado            ENUM('borrador','completado') NOT NULL DEFAULT 'borrador',
    notas             TEXT NULL,
    creado_por        INT NULL,
    fecha_creacion    DATETIME DEFAULT CURRENT_TIMESTAMP,
    completado_por    INT NULL,
    fecha_completado  DATETIME NULL,
    UNIQUE KEY uq_equipo_serie (numero_serie),
    KEY idx_ef_proyecto (proyecto_id),
    FOREIGN KEY (proyecto_id)    REFERENCES proyectos(id) ON DELETE CASCADE,
    FOREIGN KEY (creado_por)     REFERENCES usuarios(id),
    FOREIGN KEY (completado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla equipos_fabricados", $log, $errs);

// numero_serie debe admitir NULL: un equipo recién generado no tiene serie todavía, y como
// tiene UNIQUE KEY, si la columna fuera NOT NULL (sin valor explícito, este motor la rellena
// con '' en vez de fallar) el segundo equipo sin serie chocaría contra el primero por duplicado.
// MySQL/MariaDB no considera NULL como duplicado en un índice único, así que NULL sí permite
// varios equipos "sin serie" a la vez.
ejecutar($db, "ALTER TABLE equipos_fabricados MODIFY COLUMN numero_serie VARCHAR(100) NULL", "equipos_fabricados.numero_serie admite NULL", $log, $errs);

ejecutar($db, "CREATE TABLE IF NOT EXISTS equipos_fabricados_componentes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    equipo_fabricado_id INT NOT NULL,
    serie_componente_id INT NOT NULL,
    agregado_por        INT NULL,
    fecha_creacion      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_efc_componente (serie_componente_id),
    KEY idx_efc_equipo (equipo_fabricado_id),
    FOREIGN KEY (equipo_fabricado_id) REFERENCES equipos_fabricados(id) ON DELETE CASCADE,
    FOREIGN KEY (serie_componente_id) REFERENCES series_lotes(id),
    FOREIGN KEY (agregado_por)        REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla equipos_fabricados_componentes", $log, $errs);

if (!columnaExiste($db,'proyectos','cierre_total'))
    ejecutar($db,"ALTER TABLE proyectos ADD COLUMN cierre_total TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN cierre_total_fecha DATETIME NULL,
        ADD COLUMN cierre_total_por INT NULL","proyectos: cierre total de manufactura",$log,$errs);
else $log[]="⏭ proyectos.cierre_total (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 20 — Serie de pieza a nivel de página de plano (PAGINA = PIEZA DE PLANO)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 20: plano_paginas.numero_serie ──</strong>';
if (!columnaExiste($db,'plano_paginas','numero_serie'))
    ejecutar($db,"ALTER TABLE plano_paginas ADD COLUMN numero_serie VARCHAR(100) NULL","plano_paginas.numero_serie",$log,$errs);
else $log[]="⏭ plano_paginas.numero_serie (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 21 — almacen_items admite requisiciones sin proyecto (Ingenieria: Generar Requisiciones)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 21: almacen_items.requisicion_id ──</strong>';
ejecutar($db, "ALTER TABLE almacen_items MODIFY COLUMN proyecto_id INT NULL", "almacen_items.proyecto_id admite NULL", $log, $errs);
if (!columnaExiste($db,'almacen_items','requisicion_id'))
    ejecutar($db,"ALTER TABLE almacen_items ADD COLUMN requisicion_id INT NULL AFTER proyecto_id","almacen_items.requisicion_id",$log,$errs);
else $log[]="⏭ almacen_items.requisicion_id (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 22 — requisiciones.estado_compra (proceso de cotización/OC para requisiciones sin proyecto)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 22: requisiciones.estado_compra ──</strong>';
if (!columnaExiste($db,'requisiciones','estado_compra'))
    ejecutar($db,"ALTER TABLE requisiciones ADD COLUMN estado_compra ENUM('cotizacion_enviada','cotizacion_recibida','orden_generada','recepcion_parcial','recibido') NULL AFTER estado","requisiciones.estado_compra",$log,$errs);
else $log[]="⏭ requisiciones.estado_compra (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 23 — cotizaciones.orden_generada_id (permitir varias OCs por requisición:
// si una cotización no cubre todo, se puede pedir a otro proveedor el faltante)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 23: cotizaciones.orden_generada_id ──</strong>';
if (!columnaExiste($db,'cotizaciones','orden_generada_id'))
    ejecutar($db,"ALTER TABLE cotizaciones ADD COLUMN orden_generada_id INT NULL AFTER estado","cotizaciones.orden_generada_id",$log,$errs);
else $log[]="⏭ cotizaciones.orden_generada_id (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 24 — requisiciones.folio UNIQUE (evita folios duplicados aunque
// generarFolioRequisicion() falle o corra en paralelo)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 24: requisiciones.folio UNIQUE ──</strong>';
ejecutar($db, "ALTER TABLE requisiciones ADD UNIQUE KEY uq_requisiciones_folio (folio)", "requisiciones.folio UNIQUE", $log, $errs);

// ═══════════════════════════════════════════════════════════════
// FASE 25 — boom_listas.requisicion_individual_generada (evita que "Liberar
// Producción" vuelva a pedir materiales de un plano que ya generó su propia
// requisición individual desde el botón "Generar Requisición" por plano)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 25: boom_listas.requisicion_individual_generada ──</strong>';
if (!columnaExiste($db,'boom_listas','requisicion_individual_generada'))
    ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN requisicion_individual_generada TINYINT(1) NOT NULL DEFAULT 0 AFTER estado_plano","boom_listas.requisicion_individual_generada",$log,$errs);
else $log[]="⏭ boom_listas.requisicion_individual_generada (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 26 — almacen_items.cantidad_ingenieria / cantidad_produccion (desglose
// por departamento para la barra de progreso dividida en "Items del proyecto")
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 26: almacen_items desglose por departamento ──</strong>';
if (!columnaExiste($db,'almacen_items','cantidad_ingenieria'))
    ejecutar($db,"ALTER TABLE almacen_items ADD COLUMN cantidad_ingenieria INT NOT NULL DEFAULT 0 AFTER cantidad_requerida","almacen_items.cantidad_ingenieria",$log,$errs);
else $log[]="⏭ almacen_items.cantidad_ingenieria (ya existía)";
if (!columnaExiste($db,'almacen_items','cantidad_produccion'))
    ejecutar($db,"ALTER TABLE almacen_items ADD COLUMN cantidad_produccion INT NOT NULL DEFAULT 0 AFTER cantidad_ingenieria","almacen_items.cantidad_produccion",$log,$errs);
else $log[]="⏭ almacen_items.cantidad_produccion (ya existía)";

// ═══════════════════════════════════════════════════════════════
// FASE 27 — proveedor_bloqueado (bloqueo de proveedor por proyecto cuando una
// recepción queda parcial o con incidencia de mal estado/otro problema, con
// desbloqueo manual)
// ═══════════════════════════════════════════════════════════════
$log[] = '<strong>── Fase 27: proveedor_bloqueado ──</strong>';
if (!tablaExiste($db,'proveedor_bloqueado')) {
    ejecutar($db, "CREATE TABLE proveedor_bloqueado (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT NOT NULL,
        proyecto_id INT NOT NULL,
        motivo VARCHAR(255) NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_por INT NULL,
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        desbloqueado_por INT NULL,
        fecha_desbloqueo DATETIME NULL,
        KEY idx_proveedor_proyecto (proveedor_id, proyecto_id, activo)
    )", "tabla proveedor_bloqueado", $log, $errs);
} else $log[]="⏭ tabla proveedor_bloqueado (ya existía)";

$log[] = '<strong>── Fase 28: bloqueo de cuenta por intentos fallidos de login ──</strong>';
ejecutar($db, "ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bloqueado_hasta DATETIME NULL",
    "usuarios.intentos_fallidos / bloqueado_hasta", $log, $errs);

$log[] = '<strong>── Fase 29: IDs manuales (MAX(id)+1) → AUTO_INCREMENT real ──</strong>';
// Estas tablas no tenían PRIMARY KEY en absoluto (el id se calculaba a mano con
// SELECT MAX(id)+1 en el código PHP) -- condición de carrera confirmada en la
// auditoría de seguridad: dos altas concurrentes podían calcular el mismo id y
// terminar con filas duplicadas silenciosamente (sin ni siquiera un error, por
// no haber ninguna restricción de unicidad). Requiere que la tabla no tenga ya
// duplicados en id -- si aplica sobre una instalación con datos viejos
// corruptos por este mismo bug, hay que limpiar los duplicados antes a mano.
ejecutar($db, "ALTER TABLE proveedores MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "proveedores.id AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE orden_compra_detalle MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "orden_compra_detalle.id AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE boom_items MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "boom_items.id AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE cotizaciones MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "cotizaciones.id AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE calidad_inspecciones MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "calidad_inspecciones.id AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE boom_listas MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "boom_listas.id AUTO_INCREMENT", $log, $errs);
ejecutar($db, "ALTER TABLE proyecto_fases MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "proyecto_fases.id AUTO_INCREMENT", $log, $errs);
// ordenes_produccion ya tenía PRIMARY KEY (con un DEFAULT 0 raro), solo le faltaba AUTO_INCREMENT.
ejecutar($db, "ALTER TABLE ordenes_produccion MODIFY id INT NOT NULL AUTO_INCREMENT", "ordenes_produccion.id AUTO_INCREMENT", $log, $errs);

$log[] = '<strong>── Fase 30: índices en columnas usadas constantemente en WHERE/JOIN ──</strong>';
// Antes solo existía el PRIMARY KEY (y los UNIQUE de folio) en la mayoría de
// tablas -- material_id/proyecto_id/requisicion_id/orden_id se filtran y cruzan
// todo el tiempo sin ningún índice que los respalde.
ejecutar($db, "ALTER TABLE almacen_items ADD INDEX idx_material (material_id), ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_requisicion (requisicion_id)", "índices en almacen_items", $log, $errs);
ejecutar($db, "ALTER TABLE historial_movimientos ADD INDEX idx_material (material_id), ADD INDEX idx_referencia (referencia_tipo, referencia_id)", "índices en historial_movimientos", $log, $errs);
ejecutar($db, "ALTER TABLE orden_compra_detalle ADD INDEX idx_orden (orden_id), ADD INDEX idx_material (material_id)", "índices en orden_compra_detalle", $log, $errs);
ejecutar($db, "ALTER TABLE boom_items ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_boom (boom_id), ADD INDEX idx_material (material_id)", "índices en boom_items", $log, $errs);
ejecutar($db, "ALTER TABLE requisicion_detalle ADD INDEX idx_requisicion (requisicion_id), ADD INDEX idx_material (material_id)", "índices en requisicion_detalle", $log, $errs);
ejecutar($db, "ALTER TABLE cotizaciones ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_proveedor (proveedor_id), ADD INDEX idx_requisicion (requisicion_id)", "índices en cotizaciones", $log, $errs);
ejecutar($db, "ALTER TABLE ordenes_compra ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_requisicion (requisicion_id), ADD INDEX idx_proveedor (proveedor_id), ADD INDEX idx_estado (estado)", "índices en ordenes_compra", $log, $errs);
ejecutar($db, "ALTER TABLE ordenes_trabajo ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_boom_lista (boom_lista_id), ADD INDEX idx_plano_pagina (plano_pagina_id)", "índices en ordenes_trabajo", $log, $errs);
ejecutar($db, "ALTER TABLE calidad_inspecciones ADD INDEX idx_oc (orden_compra_id), ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_material (material_id)", "índices en calidad_inspecciones", $log, $errs);
ejecutar($db, "ALTER TABLE proyecto_fases ADD INDEX idx_proyecto (proyecto_id)", "índice en proyecto_fases", $log, $errs);
ejecutar($db, "ALTER TABLE boom_listas ADD INDEX idx_proyecto (proyecto_id)", "índice en boom_listas", $log, $errs);
ejecutar($db, "ALTER TABLE requisiciones ADD INDEX idx_proyecto (proyecto_id), ADD INDEX idx_estado (estado)", "índices en requisiciones", $log, $errs);

$log[] = '<strong>── Fase 31: ot_actividades -- feature vieja retirada por completo ──</strong>';
// Sub-tareas por OT (crear/marcar estado/borrar actividad, horas est. vs. reales) --
// tenía un schema drift real (columnas faltantes, sin AUTO_INCREMENT/PK) que se corrigió
// primero, pero resultó ser una UI completamente huérfana: produccion.php tenía los 4
// endpoints sin ningún botón que los disparara, y la única UI real (en proyecto.php)
// llevaba tiempo deshabilitada con `if (false)`. El usuario decidió no revivirla -- lo
// que sí se quiere (tiempo real invertido por OT) ya existe vía
// ordenes_trabajo.tiempo_regular_min/tiempo_extra_min (llenado por proceso.php), mostrado
// en produccion.php?seccion=ordenes. DROP idempotente para instalaciones que ya la tenían.
ejecutar($db, "DROP TABLE IF EXISTS ot_actividades", "ot_actividades (tabla retirada)", $log, $errs);

// ═══════════════════════════════════════════════════════════════
// FASE 32 — Candado real: stock_disponible nunca negativo (2026-08-04)
// ═══════════════════════════════════════════════════════════════
// registrarMovimientoAlmacen() ya clampea con max(0, ...), pero era la única
// defensa -- el import del catálogo real (Excel) trajo 12 materiales con stock
// negativo heredado del control manual anterior, y el editor de materiales de
// compras.php guardaba stock_disponible del $_POST sin validar. Se corrigieron
// ambos puntos, pero además se agrega este CHECK como red de seguridad real en
// BD (mismo criterio que el UNIQUE KEY de requisiciones.folio, Fase 24).
$log[] = '<strong>── Fase 32: Candado de stock nunca negativo ──</strong>';
if (!constraintExiste($db, 'materiales', 'chk_materiales_stock_no_negativo')) {
    ejecutar($db, "UPDATE materiales SET stock_disponible = 0 WHERE stock_disponible < 0", "Corrige stock negativo preexistente", $log, $errs);
    ejecutar($db, "ALTER TABLE materiales ADD CONSTRAINT chk_materiales_stock_no_negativo CHECK (stock_disponible >= 0)", "materiales.stock_disponible >= 0 (CHECK)", $log, $errs);
} else {
    $log[] = "⏭ chk_materiales_stock_no_negativo (ya existía)";
}

// ═══════════════════════════════════════════════════════════════
// FASE 33 — Cimentación: nivel jerárquico por departamento (2026-08-04)
// ═══════════════════════════════════════════════════════════════
// Independiente de usuarios.accesos (que decide SI puede entrar a un módulo)
// -- esto decide qué tan limitado está el usuario DENTRO de ese módulo:
// 'trabajador' o 'jefe'. admin (isAdmin()) queda por encima de ambos, no usa
// esta tabla. Sin restricciones reales todavía en ninguna pantalla -- solo el
// punto único (nivelUsuario()/esJefeDe() en config.php) y la UI en admin.php
// para asignarlo. Ver includes/config.php.
$log[] = '<strong>── Fase 33: Nivel jerárquico por departamento (usuario_niveles) ──</strong>';
ejecutar($db, "CREATE TABLE IF NOT EXISTS usuario_niveles (
    usuario_id INT NOT NULL,
    modulo VARCHAR(50) NOT NULL,
    nivel ENUM('trabajador','jefe') NOT NULL DEFAULT 'trabajador',
    PRIMARY KEY (usuario_id, modulo),
    CONSTRAINT fk_usuario_niveles_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla usuario_niveles", $log, $errs);

// ═══════════════════════════════════════════════════════════════
// FASE 34 — Bug real: chat duplicaba usuarios en una lista interminable (2026-08-04)
// ═══════════════════════════════════════════════════════════════
// Causa raíz: usuarios_estado (usuario_id, estado, ultima_actividad) nunca tuvo
// PRIMARY KEY ni UNIQUE -- el INSERT ... ON DUPLICATE KEY UPDATE de
// actualizar_estado() (includes/ajax_chat.php, se llama cada 30s mientras el
// chat está abierto) nunca tenía con qué chocar, así que insertaba una fila
// NUEVA cada vez en vez de actualizar. obtener_contactos() hace LEFT JOIN
// contra esa tabla -- con usuarios con cientos de filas acumuladas, salían
// duplicados una y otra vez en la lista de contactos. Confirmado en vivo:
// 239 filas para solo 2 usuarios reales (uno con 234).
// usuarios_estado es solo presencia en vivo (online/offline), no hay nada
// que preservar -- se limpia completo y se agrega la PK para que el upsert
// funcione de verdad de ahora en adelante.
$log[] = '<strong>── Fase 34: Candado real en usuarios_estado (evita duplicados en el chat) ──</strong>';
if (!primaryKeyExiste($db, 'usuarios_estado')) {
    ejecutar($db, "TRUNCATE TABLE usuarios_estado", "Limpia usuarios_estado (solo presencia en vivo, se repuebla sola)", $log, $errs);
    ejecutar($db, "ALTER TABLE usuarios_estado ADD PRIMARY KEY (usuario_id)", "usuarios_estado.usuario_id PRIMARY KEY", $log, $errs);
} else {
    $log[] = "⏭ usuarios_estado ya tenía PRIMARY KEY";
}

// Snapshots congelados de BOM por proyecto (para imprimir/descargar desde
// Compras y Almacén). Se guarda un JSON de los items en cada impresión manual
// y automáticamente al finalizar un proyecto en Almacén -- así el BOM sigue
// disponible para descargar aunque el proyecto se finalice o los materiales
// cambien después (problema real: a veces se les olvida imprimir antes de
// finalizar y ya no hay forma de volver a ver los materiales que se pidieron).
$log[] = '<strong>── Fase 35: bom_snapshots (BOM imprimible/descargable por proyecto) ──</strong>';
if (!tablaExiste($db, 'bom_snapshots')) {
    ejecutar($db, "CREATE TABLE bom_snapshots (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id     INT NOT NULL,
        snapshot_json   LONGTEXT NOT NULL,
        origen          VARCHAR(40) NOT NULL DEFAULT 'impresion',
        creado_por      INT NULL,
        fecha_creacion  DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
        FOREIGN KEY (creado_por)  REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabla bom_snapshots", $log, $errs);
} else {
    $log[] = "⏭ bom_snapshots ya existía";
}

$log[] = '<strong>── Fase 36: equipos_fabricados.id_tag (asignado por Ingeniería) ──</strong>';
if (!columnaExiste($db,'equipos_fabricados','id_tag'))
    ejecutar($db,"ALTER TABLE equipos_fabricados ADD COLUMN id_tag VARCHAR(100) NULL AFTER numero_serie, ADD UNIQUE KEY uq_ef_id_tag (id_tag)","equipos_fabricados.id_tag",$log,$errs);
else $log[]="⏭ equipos_fabricados.id_tag (ya existía)";

$log[] = '<strong>── Fase 37: boom_items.categoria_doc (clasificación para documentos, no toca el catálogo real) ──</strong>';
if (!columnaExiste($db,'boom_items','categoria_doc'))
    ejecutar($db,"ALTER TABLE boom_items ADD COLUMN categoria_doc ENUM('componente_principal','conector','material_electrico','tornilleria') NULL AFTER nota_libre","boom_items.categoria_doc",$log,$errs);
else $log[]="⏭ boom_items.categoria_doc (ya existía)";

$log[] = '<strong>── Fase 38: boom_listas.tipo_valvula (lo pone Ingeniería, se manda a Producción) ──</strong>';
if (!columnaExiste($db,'boom_listas','tipo_valvula'))
    ejecutar($db,"ALTER TABLE boom_listas ADD COLUMN tipo_valvula VARCHAR(255) NULL AFTER pieza_nombre","boom_listas.tipo_valvula",$log,$errs);
else $log[]="⏭ boom_listas.tipo_valvula (ya existía)";

$log[] = '<strong>── Fase 39: hoja_viajera.pasadas_recubrimiento (total de pasadas de mano dadas en el recubrimiento) ──</strong>';
if (!columnaExiste($db,'hoja_viajera','pasadas_recubrimiento'))
    ejecutar($db,"ALTER TABLE hoja_viajera ADD COLUMN pasadas_recubrimiento INT NULL AFTER producto","hoja_viajera.pasadas_recubrimiento",$log,$errs);
else $log[]="⏭ hoja_viajera.pasadas_recubrimiento (ya existía)";

$log[] = '<strong>── Fase 40: permitir varias Hojas Viajeras por proyecto (quita UNIQUE de proyecto_id) ──</strong>';
if (constraintExiste($db,'hoja_viajera','uq_hoja_viajera_proyecto'))
    // DROP + ADD en el mismo ALTER (no en 2 sentencias separadas) -- proyecto_id sigue siendo
    // columna de una FOREIGN KEY (hoja_viajera_ibfk_1), MariaDB exige que el índice que la respalda
    // nunca deje de existir ni un instante, así que hay que reemplazarlo atómicamente por uno normal.
    ejecutar($db,"ALTER TABLE hoja_viajera DROP INDEX uq_hoja_viajera_proyecto, ADD INDEX idx_hoja_viajera_proyecto (proyecto_id)","hoja_viajera sin UNIQUE en proyecto_id",$log,$errs);
else $log[]="⏭ hoja_viajera.uq_hoja_viajera_proyecto (ya no existía)";

$log[] = '<strong>── Fase 41: auditoria.id — PRIMARY KEY + AUTO_INCREMENT (mismo bug de la Fase 29, tabla que se había quedado fuera) ──</strong>';
// auditoria nunca tuvo PRIMARY KEY: cada INSERT (sin especificar id) dejaba id=0 en modo no-estricto,
// así que las 1255 filas existentes están todas duplicadas en id=0. Hay que renumerarlas en orden
// cronológico antes de poder declarar id AUTO_INCREMENT (no se puede agregar una PK sobre duplicados).
if (tablaExiste($db,'auditoria') && !primaryKeyExiste($db,'auditoria')) {
    $db->exec("SET @rn := 0");
    ejecutar($db, "UPDATE auditoria SET id = (@rn := @rn + 1) ORDER BY fecha_hora, id", "auditoria.id renumerado", $log, $errs);
    ejecutar($db, "ALTER TABLE auditoria MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)", "auditoria.id AUTO_INCREMENT", $log, $errs);
} else $log[]="⏭ auditoria.id (ya tenía PRIMARY KEY o la tabla no existe)";

$log[] = '<strong>── Fase 42: fat_plantillas (checklists reutilizables de FAT) + quitar protocolo_existe muerto ──</strong>';
// protocolo_existe era un checkbox en el modal de crear FAT que se guardaba en BD pero nunca
// se leía/mostraba en ningún otro lugar del sistema -- el usuario pidió quitarlo por completo
// en vez de conectarlo a algo (2026-08-14), junto con convertir el modal a página completa
// con plantillas de checklist reutilizables.
ejecutar($db, "CREATE TABLE IF NOT EXISTS fat_plantillas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(255) NOT NULL,
        checklist_json LONGTEXT NOT NULL,
        creado_por INT NULL,
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "tabla fat_plantillas", $log, $errs);
if (columnaExiste($db,'pruebas_fat','protocolo_existe'))
    ejecutar($db, "ALTER TABLE pruebas_fat DROP COLUMN protocolo_existe", "pruebas_fat.protocolo_existe eliminada", $log, $errs);
else $log[]="⏭ pruebas_fat.protocolo_existe (ya no existía)";

$log[] = '<strong>── Fase 43: firma de Ingeniería en la cadena FAT + Etapa 5 como pasos estructurados ──</strong>';
// El usuario pidió (2026-08-14) agregar Ingeniería como PRIMER eslabón de firma de la FAT
// (antes solo la creaba, no firmaba): Ingeniería -> Producción -> Proyectos -> Calidad -> completado.
// checklist deja de ser una lista plana de tareas [{texto,ok}] y pasa a representar la
// Etapa 5 (Pruebas de Funcionalidad) del protocolo T490-FAT: una lista de pasos, cada uno
// con título, instrucciones, campos de captura tipados (texto u opciones) y un resultado
// (satisfactoria/no_satisfactoria) -- mismo criterio ya usado en el resto del proyecto para
// campos JSON estructurados (ver boom_items.checklist, hoja_viajera_pasos, etc.).
ejecutar($db, "ALTER TABLE pruebas_fat
        ADD COLUMN IF NOT EXISTS firma_ingenieria_ruta VARCHAR(300) NULL AFTER creado_por,
        ADD COLUMN IF NOT EXISTS firma_ingenieria_por INT NULL AFTER firma_ingenieria_ruta,
        ADD COLUMN IF NOT EXISTS firma_ingenieria_fecha DATETIME NULL AFTER firma_ingenieria_por",
    "pruebas_fat.firma_ingenieria_*", $log, $errs);
ejecutar($db, "ALTER TABLE pruebas_fat MODIFY estado ENUM('borrador','pendiente_ingenieria','pendiente_produccion','pendiente_calidad','pendiente_proyectos','completado') DEFAULT 'borrador'",
    "pruebas_fat.estado incluye pendiente_ingenieria", $log, $errs);

// Semilla real: Etapa 5 completa del protocolo T490-FAT (actuador de doble acción gas/aceite)
// que el usuario compartió como referencia de lo que debe poder armarse con el editor nuevo.
// Queda disponible desde el día uno como plantilla reutilizable, no solo como prueba de concepto.
if (!$db->query("SELECT COUNT(*) FROM fat_plantillas WHERE nombre = 'Actuador doble acción Gas/Aceite (T490-FAT)'")->fetchColumn()) {
    $campoTexto = fn($etiqueta, $unidad = '') => ['tipo'=>'texto', 'etiqueta'=>$etiqueta, 'unidad'=>$unidad, 'valor'=>''];
    $campoOpc   = fn($etiqueta, $opciones = ['Abierto','Cerrado']) => ['tipo'=>'opciones', 'etiqueta'=>$etiqueta, 'opciones'=>$opciones, 'valor'=>''];
    $pasosT490 = [
        [
            'titulo' => 'Condiciones iniciales',
            'instrucciones' => 'Verifique que la posición inicial del actuador sin suministro hidráulico coincida con la posición del indicador de posición.',
            'campos' => [ $campoOpc('Posición inicial'), $campoTexto('Lectura de la presión inicial', 'PSI') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Suministro eléctrico y neumático',
            'instrucciones' => 'Antes de proporcionar suministro eléctrico simule una presión en las tomas de proceso con nitrógeno para poder operar el equipo. Suministre 24 VCD tomados del panel solar para iniciar la carga de presión del sistema. Espere a que el sistema cargue completamente y registre la presión de carga completa (colocando el selector Auto-Fuera en Auto). Durante las pruebas, cuando el equipo presurice el sistema hidráulico, el nitrógeno regresará al cilindro.',
            'campos' => [ $campoTexto('Lectura de presión de la toma de proceso simulada', 'PSI'), $campoTexto('Lectura de suministro eléctrico', 'VCD'), $campoTexto('Lectura de la presión de paro', 'PSI') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Operación local del actuador — Cierre',
            'instrucciones' => 'Condición inicial: el actuador debe estar completamente presurizado y en posición de abierto; desconecte el suministro eléctrico para que el sistema no vuelva a cargar energía. Accione la palanca de la válvula direccional hacia cierre y sosténgala hasta que llegue a la posición de cerrado. Registre el tiempo de operación.',
            'campos' => [ $campoTexto('Tiempo de operación', 'segundos'), $campoOpc('Posición antes de la prueba'), $campoOpc('Posición después de la prueba') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Operación local del actuador — Apertura',
            'instrucciones' => 'Con el actuador en posición cerrado, accione la palanca de la válvula direccional hacia abrir y sosténgala hasta que llegue a la posición de abierto. Registre el tiempo de operación.',
            'campos' => [ $campoTexto('Tiempo de operación', 'segundos'), $campoOpc('Posición antes de la prueba'), $campoOpc('Posición después de la prueba') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Número de carreras realizadas',
            'instrucciones' => 'Registre el número total de carreras (ciclos apertura/cierre) realizadas durante la prueba de operación local.',
            'campos' => [ $campoTexto('Número de carreras realizadas') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Cierre por baja presión (PSL)',
            'instrucciones' => 'Condición inicial: el actuador debe estar abierto y completamente presurizado. Con el actuador en posición de abierto, simule una baja presión de 476 PSI cayendo en el puerto de la línea de proceso; el actuador se desplazará hacia su posición de cerrado.',
            'campos' => [ $campoTexto('Lectura de presión inicial en la línea de proceso', 'PSI'), $campoTexto('Lectura de baja presión en la línea de proceso', 'PSI'), $campoOpc('Posición del actuador antes de PSL'), $campoOpc('Posición del actuador después de PSL') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Operación con bomba hidráulica manual — Apertura',
            'instrucciones' => 'Condición inicial: el actuador debe estar cerrado y sin suministro hidráulico. En caso de falla de suministro hidráulico, configure el equipo para operar de forma manual y verifique que el actuador abre, usando la bomba hidráulica manual, hasta llegar a la posición de abierto. Registre la cantidad de operaciones.',
            'campos' => [ $campoTexto('Cantidad de operaciones hacia apertura del actuador') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Operación con bomba hidráulica manual — Cierre',
            'instrucciones' => 'Condición inicial: el actuador debe estar abierto y sin suministro hidráulico. En caso de falla de suministro hidráulico, configure el equipo para operar de forma manual y verifique que el actuador cierra, usando la bomba hidráulica manual, hasta llegar a la posición de cerrado. Registre la cantidad de operaciones.',
            'campos' => [ $campoTexto('Cantidad de operaciones hacia cierre del actuador') ],
            'resultado' => null,
        ],
        [
            'titulo' => 'Falla segura (última posición)',
            'instrucciones' => 'Condición inicial: el actuador debe estar abierto, con suministro eléctrico e hidráulico. Con el actuador en posición de abierto, desconecte el suministro eléctrico e hidráulico hacia el actuador; el actuador debe permanecer en la posición de abierto.',
            'campos' => [ $campoOpc('Posición del actuador antes de la falla segura'), $campoOpc('Posición del actuador después de la falla segura') ],
            'resultado' => null,
        ],
    ];
    ejecutar($db, "INSERT INTO fat_plantillas (nombre, checklist_json, creado_por) VALUES (" .
        $db->quote('Actuador doble acción Gas/Aceite (T490-FAT)') . ", " .
        $db->quote(json_encode($pasosT490, JSON_UNESCAPED_UNICODE)) . ", NULL)",
        "plantilla FAT T490 (Etapa 5 real)", $log, $errs);
} else $log[]="⏭ plantilla FAT T490 (ya existía)";

$log[] = '<strong>── Fase 44: alternativas de material (padre-hijo) + solicitudes de reemplazo ──</strong>';
// El usuario pidió (2026-08-19) que Almacén pueda proponer un material equivalente
// al surtir un BOM/requisición cuando el original no conviene o no hay stock --
// pero el swap no se aplica solo: el área dueña de la especificación (Ingeniería
// para BOM de proyecto, o el área que generó la requisición) tiene que aprobarlo
// primero vía esJefeDe(), porque a veces el material es específico y no es
// intercambiable aunque a Almacén le parezca equivalente. Ver includes/config.php
// (buscarAlternativasMaterial/proponerReemplazoMaterial/resolverReemplazoMaterial)
// y reemplazos_material.php (pantalla de aprobación de los jefes).
ejecutar($db, "CREATE TABLE IF NOT EXISTS material_alternativas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_id INT NOT NULL,
        alternativa_id INT NOT NULL,
        creado_por INT NULL,
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_material_alternativa (material_id, alternativa_id),
        KEY idx_alternativa_id (alternativa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "tabla material_alternativas", $log, $errs);
ejecutar($db, "CREATE TABLE IF NOT EXISTS material_reemplazo_solicitudes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        origen_tipo ENUM('almacen_item','requisicion_detalle') NOT NULL,
        origen_id INT NOT NULL,
        material_original_id INT NOT NULL,
        material_alternativa_id INT NOT NULL,
        cantidad DECIMAL(12,2) NOT NULL,
        proyecto_id INT NULL,
        requisicion_id INT NULL,
        area_responsable VARCHAR(30) NOT NULL,
        estado ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
        propuesto_por INT NULL,
        fecha_propuesta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resuelto_por INT NULL,
        fecha_resolucion DATETIME NULL,
        nota_solicitud VARCHAR(255) NULL,
        nota_resolucion VARCHAR(255) NULL,
        KEY idx_estado (estado),
        KEY idx_origen (origen_tipo, origen_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "tabla material_reemplazo_solicitudes", $log, $errs);

$log[] = '<strong>── Fase 45: registro contable al cierre de proyecto ──</strong>';
// Snapshot generado automáticamente por registrarCierreContable() (includes/config.php)
// cuando Proyectos hace el cierre total real (proyectos.cierre_total=1) -- gasto total
// (de OCs no rechazadas), tiempo invertido y horas trabajadas. Visible en Compras,
// nueva sección "Registro Contable". Pensado como insumo real para KPIs más adelante.
ejecutar($db, "CREATE TABLE IF NOT EXISTS proyecto_registro_contable (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id INT NOT NULL,
        gasto_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        tiempo_regular_min INT NOT NULL DEFAULT 0,
        tiempo_extra_min INT NOT NULL DEFAULT 0,
        total_ots INT NOT NULL DEFAULT 0,
        fecha_cierre DATETIME NOT NULL,
        fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proyecto (proyecto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "tabla proyecto_registro_contable", $log, $errs);

$log[] = '<strong>── Fase 46: registro contable — tiempo de Almacén, Calidad y Gerencia ──</strong>';
// Ampliación pedida (2026-08-20) tras revisar qué áreas tienen una fecha real de
// inicio/fin confiable: Almacén (solo vía requisiciones -- la vía directa BOM→
// almacen_items no tiene fecha de "aquí terminé", queda pendiente a propósito),
// Calidad (solo revisión de OTs -- la de OCs se descartó porque calidad_fecha ahí
// marca la inspección DESPUÉS de que el proveedor entregó, mezclaría tiempo de
// entrega externo con el trabajo real de Calidad) y Gerencia (aprobación de OC).
// Ingeniería se queda fuera por completo -- ver nota en registrarCierreContable().
ejecutar($db, "ALTER TABLE proyecto_registro_contable
        ADD COLUMN IF NOT EXISTS almacen_tiempo_min INT NOT NULL DEFAULT 0 AFTER total_ots,
        ADD COLUMN IF NOT EXISTS calidad_tiempo_min INT NOT NULL DEFAULT 0 AFTER almacen_tiempo_min,
        ADD COLUMN IF NOT EXISTS gerencia_tiempo_min INT NOT NULL DEFAULT 0 AFTER calidad_tiempo_min",
    "proyecto_registro_contable.almacen/calidad/gerencia_tiempo_min", $log, $errs);

$log[] = '<strong>── Fase 47: asistencia de operadores + reasignación ──</strong>';
// Pedido 2026-08-20: diseño híbrido acordado con el usuario -- "Iniciar"/"Reanudar"
// en proceso.php marca presencia automática (sin UI nueva para el caso normal), y
// una pantalla de jefe (produccion.php?seccion=asistencia) para ver quién sigue
// "sin_marcar" y reasignar sus OTs pendientes a otro operador. Un estado por
// operador+día, no una tabla de horarios/turnos completa.
ejecutar($db, "CREATE TABLE IF NOT EXISTS asistencia_operadores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        fecha DATE NOT NULL,
        estado ENUM('sin_marcar','activo','inactivo') NOT NULL DEFAULT 'sin_marcar',
        fecha_marcado DATETIME NULL,
        marcado_por INT NULL,
        UNIQUE KEY uq_usuario_fecha (usuario_id, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "tabla asistencia_operadores", $log, $errs);

$log[] = '<strong>── Fase 48: candado de Proyectos antes de que Gerencia apruebe una OC ──</strong>';
// Hueco real encontrado y cerrado (2026-08-20): no existía ningún candado entre la
// revisión de fecha de Proyectos y la aprobación de Gerencia -- $filtro_fecha_oc en
// proyecto.php es solo un buscador de lista, y el resaltado "es_tarde" es puramente
// visual. Gerencia podía aprobar una OC con fecha mala sin que Proyectos la hubiera
// revisado nunca. Ahora, para una OC ligada a un proyecto (directo o vía
// requisición), Gerencia no puede aprobarla hasta que Proyectos confirme la fecha
// (ver acción confirmar_fecha_oc en proyecto.php + el gate en api/aprobar_oc.php).
// OCs sin proyecto (requisiciones externas) no necesitan esto -- a Proyectos no le
// incumben.
ejecutar($db, "ALTER TABLE ordenes_compra
        ADD COLUMN IF NOT EXISTS revision_proyectos_ok TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS revision_proyectos_por INT NULL,
        ADD COLUMN IF NOT EXISTS revision_proyectos_fecha DATETIME NULL",
    "ordenes_compra.revision_proyectos_*", $log, $errs);

$log[] = '<strong>── Fase 49: registro contable — tiempo de Ingeniería y de Almacén-directo ──</strong>';
// Las 2 señales que quedaron fuera a propósito en la Fase 46 (ver registrarCierreContable()):
// - Ingeniería: ahora SÍ hay inicio confiable -- ordenes_produccion.fecha_difusion, el
//   momento en que Proyectos difunde la orden y arranca el proyecto de verdad (confirmado
//   por el usuario, 2026-08-20). El fin (proyecto_fases.fecha_fin_real) ya era confiable
//   desde julio, pero solo ahora vale la pena usarlo: antes ingenieria.php::finalizar_proyecto
//   podía "liberar" un proyecto sin mandar todos sus BOMs a Almacén sin avisar (ver el fix
//   de esa misma fecha) -- la fecha de fin ya se ponía bien, el problema era que el botón
//   que la dispara no garantizaba que el trabajo real ya estuviera completo.
// - Almacén-directo (BOM → almacen_items, sin pasar por una requisición -- cubre tanto el
//   BOM de Ingeniería como cualquier BOM que Producción empuje directo vía plano_ia): el
//   usuario confirmó que el inicio es cuando el primer material del BOM entra a
//   almacen_items (MIN(fecha_agregado), ya existía esa columna) y el fin es cuando Almacén
//   cierra su parte del proyecto (almacen_activo 1→0). proyectos.fecha_estado NO sirve para
//   ese fin -- la pisan muchas otras acciones a lo largo de la vida del proyecto (Ingeniería
//   libera, cambios de estado manuales, etc.) -- hace falta una columna dedicada que nadie
//   más toque.
ejecutar($db, "ALTER TABLE proyecto_registro_contable
        ADD COLUMN IF NOT EXISTS ingenieria_tiempo_min INT NOT NULL DEFAULT 0 AFTER gerencia_tiempo_min,
        ADD COLUMN IF NOT EXISTS almacen_directo_tiempo_min INT NOT NULL DEFAULT 0 AFTER ingenieria_tiempo_min",
    "proyecto_registro_contable.ingenieria/almacen_directo_tiempo_min", $log, $errs);
ejecutar($db, "ALTER TABLE proyectos
        ADD COLUMN IF NOT EXISTS almacen_directo_fecha_fin DATETIME NULL",
    "proyectos.almacen_directo_fecha_fin", $log, $errs);

$log[] = '<strong>── Fase 50: consecutivos de código de material + verificación de Almacén ──</strong>';
// Diseñado con el usuario (2026-08-21). Hoy codigo es texto libre sin candado -- cualquiera
// de las 3 áreas que crean materiales (Compras/Almacén/Ingeniería) puede escribirlo a mano,
// Ingeniería ni siquiera tiene el campo visible (siempre lo manda vacío). Nuevo flujo:
// - Solo el jefe de Almacén (o admin) puede escribir/corregir codigo de verdad -- ahí vive el
//   selector real de prefijo (dropdown de los ~368 ya usados) + consecutivo autogenerado
//   (MAX de ese prefijo + 1, editable). Cuando Compras/Ingeniería crean un material, el
//   sistema sugiere un código (primeras 4 letras de la descripción + siguiente consecutivo)
//   de solo lectura, y lo guarda como "sin verificar" -- Almacén revisa después, sin bloquear
//   el uso normal del material mientras tanto (decisión explícita: no frenar a
//   Ingeniería/Producción esperando esa revisión).
// - codigo_verificado: 1 por default (catálogo real ya existente, y cualquier material que
//   el propio jefe de Almacén cree/edite directamente -- ya lo confirmó él mismo). Se pone en
//   0 solo en el INSERT de Compras/Ingeniería, y vuelve a 1 en cuanto el jefe de Almacén
//   guarda una edición sobre ese material (ver almacen2.php::editar_material).
// - UNIQUE KEY sobre codigo como red de seguridad real, mismo criterio que
//   requisiciones.folio (Fase 24) -- antes hay que normalizar codigo='' (16 filas reales) a
//   NULL, MySQL permite varios NULL en una UNIQUE key pero no varios '' iguales.
ejecutar($db, "ALTER TABLE materiales
        ADD COLUMN IF NOT EXISTS codigo_verificado TINYINT(1) NOT NULL DEFAULT 1",
    "materiales.codigo_verificado", $log, $errs);
ejecutar($db, "UPDATE materiales SET codigo = NULL WHERE codigo = ''",
    "normalizar materiales.codigo='' a NULL", $log, $errs);
ejecutar($db, "ALTER TABLE materiales ADD UNIQUE KEY uq_materiales_codigo (codigo)",
    "materiales.codigo UNIQUE", $log, $errs);

$log[] = '<strong>── Fase 51: materiales nuevos/pendientes fijados al frente del Inventario ──</strong>';
// Pedido explícito del usuario (2026-08-21): un material nuevo o con código sin verificar
// (los mismos que ya llevan el sello rojo/verde) debe aparecer AL PRINCIPIO del Inventario
// de Almacén, no donde le toque por orden alfabético -- de lo contrario se pierde entre 3700+
// materiales. Se queda fijo ahí hasta que alguien lo abra por primera vez (marcar_visto en
// api/materiales.php, llamado desde abrirModalEditarMaterial en almacen2.php); después
// regresa a su orden normal, aunque siga sin verificar.
// primera_vista NULL = nunca se ha abierto desde que se creó -> va al frente. El catálogo
// real ya existente se backfillea a su fecha_creacion (ya fueron "vistos" durante años) para
// no fijar de golpe los 3700+ materiales existentes -- SOLO la primera vez que corre esta
// fase: si el backfill corriera en cada re-ejecución de este runner (idempotente por diseño),
// también borraría el NULL real de un material recién creado que sigue genuinamente pendiente.
$primeraVistaYaExistia = columnaExiste($db, 'materiales', 'primera_vista');
ejecutar($db, "ALTER TABLE materiales
        ADD COLUMN IF NOT EXISTS primera_vista DATETIME NULL",
    "materiales.primera_vista", $log, $errs);
if (!$primeraVistaYaExistia) {
    ejecutar($db, "UPDATE materiales SET primera_vista = fecha_creacion WHERE primera_vista IS NULL",
        "backfill materiales.primera_vista = fecha_creacion (catálogo existente, solo una vez)", $log, $errs);
} else {
    $log[] = "⏭ backfill materiales.primera_vista (ya se había corrido antes)";
}

$log[] = '<strong>── Fase 52: fat_protocolos (Protocolo formal de pruebas FAT, portada + datos generales) ──</strong>';
// El checklist interno de pruebas_fat (Etapa 5) ya existía; esta tabla guarda los datos de la
// Etapa 2 (marca/modelo/TAG/presión del actuador y de la válvula) que extrae una mini-IA a
// partir de la descripción de la Orden de Producción, editables por un humano antes de exportar
// el documento a PDF/Word. Un registro por Orden de Producción -- se regenera/edita, no se versiona.
ejecutar($db, "CREATE TABLE IF NOT EXISTS fat_protocolos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        orden_produccion_id INT NOT NULL,
        pruebas_fat_id INT NULL,
        datos_generales JSON NULL,
        creado_por INT NULL,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        actualizado_por INT NULL,
        fecha_actualizacion DATETIME NULL,
        UNIQUE KEY uq_orden_produccion (orden_produccion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "tabla fat_protocolos", $log, $errs);

$log[] = '<strong>── Fase 53: backfill -- deshacer doble escape HTML en proyecto_resumen/ordenes_produccion ──</strong>';
// Bug encontrado 2026-08-26: guardar_resumen_proyecto (proyecto.php) guardaba estos campos
// con sanitize() (que aplica htmlspecialchars), pero cada pantalla que los muestra (el propio
// formulario de resumen, ver_orden_produccion.php, el Protocolo FAT) YA los escapa de nuevo con
// htmlspecialchars() al desplegarlos -- el escape correcto va SOLO al mostrar, nunca al guardar.
// Resultado real: una descripción con 6" se guardaba como 6&quot; y se veía como 6&amp;quot; (o
// peor, cada vez que alguien reabría el resumen y le daba Guardar otra vez sin darse cuenta, se
// volvía a escapar la versión ya corrupta). Este backfill decodifica las entidades HTML
// acumuladas en los datos ya guardados -- decodificar texto que nunca tuvo entidades no hace
// nada (es un no-op), así que es seguro correrlo sobre todo el catálogo existente.
function _f53_decodificar(?string $v): ?string {
    if ($v === null || $v === '') return $v;
    // Puede haberse guardado corrupto más de una vez (guardar -> reabrir ya corrupto -> volver
    // a guardar) -- decodificar en bucle hasta que ya no cambie deshace todas las capas de una.
    for ($i = 0; $i < 5; $i++) {
        $dec = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
        if ($dec === $v) break;
        $v = $dec;
    }
    return $v;
}
$_f53_campos_pr = ['numero_po', 'cliente', 'descripcion', 'fecha_entrega', 'condiciones', 'observaciones'];
try {
    $_f53_filas = $db->query("SELECT id, " . implode(',', $_f53_campos_pr) . " FROM proyecto_resumen")->fetchAll(PDO::FETCH_ASSOC);
    $_f53_tocadas_pr = 0;
    foreach ($_f53_filas as $_f53_fila) {
        $_f53_set = [];
        $_f53_vals = [];
        foreach ($_f53_campos_pr as $_f53_campo) {
            $_f53_limpio = _f53_decodificar($_f53_fila[$_f53_campo]);
            if ($_f53_limpio !== $_f53_fila[$_f53_campo]) {
                $_f53_set[] = "$_f53_campo = ?";
                $_f53_vals[] = $_f53_limpio;
            }
        }
        if ($_f53_set) {
            $_f53_vals[] = $_f53_fila['id'];
            $db->prepare("UPDATE proyecto_resumen SET " . implode(',', $_f53_set) . " WHERE id = ?")->execute($_f53_vals);
            $_f53_tocadas_pr++;
        }
    }
    $log[] = "✅ backfill proyecto_resumen: $_f53_tocadas_pr fila(s) con entidades HTML decodificadas";
} catch (Throwable $e) { $errs[] = "backfill proyecto_resumen: " . $e->getMessage(); }

try {
    $_f53_filas2 = $db->query("SELECT id, cliente, descripcion, orden_compra FROM ordenes_produccion")->fetchAll(PDO::FETCH_ASSOC);
    $_f53_tocadas_op = 0;
    foreach ($_f53_filas2 as $_f53_fila2) {
        $_f53_set2 = [];
        $_f53_vals2 = [];
        foreach (['cliente', 'descripcion', 'orden_compra'] as $_f53_campo2) {
            $_f53_limpio2 = _f53_decodificar($_f53_fila2[$_f53_campo2]);
            if ($_f53_limpio2 !== $_f53_fila2[$_f53_campo2]) {
                $_f53_set2[] = "$_f53_campo2 = ?";
                $_f53_vals2[] = $_f53_limpio2;
            }
        }
        if ($_f53_set2) {
            $_f53_vals2[] = $_f53_fila2['id'];
            $db->prepare("UPDATE ordenes_produccion SET " . implode(',', $_f53_set2) . " WHERE id = ?")->execute($_f53_vals2);
            $_f53_tocadas_op++;
        }
    }
    $log[] = "✅ backfill ordenes_produccion: $_f53_tocadas_op fila(s) con entidades HTML decodificadas";
} catch (Throwable $e) { $errs[] = "backfill ordenes_produccion: " . $e->getMessage(); }

try {
    $_f53_filas3 = $db->query("SELECT id, descripcion FROM proyectos WHERE descripcion IS NOT NULL AND descripcion <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $_f53_tocadas_p = 0;
    foreach ($_f53_filas3 as $_f53_fila3) {
        $_f53_limpio3 = _f53_decodificar($_f53_fila3['descripcion']);
        if ($_f53_limpio3 !== $_f53_fila3['descripcion']) {
            $db->prepare("UPDATE proyectos SET descripcion = ? WHERE id = ?")->execute([$_f53_limpio3, $_f53_fila3['id']]);
            $_f53_tocadas_p++;
        }
    }
    $log[] = "✅ backfill proyectos.descripcion: $_f53_tocadas_p fila(s) con entidades HTML decodificadas";
} catch (Throwable $e) { $errs[] = "backfill proyectos.descripcion: " . $e->getMessage(); }

$log[] = '<strong>── Fase 54: fat_protocolos -- archivo del FAT firmado en papel, subido a mano ──</strong>';
// Después de imprimir el PDF/Word, llenarlo a mano y firmarlo físicamente, se sube el escaneo/foto
// (o el mismo Word/PDF ya firmado digitalmente) como evidencia -- pestaña "Subir FAT" en
// fat_protocolo.php. Un archivo por Orden de Producción -- volver a subir reemplaza al anterior.
ejecutar($db, "ALTER TABLE fat_protocolos
        ADD COLUMN IF NOT EXISTS archivo_subido_ruta VARCHAR(300) NULL,
        ADD COLUMN IF NOT EXISTS archivo_subido_nombre VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS archivo_subido_por INT NULL,
        ADD COLUMN IF NOT EXISTS archivo_subido_fecha DATETIME NULL", "fat_protocolos.archivo_subido_*", $log, $errs);

$log[] = '<strong>── Fase 55: fat_protocolos -- revisión de Producción sobre el Protocolo FAT subido ──</strong>';
// Aprobar/rechazar el documento firmado en papel (archivo_subido_* de la Fase 54) -- SIN firma
// digital, las firmas son solo en físico (2026-08-28). Objeto y flujo distintos de la cadena de
// firmas vieja de pruebas_fat (api/fat_firma.php); por ahora solo Producción revisa -- Proyectos
// y Calidad se agregan después si hacen falta. Volver a subir un archivo (Fase 54) reinicia esto
// a 'pendiente' -- ver api/fat_protocolo_subir_archivo.php.
ejecutar($db, "ALTER TABLE fat_protocolos
        ADD COLUMN IF NOT EXISTS revision_estado ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
        ADD COLUMN IF NOT EXISTS revision_por INT NULL,
        ADD COLUMN IF NOT EXISTS revision_fecha DATETIME NULL,
        ADD COLUMN IF NOT EXISTS revision_motivo VARCHAR(500) NULL", "fat_protocolos.revision_*", $log, $errs);

$log[] = '<strong>── Fase 56: reemplazo de material -- varios candidatos para que Ingeniería elija ──</strong>';
// El usuario pidió (2026-09-04) que Almacén pueda ofrecer más de un material alterno
// por solicitud -- Almacén no siempre sabe cuál encaja mejor técnicamente, así que en
// vez de adivinar uno solo, propone varios y el jefe del área responsable elige cuál
// aplicar al aprobar. material_alternativa_id sigue siendo el candidato único (o el
// primero de la lista, como default) para no romper el flujo ya existente de una sola
// opción; opciones_json solo se llena cuando Almacén propuso más de una (NULL = sigue
// siendo el caso normal de un solo candidato). Ver proponerReemplazoMaterial() /
// resolverReemplazoMaterial() en includes/config.php.
ejecutar($db, "ALTER TABLE material_reemplazo_solicitudes
        ADD COLUMN IF NOT EXISTS opciones_json TEXT NULL,
        ADD COLUMN IF NOT EXISTS material_elegido_id INT NULL", "material_reemplazo_solicitudes.opciones_json/material_elegido_id", $log, $errs);

$log[] = '<strong>── Fase 57: fat_protocolos -- Calidad y Proyectos también aprueban (además de Producción) ──</strong>';
// La Fase 55 dejó anotado "Proyectos y Calidad se agregan después si hacen falta" -- ya hacen
// falta (2026-09-06). revision_estado/revision_por/revision_fecha/revision_motivo (Fase 55)
// siguen siendo la aprobación de PRODUCCIÓN sin cambiar de nombre, para no tocar todo lo que ya
// los usa; Calidad y Proyectos son su propio juego de columnas, cada quien aprueba/rechaza el
// mismo documento de forma independiente. "Fecha de cierre" real del FAT (la que pide el
// indicador DOC-GEN-FO-15 de Proyectos) = cuando las 3 áreas ya dijeron aprobado, no antes.
ejecutar($db, "ALTER TABLE fat_protocolos
        ADD COLUMN IF NOT EXISTS revision_calidad_estado ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
        ADD COLUMN IF NOT EXISTS revision_calidad_por INT NULL,
        ADD COLUMN IF NOT EXISTS revision_calidad_fecha DATETIME NULL,
        ADD COLUMN IF NOT EXISTS revision_calidad_motivo VARCHAR(500) NULL,
        ADD COLUMN IF NOT EXISTS revision_proyectos_estado ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
        ADD COLUMN IF NOT EXISTS revision_proyectos_por INT NULL,
        ADD COLUMN IF NOT EXISTS revision_proyectos_fecha DATETIME NULL,
        ADD COLUMN IF NOT EXISTS revision_proyectos_motivo VARCHAR(500) NULL", "fat_protocolos.revision_calidad_*/revision_proyectos_*", $log, $errs);

$log[] = '<strong>── Fase 58: proyecto_resumen -- semanas de plazo propias para los escenarios 1 y 2 ──</strong>';
// Antes solo el Escenario 3 (Aprobación ingeniería) tenía su "Semanas de plazo" -- el usuario
// pidió (2026-09-07) que los 3 escenarios de fecha de entrega calculen su propio resultado
// (fecha base + N semanas), no solo el 3. semanas_plazo (ya existente) se queda siendo la del
// Escenario 3 para no romper lo que ya la usa; estas dos son las de los Escenarios 1 y 2.
ejecutar($db, "ALTER TABLE proyecto_resumen
        ADD COLUMN IF NOT EXISTS semanas_plazo_po TINYINT(3) UNSIGNED NOT NULL DEFAULT 16,
        ADD COLUMN IF NOT EXISTS semanas_plazo_anticipo TINYINT(3) UNSIGNED NOT NULL DEFAULT 16", "proyecto_resumen.semanas_plazo_po/semanas_plazo_anticipo", $log, $errs);

$log[] = '<strong>── Fase 59: almacen_items -- número de serie por material y aprobación de Calidad tras recibir ──</strong>';
// Reemplaza el filtro viejo de Calidad antes de recibir (ordenes_compra.inspeccion_ok, ya no
// bloquea la Recepción de pedidos en almacen2.php). Ahora Calidad aprueba/rechaza POR MATERIAL
// después de que Almacén ya lo recibió y le asignó su número de serie -- no bloquea el flujo de
// compra, es una revisión de calidad en paralelo (2026-09-17, pedido explícito del usuario).
// numero_serie es un solo campo editable por renglón (no series_lotes -- decisión explícita:
// más simple, cubre el caso típico de 1 pieza por renglón).
ejecutar($db, "ALTER TABLE almacen_items
        ADD COLUMN IF NOT EXISTS numero_serie VARCHAR(100) NULL,
        ADD COLUMN IF NOT EXISTS calidad_aprobacion ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
        ADD COLUMN IF NOT EXISTS calidad_fecha DATETIME NULL,
        ADD COLUMN IF NOT EXISTS calidad_nota VARCHAR(500) NULL,
        ADD COLUMN IF NOT EXISTS aplica_certificado ENUM('si','no') NULL,
        ADD COLUMN IF NOT EXISTS certificado_ruta VARCHAR(300) NULL,
        ADD COLUMN IF NOT EXISTS certificado_nombre VARCHAR(255) NULL,
        ADD COLUMN IF NOT EXISTS certificado_subido_por INT NULL,
        ADD COLUMN IF NOT EXISTS certificado_fecha DATETIME NULL", "almacen_items.numero_serie/calidad_*/certificado_*", $log, $errs);

$log[] = '<strong>── Fase 60: auditorias_sgi -- repositorio de Listas de Verificación (DOC-SGI-FO-03C) ──</strong>';
// Reemplaza por completo el scaffold de "Auditoría" de la Fase 59 (separar en secciones con
// botón docx sin lógica real) -- eso queda descartado. Ahora es un repositorio real: una
// Auditoría = una fecha + un número (YYYY-NN autonumerado), con un renglón por cada uno de los
// 11 departamentos del SGI (ver includes/auditoria_sgi.php). Cada renglón puede generar su
// propio Word (copia de la plantilla real en plantillas/auditoria_sgi/, solo se sustituyen
// "No. DE AUDITORÍA" y "FECHA" -- pedido explícito del usuario, 2026-09-21) y recibir el
// documento escaneado ya firmado en papel.
ejecutar($db, "CREATE TABLE IF NOT EXISTS auditorias_sgi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(20) NOT NULL,
        fecha DATE NOT NULL,
        creado_por INT NULL,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "auditorias_sgi", $log, $errs);
ejecutar($db, "CREATE TABLE IF NOT EXISTS auditorias_sgi_departamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        auditoria_id INT NOT NULL,
        departamento VARCHAR(50) NOT NULL,
        archivo_escaneado_ruta VARCHAR(300) NULL,
        archivo_escaneado_nombre VARCHAR(255) NULL,
        subido_por INT NULL,
        fecha_subida DATETIME NULL,
        KEY idx_auditoria (auditoria_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "auditorias_sgi_departamentos", $log, $errs);

// ═══════════════════════════════════════════════════════════════
// Resultado
// ═══════════════════════════════════════════════════════════════
$total_ok  = count(array_filter($log, fn($l) => str_starts_with($l,'✅')));
$total_skip= count(array_filter($log, fn($l) => str_starts_with($l,'⏭')));
$total_err = count($errs);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Migraciones — EMPRESA EJEMPLO</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Montserrat',sans-serif;background:#f5f7fa;padding:30px 16px;}
.wrap{max-width:760px;margin:0 auto;}
.hdr{background:linear-gradient(135deg,#003d5c,#005686);color:#fff;border-radius:12px 12px 0 0;padding:22px 28px;}
.hdr h1{font-size:1.3rem;font-weight:700;}
.hdr p{font-size:.85rem;opacity:.8;margin-top:4px;}
.summary{display:flex;gap:14px;padding:18px 28px;background:#fff;border-bottom:2px solid #e0e8f0;flex-wrap:wrap;}
.stat{display:flex;align-items:center;gap:8px;font-size:.9rem;font-weight:600;}
.stat.ok{color:#2E7D32;} .stat.skip{color:#555;} .stat.err{color:#b71c1c;}
.log-wrap{background:#fff;border-radius:0 0 12px 12px;padding:20px 28px;box-shadow:0 2px 8px rgba(0,0,0,.07);}
.log-item{padding:5px 0;font-size:.82rem;border-bottom:1px solid #f5f5f5;font-family:monospace;}
.err-box{margin-top:16px;background:#fce4ec;border:1.5px solid #e57373;border-radius:8px;padding:14px 18px;}
.err-box p{font-size:.82rem;color:#b71c1c;margin-bottom:4px;}
.back{display:inline-flex;align-items:center;gap:6px;margin-top:20px;padding:9px 18px;background:#005686;color:#fff;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;}
.back:hover{background:#003d5c;}
.done-banner{background:linear-gradient(135deg,#e8f5e9,#c8e6c9);border:1.5px solid #2E7D32;border-radius:8px;padding:14px 18px;display:flex;align-items:center;gap:10px;color:#1B5E20;font-weight:600;margin-bottom:16px;}
</style>
</head>
<body>
<div class="wrap">
    <div class="hdr">
        <h1><i class="fas fa-database"></i> Aplicar Migraciones</h1>
        <p>Sistema de Requisiciones — todas las fases 1–5</p>
    </div>
    <div class="summary">
        <div class="stat ok"><i class="fas fa-check-circle"></i> <?= $total_ok ?> aplicadas</div>
        <div class="stat skip"><i class="fas fa-forward"></i> <?= $total_skip ?> ya existían</div>
        <?php if ($total_err): ?>
        <div class="stat err"><i class="fas fa-exclamation-triangle"></i> <?= $total_err ?> errores</div>
        <?php endif; ?>
    </div>
    <div class="log-wrap">
        <?php if ($total_err === 0): ?>
        <div class="done-banner">
            <i class="fas fa-check-circle" style="font-size:1.4rem;"></i>
            ¡Base de datos actualizada correctamente! Ya puedes usar el sistema.
        </div>
        <?php endif; ?>

        <?php foreach ($log as $l): ?>
        <div class="log-item"><?= $l ?></div>
        <?php endforeach; ?>

        <?php if (!empty($errs)): ?>
        <div class="err-box">
            <p><strong><i class="fas fa-exclamation-triangle"></i> Errores (no críticos):</strong></p>
            <?php foreach ($errs as $e): ?>
            <p><?= htmlspecialchars($e) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="ingenieria.php" class="back"><i class="fas fa-arrow-left"></i> Ir a Ingeniería</a>
        <a href="proyecto.php" class="back" style="background:#2E7D32;margin-left:8px;"><i class="fas fa-folder"></i> Ir a Proyectos</a>
    </div>
</div>
</body>
</html>
