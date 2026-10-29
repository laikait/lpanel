<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Data\ArraySource;
use App\Engine\Data\Bulk;
use App\Engine\Data\Criterion;
use App\Engine\Data\Cursor;
use App\Engine\Data\CursorPage;
use App\Engine\Data\DataException;
use App\Engine\Data\DataSource;
use App\Engine\Data\Direction;
use App\Engine\Data\Operator;
use App\Engine\Data\Order;
use App\Engine\Data\Query;
use App\Engine\Data\Seek;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\SqlSource;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Keyset pagination and the keyset chunk, against memory and against SQLite.
 *
 * The same walks run on both sources, because a cursor that behaved in a test
 * and skipped rows against a database would be worse than no cursor.
 */
final class CursorPaginationTest extends TestCase
{
    private ModelManager $models;

    /**
     * Twelve customers. Names repeat, so ordering by name alone ties and the
     * key has to break it; balances repeat too.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        $names = ['Cy', 'Ada', 'Bo', 'Ada', 'Cy', 'Bo', 'Ada', 'Di', 'Bo', 'Cy', 'Ada', 'Di'];
        $rows = [];

        foreach ($names as $index => $name) {
            $id = $index + 1;
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'email' => \strtolower($name) . $id . '@example.test',
                'ownerId' => 10,
                'active' => 1,
                'balance' => (float) ($id % 4),
            ];
        }

        return $rows;
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        yield 'array' => ['array'];
        yield 'sqlite' => ['sqlite'];
    }

    private function source(string $kind): DataSource
    {
        $this->models = new ModelManager();

        if ($kind === 'array') {
            return new ArraySource(['customers' => self::rows()]);
        }

        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $connection = new Connection(ConnectionConfig::of('cursor', 'sqlite::memory:'));
        $connection->execute(
            'CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL,
             ownerId INTEGER NULL, active INTEGER NOT NULL, balance REAL NOT NULL)',
        );

        foreach (self::rows() as $row) {
            $connection->insert(
                'INSERT INTO customers (id, name, email, ownerId, active, balance) VALUES (?, ?, ?, ?, ?, ?)',
                \array_values($row),
            );
        }

        return new SqlSource($connection);
    }

    private function query(DataSource $source): Query
    {
        return Query::on($source, 'customers', $this->models, Customer::class);
    }

    /**
     * Follow nextCursor() to the end.
     *
     * @return list<list<int>> the ids on each page
     */
    private static function walk(Query $query, int $perPage): array
    {
        $pages = [];
        $cursor = null;

        do {
            $page = $query->cursor($perPage, $cursor);
            $pages[] = self::ids($page);
            $cursor = $page->nextCursor();
        } while ($cursor !== null && \count($pages) < 50);

        return $pages;
    }

    /** @return list<int> */
    private static function ids(CursorPage $page): array
    {
        return \array_map(static fn(Customer $customer): int => (int) $customer->identity(), $page->items());
    }

