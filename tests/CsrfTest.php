<?php

/**
 * Cubre la protección CSRF agregada en 2026-07-31 (ver AUDITORIA_SEGURIDAD.md,
 * punto 6 del orden de ataque). Token vía $_POST o vía header X-CSRF-Token,
 * ambos caminos que usa el sistema real (formularios vs fetch()).
 */
final class CsrfTest extends TestCase
{
    private const USER = 'qa_test_phpunit_csrf';
    private const PASS = 'ClavePruebaPHPUnit123';

    protected function tearDown(): void
    {
        $this->qaDeleteUser(self::USER);
        parent::tearDown();
    }

    public function testPostWithoutTokenIsRejected(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        $jar = $this->loginAs(self::USER, self::PASS);

        $res = $this->post('calidad.php', ['accion' => 'eliminar_inspeccion', 'inspeccion_id' => '999999'], $jar);

        $this->assertSame(403, $res['code']);
        $this->assertStringContainsString('Token de seguridad', $res['body']);
    }

    public function testPostWithValidTokenInBodyIsAccepted(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        $jar = $this->loginAs(self::USER, self::PASS);

        $page = $this->get('calidad.php', $jar);
        $token = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($token, 'La página debe traer el token CSRF inyectado');

        $res = $this->post('calidad.php', [
            'accion' => 'eliminar_inspeccion',
            'inspeccion_id' => '999999',
            'csrf_token' => $token,
        ], $jar);

        // No debe ser el rechazo por CSRF -- lo que pase después (nada que borrar) es otro asunto.
        $this->assertNotSame(403, $res['code']);
        $this->assertStringNotContainsString('Token de seguridad', $res['body']);
    }

    public function testPostWithValidTokenInHeaderIsAccepted(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        $jar = $this->loginAs(self::USER, self::PASS);

        $page = $this->get('compras.php', $jar);
        $token = $this->extractCsrfToken($page['body']);
        $this->assertNotNull($token);

        $res = $this->post('api/rechazar_oc.php', ['orden_id' => '999999', 'motivo' => 'prueba'], $jar, [
            'X-CSRF-Token: ' . $token,
        ]);

        $this->assertNotSame(403, $res['code']);
    }

    /**
     * Cubre el fix de 2026-08-04: un <form> normal (no fetch/AJAX -- sin
     * header X-CSRF-Token) que manda un token PRESENTE pero que ya no
     * coincide con el de la sesión (caso real: la sesión expiró mientras se
     * llenaba un formulario largo) debe mandar a loguearse de nuevo con un
     * mensaje claro, no soltar el JSON crudo del 403 en pantalla. Un token
     * AUSENTE (testPostWithoutTokenIsRejected, arriba) sigue siendo 403 duro
     * -- esa es la firma de un POST forjado, no de una sesión vencida.
     */
    public function testFormPostWithStaleTokenRedirectsToLoginConMensaje(): void
    {
        $this->qaCreateUser(self::USER, self::PASS);
        $jar = $this->loginAs(self::USER, self::PASS);

        $res = $this->post('calidad.php', [
            'accion' => 'eliminar_inspeccion',
            'inspeccion_id' => '999999',
            'csrf_token' => 'token-viejo-que-no-coincide',
        ], $jar);

        $this->assertSame(302, $res['code']);
        $this->assertStringContainsString('Location: index.php?sesion_expirada=1', $res['headers']);
    }
}
