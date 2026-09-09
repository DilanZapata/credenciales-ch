<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use App\Services\AuthContext;
use App\Services\AuthorizationService;

/** Consulta de la auditoria y de los eventos de seguridad (art. 8). */
final class AuditController extends Controller
{
    public function __construct(
        private AuditRepository $audit,
        private UserRepository $users,
        private AuthorizationService $gate,
        private AuthContext $context
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->require('audit.view');
        [$page, $perPage] = $this->pagination($request, 50);
        $filters = $this->filtersFrom($request);
        $result  = $this->audit->paginate($filters, $page, $perPage);

        return $this->view('audit/index', [
            'pageTitle' => 'Auditoria',
            'result'    => $result,
            'page'      => $page,
            'perPage'   => $perPage,
            'pages'     => (int) ceil($result['total'] / $perPage),
            'filters'   => $filters,
            'actions'   => $this->audit->distinctActions(),
            'usersList' => $this->users->activeSelectList(),
        ]);
    }

    public function securityEvents(Request $request): Response
    {
        $this->gate->require('security.events.view');
        return $this->view('audit/security', [
            'pageTitle' => 'Eventos de seguridad',
            'events'    => $this->audit->securityEvents([
                'status'   => $request->string('status') ?: null,
                'severity' => $request->string('severity') ?: null,
            ], 200),
            'filters'   => ['status' => $request->string('status'), 'severity' => $request->string('severity')],
        ]);
    }

    public function resolveEvent(Request $request, array $params): Response
    {
        $this->gate->require('security.events.view');
        $this->audit->resolveSecurityEvent((int) $params['id'], (int) $this->context->id());
        $this->success('Evento marcado como resuelto.');
        return $this->redirect('/seguridad/eventos');
    }

    /** @return array<string,mixed> */
    private function filtersFrom(Request $request): array
    {
        return array_filter([
            'user_id'      => $request->int('user_id'),
            'national_id'  => $request->string('national_id'),
            'action'       => $request->string('action'),
            'action_group' => $request->string('action_group'),
            'entity_type'  => $request->string('entity_type'),
            'entity_id'    => $request->string('entity_id'),
            'result'       => in_array($request->string('result'), ['success', 'failure', 'denied'], true) ? $request->string('result') : null,
            'severity'     => in_array($request->string('severity'), ['info', 'notice', 'warning', 'critical'], true) ? $request->string('severity') : null,
            'ip'           => $request->string('ip'),
            'date_from'    => $request->string('date_from'),
            'date_to'      => $request->string('date_to'),
            'search'       => $request->string('q'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    }
}
