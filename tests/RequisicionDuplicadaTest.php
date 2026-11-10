<?php

/**
 * Cubre el aviso (no bloqueante) de posible requisición duplicada: dos
 * departamentos distintos generando cada uno su propia requisición para el
 * mismo proyecto pidiendo el mismo material -- incidente real que causó
 * compra doble. Ver detectarRequisicionesDuplicadas()/avisarDuplicado()/
 * notificarDuplicado() en includes/config.php, y el selector de proyecto
 * agregado a includes/generar_requisicion.php::procesarCrearRequisicionArea().
 */
final class RequisicionDuplicadaTest extends TestCase
{
    private const USER = 'qa_test_dup';
    private const PASS = 'ClavePruebaDup123';

    private int $materialId = 0;
    private int $proyectoId = 0;
    private array $requisicionIds = [];
    private int $maxNotifIdAntes = 0;

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->requisicionIds as $id) {
            $db->prepare('DELETE FROM requisicion_detalle WHERE requisicion_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM almacen_items WHERE requisicion_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM requisiciones WHERE id = ?')->execute([$id]);
        }
        if ($this->maxNotifIdAntes) {
            $db->prepare('DELETE FROM notificaciones WHERE id > ?')->execute([$this->maxNotifIdAntes]);
        }
        if ($this->proyectoId) {
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    private function seedProyectoYMaterial(): void
    {
        $db = $this->db();
        $this->materialId = (int) $db->query('SELECT id FROM materiales ORDER BY id LIMIT 1')->fetchColumn();
        $db->exec("INSERT INTO proyectos (nombre, creado_por) VALUES ('QA_TEST_DUP', 1)");
        $this->proyectoId = (int) $db->lastInsertId();
    }

    /** Inserta directo en BD una requisición activa ya existente para ese proyecto+material, de la $area dada. */
    private function seedRequisicionExistente(string $area, string $folio): int
    {
        $db = $this->db();
        $db->prepare("INSERT INTO requisiciones (folio, usuario_id, proyecto, proyecto_id, area, solicitante, estado, fecha_creacion) VALUES (?, 1, 'QA_TEST_DUP', ?, ?, 'QA Test', 'pendiente', NOW())")
           ->execute([$folio, $this->proyectoId, $area]);
        $id = (int) $db->lastInsertId();
        $this->requisicionIds[] = $id;
        $db->prepare('INSERT INTO requisicion_detalle (requisicion_id, item_numero, material_id, descripcion, cantidad, unidad_medida) VALUES (?, 1, ?, ?, 1, ?)')
           ->execute([$id, $this->materialId, 'QA test dup item', 'pza']);
        return $id;
    }

    public function testAvisaDuplicadoEntreDepartamentosDistintosYNotificaAlOriginal(): void
    {
        $db = $this->db();
        $this->seedProyectoYMaterial();
        $this->seedRequisicionExistente('Producción', 'QA-EXIST-1');
        $this->maxNotifIdAntes = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM notificaciones')->fetchColumn();

        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $res = $this->post('ingenieria.php?seccion=generar_requisicion', [
            'action'      => 'crear_requisicion_area',
            'proyecto_id' => $this->proyectoId,
            'prioridad'   => 'media',
            'fecha_requerida' => '',
            'notas'       => '',
            'items'       => [$this->materialId => ['material_id' => $this->materialId, 'cantidad' => 1]],
            'csrf_token'  => $csrf,
        ], $jar);

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('Posible requisición duplicada', $res['body'], 'Debe mostrar el aviso en la misma respuesta (ingenieria.php no redirige en este flujo)');
        $this->assertStringContainsString('QA-EXIST-1', $res['body']);

        // La nueva requisición SÍ se creó (no bloqueante).
        $nueva = $db->query("SELECT id FROM requisiciones WHERE proyecto_id = {$this->proyectoId} AND area = 'Ingeniería' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($nueva, 'La requisición nueva debe crearse aunque haya duplicado');
        $this->requisicionIds[] = (int) $nueva['id'];

        // Se notificó al departamento original (Producción -> rol manufactura).
        $notif = $db->prepare("SELECT * FROM notificaciones WHERE id > ? AND tipo = 'requisicion_posible_duplicada'");
        $notif->execute([$this->maxNotifIdAntes]);
        $filas = $notif->fetchAll();
        $this->assertNotEmpty($filas, 'Debe existir al menos una notificación de posible duplicado');
    }

    public function testNoAvisaSiLaRequisicionExistenteEsDelMismoDepartamento(): void
    {
        $db = $this->db();
        $this->seedProyectoYMaterial();
        $this->seedRequisicionExistente('Ingeniería', 'QA-EXIST-2');

        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $res = $this->post('ingenieria.php?seccion=generar_requisicion', [
            'action'      => 'crear_requisicion_area',
            'proyecto_id' => $this->proyectoId,
            'prioridad'   => 'media',
            'fecha_requerida' => '',
            'notas'       => '',
            'items'       => [$this->materialId => ['material_id' => $this->materialId, 'cantidad' => 1]],
            'csrf_token'  => $csrf,
        ], $jar);

        $this->assertSame(200, $res['code']);
        $this->assertStringNotContainsString('Posible requisición duplicada', $res['body'], 'No debe avisar si la única requisición activa existente es del mismo departamento');

        $nueva = $db->query("SELECT id FROM requisiciones WHERE proyecto_id = {$this->proyectoId} AND area = 'Ingeniería' ORDER BY id DESC LIMIT 1")->fetch();
        if ($nueva) $this->requisicionIds[] = (int) $nueva['id'];
    }

    public function testNoAvisaSiLaRequisicionEsSinProyecto(): void
    {
        $db = $this->db();
        $this->seedProyectoYMaterial();
        $this->seedRequisicionExistente('Producción', 'QA-EXIST-3');

        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $res = $this->post('ingenieria.php?seccion=generar_requisicion', [
            'action'      => 'crear_requisicion_area',
            'proyecto_id' => '', // sin proyecto -- requisición general
            'prioridad'   => 'media',
            'fecha_requerida' => '',
            'notas'       => '',
            'items'       => [$this->materialId => ['material_id' => $this->materialId, 'cantidad' => 1]],
            'csrf_token'  => $csrf,
        ], $jar);

        $this->assertSame(200, $res['code']);
        $this->assertStringNotContainsString('Posible requisición duplicada', $res['body'], 'Sin proyecto_id no debe dispararse ningún chequeo de duplicados');

        $nueva = $db->query("SELECT id FROM requisiciones WHERE area = 'Ingeniería' AND proyecto_id IS NULL ORDER BY id DESC LIMIT 1")->fetch();
        if ($nueva) $this->requisicionIds[] = (int) $nueva['id'];
    }
}
