<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelException;
use App\Tests\Fixtures\Model\Contract;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\Invoice;
use App\Tests\Fixtures\Model\Partial;
use App\Tests\Fixtures\Model\User;
use App\Tests\Support\TestCase;

final class ModelTest extends TestCase
{
    private function customer(?int $id = 1): Customer
    {
        return new Customer($id, 'Ada Lovelace', 'ada@example.test');
    }

    // ---- attributes -------------------------------------------------------

    public function test_attributes_are_the_declared_properties(): void
    {
        self::assertSame(
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'ownerId' => null, 'active' => true, 'balance' => 0.0],
            $this->customer()->attributes(),
        );
    }

    /**
     * The base class keeps its own bookkeeping in private properties. If those
     * leaked into attributes() they would end up in a row bound for storage.
     */
    public function test_the_engines_own_state_is_not_an_attribute(): void
    {
        $names = $this->customer()->attributeNames();

        self::assertNotContains('modelOriginal', $names);
        self::assertNotContains('modelRelated', $names);
    }

    public function test_attributes_are_read_from_the_whole_hierarchy(): void
    {
        $contract = new Contract(4, 'C-0004', 'signed');

        self::assertSame(['status' => 'signed', 'id' => 4, 'reference' => 'C-0004'], $contract->attributes());
    }

    /**
     * Reading an uninitialised typed property is a fatal Error, and "not set
     * yet" is a legitimate state for a model that has not been persisted.
     */
    public function test_an_uninitialised_property_is_absent_rather_than_null(): void
    {
        $partial = new Partial();

        self::assertSame(['filled' => 'yes'], $partial->attributes());
        self::assertContains('unset', $partial->attributeNames());
        self::assertNull($partial->attribute('unset'));
    }

    public function test_reading_an_undeclared_attribute_names_what_is_declared(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/has no "nope" attribute\. Declared: id, name, email/');

        $this->customer()->attribute('nope');
    }

    // ---- change tracking --------------------------------------------------

    public function test_a_model_starts_new_and_reports_every_attribute_as_a_change(): void
    {
        $customer = $this->customer();

        self::assertTrue($customer->isNew());
        self::assertNull($customer->original());
        self::assertTrue($customer->isDirty());

        // An insert needs every column, so a new model reports all of them.
        self::assertSame($customer->attributes(), $customer->changes());
    }

    public function test_marking_clean_snapshots_the_current_state(): void
    {
        $customer = $this->customer();
        $customer->markClean();

        self::assertFalse($customer->isNew());
        self::assertFalse($customer->isDirty());
        self::assertSame([], $customer->changes());
        self::assertSame($customer->attributes(), $customer->original());
    }

    /**
     * The point of tracking by reflection rather than by setter: a domain
     * method is tracked without the model knowing tracking exists.
     */
    public function test_a_domain_method_is_tracked_without_any_setter(): void
    {
        $customer = $this->customer();
        $customer->markClean();

        $customer->deactivate();

        self::assertTrue($customer->isDirty());
        self::assertTrue($customer->isDirty('active'));
        self::assertFalse($customer->isDirty('name'));
        self::assertSame(['active' => false], $customer->changes());
    }

    public function test_only_the_changed_attributes_are_reported(): void
    {
        $customer = $this->customer();
        $customer->markClean();

        $customer->rename('Ada King');
        $customer->deactivate();

        self::assertSame(['name' => 'Ada King', 'active' => false], $customer->changes());
        self::assertSame('Ada Lovelace', $customer->original()['name'] ?? null);
    }

    public function test_setting_an_attribute_back_to_its_original_value_is_not_a_change(): void
    {
        $customer = $this->customer();
        $customer->markClean();

        $customer->rename('Ada King');
        self::assertTrue($customer->isDirty('name'));

        $customer->rename('Ada Lovelace');
        self::assertFalse($customer->isDirty('name'));
        self::assertSame([], $customer->changes());
    }

    public function test_marking_clean_again_clears_the_changes(): void
    {
        $customer = $this->customer();
        $customer->markClean();
        $customer->rename('Ada King');

        $customer->markClean();

        self::assertFalse($customer->isDirty());
        self::assertSame('Ada King', $customer->original()['name'] ?? null);
    }

    /**
     * Comparison is strict, so 0 and 0.0 and '0' are three different values.
     * A loose comparison here would silently skip a column on an update.
     */
    public function test_comparison_is_strict(): void
    {
        $customer = new Customer(1, 'Ada', 'ada@example.test', null, true, 0.0);
        $customer->markClean();

        self::assertFalse($customer->isDirty('balance'));
        self::assertSame(0.0, $customer->attribute('balance'));
    }

    // ---- identity ---------------------------------------------------------

    public function test_identity_is_whatever_the_model_says_it_is(): void
    {
        self::assertSame(1, $this->customer()->identity());
        self::assertNull($this->customer(null)->identity());
    }

    // ---- relations --------------------------------------------------------

    public function test_reading_a_relation_that_was_never_loaded_throws(): void
    {
        $customer = $this->customer();

        self::assertFalse($customer->hasRelated('invoices'));
        self::assertSame([], $customer->loadedRelations());

        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/has not been loaded/');

        $customer->related('invoices');
    }

    /**
     * The distinction the whole design rests on: "loaded and empty" must be
     * answerable without a query, or the loud error above becomes a false
     * alarm and people start catching it.
     */
    public function test_loaded_and_empty_is_not_the_same_as_never_loaded(): void
    {
        $customer = $this->customer();
        $customer->attachRelated('invoices', ModelCollection::empty(Invoice::class));

        self::assertTrue($customer->hasRelated('invoices'));

        $invoices = $customer->related('invoices');
        self::assertInstanceOf(ModelCollection::class, $invoices);
        self::assertTrue($invoices->isEmpty());
    }

    public function test_a_to_one_relation_may_be_attached_as_null(): void
    {
        $customer = $this->customer();
        $customer->attachRelated('owner', null);

        self::assertTrue($customer->hasRelated('owner'));
        self::assertNull($customer->related('owner'));
    }

    public function test_an_attached_relation_is_returned_as_given(): void
    {
        $customer = $this->customer();
        $owner = new User(9, 'ada');

        $customer->attachRelated('owner', $owner);

        self::assertSame($owner, $customer->related('owner'));
        self::assertSame(['owner'], $customer->loadedRelations());
    }

    public function test_forgetting_a_relation_restores_the_unloaded_state(): void
    {
        $customer = $this->customer();
        $customer->attachRelated('owner', new User(9, 'ada'));
        $customer->forgetRelated('owner');

        self::assertFalse($customer->hasRelated('owner'));

        $this->expectException(ModelException::class);
        $customer->related('owner');
    }

    /** Relations are not state to be written; they must not reach storage. */
    public function test_an_attached_relation_is_not_an_attribute_and_is_not_a_change(): void
    {
        $customer = $this->customer();
        $customer->markClean();

        $customer->attachRelated('owner', new User(9, 'ada'));

        self::assertArrayNotHasKey('owner', $customer->attributes());
        self::assertSame([], $customer->changes());
    }

}
