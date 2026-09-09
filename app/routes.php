<?php
declare(strict_types=1);

/**
 * Definicion de rutas.
 *
 * Cada ruta declara explicitamente su cadena de middleware. El permiso
 * indicado con perm:<codigo> es una PRIMERA barrera; el servicio vuelve a
 * comprobarlo (defensa en profundidad). Ninguna ruta administrativa es
 * accesible sin 'auth'.
 */

use App\Core\Router;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\CredentialApiController;
use App\Http\Controllers\Api\SecretController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Controllers\Web\AdminController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CredentialController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\SystemController;
use App\Http\Controllers\Web\UserController;

$router = new Router();

$web  = ['security', 'throttle:global'];
$auth = array_merge($web, ['auth', 'csrf']);
$api  = ['security', 'cors', 'throttle:api', 'auth', 'csrf'];

// =====================================================================
//  ACCESO (publico)
// =====================================================================
$router->group('', array_merge($web, ['guest']), static function (Router $r): void {
    $r->get('/entrar',              [AuthController::class, 'showLogin']);
    $r->get('/recuperar',           [AuthController::class, 'showForgot']);
    $r->get('/restablecer/{token:[a-f0-9]{64}}', [AuthController::class, 'showReset']);
});
$router->group('', array_merge($web, ['guest', 'csrf']), static function (Router $r): void {
    $r->post('/entrar',       [AuthController::class, 'login']);
    $r->post('/recuperar',    [AuthController::class, 'sendReset']);
    $r->post('/restablecer',  [AuthController::class, 'doReset']);
});

// MFA: la sesion existe pero esta pendiente de verificacion.
$router->group('', $web, static function (Router $r): void {
    $r->get('/mfa', [AuthController::class, 'showMfa']);
});
$router->group('', array_merge($web, ['csrf']), static function (Router $r): void {
    $r->post('/mfa/verificar', [AuthController::class, 'verifyMfa']);
});

