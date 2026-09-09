<?php
declare(strict_types=1);

/**
 * Consola de administracion del sistema.
 *
 *   php bin/console.php key:generate      Genera la clave maestra en .env
 *   php bin/console.php install           Crea la base de datos, el esquema y el superadministrador
 *   php bin/console.php migrate           Aplica el esquema y los datos de referencia
 *   php bin/console.php seed:demo         Carga datos de ejemplo (solo entornos de prueba)
 *   php bin/console.php user:create       Crea un usuario de forma interactiva
 *   php bin/console.php alerts:run        Evalua y despacha las alertas
 *   php bin/console.php maintenance       Purga exportaciones, sesiones y limitadores vencidos
 *   php bin/console.php key:rotate        Rota la version del llavero y re-cifra los secretos
 *   php bin/console.php doctor            Diagnostico de la instalacion
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esta herramienta solo puede ejecutarse desde la linea de comandos.\n");
}

$root = dirname(__DIR__);

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Repositories\UserRepository;
use App\Support\Migrator;
use App\Services\AlertService;
use App\Services\AuthContext;
use App\Services\CryptoService;
use App\Services\ExportService;
use App\Services\PasswordGeneratorService;
use App\Services\RateLimiter;
use App\Services\SessionService;

function out(string $message = ''): void   { echo $message . PHP_EOL; }
function ok(string $message): void         { echo "\033[32m✔\033[0m " . $message . PHP_EOL; }
function warn(string $message): void       { echo "\033[33m!\033[0m " . $message . PHP_EOL; }
function fail(string $message): void       { echo "\033[31m✘\033[0m " . $message . PHP_EOL; }
function title(string $message): void      { echo PHP_EOL . "\033[1m" . $message . "\033[0m" . PHP_EOL . str_repeat('-', 62) . PHP_EOL; }

function ask(string $question, string $default = '', bool $hidden = false): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $question . $suffix . ': ';
    if ($hidden && DIRECTORY_SEPARATOR !== '\\') {
        @shell_exec('stty -echo');
        $answer = trim((string) fgets(STDIN));
        @shell_exec('stty echo');
        echo PHP_EOL;
    } else {
        $answer = trim((string) fgets(STDIN));
    }
    return $answer === '' ? $default : $answer;
}

$command = $argv[1] ?? 'help';

// --------------------------------------------------------------------
//  key:generate  (no requiere base de datos)
// --------------------------------------------------------------------
if ($command === 'key:generate') {
    $envPath = $root . '/.env';
    $master  = base64_encode(random_bytes(32));
    $pepper  = base64_encode(random_bytes(32));

    if (!is_file($envPath)) {
        $example = $root . '/.env.example';
        if (is_file($example)) {
            copy($example, $envPath);
        } else {
            file_put_contents($envPath, "APP_ENV=production\n");
        }
    }
    $contents = (string) file_get_contents($envPath);

    foreach (['APP_MASTER_KEY' => $master, 'APP_PEPPER' => $pepper] as $key => $value) {
        if (preg_match('/^' . $key . '=(.*)$/m', $contents, $m) === 1) {
            if (trim($m[1]) !== '') {
                warn($key . ' ya tiene valor. NO se sobrescribe: rotarla invalidaria los secretos existentes.');
                continue;
            }
            $contents = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $contents) ?? $contents;
        } else {
            $contents .= PHP_EOL . $key . '=' . $value;
        }
        ok($key . ' generada.');
    }
    file_put_contents($envPath, $contents);
    chmod($envPath, 0600);
    ok('.env actualizado con permisos 0600.');
    out();
    warn('GUARDE UNA COPIA SEGURA DE APP_MASTER_KEY.');
    warn('Sin esa clave los secretos cifrados NO se pueden recuperar. Nadie, ni el desarrollador.');
    exit(0);
}

/** @var \App\Core\Container $container */
$container = require $root . '/app/bootstrap.php';

