<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\UserRepository;

/**
 * Autenticacion y ciclo de vida de la identidad.
 *
 * Decisiones de seguridad relevantes:
 *  - La cedula identifica al empleado pero NUNCA autentica por si sola:
 *    siempre se exige la contrasena personal (y MFA cuando corresponda).
 *  - Los mensajes de error son genericos: no revelan si un usuario existe
 *    (mitigacion de enumeracion de cuentas).
 *  - Se ejecuta un hash "senuelo" cuando el usuario no existe, para que el
 *    tiempo de respuesta no delate la existencia de la cuenta.
 *  - Doble freno a la fuerza bruta: limitador por IP/identificador y
 *    bloqueo de cuenta por intentos fallidos.
 */
final class AuthService
{
    private const GENERIC_ERROR = 'Las credenciales proporcionadas no son validas.';

    public function __construct(
        private Database $db,
        private UserRepository $users,
        private CryptoService $crypto,
        private SessionService $sessions,
        private SettingsService $settings,
        private AuditService $audit,
        private RateLimiter $limiter,
        private TotpService $totp,
        private AuthContext $context
    ) {
    }

    /**
     * @return array{status:string,message?:string,session?:array,token?:string,user?:array}
     */
    public function attemptLogin(string $identifier, string $password, string $ip, string $userAgent, string $device): array
    {
        $identifier = trim($identifier);

        // --- Freno 1: limitador de frecuencia por IP y por identificador ---
        $ipBucket   = 'login:ip:' . $ip;
        $userBucket = 'login:id:' . mb_strtolower($identifier);
        if (!$this->limiter->attempt($ipBucket, 20, 300) || !$this->limiter->attempt($userBucket, 10, 300)) {
            $this->audit->logLoginAttempt($identifier, null, 'locked', 'rate_limited');
            $this->audit->log(
                AuditService::LOGIN_BLOCKED, 'user', null, $identifier, 'denied',
                ['motivo' => 'limite de intentos por IP/identificador'], 'warning'
            );
            $this->audit->securityEvent(
                'brute_force', 'Exceso de intentos de inicio de sesion',
                'Se bloqueo temporalmente el acceso desde ' . $ip, 'high'
            );
            throw HttpException::tooManyRequests(
                'Demasiados intentos. Espere ' . max(1, (int) ceil($this->limiter->retryAfter($ipBucket) / 60)) . ' minuto(s).'
            );
        }

        $record = $identifier === '' ? null : $this->users->findAuthRecord($identifier);

        if ($record === null) {
            // Trabajo equivalente para no filtrar la existencia de la cuenta.
            $this->crypto->verifyPassword($password, '$2y$12$usuarioinexistenteusuarioinexistenteusuarioinexiste12345678');
            $this->audit->logLoginAttempt($identifier, null, 'failed', 'unknown_identifier');
            return ['status' => 'error', 'message' => self::GENERIC_ERROR];
        }

        $userId = (int) $record['id'];

        // --- Cuenta bloqueada temporalmente ---
        if ($record['locked_until'] !== null && strtotime((string) $record['locked_until']) > time()) {
            $this->audit->logLoginAttempt($identifier, $userId, 'locked', 'account_locked');
            $this->audit->log(AuditService::LOGIN_BLOCKED, 'user', $userId, $record['username'], 'denied',
                ['motivo' => 'cuenta bloqueada temporalmente'], 'warning', $userId,
                $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);
            $minutes = max(1, (int) ceil((strtotime((string) $record['locked_until']) - time()) / 60));
            return ['status' => 'error', 'message' => "La cuenta esta bloqueada temporalmente. Intente en {$minutes} minuto(s)."];
        }

        // --- Verificacion de la contrasena ---
        if (!$this->crypto->verifyPassword($password, (string) $record['password_hash'])) {
            $maxAttempts = $this->settings->int('security.max_login_attempts', 5);
            $lockMinutes = $this->settings->int('security.lockout_minutes', 15);
            $attempts    = $this->users->incrementFailedAttempts($userId, $maxAttempts, $lockMinutes);

            $this->audit->logLoginAttempt($identifier, $userId, 'failed', 'bad_password');
            $this->audit->log(AuditService::LOGIN_FAILED, 'user', $userId, $record['username'], 'failure',
                ['intentos_fallidos' => $attempts], 'notice', $userId,
                $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);

            if ($attempts >= $maxAttempts) {
                $this->audit->securityEvent(
                    'account_locked',
                    'Cuenta bloqueada por intentos fallidos',
                    sprintf('El usuario %s supero %d intentos fallidos.', $record['username'], $maxAttempts),
                    'high',
                    $userId
                );
            }
            return ['status' => 'error', 'message' => self::GENERIC_ERROR];
        }

        // --- Estado de la cuenta (despues de validar la clave, para no filtrar estados) ---
        if ($record['status'] !== 'active') {
            $this->audit->logLoginAttempt($identifier, $userId, 'failed', 'status_' . $record['status']);
            $this->audit->log(AuditService::LOGIN_BLOCKED, 'user', $userId, $record['username'], 'denied',
                ['estado' => $record['status']], 'warning', $userId,
                $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);
            return ['status' => 'error', 'message' => 'Su cuenta no se encuentra activa. Contacte al administrador.'];
        }

        // Credenciales correctas: se limpian los frenos.
        $this->limiter->clear($userBucket);
        $this->users->clearLock($userId);

        // Re-hash oportunista si cambiaron los parametros de coste.
        if ($this->crypto->needsRehash((string) $record['password_hash'])) {
            $new = $this->crypto->hashPassword($password);
            $this->users->updatePassword($userId, $new['hash'], $new['algo'], (bool) $record['must_change_password']);
        }

        // --- MFA obligatorio? ---
        $requiresMfa = $this->userRequiresMfa($userId, (bool) $record['mfa_enforced']);
        $hasMfa      = (bool) $record['mfa_enabled'];
        $pendingMfa  = $hasMfa;

        $created = $this->sessions->create($userId, $ip, $userAgent, $device, $pendingMfa);
        $this->users->registerSuccessfulLogin($userId, $ip);
        $this->audit->logLoginAttempt($identifier, $userId, $pendingMfa ? 'mfa_required' : 'success');

        $user = $this->users->find($userId) ?? [];

        if ($pendingMfa) {
            $this->audit->log(AuditService::MFA_CHALLENGE, 'user', $userId, $record['username'], 'success',
                [], 'info', $userId, $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);
            return [
                'status'  => 'mfa_required',
                'session' => $created['session'],
                'token'   => $created['token'],
                'user'    => $user,
            ];
        }

        $this->audit->log(AuditService::LOGIN, 'user', $userId, $record['username'], 'success',
            ['dispositivo' => $device], 'info', $userId,
            $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);

        return [
            'status'               => $requiresMfa && !$hasMfa ? 'mfa_setup_required' : 'ok',
            'session'              => $created['session'],
            'token'                => $created['token'],
            'user'                 => $user,
            'must_change_password' => (bool) $record['must_change_password'],
        ];
    }

