<?php
require_once __DIR__ . '/TestCase.php';

/**
 * "Liberar Proyecto a Compras" (ingenieria.php, case 'finalizar_proyecto', 2026-08-20).
 * Antes tenía su propio empuje paralelo de materiales a almacen_items (INSERT IGNORE,
 * sin transacción) que solo corría si el proyecto no tenía NINGÚN almacen_items todavía
 * -- con 2+ BOMs y solo uno guardado, los materiales de los demás se perdían en silencio.
 * Se quitó ese camino: ahora la única forma de mandar materiales a Almacén es
 * guardar_boom (vía sincronizarAlmacenItem), y liberar el proyecto exige que TODOS sus
 * BOMs reales (tipo_lista principal/adicional) ya estén enviado_almacen=1 -- si falta
 * alguno, bloquea y nombra cuál.
 */
final class FinalizarProyectoTest extends TestCase
{
    private const USER = 'qa_test_finproy';
    private const PASS = 'ClavePruebaFinProy123';

    private int $proyectoId = 0;
    private array $boomIds = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->boomIds as $bid) {
            $db->prepare('DELETE FROM boom_items WHERE boom_id = ?')->execute([$bid]);
            $db->prepare('DELETE FROM boom_listas WHERE id = ?')->execute([$bid]);
        }
        if ($this->proyectoId) {
            $db->prepare('DELETE FROM almacen_items WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM calendario_eventos WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM proyecto_fases WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    private function crearProyecto(PDO $db): void
    {
        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_FINPROY', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();
    }

    private function crearBom(PDO $db, string $nombre, bool $enviadoAlmacen): int
    {
        $env = $enviadoAlmacen ? 1 : 0;
        $db->exec("INSERT INTO boom_listas (nombre, proyecto_id, creado_por, fecha_creacion, guardado, enviado_almacen, tipo_lista) VALUES ('" . addslashes($nombre) . "', {$this->proyectoId}, 1, NOW(), 1, $env, 'principal')");
        $id = (int) $db->lastInsertId();
        $this->boomIds[] = $id;
        return $id;
    }

    public function testBloqueaYNombraElBomQueFaltaGuardar(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $this->crearProyecto($db);
        $this->crearBom($db, 'QA_TEST_FINPROY BOM Guardado', true);
        $this->crearBom($db, 'QA_TEST_FINPROY BOM Pendiente', false);

        $jar = $this->loginAs(self::USER, self::PASS);
        // El botón real vive en ?seccion=proyectos y el form postea a la misma URL (action="")
        // -- el aviso solo se imprime ahí (renderFlash(), ver ingenieria.php:3843), no en el
        // home de ingenieria.php sin seccion.
        $page = $this->get('ingenieria.php?seccion=proyectos', $jar);
        $csrf = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($csrf);

        $resp = $this->post('ingenieria.php?seccion=proyectos', [
            'action' => 'finalizar_proyecto',
            'id' => (string) $this->proyectoId,
            'csrf_token' => $csrf,
        ], $jar);
        $this->assertSame(200, $resp['code']);
        $this->assertStringContainsString('QA_TEST_FINPROY BOM Pendiente', $resp['body'], 'el error debe nombrar el BOM que falta guardar');

        $estado = $db->query("SELECT estado FROM proyectos WHERE id = {$this->proyectoId}")->fetchColumn();
        $this->assertNotSame('finalizado', $estado, 'no debió liberarse mientras falte un BOM por guardar');
    }

    public function testLiberaCuandoTodosLosBomsEstanGuardados(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $this->crearProyecto($db);
        $this->crearBom($db, 'QA_TEST_FINPROY BOM Unico', true);

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php?seccion=proyectos', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $resp = $this->post('ingenieria.php?seccion=proyectos', [
            'action' => 'finalizar_proyecto',
            'id' => (string) $this->proyectoId,
            'csrf_token' => $csrf,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $estado = $db->query("SELECT estado FROM proyectos WHERE id = {$this->proyectoId}")->fetchColumn();
        $this->assertSame('finalizado', $estado, 'debió liberarse: su único BOM ya estaba guardado');
    }
}
