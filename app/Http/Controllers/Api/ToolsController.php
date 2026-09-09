<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\CredentialRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SystemRepository;
use App\Services\AlertService;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\PasswordGeneratorService;

/** Utilidades del frontend: generador, busqueda global, notificaciones. */
final class ToolsController extends Controller
{
    public function __construct(
        private PasswordGeneratorService $passwords,
        private CredentialRepository $credentials,
        private SystemRepository $systems,
        private NotificationRepository $notifications,
        private AlertService $alerts,
        private AuthorizationService $gate,
        private AuthContext $context
    ) {
    }

    /** POST /api/v1/generador - generador criptografico de contrasenas. */
    public function generate(Request $request): Response
    {
        $password = $this->passwords->generate([
            'length'            => (int) ($request->int('length') ?? 20),
            'upper'             => $request->bool('upper', true),
            'lower'             => $request->bool('lower', true),
            'digits'            => $request->bool('digits', true),
            'symbols'           => $request->bool('symbols', true),
            'exclude_ambiguous' => $request->bool('exclude_ambiguous', false),
        ]);
        $score = $this->passwords->strength($password);

        // La contrasena generada no se registra en ningun log ni auditoria.
        return $this->json([
            'password' => $password,
            'strength' => $score,
            'label'    => $this->passwords->strengthLabel($score),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    /** POST /api/v1/fortaleza - evalua una contrasena sin almacenarla. */
    public function strength(Request $request): Response
    {
        $score = $this->passwords->strength($request->secret('password'));
        return $this->json(['strength' => $score, 'label' => $this->passwords->strengthLabel($score)])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /** GET /api/v1/buscar - busqueda global respetando el alcance del usuario. */
    public function search(Request $request): Response
    {
        $term = $request->string('q');
        if (mb_strlen($term) < 2) {
            return $this->json(['credentials' => [], 'systems' => []]);
        }
        $this->gate->requireAny(['credentials.view', 'credentials.view_all']);

        $scopeUserId = $this->gate->credentialScopeUserId();
        $credentials = $this->credentials->paginate(['search' => $term], 1, 10, $scopeUserId);

        $systems = [];
        if ($this->gate->can('systems.view')) {
            $systems = $this->systems->paginate(['search' => $term], 1, 5)['items'];
        }

        return $this->json([
            'credentials' => array_map(static fn (array $row): array => [
                'id'       => (int) $row['id'],
                'name'     => $row['name'],
                'system'   => $row['system_name'],
                'category' => $row['category_name'],
                'username' => $row['username'],
            ], $credentials['items']),
            'systems' => array_map(static fn (array $row): array => [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
                'type' => $row['resource_type'],
            ], $systems),
        ]);
    }

    /** GET /api/v1/notificaciones */
    public function notifications(Request $request): Response
    {
        $userId  = (int) $this->context->id();
        $roleIds = array_map(static fn (array $r): int => (int) $r['id'], $this->context->roles());
        return $this->json([
            'unread' => $this->notifications->unreadCount($userId, $roleIds),
            'items'  => $this->notifications->forUser($userId, $roleIds, false, 15),
        ]);
    }

    /** GET /api/v1/alertas */
    public function alerts(Request $request): Response
    {
        $this->gate->require('dashboard.view');
        if (!$this->gate->can('credentials.view_all')) {
            return $this->json(['items' => []]);
        }
        return $this->json(['items' => $this->alerts->evaluate()]);
    }
}
