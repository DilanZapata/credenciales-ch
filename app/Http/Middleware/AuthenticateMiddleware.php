<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\AuthContext;
use App\Services\SessionService;

/**
 * Resuelve la sesion y construye el contexto de seguridad.
 *
 * Comprobaciones en cada peticion (no solo al iniciar sesion):
 *   - la sesion existe, esta activa y no ha caducado (inactividad/absoluta);
 *   - el usuario sigue ACTIVO (una baja surte efecto de inmediato);
 *   - si el segundo factor esta pendiente, solo se permite completarlo;
 *   - si debe cambiar la contrasena, se le fuerza a esa pantalla.
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SessionService $sessions,
        private UserRepository $users,
        private AuthContext $context,
        private \App\Services\SettingsService $settings
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token   = $request->cookie(SessionService::COOKIE);
        $session = $token !== null ? $this->sessions->resolve($token) : null;

        if ($session === null) {
            return $this->reject($request, 'Su sesion expiro o no ha iniciado sesion.');
        }

        $user = $this->users->find((int) $session['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            $this->sessions->revoke((string) $session['id'], null, 'usuario no activo');
            return $this->reject($request, 'Su cuenta ya no se encuentra activa.');
        }

        // MFA pendiente: solo puede acceder a la verificacion o al cierre de sesion.
        if ((int) $session['pending_mfa'] === 1) {
            $path = $request->path();
            if (!in_array($path, ['/mfa', '/mfa/verificar', '/salir'], true)) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'Debe completar la verificacion en dos pasos.', 'mfa_required' => true], 401)
                    : Response::redirect(Config::get('app.base_path', '') . '/mfa');
            }
        }

        $this->sessions->touch((string) $session['id']);

        $permissions = $this->users->effectivePermissions((int) $user['id']);
        $roles       = $this->users->rolesOf((int) $user['id']);
        $this->context->authenticate($user, $session, $permissions, $roles);

        // Datos compartidos con todas las vistas (navegacion, CSRF, permisos).
        \App\Core\View::share('auth', $this->context);
        \App\Core\View::share('csrf', (string) $session['csrf_token']);
        \App\Core\View::share('currentPath', $request->path());

        // Segundo factor obligatorio por rol (art. 23): mientras no este
        // configurado, la sesion solo puede llegar a la pantalla de alta.
        if ((int) $user['mfa_enabled'] === 0 && $this->mfaIsMandatory($user, $roles)) {
            $allowed = ['/perfil/mfa', '/perfil/mfa/iniciar', '/perfil/mfa/confirmar', '/salir', '/perfil/contrasena'];
            if (!in_array($request->path(), $allowed, true)) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'Debe configurar la verificacion en dos pasos.', 'mfa_setup_required' => true], 403)
                    : Response::redirect(Config::get('app.base_path', '') . '/perfil/mfa');
            }
        }

        // Cambio de contrasena obligatorio (por marca administrativa o por
        // caducidad de la politica de contrasenas).
        if ((int) $user['must_change_password'] === 1 || $this->passwordExpired($user)) {
            $path = $request->path();
            $allowed = ['/perfil/contrasena', '/salir', '/api/v1/perfil/contrasena'];
            if (!in_array($path, $allowed, true)) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'Debe cambiar su contrasena antes de continuar.', 'password_change_required' => true], 403)
                    : Response::redirect(Config::get('app.base_path', '') . '/perfil/contrasena');
            }
        }

        return $next($request);
    }

    /**
     * La contrasena de acceso caduca segun la politica configurada.
     * Un valor de 0 dias desactiva la caducidad.
     *
     * @param array<string,mixed> $user
     */
    private function passwordExpired(array $user): bool
    {
        $days = $this->settings->int('security.password_expiry_days', 0);
        if ($days <= 0) {
            return false;
        }
        $changedAt = $user['password_changed_at'] ?? null;
        if ($changedAt === null) {
            return true;
        }
        return strtotime((string) $changedAt) < (time() - ($days * 86400));
    }

    /**
     * @param array<string,mixed> $user
     * @param array<int,array<string,mixed>> $roles
     */
    private function mfaIsMandatory(array $user, array $roles): bool
    {
        if ((int) ($user['mfa_enforced'] ?? 0) === 1) {
            return true;
        }
        if (!$this->settings->bool('security.mfa_required_admins', true)) {
            return false;
        }
        foreach ($roles as $role) {
            if ((int) ($role['requires_mfa'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    private function reject(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            throw HttpException::unauthorized($message);
        }
        $target = rawurlencode($request->path());
        return Response::redirect(Config::get('app.base_path', '') . '/entrar?redirect=' . $target)
            ->withCookie(SessionService::COOKIE, '', time() - 3600);
    }
}
