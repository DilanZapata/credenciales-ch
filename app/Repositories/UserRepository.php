<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Persistencia de usuarios, roles efectivos y permisos efectivos.
 *
 * Nota de seguridad: los SELECT nunca proyectan `password_hash` salvo en
 * el metodo explicito findAuthRecord(), usado unicamente por AuthService.
 */
final class UserRepository
{
    private const PUBLIC_COLUMNS = 'u.id, u.national_id, u.employee_code, u.username, u.email,
        u.first_name, u.last_name, u.phone, u.position, u.company_id, u.location_id, u.department_id,
        u.status, u.mfa_enabled, u.mfa_enforced, u.must_change_password, u.password_changed_at,
        u.failed_attempts, u.locked_until, u.last_login_at, u.last_login_ip, u.notes,
        u.created_at, u.updated_at, u.deactivated_at, u.deactivation_reason';

    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::PUBLIC_COLUMNS . ',
                    c.name AS company_name, l.name AS location_name, d.name AS department_name
               FROM users u
               LEFT JOIN companies   c ON c.id = u.company_id
               LEFT JOIN locations   l ON l.id = u.location_id
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByNationalId(string $nationalId): ?array
    {
        return $this->db->selectOne('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u WHERE u.national_id = ?', [$nationalId]);
    }

    /**
     * Registro completo para autenticacion (incluye el hash).
     * Acepta usuario, correo o cedula como identificador.
     *
     * @return array<string,mixed>|null
     */
    public function findAuthRecord(string $identifier): ?array
    {
        return $this->db->selectOne(
            'SELECT u.*, c.name AS company_name
               FROM users u
               LEFT JOIN companies c ON c.id = u.company_id
              WHERE u.username = :id OR u.email = :id OR u.national_id = :id
              LIMIT 1',
            ['id' => $identifier]
        );
    }