    /**
     * The expected order, computed independently of the code under test.
     *
     * @param \Closure(array<string, mixed>, array<string, mixed>): int $compare
     *
     * @return list<int>
     */
    private static function expected(\Closure $compare): array
    {
        $rows = self::rows();
        \usort($rows, $compare);

        return \array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    // ---- walking ----------------------------------------------------------

    #[DataProvider('sources')]
    public function test_the_key_follows_the_last_columns_direction_so_one_index_serves_the_walk(string $kind): void
    {
        $first = $this->query($this->source($kind))->orderByDesc('name')->cursor(3);

        // An index on (name, id) read backwards: name DESC, id DESC.
        self::assertSame([12, 8, 10], self::ids($first));
    }

    #[DataProvider('sources')]
    public function test_with_no_order_the_walk_is_by_key(string $kind): void
    {
        $pages = self::walk($this->query($this->source($kind)), 5);

        self::assertSame([[1, 2, 3, 4, 5], [6, 7, 8, 9, 10], [11, 12]], $pages);
    }

    #[DataProvider('sources')]
    public function test_ties_on_the_order_column_are_broken_by_the_key(string $kind): void
    {
        $pages = self::walk($this->query($this->source($kind))->orderBy('name'), 5);

        self::assertSame(
            self::expected(static fn(array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]),
            \array_merge(...$pages),
        );
    }

    #[DataProvider('sources')]
    public function test_a_descending_walk(string $kind): void
    {
        $pages = self::walk($this->query($this->source($kind))->orderByDesc('name'), 4);

        self::assertSame(
            // The key follows the last column's direction: name DESC, id DESC.
            self::expected(static fn(array $a, array $b): int => [$b['name'], $b['id']] <=> [$a['name'], $a['id']]),
            \array_merge(...$pages),
        );
    }

    #[DataProvider('sources')]
    public function test_a_mixed_direction_walk_visits_every_row_once(string $kind): void
    {
        $query = $this->query($this->source($kind))->orderBy('name')->orderByDesc('balance');
        $ids = \array_merge(...self::walk($query, 3));

        self::assertSame(
            // name ASC, balance DESC, then the key DESC after it.
            self::expected(static fn(array $a, array $b): int => [$a['name'], $b['balance'], $b['id']] <=> [$b['name'], $a['balance'], $a['id']]),
            $ids,
        );
        self::assertCount(12, \array_unique($ids));
    }

    #[DataProvider('sources')]
    public function test_the_last_page_has_no_next_and_the_first_no_previous(string $kind): void
    {
        $query = $this->query($this->source($kind));
        $first = $query->cursor(5);

        self::assertNull($first->previousCursor());
        self::assertTrue($first->isFirst());
        self::assertTrue($first->hasMore());

        $all = $query->cursor(50);

        self::assertCount(12, $all);
        self::assertNull($all->nextCursor());
        self::assertFalse($all->hasMore());
    }

    #[DataProvider('sources')]
    public function test_previous_returns_to_the_page_before(string $kind): void
    {
        $query = $this->query($this->source($kind))->orderBy('name');

        $first = $query->cursor(4);
        $second = $query->cursor(4, $first->nextCursor());
        $third = $query->cursor(4, $second->nextCursor());

        $backToSecond = $query->cursor(4, $third->previousCursor());
        $backToFirst = $query->cursor(4, $backToSecond->previousCursor());

        self::assertSame(self::ids($second), self::ids($backToSecond));
        self::assertSame(self::ids($first), self::ids($backToFirst));
        self::assertNull($backToFirst->previousCursor(), 'the first page again');
        self::assertSame(self::ids($third), self::ids($query->cursor(4, $backToSecond->nextCursor())));
    }

    #[DataProvider('sources')]
    public function test_criteria_still_apply(string $kind): void
    {
        $query = $this->query($this->source($kind))->whereIs('name', 'Ada');

        self::assertSame([[2, 4], [7, 11]], self::walk($query, 2));
    }

    #[DataProvider('sources')]
    public function test_read_models_page_the_same_way(string $kind): void
    {
        $query = $this->query($this->source($kind))->orderBy('name');
        $page = $query->cursorInto(CustomerListRecord::class, 3);
        $next = $query->cursorInto(CustomerListRecord::class, 3, $page->nextCursor());

        self::assertContainsOnlyInstancesOf(CustomerListRecord::class, $page->items());
        self::assertCount(3, $next);
    }

    // ---- no count, no offset ------------------------------------------------

    public function test_a_cursor_page_never_counts(): void
    {
        $source = new class (new ArraySource(['customers' => self::rows()])) implements DataSource {
            public int $counted = 0;

            public function __construct(private readonly ArraySource $inner) {}

            public function fetch(Query $query): iterable
            {
                return $this->inner->fetch($query);
            }

            public function count(Query $query): int
            {
                ++$this->counted;

                return $this->inner->count($query);
            }

            public function insert(string $collection, string $key, array $row): int|string|null
            {
                return $this->inner->insert($collection, $key, $row);
            }

            public function update(string $collection, string $key, int|string $identity, array $changes): int
            {
                return $this->inner->update($collection, $key, $identity, $changes);
            }

            public function delete(string $collection, string $key, int|string $identity): int
            {
                return $this->inner->delete($collection, $key, $identity);
            }
        };

        $this->models = new ModelManager();
        self::walk($this->query($source), 5);

        self::assertSame(0, $source->counted);
    }

    public function test_the_sql_seeks_rather_than_skips(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $connection = new Connection(ConnectionConfig::of('cursor', 'sqlite::memory:'));
        $seek = new Seek([new Order('name'), new Order('id', Direction::Desc)], ['Bo', 6]);
        $query = Query::on(new ArraySource(), 'customers', new ModelManager())
            ->where($seek->label(), Operator::Seek, $seek)
            ->orderBy('name')->orderByDesc('id')->limit(4);

        $compiled = (new \App\Engine\Database\SqlSource($connection))->grammar()->compileSelect($query);

        self::assertStringNotContainsString('OFFSET', $compiled['sql']);
        // A plain range first, so the planner seeks into the index; the OR
        // only settles ties on the boundary row.
        self::assertStringContainsString('("name" >= ? AND (("name" > ?) OR ("name" = ? AND "id" < ?)))', $compiled['sql']);
        self::assertSame(['Bo', 'Bo', 'Bo', 6], $compiled['bindings']);
    }

    // ---- cursors that cannot be used ----------------------------------------

    public function test_a_cursor_for_another_order_is_refused(): void
    {
        $this->models = new ModelManager();
        $query = $this->query(new ArraySource(['customers' => self::rows()]));
        $cursor = $query->orderBy('name')->cursor(3)->nextCursor();

        $this->expectException(DataException::class);
        $this->expectExceptionMessage('it was made for a different order');

        $query->orderByDesc('name')->cursor(3, $cursor);
    }

    public function test_a_tampered_cursor_is_refused(): void
    {
        $this->models = new ModelManager();
        $query = $this->query(new ArraySource(['customers' => self::rows()]));

        foreach (['not-a-cursor', \base64_encode('{"o":1}'), \str_repeat('a', 5000), \base64_encode('{"o":["id:asc"],"v":[{"x":1}],"b":false}')] as $bad) {
            try {
                $query->cursor(3, $bad);
                self::fail($bad . ' was accepted');
            } catch (DataException $e) {
                self::assertStringStartsWith('The cursor cannot be used', $e->getMessage());
            }
        }
    }

    public function test_a_cursor_round_trips_unicode_and_numbers(): void
    {
        $orders = [new Order('name'), new Order('balance', Direction::Desc), new Order('id')];
        $cursor = Cursor::at($orders, ['name' => 'রিয়াদ', 'balance' => 2.5, 'id' => 7], true);
        $decoded = Cursor::decode($cursor->encode(), $orders);

        self::assertSame(['রিয়াদ', 2.5, 7], $decoded->values);
        self::assertTrue($decoded->backward);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor->encode(), 'safe in a query string');
    }

