<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Error\FrameworkException;

final class DatabaseException extends FrameworkException
{
    /**
     * Withheld: a driver's connection failure quotes the DSN back, and a DSN is
     * one keystroke away from carrying a password.
     */
    public static function cannotConnect(string $name, string $driver, \Throwable $previous): self
    {
        return (new self(
            \sprintf(
                'Could not open the "%s" connection (%s): %s',
                $name,
                $driver,
                $previous->getMessage(),
            ),
            0,
            $previous,
        ))->withheld();
    }

    /**
     * The SQL is in the message and the values are not.
     *
     * A failing statement is useless without the statement, and a bound value
     * is the one thing in a query that is likely to be somebody's password or
     * personal data. Naming the parameters without their values is the line
     * this draws.
     *
     * @param array<array-key, mixed> $bindings
     */
    public static function statementFailed(string $sql, array $bindings, \Throwable $previous): self
    {
        return (new self(
            \sprintf(
                '%s [SQL: %s] [%d bound value%s]',
                $previous->getMessage(),
                $sql,
                \count($bindings),
                \count($bindings) === 1 ? '' : 's',
            ),
            0,
            $previous,
        ))->withheld();
    }

    public static function unknownConnection(string $name, string $configured): self
    {
        return new self(\sprintf(
            'No connection named "%s" is configured. Configured: %s.',
            $name,
            $configured === '' ? '(none)' : $configured,
        ));
    }

    public static function noConnectionsConfigured(): self
    {
        return new self(
            'No database connections are configured. Set database.connections in the '
            . 'application configuration, or DB_DSN in the environment.',
        );
    }

    /**
     * An identifier that is not a plain name.
     *
     * Table and column names cannot be bound as parameters, so they are the one
     * part of a statement built by interpolation. Everything that reaches this
     * point comes from a repository declaration rather than from a request, and
     * refusing anything unusual keeps it that way even if one day something
     * slips through from higher up.
     */
    public static function unsafeIdentifier(string $identifier): self
    {
        return new self(\sprintf(
            'The identifier "%s" is not a plain name. Table and column names are written into the '
            . 'statement rather than bound, so only letters, digits and underscores are accepted. '
            . 'A name that comes from a request is never a column name.',
            $identifier,
        ));
    }

    public static function unknownOperator(string $operator): self
    {
        return new self(\sprintf(
            'The comparison "%s" is not one a where() makes. Use one of =, !=, <>, <, <=, >, >=, like, '
            . 'not like -- or whereNull(), whereIn() or whereBetween() -- and a RawExpression for anything else.',
            $operator,
        ));
    }

    public static function unknownDirection(string $direction): self
    {
        return new self(\sprintf(
            'An order is "asc" or "desc", not "%s". A direction taken from a request is mapped to one of '
            . 'the two first, never passed through.',
            $direction,
        ));
    }

    /** `= NULL` is never true in SQL; saying so here beats a query that silently matches nothing. */
    public static function comparedWithNull(string $column): self
    {
        return new self(\sprintf(
            'where("%s", ...) was given null, and "= NULL" matches nothing in SQL. '
            . 'Use whereNull("%s") or whereNotNull("%s").',
            $column,
            $column,
            $column,
        ));
    }

    public static function groupReturnedNothing(string $type): self
    {
        return new self(\sprintf(
            'A closure given to where() or join() returned %s rather than what it was handed. Builders and '
            . 'join clauses are immutable, so the closure must return what it built: '
            . 'fn (QueryBuilder $q) => $q->where(...)->orWhere(...).',
            $type,
        ));
    }

    public static function unknownAggregate(string $function): self
    {
        return new self(\sprintf(
            'The aggregate "%s" is not one of count, sum, avg, min or max. Anything else is a RawExpression.',
            $function,
        ));
    }

    public static function aggregateOfEverything(string $function): self
    {
        return new self(\sprintf(
            '%s(*) means nothing; only count() takes every row. Name the column to %s.',
            \strtoupper($function),
            $function,
        ));
    }