// --------------------------------------------------------------------
//  install / migrate
// --------------------------------------------------------------------
if ($command === 'install' || $command === 'migrate') {
    title('Instalacion del Sistema de Gestion de Credenciales');

    if (!Env::has('APP_MASTER_KEY')) {
        fail('Falta APP_MASTER_KEY. Ejecute primero: php bin/console.php key:generate');
        exit(1);
    }

    $cfg      = Config::get('database');
    $database = (string) $cfg['database'];

    // Conexion sin base de datos seleccionada para poder crearla.
    $dsn = !empty($cfg['socket'])
        ? sprintf('mysql:unix_socket=%s;charset=utf8mb4', $cfg['socket'])
        : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], (int) $cfg['port']);

    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        fail('No fue posible conectar con MySQL/MariaDB. Revise config/database.php y el .env.');
        exit(1);
    }

    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', $database)
    ));
    ok('Base de datos `' . $database . '` disponible.');

    $db       = Database::instance();
    $migrator = new Migrator($db, $root . '/database/migrations');

    $drift = $migrator->drifted();
    if ($drift !== []) {
        warn('Migraciones ya aplicadas que fueron modificadas despues: ' . implode(', ', $drift));
        warn('Los entornos pueden haber divergido. Cree una migracion nueva en lugar de editar una existente.');
    }

    $pending = $migrator->pending();
    if ($pending === []) {
        ok('La base de datos ya esta al dia (sin migraciones pendientes).');
    } else {
        out('Migraciones pendientes: ' . count($pending));
        try {
            $migrator->run(static function (string $file, int $statements): void {
                ok($file . '  (' . $statements . ' sentencias)');
            });
        } catch (RuntimeException $e) {
            fail($e->getMessage());
            exit(1);
        }
    }

    /** @var CryptoService $crypto */
    $crypto = $container->get(CryptoService::class);
    $version = $crypto->activeKeyVersion();
    ok('Llavero de cifrado activo, version ' . $version . '.');

    if ($command === 'migrate') {
        ok('Migracion completada.');
        exit(0);
    }

    // ---------------- Superadministrador ----------------
    $existing = (int) $db->scalar('SELECT COUNT(*) FROM users');
    if ($existing > 0) {
        warn('Ya existen usuarios. No se crea el superadministrador.');
        ok('Instalacion completada.');
        exit(0);
    }

    title('Creacion del superadministrador');
    $nationalId = ask('Cedula / documento', '1000000000');
    $firstName  = ask('Nombres', 'Administrador');
    $lastName   = ask('Apellidos', 'del Sistema');
    $email      = ask('Correo corporativo', 'admin@empresa.local');
    $username   = ask('Nombre de usuario', 'admin');
    $company    = ask('Nombre de la empresa', (string) Config::get('app.organization', 'Mi Empresa'));

    /** @var PasswordGeneratorService $generator */
    $generator = $container->get(PasswordGeneratorService::class);
    $password  = $generator->generate(['length' => 20, 'exclude_ambiguous' => true]);

    $companyId = $db->insert('INSERT INTO companies (name) VALUES (?)', [$company]);
    $hash      = $crypto->hashPassword($password);

    $userId = $db->insert(
        'INSERT INTO users (national_id, username, email, first_name, last_name, company_id,
                            password_hash, password_algo, password_changed_at, must_change_password, status, mfa_enforced)
         VALUES (?,?,?,?,?,?,?,?,NOW(),1,"active",1)',
        [$nationalId, strtolower($username), strtolower($email), $firstName, $lastName, $companyId, $hash['hash'], $hash['algo']]
    );
    $roleId = (int) $db->scalar("SELECT id FROM roles WHERE code = 'SUPERADMIN'");
    $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$userId, $roleId]);

    $db->insert(
        'INSERT INTO audit_logs (user_id, actor_national_id, actor_name, action, entity_type, entity_id,
                                 entity_label, result, severity, ip_address, details)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$userId, $nationalId, $firstName . ' ' . $lastName, 'system.installed', 'system', null,
         'Instalacion inicial', 'success', 'critical', '127.0.0.1',
         json_encode(['superadministrador' => $username], JSON_UNESCAPED_UNICODE)]
    );

    out();
    ok('Instalacion completada.');
    out();
    out('  Usuario:    ' . strtolower($username));
    out('  Contrasena: ' . $password);
    out();
    warn('Anote la contrasena AHORA: no vuelve a mostrarse y debera cambiarla al ingresar.');
    warn('El superadministrador tiene MFA obligatorio: configurelo en su primer acceso.');
    exit(0);
}

