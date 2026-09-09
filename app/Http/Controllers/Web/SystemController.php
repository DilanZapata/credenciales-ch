<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\CatalogRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthContext;
use App\Services\AuthorizationService;

/** Inventario de sistemas y recursos. */
final class SystemController extends Controller
{
    private const TYPES = ['web', 'application', 'software', 'computer', 'server', 'email', 'network',
                           'cloud', 'social', 'banking', 'license', 'database', 'other'];
    private const CRITICALITY = ['low', 'medium', 'high', 'critical'];
    private const STATUSES    = ['active', 'inactive', 'archived'];

    public function __construct(
        private SystemRepository $systems,
        private CatalogRepository $catalog,
        private UserRepository $users,
        private AuthorizationService $gate,
        private AuthContext $context,
        private AuditService $audit
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->require('systems.view');
        [$page, $perPage] = $this->pagination($request);

        $filters = array_filter([
            'search'        => $request->string('q'),
            'category_id'   => $request->int('category_id'),
            'company_id'    => $request->int('company_id'),
            'location_id'   => $request->int('location_id'),
            'department_id' => $request->int('department_id'),
            'resource_type' => in_array($request->string('resource_type'), self::TYPES, true) ? $request->string('resource_type') : null,
            'status'        => in_array($request->string('status'), self::STATUSES, true) ? $request->string('status') : null,
            'without_owner' => $request->bool('without_owner') ? 1 : null,
            'sort'          => $request->string('sort'),
            'direction'     => $request->string('direction'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);

        $result = $this->systems->paginate($filters, $page, $perPage);

        return $this->view('systems/index', [
            'pageTitle'   => 'Sistemas',
            'result'      => $result,
            'page'        => $page,
            'perPage'     => $perPage,
            'pages'       => (int) ceil($result['total'] / $perPage),
            'filters'     => $filters,
            'categories'  => $this->catalog->categories(),
            'companies'   => $this->catalog->companies(),
            'locations'   => $this->catalog->locations(),
            'departments' => $this->catalog->departments(),
            'types'       => self::TYPES,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $this->gate->require('systems.view');
        $system = $this->systems->find((int) $params['id']);
        if ($system === null) {
            throw HttpException::notFound('El sistema no existe.');
        }
        return $this->view('systems/show', ['pageTitle' => $system['name'], 'system' => $system]);
    }

    public function create(Request $request): Response
    {
        $this->gate->require('systems.create');
        return $this->form(null);
    }

    public function edit(Request $request, array $params): Response
    {
        $this->gate->require('systems.update');
        $system = $this->systems->find((int) $params['id']);
        if ($system === null) {
            throw HttpException::notFound('El sistema no existe.');
        }
        return $this->form($system);
    }

    public function store(Request $request): Response
    {
        $this->gate->require('systems.create');
        $data = $this->validatePayload($request);
        $data['created_by'] = $this->context->id();
        $id = $this->systems->create($data);
        $this->audit->log(AuditService::SYSTEM_CREATED, 'system', $id, (string) $data['name'], 'success', [], 'notice');
        $this->success('Sistema registrado.');
        return $this->redirect('/sistemas/' . $id);
    }

    public function update(Request $request, array $params): Response
    {
        $this->gate->require('systems.update');
        $id     = (int) $params['id'];
        $before = $this->systems->find($id);
        if ($before === null) {
            throw HttpException::notFound('El sistema no existe.');
        }
        $data = $this->validatePayload($request);
        $this->systems->update($id, $data, $this->context->id());
        $this->audit->log(AuditService::SYSTEM_UPDATED, 'system', $id, (string) $before['name'], 'success',
            ['campos' => array_keys($data)], 'notice');
        $this->success('Sistema actualizado.');
        return $this->redirect('/sistemas/' . $id);
    }

    public function archive(Request $request, array $params): Response
    {
        $this->gate->require('systems.delete');
        $id     = (int) $params['id'];
        $system = $this->systems->find($id);
        if ($system === null) {
            throw HttpException::notFound('El sistema no existe.');
        }
        $this->systems->archive($id, $this->context->id());
        $this->audit->log(AuditService::SYSTEM_DELETED, 'system', $id, (string) $system['name'], 'success',
            ['motivo' => $request->string('reason')], 'warning');
        $this->success('Sistema archivado. Sus credenciales conservan el historial.');
        return $this->redirect('/sistemas');
    }

    private function form(?array $system): Response
    {
        return $this->view('systems/form', [
            'pageTitle'   => $system === null ? 'Nuevo sistema' : 'Editar sistema',
            'system'      => $system,
            'categories'  => $this->catalog->categories(false),
            'companies'   => $this->catalog->companies(false),
            'locations'   => $this->catalog->locations(null, false),
            'departments' => $this->catalog->departments(null, false),
            'usersList'   => $this->users->activeSelectList(),
            'types'       => self::TYPES,
            'criticality' => self::CRITICALITY,
            'statuses'    => self::STATUSES,
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return Validator::make($request->all())
            ->string('name', 'El nombre del sistema', 2, 180)
            ->string('display_name', 'El nombre descriptivo', 0, 180, false)
            ->string('code', 'El codigo', 0, 60, false)
            ->text('description', 'La descripcion', 3000, false)
            ->integer('category_id', 'La categoria', 1, null, false)
            ->in('resource_type', 'El tipo de recurso', self::TYPES, false)
            ->integer('company_id', 'La empresa', 1, null, false)
            ->integer('location_id', 'La sede', 1, null, false)
            ->integer('department_id', 'El departamento', 1, null, false)
            ->url('url', 'La URL', false)
            ->ip('ip_address', 'La direccion IP', false)
            ->integer('port', 'El puerto', 1, 65535, false)
            ->string('hostname', 'El nombre del servidor', 0, 180, false)
            ->string('platform', 'La plataforma', 0, 120, false)
            ->string('provider', 'El proveedor', 0, 120, false)
            ->integer('owner_user_id', 'El responsable', 1, null, false)
            ->in('criticality', 'La criticidad', self::CRITICALITY, false)
            ->in('status', 'El estado', self::STATUSES, false)
            ->validated();
    }
}
