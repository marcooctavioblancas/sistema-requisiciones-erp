<?php

/**
 * Cubre el fix de 2026-08-04: materiales.precio_unitario nunca se
 * actualizaba con el precio real de una compra -- el unico lugar que lo
 * tocaba en todo el sistema era el editor manual de catalogo en
 * compras.php. El precio real y confiable (validarItemsContraCotizacion(),
 * viene de cotizaciones.datos_ia, blindado contra manipulacion) ahora se
 * sincroniza al material en cuanto se genera la OC (includes/solicitar_cotizacion.php).
 */
final class PrecioMaterialOcTest extends TestCase
{
    private const USER = 'qa_test_precio_oc';
    private const PASS = 'ClavePruebaPrecioOc123';

    private int $materialId = 0;
    private float $precioOriginal = 0;
    private int $proyectoId = 0;

    protected function tearDown(): void
    {
        $db = $this->db();
        if ($this->materialId) {
            $db->prepare('UPDATE materiales SET precio_unitario = ? WHERE id = ?')
               ->execute([$this->precioOriginal, $this->materialId]);
        }
        if ($this->proyectoId) {
            $db->prepare('DELETE FROM orden_compra_detalle WHERE orden_id IN (SELECT id FROM ordenes_compra WHERE proyecto_id = ?)')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM ordenes_compra WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM cotizaciones WHERE proyecto_id = ?')->execute([$this->proyectoId]);
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testGenerarOrdenActualizaPrecioUnitarioDelMaterial(): void
    {
        $db = $this->db();

        $material = $db->query('SELECT id, precio_unitario FROM materiales ORDER BY id LIMIT 1')->fetch();
        $this->materialId     = (int) $material['id'];
        $this->precioOriginal = (float) $material['precio_unitario'];
        $precioNuevo = $this->precioOriginal + 37.5; // distinto a lo que ya haya, para que el assert sea real

        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_PRECIO_OC', 1, 'cotizacion_recibida', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $datosIa = json_encode(['productos' => [[
            'material_id'     => $this->materialId,
            'descripcion'     => 'QA test precio item',
            'unidad'          => 'pza',
            'cantidad'        => 5,
            'precio_unitario' => $precioNuevo,
        ]]]);
        $db->prepare("INSERT INTO cotizaciones (proyecto_id, proveedor_id, estado, datos_ia) VALUES (?, (SELECT id FROM proveedores LIMIT 1), 'recibida', ?)")
           ->execute([$this->proyectoId, $datosIa]);
        $cotId = (int) $db->lastInsertId();

        $jar  = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('compras.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $seleccion = json_encode([(string) $cotId => [[
            'descripcion'  => 'QA test precio item',
            'unidad'       => 'pza',
            'cantidad'     => 5,
            'material_id'  => $this->materialId,
            'precio_unit'  => $precioNuevo,
        ]]]);
        $res = $this->post('includes/solicitar_cotizacion.php', [
            'action'      => 'generar_ordenes',
            'proyecto_id' => $this->proyectoId,
            'seleccion'   => $seleccion,
            'csrf_token'  => $csrf,
        ], $jar);
        $gen = json_decode($res['body'], true);
        $this->assertTrue($gen['success'] ?? false, 'generar_ordenes debería tener éxito: ' . $res['body']);

        $precioGuardado = (float) $db->query("SELECT precio_unitario FROM materiales WHERE id = {$this->materialId}")->fetchColumn();
        $this->assertSame($precioNuevo, $precioGuardado, 'materiales.precio_unitario debe quedar sincronizado con el precio real de la OC recién generada');
    }
}
