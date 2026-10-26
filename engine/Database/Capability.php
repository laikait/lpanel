<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * Something a database may or may not be able to do.
 *
 * Asked of a Grammar, or of the Connection that speaks it:
 *
 *     $connection->supports(Capability::Savepoints)
 *
 * A case is added with the feature that needs it, never ahead of one, and its
 * answer for each dialect is what that database really does rather than what
 * would be convenient to assume.
 */
enum Capability: string
{
    /** Transactions nest: a savepoint can be set, released and rolled back to. */
    case Savepoints = 'savepoints';

    /**
     * An INSERT can hand back the key it generated, in the same statement:
     * RETURNING on PostgreSQL, OUTPUT INSERTED on SQL Server.
     *
     * Where it is missing, the connection's own last-insert id is asked, which
     * is correct per statement on MySQL and SQLite. PostgreSQL's equivalent,
     * LASTVAL(), is not: it answers for whichever sequence the session used
     * last, and inside a transaction that used none it aborts the transaction.
     */
    case Returning = 'returning';

    /** Insert, or update the row a unique key says is already there, in one statement. */
    case Upsert = 'upsert';

    /**
     * RIGHT JOIN. SQLite has it only since 3.39, and distributions still ship
     * older builds, so it is not claimed there: a left join with the tables the
     * other way round asks the same question.
     */
    case RightJoin = 'right_join';

    /**
     * Creating and dropping tables inside a transaction that can still be
     * rolled back. MySQL commits every CREATE, ALTER and DROP as it runs them,
     * transaction or not, so a change of structure that fails halfway leaves
     * the half that ran.
     */
    case TransactionalDdl = 'transactional_ddl';
}