// --------------------------------------------------------------------
//  Resto de comandos (requieren instalacion previa)
// --------------------------------------------------------------------
$db = Database::instance();

switch ($command) {

    case 'alerts:run':
        /** @var AlertService $alerts */
        $alerts = $container->get(AlertService::class);
        $count  = $alerts->dispatch();
        ok($count . ' tipo(s) de alerta despachados.');
        break;

    case 'maintenance':
        title('Mantenimiento');
        /** @var ExportService $exports */
        $exports = $container->get(ExportService::class);
        ok($exports->purgeExpiredFiles() . ' archivo(s) de exportacion purgados.');
        /** @var SessionService $sessions */
        $sessions = $container->get(SessionService::class);
        ok($sessions->purgeExpired() . ' sesion(es) marcadas como expiradas.');
        /** @var RateLimiter $limiter */
        $limiter = $container->get(RateLimiter::class);
        ok($limiter->purgeExpired() . ' cubo(s) de limitacion liberados.');
        $db->execute('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 180 DAY)');
        $db->execute('DELETE FROM password_resets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        ok('Registros temporales antiguos eliminados (la auditoria se conserva integra).');
        break;

    case 'key:rotate':
        title('Rotacion del llavero de cifrado');
        warn('Se creara una nueva version de clave y se re-cifraran TODOS los secretos.');
        if (strtolower(ask('Escriba "SI" para continuar')) !== 'si') {
            out('Cancelado.');
            break;
        }
        /** @var CryptoService $crypto */
        $crypto = $container->get(CryptoService::class);
        $newVersion = $crypto->createKeyVersion();
        ok('Nueva version de clave: ' . $newVersion);

        $rows    = $db->select('SELECT * FROM credential_secrets ORDER BY id');
        $rotated = 0;
        $failed  = 0;
        foreach ($rows as $row) {
            $aad = $crypto->aad('credential', (int) $row['credential_id'], (string) $row['field'], (int) $row['version']);
            $new = $crypto->rewrap($row, $aad);
            if ($new === null) { $failed++; continue; }
            $db->execute(
                'UPDATE credential_secrets
                    SET key_version = ?, ciphertext = ?, nonce = ?, tag = ?, wrapped_dek = ?, dek_nonce = ?, dek_tag = ?
                  WHERE id = ?',
                [$new['key_version'], $new['ciphertext'], $new['nonce'], $new['tag'],
                 $new['wrapped_dek'], $new['dek_nonce'], $new['dek_tag'], (int) $row['id']]
            );
            $rotated++;
        }
        foreach ($db->select('SELECT * FROM mfa_secrets') as $row) {
            $aad = $crypto->aad('user', (int) $row['user_id'], 'mfa_secret');
            $new = $crypto->rewrap($row, $aad);
            if ($new === null) { $failed++; continue; }
            $db->execute(
                'UPDATE mfa_secrets
                    SET key_version = ?, ciphertext = ?, nonce = ?, tag = ?, wrapped_dek = ?, dek_nonce = ?, dek_tag = ?
                  WHERE user_id = ?',
                [$new['key_version'], $new['ciphertext'], $new['nonce'], $new['tag'],
                 $new['wrapped_dek'], $new['dek_nonce'], $new['dek_tag'], (int) $row['user_id']]
            );
            $rotated++;
        }
        ok($rotated . ' secreto(s) re-cifrados.');
        if ($failed > 0) { fail($failed . ' secreto(s) NO pudieron re-cifrarse. Revise la clave maestra.'); }
        break;

    case 'user:create':
        title('Crear usuario');
        /** @var UserRepository $users */
        $users     = $container->get(UserRepository::class);
        /** @var CryptoService $crypto */
        $crypto    = $container->get(CryptoService::class);
        /** @var PasswordGeneratorService $generator */
        $generator = $container->get(PasswordGeneratorService::class);

        $nationalId = ask('Cedula');
        $username   = strtolower(ask('Usuario'));
        $email      = strtolower(ask('Correo'));
        $firstName  = ask('Nombres');
        $lastName   = ask('Apellidos');

        out('Roles disponibles:');
        foreach ($db->select('SELECT id, code, name FROM roles ORDER BY level DESC') as $role) {
            out('  ' . $role['id'] . ') ' . $role['name'] . ' (' . $role['code'] . ')');
        }
        $roleId = (int) ask('Id del rol', '4');

        $password = $generator->generate(['length' => 18, 'exclude_ambiguous' => true]);
        $hash     = $crypto->hashPassword($password);
        $userId   = $users->create([
            'national_id'   => $nationalId, 'username' => $username, 'email' => $email,
            'first_name'    => $firstName,  'last_name' => $lastName,
            'password_hash' => $hash['hash'], 'password_algo' => $hash['algo'],
        ]);
        $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$userId, $roleId]);

        ok('Usuario creado con id ' . $userId);
        out('  Contrasena temporal: ' . $password);
        break;

    case 'doctor':
        title('Diagnostico de la instalacion');
        $checks = [
            'PHP >= 8.1'                  => version_compare(PHP_VERSION, '8.1.0', '>='),
            'Extension openssl'           => extension_loaded('openssl'),
            'AES-256-GCM disponible'      => in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
            'Extension pdo_mysql'         => extension_loaded('pdo_mysql'),
            'Extension zip (Excel)'       => class_exists('ZipArchive'),
            'Extension mbstring'          => extension_loaded('mbstring'),
            'APP_MASTER_KEY definida'     => Env::has('APP_MASTER_KEY'),
            '.env existe y no es escribible por terceros' =>
                is_file($root . '/.env') && (fileperms($root . '/.env') & 0022) === 0,
            'storage escribible'          => is_writable($root . '/storage'),
            'exports fuera del webroot'   => !str_starts_with((string) Config::get('paths.exports'), $root . '/public'),
            'display_errors desactivado'  => ini_get('display_errors') === '0' || Config::get('app.debug') === true,
            'Conexion a la base de datos' => (function () use ($db): bool {
                try { $db->scalar('SELECT 1'); return true; } catch (Throwable) { return false; }
            })(),
        ];
        foreach ($checks as $label => $passed) {
            $passed ? ok($label) : fail($label);
        }
        // El .env contiene la clave maestra: solo el usuario del servicio web
        // deberia poder leerlo. En XAMPP suele quedar legible por todos.
        $envPerms = is_file($root . '/.env') ? (fileperms($root . '/.env') & 0777) : 0;
        if ($envPerms !== 0600 && $envPerms !== 0400) {
            warn(sprintf('.env tiene permisos 0%o. En produccion ejecute (como root):', $envPerms));
            out('    chown <usuario-del-servidor-web> ' . $root . '/.env && chmod 400 ' . $root . '/.env');
        }

        $secrets = (int) $db->scalar('SELECT COUNT(*) FROM credential_secrets');
        $plain   = 0;
        foreach ($db->select('SELECT ciphertext FROM credential_secrets LIMIT 200') as $row) {
            $value = is_resource($row['ciphertext']) ? stream_get_contents($row['ciphertext']) : (string) $row['ciphertext'];
            if (mb_check_encoding($value, 'UTF-8') && preg_match('/^[\x20-\x7E]{4,}$/', $value) === 1) {
                $plain++;
            }
        }
        out();
        ok($secrets . ' secreto(s) almacenados.');
        $plain === 0
            ? ok('Ningun secreto presenta aspecto de texto plano.')
            : fail($plain . ' secreto(s) podrian no estar cifrados. REVISAR DE INMEDIATO.');
        break;

    case 'seed:demo':
        require $root . '/database/demo.php';
        break;

    case 'migrate:status':
        title('Estado de las migraciones');
        $migrator = new Migrator($db, $root . '/database/migrations');
        $applied  = $migrator->applied();
        foreach ($migrator->available() as $m) {
            $record = $applied[$m['version']] ?? null;
            if ($record === null) {
                warn(sprintf('%-40s PENDIENTE', $m['filename']));
            } elseif ($record['checksum'] !== $m['checksum']) {
                fail(sprintf('%-40s MODIFICADA DESPUES DE APLICARSE (%s)', $m['filename'], $record['applied_at']));
            } else {
                ok(sprintf('%-40s aplicada %s', $m['filename'], $record['applied_at']));
            }
        }
        out();
        out('Pendientes: ' . count($migrator->pending()));
        break;

    case 'db:clean':
        title('Limpieza de datos operativos');
        warn('Se ELIMINARAN: empresas, sedes, departamentos, sistemas, credenciales,');
        warn('secretos, asignaciones, historial, auditoria, sesiones, notificaciones,');
        warn('exportaciones y TODOS los usuarios excepto un superadministrador.');
        out();
        out('Se CONSERVAN: roles, permisos, categorias, parametros y el llavero de cifrado.');
        out();

        $force = in_array('--force', $argv, true);
        if (!$force && strtoupper(ask('Escriba LIMPIAR para confirmar')) !== 'LIMPIAR') {
            out('Cancelado. No se modifico nada.');
            break;
        }

        // Se conserva el superadministrador de menor id; si no existe, se aborta
        // para no dejar el sistema sin ninguna cuenta de acceso.
        $keep = $db->selectOne(
            "SELECT u.id, u.username, u.national_id, u.email, u.first_name, u.last_name
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.code = 'SUPERADMIN'
              ORDER BY u.id LIMIT 1"
        );
        if ($keep === null) {
            fail('No hay ningun superadministrador. Ejecute primero: php bin/console.php install');
            break;
        }
        $keepId = (int) $keep['id'];

        // TRUNCATE provoca un commit implicito en MySQL, de modo que no puede
        // envolverse en una transaccion: se ejecuta de forma secuencial con
        // las comprobaciones de clave ajena desactivadas.
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'export_report_items', 'export_reports', 'secret_access_log', 'credential_history',
            'credential_assignments', 'credential_secrets', 'credentials', 'systems',
            'audit_logs', 'login_attempts', 'security_events', 'notifications',
            'password_resets', 'mfa_backup_codes', 'mfa_secrets', 'rate_limits',
            'departments', 'locations', 'companies',
        ] as $table) {
            $db->pdo()->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $db->execute('DELETE FROM sessions WHERE user_id <> ?', [$keepId]);
        $db->execute('DELETE FROM user_permissions WHERE user_id <> ?', [$keepId]);
        $db->execute('DELETE FROM user_roles WHERE user_id <> ?', [$keepId]);
        $db->execute('DELETE FROM users WHERE id <> ?', [$keepId]);
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Contrasena temporal nueva para el superadministrador conservado.
        /** @var PasswordGeneratorService $generator */
        $generator = $container->get(PasswordGeneratorService::class);
        /** @var CryptoService $crypto */
        $crypto = $container->get(CryptoService::class);
        $plain  = $generator->generate(['length' => 20, 'exclude_ambiguous' => true]);
        $hash   = $crypto->hashPassword($plain);
        $db->execute(
            'UPDATE users SET password_hash = ?, password_algo = ?, password_changed_at = NOW(),
                              must_change_password = 1, company_id = NULL, location_id = NULL,
                              department_id = NULL, failed_attempts = 0, locked_until = NULL,
                              mfa_enabled = 0
              WHERE id = ?',
            [$hash['hash'], $hash['algo'], $keepId]
        );
        $db->execute('UPDATE sessions SET status = ? WHERE status = ?', ['revoked', 'active']);

        out();
        ok('Base de datos limpia.');
        out();
        out('  Usuario:    ' . $keep['username']);
        out('  Contrasena: ' . $plain);
        out();
        warn('Anotela: no vuelve a mostrarse y debera cambiarla al ingresar.');
        break;

    default:
        out('Comandos disponibles:');
        out('  key:generate    Genera APP_MASTER_KEY y APP_PEPPER en .env');
        out('  install         Instalacion completa (base de datos + superadministrador)');
        out('  migrate         Aplica las migraciones pendientes');
        out('  migrate:status  Muestra que migraciones estan aplicadas y cuales faltan');
        out('  seed:demo       Carga datos de ejemplo (NO usar en produccion)');
        out('  db:clean        Borra datos operativos y deja solo el superadministrador');
        out('  user:create     Crea un usuario');
        out('  alerts:run      Evalua y despacha alertas (programar en cron)');
        out('  maintenance     Purga archivos, sesiones y limitadores vencidos (cron)');
        out('  key:rotate      Rota el llavero y re-cifra los secretos');
        out('  doctor          Diagnostico de la instalacion');
        break;
}
