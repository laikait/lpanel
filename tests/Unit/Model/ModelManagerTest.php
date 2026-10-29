<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelException;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Model\Contract;
use App\Tests\Fixtures\Model\Country;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\Draft;
use App\Tests\Fixtures\Model\Marker;
use App\Tests\Fixtures\Model\NeverInstantiable;
use App\Tests\Fixtures\Model\Untyped;
use App\Tests\Fixtures\Model\User;
use App\Tests\Fixtures\Model\WithObject;
use App\Tests\Support\TestCase;

final class ModelManagerTest extends TestCase
{
    private ModelManager $models;

    protected function setUp(): void
    {
        $this->models = new ModelManager();
    }

    /** @return array<string, mixed> */
    private function row(int $id = 1): array
    {
        return ['id' => $id, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'];
    }

    // ---- hydration --------------------------------------------------------

    public function test_a_row_is_matched_to_the_constructor_by_parameter_name(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row());

        self::assertSame(1, $customer->identity());
        self::assertSame('Ada Lovelace', $customer->name());
        self::assertSame('ada@example.test', $customer->email());
    }

    /**
     * A model that came from storage matches storage, so an immediate update
     * must write nothing.
     */
    public function test_a_hydrated_model_is_clean(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row());

        self::assertFalse($customer->isNew());
        self::assertFalse($customer->isDirty());
        self::assertSame([], $customer->changes());
    }

    public function test_parameters_with_defaults_may_be_absent_from_the_row(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row());

        self::assertTrue($customer->isActive());
        self::assertSame(0.0, $customer->balance());
        self::assertNull($customer->ownerId());
    }

    /** Column selection means the row shape varies legitimately. */
    public function test_row_keys_the_model_does_not_declare_are_ignored(): void
    {
        $customer = $this->models->hydrate(
            Customer::class,
            $this->row() + ['created_at' => '2026-01-01', 'row_number' => 4],
        );

        self::assertSame(1, $customer->identity());
        self::assertArrayNotHasKey('created_at', $customer->attributes());
    }

    public function test_a_missing_required_value_names_the_model_the_field_and_the_row(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/has no "email" for the required string constructor parameter/');
        $this->expectExceptionMessageMatches('/The row provided: id, name/');

        $this->models->hydrate(Customer::class, ['id' => 1, 'name' => 'Ada']);
    }

    public function test_the_whole_hierarchy_is_hydrated(): void
    {
        $contract = $this->models->hydrate(Contract::class, ['id' => 4, 'reference' => 'C-0004', 'status' => 'signed']);

        self::assertSame(4, $contract->identity());
        self::assertSame('signed', $contract->status());
    }

    public function test_a_model_with_no_constructor_is_hydrated_from_its_defaults(): void
    {
        $marker = $this->models->hydrate(Marker::class, ['number' => 99]);

        // No constructor means nothing to match against, so the row is ignored
        // rather than written into the object behind its back.
        self::assertSame(7, $marker->identity());
    }

    public function test_an_abstract_model_cannot_be_hydrated(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/it is abstract/');

        $this->models->hydrate(NeverInstantiable::class, []);
    }

    /**
     * The guard is for callers the type system does not check, so the test
     * calls it the same way rather than pretending stdClass is a model.
     */
    public function test_a_class_that_is_not_a_model_cannot_be_hydrated(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/is not a/');

        (new \ReflectionMethod($this->models, 'hydrate'))->invoke($this->models, \stdClass::class, []);
    }

    /** The constructor is still the place a model defends its own invariants. */
    public function test_a_constructor_that_refuses_the_row_is_not_swallowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A customer needs a name.');

        $this->models->hydrate(Customer::class, ['id' => 1, 'name' => '', 'email' => 'a@example.test']);
    }

    // ---- conversion -------------------------------------------------------

    /** The reason conversion exists: a driver may hand back every column as a string. */
    public function test_a_string_row_from_a_database_driver_hydrates_a_typed_model(): void
    {
        $customer = $this->models->hydrate(Customer::class, [
            'id' => '42',
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'ownerId' => '7',
            'active' => '0',
            'balance' => '12.50',
        ]);

        self::assertSame(42, $customer->identity());
        self::assertSame(7, $customer->ownerId());
        self::assertFalse($customer->isActive());
        self::assertSame(12.5, $customer->balance());
    }

    public function test_an_int_is_widened_to_a_float(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row() + ['balance' => 12]);

        self::assertSame(12.0, $customer->balance());
    }

    public function test_a_number_may_become_a_string(): void
    {
        $country = $this->models->hydrate(Country::class, ['code' => 'GB', 'label' => 2026]);

        self::assertSame('2026', $country->label());
    }

    /** @param mixed $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('ambiguousValues')]
    public function test_an_ambiguous_value_is_a_mapping_error(string $field, $value): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/unambiguous/');

        $this->models->hydrate(Customer::class, [$field => $value] + $this->row());
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function ambiguousValues(): array
    {
        return [
            'a word for an int' => ['id', 'abc'],
            'a decimal for an int' => ['id', '42.5'],
            'exponent notation for an int' => ['id', '4e2'],
            'a bool for an int' => ['id', true],
            'a word for a float' => ['balance', 'lots'],
            'a bool for a string' => ['name', true],
            'an array for a string' => ['name', ['Ada']],
            'a word for a bool' => ['active', 'maybe'],
            'a number for a bool' => ['active', 2],
        ];
    }

    public function test_null_is_accepted_only_where_the_model_allows_it(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row() + ['ownerId' => null]);
        self::assertNull($customer->ownerId());

        $this->expectException(ModelException::class);
        $this->models->hydrate(Customer::class, ['id' => 1, 'name' => null, 'email' => 'a@example.test']);
    }

    /** Constructing a declared object from a row would be exactly the magic this framework avoids. */
    public function test_an_object_value_is_passed_through_untouched(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01');
        $model = $this->models->hydrate(WithObject::class, ['id' => 1, 'createdAt' => $createdAt]);

        self::assertSame($createdAt, $model->createdAt());
    }

    public function test_a_wrong_object_reaches_the_constructor_and_is_reported_as_a_mapping_error(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/constructor rejected the row/');

        $this->models->hydrate(WithObject::class, ['id' => 1, 'createdAt' => 'yesterday']);
    }

    public function test_an_untyped_parameter_takes_the_value_as_it_is(): void
    {
        $model = $this->models->hydrate(Untyped::class, ['id' => 1, 'anything' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $model->anything());
    }

    // ---- collections ------------------------------------------------------

    public function test_many_rows_hydrate_into_a_typed_collection(): void
    {
        $collection = $this->models->hydrateAll(Customer::class, [
            $this->row(1),
            $this->row(2),
            $this->row(3),
        ]);

        self::assertInstanceOf(ModelCollection::class, $collection);
        self::assertSame(Customer::class, $collection->type());
        self::assertSame([1, 2, 3], $collection->identities());
    }

    public function test_hydrating_no_rows_gives_an_empty_typed_collection(): void
    {
        $collection = $this->models->hydrateAll(Customer::class, []);

        self::assertTrue($collection->isEmpty());
        self::assertSame(Customer::class, $collection->type());
    }

    public function test_it_hydrates_from_any_iterable_including_a_generator(): void
    {
        $rows = (function (): \Generator {
            yield $this->row(1);
            yield $this->row(2);
        })();

        self::assertCount(2, $this->models->hydrateAll(Customer::class, $rows));
    }

    // ---- identity map -----------------------------------------------------

    /**
     * Without this, two queries that both touch a customer produce two objects,
     * a change to one is invisible to the other, and the last write wins.
     */
    public function test_the_same_row_hydrated_twice_is_the_same_object(): void
    {
        $first = $this->models->hydrate(Customer::class, $this->row());
        $second = $this->models->hydrate(Customer::class, $this->row());

        self::assertSame($first, $second);
    }

    public function test_pending_changes_survive_a_second_hydration(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row());
        $customer->rename('Ada King');

        $again = $this->models->hydrate(Customer::class, $this->row());

        self::assertSame($customer, $again);
        self::assertSame(['name' => 'Ada King'], $again->changes());
    }

    public function test_different_identities_are_different_objects(): void
    {
        self::assertNotSame(
            $this->models->hydrate(Customer::class, $this->row(1)),
            $this->models->hydrate(Customer::class, $this->row(2)),
        );
    }

    public function test_the_map_is_keyed_by_class_as_well_as_identity(): void
    {
        $customer = $this->models->hydrate(Customer::class, $this->row());
        $user = $this->models->hydrate(User::class, ['id' => 1, 'username' => 'ada']);

        self::assertNotSame($customer, $user);
        self::assertSame(2, $this->models->mappedCount());
    }

    public function test_string_identities_are_mapped(): void
    {
        $first = $this->models->hydrate(Country::class, ['code' => 'GB', 'label' => 'United Kingdom']);
        $second = $this->models->hydrate(Country::class, ['code' => 'GB', 'label' => 'Britain']);

        self::assertSame($first, $second);
        self::assertSame('United Kingdom', $second->label());
    }

    public function test_a_model_with_no_identity_is_never_mapped(): void
    {
        $first = $this->models->hydrate(Draft::class, ['subject' => 'Notes']);
        $second = $this->models->hydrate(Draft::class, ['subject' => 'Notes']);

        self::assertNotSame($first, $second);
        self::assertSame(0, $this->models->mappedCount());
        self::assertFalse($first->isNew(), 'a hydrated model is still clean even when it cannot be mapped');
    }

    public function test_a_model_can_be_remembered_after_it_is_given_an_identity(): void
    {
        $customer = new Customer(null, 'Ada', 'ada@example.test');
        $customer->assignIdentity(50);

        $this->models->remember($customer);

        self::assertTrue($this->models->isMapped(Customer::class, 50));
        self::assertSame($customer, $this->models->mapped(Customer::class, 50));
        self::assertSame($customer, $this->models->hydrate(Customer::class, $this->row(50)));
    }

    public function test_remembering_a_model_without_an_identity_is_refused(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/has no identity yet/');

        $this->models->remember(new Customer(null, 'Ada', 'ada@example.test'));
    }

    public function test_mapped_returns_null_for_something_that_was_never_seen(): void
    {
        self::assertNull($this->models->mapped(Customer::class, 1));
        self::assertFalse($this->models->isMapped(Customer::class, 1));
    }

    public function test_one_model_can_be_forgotten(): void
    {
        $first = $this->models->hydrate(Customer::class, $this->row());
        $this->models->forget(Customer::class, 1);

        self::assertSame(0, $this->models->mappedCount());
        self::assertNotSame($first, $this->models->hydrate(Customer::class, $this->row()));
    }

    /**
     * A long-running worker must do this between units of work. Without it the
     * map is a slow leak, and state from one job is visible to the next.
     */
    public function test_the_map_can_be_flushed_entirely_or_per_class(): void
    {
        $this->models->hydrate(Customer::class, $this->row(1));
        $this->models->hydrate(Customer::class, $this->row(2));
        $this->models->hydrate(User::class, ['id' => 1, 'username' => 'ada']);

        self::assertSame(3, $this->models->mappedCount());

        $this->models->flush(Customer::class);
        self::assertSame(1, $this->models->mappedCount());

        $this->models->flush();
        self::assertSame(0, $this->models->mappedCount());
    }

    public function test_two_managers_do_not_share_a_map(): void
    {
        $other = new ModelManager();

        self::assertNotSame(
            $this->models->hydrate(Customer::class, $this->row()),
            $other->hydrate(Customer::class, $this->row()),
        );
    }

    // ---- what the manager deliberately is not -----------------------------

    /**
     * Retrieval belongs to the data layer. A query API here would make this the
     * ORM the framework has chosen not to be.
     */
    public function test_the_manager_has_no_retrieval_api(): void
    {
        $methods = \get_class_methods(ModelManager::class);

        foreach (['find', 'findAll', 'all', 'where', 'query', 'save', 'persist', 'delete'] as $forbidden) {
            self::assertNotContains($forbidden, $methods, \sprintf('ModelManager grew a %s() method.', $forbidden));
        }
    }
}
