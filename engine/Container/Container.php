<?php

declare(strict_types=1);

namespace App\Engine\Container;

use Psr\Container\ContainerInterface;

/**
 * Dependency injection container.
 *
 * Eight public methods and no more. There is no alias() (binding an interface
 * to a concrete class *is* the alias), no extend(), no tag(), no contextual
 * binding and no resolving callbacks, because nothing in the framework needs
 * them. Application code is expected to use constructor injection and to touch
 * this class almost never.
 *
 * Autowiring is deliberately unadventurous: it resolves what it can prove and
 * throws an actionable message for everything else. It never guesses.
 */
final class Container implements ContainerInterface
{
    /** Marker returned by parameter resolution meaning "contribute no argument". */
    private const SKIP = "\0container.skip\0";

    /** @var array<string, array{factory: \Closure|string|null, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Membership test for circular detection: O(1).
     *
     * @var array<string, true>
     */
    private array $building = [];

    /**
     * Ordered view of the same stack, used only to render the message.
     *
     * @var list<string>
     */
    private array $buildStack = [];

    /** @var array<string, list<\ReflectionParameter>> */
    private array $constructorCache = [];

    /**
     * Register a binding.
     *
     * $factory may be a closure receiving this container, the name of another
     * class to resolve instead, or null to mean "autowire $id itself".
     */
    public function bind(string $id, \Closure|string|null $factory = null, bool $shared = false): void
    {
        unset($this->instances[$id]);

        $this->bindings[$id] = ['factory' => $factory, 'shared' => $shared];
    }

    public function singleton(string $id, \Closure|string|null $factory = null): void
    {
        $this->bind($id, $factory, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     *
     * @throws EntryNotFoundException when $id is neither bound nor an existing class
     * @throws ContainerException     when $id is known but cannot be built
     */
    public function get(string $id): mixed
    {
        /** @var T */
        return $this->resolve($id, []);
    }

    /**
     * Whether this container can actually produce $id.
     *
     * An explicit binding always counts. An unbound class counts only if it
     * could really be constructed: an interface, an abstract class or a class
     * with a non-public constructor reports false, because claiming otherwise
     * would make has() true for entries get() cannot return.
     */
    public function has(string $id): bool
    {
        if ($this->isBound($id)) {
            return true;
        }

        return \class_exists($id) && (new \ReflectionClass($id))->isInstantiable();
    }

    /**
     * Interfaces are included so that resolving one produces a useful message
     * rather than the blunt "unknown entry".
     *
     * @phpstan-assert-if-true class-string $id
     */
    private static function typeExists(string $id): bool
    {
        return \class_exists($id) || \interface_exists($id);
    }

    /**
     * Resolve with top-level constructor overrides, addressed by parameter name.
     *
     * Overrides are NOT propagated into nested resolutions: a name that happens
     * to match three levels down should not silently change what gets injected
     * there. With no overrides this is exactly get(); with overrides it always
     * builds a fresh instance, because handing back a shared instance that
     * ignored them would be a lie.
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     * @param array<string, mixed>   $parameters
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function make(string $id, array $parameters = []): mixed
    {
        if ($parameters === []) {
            /** @var T */
            return $this->get($id);
        }

        /** @var T */
        return $this->resolve($id, $parameters);
    }

    /**
     * Invoke a callable, resolving its parameters.
     *
     * Accepts a closure, a function name, "Class::method", [$object, 'method'],
     * [Class::class, 'method'] (static or instance), or an invokable object.
     *
     * @param callable|array{0: object|class-string, 1: string}|string $callable
     * @param array<string, mixed>                                      $parameters overrides, by parameter name
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        [$target, $reflection] = $this->reflectCallable($callable);

        $arguments = $this->resolveParameters(
            $reflection->getParameters(),
            $parameters,
            $this->describeCallable($reflection),
        );

        return $target(...$arguments);
    }

    /** True when a shared instance for $id has already been built. */
    public function resolved(string $id): bool
    {
        return \array_key_exists($id, $this->instances);
    }

    /** Explicitly registered, as opposed to merely autowirable. */
    private function isBound(string $id): bool
    {
        return isset($this->bindings[$id]) || \array_key_exists($id, $this->instances);
    }