// =====================================================================
//  APLICACION (requiere sesion)
// =====================================================================
$router->group('', $auth, static function (Router $r): void {

    $r->post('/salir', [AuthController::class, 'logout']);

    // ---------------------------- Tableros ---------------------------
    $r->get('/',             [DashboardController::class, 'index'],     ['perm:dashboard.view']);
    $r->get('/mis-accesos',  [DashboardController::class, 'myAccess'],  ['perm:credentials.view']);

    // ---------------------------- Perfil -----------------------------
    $r->get('/perfil',                  [ProfileController::class, 'index']);
    $r->get('/perfil/contrasena',       [ProfileController::class, 'passwordForm']);
    $r->post('/perfil/contrasena',      [ProfileController::class, 'changePassword']);
    $r->get('/perfil/mfa',              [ProfileController::class, 'mfa']);
    $r->post('/perfil/mfa/iniciar',     [ProfileController::class, 'beginMfa']);
    $r->post('/perfil/mfa/confirmar',   [ProfileController::class, 'confirmMfa']);
    $r->post('/perfil/mfa/desactivar',  [ProfileController::class, 'disableMfa']);
    $r->get('/notificaciones',          [ProfileController::class, 'notifications'], ['perm:notifications.view']);
    $r->post('/notificaciones/{id:\d+}/leida', [ProfileController::class, 'markNotificationRead']);

    // -------------------------- Credenciales -------------------------
    $r->get('/credenciales',                    [CredentialController::class, 'index'],   ['perm:credentials.view']);
    $r->get('/credenciales/nueva',              [CredentialController::class, 'create'],  ['perm:credentials.create']);
    $r->post('/credenciales',                   [CredentialController::class, 'store'],   ['perm:credentials.create']);
    $r->get('/credenciales/{id:\d+}',           [CredentialController::class, 'show'],    ['perm:credentials.view']);
    $r->get('/credenciales/{id:\d+}/editar',    [CredentialController::class, 'edit'],    ['perm:credentials.update']);
    $r->post('/credenciales/{id:\d+}',          [CredentialController::class, 'update'],  ['perm:credentials.update']);
    $r->get('/credenciales/{id:\d+}/historial', [CredentialController::class, 'history'], ['perm:history.view']);
    $r->post('/credenciales/{id:\d+}/rotar',    [CredentialController::class, 'rotate'],  ['perm:credentials.rotate']);
    $r->post('/credenciales/{id:\d+}/eliminar', [CredentialController::class, 'destroy'], ['perm:credentials.delete']);
    $r->post('/credenciales/{id:\d+}/restaurar',[CredentialController::class, 'restore'], ['perm:credentials.delete']);
    $r->post('/credenciales/{id:\d+}/asignar',  [CredentialController::class, 'assign'],  ['perm:credentials.assign']);
    $r->post('/credenciales/{id:\d+}/revocar/{userId:\d+}', [CredentialController::class, 'revoke'], ['perm:credentials.revoke']);

    // --------------------------- Sistemas ----------------------------
    $r->get('/sistemas',                 [SystemController::class, 'index'],   ['perm:systems.view']);
    $r->get('/sistemas/nuevo',           [SystemController::class, 'create'],  ['perm:systems.create']);
    $r->post('/sistemas',                [SystemController::class, 'store'],   ['perm:systems.create']);
    $r->get('/sistemas/{id:\d+}',        [SystemController::class, 'show'],    ['perm:systems.view']);
    $r->get('/sistemas/{id:\d+}/editar', [SystemController::class, 'edit'],    ['perm:systems.update']);
    $r->post('/sistemas/{id:\d+}',       [SystemController::class, 'update'],  ['perm:systems.update']);
    $r->post('/sistemas/{id:\d+}/archivar', [SystemController::class, 'archive'], ['perm:systems.delete']);

    // ---------------------------- Usuarios ---------------------------
    $r->get('/usuarios',                       [UserController::class, 'index'],       ['perm:users.view']);
    $r->get('/usuarios/nuevo',                 [UserController::class, 'create'],      ['perm:users.create']);
    $r->post('/usuarios',                      [UserController::class, 'store'],       ['perm:users.create']);
    $r->get('/usuarios/{id:\d+}',              [UserController::class, 'show'],        ['perm:users.view']);
    $r->get('/usuarios/{id:\d+}/editar',       [UserController::class, 'edit'],        ['perm:users.update']);
    $r->post('/usuarios/{id:\d+}',             [UserController::class, 'update'],      ['perm:users.update']);
    $r->post('/usuarios/{id:\d+}/permisos',    [UserController::class, 'permissions'], ['perm:users.assign_roles']);
    $r->post('/usuarios/{id:\d+}/desactivar',  [UserController::class, 'deactivate'],  ['perm:users.deactivate']);
    $r->post('/usuarios/{id:\d+}/reactivar',   [UserController::class, 'reactivate'],  ['perm:users.deactivate']);
    $r->post('/usuarios/{id:\d+}/restablecer', [UserController::class, 'resetPassword'], ['perm:users.reset_password']);

    // ---------------------------- Auditoria --------------------------
    $r->get('/auditoria',                    [AuditController::class, 'index'],          ['perm:audit.view']);
    $r->get('/seguridad/eventos',            [AuditController::class, 'securityEvents'], ['perm:security.events.view']);
    $r->post('/seguridad/eventos/{id:\d+}/resolver', [AuditController::class, 'resolveEvent'], ['perm:security.events.view']);

    // ---------------------------- Sesiones ---------------------------
    $r->get('/sesiones',                          [SessionController::class, 'index'],  ['perm:sessions.view']);
    $r->post('/sesiones/{id:[a-f0-9]{64}}/cerrar',[SessionController::class, 'revoke'], ['perm:sessions.revoke']);
    $r->post('/sesiones/usuario/{userId:\d+}/cerrar', [SessionController::class, 'revokeAllForUser'], ['perm:sessions.revoke']);

    // ---------------------------- Reportes ---------------------------
    $r->get('/reportes',                    [ReportController::class, 'index'],    ['perm:reports.view']);
    $r->post('/reportes/generar',           [ReportController::class, 'generate'], ['perm:reports.view']);
    $r->get('/reportes/descargar/{uuid:[a-f0-9\-]{36}}', [ReportController::class, 'download'], ['perm:reports.view']);
    $r->get('/reportes/historial',          [ReportController::class, 'history'],  ['perm:reports.view']);
    $r->post('/reportes/seleccion',         [ReportController::class, 'picker'],   ['perm:reports.view']);

    // --------------------------- Importacion -------------------------
    $r->get('/importar',            [ImportController::class, 'index'],    ['perm:import.credentials']);
    $r->get('/importar/plantilla',  [ImportController::class, 'template'], ['perm:import.credentials']);
    $r->post('/importar/previa',    [ImportController::class, 'preview'],  ['perm:import.credentials']);
    $r->post('/importar/ejecutar',  [ImportController::class, 'execute'],  ['perm:import.credentials']);

    // -------------------------- Administracion -----------------------
    $r->get('/admin/categorias',        [AdminController::class, 'categories'],    ['perm:categories.manage']);
    $r->post('/admin/categorias',       [AdminController::class, 'saveCategory'],  ['perm:categories.manage']);
    $r->get('/admin/organizacion',      [AdminController::class, 'organization'],  ['perm:org.manage']);
    $r->post('/admin/empresas',         [AdminController::class, 'saveCompany'],   ['perm:org.manage']);
    $r->post('/admin/sedes',            [AdminController::class, 'saveLocation'],  ['perm:org.manage']);
    $r->post('/admin/departamentos',    [AdminController::class, 'saveDepartment'],['perm:org.manage']);
    $r->get('/admin/roles',             [AdminController::class, 'roles'],         ['perm:roles.view']);
    $r->post('/admin/roles',            [AdminController::class, 'saveRole'],      ['perm:roles.manage']);
    $r->post('/admin/roles/{id:\d+}/permisos', [AdminController::class, 'saveRolePermissions'], ['perm:roles.manage']);
    $r->get('/admin/configuracion',     [AdminController::class, 'settings'],      ['perm:settings.manage']);
    $r->post('/admin/configuracion',    [AdminController::class, 'saveSettings'],  ['perm:settings.manage']);
});

