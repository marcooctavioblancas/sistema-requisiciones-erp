-- Esquema (sin datos) del Sistema de Requisiciones.
-- MariaDB 10.4 / utf8mb4. Se carga solo la primera vez que arranca el contenedor "db"
-- (docker-compose monta esta carpeta en /docker-entrypoint-initdb.d).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `sistema_requisiciones` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sistema_requisiciones`;

CREATE TABLE `almacen_items` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `requisicion_id` int(11) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `item` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `cantidad_requerida` int(11) NOT NULL,
  `cantidad_ingenieria` int(11) NOT NULL DEFAULT 0,
  `cantidad_produccion` int(11) NOT NULL DEFAULT 0,
  `cantidad_asignada` int(11) DEFAULT 0,
  `stock_actual` int(11) DEFAULT 0,
  `diferencia` int(11) DEFAULT 0,
  `estado` varchar(20) DEFAULT 'pendiente',
  `fecha_agregado` datetime DEFAULT NULL,
  `agregado_por` int(11) DEFAULT NULL,
  `fincado` tinyint(1) DEFAULT 0 COMMENT '1=tiene OC generada',
  `fincado_oc_id` int(11) DEFAULT NULL COMMENT 'Orden de compra que lo finc??',
  `recibido_almacen` tinyint(1) DEFAULT 0 COMMENT '1=confirmado en almac??n',
  `categoria_id` int(11) DEFAULT NULL,
  `categoria_nombre` varchar(100) DEFAULT NULL,
  `numero_serie` varchar(100) DEFAULT NULL,
  `calidad_aprobacion` enum('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
  `calidad_fecha` datetime DEFAULT NULL,
  `calidad_nota` varchar(500) DEFAULT NULL,
  `aplica_certificado` enum('si','no') DEFAULT NULL,
  `certificado_ruta` varchar(300) DEFAULT NULL,
  `certificado_nombre` varchar(255) DEFAULT NULL,
  `certificado_subido_por` int(11) DEFAULT NULL,
  `certificado_fecha` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `almacen_movimientos` (
  `id` int(11) NOT NULL,
  `almacen_item_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `stock_anterior` int(11) NOT NULL,
  `stock_nuevo` int(11) NOT NULL,
  `fecha` datetime DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `asistencia_operadores` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `estado` enum('sin_marcar','activo','inactivo') NOT NULL DEFAULT 'sin_marcar',
  `fecha_marcado` datetime DEFAULT NULL,
  `marcado_por` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `auditoria` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `accion` varchar(50) NOT NULL,
  `tabla` varchar(50) DEFAULT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `fecha_hora` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auditorias_sgi` (
  `id` int(11) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `fecha` date NOT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `auditorias_sgi_departamentos` (
  `id` int(11) NOT NULL,
  `auditoria_id` int(11) NOT NULL,
  `departamento` varchar(50) NOT NULL,
  `archivo_escaneado_ruta` varchar(300) DEFAULT NULL,
  `archivo_escaneado_nombre` varchar(255) DEFAULT NULL,
  `subido_por` int(11) DEFAULT NULL,
  `fecha_subida` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bom_plantillas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bom_plantilla_items` (
  `id` int(11) NOT NULL,
  `plantilla_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `cantidad` decimal(10,2) DEFAULT 1.00,
  `certificados` text DEFAULT NULL,
  `observacion` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bom_snapshots` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `origen` varchar(40) NOT NULL DEFAULT 'impresion',
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `boom_items` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `item` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `cantidad` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `fecha_agregado` datetime DEFAULT NULL,
  `agregado_por` int(11) DEFAULT NULL,
  `guardado` tinyint(4) DEFAULT 0,
  `boom_id` int(11) DEFAULT NULL,
  `certificados` varchar(500) DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `nota_libre` text DEFAULT NULL,
  `categoria_doc` enum('componente_principal','conector','material_electrico','tornilleria') DEFAULT NULL,
  `es_manufactura` tinyint(1) DEFAULT 0,
  `unidad_medida` varchar(50) DEFAULT 'pza'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `boom_items_log` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(50) NOT NULL,
  `campo_modificado` varchar(50) DEFAULT NULL,
  `valor_anterior` varchar(255) DEFAULT NULL,
  `valor_nuevo` varchar(255) DEFAULT NULL,
  `fecha_cambio` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `boom_listas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `proyecto_id` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT NULL,
  `num_equipos` int(11) DEFAULT 1,
  `guardado` tinyint(4) DEFAULT 1,
  `creado_por` int(11) DEFAULT NULL,
  `finalizado` tinyint(1) DEFAULT 0,
  `fecha_finalizado` datetime DEFAULT NULL,
  `finalizado_por` int(11) DEFAULT NULL,
  `tipo_lista` enum('principal','adicional','plano','plano_ia') DEFAULT 'principal',
  `hoja_plano` varchar(10) DEFAULT NULL,
  `padre_boom_id` int(11) DEFAULT NULL,
  `ia_procesado` tinyint(1) DEFAULT 0,
  `ia_fecha` datetime DEFAULT NULL,
  `pieza_nombre` varchar(200) DEFAULT NULL,
  `tipo_valvula` varchar(255) DEFAULT NULL,
  `plano_ruta` varchar(255) DEFAULT NULL,
  `observacion_fab` text DEFAULT NULL,
  `version` int(11) DEFAULT 1,
  `version_anterior` int(11) DEFAULT NULL,
  `estado_plano` enum('borrador','enviado','en_revision','aprobado','rechazado') DEFAULT 'borrador',
  `requisicion_individual_generada` tinyint(1) NOT NULL DEFAULT 0,
  `plano_comentario` text DEFAULT NULL,
  `plano_comentario_fecha` datetime DEFAULT NULL,
  `plano_comentario_por` int(11) DEFAULT NULL,
  `enviado_almacen` tinyint(1) NOT NULL DEFAULT 0,
  `revision_numero` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `boom_versiones` (
  `id` int(11) NOT NULL,
  `lista_id` int(11) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `datos_snapshot` longtext DEFAULT NULL,
  `motivo_cambio` text DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `calendario_eventos` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `tipo` enum('ingenieria','servicios') NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `creado_automatico` tinyint(1) DEFAULT 1,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `calidad_inspecciones` (
  `id` int(11) NOT NULL,
  `orden_compra_id` int(11) DEFAULT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `tipo_inspeccion` enum('recepcion','proceso','final','fat') DEFAULT 'recepcion',
  `resultado` enum('pendiente','aprobado','rechazado','condicionado') DEFAULT 'pendiente',
  `inspector_id` int(11) DEFAULT NULL,
  `fecha_inspeccion` datetime DEFAULT NULL,
  `cantidad_recibida` decimal(10,2) DEFAULT NULL,
  `cantidad_aprobada` decimal(10,2) DEFAULT NULL,
  `cantidad_rechazada` decimal(10,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `ruta_evidencia` varchar(500) DEFAULT NULL,
  `nc_numero` varchar(50) DEFAULT NULL COMMENT 'N??mero de No Conformidad',
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `carpetas_materiales` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `padre_id` int(11) DEFAULT NULL,
  `creado_por` int(11) NOT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `categorias` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `icono` varchar(50) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_conversaciones` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `contacto_id` int(11) NOT NULL,
  `ultimo_mensaje` text DEFAULT NULL,
  `ultima_actividad` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `chat_mensajes` (
  `id` int(11) NOT NULL,
  `remitente_id` int(11) NOT NULL COMMENT 'ID del usuario que envía',
  `destinatario_id` int(11) NOT NULL COMMENT 'ID del usuario que recibe',
  `mensaje` text NOT NULL,
  `archivo_nombre` varchar(255) DEFAULT NULL COMMENT 'Nombre original del archivo',
  `archivo_ruta` varchar(500) DEFAULT NULL COMMENT 'Ruta del archivo subido',
  `archivo_tipo` varchar(100) DEFAULT NULL COMMENT 'MIME type del archivo',
  `fecha_envio` datetime DEFAULT current_timestamp(),
  `leido` tinyint(4) DEFAULT 0 COMMENT '0=no leído, 1=leído'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cotizaciones` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `requisicion_id` int(11) DEFAULT NULL,
  `estado` varchar(50) DEFAULT 'pendiente',
  `orden_generada_id` int(11) DEFAULT NULL,
  `fecha_solicitud` datetime DEFAULT current_timestamp(),
  `fecha_respuesta` datetime DEFAULT NULL,
  `email_enviado_a` varchar(255) DEFAULT NULL,
  `datos_ia` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notas` text DEFAULT NULL,
  `archivo_ruta` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `documentos_referencia` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo` enum('norma','especificacion','otro') NOT NULL DEFAULT 'norma',
  `idioma` enum('es','en','otro') NOT NULL DEFAULT 'es',
  `descripcion` text DEFAULT NULL,
  `archivo_ruta` varchar(500) DEFAULT NULL,
  `texto_extraido` mediumtext DEFAULT NULL,
  `paginas` int(11) DEFAULT NULL,
  `estado` enum('pendiente','procesado','sin_texto','error') DEFAULT 'pendiente',
  `subido_por` int(11) DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `equipos_fabricados` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `numero_serie` varchar(100) DEFAULT NULL,
  `id_tag` varchar(100) DEFAULT NULL,
  `nombre` varchar(200) DEFAULT NULL,
  `estado` enum('borrador','completado') NOT NULL DEFAULT 'borrador',
  `notas` text DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `completado_por` int(11) DEFAULT NULL,
  `fecha_completado` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `equipos_fabricados_componentes` (
  `id` int(11) NOT NULL,
  `equipo_fabricado_id` int(11) NOT NULL,
  `serie_componente_id` int(11) NOT NULL,
  `agregado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fat_plantillas` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `checklist_json` longtext NOT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fat_protocolos` (
  `id` int(11) NOT NULL,
  `orden_produccion_id` int(11) NOT NULL,
  `pruebas_fat_id` int(11) DEFAULT NULL,
  `datos_generales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_generales`)),
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `actualizado_por` int(11) DEFAULT NULL,
  `fecha_actualizacion` datetime DEFAULT NULL,
  `archivo_subido_ruta` varchar(300) DEFAULT NULL,
  `archivo_subido_nombre` varchar(255) DEFAULT NULL,
  `archivo_subido_por` int(11) DEFAULT NULL,
  `archivo_subido_fecha` datetime DEFAULT NULL,
  `revision_estado` enum('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
  `revision_por` int(11) DEFAULT NULL,
  `revision_fecha` datetime DEFAULT NULL,
  `revision_motivo` varchar(500) DEFAULT NULL,
  `revision_calidad_estado` enum('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
  `revision_calidad_por` int(11) DEFAULT NULL,
  `revision_calidad_fecha` datetime DEFAULT NULL,
  `revision_calidad_motivo` varchar(500) DEFAULT NULL,
  `revision_proyectos_estado` enum('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
  `revision_proyectos_por` int(11) DEFAULT NULL,
  `revision_proyectos_fecha` datetime DEFAULT NULL,
  `revision_proyectos_motivo` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `historial_movimientos` (
  `id` int(11) NOT NULL,
  `tipo_movimiento` enum('entrada','salida','ajuste','requisicion','orden_compra','reingreso') NOT NULL,
  `referencia_id` int(11) DEFAULT NULL,
  `referencia_tipo` varchar(50) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `cantidad` int(11) DEFAULT NULL,
  `stock_anterior` int(11) DEFAULT NULL,
  `stock_nuevo` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `fecha_movimiento` timestamp NOT NULL DEFAULT current_timestamp(),
  `proveedor_id` int(11) DEFAULT NULL,
  `folio` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hoja_viajera` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `cliente` varchar(200) DEFAULT NULL,
  `no_serie` varchar(100) DEFAULT NULL,
  `id_tag` varchar(100) DEFAULT NULL,
  `producto` varchar(300) DEFAULT NULL,
  `pasadas_recubrimiento` int(11) DEFAULT NULL,
  `n_retrabajos` int(11) NOT NULL DEFAULT 0,
  `n_reprocesos` int(11) NOT NULL DEFAULT 0,
  `resultado` enum('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_finalizado` datetime DEFAULT NULL,
  `resultado_produccion` enum('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  `resultado_calidad` enum('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  `ciclo_rechazo` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `hoja_viajera_pasos` (
  `id` int(11) NOT NULL,
  `hoja_id` int(11) NOT NULL,
  `numero` tinyint(4) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `tipo` enum('simple','recubrimientos','detallado') NOT NULL DEFAULT 'simple',
  `sub_valvula` tinyint(1) DEFAULT NULL,
  `sub_actuador` tinyint(1) DEFAULT NULL,
  `sub_accesorios` tinyint(1) DEFAULT NULL,
  `actividad_estado` enum('conforme','no_conforme') DEFAULT NULL,
  `accion_realizada` enum('retrabajo','reproceso','no_se_requirio') DEFAULT NULL,
  `nc_numero` varchar(50) DEFAULT NULL,
  `vobo_produccion` enum('pendiente','conforme','no_aplica') NOT NULL DEFAULT 'pendiente',
  `vobo_produccion_por` int(11) DEFAULT NULL,
  `vobo_produccion_fecha` datetime DEFAULT NULL,
  `vobo_calidad` enum('pendiente','conforme','no_aplica') NOT NULL DEFAULT 'pendiente',
  `vobo_calidad_por` int(11) DEFAULT NULL,
  `vobo_calidad_fecha` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `indicadores_entradas` (
  `id` int(11) NOT NULL,
  `reporte_id` int(11) NOT NULL,
  `etiqueta` varchar(150) DEFAULT NULL,
  `fecha_real` date DEFAULT NULL,
  `fecha_programada` date DEFAULT NULL,
  `fecha_referencia` date DEFAULT NULL,
  `dias_reales` int(11) DEFAULT NULL,
  `dias_programados` int(11) DEFAULT NULL,
  `porcentaje` decimal(6,2) DEFAULT NULL,
  `nota` varchar(500) DEFAULT NULL,
  `orden` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `indicadores_reportes` (
  `id` int(11) NOT NULL,
  `area` varchar(20) NOT NULL,
  `trimestre` tinyint(4) NOT NULL,
  `anio` int(11) NOT NULL,
  `fecha_reporte` date DEFAULT NULL,
  `representante_nombre` varchar(150) DEFAULT NULL,
  `representante_cargo` varchar(150) DEFAULT NULL,
  `porcentaje_requerido` decimal(6,2) DEFAULT NULL,
  `porcentaje_obtenido` decimal(6,2) DEFAULT NULL,
  `cumple` tinyint(1) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `estado` enum('borrador','finalizado') NOT NULL DEFAULT 'borrador',
  `revision_numero` int(11) NOT NULL DEFAULT 1,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_finalizado` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `maquinas` (
  `id` int(11) NOT NULL,
  `clave` varchar(50) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `tag` varchar(30) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `procesos` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `materiales` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `precio_unitario` decimal(12,2) DEFAULT 0.00,
  `stock_disponible` int(11) DEFAULT 0,
  `stock_minimo` int(11) DEFAULT 0,
  `unidad_medida` varchar(20) DEFAULT 'pza',
  `imagen` varchar(255) DEFAULT NULL,
  `proveedor_principal` int(11) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `en_espera` tinyint(1) DEFAULT 0,
  `carpeta_id` int(11) DEFAULT NULL,
  `ubicacion` varchar(100) DEFAULT NULL,
  `codigo_verificado` tinyint(1) NOT NULL DEFAULT 1,
  `primera_vista` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `material_alternativas` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `alternativa_id` int(11) NOT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `material_proveedor` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `proveedor_id` int(11) NOT NULL,
  `precio_proveedor` decimal(12,2) DEFAULT NULL,
  `sku_proveedor` varchar(50) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `material_reemplazo_solicitudes` (
  `id` int(11) NOT NULL,
  `origen_tipo` enum('almacen_item','requisicion_detalle') NOT NULL,
  `origen_id` int(11) NOT NULL,
  `material_original_id` int(11) NOT NULL,
  `material_alternativa_id` int(11) NOT NULL,
  `cantidad` decimal(12,2) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `requisicion_id` int(11) DEFAULT NULL,
  `area_responsable` varchar(30) NOT NULL,
  `estado` enum('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
  `propuesto_por` int(11) DEFAULT NULL,
  `fecha_propuesta` datetime NOT NULL DEFAULT current_timestamp(),
  `resuelto_por` int(11) DEFAULT NULL,
  `fecha_resolucion` datetime DEFAULT NULL,
  `nota_solicitud` varchar(255) DEFAULT NULL,
  `nota_resolucion` varchar(255) DEFAULT NULL,
  `opciones_json` text DEFAULT NULL,
  `material_elegido_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notificaciones` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL COMMENT 'Usuario destinatario',
  `tipo` varchar(50) NOT NULL COMMENT 'tipo de notificación',
  `titulo` varchar(255) NOT NULL,
  `mensaje` text NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `leido` tinyint(4) DEFAULT 0,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ordenes_compra` (
  `id` int(11) NOT NULL,
  `folio` varchar(60) NOT NULL,
  `requisicion_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_oc` date DEFAULT curdate(),
  `fecha_entrega_estimada` date DEFAULT NULL,
  `condiciones_pago` varchar(100) DEFAULT NULL,
  `forma_envio` varchar(100) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `iva_porcentaje` decimal(5,2) DEFAULT 16.00,
  `iva` decimal(12,2) DEFAULT 0.00,
  `total` decimal(12,2) DEFAULT 0.00,
  `moneda` enum('MXN','USD') DEFAULT 'MXN',
  `tipo_cambio` decimal(8,4) DEFAULT 1.0000,
  `estado` enum('pendiente_aprobacion','aprobada','rechazada','recibida') NOT NULL DEFAULT 'pendiente_aprobacion',
  `recibida` tinyint(1) NOT NULL DEFAULT 0,
  `notas` text DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `proyecto_id` int(11) DEFAULT NULL,
  `aprobado_por` int(11) DEFAULT NULL,
  `fecha_aprobacion` datetime DEFAULT NULL,
  `firma_ruta` varchar(300) DEFAULT NULL,
  `motivo_rechazo` text DEFAULT NULL,
  `recibida_parcial` tinyint(1) DEFAULT 0,
  `inspeccion_req` tinyint(1) DEFAULT 0 COMMENT 'Requiere OK de calidad antes de recepci?n',
  `inspeccion_ok` tinyint(1) DEFAULT 0 COMMENT '1 = calidad aprob? la recepci?n',
  `inspeccion_id` int(11) DEFAULT NULL COMMENT 'FK calidad_inspecciones',
  `calidad_aprobacion` enum('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
  `calidad_fecha` datetime DEFAULT NULL,
  `calidad_nota` varchar(500) DEFAULT NULL,
  `revision_proyectos_ok` tinyint(1) NOT NULL DEFAULT 0,
  `revision_proyectos_por` int(11) DEFAULT NULL,
  `revision_proyectos_fecha` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ordenes_produccion` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `numero_proyecto` varchar(50) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `cliente` varchar(300) DEFAULT NULL,
  `orden_compra` varchar(150) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `fecha_compromiso` varchar(255) DEFAULT NULL,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items`)),
  `checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checklist`)),
  `observaciones` text DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'borrador',
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT NULL,
  `difundido_por` int(11) DEFAULT NULL,
  `fecha_difusion` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ordenes_trabajo` (
  `id` int(11) NOT NULL,
  `folio` varchar(40) DEFAULT NULL,
  `boom_lista_id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `titulo` varchar(255) NOT NULL DEFAULT '',
  `descripcion` text DEFAULT NULL,
  `maquina` varchar(100) DEFAULT NULL,
  `operario_id` int(11) DEFAULT NULL,
  `proceso` varchar(150) DEFAULT NULL,
  `tiempo_espera` decimal(8,2) DEFAULT 0.00,
  `tiempo_final` decimal(8,2) DEFAULT 0.00,
  `responsable_id` int(11) DEFAULT NULL,
  `prioridad` enum('baja','media','alta','urgente') DEFAULT 'media',
  `fecha_compromiso` date DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `estado` enum('pendiente','en_proceso','completado','cancelado') DEFAULT 'pendiente',
  `visto_produccion` tinyint(1) DEFAULT 0,
  `visto_produccion_por` int(11) DEFAULT NULL,
  `visto_produccion_fecha` datetime DEFAULT NULL,
  `visto_calidad` tinyint(1) DEFAULT 0,
  `visto_calidad_por` int(11) DEFAULT NULL,
  `visto_calidad_fecha` datetime DEFAULT NULL,
  `pausado` tinyint(1) DEFAULT 0,
  `tiempo_pausado_total` int(11) DEFAULT 0,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_inicio_real` datetime DEFAULT NULL,
  `calidad_aprobacion` enum('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
  `calidad_fecha` datetime DEFAULT NULL,
  `calidad_nota` varchar(500) DEFAULT NULL,
  `tiempo_regular_min` int(11) NOT NULL DEFAULT 0,
  `tiempo_extra_min` int(11) NOT NULL DEFAULT 0,
  `es_prioritaria` tinyint(1) NOT NULL DEFAULT 0,
  `en_stand_by` tinyint(1) NOT NULL DEFAULT 0,
  `ots_liberadas` tinyint(1) NOT NULL DEFAULT 0,
  `en_problema` tinyint(1) NOT NULL DEFAULT 0,
  `problema_descripcion` text DEFAULT NULL,
  `requisicion_externa_id` int(11) DEFAULT NULL,
  `orden_posicion` int(11) NOT NULL DEFAULT 0,
  `secuencia_ejecucion` int(11) NOT NULL DEFAULT 0,
  `operador_nombre` varchar(200) DEFAULT NULL,
  `plano_pagina_id` int(11) DEFAULT NULL,
  `tiempo_pausado_min` int(11) NOT NULL DEFAULT 0,
  `produccion_aprobacion` enum('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
  `produccion_fecha` datetime DEFAULT NULL,
  `produccion_nota` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `orden_compra_detalle` (
  `id` int(11) NOT NULL,
  `orden_id` int(11) NOT NULL,
  `item_numero` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `cantidad` int(11) NOT NULL,
  `unidad_medida` varchar(20) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `precio_unitario` decimal(12,2) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ot_logs` (
  `id` int(11) NOT NULL,
  `ot_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `accion` enum('inicio','reanudacion','pausa','fin') NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `operador_nombre` varchar(200) DEFAULT NULL,
  `maquina` varchar(200) DEFAULT NULL,
  `actividad` text DEFAULT NULL,
  `resumen` text DEFAULT NULL,
  `motivo_pausa` varchar(200) DEFAULT NULL,
  `piezas_terminadas` int(11) DEFAULT 0,
  `observaciones` text DEFAULT NULL,
  `tiempo_regular_min` int(11) DEFAULT 0,
  `tiempo_extra_min` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ot_procesos` (
  `id` int(11) NOT NULL,
  `ot_id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `estado` enum('pendiente','activo','completado') NOT NULL DEFAULT 'pendiente',
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_fin` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `plano_paginas` (
  `id` int(11) NOT NULL,
  `boom_lista_id` int(11) NOT NULL,
  `numero_pagina` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL DEFAULT '',
  `ignorada` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `numero_serie` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `direccion` text DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `lada` varchar(10) DEFAULT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `atencion` varchar(100) DEFAULT NULL,
  `referencia` varchar(50) DEFAULT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `etiqueta` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `categorias_ids` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proveedor_bloqueado` (
  `id` int(11) NOT NULL,
  `proveedor_id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `desbloqueado_por` int(11) DEFAULT NULL,
  `fecha_desbloqueo` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `proveedor_incidencias` (
  `id` int(11) NOT NULL,
  `proveedor_id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `orden_compra_id` int(11) DEFAULT NULL,
  `fecha` date NOT NULL,
  `tipo` enum('llegada_tardia','entrega_incompleta','mal_estado','otro') NOT NULL,
  `descripcion` text DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proyectos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT NULL,
  `activo` tinyint(4) DEFAULT 1,
  `completado` tinyint(4) DEFAULT 0,
  `fecha_completado` datetime DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'activo',
  `almacen_activo` int(11) DEFAULT 0,
  `fecha_estado` datetime DEFAULT NULL,
  `orden_compra_generada` tinyint(4) DEFAULT 0,
  `fecha_orden_compra` datetime DEFAULT NULL,
  `realizada` tinyint(4) DEFAULT 0,
  `fecha_realizada` datetime DEFAULT NULL,
  `estatus` varchar(50) DEFAULT 'proceso',
  `proveedor_id` int(11) DEFAULT NULL,
  `tipo` enum('ingenieria','servicios') NOT NULL DEFAULT 'ingenieria',
  `fecha_inicio` date DEFAULT NULL,
  `fecha_finiquito` date DEFAULT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `supervisor_unlock` tinyint(1) DEFAULT 0,
  `unlock_expiry` datetime DEFAULT NULL,
  `unlock_by` int(11) DEFAULT NULL,
  `porcentaje_avance` decimal(5,2) DEFAULT 0.00,
  `fecha_inicio_ing` date DEFAULT NULL COMMENT 'Inicio Ingenier??a',
  `fecha_fin_ing` date DEFAULT NULL COMMENT 'Fin Ingenier??a',
  `fecha_inicio_proc` date DEFAULT NULL COMMENT 'Inicio Procura',
  `fecha_fin_proc` date DEFAULT NULL COMMENT 'Fin Procura',
  `fecha_inicio_mfg` date DEFAULT NULL COMMENT 'Inicio Manufactura',
  `fecha_fin_mfg` date DEFAULT NULL COMMENT 'Fin Manufactura',
  `fecha_inicio_fat` date DEFAULT NULL COMMENT 'Inicio Pruebas FAT',
  `fecha_fin_fat` date DEFAULT NULL COMMENT 'Fin Pruebas FAT/Entrega',
  `produccion_liberada` tinyint(1) NOT NULL DEFAULT 0,
  `orden_ver_proceso` int(11) NOT NULL DEFAULT 0,
  `cierre_total` tinyint(1) NOT NULL DEFAULT 0,
  `cierre_total_fecha` datetime DEFAULT NULL,
  `cierre_total_por` int(11) DEFAULT NULL,
  `almacen_directo_fecha_fin` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proyecto_actividad` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `detalle` text DEFAULT NULL,
  `fecha_hora` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proyecto_documentos` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `tipo` enum('plano','diagrama_electrico','diagrama_neumatico','manual','especificacion','memoria_calculo','certificado','foto','otro') DEFAULT 'otro',
  `nombre` varchar(300) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `ruta_archivo` varchar(500) DEFAULT NULL,
  `requerido` tinyint(1) DEFAULT 1 COMMENT '1 = entregable obligatorio',
  `recibido` tinyint(1) DEFAULT 0,
  `fecha_recibido` datetime DEFAULT NULL,
  `recibido_por` int(11) DEFAULT NULL,
  `revision` varchar(20) DEFAULT NULL COMMENT 'Rev. A, Rev. B???',
  `fase` enum('ingenieria','procura','manufactura','fat','entrega') DEFAULT 'ingenieria',
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proyecto_fases` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `fase` enum('ingenieria','procura','manufactura','fat') NOT NULL,
  `nombre_display` varchar(100) NOT NULL DEFAULT '',
  `pct_avance` decimal(5,2) NOT NULL DEFAULT 0.00,
  `estado` enum('pendiente','en_proceso','completado','cancelado') DEFAULT 'pendiente',
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin_plan` date DEFAULT NULL,
  `fecha_fin_real` date DEFAULT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `actualizado_por` int(11) DEFAULT NULL,
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proyecto_registro_contable` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `gasto_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tiempo_regular_min` int(11) NOT NULL DEFAULT 0,
  `tiempo_extra_min` int(11) NOT NULL DEFAULT 0,
  `total_ots` int(11) NOT NULL DEFAULT 0,
  `almacen_tiempo_min` int(11) NOT NULL DEFAULT 0,
  `calidad_tiempo_min` int(11) NOT NULL DEFAULT 0,
  `gerencia_tiempo_min` int(11) NOT NULL DEFAULT 0,
  `ingenieria_tiempo_min` int(11) NOT NULL DEFAULT 0,
  `almacen_directo_tiempo_min` int(11) NOT NULL DEFAULT 0,
  `fecha_cierre` datetime NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proyecto_resumen` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `numero_po` varchar(100) DEFAULT NULL,
  `cliente` varchar(255) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `items_json` longtext DEFAULT NULL,
  `fecha_entrega` varchar(150) DEFAULT NULL,
  `normas_json` text DEFAULT NULL,
  `condiciones` text DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `plan_inspeccion_json` longtext DEFAULT NULL,
  `archivo_po` varchar(500) DEFAULT NULL,
  `archivo_ov` varchar(500) DEFAULT NULL,
  `analizado_ia` tinyint(1) DEFAULT 0,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL,
  `fecha_anticipo` date DEFAULT NULL,
  `fecha_aprobacion_ing` date DEFAULT NULL,
  `semanas_plazo` tinyint(3) UNSIGNED NOT NULL DEFAULT 16,
  `semanas_plazo_po` tinyint(3) UNSIGNED NOT NULL DEFAULT 16,
  `semanas_plazo_anticipo` tinyint(3) UNSIGNED NOT NULL DEFAULT 16
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pruebas_fat` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `orden_trabajo_id` int(11) DEFAULT NULL,
  `folio` varchar(30) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checklist`)),
  `estado` enum('borrador','pendiente_ingenieria','pendiente_produccion','pendiente_calidad','pendiente_proyectos','completado') DEFAULT 'borrador',
  `firma_produccion_ruta` varchar(300) DEFAULT NULL,
  `firma_produccion_por` int(11) DEFAULT NULL,
  `firma_produccion_fecha` datetime DEFAULT NULL,
  `firma_calidad_ruta` varchar(300) DEFAULT NULL,
  `firma_calidad_por` int(11) DEFAULT NULL,
  `firma_calidad_fecha` datetime DEFAULT NULL,
  `firma_proyectos_ruta` varchar(300) DEFAULT NULL,
  `firma_proyectos_por` int(11) DEFAULT NULL,
  `firma_proyectos_fecha` datetime DEFAULT NULL,
  `documento_ruta` varchar(300) DEFAULT NULL,
  `entregado` tinyint(1) DEFAULT 0,
  `fecha_entrega` datetime DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `firma_ingenieria_ruta` varchar(300) DEFAULT NULL,
  `firma_ingenieria_por` int(11) DEFAULT NULL,
  `firma_ingenieria_fecha` datetime DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_programada` date DEFAULT NULL,
  `fecha_realizada` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `requisiciones` (
  `id` int(11) NOT NULL,
  `folio` varchar(20) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `proyecto` varchar(200) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `solicitante` varchar(100) DEFAULT NULL,
  `fecha_solicitud` date DEFAULT curdate(),
  `fecha_requerida` date DEFAULT NULL,
  `prioridad` enum('baja','media','alta','urgente') DEFAULT 'media',
  `estado` enum('pendiente','aprobada','rechazada','en_proceso','completada','cancelada') DEFAULT 'pendiente',
  `estado_compra` enum('cotizacion_enviada','cotizacion_recibida','orden_generada','recepcion_parcial','recibido') DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `iva` decimal(12,2) DEFAULT 0.00,
  `total` decimal(12,2) DEFAULT 0.00,
  `moneda` enum('MXN','USD') DEFAULT 'MXN',
  `tipo_cambio` decimal(8,4) DEFAULT 1.0000,
  `notas_generales` text DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `proyecto_id` int(11) DEFAULT NULL,
  `ot_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `requisicion_detalle` (
  `id` int(11) NOT NULL,
  `requisicion_id` int(11) NOT NULL,
  `item_numero` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `cantidad` int(11) NOT NULL,
  `cantidad_entregada` int(11) DEFAULT 0,
  `unidad_medida` varchar(20) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `precio_estimado` decimal(12,2) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `requisicion_eventos` (
  `id` int(11) NOT NULL,
  `requisicion_id` int(11) NOT NULL,
  `estado_clave` varchar(40) NOT NULL,
  `detalle` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `series_lotes` (
  `id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `origen` enum('comprado','fabricado') NOT NULL DEFAULT 'comprado',
  `numero_serie` varchar(100) NOT NULL,
  `lote` varchar(100) DEFAULT NULL,
  `orden_compra_id` int(11) DEFAULT NULL,
  `proyecto_id` int(11) DEFAULT NULL,
  `ubicacion_id` int(11) DEFAULT NULL,
  `estado` varchar(50) DEFAULT 'En Almacén',
  `fecha_ingreso` datetime DEFAULT current_timestamp(),
  `fecha_salida` datetime DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `ot_id` int(11) DEFAULT NULL,
  `plano_pagina_id` int(11) DEFAULT NULL,
  `usuario_entrada_id` int(11) DEFAULT NULL,
  `usuario_salida_id` int(11) DEFAULT NULL,
  `movimiento_entrada_id` int(11) DEFAULT NULL,
  `movimiento_salida_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `subproyectos_manufactura` (
  `id` int(11) NOT NULL,
  `proyecto_id` int(11) NOT NULL,
  `boom_item_id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `observacion` text DEFAULT NULL,
  `plano_ruta` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente','en_proceso','completado') DEFAULT 'pendiente',
  `creado_por` int(11) NOT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `subproyecto_materiales` (
  `id` int(11) NOT NULL,
  `subproyecto_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `descripcion` varchar(300) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unidad_medida` varchar(50) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nombre_completo` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `tipo_usuario` varchar(50) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `ultimo_acceso` datetime DEFAULT NULL,
  `accesos` text DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `intentos_fallidos` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_hasta` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuarios_estado` (
  `usuario_id` int(11) NOT NULL,
  `estado` varchar(20) DEFAULT 'offline' COMMENT 'online, offline, ausente',
  `ultima_actividad` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `usuario_niveles` (
  `usuario_id` int(11) NOT NULL,
  `modulo` varchar(50) NOT NULL,
  `nivel` enum('trabajador','jefe') NOT NULL DEFAULT 'trabajador'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ventas_cotizaciones` (
  `id` int(11) NOT NULL,
  `folio` varchar(30) NOT NULL,
  `cliente_nombre` varchar(300) NOT NULL,
  `cliente_rfc` varchar(20) DEFAULT NULL,
  `cliente_email` varchar(200) DEFAULT NULL,
  `proyecto_id` int(11) DEFAULT NULL COMMENT 'Si se vincula a un proyecto interno',
  `descripcion` text DEFAULT NULL,
  `subtotal` decimal(14,2) DEFAULT 0.00,
  `iva` decimal(14,2) DEFAULT 0.00,
  `total` decimal(14,2) DEFAULT 0.00,
  `moneda` enum('MXN','USD') DEFAULT 'MXN',
  `estado` enum('borrador','enviada','aceptada','rechazada','vencida') DEFAULT 'borrador',
  `validez_dias` int(11) DEFAULT 30,
  `fecha_emision` date DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `aprobado_por` int(11) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ventas_cotizaciones_detalle` (
  `id` int(11) NOT NULL,
  `cotizacion_id` int(11) NOT NULL,
  `item_numero` int(11) NOT NULL DEFAULT 1,
  `descripcion` varchar(500) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unidad` varchar(30) DEFAULT 'pza',
  `precio_unitario` decimal(14,2) DEFAULT 0.00,
  `descuento_pct` decimal(5,2) DEFAULT 0.00,
  `subtotal` decimal(14,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Índices, llaves y auto-incrementos

ALTER TABLE `almacen_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_requisicion` (`requisicion_id`);

ALTER TABLE `almacen_movimientos`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `asistencia_operadores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario_fecha` (`usuario_id`,`fecha`);

ALTER TABLE `auditoria`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `auditorias_sgi`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `auditorias_sgi_departamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auditoria` (`auditoria_id`);

ALTER TABLE `bom_plantillas`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `bom_plantilla_items`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `bom_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `proyecto_id` (`proyecto_id`),
  ADD KEY `creado_por` (`creado_por`);

ALTER TABLE `boom_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_boom` (`boom_id`),
  ADD KEY `idx_material` (`material_id`);

ALTER TABLE `boom_listas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proyecto` (`proyecto_id`);

ALTER TABLE `calidad_inspecciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_oc` (`orden_compra_id`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_material` (`material_id`);

ALTER TABLE `carpetas_materiales`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_categoria_nombre` (`nombre`);

ALTER TABLE `cotizaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_proveedor` (`proveedor_id`),
  ADD KEY `idx_requisicion` (`requisicion_id`);

ALTER TABLE `documentos_referencia`
  ADD PRIMARY KEY (`id`),
  ADD KEY `proyecto_id` (`proyecto_id`),
  ADD KEY `subido_por` (`subido_por`);

ALTER TABLE `equipos_fabricados`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_equipo_serie` (`numero_serie`),
  ADD UNIQUE KEY `uq_ef_id_tag` (`id_tag`),
  ADD KEY `idx_ef_proyecto` (`proyecto_id`),
  ADD KEY `creado_por` (`creado_por`),
  ADD KEY `completado_por` (`completado_por`);

ALTER TABLE `equipos_fabricados_componentes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_efc_componente` (`serie_componente_id`),
  ADD KEY `idx_efc_equipo` (`equipo_fabricado_id`),
  ADD KEY `agregado_por` (`agregado_por`);

ALTER TABLE `fat_plantillas`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `fat_protocolos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_orden_produccion` (`orden_produccion_id`);

ALTER TABLE `historial_movimientos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_historial_folio` (`folio`),
  ADD KEY `idx_material` (`material_id`),
  ADD KEY `idx_referencia` (`referencia_tipo`,`referencia_id`);

ALTER TABLE `hoja_viajera`
  ADD PRIMARY KEY (`id`),
  ADD KEY `creado_por` (`creado_por`),
  ADD KEY `idx_hoja_viajera_proyecto` (`proyecto_id`);

ALTER TABLE `hoja_viajera_pasos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hoja` (`hoja_id`),
  ADD KEY `vobo_produccion_por` (`vobo_produccion_por`),
  ADD KEY `vobo_calidad_por` (`vobo_calidad_por`);

ALTER TABLE `indicadores_entradas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reporte` (`reporte_id`);

ALTER TABLE `indicadores_reportes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_area_trim_anio` (`area`,`trimestre`,`anio`);

ALTER TABLE `maquinas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `clave` (`clave`);

ALTER TABLE `materiales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_materiales_codigo` (`codigo`);

ALTER TABLE `material_alternativas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material_alternativa` (`material_id`,`alternativa_id`),
  ADD KEY `idx_alternativa_id` (`alternativa_id`);

ALTER TABLE `material_proveedor`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `material_reemplazo_solicitudes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_estado` (`estado`),
  ADD KEY `idx_origen` (`origen_tipo`,`origen_id`);

ALTER TABLE `notificaciones`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `ordenes_compra`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_requisicion` (`requisicion_id`),
  ADD KEY `idx_proveedor` (`proveedor_id`),
  ADD KEY `idx_estado` (`estado`);

ALTER TABLE `ordenes_produccion`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `ordenes_trabajo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_boom_lista` (`boom_lista_id`),
  ADD KEY `idx_plano_pagina` (`plano_pagina_id`);

ALTER TABLE `orden_compra_detalle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orden` (`orden_id`),
  ADD KEY `idx_material` (`material_id`);

ALTER TABLE `ot_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ot` (`ot_id`),
  ADD KEY `usuario_id` (`usuario_id`);

ALTER TABLE `ot_procesos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp2` (`ot_id`);

ALTER TABLE `plano_paginas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_plano_pagina` (`boom_lista_id`,`numero_pagina`);

ALTER TABLE `proveedores`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `proveedor_bloqueado`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proveedor_proyecto` (`proveedor_id`,`proyecto_id`,`activo`);

ALTER TABLE `proyectos`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `proyecto_fases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proyecto` (`proyecto_id`);

ALTER TABLE `proyecto_registro_contable`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_proyecto` (`proyecto_id`);

ALTER TABLE `proyecto_resumen`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `pruebas_fat`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `requisiciones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_requisiciones_folio` (`folio`),
  ADD KEY `idx_proyecto` (`proyecto_id`),
  ADD KEY `idx_estado` (`estado`);

ALTER TABLE `requisicion_detalle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_requisicion` (`requisicion_id`),
  ADD KEY `idx_material` (`material_id`);

ALTER TABLE `requisicion_eventos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_requisicion` (`requisicion_id`);

ALTER TABLE `series_lotes`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `usuarios_estado`
  ADD PRIMARY KEY (`usuario_id`);

ALTER TABLE `usuario_niveles`
  ADD PRIMARY KEY (`usuario_id`,`modulo`);

ALTER TABLE `almacen_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `almacen_movimientos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `asistencia_operadores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `auditoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `auditorias_sgi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `auditorias_sgi_departamentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `bom_plantillas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `bom_plantilla_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `bom_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `boom_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `boom_listas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `calidad_inspecciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `carpetas_materiales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `categorias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cotizaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `documentos_referencia`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `equipos_fabricados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `equipos_fabricados_componentes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `fat_plantillas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `fat_protocolos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `historial_movimientos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `hoja_viajera`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `hoja_viajera_pasos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `indicadores_entradas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `indicadores_reportes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `maquinas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `materiales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `material_alternativas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `material_proveedor`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `material_reemplazo_solicitudes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `notificaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ordenes_compra`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ordenes_produccion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ordenes_trabajo`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `orden_compra_detalle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ot_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ot_procesos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `plano_paginas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `proveedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `proveedor_bloqueado`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `proyectos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `proyecto_fases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `proyecto_registro_contable`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `proyecto_resumen`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `pruebas_fat`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `requisiciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `requisicion_detalle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `requisicion_eventos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `series_lotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `bom_snapshots`
  ADD CONSTRAINT `bom_snapshots_ibfk_1` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bom_snapshots_ibfk_2` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`);

ALTER TABLE `equipos_fabricados`
  ADD CONSTRAINT `equipos_fabricados_ibfk_1` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `equipos_fabricados_ibfk_2` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`),
  ADD CONSTRAINT `equipos_fabricados_ibfk_3` FOREIGN KEY (`completado_por`) REFERENCES `usuarios` (`id`);

ALTER TABLE `equipos_fabricados_componentes`
  ADD CONSTRAINT `equipos_fabricados_componentes_ibfk_1` FOREIGN KEY (`equipo_fabricado_id`) REFERENCES `equipos_fabricados` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `equipos_fabricados_componentes_ibfk_2` FOREIGN KEY (`serie_componente_id`) REFERENCES `series_lotes` (`id`),
  ADD CONSTRAINT `equipos_fabricados_componentes_ibfk_3` FOREIGN KEY (`agregado_por`) REFERENCES `usuarios` (`id`);

ALTER TABLE `hoja_viajera`
  ADD CONSTRAINT `hoja_viajera_ibfk_1` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hoja_viajera_ibfk_2` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`);

ALTER TABLE `hoja_viajera_pasos`
  ADD CONSTRAINT `hoja_viajera_pasos_ibfk_1` FOREIGN KEY (`hoja_id`) REFERENCES `hoja_viajera` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hoja_viajera_pasos_ibfk_2` FOREIGN KEY (`vobo_produccion_por`) REFERENCES `usuarios` (`id`),
  ADD CONSTRAINT `hoja_viajera_pasos_ibfk_3` FOREIGN KEY (`vobo_calidad_por`) REFERENCES `usuarios` (`id`);

ALTER TABLE `ot_logs`
  ADD CONSTRAINT `ot_logs_ibfk_1` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ot_logs_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

ALTER TABLE `ot_procesos`
  ADD CONSTRAINT `ot_procesos_ibfk_1` FOREIGN KEY (`ot_id`) REFERENCES `ordenes_trabajo` (`id`) ON DELETE CASCADE;

ALTER TABLE `usuario_niveles`
  ADD CONSTRAINT `fk_usuario_niveles_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
