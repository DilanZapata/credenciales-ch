<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Motor de plantillas basado en PHP plano.
 *
 * Regla de la capa de presentacion: TODA interpolacion de datos pasa por
 * e() (htmlspecialchars con ENT_QUOTES). Nunca se imprime entrada de usuario
 * sin escapar (mitigacion de XSS reflejado y almacenado).
 */
final class View
{
    private static string $path = '';
    /** @var array<string,mixed> */
    private static array $shared = [];
    private static array $sections = [];
    private static array $stack = [];

    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @return array<string,mixed> */
    public static function sharedData(): array
    {
        return self::$shared;
    }

    public static function render(string $template, array $data = []): string
    {
        $file = self::$path . '/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Plantilla no encontrada: ' . $template);
        }
        $vars = array_merge(self::$shared, $data);
        extract($vars, EXTR_SKIP);
        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        require $file;
        return (string) ob_get_clean();
    }

    /** Renderiza una vista dentro de un layout. */
    public static function page(string $template, array $data = [], string $layout = 'layouts/app'): string
    {
        $content = self::render($template, $data);
        return self::render($layout, array_merge($data, ['content' => $content]));
    }

    public static function start(string $section): void
    {
        self::$stack[] = $section;
        ob_start();
    }

    public static function end(): void
    {
        $section = array_pop(self::$stack);
        if ($section !== null) {
            self::$sections[$section] = (string) ob_get_clean();
        }
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }
}
