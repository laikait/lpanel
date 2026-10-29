<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\IsolationLevel;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Run against a real database.
 *
 * SQLite in memory is a genuine PDO driver with genuine prepared statements,
 * transactions and savepoints, so these are integration tests rather than tests
 * of a mock that agrees with whatever the code does. They cost about a
 * millisecond each and need nothing installed.
 */
final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new Connection(ConnectionConfig::of('test', 'sqlite::memory:'));
        $this->connection->execute(
            'CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)',
        );
    }

    private function seed(): void
    {
        foreach ([['Ada', 36], ['Grace', 45], ['Katherine', 52]] as [$name, $age]) {
            $this->connection->insert('INSERT INTO people (name, age) VALUES (?, ?)', [$name, $age]);
        }
    }

    // ---- connecting -------------------------------------------------------

    /** A request that never reads the database never opens a socket. */
    public function test_connecting_is_lazy(): void
    {
        $connection = new Connection(ConnectionConfig::of('idle', 'sqlite::memory:'));

        self::assertFalse($connection->isConnected());
        self::assertSame('idle', $connection->name());
        self::assertSame('sqlite', $connection->driver());

        $connection->pdo();

        self::assertTrue($connection->isConnected());
    }

    public function test_the_same_handle_is_reused(): void
    {
        self::assertSame($this->connection->pdo(), $this->connection->pdo());
    }

    public function test_disconnecting_drops_the_handle(): void
    {
        $this->connection->pdo();
        $this->connection->disconnect();

        self::assertFalse($this->connection->isConnected());
    }

    /**
     * PDO puts the DSN, and on some drivers the credentials, into its own
     * message. Ours names the connection instead.
     */
    public function test_a_connection_failure_names_the_connection_rather_than_the_dsn(): void
    {
        $connection = new Connection(ConnectionConfig::of('broken', 'sqlite:/no/such/directory/db.sqlite'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Could not open the "broken" connection \(sqlite\)/');

        $connection->pdo();
    }

    /** Real prepared statements, not emulated ones. */
    public function test_the_defaults_ask_for_real_prepared_statements(): void
    {
        $options = ConnectionConfig::of('x', 'sqlite::memory:')->pdoOptions();

        // Emulation would interpolate values into the SQL string and hand every
        // column back as a string. Both matter, so both are asserted.
        self::assertFalse($options[\PDO::ATTR_EMULATE_PREPARES]);
        self::assertFalse($options[\PDO::ATTR_STRINGIFY_FETCHES]);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $options[\PDO::ATTR_ERRMODE]);
    }

    public function test_options_can_be_overridden_per_connection(): void
    {
        $config = ConnectionConfig::of('x', 'sqlite::memory:', options: [\PDO::ATTR_CASE => \PDO::CASE_LOWER]);

        self::assertSame(\PDO::CASE_LOWER, $config->pdoOptions()[\PDO::ATTR_CASE]);
        self::assertFalse($config->pdoOptions()[\PDO::ATTR_EMULATE_PREPARES], 'the defaults still apply');
    }

    // ---- reading ----------------------------------------------------------

    public function test_select_returns_associative_rows(): void
    {
        $this->seed();

        $rows = $this->connection->select('SELECT name, age FROM people ORDER BY name');

        self::assertCount(3, $rows);
        self::assertSame(['name' => 'Ada', 'age' => 36], $rows[0]);
    }

    public function test_select_one_returns_a_row_or_null(): void
    {
        $this->seed();

        $row = $this->connection->selectOne('SELECT name FROM people WHERE id = ?', [2]);

        self::assertNotNull($row);
        self::assertSame('Grace', $row['name']);
        self::assertNull($this->connection->selectOne('SELECT name FROM people WHERE id = ?', [99]));
    }

    public function test_scalar_returns_the_first_column_or_null(): void
    {
        $this->seed();

        self::assertSame(3, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertSame(133, $this->connection->scalar('SELECT SUM(age) FROM people'));
        self::assertNull($this->connection->scalar('SELECT name FROM people WHERE id = ?', [99]));
    }

    /** Columns come back with their own types, because emulation is off. */
    public function test_integers_come_back_as_integers(): void
    {
        $this->seed();

        $row = $this->connection->selectOne('SELECT age FROM people WHERE id = 1');

        self::assertNotNull($row);
        self::assertSame(36, $row['age']);
    }

    public function test_a_cursor_yields_one_row_at_a_time(): void
    {
        $this->seed();

        $cursor = $this->connection->cursor('SELECT name FROM people ORDER BY name');
        self::assertInstanceOf(\Generator::class, $cursor);

        $names = [];

        foreach ($cursor as $row) {
            $names[] = $row['name'];
        }

        self::assertSame(['Ada', 'Grace', 'Katherine'], $names);
    }

    public function test_a_cursor_over_nothing_yields_nothing(): void
    {
        self::assertSame([], \iterator_to_array($this->connection->cursor('SELECT * FROM people')));
    }

    // ---- binding ----------------------------------------------------------

    /**
     * The security property the whole layer rests on: a value can never become
     * part of the statement.
     */
    public function test_a_value_that_looks_like_sql_stays_a_value(): void
    {
        $this->connection->insert('INSERT INTO people (name, age) VALUES (?, ?)', ["'; DROP TABLE people; --", 1]);

        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertSame(
            "'; DROP TABLE people; --",
            $this->connection->scalar('SELECT name FROM people WHERE id = 1'),
        );
    }

    public function test_named_parameters_work_as_well_as_positional_ones(): void
    {
        $this->seed();

        self::assertSame(
            'Grace',
            $this->connection->scalar('SELECT name FROM people WHERE age = :age', ['age' => 45]),
        );
    }

    public function test_null_and_boolean_bindings_keep_their_types(): void
    {
        $this->connection->execute('INSERT INTO people (name, age) VALUES (?, ?)', ['Nobody', null]);

        self::assertNull($this->connection->scalar('SELECT age FROM people WHERE name = ?', ['Nobody']));
    }

    /**
     * Left to PDO, a float is written at the `precision` setting -- fourteen
     * digits -- and 0.1 + 0.2 is stored as 0.3.
     */
    public function test_a_float_is_bound_without_losing_digits(): void
    {
        $this->connection->execute('CREATE TABLE amounts (value REAL)');

        foreach ([0.1 + 0.2, 1 / 3, 1e25, -2.5e-12, 100.0] as $value) {
            $this->connection->execute('DELETE FROM amounts');
            $this->connection->execute('INSERT INTO amounts (value) VALUES (?)', [$value]);

            self::assertSame($value, $this->connection->scalar('SELECT value FROM amounts'));
        }
    }

    /** %G follows the locale; a German one would write 0,5. */
    public function test_a_float_is_bound_the_same_under_any_locale(): void
    {
        $previous = \setlocale(\LC_NUMERIC, '0');
        \setlocale(\LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'deu', 'German');

        try {
            self::assertSame('0.5', $this->connection->scalar('SELECT CAST(? AS TEXT)', [0.5]));
        } finally {
            \setlocale(\LC_NUMERIC, \is_string($previous) ? $previous : 'C');
        }
    }

    public function test_a_date_is_bound_as_its_wall_clock_time(): void
    {
        self::assertSame(
            '2026-01-02 03:04:05',
            $this->connection->scalar('SELECT ?', [new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('Asia/Dhaka'))]),
        );

        self::assertSame(
            '2026-01-02 03:04:05.250000',
            $this->connection->scalar('SELECT ?', [new \DateTime('2026-01-02 03:04:05.25')]),
        );
    }

    public function test_a_stream_is_bound_as_binary_data(): void
    {
        $this->connection->execute('CREATE TABLE files (content BLOB)');

        $stream = \fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        \fwrite($stream, "\x00\xFFbinary\x00");
        \rewind($stream);

        $this->connection->execute('INSERT INTO files (content) VALUES (?)', [$stream]);

        self::assertSame("\x00\xFFbinary\x00", $this->connection->scalar('SELECT content FROM files'));
    }

    /** Refused by position and type; the value itself is never quoted. */
    public function test_a_value_that_cannot_be_bound_is_named_by_position_only(): void
    {
        try {
            $this->connection->select('SELECT ? , ?', ['fine', ['secret-in-an-array']]);
            self::fail('an array was bound');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('Parameter #2 is of type array', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }

        $this->expectExceptionMessage('Parameter "amount" is of type float');
        $this->connection->select('SELECT :amount', ['amount' => \NAN]);
    }

    /** A broken statement should say which statement, and not what was in it. */
    public function test_a_failing_statement_reports_the_sql_and_withholds_the_values(): void
    {
        try {
            $this->connection->select('SELECT * FROM nonexistent WHERE secret = ?', ['hunter2']);
            self::fail('the statement should have failed');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('SELECT * FROM nonexistent', $e->getMessage());
            self::assertStringContainsString('1 bound value', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    // ---- writing ----------------------------------------------------------

    public function test_execute_reports_how_many_rows_changed(): void
    {
        $this->seed();

        self::assertSame(2, $this->connection->execute('UPDATE people SET age = age + 1 WHERE age > ?', [40]));
        self::assertSame(0, $this->connection->execute('UPDATE people SET age = 0 WHERE id = ?', [99]));
        self::assertSame(1, $this->connection->execute('DELETE FROM people WHERE id = ?', [1]));
    }

    public function test_insert_returns_the_generated_identity(): void
    {
        self::assertSame(1, $this->connection->insert('INSERT INTO people (name) VALUES (?)', ['Ada']));
        self::assertSame(2, $this->connection->insert('INSERT INTO people (name) VALUES (?)', ['Grace']));
    }

    // ---- transactions -----------------------------------------------------

    public function test_a_transaction_commits_when_the_callback_returns(): void
    {
        $result = $this->connection->transaction(function (Connection $db): string {
            $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertSame(0, $this->connection->transactionDepth());
    }

    public function test_a_transaction_rolls_back_and_rethrows_when_the_callback_throws(): void
    {
        $caught = 'nothing was thrown';

        try {
            $this->connection->transaction(function (Connection $db): void {
                $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);

                throw new \RuntimeException('no');
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('no', $caught, 'the exception should have propagated');

        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertFalse($this->connection->inTransaction());
    }

    /**
     * The application decides the boundary: an invoice, its lines and its
     * entries are one transaction because the application says so, not because
     * each repository method opened one of its own.
     */
    public function test_several_writes_are_one_transaction(): void
    {
        $this->connection->transaction(function (Connection $db): void {
            $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);
            $db->insert('INSERT INTO people (name) VALUES (?)', ['Grace']);
        });

        self::assertSame(2, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    /**
     * PDO has no nested transactions: a second beginTransaction() would throw
     * or be ignored, and the first commit would write everything. Savepoints
     * make nesting mean what it looks like it means.
     */
    public function test_transactions_nest_through_savepoints(): void
    {
        $this->connection->transaction(function (Connection $outer): void {
            $outer->insert('INSERT INTO people (name) VALUES (?)', ['kept']);
            self::assertSame(1, $outer->transactionDepth());

            try {
                $outer->transaction(function (Connection $inner): void {
                    self::assertSame(2, $inner->transactionDepth());
                    $inner->insert('INSERT INTO people (name) VALUES (?)', ['discarded']);

                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
                // Swallowed on purpose: the outer transaction must survive.
            }

            $afterInner = $outer->transactionDepth();
            self::assertSame(1, $afterInner, 'back to the outer transaction');
            $outer->insert('INSERT INTO people (name) VALUES (?)', ['also kept']);
        });

        self::assertSame(
            ['kept', 'also kept'],
            \array_column($this->connection->select('SELECT name FROM people ORDER BY id'), 'name'),
        );
    }

    public function test_nesting_three_deep_commits_everything(): void
    {
        $this->connection->transaction(function (Connection $a): void {
            $a->insert('INSERT INTO people (name) VALUES (?)', ['one']);

            $a->transaction(function (Connection $b): void {
                $b->insert('INSERT INTO people (name) VALUES (?)', ['two']);

                $b->transaction(function (Connection $c): void {
                    self::assertSame(3, $c->transactionDepth());
                    $c->insert('INSERT INTO people (name) VALUES (?)', ['three']);
                });
            });
        });

        self::assertSame(3, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    public function test_an_outer_rollback_discards_committed_inner_work(): void
    {
        try {
            $this->connection->transaction(function (Connection $outer): void {
                $outer->transaction(function (Connection $inner): void {
                    $inner->insert('INSERT INTO people (name) VALUES (?)', ['inner']);
                });

                throw new \RuntimeException('outer failed');
            });
        } catch (\RuntimeException) {
        }

        // Releasing a savepoint commits nothing; the outer transaction decides.
        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    public function test_committing_without_a_transaction_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/no open transaction to commit/');

        $this->connection->commit();
    }

    public function test_rolling_back_without_a_transaction_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/no open transaction to roll back/');

        $this->connection->rollBack();
    }

    public function test_begin_and_commit_can_be_paired_by_hand(): void
    {
        $this->connection->begin();
        self::assertTrue($this->connection->inTransaction());

        $this->connection->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);
        $this->connection->commit();

        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    /** A leaked transaction is closed, and then reported rather than forgotten. */
    public function test_disconnecting_inside_a_transaction_closes_it_and_says_so(): void
    {
        $this->connection->begin();
        $this->connection->begin();

        try {
            $this->connection->disconnect();
            self::fail('closing an open transaction went unreported');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('"test" connection was closed with a transaction still open (2 levels', $e->getMessage());
        }

        self::assertFalse($this->connection->isConnected());
        self::assertSame(0, $this->connection->transactionDepth());

        // Closed, not broken: the next statement opens a fresh session.
        self::assertSame(1, $this->connection->scalar('SELECT 1'));
    }

    public function test_disconnecting_outside_a_transaction_is_quiet(): void
    {
        $this->connection->pdo();
        $this->connection->disconnect();

        self::assertFalse($this->connection->isConnected());
    }

    /**
     * The exception that explains the failure is the callback's. A rollback
     * that fails afterwards closes the connection -- the database discards the
     * transaction with the session -- and must not replace it.
     */
    public function test_the_callbacks_exception_survives_a_failing_rollback(): void
    {
        $caught = null;

        try {
            $this->connection->transaction(function (Connection $db): void {
                // Finish the transaction behind the connection's back, so the
                // rollback that follows has nothing to roll back and fails.
                $db->pdo()->commit();

                throw new \RuntimeException('the real failure');
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('the real failure', $caught->getMessage());
        self::assertFalse($this->connection->isConnected(), 'a failed rollback should close the connection');
        self::assertSame(0, $this->connection->transactionDepth());
    }

    /**
     * An inner rollback that fails loses the whole transaction. An outer level
     * that swallowed the failure must not go on writing into a new session in
     * autocommit mode, where its "transaction" would commit line by line.
     */
    public function test_a_lost_transaction_refuses_everything_until_it_is_rolled_back(): void
    {
        $refused = 'the write was allowed';

        try {
            $this->connection->transaction(function (Connection $outer): void {
                try {
                    $outer->transaction(function (Connection $inner): void {
                        // Remove the savepoint the rollback will look for.
                        $inner->pdo()->exec('RELEASE framework_savepoint_2');

                        throw new \RuntimeException('inner failed');
                    });
                } catch (\RuntimeException) {
                    // Swallowed, as careless code does.
                }

                $outer->insert('INSERT INTO people (name) VALUES (?)', ['written outside any transaction']);
            });
        } catch (DatabaseException $e) {
            $refused = $e->getMessage();
        }

        self::assertStringContainsString('transaction on the "test" connection was lost', $refused);
        self::assertSame(0, $this->connection->transactionDepth());

        // Every level rolled back: the connection is usable again.
        self::assertSame(1, $this->connection->scalar('SELECT 1'));
    }

    public function test_a_lost_transaction_cannot_be_committed(): void
    {
        $this->connection->begin();
        $this->connection->begin();
        $this->connection->pdo()->exec('RELEASE framework_savepoint_2');

        try {
            $this->connection->rollBack();
            self::fail('the rollback to a missing savepoint succeeded');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('Could not roll back on the "test" connection', $e->getMessage());
        }

        self::assertSame(1, $this->connection->transactionDepth());

        try {
            $this->connection->commit();
            self::fail('a lost transaction was committed');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('was lost', $e->getMessage());
        }

        $this->connection->rollBack();

        self::assertFalse($this->connection->inTransaction());
    }

    /**
     * A callback that opens a level and never closes it would otherwise have
     * the commit release its savepoint instead of committing ours.
     */
    public function test_a_callback_that_leaves_a_transaction_open_is_rolled_back(): void
    {
        try {
            $this->connection->transaction(function (Connection $db): void {
                $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);
                $db->begin();
            });
            self::fail('an unbalanced callback was committed');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('returned 2 levels deep, where it began at 1', $e->getMessage());
        }

        self::assertSame(0, $this->connection->transactionDepth());
        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM people'));
    }

    public function test_a_callback_that_finishes_a_transaction_it_did_not_begin_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('returned 0 levels deep, where it began at 1');

        $this->connection->transaction(function (Connection $db): void {
            $db->commit();
        });
    }

    // ---- the observation seam -----------------------------------------------

    /**
     * The observer is told the statement, how long it took, which connection,
     * how many values were bound and how many rows came of it -- and never the
     * values themselves. A profile or a slow-query line is exactly the output
     * that gets pasted into a ticket, and the bindings are where a password is.
     */
    public function test_an_observer_hears_each_statement_but_never_its_bindings(): void
    {
        $heard = [];
        $this->connection->observe(static function (mixed ...$arguments) use (&$heard): void {
            $heard[] = $arguments;
        });

        $this->connection->insert('INSERT INTO people (name, age) VALUES (?, ?)', ['hunter2-secret', 99]);

        self::assertCount(1, $heard);
        self::assertCount(5, $heard[0], 'the observer was passed something beyond the statement, time, connection and counts');
        self::assertSame('INSERT INTO people (name, age) VALUES (?, ?)', $heard[0][0]);
        self::assertIsInt($heard[0][1]);
        self::assertSame('test', $heard[0][2]);
        self::assertSame(2, $heard[0][3], 'the bindings were not counted');
        self::assertSame(1, $heard[0][4], 'the inserted row was not counted');
        self::assertStringNotContainsString('hunter2', \var_export($heard, true));
    }

    /** An observer written for three arguments, as they were, still works. */
    public function test_an_observer_that_wants_fewer_arguments_is_given_what_it_asks_for(): void
    {
        $heard = [];
        $this->connection->observe(static function (string $sql, int $nanoseconds, string $connection) use (&$heard): void {
            $heard[] = $connection;
        });

        $this->connection->scalar('SELECT 1');

        self::assertSame(['test'], $heard);
    }

    /** Rows handed back for a read, changed for a write; null where it was not known when it was reported. */
    public function test_the_row_count_is_what_the_statement_produced(): void
    {
        $this->seed();

        $rows = [];
        $this->connection->observe(static function (string $sql, int $ns, string $name, int $bindings, ?int $count) use (&$rows): void {
            $rows[] = $count;
        });

        $this->connection->select('SELECT * FROM people');
        $this->connection->select('SELECT * FROM people WHERE age > ?', [100]);
        $this->connection->selectOne('SELECT * FROM people WHERE age > ?', [40]);
        $this->connection->scalar('SELECT name FROM people WHERE age > ?', [100]);
        $this->connection->execute('UPDATE people SET age = age + 1 WHERE age > ?', [40]);
        \iterator_to_array($this->connection->cursor('SELECT * FROM people'));
        $this->connection->run('SELECT 1');

        try {
            $this->connection->select('SELECT * FROM no_such_table');
        } catch (DatabaseException) {
        }

        self::assertSame([3, 0, 1, 0, 2, null, null, null], $rows);
    }

    public function test_a_failing_statement_is_still_observed(): void
    {
        $heard = 0;
        $this->connection->observe(static function () use (&$heard): void {
            ++$heard;
        });

        try {
            $this->connection->select('SELECT * FROM no_such_table');
            self::fail('the statement did not fail');
        } catch (DatabaseException) {
        }

        self::assertSame(1, $heard);
    }

    // ---- events -------------------------------------------------------------

    /** @var list<array{string, list<mixed>}> what the connection announced, in order, as [event, arguments] */
    private array $events = [];

    private function listen(): void
    {
        $this->connection->listen(function (string $event, mixed ...$arguments): void {
            $this->events[] = [$event, \array_values($arguments)];
        });
    }

    /** @return list<string> */
    private function announced(): array
    {
        return \array_map(static fn(array $event): string => $event[0], $this->events);
    }

    public function test_a_failed_statement_is_announced_with_its_failure_but_never_its_values(): void
    {
        $this->listen();

        try {
            $this->connection->select('SELECT * FROM no_such_table WHERE secret = ?', ['hunter2-secret']);
            self::fail('the statement did not fail');
        } catch (DatabaseException $thrown) {
        }

        self::assertSame(['query.failed'], $this->announced());
        [$failure, $connection] = $this->events[0][1];
        self::assertSame($thrown, $failure, 'the listener was not given the failure the caller got');
        self::assertSame($this->connection, $connection);
        self::assertStringNotContainsString('hunter2', $thrown->getMessage());
    }

    /** Only the outermost transaction commits; a savepoint released inside it is not announced. */
    public function test_a_commit_is_announced_once_for_the_outermost_transaction(): void
    {
        $this->listen();

        $this->connection->transaction(static function (Connection $db): void {
            $db->transaction(static fn(Connection $inner): mixed => $inner->insert('INSERT INTO people (name) VALUES (?)', ['Ada']));
        });

        $this->connection->begin();
        $this->connection->commit();

        self::assertSame(['transaction.committed', 'transaction.committed'], $this->announced());
        self::assertSame([$this->connection], $this->events[0][1]);
    }

    public function test_a_rollback_is_announced_with_what_caused_it(): void
    {
        $this->listen();
        $failure = new \RuntimeException('the invoice did not balance');

        try {
            $this->connection->transaction(static function (Connection $db) use ($failure): void {
                // A savepoint rolled back inside it is not the transaction ending.
                try {
                    $db->transaction(static function (): never {
                        throw new \LogicException('inner');
                    });
                } catch (\LogicException) {
                }

                throw $failure;
            });
        } catch (\RuntimeException) {
        }

        $this->connection->begin();
        $this->connection->rollBack();

        self::assertSame(['transaction.rolled_back', 'transaction.rolled_back'], $this->announced());
        self::assertSame([$this->connection, $failure], $this->events[0][1]);
        self::assertSame([$this->connection, null], $this->events[1][1], 'a rollBack() called by hand has no cause to give');
    }

    public function test_each_retry_is_announced_with_the_attempt_about_to_run(): void
    {
        $this->listen();
        $attempts = 0;

        $this->connection->transaction(static function () use (&$attempts): void {
            if (++$attempts < 3) {
                throw self::deadlock();
            }
        }, retries: 3);

        self::assertSame(
            ['transaction.rolled_back', 'transaction.retrying', 'transaction.rolled_back', 'transaction.retrying', 'transaction.committed'],
            $this->announced(),
        );
        self::assertInstanceOf(\PDOException::class, $this->events[1][1][0]);
        self::assertSame(2, $this->events[1][1][1]);
        self::assertSame(3, $this->events[3][1][1]);
        self::assertSame($this->connection, $this->events[3][1][2]);
    }

    /**
     * A listener that throws after the commit reports on a transaction that
     * has happened. Even a deadlock of its own is not a reason to run the
     * transaction again -- that would write it twice.
     */
    public function test_a_listener_that_throws_after_the_commit_never_causes_a_retry(): void
    {
        $this->connection->listen(static function (string $event): void {
            if ($event === 'transaction.committed') {
                throw self::deadlock();
            }
        });
        $attempts = 0;

        try {
            $this->connection->transaction(function (Connection $db) use (&$attempts): void {
                ++$attempts;
                $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']);
            }, retries: 3);
            self::fail("the listener's exception was swallowed");
        } catch (\PDOException) {
        }

        self::assertSame(1, $attempts);
        self::assertSame(1, $this->connection->scalar('SELECT COUNT(*) FROM people'));
        self::assertFalse($this->connection->inTransaction());
    }

    /** A failure on its way out is the one the caller sees, whatever a listener throws. */
    public function test_a_listener_cannot_replace_a_failure_already_on_its_way_out(): void
    {
        $this->connection->listen(static function (): void {
            throw new \LogicException('a listener broke');
        });

        try {
            $this->connection->select('SELECT * FROM no_such_table');
            self::fail('the statement did not fail');
        } catch (DatabaseException) {
        }

        $failure = new \RuntimeException('the invoice did not balance');

        try {
            $this->connection->transaction(static function () use ($failure): void {
                throw $failure;
            });
        } catch (\Throwable $e) {
            self::assertSame($failure, $e);
        }

        self::assertFalse($this->connection->inTransaction());
    }

    public function test_detaching_the_listener_silences_it(): void
    {
        $this->listen();
        $this->connection->listen(null);

        $this->connection->transaction(static fn(): bool => true);

        self::assertSame([], $this->events);
    }

    // ---- isolation and retry ----------------------------------------------

    /** A failure the database would report for a deadlock: SQLSTATE 40001, as a PDOException. */
    private static function deadlock(): \PDOException
    {
        $failure = new \PDOException('Deadlock found when trying to get lock');
        $failure->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        return $failure;
    }

    public function test_a_transaction_runs_at_a_level_the_database_has(): void
    {
        $result = $this->connection->transaction(
            static fn(Connection $db): mixed => $db->insert('INSERT INTO people (name) VALUES (?)', ['Ada']),
            isolation: IsolationLevel::Serializable,
        );

        self::assertSame(1, $result);
        self::assertFalse($this->connection->inTransaction());
    }

    /** SQLite is serializable only; READ COMMITTED is refused, not silently strengthened. */
    public function test_a_level_the_database_cannot_give_is_refused_before_anything_runs(): void
    {
        $ran = false;

        try {
            $this->connection->transaction(static function () use (&$ran): void {
                $ran = true;
            }, isolation: IsolationLevel::ReadCommitted);
            self::fail('an unsupported isolation level was accepted');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('cannot run a transaction at READ COMMITTED', $e->getMessage());
        }

        self::assertFalse($ran, 'the callback ran');
        self::assertFalse($this->connection->inTransaction());
    }

    public function test_only_the_outermost_transaction_chooses_its_level_or_its_retries(): void
    {
        foreach ([
            'an isolation level' => static fn(Connection $db): mixed => $db->transaction(static fn(): bool => true, isolation: IsolationLevel::Serializable),
            'retries' => static fn(Connection $db): mixed => $db->transaction(static fn(): bool => true, retries: 2),
        ] as $option => $nested) {
            try {
                $this->connection->transaction($nested);
                self::fail(\sprintf('a nested transaction was given %s', $option));
            } catch (DatabaseException $e) {
                self::assertStringContainsString('was given ' . $option, $e->getMessage());
            }

            self::assertFalse($this->connection->inTransaction());
        }
    }

    /** Each attempt starts clean: what a failed attempt wrote is gone. */
    public function test_a_deadlock_is_retried_and_the_failed_attempts_leave_nothing(): void
    {
        $attempts = 0;

        $result = $this->connection->transaction(function (Connection $db) use (&$attempts): string {
            ++$attempts;
            $db->insert('INSERT INTO people (name) VALUES (?)', ['attempt ' . $attempts]);

            if ($attempts < 3) {
                throw self::deadlock();
            }

            return 'done';
        }, retries: 3);

        self::assertSame('done', $result);
        self::assertSame(3, $attempts);
        self::assertSame([['name' => 'attempt 3']], $this->connection->select('SELECT name FROM people'));
    }

    public function test_the_last_deadlock_is_thrown_once_the_retries_are_spent(): void
    {
        $attempts = 0;
        $caught = null;

        try {
            $this->connection->transaction(function () use (&$attempts): void {
                ++$attempts;

                throw self::deadlock();
            }, retries: 2);
        } catch (\PDOException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\PDOException::class, $caught, 'the deadlock was swallowed');
        self::assertSame('40001', $caught->errorInfo[0] ?? null);
        self::assertSame(3, $attempts, 'one attempt and two retries');
    }

    /** @return array<string, array{\Throwable}> */
    public static function notRetryable(): array
    {
        $constraint = new \PDOException('UNIQUE constraint failed');
        $constraint->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];

        $timeout = new \PDOException('Lock wait timeout exceeded');
        $timeout->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

        return [
            'a constraint violation' => [$constraint],
            'a lock wait timeout' => [$timeout],
            'an application error' => [new \RuntimeException('card declined')],
        ];
    }

    /** Only a deadlock or a serialization failure is worth a second attempt. */
    #[DataProvider('notRetryable')]
    public function test_nothing_else_is_retried(\Throwable $failure): void
    {
        $attempts = 0;

        try {
            $this->connection->transaction(function () use (&$attempts, $failure): void {
                ++$attempts;

                throw $failure;
            }, retries: 5);
        } catch (\Throwable $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(1, $attempts);
    }

    /** A deadlock reported through the framework's own exception is still recognised. */
    public function test_a_wrapped_deadlock_is_recognised_by_its_cause(): void
    {
        self::assertTrue($this->connection->isRetryable(DatabaseException::statementFailed('UPDATE x', [], self::deadlock())));
        self::assertFalse($this->connection->isRetryable(new \LogicException('no')));
    }

    public function test_negative_retries_are_refused(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connection->transaction(static fn(): bool => true, retries: -1);
    }
}
