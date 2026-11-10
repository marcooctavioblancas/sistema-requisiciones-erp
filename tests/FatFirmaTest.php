<?php

/**
 * Cubre el flujo de firmas de Pruebas FAT: Ingeniería genera la prueba (define la
 * Etapa 5 -- Pruebas de Funcionalidad, como pasos con campos de captura tipados) Y
 * firma primero; luego Producción -> Proyectos -> Calidad -> completado (2026-08-14,
 * el usuario pidió agregar Ingeniería como primer eslabón de la cadena, antes solo
 * creaba sin firmar). El gate de turno en api/fat_firma.php
 * ($fat['estado'] !== $orden_firmas[$rol]) ya existía desde antes -- lo que cambió
 * fue agregar 'ingenieria' a ese mapeo y a $siguiente/$notifs.
 */
final class FatFirmaTest extends TestCase
{
    private const USER = 'qa_test_fatflow';
    private const PASS = 'ClavePruebaFatFlow123';

    private int $proyectoId = 0;
    private int $fatId = 0;
    /** @var string[] rutas de firma guardadas en uploads/, para borrar en tearDown */
    private array $firmasGuardadas = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->firmasGuardadas as $ruta) {
            $abs = dirname(__DIR__) . '/uploads/' . $ruta;
            if (is_file($abs)) @unlink($abs);
        }
        if ($this->fatId) {
            $db->prepare('DELETE FROM pruebas_fat WHERE id = ?')->execute([$this->fatId]);
        }
        if ($this->proyectoId) {
            $db->prepare("DELETE FROM notificaciones WHERE mensaje LIKE '%QA_TEST_FAT_FLOW%'")->execute();
            $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    private function firmaFile(): CURLFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qa_test_firma_') . '.png';
        // Firma real de un magic-number de PNG basta -- fat_firma.php solo valida la
        // extensión del nombre de archivo, no decodifica la imagen.
        file_put_contents($tmp, "\x89PNG\r\n\x1a\n");
        return new CURLFile($tmp, 'image/png', 'firma.png');
    }

    public function testFlujoCompletoDeFirmasEnOrdenIngenieriaProduccionProyectosCalidad(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::USER, self::PASS, 'admin');

        $db->exec("INSERT INTO proyectos (nombre, creado_por, estado, fecha_estado) VALUES ('QA_TEST_FAT_FLOW', 1, 'activo', NOW())");
        $this->proyectoId = (int) $db->lastInsertId();

        $jar = $this->loginAs(self::USER, self::PASS);
        $page = $this->get('ingenieria.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($csrf, 'ingenieria.php debe traer el token CSRF inyectado');

        // 1) Ingeniería crea la FAT con la Etapa 5 como pasos estructurados.
        $pasos = [
            ['titulo' => 'Paso 1', 'instrucciones' => 'Instrucciones 1', 'campos' => [
                ['tipo' => 'texto', 'etiqueta' => 'Lectura', 'unidad' => 'PSI', 'valor' => ''],
            ], 'resultado' => null],
            ['titulo' => 'Paso 2', 'instrucciones' => 'Instrucciones 2', 'campos' => [
                ['tipo' => 'opciones', 'etiqueta' => 'Posición', 'opciones' => ['Abierto', 'Cerrado'], 'valor' => ''],
            ], 'resultado' => null],
        ];
        $this->post('ingenieria.php', [
            'action'          => 'crear_fat',
            'fat_proyecto_id' => $this->proyectoId,
            'fat_titulo'      => 'QA_TEST_FAT_FLOW titulo',
            'pasos_json'      => json_encode($pasos),
            'csrf_token'      => $csrf,
        ], $jar);

        $stmt = $db->prepare("SELECT id, estado, checklist FROM pruebas_fat WHERE proyecto_id = ? AND titulo = ?");
        $stmt->execute([$this->proyectoId, 'QA_TEST_FAT_FLOW titulo']);
        $fat = $stmt->fetch();
        $this->assertNotFalse($fat, 'La FAT debió crearse desde ingenieria.php');
        $this->fatId = (int) $fat['id'];
        $this->assertSame('pendiente_ingenieria', $fat['estado']);
        $checklistGuardado = json_decode($fat['checklist'], true);
        $this->assertCount(2, $checklistGuardado, 'Los 2 pasos de la Etapa 5 deben quedar guardados');
        $this->assertSame('Paso 1', $checklistGuardado[0]['titulo']);

        // 2) Fuera de turno: Producción intenta firmar antes que Ingeniería -- debe rechazarse.
        $resFueraDeTurno0 = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'produccion',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyFueraDeTurno0 = json_decode($resFueraDeTurno0['body'], true);
        $this->assertFalse($bodyFueraDeTurno0['ok'] ?? true, 'Producción no debería poder firmar antes que Ingeniería: ' . $resFueraDeTurno0['body']);
        $estadoTrasIntento0 = $db->query("SELECT estado FROM pruebas_fat WHERE id = {$this->fatId}")->fetchColumn();
        $this->assertSame('pendiente_ingenieria', $estadoTrasIntento0);

        // 3) Ingeniería firma -> pasa a pendiente_produccion.
        $resIng = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'ingenieria',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyIng = json_decode($resIng['body'], true);
        $this->assertTrue($bodyIng['ok'] ?? false, 'Ingeniería debería poder firmar primero: ' . $resIng['body']);
        $this->assertSame('pendiente_produccion', $bodyIng['nuevo_estado']);

        $rutaIng = $db->query("SELECT firma_ingenieria_ruta FROM pruebas_fat WHERE id = {$this->fatId}")->fetchColumn();
        if ($rutaIng) $this->firmasGuardadas[] = $rutaIng;

        // 4) Fuera de turno: Calidad intenta firmar antes que Producción -- debe rechazarse
        // y el estado no debe moverse ni un paso.
        $resFueraDeTurno = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'calidad',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyFueraDeTurno = json_decode($resFueraDeTurno['body'], true);
        $this->assertFalse($bodyFueraDeTurno['ok'] ?? true, 'Calidad no debería poder firmar antes que Producción: ' . $resFueraDeTurno['body']);
        $estadoTrasIntento = $db->query("SELECT estado FROM pruebas_fat WHERE id = {$this->fatId}")->fetchColumn();
        $this->assertSame('pendiente_produccion', $estadoTrasIntento, 'El estado no debe cambiar cuando se rechaza la firma fuera de turno');

        // 5) La Etapa 5 ya no se llena digitalmente (quitado 2026-08-28 -- ahora se llena a
        // mano en el documento físico que se sube en la tarjeta "Protocolo FAT"), así que
        // Producción firma directo sin pasar por actualizar_checklist_fat.

        // 6) Producción firma -> pasa a pendiente_proyectos (NO pendiente_calidad, ese orden
        // ya se reordenó en una sesión anterior).
        $resProd = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'produccion',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyProd = json_decode($resProd['body'], true);
        $this->assertTrue($bodyProd['ok'] ?? false, 'Producción debería poder firmar: ' . $resProd['body']);
        $this->assertSame('pendiente_proyectos', $bodyProd['nuevo_estado']);

        $rutaProd = $db->query("SELECT firma_produccion_ruta FROM pruebas_fat WHERE id = {$this->fatId}")->fetchColumn();
        if ($rutaProd) $this->firmasGuardadas[] = $rutaProd;

        // 7) Calidad sigue fuera de turno -- ahora le toca a Proyectos, no a Calidad todavía.
        $resFueraDeTurno2 = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'calidad',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyFueraDeTurno2 = json_decode($resFueraDeTurno2['body'], true);
        $this->assertFalse($bodyFueraDeTurno2['ok'] ?? true, 'Calidad no debería poder firmar antes que Proyectos: ' . $resFueraDeTurno2['body']);

        // 8) Proyectos firma -> pendiente_calidad.
        $resProy = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'proyectos',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyProy = json_decode($resProy['body'], true);
        $this->assertTrue($bodyProy['ok'] ?? false, 'Proyectos debería poder firmar: ' . $resProy['body']);
        $this->assertSame('pendiente_calidad', $bodyProy['nuevo_estado']);

        $rutaProy = $db->query("SELECT firma_proyectos_ruta FROM pruebas_fat WHERE id = {$this->fatId}")->fetchColumn();
        if ($rutaProy) $this->firmasGuardadas[] = $rutaProy;

        // 9) Calidad firma -> completado.
        $resCal = $this->postMultipart('api/fat_firma.php', [
            'fat_id'        => (string) $this->fatId,
            'rol'           => 'calidad',
            'accion'        => 'firmar',
            'firma_archivo' => $this->firmaFile(),
        ], $jar, ['X-CSRF-Token: ' . $csrf]);
        $bodyCal = json_decode($resCal['body'], true);
        $this->assertTrue($bodyCal['ok'] ?? false, 'Calidad debería poder firmar al final: ' . $resCal['body']);
        $this->assertSame('completado', $bodyCal['nuevo_estado']);

        $rutaCal = $db->query("SELECT firma_calidad_ruta FROM pruebas_fat WHERE id = {$this->fatId}")->fetchColumn();
        if ($rutaCal) $this->firmasGuardadas[] = $rutaCal;
    }
}
