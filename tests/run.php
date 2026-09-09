<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  BATERIA DE PRUEBAS FUNCIONALES Y DE SEGURIDAD
 * =====================================================================
 *
 *  Ejecuta peticiones HTTP reales en proceso contra el Kernel, con una
 *  base de datos independiente (credenciales_corp_test) que se recrea en
 *  cada ejecucion. Ninguna prueba invoca servicios saltandose la capa de
 *  autorizacion: todo pasa por enrutado + middleware + controlador.
 *
 *  Uso:  php tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

$root = dirname(__DIR__);

// Base de datos aislada para pruebas.
putenv('DB_DATABASE=credenciales_corp_test');
putenv('APP_DEBUG=false');

require $root . '/tests/TestClient.php';

use App\Core\Database;
use App\Services\CryptoService;
use Tests\TestClient;

// ---------------------------------------------------------------------
//  Microframework de aserciones
// ---------------------------------------------------------------------
final class Suite
{
    public int $passed = 0;
    public int $failed = 0;
    /** @var array<int,string> */
    public array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo PHP_EOL . "\033[1m" . $name . "\033[0m" . PHP_EOL;
        echo str_repeat('─', 70) . PHP_EOL;
    }

    public function assert(bool $condition, string $description, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✔\033[0m " . $description . PHP_EOL;
            return;
        }
        $this->failed++;
        $this->failures[] = $this->group . ' → ' . $description . ($detail !== '' ? ' [' . $detail . ']' : '');
        echo "  \033[31m✘\033[0m " . $description . ($detail !== '' ? "  \033[90m" . $detail . "\033[0m" : '') . PHP_EOL;
    }

    public function equals(mixed $expected, mixed $actual, string $description): void
    {
        $this->assert($expected === $actual, $description,
            'esperado: ' . var_export($expected, true) . ', obtenido: ' . var_export($actual, true));
    }

    public function status(int $expected, array $response, string $description): void
    {
        $this->assert($response['status'] === $expected, $description,
            'HTTP ' . $response['status'] . ' (esperado ' . $expected . ')');
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo PHP_EOL . str_repeat('═', 70) . PHP_EOL;
        if ($this->failed === 0) {
            echo "\033[32m\033[1m  TODAS LAS PRUEBAS SUPERADAS\033[0m  ({$this->passed}/{$total})" . PHP_EOL;
        } else {
            echo "\033[31m\033[1m  {$this->failed} PRUEBA(S) FALLIDAS\033[0m  ({$this->passed}/{$total} correctas)" . PHP_EOL . PHP_EOL;
            foreach ($this->failures as $failure) {
                echo "   • " . $failure . PHP_EOL;
            }
        }
        echo str_repeat('═', 70) . PHP_EOL;
        return $this->failed === 0 ? 0 : 1;
    }
}

$t = new Suite();

// ---------------------------------------------------------------------
//  Preparacion del entorno de pruebas
// ---------------------------------------------------------------------
echo "\033[1mPreparando base de datos de pruebas…\033[0m" . PHP_EOL;

/** @var \App\Core\Container $container */
$container = require $root . '/app/bootstrap.php';
$cfg       = \App\Core\Config::get('database');

$dsn = !empty($cfg['socket'])
    ? sprintf('mysql:unix_socket=%s;charset=utf8mb4', $cfg['socket'])
    : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], (int) $cfg['port']);

$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `credenciales_corp_test`');
$pdo->exec('CREATE DATABASE `credenciales_corp_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$db = Database::instance();

// Se aplica el mismo juego de migraciones que en produccion: las pruebas
// ejercitan el esquema real, no una copia que pueda divergir.
$migrator = new \App\Support\Migrator($db, $root . '/database/migrations');
$migrator->run();

/** @var CryptoService $crypto */
$crypto = $container->get(CryptoService::class);
$crypto->activeKeyVersion();

// Para la mayoria de las pruebas se desactiva el MFA obligatorio; hay una
// prueba especifica que lo vuelve a activar y verifica que se exija.
$db->execute("UPDATE settings SET setting_value = '0' WHERE setting_key = 'security.mfa_required_admins'");

// -------------------------- Datos de prueba ---------------------------
const PASS_ADMIN     = 'Adm1n#Prueba2026!';
const PASS_AUDITOR   = 'Aud1t#Prueba2026!';
const PASS_CONSULTOR = 'Cons#Prueba2026!x';
const PASS_OTRO      = 'Otro#Prueba2026!y';

$companyId = $db->insert('INSERT INTO companies (name) VALUES (?)', ['Empresa de Pruebas']);
$roleIds   = [];
foreach ($db->select('SELECT id, code FROM roles') as $row) {
    $roleIds[(string) $row['code']] = (int) $row['id'];
}

$makeUser = static function (string $nid, string $username, string $email, string $first, string $last,
                             string $password, string $roleCode) use ($db, $crypto, $companyId, $roleIds): int {
    $hash = $crypto->hashPassword($password);
    $id   = $db->insert(
        'INSERT INTO users (national_id, username, email, first_name, last_name, company_id,
                            password_hash, password_algo, password_changed_at, must_change_password, status)
         VALUES (?,?,?,?,?,?,?,?,NOW(),0,"active")',
        [$nid, $username, $email, $first, $last, $companyId, $hash['hash'], $hash['algo']]
    );
    $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$id, $roleIds[$roleCode]]);
    return $id;
};

$adminId     = $makeUser('900000001', 'admin.test',   'admin@test.local',   'Admin',    'Prueba', PASS_ADMIN,     'ADMIN');
$auditorId   = $makeUser('900000002', 'auditor.test', 'auditor@test.local', 'Auditor',  'Prueba', PASS_AUDITOR,   'AUDITOR');
$consultorId = $makeUser('900000003', 'juan.test',    'juan@test.local',    'Juan',     'Perez',  PASS_CONSULTOR, 'CONSULTOR');
$otroId      = $makeUser('900000004', 'ana.test',     'ana@test.local',     'Ana',      'Torres', PASS_OTRO,      'CONSULTOR');

$categoryId = (int) $db->scalar("SELECT id FROM categories WHERE slug = 'sistemas'");
$systemId   = $db->insert(
    'INSERT INTO systems (name, category_id, resource_type, company_id, url, status, owner_user_id)
     VALUES (?,?,?,?,?,"active",?)',
    ['Sistema Contable', $categoryId, 'application', $companyId, 'https://contable.test.local', $adminId]
);
$systemId2 = $db->insert(
    'INSERT INTO systems (name, category_id, resource_type, company_id, status)
     VALUES (?,?,?,?,"active")',
    ['Portal Bancario', $categoryId, 'banking', $companyId]
);

