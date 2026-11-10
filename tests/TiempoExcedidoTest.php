<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../includes/config.php';

/**
 * avisarSiTiempoExcedido() (includes/config.php) es lógica pura de backend, sin
 * superficie de UI (dispara notificarRoles(), nada más) -- se llama directo en
 * vez de manejar todo el ciclo real de proceso.php (iniciar/terminar depende de
 * calcularTiempoBloque(), que respeta horario laboral y no se puede fijar a un
 * valor exacto de minutos desde una prueba). Mismo criterio ya usado en la
 * sesión de la auditoría cruzada para lógica de puro backend.
 */
final class TiempoExcedidoTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        parent::tearDown();
    }

    private function crearOtCompletada(PDO $db, string $proceso, int $tiempoMin, int $proyectoId): int
    {
        $stmt = $db->prepare("INSERT INTO ordenes_trabajo (boom_lista_id, proyecto_id, titulo, proceso, estado, tiempo_regular_min, fecha_creacion) VALUES (0, ?, 'x', ?, 'completado', ?, NOW())");
        $stmt->execute([$proyectoId, $proceso, $tiempoMin]);
        $id = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_trabajo WHERE id = $id";
        return $id;
    }

    public function testAvisaCuandoTiempoRealSuperaElPromedioHistorico(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_tex_proyecto', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $proceso = 'QA_TEST_TIEMPOEXC_' . uniqid();
        // Histórico: 3 OTs previas de 60 min cada una -> promedio = 60.
        $this->crearOtCompletada($db, $proceso, 60, $proyectoId);
        $this->crearOtCompletada($db, $proceso, 60, $proyectoId);
        $this->crearOtCompletada($db, $proceso, 60, $proyectoId);

        // La nueva OT tardó 100 min -- 66% más que el promedio (umbral es 30%).
        $otId = $this->crearOtCompletada($db, $proceso, 100, $proyectoId);
        avisarSiTiempoExcedido($db, $otId, 0);

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'ot_tiempo_excedido' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($notif, 'debió avisar: 100min vs promedio de 60min supera el margen del 30%');
        $this->assertStringContainsString('67%', $notif['mensaje']);
    }

    public function testNoAvisaSiEstaDentroDelMargen(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_tex_proyecto2', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $proceso = 'QA_TEST_TIEMPOEXC_' . uniqid();
        $this->crearOtCompletada($db, $proceso, 60, $proyectoId);
        $this->crearOtCompletada($db, $proceso, 60, $proyectoId);
        $this->crearOtCompletada($db, $proceso, 60, $proyectoId);

        // 65 min vs promedio 60 -- solo 8% más, dentro del margen de 30%.
        $otId = $this->crearOtCompletada($db, $proceso, 65, $proyectoId);
        avisarSiTiempoExcedido($db, $otId, 0);

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'ot_tiempo_excedido'")->fetch();
        $this->assertFalse($notif, 'no debió avisar: 65min está dentro del margen normal sobre el promedio de 60min');
    }

    public function testNoAvisaConMuestraHistoricaInsuficiente(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";

        $db->exec("INSERT INTO proyectos (nombre, activo) VALUES ('qa_test_tex_proyecto3', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        $proceso = 'QA_TEST_TIEMPOEXC_' . uniqid();
        // Solo 2 OTs previas (mínimo requerido es 3) -- aunque la nueva tarde muchísimo, no debe avisar.
        $this->crearOtCompletada($db, $proceso, 30, $proyectoId);
        $this->crearOtCompletada($db, $proceso, 30, $proyectoId);

        $otId = $this->crearOtCompletada($db, $proceso, 300, $proyectoId);
        avisarSiTiempoExcedido($db, $otId, 0);

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'ot_tiempo_excedido'")->fetch();
        $this->assertFalse($notif, 'no debió avisar: solo hay 2 OTs históricas, muestra insuficiente');
    }
}
