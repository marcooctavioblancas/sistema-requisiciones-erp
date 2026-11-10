<?php

/**
 * Cubre el rediseño de Equipos Fabricados (2026-08-13): Ingeniería (no Producción)
 * genera los equipos con número de serie automático y les pone ID/TAG, antes de
 * que existan OTs. Regresión directa del bug real encontrado en vivo: los 2 forms
 * nuevos del componente compartido (includes/equipos_fabricados.php) para el rol
 * Ingeniería traían name="accion" pero ingenieria.php despacha por $_POST['action']
 * -- el botón "Generar equipos" no hacía nada, sin error visible. Si alguien vuelve
 * a romper el nombre del campo, testCrearEquiposDesdeIngenieria falla porque
 * ningún equipo queda creado.
 *
 * También cubre el gate proyectoListoParaEquipos(): Producción no puede vincular
 * componentes ni completar equipos hasta que el proyecto esté listo (OTs+Hoja
 * Viajera+FAT) -- un proyecto recién creado, sin nada de eso, nunca está listo.
 *
 * 2026-08-25: se quitó el paso manual de "cuántos equipos" -- ya no se manda
 * 'cantidad' por POST, el backend la toma directo de boom_listas.num_equipos del
 * BOM principal del proyecto (pedido explícito del usuario, para no capturar dos
 * veces el mismo número).
 */
final class EquiposFabricadosTest extends TestCase
{
    private const USER = 'qa_test_equiposfab';
    private const PASS = 'ClavePruebaEquiposFab123';

    private int $proyectoId = 0;

    protected function tearDown(): void
    {
        $db = $this->db();
        if ($this->proyectoId) {
            $db->prepare('DELETE FROM equipos_fabricados_componentes WHERE equipo_fabricado_id IN (SELECT id FROM equipos_fabricados WHERE proyecto_id = ?)')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM equipos_fabricados WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM boom_listas WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testCrearEquiposDesdeIngenieriaYAsignarIdTag(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_EQUIPOSFAB', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();
        // El número de equipos ya no se captura al generar -- se toma del BOM principal.
        $db->exec("INSERT INTO boom_listas (nombre, proyecto_id, creado_por, fecha_creacion, tipo_lista, num_equipos) VALUES ('QA_TEST_EQUIPOSFAB bom', {$this->proyectoId}, 1, NOW(), 'principal', 2)");

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($csrf);

        // Regresión del bug real: si el form volviera a usar name="accion" en vez de
        // name="action", ingenieria.php nunca entra al switch (isset($_POST['action'])
        // sería false) y esta llamada sería un no-op silencioso -- 0 equipos creados.
        $this->post('ingenieria.php', [
            'action'      => 'crear_equipos_fabricados',
            'proyecto_id' => $this->proyectoId,
            'csrf_token'  => $csrf,
        ], $jar);

        $stmt = $db->prepare('SELECT id, numero_serie, id_tag, estado FROM equipos_fabricados WHERE proyecto_id = ? ORDER BY id');
        $stmt->execute([$this->proyectoId]);
        $equipos = $stmt->fetchAll();

        $this->assertCount(2, $equipos, 'Ingeniería debió crear los 2 equipos pedidos');
        foreach ($equipos as $eq) {
            $this->assertMatchesRegularExpression(
                '/^QA_TEST_EQUIPOSFAB-\d{4}-\d{3}$/',
                $eq['numero_serie'],
                'El número de serie debe seguir el patrón proyecto-año-consecutivo'
            );
            $this->assertNull($eq['id_tag'], 'El ID/TAG se pone después, no al crear');
            $this->assertSame('borrador', $eq['estado']);
        }
        // Consecutivo real, sin huecos ni repetidos.
        $this->assertStringEndsWith('-001', $equipos[0]['numero_serie']);
        $this->assertStringEndsWith('-002', $equipos[1]['numero_serie']);

        // Asignar ID/TAG (misma regresión de nombre de campo que crear_equipos_fabricados).
        $equipoId = (int) $equipos[0]['id'];
        $this->post('ingenieria.php', [
            'action'      => 'guardar_id_tag_equipo',
            'proyecto_id' => $this->proyectoId,
            'equipo_id'   => $equipoId,
            'id_tag'      => 'QA_TEST_TAG_001',
            'csrf_token'  => $csrf,
        ], $jar);

        $idTagGuardado = $db->query("SELECT id_tag FROM equipos_fabricados WHERE id = $equipoId")->fetchColumn();
        $this->assertSame('QA_TEST_TAG_001', $idTagGuardado);
    }

    public function testProduccionNoPuedeVincularNiCompletarSiProyectoNoEstaListo(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_EQUIPOSFAB', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        // Un equipo en borrador, sin ninguna OT/Hoja Viajera/FAT -- proyectoListoParaEquipos()
        // debe ser false, así que produccion.php debe bloquear tanto vincular como completar
        // ANTES de siquiera validar que el serie_id/equipo_id sean reales.
        $db->exec("INSERT INTO equipos_fabricados (proyecto_id, numero_serie, creado_por) VALUES ({$this->proyectoId}, 'QA_TEST_EQUIPOSFAB-9999-001', 1)");
        $equipoId = (int) $db->lastInsertId();

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('produccion.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $this->post('produccion.php', [
            'accion'      => 'vincular_componente_equipo',
            'proyecto_id' => $this->proyectoId,
            'equipo_id'   => $equipoId,
            'serie_id'    => 999999,
            'csrf_token'  => $csrf,
        ], $jar);

        $vinculados = (int) $db->query("SELECT COUNT(*) FROM equipos_fabricados_componentes WHERE equipo_fabricado_id = $equipoId")->fetchColumn();
        $this->assertSame(0, $vinculados, 'No debe poder vincular componentes mientras el proyecto no esté listo');

        $this->post('produccion.php', [
            'accion'      => 'completar_equipo_fabricado',
            'proyecto_id' => $this->proyectoId,
            'equipo_id'   => $equipoId,
            'csrf_token'  => $csrf,
        ], $jar);

        $estado = $db->query("SELECT estado FROM equipos_fabricados WHERE id = $equipoId")->fetchColumn();
        $this->assertSame('borrador', $estado, 'No debe poder completarse mientras el proyecto no esté listo');
    }
}