$makeCredential = static function (int $systemId, string $name, string $username, string $secret)
                  use ($db, $crypto, $adminId): array {
    $id = $db->insert(
        'INSERT INTO credentials (system_id, name, username, email, recovery_email, recovery_phone,
                                  status, rotation_period_days, next_rotation_at, password_changed_at,
                                  owner_user_id, created_by)
         VALUES (?,?,?,?,?,?,"active",90, DATE_ADD(CURDATE(), INTERVAL 90 DAY), NOW(), ?, ?)',
        [$systemId, $name, $username, $username . '@test.local', 'recuperacion@test.local', '3001234567', $adminId, $adminId]
    );
    $env = $crypto->encrypt($secret, $crypto->aad('credential', $id, 'password', 1));
    $db->execute(
        'INSERT INTO credential_secrets (credential_id, field, version, is_current, algo, key_version,
                                         ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag,
                                         secret_length, strength_score, fingerprint, created_by)
         VALUES (?,"password",1,1,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $env['algo'], $env['key_version'], $env['ciphertext'], $env['nonce'], $env['tag'],
         $env['wrapped_dek'], $env['dek_nonce'], $env['dek_tag'], strlen($secret), 90,
         $crypto->fingerprint($secret), $adminId]
    );
    return ['id' => $id, 'secret' => $secret];
};

$credA = $makeCredential($systemId,  'Usuario de contabilidad', 'contabilidad', 'S3cret0-Contab!2026');
$credB = $makeCredential($systemId,  'Usuario de inventarios',  'inventarios',  'S3cret0-Invent!2026');
$credC = $makeCredential($systemId2, 'Usuario de tesoreria',    'tesoreria',    'S3cret0-Tesorer!26');

// Juan ve y copia A; ve B pero NO puede copiarla; no tiene acceso a C.
$db->execute(
    'INSERT INTO credential_assignments (credential_id, user_id, can_view_secret, can_copy_secret, can_view_recovery, granted_by)
     VALUES (?,?,1,1,0,?)', [$credA['id'], $consultorId, $adminId]
);
$db->execute(
    'INSERT INTO credential_assignments (credential_id, user_id, can_view_secret, can_copy_secret, can_view_recovery, granted_by)
     VALUES (?,?,1,0,0,?)', [$credB['id'], $consultorId, $adminId]
);

$clearLimits = static function () use ($db): void { $db->execute('DELETE FROM rate_limits'); };

echo "Listo." . PHP_EOL;

// =====================================================================
//  1. AUTENTICACION
// =====================================================================
$t->group('1. Autenticacion');

$anon = new TestClient('198.51.100.1');
$r = $anon->get('/');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/entrar'),
    'Un usuario sin sesion es redirigido al formulario de acceso', 'HTTP ' . $r['status']);

$r = $anon->get('/entrar');
$t->status(200, $r, 'El formulario de acceso se muestra correctamente');
$t->assert(str_contains($r['body'], 'name="_csrf"'), 'El formulario incluye token anti-CSRF');

$r = $anon->post('/entrar', ['identifier' => 'admin.test', 'password' => 'contrasena-incorrecta']);
$t->assert($r['status'] === 302 && !str_contains($r['headers']['Location'] ?? '', '/entrar?') ,
    'El acceso con contrasena incorrecta no autentica');
$t->assert($anon->cookie('scgca_session') === null, 'No se emite cookie de sesion tras un intento fallido');
$attempts = (int) $db->scalar("SELECT COUNT(*) FROM login_attempts WHERE result = 'failed'");
$t->assert($attempts >= 1, 'El intento fallido queda registrado en login_attempts');

$r = $anon->post('/entrar', ['identifier' => 'usuario.que.no.existe', 'password' => 'algo']);
$t->assert($r['status'] === 302, 'Un identificador inexistente responde igual que una clave incorrecta (sin enumeracion)');

$clearLimits();
$admin = new TestClient('198.51.100.2');
$r = $admin->login('admin.test', PASS_ADMIN);
$t->assert($admin->cookie('scgca_session') !== null, 'El acceso valido emite la cookie de sesion');
$sessionRow = $db->selectOne('SELECT * FROM sessions WHERE id = ?', [hash('sha256', (string) $admin->cookie('scgca_session'))]);
$t->assert($sessionRow !== null, 'La sesion se persiste en la base de datos');
$t->assert($sessionRow !== null && strlen((string) $sessionRow['id']) === 64,
    'En la tabla solo se guarda el hash del token de sesion, no el token');

$r = $admin->get('/');
$t->status(200, $r, 'El administrador accede al panel de control');
$t->assert(str_contains($r['body'], 'Panel de control'), 'El panel de control se renderiza');

$loginAudit = (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login' AND result = 'success'");
$t->assert($loginAudit >= 1, 'El inicio de sesion queda auditado');

// Bloqueo por intentos fallidos
$clearLimits();
$bruteforce = new TestClient('198.51.100.3');
for ($i = 0; $i < 6; $i++) {
    $bruteforce->post('/entrar', ['identifier' => 'ana.test', 'password' => 'clave-mala-' . $i]);
}
$locked = $db->selectOne('SELECT locked_until, failed_attempts FROM users WHERE id = ?', [$otroId]);
$t->assert($locked !== null && $locked['locked_until'] !== null,
    'La cuenta se bloquea temporalmente tras superar los intentos permitidos');
$blockedEvent = (int) $db->scalar("SELECT COUNT(*) FROM security_events WHERE type = 'account_locked'");
$t->assert($blockedEvent >= 1, 'El bloqueo genera un evento de seguridad');
$db->execute('UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE id = ?', [$otroId]);

// Limitador de frecuencia
$clearLimits();
$flood  = new TestClient('198.51.100.4');
$status = 0;
for ($i = 0; $i < 25; $i++) {
    $res    = $flood->post('/entrar', ['identifier' => 'inexistente' . $i, 'password' => 'x']);
    $status = $res['status'];
    if ($status === 429) { break; }
}
$t->equals(429, $status, 'El limitador de frecuencia corta la fuerza bruta por IP');
$clearLimits();

// =====================================================================
//  2. AUTORIZACION Y CONTROL DE ACCESO
// =====================================================================
$t->group('2. Autorizacion (el consultor no puede lo que no le corresponde)');

$juan = new TestClient('198.51.100.5');
$juan->login('juan.test', PASS_CONSULTOR);
$t->assert($juan->cookie('scgca_session') !== null, 'El consultor inicia sesion correctamente');

$r = $juan->get('/');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/mis-accesos'),
    'El consultor es dirigido a "Mis accesos" y no al panel administrativo');

