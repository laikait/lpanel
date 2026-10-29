<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelException;
use App\Engine\Model\Relation;
use App\Engine\Model\RelationManager;
use App\Engine\Model\RelationType;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\Invoice;
use App\Tests\Fixtures\Model\User;
use App\Tests\Support\TestCase;

final class RelationManagerTest extends TestCase
{
    private RelationManager $relations;

    protected function setUp(): void
    {
        $this->relations = new RelationManager();
        $this->relations->declare(
            Customer::class,
            Relation::many('invoices', Invoice::class, localKey: 'id', foreignKey: 'customerId'),
            Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id'),
        );
    }

    private function customers(): ModelCollection
    {
        return ModelCollection::of(Customer::class, [
            new Customer(1, 'Ada', 'ada@example.test', ownerId: 10),
            new Customer(2, 'Grace', 'grace@example.test', ownerId: 10),
            new Customer(3, 'Katherine', 'katherine@example.test', ownerId: null),
        ]);
    }

    // ---- declarations -----------------------------------------------------

    public function test_a_relation_names_both_sides_of_the_link(): void
    {
        $invoices = Relation::many('invoices', Invoice::class, localKey: 'id', foreignKey: 'customerId');

        self::assertSame('invoices', $invoices->name);
        self::assertSame(RelationType::Many, $invoices->type);
        self::assertSame(Invoice::class, $invoices->related);
        self::assertSame('id', $invoices->localKey);
        self::assertSame('customerId', $invoices->foreignKey);
        self::assertTrue($invoices->isMany());

        self::assertFalse(Relation::one('owner', User::class, 'ownerId', 'id')->isMany());
    }

    public function test_a_relation_can_describe_itself(): void
    {
        self::assertSame(
            [
                'name' => 'owner',
                'type' => 'one',
                'related' => User::class,
                'localKey' => 'ownerId',
                'foreignKey' => 'id',
            ],
            Relation::one('owner', User::class, 'ownerId', 'id')->describe(),
        );
    }

    /**
     * Module files are not statically analysed, so this guard is what actually
     * catches a mistyped class name there. The test calls it the same way.
     */
    public function test_relating_to_something_that_is_not_a_model_is_refused(): void
    {
        $this->expectException(ModelException::class);

        (new \ReflectionMethod(Relation::class, 'one'))->invoke(null, 'nope', \stdClass::class, 'id', 'id');
    }

    public function test_declarations_are_stored_per_model(): void
    {
        self::assertSame(['invoices', 'owner'], \array_keys($this->relations->for(Customer::class)));
        self::assertSame([], $this->relations->for(User::class));

        self::assertTrue($this->relations->has(Customer::class, 'owner'));
        self::assertFalse($this->relations->has(Customer::class, 'nope'));
        self::assertFalse($this->relations->has(User::class, 'owner'));
    }

    public function test_redeclaring_a_relation_replaces_it(): void
    {
        $this->relations->declare(Customer::class, Relation::one('invoices', Invoice::class, 'id', 'customerId'));

        self::assertCount(2, $this->relations->for(Customer::class));
        self::assertFalse($this->relations->get(Customer::class, 'invoices')->isMany());
    }

    public function test_an_undeclared_relation_names_the_ones_that_exist(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/No relation "nope" is declared/');
        $this->expectExceptionMessageMatches('/Declared: invoices, owner/');

        $this->relations->get(Customer::class, 'nope');
    }

    // ---- the batch primitive ----------------------------------------------

    /**
     * These are the values a single "WHERE customer_id IN (...)" is built from.
     * Getting them without touching the database is what makes one query
     * possible where an ORM would run one per parent.
     */
    public function test_keys_for_a_relation_are_distinct_and_skip_nulls(): void
    {
        self::assertSame([1, 2, 3], $this->relations->keysFor($this->customers(), 'invoices'));
        self::assertSame([10], $this->relations->keysFor($this->customers(), 'owner'));
    }

    public function test_keys_for_an_empty_collection_are_empty(): void
    {
        self::assertSame([], $this->relations->keysFor(ModelCollection::empty(Customer::class), 'invoices'));
    }

    // ---- linking ----------------------------------------------------------

