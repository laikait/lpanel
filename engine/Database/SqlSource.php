<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Data\Bulk;
use App\Engine\Data\BulkWrites;
use App\Engine\Data\DataSource;
use App\Engine\Data\Query;

/**
 * The data layer, over a real database.
 *
 * This is the whole bridge between the two phases, and it is deliberately
 * thin: a Query describes what to read, the Grammar turns that into SQL, the
 * Connection runs it. Everything above -- repositories, hydration, read models,
 * relation linking, pagination -- is unchanged from the day it ran against an
 * array in memory, because none of it was ever written against a database.
 *
 * Swapping ArraySource for this is one line in a module:
 *
 *     $services->singleton(DataSource::class, static fn (Container $c): DataSource
 *         => new SqlSource($c->get(ConnectionManager::class)->connection()));
 *
 * fetch() returns a cursor rather than an array, so a query that streams really
 * streams: Query::stream() and Query::chunk() hold one row at a time rather than
 * a table.
 */
final class SqlSource implements DataSource, BulkWrites
{
    private readonly Grammar $grammar;

    public function __construct(
        private readonly Connection $connection,
        ?Grammar $grammar = null,
    ) {
        $this->grammar = $grammar ?? $connection->grammar();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function fetch(Query $query): iterable
    {
        $compiled = $this->grammar->compileSelect($query);

        return $this->connection->cursor($compiled['sql'], $compiled['bindings']);
    }

    public function count(Query $query): int
    {
        $compiled = $this->grammar->compileCount($query);
        $count = $this->connection->scalar($compiled['sql'], $compiled['bindings']);

        return \is_int($count) ? $count : (int) (\is_numeric($count) ? $count : 0);
    }

    public function insert(string $collection, string $key, array $row): int|string|null
    {
        // A key the row already carries wins over whatever the database would
        // say: the application supplied it deliberately, and there is nothing
        // to ask for.
        /** @var mixed $supplied */
        $supplied = $row[$key] ?? null;

        if (\is_int($supplied) || \is_string($supplied)) {
            $compiled = $this->grammar->compileInsert($collection, $row);
            $this->connection->execute($compiled['sql'], $compiled['bindings']);

            return $supplied;
        }

        // Otherwise the statement hands the generated key back where the
        // dialect can (RETURNING, OUTPUT), which is the only way that is
        // correct on PostgreSQL.
        $compiled = $this->grammar->compileInsert($collection, $row, $key);

        return $this->connection->insert($compiled['sql'], $compiled['bindings']);
    }

    public function update(string $collection, string $key, int|string $identity, array $changes): int
    {
        if ($changes === []) {
            return 0;
        }

        $compiled = $this->grammar->compileUpdate($collection, $key, $identity, $changes);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }

    public function delete(string $collection, string $key, int|string $identity): int
    {
        $compiled = $this->grammar->compileDelete($collection, $key, $identity);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }

    // ---- bulk writes ------------------------------------------------------

    /**
     * As few INSERT statements as the driver's placeholder limit allows.
     *
     * Not wrapped in a transaction, on purpose; see BulkWrites. Several
     * statements are sent when the rows do not fit in one, and whether a
     * failure in the last should undo the first is the caller's decision.
     */
    public function insertMany(string $collection, string $key, array $rows): int
    {
        if ($rows === []) {
            // An import that found nothing to import is not an error, and no
            // statement is the only correct INSERT for no rows.
            return 0;
        }

        $columns = Bulk::columns($collection, $rows);
        $stored = 0;

        foreach ($this->grammar->compileInsertMany($collection, $columns, $rows) as $compiled) {
            $stored += $this->connection->execute($compiled['sql'], $compiled['bindings']);
        }

        return $stored;
    }

    public function updateWhere(Query $query, array $changes): int
    {
        $criteria = Bulk::criteria($query, 'update');

        if ($changes === []) {
            return 0;
        }

        $compiled = $this->grammar->compileUpdateWhere($query->collection(), $criteria, $changes);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }

    public function deleteWhere(Query $query): int
    {
        $compiled = $this->grammar->compileDeleteWhere($query->collection(), Bulk::criteria($query, 'delete'));

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }
}
