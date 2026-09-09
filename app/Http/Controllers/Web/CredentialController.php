<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\AssignmentRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\CredentialRepository;
use App\Repositories\ExportRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\CredentialService;
use App\Services\SettingsService;

/** Gestion de credenciales desde la interfaz web. */
final class CredentialController extends Controller
{
    private const STATUSES     = ['active', 'inactive', 'expired', 'revoked', 'archived'];
    private const ENVIRONMENTS = ['production', 'staging', 'development', 'other'];

    public function __construct(
        private CredentialService $credentials,
        private CredentialRepository $repository,
        private SystemRepository $systems,
        private CatalogRepository $catalog,
        private UserRepository $users,
        private AssignmentRepository $assignments,
        private ExportRepository $exports,
        private AuthorizationService $gate,
        private AuthContext $context,
        private SettingsService $settings
    ) {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request);
        $filters = $this->filtersFrom($request);
        $result  = $this->credentials->list($filters, $page, $perPage);

        return $this->view('credentials/index', [
            'pageTitle'   => 'Credenciales',
            'result'      => $result,
            'filters'     => $filters,
            'categories'  => $this->catalog->categories(),
            'systems'     => $this->systems->selectList(),
            'companies'   => $this->catalog->companies(),
            'locations'   => $this->catalog->locations(),
            'departments' => $this->catalog->departments(),
            'usersList'   => $this->gate->can('users.view') ? $this->users->activeSelectList() : [],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $id         = (int) $params['id'];
        $credential = $this->credentials->show($id);

        $canManage = $this->gate->can('credentials.assign') || $this->gate->can('credentials.view_all');

        return $this->view('credentials/show', [
            'pageTitle'    => $credential['name'],
            'credential'   => $credential,
            'assignments'  => $canManage ? $this->assignments->forCredential($id) : [],
            'usersList'    => $this->gate->can('credentials.assign') ? $this->users->activeSelectList() : [],
            'history'      => $this->gate->can('history.view') ? $this->repository->history(['credential_id' => $id], 15) : [],
            'secretMeta'   => $this->gate->can('history.view') ? $this->repository->secretHistoryMeta($id) : [],
            'exportTrace'  => $this->gate->can('audit.view') ? $this->exports->exportsContaining($id, 10) : [],
            'accessTrace'  => $this->gate->can('audit.view') ? $this->repository->secretAccessHistory($id, 15) : [],
        ]);
    }

    public function history(Request $request, array $params): Response
    {
        $id   = (int) $params['id'];
        $data = $this->credentials->history($id);
        $meta = $this->credentials->show($id, false);

        return $this->view('credentials/history', [
            'pageTitle'  => 'Historial - ' . $meta['name'],
            'credential' => $meta,
            'versions'   => $data['secret_versions'],
            'changes'    => $data['changes'],
            'access'     => $data['secret_access'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->gate->require('credentials.create');
        return $this->view('credentials/form', [
            'pageTitle'   => 'Nueva credencial',
            'credential'  => null,
            'systems'     => $this->systems->selectList(),
            'usersList'   => $this->users->activeSelectList(),
            'statuses'    => self::STATUSES,
            'environments'=> self::ENVIRONMENTS,
            'defaultRotation' => $this->settings->int('credentials.default_rotation_days', 90),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->gate->require('credentials.create');
        $data = $this->validatePayload($request);
        $id   = $this->credentials->create($data, $request->secret('password'));

        $this->success('Credencial registrada correctamente.');
        return $this->redirect('/credenciales/' . $id);
    }

    public function edit(Request $request, array $params): Response
    {
        $this->gate->require('credentials.update');
        $id  = (int) $params['id'];
        $row = $this->repository->find($id, null, true);
        if ($row === null) {
            return $this->redirect('/credenciales');
        }
        return $this->view('credentials/form', [
            'pageTitle'   => 'Editar credencial',
            'credential'  => $row,
            'systems'     => $this->systems->selectList(),
            'usersList'   => $this->users->activeSelectList(),
            'statuses'    => self::STATUSES,
            'environments'=> self::ENVIRONMENTS,
            'defaultRotation' => $this->settings->int('credentials.default_rotation_days', 90),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $id   = (int) $params['id'];
        $data = $this->validatePayload($request, false);
        $this->credentials->update($id, $data);
        $this->success('Credencial actualizada.');
        return $this->redirect('/credenciales/' . $id);
    }

    public function rotate(Request $request, array $params): Response
    {
        $id      = (int) $params['id'];
        $version = $this->credentials->rotatePassword(
            $id,
            $request->secret('password'),
            $request->string('reason') ?: null,
            $request->int('rotation_period_days')
        );
        $this->success('Contrasena actualizada (version ' . $version . ').');
        return $this->redirect('/credenciales/' . $id);
    }

    public function destroy(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $this->credentials->delete($id, $request->string('reason') ?: 'Sin motivo indicado');
        $this->success('Credencial dada de baja. El historial se conserva.');
        return $this->redirect('/credenciales');
    }

    public function restore(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $this->credentials->restore($id);
        $this->success('Credencial reactivada.');
        return $this->redirect('/credenciales/' . $id);
    }

    public function assign(Request $request, array $params): Response
    {
        $id     = (int) $params['id'];
        $userId = (int) $request->int('user_id', 0);
        $this->credentials->assign($id, $userId, [
            'can_view_secret'   => $request->bool('can_view_secret', true) ? 1 : 0,
            'can_copy_secret'   => $request->bool('can_copy_secret', true) ? 1 : 0,
            'can_view_recovery' => $request->bool('can_view_recovery', false) ? 1 : 0,
            'expires_at'        => $request->string('expires_at') ?: null,
            'reason'            => $request->string('reason') ?: null,
        ]);
        $this->success('Acceso asignado.');
        return $this->redirect('/credenciales/' . $id);
    }

    public function revoke(Request $request, array $params): Response
    {
        $id     = (int) $params['id'];
        $userId = (int) ($params['userId'] ?? $request->int('user_id', 0));
        $this->credentials->revoke($id, $userId, $request->string('reason') ?: 'Revocacion administrativa');
        $this->success('Acceso revocado.');
        return $this->redirect('/credenciales/' . $id);
    }

    // ---------------------------------------------------------------

    private function filtersFrom(Request $request): array
    {
        return array_filter([
            'search'              => $request->string('q'),
            'category_id'         => $request->int('category_id'),
            'system_id'           => $request->int('system_id'),
            'company_id'          => $request->int('company_id'),
            'location_id'         => $request->int('location_id'),
            'department_id'       => $request->int('department_id'),
            'owner_user_id'       => $request->int('owner_user_id'),
            'assigned_user_id'    => $request->int('assigned_user_id'),
            'resource_type'       => $request->string('resource_type'),
            'status'              => in_array($request->string('status'), self::STATUSES, true) ? $request->string('status') : null,
            'expiring_days'       => $request->int('expiring_days'),
            'expired'             => $request->bool('expired') ? 1 : null,
            'never_rotated'       => $request->bool('never_rotated') ? 1 : null,
            'without_owner'       => $request->bool('without_owner') ? 1 : null,
            'without_assignments' => $request->bool('without_assignments') ? 1 : null,
            'sort'                => $request->string('sort'),
            'direction'           => $request->string('direction'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    }

    private function validatePayload(Request $request, bool $requireSystem = true): array
    {
        $data = $request->all();

        $validator = Validator::make($data)
            ->integer('system_id', 'El sistema', 1, null, $requireSystem)
            ->string('name', 'El nombre de la credencial', 2, 180)
            ->in('environment', 'El entorno', self::ENVIRONMENTS, false)
            ->string('username', 'El usuario', 0, 190, false)
            ->email('email', 'El correo electronico', false)
            ->string('domain', 'El dominio', 0, 190, false)
            ->string('admin_username', 'El usuario administrador', 0, 190, false)
            ->string('auth_method', 'El metodo de autenticacion', 0, 80, false)
            ->email('recovery_email', 'El correo de recuperacion', false)
            ->phone('recovery_phone', 'El telefono de recuperacion', false)
            ->string('recovery_username', 'El usuario de recuperacion', 0, 190, false)
            ->text('recovery_notes', 'La informacion de recuperacion', 3000, false)
            ->text('observations', 'Las observaciones', 3000, false)
            ->integer('owner_user_id', 'El responsable', 1, null, false)
            ->in('status', 'El estado', self::STATUSES, false)
            ->integer('rotation_period_days', 'El periodo de rotacion', 0, 3650, false)
            ->date('expires_at', 'La fecha de vencimiento', false)
            ->bool('has_security_questions');

        $clean = $validator->validated();

        // Solo se propagan las claves realmente presentes en la peticion.
        $out = [];
        foreach ($clean as $key => $value) {
            if (array_key_exists($key, $data) || $key === 'has_security_questions') {
                $out[$key] = $value;
            }
        }
        if (isset($out['has_security_questions'])) {
            $out['has_security_questions'] = $out['has_security_questions'] ? 1 : 0;
        }
        if (isset($out['status']) && $out['status'] === null) {
            unset($out['status']);
        }
        if (isset($out['environment']) && $out['environment'] === null) {
            unset($out['environment']);
        }
        if ($request->string('change_reason') !== '') {
            $out['change_reason'] = $request->string('change_reason');
        }
        return $out;
    }
}
