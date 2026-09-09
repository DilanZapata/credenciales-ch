<?php
declare(strict_types=1);

/**
 * Arranque de la aplicacion: autocarga, configuracion, contenedor de
 * servicios y politicas de PHP en tiempo de ejecucion.
 */

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\View;
use App\Repositories\AssignmentRepository;
use App\Repositories\AuditRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\CredentialRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\ExportRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SystemRepository;
use App\Repositories\UserRepository;
use App\Services\AlertService;
use App\Services\AuditService;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\AuthService;
use App\Services\CredentialService;
use App\Services\CryptoService;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Services\MailService;
use App\Services\PasswordGeneratorService;
use App\Services\RateLimiter;
use App\Services\SessionService;
use App\Services\SettingsService;
use App\Services\TotpService;
use App\Services\UserService;

$root = dirname(__DIR__);

// --------------------------------------------------------------------
// Autocarga PSR-4 minima (App\ -> app/). Sin dependencias externas.
// --------------------------------------------------------------------
if (!defined('SCGCA_AUTOLOADER_REGISTERED')) {
define('SCGCA_AUTOLOADER_REGISTERED', true);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file     = $root . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
}

require_once $root . '/app/Support/helpers.php';

// --------------------------------------------------------------------
// Entorno y configuracion
// --------------------------------------------------------------------
Env::load($root . '/.env');
Config::loadDir($root . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'America/Bogota'));
mb_internal_encoding('UTF-8');
setlocale(LC_ALL, 'es_CO.UTF-8', 'es_ES.UTF-8', 'es_ES', 'C');

Logger::setDirectory((string) Config::get('paths.logs'));
View::setPath((string) Config::get('paths.views'));

// --------------------------------------------------------------------
// Politicas de PHP en ejecucion
// --------------------------------------------------------------------
$debug = (bool) Config::get('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // Las sesiones nativas de PHP no se usan: el sistema gestiona las suyas.
    ini_set('session.use_cookies', '0');
    // No exponer la version de PHP en las cabeceras.
    header_remove('X-Powered-By');
}

// --------------------------------------------------------------------
// Contenedor de servicios
// --------------------------------------------------------------------
$container = new Container();

$container->set(Database::class, static fn () => Database::instance());
$container->set(AuthContext::class, static fn () => new AuthContext());

$container->set(CryptoService::class, static fn (Container $c) => new CryptoService($c->get(Database::class)));
$container->set(SettingsService::class, static fn (Container $c) => new SettingsService($c->get(Database::class)));
$container->set(RateLimiter::class, static fn (Container $c) => new RateLimiter($c->get(Database::class)));
$container->set(TotpService::class, static fn () => new TotpService());
$container->set(PasswordGeneratorService::class, static fn () => new PasswordGeneratorService());

$container->set(UserRepository::class,        static fn (Container $c) => new UserRepository($c->get(Database::class)));
$container->set(CredentialRepository::class,  static fn (Container $c) => new CredentialRepository($c->get(Database::class)));
$container->set(SystemRepository::class,      static fn (Container $c) => new SystemRepository($c->get(Database::class)));
$container->set(CatalogRepository::class,     static fn (Container $c) => new CatalogRepository($c->get(Database::class)));
$container->set(AssignmentRepository::class,  static fn (Container $c) => new AssignmentRepository($c->get(Database::class)));
$container->set(AuditRepository::class,       static fn (Container $c) => new AuditRepository($c->get(Database::class)));
$container->set(DashboardRepository::class,   static fn (Container $c) => new DashboardRepository($c->get(Database::class)));
$container->set(ExportRepository::class,      static fn (Container $c) => new ExportRepository($c->get(Database::class)));
$container->set(NotificationRepository::class,static fn (Container $c) => new NotificationRepository($c->get(Database::class)));

$container->set(AuditService::class, static fn (Container $c) => new AuditService(
    $c->get(Database::class),
    $c->get(AuthContext::class)
));

$container->set(SessionService::class, static fn (Container $c) => new SessionService(
    $c->get(Database::class),
    $c->get(CryptoService::class),
    $c->get(SettingsService::class)
));

$container->set(AuthorizationService::class, static fn (Container $c) => new AuthorizationService(
    $c->get(AuthContext::class),
    $c->get(SettingsService::class),
    $c->get(AuditService::class)
));

$container->set(MailService::class, static fn (Container $c) => new MailService($c->get(SettingsService::class)));

$container->set(AuthService::class, static fn (Container $c) => new AuthService(
    $c->get(Database::class),
    $c->get(UserRepository::class),
    $c->get(CryptoService::class),
    $c->get(SessionService::class),
    $c->get(SettingsService::class),
    $c->get(AuditService::class),
    $c->get(RateLimiter::class),
    $c->get(TotpService::class),
    $c->get(AuthContext::class)
));

$container->set(CredentialService::class, static fn (Container $c) => new CredentialService(
    $c->get(Database::class),
    $c->get(CredentialRepository::class),
    $c->get(AssignmentRepository::class),
    $c->get(UserRepository::class),
    $c->get(CryptoService::class),
    $c->get(AuthorizationService::class),
    $c->get(AuthContext::class),
    $c->get(AuditService::class),
    $c->get(SettingsService::class),
    $c->get(RateLimiter::class),
    $c->get(PasswordGeneratorService::class)
));

$container->set(UserService::class, static fn (Container $c) => new UserService(
    $c->get(Database::class),
    $c->get(UserRepository::class),
    $c->get(AssignmentRepository::class),
    $c->get(SessionService::class),
    $c->get(CryptoService::class),
    $c->get(AuthService::class),
    $c->get(AuthorizationService::class),
    $c->get(AuthContext::class),
    $c->get(AuditService::class),
    $c->get(PasswordGeneratorService::class)
));

$container->set(ExportService::class, static fn (Container $c) => new ExportService(
    $c->get(CredentialRepository::class),
    $c->get(AuditRepository::class),
    $c->get(ExportRepository::class),
    $c->get(CryptoService::class),
    $c->get(AuthorizationService::class),
    $c->get(AuthContext::class),
    $c->get(AuditService::class),
    $c->get(SettingsService::class),
    $c->get(RateLimiter::class)
));

$container->set(ImportService::class, static fn (Container $c) => new ImportService(
    $c->get(Database::class),
    $c->get(CredentialService::class),
    $c->get(SystemRepository::class),
    $c->get(CatalogRepository::class),
    $c->get(AuthorizationService::class),
    $c->get(AuditService::class),
    $c->get(CryptoService::class)
));

$container->set(AlertService::class, static fn (Container $c) => new AlertService(
    $c->get(Database::class),
    $c->get(CredentialRepository::class),
    $c->get(UserRepository::class),
    $c->get(NotificationRepository::class),
    $c->get(SettingsService::class),
    $c->get(MailService::class)
));

return $container;