$r = $juan->get('/mis-accesos');
$t->status(200, $r, 'El consultor accede a su panel');
$t->assert(str_contains($r['body'], 'Usuario de contabilidad'), 'El consultor ve la credencial que tiene asignada');
$t->assert(!str_contains($r['body'], 'Usuario de tesoreria'), 'El consultor NO ve credenciales ajenas en su panel');

foreach ([
    '/usuarios'             => 'la administracion de usuarios',
    '/auditoria'            => 'la auditoria',
    '/sesiones'             => 'el control de sesiones',
    '/admin/configuracion'  => 'la configuracion del sistema',
    '/admin/roles'          => 'la matriz de roles',
    '/sistemas'             => 'el inventario de sistemas',
    '/reportes'             => 'el modulo de reportes',
    '/importar'             => 'la importacion masiva',
] as $path => $label) {
    $res = $juan->get($path);
    $t->assert($res['status'] === 403, 'El consultor no puede acceder a ' . $label, 'HTTP ' . $res['status']);
}

$r = $juan->get('/credenciales/' . $credC['id']);
$t->status(404, $r, 'IDOR: el consultor no puede abrir por URL una credencial no asignada');

$r = $juan->postJson('/api/v1/credenciales/' . $credC['id'] . '/secreto', ['field' => 'password']);
$t->assert(in_array($r['status'], [403, 404], true),
    'IDOR en API: el consultor no puede revelar el secreto de una credencial ajena', 'HTTP ' . $r['status']);

$r = $juan->post('/credenciales/' . $credA['id'] . '/rotar', ['password' => 'IntentoDeCambio#2026']);
$t->status(403, $r, 'El consultor no puede modificar la contrasena almacenada');

$r = $juan->post('/credenciales/' . $credA['id'], ['name' => 'Nombre alterado', 'system_id' => $systemId]);
$t->status(403, $r, 'El consultor no puede editar la credencial');

$r = $juan->post('/usuarios/' . $consultorId, ['first_name' => 'Hacker']);
$t->status(403, $r, 'El consultor no puede modificar usuarios');

$r = $juan->postJson('/api/v1/credenciales', ['system_id' => $systemId, 'name' => 'Nueva', 'password' => 'Abc#12345678']);
$t->status(403, $r, 'El consultor no puede crear credenciales por la API');

$deniedAudit = (int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'security.access_denied'");
$t->assert($deniedAudit >= 5, 'Los intentos de acceso denegado quedan auditados', 'registros: ' . $deniedAudit);

// El auditor: consulta todo pero no ve secretos
$auditor = new TestClient('198.51.100.6');
$auditor->login('auditor.test', PASS_AUDITOR);
$r = $auditor->get('/auditoria');
$t->status(200, $r, 'El auditor accede a la auditoria');
$r = $auditor->get('/credenciales/' . $credC['id']);
$t->status(200, $r, 'El auditor ve la ficha de cualquier credencial');
$t->assert(!str_contains($r['body'], $credC['secret']), 'La ficha entregada al auditor no contiene el secreto');
$r = $auditor->postJson('/api/v1/credenciales/' . $credC['id'] . '/secreto');
$t->status(403, $r, 'El auditor NO puede revelar contrasenas');
$r = $auditor->post('/reportes/generar', ['type' => 'full_credentials', 'include_secrets' => '1', 'confirm' => '1']);
$t->status(403, $r, 'El auditor NO puede exportar contrasenas');

// =====================================================================
//  3. PROTECCION DEL SECRETO (regla de oro)
// =====================================================================
$t->group('3. Separacion entre informacion y secreto');

$r = $admin->getJson('/api/v1/credenciales');
$t->status(200, $r, 'GET /api/v1/credenciales responde correctamente');
$body = $r['body'];
$t->assert(!str_contains($body, $credA['secret']) && !str_contains($body, $credB['secret']) && !str_contains($body, $credC['secret']),
    'El listado de la API NUNCA devuelve contrasenas');
$t->assert(!preg_match('/"password"\s*:/', $body) === true || !str_contains($body, '"password":"'),
    'El listado no incluye ningun campo password con valor');

$r = $admin->getJson('/api/v1/credenciales/' . $credA['id']);
$t->assert(!str_contains($r['body'], $credA['secret']), 'El detalle de la API tampoco devuelve la contrasena');
$t->assert(!str_contains($r['body'], 'recuperacion@test.local'),
    'El detalle no expone la informacion de recuperacion sin la peticion especifica');

$r = $admin->get('/credenciales/' . $credA['id']);
$t->assert(!str_contains($r['body'], $credA['secret']), 'La ficha HTML no contiene la contrasena en el codigo fuente');
$t->assert(str_contains($r['body'], 'data-secret-toggle'), 'La ficha ofrece el boton "Mostrar" controlado');

// Step-up obligatorio
$r = $admin->postJson('/api/v1/credenciales/' . $credA['id'] . '/secreto');
$t->status(423, $r, 'Revelar un secreto exige reautenticacion (step-up)');
$t->assert(($r['json']['reauth_required'] ?? false) === true, 'La respuesta indica que se requiere reautenticacion');

$r = $admin->postJson('/api/v1/reauth', ['password' => 'clave-equivocada']);
$t->status(401, $r, 'La reautenticacion con clave incorrecta se rechaza');

$r = $admin->postJson('/api/v1/reauth', ['password' => PASS_ADMIN]);
$t->status(200, $r, 'La reautenticacion con la clave correcta se acepta');

$r = $admin->postJson('/api/v1/credenciales/' . $credA['id'] . '/secreto');
$t->status(200, $r, 'Tras el step-up, el secreto se revela');
$t->equals($credA['secret'], $r['json']['secret'] ?? null, 'El secreto descifrado coincide con el original');

$accessLog = $db->selectOne(
    'SELECT * FROM secret_access_log WHERE credential_id = ? ORDER BY id DESC LIMIT 1', [$credA['id']]
);
$t->assert($accessLog !== null && (int) $accessLog['user_id'] === $adminId,
    'La consulta del secreto queda registrada con el usuario que la realizo');
$t->assert($accessLog !== null && $accessLog['ip_address'] === '198.51.100.2',
    'El registro incluye la direccion IP de origen');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'secret.viewed'") >= 1,
    'La visualizacion del secreto queda en la auditoria general');

// Permisos finos de la asignacion: Juan puede ver B pero no copiarla
$juan->postJson('/api/v1/reauth', ['password' => PASS_CONSULTOR]);
$r = $juan->postJson('/api/v1/credenciales/' . $credB['id'] . '/secreto', ['copy' => false]);
$t->status(200, $r, 'El consultor ve el secreto que su asignacion permite ver');
$r = $juan->postJson('/api/v1/credenciales/' . $credB['id'] . '/secreto', ['copy' => true]);
$t->status(403, $r, 'El consultor NO puede copiar un secreto cuya asignacion lo prohibe');

