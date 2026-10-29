<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Data\ArraySource;
use App\Engine\Data\DataException;
use App\Engine\Data\DataSource;
use App\Engine\Data\Page;
use App\Engine\Data\Query;
use App\Engine\Data\Repository;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Data\CountryRepository;
use App\Tests\Fixtures\Data\CustomerRepository;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;
use App\Tests\Support\TestCase;

final class RepositoryTest extends TestCase
{
    private ArraySource $source;

    private ModelManager $models;

    private CustomerRepository $customers;

    protected function setUp(): void
    {
        $this->source = new ArraySource(['customers' => [
            ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test', 'ownerId' => 10, 'active' => true, 'balance' => 5.0],
            ['id' => 2, 'name' => 'Grace', 'email' => 'grace@example.test', 'ownerId' => 11, 'active' => false, 'balance' => 0.0],
            ['id' => 3, 'name' => 'Katherine', 'email' => 'kat@example.test', 'ownerId' => null, 'active' => true, 'balance' => 12.5],
        ]]);

        $this->models = new ModelManager();
        $this->customers = new CustomerRepository($this->source, $this->models);
    }

    // ---- the shape of the API ---------------------------------------------

    /**
     * The whole point of the base class: it contributes no API at all, so every
     * public method on a repository is one somebody wrote on purpose.
     *
     * A base with find(), findAll(), save() and delete() on it is a table
     * gateway with a longer name -- it says nothing about the domain and grows
     * a method per column.
     */
    public function test_the_base_class_contributes_no_public_api(): void
    {
        $public = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(Repository::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame(['__construct'], $public);
    }

    public function test_the_plumbing_cannot_be_overridden(): void
    {
        $reflection = new \ReflectionClass(Repository::class);

        foreach (['query', 'persist', 'remove', 'hydrate', 'insertMany', 'updateWhere', 'deleteWhere'] as $method) {
            self::assertTrue(
                $reflection->getMethod($method)->isFinal(),
                \sprintf('Repository::%s() should be final; it is the contract, not a hook.', $method),
            );
        }
    }

    public function test_a_repository_is_autowirable_from_the_inherited_constructor(): void
    {
        $container = $this->application()->container();
        $container->instance(DataSource::class, $this->source);

        // Nothing declares a constructor: the container reads the inherited one
        // and injects both collaborators by type.
        $repository = $container->get(CustomerRepository::class);

        self::assertInstanceOf(CustomerRepository::class, $repository);
        self::assertSame('Ada', $repository->find(1)?->name());
    }

    // ---- reading ----------------------------------------------------------

    public function test_a_domain_named_read_returns_a_model(): void
    {
        $customer = $this->customers->findByEmail('ada@example.test');

        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame(1, $customer->identity());
        self::assertFalse($customer->isNew());
        self::assertFalse($customer->isDirty());
    }

    public function test_a_read_that_matches_nothing_is_null(): void
    {
        self::assertNull($this->customers->findByEmail('nobody@example.test'));
    }

    public function test_the_inherited_query_knows_the_collection_and_the_model(): void
    {
        $query = $this->customers->queryFor();

        self::assertInstanceOf(Query::class, $query);
        self::assertSame('customers', $query->collection());
        self::assertSame(Customer::class, $query->modelClass());
    }

    public function test_a_read_optimised_method_returns_read_models(): void
    {
        $page = $this->customers->listPage(1, 2);

        self::assertInstanceOf(Page::class, $page);
        self::assertCount(2, $page);
        self::assertInstanceOf(CustomerListRecord::class, $page->items[0]);
        self::assertSame(3, $page->total);
    }

    public function test_a_column_read_avoids_models_entirely(): void
    {
        self::assertSame([10, 11], $this->customers->ownerIds());
    }

    // ---- writing ----------------------------------------------------------

    /**
     * A new model has no identity, so the stored row is read back and the
     * caller uses the return value. The alternative -- reaching into the model
     * to set its identity -- would mean every model exposing a writable one for
     * the engine's benefit.
     */
    public function test_registering_a_model_stores_it_and_returns_what_is_stored(): void
    {
        $customer = $this->customers->register('Barbara', 'barbara@example.test');

        self::assertSame(4, $customer->identity());
        self::assertFalse($customer->isNew(), 'the returned model matches storage');
        self::assertFalse($customer->isDirty());

        self::assertCount(4, $this->source->all('customers'));
        self::assertSame('Barbara', $this->customers->find(4)?->name());
    }

    public function test_a_stored_model_joins_the_identity_map(): void
    {
        $customer = $this->customers->register('Barbara', 'barbara@example.test');

        self::assertSame($customer, $this->customers->find(4));
    }

    /** The whole reason the model layer tracks changes: write only what moved. */
    public function test_an_update_writes_only_what_changed(): void
    {
        $writes = new class ($this->source) implements DataSource {
            /** @var list<array<string, mixed>> */
            public array $updates = [];

            public function __construct(private readonly ArraySource $inner) {}

            public function fetch(Query $query): iterable
            {
                return $this->inner->fetch($query);
            }

            public function count(Query $query): int
            {
                return $this->inner->count($query);
            }

            public function insert(string $collection, string $key, array $row): int|string|null
            {
                return $this->inner->insert($collection, $key, $row);
            }

            public function update(string $collection, string $key, int|string $identity, array $changes): int
            {
                $this->updates[] = $changes;

                return $this->inner->update($collection, $key, $identity, $changes);
            }

            public function delete(string $collection, string $key, int|string $identity): int
            {
                return $this->inner->delete($collection, $key, $identity);
            }
        };

        $repository = new CustomerRepository($writes, $this->models);
        $customer = $repository->find(1);
        self::assertNotNull($customer);

        $repository->rename($customer, 'Ada Lovelace');

        self::assertSame([['name' => 'Ada Lovelace']], $writes->updates);
    }

    public function test_an_update_leaves_the_model_clean(): void
    {
        $customer = $this->customers->find(1);
        self::assertNotNull($customer);

        $renamed = $this->customers->rename($customer, 'Ada Lovelace');

        self::assertSame($customer, $renamed, 'an update returns the same instance');
        self::assertFalse($renamed->isDirty());
        self::assertSame('Ada Lovelace', $this->source->all('customers')[0]['name']);
    }

    public function test_a_domain_operation_writes_the_field_it_changed(): void
    {
        $customer = $this->customers->find(1);
        self::assertNotNull($customer);

        $this->customers->deactivate($customer);

        self::assertFalse($this->source->all('customers')[0]['active']);
        self::assertCount(1, $this->customers->active());
    }

    /**
     * Persisting a model that has not changed is not a mistake. A caller being
     * careful should not be punished with a pointless write.
     */
    public function test_persisting_an_unchanged_model_writes_nothing(): void
    {
        $customer = $this->customers->find(1);
        self::assertNotNull($customer);

        $before = $this->source->all('customers');
        $this->customers->rename($customer, 'Ada');

        self::assertSame($before, $this->source->all('customers'));
    }

    public function test_removing_a_model_deletes_its_row_and_forgets_it(): void
    {
        $customer = $this->customers->find(1);
        self::assertNotNull($customer);

        $this->customers->forget($customer);

        self::assertCount(2, $this->source->all('customers'));
        self::assertFalse($this->models->isMapped(Customer::class, 1));
        self::assertNull($this->customers->find(1));
    }

    public function test_removing_a_model_that_was_never_stored_is_refused(): void
    {
        $this->expectException(DataException::class);
        $this->expectExceptionMessageMatches('/never been stored/');

        $this->customers->forget(new Customer(null, 'Nobody', 'nobody@example.test'));
    }

    // ---- a key other than "id" --------------------------------------------

    /**
     * A model that assigns its own identity is stored under it, rather than
     * being handed a generated one.
     */
    public function test_a_repository_can_key_rows_by_something_else(): void
    {
        $countries = new CountryRepository($this->source, $this->models);

        $country = $countries->add('GB', 'United Kingdom');

        self::assertSame('GB', $country->identity());
        self::assertSame(
            [['code' => 'GB', 'label' => 'United Kingdom']],
            $this->source->all('countries'),
        );
        self::assertSame('United Kingdom', $countries->find('GB')?->label());

        $countries->drop($country);
        self::assertSame([], $this->source->all('countries'));
    }

    // ---- bulk writes ------------------------------------------------------

    public function test_a_bulk_import_stores_rows_without_building_models(): void
    {
        $stored = $this->customers->import([
            ['name' => 'Mary', 'email' => 'mary@example.test', 'ownerId' => null, 'active' => true, 'balance' => 0.0],
            ['name' => 'Hedy', 'email' => 'hedy@example.test', 'ownerId' => null, 'active' => true, 'balance' => 0.0],
        ]);

        self::assertSame(2, $stored);
        self::assertSame(0, $this->models->mappedCount(), 'nothing was hydrated to store them');
        self::assertSame('Hedy', $this->customers->findByEmail('hedy@example.test')?->name());
    }

    /**
     * The database changed underneath models already in memory. Handing out the
     * mapped object afterwards would say Ada is still active when she is not.
     */
    public function test_a_set_based_update_forgets_the_models_it_may_have_changed(): void
    {
        $ada = $this->customers->find(1);
        self::assertTrue($ada?->isActive());

        self::assertSame(1, $this->customers->deactivateOwnedBy(10));

        $reloaded = $this->customers->find(1);
        self::assertNotSame($ada, $reloaded, 'the identity map handed back the stale object');
        self::assertFalse($reloaded?->isActive());
    }

    public function test_a_set_based_delete_forgets_the_models_it_removed(): void
    {
        $grace = $this->customers->find(2);
        self::assertNotNull($grace);

        self::assertSame(1, $this->customers->purgeInactive());

        self::assertNull($this->customers->find(2));
        self::assertFalse($this->models->isMapped(Customer::class, 2));
    }

    /** A source without set-based writes says so by name, rather than looping quietly. */
    public function test_a_bulk_write_on_a_source_that_cannot_do_one_is_refused(): void
    {
        $source = new class implements DataSource {
            public function fetch(Query $query): iterable
            {
                return [];
            }

            public function count(Query $query): int
            {
                return 0;
            }

            public function insert(string $collection, string $key, array $row): int|string|null
            {
                return null;
            }

            public function update(string $collection, string $key, int|string $identity, array $changes): int
            {
                return 0;
            }

            public function delete(string $collection, string $key, int|string $identity): int
            {
                return 0;
            }
        };

        $this->expectException(DataException::class);
        $this->expectExceptionMessageMatches('/does not implement BulkWrites/');

        (new CustomerRepository($source, $this->models))->purgeInactive();
    }

    // ---- hydration --------------------------------------------------------

    public function test_a_row_obtained_elsewhere_can_be_hydrated(): void
    {
        $customer = $this->customers->hydrateRow(
            ['id' => 99, 'name' => 'Elsewhere', 'email' => 'e@example.test'],
        );

        self::assertInstanceOf(Customer::class, $customer);
        self::assertFalse($customer->isNew());
    }
}