    /** A join without ON is a cross join, which is almost never what was meant. */
    public static function joinWithoutConditions(string $table): self
    {
        return new self(\sprintf(
            'The join to "%s" has no ON condition, which would pair every row with every other. '
            . 'Give it one: join("%s", "a.id", "=", "b.a_id").',
            $table,
            $table,
        ));
    }

    public static function aggregateOfGroups(string $table, string $function): self
    {
        return new self(\sprintf(
            'The query on "%s" is grouped, so %s() would be one value per group. Select '
            . 'Aggregate::%s(...) alongside the grouped columns and read them with get().',
            $table,
            $function,
            $function,
        ));
    }

    public static function isolationUnsupported(IsolationLevel $level, string $driver): self
    {
        return new self(\sprintf(
            'The %s database cannot run a transaction at %s, and a weaker or stronger level is not '
            . 'substituted without being asked. Choose a level this database has.',
            $driver === '' ? 'unnamed' : $driver,
            $level->value,
        ));
    }

    /** Only the outermost transaction chooses: a savepoint has no isolation level of its own. */
    public static function nestedTransactionOptions(string $connection, string $option): self
    {
        return new self(\sprintf(
            'A transaction nested inside another on the "%s" connection was given %s. Only the outermost '
            . 'transaction can have one: an isolation level is set as a transaction begins, and a retry has to '
            . 'start the whole transaction again.',
            $connection,
            $option,
        ));
    }

    public static function negativeRetries(int $retries): self
    {
        return new self(\sprintf('A transaction cannot be retried %d times.', $retries));
    }

    public static function unsupported(Capability $capability, string $driver): self
    {
        return new self(\sprintf(
            'The %s database cannot do "%s", and the framework does not pretend otherwise. '
            . 'Check $connection->supports(Capability::%s) first, or write the SQL for this database.',
            $driver === '' ? 'unnamed' : $driver,
            $capability->value,
            $capability->name,
        ));
    }

