<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use PDOException;
use RuntimeException;

/**
 * Ejecutor de migraciones versionadas.
 *
 * Cada archivo de `database/migrations/` se aplica UNA sola vez, en orden
 * numerico, y queda anotado en la tabla `schema_migrations` con su suma de
 * verificacion. Esto permite:
 *
 *   - desplegar en un servidor nuevo con un solo comando;
 *   - aplicar cambios de esquema posteriores sin tocar la base a mano;
 *   - detectar que alguien edito una migracion ya aplicada (lo que dejaria
 *     entornos divergentes sin que nadie se entere).
 *
 * Convencion de nombres:  NNNN_descripcion_breve.sql   (0001, 0002, 0003…)
 */
final class Migrator
{
    public function __construct(private Database $db, private string $directory)
    {
    }

    /** Crea la tabla de control si aun no existe. */
    public function ensureRegistry(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(20)  NOT NULL,
                filename    VARCHAR(190) NOT NULL,
                checksum    CHAR(64)     NOT NULL,
                statements  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<int,array{version:string,filename:string,path:string,checksum:string}>
     */
    public function available(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $path) {
            $filename = basename($path);
            if (preg_match('/^(\d{4})_/', $filename, $m) !== 1) {
                throw new RuntimeException(
                    'Nombre de migracion invalido: ' . $filename . ' (esperado NNNN_descripcion.sql)'
                );
            }
            $migrations[] = [
                'version'  => $m[1],
                'filename' => $filename,
                'path'     => $path,
                'checksum' => hash_file('sha256', $path) ?: '',
            ];
        }
        return $migrations;
    }

    /** @return array<string,array{filename:string,checksum:string,applied_at:string}> */
    public function applied(): array
    {
        $this->ensureRegistry();
        $rows = $this->db->select('SELECT version, filename, checksum, applied_at FROM schema_migrations');
        $out  = [];
        foreach ($rows as $row) {
            $out[(string) $row['version']] = [
                'filename'   => (string) $row['filename'],
                'checksum'   => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }
        return $out;
    }

    /** @return array<int,array{version:string,filename:string,path:string,checksum:string}> */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter(
            $this->available(),
            static fn (array $m): bool => !isset($applied[$m['version']])
        ));
    }

    /**
     * Avisa si una migracion ya aplicada fue modificada despues.
     *
     * @return array<int,string>
     */
    public function drifted(): array
    {
        $applied = $this->applied();
        $drift   = [];
        foreach ($this->available() as $migration) {
            $record = $applied[$migration['version']] ?? null;
            if ($record !== null && $record['checksum'] !== $migration['checksum']) {
                $drift[] = $migration['filename'];
            }
        }
        return $drift;
    }

    /**
     * Aplica las migraciones pendientes.
     *
     * @param callable(string,int):void|null $onApplied  notificacion por migracion
     * @return int numero de migraciones aplicadas
     */
    public function run(?callable $onApplied = null): int
    {
        $this->ensureRegistry();
        $count = 0;

        foreach ($this->pending() as $migration) {
            $statements = $this->statements((string) file_get_contents($migration['path']));
            $applied    = 0;

            foreach ($statements as $statement) {
                try {
                    $this->db->pdo()->exec($statement);
                    $applied++;
                } catch (PDOException $e) {
                    throw new RuntimeException(sprintf(
                        'Fallo la migracion %s en la sentencia %d: %s',
                        $migration['filename'],
                        $applied + 1,
                        $e->getMessage()
                    ), 0, $e);
                }
            }

            $this->db->execute(
                'INSERT INTO schema_migrations (version, filename, checksum, statements) VALUES (?,?,?,?)',
                [$migration['version'], $migration['filename'], $migration['checksum'], $applied]
            );

            $count++;
            if ($onApplied !== null) {
                $onApplied($migration['filename'], $applied);
            }
        }

        return $count;
    }

    /**
     * Divide un archivo SQL en sentencias.
     *
     * Se descartan las lineas de comentario y se separa por ";" al final de
     * linea: suficiente para este esquema, que no usa procedimientos
     * almacenados ni delimitadores personalizados.
     *
     * @return array<int,string>
     */
    private function statements(string $sql): array
    {
        $lines = array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $clean = implode("\n", $lines);

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*(\n|$)/', $clean) ?: []),
            static fn (string $s): bool => $s !== ''
        ));
    }
}
