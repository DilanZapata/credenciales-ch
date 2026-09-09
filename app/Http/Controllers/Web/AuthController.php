<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Csrf;
use App\Core\ValidationException;
use App\Http\Controllers\Controller;
use App\Services\AuthContext;
use App\Services\AuthService;
use App\Services\MailService;
use App\Services\SessionService;
use App\Services\SettingsService;

/** Acceso al sistema: ingreso, MFA, cierre de sesion y recuperacion. */
final class AuthController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private SessionService $sessions,
        private AuthContext $context,
        private SettingsService $settings,
        private MailService $mail
    ) {
    }

    public function showLogin(Request $request): Response
    {
        // Se emite una sesion "invitada" solo para poder validar el token CSRF
        // del formulario sin exponer un token global.
        $csrf = $this->guestCsrf($request);

        $response = $this->view('auth/login', [
            'csrf'     => $csrf['token'],
            'redirect' => $this->safeRedirect($request->string('redirect')),
        ], 'layouts/auth');

        return $csrf['cookie'] !== null
            ? $response->withCookie(Csrf::COOKIE, $csrf['cookie'], time() + 7200)
            : $response;
    }

    public function login(Request $request): Response
    {
        $identifier = $request->string('identifier');
        $password   = $request->secret('password');

        if ($identifier === '' || $password === '') {
            $this->error('Debe indicar su usuario o cedula y su contrasena.');
            return $this->redirect('/entrar');
        }

        $result = $this->auth->attemptLogin(
            $identifier,
            $password,
            $request->ip(),
            $request->userAgent(),
            $request->device()
        );

        if ($result['status'] === 'error') {
            $this->error($result['message'] ?? 'No fue posible iniciar sesion.');
            return $this->redirect('/entrar');
        }

        $target = match ($result['status']) {
            'mfa_required'       => '/mfa',
            'mfa_setup_required' => '/perfil/mfa',
            default              => ($result['must_change_password'] ?? false)
                                    ? '/perfil/contrasena'
                                    : ($this->safeRedirect($request->string('redirect')) ?: '/'),
        };

        if ($result['status'] === 'mfa_setup_required') {
            $this->flash('info', 'Su rol exige verificacion en dos pasos. Configurela para continuar.');
        }

        return $this->redirect($target)
            ->withCookie(SessionService::COOKIE, (string) $result['token'], 0);
    }

    public function showMfa(Request $request): Response
    {
        $session = $this->currentRawSession($request);
        if ($session === null) {
            return $this->redirect('/entrar');
        }
        return $this->view('auth/mfa', ['csrf' => $session['csrf_token']], 'layouts/auth');
    }

    public function verifyMfa(Request $request): Response
    {
        $session = $this->currentRawSession($request);
        if ($session === null) {
            return $this->redirect('/entrar');
        }
        $code   = $request->string('code');
        $result = $this->auth->verifyMfa($session, $code);

        if ($result['status'] !== 'ok') {
            $this->error($result['message'] ?? 'Codigo invalido.');
            return $this->redirect('/mfa');
        }

        return $this->redirect('/')->withCookie(SessionService::COOKIE, (string) $result['token'], 0);
    }

    public function logout(Request $request): Response
    {
        $sessionId = $this->context->sessionId();
        if ($sessionId !== null) {
            $this->auth->logout($sessionId, $this->context->id());
        }
        return $this->redirect('/entrar')->withCookie(SessionService::COOKIE, '', time() - 3600);
    }

    // ---------------------------------------------------------------
    //  Recuperacion de cuenta
    // ---------------------------------------------------------------

    public function showForgot(Request $request): Response
    {
        $csrf     = $this->guestCsrf($request);
        $response = $this->view('auth/forgot', ['csrf' => $csrf['token']], 'layouts/auth');
        return $csrf['cookie'] !== null
            ? $response->withCookie(Csrf::COOKIE, $csrf['cookie'], time() + 7200)
            : $response;
    }

    public function sendReset(Request $request): Response
    {
        $identifier = $request->string('identifier');
        $result     = $this->auth->requestPasswordReset($identifier, $request->ip());

        if ($result !== null) {
            $link = (string) Config::get('app.url') . '/restablecer/' . $result['token'];
            $this->mail->sendPasswordResetLink(
                (string) $result['user']['email'],
                (string) $result['user']['first_name'],
                $link
            );
            // En entorno de desarrollo se muestra el enlace para poder probar
            // el flujo sin servidor de correo configurado.
            if ((bool) Config::get('app.debug', false) && !$this->mail->enabled()) {
                $this->flash('info', 'Enlace de recuperacion (solo modo desarrollo): ' . $link);
            }
        }

        // Respuesta identica exista o no la cuenta: no se filtra informacion.
        $this->success('Si el identificador corresponde a una cuenta activa, recibira un correo con las instrucciones.');
        return $this->redirect('/entrar');
    }

    public function showReset(Request $request, array $params): Response
    {
        $csrf     = $this->guestCsrf($request);
        $response = $this->view('auth/reset', [
            'csrf'  => $csrf['token'],
            'token' => (string) ($params['token'] ?? ''),
        ], 'layouts/auth');
        return $csrf['cookie'] !== null
            ? $response->withCookie(Csrf::COOKIE, $csrf['cookie'], time() + 7200)
            : $response;
    }

    public function doReset(Request $request): Response
    {
        $token = $request->string('token');
        try {
            $this->auth->completePasswordReset(
                $token,
                $request->secret('password'),
                $request->secret('password_confirmation')
            );
        } catch (ValidationException $e) {
            $this->flash('errors', $e->errors());
            $this->error(implode(' ', $e->errors()));
            return $this->redirect('/restablecer/' . rawurlencode($token));
        }
        $this->success('Su contrasena se actualizo correctamente. Ya puede iniciar sesion.');
        return $this->redirect('/entrar');
    }

    // ---------------------------------------------------------------

    /**
     * Token CSRF para formularios publicos (acceso, recuperacion).
     *
     * Sin sesion autenticada se usa el patron "double submit cookie":
     * el mismo token viaja en una cookie HttpOnly SameSite=Strict y en el
     * formulario. CsrfMiddleware compara ambos en tiempo constante.
     *
     * @return array{token:string,cookie:?string}
     */
    private function guestCsrf(Request $request): array
    {
        $existing = $request->cookie(SessionService::COOKIE);
        if ($existing !== null) {
            $session = $this->sessions->resolve($existing);
            if ($session !== null) {
                return ['token' => (string) $session['csrf_token'], 'cookie' => null];
            }
        }
        $cookie = $request->cookie(Csrf::COOKIE);
        if ($cookie !== null && Csrf::isValidFormat($cookie)) {
            return ['token' => $cookie, 'cookie' => null];
        }
        $token = Csrf::generate();
        return ['token' => $token, 'cookie' => $token];
    }

    /** @return array<string,mixed>|null */
    private function currentRawSession(Request $request): ?array
    {
        $cookie = $request->cookie(SessionService::COOKIE);
        return $cookie !== null ? $this->sessions->resolve($cookie) : null;
    }

    private function safeRedirect(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }
        return preg_match('#^/[A-Za-z0-9/_\-]*$#', $path) === 1 ? $path : '';
    }
}
