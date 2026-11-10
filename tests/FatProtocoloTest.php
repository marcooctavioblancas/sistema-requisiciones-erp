<?php

/**
 * Protocolo formal de Pruebas FAT (portada + 6 etapas, exportable a PDF y Word), distinto del
 * checklist interno pruebas_fat que cubre tests/FatFirmaTest.php.
 */
class FatProtocoloTest extends TestCase
{
    private int $proyectoId = 0;
    private int $opId = 0;
    private int $fatId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->db();

        $db->prepare('DELETE FROM usuarios WHERE username = ?')->execute(['qa_test_fatproto']);
        $stmt = $db->prepare("INSERT INTO usuarios (username, password, nombre_completo, tipo_usuario, accesos, activo) VALUES (?, ?, 'QA Test FAT Protocolo', 'multiple', '[\"ingenieria\",\"manufactura\",\"proyectos\",\"calidad\"]', 1)");
        $stmt->execute(['qa_test_fatproto', password_hash('Test1234!', PASSWORD_DEFAULT)]);

        $db->prepare("INSERT INTO proyectos (nombre, descripcion, tipo, fecha_creacion) VALUES ('qa_test_fatproto_T490', 'Réplica QA de T-490', 'ingenieria', NOW())")->execute();
        $this->proyectoId = (int)$db->lastInsertId();

        $descripcionGeneral = 'ACTUADOR GAS/ACEITE ABE, MARCA EMPRESA EJEMPLO, MODELO G/A-RO-382K-DA-AH-BH';
        $descItem = 'ACTUADOR GAS SOBRE ACEITE – GAS/OIL (DE BAJAS EMISIONES (ABE) + FUNCIÓN VOP-2), MARCA EMPRESA EJEMPLO MODELO G/A-RO-382K-DA-AH-BH, de DOBLE ACCIÓN, tipo YUGO ESCOCÉS, posición segura a falla de suministro CIERRA operado a una presión de suministro de 32 BAR, para operar válvula de bola 24" - 600#. BOMBA HIDRÁULICA PARA OPERACIÓN MANUAL, para apertura y cierre, SUMINISTRO DE SISTEMA SOLAR A 24 VCD., REFACCIONES PARA 2 AÑOS – ADICIONAL AL SUMINISTRO';
        $items = [['item' => 1, 'tag' => 'S/T', 'qty' => '1', 'desc' => $descItem]];

        $stmt = $db->prepare("INSERT INTO ordenes_produccion (proyecto_id, numero_proyecto, fecha, cliente, orden_compra, descripcion, fecha_compromiso, items, checklist, estado, fecha_creacion)
                               VALUES (?, 'qa_test_fatproto_T490', '2025-10-15', 'GDI S.A. de C.V.', 'OC-C0236952-1', ?, '16 semanas', ?, '{}', 'activa', NOW())");
        $stmt->execute([$this->proyectoId, $descripcionGeneral, json_encode($items, JSON_UNESCAPED_UNICODE)]);
        $this->opId = (int)$db->lastInsertId();

        $plantilla = $db->query("SELECT checklist_json FROM fat_plantillas WHERE nombre = 'Actuador doble acción Gas/Aceite (T490-FAT)'")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO pruebas_fat (proyecto_id, folio, titulo, descripcion, checklist, estado, creado_por, fecha_creacion)
                               VALUES (?, 'FAT-QATEST-PROTO', 'Protocolo FAT qa_test', 'Réplica de prueba', ?, 'pendiente_ingenieria', NULL, NOW())");
        $stmt->execute([$this->proyectoId, $plantilla ?: '[]']);
        $this->fatId = (int)$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        $db->prepare('DELETE FROM fat_protocolos WHERE orden_produccion_id = ?')->execute([$this->opId]);
        $db->prepare('DELETE FROM pruebas_fat WHERE id = ?')->execute([$this->fatId]);
        $db->prepare('DELETE FROM ordenes_produccion WHERE id = ?')->execute([$this->opId]);
        $db->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        $db->prepare('DELETE FROM usuarios WHERE username = ?')->execute(['qa_test_fatproto']);
        parent::tearDown();
    }

