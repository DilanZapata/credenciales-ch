<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Limitador de frecuencia por ventana fija, persistido en base de datos
 * (funciona con multiples procesos de Apache/PHP-FPM, a diferencia de
 * una solucion en memoria por proceso).
 *
 * Se aplica a: login, reautenticacion, revelado de secretos, exportacion,
 * busqueda y API en general.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Consume un intento del cubo. Devuelve false si se agoto la cuota.
     */
    public function attempt(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $key = substr(hash('sha256', $bucket), 0, 64);
        return $this->db->transaction(function (Database $db) use ($key, $maxAttempts, $windowSeconds): bool {
            $row = $db->selectOne(
                'SELECT hits, window_start, expires_at FROM rate_limits WHERE bucket = ? FOR UPDATE',
                [$key]
            );
            $now = time();
            if ($row === null || strtotime((string) $row['expires_at']) <= $now) {
                $db->execute(
                    'REPLACE INTO rate_limits (bucket, hits, window_start, expires_at) VALUES (?, 1, ?, ?)',
                    [$key, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + $windowSeconds)]
                );
                return true;
            }
            $hits = (int) $row['hits'];
            if ($hits >= $maxAttempts) {
                return false;
            }
            $db->execute('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$key]);
            return true;
        });
    }

    public function remaining(string $bucket, int $maxAttempts): int
    {
        $key = substr(hash('sha256', $bucket), 0, 64);
        $row = $this->db->selectOne('SELECT hits, expires_at FROM rate_limits WHERE bucket = ?', [$key]);
        if ($row === null || strtotime((string) $row['expires_at']) <= time()) {
            return $maxAttempts;
        }
        return max(0, $maxAttempts - (int) $row['hits']);
    }

    public function retryAfter(string $bucket): int
    {
        $key = substr(hash('sha256', $bucket), 0, 64);
        $row = $this->db->selectOne('SELECT expires_at FROM rate_limits WHERE bucket = ?', [$key]);
        if ($row === null) {
            return 0;
        }
        return max(0, strtotime((string) $row['expires_at']) - time());
    }

    public function clear(string $bucket): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE bucket = ?', [substr(hash('sha256', $bucket), 0, 64)]);
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM rate_limits WHERE expires_at < NOW()');
    }
}
