<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Flash;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Cliente HTTP en proceso.
 *
 * Ejecuta peticiones reales contra el Kernel (enrutado + middleware +
 * controladores + base de datos), de modo que las pruebas ejercitan
 * exactamente el mismo camino que el navegador. No hay atajos que
 * salten la capa de autorizacion.
 */
final class TestClient
{
    /** @var array<string,string> */
    private array $cookies = [];
    private string $ip;
    private string $userAgent;
    public ?Response $lastResponse = null;
    public string $lastBody = '';

    public function __construct(string $ip = '203.0.113.10', string $userAgent = 'PruebasSCGCA/1.0 (Chrome/120 Windows NT 10)')
    {
        $this->ip        = $ip;
        $this->userAgent = $userAgent;
    }

    public function reset(): void
    {
        $this->cookies = [];
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /** Token CSRF vigente derivado de la sesion real almacenada. */
    public function csrfToken(): string
    {
        $token = $this->cookies['scgca_session'] ?? null;
        if ($token !== null) {
            $row = \App\Core\Database::instance()->selectOne(
                'SELECT csrf_token FROM sessions WHERE id = ?',
                [hash('sha256', $token)]
            );
            if ($row !== null) {
                return (string) $row['csrf_token'];
            }
        }
        // Formularios publicos: patron double submit cookie.
        $guest = $this->cookies['scgca_csrf'] ?? null;
        if ($guest === null) {
            $guest = bin2hex(random_bytes(32));
            $this->cookies['scgca_csrf'] = $guest;
        }
        return $guest;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{status:int,body:string,headers:array<string,string>,json:?array}
     */
    public function request(string $method, string $path, array $data = [], bool $json = false, bool $withCsrf = true): array
    {
        $method = strtoupper($method);
        $query  = [];
        $body   = [];

        $parts = explode('?', $path, 2);
        $path  = $parts[0];
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }

        if ($method === 'GET') {
            $query = array_merge($query, $data);
        } else {
            $body = $data;
            if ($withCsrf && !$json) {
                $body['_csrf'] = $this->csrfToken();
            }
        }

        $basePath = '/credencial/public';
        $server = [
            'REQUEST_METHOD' => in_array($method, ['PUT', 'PATCH', 'DELETE'], true) ? 'POST' : $method,
            'REQUEST_URI'    => $basePath . $path . ($query !== [] ? '?' . http_build_query($query) : ''),
            'REMOTE_ADDR'    => $this->ip,
            'HTTP_USER_AGENT'=> $this->userAgent,
            'HTTP_HOST'      => 'localhost',
            'HTTP_ORIGIN'    => 'http://localhost',
            'HTTPS'          => 'off',
        ];
        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            $body['_method'] = $method;
        }

        $raw = '';
        if ($json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $server['HTTP_ACCEPT']  = 'application/json';
            $raw  = (string) json_encode($body);
            if ($withCsrf) {
                $server['HTTP_X_CSRF_TOKEN'] = $this->csrfToken();
            }
            $body = [];
        }

        // Contenedor y enrutador nuevos por peticion: el contexto de
        // seguridad no se comparte entre llamadas, igual que en produccion.
        $root      = dirname(__DIR__);
        $container = require $root . '/app/bootstrap.php';
        /** @var Router $router */
        $router = require $root . '/app/routes.php';

        $request = new Request($query, $body, $server, $this->cookies, [], $raw);
        Flash::load($request);

        $kernel   = new Kernel($container, $router);
        $response = $kernel->handle($request);
        $response = Flash::applyTo($response);

        foreach ($response->cookies() as $cookie) {
            if ($cookie['expires'] !== 0 && $cookie['expires'] < time()) {
                unset($this->cookies[$cookie['name']]);
                continue;
            }
            $this->cookies[$cookie['name']] = $cookie['value'];
        }

        $bodyText = $response->body();
        if ($response->filePath() !== null) {
            // Se ejecuta el envio real para ejercitar el borrado del archivo
            // temporal, tal como ocurre en produccion.
            ob_start();
            $response->send();
            $bodyText = '[BINARIO:' . strlen((string) ob_get_clean()) . ']';
        }

        $this->lastResponse = $response;
        $this->lastBody     = $bodyText;

        $decoded = null;
        if (str_contains($response->headers()['Content-Type'] ?? '', 'application/json')) {
            $decoded = json_decode($bodyText, true);
            $decoded = is_array($decoded) ? $decoded : null;
        }

        // Flash se reinicia entre peticiones (estado por proceso).
        self::resetFlash();

        return [
            'status'  => $response->status(),
            'body'    => $bodyText,
            'headers' => $response->headers(),
            'json'    => $decoded,
            'file'    => $response->filePath(),
        ];
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $data = [], bool $withCsrf = true): array
    {
        return $this->request('POST', $path, $data, false, $withCsrf);
    }

    public function postJson(string $path, array $data = [], bool $withCsrf = true): array
    {
        return $this->request('POST', $path, $data, true, $withCsrf);
    }

    public function getJson(string $path, array $query = []): array
    {
        $result = $this->request('GET', $path, $query, false);
        return $result;
    }

    /** Inicia sesion y deja la cookie de sesion lista para las siguientes peticiones. */
    public function login(string $identifier, string $password): array
    {
        $this->get('/entrar');
        return $this->post('/entrar', ['identifier' => $identifier, 'password' => $password]);
    }

    private static function resetFlash(): void
    {
        $reflection = new \ReflectionClass(Flash::class);
        foreach (['data' => [], 'pending' => [], 'loaded' => false] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue(null, $value);
            }
        }
    }
}