    public function test_a_null_order_value_cannot_be_continued_from(): void
    {
        $this->expectException(DataException::class);
        $this->expectExceptionMessage('whose "ownerId" is null');

        new Seek([new Order('ownerId'), new Order('id')], [null, 3]);
    }

    public function test_a_page_size_below_one_is_refused(): void
    {
        $this->models = new ModelManager();

        $this->expectException(DataException::class);

        $this->query(new ArraySource(['customers' => self::rows()]))->cursor(0);
    }

    public function test_a_bulk_write_refuses_a_cursor_position(): void
    {
        $seek = new Seek([new Order('id')], [3]);
        $query = Query::on(new ArraySource(), 'customers', new ModelManager())->where($seek->label(), Operator::Seek, $seek);

        $this->expectException(DataException::class);
        $this->expectExceptionMessage('a cursor position');

        Bulk::criteria($query, 'update');
    }

    public function test_a_seek_criterion_needs_a_seek_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Criterion('id', Operator::Seek, 3);
    }

    // ---- chunk ---------------------------------------------------------------

    #[DataProvider('sources')]
    public function test_chunk_neither_skips_nor_repeats_while_rows_are_deleted(string $kind): void
    {
        $source = $this->source($kind);
        $seen = [];

        // The classic offset bug: deleting what was processed shifts the next
        // batch's offset past rows nobody saw.
        $this->query($source)->chunk(5, static function (ModelCollection $batch) use (&$seen, $source): void {
            foreach ($batch as $customer) {
                $seen[] = (int) $customer->identity();
                $source->delete('customers', 'id', (int) $customer->identity());
            }
        });

        self::assertSame(\range(1, 12), $seen);
    }

    #[DataProvider('sources')]
    public function test_chunk_follows_the_query_order(string $kind): void
    {
        $seen = [];

        $this->query($this->source($kind))->orderBy('name')->chunk(5, static function (ModelCollection $batch) use (&$seen): void {
            foreach ($batch as $customer) {
                $seen[] = (int) $customer->identity();
            }
        });

        self::assertSame(
            self::expected(static fn(array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]),
            $seen,
        );
    }
}