    /**
     * The single resolution path.
     *
     * Sharing is handled here rather than in get() so that it applies at every
     * depth: a singleton injected as someone else's dependency has to be the
     * same object as the one get() hands out, and an instance() registration
     * has to be visible to nested autowiring.
     *
     * Explicit overrides always force a fresh build, because returning a shared
     * object that ignored them would be a lie.
     *
     * @param array<string, mixed> $parameters
     */
    private function resolve(string $id, array $parameters): mixed
    {
        if ($parameters === [] && \array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->building[$id])) {
            $start = (int) \array_search($id, $this->buildStack, true);

            throw ContainerException::circularDependency(
                \array_values([...\array_slice($this->buildStack, $start), $id]),
            );
        }

        $this->building[$id] = true;
        $this->buildStack[] = $id;

        try {
            /** @var mixed $resolved */
            $resolved = $this->resolveBinding($id, $parameters);

            if ($parameters === [] && ($this->bindings[$id]['shared'] ?? false) === true) {
                $this->instances[$id] = $resolved;
            }

            return $resolved;
        } finally {
            unset($this->building[$id]);
            \array_pop($this->buildStack);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function resolveBinding(string $id, array $parameters): mixed
    {
        if (!isset($this->bindings[$id])) {
            if (!self::typeExists($id)) {
                throw EntryNotFoundException::for($id);
            }

            return $this->build($id, $parameters);
        }

        $factory = $this->bindings[$id]['factory'];

        if ($factory instanceof \Closure) {
            return $factory($this);
        }

        if (\is_string($factory) && $factory !== $id) {
            return $this->resolveBinding($factory, $parameters);
        }

        if (!self::typeExists($id)) {
            throw ContainerException::notInstantiable($id, 'no such class, and the binding points at itself');
        }

        return $this->build($id, $parameters);
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $parameters
     */
    private function build(string $class, array $parameters): object
    {
        /** @var \ReflectionClass<object> $reflection */
        $reflection = new \ReflectionClass($class);

        if ($reflection->isInterface()) {
            throw ContainerException::notInstantiable($class, 'it is an interface');
        }

        if ($reflection->isAbstract()) {
            throw ContainerException::notInstantiable($class, 'it is abstract');
        }

        if (!$reflection->isInstantiable()) {
            throw ContainerException::notInstantiable($class, 'its constructor is not public');
        }

        $constructorParameters = $this->constructorParameters($reflection);

        if ($constructorParameters === []) {
            return $reflection->newInstance();
        }

        return $reflection->newInstanceArgs(
            $this->resolveParameters($constructorParameters, $parameters, $class . '::__construct()'),
        );
    }

    /**
     * Reflection is the dominant cost in a container, and the same constructors
     * are inspected over and over within a request and across a test suite.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionParameter>
     */
    private function constructorParameters(\ReflectionClass $reflection): array
    {
        $name = $reflection->getName();

        return $this->constructorCache[$name] ??= $reflection->getConstructor()?->getParameters() ?? [];
    }

    /**
     * @param list<\ReflectionParameter> $parameters
     * @param array<string, mixed>       $overrides
     *
     * @return list<mixed>
     */
    private function resolveParameters(array $parameters, array $overrides, string $owner): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            /** @var mixed $value */
            $value = $this->resolveParameter($parameter, $overrides, $owner);

            if ($value !== self::SKIP) {
                $arguments[] = $value;
            }
        }

        return $arguments;
    }

    /** @param array<string, mixed> $overrides */
    private function resolveParameter(\ReflectionParameter $parameter, array $overrides, string $owner): mixed
    {
        if (\array_key_exists($parameter->getName(), $overrides)) {
            return $overrides[$parameter->getName()];
        }

        $type = $parameter->getType();

        // An untyped parameter technically accepts null, but injecting null into
        // one is a guess, not a resolution. Only an explicit default rescues it.
        if ($type === null) {
            return $this->fallback(
                $parameter,
                static fn(): mixed => throw ContainerException::unresolvableParameter(
                    $owner,
                    $parameter->getName(),
                    'untyped',
                ),
                allowNullFallback: false,
            );
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return $this->fallback($parameter, static fn(): mixed => throw ContainerException::intersectionNotSupported(
                $owner,
                $parameter->getName(),
                (string) $type,
            ));
        }

        if ($type instanceof \ReflectionUnionType) {
            return $this->resolveUnion($type, $parameter, $owner);
        }

        if (!$type instanceof \ReflectionNamedType) {
            return $this->fallback($parameter, static fn(): mixed => throw ContainerException::unresolvableParameter(
                $owner,
                $parameter->getName(),
                (string) $type,
            ));
        }

        if ($type->isBuiltin()) {
            return $this->fallback($parameter, static fn(): mixed => throw ContainerException::unresolvableParameter(
                $owner,
                $parameter->getName(),
                $type->getName(),
            ));
        }

        // A variadic class-typed parameter resolves to zero arguments. Guessing
        // a collection of every compatible binding is exactly the sort of magic
        // this framework refuses to do.
        if ($parameter->isVariadic()) {
            return self::SKIP;
        }

        try {
            return $this->resolve($type->getName(), []);
        } catch (ContainerException | EntryNotFoundException $e) {
            return $this->fallback($parameter, static fn(): mixed => throw $e);
        }
    }

