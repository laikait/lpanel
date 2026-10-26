<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * The base for a domain model.
 *
 * A model here is deliberately NOT an active record. There is no save(), no
 * delete(), no static find(), no query builder and no lazy relationship
 * property, because a model is not a table, not a schema, not a repository and
 * not a query. It is domain state and the behaviour that guards it:
 *
 *     final class Customer extends Model
 *     {
 *         public function __construct(
 *             private ?int $id,
 *             private string $name,
 *             private string $email,
 *             private bool $active = true,
 *         ) {}
 *
 *         public function identity(): ?int { return $this->id; }
 *
 *         public function deactivate(): void { $this->active = false; }
 *     }
 *
 * What the engine adds is the part a persistence layer genuinely cannot infer:
 *
 *  - Change tracking. The model snapshots its declared properties whenever it
 *    is known to match storage, and changes() reports what has diverged since.
 *    A repository writes the changed columns instead of every column, and it
 *    gets that for free from ordinary domain methods -- deactivate() above is
 *    tracked without a single setter.
 *
 *  - Explicit relations. related() returns what has been attached and throws
 *    when nothing has. It never loads anything. That is the whole defence
 *    against N+1: there is no lazy loader to trigger by accident, so a loop
 *    over ten thousand customers cannot quietly become ten thousand queries.
 *
 * Note what is absent: this class does not implement JsonSerializable, and
 * there is no toArray() aimed at a response. A domain model is not an API
 * representation. Project it into a ReadModel, or map it explicitly in the
 * handler that owns the endpoint.
 *
 * Change tracking compares values strictly. Two consequences worth knowing:
 * arrays compare by value, and objects compare by identity -- mutating an
 * object held in a property is invisible to changes(), because the property
 * still points at the same object. Hold value objects immutably, or replace
 * rather than mutate.
 */
abstract class Model
{
    /** @var array<string, mixed>|null null until the model is known to match storage */
    private ?array $modelOriginal = null;

    /** @var array<string, Model|ModelCollection|null> */
    private array $modelRelated = [];

    /**
     * The model's domain identity, or null if it has not been given one yet.
     *
     * This is identity in the domain sense. It is usually the same value a
     * database uses as a primary key, but the model does not know or care that
     * a database exists.
     */
    abstract public function identity(): int|string|null;

    // ---- state ------------------------------------------------------------

    /**
     * Every declared property as a name => value map.
     *
     * Uninitialised typed properties are absent rather than null.
     *
     * @return array<string, mixed>
     */
    final public function attributes(): array
    {
        return Attributes::values($this, self::class);
    }

    /** @return list<string> */
    final public function attributeNames(): array
    {
        return Attributes::names($this, self::class);
    }

    final public function attribute(string $name): mixed
    {
        $attributes = $this->attributes();

        if (!\array_key_exists($name, $attributes)) {
            if (!Attributes::has($this, self::class, $name)) {
                throw ModelException::unknownAttribute(
                    static::class,
                    $name,
                    \implode(', ', $this->attributeNames()),
                );
            }

            // Declared but uninitialised.
            return null;
        }

        return $attributes[$name];
    }

    /**
     * The snapshot taken the last time this model was known to match storage,
     * or null if it never has been.
     *
     * @return array<string, mixed>|null
     */
    final public function original(): ?array
    {
        return $this->modelOriginal;
    }

    /** True until the model has been reconciled with storage at least once. */
    final public function isNew(): bool
    {
        return $this->modelOriginal === null;
    }

    /**
     * The attributes that differ from the snapshot.
     *
     * A new model reports all of its attributes, because all of them have to be
     * written. That makes changes() the single thing a repository needs for
     * both an insert and an update.
     *
     * @return array<string, mixed>
     */
    final public function changes(): array
    {
        $current = $this->attributes();

        if ($this->modelOriginal === null) {
            return $current;
        }

        $changes = [];

        foreach ($current as $name => $value) {
            if (!\array_key_exists($name, $this->modelOriginal) || $this->modelOriginal[$name] !== $value) {
                $changes[$name] = $value;
            }
        }

        return $changes;
    }

    /** With no argument: has anything changed. With one: has that attribute changed. */
    final public function isDirty(?string $attribute = null): bool
    {
        $changes = $this->changes();

        return $attribute === null
            ? $changes !== []
            : \array_key_exists($attribute, $changes);
    }

    /**
     * Declare that the model now matches storage, and snapshot it.
     *
     * The data layer calls this after a successful load, insert or update.
     * Calling it by hand means telling the framework something untrue, and the
     * next update will skip the columns you claimed were already written.
     */
    final public function markClean(): void
    {
        $this->modelOriginal = $this->attributes();
    }

    // ---- relations --------------------------------------------------------

    /**
     * Attach an already-loaded relation.
     *
     * This is data-layer API. Nothing here fetches: the caller has loaded the
     * related models in one batch and is handing them over. Attaching null or
     * an empty collection is meaningful -- it records "loaded, and there is
     * nothing there", which related() then returns instead of throwing.
     */
    final public function attachRelated(string $name, Model|ModelCollection|null $related): void
    {
        $this->modelRelated[$name] = $related;
    }

    final public function hasRelated(string $name): bool
    {
        return \array_key_exists($name, $this->modelRelated);
    }

    /**
     * The attached relation.
     *
     * Throws if nothing has been attached. That is the design: an unloaded
     * relation is a bug in the calling code, and a loud exception during
     * development is cheaper than a silent query inside a loop in production.
     */
    final public function related(string $name): Model|ModelCollection|null
    {
        if (!\array_key_exists($name, $this->modelRelated)) {
            throw ModelException::relationNotLoaded(
                static::class,
                $name,
                \array_keys($this->modelRelated),
            );
        }

        return $this->modelRelated[$name];
    }

    /** @return list<string> the relations loaded on this instance */
    final public function loadedRelations(): array
    {
        return \array_keys($this->modelRelated);
    }

    final public function forgetRelated(string $name): void
    {
        unset($this->modelRelated[$name]);
    }
}
