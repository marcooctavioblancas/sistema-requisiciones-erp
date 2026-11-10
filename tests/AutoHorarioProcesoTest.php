<?php
require_once __DIR__ . '/TestCase.php';

/**
 * Bug real encontrado y corregido (2026-08-20): el JS de proceso.php dispara
 * auto_pausar/auto_reanudar con ot_id=0 (actúan sobre TODAS las OTs activas, no
 * una específica) -- pero esas 2 acciones vivían DESPUÉS del gate "requiere OT
 * específica" (SELECT ... WHERE id=?, corta con error si no encuentra), que con
 * ot_id=0 nunca encontraba nada. El mecanismo completo de auto-pausa por horario
 * (comida/fin de turno) nunca se había ejecutado ni una sola vez. Se movieron
 * antes del gate, junto a 'ticker'/'completar_proceso'.
 */
final class AutoHorarioProcesoTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_ah_admin');
        parent::tearDown();
    }

    public function testAutoPausarYAutoReanudarActuanSobreTodasLasOtsActivas(): void
    {
        $db = $this->db();
        $this->qaCreateUser('qa_test_ah_admin', 'Passw0rd!', 'admin');

        // OT1: activa, se debe auto-pausar.
        $db->exec("INSERT INTO ordenes_trabajo (boom_lista_id, titulo, estado, pausado) VALUES (0, 'qa_test_ah_ot1', 'en_proceso', 0)");
        $ot1 = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ot_logs (ot_id, usuario_id, accion) VALUES (?, 1, 'inicio')")->execute([$ot1]);
        $this->cleanupSql[] = "DELETE FROM ordenes_trabajo WHERE id = $ot1";
        $this->cleanupSql[] = "DELETE FROM ot_logs WHERE ot_id = $ot1";

        // OT2: ya estaba pausada MANUALMENTE (no por horario) -- auto_reanudar NO debe tocarla.
        $db->exec("INSERT INTO ordenes_trabajo (boom_lista_id, titulo, estado, pausado) VALUES (0, 'qa_test_ah_ot2', 'en_proceso', 1)");
        $ot2 = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ot_logs (ot_id, usuario_id, accion, motivo_pausa) VALUES (?, 1, 'pausa', 'Esperando material')")->execute([$ot2]);
        $this->cleanupSql[] = "DELETE FROM ordenes_trabajo WHERE id = $ot2";
        $this->cleanupSql[] = "DELETE FROM ot_logs WHERE ot_id = $ot2";

        $jar = $this->loginAs('qa_test_ah_admin', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('proceso.php', $jar)['body']);
        $this->assertNotNull($token, 'no se pudo extraer el token CSRF');

        // Mismo payload que manda el JS real (ver dispararAutoHorario() en proceso.php).
        $resp = $this->post('proceso.php', [
            'ajax' => '1', 'accion' => 'auto_pausar', 'ot_id' => '0', 'tipo' => 'turno',
            'csrf_token' => $token,
        ], $jar);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['ok'] ?? false, 'auto_pausar debió responder ok=true, no "OT no encontrada": ' . $resp['body']);
        $this->assertGreaterThanOrEqual(1, $body['count'] ?? 0, 'debió pausar al menos la OT1 activa');

        $ot1Row = $db->query("SELECT pausado FROM ordenes_trabajo WHERE id = $ot1")->fetch();
        $this->assertSame(1, (int)$ot1Row['pausado'], 'OT1 debió quedar pausada por el auto_pausar');

        $logPausa = $db->query("SELECT motivo_pausa FROM ot_logs WHERE ot_id = $ot1 AND accion = 'pausa' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($logPausa);
        $this->assertStringStartsWith('Horario automático', $logPausa['motivo_pausa']);

        // Ahora reanudar por horario.
        $resp2 = $this->post('proceso.php', [
            'ajax' => '1', 'accion' => 'auto_reanudar', 'ot_id' => '0', 'tipo' => 'turno',
            'csrf_token' => $token,
        ], $jar);
        $body2 = json_decode($resp2['body'], true);
        $this->assertTrue($body2['ok'] ?? false, 'auto_reanudar debió responder ok=true: ' . $resp2['body']);
        $this->assertGreaterThanOrEqual(1, $body2['count'] ?? 0, 'debió reanudar al menos la OT1 (pausada por horario)');

        $ot1Final = $db->query("SELECT pausado FROM ordenes_trabajo WHERE id = $ot1")->fetch();
        $this->assertSame(0, (int)$ot1Final['pausado'], 'OT1 (pausada por horario) debió reanudarse');

        $ot2Final = $db->query("SELECT pausado FROM ordenes_trabajo WHERE id = $ot2")->fetch();
        $this->assertSame(1, (int)$ot2Final['pausado'], 'OT2 (pausada manualmente) NO debió tocarse por auto_reanudar');

        $reanudLogOt2 = $db->query("SELECT COUNT(*) FROM ot_logs WHERE ot_id = $ot2 AND accion = 'reanudacion'")->fetchColumn();
        $this->assertSame(0, (int)$reanudLogOt2, 'OT2 no debió recibir ningún log de reanudación automática');
    }
}
