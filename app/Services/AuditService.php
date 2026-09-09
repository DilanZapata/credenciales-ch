<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * Auditoria del sistema.
 *
 * Tres registros complementarios:
 *   - audit_logs        : todo evento relevante (quien, que, cuando, resultado)
 *   - secret_access_log : acceso especifico a secretos (ver/copiar/exportar)
 *   - security_events   : anomalias que exigen atencion del administrador
 *
 * GARANTIA: el contexto se filtra con Logger::redact antes de serializarse,
 * de modo que ninguna contrasena puede terminar en la auditoria.
 */
final class AuditService
{
    // Catalogo de acciones auditables (art. 8)
    public const LOGIN                = 'auth.login';
    public const LOGOUT               = 'auth.logout';
    public const LOGIN_FAILED         = 'auth.login_failed';
    public const LOGIN_BLOCKED        = 'auth.login_blocked';
    public const MFA_CHALLENGE        = 'auth.mfa_challenge';
    public const MFA_FAILED           = 'auth.mfa_failed';
    public const MFA_ENABLED          = 'auth.mfa_enabled';
    public const MFA_DISABLED         = 'auth.mfa_disabled';
    public const REAUTH               = 'auth.reauth';
    public const REAUTH_FAILED        = 'auth.reauth_failed';
    public const PASSWORD_CHANGED     = 'auth.password_changed';
    public const PASSWORD_RESET_REQ   = 'auth.password_reset_requested';
    public const PASSWORD_RESET_DONE  = 'auth.password_reset_completed';

    public const USER_CREATED         = 'user.created';
    public const USER_UPDATED         = 'user.updated';
    public const USER_DEACTIVATED     = 'user.deactivated';
    public const USER_REACTIVATED     = 'user.reactivated';
    public const USER_ROLES_CHANGED   = 'user.roles_changed';
    public const USER_PERMS_CHANGED   = 'user.permissions_changed';
    public const USER_PASSWORD_RESET  = 'user.password_reset';

    public const SYSTEM_CREATED       = 'system.created';
    public const SYSTEM_UPDATED       = 'system.updated';
    public const SYSTEM_DELETED       = 'system.deleted';

    public const CREDENTIAL_CREATED   = 'credential.created';
    public const CREDENTIAL_UPDATED   = 'credential.updated';
    public const CREDENTIAL_VIEWED    = 'credential.viewed';
    public const CREDENTIAL_ROTATED   = 'credential.password_rotated';
    public const CREDENTIAL_DELETED   = 'credential.deleted';
    public const CREDENTIAL_RESTORED  = 'credential.restored';
    public const CREDENTIAL_ASSIGNED  = 'credential.assigned';
    public const CREDENTIAL_REVOKED   = 'credential.revoked';

    public const SECRET_VIEWED        = 'secret.viewed';
    public const SECRET_COPIED        = 'secret.copied';
    public const SECRET_HISTORY_VIEW  = 'secret.history_viewed';
    public const RECOVERY_VIEWED      = 'recovery.viewed';

    public const EXPORT_GENERATED     = 'export.generated';
    public const EXPORT_DOWNLOADED    = 'export.downloaded';
    public const EXPORT_DENIED        = 'export.denied';
    public const IMPORT_EXECUTED      = 'import.executed';

    public const SESSION_REVOKED      = 'session.revoked';
    public const SETTINGS_UPDATED     = 'settings.updated';
    public const CATEGORY_MANAGED     = 'category.managed';
    public const ORG_MANAGED          = 'org.managed';
    public const ROLE_MANAGED         = 'role.managed';
    public const ACCESS_DENIED        = 'security.access_denied';

    public function __construct(private Database $db, private AuthContext $auth)
    {
    }

