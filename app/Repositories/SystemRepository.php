<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Inventario de sistemas / recursos que requieren autenticacion. */
final class SystemRepository
{
    private const SORTABLE = [
        'name'       => 's.name',
        'category'   => 'cat.name',
        'type'       => 's.resource_type',
        'status'     => 's.status',
        'created_at' => 's.created_at',
    ];

    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT s.*, cat.name AS category_name, cat.color AS category_color,
                    co.name AS company_name, lo.name AS location_name, de.name AS department_name,
                    CONCAT_WS(" ", ow.first_name, ow.last_name) AS owner_name, ow.national_id AS owner_national_id,
                    (SELECT COUNT(*) FROM credentials c WHERE c.system_id = s.id AND c.deleted_at IS NULL) AS credential_count
               FROM systems s
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = s.owner_user_id
              WHERE s.id = ?',
            [$id]
        );
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(s.name LIKE :q OR s.display_name LIKE :q OR s.hostname LIKE :q
                         OR s.url LIKE :q OR s.provider LIKE :q OR s.platform LIKE :q OR s.ip_address LIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
        }
        foreach (['category_id' => 's.category_id', 'company_id' => 's.company_id',
                  'location_id' => 's.location_id', 'department_id' => 's.department_id',
                  'owner_user_id' => 's.owner_user_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]      = "{$column} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        foreach (['resource_type' => 's.resource_type', 'status' => 's.status', 'criticality' => 's.criticality'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]      = "{$column} = :{$key}";
                $params[$key] = (string) $filters[$key];
            }
        }
        if (!empty($filters['without_owner'])) {
            $where[] = 's.owner_user_id IS NULL';
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM systems s LEFT JOIN categories cat ON cat.id = s.category_id WHERE ' . $whereSql,
            $params
        );

        $sortCol   = self::SORTABLE[(string) ($filters['sort'] ?? 'name')] ?? self::SORTABLE['name'];
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $offset    = max(0, ($page - 1) * $perPage);

        $items = $this->db->select(
            'SELECT s.id, s.code, s.name, s.display_name, s.resource_type, s.url, s.ip_address, s.port,
                    s.hostname, s.platform, s.provider, s.criticality, s.status, s.created_at,
                    cat.name AS category_name, cat.color AS category_color,
                    co.name AS company_name, lo.name AS location_name, de.name AS department_name,
                    CONCAT_WS(" ", ow.first_name, ow.last_name) AS owner_name,
                    (SELECT COUNT(*) FROM credentials c WHERE c.system_id = s.id AND c.deleted_at IS NULL) AS credential_count
               FROM systems s
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = s.owner_user_id
              WHERE ' . $whereSql . '
              ORDER BY ' . $sortCol . ' ' . $direction . '
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO systems
               (code, name, display_name, description, category_id, resource_type, company_id, location_id,
                department_id, url, ip_address, port, hostname, platform, provider, owner_user_id,
                criticality, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $data['code'] ?? null, $data['name'], $data['display_name'] ?? null, $data['description'] ?? null,
                $data['category_id'] ?? null, $data['resource_type'] ?? 'other', $data['company_id'] ?? null,
                $data['location_id'] ?? null, $data['department_id'] ?? null, $data['url'] ?? null,
                $data['ip_address'] ?? null, $data['port'] ?? null, $data['hostname'] ?? null,
                $data['platform'] ?? null, $data['provider'] ?? null, $data['owner_user_id'] ?? null,
                $data['criticality'] ?? 'medium', $data['status'] ?? 'active', $data['created_by'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data, ?int $updatedBy): void
    {
        $allowed = ['code', 'name', 'display_name', 'description', 'category_id', 'resource_type',
                    'company_id', 'location_id', 'department_id', 'url', 'ip_address', 'port',
                    'hostname', 'platform', 'provider', 'owner_user_id', 'criticality', 'status'];
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
        $this->db->execute('UPDATE systems SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function archive(int $id, ?int $userId): void
    {
        $this->db->execute("UPDATE systems SET status = 'archived', updated_by = ? WHERE id = ?", [$userId, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function selectList(): array
    {
        return $this->db->select(
            "SELECT s.id, s.name, cat.name AS category_name
               FROM systems s LEFT JOIN categories cat ON cat.id = s.category_id
              WHERE s.status = 'active' ORDER BY s.name"
        );
    }

    public function countsByType(): array
    {
        return $this->db->select(
            "SELECT resource_type, COUNT(*) AS total FROM systems WHERE status <> 'archived'
              GROUP BY resource_type ORDER BY total DESC"
        );
    }

    public function withoutOwner(): array
    {
        return $this->db->select(
            "SELECT id, name, resource_type FROM systems WHERE owner_user_id IS NULL AND status = 'active' ORDER BY name"
        );
    }

    public function withoutCredentials(): array
    {
        return $this->db->select(
            "SELECT s.id, s.name FROM systems s
              WHERE s.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM credentials c WHERE c.system_id = s.id AND c.deleted_at IS NULL)
              ORDER BY s.name"
        );
    }
}