    /** Un rol con requires_mfa=1 o la marca individual obligan al segundo factor. */
    public function userRequiresMfa(int $userId, bool $enforcedFlag = false): bool
    {
        if ($enforcedFlag) {
            return true;
        }
        if (!$this->settings->bool('security.mfa_required_admins', true)) {
            return false;
        }
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND r.requires_mfa = 1',
            [$userId]
        ) > 0;
    }

    /**
     * Segundo factor. Acepta codigo TOTP o codigo de respaldo de un solo uso.
     *
     * @return array{status:string,message?:string,token?:string,session?:array}
     */
    public function verifyMfa(array $session, string $code): array
    {
        $userId = (int) $session['user_id'];
        $bucket = 'mfa:' . $userId;
        if (!$this->limiter->attempt($bucket, 8, 300)) {
            $this->audit->securityEvent('mfa_brute_force', 'Multiples codigos MFA incorrectos',
                'Usuario ' . $userId, 'high', $userId);
            throw HttpException::tooManyRequests('Demasiados intentos de verificacion. Espere unos minutos.');
        }

        $secret = $this->mfaSecret($userId);
        $valid  = $secret !== null && $this->totp->verify($secret, $code);

        if (!$valid) {
            $valid = $this->consumeBackupCode($userId, $code);
        }

        if (!$valid) {
            $this->audit->logLoginAttempt((string) $userId, $userId, 'mfa_failed');
            $this->audit->log(AuditService::MFA_FAILED, 'user', $userId, null, 'failure', [], 'warning');
            return ['status' => 'error', 'message' => 'El codigo de verificacion no es valido.'];
        }

        $this->limiter->clear($bucket);
        $this->sessions->markMfaVerified((string) $session['id']);
        // Rotacion del identificador tras elevar el nivel de autenticacion.
        $rotated = $this->sessions->rotate((string) $session['id']);

        // El contexto aun no esta autenticado en esta peticion: los datos del
        // actor se pasan explicitamente para que la auditoria no quede anonima.
        $record = $this->users->find($userId);
        $this->audit->log(
            AuditService::LOGIN, 'user', $userId, $record['username'] ?? null, 'success', ['mfa' => true], 'info',
            $userId,
            $record !== null ? trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')) : null,
            $record['national_id'] ?? null
        );
        $this->audit->logLoginAttempt((string) ($record['username'] ?? $userId), $userId, 'success', 'mfa_ok');

        return ['status' => 'ok', 'token' => $rotated['token'], 'session' => $rotated['session']];
    }

    /** Reautenticacion (step-up) previa a operaciones sensibles. */
    public function reauthenticate(int $userId, string $password, ?string $mfaCode = null): bool
    {
        $bucket = 'reauth:' . $userId;
        if (!$this->limiter->attempt($bucket, 10, 300)) {
            throw HttpException::tooManyRequests('Demasiados intentos de confirmacion.');
        }
        $record = $this->users->findAuthRecordById($userId);
        if ($record === null || !$this->crypto->verifyPassword($password, (string) $record['password_hash'])) {
            $this->audit->log(AuditService::REAUTH_FAILED, 'user', $userId, null, 'failure', [], 'warning');
            return false;
        }
        if ((bool) $record['mfa_enabled'] && $mfaCode !== null && $mfaCode !== '') {
            $secret = $this->mfaSecret($userId);
            if ($secret === null || !$this->totp->verify($secret, $mfaCode)) {
                $this->audit->log(AuditService::REAUTH_FAILED, 'user', $userId, null, 'failure', ['mfa' => false], 'warning');
                return false;
            }
        }
        $this->limiter->clear($bucket);
        $this->audit->log(AuditService::REAUTH, 'user', $userId, null, 'success');
        return true;
    }

    public function logout(string $sessionId, ?int $userId): void
    {
        $this->sessions->revoke($sessionId, $userId, 'cierre de sesion del usuario');
        $this->audit->log(AuditService::LOGOUT, 'session', $sessionId, null, 'success');
    }

    // -----------------------------------------------------------------
    //  Contrasenas de acceso al sistema
    // -----------------------------------------------------------------

    /** Politica de contrasenas para el acceso AL SISTEMA. */
    public function validatePasswordPolicy(string $password, array $userData = []): void
    {
        $min    = $this->settings->int('security.password_min_length', 12);
        $errors = [];

        if (mb_strlen($password) < $min) {
            $errors['password'] = "La contrasena debe tener al menos {$min} caracteres.";
        } elseif (mb_strlen($password) > 200) {
            $errors['password'] = 'La contrasena no puede superar 200 caracteres.';
        } elseif (
            preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
            || preg_match('/[^a-zA-Z0-9]/', $password) !== 1
        ) {
            $errors['password'] = 'La contrasena debe combinar mayusculas, minusculas, numeros y un caracter especial.';
        }

        if ($errors === []) {
            foreach (['username', 'email', 'national_id', 'first_name', 'last_name'] as $field) {
                $value = (string) ($userData[$field] ?? '');
                if ($value !== '' && mb_strlen($value) >= 4 && stripos($password, $value) !== false) {
                    $errors['password'] = 'La contrasena no puede contener sus datos personales.';
                    break;
                }
            }
        }

        if ($errors === []) {
            $common = ['password', 'contrasena', '12345678', 'qwerty', 'admin123', 'bienvenido', 'empresa123'];
            foreach ($common as $needle) {
                if (stripos($password, $needle) !== false) {
                    $errors['password'] = 'La contrasena contiene un patron demasiado comun.';
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public function changeOwnPassword(int $userId, string $current, string $new, string $confirmation): void
    {
        $record = $this->users->findAuthRecordById($userId);
        if ($record === null) {
            throw HttpException::unauthorized();
        }
        if (!$this->crypto->verifyPassword($current, (string) $record['password_hash'])) {
            throw new ValidationException(['current_password' => 'La contrasena actual no es correcta.']);
        }
        if (!hash_equals($new, $confirmation)) {
            throw new ValidationException(['password_confirmation' => 'La confirmacion no coincide.']);
        }
        if ($this->crypto->verifyPassword($new, (string) $record['password_hash'])) {
            throw new ValidationException(['password' => 'La nueva contrasena debe ser distinta de la actual.']);
        }
        $this->validatePasswordPolicy($new, $record);

        $hash = $this->crypto->hashPassword($new);
        $this->users->updatePassword($userId, $hash['hash'], $hash['algo'], false);
        // Cambiar la contrasena invalida el resto de sesiones del usuario.
        $this->sessions->revokeAllForUser($userId, $userId, 'cambio de contrasena', $this->context->sessionId());
        $this->audit->log(AuditService::PASSWORD_CHANGED, 'user', $userId, null, 'success', [], 'notice');
    }

    // -----------------------------------------------------------------
    //  MFA: alta y baja
    // -----------------------------------------------------------------

    public function mfaSecret(int $userId): ?string
    {
        $row = $this->db->selectOne('SELECT * FROM mfa_secrets WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return null;
        }
        return $this->crypto->decrypt($row, $this->crypto->aad('user', $userId, 'mfa_secret'));
    }

    /** @return array{secret:string,uri:string} */
    public function beginMfaEnrollment(int $userId, string $account, string $issuer): array
    {
        $secret   = $this->totp->generateSecret();
        $envelope = $this->crypto->encrypt($secret, $this->crypto->aad('user', $userId, 'mfa_secret'));
        $this->db->execute(
            'REPLACE INTO mfa_secrets (user_id, key_version, ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $userId, $envelope['key_version'], $envelope['ciphertext'], $envelope['nonce'],
                $envelope['tag'], $envelope['wrapped_dek'], $envelope['dek_nonce'], $envelope['dek_tag'],
            ]
        );
        return ['secret' => $secret, 'uri' => $this->totp->provisioningUri($secret, $account, $issuer)];
    }

    /** @return array<int,string> codigos de respaldo generados */
    public function confirmMfaEnrollment(int $userId, string $code): array
    {
        $secret = $this->mfaSecret($userId);
        if ($secret === null || !$this->totp->verify($secret, $code)) {
            throw new ValidationException(['code' => 'El codigo no es valido. Verifique la hora de su dispositivo.']);
        }
        $codes = $this->totp->generateBackupCodes();
        $this->db->transaction(function (Database $db) use ($userId, $codes): void {
            $db->execute('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$userId]);
            foreach ($codes as $code) {
                $db->execute(
                    'INSERT INTO mfa_backup_codes (user_id, code_hash) VALUES (?,?)',
                    [$userId, password_hash($code, PASSWORD_BCRYPT, ['cost' => 10])]
                );
            }
            $db->execute('UPDATE mfa_secrets SET confirmed_at = NOW() WHERE user_id = ?', [$userId]);
            $db->execute('UPDATE users SET mfa_enabled = 1 WHERE id = ?', [$userId]);
        });
        $this->audit->log(AuditService::MFA_ENABLED, 'user', $userId, null, 'success', [], 'notice');
        return $codes;
    }

    public function disableMfa(int $userId, int $performedBy): void
    {
        $this->db->transaction(function (Database $db) use ($userId): void {
            $db->execute('DELETE FROM mfa_secrets WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$userId]);
            $db->execute('UPDATE users SET mfa_enabled = 0 WHERE id = ?', [$userId]);
        });
        $this->audit->log(AuditService::MFA_DISABLED, 'user', $userId, null, 'success',
            ['ejecutado_por' => $performedBy], 'warning');
    }

    private function consumeBackupCode(int $userId, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }
        $rows = $this->db->select(
            'SELECT id, code_hash FROM mfa_backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
        foreach ($rows as $row) {
            if (password_verify($code, (string) $row['code_hash'])) {
                $this->db->execute('UPDATE mfa_backup_codes SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
                $this->audit->securityEvent('mfa_backup_used', 'Uso de codigo de respaldo MFA',
                    'Se consumio un codigo de respaldo.', 'medium', $userId);
                return true;
            }
        }
        return false;
    }

    public function remainingBackupCodes(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM mfa_backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }

    // -----------------------------------------------------------------
    //  Recuperacion de cuenta
    // -----------------------------------------------------------------

    /**
     * Genera un token de restablecimiento. NUNCA se envia la contrasena
     * actual: solo un enlace de un solo uso con vigencia corta.
     * La respuesta al usuario es siempre la misma exista o no la cuenta.
     */
    public function requestPasswordReset(string $identifier, string $ip): ?array
    {
        if (!$this->limiter->attempt('pwreset:ip:' . $ip, 5, 900)) {
            throw HttpException::tooManyRequests('Demasiadas solicitudes de recuperacion.');
        }
        $record = $this->users->findAuthRecord($identifier);
        if ($record === null || $record['status'] !== 'active') {
            $this->audit->log(AuditService::PASSWORD_RESET_REQ, 'user', null, $identifier, 'failure',
                ['motivo' => 'identificador no valido'], 'notice');
            return null;
        }
        $token = $this->crypto->randomToken(32);
        $this->db->execute('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [(int) $record['id']]);
        $this->db->insert(
            'INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at)
             VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
            [(int) $record['id'], $this->crypto->hashToken($token), $ip]
        );
        $this->audit->log(AuditService::PASSWORD_RESET_REQ, 'user', (int) $record['id'], $record['username'], 'success',
            [], 'notice', (int) $record['id'], $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);

        return ['token' => $token, 'user' => $record];
    }

    public function completePasswordReset(string $token, string $password, string $confirmation): void
    {
        $row = $this->db->selectOne(
            'SELECT pr.*, u.username, u.email, u.national_id, u.first_name, u.last_name
               FROM password_resets pr JOIN users u ON u.id = pr.user_id
              WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()',
            [$this->crypto->hashToken($token)]
        );
        if ($row === null) {
            throw new ValidationException(['token' => 'El enlace de recuperacion no es valido o ya expiro.']);
        }
        if (!hash_equals($password, $confirmation)) {
            throw new ValidationException(['password_confirmation' => 'La confirmacion no coincide.']);
        }
        $this->validatePasswordPolicy($password, $row);

        $userId = (int) $row['user_id'];
        $hash   = $this->crypto->hashPassword($password);
        $this->db->transaction(function (Database $db) use ($row, $userId, $hash): void {
            $db->execute('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
            $this->users->updatePassword($userId, $hash['hash'], $hash['algo'], false);
        });
        $this->sessions->revokeAllForUser($userId, null, 'restablecimiento de contrasena');
        $this->audit->log(AuditService::PASSWORD_RESET_DONE, 'user', $userId, $row['username'], 'success',
            [], 'notice', $userId, $row['first_name'] . ' ' . $row['last_name'], $row['national_id']);
    }
}
