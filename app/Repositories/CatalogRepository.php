<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Catalogos: categorias, empresas, sedes y departamentos. */
final class CatalogRepository
{
    public function __construct(private Database $db)
    {
    }

    // ------------------------------ Categorias -------------------------
    public function categories(bool $onlyActive = true): array
    {
        return $this->db->select(
            'SELECT c.*, (SELECT COUNT(*) FROM systems s WHERE s.category_id = c.id) AS system_count
               FROM categories c ' . ($onlyActive ? 'WHERE c.is_active = 1 ' : '') . '
              ORDER BY c.sort_order, c.name'
        );
    }

    public function findCategory(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    public function createCategory(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO categories (name, slug, description, color, icon, sort_order, is_active)
             VALUES (?,?,?,?,?,?,1)',
            [
                $data['name'], $this->slug($data['name']), $data['description'] ?? null,
                $data['color'] ?? '#64748b', $data['icon'] ?? 'folder', (int) ($data['sort_order'] ?? 0),
            ]
        );
    }

    public function updateCategory(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE categories SET name = ?, slug = ?, description = ?, color = ?, icon = ?, sort_order = ?, is_active = ?
              WHERE id = ?',
            [
                $data['name'], $this->slug($data['name']), $data['description'] ?? null,
                $data['color'] ?? '#64748b', $data['icon'] ?? 'folder',
                (int) ($data['sort_order'] ?? 0), (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    public function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = strtr($slug, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'categoria';
    }

    // ------------------------------ Empresas ---------------------------
    public function companies(bool $onlyActive = true): array
    {
        return $this->db->select(
            'SELECT * FROM companies ' . ($onlyActive ? 'WHERE is_active = 1 ' : '') . 'ORDER BY name'
        );
    }

    public function createCompany(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO companies (name, legal_name, tax_id) VALUES (?,?,?)',
            [$data['name'], $data['legal_name'] ?? null, $data['tax_id'] ?? null]
        );
    }

    public function updateCompany(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE companies SET name = ?, legal_name = ?, tax_id = ?, is_active = ? WHERE id = ?',
            [$data['name'], $data['legal_name'] ?? null, $data['tax_id'] ?? null, (int) ($data['is_active'] ?? 1), $id]
        );
    }

    // ------------------------------ Sedes ------------------------------
    public function locations(?int $companyId = null, bool $onlyActive = true): array
    {
        $where  = [];
        $params = [];
        if ($companyId !== null) {
            $where[]              = 'l.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($onlyActive) {
            $where[] = 'l.is_active = 1';
        }
        $sql = 'SELECT l.*, c.name AS company_name FROM locations l JOIN companies c ON c.id = l.company_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $this->db->select($sql . ' ORDER BY c.name, l.name', $params);
    }

    public function createLocation(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO locations (company_id, name, address, city, country) VALUES (?,?,?,?,?)',
            [(int) $data['company_id'], $data['name'], $data['address'] ?? null, $data['city'] ?? null, $data['country'] ?? null]
        );
    }

    public function updateLocation(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE locations SET company_id = ?, name = ?, address = ?, city = ?, country = ?, is_active = ? WHERE id = ?',
            [
                (int) $data['company_id'], $data['name'], $data['address'] ?? null, $data['city'] ?? null,
                $data['country'] ?? null, (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    // --------------------------- Departamentos -------------------------
    public function departments(?int $companyId = null, bool $onlyActive = true): array
    {
        $where  = [];
        $params = [];
        if ($companyId !== null) {
            $where[]              = 'd.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($onlyActive) {
            $where[] = 'd.is_active = 1';
        }
        $sql = 'SELECT d.*, c.name AS company_name, l.name AS location_name
                  FROM departments d
                  JOIN companies c ON c.id = d.company_id
                  LEFT JOIN locations l ON l.id = d.location_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $this->db->select($sql . ' ORDER BY c.name, d.name', $params);
    }

    public function createDepartment(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO departments (company_id, location_id, name, code) VALUES (?,?,?,?)',
            [(int) $data['company_id'], $data['location_id'] ?? null, $data['name'], $data['code'] ?? null]
        );
    }

    public function updateDepartment(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE departments SET company_id = ?, location_id = ?, name = ?, code = ?, is_active = ? WHERE id = ?',
            [
                (int) $data['company_id'], $data['location_id'] ?? null, $data['name'],
                $data['code'] ?? null, (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    // ------------------------------ Roles ------------------------------
    public function roles(): array
    {
        return $this->db->select(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r ORDER BY r.level DESC'
        );
    }

    public function permissions(): array
    {
        return $this->db->select('SELECT * FROM permissions ORDER BY group_name, code');
    }

    /** @return array<int,string> */
    public function rolePermissionCodes(int $roleId): array
    {
        return array_column($this->db->select(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$roleId]
        ), 'code');
    }

    /** @param array<int,string> $codes */
    public function setRolePermissions(int $roleId, array $codes): void
    {
        $this->db->transaction(function (Database $db) use ($roleId, $codes): void {
            $db->execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach (array_unique($codes) as $code) {
                $permissionId = $db->scalar('SELECT id FROM permissions WHERE code = ?', [$code]);
                if ($permissionId !== null) {
                    $db->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)',
                        [$roleId, (int) $permissionId]);
                }
            }
        });
    }

    public function findRole(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    public function createRole(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO roles (code, name, description, level, is_system, requires_mfa) VALUES (?,?,?,?,0,?)',
            [
                strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string) $data['code']) ?? ''),
                $data['name'], $data['description'] ?? null,
                (int) ($data['level'] ?? 10), (int) ($data['requires_mfa'] ?? 0),
            ]
        );
    }

    public function updateRole(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE roles SET name = ?, description = ?, level = ?, requires_mfa = ?, is_active = ? WHERE id = ?',
            [
                $data['name'], $data['description'] ?? null, (int) ($data['level'] ?? 10),
                (int) ($data['requires_mfa'] ?? 0), (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }
}