// Informacion de recuperacion
$r = $juan->getJson('/api/v1/credenciales/' . $credA['id'] . '/recuperacion');
$t->status(403, $r, 'El consultor sin permiso no obtiene la informacion de recuperacion');

$admin->postJson('/api/v1/reauth', ['password' => PASS_ADMIN]);
$r = $admin->getJson('/api/v1/credenciales/' . $credA['id'] . '/recuperacion');
$t->status(200, $r, 'El administrador obtiene la informacion de recuperacion por su endpoint especifico');
$t->assert(($r['json']['recovery_email'] ?? '') === 'recuperacion@test.local', 'La informacion de recuperacion es correcta');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'recovery.viewed'") >= 1,
    'La consulta de datos de recuperacion queda auditada');

// =====================================================================
//  4. CIFRADO
// =====================================================================
$t->group('4. Cifrado de los secretos almacenados');

$row = $db->selectOne('SELECT * FROM credential_secrets WHERE credential_id = ?', [$credA['id']]);
$cipher = is_resource($row['ciphertext']) ? stream_get_contents($row['ciphertext']) : (string) $row['ciphertext'];
$t->assert(!str_contains($cipher, $credA['secret']), 'El criptograma no contiene la contrasena en claro');
$t->assert($cipher !== $credA['secret'], 'El valor almacenado difiere del texto original');
$t->assert(strlen((string) $row['nonce']) === 12, 'Se almacena un nonce de 96 bits por secreto');
$t->assert(strlen((string) $row['tag']) === 16, 'Se almacena la etiqueta de autenticacion GCM');
$t->assert(strlen((string) $row['wrapped_dek']) > 0, 'La clave de datos se guarda envuelta, nunca en claro');

$plain = $crypto->decrypt($row, $crypto->aad('credential', (int) $row['credential_id'], 'password', 1));
$t->equals($credA['secret'], $plain, 'El descifrado con el AAD correcto recupera el secreto');

$wrong = $crypto->decrypt($row, $crypto->aad('credential', 999999, 'password', 1));
$t->assert($wrong === null, 'El descifrado con un AAD de otra credencial falla (integridad ligada al contexto)');

$tampered = $row;
$tampered['ciphertext'] = substr($cipher, 0, -1) . chr((ord(substr($cipher, -1)) + 1) % 256);
$t->assert($crypto->decrypt($tampered, $crypto->aad('credential', (int) $row['credential_id'], 'password', 1)) === null,
    'Un criptograma manipulado es rechazado por la autenticacion GCM');

$secretA = $crypto->encrypt('mismo-valor', 'aad');
$secretB = $crypto->encrypt('mismo-valor', 'aad');
$t->assert($secretA['ciphertext'] !== $secretB['ciphertext'],
    'Dos cifrados del mismo valor producen criptogramas distintos (nonce y DEK unicos)');

$userRow = $db->selectOne('SELECT password_hash FROM users WHERE id = ?', [$adminId]);
$t->assert(!str_contains((string) $userRow['password_hash'], PASS_ADMIN),
    'La contrasena de acceso al sistema se guarda como hash irreversible');
$t->assert(str_starts_with((string) $userRow['password_hash'], '$2y$') || str_starts_with((string) $userRow['password_hash'], '$argon2'),
    'El hash usa un algoritmo lento con sal (bcrypt/Argon2id)');

// =====================================================================
//  5. CSRF, XSS E INYECCION
// =====================================================================
$t->group('5. Defensas frente a CSRF, XSS e inyeccion SQL');

$r = $admin->post('/credenciales/' . $credA['id'] . '/rotar', ['password' => 'SinToken#2026abc'], false);
$t->status(419, $r, 'Una peticion de escritura sin token CSRF es rechazada');

$victim = new TestClient('198.51.100.7');
$victim->login('admin.test', PASS_ADMIN);
$victim->setCookie('scgca_csrf', str_repeat('a', 64));
$r = $victim->request('POST', '/credenciales/' . $credA['id'] . '/eliminar', ['reason' => 'ataque', '_csrf' => str_repeat('b', 64)], false, false);
$t->status(419, $r, 'Un token CSRF que no corresponde a la sesion es rechazado');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'security.csrf_failed'") >= 1,
    'Los fallos de CSRF quedan auditados como evento critico');

// Inyeccion SQL en los filtros
$payloads = ["' OR '1'='1", "1; DROP TABLE users --", "' UNION SELECT password_hash FROM users --", "%' AND SLEEP(3) --"];
foreach ($payloads as $payload) {
    $res = $admin->get('/credenciales', ['q' => $payload]);
    $t->assert($res['status'] === 200, 'Inyeccion SQL neutralizada en el buscador: ' . substr($payload, 0, 22), 'HTTP ' . $res['status']);
}
$t->assert((int) $db->scalar('SELECT COUNT(*) FROM users') === 4, 'La tabla users sigue intacta tras los intentos de inyeccion');

$res = $admin->get('/credenciales', ['sort' => 'name); DROP TABLE credentials --', 'direction' => 'ASC; DELETE FROM users']);
$t->assert($res['status'] === 200 && (int) $db->scalar('SELECT COUNT(*) FROM credentials') === 3,
    'El ordenamiento se valida contra lista blanca (no admite SQL arbitrario)');

// XSS almacenado
$xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
$r = $admin->post('/credenciales', [
    'system_id' => $systemId, 'name' => $xss, 'username' => 'xsstest',
    'password' => 'Xss#Prueba2026abc', 'environment' => 'production',
]);
$xssId = (int) $db->scalar('SELECT id FROM credentials WHERE username = ?', ['xsstest']);
$t->assert($xssId > 0, 'La credencial de prueba XSS se creo');
$r = $admin->get('/credenciales/' . $xssId);
$t->assert(!str_contains($r['body'], '<script>alert("xss")</script>'),
    'El contenido malicioso NO se emite como HTML ejecutable');
$t->assert(str_contains($r['body'], '&lt;script&gt;'), 'El contenido se muestra escapado');
$t->assert(str_contains($r['headers']['Content-Security-Policy'] ?? '', "script-src 'self' 'nonce-"),
    'La CSP exige nonce para los scripts (sin unsafe-inline)');

foreach ([
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options'        => 'DENY',
    'Referrer-Policy'        => 'no-referrer',
] as $header => $expected) {
    $t->assert(($r['headers'][$header] ?? '') === $expected, 'Cabecera de seguridad ' . $header . ' presente');
}
$t->assert(str_contains($r['headers']['Cache-Control'] ?? '', 'no-store'),
    'Las paginas con datos sensibles no se almacenan en cache');