    /**
     * Registra un evento de auditoria.
     *
     * @param array<string,mixed> $details
     */
    public function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $entityLabel = null,
        string $result = 'success',
        array $details = [],
        string $severity = 'info',
        ?int $overrideUserId = null,
        ?string $overrideName = null,
        ?string $overrideNationalId = null
    ): void {
        try {
            $safeDetails = Logger::redact($details);
            $this->db->insert(
                'INSERT INTO audit_logs
                   (user_id, actor_national_id, actor_name, action, entity_type, entity_id, entity_label,
                    result, severity, ip_address, user_agent, device, session_id, http_method, route, details)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $overrideUserId ?? $this->auth->id(),
                    $overrideNationalId ?? $this->auth->nationalId(),
                    $overrideName ?? ($this->auth->check() ? $this->auth->fullName() : null),
                    $action,
                    $entityType,
                    $entityId !== null ? mb_substr((string) $entityId, 0, 64) : null,
                    $entityLabel !== null ? mb_substr($entityLabel, 0, 255) : null,
                    $result,
                    $severity,
                    $this->auth->ip(),
                    $this->auth->userAgent(),
                    $this->auth->device(),
                    $this->auth->sessionId(),
                    $this->auth->method(),
                    mb_substr($this->auth->route(), 0, 255),
                    $safeDetails === [] ? null : json_encode($safeDetails, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            // La auditoria nunca debe tumbar la operacion, pero si dejar rastro.
            Logger::error('No fue posible escribir en auditoria', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Registro dedicado de acceso a un secreto. */
    public function logSecretAccess(
        int $credentialId,
        string $accessType,
        ?int $secretId = null,
        string $field = 'password',
        ?int $secretVersion = null,
        string $result = 'success',
        ?string $reason = null,
        ?int $reportId = null
    ): void {
        try {
            $this->db->insert(
                'INSERT INTO secret_access_log
                   (credential_id, secret_id, secret_field, secret_version, user_id, actor_national_id,
                    access_type, result, reason, report_id, ip_address, user_agent, session_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $credentialId, $secretId, $field, $secretVersion,
                    $this->auth->id(), $this->auth->nationalId(),
                    $accessType, $result, $reason, $reportId,
                    $this->auth->ip(), $this->auth->userAgent(), $this->auth->sessionId(),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('No fue posible registrar el acceso al secreto', ['error' => $e->getMessage()]);
        }
    }

    /** Evento de seguridad para el tablero del administrador. */
    public function securityEvent(
        string $type,
        string $title,
        string $message = '',
        string $severity = 'medium',
        ?int $userId = null,
        array $details = []
    ): void {
        try {
            $this->db->insert(
                'INSERT INTO security_events (type, severity, user_id, ip_address, title, message, details)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $type,
                    $severity,
                    $userId ?? $this->auth->id(),
                    $this->auth->ip(),
                    mb_substr($title, 0, 180),
                    mb_substr($message, 0, 500),
                    $details === [] ? null : json_encode(Logger::redact($details), JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('No fue posible registrar el evento de seguridad', ['error' => $e->getMessage()]);
        }
    }

    /** Intento de acceso (exitoso o no). Nunca almacena la contrasena. */
    public function logLoginAttempt(string $identifier, ?int $userId, string $result, ?string $reason = null): void
    {
        $this->db->insert(
            'INSERT INTO login_attempts (identifier, user_id, ip_address, user_agent, result, reason)
             VALUES (?,?,?,?,?,?)',
            [
                mb_substr($identifier, 0, 190),
                $userId,
                $this->auth->ip(),
                $this->auth->userAgent(),
                $result,
                $reason,
            ]
        );
    }

    /** Traza de cambios sobre una credencial (historial funcional). */
    public function credentialHistory(
        int $credentialId,
        string $action,
        array $data = []
    ): void {
        $this->db->insert(
            'INSERT INTO credential_history
               (credential_id, secret_version, action, field_changed, old_value, new_value,
                old_status, new_status, old_expires_at, new_expires_at, reason, performed_by, ip_address, metadata)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $credentialId,
                $data['secret_version'] ?? null,
                $action,
                $data['field'] ?? null,
                isset($data['old_value']) ? mb_substr((string) $data['old_value'], 0, 500) : null,
                isset($data['new_value']) ? mb_substr((string) $data['new_value'], 0, 500) : null,
                $data['old_status'] ?? null,
                $data['new_status'] ?? null,
                $data['old_expires_at'] ?? null,
                $data['new_expires_at'] ?? null,
                isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 255) : null,
                $this->auth->id(),
                $this->auth->ip(),
                isset($data['metadata']) ? json_encode(Logger::redact($data['metadata']), JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }
}
