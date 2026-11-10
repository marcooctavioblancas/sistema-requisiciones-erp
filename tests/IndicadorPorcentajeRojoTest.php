<?php

/**
 * Cubre el highlight en rojo (#b71c1c) cuando un porcentaje de indicador supera 100%
 * (2026-08-13) -- ya estaba en pantalla (includes/indicadores.php) desde antes; lo
 * nuevo es que también se aplica en las exportaciones Excel y PDF
 * (api/exportar_indicador_excel.php, api/vista_pdf_indicador.php), tanto por fila
 * como en el % obtenido/promedio general.
 */
final class IndicadorPorcentajeRojoTest extends TestCase
{
    private const USER = 'qa_test_indrojo';
    private const PASS = 'ClavePruebaIndRojo123';

    /** @var int[] */
    private array $reporteIds = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->reporteIds as $id) {
            $db->prepare('DELETE FROM indicadores_entradas WHERE reporte_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM indicadores_reportes WHERE id = ?')->execute([$id]);
        }
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    /** area='compras' (INDICADORES_CONFIG) + trimestre/año 1900 -- combinación que nunca
     *  puede chocar con un reporte real (uq_area_trim_anio) ni confundirse con uno. */
    private function crearReporte(int $trimestre, ?float $porcentajeObtenido, float $porcentajeFila): int
    {
        $db = $this->db();
        $db->prepare("INSERT INTO indicadores_reportes
                (area, trimestre, anio, fecha_reporte, representante_nombre, representante_cargo,
                 porcentaje_requerido, porcentaje_obtenido, cumple, estado, creado_por, fecha_creacion)
             VALUES ('compras', ?, 1900, CURDATE(), 'QA Test', 'QA', 90.00, ?, 1, 'finalizado', 1, NOW())")
           ->execute([$trimestre, $porcentajeObtenido]);
        $reporteId = (int) $db->lastInsertId();
        $this->reporteIds[] = $reporteId;

        $db->prepare("INSERT INTO indicadores_entradas (reporte_id, etiqueta, orden, porcentaje) VALUES (?, 'QA_TEST entrada', 1, ?)")
           ->execute([$reporteId, $porcentajeFila]);

        return $reporteId;
    }

    public function testPorcentajeMayorA100SePintaDeRojoEnExcelYPdf(): void
    {
        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $jar = $this->loginAs(self::USER, self::PASS);

        $reporteId = $this->crearReporte(1, 145.50, 120.00);

        $excel = $this->get("api/exportar_indicador_excel.php?reporte_id=$reporteId", $jar);
        $this->assertSame(200, $excel['code']);
        $this->assertStringContainsString('145.50%', $excel['body']);
        $this->assertStringContainsString('120.00%', $excel['body']);
        $this->assertSame(2, substr_count($excel['body'], '#b71c1c'), 'El Excel debe pintar en rojo tanto la fila (120%) como el promedio (145.50%)');

        $pdf = $this->get("api/vista_pdf_indicador.php?reporte_id=$reporteId", $jar);
        $this->assertSame(200, $pdf['code']);
        // El texto de un PDF nunca es ASCII buscable en los bytes crudos (mPDF lo codifica
        // como cadenas hex en el content stream) -- se extrae con pdftotext/pdftohtml
        // (poppler-utils), mismo criterio que la verificación de los .docx del proyecto.
        $pdfTexto = $this->pdfText($pdf['body']);
        $this->assertStringContainsString('145.50%', $pdfTexto);
        $this->assertStringContainsString('120.00%', $pdfTexto);
        $pdfHtml = $this->pdfHtml($pdf['body']);
        $this->assertSame(2, substr_count($pdfHtml, 'color:#b71c1c'), 'El PDF debe pintar en rojo tanto la fila (120%) como el promedio (145.50%)');
    }

    public function testPorcentajeMenorOIgualA100NoSePintaDeRojo(): void
    {
        $this->qaCreateUser(self::USER, self::PASS, 'admin');
        $jar = $this->loginAs(self::USER, self::PASS);

        $reporteId = $this->crearReporte(2, 85.00, 90.00);

        $excel = $this->get("api/exportar_indicador_excel.php?reporte_id=$reporteId", $jar);
        $this->assertStringContainsString('85.00%', $excel['body']);
        $this->assertStringNotContainsString('#b71c1c', $excel['body']);

        $pdf = $this->get("api/vista_pdf_indicador.php?reporte_id=$reporteId", $jar);
        $this->assertStringContainsString('85.00%', $this->pdfText($pdf['body']));
        $this->assertStringNotContainsString('color:#b71c1c', $this->pdfHtml($pdf['body']));
    }
}
