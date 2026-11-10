<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../includes/config.php';

/**
 * Consecutivos de código de material (2026-08-21). Diseñado con el usuario: solo el jefe
 * de Almacén (o admin) puede escribir código de verdad -- Compras/Ingeniería/trabajadores
 * de Almacén solo ven una sugerencia (prefijo de 4 letras de la descripción + siguiente
 * consecutivo) que se guarda sin verificar, y Almacén revisa después sin bloquear el uso
 * normal del material mientras tanto.
 */
final class CodigoMaterialTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_cod_compras');
        $this->qaDeleteUser('qa_test_cod_jefe');
        $this->qaDeleteUser('qa_test_cod_trab');
        parent::tearDown();
    }

    public function testHelperSugiereConDescripcionCorta(): void
    {
        $db = $this->db();
        $this->assertNull(sugerirCodigoMaterial($db, 'XY'), 'menos de 4 caracteres sin espacios no debe dar un prefijo real');
    }

    public function testHelperSugiereSiguienteConsecutivoDelPrefijo(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO materiales (codigo, codigo_verificado, descripcion, activo) VALUES ('QATX000005', 1, 'QA_TEST cod existente', 1)");
        $matId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = $matId";

        $sugerido = sugerirCodigoMaterial($db, 'QATX ALGO NUEVO DE PRUEBA');
        $this->assertSame('QATX000006', $sugerido);
    }

    public function testCompraCreaMaterialSinPermisoQuedaSinVerificarYNotificaAlmacen(): void
    {
        $db = $this->db();
        $maxNotifId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();
        $this->cleanupSql[] = "DELETE FROM notificaciones WHERE id > $maxNotifId";

        $comprasId = $this->qaCreateUser('qa_test_cod_compras', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"compras\"]' WHERE id = $comprasId");
        $jar = $this->loginAs('qa_test_cod_compras', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('compras.php', $jar)['body']);
        $this->assertNotNull($token);

        $resp = $this->post('compras.php', [
            'action' => 'guardar_material',
            'codigo' => 'INVENTADO999999', // debe ignorarse: quien crea no es jefe de almacén
            'descripcion' => 'MATERIAL QA_TEST_COD desde compras',
            'categoria_id' => '1',
            'marca' => '', 'modelo' => '', 'precio_unitario' => '0',
            'stock_disponible' => '0', 'stock_minimo' => '0', 'unidad_medida' => 'pza',
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $mat = $db->query("SELECT id, codigo, codigo_verificado FROM materiales WHERE descripcion = 'MATERIAL QA_TEST_COD desde compras'")->fetch();
        $this->assertNotFalse($mat, 'debió crearse el material');
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = {$mat['id']}";

        $this->assertNotSame('INVENTADO999999', $mat['codigo'], 'compras no puede escribir el código a mano');
        $this->assertMatchesRegularExpression('/^MATE\d{6}$/', $mat['codigo'], 'debió sugerirse prefijo de la descripción + consecutivo');
        $this->assertSame(0, (int)$mat['codigo_verificado'], 'debe quedar sin verificar');

        $notif = $db->query("SELECT * FROM notificaciones WHERE id > $maxNotifId AND tipo = 'material_sin_verificar' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotFalse($notif, 'debió notificar a Almacén para que verifique');
    }

    public function testJefeAlmacenAsignaCodigoLibreYQuedaVerificado(): void
    {
        $db = $this->db();
        $jefeId = $this->qaCreateUser('qa_test_cod_jefe', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"almacen\"]' WHERE id = $jefeId");
        $db->prepare("INSERT INTO usuario_niveles (usuario_id, modulo, nivel) VALUES (?, 'almacen', 'jefe')")->execute([$jefeId]);
        $this->cleanupSql[] = "DELETE FROM usuario_niveles WHERE usuario_id = $jefeId";

        $jar = $this->loginAs('qa_test_cod_jefe', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('almacen2.php', $jar)['body']);
        $this->assertNotNull($token);

        $resp = $this->post('almacen2.php', [
            'action' => 'guardar_material',
            'codigo' => 'QATX900001',
            'descripcion' => 'QA_TEST_COD material desde jefe almacen',
            'categoria_id' => '1',
            'marca' => '', 'modelo' => '', 'unidad_medida' => 'pza', 'ubicacion' => '', 'stock_minimo' => '0',
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $mat = $db->query("SELECT id, codigo, codigo_verificado FROM materiales WHERE descripcion = 'QA_TEST_COD material desde jefe almacen'")->fetch();
        $this->assertNotFalse($mat);
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = {$mat['id']}";

        $this->assertSame('QATX900001', $mat['codigo'], 'el jefe de Almacén sí puede asignar el código que elija');
        $this->assertSame(1, (int)$mat['codigo_verificado'], 'lo que crea el propio jefe queda verificado');
    }

    public function testTrabajadorAlmacenSinNivelJefeNoPuedeAsignarCodigo(): void
    {
        $db = $this->db();
        $trabId = $this->qaCreateUser('qa_test_cod_trab', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"almacen\"]' WHERE id = $trabId"); // sin usuario_niveles -> 'trabajador' por default

        $jar = $this->loginAs('qa_test_cod_trab', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('almacen2.php', $jar)['body']);

        $resp = $this->post('almacen2.php', [
            'action' => 'guardar_material',
            'codigo' => 'QATX900002',
            'descripcion' => 'QA_TEST_COD material desde trabajador almacen',
            'categoria_id' => '1',
            'marca' => '', 'modelo' => '', 'unidad_medida' => 'pza', 'ubicacion' => '', 'stock_minimo' => '0',
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $mat = $db->query("SELECT id, codigo, codigo_verificado FROM materiales WHERE descripcion = 'QA_TEST_COD material desde trabajador almacen'")->fetch();
        $this->assertNotFalse($mat);
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = {$mat['id']}";

        $this->assertNotSame('QATX900002', $mat['codigo'], 'un trabajador de Almacén sin nivel jefe no debió poder fijar el código a mano');
        $this->assertSame(0, (int)$mat['codigo_verificado']);
    }

    public function testEditarSinPermisoNoTocaCodigoNiVerificacion(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO materiales (codigo, codigo_verificado, descripcion, activo, ubicacion) VALUES ('QATX900003', 0, 'QA_TEST_COD material a editar', 1, 'A1')");
        $matId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = $matId";

        $trabId = $this->qaCreateUser('qa_test_cod_trab', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"almacen\"]' WHERE id = $trabId");

        $jar = $this->loginAs('qa_test_cod_trab', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('almacen2.php', $jar)['body']);

        $resp = $this->post('almacen2.php', [
            'action' => 'editar_material',
            'id' => (string)$matId,
            'codigo' => 'QATX999999', // intento de cambiarlo a mano, debe ignorarse
            'descripcion' => 'QA_TEST_COD material a editar',
            'categoria_id' => '1',
            'marca' => '', 'modelo' => '', 'unidad_medida' => 'pza', 'ubicacion' => 'B2', 'stock_minimo' => '5',
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $mat = $db->query("SELECT codigo, codigo_verificado, ubicacion, stock_minimo FROM materiales WHERE id = $matId")->fetch();
        $this->assertSame('QATX900003', $mat['codigo'], 'el código no debió cambiar');
        $this->assertSame(0, (int)$mat['codigo_verificado'], 'la verificación no debió cambiar');
        $this->assertSame('B2', $mat['ubicacion'], 'el resto de los campos sí debió actualizarse con normalidad');
        $this->assertSame(5, (int)$mat['stock_minimo']);
    }

    public function testJefeAlmacenVerificaAlEditar(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO materiales (codigo, codigo_verificado, descripcion, activo) VALUES ('QATX900004', 0, 'QA_TEST_COD material pendiente', 1)");
        $matId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = $matId";

        $jefeId = $this->qaCreateUser('qa_test_cod_jefe', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"almacen\"]' WHERE id = $jefeId");
        $db->prepare("INSERT INTO usuario_niveles (usuario_id, modulo, nivel) VALUES (?, 'almacen', 'jefe')")->execute([$jefeId]);
        $this->cleanupSql[] = "DELETE FROM usuario_niveles WHERE usuario_id = $jefeId";

        $jar = $this->loginAs('qa_test_cod_jefe', 'Passw0rd!');
        $token = $this->extractCsrfToken($this->get('almacen2.php', $jar)['body']);

        $resp = $this->post('almacen2.php', [
            'action' => 'editar_material',
            'id' => (string)$matId,
            'codigo' => 'QATX900004', // el jefe lo confirma sin cambiarlo
            'descripcion' => 'QA_TEST_COD material pendiente',
            'categoria_id' => '1',
            'marca' => '', 'modelo' => '', 'unidad_medida' => 'pza', 'ubicacion' => '', 'stock_minimo' => '0',
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $mat = $db->query("SELECT codigo_verificado FROM materiales WHERE id = $matId")->fetch();
        $this->assertSame(1, (int)$mat['codigo_verificado'], 'el jefe de Almacén confirmó el material -- queda verificado');
    }

    public function testMaterialNuncaAbiertoApareceAntesQueUnoViejoEnElInventario(): void
    {
        $db = $this->db();
        // "AAAA..." ordena primero alfabéticamente que "ZZZZ..." -- si el orden fuera puramente
        // alfabético, el viejo (AAAA) saldría antes en el HTML. Con el pin al frente debe ser
        // al revés: el nunca-abierto (primera_vista NULL) sale primero aunque su descripción
        // empiece con Z.
        $db->exec("INSERT INTO materiales (codigo, codigo_verificado, descripcion, activo, primera_vista) VALUES ('QATX910001', 1, 'AAAA_QA_TEST_COD_ORDEN viejo ya visto', 1, '2020-01-01 00:00:00')");
        $viejoId = (int)$db->lastInsertId();
        $db->exec("INSERT INTO materiales (codigo, codigo_verificado, descripcion, activo, primera_vista) VALUES ('QATX910002', 0, 'ZZZZ_QA_TEST_COD_ORDEN nunca abierto', 1, NULL)");
        $nuevoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id IN ($viejoId, $nuevoId)";

        $this->qaCreateUser('qa_test_cod_jefe', 'Passw0rd!', 'admin');
        $jar = $this->loginAs('qa_test_cod_jefe', 'Passw0rd!');
        $body = $this->get('almacen2.php?seccion=inventario', $jar)['body'];

        $posViejo = strpos($body, 'AAAA_QA_TEST_COD_ORDEN');
        $posNuevo = strpos($body, 'ZZZZ_QA_TEST_COD_ORDEN');
        $this->assertNotFalse($posViejo);
        $this->assertNotFalse($posNuevo);
        $this->assertLessThan($posViejo, $posNuevo, 'el material nunca abierto debe salir ANTES que el viejo, aunque alfabéticamente vaya después');
    }

    public function testMarcarVistoSacaAlMaterialDelFrenteDelInventario(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO materiales (codigo, codigo_verificado, descripcion, activo, primera_vista) VALUES ('QATX910003', 0, 'QA_TEST_COD_VISTO material pendiente', 1, NULL)");
        $matId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM materiales WHERE id = $matId";

        $this->qaCreateUser('qa_test_cod_jefe', 'Passw0rd!', 'admin');
        $jar = $this->loginAs('qa_test_cod_jefe', 'Passw0rd!');
        $page = $this->get('almacen2.php', $jar);
        $token = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($token);

        $resp = $this->post('api/materiales.php?action=marcar_visto', [
            'id' => (string)$matId,
            'csrf_token' => $token,
        ], $jar);
        $this->assertSame(200, $resp['code']);

        $mat = $db->query("SELECT primera_vista FROM materiales WHERE id = $matId")->fetch();
        $this->assertNotNull($mat['primera_vista'], 'marcar_visto debió fijar primera_vista');
    }
}
