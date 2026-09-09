<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Notificaciones y alertas dentro del sistema. */
final class NotificationRepository
{
    public function __construct(private Database $db)
    {
    }

    public function push(array $data): void
    {
        // dedupe_key evita repetir la misma alerta cada vez que corre el cron.
        $this->db->execute(
            'INSERT IGNORE INTO notifications (user_id, role_id, type, severity, title, message, link, dedupe_key)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $data['user_id'] ?? null, $data['role_id'] ?? null, $data['type'],
                $data['severity'] ?? 'info', mb_substr($data['title'], 0, 180),
                isset($data['message']) ? mb_substr((string) $data['message'], 0, 500) : null,
                $data['link'] ?? null, $data['dedupe_key'] ?? null,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forUser(int $userId, array $roleIds, bool $onlyUnread = false, int $limit = 30): array
    {
        $roleFilter = '';
        $params     = ['uid' => $userId];
        if ($roleIds !== []) {
            $names = [];
            foreach (array_values($roleIds) as $i => $roleId) {
                $names[]             = ':r' . $i;
                $params['r' . $i]    = (int) $roleId;
            }
            $roleFilter = ' OR n.role_id IN (' . implode(',', $names) . ')';
        }
        $unread = $onlyUnread ? ' AND n.is_read = 0' : '';
        return $this->db->select(
            'SELECT n.* FROM notifications n
              WHERE (n.user_id = :uid' . $roleFilter . ')' . $unread . '
              ORDER BY n.is_read ASC, n.created_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    public function unreadCount(int $userId, array $roleIds): int
    {
        $roleFilter = '';
        $params     = ['uid' => $userId];
        if ($roleIds !== []) {
            $names = [];
            foreach (array_values($roleIds) as $i => $roleId) {
                $names[]          = ':r' . $i;
                $params['r' . $i] = (int) $roleId;
            }
            $roleFilter = ' OR n.role_id IN (' . implode(',', $names) . ')';
        }
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications n WHERE (n.user_id = :uid' . $roleFilter . ') AND n.is_read = 0',
            $params
        );
    }

    public function markRead(int $id, int $userId, array $roleIds): void
    {
        $roleFilter = '';
        $params     = ['id' => $id, 'uid' => $userId];
        if ($roleIds !== []) {
            $names = [];
            foreach (array_values($roleIds) as $i => $roleId) {
                $names[]          = ':r' . $i;
                $params['r' . $i] = (int) $roleId;
            }
            $roleFilter = ' OR n.role_id IN (' . implode(',', $names) . ')';
        }
        $this->db->execute(
            'UPDATE notifications n SET n.is_read = 1, n.read_at = NOW()
              WHERE n.id = :id AND (n.user_id = :uid' . $roleFilter . ')',
            $params
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    public function purgeOld(int $days = 90): int
    {
        return $this->db->execute(
            'DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
    }
}
