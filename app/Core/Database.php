<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Acceso a datos. Toda consulta usa sentencias preparadas: no existe
 * concatenacion de entrada de usuario en SQL (mitigacion de SQL Injection).
 * Los identificadores dinamicos (ORDER BY) se validan contra listas blancas
 * en los repositorios, nunca se interpolan libremente.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        if (!empty($cfg['socket'])) {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $cfg['socket'],
                $cfg['database'],
                $cfg['charset'] ?? 'utf8mb4'
            );
        }

        try {
            $this->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Sentencias preparadas reales en el servidor: sin emulacion
                // el driver no interpola valores en la cadena SQL.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            // El mensaje del driver puede contener credenciales de conexion.
            throw new RuntimeException('No fue posible conectar con la base de datos.', 0, $e);
        }
    }

    public static function instance(?array $cfg = null): Database
    {
        if (self::$instance === null) {
            $cfg = $cfg ?? Config::get('database');
            if (!is_array($cfg)) {
                throw new RuntimeException('Configuracion de base de datos no disponible.');
            }
            self::$instance = new self($cfg);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        [$sql, $params] = $this->expandRepeatedPlaceholders($sql, $params);

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
                $type  = match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_null($value) => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                };
                // Los binarios (ciphertext, nonce) se envian como LOB seguro.
                if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $type = PDO::PARAM_LOB;
                }
                $stmt->bindValue($param, $value, $type);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            // Se registra la sentencia (sin valores) para poder diagnosticar
            // en el servidor; al cliente jamas se le devuelve este detalle.
            Logger::error('Fallo al ejecutar una consulta', [
                'sql'   => preg_replace('/\s+/', ' ', $sql),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $stmt;
    }

    /**
     * Con sentencias preparadas reales (sin emulacion) PDO no admite que
     * un mismo marcador con nombre aparezca varias veces en la consulta.
     * Aqui se reescriben esas repeticiones a marcadores unicos y se
     * duplican los valores, de modo que las consultas legibles del
     * repositorio siguen funcionando sin activar la emulacion.
     *
     * @param array<string|int,mixed> $params
     * @return array{0:string,1:array<string|int,mixed>}
     */
    private function expandRepeatedPlaceholders(string $sql, array $params): array
    {
        if ($params === [] || array_is_list($params)) {
            return [$sql, $params];
        }

        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[ltrim((string) $key, ':')] = $value;
        }

        $seen  = [];
        $extra = [];
        $sql   = (string) preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m) use (&$seen, &$extra, $normalized): string {
                $name = $m[1];
                if (!array_key_exists($name, $normalized)) {
                    return $m[0];
                }
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                if ($seen[$name] === 1) {
                    return $m[0];
                }
                $alias         = $name . '__r' . $seen[$name];
                $extra[$alias] = $normalized[$name];
                return ':' . $alias;
            },
            $sql
        );

        return [$sql, $normalized + $extra];
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback($this);
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
