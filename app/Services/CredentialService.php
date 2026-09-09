<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\AssignmentRepository;
use App\Repositories\CredentialRepository;
use App\Repositories\UserRepository;

/**
 * Logica de negocio de credenciales.
 *
 * Aqui se concentra la regla de oro del sistema (art. 45):
 *
 *    INFORMACION de la credencial  !=  SECRETO de la credencial
 *
 *  - Los metodos de lectura devuelven SIEMPRE la ficha sin secretos.
 *  - revealSecret() es la unica puerta de acceso al texto en claro y
 *    exige, en este orden: permiso -> asignacion vigente -> step-up ->
 *    limitador de frecuencia -> registro de auditoria.
 */
final class CredentialService
{
    public function __construct(
        private Database $db,
        private CredentialRepository $credentials,
        private AssignmentRepository $assignments,
        private UserRepository $users,
        private CryptoService $crypto,
        private AuthorizationService $gate,
        private AuthContext $context,
        private AuditService $audit,
        private SettingsService $settings,
        private RateLimiter $limiter,
        private PasswordGeneratorService $passwords
    ) {
    }

    // -----------------------------------------------------------------
    //  Lectura
    // -----------------------------------------------------------------

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        $this->gate->requireAny(['credentials.view', 'credentials.view_all']);
        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);

        $result = $this->credentials->paginate($filters, $page, $perPage, $this->gate->credentialScopeUserId());

        return [
            'items'    => array_map([$this, 'presentListItem'], $result['items']),
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($result['total'] / $perPage),
        ];
    }

    /**
     * Ficha completa SIN secretos. Registra la consulta en auditoria.
     *
     * @return array<string,mixed>
     */
    public function show(int $id, bool $audit = true): array
    {
        $this->gate->requireAny(['credentials.view', 'credentials.view_all'], 'credential', $id);

        $canSeeRecovery = $this->gate->can('credentials.recovery.view');
        $credential     = $this->credentials->find($id, $this->gate->credentialScopeUserId(), $canSeeRecovery);

        if ($credential === null) {
            // Se responde 404 tanto si no existe como si no esta autorizado:
            // no se revela la existencia de recursos ajenos.
            $this->audit->log(AuditService::ACCESS_DENIED, 'credential', $id, null, 'denied',
                ['motivo' => 'credencial inexistente o fuera del alcance'], 'warning');
            throw HttpException::notFound('La credencial solicitada no existe o no tiene acceso a ella.');
        }

        if ($audit) {
            $this->audit->log(AuditService::CREDENTIAL_VIEWED, 'credential', $id, (string) $credential['name']);
        }

        return $this->presentDetail($credential, $canSeeRecovery);
    }

    /** Historial de cambios de una credencial (art. 32). */
    public function history(int $id): array
    {
        $this->show($id, false);
        $this->gate->requireAny(['history.view', 'credentials.view_all'], 'credential', $id);

        return [
            'secret_versions' => $this->credentials->secretHistoryMeta($id),
            'changes'         => $this->credentials->history(['credential_id' => $id], 300),
            'secret_access'   => $this->gate->can('audit.view')
                ? $this->credentials->secretAccessHistory($id, 100)
                : [],
        ];
    }

    public function assignments(int $id): array
    {
        $this->show($id, false);
        $this->gate->requireAny(['credentials.assign', 'credentials.view_all'], 'credential', $id);
        return $this->assignments->forCredential($id);
    }

    // -----------------------------------------------------------------
    //  Acceso al secreto: la operacion mas sensible del sistema
    // -----------------------------------------------------------------

    /**
     * Revela un secreto en claro.
     *
     * @param string $accessType 'view' | 'copy' | 'history_view'
     * @return array{secret:string,version:int,field:string,generated_at:string}
     */
    public function revealSecret(int $id, string $field = 'password', string $accessType = 'view', ?int $version = null): array
    {
        $field = in_array($field, ['password', 'pin', 'access_code'], true) ? $field : 'password';

        // 1) Permiso funcional
        $permission = match ($accessType) {
            'copy'         => 'credentials.secret.copy',
            'history_view' => 'credentials.secret.history',
            default        => 'credentials.secret.view',
        };
        $this->gate->require($permission, 'credential', $id);

        // 2) Alcance de datos: debe existir la credencial dentro de su ambito
        $scopeUserId = $this->gate->credentialScopeUserId();
        $credential  = $this->credentials->find($id, $scopeUserId);
        if ($credential === null) {
            $this->audit->logSecretAccess($id, $accessType, null, $field, null, 'denied', 'fuera del alcance');
            throw HttpException::notFound('La credencial solicitada no existe o no tiene acceso a ella.');
        }

        // 3) Permisos finos de la asignacion (un consultor puede tener
        //    acceso de lectura pero no de copia sobre una credencial dada)
        if ($scopeUserId !== null) {
            $assignment = $this->assignments->find($id, $scopeUserId);
            $flag       = $accessType === 'copy' ? 'can_copy_secret' : 'can_view_secret';
            if ($assignment === null || (int) $assignment['is_active'] !== 1 || (int) $assignment[$flag] !== 1) {
                $this->audit->logSecretAccess($id, $accessType, null, $field, null, 'denied', 'asignacion sin permiso');
                throw HttpException::forbidden('Su asignacion no permite esta operacion sobre la credencial.');
            }
        }

        // 4) Freno a la extraccion masiva de secretos
        $bucket = 'secret:' . ($this->context->id() ?? 0);
        if (!$this->limiter->attempt($bucket, 60, 300)) {
            $this->audit->logSecretAccess($id, $accessType, null, $field, null, 'denied', 'limite de frecuencia');
            $this->audit->securityEvent(
                'secret_flood',
                'Consulta masiva de secretos',
                sprintf('El usuario %s supero el limite de revelados en 5 minutos.', $this->context->fullName()),
                'high'
            );
            throw HttpException::tooManyRequests('Ha superado el limite de consultas de contrasenas. Intente mas tarde.');
        }

        // 5) Reautenticacion reciente (step-up)
        $this->gate->requireStepUp('secret');

        // 6) Recuperacion del sobre cifrado
        $row = $version !== null
            ? $this->credentials->secretVersion($id, $version, $field)
            : $this->credentials->currentSecret($id, $field);

        if ($row === null) {
            throw HttpException::notFound('La credencial no tiene un secreto registrado para ese campo.');
        }
        if ($version !== null && (int) $row['is_current'] !== 1) {
            // Un secreto historico exige el permiso especifico (art. 32).
            $this->gate->require('credentials.secret.history', 'credential', $id);
        }

        $aad   = $this->crypto->aad('credential', $id, $field, (int) $row['version']);
        $plain = $this->crypto->decrypt($row, $aad);

        if ($plain === null) {
            $this->audit->logSecretAccess($id, $accessType, (int) $row['id'], $field, (int) $row['version'], 'denied', 'fallo de descifrado');
            $this->audit->securityEvent(
                'decryption_failure',
                'Fallo de descifrado de un secreto',
                'La autenticacion criptografica del registro fallo. Posible manipulacion de datos o clave incorrecta.',
                'critical'
            );
            throw new HttpException(500, 'No fue posible descifrar el secreto. Contacte al administrador.');
        }

        // 7) Trazabilidad: quien, cuando, que, desde donde
        $this->audit->logSecretAccess($id, $accessType, (int) $row['id'], $field, (int) $row['version']);
        $this->audit->log(
            match ($accessType) {
                'copy'         => AuditService::SECRET_COPIED,
                'history_view' => AuditService::SECRET_HISTORY_VIEW,
                default        => AuditService::SECRET_VIEWED,
            },
            'credential',
            $id,
            (string) $credential['name'],
            'success',
            ['campo' => $field, 'version' => (int) $row['version']],
            'notice'
        );

        return [
            'secret'       => $plain,
            'version'      => (int) $row['version'],
            'field'        => $field,
            'is_current'   => (bool) $row['is_current'],
            'generated_at' => (string) $row['created_at'],
        ];
    }

    /** Informacion de recuperacion (art. 5): permiso propio y auditoria propia. */
    public function recoveryInfo(int $id): array
    {
        $this->gate->require('credentials.recovery.view', 'credential', $id);
        $scopeUserId = $this->gate->credentialScopeUserId();
        $credential  = $this->credentials->find($id, $scopeUserId, true);
        if ($credential === null) {
            throw HttpException::notFound('La credencial solicitada no existe o no tiene acceso a ella.');
        }
        if ($scopeUserId !== null) {
            $assignment = $this->assignments->find($id, $scopeUserId);
            if ($assignment === null || (int) $assignment['can_view_recovery'] !== 1) {
                throw HttpException::forbidden('Su asignacion no incluye la informacion de recuperacion.');
            }
        }
        $this->gate->requireStepUp('secret');
        $this->audit->log(AuditService::RECOVERY_VIEWED, 'credential', $id, (string) $credential['name'],
            'success', [], 'notice');
        $this->audit->logSecretAccess($id, 'view', null, 'recovery_answer');

        return [
            'recovery_email'    => $credential['recovery_email'],
            'recovery_phone'    => $credential['recovery_phone'],
            'recovery_username' => $credential['recovery_username'],
            'recovery_notes'    => $credential['recovery_notes'],
        ];
    }

    // -----------------------------------------------------------------
    //  Escritura
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function create(array $data, string $secret): int
    {
        $this->gate->require('credentials.create');

        if (trim($secret) === '') {
            throw new ValidationException(['password' => 'Debe indicar la contrasena de la credencial.']);
        }
        if (mb_strlen($secret) > 1024) {
            throw new ValidationException(['password' => 'El secreto no puede superar 1024 caracteres.']);
        }

        $rotationDays = $data['rotation_period_days'] ?? $this->settings->int('credentials.default_rotation_days', 90);
        $data['rotation_period_days'] = $rotationDays > 0 ? $rotationDays : null;
        $data['next_rotation_at']     = $rotationDays > 0
            ? (new \DateTimeImmutable())->modify("+{$rotationDays} days")->format('Y-m-d')
            : null;
        $data['created_by'] = $this->context->id();

        $id = $this->db->transaction(function () use ($data, $secret): int {
            $id = $this->credentials->create($data);
            $this->persistSecret($id, $secret, 'password', null, 'registro inicial');
            $this->credentials->markRotated($id, $data['rotation_period_days'], $this->context->id());
            return $id;
        });

        $this->audit->log(AuditService::CREDENTIAL_CREATED, 'credential', $id, (string) $data['name'],
            'success', ['sistema_id' => $data['system_id']], 'notice');
        $this->audit->credentialHistory($id, 'created', [
            'reason'         => 'Alta de la credencial',
            'new_status'     => $data['status'] ?? 'active',
            'new_expires_at' => $data['expires_at'] ?? null,
        ]);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->gate->require('credentials.update', 'credential', $id);
        $before = $this->credentials->find($id, null, true);
        if ($before === null) {
            throw HttpException::notFound('La credencial no existe.');
        }

        $this->credentials->update($id, $data, $this->context->id());

        // Diferencia campo a campo, sin registrar nunca valores sensibles.
        $tracked = ['name', 'username', 'email', 'domain', 'admin_username', 'auth_method', 'environment',
                    'owner_user_id', 'status', 'expires_at', 'rotation_period_days', 'system_id'];
        foreach ($tracked as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $old = $before[$field] ?? null;
            $new = $data[$field];
            if ((string) $old === (string) $new) {
                continue;
            }
            $this->audit->credentialHistory($id, $field === 'status' ? 'status_changed' : 'updated', [
                'field'          => $field,
                'old_value'      => $old === null ? null : (string) $old,
                'new_value'      => $new === null ? null : (string) $new,
                'old_status'     => $field === 'status' ? (string) $old : null,
                'new_status'     => $field === 'status' ? (string) $new : null,
                'old_expires_at' => $field === 'expires_at' ? $old : null,
                'new_expires_at' => $field === 'expires_at' ? $new : null,
                'reason'         => $data['change_reason'] ?? null,
            ]);
        }

        // Los datos de recuperacion se auditan sin volcar su contenido.
        foreach (['recovery_email', 'recovery_phone', 'recovery_username', 'recovery_notes'] as $field) {
            if (array_key_exists($field, $data) && (string) ($before[$field] ?? '') !== (string) $data[$field]) {
                $this->audit->credentialHistory($id, 'updated', [
                    'field'  => $field,
                    'reason' => 'Actualizacion de datos de recuperacion',
                ]);
            }
        }

        $this->audit->log(AuditService::CREDENTIAL_UPDATED, 'credential', $id, (string) $before['name'],
            'success', ['campos' => array_keys($data)], 'notice');
    }

    /**
     * Rotacion de la contrasena (art. 16).
     * El secreto anterior no se pierde: pasa a ser una version historica cifrada.
     */
    public function rotatePassword(int $id, string $newSecret, ?string $reason, ?int $rotationDays = null, string $field = 'password'): int
    {
        $this->gate->require('credentials.rotate', 'credential', $id);
        $this->gate->requireStepUp('secret');

        $credential = $this->credentials->find($id, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        if (trim($newSecret) === '') {
            throw new ValidationException(['password' => 'Debe indicar la nueva contrasena.']);
        }
        if (mb_strlen($newSecret) > 1024) {
            throw new ValidationException(['password' => 'El secreto no puede superar 1024 caracteres.']);
        }

        // No se admite repetir el secreto vigente (comparacion por huella,
        // sin necesidad de descifrar nada).
        $current = $this->credentials->currentSecret($id, $field);
        if ($current !== null && $current['fingerprint'] !== null
            && hash_equals((string) $current['fingerprint'], $this->crypto->fingerprint($newSecret))) {
            throw new ValidationException(['password' => 'La nueva contrasena debe ser distinta de la actual.']);
        }

        $days = $rotationDays ?? ($credential['rotation_period_days'] !== null
            ? (int) $credential['rotation_period_days']
            : $this->settings->int('credentials.default_rotation_days', 90));

        $version = $this->db->transaction(function () use ($id, $newSecret, $field, $reason, $days): int {
            $version = $this->persistSecret($id, $newSecret, $field, null, $reason);
            $this->credentials->markRotated($id, $days > 0 ? $days : null, $this->context->id());
            return $version;
        });

        $this->audit->log(AuditService::CREDENTIAL_ROTATED, 'credential', $id, (string) $credential['name'],
            'success', ['campo' => $field, 'version' => $version, 'motivo' => $reason], 'notice');
        $this->audit->credentialHistory($id, 'password_rotated', [
            'secret_version' => $version,
            'field'          => $field,
            'reason'         => $reason ?? 'Rotacion de contrasena',
            'metadata'       => ['proxima_rotacion_dias' => $days],
        ]);

        return $version;
    }

    public function delete(int $id, string $reason): void
    {
        $this->gate->require('credentials.delete', 'credential', $id);
        $credential = $this->credentials->find($id, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        // Baja LOGICA: el historial y la auditoria se conservan intactos.
        $this->credentials->softDelete($id, $this->context->id());
        $this->audit->log(AuditService::CREDENTIAL_DELETED, 'credential', $id, (string) $credential['name'],
            'success', ['motivo' => $reason], 'warning');
        $this->audit->credentialHistory($id, 'deleted', [
            'reason'     => $reason,
            'old_status' => (string) $credential['status'],
            'new_status' => 'archived',
        ]);
    }

    public function restore(int $id): void
    {
        $this->gate->require('credentials.delete', 'credential', $id);
        $credential = $this->credentials->find($id, null, false, true);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        $this->credentials->restore($id, $this->context->id());
        $this->audit->log(AuditService::CREDENTIAL_RESTORED, 'credential', $id, (string) $credential['name'],
            'success', [], 'notice');
        $this->audit->credentialHistory($id, 'restored', ['reason' => 'Reactivacion de la credencial']);
    }

    // -----------------------------------------------------------------
    //  Asignaciones
    // -----------------------------------------------------------------

    public function assign(int $credentialId, int $userId, array $options): void
    {
        $this->gate->require('credentials.assign', 'credential', $credentialId);

        $credential = $this->credentials->find($credentialId, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        $user = $this->users->find($userId);
        if ($user === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        if ($user['status'] !== 'active') {
            throw new ValidationException(['user_id' => 'No se pueden asignar credenciales a un usuario inactivo.']);
        }

        $this->assignments->grant($credentialId, $userId, $options, (int) $this->context->id());

        $this->audit->log(AuditService::CREDENTIAL_ASSIGNED, 'credential', $credentialId,
            (string) $credential['name'], 'success', [
                'usuario'      => $user['username'],
                'cedula'       => $user['national_id'],
                'ver_secreto'  => (bool) ($options['can_view_secret'] ?? true),
                'copiar'       => (bool) ($options['can_copy_secret'] ?? true),
                'recuperacion' => (bool) ($options['can_view_recovery'] ?? false),
            ], 'notice');
        $this->audit->credentialHistory($credentialId, 'assigned', [
            'field'     => 'assignment',
            'new_value' => $user['national_id'] . ' - ' . $user['first_name'] . ' ' . $user['last_name'],
            'reason'    => $options['reason'] ?? 'Asignacion de acceso',
        ]);
    }

    public function revoke(int $credentialId, int $userId, string $reason): void
    {
        $this->gate->require('credentials.revoke', 'credential', $credentialId);

        $credential = $this->credentials->find($credentialId, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        $user = $this->users->find($userId);
        $this->assignments->revoke($credentialId, $userId, (int) $this->context->id(), $reason);

        $this->audit->log(AuditService::CREDENTIAL_REVOKED, 'credential', $credentialId,
            (string) $credential['name'], 'success',
            ['usuario' => $user['username'] ?? $userId, 'motivo' => $reason], 'warning');
        $this->audit->credentialHistory($credentialId, 'revoked', [
            'field'     => 'assignment',
            'old_value' => ($user['national_id'] ?? '') . ' - ' . trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'reason'    => $reason,
        ]);
    }

    // -----------------------------------------------------------------
    //  Internos
    // -----------------------------------------------------------------

    private function persistSecret(int $credentialId, string $secret, string $field, ?string $label, ?string $reason): int
    {
        $nextVersion = 1 + (int) ($this->db->scalar(
            'SELECT COALESCE(MAX(version), 0) FROM credential_secrets WHERE credential_id = ? AND field = ?',
            [$credentialId, $field]
        ) ?? 0);

        $envelope = $this->crypto->encrypt($secret, $this->crypto->aad('credential', $credentialId, $field, $nextVersion));

        return $this->credentials->storeSecret(
            $credentialId,
            $envelope,
            $field,
            $label,
            mb_strlen($secret),
            $this->passwords->strength($secret),
            $this->crypto->fingerprint($secret),
            $reason,
            $this->context->id()
        );
    }

    /**
     * Proyeccion de un elemento de listado. Nunca incluye secretos ni
     * datos de recuperacion.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentListItem(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'name'              => $row['name'],
            'system_id'         => (int) $row['system_id'],
            'system_name'       => $row['system_name'],
            'category_name'     => $row['category_name'],
            'category_color'    => $row['category_color'],
            'resource_type'     => $row['resource_type'],
            'username'          => $row['username'],
            'email'             => $row['email'],
            'url'               => $row['url'],
            'environment'       => $row['environment'],
            'status'            => $row['status'],
            'owner_name'        => $row['owner_name'] ?: null,
            'company_name'      => $row['company_name'],
            'location_name'     => $row['location_name'],
            'department_name'   => $row['department_name'],
            'assignment_count'  => (int) $row['assignment_count'],
            'password_changed_at' => $row['password_changed_at'],
            'next_rotation_at'  => $row['next_rotation_at'],
            'expires_at'        => $row['expires_at'],
            'updated_at'        => $row['updated_at'],
            'rotation_state'    => $this->rotationState($row),
            // Marcador explicito: la API nunca devuelve el secreto en un listado.
            'has_secret'        => true,
        ];
    }

    /** @param array<string,mixed> $row */
    private function presentDetail(array $row, bool $includeRecovery): array
    {
        $detail = [
            'id'                     => (int) $row['id'],
            'name'                   => $row['name'],
            'environment'            => $row['environment'],
            'status'                 => $row['status'],
            'username'               => $row['username'],
            'email'                  => $row['email'],
            'domain'                 => $row['domain'],
            'admin_username'         => $row['admin_username'],
            'auth_method'            => $row['auth_method'],
            'observations'           => $row['observations'],
            'has_security_questions' => (bool) $row['has_security_questions'],
            'owner_user_id'          => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
            'owner_name'             => trim(($row['owner_first_name'] ?? '') . ' ' . ($row['owner_last_name'] ?? '')) ?: null,
            'owner_national_id'      => $row['owner_national_id'] ?? null,
            'created_at'             => $row['created_at'],
            'updated_at'             => $row['updated_at'],
            'password_changed_at'    => $row['password_changed_at'],
            'rotation_period_days'   => $row['rotation_period_days'] !== null ? (int) $row['rotation_period_days'] : null,
            'next_rotation_at'       => $row['next_rotation_at'],
            'expires_at'             => $row['expires_at'],
            'created_by_username'    => $row['created_by_username'],
            'updated_by_username'    => $row['updated_by_username'],
            'assignment_count'       => (int) $row['assignment_count'],
            'secret_version'         => $row['secret_version'] !== null ? (int) $row['secret_version'] : null,
            'secret_count'           => (int) $row['secret_count'],
            'rotation_state'         => $this->rotationState($row),
            'system' => [
                'id'            => (int) $row['system_id'],
                'name'          => $row['system_name'],
                'display_name'  => $row['system_display_name'],
                'description'   => $row['system_description'],
                'url'           => $row['url'],
                'ip_address'    => $row['ip_address'],
                'port'          => $row['port'] !== null ? (int) $row['port'] : null,
                'hostname'      => $row['hostname'],
                'platform'      => $row['platform'],
                'provider'      => $row['provider'],
                'resource_type' => $row['resource_type'],
                'criticality'   => $row['criticality'],
                'status'        => $row['system_status'],
            ],
            'category' => [
                'id'    => $row['category_id'] !== null ? (int) $row['category_id'] : null,
                'name'  => $row['category_name'],
                'color' => $row['category_color'],
            ],
            'organization' => [
                'company'    => $row['company_name'],
                'location'   => $row['location_name'],
                'department' => $row['department_name'],
            ],
            // La informacion de recuperacion se entrega SOLO tras el
            // endpoint especifico; aqui unicamente se indica si existe.
            'has_recovery_info' => ($row['recovery_email'] ?? null) !== null
                                || ($row['recovery_phone'] ?? null) !== null
                                || ($row['recovery_username'] ?? null) !== null,
        ];

        if ($includeRecovery) {
            $detail['recovery_available'] = true;
        }

        return $detail;
    }

    /** @param array<string,mixed> $row */
    private function rotationState(array $row): string
    {
        $today  = new \DateTimeImmutable('today');
        $expiry = $row['expires_at'] ?? null;
        if ($expiry !== null && new \DateTimeImmutable((string) $expiry) < $today) {
            return 'expired';
        }
        $rotation = $row['next_rotation_at'] ?? null;
        if ($rotation !== null) {
            $date = new \DateTimeImmutable((string) $rotation);
            if ($date < $today) {
                return 'rotation_due';
            }
            $warning = $this->settings->int('alerts.expiry_warning_days', 15);
            if ($date <= $today->modify("+{$warning} days")) {
                return 'rotation_soon';
            }
        }
        if (($row['password_changed_at'] ?? null) === null) {
            return 'never_rotated';
        }
        return 'ok';
    }
}
