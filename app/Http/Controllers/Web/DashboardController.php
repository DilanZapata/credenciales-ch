<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\AssignmentRepository;
use App\Repositories\AuditRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;
use App\Services\AlertService;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\SessionService;
use App\Services\SettingsService;

/** Tableros: vista administrativa completa y vista simple del consultor. */
final class DashboardController extends Controller
{
    public function __construct(
        private DashboardRepository $dashboard,
        private UserRepository $users,
        private SystemRepository $systems,
        private AssignmentRepository $assignments,
        private AuditRepository $audit,
        private AlertService $alerts,
        private SessionService $sessions,
        private SettingsService $settings,
        private AuthorizationService $gate,
        private AuthContext $context
    ) {
    }

    public function index(Request $request): Response
    {
        // El consultor no ve indicadores globales: solo sus accesos.
        if (!$this->gate->can('credentials.view_all')) {
            return $this->redirect('/mis-accesos');
        }

        $warningDays = $this->settings->int('alerts.expiry_warning_days', 15);

        return $this->view('dashboard/admin', [
            'pageTitle'        => 'Panel de control',
            'credentialStats'  => $this->dashboard->credentialCounters($warningDays),
            'userStats'        => $this->users->counters(),
            'systemStats'      => $this->dashboard->systemCounters(),
            'byCategory'       => $this->dashboard->credentialsByCategory(),
            'expiring'         => $this->dashboard->expiringCredentials(max(30, $warningDays), 12),
            'recentChanges'    => $this->dashboard->recentCredentialChanges(8),
            'recentSecrets'    => $this->gate->can('audit.view') ? $this->audit->recentSecretAccess(8) : [],
            'recentExports'    => $this->gate->can('audit.view') ? $this->dashboard->recentExports(6) : [],
            'recentLogins'     => $this->gate->can('audit.view') ? $this->audit->recentLoginAttempts(8) : [],
            'alerts'           => $this->alerts->evaluate(),
            'securityEvents'   => $this->gate->can('security.events.view')
                                    ? $this->audit->securityEvents(['status' => 'open'], 6) : [],
            'topUsers'         => $this->assignments->topUsersByAccess(6),
            'activeSessions'   => $this->sessions->activeCount(),
            'failedLogins24h'  => $this->audit->failedLoginsSince(date('Y-m-d H:i:s', time() - 86400)),
            'accessSeries'     => $this->dashboard->secretAccessSeries(14),
        ]);
    }

    /** Panel del consultor (art. 13): sencillo y centrado en "mis accesos". */
    public function myAccess(Request $request): Response
    {
        $userId = (int) $this->context->id();
        $search = $request->string('q');

        $items = $this->assignments->forUser($userId, true);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items  = array_values(array_filter($items, static function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower((string) $row['system_name']), $needle)
                    || str_contains(mb_strtolower((string) $row['credential_name']), $needle)
                    || str_contains(mb_strtolower((string) ($row['username'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($row['category_name'] ?? '')), $needle);
            }));
        }

        return $this->view('dashboard/consultant', [
            'pageTitle' => 'Mis accesos',
            'items'     => $items,
            'search'    => $search,
        ]);
    }
}
