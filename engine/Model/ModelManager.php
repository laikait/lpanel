<?php

declare(strict_types=1);

namespace App\Engine\Model;

use App\Engine\Security\Encrypter;
use App\Engine\Support\Coercion;

/**
 * Turns storage rows into models, and keeps one instance per identity.
 *
 * This is the seam between the model layer and the data layer that comes after
 * it. A repository produces rows -- from PDO, from a cache, from an HTTP
 * payload, from a fixture array -- and hands them here. Nothing in this class
 * knows what a database is, which is what lets the model layer be tested and
 * used without one.
 *
 * Two jobs, and no third:
 *
 *  - Hydration. A row is matched to the model's CONSTRUCTOR, by parameter name.
 *    That keeps a model an ordinary object with real typed properties and a
 *    constructor that can enforce its own invariants, instead of an attribute
 *    bag that reflection writes into behind its back. Values are converted only
 *    where the conversion is unambiguous, which matters because a database
 *    driver may hand back "42" where the model declares int.
 *
 *  - Identity. Within one unit of work, the same row hydrated twice is the same
 *    object. Without this, two queries that both touch a customer produce two
 *    objects, a change made to one is invisible to the other, and whichever is
 *    written last silently wins.
 *
 * The identity map is memory. It is bounded by a request, which is fine, and
 * unbounded in a long-running worker, which is not: a worker must call flush()
 * between units of work. The queue phase will do that at the boundary it owns;
 * until then it is the caller's job, and it is the reason flush() is public
 * rather than an internal detail.
 *
 * There is deliberately no findAll(), no where() and no get(). Retrieval is the
 * data layer's concern, and putting a query API here would make this class the
 * ORM the framework has chosen not to be.
 */
final class ModelManager
{
    /** @var array<class-string<Model>, array<int|string, Model>> */
    private array $identities = [];

    /** @var array<class-string, list<\ReflectionParameter>> */
    private array $constructors = [];

    /** @var array<class-string, list<string>> the #[Encrypted] parameters of each class */
    private array $encrypted = [];

    /**
     * @param ?Encrypter $encrypter for #[Encrypted] properties; without one,
     *                              a model that has any is refused
     */
    public function __construct(private readonly ?Encrypter $encrypter = null) {}

    // ---- hydration --------------------------------------------------------

    /**
     * Build a model from a row.
     *
     * Row keys the constructor does not declare are ignored: a query may select
     * more than a model needs, and column selection means the row shape varies
     * legitimately. A constructor parameter the row does not supply is an error
     * unless it has a default.
     *
     * @template T of Model
     *
     * @param class-string<T>      $model
     * @param array<string, mixed> $row
     *
     * @return T
     */
    public function hydrate(string $model, array $row): Model
    {
        $instance = $this->build($model, $row);
        $identity = $instance->identity();

        if ($identity === null) {
            // Nothing to key it by, so it cannot take part in the identity map.
            $instance->markClean();

            return $instance;
        }

        $existing = $this->identities[$model][$identity] ?? null;

        if ($existing instanceof $model) {
            // The freshly built instance is discarded. Returning it instead
            // would hand out a second object for the same entity, which is the
            // exact divergence the identity map exists to prevent. The cost is
            // one construction; the alternative is a class of bug that only
            // shows up once two code paths touch the same row.
            return $existing;
        }

        $instance->markClean();
        $this->identities[$model][$identity] = $instance;

        return $instance;
    }