    /** @return array<string,mixed>|null */
    public function findAuthRecordById(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function existsField(string $field, string $value, ?int $exceptId = null): bool
    {
        $allowed = ['username', 'email', 'national_id', 'employee_code'];
        if (!in_array($field, $allowed, true)) {
            return false;
        }
        $sql    = "SELECT COUNT(*) FROM users WHERE {$field} = ?";
        $params = [$value];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return (int) $this->db->scalar($sql, $params) > 0;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO users
               (national_id, employee_code, username, email, first_name, last_name, phone, position,
                company_id, location_id, department_id, password_hash, password_algo, password_changed_at,
                must_change_password, status, mfa_enforced, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?)',
            [
                $data['national_id'], $data['employee_code'] ?? null, $data['username'], $data['email'],
                $data['first_name'], $data['last_name'], $data['phone'] ?? null, $data['position'] ?? null,
                $data['company_id'] ?? null, $data['location_id'] ?? null, $data['department_id'] ?? null,
                $data['password_hash'], $data['password_algo'],
                (int) ($data['must_change_password'] ?? 1), $data['status'] ?? 'active',
                (int) ($data['mfa_enforced'] ?? 0), $data['notes'] ?? null, $data['created_by'] ?? null,
            ]
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, ?int $updatedBy = null): void
    {
        $allowed = [
            'national_id', 'employee_code', 'username', 'email', 'first_name', 'last_name',
            'phone', 'position', 'company_id', 'location_id', 'department_id', 'status',
            'mfa_enforced', 'notes',
        ];
        $sets   = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $sets[]   = "{$column} = ?";
                $params[] = $data[$column];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[]   = 'updated_by = ?';
        $params[] = $updatedBy;
        $params[] = $id;
        $this->db->execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function updatePassword(int $id, string $hash, string $algo, bool $mustChange = false): void
    {
        $this->db->execute(
            'UPDATE users
                SET password_hash = ?, password_algo = ?, password_changed_at = NOW(),
                    must_change_password = ?, failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [$hash, $algo, $mustChange ? 1 : 0, $id]
        );
    }

    public function registerSuccessfulLogin(int $id, string $ip): void
    {
        $this->db->execute(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [$ip, $id]
        );
    }

    public function incrementFailedAttempts(int $id, int $maxAttempts, int $lockMinutes): int
    {
        $this->db->execute('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
        $attempts = (int) $this->db->scalar('SELECT failed_attempts FROM users WHERE id = ?', [$id]);
        if ($attempts >= $maxAttempts) {
            $this->db->execute(
                'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
                [$lockMinutes, $id]
            );
        }
        return $attempts;
    }

    public function clearLock(int $id): void
    {
        $this->db->execute('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    }

    public function deactivate(int $id, int $by, string $reason): void
    {
        $this->db->execute(
            "UPDATE users
                SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ?, deactivation_reason = ?
              WHERE id = ?",
            [$by, mb_substr($reason, 0, 255), $id]
        );
    }

    public function reactivate(int $id, int $by): void
    {
        $this->db->execute(
            "UPDATE users
                SET status = 'active', deactivated_at = NULL, deactivated_by = NULL,
                    deactivation_reason = NULL, failed_attempts = 0, locked_until = NULL, updated_by = ?
              WHERE id = ?",
            [$by, $id]
        );
    }

    public function setMfaEnabled(int $id, bool $enabled): void
    {
        $this->db->execute('UPDATE users SET mfa_enabled = ? WHERE id = ?', [$enabled ? 1 : 0, $id]);
    }

    // ---------------------------------------------------------------
    //  Roles y permisos
    // ---------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function rolesOf(int $userId): array
    {
        return $this->db->select(
            'SELECT r.id, r.code, r.name, r.level, r.requires_mfa
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND r.is_active = 1
              ORDER BY r.level DESC',
            [$userId]
        );
    }

    /**
     * Permisos EFECTIVOS: union de los permisos de sus roles mas las
     * concesiones individuales, menos las denegaciones individuales.
     * La denegacion siempre prevalece (principio de minimo privilegio).
     *
     * @return array<int,string>
     */
    public function effectivePermissions(int $userId): array
    {
        $rows = $this->db->select(
            "SELECT p.code
               FROM user_roles ur
               JOIN roles r            ON r.id = ur.role_id AND r.is_active = 1
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p       ON p.id = rp.permission_id
              WHERE ur.user_id = :uid
              UNION
             SELECT p.code
               FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = :uid AND up.effect = 'allow'",
            ['uid' => $userId]
        );
        $granted = array_column($rows, 'code');

        $denied = array_column($this->db->select(
            "SELECT p.code FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = ? AND up.effect = 'deny'",
            [$userId]
        ), 'code');

        return array_values(array_diff($granted, $denied));
    }

    /** @param array<int,int> $roleIds */
    public function setRoles(int $userId, array $roleIds, int $assignedBy): void
    {
        $this->db->transaction(function (Database $db) use ($userId, $roleIds, $assignedBy): void {
            $db->execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
            foreach (array_unique($roleIds) as $roleId) {
                $db->execute(
                    'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?,?,?)',
                    [$userId, (int) $roleId, $assignedBy]
                );
            }
        });
    }

    /** @param array<string,string> $overrides code => allow|deny */
    public function setPermissionOverrides(int $userId, array $overrides, int $assignedBy): void
    {
        $this->db->transaction(function (Database $db) use ($userId, $overrides, $assignedBy): void {
            $db->execute('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);
            foreach ($overrides as $code => $effect) {
                if (!in_array($effect, ['allow', 'deny'], true)) {
                    continue;
                }
                $permissionId = $db->scalar('SELECT id FROM permissions WHERE code = ?', [$code]);
                if ($permissionId === null) {
                    continue;
                }
                $db->execute(
                    'INSERT INTO user_permissions (user_id, permission_id, effect, assigned_by) VALUES (?,?,?,?)',
                    [$userId, (int) $permissionId, $effect, $assignedBy]
                );
            }
        });
    }

    /** @return array<string,string> */
    public function permissionOverrides(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT p.code, up.effect FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = ?',
            [$userId]
        );
        return array_column($rows, 'effect', 'code');
    }

    // ---------------------------------------------------------------
    //  Listados
    // ---------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]          = '(u.first_name LIKE :q OR u.last_name LIKE :q OR u.username LIKE :q
                                  OR u.email LIKE :q OR u.national_id LIKE :q OR u.employee_code LIKE :q)';
            $params['q']      = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[]            = 'u.status = :status';
            $params['status']   = $filters['status'];
        }
        if (!empty($filters['role_id'])) {
            $where[]            = 'EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = :role_id)';
            $params['role_id']  = (int) $filters['role_id'];
        }
        if (!empty($filters['company_id'])) {
            $where[]              = 'u.company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[]                 = 'u.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) $this->db->scalar("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);

        $offset = max(0, ($page - 1) * $perPage);
        $items  = $this->db->select(
            'SELECT ' . self::PUBLIC_COLUMNS . ',
                    c.name AS company_name, d.name AS department_name,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.level DESC SEPARATOR ", ")
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id) AS role_names,
                    (SELECT COUNT(*) FROM credential_assignments ca
                      WHERE ca.user_id = u.id AND ca.is_active = 1) AS assigned_credentials
               FROM users u
               LEFT JOIN companies   c ON c.id = u.company_id
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE ' . $whereSql . '
              ORDER BY u.status ASC, u.last_name ASC, u.first_name ASC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,array<string,mixed>> */
    public function activeSelectList(): array
    {
        return $this->db->select(
            "SELECT id, national_id, CONCAT(first_name, ' ', last_name, ' (', national_id, ')') AS label
               FROM users WHERE status = 'active' ORDER BY first_name, last_name"
        );
    }

    /** @return array<string,int> */
    public function counters(): array
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active')   AS active,
                    SUM(status <> 'active')  AS inactive,
                    SUM(mfa_enabled = 1)     AS with_mfa,
                    SUM(locked_until IS NOT NULL AND locked_until > NOW()) AS locked
               FROM users"
        ) ?? [];
        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'with_mfa' => (int) ($row['with_mfa'] ?? 0),
            'locked'   => (int) ($row['locked'] ?? 0),
        ];
    }

    /** Usuarios desactivados que conservan asignaciones activas (alerta art. 15). */
    public function inactiveWithActiveAssignments(): array
    {
        return $this->db->select(
            "SELECT u.id, u.national_id, u.first_name, u.last_name, u.status,
                    COUNT(ca.id) AS active_assignments
               FROM users u
               JOIN credential_assignments ca ON ca.user_id = u.id AND ca.is_active = 1
              WHERE u.status <> 'active'
              GROUP BY u.id, u.national_id, u.first_name, u.last_name, u.status
              ORDER BY active_assignments DESC"
        );
    }
}
