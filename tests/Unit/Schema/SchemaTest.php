<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;
use App\Engine\Schema\SchemaException;
use App\Tests\Support\TestCase;

final class SchemaTest extends TestCase
{
    private function customer(): Schema
    {
        return Schema::of(
            'customer',
            Field::string('name')->length(1, 120),
            Field::string('email'),
            Field::int('age')->optional()->range(0, 150),
            Field::bool('active')->default(true),
        );
    }

    // ---- declaration ------------------------------------------------------

    public function test_a_schema_knows_its_name_and_fields(): void
    {
        $schema = $this->customer();

        self::assertSame('customer', $schema->name());
        self::assertSame(['name', 'email', 'age', 'active'], $schema->fieldNames());
        self::assertTrue($schema->has('email'));
        self::assertFalse($schema->has('nope'));
        self::assertSame('email', $schema->field('email')->name);
    }

    public function test_asking_for_a_field_that_does_not_exist_names_the_ones_that_do(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Declared: name, email, age, active/');

        $this->customer()->field('nope');
    }

    /** Field names are the keys of the data, so a duplicate is ambiguous. */
    public function test_a_duplicate_field_is_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/declares "name" twice/');

        Schema::of('x', Field::string('name'), Field::int('name'));
    }

    /** Deriving one contract from another: a resource is its input plus an id. */
    public function test_a_schema_can_be_extended_into_another(): void
    {
        $resource = $this->customer()->with(Field::int('id'))->named('customer.resource');

        self::assertSame('customer.resource', $resource->name());
        self::assertSame(['name', 'email', 'age', 'active', 'id'], $resource->fieldNames());

        // The original is untouched.
        self::assertFalse($this->customer()->has('id'));
    }

    public function test_extending_replaces_a_field_of_the_same_name(): void
    {
        $relaxed = $this->customer()->with(Field::string('email')->optional());

        self::assertCount(4, $relaxed->fields());
        self::assertFalse($relaxed->field('email')->isRequired());
    }

    // ---- validation -------------------------------------------------------

    public function test_valid_data_produces_no_errors(): void
    {
        $result = $this->customer()->validate(['name' => 'Ada', 'email' => 'ada@example.test']);

        self::assertTrue($result->isValid());
        self::assertCount(0, $result);
    }

    /**
     * Four bad fields are four errors, not four round trips. This is the whole
     * reason validation collects instead of throwing on the first problem.
     */
    public function test_every_problem_is_reported_at_once(): void
    {
        $result = $this->customer()->validate(['name' => '', 'age' => 'old', 'active' => 'maybe']);

        self::assertFalse($result->isValid());
        self::assertSame(
            ['name', 'email', 'age', 'active'],
            $result->paths(),
        );
    }

    public function test_a_missing_required_field_says_so(): void
    {
        $result = $this->customer()->validate(['email' => 'ada@example.test']);

        self::assertSame(['name' => ['is required']], $result->messages());
    }

    public function test_a_field_with_a_default_is_never_missing(): void
    {
        self::assertTrue($this->customer()->validate(['name' => 'Ada', 'email' => 'a@example.test'])->isValid());
    }

    public function test_validation_never_throws(): void
    {
        $result = $this->customer()->validate([]);

        self::assertFalse($result->isValid());
        self::assertCount(2, $result);
    }

    // ---- deserialisation --------------------------------------------------

    public function test_input_is_converted_and_defaults_are_applied(): void
    {
        $data = $this->customer()->deserialize([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'age' => '36',
        ]);

        self::assertSame([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'age' => 36,
            'active' => true,
        ], $data);
    }

    /**
     * Unknown keys are dropped, not rejected. A client sending a field this
     * version does not know about is not an error, and nothing unexpected
     * travels onwards by accident.
     */
    public function test_unknown_keys_are_dropped(): void
    {
        $data = $this->customer()->deserialize([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'is_admin' => true,
            'id' => 999,
        ]);

        self::assertArrayNotHasKey('is_admin', $data);
        self::assertArrayNotHasKey('id', $data);
    }

    /** Absent and null are different things, and they stay different. */
    public function test_an_optional_absent_field_is_absent_from_the_result(): void
    {
        $data = $this->customer()->deserialize(['name' => 'Ada', 'email' => 'ada@example.test']);

        self::assertArrayNotHasKey('age', $data);

        $withNull = $this->customer()
            ->with(Field::int('age')->optional()->nullable())
            ->deserialize(['name' => 'Ada', 'email' => 'ada@example.test', 'age' => null]);

        self::assertArrayHasKey('age', $withNull);
        self::assertNull($withNull['age']);
    }

    public function test_bad_input_throws_and_carries_every_error(): void
    {
        try {
            $this->customer()->deserialize(['age' => 'old']);
            self::fail('deserialize should have refused this');
        } catch (SchemaException $e) {
            self::assertStringContainsString('does not satisfy the "customer" schema', $e->getMessage());
            self::assertSame(['name', 'email', 'age'], $e->result()->paths());
            self::assertFalse($e->result()->isValid());
        }
    }

    // ---- serialisation ----------------------------------------------------

    public function test_outgoing_data_is_shaped_to_the_contract(): void
    {
        $resource = Schema::of(
            'customer.resource',
            Field::int('id'),
            Field::string('name'),
            Field::string('email'),
        );

        $payload = $resource->serialize([
            'id' => 7,
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'internalNote' => 'do not ship this',
        ]);

        self::assertSame(['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.test'], $payload);
    }

    /**
     * A mismatch on the way out is the application breaking its own promise.
     * Reporting it as a client error would send someone looking for a bug at
     * the wrong end of the wire.
     */
    public function test_failing_to_match_your_own_contract_is_reported_as_your_fault(): void
    {
        $resource = Schema::of('customer.resource', Field::int('id'), Field::string('name'));

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/fault in the code producing the data/');

        $resource->serialize(['name' => 'Ada']);
    }

    // ---- nesting ----------------------------------------------------------

    private function order(): Schema
    {
        return Schema::of(
            'order',
            Field::string('reference'),
            Field::object('customer', Schema::of('customer', Field::string('name'), Field::string('email'))),
            Field::collection('lines', Schema::of('line', Field::string('sku'), Field::int('quantity')->range(1, null))),
        );
    }

    public function test_nested_structures_are_validated_and_converted_throughout(): void
    {
        $data = $this->order()->deserialize([
            'reference' => 'ORD-1',
            'customer' => ['name' => 'Ada', 'email' => 'ada@example.test'],
            'lines' => [
                ['sku' => 'A-1', 'quantity' => '2'],
                ['sku' => 'B-2', 'quantity' => 1],
            ],
        ]);

        self::assertSame(2, $data['lines'][0]['quantity'], 'conversion reaches into a collection');
        self::assertSame('Ada', $data['customer']['name']);
    }

    public function test_a_nested_error_carries_the_full_path(): void
    {
        $result = $this->order()->validate([
            'reference' => 'ORD-1',
            'customer' => ['name' => 'Ada'],
            'lines' => [
                ['sku' => 'A-1', 'quantity' => 0],
                ['quantity' => 3],
            ],
        ]);

        self::assertSame(
            ['customer.email', 'lines.0.quantity', 'lines.1.sku'],
            $result->paths(),
        );
    }

    public function test_unknown_keys_are_dropped_at_every_level(): void
    {
        $data = $this->order()->deserialize([
            'reference' => 'ORD-1',
            'customer' => ['name' => 'Ada', 'email' => 'a@example.test', 'secret' => 'x'],
            'lines' => [['sku' => 'A-1', 'quantity' => 1, 'cost' => 99]],
        ]);

        self::assertArrayNotHasKey('secret', $data['customer']);
        self::assertArrayNotHasKey('cost', $data['lines'][0]);
    }

    // ---- the contract -----------------------------------------------------

    public function test_a_schema_describes_itself_as_plain_data(): void
    {
        $described = $this->customer()->describe();

        self::assertSame('customer', $described['name']);
        self::assertSame(['name', 'email', 'age', 'active'], \array_keys($described['fields']));
        self::assertSame('string', $described['fields']['name']['type']);
        self::assertTrue($described['fields']['email']['required']);
        self::assertFalse($described['fields']['age']['required']);
        self::assertTrue($described['fields']['active']['default']);
    }

    /** The description is derived, so it cannot drift from what is enforced. */
    public function test_the_description_can_be_encoded_for_a_client(): void
    {
        $json = \json_encode($this->order()->describe(), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"lines"', $json);
        self::assertStringContainsString('"element"', $json);
        self::assertStringContainsString('"quantity"', $json);
    }

    // ---- what a schema deliberately is not --------------------------------

    /**
     * Not a form-request object: nothing here is resolved from a handler
     * signature, nothing throws an HTTP status, and the result is plain data
     * that the caller decides what to do with.
     */
    public function test_a_schema_produces_data_rather_than_an_object(): void
    {
        // Plain data, which the caller decides what to do with -- hand it to
        // ModelManager::hydrate(), a repository or a queue payload; the schema
        // never learns which.
        self::assertSame(
            ['name' => 'Ada', 'email' => 'ada@example.test', 'active' => true],
            $this->customer()->deserialize(['name' => 'Ada', 'email' => 'ada@example.test']),
        );

        foreach (\get_class_methods(Schema::class) as $method) {
            foreach (['model', 'request', 'response', 'rules', 'authorize', 'fill'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    \strtolower($method),
                    \sprintf('Schema::%s() reaches outside what a shape should know about.', $method),
                );
            }
        }
    }

    public function test_the_same_schema_serves_input_and_output(): void
    {
        $schema = Schema::of('point', Field::int('x'), Field::int('y'));

        self::assertSame(['x' => 1, 'y' => 2], $schema->deserialize(['x' => '1', 'y' => '2']));
        self::assertSame(['x' => 1, 'y' => 2], $schema->serialize(['x' => 1, 'y' => 2]));
    }

    public function test_an_empty_schema_accepts_anything_and_returns_nothing(): void
    {
        $schema = Schema::of('empty');

        self::assertSame([], $schema->deserialize(['a' => 1, 'b' => 2]));
        self::assertTrue($schema->validate([])->isValid());
    }
}
