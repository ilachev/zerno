<?php

declare(strict_types=1);

namespace App\Platform\DI;

/**
 * @template-covariant T of object
 * @implements Container<T>
 */
final class DIContainer implements Container
{
    /**
     * @var array<class-string, object>
     */
    private array $instances = [];

    /**
     * @var array<class-string, callable(Container<T>): object>
     */
    private array $definitions = [];

    /**
     * @var array<class-string, class-string>
     */
    private array $aliases = [];

    /**
     * @var array<class-string, class-string>
     */
    private array $concretes = [];

    /**
     * @var array<class-string, AutowirePlan>
     */
    private array $autowirePlans = [];

    /**
     * @var array<class-string, bool>
     */
    private array $resolving = [];

    /**
     * @template U of object
     * @param class-string<U> $id
     * @param callable(Container<T>): U $definition
     */
    public function set(string $id, callable $definition): void
    {
        $this->definitions[$id] = $definition;
        unset($this->instances[$id], $this->autowirePlans[$id], $this->aliases[$id], $this->concretes[$id]);
    }

    /**
     * @template U of object
     * @param class-string<U> $interface
     * @param class-string<U> $concrete
     */
    public function bind(string $interface, string $concrete): void
    {
        $this->aliases[$interface] = $concrete;
        $this->concretes[$interface] = $concrete;
        unset($this->definitions[$interface], $this->instances[$interface], $this->autowirePlans[$interface]);
    }

    /**
     * @template U of object
     * @param class-string<U> $id
     * @return U
     * @throws ContainerException
     */
    public function get(string $id): object
    {
        // First check if we have a cached instance
        if (isset($this->instances[$id])) {
            /** @var U */
            return $this->instances[$id];
        }

        /** @var class-string $concrete */
        $concrete = $this->resolveConcreteId($id);

        // Check if concrete instance is already resolved
        if (isset($this->instances[$concrete]) && $id !== $concrete) {
            $this->instances[$id] = $this->instances[$concrete];

            /** @var U */
            return $this->instances[$id];
        }

        // Circular dependency check
        if (isset($this->resolving[$concrete])) {
            throw new ContainerException("Circular dependency detected for {$concrete}");
        }

        $this->resolving[$concrete] = true;

        try {
            if (isset($this->definitions[$concrete])) {
                // If we have a factory for this concrete class, use it
                $instance = ($this->definitions[$concrete])($this);
            } else {
                /** @var class-string<U> $concrete */
                $instance = $this->instantiate($concrete);
            }

            // Cache instance under both interface and concrete name
            $this->instances[$concrete] = $instance;
            if ($id !== $concrete) {
                $this->instances[$id] = $instance;
            }

            /** @var U */
            return $instance;
        } finally {
            unset($this->resolving[$concrete]);
        }
    }

    public function has(string $id): bool
    {
        if (isset($this->aliases[$id])) {
            return true;
        }

        $concrete = $this->concretes[$id] ?? $id;

        return isset($this->instances[$concrete])
            || isset($this->definitions[$concrete]);
    }

    /**
     * @param class-string $id
     * @return class-string
     */
    private function resolveConcreteId(string $id): string
    {
        if (isset($this->concretes[$id])) {
            return $this->concretes[$id];
        }

        if (isset($this->aliases[$id])) {
            return $this->concretes[$id] = $this->aliases[$id];
        }

        if (isset($this->definitions[$id])) {
            return $id;
        }

        if (interface_exists($id)) {
            throw new ContainerException("No binding found for interface {$id}");
        }

        if (!class_exists($id)) {
            throw new ContainerException("Class or interface {$id} does not exist");
        }

        return $this->concretes[$id] = $id;
    }

    /**
     * @template U of object
     * @param class-string<U> $concrete
     * @return U
     */
    private function instantiate(string $concrete): object
    {
        $plan = $this->autowirePlans[$concrete] ??= $this->buildAutowirePlan($concrete);

        try {
            /** @var U */
            return $plan->instantiate($this);
        } catch (ContainerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ContainerException("Cannot instantiate {$concrete}", 0, $e);
        }
    }

    /**
     * @template U of object
     * @param class-string<U> $concrete
     */
    private function buildAutowirePlan(string $concrete): AutowirePlan
    {
        $reflection = new \ReflectionClass($concrete);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class {$concrete} is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new AutowirePlan($concrete, []);
        }

        return new AutowirePlan(
            $concrete,
            $this->buildArguments($constructor->getParameters()),
        );
    }

    /**
     * @param \ReflectionParameter[] $parameters
     * @return list<AutowireArgument>
     * @throws ContainerException
     */
    private function buildArguments(array $parameters): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getType();

            if ($dependency === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = new DefaultValueArgument($parameter->getDefaultValue());

                    continue;
                }

                throw new ContainerException(
                    "Cannot resolve parameter {$parameter->getName()}: no type hint",
                );
            }

            if (!$dependency instanceof \ReflectionNamedType) {
                throw new ContainerException(
                    "Cannot resolve union or intersection type for parameter {$parameter->getName()}",
                );
            }

            if ($dependency->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = new DefaultValueArgument($parameter->getDefaultValue());

                    continue;
                }

                throw new ContainerException(
                    "Cannot resolve built-in type for parameter {$parameter->getName()}",
                );
            }

            /** @var class-string $dependencyName */
            $dependencyName = $dependency->getName();
            $arguments[] = new ServiceReferenceArgument($dependencyName);
        }

        return $arguments;
    }
}
