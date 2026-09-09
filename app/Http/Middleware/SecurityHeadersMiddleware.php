<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Cabeceras de seguridad y politica de contenido.
 *
 * La CSP es estricta: sin 'unsafe-inline' para scripts (se usa un nonce
 * por peticion), sin origenes externos, y con form-action limitada al
 * propio sitio. Esto reduce drasticamente el impacto de un XSS.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->setAttribute('csp_nonce', $nonce);
        \App\Core\View::share('cspNonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        foreach ((array) Config::get('security.headers', []) as $name => $value) {
            $response->withHeader((string) $name, (string) $value);
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Los scripts exigen nonce (sin 'unsafe-inline'): un XSS no puede
            // ejecutar codigo. Para hojas de estilo se admite el atributo
            // style en linea, necesario para valores dinamicos (barras de
            // progreso, colores de categoria); la inyeccion de CSS tiene un
            // impacto muy inferior y no permite ejecucion de codigo.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        $response->withHeader('Content-Security-Policy', $csp);

        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', (string) Config::get('security.hsts'));
        }

        // Ninguna vista del sistema debe quedar en cache del navegador:
        // podria mostrar datos de otro usuario tras cerrar sesion.
        $response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->withHeader('Pragma', 'no-cache');

        return $response;
    }
}
