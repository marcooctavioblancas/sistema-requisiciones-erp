<?php

/**
 * Cubre boom_items.categoria_doc (clasificación de items para documentos: Componente
 * Principal/Conectores/Material Eléctrico/Tornillería, con color y orden fijos,
 * independiente del catálogo real) y boom_listas.tipo_valvula (2026-08-13).
 * Prueba contra los endpoints reales (api/guardar_item_bom.php,
 * api/guardar_tipo_valvula_bom.php) y contra la salida real del PDF impreso
 * (api/vista_pdf_bom.php), no solo contra la BD.
 */
final class BomCategoriaDocTest extends TestCase
{
    private const USER = 'qa_test_bomcatdoc';
    private const PASS = 'ClavePruebaBomCatDoc123';

    private int $proyectoId = 0;
    private int $boomId = 0;

    protected function tearDown(): void
    {
        $db = $this->db();
        if ($this->boomId) {
            $db->prepare('DELETE FROM boom_items WHERE boom_id = ?')->execute([$this->boomId]);
            $db->prepare('DELETE FROM boom_listas WHERE id = ?')->execute([$this->boomId]);
        }
        if ($this->proyectoId) {
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testGuardarCategoriaDocSeReflejaEnPdfConOrdenYColorCorrectos(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_BOMCATDOC', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $db->exec("INSERT INTO boom_listas (nombre, proyecto_id, creado_por, fecha_creacion, enviado_almacen) VALUES ('QA_TEST_BOMCATDOC bom', {$this->proyectoId}, 1, NOW(), 0)");
        $this->boomId = (int) $db->lastInsertId();

        // Item B se guarda primero pero debe imprimirse DESPUÉS del A -- el orden en el PDF
        // lo da la categoría de documento (Componente Principal antes que Conectores), no
        // el orden de inserción/guardado.
        $db->exec("INSERT INTO boom_items (proyecto_id, boom_id, item, descripcion, cantidad, guardado) VALUES ({$this->proyectoId}, {$this->boomId}, 'item-b', 'QA_TEST item conector', 1, 1)");
        $itemConectorId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO boom_items (proyecto_id, boom_id, item, descripcion, cantidad, guardado) VALUES ({$this->proyectoId}, {$this->boomId}, 'item-a', 'QA_TEST item principal', 1, 1)");
        $itemPrincipalId = (int) $db->lastInsertId();

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($csrf);

        $resConector = $this->postJson('api/guardar_item_bom.php', [
            'item_id'       => $itemConectorId,
            'categoria_doc' => 'conector',
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $this->assertSame(200, $resConector['code']);

        $resPrincipal = $this->postJson('api/guardar_item_bom.php', [
            'item_id'       => $itemPrincipalId,
            'categoria_doc' => 'componente_principal',
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $this->assertSame(200, $resPrincipal['code']);

        $guardados = $db->query("SELECT id, categoria_doc FROM boom_items WHERE boom_id = {$this->boomId}")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('conector', $guardados[$itemConectorId]);
        $this->assertSame('componente_principal', $guardados[$itemPrincipalId]);

        // Un valor fuera de la whitelist del ENUM debe ignorarse (columna sin tocar), no
        // reventar la query -- la whitelist vive en el propio endpoint, no solo en el ENUM de BD.
        $resInvalido = $this->postJson('api/guardar_item_bom.php', [
            'item_id'       => $itemConectorId,
            'categoria_doc' => 'valor_inventado',
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $this->assertSame(200, $resInvalido['code']);
        $sigueConector = $db->query("SELECT categoria_doc FROM boom_items WHERE id = $itemConectorId")->fetchColumn();
        $this->assertSame('conector', $sigueConector, 'Un categoria_doc inválido no debe sobrescribir el valor ya guardado');

        // Reflejo real en el PDF impreso: orden (Componente Principal antes que Conectores)
        // y color (rojo #c62828 para principal, azul #1565c0 para conectores).
        $pdf = $this->get("api/vista_pdf_bom.php?boom_id={$this->boomId}", $jar);
        $this->assertSame(200, $pdf['code']);
        $this->assertStringContainsString('#c62828', $pdf['body'], 'El PDF debe traer el color rojo de Componente Principal');
        $this->assertStringContainsString('#1565c0', $pdf['body'], 'El PDF debe traer el color azul de Conectores');
        $posPrincipal = strpos($pdf['body'], 'QA_TEST item principal');
        $posConector  = strpos($pdf['body'], 'QA_TEST item conector');
        $this->assertNotFalse($posPrincipal);
        $this->assertNotFalse($posConector);
        $this->assertLessThan($posConector, $posPrincipal, 'Componente Principal debe imprimirse antes que Conectores, sin importar el orden en que se guardaron');
    }

    public function testTipoValvulaSeGuardaYSeBloqueaSiElBomYaEstaLiberado(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_BOMCATDOC', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $db->exec("INSERT INTO boom_listas (nombre, proyecto_id, creado_por, fecha_creacion, enviado_almacen) VALUES ('QA_TEST_BOMCATDOC bom valvula', {$this->proyectoId}, 1, NOW(), 0)");
        $this->boomId = (int) $db->lastInsertId();

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $res = $this->postJson('api/guardar_tipo_valvula_bom.php', [
            'boom_id'      => $this->boomId,
            'tipo_valvula' => 'QA_TEST VALVULA 6"-600#',
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $this->assertSame(200, $res['code']);
        $guardado = $db->query("SELECT tipo_valvula FROM boom_listas WHERE id = {$this->boomId}")->fetchColumn();
        $this->assertSame('QA_TEST VALVULA 6"-600#', $guardado);

        // BOM liberado (enviado_almacen=1) es de solo lectura -- el intento de cambio debe
        // rechazarse y el valor previo debe quedar intacto.
        $db->exec("UPDATE boom_listas SET enviado_almacen = 1 WHERE id = {$this->boomId}");
        $resBloqueado = $this->postJson('api/guardar_tipo_valvula_bom.php', [
            'boom_id'      => $this->boomId,
            'tipo_valvula' => 'QA_TEST OTRO VALOR',
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $this->assertSame(403, $resBloqueado['code']);
        $sigueIgual = $db->query("SELECT tipo_valvula FROM boom_listas WHERE id = {$this->boomId}")->fetchColumn();
        $this->assertSame('QA_TEST VALVULA 6"-600#', $sigueIgual, 'Un BOM liberado no debe permitir cambiar el tipo de válvula');
    }
}
