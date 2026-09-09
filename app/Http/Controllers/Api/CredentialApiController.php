<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\AssignmentRepository;
use App\Services\CredentialService;

/**
 * API REST de credenciales.
 *
 * Ninguna respuesta de este controlador contiene secretos: para eso
 * existe SecretController, con sus propios controles.
 */
final class CredentialApiController extends Controller
{
    public function __construct(
        private CredentialService $credentials,
        private AssignmentRepository $assignments
    ) {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request);
        $filters = array_filter([
            'search'           => $request->string('q'),
            'category_id'      => $request->int('category_id'),
            'system_id'        => $request->int('system_id'),
            'company_id'       => $request->int('company_id'),
            'department_id'    => $request->int('department_id'),
            'status'           => $request->string('status'),
            'assigned_user_id' => $request->int('assigned_user_id'),
            'sort'             => $request->string('sort'),
            'direction'        => $request->string('direction'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);

        return $this->json($this->credentials->list($filters, $page, $perPage));
    }

    public function show(Request $request, array $params): Response
    {
        return $this->json($this->credentials->show((int) $params['id']));
    }

    public function history(Request $request, array $params): Response
    {
        return $this->json($this->credentials->history((int) $params['id']));
    }

    public function assignments(Request $request, array $params): Response
    {
        return $this->json(['items' => $this->credentials->assignments((int) $params['id'])]);
    }

    public function store(Request $request): Response
    {
        $id = $this->credentials->create($this->payload($request), $request->secret('password'));
        return $this->json(['id' => $id, 'message' => 'Credencial creada.'], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $this->credentials->update((int) $params['id'], $this->payload($request));
        return $this->json(['message' => 'Credencial actualizada.']);
    }

    public function rotate(Request $request, array $params): Response
    {
        $version = $this->credentials->rotatePassword(
            (int) $params['id'],
            $request->secret('password'),
            $request->string('reason') ?: null,
            $request->int('rotation_period_days')
        );
        return $this->json(['message' => 'Contrasena actualizada.', 'version' => $version]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->credentials->delete((int) $params['id'], $request->string('reason') ?: 'Sin motivo indicado');
        return $this->json(['message' => 'Credencial dada de baja.']);
    }

    public function assign(Request $request, array $params): Response
    {
        $this->credentials->assign((int) $params['id'], (int) $request->int('user_id', 0), [
            'can_view_secret'   => $request->bool('can_view_secret', true) ? 1 : 0,
            'can_copy_secret'   => $request->bool('can_copy_secret', true) ? 1 : 0,
            'can_view_recovery' => $request->bool('can_view_recovery', false) ? 1 : 0,
            'expires_at'        => $request->string('expires_at') ?: null,
        ]);
        return $this->json(['message' => 'Acceso asignado.']);
    }

    public function revoke(Request $request, array $params): Response
    {
        $this->credentials->revoke(
            (int) $params['id'],
            (int) $params['userId'],
            $request->string('reason') ?: 'Revocacion via API'
        );
        return $this->json(['message' => 'Acceso revocado.']);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $fields = ['system_id', 'name', 'environment', 'username', 'email', 'domain', 'admin_username',
                   'auth_method', 'recovery_email', 'recovery_phone', 'recovery_username', 'recovery_notes',
                   'observations', 'owner_user_id', 'status', 'rotation_period_days', 'expires_at'];
        $data   = [];
        $all    = $request->all();
        foreach ($fields as $field) {
            if (array_key_exists($field, $all)) {
                $data[$field] = in_array($field, ['system_id', 'owner_user_id', 'rotation_period_days'], true)
                    ? $request->int($field)
                    : $request->string($field);
            }
        }
        return $data;
    }
}
