<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\SessionService;

/** Control de sesiones activas (art. 20). */
final class SessionController extends Controller
{
    public function __construct(
        private SessionService $sessions,
        private AuthorizationService $gate,
        private AuthContext $context,
        private AuditService $audit
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->require('sessions.view');
        $filters = [
            'status'  => $request->string('status') ?: 'active',
            'user_id' => $request->int('user_id'),
            'search'  => $request->string('q'),
        ];
        return $this->view('sessions/index', [
            'pageTitle'   => 'Sesiones',
            'sessions'    => $this->sessions->listSessions($filters, 200),
            'filters'     => $filters,
            'currentId'   => $this->context->sessionId(),
        ]);
    }

    public function revoke(Request $request, array $params): Response
    {
        $this->gate->require('sessions.revoke');
        $id = (string) $params['id'];
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            throw HttpException::badRequest('Identificador de sesion invalido.');
        }
        if ($id === $this->context->sessionId()) {
            $this->error('No puede cerrar su propia sesion desde este panel; use "Salir".');
            return $this->redirect('/sesiones');
        }
        $this->sessions->revoke($id, $this->context->id(), $request->string('reason') ?: 'cierre remoto por administrador');
        $this->audit->log(AuditService::SESSION_REVOKED, 'session', $id, null, 'success',
            ['motivo' => $request->string('reason')], 'warning');
        $this->success('Sesion cerrada remotamente.');
        return $this->redirect('/sesiones');
    }

    public function revokeAllForUser(Request $request, array $params): Response
    {
        $this->gate->require('sessions.revoke');
        $userId = (int) $params['userId'];
        $count  = $this->sessions->revokeAllForUser($userId, $this->context->id(), 'cierre masivo por administrador');
        $this->audit->log(AuditService::SESSION_REVOKED, 'user', $userId, null, 'success',
            ['sesiones_cerradas' => $count], 'warning');
        $this->success($count . ' sesion(es) cerradas.');
        return $this->back($request, '/sesiones');
    }
}
