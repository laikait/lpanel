<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Data\ArraySource;
use App\Engine\Data\DataException;
use App\Engine\Data\DataSource;
use App\Engine\Data\Direction;
use App\Engine\Data\Operator;
use App\Engine\Data\Page;
use App\Engine\Data\Query;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;
use App\Tests\Support\TestCase;

final class QueryTest extends TestCase
{
    private ArraySource $source;

    private ModelManager $models;

    protected function setUp(): void
    {
        $this->source = new ArraySource(['customers' => [
            ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test', 'ownerId' => 10, 'active' => true, 'balance' => 5.0],
            ['id' => 2, 'name' => 'Grace', 'email' => 'grace@example.test', 'ownerId' => 10, 'active' => false, 'balance' => 0.0],
            ['id' => 3, 'name' => 'Katherine', 'email' => 'kat@example.test', 'ownerId' => null, 'active' => true, 'balance' => 12.5],
        ]]);

        $this->models = new ModelManager();
    }

    private function query(): Query
    {
        return Query::on($this->source, 'customers', $this->models, Customer::class);
    }

    // ---- building ---------------------------------------------------------

    /** Nothing is read until a terminal method is called. */
    public function test_building_a_query_reads_nothing(): void
    {
        // Every method refuses to be called, so building anything at all is a
        // loud failure rather than a counter nobody reads.
        $source = new class implements DataSource {
            public function fetch(Query $query): iterable
            {
                throw new \LogicException('building a query must not read');
            }

            public function count(Query $query): int
            {
                throw new \LogicException('building a query must not count');
            }

            public function insert(string $collection, string $key, array $row): int|string|null
            {
                throw new \LogicException('building a query must not write');
            }

            public function update(string $collection, string $key, int|string $identity, array $changes): int
            {
                throw new \LogicException('building a query must not write');
            }

            public function delete(string $collection, string $key, int|string $identity): int
            {
                throw new \LogicException('building a query must not write');
            }
        };

        $query = Query::on($source, 'customers', $this->models)
            ->whereIs('active', true)
            ->orderBy('name')
            ->limit(10)
            ->offset(5)
            ->select('id');

        self::assertSame(['id'], $query->columns());
    }

    /**
     * A repository can hold a base query and hand out narrowed copies without
     * one caller changing what the next one sees.
     */
    public function test_every_builder_method_returns_a_new_query(): void
    {
        $base = $this->query();
        $narrowed = $base->whereIs('active', true)->orderBy('name')->limit(1);

        self::assertNotSame($base, $narrowed);
        self::assertSame([], $base->criteria());
        self::assertCount(1, $narrowed->criteria());
        self::assertNull($base->limitValue());
    }

    public function test_a_query_describes_what_it_would_read(): void
    {
        $described = $this->query()
            ->select('id', 'name')
            ->whereIs('active', true)
            ->where('balance', Operator::Gt, 0)
            ->orderByDesc('name')
            ->limit(10)
            ->offset(20)
            ->describe();

        self::assertSame('customers', $described['collection']);
        self::assertSame(['id', 'name'], $described['columns']);
        self::assertSame(10, $described['limit']);
        self::assertSame(20, $described['offset']);
        self::assertSame([['field' => 'name', 'direction' => 'desc']], $described['orders']);
        self::assertSame(
            [
                ['field' => 'active', 'operator' => '=', 'value' => true],
                ['field' => 'balance', 'operator' => '>', 'value' => 0],
            ],
            $described['criteria'],
        );
    }

    public function test_criteria_combine_with_and(): void
    {
        self::assertSame(
            [1],
            $this->query()->whereIs('active', true)->whereIs('ownerId', 10)->column('id'),
        );
    }

    public function test_the_shorthands_cover_the_common_comparisons(): void
    {
        self::assertSame([1, 3], $this->query()->whereIs('active', true)->column('id'));
        self::assertSame([2], $this->query()->whereNot('active', true)->column('id'));
        self::assertSame([1, 2], $this->query()->whereIn('id', [1, 2])->column('id'));
        self::assertSame([3], $this->query()->whereNotIn('id', [1, 2])->column('id'));
        self::assertSame([3], $this->query()->whereNull('ownerId')->column('id'));
        self::assertSame([1, 2], $this->query()->whereNotNull('ownerId')->column('id'));
        self::assertSame([3], $this->query()->whereLike('name', 'kath%')->column('id'));
    }

    public function test_a_negative_offset_is_clamped_rather_than_inverting_the_slice(): void
    {
        self::assertSame(0, $this->query()->offset(-5)->offsetValue());
    }

    // ---- raw reads --------------------------------------------------------

