# Sistema de Requisiciones — ERP para manufactura por proyecto

Aplicación web (PHP + MariaDB) que cubre el ciclo completo de una empresa que fabrica equipos por proyecto:
desde que alguien pide un material hasta que el equipo pasa sus pruebas y se entrega.
Es un proyecto individual: ~68 mil líneas de PHP sin framework, 64 tablas y 24 archivos de pruebas de integración.

> **Copia para portafolio.** Se eliminaron el nombre y los logos de la empresa, los datos reales, las
> credenciales y los formatos oficiales (.docx). Queda el código y un esquema de base de datos vacío con un usuario demo.

## Qué incluye

| Módulo | Qué hace | Archivos principales |
|---|---|---|
| Requisiciones | Solicitud de materiales por proyecto, con historial de eventos | `ver_requisicion.php`, `includes/generar_requisicion.php` |
| Almacén | Inventario, recepción de pedidos, series/lotes, movimientos e importación de inventario | `almacen2.php`, `importar_inventario.php` |
| Compras | Órdenes de compra con flujo de aprobación (PDF), cotizaciones comparativas a proveedores por correo, análisis de cotizaciones con IA | `compras.php`, `orden_compra.php`, `includes/solicitar_cotizacion.php`, `includes/analizar_cotizacion.php` |
| Proveedores | Catálogo, incidencias de entrega y bloqueo de proveedores | `compras.php`, `calidad.php` |
| Ingeniería | BOM (lista de materiales) con revisiones y plantillas, importación, lectura de planos con OCR/IA | `ingenieria.php`, `bom.php`, `api/*bom*.php` |
| Producción | Órdenes de producción y de trabajo, procesos por máquina, hoja viajera, asistencia de operadores, equipos fabricados | `produccion.php`, `proceso.php`, `includes/hoja_viajera.php`, `includes/equipos_fabricados.php` |
| Calidad | Inspecciones y protocolos de pruebas FAT, exportables a Word y PDF | `calidad.php`, `fat_protocolo.php`, `api/exportar_docx_*.php` |
| Proyectos y gerencia | Seguimiento de proyectos, calendario, indicadores trimestrales (Word/Excel/PDF), vista gerencial | `proyecto.php`, `calendario.php`, `gerencia.php`, `includes/indicadores.php` |
| Ventas | Cotizaciones a clientes | `ventas.php` |
| Auditoría SGI | Listas de verificación por área generadas desde plantillas | `includes/auditoria_sgi.php` |
| Administración | Usuarios, accesos por módulo, auditoría de acciones, chat interno, notificaciones | `admin.php`, `Menu.php`, `includes/ajax_chat.php` |

## Tecnología

- **Backend:** PHP 8.2 sin framework, PDO con consultas preparadas, MariaDB 10.4, Apache.
- **Seguridad:** contraseñas con `password_hash` (bcrypt), CSRF, captcha, bloqueo de cuenta por intentos fallidos y límite de intentos por IP, control de acceso por módulo, bitácora de auditoría.
- **Documentos:** mPDF, un generador propio de `.docx` (`includes/docx_writer.php`), html2pdf.js.
- **Otros:** PHPMailer (correo), PyMuPDF (extracción de texto de PDF), pdf.js y Tesseract.js (OCR en el navegador), Chart.js, FullCalendar, Redis (sesiones).
- **Infraestructura:** Docker Compose (app, base de datos, Redis, respaldos automáticos y phpMyAdmin).
- **Pruebas:** 24 pruebas de integración con PHPUnit que ejercen la app por HTTP (`tests/`).

## Cómo correrlo

Necesitas Docker (Docker Desktop en Windows/Mac).

```bash
cp .env.example .env        # en Windows (cmd): copy .env.example .env
docker compose up --build
```

- App: http://localhost:8080 — usuario `admin`, contraseña `admin123` (solo para pruebas locales; cámbiala).
- phpMyAdmin: http://localhost:8081 (solo accesible desde tu máquina).
- La primera vez, la base de datos se crea sola con `database/01_schema.sql` y `database/02_seed.sql`.
- Pruebas: con la app corriendo, `docker compose exec app vendor/bin/phpunit`.

Las credenciales `root/root` de `docker-compose.yml` son solo para desarrollo local; cámbialas antes de exponer algo a una red.

### Funciones opcionales (en `.env`)

- `GROQ_API_KEY`: análisis de cotizaciones y de documentos con IA ([Groq](https://console.groq.com)).
- `SMTP_*`: envío de solicitudes de cotización por correo.

Sin estas variables el resto del sistema funciona; solo esas dos funciones quedan inactivas.

## Qué no está incluido

- **Logos:** el sistema busca `uploads/logo/logo.png`, `logo_horizontal.png`, `isotipo.png` y `logo_fat.jpg`; si no existen muestra texto en su lugar. Pon los tuyos en esa carpeta.
- **Plantillas de auditoría SGI:** en `plantillas/auditoria_sgi/` van las listas de verificación en `.docx` (los nombres esperados están en `includes/auditoria_sgi.php`).
- **Fuente Arial:** si copias `arial.ttf`/`arialbd.ttf`/`arialbi.ttf`/`ariali.ttf` a `fonts/arial/`, el PDF del protocolo FAT usa Arial; si no, usa una fuente libre de mPDF.
- **Datos:** el esquema viene vacío; no hay proveedores, materiales ni proyectos de ejemplo.

## Estructura

```
api/          endpoints JSON y exportaciones (PDF, Word, Excel)
includes/     configuración, lógica compartida, PHPMailer, generador .docx
ajax/         endpoints del repositorio de documentos de referencia
css/ js/      estilos y tema
database/     esquema vacío y datos mínimos (se cargan al crear el contenedor)
tests/        pruebas de integración (PHPUnit)
scripts/      respaldo automático de la base de datos y extracción de texto de PDF
docs/         notas para el manual de usuario
*.php         una página por módulo (almacen2, compras, ingenieria, produccion, ...)
```

## Notas de diseño

Cada módulo vive en un solo archivo PHP grande (lógica, consultas y vista juntas). Funcionó para avanzar rápido
con un solo desarrollador; lo siguiente sería separar la lógica de acceso a datos y las vistas por módulo.
Por eso las pruebas son de integración por HTTP y no unitarias.

## Licencia

Todos los derechos reservados. Se publica como muestra de portafolio.
