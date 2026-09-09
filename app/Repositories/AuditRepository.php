<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Consulta del registro de auditoria y de los eventos de seguridad. */
final class AuditRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Auditoria filtrable (art. 8).
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildFilters($filters);

        $total  = (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs a WHERE ' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $perPage);

        $items = $this->db->select(
            'SELECT a.*, u.username
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE ' . $whereSql . '
              ORDER BY a.occurred_at DESC, a.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,array<string,mixed>> */
    public function export(array $filters, int $limit = 20000): array
    {
        [$whereSql, $params] = $this->buildFilters($filters);
        return $this->db->select(
            'SELECT a.occurred_at, a.actor_name, a.actor_national_id, a.action, a.entity_type, a.entity_id,
                    a.entity_label, a.result, a.severity, a.ip_address, a.device, a.route, a.details
               FROM audit_logs a
              WHERE ' . $whereSql . '
              ORDER BY a.occurred_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildFilters(array $filters): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[]           = 'a.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['national_id'])) {
            $where[]               = 'a.actor_national_id = :national_id';
            $params['national_id'] = (string) $filters['national_id'];
        }
        if (!empty($filters['action'])) {
            $where[]          = 'a.action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (!empty($filters['action_group'])) {
            $where[]                = 'a.action LIKE :action_group';
            $params['action_group'] = str_replace(['%', '_'], ['\%', '\_'], (string) $filters['action_group']) . '.%';
        }
        if (!empty($filters['entity_type'])) {
            $where[]               = 'a.entity_type = :entity_type';
            $params['entity_type'] = (string) $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[]             = 'a.entity_id = :entity_id';
            $params['entity_id'] = (string) $filters['entity_id'];
        }
        if (!empty($filters['result'])) {
            $where[]          = 'a.result = :result';
            $params['result'] = (string) $filters['result'];
        }
        if (!empty($filters['severity'])) {
            $where[]            = 'a.severity = :severity';
            $params['severity'] = (string) $filters['severity'];
        }
        if (!empty($filters['ip'])) {
            $where[]      = 'a.ip_address = :ip';
            $params['ip'] = (string) $filters['ip'];
        }
        if (!empty($filters['date_from'])) {
            $where[]             = 'a.occurred_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]           = 'a.occurred_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[]          = '(a.entity_label LIKE :q OR a.actor_name LIKE :q OR a.action LIKE :q)';
            $params['q']      = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array<int,string> */
    public function distinctActions(): array
    {
        return array_column($this->db->select('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
    }

    public function recent(int $limit = 10, ?string $actionPrefix = null): array
    {
        $sql    = 'SELECT a.occurred_at, a.actor_name, a.action, a.entity_label, a.result
                     FROM audit_logs a';
        $params = [];
        if ($actionPrefix !== null) {
            $sql          .= ' WHERE a.action LIKE :prefix';
            $params['prefix'] = $actionPrefix . '%';
        }
        return $this->db->select($sql . ' ORDER BY a.occurred_at DESC LIMIT ' . (int) $limit, $params);
    }

    public function recentSecretAccess(int $limit = 10): array
    {
        return $this->db->select(
            'SELECT sal.occurred_at, sal.access_type, sal.result, sal.ip_address,
                    c.name AS credential_name, s.name AS system_name,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS user_name, u.national_id
               FROM secret_access_log sal
               JOIN credentials c ON c.id = sal.credential_id
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN users u ON u.id = sal.user_id
              ORDER BY sal.occurred_at DESC
              LIMIT ' . (int) $limit
        );
    }

    public function failedLoginsSince(string $since): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM login_attempts WHERE result IN ('failed','locked','mfa_failed') AND attempted_at >= ?",
            [$since]
        );
    }

    public function recentLoginAttempts(int $limit = 15): array
    {
        return $this->db->select(
            'SELECT la.*, CONCAT_WS(" ", u.first_name, u.last_name) AS user_name
               FROM login_attempts la
               LEFT JOIN users u ON u.id = la.user_id
              ORDER BY la.attempted_at DESC
              LIMIT ' . (int) $limit
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function securityEvents(array $filters = [], int $limit = 50): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[]          = 'se.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['severity'])) {
            $where[]            = 'se.severity = :severity';
            $params['severity'] = (string) $filters['severity'];
        }
        return $this->db->select(
            'SELECT se.*, CONCAT_WS(" ", u.first_name, u.last_name) AS user_name
               FROM security_events se
               LEFT JOIN users u ON u.id = se.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY FIELD(se.severity, "critical","high","medium","low"), se.created_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    public function resolveSecurityEvent(int $id, int $userId): void
    {
        $this->db->execute(
            "UPDATE security_events SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ?",
            [$userId, $id]
        );
    }

    public function openSecurityEventCount(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM security_events WHERE status = 'open'");
    }
}
