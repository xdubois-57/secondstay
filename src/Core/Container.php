<?php

declare(strict_types=1);

namespace SecondStay\Core;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param Closure(Container): mixed $factory
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            /** @var T */
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException('Service non enregistre : ' . $id);
        }

        $value = ($this->factories[$id])($this);
        $this->instances[$id] = $value;

        /** @var T */
        return $value;
    }

    public function forget(string $id): void
    {
        unset($this->instances[$id]);
    }
}
