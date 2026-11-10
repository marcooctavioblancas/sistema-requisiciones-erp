<?php

/**
 * Cubre 3 cambios de Hoja Viajera (2026-08-13):
 * 1) Cliente/Producto se autocompletan desde la Orden de Producción del proyecto
 *    al crear la hoja, ya no se capturan a mano.
 * 2) pasadas_recubrimiento: campo editable inline en el encabezado (mismo mecanismo
 *    que no_serie), solo acepta enteros -- cualquier otra cosa se guarda como NULL.
 * 3) Un proyecto puede tener varias hojas viajeras (antes bloqueado por un UNIQUE
 *    KEY en hoja_viajera.proyecto_id) -- regresión directa: si el UNIQUE volviera,
 *    la segunda INSERT fallaría dentro del try/catch y solo quedaría 1 fila.
 */
final class HojaViajeraTest extends TestCase
{
    private const USER = 'qa_test_hojaviajera';
    private const PASS = 'ClavePruebaHojaViajera123';

    private int $proyectoId = 0;
    /** @var int[] */
    private array $hojaIds = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->hojaIds as $hojaId) {
            $db->prepare('DELETE FROM hoja_viajera_pasos WHERE hoja_id = ?')->execute([$hojaId]);
            $db->prepare('DELETE FROM hoja_viajera WHERE id = ?')->execute([$hojaId]);
        }
        if ($this->proyectoId) {
            $db->prepare('DELETE FROM ordenes_produccion WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testCrearHojaAutocompletaClienteYProductoDesdeOrdenDeProduccion(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_HOJAVIAJERA', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $db->exec("INSERT INTO ordenes_produccion (proyecto_id, numero_proyecto, cliente, descripcion, estado, fecha_creacion)
                   VALUES ({$this->proyectoId}, 'QA_TEST_HOJAVIAJERA', 'QA_TEST Cliente SA', 'QA_TEST Producto valvula', 'activo', NOW())");

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('produccion.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($csrf);

        $this->post('api/hoja_viajera.php', [
            'accion'      => 'crear_hoja_viajera',
            'proyecto_id' => $this->proyectoId,
            'no_serie'    => 'QA_TEST_SN_A',
            'id_tag'      => 'QA_TEST_TAG_A',
            'csrf_token'  => $csrf,
        ], $jar);

        $stmt = $db->prepare('SELECT id, cliente, producto, no_serie FROM hoja_viajera WHERE proyecto_id = ?');
        $stmt->execute([$this->proyectoId]);
        $hoja = $stmt->fetch();
        $this->assertNotFalse($hoja, 'La hoja debió crearse');
        $this->hojaIds[] = (int) $hoja['id'];

        $this->assertSame('QA_TEST Cliente SA', $hoja['cliente'], 'Cliente debe salir de la Orden de Producción, no capturarse a mano');
        $this->assertSame('QA_TEST Producto valvula', $hoja['producto'], 'Producto debe salir de la Orden de Producción');
        $this->assertSame('QA_TEST_SN_A', $hoja['no_serie']);

        // Los 15 pasos fijos deben quedar creados junto con la hoja.
        $totalPasos = (int) $db->query("SELECT COUNT(*) FROM hoja_viajera_pasos WHERE hoja_id = {$hoja['id']}")->fetchColumn();
        $this->assertSame(15, $totalPasos);
    }

    public function testPasadasDeRecubrimientoSoloAceptaEnteros(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_HOJAVIAJERA', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO hoja_viajera (proyecto_id, cliente, no_serie, producto, creado_por, fecha_creacion)
                   VALUES ({$this->proyectoId}, 'QA_TEST Cliente', 'QA_TEST_SN_B', 'QA_TEST Producto', 1, NOW())");
        $hojaId = (int) $db->lastInsertId();
        $this->hojaIds[] = $hojaId;

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('produccion.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $this->post('api/hoja_viajera.php', [
            'accion'   => 'guardar_campo_encabezado',
            'hoja_id'  => $hojaId,
            'campo'    => 'pasadas_recubrimiento',
            'valor'    => '3',
            'csrf_token' => $csrf,
        ], $jar);
        $this->assertSame('3', (string) $db->query("SELECT pasadas_recubrimiento FROM hoja_viajera WHERE id = $hojaId")->fetchColumn());

        // Un valor no numérico se limpia a NULL en vez de fallar el UPDATE (columna INT).
        $this->post('api/hoja_viajera.php', [
            'accion'   => 'guardar_campo_encabezado',
            'hoja_id'  => $hojaId,
            'campo'    => 'pasadas_recubrimiento',
            'valor'    => 'tres',
            'csrf_token' => $csrf,
        ], $jar);
        $this->assertNull($db->query("SELECT pasadas_recubrimiento FROM hoja_viajera WHERE id = $hojaId")->fetchColumn());
    }

    public function testUnProyectoPuedeTenerVariasHojasViajeras(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_HOJAVIAJERA', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('produccion.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        foreach (['QA_TEST_SN_MULTI_1', 'QA_TEST_SN_MULTI_2'] as $noSerie) {
            $this->post('api/hoja_viajera.php', [
                'accion'      => 'crear_hoja_viajera',
                'proyecto_id' => $this->proyectoId,
                'no_serie'    => $noSerie,
                'csrf_token'  => $csrf,
            ], $jar);
        }

        $stmt = $db->prepare('SELECT id, no_serie FROM hoja_viajera WHERE proyecto_id = ? ORDER BY id');
        $stmt->execute([$this->proyectoId]);
        $hojas = $stmt->fetchAll();
        foreach ($hojas as $h) $this->hojaIds[] = (int) $h['id'];

        // Regresión directa del UNIQUE KEY que existía antes: sin el fix, la segunda
        // INSERT fallaba dentro del try/catch (rollBack silencioso) y aquí quedaría 1 sola fila.
        $this->assertCount(2, $hojas, 'El proyecto debe poder tener 2 hojas viajeras simultáneas');
        $this->assertSame('QA_TEST_SN_MULTI_1', $hojas[0]['no_serie']);
        $this->assertSame('QA_TEST_SN_MULTI_2', $hojas[1]['no_serie']);
    }
}
