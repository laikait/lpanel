<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * An immutable, single-typed list of models.
 *
 * Single-typed on purpose. Heavy backends do their reads in batches, and every
 * batch primitive here -- identities(), pluck(), keyByIdentity() -- is only
 * safe if every element is the same kind of thing. The type check happens once,
 * at construction, rather than at every call site.
 *
 * This is not a general-purpose collection library and should not become one.
 * It holds the operations a data layer actually needs to avoid N+1: get the
 * keys, index by key, take a column, split into batches.
 *
 * It is deliberately not JsonSerializable. A collection of domain models is not
 * an API response; map it to read models or arrays where the response is built.
 *
 * @implements \IteratorAggregate<int, Model>
 */
final class ModelCollection implements \Countable, \IteratorAggregate
{
    /**
     * @param class-string<Model> $type
     * @param list<Model>         $models
     */
    private function __construct(
        private readonly string $type,
        private readonly array $models,
    ) {}

    /**
     * @param class-string<Model> $type
     * @param iterable<Model>     $models
     */
    public static function of(string $type, iterable $models): self
    {
        if (!\is_a($type, Model::class, true)) {
            throw ModelException::notAModel($type);
        }

        $list = [];

        foreach ($models as $model) {
            if (!$model instanceof $type) {
                throw ModelException::collectionTypeMismatch($type, $model::class);
            }

            $list[] = $model;
        }

        return new self($type, $list);
    }

    /** @param class-string<Model> $type */
    public static function empty(string $type): self
    {
        return self::of($type, []);
    }

    /** @return class-string<Model> */
    public function type(): string
    {
        return $this->type;
    }

    /** @return list<Model> */
    public function all(): array
    {
        return $this->models;
    }

    public function first(): ?Model
    {
        return $this->models[0] ?? null;
    }

    public function last(): ?Model
    {
        return $this->models === [] ? null : $this->models[\count($this->models) - 1];
    }

    public function isEmpty(): bool
    {
        return $this->models === [];
    }

    public function count(): int
    {
        return \count($this->models);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->models);
    }

    // ---- batch primitives -------------------------------------------------

    /**
     * The distinct identities in this collection.
     *
     * This is the value a batch load is built from: take the identities, issue
     * one query with an IN clause, then link the results. Models without an
     * identity are skipped rather than reported as null.
     *
     * @return list<int|string>
     */
    public function identities(): array
    {
        $identities = [];

        foreach ($this->models as $model) {
            $identity = $model->identity();

            if ($identity !== null) {
                $identities[$identity] = $identity;
            }
        }

        return \array_values($identities);
    }

    /** @return array<int|string, Model> */
    public function keyByIdentity(): array
    {
        $keyed = [];

        foreach ($this->models as $model) {
            $identity = $model->identity();

            if ($identity !== null) {
                $keyed[$identity] = $model;
            }
        }

        return $keyed;
    }

    public function find(int|string $identity): ?Model
    {
        foreach ($this->models as $model) {
            if ($model->identity() === $identity) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The values of one attribute, in order, with duplicates kept.
     *
     * @return list<mixed>
     */
    public function pluck(string $attribute): array
    {
        return \array_map(
            static fn(Model $model): mixed => $model->attribute($attribute),
            $this->models,
        );
    }

    /**
     * Split into batches of at most $size.
     *
     * @return list<self>
     */
    public function chunk(int $size): array
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A chunk size must be at least 1.');
        }

        $chunks = [];

        foreach (\array_chunk($this->models, $size) as $chunk) {
            $chunks[] = new self($this->type, $chunk);
        }

        return $chunks;
    }

    // ---- transformation ---------------------------------------------------

    /**
     * Map to anything. The result is a plain list, not a collection, because a
     * projection usually stops being a model -- which is exactly the point of
     * read models.
     *
     * @param \Closure(Model): mixed $callback
     *
     * @return list<mixed>
     */
    public function map(\Closure $callback): array
    {
        return \array_map($callback, $this->models);
    }

    /**
     * @param \Closure(Model): bool $callback
     */
    public function filter(\Closure $callback): self
    {
        return new self($this->type, \array_values(\array_filter($this->models, $callback)));
    }
}
