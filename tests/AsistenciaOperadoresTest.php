<?php
require_once __DIR__ . '/TestCase.php';

/**
 * Diseño híbrido de asistencia acordado con el usuario (2026-08-20): "Iniciar"/
 * "Reanudar" en proceso.php marcan presencia automática (asistencia_operadores),
 * y un jefe revisa en produccion.php?seccion=asistencia quién sigue "sin_marcar"
 * para marcarlo inactivo y reasignar sus OTs pendientes a otro operador.
 */
final class AsistenciaOperadoresTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_asis_admin');
        $this->qaDeleteUser('qa_test_asis_jefe');
        $this->qaDeleteUser('qa_test_asis_trab');
        $this->qaDeleteUser('qa_test_asis_op');
        parent::tearDown();
    }

    public function testIniciarOtMarcaAsistenciaActivaAutomaticamente(): void
    {
        $db = $this->db();
        $adminId = $this->qaCreateUser('qa_test_asis_admin', 'Passw0rd!', 'admin');
        $db->exec("DELETE FROM asistencia_operadores WHERE usuario_id = $adminId AND fecha = CURDATE()");
        $this->cleanupSql[] = "DELETE FROM asistencia_operadores WHERE usuario_id = $adminId";

        $db->exec("INSERT INTO ordenes_trabajo (boom_lista_id, titulo, estado, orden_posicion) VALUES (0, 'qa_test_asis_ot1', 'pendiente', 0)");
        $otId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_trabajo WHERE id = $otId";
        $this->cleanupSql[] = "DELETE FROM ot_logs WHERE ot_id = $otId";

        $jar = $this->loginAs('qa_test_asis_admin', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('proceso.php', $jar)['body']);
        $this->assertNotNull($token, 'no se pudo extraer el token CSRF');

        $resp = $this->post('proceso.php', [
            'ajax' => '1', 'accion' => 'iniciar', 'ot_id' => (string)$otId,
            'operador' => 'QA Test', 'maquina' => '', 'actividad' => '',
            'csrf_token' => $token,
        ], $jar);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['ok'] ?? false, 'iniciar debió responder ok=true: ' . $resp['body']);

        $asis = $db->query("SELECT estado FROM asistencia_operadores WHERE usuario_id = $adminId AND fecha = CURDATE()")->fetch();
        $this->assertNotFalse($asis, 'debió crearse el registro de asistencia al iniciar la OT');
        $this->assertSame('activo', $asis['estado']);
    }

    public function testJefePuedeMarcarInactivoYReasignarOtPendiente(): void
    {
        $db = $this->db();
        $jefeId = $this->qaCreateUser('qa_test_asis_jefe', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"manufactura\"]' WHERE id = $jefeId");
        $db->prepare("INSERT INTO usuario_niveles (usuario_id, modulo, nivel) VALUES (?, 'manufactura', 'jefe')")->execute([$jefeId]);
        $this->cleanupSql[] = "DELETE FROM usuario_niveles WHERE usuario_id = $jefeId";

        $opId = $this->qaCreateUser('qa_test_asis_op', 'Passw0rd!', 'operador-proceso');
        $db->exec("UPDATE usuarios SET nombre_completo = 'QA Test Operador Ausente' WHERE id = $opId");
        $this->cleanupSql[] = "DELETE FROM asistencia_operadores WHERE usuario_id = $opId";

        $db->exec("INSERT INTO ordenes_trabajo (boom_lista_id, titulo, estado, operador_nombre) VALUES (0, 'qa_test_asis_ot2', 'pendiente', 'QA Test Operador Ausente')");
        $otId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_trabajo WHERE id = $otId";

        $jar = $this->loginAs('qa_test_asis_jefe', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('produccion.php?seccion=asistencia', $jar)['body']);
        $this->assertNotNull($token, 'no se pudo extraer el token CSRF');

        $respMarcar = $this->post('produccion.php', [
            'accion' => 'marcar_inactivo_asistencia', 'usuario_id' => (string)$opId, 'csrf_token' => $token,
        ], $jar);
        $this->assertSame(302, $respMarcar['code']);

        $asis = $db->query("SELECT estado FROM asistencia_operadores WHERE usuario_id = $opId AND fecha = CURDATE()")->fetch();
        $this->assertNotFalse($asis);
        $this->assertSame('inactivo', $asis['estado'], 'el jefe debió poder marcar inactivo al operador');

        // La OT pendiente del operador ausente ahora debe aparecer en el listado de reasignar.
        $pagina = $this->get('produccion.php?seccion=asistencia', $jar)['body'];
        $this->assertStringContainsString('qa_test_asis_ot2', $pagina);

        $respReasignar = $this->post('produccion.php', [
            'accion' => 'reasignar_operador_ot', 'ot_id' => (string)$otId, 'nuevo_operador' => 'QA Test Reemplazo', 'csrf_token' => $token,
        ], $jar);
        $this->assertSame(302, $respReasignar['code']);

        $otFinal = $db->query("SELECT operador_nombre FROM ordenes_trabajo WHERE id = $otId")->fetch();
        $this->assertSame('QA Test Reemplazo', $otFinal['operador_nombre']);
    }

    public function testUsuarioSinNivelJefeNoPuedeMarcarInactivo(): void
    {
        $db = $this->db();
        $trabId = $this->qaCreateUser('qa_test_asis_trab', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"manufactura\"]' WHERE id = $trabId"); // hasAcceso('manufactura') sin nivel jefe -> 'trabajador' por default

        $opId = $this->qaCreateUser('qa_test_asis_op', 'Passw0rd!', 'operador-proceso');
        $this->cleanupSql[] = "DELETE FROM asistencia_operadores WHERE usuario_id = $opId";

        $jar = $this->loginAs('qa_test_asis_trab', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('produccion.php?seccion=asistencia', $jar)['body']);
        $this->assertNotNull($token);

        $this->post('produccion.php', [
            'accion' => 'marcar_inactivo_asistencia', 'usuario_id' => (string)$opId, 'csrf_token' => $token,
        ], $jar);

        $asis = $db->query("SELECT estado FROM asistencia_operadores WHERE usuario_id = $opId AND fecha = CURDATE()")->fetch();
        $this->assertFalse($asis, 'un usuario sin nivel jefe no debió poder marcar asistencia');
    }
}