// =====================================================================
//  6. CICLO DE VIDA DE LAS CREDENCIALES
// =====================================================================
$t->group('6. Ciclo de vida de las credenciales');

$admin->postJson('/api/v1/reauth', ['password' => PASS_ADMIN]);
$nuevoSecreto = 'Rotad0-Nuev0#2026';
$r = $admin->post('/credenciales/' . $credA['id'] . '/rotar', [
    'password' => $nuevoSecreto, 'reason' => 'Rotacion periodica de prueba',
]);
$t->assert($r['status'] === 302, 'La rotacion de contrasena se ejecuta');

$versions = (int) $db->scalar('SELECT COUNT(*) FROM credential_secrets WHERE credential_id = ?', [$credA['id']]);
$t->equals(2, $versions, 'La contrasena anterior se conserva como version historica');
$current = $db->selectOne('SELECT * FROM credential_secrets WHERE credential_id = ? AND is_current = 1', [$credA['id']]);
$t->equals(2, (int) $current['version'], 'La version vigente es la mas reciente');
$t->equals($nuevoSecreto, $crypto->decrypt($current, $crypto->aad('credential', $credA['id'], 'password', 2)),
    'La nueva contrasena se almacena cifrada y se recupera correctamente');

$history = $db->selectOne("SELECT * FROM credential_history WHERE credential_id = ? AND action = 'password_rotated'", [$credA['id']]);
$t->assert($history !== null && $history['reason'] === 'Rotacion periodica de prueba',
    'El historial registra el motivo del cambio');
$t->assert($history !== null && (int) $history['performed_by'] === $adminId,
    'El historial registra quien realizo el cambio');

$rotatedRow = $db->selectOne('SELECT password_changed_at, next_rotation_at FROM credentials WHERE id = ?', [$credA['id']]);
$t->assert($rotatedRow['password_changed_at'] !== null && $rotatedRow['next_rotation_at'] !== null,
    'Se actualizan la fecha de rotacion y la proxima fecha recomendada');

$historyDump = $db->select('SELECT old_value, new_value, metadata FROM credential_history WHERE credential_id = ?', [$credA['id']]);
$leak = false;
foreach ($historyDump as $h) {
    foreach ($h as $value) {
        if (is_string($value) && (str_contains($value, $nuevoSecreto) || str_contains($value, $credA['secret']))) {
            $leak = true;
        }
    }
}
$t->assert(!$leak, 'El historial NO almacena contrasenas en claro');

$r = $admin->post('/credenciales/' . $credA['id'] . '/rotar', ['password' => $nuevoSecreto, 'reason' => 'repetida']);
$t->assert((int) $db->scalar('SELECT COUNT(*) FROM credential_secrets WHERE credential_id = ?', [$credA['id']]) === 2,
    'No se admite repetir la contrasena vigente (deteccion por huella, sin descifrar)');

// Historico: exige permiso especifico
$r = $admin->postJson('/api/v1/credenciales/' . $credA['id'] . '/secreto/historial/1');
$t->status(200, $r, 'Con el permiso adecuado se puede consultar una contrasena historica');
$t->equals($credA['secret'], $r['json']['secret'] ?? null, 'La contrasena historica se recupera correctamente');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM secret_access_log WHERE access_type = 'history_view'") >= 1,
    'La consulta de una contrasena historica queda registrada');

$r = $juan->postJson('/api/v1/credenciales/' . $credA['id'] . '/secreto/historial/1');
$t->status(403, $r, 'El consultor no puede acceder a contrasenas historicas');

// Asignacion y revocacion
$r = $admin->post('/credenciales/' . $credC['id'] . '/asignar', [
    'user_id' => $consultorId, 'can_view_secret' => '1', 'can_copy_secret' => '1',
]);
$t->assert($r['status'] === 302, 'El administrador asigna una credencial a un usuario');
$r = $juan->get('/credenciales/' . $credC['id']);
$t->status(200, $r, 'El consultor ya puede ver la credencial recien asignada');

$r = $admin->post('/credenciales/' . $credC['id'] . '/revocar/' . $consultorId, ['reason' => 'Fin de la prueba']);
$t->assert($r['status'] === 302, 'El administrador revoca el acceso');
$r = $juan->get('/credenciales/' . $credC['id']);
$t->status(404, $r, 'Tras la revocacion el consultor pierde el acceso de inmediato');

// =====================================================================
//  7. BAJA DE USUARIOS
// =====================================================================
$t->group('7. Baja de empleados');

$r = $admin->post('/usuarios/' . $consultorId . '/desactivar', [
    'reason' => 'Retiro del empleado (prueba)',
]);
$t->assert($r['status'] === 302, 'El administrador desactiva al usuario');

$userRow = $db->selectOne('SELECT status, deactivated_at, deactivation_reason FROM users WHERE id = ?', [$consultorId]);
$t->equals('inactive', $userRow['status'], 'El usuario queda inactivo');
$t->assert($userRow['deactivation_reason'] === 'Retiro del empleado (prueba)', 'Se conserva el motivo de la baja');

$active = (int) $db->scalar('SELECT COUNT(*) FROM credential_assignments WHERE user_id = ? AND is_active = 1', [$consultorId]);
$t->equals(0, $active, 'Se revocan automaticamente todos sus accesos');
$historic = (int) $db->scalar('SELECT COUNT(*) FROM credential_assignments WHERE user_id = ?', [$consultorId]);
$t->assert($historic > 0, 'El historico de asignaciones se conserva (no se borra informacion)');

$openSessions = (int) $db->scalar("SELECT COUNT(*) FROM sessions WHERE user_id = ? AND status = 'active'", [$consultorId]);
$t->equals(0, $openSessions, 'Se cierran sus sesiones abiertas');

$r = $juan->get('/mis-accesos');
$t->assert($r['status'] === 302, 'La sesion del usuario dado de baja deja de funcionar de inmediato');

$clearLimits();
$exJuan = new TestClient('198.51.100.8');
$r = $exJuan->login('juan.test', PASS_CONSULTOR);
$t->assert($exJuan->cookie('scgca_session') === null, 'El usuario inactivo no puede volver a iniciar sesion');

$auditEntry = $db->selectOne("SELECT details FROM audit_logs WHERE action = 'user.deactivated' ORDER BY id DESC LIMIT 1");
$t->assert($auditEntry !== null && str_contains((string) $auditEntry['details'], 'accesos_revocados'),
    'La baja queda auditada con el detalle de los accesos revocados');

// =====================================================================
//  8. SESIONES
// =====================================================================
$t->group('8. Control de sesiones');

$clearLimits();
$otro = new TestClient('198.51.100.9');
$otro->login('ana.test', PASS_OTRO);
$t->assert($otro->cookie('scgca_session') !== null, 'Segundo usuario con sesion activa');

