<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Gestion de sesiones propia, respaldada en base de datos.
 *
 * Por que no las sesiones nativas de PHP:
 *   - se necesita listar y CERRAR REMOTAMENTE sesiones de otros usuarios;
 *   - se requiere caducidad doble (inactividad + absoluta) y marca de
 *     reautenticacion (step-up) por sesion;
 *   - los archivos de sesion de PHP en /tmp son un activo sensible extra.
 *
 * El token viaja en una cookie HttpOnly + Secure + SameSite=Strict.
 * En la tabla solo se guarda su SHA-256: robar la base de datos no permite
 * suplantar una sesion.
 */
final class SessionService
{
    public const COOKIE = 'scgca_session';

    public function __construct(
        private Database $db,
        private CryptoService $crypto,
        private SettingsService $settings
    ) {
    }

    /**
     * Crea una sesion nueva. Devuelve el token en claro (solo aqui existe).
     *
     * @return array{token:string,session:array<string,mixed>}
     */
    public function create(int $userId, string $ip, string $userAgent, string $device, bool $pendingMfa): array
    {
        $token     = $this->crypto->randomToken(32);
        $id        = $this->crypto->hashToken($token);
        $csrf      = $this->crypto->randomToken(32);
        $absHours  = max(1, $this->settings->int('security.session_absolute_hours', 8));

        $this->db->execute(
            'INSERT INTO sessions
               (id, user_id, csrf_token, ip_address, user_agent, device, pending_mfa, absolute_expires_at)
             VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
            [$id, $userId, $csrf, $ip, $userAgent, $device, $pendingMfa ? 1 : 0, $absHours]
        );

        $session = $this->findById($id);
        return ['token' => $token, 'session' => $session ?? []];
    }

    /**
     * Rotacion del identificador de sesion. Se invoca tras autenticar y tras
     * superar el MFA: neutraliza la fijacion de sesion (session fixation).
     *
     * @return array{token:string,session:array<string,mixed>}
     */
    public function rotate(string $currentId): array
    {
        $token = $this->crypto->randomToken(32);
        $newId = $this->crypto->hashToken($token);
        $this->db->execute('UPDATE sessions SET id = ? WHERE id = ?', [$newId, $currentId]);
        // Las referencias historicas de auditoria conservan el id anterior a proposito.
        $session = $this->findById($newId);
        return ['token' => $token, 'session' => $session ?? []];
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM sessions WHERE id = ?', [$id]);
    }

    /**
     * Resuelve una sesion a partir del token de la cookie, aplicando las
     * politicas de caducidad. Devuelve null si no es utilizable.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $token): ?array
    {
        if (strlen($token) !== 64 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $id      = $this->crypto->hashToken($token);
        $session = $this->findById($id);
        if ($session === null || $session['status'] !== 'active') {
            return null;
        }

        // Caducidad absoluta
        if (strtotime((string) $session['absolute_expires_at']) <= time()) {
            $this->expire($id);
            return null;
        }
        // Caducidad por inactividad
        $idleMinutes = max(1, $this->settings->int('security.session_idle_minutes', 30));
        if (strtotime((string) $session['last_activity_at']) + ($idleMinutes * 60) <= time()) {
            $this->expire($id);
            return null;
        }
        return $session;
    }

    public function touch(string $id): void
    {
        $this->db->execute('UPDATE sessions SET last_activity_at = NOW() WHERE id = ?', [$id]);
    }

    public function markMfaVerified(string $id): void
    {
        $this->db->execute(
            'UPDATE sessions SET mfa_verified = 1, pending_mfa = 0, reauth_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function markReauthenticated(string $id): string
    {
        $this->db->execute('UPDATE sessions SET reauth_at = NOW() WHERE id = ?', [$id]);
        return (string) $this->db->scalar('SELECT reauth_at FROM sessions WHERE id = ?', [$id]);
    }

    public function expire(string $id): void
    {
        $this->db->execute("UPDATE sessions SET status = 'expired' WHERE id = ? AND status = 'active'", [$id]);
    }

    public function revoke(string $id, ?int $byUserId, string $reason): void
    {
        $this->db->execute(
            "UPDATE sessions
                SET status = 'revoked', revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
              WHERE id = ? AND status = 'active'",
            [$byUserId, mb_substr($reason, 0, 120), $id]
        );
    }

    public function revokeAllForUser(int $userId, ?int $byUserId, string $reason, ?string $exceptId = null): int
    {
        $sql    = "UPDATE sessions SET status = 'revoked', revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
                    WHERE user_id = ? AND status = 'active'";
        $params = [$byUserId, mb_substr($reason, 0, 120), $userId];
        if ($exceptId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->execute($sql, $params);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listSessions(array $filters = [], int $limit = 100): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[]          = 's.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $where[]           = 's.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['search'])) {
            $where[]      = '(u.first_name LIKE :q OR u.last_name LIKE :q OR u.username LIKE :q OR u.national_id LIKE :q OR s.ip_address LIKE :q)';
            $params['q']  = '%' . $filters['search'] . '%';
        }
        return $this->db->select(
            'SELECT s.id, s.user_id, s.ip_address, s.device, s.user_agent, s.status, s.mfa_verified,
                    s.created_at, s.last_activity_at, s.absolute_expires_at, s.revoked_at, s.revoke_reason,
                    u.first_name, u.last_name, u.username, u.national_id
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY (s.status = "active") DESC, s.last_activity_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    public function activeCount(): int
    {
        $idle = max(1, $this->settings->int('security.session_idle_minutes', 30));
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM sessions
              WHERE status = 'active'
                AND absolute_expires_at > NOW()
                AND last_activity_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$idle]
        );
    }

    /** Mantenimiento: marca como expiradas las sesiones vencidas. */
    public function purgeExpired(): int
    {
        $idle = max(1, $this->settings->int('security.session_idle_minutes', 30));
        return $this->db->execute(
            "UPDATE sessions SET status = 'expired'
              WHERE status = 'active'
                AND (absolute_expires_at <= NOW()
                     OR last_activity_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))",
            [$idle]
        );
    }
}
