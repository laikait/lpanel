<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Data\ArraySource;
use App\Engine\Data\BulkWrites;
use App\Engine\Data\DataException;
use App\Engine\Data\DataSource;
use App\Engine\Data\Operator;
use App\Engine\Data\Query;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\SqlSource;
use App\Engine\Model\ModelManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a bulk write means, asserted against every source that offers one.
 *
 * The sixth conformance suite. It exists for the same reason the data layer
 * does: a repository tested against ArraySource is supposed to behave the same
 * against a database, and bulk writes are exactly where two implementations
 * drift -- one counts matched rows and the other changed ones, one accepts a
 * limit and the other ignores it, one fills a missing column and the other
 * refuses. Every one of those is pinned here once.
 */
final class BulkWritesConformanceTest extends TestCase
{
    /** @var list<Connection> */
    private static array $connections = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$connections as $connection) {
            $connection->disconnect();
        }

        self::$connections = [];
    }

    /** @return array<string, array{\Closure(): (DataSource&BulkWrites)}> */
    public static function sources(): array
    {
        return [
            'memory' => [static fn(): ArraySource => new ArraySource(['customers' => []])],
            'sqlite' => [static function (): SqlSource {
                $connection = new Connection(ConnectionConfig::of('bulk', 'sqlite::memory:'));
                self::$connections[] = $connection;

                $connection->execute(
                    'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, '
                    . 'email TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, balance REAL NOT NULL DEFAULT 0)',
                );

                return new SqlSource($connection);
            }],
        ];
    }

    private function query(DataSource $source): Query
    {
        return Query::on($source, 'customers', new ModelManager());
    }

    /**
     * @param int $count
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(int $count, int $active = 1): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; ++$i) {
            $rows[] = ['name' => "Customer {$i}", 'email' => "c{$i}@example.test", 'active' => $i % 2 === 0 ? 0 : $active, 'balance' => (float) $i];
        }

        return $rows;
    }

    // ---- insertMany ---------------------------------------------------------

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_insert_many_stores_every_row_and_counts_them(\Closure $make): void
    {
        $source = $make();

        self::assertSame(10, $source->insertMany('customers', 'id', self::rows(10)));
        self::assertSame(10, $this->query($source)->count());

        $identities = $this->query($source)->orderBy('id')->column('id');
        self::assertCount(10, \array_unique($identities), 'every stored row has its own identity');
    }

    /**
     * 400 rows of four columns is 1,600 placeholders -- past SQLite's 999, so
     * the database source must split the statement and the count must survive.
     *
     * @param \Closure(): (DataSource&BulkWrites) $make
     */
    #[DataProvider('sources')]
    public function test_insert_many_stores_more_rows_than_one_statement_can_carry(\Closure $make): void
    {
        $source = $make();

        self::assertSame(400, $source->insertMany('customers', 'id', self::rows(400)));
        self::assertSame(400, $this->query($source)->count());
    }

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_inserting_no_rows_stores_nothing(\Closure $make): void
    {
        self::assertSame(0, $make()->insertMany('customers', 'id', []));
    }

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_rows_naming_different_columns_are_refused_before_anything_is_stored(\Closure $make): void
    {
        $source = $make();
        $rows = self::rows(3);
        unset($rows[2]['balance']);

        try {
            $source->insertMany('customers', 'id', $rows);
            self::fail('rows that disagree about their columns were accepted');
        } catch (DataException $e) {
            self::assertStringContainsString('Row 2', $e->getMessage());
        }

        self::assertSame(0, $this->query($source)->count());
    }

    /** Same columns in a different order is the same row. @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_column_order_within_a_row_does_not_matter(\Closure $make): void
    {
        $source = $make();

        $source->insertMany('customers', 'id', [
            ['name' => 'Ada', 'email' => 'ada@example.test', 'active' => 1, 'balance' => 1.0],
            ['balance' => 2.0, 'active' => 0, 'email' => 'grace@example.test', 'name' => 'Grace'],
        ]);

        self::assertSame(['Ada', 'Grace'], $this->query($source)->orderBy('name')->column('name'));
        self::assertEqualsWithDelta(2.0, $this->query($source)->whereIs('name', 'Grace')->value('balance'), 0.0001);
    }

    // ---- updateWhere --------------------------------------------------------

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_update_where_changes_exactly_the_matching_rows(\Closure $make): void
    {
        $source = $make();
        $source->insertMany('customers', 'id', self::rows(10));

        $changed = $source->updateWhere(
            $this->query($source)->where('balance', Operator::Gt, 6.0),
            ['active' => 0],
        );

        self::assertSame(4, $changed);
        self::assertSame(0, $this->query($source)->where('balance', Operator::Gt, 6.0)->whereIs('active', 1)->count());
        self::assertSame(3, $this->query($source)->whereIs('active', 1)->count(), 'rows 1, 3 and 5 were untouched');
    }

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_update_where_accepts_a_list_criterion(\Closure $make): void
    {
        $source = $make();
        $source->insertMany('customers', 'id', self::rows(5));

        self::assertSame(2, $source->updateWhere(
            $this->query($source)->whereIn('email', ['c1@example.test', 'c4@example.test']),
            ['name' => 'Renamed'],
        ));

        self::assertSame(2, $this->query($source)->whereIs('name', 'Renamed')->count());
    }

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_updating_with_no_changes_changes_nothing(\Closure $make): void
    {
        $source = $make();
        $source->insertMany('customers', 'id', self::rows(3));

        self::assertSame(0, $source->updateWhere($this->query($source)->whereIs('active', 1), []));
    }

    // ---- deleteWhere --------------------------------------------------------

    /** @param \Closure(): (DataSource&BulkWrites) $make */
    #[DataProvider('sources')]
    public function test_delete_where_removes_exactly_the_matching_rows(\Closure $make): void
    {
        $source = $make();
        $source->insertMany('customers', 'id', self::rows(10));

        self::assertSame(5, $source->deleteWhere($this->query($source)->whereIs('active', 0)));
        self::assertSame(5, $this->query($source)->count());
        self::assertSame(0, $this->query($source)->whereIs('active', 0)->count());
    }

    // ---- what a bulk query may not carry -------------------------------------

    /** @return array<string, array{\Closure(): (DataSource&BulkWrites), \Closure(Query): Query, string}> */
    public static function refusedQueries(): array
    {
        $cases = [];

        $shapes = [
            'no criteria' => [static fn(Query $query): Query => $query, 'no criteria'],
            'an order' => [static fn(Query $query): Query => $query->whereIs('active', 1)->orderBy('name'), 'an order'],
            'a limit' => [static fn(Query $query): Query => $query->whereIs('active', 1)->limit(5), 'a limit'],
            'an offset' => [static fn(Query $query): Query => $query->whereIs('active', 1)->offset(2), 'an offset'],
            'columns' => [static fn(Query $query): Query => $query->whereIs('active', 1)->select('name'), 'a column list'],
        ];

        foreach (self::sources() as $sourceName => [$make]) {
            foreach ($shapes as $shapeName => [$shape, $message]) {
                $cases[$sourceName . ', ' . $shapeName] = [$make, $shape, $message];
            }
        }

        return $cases;
    }

    /**
     * Refused for both writes, and before the database sees anything: every row
     * is still there afterwards.
     *
     * @param \Closure(): (DataSource&BulkWrites) $make
     * @param \Closure(Query): Query              $shape
     */
    #[DataProvider('refusedQueries')]
    public function test_a_query_that_is_more_than_criteria_is_refused(\Closure $make, \Closure $shape, string $message): void
    {
        $source = $make();
        $source->insertMany('customers', 'id', self::rows(6));

        foreach ([
            'update' => static fn(): int => $source->updateWhere($shape(Query::on($source, 'customers', new ModelManager())), ['active' => 0]),
            'delete' => static fn(): int => $source->deleteWhere($shape(Query::on($source, 'customers', new ModelManager()))),
        ] as $operation => $write) {
            try {
                $write();
                self::fail(\sprintf('a bulk %s accepted a query with %s', $operation, $message));
            } catch (DataException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertSame(6, $this->query($source)->count());
        self::assertSame(3, $this->query($source)->whereIs('active', 1)->count());
    }
}
