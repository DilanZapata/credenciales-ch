<?php
declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Enrutador con soporte de middleware por ruta y grupos.
 * El patron admite marcadores {param} y {param:\d+}.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,handler:mixed,middleware:array<int,string>,name:?string}> */
    private array $routes = [];
    /** @var array<int,string> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    public function group(string $prefix, array $middleware, Closure $callback): void
    {
        $prevPrefix     = $this->groupPrefix;
        $prevMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $prevPrefix . $prefix;
        $this->groupMiddleware = array_merge($prevMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;
    }

    public function get(string $p, mixed $h, array $mw = [], ?string $name = null): void    { $this->add('GET', $p, $h, $mw, $name); }
    public function post(string $p, mixed $h, array $mw = [], ?string $name = null): void   { $this->add('POST', $p, $h, $mw, $name); }
    public function put(string $p, mixed $h, array $mw = [], ?string $name = null): void    { $this->add('PUT', $p, $h, $mw, $name); }
    public function patch(string $p, mixed $h, array $mw = [], ?string $name = null): void  { $this->add('PATCH', $p, $h, $mw, $name); }
    public function delete(string $p, mixed $h, array $mw = [], ?string $name = null): void { $this->add('DELETE', $p, $h, $mw, $name); }

    private function add(string $method, string $path, mixed $handler, array $middleware, ?string $name): void
    {
        $pattern = $this->groupPrefix . $path;
        $pattern = $pattern === '' ? '/' : $pattern;

        $params = [];
        // El sub-patron admite cuantificadores con llaves ({36}, {2,8}),
        // por eso no basta con [^}]+ para delimitarlo.
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $sub      = $m[2] ?? '[^/]+';
                return '(' . $sub . ')';
            },
            str_replace('/', '\/', $pattern)
        );

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'regex'      => '/^' . $regex . '$/',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name'       => $name,
        ];
    }

    /**
     * @return array{route:array,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $pathAllowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) === 1) {
                if ($route['method'] !== $method) {
                    $pathAllowed[] = $route['method'];
                    continue;
                }
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $m[$i + 1];
                }
                return ['route' => $route, 'params' => $params];
            }
        }
        if ($pathAllowed !== []) {
            throw new HttpException(405, 'Metodo no permitido para esta ruta.');
        }
        return null;
    }

    /** @return array<int,array> */
    public function routes(): array
    {
        return $this->routes;
    }
}
