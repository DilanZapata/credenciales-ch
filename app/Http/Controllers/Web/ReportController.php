<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\CatalogRepository;
use App\Repositories\CredentialRepository;
use App\Repositories\ExportRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;
use App\Services\AuthorizationService;
use App\Services\ExportService;

/** Modulo de reportes y exportacion a Excel (art. 33 a 42). */
final class ReportController extends Controller
{
    public function __construct(
        private ExportService $exports,
        private ExportRepository $exportRepo,
        private CredentialRepository $credentials,
        private SystemRepository $systems,
        private CatalogRepository $catalog,
        private UserRepository $users,
        private AuthorizationService $gate
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->require('reports.view');
        return $this->view('reports/index', [
            'pageTitle'    => 'Reportes',
            'categories'   => $this->catalog->categories(),
            'systems'      => $this->systems->selectList(),
            'companies'    => $this->catalog->companies(),
            'locations'    => $this->catalog->locations(),
            'departments'  => $this->catalog->departments(),
            'usersList'    => $this->users->activeSelectList(),
            'canExportSecrets' => $this->gate->can('export.credentials.secrets'),
            'canExportHistory' => $this->gate->can('export.history'),
            'canExportAudit'   => $this->gate->can('audit.export'),
        ]);
    }

    /** Seleccion manual de credenciales para exportar (art. 35). */
    public function picker(Request $request): Response
    {
        $this->gate->require('reports.view');
        $filters = array_filter([
            'search'      => $request->string('q'),
            'category_id' => $request->int('category_id'),
            'system_id'   => $request->int('system_id'),
            'company_id'  => $request->int('company_id'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);

        $result = $this->credentials->paginate($filters, 1, 200, $this->gate->credentialScopeUserId());
        return $this->json([
            'items' => array_map(static fn (array $row): array => [
                'id'       => (int) $row['id'],
                'name'     => $row['name'],
                'system'   => $row['system_name'],
                'category' => $row['category_name'],
                'username' => $row['username'],
            ], $result['items']),
            'total' => $result['total'],
        ]);
    }

    public function generate(Request $request): Response
    {
        $result = $this->exports->generate([
            'type'            => $request->string('type', 'inventory'),
            'include_secrets' => $request->bool('include_secrets'),
            'confirm'         => $request->bool('confirm'),
            'ids'             => $request->arrayOfInts('ids'),
            'filters'         => array_filter([
                'search'              => $request->string('q'),
                'category_id'         => $request->int('category_id'),
                'system_id'           => $request->int('system_id'),
                'company_id'          => $request->int('company_id'),
                'location_id'         => $request->int('location_id'),
                'department_id'       => $request->int('department_id'),
                'owner_user_id'       => $request->int('owner_user_id'),
                'assigned_user_id'    => $request->int('assigned_user_id'),
                'status'              => $request->string('status'),
                'resource_type'       => $request->string('resource_type'),
                'expired'             => $request->bool('expired') ? 1 : null,
                'expiring_days'       => $request->int('expiring_days'),
                'never_rotated'       => $request->bool('never_rotated') ? 1 : null,
                'without_owner'       => $request->bool('without_owner') ? 1 : null,
                'without_assignments' => $request->bool('without_assignments') ? 1 : null,
                'date_from'           => $request->string('date_from'),
                'date_to'             => $request->string('date_to'),
                'action'              => $request->string('action'),
                'user_id'             => $request->int('user_id'),
                'result'              => $request->string('result'),
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 0),
        ]);

        if ($request->wantsJson()) {
            return $this->json([
                'uuid'         => $result['uuid'],
                'record_count' => $result['record_count'],
                'download_url' => $this->url('/reportes/descargar/' . $result['uuid']),
            ]);
        }

        $this->flash('export_ready', $result);
        $this->success(sprintf(
            'Reporte generado con %d registro(s).%s',
            $result['record_count'],
            $result['included_secrets'] ? ' ATENCION: contiene contrasenas reales.' : ''
        ));
        return $this->redirect('/reportes/descargar/' . $result['uuid']);
    }

    public function download(Request $request, array $params): Response
    {
        $uuid = (string) $params['uuid'];
        if (preg_match('/^[a-f0-9\-]{36}$/', $uuid) !== 1) {
            return $this->redirect('/reportes');
        }
        $file = $this->exports->download($uuid);

        return Response::download(
            $file['path'],
            $file['file_name'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            true // el archivo se elimina del servidor inmediatamente tras enviarlo
        );
    }

    /** Bitacora de exportaciones (art. 38). */
    public function history(Request $request): Response
    {
        $this->gate->requireAny(['audit.view', 'reports.view']);
        [$page, $perPage] = $this->pagination($request, 30);
        $filters = array_filter([
            'user_id'          => $request->int('user_id'),
            'report_type'      => $request->string('report_type'),
            'included_secrets' => $request->string('included_secrets'),
            'date_from'        => $request->string('date_from'),
            'date_to'          => $request->string('date_to'),
        ], static fn ($v) => $v !== null && $v !== '');

        $result = $this->exportRepo->paginate($filters, $page, $perPage);

        return $this->view('reports/history', [
            'pageTitle' => 'Historial de exportaciones',
            'result'    => $result,
            'page'      => $page,
            'perPage'   => $perPage,
            'pages'     => (int) ceil($result['total'] / $perPage),
            'filters'   => $filters,
            'usersList' => $this->users->activeSelectList(),
        ]);
    }
}
