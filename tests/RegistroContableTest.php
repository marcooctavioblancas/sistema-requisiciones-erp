<?php
require_once __DIR__ . '/TestCase.php';

/**
 * Cubre el registro contable pedido 2026-08-20: al cerrar totalmente un proyecto
 * (proyectos.cierre_total=1, vía proyecto.php::cerrar_proyecto_total), debe quedar
 * un registro con gasto total (de OCs no rechazadas), tiempo invertido y horas
 * trabajadas -- visible en Compras, sección "Registro Contable".
 */
final class RegistroContableTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_rc_admin');
        parent::tearDown();
    }

    public function testCierreTotalGeneraRegistroContableConGastoYTiempoReales(): void
    {
        // ordenes_compra.fecha_creacion es TIMESTAMP (se normaliza según el time_zone de
        // sesión de MySQL) mientras que fecha_aprobacion es DATETIME (literal, sin
        // conversión) -- $this->db() ya deja la sesión en America/Mexico_City (ver
        // TestCase::db()), así que TIMESTAMPDIFF sale alineado con lo que haría la app real.
        $db = $this->db();
        $this->qaCreateUser('qa_test_rc_admin', 'Passw0rd!', 'admin');

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_rc_proyecto', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";
        $this->cleanupSql[] = "DELETE FROM proyecto_registro_contable WHERE proyecto_id = $proyectoId";

        // Requisito de cerrar_proyecto_total: todos los equipos fabricados completados.
        $db->prepare("INSERT INTO equipos_fabricados (proyecto_id, nombre, estado) VALUES (?, 'qa_test_rc_equipo', 'completado')")->execute([$proyectoId]);
        $this->cleanupSql[] = "DELETE FROM equipos_fabricados WHERE proyecto_id = $proyectoId";

        $db->exec("INSERT INTO proveedores (nombre) VALUES ('qa_test_rc_proveedor')");
        $provId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proveedores WHERE id = $provId";

        // OC aprobada (cuenta) + OC rechazada (NO debe contar en el gasto).
        $db->prepare("INSERT INTO ordenes_compra (folio, proveedor_id, usuario_id, total, estado, proyecto_id) VALUES ('QA-TEST-RC-1', ?, 1, 1500.00, 'aprobada', ?)")->execute([$provId, $proyectoId]);
        $ocOkId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ordenes_compra (folio, proveedor_id, usuario_id, total, estado, proyecto_id) VALUES ('QA-TEST-RC-2', ?, 1, 500.00, 'rechazada', ?)")->execute([$provId, $proyectoId]);
        $ocRechId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_compra WHERE id IN ($ocOkId, $ocRechId)";

        // 2 OTs del proyecto con tiempo real acumulado.
        $db->prepare("INSERT INTO ordenes_trabajo (boom_lista_id, proyecto_id, titulo, estado, tiempo_regular_min, tiempo_extra_min) VALUES (0, ?, 'qa_test_rc_ot1', 'completado', 120, 30)")->execute([$proyectoId]);
        $ot1 = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ordenes_trabajo (boom_lista_id, proyecto_id, titulo, estado, tiempo_regular_min, tiempo_extra_min) VALUES (0, ?, 'qa_test_rc_ot2', 'completado', 60, 0)")->execute([$proyectoId]);
        $ot2 = (int)$db->lastInsertId();
        // 3ra OT solo para medir tiempo de Calidad (visto_produccion_fecha -> calidad_fecha, 45 min) --
        // sin tiempo_regular/extra para no alterar el total ya afirmado arriba (180/30).
        $db->prepare("INSERT INTO ordenes_trabajo (boom_lista_id, proyecto_id, titulo, estado, visto_produccion_fecha, calidad_fecha) VALUES (0, ?, 'qa_test_rc_ot3', 'completado', '2026-01-01 09:00:00', '2026-01-01 09:45:00')")->execute([$proyectoId]);
        $ot3 = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_trabajo WHERE id IN ($ot1, $ot2, $ot3)";

        // Requisición completada ligada al proyecto -- mide tiempo de Almacén (90 min).
        $db->exec("INSERT INTO requisiciones (folio, usuario_id, area, solicitante, estado, proyecto_id, fecha_creacion) VALUES ('QA-TEST-RC-REQ', 1, 'Producción', 'QA', 'pendiente', $proyectoId, '2026-01-01 10:00:00')");
        $reqId = (int)$db->lastInsertId();
        $db->exec("UPDATE requisiciones SET estado = 'completada', fecha_actualizacion = '2026-01-01 11:30:00' WHERE id = $reqId");
        $this->cleanupSql[] = "DELETE FROM requisiciones WHERE id = $reqId";

        // OC aprobada con fecha real -- mide tiempo de Gerencia (20 min). Total en 0 para
        // no alterar el gasto ya afirmado arriba ($1500).
        $db->prepare("INSERT INTO ordenes_compra (folio, proveedor_id, usuario_id, total, estado, proyecto_id, fecha_creacion, fecha_aprobacion) VALUES ('QA-TEST-RC-3', ?, 1, 0, 'aprobada', ?, '2026-01-01 08:00:00', '2026-01-01 08:20:00')")->execute([$provId, $proyectoId]);
        $ocGerId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_compra WHERE id = $ocGerId";

        // Orden de Producción difundida -- mide inicio de Ingeniería (2026-08-20).
        $db->exec("INSERT INTO ordenes_produccion (proyecto_id, numero_proyecto, estado, fecha_difusion) VALUES ($proyectoId, 'qa_test_rc_proyecto', 'activa', '2026-01-01 00:00:00')");
        $ordProdId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_produccion WHERE id = $ordProdId";

        // proyecto_fases.fecha_fin_real (fase='ingenieria') -- fin de Ingeniería. Es DATE
        // (no DATETIME), MySQL lo trata como medianoche al comparar -- 2026-01-01 00:00 ->
        // 2026-01-03 (00:00) = exactamente 2 días = 2880 min.
        $db->exec("INSERT INTO proyecto_fases (proyecto_id, fase, nombre_display, fecha_fin_real) VALUES ($proyectoId, 'ingenieria', 'Ingeniería', '2026-01-03')");
        $this->cleanupSql[] = "DELETE FROM proyecto_fases WHERE proyecto_id = $proyectoId AND fase = 'ingenieria'";

        // almacen_items.fecha_agregado -- inicio de Almacén-directo. proyectos.almacen_directo_fecha_fin
        // (1 día después) -- fin. 2026-01-01 06:00 -> 2026-01-02 06:00 = exactamente 1 día = 1440 min.
        $db->exec("INSERT INTO almacen_items (proyecto_id, item, cantidad_requerida, fecha_agregado) VALUES ($proyectoId, 'qa_test_rc_item', 1, '2026-01-01 06:00:00')");
        $almItemId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM almacen_items WHERE id = $almItemId";
        $db->exec("UPDATE proyectos SET almacen_directo_fecha_fin = '2026-01-02 06:00:00' WHERE id = $proyectoId");

        $jar = $this->loginAs('qa_test_rc_admin', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('proyecto.php', $jar)['body']);
        $this->assertNotNull($token, 'no se pudo extraer el token CSRF');

        $resp = $this->post('proyecto.php', [
            'accion' => 'cerrar_proyecto_total',
            'proyecto_id' => $proyectoId,
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(302, $resp['code']);

        $proyFinal = $db->query("SELECT cierre_total FROM proyectos WHERE id = $proyectoId")->fetch();
        $this->assertSame(1, (int)$proyFinal['cierre_total'], 'el cierre total debió aplicarse');

        $registro = $db->query("SELECT * FROM proyecto_registro_contable WHERE proyecto_id = $proyectoId")->fetch();
        $this->assertNotFalse($registro, 'debió generarse el registro contable al cerrar el proyecto');
        $this->assertEqualsWithDelta(1500.00, (float)$registro['gasto_total'], 0.001);
        $this->assertSame(180, (int)$registro['tiempo_regular_min']);
        $this->assertSame(30, (int)$registro['tiempo_extra_min']);
        $this->assertSame(3, (int)$registro['total_ots']);
        $this->assertSame(90, (int)$registro['almacen_tiempo_min'], 'tiempo de Almacén: requisición completada 10:00->11:30');
        $this->assertSame(45, (int)$registro['calidad_tiempo_min'], 'tiempo de Calidad: OT vista_produccion 09:00->calidad 09:45');
        $this->assertSame(20, (int)$registro['gerencia_tiempo_min'], 'tiempo de Gerencia: OC creada 08:00->aprobada 08:20');
        $this->assertSame(2880, (int)$registro['ingenieria_tiempo_min'], 'tiempo de Ingeniería: orden difundida 01-01 00:00 -> fase fin_real 01-03 (2 días)');
        $this->assertSame(1440, (int)$registro['almacen_directo_tiempo_min'], 'tiempo de Almacén-directo: item entra 01-01 06:00 -> almacen_directo_fecha_fin 01-02 06:00 (1 día)');
    }

    public function testSinFechaDifusionNiFinRealNoInventaTiempoDeIngenieria(): void
    {
        $db = $this->db();
        $this->qaCreateUser('qa_test_rc_admin', 'Passw0rd!', 'admin');

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_rc_proyecto2', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";
        $this->cleanupSql[] = "DELETE FROM proyecto_registro_contable WHERE proyecto_id = $proyectoId";

        $db->prepare("INSERT INTO equipos_fabricados (proyecto_id, nombre, estado) VALUES (?, 'qa_test_rc_equipo2', 'completado')")->execute([$proyectoId]);
        $this->cleanupSql[] = "DELETE FROM equipos_fabricados WHERE proyecto_id = $proyectoId";

        $jar = $this->loginAs('qa_test_rc_admin', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('proyecto.php', $jar)['body']);

        $resp = $this->post('proyecto.php', [
            'accion' => 'cerrar_proyecto_total',
            'proyecto_id' => $proyectoId,
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(302, $resp['code']);

        $registro = $db->query("SELECT ingenieria_tiempo_min, almacen_directo_tiempo_min FROM proyecto_registro_contable WHERE proyecto_id = $proyectoId")->fetch();
        $this->assertNotFalse($registro);
        $this->assertSame(0, (int)$registro['ingenieria_tiempo_min'], 'sin orden de producción difundida ni fase de ingeniería, no debe inventar un tiempo');
        $this->assertSame(0, (int)$registro['almacen_directo_tiempo_min'], 'sin almacen_items ni almacen_directo_fecha_fin, no debe inventar un tiempo');
    }
}
