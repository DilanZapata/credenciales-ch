<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Indicadores agregados para los tableros (art. 14 y 43). */
final class DashboardRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string,int> */
    public function credentialCounters(int $warningDays = 15): array
    {
        $row = $this->db->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(c.status = 'active')   AS active,
                SUM(c.status <> 'active')  AS inactive,
                SUM(c.expires_at IS NOT NULL AND c.expires_at < CURDATE()) AS expired,
                SUM(c.expires_at IS NOT NULL AND c.expires_at BETWEEN CURDATE()
                    AND DATE_ADD(CURDATE(), INTERVAL :days DAY)) AS expiring_soon,
                SUM(c.next_rotation_at IS NOT NULL AND c.next_rotation_at < CURDATE()) AS rotation_due,
                SUM(c.password_changed_at IS NULL) AS never_rotated,
                SUM(c.owner_user_id IS NULL) AS without_owner,
                SUM(c.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS recently_updated
               FROM credentials c
              WHERE c.deleted_at IS NULL",
            ['days' => $warningDays]
        ) ?? [];

        $withoutAssignments = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM credentials c
              WHERE c.deleted_at IS NULL AND c.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM credential_assignments ca
                                 WHERE ca.credential_id = c.id AND ca.is_active = 1)"
        );

        return [
            'total'                => (int) ($row['total'] ?? 0),
            'active'               => (int) ($row['active'] ?? 0),
            'inactive'             => (int) ($row['inactive'] ?? 0),
            'expired'              => (int) ($row['expired'] ?? 0),
            'expiring_soon'        => (int) ($row['expiring_soon'] ?? 0),
            'rotation_due'         => (int) ($row['rotation_due'] ?? 0),
            'never_rotated'        => (int) ($row['never_rotated'] ?? 0),
            'without_owner'        => (int) ($row['without_owner'] ?? 0),
            'recently_updated'     => (int) ($row['recently_updated'] ?? 0),
            'without_assignments'  => $withoutAssignments,
        ];
    }

    /** @return array<string,int> */
    public function systemCounters(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active') AS active,
                    SUM(owner_user_id IS NULL) AS without_owner
               FROM systems"
        ) ?? [];
        $withoutCredentials = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM systems s
              WHERE s.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM credentials c WHERE c.system_id = s.id AND c.deleted_at IS NULL)"
        );
        return [
            'total'               => (int) ($row['total'] ?? 0),
            'active'              => (int) ($row['active'] ?? 0),
            'without_owner'       => (int) ($row['without_owner'] ?? 0),
            'without_credentials' => $withoutCredentials,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function credentialsByCategory(): array
    {
        return $this->db->select(
            'SELECT COALESCE(cat.name, "Sin categoria") AS name, COALESCE(cat.color, "#94a3b8") AS color,
                    COUNT(c.id) AS total
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
              WHERE c.deleted_at IS NULL
              GROUP BY cat.id, cat.name, cat.color
              ORDER BY total DESC'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function expiringCredentials(int $days = 30, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT c.id, c.name, c.expires_at, c.next_rotation_at, c.password_changed_at,
                    s.name AS system_name, cat.name AS category_name,
                    DATEDIFF(COALESCE(c.expires_at, c.next_rotation_at), CURDATE()) AS days_left
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
              WHERE c.deleted_at IS NULL AND c.status = "active"
                AND (c.expires_at IS NOT NULL OR c.next_rotation_at IS NOT NULL)
                AND COALESCE(c.expires_at, c.next_rotation_at) <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
              ORDER BY days_left ASC
              LIMIT ' . (int) $limit,
            ['days' => $days]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recentExports(int $limit = 10): array
    {
        return $this->db->select(
            'SELECT er.uuid, er.report_type, er.record_count, er.included_secrets, er.created_at,
                    er.status, er.ip_address, CONCAT_WS(" ", u.first_name, u.last_name) AS user_name,
                    er.actor_national_id
               FROM export_reports er
               LEFT JOIN users u ON u.id = er.user_id
              ORDER BY er.created_at DESC
              LIMIT ' . (int) $limit
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recentCredentialChanges(int $limit = 10): array
    {
        return $this->db->select(
            'SELECT h.performed_at, h.action, h.reason, c.name AS credential_name, s.name AS system_name,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS performed_by_name
               FROM credential_history h
               JOIN credentials c ON c.id = h.credential_id
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN users u ON u.id = h.performed_by
              ORDER BY h.performed_at DESC
              LIMIT ' . (int) $limit
        );
    }

    /** Serie diaria de accesos a secretos para el grafico del tablero. */
    public function secretAccessSeries(int $days = 14): array
    {
        return $this->db->select(
            'SELECT DATE(occurred_at) AS day, COUNT(*) AS total
               FROM secret_access_log
              WHERE occurred_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
              GROUP BY DATE(occurred_at)
              ORDER BY day',
            ['days' => $days]
        );
    }
}
