<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Parametros administrables del sistema (tabla `settings`). */
final class SettingsService
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows  = $this->db->select('SELECT setting_key, setting_value, value_type FROM settings');
        $items = [];
        foreach ($rows as $row) {
            $items[$row['setting_key']] = $this->cast($row['setting_value'], (string) $row['value_type']);
        }
        $this->cache = $items;
        return $items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        return (bool) $v;
    }

    /** @return array<int,array<string,mixed>> */
    public function grouped(): array
    {
        return $this->db->select(
            'SELECT setting_key, setting_value, value_type, group_name, label, description
               FROM settings ORDER BY group_name, setting_key'
        );
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $this->db->execute(
            'UPDATE settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?',
            [$value, $userId, $key]
        );
        $this->cache = null;
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'int'  => (int) $value,
            'bool' => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true),
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