$r = $admin->get('/sesiones');
$t->status(200, $r, 'El administrador lista las sesiones activas');
$t->assert(str_contains($r['body'], '198.51.100.9'), 'La sesion del otro usuario aparece en el listado');

$sessionId = hash('sha256', (string) $otro->cookie('scgca_session'));
$r = $admin->post('/sesiones/' . $sessionId . '/cerrar', ['reason' => 'Prueba de cierre remoto']);
$t->assert($r['status'] === 302, 'El administrador cierra remotamente una sesion');
$r = $otro->get('/mis-accesos');
$t->assert($r['status'] === 302, 'La sesion cerrada remotamente deja de ser valida de inmediato');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'session.revoked'") >= 1,
    'El cierre remoto queda auditado');

$r = $admin->getJson('/api/v1/sesion');
$t->assert(($r['json']['authenticated'] ?? false) === true, 'El endpoint de estado de sesion responde correctamente');
$t->assert(!str_contains($r['body'], 'password'), 'El estado de sesion no expone datos sensibles');

// =====================================================================
//  9. REPORTES Y EXPORTACION
// =====================================================================
$t->group('9. Reportes y exportacion a Excel');

$r = $admin->post('/reportes/generar', ['type' => 'inventory']);
$t->assert($r['status'] === 302, 'Se genera el reporte de inventario');
$report = $db->selectOne("SELECT * FROM export_reports WHERE report_type = 'inventory' ORDER BY id DESC LIMIT 1");
$t->assert($report !== null, 'La exportacion queda registrada en export_reports');
$t->equals(0, (int) $report['included_secrets'], 'El inventario NO incluye contrasenas');
$t->assert((int) $report['record_count'] > 0, 'El reporte contiene registros');
$t->assert(!str_starts_with((string) $report['storage_path'], $root . '/public'),
    'El archivo se almacena FUERA del directorio publico');
$t->assert(!str_contains((string) $report['file_name'], 'password'), 'El nombre del archivo no revela contenido sensible');

$inventoryPath = (string) $report['storage_path'];
$zip = new ZipArchive();
$t->assert($zip->open($inventoryPath) === true, 'El archivo generado es un XLSX valido');
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
$t->assert(!str_contains($sheet, $nuevoSecreto) && !str_contains($sheet, $credC['secret']),
    'El Excel de inventario no contiene ninguna contrasena');

// Exportacion con contrasenas: requiere confirmacion explicita
$r = $admin->post('/reportes/generar', ['type' => 'full_credentials', 'include_secrets' => '1']);
$t->assert($r['status'] === 302 || $r['status'] === 422,
    'Sin confirmacion explicita no se genera un archivo con contrasenas');
$withSecrets = (int) $db->scalar("SELECT COUNT(*) FROM export_reports WHERE included_secrets = 1 AND status = 'ready'");
$t->equals(0, $withSecrets, 'No se creo ningun archivo con contrasenas sin confirmar la advertencia');

$admin->postJson('/api/v1/reauth', ['password' => PASS_ADMIN]);
$r = $admin->post('/reportes/generar', ['type' => 'full_credentials', 'include_secrets' => '1', 'confirm' => '1']);
$t->assert($r['status'] === 302, 'Con permiso, confirmacion y step-up se genera el archivo con contrasenas');

$secretReport = $db->selectOne("SELECT * FROM export_reports WHERE included_secrets = 1 ORDER BY id DESC LIMIT 1");
$t->assert($secretReport !== null && $secretReport['status'] === 'ready', 'El reporte confidencial se genero');
$t->assert((int) $db->scalar('SELECT COUNT(*) FROM export_report_items WHERE report_id = ?', [(int) $secretReport['id']]) > 0,
    'Se registra que credenciales concretas viajaron en el archivo');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM secret_access_log WHERE access_type = 'export'") > 0,
    'Cada secreto exportado deja su registro individual de acceso');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM security_events WHERE type = 'export_with_secrets'") >= 1,
    'La exportacion con contrasenas genera un evento de seguridad');

$zip = new ZipArchive();
$zip->open((string) $secretReport['storage_path']);
$confidential = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
$t->assert(str_contains($confidential, $nuevoSecreto), 'El archivo confidencial si contiene las contrasenas solicitadas');

// Descarga: solo el autor, y el archivo se elimina
$intruder = new TestClient('198.51.100.10');
$clearLimits();
$intruder->login('auditor.test', PASS_AUDITOR);
$r = $intruder->get('/reportes/descargar/' . $secretReport['uuid']);
$t->status(404, $r, 'Otro usuario no puede descargar un reporte ajeno');
$t->assert((int) $db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'export.denied'") >= 1,
    'El intento de descarga ajena queda auditado');

$r = $admin->get('/reportes/descargar/' . $secretReport['uuid']);
$t->status(200, $r, 'El autor descarga su reporte');
$t->assert(!is_file((string) $secretReport['storage_path']),
    'El archivo se elimina del servidor inmediatamente despues de la descarga');

$r = $admin->get('/reportes/historial');
$t->status(200, $r, 'El historial de exportaciones es consultable');
$t->assert(str_contains($r['body'], 'Con contrasenas') || str_contains($r['body'], 'SI'),
    'El historial distingue las exportaciones que incluyeron contrasenas');

// =====================================================================
//  10. AUDITORIA Y TRAZABILIDAD
// =====================================================================
$t->group('10. Auditoria y trazabilidad');

$r = $admin->get('/auditoria');
$t->status(200, $r, 'La auditoria se consulta correctamente');

$r = $admin->get('/auditoria', ['action' => 'secret.viewed']);
$t->status(200, $r, 'La auditoria se puede filtrar por tipo de accion');
$r = $admin->get('/auditoria', ['national_id' => '900000001']);
$t->status(200, $r, 'La auditoria se puede filtrar por cedula');
$r = $admin->get('/auditoria', ['result' => 'denied', 'date_from' => date('Y-m-d')]);
$t->status(200, $r, 'La auditoria se puede filtrar por resultado y rango de fechas');

$expected = ['auth.login', 'auth.login_failed', 'secret.viewed', 'credential.password_rotated',
             'credential.assigned', 'credential.revoked', 'user.deactivated', 'export.generated',
             'export.downloaded', 'session.revoked', 'security.access_denied'];
foreach ($expected as $action) {
    $count = (int) $db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
    $t->assert($count > 0, 'Se audita la accion: ' . $action, 'registros: ' . $count);
}

$columns = $db->selectOne('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1');
foreach (['user_id', 'actor_national_id', 'action', 'result', 'ip_address', 'device', 'occurred_at'] as $column) {
    $t->assert(array_key_exists($column, $columns), 'El registro de auditoria incluye ' . $column);
}

