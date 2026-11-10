<?php

/**
 * Cubre la cimentacion de niveles jerarquicos por departamento
 * (trabajador/jefe), independiente de usuarios.accesos. Ver
 * usuario_niveles / nivelUsuario() / esJefeDe() en includes/config.php y el
 * formulario de admin.php (guardar_usuario).
 */
final class UsuarioNivelesTest extends TestCase
{
    private const ADMIN_USER = 'qa_test_niveles_admin';
    private const ADMIN_PASS = 'ClavePruebaNiveles123';

    private int $creadoId = 0;

    protected function tearDown(): void
    {
        $db = $this->db();
        if ($this->creadoId) {
            $db->prepare('DELETE FROM usuario_niveles WHERE usuario_id = ?')->execute([$this->creadoId]);
            $db->prepare("DELETE FROM auditoria WHERE tabla = 'usuarios' AND registro_id = ?")->execute([$this->creadoId]);
            $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$this->creadoId]);
        }
        $this->qaDeleteUser(self::ADMIN_USER);
        parent::tearDown();
    }

    public function testGuardarUsuarioConDistintoNivelPorModuloYNivelUsuarioLoLee(): void
    {
        $db = $this->db();
        $this->qaCreateUser(self::ADMIN_USER, self::ADMIN_PASS, 'admin');
        $jar = $this->loginAs(self::ADMIN_USER, self::ADMIN_PASS);

        $page = $this->get('admin.php', $jar);
        $csrf = $this->extractCsrfToken($page['body']);

        $res = $this->post('admin.php', [
            'action'          => 'guardar_usuario',
            'nombre_completo' => 'QA Test Niveles',
            'username'        => 'qa_test_niveles_multi',
            'password'        => 'ClavePruebaMulti123',
            'email'           => '',
            'telefono'        => '',
            'tipo_usuario'    => 'multiple',
            'accesos'         => ['compras', 'almacen'],
            'nivel_compras'   => 'jefe',
            'nivel_almacen'   => 'trabajador',
            'csrf_token'      => $csrf,
        ], $jar);

        $this->assertSame(200, $res['code']);

        $nuevo = $db->prepare('SELECT id FROM usuarios WHERE username = ?');
        $nuevo->execute(['qa_test_niveles_multi']);
        $this->creadoId = (int) $nuevo->fetchColumn();
        $this->assertGreaterThan(0, $this->creadoId, 'El usuario debió crearse: ' . $res['body']);

        $niveles = $db->prepare('SELECT modulo, nivel FROM usuario_niveles WHERE usuario_id = ? ORDER BY modulo');
        $niveles->execute([$this->creadoId]);
        $filas = $niveles->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(['almacen' => 'trabajador', 'compras' => 'jefe'], $filas);
    }
}
