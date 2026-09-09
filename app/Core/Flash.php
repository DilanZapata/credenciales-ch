<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Mensajes efimeros entre peticiones.
 *
 * Se apoyan en una cookie firmada de vida muy corta en lugar de la sesion
 * nativa de PHP (que esta deshabilitada). El contenido es texto de interfaz;
 * nunca se colocan secretos aqui.
 */
final class Flash
{
    private const COOKIE = 'scgca_flash';
    /** @var array<string,mixed> */
    private static array $data    = [];
    private static array $pending = [];
    private static bool $loaded   = false;

    public static function load(Request $request): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $raw = $request->cookie(self::COOKIE);
        if ($raw === null || $raw === '') {
            return;
        }
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return;
        }
        [$payload, $signature] = $parts;
        $expected = hash_hmac('sha256', $payload, self::key());
        if (!hash_equals($expected, $signature)) {
            return;
        }
        $decoded = json_decode((string) base64_decode($payload, true), true);
        if (is_array($decoded)) {
            self::$data = $decoded;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        self::$pending[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    public static function applyTo(Response $response): Response
    {
        if (self::$pending === []) {
            // Se limpia la cookie tras consumirla.
            if (self::$data !== []) {
                $response->withCookie(self::COOKIE, '', time() - 3600);
            }
            return $response;
        }
        $payload   = base64_encode((string) json_encode(self::$pending, JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', $payload, self::key());
        return $response->withCookie(self::COOKIE, $payload . '.' . $signature, time() + 120);
    }

    private static function key(): string
    {
        $master = (string) Env::get('APP_MASTER_KEY', 'flash-fallback-key');
        return hash_hmac('sha256', 'SCGCA:FLASH', $master);
    }
}
