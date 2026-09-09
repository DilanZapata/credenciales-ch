<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\AuthContext;
use App\Services\SessionService;

/**
 * Proteccion CSRF para todo metodo que modifica estado.
 *
 * Defensa en profundidad, tres capas:
 *   1. Token sincronizador ligado a la sesion (formulario o cabecera
 *      X-CSRF-Token), comparado en tiempo constante.
 *   2. Verificacion del Origin/Referer contra el host propio.
 *   3. Cookie de sesion con SameSite=Strict (definida en Response).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private SessionService $sessions,
        private AuthContext $context,
        private AuditService $audit
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $method = $request->method();
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $path = $request->path();
        if (in_array($path, (array) Config::get('security.csrf_exempt', []), true)) {
            return $next($request);
        }

        $expected = $this->expectedToken($request);
        $provided = $request->header('X-CSRF-Token') ?? (string) $request->input('_csrf', '');

        if ($expected === null || $provided === '' || !hash_equals($expected, (string) $provided)) {
            $this->audit->log('security.csrf_failed', null, null, $path, 'denied',
                ['metodo' => $method], 'critical');
            throw new HttpException(419, 'La sesion del formulario expiro. Recargue la pagina e intente nuevamente.');
        }

        if (!$this->originIsTrusted($request)) {
            $this->audit->log('security.csrf_origin_failed', null, null, $path, 'denied',
                ['origen' => $request->origin() ?? $request->referer()], 'critical');
            throw new HttpException(419, 'El origen de la solicitud no es valido.');
        }

        return $next($request);
    }

    private function expectedToken(Request $request): ?string
    {
        // 1. Sesion autenticada: token sincronizador de la fila de sesion.
        $token = $this->context->csrfToken();
        if ($token !== null) {
            return $token;
        }
        // 2. Sesion existente pero aun pendiente de MFA.
        $cookie = $request->cookie(SessionService::COOKIE);
        if ($cookie !== null) {
            $session = $this->sessions->resolve($cookie);
            if ($session !== null) {
                return (string) $session['csrf_token'];
            }
        }
        // 3. Formularios publicos: double submit cookie.
        $guest = $request->cookie(Csrf::COOKIE);
        return $guest !== null && Csrf::isValidFormat($guest) ? $guest : null;
    }

    private function originIsTrusted(Request $request): bool
    {
        $origin = $request->origin() ?? $request->referer();

        // Sin Origin/Referer, o con un origen opaco ("null", que envian los
        // contextos aislados y algunas configuraciones de privacidad), se
        // acepta apoyandose UNICAMENTE en el token sincronizador, que ya fue
        // validado en tiempo constante contra la sesion. Es la recomendacion
        // de OWASP: la comprobacion de origen es defensa en profundidad, no
        // el control primario.
        if ($origin === null || $origin === '' || $origin === 'null') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if ($host === null || $host === false) {
            // Un Origin presente pero con formato invalido si es sospechoso.
            return false;
        }
        // El host esperado sale SIEMPRE de la configuracion, nunca de la
        // cabecera Host: esa la controla el cliente y podria falsificarse.
        $expected = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        if ($expected === null || $expected === false || $expected === '') {
            // Sin APP_URL configurada no hay nada con que comparar; se
            // mantiene la validacion del token, que ya se supero.
            return true;
        }
        return strcasecmp($host, explode(':', (string) $expected)[0]) === 0;
    }
}
