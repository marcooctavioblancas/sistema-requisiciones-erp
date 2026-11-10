<?php

/**
 * Cubre el fix de 2026-08-01: generar_ordenes (flujo de OC de PROYECTO, en
 * includes/solicitar_cotizacion.php) no guardaba material_id en
 * orden_compra_detalle -- a diferencia de su gemelo generar_ordenes_req, que
 * sí lo hacía. El JS de recepción (almacen2.php) ya mandaba material_id bien,
 * pero como la fila en BD siempre traía NULL, confirmar_recepcion nunca podía
 * llamar a registrarMovimientoAlmacen() y materiales.stock_disponible nunca
 * subía de verdad para OCs de proyecto. Ver AUDITORIA_SEGURIDAD.md.
 */
final class StockOcRecepcionTest extends TestCase
{
    private const USER = 'qa_test_stockoc';
    private const PASS = 'ClavePruebaStockOc123';

    private int $materialId = 0;
    private float $stockOriginal = 0;
    private int $proyectoId = 0;

    protected function tearDown(): void
    {
        $db = $this->db();
        if ($this->materialId) {
            $db->prepare('UPDATE materiales SET stock_disponible = ? WHERE id = ?')
               ->execute([$this->stockOriginal, $this->materialId]);
        }
        if ($this->proyectoId) {
            $db->prepare("DELETE FROM historial_movimientos WHERE referencia_tipo = 'orden_compra' AND referencia_id = ?")->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM orden_compra_detalle WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE proyecto_id = ?)')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM ordenes_compra WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM cotizaciones WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare("DELETE FROM auditoria WHERE tabla = 'proyectos' AND registro_id = ?")->execute([$this->proyectoId]);
            $db->prepare("DELETE FROM notificaciones WHERE mensaje LIKE '%QA_TEST_STOCKOC%'")->execute();
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testRecepcionDeOcDeProyectoSubeStockReal(): void
    {
        $db = $this->db();

        $material = $db->query('SELECT id, stock_disponible FROM materiales ORDER BY id LIMIT 1')->fetch();
        $this->materialId    = (int) $material['id'];
        $this->stockOriginal = (float) $material['stock_disponible'];

        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_STOCKOC', 1, 'cotizacion_recibida', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $datosIa = json_encode(['productos' => [[
            'material_id'      => $this->materialId,
            'descripcion'      => 'QA test stock item',
            'unidad'           => 'pza',
            'cantidad'         => 3,
            'precio_unitario'  => 50,
        ]]]);
        $db->prepare("INSERT INTO cotizaciones (proyecto_id, proveedor_id, estado, datos_ia) VALUES (?, (SELECT id FROM proveedores LIMIT 1), 'recibida', ?)")
           ->execute([$this->proyectoId, $datosIa]);
        $cotId = (int) $db->lastInsertId();

        $jar  = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('compras.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $seleccion = json_encode([(string) $cotId => [[
            'descripcion'  => 'QA test stock item',
            'unidad'       => 'pza',
            'cantidad'     => 3,
            'material_id'  => $this->materialId,
            'precio_unit'  => 50,
        ]]]);
        $res = $this->post('includes/solicitar_cotizacion.php', [
            'action'      => 'generar_ordenes',
            'proyecto_id' => $this->proyectoId,
            'seleccion'   => $seleccion,
            'csrf_token'  => $csrf,
        ], $jar);
        $gen = json_decode($res['body'], true);
        $this->assertTrue($gen['success'] ?? false, 'generar_ordenes debería tener éxito: ' . $res['body']);

        // El bug real, sin el fix: esta fila quedaba con material_id NULL.
        $detalle = $db->prepare('SELECT material_id FROM orden_compra_detalle WHERE orden_id = ?');
        $detalle->execute([$gen['ordenes'][0]['id']]);
        $this->assertSame($this->materialId, (int) $detalle->fetchColumn(), 'orden_compra_detalle.material_id debe quedar poblado al generar la OC de proyecto');

        $itemsDetalle = json_encode([[
            'oc_id' => $gen['ordenes'][0]['id'], 'folio' => $gen['ordenes'][0]['folio'],
            'proveedor' => '', 'proveedor_id' => null,
            'descripcion' => 'QA test stock item', 'cantidad_ordenada' => 3, 'cantidad_recibida' => 3,
            'unidad' => 'pza', 'material_id' => $this->materialId, 'estado' => 'completo',
        ]]);
        $res2 = $this->post('includes/solicitar_cotizacion.php', [
            'action'                  => 'confirmar_recepcion',
            'proyecto_id'             => $this->proyectoId,
            'completo'                => 1,
            'items_detalle'           => $itemsDetalle,
            'notas_recepcion'         => '',
            'incidencias'             => '[]',
            'proveedores_incompletos' => '[]',
            'csrf_token'              => $csrf,
        ], $jar);
        $rec = json_decode($res2['body'], true);
        $this->assertTrue($rec['success'] ?? false, 'confirmar_recepcion debería tener éxito: ' . $res2['body']);

        $stockNuevo = (float) $db->query("SELECT stock_disponible FROM materiales WHERE id = {$this->materialId}")->fetchColumn();
        $this->assertSame($this->stockOriginal + 3, $stockNuevo, 'La recepción de la OC de proyecto debe subir materiales.stock_disponible de verdad');
    }
}
