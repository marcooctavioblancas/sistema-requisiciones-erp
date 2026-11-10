<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../includes/config.php';

/**
 * avisarAsistenciaPendiente() (includes/config.php) es lógica pura de backend
 * (dispara notificarRoles(), nada más) -- se llama directo con un $ahora fijo
 * en vez de depender del reloj real del sistema, mismo criterio ya usado en
 * TiempoExcedidoTest.php para avisarSiTiempoExcedido().
 */
final class AsistenciaAvisoTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        // Red de seguridad extra sobre el borrado por id > maxNotifId de arriba: estas
        // pruebas disparan notificarRoles() de verdad sobre cuentas reales de este Docker
        // de desarrollo (admin/jefes existentes) -- no dejar ningún rastro de hoy.
        $db->exec("DELETE FROM notificaciones WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()");
        $this->qaDeleteUser('qa_test_asisaviso_op');
        parent::tearDown();
    }

    private function unMartesLaboral(string $hora): DateTimeImmutable
    {
        // "tuesday this week" nunca cae en fin de semana, a diferencia de "monday"/"friday"
        // si la prueba corre justo ese día -- evita ambigüedad de si "today" ya pasó la hora.
        return new DateTimeImmutable("tuesday this week $hora");
    }

    public function testAvisaCuandoPasaLaHoraDeCorteConOperadorSinMarcar(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $db->exec("DELETE FROM notificaciones WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()");

        $opId = $this->qaCreateUser('qa_test_asisaviso_op', 'Passw0rd!', 'operador-proceso');
        $db->exec("UPDATE usuarios SET nombre_completo = 'QA Test Aviso Ausente' WHERE id = $opId");
        $db->exec("DELETE FROM asistencia_operadores WHERE usuario_id = $opId");

        avisarAsistenciaPendiente($db, $this->unMartesLaboral('10:05'));

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'asistencia_pendiente' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($notif, 'debió avisar: operador sin marcar pasada la hora de corte');
        $this->assertStringContainsString('QA Test Aviso Ausente', $notif['mensaje']);
    }

    public function testNoAvisaAntesDeLaHoraDeCorte(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $db->exec("DELETE FROM notificaciones WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()");

        $opId = $this->qaCreateUser('qa_test_asisaviso_op', 'Passw0rd!', 'operador-proceso');
        $db->exec("DELETE FROM asistencia_operadores WHERE usuario_id = $opId");

        avisarAsistenciaPendiente($db, $this->unMartesLaboral('09:00'));

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'asistencia_pendiente'")->fetch();
        $this->assertFalse($notif, 'no debió avisar antes de la hora de corte (10:00)');
    }

    public function testNoAvisaEnFinDeSemana(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $db->exec("DELETE FROM notificaciones WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()");

        $opId = $this->qaCreateUser('qa_test_asisaviso_op', 'Passw0rd!', 'operador-proceso');
        $db->exec("DELETE FROM asistencia_operadores WHERE usuario_id = $opId");

        $sabado = new DateTimeImmutable('saturday this week 10:05');
        avisarAsistenciaPendiente($db, $sabado);

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'asistencia_pendiente'")->fetch();
        $this->assertFalse($notif, 'no debió avisar en fin de semana');
    }

    public function testNoAvisaSiYaFueMarcadoManualmenteInactivo(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $db->exec("DELETE FROM notificaciones WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()");

        $opId = $this->qaCreateUser('qa_test_asisaviso_op', 'Passw0rd!', 'operador-proceso');
        $db->exec("DELETE FROM asistencia_operadores WHERE usuario_id = $opId");
        // Un jefe ya sabía de antemano que no iba a llegar y lo marcó inactivo manual --
        // mismo mecanismo ya probado en AsistenciaOperadoresTest.php.
        $db->prepare("INSERT INTO asistencia_operadores (usuario_id, fecha, estado, fecha_marcado) VALUES (?, CURDATE(), 'inactivo', NOW())")->execute([$opId]);
        $this->cleanupSql[] = "DELETE FROM asistencia_operadores WHERE usuario_id = $opId";

        avisarAsistenciaPendiente($db, $this->unMartesLaboral('10:05'));

        // Ojo: esta BD de desarrollo ya trae cuentas reales operador-proceso (Betito,
        // Nestor, PRODUCCION) sin marcar hoy -- el aviso SÍ dispara por ellas (correcto,
        // es su comportamiento real). Lo que esta prueba verifica es que la cuenta qa_test
        // ya marcada manualmente NO aparezca listada en el mensaje.
        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'asistencia_pendiente' ORDER BY id DESC LIMIT 1")->fetch();
        if ($notif !== false) {
            $this->assertStringNotContainsString('QA Test Aviso Ausente', $notif['mensaje'], 'el operador ya marcado (inactivo) manualmente no debió aparecer en el aviso');
        }
    }

    public function testNoDuplicaElAvisoElMismoDia(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";
        $db->exec("DELETE FROM notificaciones WHERE tipo = 'asistencia_pendiente' AND DATE(fecha_creacion) = CURDATE()");

        $opId = $this->qaCreateUser('qa_test_asisaviso_op', 'Passw0rd!', 'operador-proceso');
        $db->exec("DELETE FROM asistencia_operadores WHERE usuario_id = $opId");

        // notificarRoles() inserta 1 fila POR DESTINATARIO (admin + jefes de manufactura),
        // así que una sola llamada ya deja varias filas -- lo que se prueba aquí es que
        // la SEGUNDA llamada del mismo día no vuelva a insertar nada más (el guard de
        // dedupe), no que exista una sola fila en total.
        avisarAsistenciaPendiente($db, $this->unMartesLaboral('10:05'));
        $countTrasPrimera = (int)$db->query("SELECT COUNT(*) FROM notificaciones WHERE id > $maxNotifId AND tipo = 'asistencia_pendiente'")->fetchColumn();
        $this->assertGreaterThan(0, $countTrasPrimera, 'la primera llamada debió avisar');

        avisarAsistenciaPendiente($db, $this->unMartesLaboral('11:30'));
        $countTrasSegunda = (int)$db->query("SELECT COUNT(*) FROM notificaciones WHERE id > $maxNotifId AND tipo = 'asistencia_pendiente'")->fetchColumn();
        $this->assertSame($countTrasPrimera, $countTrasSegunda, 'la segunda llamada del mismo día no debió volver a avisar');
    }
}
