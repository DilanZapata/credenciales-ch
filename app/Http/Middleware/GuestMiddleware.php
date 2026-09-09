<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\SessionService;

/** Impide que un usuario ya autenticado vuelva al formulario de acceso. */
final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionService $sessions)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookie(SessionService::COOKIE);
        if ($token !== null) {
            $session = $this->sessions->resolve($token);
            if ($session !== null && (int) $session['pending_mfa'] === 0) {
                return Response::redirect(Config::get('app.base_path', '') . '/');
            }
        }
        return $next($request);
    }
}