    public function test_rows_returns_what_the_source_produced(): void
    {
        $rows = $this->query()->whereIs('id', 1)->rows();

        self::assertCount(1, $rows);
        self::assertSame('Ada', $rows[0]['name']);
    }

    public function test_select_narrows_what_is_read(): void
    {
        $rows = $this->query()->select('id', 'name')->rows();

        self::assertSame(['id', 'name'], \array_keys($rows[0]));
    }

    public function test_first_row_is_the_first_match_and_null_when_there_is_none(): void
    {
        self::assertSame('Ada', $this->query()->orderBy('name')->firstRow()['name'] ?? null);
        self::assertNull($this->query()->whereIs('id', 99)->firstRow());
    }

    /** Reading a whole row to keep one field is what column() exists to avoid. */
    public function test_column_selects_only_that_column(): void
    {
        self::assertSame(['Ada', 'Grace', 'Katherine'], $this->query()->orderBy('name')->column('name'));
        self::assertSame([], $this->query()->whereIs('id', 99)->column('name'));
    }

    public function test_value_returns_one_field_of_one_row(): void
    {
        self::assertSame('Ada', $this->query()->whereIs('id', 1)->value('name'));
        self::assertNull($this->query()->whereIs('id', 99)->value('name'));
    }

    public function test_count_ignores_limit_and_offset_the_way_sql_does(): void
    {
        self::assertSame(3, $this->query()->count());
        self::assertSame(3, $this->query()->limit(1)->count());
        self::assertSame(2, $this->query()->whereIs('active', true)->count());
    }

    public function test_exists_answers_without_reading_rows(): void
    {
        self::assertTrue($this->query()->whereIs('email', 'ada@example.test')->exists());
        self::assertFalse($this->query()->whereIs('email', 'nobody@example.test')->exists());
    }

    // ---- ordering and limits ---------------------------------------------

    public function test_ordering_applies_in_the_order_declared(): void
    {
        // Inactive first, then the actives by name: the second clause only
        // decides the ties the first one left.
        self::assertSame([2, 1, 3], $this->query()->orderBy('active')->orderBy('name')->column('id'));
    }

    /**
     * Null takes no part in an ordering comparison, and sorts last ascending.
     * PHP would happily decide null < 10; SQL would call it unknown. Following
     * SQL here keeps the two sources from disagreeing.
     */
    public function test_nulls_sort_last_ascending(): void
    {
        self::assertSame([1, 2, 3], $this->query()->orderBy('ownerId')->orderBy('id')->column('id'));
        self::assertSame([3, 1, 2], $this->query()->orderByDesc('ownerId')->orderBy('id')->column('id'));
    }

    public function test_descending_order(): void
    {
        self::assertSame(['Katherine', 'Grace', 'Ada'], $this->query()->orderByDesc('name')->column('name'));
        self::assertSame(
            ['Katherine', 'Grace', 'Ada'],
            $this->query()->orderBy('name', Direction::Desc)->column('name'),
        );
    }

    public function test_limit_and_offset_slice_the_result(): void
    {
        self::assertSame(['Grace'], $this->query()->orderBy('name')->limit(1)->offset(1)->column('name'));
        self::assertSame(['Ada', 'Grace'], $this->query()->orderBy('name')->limit(2)->column('name'));
    }

    // ---- read models ------------------------------------------------------

    /**
     * Asking for a projection also narrows what is read, because the columns
     * come from the read model's own constructor.
     */
    public function test_into_builds_read_models_and_narrows_the_selection(): void
    {
        $records = $this->query()->orderBy('name')->into(CustomerListRecord::class);

        self::assertCount(3, $records);
        self::assertInstanceOf(CustomerListRecord::class, $records[0]);
        self::assertSame(['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test'], $records[0]->toArray());
    }

    public function test_an_explicit_selection_wins_over_the_read_models_columns(): void
    {
        // The caller knew something the read model does not, so the caller wins.
        $rows = $this->query()->select('id', 'name', 'email', 'balance')->rows();

        self::assertArrayHasKey('balance', $rows[0]);
    }

    public function test_first_into_returns_one_read_model_or_null(): void
    {
        self::assertSame('Ada', $this->query()->orderBy('name')->firstInto(CustomerListRecord::class)?->name);
        self::assertNull($this->query()->whereIs('id', 99)->firstInto(CustomerListRecord::class));
    }

    // ---- domain models ----------------------------------------------------

    public function test_get_hydrates_domain_models(): void
    {
        $customers = $this->query()->orderBy('name')->get();

        self::assertInstanceOf(ModelCollection::class, $customers);
        self::assertSame(Customer::class, $customers->type());
        self::assertSame([1, 2, 3], $customers->identities());
        self::assertFalse($customers->first()?->isDirty(), 'a model read from storage is clean');
    }

