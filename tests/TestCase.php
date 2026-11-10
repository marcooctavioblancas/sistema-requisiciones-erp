<?php

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base para pruebas de integración HTTP. Cada prueba que necesite un usuario
 * de prueba debe crearlo con qaCreateUser() y limpiarlo en tearDown() -- nunca
 * dejar datos de prueba en la BD real. Usar siempre el prefijo qa_test_ en
 * nombres de usuario/proyecto/etc. para que sea obvio qué es de prueba si algo
 * queda a medias.
 */
abstract class TestCase extends BaseTestCase
{
    private ?PDO $pdo = null;
    /** @var string[] cookie jars temporales creados durante la prueba, para limpiar */
    private array $cookieJars = [];

    /**
     * Todas las pruebas pegan a TEST_BASE_URL desde curl corriendo DENTRO del mismo
     * contenedor de la app -- REMOTE_ADDR siempre es 127.0.0.1. Sin este reset, correr
     * la suite completa varias veces seguidas (patrón normal de verificación en este
     * proyecto) terminaría bloqueando esa IP por el rate-limit de login (ver index.php).
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiarRateLimitIp();
    }

    protected function limpiarRateLimitIp(string $ip = '127.0.0.1'): void
    {
        try {
            $redis = new Redis();
            $redis->connect(TEST_REDIS_HOST, 6379, 1.0);
            $redis->del("login_ip_fails:$ip");
            $redis->del("login_ip_block:$ip");
        } catch (Throwable $e) {
        }
    }

    protected function db(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                'mysql:host=' . TEST_DB_HOST . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4',
                TEST_DB_USER,
                TEST_DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            // Una request real siempre pasa por includes/config.php, que fija PHP y MySQL a
            // America/Mexico_City -- esta conexión de pruebas, sin eso, se queda en el time_zone
            // SYSTEM del contenedor (UTC). Los servidores viven en UTC pero el negocio opera en
            // Mexico_City (UTC-6): durante las 6 horas en que UTC ya cruzó medianoche y
            // Mexico_City todavía no (00:00-06:00 UTC = 18:00-00:00 Mexico_City del día
            // anterior), cualquier prueba que compare CURDATE()/NOW() entre esta conexión y una
            // fila insertada por una request real (vía HTTP, con el time_zone correcto) fallaba
            // por un desfase de fecha de un día completo -- no por un bug real del código bajo
            // prueba. Mismo fix que ya se aplicaba a mano en RegistroContableTest.php, ahora
            // centralizado aquí para que aplique a cualquier prueba que use $this->db().
            date_default_timezone_set('America/Mexico_City');
            $this->pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return $this->pdo;
    }

    /** Crea (o reemplaza) un usuario de prueba con el password dado en texto plano. */
    protected function qaCreateUser(string $username, string $password, string $tipoUsuario = 'admin'): int
    {
        $db = $this->db();
        $db->prepare('DELETE FROM usuarios WHERE username = ?')->execute([$username]);
        $stmt = $db->prepare('INSERT INTO usuarios (username, password, nombre_completo, tipo_usuario, activo) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'QA Test User', $tipoUsuario]);
        return (int)$db->lastInsertId();
    }

    protected function qaDeleteUser(string $username): void
    {
        $this->db()->prepare('DELETE FROM usuarios WHERE username = ?')->execute([$username]);
    }

    /** Nuevo cookie jar temporal (archivo), se borra solo en tearDown(). */
    protected function newCookieJar(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_cookies_');
        $this->cookieJars[] = $path;
        return $path;
    }

    /**
     * GET/POST genérico. $cookieJar es un path de archivo (cookie jar de curl);
     * el mismo jar se usa como archivo Y como fuente en cada llamada para que
     * las cookies persistan entre requests de la misma "sesión de navegador".
     */
    protected function http(string $method, string $path, array $data = [], ?string $cookieJar = null, array $extraHeaders = []): array
    {
        $ch = curl_init(rtrim(TEST_BASE_URL, '/') . '/' . ltrim($path, '/'));
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($cookieJar) {
            $opts[CURLOPT_COOKIEJAR] = $cookieJar;
            $opts[CURLOPT_COOKIEFILE] = $cookieJar;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
        if ($extraHeaders) {
            $opts[CURLOPT_HTTPHEADER] = $extraHeaders;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return ['code' => $code, 'headers' => $headers, 'body' => $body];
    }

    protected function get(string $path, ?string $cookieJar = null): array
    {
        return $this->http('GET', $path, [], $cookieJar);
    }

    protected function post(string $path, array $data, ?string $cookieJar = null, array $extraHeaders = []): array
    {
        return $this->http('POST', $path, $data, $cookieJar, $extraHeaders);
    }

    /** POST con body JSON crudo -- para los endpoints api/*.php que leen file_get_contents('php://input')
     *  en vez de $_POST (guardar_item_bom.php, guardar_tipo_valvula_bom.php, etc.). El CSRF de estos
     *  endpoints solo puede ir por header X-CSRF-Token (el body no es form-encoded, no hay $_POST). */
    protected function postJson(string $path, array $data, ?string $cookieJar = null, array $extraHeaders = []): array
    {
        $ch = curl_init(rtrim(TEST_BASE_URL, '/') . '/' . ltrim($path, '/'));
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $extraHeaders),
        ];
        if ($cookieJar) {
            $opts[CURLOPT_COOKIEJAR] = $cookieJar;
            $opts[CURLOPT_COOKIEFILE] = $cookieJar;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }

    /** POST multipart/form-data -- para endpoints con upload de archivo (ej. api/fat_firma.php). Los
     *  valores del array pueden ser strings normales o CURLFile para el/los campo(s) de archivo. */
    protected function postMultipart(string $path, array $fields, ?string $cookieJar = null, array $extraHeaders = []): array
    {
        $ch = curl_init(rtrim(TEST_BASE_URL, '/') . '/' . ltrim($path, '/'));
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
        ];
        if ($extraHeaders) {
            $opts[CURLOPT_HTTPHEADER] = $extraHeaders;
        }
        if ($cookieJar) {
            $opts[CURLOPT_COOKIEJAR] = $cookieJar;
            $opts[CURLOPT_COOKIEFILE] = $cookieJar;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }

    /** Texto plano de un PDF (bytes crudos de la respuesta) vía pdftotext (poppler-utils, ya
     *  usado en este proyecto para verificar los .docx generados a mano -- ver memoria del
     *  proyecto). Necesario porque el texto dentro de un PDF nunca es ASCII buscable
     *  directamente: mPDF lo codifica como cadenas hex en el content stream aunque se
     *  desactive la compresión, así que buscar "145.50%" en los bytes crudos del PDF
     *  siempre falla sin importar la compresión. */
    protected function pdfText(string $pdfBytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qa_test_pdf_') . '.pdf';
        file_put_contents($tmp, $pdfBytes);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?? '';
        unlink($tmp);
        return $texto;
    }

    /** HTML (con estilos inline, incluido color) de un PDF vía pdftohtml -- para verificar
     *  colores aplicados (ej. rojo #b71c1c) que pdftotext no conserva. */
    protected function pdfHtml(string $pdfBytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qa_test_pdf_') . '.pdf';
        file_put_contents($tmp, $pdfBytes);
        $html = shell_exec('pdftohtml -s -stdout ' . escapeshellarg($tmp) . ' 2>/dev/null') ?? '';
        unlink($tmp);
        return $html;
    }

    /** Extrae el valor de una cookie del cookie jar (formato Netscape, tab-separado). */
    protected function cookieValue(string $jar, string $name): ?string
    {
        $contents = @file_get_contents($jar);
        if (!$contents) return null;
        foreach (explode("\n", $contents) as $line) {
            if (str_contains($line, $name)) {
                $parts = explode("\t", trim($line));
                return end($parts);
            }
        }
        return null;
    }

    /**
     * Lee el código real del CAPTCHA directo de Redis (mismo backend de sesiones que usa
     * la app vía session.save_handler=redis) -- ya no es texto en el HTML, es una imagen
     * (ver captcha_img.php), así que no hay nada que parsear del body de la página.
     */
    protected function resolverCaptcha(string $jar): string
    {
        $sid = $this->cookieValue($jar, 'PHPSESSID');
        if (!$sid) return '';
        try {
            $redis = new Redis();
            $redis->connect(TEST_REDIS_HOST, 6379, 1.0);
            $raw = $redis->get('PHPREDIS_SESSION:' . $sid);
            if (!$raw) return '';
            preg_match('/captcha_code\|s:\d+:"([^"]+)"/', $raw, $m);
            return $m[1] ?? '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Extrae el token CSRF que el script inyectado embebe como var t="...". */
    protected function extractCsrfToken(string $html): ?string
    {
        preg_match('/var t="([a-f0-9]+)"/', $html, $m);
        return $m[1] ?? null;
    }

    /** Login completo: GET login -> resolver captcha -> POST credenciales. Devuelve el cookie jar. */
    protected function loginAs(string $username, string $password): string
    {
        $jar = $this->newCookieJar();
        $this->get('index.php', $jar);
        $codigo = $this->resolverCaptcha($jar);
        $this->post('index.php', [
            'username' => $username,
            'password' => $password,
            'captcha_respuesta' => $codigo,
        ], $jar);
        return $jar;
    }

    protected function tearDown(): void
    {
        foreach ($this->cookieJars as $jar) {
            @unlink($jar);
        }
        $this->cookieJars = [];
        parent::tearDown();
    }
}
