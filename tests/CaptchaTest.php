<?php

/**
 * Cubre el CAPTCHA con imagen (reemplazo del "N + M" en texto plano) y el
 * rate-limit de login por IP, agregados en la sesión de 2026-07-31. Ver
 * AUDITORIA_SEGURIDAD.md e includes/config.php (registrarIntentoFallidoIp).
 */
final class CaptchaTest extends TestCase
{
    public function testCaptchaImageRendersAsPng(): void
    {
        $jar = $this->newCookieJar();
        $this->get('index.php', $jar); // asegura que la sesión tenga un captcha_code
        $res = $this->get('captcha_img.php', $jar);

        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('image/png', $res['headers']);
        $this->assertStringStartsWith("\x89PNG", $res['body']);
    }

    public function testWrongCaptchaCodeIsRejected(): void
    {
        $jar = $this->newCookieJar();
        $this->get('index.php', $jar);
        $real = $this->resolverCaptcha($jar);
        $incorrecto = ($real === 'AAAAA') ? 'BBBBB' : 'AAAAA';

        $res = $this->post('index.php', [
            'username' => 'cualquiera',
            'password' => 'cualquiera',
            'captcha_respuesta' => $incorrecto,
        ], $jar);

        $this->assertStringContainsString('código de la imagen es incorrecto', $res['body']);
    }

    public function testIpGetsRateLimitedAfterTooManyFailedAttempts(): void
    {
        // El bloqueo de IP también dispara una notificación real a admin (ver index.php)
        // -- se limpia al final para no dejar basura en notificaciones.
        $maxIdAntes = (int)$this->db()->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();

        $jar = $this->newCookieJar();
        // Cualquier fallo de validación (usuario/password vacíos, captcha vacío) ya
        // cuenta para el rate-limit por IP -- no hace falta resolver el captcha real
        // para ejercitar el contador, mismo camino de código que un intento real fallido.
        for ($i = 0; $i < 15; $i++) {
            $this->post('index.php', ['username' => '', 'password' => '', 'captcha_respuesta' => ''], $jar);
        }

        $res = $this->post('index.php', [
            'username' => 'qa_test_no_deberia_importar',
            'password' => 'qa_test_no_deberia_importar',
            'captcha_respuesta' => 'X',
        ], $jar);

        $this->assertSame(429, $res['code']);
        $this->assertStringContainsString('Demasiados intentos', $res['body']);

        $this->limpiarRateLimitIp(); // no dejar la IP bloqueada para el resto de la suite
        $this->db()->prepare("DELETE FROM notificaciones WHERE id > ? AND tipo = 'seguridad'")->execute([$maxIdAntes]);
    }
}
