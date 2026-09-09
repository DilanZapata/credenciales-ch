<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Abstraccion de la peticion HTTP. Toda entrada externa se toma unicamente
 * desde aqui; los controladores no leen $_GET/$_POST directamente.
 */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $cookies;
    private array $files;
    private array $attributes = [];
    private ?array $json = null;
    private string $rawBody;

    public function __construct(array $query, array $body, array $server, array $cookies, array $files, string $rawBody = '')
    {
        $this->query   = $query;
        $this->body    = $body;
        $this->server  = $server;
        $this->cookies = $cookies;
        $this->files   = $files;
        $this->rawBody = $rawBody;
    }

    public static function capture(): self
    {
        $raw = '';
        $ct  = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
        }
        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES, $raw);
    }

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        // Method override solo desde POST y solo a verbos idempotentes conocidos.
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->body['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    public function path(): string
    {
        $uri  = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = Config::get('app.base_path', '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type') ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        if ($this->isJson()) {
            return true;
        }
        $accept = $this->header('Accept') ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        return str_starts_with($this->path(), '/api/');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        $alt = str_replace('-', '_', strtoupper($name));
        return isset($this->server[$alt]) ? (string) $this->server[$alt] : null;
    }

    private function jsonBody(): array
    {
        if ($this->json === null) {
            $decoded    = $this->rawBody !== '' ? json_decode($this->rawBody, true) : null;
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->isJson() ? $this->jsonBody() : $this->body;
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        if (is_array($v)) {
            return $default;
        }
        // Normalizacion: recorte + eliminacion de bytes de control.
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '');
    }

    /** Valor sin recortar ni normalizar: solo para secretos y contrasenas. */
    public function secret(string $key): string
    {
        $v = $this->input($key, '');
        return is_string($v) ? $v : '';
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->input($key, null);
        if ($v === null || $v === '' || is_array($v)) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_INT) === false ? $default : (int) $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, null);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) (is_array($v) ? '' : $v)), ['1', 'true', 'on', 'yes', 'si'], true);
    }

    /** @return array<int,string> */
    public function arrayOfStrings(string $key): array
    {
        $v = $this->input($key, []);
        if (!is_array($v)) {
            $v = $v === '' || $v === null ? [] : [$v];
        }
        return array_values(array_filter(array_map(
            static fn ($item) => is_scalar($item) ? trim((string) $item) : '',
            $v
        ), static fn ($item) => $item !== ''));
    }

    /** @return array<int,int> */
    public function arrayOfInts(string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => filter_var($v, FILTER_VALIDATE_INT),
            $this->arrayOfStrings($key)
        ), static fn ($v) => $v !== false)));
    }

    public function all(): array
    {
        return array_merge($this->query, $this->isJson() ? $this->jsonBody() : $this->body);
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    public function ip(): string
    {
        $trustProxies = (bool) Config::get('app.trust_proxy', false);
        if ($trustProxies) {
            $forwarded = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $first = trim(explode(',', (string) $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** Etiqueta legible del dispositivo, derivada del user agent. */
    public function device(): string
    {
        $ua = $this->userAgent();
        if ($ua === '') {
            return 'Desconocido';
        }
        $os = 'Otro';
        foreach ([
            'Windows NT 10' => 'Windows 10/11', 'Windows NT' => 'Windows', 'Android' => 'Android',
            'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Mac OS X' => 'macOS', 'Linux' => 'Linux',
        ] as $needle => $label) {
            if (str_contains($ua, $needle)) { $os = $label; break; }
        }
        $browser = 'Navegador';
        foreach ([
            'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome',
            'Firefox/' => 'Firefox', 'Safari/' => 'Safari',
        ] as $needle => $label) {
            if (str_contains($ua, $needle)) { $browser = $label; break; }
        }
        return $browser . ' / ' . $os;
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ((bool) Config::get('app.trust_proxy', false)) {
            return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    public function origin(): ?string
    {
        return $this->header('Origin');
    }

    public function referer(): ?string
    {
        return $this->header('Referer');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
