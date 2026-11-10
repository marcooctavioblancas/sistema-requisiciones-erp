<?php
require_once __DIR__ . '/TestCase.php';

/**
 * Hueco real encontrado y cerrado (2026-08-20): antes Gerencia podía aprobar una OC
 * ligada a un proyecto sin que Proyectos hubiera revisado la fecha de entrega nunca
 * -- no había ningún candado real, solo un resaltado visual (es_tarde) en
 * proyecto.php. Ahora api/aprobar_oc.php exige ordenes_compra.revision_proyectos_ok=1
 * para OCs con proyecto (directo o vía requisición), a menos que sea admin.
 */
final class CandadoProyectosOcTest extends TestCase
{
    private const FIRMA_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_cpo_admin');
        $this->qaDeleteUser('qa_test_cpo_gerente');
        $this->qaDeleteUser('qa_test_cpo_proy');
        parent::tearDown();
    }

    private function crearOc(PDO $db, int $provId, ?int $proyectoId, float $total = 100.00): int
    {
        $db->prepare("INSERT INTO ordenes_compra (folio, proveedor_id, usuario_id, total, estado, proyecto_id) VALUES (?, ?, 1, ?, 'pendiente_aprobacion', ?)")
           ->execute(['QA-TEST-CPO-' . uniqid(), $provId, $total, $proyectoId]);
        return (int)$db->lastInsertId();
    }

    public function testGerenciaNoPuedeAprobarOcDeProyectoSinConfirmacion(): void
    {
        $db = $this->db();
        $gerenteId = $this->qaCreateUser('qa_test_cpo_gerente', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"gerente\"]' WHERE id = $gerenteId");

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_cpo_proyecto', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $db->exec("INSERT INTO proveedores (nombre) VALUES ('qa_test_cpo_prov')");
        $provId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proveedores WHERE id = $provId";

        $ocId = $this->crearOc($db, $provId, $proyectoId);
        $this->cleanupSql[] = "DELETE FROM ordenes_compra WHERE id = $ocId";

        $jar = $this->loginAs('qa_test_cpo_gerente', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('gerencia.php', $jar)['body']);
        $this->assertNotNull($token);

        $resp = $this->post('api/aprobar_oc.php', [
            'orden_id' => (string)$ocId, 'firma_base64' => 'data:image/png;base64,' . self::FIRMA_PNG,
            'csrf_token' => $token,
        ], $jar, ['X-CSRF-Token: ' . $token]);
        $body = json_decode($resp['body'], true);
        $this->assertFalse($body['ok'] ?? true, 'Gerencia NO debió poder aprobar sin que Proyectos confirmara la fecha');
        $this->assertStringContainsString('Proyectos', $body['error'] ?? '');

        $ocEstado = $db->query("SELECT estado FROM ordenes_compra WHERE id = $ocId")->fetchColumn();
        $this->assertSame('pendiente_aprobacion', $ocEstado, 'la OC no debió quedar aprobada');
    }

    public function testProyectosConfirmaFechaYGerenciaYaPuedeAprobar(): void
    {
        $db = $this->db();
        $gerenteId = $this->qaCreateUser('qa_test_cpo_gerente', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"gerente\"]' WHERE id = $gerenteId");
        $proyUserId = $this->qaCreateUser('qa_test_cpo_proy', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"proyectos\"]' WHERE id = $proyUserId");

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_cpo_proyecto2', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $db->exec("INSERT INTO proveedores (nombre) VALUES ('qa_test_cpo_prov2')");
        $provId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proveedores WHERE id = $provId";

        $ocId = $this->crearOc($db, $provId, $proyectoId);
        $this->cleanupSql[] = "DELETE FROM ordenes_compra WHERE id = $ocId";

        // Proyectos confirma la fecha.
        $proyJar = $this->loginAs('qa_test_cpo_proy', 'Passw0rd!');
        $proyToken = $this->extractCsrfToken($this->get('proyecto.php', $proyJar)['body']);
        $respConfirmar = $this->post('proyecto.php', [
            'accion' => 'confirmar_fecha_oc', 'oc_id' => (string)$ocId, 'csrf_token' => $proyToken,
        ], $proyJar);
        $this->assertSame(302, $respConfirmar['code']);

        $ocTrasConfirmar = $db->query("SELECT revision_proyectos_ok, revision_proyectos_por FROM ordenes_compra WHERE id = $ocId")->fetch();
        $this->assertSame(1, (int)$ocTrasConfirmar['revision_proyectos_ok']);
        $this->assertSame($proyUserId, (int)$ocTrasConfirmar['revision_proyectos_por']);

        // Ahora Gerencia SÍ puede aprobar.
        $gerJar = $this->loginAs('qa_test_cpo_gerente', 'Passw0rd!');
        $gerToken = $this->extractCsrfToken($this->get('gerencia.php', $gerJar)['body']);
        $resp = $this->post('api/aprobar_oc.php', [
            'orden_id' => (string)$ocId, 'firma_base64' => 'data:image/png;base64,' . self::FIRMA_PNG,
            'csrf_token' => $gerToken,
        ], $gerJar, ['X-CSRF-Token: ' . $gerToken]);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['ok'] ?? false, 'Gerencia debió poder aprobar tras la confirmación de Proyectos: ' . $resp['body']);

        $ocFinal = $db->query("SELECT estado FROM ordenes_compra WHERE id = $ocId")->fetch();
        $this->assertSame('aprobada', $ocFinal['estado']);

        @unlink(__DIR__ . '/../uploads/firmas/' . $ocId . '.png');
    }

    public function testOcSinProyectoNoNecesitaConfirmacionDeProyectos(): void
    {
        $db = $this->db();
        $gerenteId = $this->qaCreateUser('qa_test_cpo_gerente', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"gerente\"]' WHERE id = $gerenteId");

        $db->exec("INSERT INTO proveedores (nombre) VALUES ('qa_test_cpo_prov3')");
        $provId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proveedores WHERE id = $provId";

        // Sin proyecto_id -- ej. requisición externa sin ligar a ningún proyecto.
        $ocId = $this->crearOc($db, $provId, null);
        $this->cleanupSql[] = "DELETE FROM ordenes_compra WHERE id = $ocId";

        $jar = $this->loginAs('qa_test_cpo_gerente', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('gerencia.php', $jar)['body']);
        $resp = $this->post('api/aprobar_oc.php', [
            'orden_id' => (string)$ocId, 'firma_base64' => 'data:image/png;base64,' . self::FIRMA_PNG,
            'csrf_token' => $token,
        ], $jar, ['X-CSRF-Token: ' . $token]);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['ok'] ?? false, 'una OC sin proyecto no debió requerir confirmación de Proyectos: ' . $resp['body']);

        @unlink(__DIR__ . '/../uploads/firmas/' . $ocId . '.png');
    }
}
