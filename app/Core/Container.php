<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Contenedor de servicios muy simple: fabricas perezosas + instancias unicas. */
final class Container
{
    /** @var array<string,callable> */
    private array $factories = [];
    /** @var array<string,mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException('Servicio no registrado: ' . $id);
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