    /**
     * @template T of Model
     *
     * @param class-string<T>                $model
     * @param iterable<array<string, mixed>> $rows
     */
    public function hydrateAll(string $model, iterable $rows): ModelCollection
    {
        $models = [];

        foreach ($rows as $row) {
            $models[] = $this->hydrate($model, $row);
        }

        return ModelCollection::of($model, $models);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T>      $model
     * @param array<string, mixed> $row
     *
     * @return T
     */
    private function build(string $model, array $row): Model
    {
        if (!\is_a($model, Model::class, true)) {
            throw ModelException::notAModel($model);
        }

        /** @var T */
        return $this->construct($model, $row);
    }

    /**
     * Build a read model from a row.
     *
     * The same constructor matching hydration uses, because a projection has
     * the same problem: a row of loosely typed values and a constructor that
     * declares what it wants. What a read model does not get is an identity
     * map, change tracking or relations -- it has none of those, which is the
     * whole reason it is cheap.
     *
     * @template T of ReadModel
     *
     * @param class-string<T>      $readModel
     * @param array<string, mixed> $row
     *
     * @return T
     */
    public function project(string $readModel, array $row): ReadModel
    {
        if (!\is_a($readModel, ReadModel::class, true)) {
            throw ModelException::notAReadModel($readModel);
        }

        /** @var T */
        return $this->construct($readModel, $row);
    }

    /**
     * The columns a class declares, which is what a projection needs to read.
     *
     * Asking the read model itself means selecting the right columns cannot
     * drift from the fields it is built out of.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public function columnsFor(string $class): array
    {
        if (!\class_exists($class)) {
            return [];
        }

        return \array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $this->parameters($class, new \ReflectionClass($class)),
        );
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $row
     */
    private function construct(string $class, array $row): object
    {
        $model = $class;
        $reflection = new \ReflectionClass($model);

        if (!$reflection->isInstantiable()) {
            throw ModelException::notInstantiable(
                $model,
                $reflection->isAbstract() ? 'it is abstract.' : 'it cannot be constructed.',
            );
        }

        $arguments = [];

        foreach ($this->parameters($model, $reflection) as $parameter) {
            $name = $parameter->getName();

            if (!\array_key_exists($name, $row)) {
                if ($parameter->isDefaultValueAvailable() || $parameter->isVariadic()) {
                    continue;
                }

                throw ModelException::missingAttribute(
                    $model,
                    $name,
                    $this->describeType($parameter),
                    \array_keys($row),
                );
            }

            /** @var mixed $value */
            $value = $row[$name];

            if (\in_array($name, $this->encryptedParameters($model, $reflection), true)) {
                $value = $this->open($model, $name, $value);
            }

            $arguments[$name] = $this->convert($model, $parameter, $value);
        }

        try {
            return new $model(...$arguments);
        } catch (\TypeError|\ValueError|\ArgumentCountError $e) {
            throw ModelException::constructorRejected($model, $e->getMessage());
        }
    }

    // ---- encrypted attributes ---------------------------------------------

    /**
     * A row as it must be stored: every #[Encrypted] attribute of $class in it
     * encrypted. Called by the repository on every write; the rest of the row
     * is returned untouched.
     *
     * @param string               $class a model or read model class; anything else passes the row through
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function seal(string $class, array $row): array
    {
        if (!\class_exists($class)) {
            return $row;
        }

        foreach ($this->encryptedParameters($class, new \ReflectionClass($class)) as $name) {
            if (!\array_key_exists($name, $row) || $row[$name] === null) {
                continue;
            }

            if (!\is_string($row[$name])) {
                throw ModelException::encryptedNotString($class, $name, \get_debug_type($row[$name]));
            }

            $encrypter = $this->encrypter ?? throw ModelException::encrypterMissing($class, $name);
            $row[$name] = $encrypter->encrypt($row[$name], self::purpose($class, $name));
        }

        return $row;
    }

    /** The context an attribute is encrypted under, so a token is good in one column only. */
    public static function purpose(string $class, string $attribute): string
    {
        return $class . '.' . $attribute;
    }

    private function open(string $model, string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw ModelException::notDecryptable($model, $name);
        }

        $encrypter = $this->encrypter ?? throw ModelException::encrypterMissing($model, $name);

        return $encrypter->decrypt($value, self::purpose($model, $name))
            ?? throw ModelException::notDecryptable($model, $name);
    }

    /**
     * @param class-string             $class
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<string>
     */
    private function encryptedParameters(string $class, \ReflectionClass $reflection): array
    {
        if (isset($this->encrypted[$class])) {
            return $this->encrypted[$class];
        }

        $names = [];

        foreach ($this->parameters($class, $reflection) as $parameter) {
            if ($parameter->getAttributes(Encrypted::class) === []) {
                continue;
            }

            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'string') {
                throw ModelException::encryptedNotString($class, $parameter->getName(), (string) $type);
            }

            $names[] = $parameter->getName();
        }

