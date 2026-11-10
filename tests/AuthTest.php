<?php

/**
 * Cubre los fixes de la auditoría de seguridad de 2026-07-30/31 relacionados
 * con login: bloqueo de cuenta, migración de hashes débiles, y que las páginas
 * protegidas no sean accesibles sin sesión. Ver AUDITORIA_SEGURIDAD.md.
 */
final class AuthTest extends TestCase
{
    private const USER = 'qa_test_phpunit_auth';
    private const PASS = 'ClavePruebaPHPUnit123';

    protected function tearDown(): void
    {
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testLoginPageLoadsWithoutSession(): void
    {
        $res = $this->get('index.php');
        $this->assertSame(200, $res['code']);
        $this->assertStringContainsString('Iniciar Sesión', $res['body']);
    }

    public function testProtectedPagesRedirectWithoutSession(): void
    {
        foreach (['produccion.php', 'compras.php', 'ingenieria.php', 'calidad.php', 'almacen2.php', 'proyecto.php'] as $pagina) {
            $res = $this->get($pagina);
            $this->assertSame(302, $res['code'], "$pagina debería redirigir (302) sin sesión");
        }
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        $jar = $this->newCookieJar();
        $this->get('index.php', $jar);
        $respuesta = $this->resolverCaptcha($jar);

        $res = $this->post('index.php', [
            'username' => self::USER,
            'password' => 'contraseña-incorrecta',
            'captcha_respuesta' => $respuesta,
        ], $jar);

        $this->assertStringContainsString('Usuario o contraseña incorrectos', $res['body']);
    }

    public function testLoginWithCorrectPasswordSucceedsAndRegeneratesSession(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        $jar = $this->newCookieJar();

        $this->get('index.php', $jar);
        $sessionAntes = $this->cookieValue($jar, 'PHPSESSID');

        $respuesta = $this->resolverCaptcha($jar);
        $res = $this->post('index.php', [
            'username' => self::USER,
            'password' => self::PASS,
            'captcha_respuesta' => $respuesta,
        ], $jar);

        $this->assertSame(302, $res['code']);
        $sessionDespues = $this->cookieValue($jar, 'PHPSESSID');
        $this->assertNotSame($sessionAntes, $sessionDespues, 'El ID de sesión debe regenerarse tras un login exitoso (protección contra session fixation)');
    }

    public function testAccountLocksAfterFiveFailedAttempts(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        // El bloqueo de cuenta ahora también dispara una notificación real a admin
        // (ver index.php) -- se limpia al final para no dejar basura en notificaciones.
        $maxIdAntes = (int)$this->db()->query("SELECT COALESCE(MAX(id),0) FROM notificaciones")->fetchColumn();

        for ($i = 0; $i < 5; $i++) {
            $jar = $this->newCookieJar();
            $this->get('index.php', $jar);
            $respuesta = $this->resolverCaptcha($jar);
            $this->post('index.php', [
                'username' => self::USER,
                'password' => 'contraseña-incorrecta',
                'captcha_respuesta' => $respuesta,
            ], $jar);
        }

        // Sexto intento, esta vez con la contraseña CORRECTA -- debe seguir rechazado por el bloqueo.
        $jar = $this->newCookieJar();
        $this->get('index.php', $jar);
        $respuesta = $this->resolverCaptcha($jar);
        $res = $this->post('index.php', [
            'username' => self::USER,
            'password' => self::PASS,
            'captcha_respuesta' => $respuesta,
        ], $jar);

        $this->assertStringContainsString('bloqueada temporalmente', $res['body']);

        $this->db()->prepare("DELETE FROM notificaciones WHERE id > ? AND tipo = 'seguridad'")->execute([$maxIdAntes]);
    }

    public function testWeakHashIsUpgradedToBcryptOnLogin(): void
    {
        $db = $this->db();
        $db->prepare('DELETE FROM usuarios WHERE username = ?')->execute([self::USER]);
        $stmt = $db->prepare('INSERT INTO usuarios (username, password, nombre_completo, tipo_usuario, activo) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([self::USER, md5(self::PASS), 'QA Test User', 'admin']);

        $this->loginAs(self::USER, self::PASS);

        $hash = $db->prepare('SELECT password FROM usuarios WHERE username = ?');
        $hash->execute([self::USER]);
        $stored = $hash->fetchColumn();

        $this->assertStringStartsWith('$2y$', $stored, 'El hash MD5 debe migrarse a bcrypt en el primer login exitoso');
    }
}
