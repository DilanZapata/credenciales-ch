<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';
    /** @var array<int,array{name:string,value:string,expires:int}> */
    private array $cookies = [];
    private ?string $filePath = null;
    private bool $deleteAfterSend = false;

    public static function html(string $body, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->body   = $body;
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public static function json(array|\JsonSerializable $data, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->body   = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r->headers['Content-Type'] = 'application/json; charset=UTF-8';
        return $r;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $r = new self();
        $r->status = $status;
        // Solo se permiten destinos internos: evita open redirect.
        $r->headers['Location'] = self::sanitizeLocation($url);
        return $r;
    }

    public static function noContent(): self
    {
        $r = new self();
        $r->status = 204;
        return $r;
    }

    public static function download(string $path, string $filename, string $mime, bool $deleteAfter = true): self
    {
        $r = new self();
        $r->filePath        = $path;
        $r->deleteAfterSend = $deleteAfter;
        $r->headers['Content-Type']              = $mime;
        $r->headers['Content-Length']            = (string) filesize($path);
        $r->headers['Content-Disposition']       = 'attachment; filename="' . self::sanitizeFilename($filename) . '"';
        $r->headers['X-Content-Type-Options']    = 'nosniff';
        $r->headers['Cache-Control']             = 'no-store, no-cache, must-revalidate, private';
        $r->headers['Pragma']                    = 'no-cache';
        return $r;
    }

    private static function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $name) ?? 'reporte';
        return substr($name, 0, 120);
    }

    private static function sanitizeLocation(string $url): string
    {
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '//')) {
            $allowed = (string) Config::get('app.url', '');
            if ($allowed !== '' && str_starts_with($url, $allowed)) {
                return $url;
            }
            return Config::get('app.base_path', '') . '/';
        }
        return $url;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = str_replace(["\r", "\n"], '', $value);
        return $this;
    }

    public function withCookie(string $name, string $value, int $expires): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'expires' => $expires];
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<int,array{name:string,value:string,expires:int}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            $secure   = Config::get('session.cookie_secure', false);
            $basePath = Config::get('app.base_path', '') ?: '';
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], [
                    'expires'  => $cookie['expires'],
                    'path'     => $basePath . '/',
                    'domain'   => '',
                    'secure'   => (bool) $secure,
                    'httponly' => true,
                    'samesite' => (string) Config::get('session.cookie_samesite', 'Strict'),
                ]);
            }
        }

        if ($this->filePath !== null && is_readable($this->filePath)) {
            $path = $this->filePath;
            // Se envia en bloques para no cargar el archivo completo en memoria.
            $fh = fopen($path, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    echo fread($fh, 8192);
                }
                fclose($fh);
            }
            if ($this->deleteAfterSend) {
                @unlink($path);
            }
            return;
        }

        echo $this->body;
    }
}
