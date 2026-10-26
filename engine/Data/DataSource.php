<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * Where rows come from and go to.
 *
 * This is the one interface the data layer defines, and it exists because a
 * data layer without a source is not a thing. Everything above it -- Query,
 * Repository, the read models a query projects into -- is written against these
 * five methods and never against a connection, a statement or a dialect.
 *
 * Two implementations are expected. ArraySource is here, and it is not a toy:
 * a repository tested against it is tested at full speed with no fixtures to
 * load and no database to install. The Database phase adds a PDO-backed one,
 * and nothing above this interface changes when it does.
 *
 * Deliberately absent: transactions, connections and anything resembling SQL.
 * Those belong to the database layer, which owns connection management, nested
 * transaction strategy and prepared statements. Putting a transaction() here
 * would make every source pretend to have one.
 *
 * Identity is by a single key. That is a real constraint -- a composite primary
 * key cannot be expressed -- and it is the constraint that keeps this interface
 * to five methods a document store could implement as easily as a table.
 */
interface DataSource
{
    /**
     * The rows a query selects, in its order, within its limits.
     *
     * Returning an iterable rather than an array is what allows a source to
     * stream a large result instead of holding it all in memory.
     *
     * @return iterable<array<string, mixed>>
     */
    public function fetch(Query $query): iterable;

    /** How many rows the query matches, ignoring its limit and offset. */
    public function count(Query $query): int;

    /**
     * Store a new row and return the identity it was given.
     *
     * A source that assigns identities returns the new one; a source storing a
     * row that already carries its own returns that. Null means neither
     * happened, which the repository treats as an error rather than guessing.
     *
     * @param array<string, mixed> $row
     */
    public function insert(string $collection, string $key, array $row): int|string|null;

    /**
     * Change the named columns of one row, and report how many rows changed.
     *
     * @param array<string, mixed> $changes
     */
    public function update(string $collection, string $key, int|string $identity, array $changes): int;

    /** Remove one row, and report how many rows went. */
    public function delete(string $collection, string $key, int|string $identity): int;
}