    public function test_first_hydrates_one_model(): void
    {
        self::assertSame('Ada', $this->query()->whereIs('id', 1)->first()?->attribute('name'));
        self::assertNull($this->query()->whereIs('id', 99)->first());
    }

    public function test_the_identity_map_holds_across_queries(): void
    {
        self::assertSame(
            $this->query()->whereIs('id', 1)->first(),
            $this->query()->whereIs('email', 'ada@example.test')->first(),
        );
    }

    public function test_streaming_yields_one_model_at_a_time(): void
    {
        $stream = $this->query()->orderBy('name')->stream();

        self::assertInstanceOf(\Generator::class, $stream);

        $names = [];

        foreach ($stream as $customer) {
            $names[] = $customer->attribute('name');
        }

        self::assertSame(['Ada', 'Grace', 'Katherine'], $names);
    }

    /**
     * A query with no model can still do everything that does not need one,
     * and says so clearly when asked for something that does.
     */
    public function test_a_query_without_a_model_reads_rows_but_refuses_to_hydrate(): void
    {
        $query = Query::on($this->source, 'customers', $this->models);

        self::assertCount(3, $query->rows());
        self::assertSame(3, $query->count());
        self::assertCount(3, $query->into(CustomerListRecord::class));

        $this->expectException(DataException::class);
        $this->expectExceptionMessageMatches('/was not given a model class/');

        $query->get();
    }

    // ---- batches ----------------------------------------------------------

    public function test_chunk_walks_the_whole_result_in_batches(): void
    {
        $batches = [];

        $this->query()->orderBy('name')->chunk(2, static function (ModelCollection $batch) use (&$batches): void {
            $batches[] = $batch->count();
        });

        self::assertSame([2, 1], $batches);
    }

    public function test_chunk_stops_when_the_callback_returns_false(): void
    {
        $seen = 0;

        $this->query()->orderBy('name')->chunk(1, static function (ModelCollection $batch) use (&$seen): bool {
            ++$seen;

            return false;
        });

        self::assertSame(1, $seen);
    }

    public function test_chunking_an_empty_result_never_calls_back(): void
    {
        $called = false;

        $this->query()->whereIs('id', 99)->chunk(10, static function (ModelCollection $batch) use (&$called): void {
            $called = true;
        });

        self::assertFalse($called);
    }

    public function test_a_chunk_size_below_one_is_refused(): void
    {
        $this->expectException(DataException::class);

        $this->query()->chunk(0, static fn(ModelCollection $batch): null => null);
    }

    // ---- pagination -------------------------------------------------------

    public function test_a_page_carries_the_totals_needed_to_show_a_pager(): void
    {
        $page = $this->query()->orderBy('name')->page(2, 2);

        self::assertInstanceOf(Page::class, $page);
        self::assertCount(1, $page);
        self::assertSame(['total' => 3, 'page' => 2, 'per_page' => 2, 'pages' => 2, 'count' => 1], $page->meta());
        self::assertFalse($page->hasMore());
        self::assertTrue($page->isLast());
    }

    public function test_a_page_beyond_the_end_is_empty_rather_than_an_error(): void
    {
        $page = $this->query()->page(9, 10);

        self::assertTrue($page->isEmpty());
        self::assertSame(3, $page->total);
    }

    /** The paginated form a list screen should reach for. */
    public function test_paging_into_read_models_never_builds_a_domain_object(): void
    {
        $page = $this->query()->orderBy('name')->pageInto(CustomerListRecord::class, 1, 2);

        self::assertCount(2, $page);
        self::assertInstanceOf(CustomerListRecord::class, $page->items[0]);
        self::assertSame(3, $page->total);
        self::assertTrue($page->hasMore());
    }

    public function test_a_page_below_one_is_refused(): void
    {
        $this->expectException(DataException::class);
        $this->expectExceptionMessageMatches('/must be at least 1/');

        $this->query()->page(0, 10);
    }

    // ---- what a query deliberately does not have --------------------------

    /**
     * No OR and no join. A general boolean tree is where a query builder turns
     * into a query language, and a join cannot be honoured by every kind of
     * source -- data from two places is loaded in two queries and linked.
     */
    public function test_there_is_no_or_and_no_join(): void
    {
        $methods = \array_map('strtolower', \get_class_methods(Query::class));

        foreach (['orwhere', 'join', 'leftjoin', 'innerjoin', 'union', 'having', 'groupby', 'raw'] as $absent) {
            self::assertNotContains($absent, $methods, \sprintf('Query grew %s().', $absent));
        }
    }
}
