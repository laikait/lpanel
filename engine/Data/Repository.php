<?php

declare(strict_types=1);

namespace App\Engine\Data;

use App\Engine\Model\Model;
use App\Engine\Model\ModelManager;

/**
 * The plumbing a repository is written from, and none of its API.
 *
 * Look at what is public here: the constructor, and nothing else. That is
 * deliberate and it is tested. Every public method on a real repository is one
 * somebody wrote on purpose, named after something the application actually
 * does:
 *
 *     final class CustomerRepository extends Repository
 *     {
 *         protected function model(): string      { return Customer::class; }
 *         protected function collection(): string { return 'customers'; }
 *
 *         public function findByEmail(string $email): ?Customer
 *         {
 *             return $this->query()->whereIs('email', $email)->first();
 *         }
 *
 *         public function register(array $attributes): Customer
 *         {
 *             return $this->persist(new Customer(null, $attributes['name'], $attributes['email']));
 *         }
 *
 *         public function deactivate(Customer $customer): Customer
 *         {
 *             $customer->deactivate();
 *
 *             return $this->persist($customer);
 *         }
 *     }
 *
 * There is no inherited find(), findAll(), findById(), save() or delete(),
 * because a repository that has all of those for every model is a table gateway
 * with a longer name: it tells you nothing about the domain, it grows a method
 * per column, and the interesting operation -- deactivating a customer, which
 * is one write and three rules -- ends up spread across the calling code.
 *
 * Repositories are also not generated. A model too simple to need one does not
 * get one, and the query builder is available directly for reads that are not
 * worth a named method.
 */
abstract class Repository
{
    public function __construct(
        protected readonly DataSource $source,
        protected readonly ModelManager $models,
    ) {}

    /** The model this repository stores. */
    abstract protected function model(): string;

    /** Where its rows live: a table, a collection, a file, a key prefix. */
    abstract protected function collection(): string;

    /**
     * The field holding the identity.
     *
     * One field, matching Model::identity(). A composite key cannot be
     * expressed, which is the price of a data interface simple enough for
     * something other than a relational database to implement.
     */
    protected function key(): string
    {
        return 'id';
    }

    /**
     * A query over this repository's collection, already knowing the model.
     *
     * Nothing has been read when this returns. Narrow it, then choose how much
     * hydration the caller is paying for: rows(), column(), into() or get().
     */
    final protected function query(): Query
    {
        /** @var class-string<Model> $model */
        $model = $this->model();

        return Query::on($this->source, $this->collection(), $this->models, $model, $this->key());
    }

    /**
     * Write a model, inserting or updating as appropriate, and return what is
     * now stored.
     *
     * **Use the returned model.** After an insert the stored row has an
     * identity the object handed in never had, and rather than reaching into
     * the model to set it -- which would mean every model exposing a writable
     * identity for the engine's benefit -- the row is read back through the
     * usual hydration and comes out clean, mapped and complete.
     *
     * An update writes only what changed, which is the entire point of the
     * model layer tracking changes at all.
     */
    final protected function persist(Model $model): Model
    {
        return $model->isNew()
            ? $this->insert($model)
            : $this->applyChanges($model);
    }

    private function insert(Model $model): Model
    {
        $row = $model->changes();
        $key = $this->key();

        // A null identity is the absence of one, not a value to store.
        if (($row[$key] ?? null) === null) {
            unset($row[$key]);
        }

        /** @var class-string<Model> $class */
        $class = $this->model();

        // #[Encrypted] attributes are written as tokens, and the model comes
        // back from the row as written: hydration decrypts them again.
        $row = $this->models->seal($class, $row);

        $identity = $this->source->insert($this->collection(), $key, $row)
            ?? throw DataException::insertReturnedNoIdentity($this->collection(), $key);

        return $this->models->hydrate($class, [...$row, $key => $identity]);
    }

    private function applyChanges(Model $model): Model
    {
        $changes = $model->changes();

        if ($changes === []) {
            // Nothing to write. Returning quietly is right: a caller that
            // persists unconditionally after a no-op edit is not making a
            // mistake, it is just being careful.
            return $model;
        }

        $identity = $model->identity()
            ?? throw DataException::cannotRemoveWithoutIdentity($model::class);

        $this->source->update($this->collection(), $this->key(), $identity, $this->models->seal($model::class, $changes));
        $model->markClean();

        return $model;
    }

    /** Remove a model's row, and forget it. */
    final protected function remove(Model $model): void
    {
        $identity = $model->identity()
            ?? throw DataException::cannotRemoveWithoutIdentity($model::class);

        $this->source->delete($this->collection(), $this->key(), $identity);

        // It no longer exists, so the identity map must not keep handing it out.
        $this->models->forget($model::class, $identity);
    }

    // ---- bulk writes ------------------------------------------------------

    /**
     * Store many rows in as few operations as the source allows.
     *
     * Rows, not models: nothing is hydrated and no identities come back, which
     * is the cost being avoided. An import of ten thousand customers is this; a
     * customer signing up is persist().
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return int how many rows were stored
     */
    final protected function insertMany(array $rows): int
    {
        $class = $this->model();
        $rows = \array_map(fn(array $row): array => $this->models->seal($class, $row), $rows);

        return $this->bulk()->insertMany($this->collection(), $this->key(), $rows);
    }

    /**
     * Change every row the query matches, in one operation.
     *
     * The query must carry criteria and nothing else; see BulkWrites. Every
     * model of this repository's class that was already loaded is forgotten
     * afterwards, because the database has just changed underneath objects the
     * identity map would otherwise keep handing out.
     *
     * @param array<string, mixed> $changes
     */
    final protected function updateWhere(Query $query, array $changes): int
    {
        $changed = $this->bulk()->updateWhere($query, $this->models->seal($this->model(), $changes));
        $this->forgetLoaded();

        return $changed;
    }

    /** Remove every row the query matches, in one operation. See updateWhere(). */
    final protected function deleteWhere(Query $query): int
    {
        $removed = $this->bulk()->deleteWhere($query);
        $this->forgetLoaded();

        return $removed;
    }

    private function forgetLoaded(): void
    {
        /** @var class-string<Model> $class */
        $class = $this->model();

        $this->models->flush($class);
    }

    private function bulk(): BulkWrites
    {
        if (!$this->source instanceof BulkWrites) {
            throw DataException::bulkUnsupported($this->collection(), $this->source::class);
        }

        return $this->source;
    }

    /**
     * Build a model from a row this repository obtained some other way.
     *
     * @param array<string, mixed> $row
     */
    final protected function hydrate(array $row): Model
    {
        /** @var class-string<Model> $class */
        $class = $this->model();

        return $this->models->hydrate($class, $row);
    }
}
