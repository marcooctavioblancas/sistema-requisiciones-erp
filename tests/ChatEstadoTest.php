<?php

/**
 * Cubre el fix de 2026-08-04: usuarios_estado nunca tuvo PRIMARY KEY/UNIQUE,
 * asi que el INSERT ... ON DUPLICATE KEY UPDATE de
 * includes/ajax_chat.php::actualizar_estado nunca chocaba con nada e
 * insertaba una fila nueva cada vez -- con el polling cada 30s del chat,
 * usuarios_estado crecia sin limite y el LEFT JOIN de obtener_contactos
 * mostraba al mismo usuario duplicado una y otra vez ("lista interminable").
 * Confirmado en vivo antes del fix: 239 filas para 2 usuarios reales.
 */
final class ChatEstadoTest extends TestCase
{
    private const USER = 'qa_test_chat_estado';
    private const PASS = 'ClavePruebaChatEstado123';

    private int $userId = 0;

    protected function tearDown(): void
    {
        if ($this->userId) {
            $this->db()->prepare('DELETE FROM usuarios_estado WHERE usuario_id = ?')->execute([$this->userId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testActualizarEstadoNoAcumulaFilasDuplicadas(): void
    {
        $this->userId = $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $jar = $this->loginAs(self::USER, self::PASS);

        // Simula 3 pings de presencia como haría el polling de 30s del chat.
        $this->get('includes/ajax_chat.php?action=actualizar_estado', $jar);
        $this->get('includes/ajax_chat.php?action=actualizar_estado', $jar);
        $res = $this->get('includes/ajax_chat.php?action=actualizar_estado', $jar);

        $this->assertSame(200, $res['code']);

        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM usuarios_estado WHERE usuario_id = ?');
        $stmt->execute([$this->userId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Debe quedar exactamente 1 fila por usuario, sin importar cuántos pings mande');
    }
}