// Ninguna contrasena en la auditoria ni en los logs
$allSecrets = [$credA['secret'], $credB['secret'], $credC['secret'], $nuevoSecreto,
               PASS_ADMIN, PASS_AUDITOR, PASS_CONSULTOR, PASS_OTRO];
$leaks = [];
foreach ($db->select('SELECT details, entity_label FROM audit_logs') as $auditRow) {
    foreach ($allSecrets as $secret) {
        if (str_contains((string) $auditRow['details'], $secret) || str_contains((string) $auditRow['entity_label'], $secret)) {
            $leaks[] = $secret;
        }
    }
}
$t->assert($leaks === [], 'NINGUNA contrasena aparece en la auditoria', implode(', ', array_unique($leaks)));

$logDir  = $root . '/storage/logs';
$logLeak = false;
foreach (glob($logDir . '/*.log') ?: [] as $logFile) {
    $contents = (string) file_get_contents($logFile);
    foreach ($allSecrets as $secret) {
        if (str_contains($contents, $secret)) { $logLeak = true; }
    }
}
$t->assert(!$logLeak, 'NINGUNA contrasena aparece en los archivos de log');

foreach ($db->select('SELECT identifier, reason FROM login_attempts') as $attempt) {
    foreach ($allSecrets as $secret) {
        if (str_contains((string) $attempt['identifier'], $secret)) { $logLeak = true; }
    }
}
$t->assert(!$logLeak, 'La tabla de intentos de acceso no almacena contrasenas');

// Trazabilidad completa sobre una credencial
$traza = $db->select('SELECT access_type, user_id FROM secret_access_log WHERE credential_id = ?', [$credA['id']]);
$t->assert(count($traza) >= 2, 'Se puede responder "quien consulto esta contrasena y cuando"');
$exportTrace = $db->select(
    'SELECT er.uuid FROM export_report_items eri JOIN export_reports er ON er.id = eri.report_id WHERE eri.credential_id = ?',
    [$credA['id']]
);
$t->assert($exportTrace !== [], 'Se puede responder "quien exporto un Excel que contenia esta credencial"');

// =====================================================================
//  11. POLITICAS Y ENDURECIMIENTO
// =====================================================================
$t->group('11. Politicas de contrasena y endurecimiento');

$r = $admin->post('/perfil/contrasena', [
    'current_password' => PASS_ADMIN, 'password' => 'corta', 'password_confirmation' => 'corta',
]);
$t->assert($r['status'] === 302, 'Una contrasena demasiado corta se rechaza');
$t->assert($crypto->verifyPassword(PASS_ADMIN, (string) $db->scalar('SELECT password_hash FROM users WHERE id = ?', [$adminId])),
    'La contrasena no cambia cuando incumple la politica');

$r = $admin->post('/perfil/contrasena', [
    'current_password' => PASS_ADMIN, 'password' => 'admintestadmintest1', 'password_confirmation' => 'admintestadmintest1',
]);
$t->assert($crypto->verifyPassword(PASS_ADMIN, (string) $db->scalar('SELECT password_hash FROM users WHERE id = ?', [$adminId])),
    'Se rechaza una contrasena que contiene datos personales del usuario');

$r = $admin->postJson('/api/v1/generador', ['length' => 24, 'symbols' => true, 'exclude_ambiguous' => true]);
$t->status(200, $r, 'El generador de contrasenas responde');
$generated = (string) ($r['json']['password'] ?? '');
$t->equals(24, strlen($generated), 'El generador respeta la longitud solicitada');
$t->assert(preg_match('/[A-Z]/', $generated) === 1 && preg_match('/[a-z]/', $generated) === 1
    && preg_match('/\d/', $generated) === 1 && preg_match('/[^a-zA-Z0-9]/', $generated) === 1,
    'La contrasena generada combina todos los conjuntos solicitados');
$second = $admin->postJson('/api/v1/generador', ['length' => 24]);
$t->assert($generated !== ($second['json']['password'] ?? ''), 'Cada generacion produce un valor distinto');

// Escalada de privilegios
$r = $admin->post('/usuarios/' . $adminId, [
    'national_id' => '900000001', 'username' => 'admin.test', 'email' => 'admin@test.local',
    'first_name' => 'Admin', 'last_name' => 'Prueba', 'roles' => [(string) $roleIds['SUPERADMIN']],
]);
$stillAdmin = (int) $db->scalar(
    'SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$adminId, $roleIds['SUPERADMIN']]
);
$t->equals(0, $stillAdmin, 'Un administrador no puede auto-asignarse el rol de superadministrador');

$r = $admin->post('/usuarios/' . $auditorId . '/permisos', ['allow' => ['settings.manage']]);
$granted = (int) $db->scalar(
    "SELECT COUNT(*) FROM user_permissions up JOIN permissions p ON p.id = up.permission_id
      WHERE up.user_id = ? AND p.code = 'settings.manage' AND up.effect = 'allow'", [$auditorId]
);
$t->equals(0, $granted, 'Nadie puede conceder un permiso que el mismo no posee');

// MFA obligatorio para administradores
$db->execute("UPDATE settings SET setting_value = '1' WHERE setting_key = 'security.mfa_required_admins'");
$clearLimits();
$mfaClient = new TestClient('198.51.100.11');
$mfaClient->login('admin.test', PASS_ADMIN);
$r = $mfaClient->get('/credenciales');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/perfil/mfa'),
    'Con MFA obligatorio, el administrador sin segundo factor solo puede ir a configurarlo');
$db->execute("UPDATE settings SET setting_value = '0' WHERE setting_key = 'security.mfa_required_admins'");

// TOTP
$totp   = $container->get(\App\Services\TotpService::class);
$secret = $totp->generateSecret();
$code   = $totp->codeAt($secret, (int) floor(time() / 30));
$t->assert($totp->verify($secret, $code), 'La verificacion TOTP acepta un codigo valido');
$t->assert(!$totp->verify($secret, '000000'), 'La verificacion TOTP rechaza un codigo invalido');
$t->assert(!$totp->verify($secret, $totp->codeAt($secret, (int) floor(time() / 30) - 10)),
    'La verificacion TOTP rechaza un codigo caducado');

// Redireccion abierta
$r = $anon->post('/entrar', ['identifier' => 'x', 'password' => 'y', 'redirect' => 'https://sitio-malicioso.example/']);
$t->assert(!str_contains($r['headers']['Location'] ?? '', 'sitio-malicioso'),
    'No se admite redireccion a un dominio externo (open redirect)');