// =====================================================================
//  API v1 (misma sesion, misma autorizacion, respuestas JSON)
// =====================================================================
$router->group('/api/v1', $api, static function (Router $r): void {

    $r->get('/sesion',   [AuthApiController::class, 'status']);
    $r->post('/reauth',  [AuthApiController::class, 'reauth']);

    $r->get('/credenciales',                 [CredentialApiController::class, 'index'],  ['perm:credentials.view']);
    $r->post('/credenciales',                [CredentialApiController::class, 'store'],  ['perm:credentials.create']);
    $r->get('/credenciales/{id:\d+}',        [CredentialApiController::class, 'show'],   ['perm:credentials.view']);
    $r->put('/credenciales/{id:\d+}',        [CredentialApiController::class, 'update'], ['perm:credentials.update']);
    $r->delete('/credenciales/{id:\d+}',     [CredentialApiController::class, 'destroy'],['perm:credentials.delete']);
    $r->get('/credenciales/{id:\d+}/historial',   [CredentialApiController::class, 'history'],     ['perm:history.view']);
    $r->get('/credenciales/{id:\d+}/asignaciones',[CredentialApiController::class, 'assignments'], ['perm:credentials.assign']);
    $r->post('/credenciales/{id:\d+}/rotar',      [CredentialApiController::class, 'rotate'],      ['perm:credentials.rotate']);
    $r->post('/credenciales/{id:\d+}/asignar',    [CredentialApiController::class, 'assign'],      ['perm:credentials.assign']);
    $r->delete('/credenciales/{id:\d+}/asignaciones/{userId:\d+}', [CredentialApiController::class, 'revoke'], ['perm:credentials.revoke']);

    // Secretos: rutas dedicadas, nunca embebidos en las anteriores.
    $r->post('/credenciales/{id:\d+}/secreto',                       [SecretController::class, 'reveal'],         ['perm:credentials.secret.view']);
    $r->post('/credenciales/{id:\d+}/secreto/historial/{version:\d+}', [SecretController::class, 'revealHistoric'], ['perm:credentials.secret.history']);
    $r->get('/credenciales/{id:\d+}/recuperacion',                   [SecretController::class, 'recovery'],       ['perm:credentials.recovery.view']);

    // Utilidades
    $r->post('/generador',      [ToolsController::class, 'generate'],  ['perm:credentials.create']);
    $r->post('/fortaleza',      [ToolsController::class, 'strength']);
    $r->get('/buscar',          [ToolsController::class, 'search'],    ['throttle:search']);
    $r->get('/notificaciones',  [ToolsController::class, 'notifications']);
    $r->get('/alertas',         [ToolsController::class, 'alerts']);
});

return $router;
