<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Engine\Model\Model;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelException;
use App\Tests\Fixtures\Model\Country;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;
use App\Tests\Fixtures\Model\Draft;
use App\Tests\Fixtures\Model\User;
use App\Tests\Support\TestCase;

final class ModelCollectionTest extends TestCase
{
    /** @return list<Customer> */
    private function customers(): array
    {
        return [
            new Customer(1, 'Ada Lovelace', 'ada@example.test', ownerId: 10),
            new Customer(2, 'Grace Hopper', 'grace@example.test', ownerId: 10),
            new Customer(3, 'Katherine Johnson', 'katherine@example.test', ownerId: 11),
        ];
    }

    private function collection(): ModelCollection
    {
        return ModelCollection::of(Customer::class, $this->customers());
    }

    // ---- construction and typing ------------------------------------------

    public function test_it_holds_models_in_order(): void
    {
        $collection = $this->collection();

        self::assertCount(3, $collection);
        self::assertFalse($collection->isEmpty());
        self::assertSame(Customer::class, $collection->type());
        self::assertSame(1, $collection->first()?->identity());
        self::assertSame(3, $collection->last()?->identity());
    }

    public function test_an_empty_collection_still_knows_its_type(): void
    {
        $collection = ModelCollection::empty(Customer::class);

        self::assertTrue($collection->isEmpty());
        self::assertCount(0, $collection);
        self::assertNull($collection->first());
        self::assertNull($collection->last());
        self::assertSame(Customer::class, $collection->type());
    }

    /**
     * Single-typed by construction. Every batch primitive below assumes it, so
     * the check happens once here instead of at each call site.
     */
    public function test_a_foreign_model_is_rejected_at_construction(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/cannot hold/');

        ModelCollection::of(Customer::class, [new Customer(1, 'Ada', 'a@example.test'), new User(1, 'ada')]);
    }

    /**
     * The guard is for callers the type system does not check -- a module.php
     * naming a class in configuration, say -- so the test calls it the same
     * way, dynamically, rather than pretending stdClass is a model.
     */
    public function test_a_class_that_is_not_a_model_is_rejected(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/is not a/');

        (new \ReflectionMethod(ModelCollection::class, 'of'))->invoke(null, \stdClass::class, []);
    }

    public function test_it_is_iterable(): void
    {
        $names = [];

        foreach ($this->collection() as $customer) {
            self::assertInstanceOf(Customer::class, $customer);
            $names[] = $customer->name();
        }

        self::assertSame(['Ada Lovelace', 'Grace Hopper', 'Katherine Johnson'], $names);
    }

    // ---- batch primitives -------------------------------------------------

    /**
     * This is the value a batch load is built from: one query with an IN clause
     * instead of one query per model.
     */
    public function test_identities_are_distinct_and_skip_models_without_one(): void
    {
        $collection = ModelCollection::of(Customer::class, [
            new Customer(1, 'Ada', 'a@example.test'),
            new Customer(1, 'Ada again', 'a@example.test'),
            new Customer(null, 'Unsaved', 'u@example.test'),
        ]);

        self::assertSame([1], $collection->identities());
    }

    public function test_identities_work_for_string_keys(): void
    {
        $collection = ModelCollection::of(Country::class, [new Country('GB', 'United Kingdom'), new Country('SE', 'Sweden')]);

        self::assertSame(['GB', 'SE'], $collection->identities());
    }

    public function test_it_can_be_indexed_by_identity(): void
    {
        $keyed = $this->collection()->keyByIdentity();

        self::assertSame([1, 2, 3], \array_keys($keyed));
        self::assertSame('Grace Hopper', $keyed[2]->attribute('name'));
    }

    public function test_find_returns_the_model_with_that_identity(): void
    {
        self::assertSame('Katherine Johnson', $this->collection()->find(3)?->attribute('name'));
        self::assertNull($this->collection()->find(99));
    }

    public function test_pluck_takes_one_attribute_in_order_and_keeps_duplicates(): void
    {
        self::assertSame([10, 10, 11], $this->collection()->pluck('ownerId'));
    }

    public function test_plucking_an_undeclared_attribute_throws(): void
    {
        $this->expectException(ModelException::class);

        $this->collection()->pluck('nope');
    }

    public function test_chunk_splits_into_batches_of_at_most_the_given_size(): void
    {
        $chunks = $this->collection()->chunk(2);

        self::assertCount(2, $chunks);
        self::assertCount(2, $chunks[0]);
        self::assertCount(1, $chunks[1]);
        self::assertSame(Customer::class, $chunks[1]->type());
    }

    public function test_chunking_an_empty_collection_yields_nothing(): void
    {
        self::assertSame([], ModelCollection::empty(Customer::class)->chunk(10));
    }

    public function test_a_chunk_size_below_one_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->collection()->chunk(0);
    }

    // ---- transformation ---------------------------------------------------

    /**
     * map() returns a plain list rather than a collection, because a projection
     * usually stops being a model -- which is the entire point of read models.
     */
    public function test_map_projects_out_of_the_model_layer(): void
    {
        $records = $this->collection()->map(
            static fn(Model $customer): CustomerListRecord => CustomerListRecord::from(
                $customer instanceof Customer ? $customer : throw new \LogicException('wrong type'),
            ),
        );

        self::assertCount(3, $records);
        self::assertInstanceOf(CustomerListRecord::class, $records[0]);
    }

    public function test_filter_keeps_the_type_and_reindexes(): void
    {
        $filtered = $this->collection()->filter(
            static fn(Model $customer): bool => $customer->attribute('ownerId') === 10,
        );

        self::assertInstanceOf(ModelCollection::class, $filtered);
        self::assertSame(Customer::class, $filtered->type());
        self::assertSame([1, 2], $filtered->identities());
        self::assertSame([0, 1], \array_keys($filtered->all()));
    }

    public function test_filtering_does_not_touch_the_original(): void
    {
        $collection = $this->collection();
        $collection->filter(static fn(Model $model): bool => false);

        self::assertCount(3, $collection);
    }

    // ---- what a collection deliberately is not ----------------------------

    public function test_a_collection_of_models_is_not_serialisable(): void
    {
        self::assertFalse(
            (new \ReflectionClass(ModelCollection::class))->implementsInterface(\JsonSerializable::class),
            'A collection of domain models is not an API response.',
        );
    }

    public function test_models_without_identities_are_still_held(): void
    {
        $drafts = ModelCollection::of(Draft::class, [new Draft('one'), new Draft('two')]);

        self::assertCount(2, $drafts);
        self::assertSame([], $drafts->identities());
        self::assertSame([], $drafts->keyByIdentity());
    }
}
