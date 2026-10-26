<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Database\Structure\Tables;

/**
 * One database connection.
 *
 * Nothing here knows what a model, a repository or a query object is. That is
 * the point: the database layer is usable on its own, for a report, a migration
 * or a one-off script, and the data layer sits on top of it rather than being
 * the only way in.
 *
 *     $connection->select('SELECT * FROM invoices WHERE customer_id = ?', [$id]);
 *     $connection->transaction(function (Connection $db) use ($invoice): void {
 *         $db->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [...]);
 *         $db->insert('INSERT INTO entries (invoice_id, amount) VALUES (?, ?)', [...]);
 *     });
 *
 * **Connecting is lazy.** The PDO handle is opened on first use, so a request
 * that never reads the database never opens a socket -- which is what makes it
 * reasonable to configure connections an application only sometimes needs.
 *
 * **Values are always bound, never interpolated.** Every method here takes SQL
 * and a separate list of bindings, and prepared statements are real rather than
 * emulated, so a value cannot become part of the statement.
 */
final class Connection
{
    /**
     * PDO::SQLSRV_ENCODING_BINARY, written out: the constant exists only where
     * pdo_sqlsrv is loaded, and this file must load everywhere.
     */
    private const SQLSRV_ENCODING_BINARY = 2;

    private ?\PDO $pdo = null;

    /** How deep the current transaction is nested; 0 means none is open. */
    private int $depth = 0;

    /**
     * Whether the open transaction was lost to a failed rollback.
     *
     * The connection is closed by then, so the next statement would quietly
     * open a new session in autocommit mode, and an outer level that caught
     * the failure would go on writing outside any transaction at all. Until
     * every level still open has rolled back, nothing else runs.
     */
    private bool $lost = false;

    /** @var (\Closure(string, int, string, int, ?int): void)|null */
    private ?\Closure $observer = null;

    /** @var (\Closure(string, mixed...): void)|null */
    private ?\Closure $listener = null;

    private ?Grammar $grammar = null;

    /** What puts the session's isolation level back when this transaction ends, if anything must. */
    private ?string $resetIsolation = null;

    public function __construct(private readonly ConnectionConfig $config) {}

    /** The dialect this connection speaks, for the statements it writes itself. */
    public function grammar(): Grammar
    {
        return $this->grammar ??= Grammar::for($this->driver());
    }

    /**
     * A query builder over a table on this connection.
     *
     *     $connection->table('invoices')->where('status', 'open')->orderBy('id')->get();
     *
     * Building one opens nothing; the connection is used when a terminal method
     * runs the query. See QueryBuilder.
     */
    public function table(string $table): QueryBuilder
    {
        return QueryBuilder::on($this, $table);
    }

    /**
     * Creating and dropping tables in this connection's dialect.
     *
     *     $connection->tables()->create('invoices', static function (Table $table): void { ... });
     *
     * See Structure\Tables.
     */
    public function tables(bool $pretend = false): Tables
    {
        return new Tables($this, $pretend);
    }

    /** Whether this connection's database can do something at all. */
    public function supports(Capability $capability): bool
    {
        return $this->grammar()->supports($capability);
    }

    public function name(): string
    {
        return $this->config->name;
    }

    public function driver(): string
    {
        return $this->config->driver();
    }

    public function config(): ConnectionConfig
    {
        return $this->config;
    }

    // ---- the handle -------------------------------------------------------

    /**
     * The PDO handle, opened on first use.
     *
     * Public because there is always something PDO can do that this class has
     * not wrapped, and a layer that forces people to work around it is worse
     * than one that admits an escape hatch.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        try {
            return $this->pdo = new \PDO(
                $this->config->dsn,
                $this->config->username,
                $this->config->password,
                $this->config->pdoOptions(),
            );
        } catch (\PDOException $e) {
            // PDO puts the DSN, and sometimes the credentials, into its own
            // message. Ours names the connection instead.
            throw DatabaseException::cannotConnect($this->name(), $this->driver(), $e);
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof \PDO;
    }

    /**
     * Close the connection.
     *
     * A long-running worker between jobs, or anything that has finished with
     * the database and does not want to hold a server-side session open.
     *
     * Closing inside a transaction still closes -- the server discards what was
     * not committed, so nothing is left half-written -- and then says so. A
     * transaction still open at this point was leaked by whoever opened it, and
     * its work has just been thrown away; that is not something to learn about
     * from a missing invoice.
     */
    public function disconnect(): void
    {
        $open = $this->depth;
        $this->abandon();

        if ($open > 0) {
            throw DatabaseException::closedInTransaction($this->name(), $open);
        }
    }

