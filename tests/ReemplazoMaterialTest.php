<?php
require_once __DIR__ . '/TestCase.php';

/**
 * Cubre el flujo de reemplazo de material (padre-hijo) pedido 2026-08-19:
 * Almacén propone un material alterno para un item de BOM o de requisición,
 * y solo el jefe del área responsable (o admin) puede aprobarlo -- al
 * aprobar, el swap se aplica directo sobre la fila origen.
 */
final class ReemplazoMaterialTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_rm_admin');
        $this->qaDeleteUser('qa_test_rm_trab');
        $this->qaDeleteUser('qa_test_rm_jefe_ing');
        $this->qaDeleteUser('qa_test_rm_jefe_prod');
        parent::tearDown();
    }

    public function testProponerYAprobarReemplazoEnAlmacenItem(): void
    {
        $db = $this->db();
        // notificarRoles() abanica a TODOS los usuarios del área + admin (cuentas reales,
        // no solo las qa_test_*) -- se captura la marca de agua para poder borrar
        // exactamente lo que esta prueba generó, sin tocar notificaciones reales previas.
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $this->qaCreateUser('qa_test_rm_admin', 'Passw0rd!', 'admin');

        $matOriginal = $this->crearMaterial($db, 'qa_test_rm original', 0);
        $matAlterno  = $this->crearMaterial($db, 'qa_test_rm alterno', 25);

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_rm_proyecto', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $stmt = $db->prepare("INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, cantidad_requerida, stock_actual, diferencia, fecha_agregado) VALUES (?, ?, 'qa_test_rm original', 'qa_test_rm original', 5, 0, -5, NOW())");
        $stmt->execute([$proyectoId, $matOriginal]);
        $itemId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM almacen_items WHERE id = $itemId";

        $jar = $this->loginAs('qa_test_rm_admin', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('reemplazos_material.php', $jar)['body']);
        $this->assertNotNull($token, 'no se pudo extraer el token CSRF');

        $resp = $this->postJson('api/reemplazo_material.php', [
            'origen_tipo' => 'almacen_item',
            'origen_id' => $itemId,
            'alternativa_id' => $matAlterno,
            'nota' => 'prueba automatizada',
        ], $jar, ['X-CSRF-Token: ' . $token]);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['success'] ?? false, 'proponer reemplazo falló: ' . ($body['error'] ?? $resp['body']));

        $solicitud = $db->query("SELECT * FROM material_reemplazo_solicitudes WHERE origen_tipo='almacen_item' AND origen_id=$itemId ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($solicitud);
        $this->assertSame('pendiente', $solicitud['estado']);
        $this->assertSame('ingenieria', $solicitud['area_responsable']);
        $this->cleanupSql[] = "DELETE FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}";
        $this->cleanupSql[] = "DELETE FROM material_alternativas WHERE material_id = $matOriginal AND alternativa_id = $matAlterno";

        $catalogo = $db->query("SELECT * FROM material_alternativas WHERE material_id=$matOriginal AND alternativa_id=$matAlterno")->fetch();
        $this->assertNotFalse($catalogo, 'el par debió quedar registrado en el catálogo padre-hijo');

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo='reemplazo_material_pendiente' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($notif);
        $this->assertStringContainsString('qa_test_rm_proyecto', $notif['mensaje'], 'la notificación debe mencionar el proyecto de origen');

        // Resolver: aprobar como admin (esJefeDe() bypassa por isAdmin())
        $resp2 = $this->post('reemplazos_material.php', [
            'accion' => 'resolver',
            'solicitud_id' => $solicitud['id'],
            'decision' => 'aprobar',
            'nota' => 'aprobado en prueba',
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(302, $resp2['code']);

        $itemFinal = $db->query("SELECT * FROM almacen_items WHERE id = $itemId")->fetch();
        $this->assertSame((string)$matAlterno, (string)$itemFinal['material_id']);
        $this->assertEquals(25, $itemFinal['stock_actual']);
        $this->assertEquals(25 - 5, $itemFinal['diferencia']);

        $solicitudFinal = $db->query("SELECT * FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}")->fetch();
        $this->assertSame('aprobado', $solicitudFinal['estado']);

        $notifResuelta = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo='reemplazo_material_resuelto' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($notifResuelta);

        $this->cleanupSql[] = "DELETE FROM materiales WHERE id IN ($matOriginal, $matAlterno)";
    }

    public function testProponerYAprobarReemplazoEnRequisicionDetalle(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $this->qaCreateUser('qa_test_rm_admin', 'Passw0rd!', 'admin');

        $matOriginal = $this->crearMaterial($db, 'qa_test_rm req original', 0);
        $matAlterno  = $this->crearMaterial($db, 'qa_test_rm req alterno', 12);

        $db->exec("INSERT INTO requisiciones (folio, usuario_id, area, solicitante, estado) VALUES ('QA-TEST-RM-0001', 1, 'Producción', 'QA Test', 'pendiente')");
        $reqId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM requisiciones WHERE id = $reqId";

        $stmt = $db->prepare("INSERT INTO requisicion_detalle (requisicion_id, item_numero, material_id, cantidad, descripcion) VALUES (?, 1, ?, 3, 'qa_test_rm req original')");
        $stmt->execute([$reqId, $matOriginal]);
        $detalleId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM requisicion_detalle WHERE id = $detalleId";

        $jar = $this->loginAs('qa_test_rm_admin', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('reemplazos_material.php', $jar)['body']);

        $resp = $this->postJson('api/reemplazo_material.php', [
            'origen_tipo' => 'requisicion_detalle',
            'origen_id' => $detalleId,
            'alternativa_id' => $matAlterno,
        ], $jar, ['X-CSRF-Token: ' . $token]);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['success'] ?? false, 'proponer reemplazo falló: ' . ($body['error'] ?? $resp['body']));

        $solicitud = $db->query("SELECT * FROM material_reemplazo_solicitudes WHERE origen_tipo='requisicion_detalle' AND origen_id=$detalleId ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($solicitud);
        // 'Producción' -> 'manufactura', mismo mapeo ya usado por notificarDuplicado()
        $this->assertSame('manufactura', $solicitud['area_responsable']);
        $this->assertSame($reqId, (int)$solicitud['requisicion_id']);
        $this->cleanupSql[] = "DELETE FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}";
        $this->cleanupSql[] = "DELETE FROM material_alternativas WHERE material_id = $matOriginal AND alternativa_id = $matAlterno";

        $this->post('reemplazos_material.php', [
            'accion' => 'resolver', 'solicitud_id' => $solicitud['id'], 'decision' => 'aprobar', 'csrf_token' => $token,
        ], $jar);

        $detalleFinal = $db->query("SELECT * FROM requisicion_detalle WHERE id = $detalleId")->fetch();
        $this->assertSame((string)$matAlterno, (string)$detalleFinal['material_id']);
        $this->assertStringContainsString('sustituido con autorización', $detalleFinal['notas'] ?? '');

        $this->cleanupSql[] = "DELETE FROM materiales WHERE id IN ($matOriginal, $matAlterno)";
    }

    public function testTrabajadorSinNivelJefeNoPuedeAprobar(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $this->qaCreateUser('qa_test_rm_admin', 'Passw0rd!', 'admin');
        $this->qaCreateUser('qa_test_rm_trab', 'Passw0rd!', 'ingenieria'); // sin fila en usuario_niveles -> 'trabajador' por default

        $matOriginal = $this->crearMaterial($db, 'qa_test_rm original2', 0);
        $matAlterno  = $this->crearMaterial($db, 'qa_test_rm alterno2', 0);

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_rm_proyecto2', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $stmt = $db->prepare("INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, cantidad_requerida, stock_actual, diferencia, fecha_agregado) VALUES (?, ?, 'x', 'x', 1, 0, -1, NOW())");
        $stmt->execute([$proyectoId, $matOriginal]);
        $itemId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM almacen_items WHERE id = $itemId";

        $adminJar = $this->loginAs('qa_test_rm_admin', 'Passw0rd!');
        $adminToken = $this->extractCsrfToken($this->get('reemplazos_material.php', $adminJar)['body']);
        $this->postJson('api/reemplazo_material.php', [
            'origen_tipo' => 'almacen_item', 'origen_id' => $itemId, 'alternativa_id' => $matAlterno,
        ], $adminJar, ['X-CSRF-Token: ' . $adminToken]);

        $solicitud = $db->query("SELECT * FROM material_reemplazo_solicitudes WHERE origen_id=$itemId ORDER BY id DESC LIMIT 1")->fetch();
        $this->cleanupSql[] = "DELETE FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}";
        $this->cleanupSql[] = "DELETE FROM material_alternativas WHERE material_id = $matOriginal AND alternativa_id = $matAlterno";

        // Trabajador de Ingeniería (área responsable de este origen) sin nivel 'jefe' intenta aprobar.
        $trabJar = $this->loginAs('qa_test_rm_trab', 'Passw0rd!');
        $trabToken = $this->extractCsrfToken($this->get('reemplazos_material.php', $trabJar)['body']);
        $resp = $this->post('reemplazos_material.php', [
            'accion' => 'resolver', 'solicitud_id' => $solicitud['id'], 'decision' => 'aprobar', 'csrf_token' => $trabToken,
        ], $trabJar);
        $this->assertSame(302, $resp['code']);

        $solicitudFinal = $db->query("SELECT estado FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}")->fetch();
        $this->assertSame('pendiente', $solicitudFinal['estado'], 'un trabajador sin nivel jefe no debería poder aprobar');

        $itemFinal = $db->query("SELECT material_id FROM almacen_items WHERE id = $itemId")->fetch();
        $this->assertSame((string)$matOriginal, (string)$itemFinal['material_id'], 'el material no debió cambiar');

        $this->cleanupSql[] = "DELETE FROM materiales WHERE id IN ($matOriginal, $matAlterno)";
    }

    /**
     * Pedido explícito del usuario (2026-08-20): si el material lo pidió Producción
     * (columna cantidad_produccion, no el BOM de Ingeniería), a Ingeniería "no le
     * incumbe" -- el jefe de Ingeniería NO debe poder aprobar/rechazar ese reemplazo,
     * solo el jefe de Producción (manufactura). Antes area_responsable era siempre
     * 'ingenieria' para cualquier ítem de almacen_items, sin importar quién lo pidió.
     */
    public function testAreaResponsableSigueAQuienPidioElMaterialNoSiempreIngenieria(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";

        $this->qaCreateUser('qa_test_rm_admin', 'Passw0rd!', 'admin');
        $jefeIngId = $this->qaCreateUser('qa_test_rm_jefe_ing', 'Passw0rd!', 'ingenieria');
        $jefeProdId = $this->qaCreateUser('qa_test_rm_jefe_prod', 'Passw0rd!', 'ingenieria'); // tipo irrelevante, solo importa el nivel
        $db->prepare("INSERT INTO usuario_niveles (usuario_id, modulo, nivel) VALUES (?, 'ingenieria', 'jefe')")->execute([$jefeIngId]);
        $db->prepare("INSERT INTO usuario_niveles (usuario_id, modulo, nivel) VALUES (?, 'manufactura', 'jefe')")->execute([$jefeProdId]);
        $this->cleanupSql[] = "DELETE FROM usuario_niveles WHERE usuario_id IN ($jefeIngId, $jefeProdId)";

        $matOriginal = $this->crearMaterial($db, 'qa_test_rm prod original', 0);
        $matAlterno  = $this->crearMaterial($db, 'qa_test_rm prod alterno', 8);

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_rm_proyecto3', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        // cantidad_produccion > 0, cantidad_ingenieria = 0 -- lo pidió Producción, no el BOM.
        $stmt = $db->prepare("INSERT INTO almacen_items (proyecto_id, material_id, item, descripcion, cantidad_requerida, cantidad_ingenieria, cantidad_produccion, stock_actual, diferencia, fecha_agregado) VALUES (?, ?, 'x', 'x', 4, 0, 4, 0, -4, NOW())");
        $stmt->execute([$proyectoId, $matOriginal]);
        $itemId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM almacen_items WHERE id = $itemId";

        $adminJar = $this->loginAs('qa_test_rm_admin', 'Passw0rd!');
        $adminToken = $this->extractCsrfToken($this->get('reemplazos_material.php', $adminJar)['body']);
        $resp = $this->postJson('api/reemplazo_material.php', [
            'origen_tipo' => 'almacen_item', 'origen_id' => $itemId, 'alternativa_id' => $matAlterno,
        ], $adminJar, ['X-CSRF-Token: ' . $adminToken]);
        $this->assertTrue((json_decode($resp['body'], true)['success'] ?? false));

        $solicitud = $db->query("SELECT * FROM material_reemplazo_solicitudes WHERE origen_id=$itemId ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('manufactura', $solicitud['area_responsable'], 'un ítem pedido por Producción debe rutear a manufactura, no a ingenieria');
        $this->cleanupSql[] = "DELETE FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}";
        $this->cleanupSql[] = "DELETE FROM material_alternativas WHERE material_id = $matOriginal AND alternativa_id = $matAlterno";

        // El jefe de Ingeniería NO debe poder aprobar algo que Ingeniería nunca pidió.
        $ingJar = $this->loginAs('qa_test_rm_jefe_ing', 'Passw0rd!');
        $ingToken = $this->extractCsrfToken($this->get('reemplazos_material.php', $ingJar)['body']);
        $this->post('reemplazos_material.php', [
            'accion' => 'resolver', 'solicitud_id' => $solicitud['id'], 'decision' => 'aprobar', 'csrf_token' => $ingToken,
        ], $ingJar);
        $estadoTrasIng = $db->query("SELECT estado FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}")->fetchColumn();
        $this->assertSame('pendiente', $estadoTrasIng, 'el jefe de Ingeniería no debe poder resolver un reemplazo que pidió Producción');

        // El jefe de Producción SÍ debe poder aprobarlo.
        $prodJar = $this->loginAs('qa_test_rm_jefe_prod', 'Passw0rd!');
        $prodToken = $this->extractCsrfToken($this->get('reemplazos_material.php', $prodJar)['body']);
        $this->post('reemplazos_material.php', [
            'accion' => 'resolver', 'solicitud_id' => $solicitud['id'], 'decision' => 'aprobar', 'csrf_token' => $prodToken,
        ], $prodJar);
        $estadoTrasProd = $db->query("SELECT estado FROM material_reemplazo_solicitudes WHERE id = {$solicitud['id']}")->fetchColumn();
        $this->assertSame('aprobado', $estadoTrasProd, 'el jefe de Producción sí debe poder resolver un reemplazo que pidió su propia área');

        $this->cleanupSql[] = "DELETE FROM materiales WHERE id IN ($matOriginal, $matAlterno)";
    }

    private function crearMaterial(PDO $db, string $descripcion, int $stock): int
    {
        $stmt = $db->prepare("INSERT INTO materiales (descripcion, marca, modelo, stock_disponible, activo) VALUES (?, 'QA', 'TEST', ?, 1)");
        $stmt->execute([$descripcion, $stock]);
        return (int)$db->lastInsertId();
    }
}