    public function test_linking_a_to_many_relation_groups_children_under_their_parent(): void
    {
        $customers = $this->customers();
        $invoices = ModelCollection::of(Invoice::class, [
            new Invoice(100, 1, 25.0),
            new Invoice(101, 1, 30.0),
            new Invoice(102, 2, 40.0),
        ]);

        $this->relations->link($customers, 'invoices', $invoices);

        $first = $customers->find(1)?->related('invoices');
        self::assertInstanceOf(ModelCollection::class, $first);
        self::assertSame([100, 101], $first->identities());

        $second = $customers->find(2)?->related('invoices');
        self::assertInstanceOf(ModelCollection::class, $second);
        self::assertSame([102], $second->identities());
    }

    /**
     * Every parent is attached to, including the ones with nothing to attach.
     * Otherwise "loaded, empty" is indistinguishable from "never loaded" and
     * the loud error this design depends on becomes a false alarm.
     */
    public function test_a_parent_with_no_children_gets_an_empty_collection_not_a_missing_relation(): void
    {
        $customers = $this->customers();
        $this->relations->link($customers, 'invoices', ModelCollection::of(Invoice::class, [new Invoice(100, 1, 25.0)]));

        $third = $customers->find(3);
        self::assertNotNull($third);
        self::assertTrue($third->hasRelated('invoices'));

        $invoices = $third->related('invoices');
        self::assertInstanceOf(ModelCollection::class, $invoices);
        self::assertTrue($invoices->isEmpty());
    }

    public function test_linking_a_to_one_relation_attaches_a_single_model(): void
    {
        $customers = $this->customers();
        $owners = ModelCollection::of(User::class, [new User(10, 'ada')]);

        $this->relations->link($customers, 'owner', $owners);

        $first = $customers->find(1)?->related('owner');
        $second = $customers->find(2)?->related('owner');

        self::assertInstanceOf(User::class, $first);
        self::assertSame('ada', $first->username());
        self::assertSame($first, $second, 'two parents sharing an owner share the object');
    }

    public function test_a_to_one_relation_with_no_match_is_attached_as_null(): void
    {
        $customers = $this->customers();
        $this->relations->link($customers, 'owner', ModelCollection::of(User::class, [new User(10, 'ada')]));

        $third = $customers->find(3);
        self::assertNotNull($third);
        self::assertTrue($third->hasRelated('owner'));
        self::assertNull($third->related('owner'));
    }

    public function test_a_repository_can_record_an_empty_result_without_a_query(): void
    {
        $customers = $this->customers();
        $this->relations->linkEmpty($customers, 'invoices');

        foreach ($customers as $customer) {
            self::assertTrue($customer->hasRelated('invoices'));
            $invoices = $customer->related('invoices');
            self::assertInstanceOf(ModelCollection::class, $invoices);
            self::assertTrue($invoices->isEmpty());
        }
    }

    public function test_linking_the_wrong_kind_of_child_is_refused(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/relation holds/');

        $this->relations->link($this->customers(), 'invoices', ModelCollection::of(User::class, [new User(10, 'ada')]));
    }

    public function test_linking_an_undeclared_relation_is_refused(): void
    {
        $this->expectException(ModelException::class);

        $this->relations->link($this->customers(), 'nope', ModelCollection::empty(Invoice::class));
    }

    public function test_relinking_replaces_what_was_attached(): void
    {
        $customers = $this->customers();

        $this->relations->link($customers, 'invoices', ModelCollection::of(Invoice::class, [new Invoice(100, 1, 25.0)]));
        $this->relations->link($customers, 'invoices', ModelCollection::of(Invoice::class, [new Invoice(200, 1, 99.0)]));

        $invoices = $customers->find(1)?->related('invoices');
        self::assertInstanceOf(ModelCollection::class, $invoices);
        self::assertSame([200], $invoices->identities());
    }

    /**
     * The whole reason this class exists. A relation that fetched itself when
     * touched would issue one query per parent here; linking issues none.
     */
    public function test_linking_never_loads_anything(): void
    {
        $manager = new RelationManager();

        foreach (\get_class_methods($manager) as $method) {
            self::assertStringStartsNotWith('load', $method);
            self::assertStringStartsNotWith('fetch', $method);
        }

        self::assertStringNotContainsString(
            'App\\Engine\\Data',
            (string) \file_get_contents($this->basePath('engine/Model/RelationManager.php')),
        );
    }
}