    public function testMiniIaExtraeSinInventarYGeneraPdfYDocx(): void
    {
        $jar = $this->loginAs('qa_test_fatproto', 'Test1234!');
        $token = $this->extractCsrfToken($this->get('proyecto.php', $jar)['body']);
        $this->assertNotNull($token, 'no se pudo extraer el token CSRF');

        // Mini-IA: debe extraer los datos reales presentes en la descripción...
        $resp = $this->post('api/analizar_fat_datos.php', ['orden_produccion_id' => $this->opId, 'csrf_token' => $token], $jar);
        $body = json_decode($resp['body'], true);
        $this->assertTrue($body['success'] ?? false, 'mini-IA debió responder success=true: ' . $resp['body']);
        $dg = $body['datos_generales'];
        $this->assertSame('EMPRESA EJEMPLO', $dg['actuador']['marca']);
        $this->assertSame('G/A-RO-382K-DA-AH-BH', $dg['actuador']['modelo']);
        $this->assertSame('32 BAR', $dg['actuador']['presion_operacion'], 'no debe convertir unidades (bar->psi)');
        $this->assertSame('bola', $dg['valvula']['tipo']);
        $this->assertSame('600#', $dg['valvula']['clase']);
        // ...y dejar en null lo que NO aparece explícito (marca/modelo/no_serie de la válvula).
        $this->assertNull($dg['valvula']['marca'], 'no debe inventar la marca de la válvula');
        $this->assertNull($dg['valvula']['modelo'], 'no debe inventar el modelo de la válvula');

        // Guardar, vinculando la pruebas_fat sembrada
        $resp2 = $this->post('api/fat_protocolo_datos.php', [
            'orden_produccion_id' => $this->opId,
            'pruebas_fat_id' => $this->fatId,
            'datos_generales' => json_encode($dg),
            'csrf_token' => $token,
        ], $jar);
        $body2 = json_decode($resp2['body'], true);
        $this->assertTrue($body2['success'] ?? false, 'guardar debió responder success=true: ' . $resp2['body']);

        // PDF: generado con mPDF (ver api/vista_pdf_fat_protocolo.php) -- ya no es HTML crudo, es
        // un PDF real con el texto embebido vía una fuente Unicode (necesaria para ☐ y demás
        // glifos fuera de Latin-1), así que el contenido queda codificado por glifo dentro del
        // stream comprimido, NO como texto plano buscable con assertStringContainsString como
        // antes. Se valida que sea un PDF real y no un error/página en blanco.
        $respPdf = $this->get('api/vista_pdf_fat_protocolo.php?orden_produccion_id=' . $this->opId, $jar);
        $this->assertEquals(200, $respPdf['code']);
        $this->assertStringContainsString('Content-Type: application/pdf', $respPdf['headers']);
        $this->assertStringStartsWith('%PDF-', $respPdf['body'], 'la respuesta debe ser un PDF válido');
        $this->assertGreaterThan(5000, strlen($respPdf['body']), 'el PDF parece vacío o truncado');

        // DOCX: zip válido, XML bien formado, pulgadas sin corromper
        $ch = curl_init(rtrim(TEST_BASE_URL, '/') . '/api/exportar_docx_fat_protocolo.php?orden_produccion_id=' . $this->opId);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_HEADER => true]);
        $raw = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertEquals(200, $code);
        $docxBytes = substr($raw, $headerSize);

        $tmpFile = tempnam(sys_get_temp_dir(), 'fatdocx_') . '.docx';
        file_put_contents($tmpFile, $docxBytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmpFile) === true, 'el .docx debe ser un zip válido');
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmpFile);

        $this->assertNotFalse($documentXml, 'debe existir word/document.xml dentro del docx');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($documentXml), 'word/document.xml debe ser XML válido');
        $this->assertStringContainsString('24&quot;', $documentXml, 'las pulgadas van escapadas como &quot; (comilla real al abrir en Word)');
        $this->assertStringNotContainsString('24quot', $documentXml, 'no debe aparecer el texto "quot" sin escapar');
        $this->assertStringContainsString('G/A-RO-382K-DA-AH-BH', $dom->textContent);
    }
}