    /**
     * Whether the database said the table is not there.
     *
     * A missing table is a deployment mistake -- `migrate` was not run -- and a
     * store that uses a table says so in its own words. Each driver phrases it
     * differently and none keeps an error code that survives PDO, so the
     * phrasing is what there is: the database's own, in this message and the
     * one it wraps.
     */
    public function meansMissingTable(): bool
    {
        $messages = '';

        for ($cause = $this; $cause !== null; $cause = $cause->getPrevious()) {
            $messages .= ' ' . \strtolower($cause->getMessage());
        }

        foreach (['no such table', 'base table or view not found', 'does not exist', "doesn't exist", 'invalid object name'] as $phrase) {
            if (\str_contains($messages, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /** A row lock ends with the transaction; with none, it would end before the next statement. */
    public static function lockOutsideTransaction(string $table): self
    {
        return new self(\sprintf(
            'Rows of "%s" were read with lockForUpdate() outside a transaction, where the lock would be gone '
            . 'before anything could use it. Read and write inside $connection->transaction().',
            $table,
        ));
    }

    /** A grouped row stands for many rows, and none of them can be locked through it. */
    public static function lockOnGroups(string $table): self
    {
        return new self(\sprintf(
            'A grouped query on "%s" cannot lock rows: each result stands for many. Lock the rows themselves, ungrouped.',
            $table,
        ));
    }

    /** "Every row" has to be said in so many words. */
    public static function writeWithoutConditions(string $table, string $operation): self
    {
        return new self(\sprintf(
            'A %s of "%s" has no conditions, so it would change every row. '
            . 'Add a where(), or call %sAll() if every row is really meant.',
            $operation,
            $table,
            $operation,
        ));
    }

    public static function writeCarries(string $table, string $operation, string $what): self
    {
        return new self(\sprintf(
            'A %s of "%s" was given %s, which a %s cannot honour on every database. '
            . 'Narrow it with conditions instead; if the rows are chosen by order and limit, select their keys '
            . 'first and write whereIn() those.',
            $operation,
            $table,
            $what,
            $operation,
        ));
    }

    public static function aliasInWrite(string $table): self
    {
        return new self(\sprintf(
            'The table "%s" has an alias, and a write cannot use one: the databases disagree about where an '
            . 'alias goes in an update or a delete. Write to the table by its name.',
            $table,
        ));
    }

    /** By position and column names only; the values stay out of the message. */
    public static function inconsistentRows(string $table, int $index): self
    {
        return new self(\sprintf(
            'Row %d written to "%s" names different columns from the first row. Every row of one write '
            . 'names the same columns, so that nothing is filled in silently.',
            $index,
            $table,
        ));
    }

    public static function rowNeedsColumnNames(string $table): self
    {
        return new self(\sprintf(
            'A row written to "%s" is a list rather than column => value pairs. Name every column.',
            $table,
        ));
    }

    public static function rawInBulk(string $table): self
    {
        return new self(\sprintf(
            'A RawExpression was given as a value in a many-row write to "%s". Those are split to fit each '
            . 'database\'s placeholder limit, which a hand-written expression would break. Use insert() '
            . 'or update() for rows that need one.',
            $table,
        ));
    }

    public static function upsertNeedsUniqueBy(string $table): self
    {
        return new self(\sprintf(
            'An upsert into "%s" was given no unique columns. Name the columns of the unique key a '
            . 'conflict is judged by.',
            $table,
        ));
    }

    public static function noRowsToUpdate(string $table): self
    {
        return new self(\sprintf('An update of "%s" was asked to change no columns.', $table));
    }

    public static function noColumnsToInsert(string $table): self
    {
        return new self(\sprintf('An insert into "%s" was given no columns.', $table));
    }

    public static function notInTransaction(string $operation): self
    {
        return new self(\sprintf(
            'There is no open transaction to %s. begin() and %s() must be balanced; '
            . 'prefer transaction(), which balances them for you.',
            $operation,
            $operation,
        ));
    }

    public static function driverLacksSavepoints(string $driver): self
    {
        return new self(\sprintf(
            'Transactions cannot nest on the %s driver: nested transactions use savepoints, and the '
            . 'framework only writes savepoints for mysql, pgsql, sqlite and sqlsrv. '
            . 'Keep the transaction boundary at one level.',
            $driver === '' ? 'unnamed' : $driver,
        ));
    }

    /** Withheld: the driver's message may quote the server's, which may quote anything. */
    public static function transactionFailed(string $operation, string $connection, \Throwable $previous): self
    {
        return (new self(
            \sprintf('Could not %s on the "%s" connection: %s', $operation, $connection, $previous->getMessage()),
            0,
            $previous,
        ))->withheld();
    }

    public static function transactionLost(string $connection, int $depth): self
    {
        return new self(\sprintf(
            'The transaction on the "%s" connection was lost: a rollback failed, so the connection was '
            . 'closed and the database discarded everything uncommitted. Nothing more runs on it until '
            . 'the %d level%s still open %s rolled back; transaction() does that for you.',
            $connection,
            $depth,
            $depth === 1 ? '' : 's',
            $depth === 1 ? 'is' : 'are',
        ));
    }

    public static function closedInTransaction(string $connection, int $depth): self
    {
        return new self(\sprintf(
            'The "%s" connection was closed with a transaction still open (%d level%s deep). '
            . 'Nothing in it was committed, and the database has discarded it. Whatever began that '
            . 'transaction never finished it; transaction() finishes one for you.',
            $connection,
            $depth,
            $depth === 1 ? '' : 's',
        ));
    }

    public static function unbalancedTransaction(string $connection, int $expected, int $actual): self
    {
        return new self(\sprintf(
            'A transaction callback on the "%s" connection returned %d level%s deep, where it began at %d. '
            . 'Something inside it called begin() without commit() or rollBack(), or finished a transaction '
            . 'it did not begin. Everything since the callback began has been rolled back.',
            $connection,
            $actual,
            $actual === 1 ? '' : 's',
            $expected,
        ));
    }

    /** By position and type only: the value is exactly what must not be quoted. */
    public static function unbindableValue(int|string $parameter, string $type): self
    {
        return new self(\sprintf(
            'Parameter %s is of type %s, which cannot be bound. Bind a string, integer, finite float, '
            . 'boolean, null, date, stream resource or Stringable.',
            \is_int($parameter) ? '#' . $parameter : '"' . $parameter . '"',
            $type,
        ));
    }

    /**
     * A connection configured by parts for a driver whose DSN is not written.
     *
     * Every PDO driver documents its own DSN; the framework assembles one only
     * for the drivers it has written the format for.
     */
    public static function cannotAssembleDsn(string $connection, string $driver): self
    {
        return new self(\sprintf(
            'The "%s" connection names the driver "%s" without a dsn. A DSN is assembled from host, '
            . 'port and database only for mysql, pgsql, sqlite and sqlsrv; give this one a "dsn".',
            $connection,
            $driver,
        ));
    }

    /**
     * A DSN part that would change the meaning of the DSN around it.
     *
     * The value is not quoted: a setting that got this far wrong may be one
     * that should not be in a log.
     */
    public static function unsafeDsnPart(string $connection, string $key): self
    {
        return new self(\sprintf(
            'The "%s" connection\'s "%s" contains a character that separates DSN parts (";" or, '
            . 'for the host, ","). Give the whole DSN as "dsn" if it really needs one.',
            $connection,
            $key,
        ));
    }

    /** A table described in a way some database could not create. $subject names the table, column or key. */
    public static function invalidStructure(string $subject, string $reason): self
    {
        return new self($subject . ': ' . $reason);
    }

    /** A driver with no dialect of its own has no column types to write. */
    public static function noStructureDialect(string $driver): self
    {
        return new self(\sprintf(
            'The %s database has no dialect that writes table structure; tables can be created '
            . 'on mysql, pgsql, sqlite and sqlsrv. Write this one\'s DDL by hand.',
            $driver === '' ? 'unnamed' : $driver,
        ));
    }

    /** @param list<string> $drivers */
    public static function rawNotForDriver(string $driver, array $drivers): self
    {
        return new self(\sprintf(
            'This hand-written SQL was written for %s, and this connection is %s. Nothing was run. '
            . 'Describe the change with the table builder, or write the SQL for this database too.',
            \implode(', ', $drivers),
            $driver,
        ));
    }

    /**
     * A change this database's ALTER TABLE has no statement for. It is refused
     * rather than made by rebuilding the table out of sight: a rebuild copies
     * every row, and loses whatever the builder does not describe.
     */
    public static function cannotAlter(string $driver, string $table, string $what): self
    {
        return new self(\sprintf(
            'The %s database cannot %s on the table "%s": its ALTER TABLE has no way to. Nothing was run. '
            . 'The way there is a new table: create it as it should be, copy the rows across, and drop the old one.',
            $driver,
            $what,
            $table,
        ));
    }

    /**
     * RENAME COLUMN on a server older than MySQL 8.0 or MariaDB 10.5.2. The
     * only rename those have is CHANGE, which restates the whole column, and
     * a rename does not know the rest of it.
     */
    public static function renameNeedsNewerServer(string $version, string $table): self
    {
        return new self(\sprintf(
            'This server (%s) has no RENAME COLUMN, so a column of "%s" cannot be renamed. Nothing was run. '
            . 'It needs MySQL 8.0 or MariaDB 10.5.2; before those, restate the column with CHANGE in '
            . '$tables->raw(..., \'mysql\').',
            $version,
            $table,
        ));
    }

    public static function cannotQuote(string $driver): self
    {
        return new self(\sprintf('The %s driver could not quote a default value, so the table was not created.', $driver));
    }
}
