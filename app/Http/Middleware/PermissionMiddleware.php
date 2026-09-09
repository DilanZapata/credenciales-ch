<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthorizationService;

/**
 * Comprobacion de permiso a nivel de ruta.
 *
 * Es una primera barrera; los servicios vuelven a verificar el permiso
 * (defensa en profundidad): ninguna capa confia en la anterior.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthorizationService $gate, private string $permission)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->gate->require($this->permission);
        return $next($request);
    }
}