    private function resolveUnion(\ReflectionUnionType $type, \ReflectionParameter $parameter, string $owner): mixed
    {
        foreach ($type->getTypes() as $member) {
            if (!$member instanceof \ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            // Only explicitly bound members are considered. Picking "the first
            // one that happens to be instantiable" would make the choice the
            // container's rather than the author's.
            if ($this->isBound($member->getName())) {
                return $this->resolve($member->getName(), []);
            }
        }

        return $this->fallback($parameter, static fn(): mixed => throw ContainerException::ambiguousUnion(
            $owner,
            $parameter->getName(),
            (string) $type,
        ));
    }

    /**
     * Last-resort ladder shared by every unresolvable case: an available default
     * wins, then a nullable type, then the caller's exception.
     *
     * @param \Closure(): mixed $fail
     */
    private function fallback(
        \ReflectionParameter $parameter,
        \Closure $fail,
        bool $allowNullFallback = true,
    ): mixed {
        if ($parameter->isVariadic()) {
            return self::SKIP;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($allowNullFallback && $parameter->allowsNull()) {
            return null;
        }

        return $fail();
    }

    /**
     * Everything is normalised to a Closure so that the single invocation site
     * in call() has exactly one concrete type to work with.
     *
     * @param callable|array{0: object|class-string, 1: string}|string $callable
     *
     * @return array{\Closure, \ReflectionFunctionAbstract}
     */
    private function reflectCallable(callable|array|string $callable): array
    {
        if ($callable instanceof \Closure) {
            return [$callable, new \ReflectionFunction($callable)];
        }

        if (\is_string($callable) && \str_contains($callable, '::')) {
            $callable = \explode('::', $callable, 2);
        }

        if (\is_string($callable)) {
            if (!\function_exists($callable)) {
                throw ContainerException::notCallable(\sprintf('function "%s"', $callable));
            }

            return [\Closure::fromCallable($callable), new \ReflectionFunction($callable)];
        }

        if (\is_array($callable)) {
            return $this->reflectMethod($callable);
        }

        if (\is_object($callable) && \method_exists($callable, '__invoke')) {
            return [\Closure::fromCallable($callable), new \ReflectionMethod($callable, '__invoke')];
        }

        throw ContainerException::notCallable(\get_debug_type($callable));
    }

    /**
     * @param array{0: object|class-string, 1: string}|array<int, mixed> $callable
     *
     * @return array{\Closure, \ReflectionFunctionAbstract}
     */
    private function reflectMethod(array $callable): array
    {
        if (\count($callable) !== 2 || !\is_string($callable[1])) {
            throw ContainerException::notCallable('an array that is not [target, method]');
        }

        $target = $callable[0];
        $method = $callable[1];

        if (\is_string($target)) {
            if (!\class_exists($target) || !\method_exists($target, $method)) {
                throw ContainerException::notCallable(\sprintf('%s::%s()', $target, $method));
            }

            $reflection = new \ReflectionMethod($target, $method);

            if ($reflection->isStatic()) {
                return [$reflection->getClosure(), $reflection];
            }

            $target = $this->get($target);
        }

        if (!\is_object($target) || !\method_exists($target, $method)) {
            throw ContainerException::notCallable(\sprintf('%s::%s()', \get_debug_type($target), $method));
        }

        $reflection = new \ReflectionMethod($target, $method);

        return [$reflection->getClosure($target), $reflection];
    }

    private function describeCallable(\ReflectionFunctionAbstract $reflection): string
    {
        if ($reflection instanceof \ReflectionMethod) {
            return $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName() . '()';
        }

        return $reflection->getName() . '()';
    }
}
