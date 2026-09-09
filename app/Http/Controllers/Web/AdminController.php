<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\CatalogRepository;
use App\Services\AuditService;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\SettingsService;

/** Administracion: catalogos, estructura organizacional, roles y politicas. */
final class AdminController extends Controller
{
    public function __construct(
        private CatalogRepository $catalog,
        private SettingsService $settings,
        private AuthorizationService $gate,
        private AuthContext $context,
        private AuditService $audit
    ) {
    }

    // -------------------------- Categorias ---------------------------
    public function categories(Request $request): Response
    {
        $this->gate->require('categories.manage');
        return $this->view('admin/categories', [
            'pageTitle'  => 'Categorias',
            'categories' => $this->catalog->categories(false),
        ]);
    }

    public function saveCategory(Request $request): Response
    {
        $this->gate->require('categories.manage');
        $data = Validator::make($request->all())
            ->string('name', 'El nombre', 2, 120)
            ->string('description', 'La descripcion', 0, 255, false)
            ->string('color', 'El color', 0, 9, false)
            ->string('icon', 'El icono', 0, 40, false)
            ->integer('sort_order', 'El orden', 0, 999, false)
            ->bool('is_active')
            ->validated();

        $data['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($data['color'] ?? '')) === 1 ? $data['color'] : '#64748b';
        $data['icon']  = preg_match('/^[a-z\-]{1,40}$/', (string) ($data['icon'] ?? '')) === 1 ? $data['icon'] : 'folder';

        $id = $request->int('id');
        if ($id !== null && $id > 0) {
            $this->catalog->updateCategory($id, $data);
            $action = 'actualizada';
        } else {
            $id     = $this->catalog->createCategory($data);
            $action = 'creada';
        }
        $this->audit->log(AuditService::CATEGORY_MANAGED, 'category', $id, (string) $data['name'], 'success',
            ['accion' => $action], 'notice');
        $this->success('Categoria ' . $action . '.');
        return $this->redirect('/admin/categorias');
    }

    // ------------------------- Organizacion --------------------------
    public function organization(Request $request): Response
    {
        $this->gate->require('org.manage');
        return $this->view('admin/organization', [
            'pageTitle'   => 'Empresas, sedes y departamentos',
            'companies'   => $this->catalog->companies(false),
            'locations'   => $this->catalog->locations(null, false),
            'departments' => $this->catalog->departments(null, false),
        ]);
    }

    public function saveCompany(Request $request): Response
    {
        $this->gate->require('org.manage');
        $data = Validator::make($request->all())
            ->string('name', 'El nombre', 2, 150)
            ->string('legal_name', 'La razon social', 0, 200, false)
            ->string('tax_id', 'El NIT', 0, 50, false)
            ->bool('is_active')
            ->validated();

        $id = $request->int('id');
        if ($id !== null && $id > 0) {
            $this->catalog->updateCompany($id, $data);
        } else {
            $id = $this->catalog->createCompany($data);
        }
        $this->audit->log(AuditService::ORG_MANAGED, 'company', $id, (string) $data['name'], 'success', [], 'notice');
        $this->success('Empresa guardada.');
        return $this->redirect('/admin/organizacion');
    }

    public function saveLocation(Request $request): Response
    {
        $this->gate->require('org.manage');
        $data = Validator::make($request->all())
            ->integer('company_id', 'La empresa', 1)
            ->string('name', 'El nombre', 2, 150)
            ->string('address', 'La direccion', 0, 255, false)
            ->string('city', 'La ciudad', 0, 100, false)
            ->string('country', 'El pais', 0, 100, false)
            ->bool('is_active')
            ->validated();

        $id = $request->int('id');
        if ($id !== null && $id > 0) {
            $this->catalog->updateLocation($id, $data);
        } else {
            $id = $this->catalog->createLocation($data);
        }
        $this->audit->log(AuditService::ORG_MANAGED, 'location', $id, (string) $data['name'], 'success', [], 'notice');
        $this->success('Sede guardada.');
        return $this->redirect('/admin/organizacion');
    }

    public function saveDepartment(Request $request): Response
    {
        $this->gate->require('org.manage');
        $data = Validator::make($request->all())
            ->integer('company_id', 'La empresa', 1)
            ->integer('location_id', 'La sede', 1, null, false)
            ->string('name', 'El nombre', 2, 150)
            ->string('code', 'El codigo', 0, 40, false)
            ->bool('is_active')
            ->validated();

        $id = $request->int('id');
        if ($id !== null && $id > 0) {
            $this->catalog->updateDepartment($id, $data);
        } else {
            $id = $this->catalog->createDepartment($data);
        }
        $this->audit->log(AuditService::ORG_MANAGED, 'department', $id, (string) $data['name'], 'success', [], 'notice');
        $this->success('Departamento guardado.');
        return $this->redirect('/admin/organizacion');
    }

    // ---------------------------- Roles ------------------------------
    public function roles(Request $request): Response
    {
        $this->gate->require('roles.view');
        $roles       = $this->catalog->roles();
        $permissions = $this->catalog->permissions();
        $matrix      = [];
        foreach ($roles as $role) {
            $matrix[(int) $role['id']] = $this->catalog->rolePermissionCodes((int) $role['id']);
        }
        return $this->view('admin/roles', [
            'pageTitle'   => 'Roles y permisos',
            'roles'       => $roles,
            'permissions' => $permissions,
            'matrix'      => $matrix,
            'canManage'   => $this->gate->can('roles.manage'),
        ]);
    }

    public function saveRole(Request $request): Response
    {
        $this->gate->require('roles.manage');
        $data = Validator::make($request->all())
            ->string('name', 'El nombre', 2, 80)
            ->string('code', 'El codigo', 2, 40, false)
            ->string('description', 'La descripcion', 0, 255, false)
            ->integer('level', 'El nivel', 1, 99)
            ->bool('requires_mfa')
            ->bool('is_active')
            ->validated();

        // Nadie puede crear un rol de nivel igual o superior al suyo.
        if (!$this->context->isSuperAdmin() && (int) $data['level'] >= $this->context->level()) {
            throw HttpException::forbidden('No puede crear roles de nivel igual o superior al suyo.');
        }

        $id = $request->int('id');
        if ($id !== null && $id > 0) {
            $role = $this->catalog->findRole($id);
            if ($role === null) {
                throw HttpException::notFound('El rol no existe.');
            }
            $this->catalog->updateRole($id, $data);
        } else {
            if (($data['code'] ?? '') === '') {
                throw HttpException::badRequest('Debe indicar el codigo del rol.');
            }
            $id = $this->catalog->createRole($data);
        }
        $this->audit->log(AuditService::ROLE_MANAGED, 'role', $id, (string) $data['name'], 'success', [], 'warning');
        $this->success('Rol guardado.');
        return $this->redirect('/admin/roles');
    }

    public function saveRolePermissions(Request $request, array $params): Response
    {
        $this->gate->require('roles.manage');
        $roleId = (int) $params['id'];
        $role   = $this->catalog->findRole($roleId);
        if ($role === null) {
            throw HttpException::notFound('El rol no existe.');
        }
        if (!$this->context->isSuperAdmin()) {
            if ((int) $role['level'] >= $this->context->level()) {
                throw HttpException::forbidden('No puede modificar un rol de nivel igual o superior al suyo.');
            }
            // Ni conceder a un rol permisos que uno mismo no posee.
            foreach ($request->arrayOfStrings('permissions') as $code) {
                if (!$this->context->can($code)) {
                    throw HttpException::forbidden('No puede otorgar el permiso: ' . $code);
                }
            }
        }

        $before = $this->catalog->rolePermissionCodes($roleId);
        $this->catalog->setRolePermissions($roleId, $request->arrayOfStrings('permissions'));
        $after  = $this->catalog->rolePermissionCodes($roleId);

        $this->audit->log(AuditService::ROLE_MANAGED, 'role', $roleId, (string) $role['name'], 'success', [
            'otorgados' => array_values(array_diff($after, $before)),
            'retirados' => array_values(array_diff($before, $after)),
        ], 'critical');
        $this->success('Matriz de permisos actualizada.');
        return $this->redirect('/admin/roles');
    }

    // ------------------------- Configuracion --------------------------
    public function settings(Request $request): Response
    {
        $this->gate->require('settings.manage');
        $grouped = [];
        foreach ($this->settings->grouped() as $row) {
            $grouped[(string) $row['group_name']][] = $row;
        }
        return $this->view('admin/settings', [
            'pageTitle' => 'Configuracion y politicas de seguridad',
            'grouped'   => $grouped,
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $this->gate->require('settings.manage');
        $this->gate->requireStepUp('secret');

        $changed = [];
        foreach ($this->settings->grouped() as $row) {
            $key = (string) $row['setting_key'];
            if (!array_key_exists($key, $request->all())) {
                // Las casillas no marcadas no llegan en la peticion.
                if ($row['value_type'] === 'bool') {
                    $value = '0';
                    if ((string) $row['setting_value'] !== $value) {
                        $this->settings->set($key, $value, $this->context->id());
                        $changed[$key] = $value;
                    }
                }
                continue;
            }
            $raw = (string) $request->input($key, '');
            $value = match ((string) $row['value_type']) {
                'int'  => (string) max(0, (int) $raw),
                'bool' => in_array(strtolower($raw), ['1', 'true', 'on', 'yes', 'si'], true) ? '1' : '0',
                default => mb_substr($raw, 0, 500),
            };
            if ($value !== (string) $row['setting_value']) {
                $this->settings->set($key, $value, $this->context->id());
                $changed[$key] = $value;
            }
        }

        if ($changed !== []) {
            $this->audit->log(AuditService::SETTINGS_UPDATED, 'settings', null, 'Politicas de seguridad', 'success',
                ['cambios' => $changed], 'critical');
        }
        $this->success('Configuracion actualizada.');
        return $this->redirect('/admin/configuracion');
    }
}
