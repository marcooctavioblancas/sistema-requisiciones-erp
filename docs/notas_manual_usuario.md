# Notas para el manual de usuario

Textos explicativos que vivían como banners "i" dentro de la app (quitados de la UI el
2026-09-13) porque explican reglas de negocio/flujo, no son estado dinámico. Base para
armar el manual de usuario.

## Almacén (almacen2.php)

- **Todas las requisiciones (vista global)**: Todas las requisiciones del sistema, sin
  importar desde qué módulo se generaron.
- **Modal de recepción de OC**: "Entrega incompleta" se detecta automáticamente si hay
  piezas faltantes.

## Ingeniería / BOM (bom.php, ingenieria.php)

- **Tabla de materiales de un BOM con varios equipos**: Las cantidades "Cant. Unit." son
  por equipo. "Total ×N" es la cantidad total del proyecto.
- **Sección FAT del BOM**: El Protocolo FAT firmado en papel lo aprueba Producción; una
  vez aprobado se ve en solo lectura en Proyectos y Calidad.
- **Modal "Desfijar BOM"**: Podrás corregir o agregar materiales. Al volver a guardar,
  solo se enviarán a Almacén los ítems nuevos o modificados — lo que ya estaba correcto
  no se duplicará.

## Compras (compras.php)

- **Panel de pedidos/OCs**: La recepción de los pedidos se confirma desde Almacén →
  Recepción de pedidos.
- **Sección de faltantes sin proyecto**: Requisiciones sin proyecto donde el stock
  actual no alcanza para surtir todo.

## Producción (produccion.php)

- **Aprobación de OTs**: Primero Producción, luego Calidad.
- **BOM visto desde Producción**: Solo lectura — este BOM lo arma y mantiene Ingeniería.
- **Pruebas FAT**: Las pruebas FAT se generan desde una OT completada.

## Trazabilidad / Hoja Viajera (includes/equipos_fabricados.php, includes/hoja_viajera.php)

- **Número de serie de equipo fabricado**: Serie final del equipo y sus componentes.
- **Hoja Viajera (listado)**: Una hoja viajera por pieza/equipo individual — un proyecto
  puede tener varias.
- **Hoja Viajera (generar nueva)**: Cliente y Producto se toman automáticamente de la
  Orden de Producción del proyecto.

## Proyectos (proyecto.php)

- **Modal "Rechazar OC" (Compras vía Proyectos)**: Al rechazar, se notificará a Compras
  y se generará automáticamente una nueva requisición con los materiales pendientes
  para re-cotizar con otro proveedor.
  ⚠️ Este texto explica una CONSECUENCIA justo antes de una acción -- a diferencia de
  los demás (que son solo contexto de fondo), este avisa algo importante en el momento
  de decidir. Se quitó igual porque así se pidió, pero avísame si prefieres dejarlo ahí
  y solo agregarlo también al manual.
