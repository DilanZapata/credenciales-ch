<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lector minimalista de archivos .env.
 *
 * El .env vive FUERA del webroot (raiz del proyecto) y contiene la clave
 * maestra de cifrado. Nunca se expone por HTTP ni se vuelca en logs.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;
        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if (strlen($val) >= 2) {
                $first = $val[0];
                if (($first === '"' || $first === "'") && substr($val, -1) === $first) {
                    $val = substr($val, 1, -1);
                }
            }
            self::$vars[$key] = $val;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Una variable de entorno real tiene prioridad sobre el archivo:
        // permite sobrescribir la configuracion en despliegues y pruebas
        // sin tocar el .env.
        $fromEnvironment = getenv($key);
        if ($fromEnvironment !== false && $fromEnvironment !== '') {
            $v = $fromEnvironment;
        } elseif (!self::$loaded || !array_key_exists($key, self::$vars)) {
            return $default;
        } else {
            $v = self::$vars[$key];
        }
        return match (strtolower($v)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            ''                 => $default,
            default            => $v,
        };
    }

    public static function has(string $key): bool
    {
        if (getenv($key) !== false && getenv($key) !== '') {
            return true;
        }
        return isset(self::$vars[$key]) && self::$vars[$key] !== '';
    }
}