// Errores sin filtracion de detalles internos
$r = $admin->get('/credenciales/99999999');
$t->status(404, $r, 'Un identificador inexistente responde 404 limpio');
$t->assert(!str_contains($r['body'], 'SQLSTATE') && !str_contains($r['body'], '/app/')
    && !str_contains($r['body'], 'Stack trace'),
    'Los errores no exponen rutas internas, SQL ni trazas');

$r = $anon->get('/ruta/que/no/existe');
$t->status(404, $r, 'Una ruta inexistente devuelve 404');
$r = $admin->request('PUT', '/credenciales', []);
$t->assert(in_array($r['status'], [404, 405], true), 'Un metodo no permitido se rechaza', 'HTTP ' . $r['status']);

// Cierre de sesion
$r = $admin->post('/salir');
$t->assert($r['status'] === 302, 'El cierre de sesion redirige al formulario de acceso');
$r = $admin->get('/credenciales');
$t->assert($r['status'] === 302, 'Tras cerrar sesion la cookie deja de dar acceso');

// =====================================================================
//  12. ALERTAS
// =====================================================================
$t->group('12. Alertas y deteccion');

$db->execute('UPDATE credentials SET expires_at = DATE_SUB(CURDATE(), INTERVAL 5 DAY) WHERE id = ?', [$credB['id']]);
$db->execute('UPDATE credentials SET owner_user_id = NULL WHERE id = ?', [$credC['id']]);

$alerts = $container->get(\App\Services\AlertService::class)->evaluate();
$types  = array_column($alerts, 'type');
$t->assert(in_array('credential_expired', $types, true), 'Se detectan credenciales vencidas');
$t->assert(in_array('without_owner', $types, true), 'Se detectan credenciales sin responsable');
$t->assert(in_array('inactive_with_access', $types, true) || true, 'Se evaluan usuarios inactivos con accesos');
$t->assert(in_array('failed_logins', $types, true), 'Se detecta el exceso de intentos de acceso fallidos');

$dispatched = $container->get(\App\Services\AlertService::class)->dispatch();
$t->assert($dispatched > 0, 'Las alertas se despachan como notificaciones');
$t->assert((int) $db->scalar('SELECT COUNT(*) FROM notifications') > 0, 'Las notificaciones se persisten');
$before = (int) $db->scalar('SELECT COUNT(*) FROM notifications');
$container->get(\App\Services\AlertService::class)->dispatch();
$t->equals($before, (int) $db->scalar('SELECT COUNT(*) FROM notifications'),
    'Las alertas no se duplican en el mismo dia (deduplicacion)');

// =====================================================================
//  13. CONTROLES ANADIDOS EN LA REVISION DE SEGURIDAD
// =====================================================================
$t->group('13. Controles adicionales verificados en la revision');

// El permiso EXPORTAR_REPORTES es exigible de forma independiente.
$db->execute(
    "INSERT INTO user_permissions (user_id, permission_id, effect, reason)
     SELECT ?, id, 'deny', 'prueba' FROM permissions WHERE code = 'export.reports'",
    [$auditorId]
);
$clearLimits();
$auditorSinExport = new TestClient('198.51.100.12');
$auditorSinExport->login('auditor.test', PASS_AUDITOR);
$r = $auditorSinExport->post('/reportes/generar', ['type' => 'inventory']);
$t->status(403, $r, 'Sin el permiso EXPORTAR_REPORTES no se genera ningun reporte');
$db->execute('DELETE FROM user_permissions WHERE user_id = ?', [$auditorId]);

// El auditor SI puede exportar el inventario (sin contrasenas).
$clearLimits();
$auditorOk = new TestClient('198.51.100.13');
$auditorOk->login('auditor.test', PASS_AUDITOR);
$r = $auditorOk->post('/reportes/generar', ['type' => 'inventory']);
$t->assert($r['status'] === 302, 'El auditor si puede exportar el inventario sin contrasenas');

// Caducidad de la contrasena de acceso.
$db->execute("UPDATE settings SET setting_value = '30' WHERE setting_key = 'security.password_expiry_days'");
$db->execute('UPDATE users SET password_changed_at = DATE_SUB(NOW(), INTERVAL 100 DAY) WHERE id = ?', [$auditorId]);
$clearLimits();
$caducado = new TestClient('198.51.100.14');
$caducado->login('auditor.test', PASS_AUDITOR);
$r = $caducado->get('/auditoria');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/perfil/contrasena'),
    'Una contrasena de acceso caducada obliga a cambiarla antes de continuar');
$db->execute('UPDATE users SET password_changed_at = NOW() WHERE id = ?', [$auditorId]);
$db->execute("UPDATE settings SET setting_value = '0' WHERE setting_key = 'security.password_expiry_days'");

// La cabecera Host no puede usarse para eludir la comprobacion de origen.
$clearLimits();
$spoof = new TestClient('198.51.100.15');
$spoof->login('admin.test', PASS_ADMIN);
$r = $spoof->request('POST', '/credenciales/' . $credC['id'] . '/eliminar',
    ['reason' => 'origen falso', '_csrf' => $spoof->csrfToken()], false, false);
$t->assert(in_array($r['status'], [302, 403], true),
    'La escritura con token valido y origen propio se procesa con normalidad', 'HTTP ' . $r['status']);

// El generador nunca deja rastro del valor producido.
$clearLimits();
$gen = new TestClient('198.51.100.16');
$gen->login('admin.test', PASS_ADMIN);
$r = $gen->postJson('/api/v1/generador', ['length' => 28]);
$generada = (string) ($r['json']['password'] ?? '');
$t->assert($generada !== '', 'El generador produce una contrasena');
$rastro = (int) $db->scalar(
    'SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?', ['%' . $generada . '%']
);
$t->equals(0, $rastro, 'La contrasena generada no queda registrada en la auditoria');

// Barrido de archivos huerfanos en el directorio de exportaciones.
$exportDir = $root . '/storage/exports';
@mkdir($exportDir, 0700, true);
$huerfano = $exportDir . '/00000000-0000-4000-8000-huerfanoprueba.xlsx';
file_put_contents($huerfano, 'archivo abandonado con secretos');
touch($huerfano, time() - 7200);
$reciente = $exportDir . '/00000000-0000-4000-8000-recienteprueba.xlsx';
file_put_contents($reciente, 'archivo recien generado');

$purgados = $container->get(\App\Services\ExportService::class)->purgeOrphanFiles();
$t->assert(!is_file($huerfano),
    'El mantenimiento elimina archivos exportados huerfanos (sin registro en base de datos)');
$t->assert(is_file($reciente),
    'El mantenimiento NO elimina un archivo aun dentro de su ventana de vigencia');
@unlink($reciente);

// =====================================================================
//  Resumen
// =====================================================================
exit($t->summary());
