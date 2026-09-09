<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Persistencia de credenciales.
 *
 * REGLA DE ORO (art. 45): ninguna consulta de esta clase devuelve secretos
 * junto con los metadatos. Los sobres cifrados solo se obtienen mediante
 * los metodos explicitos currentSecret()/secretVersion(), invocados
 * unicamente por CredentialService tras verificar permisos y step-up.
 *
 * Alcance de datos: cuando se recibe $scopeUserId, el SQL exige una
 * asignacion activa. El filtrado ocurre en la base de datos, no en PHP,
 * de modo que un IDOR no puede sortearse manipulando identificadores.
 */
final class CredentialRepository
{
    private const BASE_COLUMNS = 'c.id, c.system_id, c.name, c.environment, c.username, c.email, c.domain,
        c.admin_username, c.auth_method, c.has_security_questions, c.observations, c.owner_user_id,
        c.status, c.password_changed_at, c.rotation_period_days, c.next_rotation_at, c.expires_at,
        c.created_at, c.updated_at, c.created_by, c.updated_by, c.deleted_at';

    private const RECOVERY_COLUMNS = 'c.recovery_email, c.recovery_phone, c.recovery_username, c.recovery_notes';

    /** Columnas por las que se permite ordenar (lista blanca anti-inyeccion). */
    private const SORTABLE = [
        'name'        => 'c.name',
        'system'      => 's.name',
        'category'    => 'cat.name',
        'status'      => 'c.status',
        'updated_at'  => 'c.updated_at',
        'created_at'  => 'c.created_at',
        'expires_at'  => 'c.expires_at',
        'rotation'    => 'c.next_rotation_at',
    ];

    public function __construct(private Database $db)
    {
    }

    // -----------------------------------------------------------------
    //  Lectura de metadatos
    // -----------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id, ?int $scopeUserId = null, bool $includeRecovery = false, bool $includeDeleted = false): ?array
    {
        $columns = self::BASE_COLUMNS . ($includeRecovery ? ', ' . self::RECOVERY_COLUMNS : '');
        $params  = ['id' => $id];
        $scope   = '';
        if ($scopeUserId !== null) {
            $scope = ' AND EXISTS (SELECT 1 FROM credential_assignments ca
                                    WHERE ca.credential_id = c.id AND ca.user_id = :scope_user
                                      AND ca.is_active = 1 AND ca.revoked_at IS NULL
                                      AND (ca.expires_at IS NULL OR ca.expires_at > NOW()))';
            $params['scope_user'] = $scopeUserId;
        }
        $deleted = $includeDeleted ? '' : ' AND c.deleted_at IS NULL';

