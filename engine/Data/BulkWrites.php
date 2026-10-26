<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * Writes that touch many rows in one operation, without building a model for
 * any of them.
 *
 * A billing run that marks four thousand invoices overdue should be one
 * statement, not four thousand loads, four thousand change sets and four
 * thousand single-row updates. Row-at-a-time is the right default for domain
 * work, because rules run per object; it is the wrong tool for a set, and the
 * specification lists bulk operations among the mechanisms a heavy backend has
 * to have rather than discover it lacks.
 *
 * **Why a second interface rather than three more DataSource methods.**
 * DataSource is five methods on purpose, and a test holds it there: it is the
 * seam something other than a relational database can implement. A source that
 * cannot do set-based writes is still a perfectly good source; it simply does
 * not implement this, and a repository that asks for bulk writes from it is told
 * so by name instead of being handed a loop pretending to be one.
 *
 * **What a Query means here.** Only its criteria. The rows a bulk write touches
 * are "every row these conditions match", so a query carrying an order, a limit,
 * an offset or a column list is refused rather than half-honoured -- UPDATE with
 * a LIMIT means different things on different databases, and on some it is a
 * syntax error. A query with no criteria at all is refused too: "change every
 * row" is a thing to write on purpose, not the consequence of a condition that
 * was accidentally never added.
 *
 * **No transaction is opened.** A multi-row insert larger than one statement can
 * carry is sent in several, and if the third fails the first two are stored --
 * unless the caller opened a transaction around it, which is exactly where the
 * specification puts that decision. See Connection::transaction().
 */
interface BulkWrites
{
    /**
     * Store many new rows, and report how many were stored.
     *
     * No identities come back: returning them portably would mean one statement
     * per row again, which is the cost this exists to avoid. Every row must
     * name the same columns.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertMany(string $collection, string $key, array $rows): int;

    /**
     * Change the named columns of every row the query's criteria match.
     *
     * @param array<string, mixed> $changes
     *
     * @return int how many rows the source reports changed
     */
    public function updateWhere(Query $query, array $changes): int;

    /**
     * Remove every row the query's criteria match.
     *
     * @return int how many rows were removed
     */
    public function deleteWhere(Query $query): int;
}
