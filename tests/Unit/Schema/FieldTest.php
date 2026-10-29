<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Engine\Schema\Field;
use App\Engine\Schema\FieldType;
use App\Engine\Schema\Schema;
use App\Engine\Schema\SchemaException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FieldTest extends TestCase
{
    // ---- declaration ------------------------------------------------------

    public function test_a_field_knows_its_name_and_type(): void
    {
        self::assertSame('age', Field::int('age')->name);
        self::assertSame(FieldType::Int, Field::int('age')->type);
        self::assertSame(FieldType::Float, Field::float('x')->type);
        self::assertSame(FieldType::String, Field::string('x')->type);
        self::assertSame(FieldType::Bool, Field::bool('x')->type);
    }

    /** Required unless you say otherwise, so a forgotten mark fails loudly. */
    public function test_a_field_is_required_by_default(): void
    {
        self::assertTrue(Field::string('name')->isRequired());
        self::assertFalse(Field::string('name')->optional()->isRequired());
        self::assertTrue(Field::string('name')->optional()->required()->isRequired());
    }

    public function test_null_is_a_separate_question_from_absence(): void
    {
        $field = Field::string('name');

        self::assertFalse($field->isNullable());
        self::assertTrue($field->nullable()->isNullable());
        self::assertFalse($field->nullable()->nullable(false)->isNullable());

        // Required and nullable: must be there, may be null.
        self::assertTrue($field->nullable()->isRequired());
    }

    public function test_a_default_makes_a_field_optional(): void
    {
        $field = Field::bool('active')->default(true);

        self::assertTrue($field->hasDefault());
        self::assertTrue($field->defaultValue());
        self::assertFalse($field->isRequired());
    }

    /**
     * Fields are values. One declared once and reused must not change because
     * something downstream refined it.
     */
    public function test_every_modifier_returns_a_new_field(): void
    {
        $base = Field::string('name');
        $refined = $base->optional()->nullable()->length(1, 10);

        self::assertNotSame($base, $refined);
        self::assertTrue($base->isRequired());
        self::assertFalse($base->isNullable());
    }

    // ---- constraints that cannot apply ------------------------------------

    /**
     * A broken declaration fails when the schema is built, not on the first
     * request that happens to exercise the field.
     */
    #[DataProvider('inapplicableConstraints')]
    public function test_a_constraint_that_cannot_mean_anything_is_refused(\Closure $declare, string $expected): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches($expected);

        $declare();
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function inapplicableConstraints(): array
    {
        $schema = Schema::of('nested', Field::string('x'));

        return [
            'length on an int' => [static fn(): Field => Field::int('a')->length(1, 2), '/length\(\).+is an integer/'],
            'length on a list' => [static fn(): Field => Field::listOf('a', Field::int('e'))->length(1, 2), '/length\(\)/'],
            'range on a string' => [static fn(): Field => Field::string('a')->range(1, 2), '/range\(\).+is a string/'],
            'range on a list' => [static fn(): Field => Field::listOf('a', Field::int('e'))->range(1, 2), '/range\(\)/'],
            'size on a string' => [static fn(): Field => Field::string('a')->size(1, 2), '/size\(\)/'],
            'size on an object' => [static fn(): Field => Field::object('a', $schema)->size(1, 2), '/size\(\)/'],
            'pattern on an int' => [static fn(): Field => Field::int('a')->pattern('/x/'), '/pattern\(\)/'],
            'oneOf on an object' => [static fn(): Field => Field::object('a', $schema)->oneOf(['x']), '/oneOf\(\)/'],
        ];
    }

    public function test_a_bound_whose_minimum_exceeds_its_maximum_is_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/minimum above its maximum/');

        Field::int('a')->range(10, 1);
    }

    public function test_a_choice_of_nothing_is_refused(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/nothing could ever be valid/');

        Field::string('a')->oneOf([]);
    }

    public function test_a_default_that_could_never_be_valid_is_refused(): void
    {
        $this->expectException(SchemaException::class);

        Field::string('status')->oneOf(['active', 'suspended'])->default('unknown');
    }

    public function test_an_open_ended_bound_is_allowed(): void
    {
        $atLeast = Field::int('a')->range(0, null);

        self::assertSame(['value' => 5, 'errors' => []], $atLeast->apply(5, 'a'));
        self::assertCount(1, $atLeast->apply(-1, 'a')['errors']);
        self::assertSame([], $atLeast->apply(\PHP_INT_MAX, 'a')['errors']);
    }

    // ---- applying ---------------------------------------------------------

    public function test_a_value_is_converted_and_returned_with_its_errors(): void
    {
        $applied = Field::int('age')->apply('36', 'age');

        self::assertSame(36, $applied['value']);
        self::assertSame([], $applied['errors']);
    }

    public function test_a_value_with_no_single_reading_is_reported_by_type(): void
    {
        $applied = Field::int('age')->apply('old', 'age');

        self::assertCount(1, $applied['errors']);
        self::assertSame('age', $applied['errors'][0]->path);
        self::assertSame('must be an integer', $applied['errors'][0]->message);
    }

    public function test_null_is_rejected_unless_the_field_allows_it(): void
    {
        self::assertSame('must not be null', Field::string('a')->apply(null, 'a')['errors'][0]->message);
        self::assertSame([], Field::string('a')->nullable()->apply(null, 'a')['errors']);
        self::assertNull(Field::string('a')->nullable()->apply(null, 'a')['value']);
    }

    /** A null never reaches a constraint, so no check has to defend itself. */
    public function test_a_check_never_sees_null(): void
    {
        $field = Field::string('a')->nullable()->check(
            'never called for null',
            static fn(mixed $value): bool => $value !== null,
        );

        self::assertSame([], $field->apply(null, 'a')['errors']);
    }

    public function test_bounds_read_as_a_number_a_length_or_a_count(): void
    {
        self::assertSame(
            'must be at least 18',
            Field::int('age')->range(18, null)->apply(17, 'age')['errors'][0]->message,
        );

        self::assertSame(
            'must be at most 3 characters long',
            Field::string('code')->length(null, 3)->apply('abcd', 'code')['errors'][0]->message,
        );

        self::assertSame(
            'must have at least 1 item',
            Field::listOf('tags', Field::string('t'))->size(1, null)->apply([], 'tags')['errors'][0]->message,
        );
    }

    public function test_length_counts_characters_rather_than_bytes(): void
    {
        // "naïve" is five characters and six bytes. Counting bytes would reject
        // a name that is perfectly within the limit.
        self::assertSame([], Field::string('name')->length(null, 5)->apply('naïve', 'name')['errors']);
    }

    public function test_a_choice_is_compared_strictly(): void
    {
        $field = Field::int('level')->oneOf([1, 2, 3]);

        self::assertSame([], $field->apply(2, 'level')['errors']);
        self::assertSame([], $field->apply('2', 'level')['errors'], 'conversion happens before the comparison');
        self::assertSame('must be one of: 1, 2, 3', $field->apply(9, 'level')['errors'][0]->message);
    }

    public function test_a_pattern_reports_the_expectation_rather_than_the_regex(): void
    {
        $withText = Field::string('postcode')->pattern('/^[A-Z0-9 ]+$/', 'a postcode');
        $without = Field::string('postcode')->pattern('/^[A-Z0-9 ]+$/');

        self::assertSame('must be a postcode', $withText->apply('lower', 'postcode')['errors'][0]->message);
        self::assertStringContainsString('must match', $without->apply('lower', 'postcode')['errors'][0]->message);
    }

    /**
     * The reason there is no Email field type: one line covers it, and the
     * engine never has to hold an opinion about what an address looks like.
     */
    public function test_a_check_covers_what_the_engine_deliberately_does_not(): void
    {
        $email = Field::string('email')->check(
            'an email address',
            static fn(mixed $value): bool => \is_string($value)
                && \filter_var($value, \FILTER_VALIDATE_EMAIL) !== false,
        );

        self::assertSame([], $email->apply('ada@example.test', 'email')['errors']);
        self::assertSame('must be an email address', $email->apply('nope', 'email')['errors'][0]->message);
    }

    public function test_checks_accumulate_and_all_of_them_run(): void
    {
        $field = Field::string('a')
            ->check('lowercase', static fn(mixed $v): bool => \is_string($v) && $v === \strtolower($v))
            ->check('short', static fn(mixed $v): bool => \is_string($v) && \strlen($v) < 3);

        self::assertCount(2, $field->apply('LONGER', 'a')['errors']);
    }

    public function test_every_problem_with_one_value_is_reported_at_once(): void
    {
        $field = Field::string('code')->length(5, null)->pattern('/^[0-9]+$/', 'digits only');

        self::assertCount(2, $field->apply('ab', 'code')['errors']);
    }

    // ---- composites -------------------------------------------------------

    public function test_an_object_field_descends_into_its_schema(): void
    {
        $address = Schema::of('address', Field::string('line1'), Field::string('postcode'));
        $applied = Field::object('address', $address)->apply(['line1' => '1 Main St'], 'address');

        self::assertSame(['line1' => '1 Main St'], $applied['value']);
        self::assertSame('address.postcode', $applied['errors'][0]->path);
        self::assertSame('is required', $applied['errors'][0]->message);
    }

    public function test_a_list_field_reports_the_index_of_a_bad_element(): void
    {
        $applied = Field::listOf('ids', Field::int('id'))->apply([1, 'x', 3], 'ids');

        self::assertSame('ids.1', $applied['errors'][0]->path);
        self::assertSame([1, 'x', 3], $applied['value']);
    }

    public function test_a_list_must_be_sequential(): void
    {
        $field = Field::listOf('ids', Field::int('id'));

        self::assertSame('must be a list', $field->apply(['a' => 1], 'ids')['errors'][0]->message);
        self::assertSame('must be a list', $field->apply('1,2,3', 'ids')['errors'][0]->message);
        self::assertSame([], $field->apply([], 'ids')['errors']);
    }

    public function test_a_collection_is_a_list_of_objects(): void
    {
        $contact = Schema::of('contact', Field::string('email'));
        $applied = Field::collection('contacts', $contact)->apply(
            [['email' => 'a@example.test'], []],
            'contacts',
        );

        self::assertSame('contacts.1.email', $applied['errors'][0]->path);
        self::assertSame('is required', $applied['errors'][0]->message);
        self::assertSame('a@example.test', $applied['value'][0]['email'] ?? null);
    }

    public function test_lists_nest_arbitrarily(): void
    {
        $grid = Field::listOf('grid', Field::listOf('row', Field::int('cell')));
        $applied = $grid->apply([[1, 2], [3, 'x']], 'grid');

        self::assertSame('grid.1.1', $applied['errors'][0]->path);
    }

    // ---- the contract -----------------------------------------------------

    public function test_a_field_describes_itself_as_plain_data(): void
    {
        $field = Field::string('status')
            ->oneOf(['active', 'suspended'])
            ->default('active')
            ->describedAs('Whether the customer may trade.');

        self::assertSame([
            'type' => 'string',
            'required' => false,
            'nullable' => false,
            'default' => 'active',
            'description' => 'Whether the customer may trade.',
            'oneOf' => ['active', 'suspended'],
        ], $field->describe());
    }

    /** A closure cannot be published, but the expectation it enforces can. */
    public function test_a_check_is_described_by_its_expectation(): void
    {
        $described = Field::string('email')
            ->check('an email address', static fn(mixed $v): bool => true)
            ->describe();

        self::assertSame(['an email address'], $described['checks']);
    }

    public function test_a_composite_describes_what_it_contains(): void
    {
        $address = Schema::of('address', Field::string('line1'));

        self::assertArrayHasKey('schema', Field::object('address', $address)->describe());
        self::assertArrayHasKey('element', Field::listOf('ids', Field::int('id'))->describe());
    }

    public function test_bounds_appear_in_the_description(): void
    {
        $described = Field::int('age')->range(0, 150)->describe();

        self::assertSame(0, $described['min']);
        self::assertSame(150, $described['max']);
    }
}