        return $this->encrypted[$class] = $names;
    }

    /**
     * @param class-string             $model
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionParameter>
     */
    private function parameters(string $model, \ReflectionClass $reflection): array
    {
        if (isset($this->constructors[$model])) {
            return $this->constructors[$model];
        }

        $constructor = $reflection->getConstructor();

        return $this->constructors[$model] = $constructor === null ? [] : $constructor->getParameters();
    }

    /**
     * Convert a row value to the type the constructor declares.
     *
     * The question "is there one reading of this value?" is answered by
     * Support\Coercion, which route parameters and schema input ask too. What
     * stays here is what differs: a bad row is a mapping bug, so it is an
     * exception naming the model and the attribute, not a 400 and not a
     * validation error.
     */
    private function convert(string $model, \ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw ModelException::invalidAttribute($model, $parameter->getName(), $type->getName(), $value);
        }

        return match ($type->getName()) {
            'int' => $this->toInt($model, $parameter, $value),
            'float' => $this->toFloat($model, $parameter, $value),
            'string' => $this->toString($model, $parameter, $value),
            'bool' => $this->toBool($model, $parameter, $value),
            default => $value,
        };
    }

    private function toInt(string $model, \ReflectionParameter $parameter, mixed $value): int
    {
        return Coercion::toInt($value)
            ?? throw ModelException::invalidAttribute($model, $parameter->getName(), 'an int', $value);
    }

    private function toFloat(string $model, \ReflectionParameter $parameter, mixed $value): float
    {
        return Coercion::toFloat($value)
            ?? throw ModelException::invalidAttribute($model, $parameter->getName(), 'a float', $value);
    }

    private function toString(string $model, \ReflectionParameter $parameter, mixed $value): string
    {
        return Coercion::toString($value)
            ?? throw ModelException::invalidAttribute($model, $parameter->getName(), 'a string', $value);
    }

    private function toBool(string $model, \ReflectionParameter $parameter, mixed $value): bool
    {
        return Coercion::toBool($value)
            ?? throw ModelException::invalidAttribute($model, $parameter->getName(), 'a bool', $value);
    }

    private function describeType(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        return $type === null ? 'untyped' : (string) $type;
    }

    // ---- identity map -----------------------------------------------------

    /**
     * Put a model into the map.
     *
     * A repository calls this after an insert, once the new row has been given
     * an identity, so that a later read of the same entity returns this object
     * rather than a second one.
     */
    public function remember(Model $model): void
    {
        $identity = $model->identity();

        if ($identity === null) {
            throw ModelException::identityRequired($model::class, 'remember');
        }

        $this->identities[$model::class][$identity] = $model;
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return T|null
     */
    public function mapped(string $model, int|string $identity): ?Model
    {
        $instance = $this->identities[$model][$identity] ?? null;

        return $instance instanceof $model ? $instance : null;
    }

    /** @param class-string<Model> $model */
    public function isMapped(string $model, int|string $identity): bool
    {
        return isset($this->identities[$model][$identity]);
    }

    /** @param class-string<Model> $model */
    public function forget(string $model, int|string $identity): void
    {
        unset($this->identities[$model][$identity]);
    }

    /**
     * Empty the identity map, for one model class or entirely.
     *
     * A long-running worker calls this between units of work. Skipping it is a
     * slow memory leak and, worse, lets state from one job be seen by the next.
     *
     * @param class-string<Model>|null $model
     */
    public function flush(?string $model = null): void
    {
        if ($model === null) {
            $this->identities = [];

            return;
        }

        unset($this->identities[$model]);
    }

    /** How many models the map currently holds. For diagnostics and tests. */
    public function mappedCount(): int
    {
        $count = 0;

        foreach ($this->identities as $models) {
            $count += \count($models);
        }

        return $count;
    }
}
