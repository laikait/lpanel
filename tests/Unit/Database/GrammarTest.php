<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Data\ArraySource;
use App\Engine\Data\Operator;
use App\Engine\Data\Query;
use App\Engine\Database\Capability;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Grammar;
use App\Engine\Database\IsolationLevel;
use App\Engine\Database\MySqlGrammar;
use App\Engine\Database\PostgresGrammar;
use App\Engine\Database\SqliteGrammar;
use App\Engine\Database\SqlServerGrammar;
use App\Engine\Model\ModelManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GrammarTest extends TestCase
{
    private function query(): Query
    {
        return Query::on(new ArraySource(), 'customers', new ModelManager());
    }

    private function grammar(string $driver = 'sqlite'): Grammar
    {
        return Grammar::for($driver);
    }

    // ---- identifiers: the injection boundary ------------------------------

    /**
     * No database accepts a parameter where a column goes, so identifiers are
     * the one part of a statement written in rather than bound. This is
     * therefore the only place injection could live, and the pattern is what
     * keeps it from doing so.
     */
    #[DataProvider('dangerousIdentifiers')]
    public function test_an_identifier_that_is_not_a_plain_name_is_refused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/is not a plain name/');

        $this->grammar()->identifier($identifier);
    }

    /** @return array<string, array{0: string}> */
    public static function dangerousIdentifiers(): array
    {
        return [
            'a statement' => ['id; DROP TABLE customers'],
            'a comment' => ['id -- '],
            'a quote' => ["id' OR '1'='1"],
            'a backtick' => ['id`'],
            'a double quote' => ['id"'],
            'a space' => ['customer id'],
            'a dot' => ['customers.id'],
            'a star' => ['*'],
            'a function call' => ['COUNT(*)'],
            'a leading digit' => ['1st'],
            'empty' => [''],
            'a newline' => ["id\nDROP"],
            'a null byte' => ["id\0"],
            'unicode' => ['naïve'],
        ];
    }

    public function test_a_plain_name_is_quoted_for_its_driver(): void
    {
        self::assertSame('`name`', $this->grammar('mysql')->identifier('name'));
        self::assertSame('"name"', $this->grammar('pgsql')->identifier('name'));
        self::assertSame('"name"', $this->grammar('sqlite')->identifier('name'));
        self::assertSame('[name]', $this->grammar('sqlsrv')->identifier('name'));
    }

    public function test_underscores_and_digits_are_fine_after_the_first_character(): void
    {
        self::assertSame('"customer_id_2"', $this->grammar()->identifier('customer_id_2'));
        self::assertSame('"_internal"', $this->grammar()->identifier('_internal'));
    }

    /** An ordering column arriving from a query string must not reach SQL. */
    public function test_a_sort_column_from_a_request_cannot_reach_the_statement(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileSelect($this->query()->orderBy('name; DELETE FROM customers'));
    }

    public function test_a_filtered_column_name_is_checked_too(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileSelect($this->query()->whereIs('id = 1 OR 1', 1));
    }

    // ---- selects ----------------------------------------------------------

    public function test_a_bare_query_selects_everything(): void
    {
        self::assertSame(
            ['sql' => 'SELECT * FROM "customers"', 'bindings' => []],
            $this->grammar()->compileSelect($this->query()),
        );
    }

    public function test_selected_columns_are_listed_and_quoted(): void
    {
        self::assertSame(
            'SELECT "id", "name" FROM "customers"',
            $this->grammar()->compileSelect($this->query()->select('id', 'name'))['sql'],
        );
    }

    public function test_criteria_become_placeholders_and_bindings(): void
    {
        $compiled = $this->grammar()->compileSelect(
            $this->query()->whereIs('active', true)->where('balance', Operator::Gt, 100),
        );

        self::assertSame(
            'SELECT * FROM "customers" WHERE "active" = ? AND "balance" > ?',
            $compiled['sql'],
        );
        self::assertSame([true, 100], $compiled['bindings']);
    }

    public function test_null_comparisons_take_no_placeholder(): void
    {
        self::assertSame(
            'SELECT * FROM "customers" WHERE "ownerId" IS NULL',
            $this->grammar()->compileSelect($this->query()->whereNull('ownerId'))['sql'],
        );

        self::assertSame(
            [],
            $this->grammar()->compileSelect($this->query()->whereNotNull('ownerId'))['bindings'],
        );
    }

    public function test_a_list_gets_one_placeholder_per_value(): void
    {
        $compiled = $this->grammar()->compileSelect($this->query()->whereIn('id', [1, 2, 3]));

        self::assertSame('SELECT * FROM "customers" WHERE "id" IN (?, ?, ?)', $compiled['sql']);
        self::assertSame([1, 2, 3], $compiled['bindings']);

        self::assertStringContainsString(
            'NOT IN (?, ?)',
            $this->grammar()->compileSelect($this->query()->whereNotIn('id', [1, 2]))['sql'],
        );
    }

    public function test_a_like_pattern_is_bound_rather_than_written_in(): void
    {
        $compiled = $this->grammar()->compileSelect($this->query()->whereLike('name', "%'; DROP--%"));

        self::assertSame('SELECT * FROM "customers" WHERE "name" LIKE ?', $compiled['sql']);
        self::assertSame(["%'; DROP--%"], $compiled['bindings']);
    }

    public function test_ordering_is_compiled_in_the_order_declared(): void
    {
        self::assertStringEndsWith(
            'ORDER BY "active" ASC, "name" DESC',
            $this->grammar()->compileSelect($this->query()->orderBy('active')->orderByDesc('name'))['sql'],
        );
    }

    /**
     * Limits are written in rather than bound. They are typed int in PHP by the
     * time they arrive, so there is nothing to inject, and binding them is the
     * one thing several drivers get wrong.
     */
    public function test_limits_and_offsets_are_written_as_integers(): void
    {
        self::assertStringEndsWith(
            'LIMIT 10',
            $this->grammar()->compileSelect($this->query()->limit(10))['sql'],
        );

        self::assertStringEndsWith(
            'LIMIT 10 OFFSET 20',
            $this->grammar()->compileSelect($this->query()->limit(10)->offset(20))['sql'],
        );

        self::assertSame(
            [],
            $this->grammar()->compileSelect($this->query()->limit(10)->offset(20))['bindings'],
        );
    }

    /** Most drivers will not take an offset without a limit. */
    public function test_an_offset_without_a_limit_still_gets_one(): void
    {
        $sql = $this->grammar()->compileSelect($this->query()->offset(5))['sql'];

        self::assertStringContainsString('LIMIT ' . \PHP_INT_MAX, $sql);
        self::assertStringEndsWith('OFFSET 5', $sql);
    }

    /**
     * SQL Server has no LIMIT. OFFSET ... FETCH belongs to ORDER BY, so a query
     * that asked for no order still gets one that orders by nothing.
     *
     * Compiled only: there is no SQL Server driver where this was written, so
     * these assert the documented syntax rather than a server's answer.
     */
    public function test_sql_server_pages_with_offset_and_fetch(): void
    {
        $sqlsrv = $this->grammar('sqlsrv');

        self::assertSame(
            'SELECT * FROM [customers] ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY',
            $sqlsrv->compileSelect($this->query()->limit(10))['sql'],
        );

        self::assertSame(
            'SELECT * FROM [customers] ORDER BY [name] ASC OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY',
            $sqlsrv->compileSelect($this->query()->orderBy('name')->limit(10)->offset(20))['sql'],
        );

        self::assertSame(
            'SELECT * FROM [customers] ORDER BY (SELECT NULL) OFFSET 5 ROWS',
            $sqlsrv->compileSelect($this->query()->offset(5))['sql'],
        );

        self::assertSame('SELECT * FROM [customers]', $sqlsrv->compileSelect($this->query())['sql']);
    }

    /** FETCH NEXT 0 ROWS is an error there; skipping every row is the same nothing. */
    public function test_sql_server_answers_a_limit_of_zero_with_nothing(): void
    {
        self::assertStringEndsWith(
            ' OFFSET ' . \PHP_INT_MAX . ' ROWS FETCH NEXT 1 ROWS ONLY',
            $this->grammar('sqlsrv')->compileSelect($this->query()->limit(0))['sql'],
        );
    }

    /** No other driver is given SQL Server's paging, or loses its own. */
    public function test_every_other_driver_keeps_limit_and_offset(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $sql = $this->grammar($driver)->compileSelect($this->query()->limit(10)->offset(20))['sql'];

            self::assertStringEndsWith(' LIMIT 10 OFFSET 20', $sql, $driver);
            self::assertStringNotContainsString('ORDER BY', $sql, $driver);
        }
    }

    public function test_a_query_with_no_limit_says_nothing_about_limits(): void
    {
        self::assertStringNotContainsString('LIMIT', $this->grammar()->compileSelect($this->query())['sql']);
    }

    // ---- counts -----------------------------------------------------------

    /** The data layer promises a count ignores the slice; so does the SQL. */
    public function test_a_count_keeps_the_criteria_and_drops_the_slice(): void
    {
        $compiled = $this->grammar()->compileCount(
            $this->query()->whereIs('active', true)->orderBy('name')->limit(10)->offset(20),
        );

        self::assertSame('SELECT COUNT(*) FROM "customers" WHERE "active" = ?', $compiled['sql']);
        self::assertSame([true], $compiled['bindings']);
    }

    // ---- writes -----------------------------------------------------------

    public function test_an_insert_lists_its_columns_and_binds_its_values(): void
    {
        $compiled = $this->grammar()->compileInsert('customers', ['name' => 'Ada', 'email' => 'ada@example.test']);

        self::assertSame('INSERT INTO "customers" ("name", "email") VALUES (?, ?)', $compiled['sql']);
        self::assertSame(['Ada', 'ada@example.test'], $compiled['bindings']);
    }

    public function test_an_insert_with_no_columns_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/given no columns/');

        $this->grammar()->compileInsert('customers', []);
    }

    public function test_an_update_sets_only_what_changed_and_ends_with_the_key(): void
    {
        $compiled = $this->grammar()->compileUpdate('customers', 'id', 7, ['name' => 'Ada King']);

        self::assertSame('UPDATE "customers" SET "name" = ? WHERE "id" = ?', $compiled['sql']);
        self::assertSame(['Ada King', 7], $compiled['bindings']);
    }

    public function test_an_update_with_no_changes_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/change no columns/');

        $this->grammar()->compileUpdate('customers', 'id', 7, []);
    }

    public function test_a_delete_targets_one_row_by_its_key(): void
    {
        $compiled = $this->grammar()->compileDelete('customers', 'code', 'GB');

        self::assertSame('DELETE FROM "customers" WHERE "code" = ?', $compiled['sql']);
        self::assertSame(['GB'], $compiled['bindings']);
    }

    public function test_a_column_name_in_a_write_is_checked_as_well(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileInsert('customers', ['name) VALUES (1); --' => 'x']);
    }

    // ---- writing many -----------------------------------------------------

    public function test_a_multi_row_insert_is_one_statement_when_it_fits(): void
    {
        $statements = $this->grammar()->compileInsertMany('customers', ['name', 'email'], [
            ['name' => 'Ada', 'email' => 'ada@example.test'],
            ['email' => 'grace@example.test', 'name' => 'Grace'],
        ]);

        self::assertCount(1, $statements);
        self::assertSame('INSERT INTO "customers" ("name", "email") VALUES (?, ?), (?, ?)', $statements[0]['sql']);
        self::assertSame(['Ada', 'ada@example.test', 'Grace', 'grace@example.test'], $statements[0]['bindings'], 'bound in column order, whatever order the row was written in');
    }

    /**
     * 999 placeholders on SQLite, four columns: 249 rows a statement. The batch
     * boundary is where an off-by-one would lose or duplicate a row.
     */
    public function test_a_multi_row_insert_is_split_at_the_placeholder_limit(): void
    {
        $rows = \array_fill(0, 500, ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);

        $statements = $this->grammar('sqlite')->compileInsertMany('t', ['a', 'b', 'c', 'd'], $rows);

        self::assertSame([249, 249, 2], \array_map(
            static fn(array $statement): int => \intdiv(\count($statement['bindings']), 4),
            $statements,
        ));

        foreach ($statements as $statement) {
            self::assertLessThanOrEqual(999, \count($statement['bindings']));
            self::assertSame(\count($statement['bindings']), \substr_count($statement['sql'], '?'));
        }
    }

    /** @return array<string, array{string, int}> */
    public static function placeholderLimits(): array
    {
        return [
            'sqlite' => ['sqlite', 999],
            'mysql' => ['mysql', 65535],
            'pgsql' => ['pgsql', 65535],
            'sqlsrv' => ['sqlsrv', 2000],
            'unknown driver gets the smallest' => ['odbc', 999],
        ];
    }

    #[DataProvider('placeholderLimits')]
    public function test_each_driver_has_a_placeholder_limit(string $driver, int $limit): void
    {
        self::assertSame($limit, $this->grammar($driver)->maxBindings());
    }

    public function test_a_set_based_update_binds_the_changes_before_the_criteria(): void
    {
        $query = $this->query()->whereIs('active', 1)->where('balance', Operator::Lt, 0);

        $compiled = $this->grammar()->compileUpdateWhere('customers', $query->criteria(), ['active' => 0, 'name' => 'x']);

        self::assertSame('UPDATE "customers" SET "active" = ?, "name" = ? WHERE "active" = ? AND "balance" < ?', $compiled['sql']);
        self::assertSame([0, 'x', 1, 0], $compiled['bindings']);
    }

    public function test_a_set_based_delete_is_its_criteria(): void
    {
        $query = $this->query()->whereIn('id', [1, 2, 3]);

        $compiled = $this->grammar()->compileDeleteWhere('customers', $query->criteria());

        self::assertSame('DELETE FROM "customers" WHERE "id" IN (?, ?, ?)', $compiled['sql']);
        self::assertSame([1, 2, 3], $compiled['bindings']);
    }

    // ---- dialects ---------------------------------------------------------

    public function test_each_driver_gets_its_own_dialect(): void
    {
        self::assertInstanceOf(MySqlGrammar::class, Grammar::for('mysql'));
        self::assertInstanceOf(PostgresGrammar::class, Grammar::for('pgsql'));
        self::assertInstanceOf(SqliteGrammar::class, Grammar::for('sqlite'));
        self::assertInstanceOf(SqlServerGrammar::class, Grammar::for('sqlsrv'));

        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv'] as $driver) {
            self::assertSame($driver, Grammar::for($driver)->driver());
        }
    }

    /**
     * A driver nobody has written a dialect for gets standard SQL, and is
     * promised nothing beyond it.
     */
    public function test_an_unknown_driver_gets_standard_sql_and_no_capabilities(): void
    {
        $grammar = Grammar::for('firebird');

        self::assertSame(Grammar::class, $grammar::class);
        self::assertSame('', $grammar->driver());
        self::assertSame('"name"', $grammar->identifier('name'));
        self::assertSame(999, $grammar->maxBindings());
        self::assertStringEndsWith(' LIMIT 10', $grammar->compileSelect($this->query()->limit(10))['sql']);

        foreach (Capability::cases() as $capability) {
            self::assertFalse($grammar->supports($capability), $capability->value);
        }
    }

    public function test_each_dialect_states_its_placeholder_limit(): void
    {
        self::assertSame(65535, Grammar::for('mysql')->maxBindings());
        self::assertSame(65535, Grammar::for('pgsql')->maxBindings());
        self::assertSame(999, Grammar::for('sqlite')->maxBindings());
        self::assertSame(2000, Grammar::for('sqlsrv')->maxBindings());
    }

    /**
     * What each database really does, as a table.
     *
     * Every case of Capability is compared, so a new one fails here until its
     * answer has been decided -- and written down -- for every dialect.
     */
    public function test_capabilities_are_what_each_database_really_does(): void
    {
        $expected = [
            'mysql' => ['savepoints' => true, 'returning' => false, 'upsert' => true, 'right_join' => true, 'transactional_ddl' => false],
            'pgsql' => ['savepoints' => true, 'returning' => true, 'upsert' => true, 'right_join' => true, 'transactional_ddl' => true],
            'sqlite' => ['savepoints' => true, 'returning' => false, 'upsert' => true, 'right_join' => false, 'transactional_ddl' => true],
            'sqlsrv' => ['savepoints' => true, 'returning' => true, 'upsert' => false, 'right_join' => true, 'transactional_ddl' => true],
        ];

        foreach ($expected as $driver => $answers) {
            $actual = [];

            foreach (Capability::cases() as $capability) {
                $actual[$capability->value] = Grammar::for($driver)->supports($capability);
            }

            self::assertSame($answers, $actual, $driver);
        }
    }

    public function test_a_connection_answers_for_its_dialect(): void
    {
        self::assertTrue((new Connection(ConnectionConfig::of('x', 'sqlite::memory:')))->supports(Capability::Savepoints));
        self::assertFalse((new Connection(ConnectionConfig::of('x', 'oci:dbname=legacy')))->supports(Capability::Savepoints));
    }

    // ---- savepoints -------------------------------------------------------

    public function test_savepoints_are_written_in_each_dialect(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $grammar = $this->grammar($driver);

            self::assertTrue($grammar->supports(Capability::Savepoints), $driver);
            self::assertSame('SAVEPOINT sp_2', $grammar->compileSavepoint('sp_2'), $driver);
            self::assertSame('RELEASE SAVEPOINT sp_2', $grammar->compileReleaseSavepoint('sp_2'), $driver);
            self::assertSame('ROLLBACK TO SAVEPOINT sp_2', $grammar->compileRollbackToSavepoint('sp_2'), $driver);
        }
    }

    /** SQL Server names them differently and has nothing to release. Compiled only. */
    public function test_sql_server_saves_a_transaction_and_releases_nothing(): void
    {
        $sqlsrv = $this->grammar('sqlsrv');

        self::assertTrue($sqlsrv->supports(Capability::Savepoints));
        self::assertSame('SAVE TRANSACTION sp_2', $sqlsrv->compileSavepoint('sp_2'));
        self::assertNull($sqlsrv->compileReleaseSavepoint('sp_2'));
        self::assertSame('ROLLBACK TRANSACTION sp_2', $sqlsrv->compileRollbackToSavepoint('sp_2'));
    }

    /** Oracle has savepoints but no RELEASE; nothing is claimed that is not written. */
    public function test_a_driver_whose_savepoints_are_not_written_does_not_claim_them(): void
    {
        self::assertFalse($this->grammar('oci')->supports(Capability::Savepoints));
        self::assertFalse($this->grammar('')->supports(Capability::Savepoints));
    }

    public function test_a_savepoint_name_is_checked_like_any_identifier(): void
    {
        $this->expectException(DatabaseException::class);

        $this->grammar()->compileSavepoint('sp; DROP TABLE customers');
    }

    // ---- isolation and retry ----------------------------------------------

    /** Where each database needs the level set, and whether the session must be put back. */
    public function test_each_dialect_sets_the_isolation_level_where_its_database_needs_it(): void
    {
        $level = IsolationLevel::RepeatableRead;

        self::assertSame(
            ['before' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'after' => null, 'reset' => null],
            Grammar::for('mysql')->compileIsolation($level),
            'MySQL: before BEGIN, for the next transaction only',
        );
        self::assertSame(
            ['before' => null, 'after' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'reset' => null],
            Grammar::for('pgsql')->compileIsolation($level),
            'PostgreSQL: first inside the transaction, ending with it',
        );
        self::assertSame(
            [
                'before' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                'after' => null,
                'reset' => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
            ],
            Grammar::for('sqlsrv')->compileIsolation($level),
            'SQL Server: before BEGIN, for the whole session, so it is put back',
        );
        self::assertNull(Grammar::for('sqlsrv')->compileIsolation(IsolationLevel::ReadCommitted)['reset'], 'the default needs no reset');
        self::assertSame(
            ['before' => null, 'after' => null, 'reset' => null],
            Grammar::for('sqlite')->compileIsolation(IsolationLevel::Serializable),
            'SQLite: the only level there is',
        );
    }

    public function test_which_levels_each_database_can_give(): void
    {
        foreach (IsolationLevel::cases() as $level) {
            foreach (['mysql', 'pgsql', 'sqlsrv'] as $driver) {
                self::assertTrue(Grammar::for($driver)->supportsIsolation($level), $driver . ' ' . $level->value);
            }

            self::assertSame($level === IsolationLevel::Serializable, Grammar::for('sqlite')->supportsIsolation($level));
            self::assertFalse(Grammar::for('firebird')->supportsIsolation($level), 'standard SQL claims nothing');
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The sqlite database cannot run a transaction at READ UNCOMMITTED');

        Grammar::for('sqlite')->compileIsolation(IsolationLevel::ReadUncommitted);
    }

    /** @return array<string, array{string, string, int|null, bool}> */
    public static function failures(): array
    {
        return [
            'serialization failure' => ['pgsql', '40001', 7, true],
            'PostgreSQL deadlock' => ['pgsql', '40P01', 7, true],
            'SQL Server deadlock' => ['sqlsrv', '40001', 1205, true],
            'MySQL deadlock as 40001' => ['mysql', '40001', 1213, true],
            'MySQL deadlock as HY000' => ['mysql', 'HY000', 1213, true],
            'MySQL lock wait timeout' => ['mysql', 'HY000', 1205, false],
            'unique violation' => ['pgsql', '23505', 7, false],
            'syntax error' => ['sqlite', 'HY000', 1, false],
            'lost connection' => ['mysql', 'HY000', 2006, false],
            'an unknown driver' => ['firebird', '42000', null, false],
        ];
    }

    #[DataProvider('failures')]
    public function test_only_deadlocks_and_serialization_failures_are_retryable(string $driver, string $state, ?int $code, bool $retryable): void
    {
        $failure = new \PDOException('failure');
        $failure->errorInfo = [$state, $code, 'failure'];

        self::assertSame($retryable, Grammar::for($driver)->isRetryable($failure));
    }
}