    /**
     * Drop the handle and forget any transaction on it.
     *
     * The server rolls back whatever the session had not committed when the
     * session ends, which makes this the rollback of last resort: the one that
     * cannot fail.
     */
    private function abandon(): void
    {
        $this->pdo = null;
        $this->depth = 0;
        $this->lost = false;
        $this->resetIsolation = null;
    }

    private function assertNotLost(): void
    {
        if ($this->lost) {
            throw DatabaseException::transactionLost($this->name(), $this->depth);
        }
    }

    // ---- reading ----------------------------------------------------------

    /**
     * @param array<array-key, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        [$statement, $started] = $this->statement($sql, $bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $this->observed($sql, $started, $bindings, \count($rows));

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        [$statement, $started] = $this->statement($sql, $bindings);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->observed($sql, $started, $bindings, \is_array($row) ? 1 : 0);

        return \is_array($row) ? $row : null;
    }

    /**
     * The first column of the first row: a count, a sum, an existence check.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        [$statement, $started] = $this->statement($sql, $bindings);

        $value = $statement->fetchColumn();
        $this->observed($sql, $started, $bindings, $value === false ? 0 : 1);

        return $value === false ? null : $value;
    }

    /**
     * The rows one at a time, without loading them all.
     *
     * For an export or a job over a table that does not fit in memory. The
     * statement stays open until the generator is exhausted or discarded.
     *
     * @param array<array-key, mixed> $bindings
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(string $sql, array $bindings = []): \Generator
    {
        $statement = $this->run($sql, $bindings);

        while (true) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!\is_array($row)) {
                break;
            }

            /** @var array<string, mixed> $row */
            yield $row;
        }

        $statement->closeCursor();
    }

    // ---- writing ----------------------------------------------------------

    /**
     * Run a statement and report how many rows it changed.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        [$statement, $started] = $this->statement($sql, $bindings);

        $changed = $statement->rowCount();
        $this->observed($sql, $started, $bindings, $changed);

        return $changed;
    }

    /**
     * Insert and return the identity the database assigned, if it assigned one.
     *
     * A table whose key the application supplies returns null here, and that is
     * not an error -- the caller already knows the key it sent.
     *
     * Where the identity comes from:
     *
     *   - **The statement itself**, when it has a RETURNING or OUTPUT clause:
     *     the first column of the row it hands back. Grammar::compileInsert()
     *     writes one where the dialect has Capability::Returning.
     *   - **Otherwise the connection's last-insert id**, which MySQL and SQLite
     *     keep per statement.
     *   - **Never PostgreSQL's**, which is LASTVAL(): the last value of whichever
     *     sequence the session touched, so a stale key after an insert into a
     *     table with no sequence -- and inside a transaction that touched none,
     *     an error that aborts the whole transaction. On PostgreSQL an insert
     *     without RETURNING answers null.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int|string|null
    {
        [$statement, $started] = $this->statement($sql, $bindings);

        // SQL Server counts nothing for a statement that also hands back rows.
        $inserted = $statement->rowCount();
        $this->observed($sql, $started, $bindings, $inserted >= 0 ? $inserted : null);

        if ($statement->columnCount() > 0) {
            $identity = $statement->fetchColumn();
            $statement->closeCursor();

            return self::identity($identity);
        }

        if ($this->driver() === 'pgsql') {
            return null;
        }

        try {
            $identity = $this->pdo()->lastInsertId();
        } catch (\PDOException) {
            // Some drivers raise rather than returning false when there is no
            // sequence to report. Nothing was inserted badly; there is just
            // nothing to say.
            return null;
        }

        return self::identity($identity);
    }

    /** An identity as the application sees one: an integer when it is one, null when there is none. */
    private static function identity(mixed $identity): int|string|null
    {
        if (\is_int($identity)) {
            return $identity === 0 ? null : $identity;
        }

        if (!\is_string($identity) || $identity === '' || $identity === '0') {
            return null;
        }

        return \ctype_digit($identity) ? (int) $identity : $identity;
    }

    /**
     * Prepare, bind and execute.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): \PDOStatement
    {
        [$statement, $started] = $this->statement($sql, $bindings);
        $this->observed($sql, $started, $bindings, null);

        return $statement;
    }

    /**
     * Prepare, bind and execute, and say when it started if anything is timing it.
     *
     * A statement that fails is observed here, and announced as
     * query.failed; one that succeeds is observed by the caller, once it knows
     * how many rows there were.
     *
     * @param array<array-key, mixed> $bindings
     *
     * @return array{\PDOStatement, int|float}
     */
    private function statement(string $sql, array $bindings): array
    {
        $this->assertNotLost();

        $started = $this->observer === null ? 0 : \hrtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);
            $this->bind($statement, $bindings);
            $statement->execute();
        } catch (\PDOException $e) {
            $failure = DatabaseException::statementFailed($sql, $bindings, $e);
            $this->observed($sql, $started, $bindings, null);
            $this->quietly('query.failed', $failure, $this);

            throw $failure;
        } catch (\Throwable $e) {
            $this->observed($sql, $started, $bindings, null);

            throw $e;
        }

        return [$statement, $started];
    }

    /** @param array<array-key, mixed> $bindings */
    private function observed(string $sql, int|float $started, array $bindings, ?int $rows): void
    {
        if ($this->observer !== null) {
            ($this->observer)($sql, \hrtime(true) - $started, $this->name(), \count($bindings), $rows);
        }
    }

    /**
     * Be told about each statement: what it was, how long it took, how much.
     *
     * Called after every statement, including one that failed, with:
     *
     *   - the SQL;
     *   - the nanoseconds from prepare until its rows were read -- for a
     *     cursor, until it ran, because the reading happens in the caller's
     *     loop;
     *   - this connection's name;
     *   - how many values were bound into it;
     *   - how many rows it handed back or, for a write, changed -- null where
     *     that was not known when it was reported: a statement that failed, a
     *     cursor, or one run through run().
     *
     * An observer written for fewer of these takes fewer; PHP drops the rest.
     *
     * **The bindings themselves are not passed**, deliberately and permanently.
     * The SQL is safe to write down because every value in it is bound; the
     * bindings are the values -- a password hash, a card number, a customer's
     * address -- and whatever observes queries writes somewhere somebody will
     * read. How many there were says whether an IN list grew; what they were is
     * nobody's business.
     *
     * @param (\Closure(string, int, string, int, ?int): void)|null $observer
     */
    public function observe(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    /**
     * Be told what happened to statements and transactions, as named events.
     *
     *   - `query.failed` (DatabaseException $failure, Connection $connection):
     *     the database refused a statement. The exception carries the SQL and
     *     how many values were bound, never the values.
     *   - `transaction.committed` (Connection $connection): the outermost
     *     transaction committed. Releasing a savepoint commits nothing and is
     *     not announced.
     *   - `transaction.rolled_back` (Connection $connection, ?\Throwable $cause):
     *     the outermost transaction was rolled back -- by rollBack(), with no
     *     cause, or by transaction(), with the failure that ended it.
     *   - `transaction.retrying` (\Throwable $failure, int $attempt, Connection $connection):
     *     transaction() is about to run again after a deadlock or a
     *     serialization failure; $attempt is the number of the one about to
     *     start, 2 for the first retry.
     *
     * This layer knows nothing about hooks; the bootstrap turns each of these
     * into the hook `database.<event>`. Null detaches.
     *
     * An event is announced once what it describes is settled, so a listener
     * cannot change it: a listener that throws after a commit does not undo
     * it, and is never taken for a reason to run the transaction again. And a
     * listener cannot replace a failure already on its way out -- one that
     * throws on query.failed, or on a rollback inside transaction(), is
     * ignored, and the database's failure is the one the caller sees.
     *
     * @param (\Closure(string, mixed...): void)|null $listener
     */
    public function listen(?\Closure $listener): void
    {
        $this->listener = $listener;
    }

    private function announce(string $event, mixed ...$arguments): void
    {
        if ($this->listener !== null) {
            ($this->listener)($event, ...$arguments);
        }
    }

    /** Announce while a failure is already on its way out, which a listener must not replace. */
    private function quietly(string $event, mixed ...$arguments): void
    {
        try {
            $this->announce($event, ...$arguments);
        } catch (\Throwable) {
            // See listen(): the failure in flight is the one that explains
            // what went wrong.
        }
    }

    /**
     * Bind each value with the type PDO should use.
     *
     * Positional parameters are one-based in PDO and zero-based in PHP arrays,
     * which is the sort of detail that produces an off-by-one nobody enjoys
     * finding.
     *
     *   - A float is written with as many digits as it takes to come back
     *     unchanged. PDO would otherwise turn it into a string at the
     *     `precision` setting, fourteen digits, and 0.1 + 0.2 would be stored
     *     as 0.3.
     *   - A date is written as its own wall-clock time, `Y-m-d H:i:s`, with
     *     microseconds when it has any. The time zone is not converted: which
     *     zone a column holds is the application's decision, and a conversion
     *     made here would be one nobody could see.
     *   - A stream resource is sent as a large object, for binary data. SQL
     *     Server would otherwise send it as text, and refuse to put text in a
     *     VARBINARY column, so there it is bound with the driver's binary
     *     encoding -- an option only bindParam() takes.
     *   - Anything else is refused by position, never by value.
     *
     * @param array<array-key, mixed> $bindings
     */
    private function bind(\PDOStatement $statement, array $bindings): void
    {
        $position = 1;
        $streams = [];

        foreach ($bindings as $key => $value) {
            $parameter = \is_int($key) ? $position++ : $key;

            if (\is_resource($value) && $this->driver() === 'sqlsrv') {
                $streams[$parameter] = $value;
                $statement->bindParam($parameter, $streams[$parameter], \PDO::PARAM_LOB, 0, self::SQLSRV_ENCODING_BINARY);

                continue;
            }

            [$bound, $type] = match (true) {
                \is_int($value) => [$value, \PDO::PARAM_INT],
                \is_bool($value) => [$value, \PDO::PARAM_BOOL],
                $value === null => [null, \PDO::PARAM_NULL],
                \is_string($value) => [$value, \PDO::PARAM_STR],
                \is_float($value) && \is_finite($value) => [self::decimal($value), \PDO::PARAM_STR],
                $value instanceof \DateTimeInterface => [self::timestamp($value), \PDO::PARAM_STR],
                $value instanceof \Stringable => [(string) $value, \PDO::PARAM_STR],
                \is_resource($value) => [$value, \PDO::PARAM_LOB],
                default => throw DatabaseException::unbindableValue($parameter, \get_debug_type($value)),
            };

            $statement->bindValue($parameter, $bound, $type);
        }
    }

    /**
     * The shortest decimal that reads back as the same float.
     *
     * %H rather than %G: the uppercase G follows the locale, and a German one
     * writes the decimal point as a comma.
     */
    private static function decimal(float $value): string
    {
        $short = \sprintf('%.15H', $value);

        return (float) $short === $value ? $short : \sprintf('%.17H', $value);
    }

    private static function timestamp(\DateTimeInterface $value): string
    {
        return $value->format($value->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }

    // ---- transactions -----------------------------------------------------

    /**
     * Run a callback inside a transaction, committing it or rolling it back.
     *
     * The application decides the boundary, which is why this takes a callback
     * rather than every repository method opening one of its own. An invoice,
     * its lines, its accounting entries and its payment are one transaction
     * because the application says so.
     *
     *     $db->transaction($callback, isolation: IsolationLevel::Serializable, retries: 3);
     *
     * **$isolation** sets the level the transaction runs at, written where the
     * database needs it; a level it cannot give is refused before anything
     * runs. **$retries** runs the whole transaction again, from a clean state,
     * when it fails with a deadlock or a serialization failure -- and never
     * for anything else. Both belong to the outermost transaction only.
     *
     * **With retries, the callback may run more than once.** Everything it does
     * to this database is rolled back between attempts; nothing else is. An
     * email sent, a payment taken, a file written or a queue job pushed inside
     * it happens once per attempt. Do those after the transaction returns.
     *
     * @template T
     *
     * @param \Closure(self): T $callback
     *
     * @return T
     */
    public function transaction(\Closure $callback, ?IsolationLevel $isolation = null, int $retries = 0): mixed
    {
        if ($retries < 0) {
            throw DatabaseException::negativeRetries($retries);
        }

        if ($retries > 0 && $this->depth > 0) {
            throw DatabaseException::nestedTransactionOptions($this->name(), 'retries');
        }

        $outermost = $this->depth === 0;
        $attempt = 1;

        while (true) {
            try {
                $result = $this->attempt($callback, $isolation);

                break;
            } catch (\Throwable $e) {
                if ($attempt > $retries || !$this->isRetryable($e)) {
                    throw $e;
                }

                ++$attempt;
                $this->announce('transaction.retrying', $e, $attempt, $this);
                $this->pause($attempt - 1);
            }
        }

        // Outside the loop: a listener that throws now is reporting on a
        // transaction that has committed, and nothing it throws is a reason to
        // run that transaction again.
        if ($outermost) {
            $this->announce('transaction.committed', $this);
        }

        return $result;
    }

    /**
     * Whether a failure is a deadlock or a serialization failure, which running
     * the transaction again can cure. Anything else is not, and is never
     * retried: a constraint violation fails the same way twice.
     *
     * For code that manages its own retries; transaction(retries:) asks this.
     */
    public function isRetryable(\Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException) {
                return $this->grammar()->isRetryable($cause);
            }
        }

        return false;
    }

    /**
     * @template T
     *
     * @param \Closure(self): T $callback
     *
     * @return T
     */
    private function attempt(\Closure $callback, ?IsolationLevel $isolation): mixed
    {
        $this->begin($isolation);
        $level = $this->depth;

        try {
            $result = $callback($this);

            // A callback that began a transaction and never finished it would
            // otherwise have this commit() release its savepoint instead of
            // ours, and leave our own transaction open behind it.
            if ($this->depth !== $level) {
                throw DatabaseException::unbalancedTransaction($this->name(), $level, $this->depth);
            }

            // Not commit(), which announces: transaction() does, once there is
            // no retry left to confuse it with.
            $this->commitLevel();

            return $result;
        } catch (\Throwable $e) {
            $this->unwindTo($level - 1, $e);

            // The callback's exception, always. If the rollback failed as well,
            // the connection has been closed, which is the rollback that could
            // not fail; the failure that explains what went wrong is this one.
            throw $e;
        }
    }

    /**
     * A short wait before another attempt, growing with each one and jittered,
     * so that two transactions that deadlocked on each other do not collide
     * again on the same schedule: 10 ms, 20, 40 ... up to 200, plus up to 5.
     */
    private function pause(int $attempt): void
    {
        \usleep(\min(200_000, 10_000 * (2 ** ($attempt - 1))) + \random_int(0, 5_000));
    }

    /**
     * Roll back until the transaction is $depth deep, whatever it takes, on
     * the way out with $cause.
     *
     * Ending the outermost transaction is announced even if the rollback
     * itself failed: the connection was closed then, and the server discarded
     * the work, which is the rollback all the same.
     */
    private function unwindTo(int $depth, \Throwable $cause): void
    {
        $ending = $depth === 0 && $this->depth > 0;

        while ($this->depth > $depth) {
            try {
                $this->rollBackLevel();
            } catch (DatabaseException) {
                // The rollback failed and closed the connection. The levels
                // left are lost, and rolling them back only counts them down.
            }
        }

        if ($ending) {
            $this->quietly('transaction.rolled_back', $this, $cause);
        }
    }

    /**
     * Open a transaction, or a savepoint inside the one already open.
     *
     * PDO has no nested transactions, so the second begin() would silently be a
     * no-op and the first commit() would write everything. Savepoints make
     * nesting mean what it looks like it means.
     *
     * The caveat worth knowing: rolling back to a savepoint undoes the inner
     * work and leaves the outer transaction open. If the calling code swallows
     * the exception that caused it, the outer transaction still commits -- with
     * the inner work gone. Nested transactions are like that everywhere; catch
     * deliberately or not at all.
     *
     * An isolation level applies to the outermost transaction only, and is
     * checked before anything is sent: a level the database cannot give is
     * refused, not approximated.
     */
    public function begin(?IsolationLevel $isolation = null): void
    {
        $this->assertNotLost();

        if ($this->depth === 0) {
            $statements = $isolation === null
                ? ['before' => null, 'after' => null, 'reset' => null]
                : $this->grammar()->compileIsolation($isolation);

            try {
                if ($statements['before'] !== null) {
                    $this->pdo()->exec($statements['before']);
                }

                $this->pdo()->beginTransaction();
            } catch (\PDOException $e) {
                throw DatabaseException::transactionFailed('begin a transaction', $this->name(), $e);
            }

            $this->depth = 1;
            $this->resetIsolation = $statements['reset'];

            if ($statements['after'] !== null) {
                try {
                    $this->pdo()->exec($statements['after']);
                } catch (\PDOException $e) {
                    $failure = DatabaseException::transactionFailed('set the isolation level', $this->name(), $e);
                    $this->unwindTo(0, $failure);

                    throw $failure;
                }
            }

            return;
        }

        if ($isolation !== null) {
            throw DatabaseException::nestedTransactionOptions($this->name(), 'an isolation level');
        }

        if (!$this->supports(Capability::Savepoints)) {
            throw DatabaseException::driverLacksSavepoints($this->driver());
        }

        try {
            $this->pdo()->exec($this->grammar()->compileSavepoint($this->savepoint($this->depth + 1)));
        } catch (\PDOException $e) {
            throw DatabaseException::transactionFailed('open a savepoint', $this->name(), $e);
        }

        ++$this->depth;
    }

    /**
     * Put the session's isolation level back after a transaction that changed
     * it for the whole session, as SQL Server's does. If even that fails, the
     * session is closed: its level is unknown, and the next query must not
     * inherit it.
     */
    private function restoreIsolation(): void
    {
        $reset = $this->resetIsolation;
        $this->resetIsolation = null;

        if ($reset === null || !$this->pdo instanceof \PDO) {
            return;
        }

        try {
            $this->pdo->exec($reset);
        } catch (\PDOException) {
            $this->abandon();
        }
    }

    /**
     * Commit the transaction, or release the savepoint inside it.
     *
     * A failed commit leaves the transaction open, as the database does, so
     * the caller can still roll it back -- which transaction() does.
     */
    public function commit(): void
    {
        $this->commitLevel();

        if ($this->depth === 0) {
            $this->announce('transaction.committed', $this);
        }
    }

    private function commitLevel(): void
    {
        if ($this->depth === 0) {
            throw DatabaseException::notInTransaction('commit');
        }

        $this->assertNotLost();

        if ($this->depth === 1) {
            try {
                $this->pdo()->commit();
            } catch (\PDOException $e) {
                throw DatabaseException::transactionFailed('commit', $this->name(), $e);
            }

            $this->depth = 0;
            $this->restoreIsolation();

            return;
        }

        // Releasing a savepoint does not commit anything; the outer transaction
        // still decides. SQL Server has no release, and needs none.
        $release = $this->grammar()->compileReleaseSavepoint($this->savepoint($this->depth));

        if ($release !== null) {
            try {
                $this->pdo()->exec($release);
            } catch (\PDOException $e) {
                throw DatabaseException::transactionFailed('release a savepoint', $this->name(), $e);
            }
        }

        --$this->depth;
    }

    /**
     * Roll back the transaction, or the work since the last savepoint.
     *
     * A rollback that fails leaves nothing to trust: the inner work may still
     * be there, and an outer transaction that went on to commit would write it.
     * So a failed rollback at any depth closes the connection -- the server
     * discards everything uncommitted when the session ends -- and throws.
     * Outer levels still open find the transaction lost: every statement and
     * commit is refused until each of them has rolled back, which then succeeds
     * at once, because what it asks for has already happened.
     */
    public function rollBack(): void
    {
        $this->rollBackLevel();

        if ($this->depth === 0) {
            $this->announce('transaction.rolled_back', $this, null);
        }
    }

    private function rollBackLevel(): void
    {
        if ($this->depth === 0) {
            throw DatabaseException::notInTransaction('roll back');
        }

        if ($this->lost) {
            --$this->depth;
            $this->lost = $this->depth > 0;

            return;
        }

        try {
            if ($this->depth === 1) {
                $this->pdo()->rollBack();
            } else {
                $this->pdo()->exec($this->grammar()->compileRollbackToSavepoint($this->savepoint($this->depth)));
            }
        } catch (\PDOException $e) {
            $this->pdo = null;
            $this->resetIsolation = null;
            --$this->depth;
            $this->lost = $this->depth > 0;

            throw DatabaseException::transactionFailed('roll back', $this->name(), $e);
        }

        --$this->depth;

        if ($this->depth === 0) {
            $this->restoreIsolation();
        }
    }

    public function transactionDepth(): int
    {
        return $this->depth;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** Generated, never supplied, so it is always a safe identifier. */
    private function savepoint(int $depth): string
    {
        return 'framework_savepoint_' . $depth;
    }
}