        return $this->db->selectOne(
            'SELECT ' . $columns . ',
                    s.name AS system_name, s.display_name AS system_display_name, s.url, s.ip_address,
                    s.port, s.hostname, s.platform, s.provider, s.resource_type, s.criticality,
                    s.description AS system_description, s.status AS system_status,
                    cat.id AS category_id, cat.name AS category_name, cat.color AS category_color,
                    co.id AS company_id, co.name AS company_name,
                    lo.id AS location_id, lo.name AS location_name,
                    de.id AS department_id, de.name AS department_name,
                    ow.first_name AS owner_first_name, ow.last_name AS owner_last_name, ow.national_id AS owner_national_id,
                    cb.username AS created_by_username, ub.username AS updated_by_username,
                    (SELECT COUNT(*) FROM credential_assignments ca2
                      WHERE ca2.credential_id = c.id AND ca2.is_active = 1) AS assignment_count,
                    (SELECT MAX(version) FROM credential_secrets cs
                      WHERE cs.credential_id = c.id AND cs.field = "password") AS secret_version,
                    (SELECT COUNT(*) FROM credential_secrets cs2
                      WHERE cs2.credential_id = c.id AND cs2.field = "password") AS secret_count
               FROM credentials c
               JOIN systems s      ON s.id = c.system_id
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = c.owner_user_id
               LEFT JOIN users cb ON cb.id = c.created_by
               LEFT JOIN users ub ON ub.id = c.updated_by
              WHERE c.id = :id' . $deleted . $scope,
            $params
        );
    }

    /**
     * Listado paginado con filtros y busqueda global (art. 12).
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function paginate(array $filters, int $page, int $perPage, ?int $scopeUserId = null): array
    {
        [$whereSql, $params, $joins] = $this->buildFilters($filters, $scopeUserId);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(DISTINCT c.id)
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
               ' . $joins . '
              WHERE ' . $whereSql,
            $params
        );

        $sortKey   = (string) ($filters['sort'] ?? 'name');
        $sortCol   = self::SORTABLE[$sortKey] ?? self::SORTABLE['name'];
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $offset    = max(0, ($page - 1) * $perPage);

        $items = $this->db->select(
            'SELECT ' . self::BASE_COLUMNS . ',
                    s.name AS system_name, s.url, s.resource_type, s.criticality,
                    cat.name AS category_name, cat.color AS category_color,
                    co.name AS company_name, lo.name AS location_name, de.name AS department_name,
                    CONCAT_WS(" ", ow.first_name, ow.last_name) AS owner_name,
                    (SELECT COUNT(*) FROM credential_assignments ca2
                      WHERE ca2.credential_id = c.id AND ca2.is_active = 1) AS assignment_count
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = c.owner_user_id
               ' . $joins . '
              WHERE ' . $whereSql . '
              GROUP BY c.id
              ORDER BY ' . $sortCol . ' ' . $direction . '
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Identificadores que cumplen un filtro (para exportaciones masivas).
     *
     * @return array<int,int>
     */
    public function idsMatching(array $filters, ?int $scopeUserId = null, int $limit = 5000): array
    {
        [$whereSql, $params, $joins] = $this->buildFilters($filters, $scopeUserId);
        $rows = $this->db->select(
            'SELECT DISTINCT c.id
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
               ' . $joins . '
              WHERE ' . $whereSql . '
              ORDER BY c.id
              LIMIT ' . (int) $limit,
            $params
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Filas completas para reportes. Incluye datos de recuperacion solo si
     * se solicita explicitamente y el llamador ya verifico el permiso.
     *
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function findManyForReport(array $ids, bool $includeRecovery, ?int $scopeUserId = null): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = array_map('intval', $ids);
        $scope        = '';
        if ($scopeUserId !== null) {
            $scope    = ' AND EXISTS (SELECT 1 FROM credential_assignments ca
                                       WHERE ca.credential_id = c.id AND ca.user_id = ?
                                         AND ca.is_active = 1)';
            $params[] = $scopeUserId;
        }
        $columns = self::BASE_COLUMNS . ($includeRecovery ? ', ' . self::RECOVERY_COLUMNS : '');

        return $this->db->select(
            'SELECT ' . $columns . ',
                    s.name AS system_name, s.display_name AS system_display_name, s.description AS system_description,
                    s.url, s.ip_address, s.port, s.hostname, s.platform, s.provider, s.resource_type,
                    cat.name AS category_name, co.name AS company_name, lo.name AS location_name,
                    de.name AS department_name,
                    CONCAT_WS(" ", ow.first_name, ow.last_name) AS owner_name
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = c.owner_user_id
              WHERE c.id IN (' . $placeholders . ') AND c.deleted_at IS NULL' . $scope . '
              ORDER BY s.name, c.name',
            $params
        );
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:string}
     */
    private function buildFilters(array $filters, ?int $scopeUserId): array
    {
        $where  = [];
        $params = [];
        $joins  = '';

        $where[] = ($filters['include_deleted'] ?? false) ? '1=1' : 'c.deleted_at IS NULL';

        if ($scopeUserId !== null) {
            $joins .= ' JOIN credential_assignments ca
                          ON ca.credential_id = c.id
                         AND ca.user_id = :scope_user
                         AND ca.is_active = 1
                         AND ca.revoked_at IS NULL
                         AND (ca.expires_at IS NULL OR ca.expires_at > NOW()) ';
            $params['scope_user'] = $scopeUserId;
            // Un consultor no ve credenciales dadas de baja.
            $where[] = "c.status = 'active'";
        }

        if (!empty($filters['search'])) {
            $where[] = '(c.name LIKE :q OR c.username LIKE :q OR c.email LIKE :q OR c.domain LIKE :q
                         OR s.name LIKE :q OR s.display_name LIKE :q OR s.url LIKE :q OR s.hostname LIKE :q
                         OR s.provider LIKE :q OR cat.name LIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[] = 's.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['system_id'])) {
            $where[] = 'c.system_id = :system_id';
            $params['system_id'] = (int) $filters['system_id'];
        }
        if (!empty($filters['company_id'])) {
            $where[] = 's.company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['location_id'])) {
            $where[] = 's.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 's.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }
        if (!empty($filters['owner_user_id'])) {
            $where[] = 'c.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = (int) $filters['owner_user_id'];
        }
        if (!empty($filters['resource_type'])) {
            $where[] = 's.resource_type = :resource_type';
            $params['resource_type'] = (string) $filters['resource_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['assigned_user_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM credential_assignments caf
                                 WHERE caf.credential_id = c.id AND caf.user_id = :assigned_user_id
                                   AND caf.is_active = 1)';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }
        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['ids'])));
            if ($ids !== []) {
                $names = [];
                foreach ($ids as $i => $id) {
                    $key         = 'id_' . $i;
                    $names[]     = ':' . $key;
                    $params[$key] = $id;
                }
                $where[] = 'c.id IN (' . implode(',', $names) . ')';
            } else {
                $where[] = '1=0';
            }
        }
        if (!empty($filters['expiring_days'])) {
            $where[] = 'c.expires_at IS NOT NULL AND c.expires_at BETWEEN CURDATE()
                        AND DATE_ADD(CURDATE(), INTERVAL :expiring_days DAY)';
            $params['expiring_days'] = (int) $filters['expiring_days'];
        }
        if (!empty($filters['expired'])) {
            $where[] = '((c.expires_at IS NOT NULL AND c.expires_at < CURDATE())
                         OR (c.next_rotation_at IS NOT NULL AND c.next_rotation_at < CURDATE()))';
        }
        if (!empty($filters['never_rotated'])) {
            $where[] = 'c.password_changed_at IS NULL';
        }
        if (!empty($filters['without_owner'])) {
            $where[] = 'c.owner_user_id IS NULL';
        }
        if (!empty($filters['without_assignments'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM credential_assignments cae
                                     WHERE cae.credential_id = c.id AND cae.is_active = 1)';
        }

        return [implode(' AND ', $where), $params, $joins];
    }

    // -----------------------------------------------------------------
    //  Escritura de metadatos
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO credentials
               (system_id, name, environment, username, email, domain, admin_username, auth_method,
                recovery_email, recovery_phone, recovery_username, recovery_notes, has_security_questions,
                observations, owner_user_id, status, rotation_period_days, next_rotation_at, expires_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $data['system_id'], $data['name'], $data['environment'] ?? 'production',
                $data['username'] ?? null, $data['email'] ?? null, $data['domain'] ?? null,
                $data['admin_username'] ?? null, $data['auth_method'] ?? null,
                $data['recovery_email'] ?? null, $data['recovery_phone'] ?? null,
                $data['recovery_username'] ?? null, $data['recovery_notes'] ?? null,
                (int) ($data['has_security_questions'] ?? 0),
                $data['observations'] ?? null, $data['owner_user_id'] ?? null,
                $data['status'] ?? 'active', $data['rotation_period_days'] ?? null,
                $data['next_rotation_at'] ?? null, $data['expires_at'] ?? null,
                $data['created_by'] ?? null,
            ]
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, ?int $updatedBy): void
    {
        $allowed = [
            'system_id', 'name', 'environment', 'username', 'email', 'domain', 'admin_username',
            'auth_method', 'recovery_email', 'recovery_phone', 'recovery_username', 'recovery_notes',
            'has_security_questions', 'observations', 'owner_user_id', 'status',
            'rotation_period_days', 'next_rotation_at', 'expires_at',
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
        $this->db->execute('UPDATE credentials SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function softDelete(int $id, ?int $userId): void
    {
        $this->db->execute(
            "UPDATE credentials SET deleted_at = NOW(), status = 'archived', updated_by = ? WHERE id = ?",
            [$userId, $id]
        );
        $this->db->execute(
            "UPDATE credential_assignments
                SET is_active = 0, revoked_at = NOW(), revoked_by = ?, revoke_reason = 'credencial dada de baja'
              WHERE credential_id = ? AND is_active = 1",
            [$userId, $id]
        );
    }

    public function restore(int $id, ?int $userId): void
    {
        $this->db->execute(
            "UPDATE credentials SET deleted_at = NULL, status = 'active', updated_by = ? WHERE id = ?",
            [$userId, $id]
        );
    }

    public function markRotated(int $id, ?int $rotationDays, ?int $userId): void
    {
        if ($rotationDays !== null && $rotationDays > 0) {
            $this->db->execute(
                'UPDATE credentials
                    SET password_changed_at = NOW(),
                        next_rotation_at = DATE_ADD(CURDATE(), INTERVAL ? DAY),
                        updated_by = ?
                  WHERE id = ?',
                [$rotationDays, $userId, $id]
            );
            return;
        }
        $this->db->execute(
            'UPDATE credentials SET password_changed_at = NOW(), updated_by = ? WHERE id = ?',
            [$userId, $id]
        );
    }

    // -----------------------------------------------------------------
    //  Secretos (sobres cifrados) - acceso explicito y controlado
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function currentSecret(int $credentialId, string $field = 'password'): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM credential_secrets
              WHERE credential_id = ? AND field = ? AND is_current = 1
              ORDER BY version DESC LIMIT 1',
            [$credentialId, $field]
        );
    }

    /** @return array<string,mixed>|null */
    public function secretVersion(int $credentialId, int $version, string $field = 'password'): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM credential_secrets WHERE credential_id = ? AND field = ? AND version = ?',
            [$credentialId, $field, $version]
        );
    }

    /**
     * Metadatos del historial de secretos. NO devuelve el criptograma:
     * solo fechas, autor, motivo y robustez (art. 32).
     *
     * @return array<int,array<string,mixed>>
     */
    public function secretHistoryMeta(int $credentialId, string $field = 'password'): array
    {
        return $this->db->select(
            'SELECT cs.id, cs.version, cs.is_current, cs.change_reason, cs.created_at, cs.retired_at,
                    cs.secret_length, cs.strength_score, cs.key_version,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS changed_by_name,
                    u.national_id AS changed_by_national_id
               FROM credential_secrets cs
               LEFT JOIN users u ON u.id = cs.created_by
              WHERE cs.credential_id = ? AND cs.field = ?
              ORDER BY cs.version DESC',
            [$credentialId, $field]
        );
    }

    /**
     * Inserta una nueva version del secreto y retira la anterior.
     *
     * @param array<string,mixed> $envelope
     */
    public function storeSecret(
        int $credentialId,
        array $envelope,
        string $field,
        ?string $label,
        int $length,
        int $strength,
        string $fingerprint,
        ?string $reason,
        ?int $userId
    ): int {
        return $this->db->transaction(function (Database $db) use (
            $credentialId, $envelope, $field, $label, $length, $strength, $fingerprint, $reason, $userId
        ): int {
            $version = (int) ($db->scalar(
                'SELECT COALESCE(MAX(version), 0) FROM credential_secrets WHERE credential_id = ? AND field = ?',
                [$credentialId, $field]
            ) ?? 0) + 1;

            $db->execute(
                'UPDATE credential_secrets SET is_current = 0, retired_at = NOW()
                  WHERE credential_id = ? AND field = ? AND is_current = 1',
                [$credentialId, $field]
            );

            $db->insert(
                'INSERT INTO credential_secrets
                   (credential_id, field, label, version, is_current, algo, key_version,
                    ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag,
                    secret_length, strength_score, fingerprint, change_reason, created_by)
                 VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $credentialId, $field, $label, $version, $envelope['algo'], $envelope['key_version'],
                    $envelope['ciphertext'], $envelope['nonce'], $envelope['tag'],
                    $envelope['wrapped_dek'], $envelope['dek_nonce'], $envelope['dek_tag'],
                    $length, $strength, $fingerprint, $reason, $userId,
                ]
            );
            return $version;
        });
    }

    /** Contrasenas reutilizadas en varias credenciales (alerta de seguridad). */
    public function reusedSecrets(): array
    {
        return $this->db->select(
            'SELECT cs.fingerprint, COUNT(DISTINCT cs.credential_id) AS total,
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR " | ") AS credentials
               FROM credential_secrets cs
               JOIN credentials c ON c.id = cs.credential_id AND c.deleted_at IS NULL
              WHERE cs.is_current = 1 AND cs.field = "password" AND cs.fingerprint IS NOT NULL
              GROUP BY cs.fingerprint
             HAVING total > 1
              ORDER BY total DESC
              LIMIT 50'
        );
    }

    // -----------------------------------------------------------------
    //  Historial funcional
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function history(array $filters, int $limit = 200): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['credential_id'])) {
            $where[] = 'h.credential_id = :credential_id';
            $params['credential_id'] = (int) $filters['credential_id'];
        }
        if (!empty($filters['credential_ids']) && is_array($filters['credential_ids'])) {
            $ids   = array_values(array_filter(array_map('intval', $filters['credential_ids'])));
            $names = [];
            foreach ($ids as $i => $id) {
                $names[] = ':hid_' . $i;
                $params['hid_' . $i] = $id;
            }
            $where[] = $names === [] ? '1=0' : 'h.credential_id IN (' . implode(',', $names) . ')';
        }
        if (!empty($filters['system_id'])) {
            $where[] = 'c.system_id = :system_id';
            $params['system_id'] = (int) $filters['system_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'h.performed_by = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'h.action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'h.performed_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'h.performed_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return $this->db->select(
            'SELECT h.*, c.name AS credential_name, s.name AS system_name,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS performed_by_name,
                    u.national_id AS performed_by_national_id
               FROM credential_history h
               JOIN credentials c ON c.id = h.credential_id
               JOIN systems s     ON s.id = c.system_id
               LEFT JOIN users u  ON u.id = h.performed_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY h.performed_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    /** Quien consulto/copio/exporto el secreto de una credencial. */
    public function secretAccessHistory(int $credentialId, int $limit = 100): array
    {
        return $this->db->select(
            'SELECT sal.*, CONCAT_WS(" ", u.first_name, u.last_name) AS user_name, u.username
               FROM secret_access_log sal
               LEFT JOIN users u ON u.id = sal.user_id
              WHERE sal.credential_id = ?
              ORDER BY sal.occurred_at DESC
              LIMIT ' . (int) $limit,
            [$credentialId]
        );
    }
}
