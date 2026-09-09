<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * CORS restrictivo.
 *
 * Por defecto esta DESHABILITADO: la API se consume desde el mismo origen.
 * Si se habilita, solo responde a origenes de una lista blanca explicita y
 * nunca con comodin junto a credenciales.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $config = (array) Config::get('security.cors', []);
        $origin = $request->origin();

        $allowed = ($config['enabled'] ?? false)
            && $origin !== null
            && in_array($origin, (array) ($config['allowed_origins'] ?? []), true);

        if ($request->method() === 'OPTIONS') {
            $response = Response::noContent();
            if ($allowed) {
                $this->applyHeaders($response, (string) $origin, $config);
            }
            return $response;
        }

        $response = $next($request);
        if ($allowed) {
            $this->applyHeaders($response, (string) $origin, $config);
        }
        return $response;
    }

    private function applyHeaders(Response $response, string $origin, array $config): void
    {
        $response->withHeader('Access-Control-Allow-Origin', $origin)
                 ->withHeader('Vary', 'Origin')
                 ->withHeader('Access-Control-Allow-Credentials', 'true')
                 ->withHeader('Access-Control-Allow-Headers', implode(', ', (array) ($config['allowed_headers'] ?? [])))
                 ->withHeader('Access-Control-Allow-Methods', implode(', ', (array) ($config['allowed_methods'] ?? [])))
                 ->withHeader('Access-Control-Max-Age', (string) ($config['max_age'] ?? 600));
    }
}
