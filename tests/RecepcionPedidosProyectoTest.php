<?php
require_once __DIR__ . '/TestCase.php';

/**
 * Bug real reportado por el usuario (2026-08-21): una OC generada desde una requisición
 * externa CON proyecto asignado (generar_ordenes_req -- usada por Producción/Calidad/etc.
 * vía "Generar Requisiciones") hereda bien proyecto_id, pero esa función solo actualiza
 * requisiciones.estado_compra, nunca proyectos.estado. La pantalla de Recepción de Pedidos
 * filtraba proyectos por estado IN ('orden_generada','recepcion_parcial') -- un estado que
 * SOLO pone la otra vía (generar_ordenes, BOM->cotización de Ingeniería/Compras). El
 * proyecto quedaba invisible ahí aunque su OC ya estuviera aprobada por Gerencia y Calidad.
 */
final class RecepcionPedidosProyectoTest extends TestCase
{
    private array $cleanupSql = [];

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach (array_reverse($this->cleanupSql) as $sql) {
            $db->exec($sql);
        }
        $this->qaDeleteUser('qa_test_recep_almacen');
        parent::tearDown();
    }

    public function testProyectoConOcAprobadaPorRequisicionExternaApareceEnRecepcion(): void
    {
        $db = $this->db();

        // Proyecto en un estado que NUNCA pasa por generar_ordenes (el mismo caso real:
        // "finalizado", liberado por Ingeniería hace tiempo, nunca tocado por la vía de
        // cotización directa de BOM).
        $db->exec("INSERT INTO proyectos (nombre, estado, activo) VALUES ('QA_TEST_RECEP_PROY', 'finalizado', 1)");
        $proyectoId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proyectos WHERE id = $proyectoId";

        // Requisición externa CON proyecto asignado (como la genera Producción/Calidad desde
        // el módulo compartido "Generar Requisiciones").
        $db->exec("INSERT INTO requisiciones (folio, usuario_id, area, solicitante, estado, proyecto_id, proyecto, fecha_creacion) VALUES ('QA-TEST-RECEP-REQ', 1, 'Calidad', 'QA', 'en_proceso', $proyectoId, 'QA_TEST_RECEP_PROY', NOW())");
        $requisicionId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM requisiciones WHERE id = $requisicionId";

        $db->exec("INSERT INTO proveedores (nombre, activo) VALUES ('QA_TEST_RECEP_PROV', 1)");
        $provId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM proveedores WHERE id = $provId";

        // OC tal como la deja generar_ordenes_req: proyecto_id heredado de la requisición,
        // ya aprobada por Gerencia y por Calidad (inspeccion_ok=1), sin recibir todavía.
        $db->exec("INSERT INTO ordenes_compra (folio, requisicion_id, proyecto_id, proveedor_id, usuario_id, estado, calidad_aprobacion, inspeccion_ok, total) VALUES ('QA-TEST-RECEP-OC', $requisicionId, $proyectoId, $provId, 1, 'aprobada', 'aprobado', 1, 100)");
        $ocId = (int)$db->lastInsertId();
        $this->cleanupSql[] = "DELETE FROM ordenes_compra WHERE id = $ocId";

        $almacenId = $this->qaCreateUser('qa_test_recep_almacen', 'Passw0rd!', 'multiple');
        $db->exec("UPDATE usuarios SET accesos = '[\"almacen\"]' WHERE id = $almacenId");
        $jar = $this->loginAs('qa_test_recep_almacen', 'Passw0rd!');
        $body = $this->get('almacen2.php?seccion=recepcion_pedidos', $jar)['body'];

        // Ojo: ni el nombre del proyecto ni el folio de la OC solos sirven como marcador --
        // ambos también aparecen en <select> genéricos de esta misma página que no tienen
        // relación con la cola real de recepción ($proyectos_pedido): un <select
        // name="orden_compra_id"> (modal de "Entrada" manual, lista TODAS las OC recientes)
        // y un <select name="proyecto_id"> (selector genérico de proyecto). Ambos hubieran
        // dado un falso positivo incluso con el bug presente. El marcador real es el folio
        // envuelto en el <strong style="color:#003d5c"> exacto que solo usa la tarjeta de
        // $proyectos_pedido (ver almacen2.php ~línea 4682).
        $this->assertStringContainsString(
            '<strong style="color:#003d5c">QA-TEST-RECEP-OC</strong>',
            $body,
            'la OC aprobada por Gerencia y Calidad debió aparecer en la tarjeta de Recepción de Pedidos del proyecto'
        );
    }
}
