<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * DI-контейнер. Зарегистрированные фабрики разрешаются один раз и кэшируются;
 * классы без регистрации собираются по типам конструктора.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** Один экземпляр на процесс. */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|object
     */
    public function get(string $id): object
    {
        // Иначе автосборка создала бы новый пустой контейнер
        // потребителям, объявившим его в конструкторе
        if ($id === self::class) {
            return $this;
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }

        return $this->autowire($id);
    }

    /**
     * Сборка без регистрации по типам конструктора.
     * Скалярные параметры требуют значения по умолчанию.
     */
    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new RuntimeException("Контейнер не знает, как собрать [$id]");
        }

        $constructor = (new ReflectionClass($id))->getConstructor();

        if ($constructor === null) {
            return new $id();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new RuntimeException(
                    "Не могу собрать [$id]: параметр \${$parameter->getName()} без типа и без значения по умолчанию"
                );
            }
        }

        return new $id(...$arguments);
    }
}
