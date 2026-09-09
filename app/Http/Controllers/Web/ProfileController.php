<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Http\Controllers\Controller;
use App\Repositories\AssignmentRepository;
use App\Repositories\NotificationRepository;
use App\Services\AuthContext;
use App\Services\AuthService;
use App\Services\SessionService;

/** Perfil del usuario: contrasena propia, MFA y notificaciones. */
final class ProfileController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private AuthContext $context,
        private SessionService $sessions,
        private AssignmentRepository $assignments,
        private NotificationRepository $notifications
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->context->id();
        return $this->view('users/profile', [
            'pageTitle'      => 'Mi perfil',
            'user'           => $this->context->user(),
            'roles'          => $this->context->roles(),
            'permissions'    => $this->context->permissions(),
            'assignments'    => $this->assignments->forUser($userId, true),
            'sessions'       => $this->sessions->listSessions(['user_id' => $userId, 'status' => 'active'], 20),
            'currentSession' => $this->context->sessionId(),
            'backupCodes'    => $this->auth->remainingBackupCodes($userId),
        ]);
    }

    public function passwordForm(Request $request): Response
    {
        return $this->view('users/password', [
            'pageTitle' => 'Cambiar contrasena',
            'forced'    => (int) ($this->context->user()['must_change_password'] ?? 0) === 1,
        ], 'layouts/app');
    }

    public function changePassword(Request $request): Response
    {
        try {
            $this->auth->changeOwnPassword(
                (int) $this->context->id(),
                $request->secret('current_password'),
                $request->secret('password'),
                $request->secret('password_confirmation')
            );
        } catch (ValidationException $e) {
            $this->flash('errors', $e->errors());
            $this->error(implode(' ', $e->errors()));
            return $this->redirect('/perfil/contrasena');
        }
        $this->success('Contrasena actualizada. Se cerraron sus otras sesiones.');
        return $this->redirect('/');
    }

    public function mfa(Request $request): Response
    {
        $userId = (int) $this->context->id();
        return $this->view('users/mfa', [
            'pageTitle'   => 'Verificacion en dos pasos',
            'enabled'     => (bool) ($this->context->user()['mfa_enabled'] ?? false),
            'required'    => $this->auth->userRequiresMfa($userId, (bool) ($this->context->user()['mfa_enforced'] ?? false)),
            'backupCodes' => $this->auth->remainingBackupCodes($userId),
            'enrollment'  => null,
        ]);
    }

    public function beginMfa(Request $request): Response
    {
        $userId     = (int) $this->context->id();
        $account    = (string) ($this->context->user()['email'] ?? 'usuario');
        $issuer     = (string) Config::get('app.short_name', 'Credenciales');
        $enrollment = $this->auth->beginMfaEnrollment($userId, $account, $issuer);

        return $this->view('users/mfa', [
            'pageTitle'   => 'Verificacion en dos pasos',
            'enabled'     => false,
            'required'    => true,
            'backupCodes' => 0,
            'enrollment'  => $enrollment,
        ]);
    }

    public function confirmMfa(Request $request): Response
    {
        try {
            $codes = $this->auth->confirmMfaEnrollment((int) $this->context->id(), $request->string('code'));
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->errors()));
            return $this->redirect('/perfil/mfa');
        }
        // Los codigos de respaldo se muestran una unica vez.
        $this->flash('backup_codes', $codes);
        $this->success('Verificacion en dos pasos activada. Guarde sus codigos de respaldo.');
        return $this->redirect('/perfil/mfa');
    }

    public function disableMfa(Request $request): Response
    {
        $userId = (int) $this->context->id();
        if (!$this->auth->reauthenticate($userId, $request->secret('password'))) {
            $this->error('La contrasena no es correcta.');
            return $this->redirect('/perfil/mfa');
        }
        if ($this->auth->userRequiresMfa($userId, (bool) ($this->context->user()['mfa_enforced'] ?? false))) {
            $this->error('Su rol exige verificacion en dos pasos; no es posible desactivarla.');
            return $this->redirect('/perfil/mfa');
        }
        $this->auth->disableMfa($userId, $userId);
        $this->success('Verificacion en dos pasos desactivada.');
        return $this->redirect('/perfil/mfa');
    }

    public function notifications(Request $request): Response
    {
        $userId  = (int) $this->context->id();
        $roleIds = array_map(static fn (array $r): int => (int) $r['id'], $this->context->roles());
        return $this->view('users/notifications', [
            'pageTitle'     => 'Notificaciones',
            'notifications' => $this->notifications->forUser($userId, $roleIds, false, 100),
        ]);
    }

    public function markNotificationRead(Request $request, array $params): Response
    {
        $userId  = (int) $this->context->id();
        $roleIds = array_map(static fn (array $r): int => (int) $r['id'], $this->context->roles());
        $this->notifications->markRead((int) $params['id'], $userId, $roleIds);
        return $this->back($request, '/notificaciones');
    }
}
