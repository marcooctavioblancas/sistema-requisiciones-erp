<?php
/**
 * Bootstrap de pruebas -- estas son pruebas de INTEGRACIÓN vía HTTP contra la
 * app real corriendo (no unit tests aislados). Se hizo así a propósito: casi
 * todo el código vive en scripts monolíticos que arrancan sesión y hacen I/O
 * en cuanto se hace require de config.php, así que aislar funciones puras
 * para probarlas por separado requeriría refactorizar la app entera primero.
 * Probar por HTTP contra el sistema real, con datos de prueba que cada test
 * crea y limpia, da cobertura real sin ese refactor.
 *
 * Requiere que el stack de Docker esté corriendo (docker compose up).
 * Correr con: docker compose exec app vendor/bin/phpunit
 */

define('TEST_BASE_URL', getenv('TEST_BASE_URL') ?: 'http://localhost:8080');

define('TEST_DB_HOST', getenv('DB_HOST') ?: 'db');
define('TEST_DB_USER', getenv('DB_USER') ?: 'root');
define('TEST_DB_PASS', getenv('DB_PASS') ?: 'root');
define('TEST_DB_NAME', getenv('DB_NAME') ?: 'sistema_requisiciones');

// El CAPTCHA ahora es una imagen (ver captcha_img.php) -- no se puede leer del HTML como
// antes. Las pruebas leen el código real directo de Redis (mismo backend de sesiones que
// usa la app vía session.save_handler=redis), igual que un usuario real vería la imagen.
define('TEST_REDIS_HOST', getenv('REDIS_HOST') ?: 'redis');

require_once __DIR__ . '/TestCase.php';
