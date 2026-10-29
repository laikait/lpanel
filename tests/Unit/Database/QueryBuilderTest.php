<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Grammar;
use App\Engine\Database\Query\Aggregate;
use App\Engine\Database\Query\JoinClause;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Database\Query\RawExpression;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The builder as SQL text, in every dialect, and as results on SQLite.
 *
 * DialectConformanceTest runs the same builder on each database server; this
 * file pins what is written and what is refused.
 */
final class QueryBuilderTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection(ConnectionConfig::of('test', 'sqlite::memory:'));
    }

    /** A builder on a connection that is never opened: compile() needs only the dialect. */
    private function on(string $driver, string $table = 'users'): QueryBuilder
    {
        return (new Connection(ConnectionConfig::of($driver, $driver . ':never-opened')))->table($table);
    }

    /** @return array{string, list<mixed>} */
    private function compiled(QueryBuilder $query): array
    {
        $compiled = $query->compile();

        return [$compiled['sql'], $compiled['bindings']];
    }

    private function seed(): void
    {
        $this->db->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, status TEXT, deleted_at TEXT)',
        );

        foreach ([
            [1, 'Ada', 36, 'active', null],
            [2, 'Grace', 45, 'pending', null],
            [3, 'Katherine', 52, 'active', '2026-01-01'],
            [4, 'Dorothy', 17, 'banned', null],
        ] as $row) {
            $this->db->execute('INSERT INTO users VALUES (?, ?, ?, ?, ?)', $row);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<mixed>
     */
    private static function names(array $rows): array
    {
        return \array_column($rows, 'name');
    }

    // ---- building runs nothing --------------------------------------------

    public function test_building_a_query_opens_no_connection(): void
    {
        $query = $this->db->table('users')->where('age', '>', 18)->orderBy('name')->limit(5);

        self::assertFalse($this->db->isConnected());

        $query->compile();
        self::assertFalse($this->db->isConnected(), 'compiling is not running');
    }

    /** A base query narrowed two ways stays two queries. */
    public function test_a_builder_is_immutable(): void
    {
        $base = $this->db->table('users')->where('status', 'active');

        $adults = $base->where('age', '>=', 18);
        $minors = $base->where('age', '<', 18);

        self::assertSame('SELECT * FROM "users" WHERE "status" = ?', $base->compile()['sql']);
        self::assertStringEndsWith('"age" >= ?', $adults->compile()['sql']);
        self::assertStringEndsWith('"age" < ?', $minors->compile()['sql']);
    }

    // ---- SQL text ---------------------------------------------------------

    public function test_a_plain_query_selects_everything(): void
    {
        self::assertSame(['SELECT * FROM "users"', []], $this->compiled($this->on('sqlite')));
    }

    public function test_conditions_bind_their_values_in_order(): void
    {
        [$sql, $bindings] = $this->compiled(
            $this->on('pgsql')->select('id', 'name')->where('active', true)->where('age', '>', 18)->orWhere('role', 'admin'),
        );

        self::assertSame('SELECT "id", "name" FROM "users" WHERE "active" = ? AND "age" > ? OR "role" = ?', $sql);
        self::assertSame([true, 18, 'admin'], $bindings);
    }

    public function test_a_group_is_written_in_parentheses(): void
    {
        [$sql, $bindings] = $this->compiled(
            $this->on('mysql')
                ->where('active', true)
                ->where(fn(QueryBuilder $q): QueryBuilder => $q->where('status', 'active')->orWhere('status', 'pending')),
        );

        self::assertSame('SELECT * FROM `users` WHERE `active` = ? AND (`status` = ? OR `status` = ?)', $sql);
        self::assertSame([true, 'active', 'pending'], $bindings);
    }

    public function test_groups_nest(): void
    {
        [$sql] = $this->compiled($this->on('sqlite')->where(
            fn(QueryBuilder $q): QueryBuilder => $q->where('a', 1)->orWhere(
                fn(QueryBuilder $q): QueryBuilder => $q->where('b', 2)->where('c', 3),
            ),
        ));

        self::assertSame('SELECT * FROM "users" WHERE ("a" = ? OR ("b" = ? AND "c" = ?))', $sql);
    }

    public function test_an_empty_group_adds_nothing(): void
    {
        self::assertSame(
            'SELECT * FROM "users"',
            $this->on('sqlite')->where(fn(QueryBuilder $q): QueryBuilder => $q)->compile()['sql'],
        );
    }

    public function test_null_in_and_between_conditions(): void
    {
        [$sql, $bindings] = $this->compiled(
            $this->on('sqlsrv')
                ->whereNull('deleted_at')
                ->whereNotNull('verified_at')
                ->whereIn('status', ['active', 'pending'])
                ->whereNotIn('role', ['guest'])
                ->whereBetween('age', 18, 65)
                ->whereNotBetween('score', 0, 10),
        );

        self::assertSame(
            'SELECT * FROM [users] WHERE [deleted_at] IS NULL AND [verified_at] IS NOT NULL'
            . ' AND [status] IN (?, ?) AND [role] NOT IN (?) AND [age] BETWEEN ? AND ? AND [score] NOT BETWEEN ? AND ?',
            $sql,
        );
        self::assertSame(['active', 'pending', 'guest', 18, 65, 0, 10], $bindings);
    }

    /** "IN ()" is a syntax error on some databases; an empty list means what it says. */
    public function test_an_empty_list_matches_nothing_and_excludes_nothing(): void
    {
        self::assertSame(['SELECT * FROM "users" WHERE 1 = 0', []], $this->compiled($this->on('sqlite')->whereIn('id', [])));
        self::assertSame(['SELECT * FROM "users" WHERE 1 = 1', []], $this->compiled($this->on('sqlite')->whereNotIn('id', [])));
    }

    public function test_operators_are_written_from_the_list(): void
    {
        foreach (['=', '!=', '<>', '<', '<=', '>', '>='] as $operator) {
            self::assertStringEndsWith(
                '"age" ' . $operator . ' ?',
                $this->on('sqlite')->where('age', $operator, 1)->compile()['sql'],
            );
        }

        self::assertStringEndsWith('"name" LIKE ?', $this->on('sqlite')->where('name', 'like', 'A%')->compile()['sql']);
        self::assertStringEndsWith('"name" NOT LIKE ?', $this->on('sqlite')->where('name', 'NOT LIKE', 'A%')->compile()['sql']);
    }

    public function test_order_and_slice_in_each_dialect(): void
    {
        $query = static fn(QueryBuilder $q): QueryBuilder => $q->orderBy('name')->orderByDesc('id')->limit(10)->offset(20);

        self::assertSame(
            'SELECT * FROM `users` ORDER BY `name` ASC, `id` DESC LIMIT 10 OFFSET 20',
            $query($this->on('mysql'))->compile()['sql'],
        );
        self::assertSame(
            'SELECT * FROM "users" ORDER BY "name" ASC, "id" DESC LIMIT 10 OFFSET 20',
            $query($this->on('pgsql'))->compile()['sql'],
        );
        self::assertSame(
            'SELECT * FROM [users] ORDER BY [name] ASC, [id] DESC OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY',
            $query($this->on('sqlsrv'))->compile()['sql'],
        );
        self::assertSame(
            'SELECT * FROM [users] ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY',
            $this->on('sqlsrv')->limit(5)->compile()['sql'],
        );
    }

    /** FOR UPDATE where the database has it, a table hint on SQL Server, nothing on SQLite. */
    public function test_a_row_lock_in_each_dialect(): void
    {
        $query = static fn(QueryBuilder $q): QueryBuilder => $q->where('id', 7)->lockForUpdate()->limit(1);

        self::assertSame('SELECT * FROM `users` WHERE `id` = ? LIMIT 1 FOR UPDATE', $query($this->on('mysql'))->compile()['sql']);
        self::assertSame('SELECT * FROM "users" WHERE "id" = ? LIMIT 1 FOR UPDATE', $query($this->on('pgsql'))->compile()['sql']);
        self::assertSame('SELECT * FROM "users" WHERE "id" = ? LIMIT 1', $query($this->on('sqlite'))->compile()['sql']);
        self::assertSame(
            'SELECT * FROM [users] WITH (UPDLOCK, ROWLOCK) WHERE [id] = ? ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY',
            $query($this->on('sqlsrv'))->compile()['sql'],
        );
    }

    /** A lock taken outside a transaction would end with its own statement. */
    public function test_a_row_lock_outside_a_transaction_is_refused(): void
    {
        $this->seed();

        $locked = $this->db->transaction(fn(Connection $db): ?array => $db->table('users')->where('id', 1)->lockForUpdate()->first());
        self::assertSame('Ada', $locked['name'] ?? null);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('outside a transaction');

        $this->db->table('users')->where('id', 1)->lockForUpdate()->first();
    }

    public function test_a_grouped_query_cannot_lock_rows(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('cannot lock rows');

        $this->on('pgsql')->groupBy('status')->lockForUpdate()->compile();
    }

    public function test_a_limit_can_be_removed(): void
    {
        self::assertSame('SELECT * FROM "users"', $this->on('sqlite')->limit(5)->limit(null)->compile()['sql']);
    }

    // ---- names with more than one part ------------------------------------

    public function test_qualified_names_and_aliases_are_quoted_part_by_part(): void
    {
        [$sql] = $this->compiled(
            $this->on('mysql', 'sales.orders AS o')
                ->select('o.*', 'o.total AS amount', 'customer_id as customer')
                ->where('o.status', 'open')
                ->orderBy('o.created_at', 'DESC'),
        );

        self::assertSame(
            'SELECT `o`.*, `o`.`total` AS `amount`, `customer_id` AS `customer` FROM `sales`.`orders` AS `o`'
            . ' WHERE `o`.`status` = ? ORDER BY `o`.`created_at` DESC',
            $sql,
        );
    }

    public function test_raw_expressions_carry_their_own_bindings_in_place(): void
    {
        [$sql, $bindings] = $this->compiled(
            $this->on('pgsql')
                ->select('id', new RawExpression('COALESCE(nickname, ?) AS shown', ['anonymous']))
                ->where('active', true)
                ->where(new RawExpression('LOWER(email) = ? OR LOWER(backup_email) = ?', ['a@b.c', 'a@b.c'])),
        );

        self::assertSame(
            'SELECT "id", COALESCE(nickname, ?) AS shown FROM "users" WHERE "active" = ?'
            . ' AND (LOWER(email) = ? OR LOWER(backup_email) = ?)',
            $sql,
        );
        self::assertSame(['anonymous', true, 'a@b.c', 'a@b.c'], $bindings);
    }

    // ---- refusals: nothing from a request becomes SQL ----------------------

    /** @return array<string, array{\Closure(QueryBuilder): QueryBuilder}> */
    public static function injections(): array
    {
        return [
            'column' => [static fn(QueryBuilder $q): QueryBuilder => $q->where('name; DROP TABLE users', 'x')],
            'order column' => [static fn(QueryBuilder $q): QueryBuilder => $q->orderBy('name, (SELECT password FROM admins)')],
            'selected column' => [static fn(QueryBuilder $q): QueryBuilder => $q->select('id, password')],
            'alias' => [static fn(QueryBuilder $q): QueryBuilder => $q->select('id AS "x"')],
            'quote in a part' => [static fn(QueryBuilder $q): QueryBuilder => $q->where('users.na"me', 1)],
            'four parts' => [static fn(QueryBuilder $q): QueryBuilder => $q->where('a.b.c.d', 1)],
            'null column' => [static fn(QueryBuilder $q): QueryBuilder => $q->whereNull('deleted_at OR 1=1')],
            'in column' => [static fn(QueryBuilder $q): QueryBuilder => $q->whereIn('id) OR (1', [1])],
        ];
    }

    /** @param \Closure(QueryBuilder): QueryBuilder $build */
    #[DataProvider('injections')]
    public function test_a_name_that_is_not_a_plain_name_is_refused(\Closure $build): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/is not a plain name/');

        $build($this->on('sqlite'))->compile();
    }

    public function test_a_table_name_is_checked_too(): void
    {
        $this->expectException(DatabaseException::class);

        $this->on('sqlite', 'users; DROP TABLE users')->compile();
    }

    public function test_an_operator_not_on_the_list_is_refused(): void
    {
        foreach (['= 1 OR 1 =', 'regexp', '||', 'IS'] as $operator) {
            try {
                $this->on('sqlite')->where('name', $operator, 'x');
                self::fail(\sprintf('the operator "%s" was accepted', $operator));
            } catch (DatabaseException $e) {
                self::assertStringContainsString('is not one a where() makes', $e->getMessage());
            }
        }
    }

    public function test_a_direction_that_is_not_asc_or_desc_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('An order is "asc" or "desc", not "desc, password".');

        $this->on('sqlite')->orderBy('name', 'desc, password');
    }

    /** "= NULL" is never true; the builder says so instead of matching nothing. */
    public function test_comparing_with_null_is_refused_in_favour_of_where_null(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Use whereNull("deleted_at")');

        $this->on('sqlite')->where('deleted_at', null);
    }

    public function test_a_group_that_returns_nothing_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('must return what it built');

        $this->on('sqlite')->where(static function (QueryBuilder $q): void {
            $q->where('a', 1);
        });
    }

    /** A value is a value, whatever it looks like. */
    public function test_a_value_that_looks_like_sql_is_only_ever_bound(): void
    {
        $this->seed();

        $attack = "' OR '1'='1";

        self::assertSame([], $this->db->table('users')->where('name', $attack)->get());
        self::assertStringNotContainsString($attack, $this->db->table('users')->where('name', $attack)->compile()['sql']);
    }

    // ---- running it -------------------------------------------------------

    public function test_get_returns_the_matching_rows(): void
    {
        $this->seed();

        $rows = $this->db->table('users')
            ->select('name')
            ->whereNull('deleted_at')
            ->where(fn(QueryBuilder $q): QueryBuilder => $q->where('status', 'active')->orWhere('age', '>', 40))
            ->orderBy('name')
            ->get();

        self::assertSame(['Ada', 'Grace'], self::names($rows));
    }

    public function test_first_returns_one_row_or_null(): void
    {
        $this->seed();

        self::assertSame(['name' => 'Katherine'], $this->db->table('users')->select('name')->orderByDesc('age')->first());
        self::assertNull($this->db->table('users')->where('age', '>', 100)->first());
    }

    public function test_a_cursor_streams_the_rows(): void
    {
        $this->seed();

        $cursor = $this->db->table('users')->select('name')->whereIn('id', [2, 4])->orderBy('id')->cursor();

        self::assertInstanceOf(\Generator::class, $cursor);
        self::assertSame(['Grace', 'Dorothy'], self::names(\iterator_to_array($cursor, false)));
    }

    public function test_count_ignores_order_and_slice(): void
    {
        $this->seed();

        self::assertSame(4, $this->db->table('users')->count());
        self::assertSame(2, $this->db->table('users')->where('status', 'active')->orderBy('name')->limit(1)->offset(1)->count());
    }

    public function test_exists_answers_without_reading_the_rows(): void
    {
        $this->seed();

        self::assertTrue($this->db->table('users')->whereBetween('age', 40, 50)->exists());
        self::assertFalse($this->db->table('users')->whereBetween('age', 60, 70)->exists());
        self::assertTrue($this->db->table('users')->limit(0)->offset(10)->exists(), 'the slice is not the question');
    }

    public function test_between_is_inclusive_and_its_negation_is_not(): void
    {
        $this->seed();

        self::assertSame(['Ada', 'Grace'], self::names($this->db->table('users')->whereBetween('age', 36, 45)->orderBy('id')->get()));
        self::assertSame(['Katherine', 'Dorothy'], self::names($this->db->table('users')->whereNotBetween('age', 36, 45)->orderBy('id')->get()));
    }

    // ---- joins, grouping and aggregates as SQL text ----------------------

    public function test_joins_are_written_with_their_conditions(): void
    {
        [$sql, $bindings] = $this->compiled(
            $this->on('pgsql', 'customers AS c')
                ->select('c.name', 'o.total')
                ->join('orders AS o', 'o.customer_id', '=', 'c.id')
                ->leftJoin('payments AS p', fn(JoinClause $j): JoinClause => $j
                    ->on('p.order_id', '=', 'o.id')
                    ->orOn('p.legacy_order_id', '=', 'o.id')
                    ->where('p.status', 'settled'))
                ->where('c.active', true),
        );

        self::assertSame(
            'SELECT "c"."name", "o"."total" FROM "customers" AS "c"'
            . ' INNER JOIN "orders" AS "o" ON "o"."customer_id" = "c"."id"'
            . ' LEFT JOIN "payments" AS "p" ON "p"."order_id" = "o"."id" OR "p"."legacy_order_id" = "o"."id" AND "p"."status" = ?'
            . ' WHERE "c"."active" = ?',
            $sql,
        );
        self::assertSame(['settled', true], $bindings, 'join values bind before the conditions');
    }

    public function test_a_right_join_is_written_where_the_database_has_one(): void
    {
        self::assertStringContainsString(
            ' RIGHT JOIN [orders] AS [o] ON [o].[customer_id] = [c].[id]',
            $this->on('sqlsrv', 'customers AS c')->rightJoin('orders AS o', 'o.customer_id', '=', 'c.id')->compile()['sql'],
        );
    }

    public function test_a_right_join_is_refused_where_it_is_not_counted_on(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The sqlite database cannot do "right_join"');

        $this->on('sqlite', 'customers AS c')->rightJoin('orders AS o', 'o.customer_id', '=', 'c.id')->compile();
    }

    public function test_a_join_that_returns_nothing_or_has_no_condition_is_refused(): void
    {
        try {
            $this->on('sqlite')->join('orders', static function (JoinClause $j): void {
                $j->on('orders.user_id', '=', 'users.id');
            });
            self::fail('a join closure returning nothing was accepted');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('must return what it built', $e->getMessage());
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('has no ON condition');

        $this->on('sqlite')->join('orders', fn(JoinClause $j): JoinClause => $j)->compile();
    }

    public function test_a_column_can_be_compared_with_another(): void
    {
        self::assertSame(
            ['SELECT * FROM `users` WHERE `updated_at` > `created_at`', []],
            $this->compiled($this->on('mysql')->whereColumn('updated_at', '>', 'created_at')),
        );
    }

    public function test_grouping_and_having_with_aggregates(): void
    {
        [$sql, $bindings] = $this->compiled(
            $this->on('sqlsrv', 'orders')
                ->select('status', Aggregate::count(as: 'orders'), Aggregate::sum('total', as: 'revenue'))
                ->where('year', 2026)
                ->groupBy('status')
                ->having(Aggregate::sum('total'), '>', 1000)
                ->orHaving(Aggregate::count(), '>=', 10)
                ->orderByDesc('revenue'),
        );

        self::assertSame(
            'SELECT [status], COUNT(*) AS [orders], SUM([total]) AS [revenue] FROM [orders]'
            . ' WHERE [year] = ? GROUP BY [status] HAVING SUM([total]) > ? OR COUNT(*) >= ?'
            . ' ORDER BY [revenue] DESC',
            $sql,
        );
        self::assertSame([2026, 1000, 10], $bindings);
    }

    /**
     * SQL Server averages an integer column in integers, 3 and 4 to 3, so its
     * AVG is taken over the column as a decimal -- wherever an average is
     * written: selected, in HAVING, or asked for by avg().
     */
    public function test_an_average_on_sql_server_keeps_its_fraction(): void
    {
        $orders = $this->on('sqlsrv', 'orders');

        self::assertSame(
            'SELECT [status], AVG([total] * 1.0) AS [average] FROM [orders] GROUP BY [status] HAVING AVG([total] * 1.0) > ?',
            $this->compiled($orders->select('status', Aggregate::avg('total', as: 'average'))->groupBy('status')->having(Aggregate::avg('total'), '>', 3))[0],
        );
        self::assertSame(
            'SELECT AVG([total] * 1.0) FROM [orders]',
            Grammar::for('sqlsrv')->compileQueryAggregate($orders->state(), Aggregate::avg('total'))['sql'],
        );
        self::assertSame('SELECT AVG(`total`) FROM `orders`', $this->compiled($this->on('mysql', 'orders')->select(Aggregate::avg('total')))[0]);
    }

    public function test_a_grouped_count_counts_the_groups(): void
    {
        $compiled = Grammar::for('sqlsrv')->compileQueryCount(
            $this->on('sqlsrv', 'orders')->where('year', 2026)->groupBy('status')->orderBy('status')->limit(5)->state(),
        );

        self::assertSame(
            'SELECT COUNT(*) FROM (SELECT 1 AS [grouped_row] FROM [orders] WHERE [year] = ? GROUP BY [status]) AS [grouped_rows]',
            $compiled['sql'],
        );
        self::assertSame([2026], $compiled['bindings']);
    }

    /** @return array<string, array{\Closure(): mixed, string}> */
    public static function badAggregates(): array
    {
        return [
            'sum of everything' => [static fn(): Aggregate => Aggregate::sum('*'), 'SUM(*) means nothing'],
            'column injection' => [static fn(): mixed => Grammar::for('sqlite')->compileQuery(
                (new Connection(ConnectionConfig::of('x', 'sqlite::memory:')))->table('t')->select(Aggregate::max('a) FROM secrets --'))->state(),
            ), 'is not a plain name'],
            'having operator' => [static fn(): mixed => (new Connection(ConnectionConfig::of('x', 'sqlite::memory:')))
                ->table('t')->groupBy('a')->having(Aggregate::count(), '> 0 OR 1 =', 1), 'is not one a where() makes'],
        ];
    }

    /**
     * The five functions are the only constructors, so a function name is never
     * free text; what remains to check is the column and the comparison.
     *
     * @param \Closure(): mixed $build
     */
    #[DataProvider('badAggregates')]
    public function test_an_aggregate_carries_nothing_but_what_it_says(\Closure $build, string $message): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    // ---- joins, grouping and aggregates on a database --------------------

    private function seedOrders(): void
    {
        $this->seed();
        $this->db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total REAL, status TEXT)');
        $this->db->table('orders')->insertMany([
            ['id' => 1, 'user_id' => 1, 'total' => 100.0, 'status' => 'paid'],
            ['id' => 2, 'user_id' => 1, 'total' => 250.5, 'status' => 'paid'],
            ['id' => 3, 'user_id' => 2, 'total' => 40.0, 'status' => 'open'],
            ['id' => 4, 'user_id' => 9, 'total' => 5.0, 'status' => 'open'],
        ]);
    }

    public function test_an_inner_join_keeps_only_matches_and_a_left_join_keeps_the_left(): void
    {
        $this->seedOrders();

        self::assertSame(
            [['name' => 'Ada', 'total' => 100.0], ['name' => 'Ada', 'total' => 250.5], ['name' => 'Grace', 'total' => 40.0]],
            $this->db->table('users AS u')->select('u.name', 'o.total')
                ->join('orders AS o', 'o.user_id', '=', 'u.id')->orderBy('o.id')->get(),
        );

        self::assertSame(
            ['Ada', 'Ada', 'Grace', 'Katherine', 'Dorothy'],
            self::names($this->db->table('users AS u')->select('u.name')
                ->leftJoin('orders AS o', 'o.user_id', '=', 'u.id')->orderBy('u.id')->orderBy('o.id')->get()),
        );

        self::assertSame(
            ['Katherine', 'Dorothy'],
            self::names($this->db->table('users AS u')->select('u.name')
                ->leftJoin('orders AS o', 'o.user_id', '=', 'u.id')->whereNull('o.id')->orderBy('u.id')->get()),
            'the users with no orders',
        );
    }

    public function test_groups_their_aggregates_and_their_count(): void
    {
        $this->seedOrders();

        $query = $this->db->table('orders')
            ->select('status', Aggregate::count(as: 'orders'), Aggregate::sum('total', as: 'revenue'))
            ->groupBy('status')
            ->orderBy('status');

        self::assertSame(
            [['status' => 'open', 'orders' => 2, 'revenue' => 45.0], ['status' => 'paid', 'orders' => 2, 'revenue' => 350.5]],
            $query->get(),
        );
        self::assertSame(2, $query->count(), 'a grouped count counts the groups');
        self::assertSame(
            [['status' => 'paid', 'orders' => 2, 'revenue' => 350.5]],
            $query->having(Aggregate::sum('total'), '>', 100)->get(),
        );
        self::assertTrue($query->having(Aggregate::count(), '>=', 2)->exists());
        self::assertFalse($query->having(Aggregate::count(), '>', 2)->exists());
    }

    public function test_sum_avg_min_and_max_over_the_matching_rows(): void
    {
        $this->seedOrders();

        self::assertSame(395.5, $this->db->table('orders')->sum('total'));
        self::assertSame(350.5, $this->db->table('orders')->where('status', 'paid')->sum('total'));
        self::assertSame(98.875, $this->db->table('orders')->avg('total'));
        self::assertSame(5.0, $this->db->table('orders')->min('total'));
        self::assertSame('paid', $this->db->table('orders')->max('status'));
        self::assertNull($this->db->table('orders')->where('status', 'refunded')->sum('total'), 'no rows is null, not zero');
    }

    public function test_an_aggregate_of_a_grouped_query_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('is grouped, so sum() would be one value per group');

        $this->db->table('orders')->groupBy('status')->sum('total');
    }

    public function test_a_write_with_a_join_or_a_grouping_is_refused(): void
    {
        $this->seedOrders();

        try {
            $this->db->table('users')->join('orders', 'orders.user_id', '=', 'users.id')->where('users.id', 1)->delete();
            self::fail('a joined delete ran');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('was given a join', $e->getMessage());
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('was given a grouping');

        $this->db->table('orders')->groupBy('status')->where('id', 1)->update(['total' => 0]);
    }

    // ---- writes as SQL text -----------------------------------------------

    public function test_an_insert_in_each_dialect_hands_back_its_key_where_it_can(): void
    {
        $row = ['name' => 'Ada', 'age' => 36];

        self::assertSame(
            ['sql' => 'INSERT INTO "users" ("name", "age") VALUES (?, ?) RETURNING "id"', 'bindings' => ['Ada', 36]],
            Grammar::for('pgsql')->compileQueryInsert($this->on('pgsql')->state(), $row, 'id'),
        );
        self::assertSame(
            'INSERT INTO [users] ([name], [age]) OUTPUT INSERTED.[id] VALUES (?, ?)',
            Grammar::for('sqlsrv')->compileQueryInsert($this->on('sqlsrv')->state(), $row, 'id')['sql'],
        );

        // MySQL and SQLite report the key per statement; nothing is added.
        self::assertSame(
            'INSERT INTO `users` (`name`, `age`) VALUES (?, ?)',
            Grammar::for('mysql')->compileQueryInsert($this->on('mysql')->state(), $row, 'id')['sql'],
        );
        self::assertSame(
            'INSERT INTO "users" ("name", "age") VALUES (?, ?)',
            Grammar::for('sqlite')->compileQueryInsert($this->on('sqlite')->state(), $row, 'id')['sql'],
        );
    }

    public function test_an_update_binds_its_values_before_its_conditions(): void
    {
        $compiled = Grammar::for('mysql')->compileQueryUpdate(
            $this->on('mysql')->where('id', 7)->orWhere('email', 'a@b.c')->state(),
            ['name' => 'Ada', 'stock' => new RawExpression('stock - ?', [2])],
        );

        self::assertSame('UPDATE `users` SET `name` = ?, `stock` = (stock - ?) WHERE `id` = ? OR `email` = ?', $compiled['sql']);
        self::assertSame(['Ada', 2, 7, 'a@b.c'], $compiled['bindings']);
    }

    public function test_a_delete_in_each_dialect(): void
    {
        self::assertSame(
            ['sql' => 'DELETE FROM [users] WHERE [id] IN (?, ?)', 'bindings' => [1, 2]],
            Grammar::for('sqlsrv')->compileQueryDelete($this->on('sqlsrv')->whereIn('id', [1, 2])->state()),
        );
    }

    public function test_an_upsert_in_each_dialect_that_has_one(): void
    {
        $rows = [['sku' => 'A', 'quantity' => 1, 'name' => 'Apple']];

        self::assertSame(
            'INSERT INTO "stock" ("sku", "quantity", "name") VALUES (?, ?, ?)'
            . ' ON CONFLICT ("sku") DO UPDATE SET "quantity" = EXCLUDED."quantity"',
            Grammar::for('pgsql')->compileUpsert($this->on('pgsql', 'stock')->state(), ['sku', 'quantity', 'name'], $rows, ['sku'], ['quantity'])[0]['sql'],
        );
        self::assertSame(
            'INSERT INTO "stock" ("sku", "quantity", "name") VALUES (?, ?, ?) ON CONFLICT ("sku") DO NOTHING',
            Grammar::for('sqlite')->compileUpsert($this->on('sqlite', 'stock')->state(), ['sku', 'quantity', 'name'], $rows, ['sku'], [])[0]['sql'],
        );
        self::assertSame(
            'INSERT INTO `stock` (`sku`, `quantity`, `name`) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `quantity` = VALUES(`quantity`), `name` = VALUES(`name`)',
            Grammar::for('mysql')->compileUpsert($this->on('mysql', 'stock')->state(), ['sku', 'quantity', 'name'], $rows, ['sku'], ['quantity', 'name'])[0]['sql'],
        );
        self::assertSame(
            'INSERT INTO `stock` (`sku`, `quantity`, `name`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `sku` = `sku`',
            Grammar::for('mysql')->compileUpsert($this->on('mysql', 'stock')->state(), ['sku', 'quantity', 'name'], $rows, ['sku'], [])[0]['sql'],
        );
    }

    /** SQL Server's MERGE is a different statement with different locking; it is not faked. */
    public function test_an_upsert_where_the_database_has_none_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The sqlsrv database cannot do "upsert"');

        Grammar::for('sqlsrv')->compileUpsert($this->on('sqlsrv', 'stock')->state(), ['sku'], [['sku' => 'A']], ['sku'], []);
    }

    // ---- writes: what is refused ------------------------------------------

    /** A forgotten where() is an exception, not a rewritten table. */
    public function test_an_update_or_delete_without_conditions_is_refused(): void
    {
        $this->seed();

        foreach ([
            'update' => fn(): int => $this->db->table('users')->update(['status' => 'x']),
            'delete' => fn(): int => $this->db->table('users')->delete(),
        ] as $operation => $write) {
            try {
                $write();
                self::fail(\sprintf('an unconditional %s ran', $operation));
            } catch (DatabaseException $e) {
                self::assertStringContainsString(\sprintf('call %sAll() if every row is really meant', $operation), $e->getMessage());
            }
        }

        self::assertSame(4, $this->db->table('users')->where('status', '!=', 'x')->count(), 'nothing was written');
    }

    public function test_every_row_can_be_written_when_it_is_asked_for_by_name(): void
    {
        $this->seed();

        self::assertSame(4, $this->db->table('users')->updateAll(['status' => 'archived']));
        self::assertSame(4, $this->db->table('users')->where('status', 'archived')->count());
        self::assertSame(4, $this->db->table('users')->deleteAll());
        self::assertSame(0, $this->db->table('users')->count());
    }

    /** @return array<string, array{\Closure(QueryBuilder): mixed, string}> */
    public static function writesCarryingReadClauses(): array
    {
        return [
            'update with a limit' => [static fn(QueryBuilder $q): int => $q->where('id', 1)->limit(1)->update(['a' => 1]), 'a limit'],
            'delete with an order' => [static fn(QueryBuilder $q): int => $q->where('id', 1)->orderBy('id')->delete(), 'an order'],
            'delete with an offset' => [static fn(QueryBuilder $q): int => $q->where('id', 1)->offset(2)->delete(), 'an offset'],
            'update with columns' => [static fn(QueryBuilder $q): int => $q->select('id')->where('id', 1)->update(['a' => 1]), 'a column list'],
            'insert with conditions' => [static fn(QueryBuilder $q): mixed => $q->where('id', 1)->insert(['a' => 1]), 'conditions'],
            'upsert with a limit' => [static function (QueryBuilder $q): bool {
                $q->limit(1)->upsert([['a' => 1]], ['a']);

                return true;
            }, 'a limit'],
        ];
    }

    /**
     * An UPDATE cannot honour LIMIT on most databases, so a limit is refused
     * rather than silently ignored -- which would write more rows than asked.
     *
     * @param \Closure(QueryBuilder): mixed $write
     */
    #[DataProvider('writesCarryingReadClauses')]
    public function test_a_write_carrying_a_read_clause_is_refused(\Closure $write, string $what): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('was given ' . $what);

        $write($this->db->table('users'));
    }

    public function test_a_write_to_an_aliased_table_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('a write cannot use one');

        $this->db->table('users AS u')->where('u.id', 1)->delete();
    }

    public function test_rows_of_a_many_row_write_must_name_the_same_columns(): void
    {
        $this->seed();

        try {
            $this->db->table('users')->insertMany([
                ['id' => 10, 'name' => 'A'],
                ['name' => 'B', 'id' => 11],
                ['id' => 12, 'name' => 'C', 'age' => 1],
            ]);
            self::fail('rows naming different columns were written');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('Row 2 written to "users" names different columns', $e->getMessage());
        }

        self::assertSame(4, $this->db->table('users')->count(), 'nothing was written');
    }

    /** The shape a decoded request or CSV line arrives in, which no type declaration stops. */
    public function test_a_row_without_column_names_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('is a list rather than column => value pairs');

        $this->db->table('users')->insert(['Ada', 36]);
    }

    public function test_a_raw_expression_in_a_many_row_write_is_refused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('RawExpression was given as a value in a many-row write');

        $this->db->table('users')->insertMany([['name' => new RawExpression('UPPER(?)', ['a'])]]);
    }

    public function test_an_upsert_needs_its_unique_columns(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('was given no unique columns');

        $this->db->table('users')->upsert([['id' => 1]], []);
    }

    public function test_a_write_names_its_columns_as_plain_names(): void
    {
        $this->seed();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/is not a plain name/');

        $this->db->table('users')->where('id', 1)->update(['name = name, age' => 1]);
    }

    // ---- writes on a database ---------------------------------------------

    public function test_rows_are_inserted_and_their_keys_returned_when_asked(): void
    {
        $this->seed();

        self::assertSame(5, $this->db->table('users')->insert(['name' => 'Mary', 'age' => 30], 'id'));
        self::assertNull($this->db->table('users')->insert(['name' => 'Annie', 'age' => 31]), 'no key asked for');
        self::assertSame(3, $this->db->table('users')->insertMany([
            ['name' => 'X', 'age' => 1],
            ['age' => 2, 'name' => 'Y'],
            ['name' => 'Z', 'age' => 3],
        ]));
        self::assertSame(0, $this->db->table('users')->insertMany([]));
        self::assertSame(['X', 'Y', 'Z'], self::names($this->db->table('users')->select('name')->where('age', '<', 4)->orderBy('age')->get()));
    }

    public function test_update_and_delete_say_how_many_rows_they_touched(): void
    {
        $this->seed();

        self::assertSame(2, $this->db->table('users')->where('status', 'active')->update(['status' => 'current']));
        self::assertSame(0, $this->db->table('users')->where('id', 99)->update(['status' => 'x']));
        self::assertSame(0, $this->db->table('users')->where('id', 1)->update([]), 'no changes run nothing');
        self::assertSame(1, $this->db->table('users')->where('age', '<', 18)->delete());
        self::assertSame(3, $this->db->table('users')->count());
    }

    public function test_an_update_value_may_be_an_expression(): void
    {
        $this->seed();

        $this->db->table('users')->where('id', 1)->update(['age' => new RawExpression('age + ?', [10])]);

        self::assertSame(['age' => 46], $this->db->table('users')->select('age')->where('id', 1)->first());
    }

    public function test_an_upsert_inserts_new_rows_and_updates_existing_ones(): void
    {
        $this->seed();

        $this->db->table('users')->upsert([
            ['id' => 1, 'name' => 'Ada Lovelace', 'age' => 37],
            ['id' => 9, 'name' => 'New', 'age' => 20],
        ], uniqueBy: ['id'], update: ['name']);

        self::assertSame(
            [['id' => 1, 'name' => 'Ada Lovelace', 'age' => 36], ['id' => 9, 'name' => 'New', 'age' => 20]],
            $this->db->table('users')->select('id', 'name', 'age')->whereIn('id', [1, 9])->orderBy('id')->get(),
            'only the named column was overwritten',
        );

        $this->db->table('users')->upsert([['id' => 1, 'name' => 'ignored', 'age' => 0]], uniqueBy: ['id'], update: []);
        self::assertSame(['name' => 'Ada Lovelace'], $this->db->table('users')->select('name')->where('id', 1)->first());
    }
}
