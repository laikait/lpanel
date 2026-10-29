<?php

declare(strict_types=1);

namespace App\Tests\Unit\OpenApi;

use App\Engine\OpenApi\OpenApiException;
use App\Engine\OpenApi\SchemaConverter;
use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Schema declarations as JSON Schema: every constraint the validator enforces
 * is in the description, and nothing it does not enforce.
 */
final class SchemaConverterTest extends TestCase
{
    public function test_each_type_becomes_its_json_schema_type(): void
    {
        $converter = new SchemaConverter();

        self::assertSame(['type' => 'integer'], $converter->field(Field::int('a')));
        self::assertSame(['type' => 'number'], $converter->field(Field::float('a')));
        self::assertSame(['type' => 'string'], $converter->field(Field::string('a')));
        self::assertSame(['type' => 'boolean'], $converter->field(Field::bool('a')));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $converter->field(Field::listOf('a', Field::string('a'))));
    }

    public function test_bounds_use_the_keyword_for_the_type(): void
    {
        $converter = new SchemaConverter();

        self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 9], $converter->field(Field::int('a')->range(1, 9)));
        self::assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 120], $converter->field(Field::string('a')->length(1, 120)));
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 0, 'maxItems' => 10],
            $converter->field(Field::listOf('a', Field::int('a'))->size(0, 10)),
        );
        self::assertSame(['type' => 'integer', 'minimum' => 1], $converter->field(Field::int('a')->range(1, null)));
    }

    public function test_choices_defaults_and_descriptions_carry_over(): void
    {
        $field = Field::string('status')->oneOf(['active', 'suspended'])->default('active')->describedAs('Where it stands.');

        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'suspended'], 'description' => 'Where it stands.', 'default' => 'active'],
            (new SchemaConverter())->field($field),
        );
    }

    public function test_nullable_adds_null_to_the_type_and_the_choices(): void
    {
        $converter = new SchemaConverter();

        self::assertSame(['type' => ['string', 'null']], $converter->field(Field::string('a')->nullable()));
        self::assertSame(
            ['type' => ['string', 'null'], 'enum' => ['x', null]],
            $converter->field(Field::string('a')->oneOf(['x'])->nullable()),
        );
    }

    public function test_a_plain_pattern_is_passed_on_without_its_delimiters(): void
    {
        $schema = (new SchemaConverter())->field(Field::string('code')->pattern('/\A[A-Z]{3}\z/u', 'three capitals'));

        self::assertSame('^[A-Z]{3}$', $schema['pattern']);
        self::assertArrayNotHasKey('description', $schema);
    }

    public function test_a_pattern_with_php_only_flags_is_described_in_words(): void
    {
        $schema = (new SchemaConverter())->field(Field::string('code')->pattern('/^[a-z]+$/i', 'letters'));

        self::assertArrayNotHasKey('pattern', $schema);
        self::assertSame('Must be letters.', $schema['description']);
    }

    public function test_a_check_closure_is_described_by_its_expectation(): void
    {
        $field = Field::string('email')->describedAs('Where mail goes.')->check('an email address', static fn(mixed $v): bool => true);

        self::assertSame(
            ['type' => 'string', 'description' => 'Where mail goes. Must be an email address.'],
            (new SchemaConverter())->field($field),
        );
    }

    public function test_a_schema_is_one_component_referenced_everywhere(): void
    {
        $converter = new SchemaConverter();
        $address = Schema::of('address', Field::string('city'), Field::string('zip')->optional());
        $person = Schema::of('person', Field::string('name'), Field::object('home', $address), Field::collection('past', $address));

        self::assertSame(['$ref' => '#/components/schemas/person'], $converter->reference($person));
        $once = $converter->components();

        // A second use is the same reference, not a second component.
        $converter->reference($person);
        $components = $converter->components();
        self::assertSame($once, $components);

        self::assertSame(['address', 'person'], \array_keys($components));
        self::assertSame(['city'], $components['address']['required']);
        self::assertSame(['name', 'home', 'past'], $components['person']['required']);
        self::assertSame(['$ref' => '#/components/schemas/address'], $components['person']['properties']['home']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/address']], $components['person']['properties']['past']);
    }

    public function test_a_nullable_reference_allows_null_alongside_it(): void
    {
        $converter = new SchemaConverter();
        $field = Field::object('home', Schema::of('address', Field::string('city')))->nullable();

        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/address'], ['type' => 'null']]],
            $converter->field($field),
        );
    }

    public function test_two_different_schemas_with_one_name_are_refused(): void
    {
        $converter = new SchemaConverter();
        $converter->reference(Schema::of('thing', Field::string('a')));

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('"thing"');

        $converter->reference(Schema::of('thing', Field::int('a')));
    }

    public function test_a_schema_name_openapi_cannot_refer_to_is_refused(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('"Billing/Invoice"');

        (new SchemaConverter())->reference(Schema::of('Billing/Invoice', Field::int('id')));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function patterns(): iterable
    {
        yield 'delimiters' => ['#^a$#', '^a$'];
        yield 'brace delimiters' => ['{^a$}', '^a$'];
        yield 'whole-string anchors' => ['/\A[A-Z]{3}\z/u', '^[A-Z]{3}$'];
        yield 'groups ECMA has' => ['/^(?:a|b)(?=c)(?<year>\d{4})$/', '^(?:a|b)(?=c)(?<year>\d{4})$'];
        yield 'a unicode property' => ['/^\p{L}+$/u', '^\p{L}+$'];
        yield 'an escaped backslash before A' => ['/^a\\\\A$/', '^a\\\\A$'];
        yield 'an escaped backslash before z' => ['/^a\\\\z/', '^a\\\\z'];
        yield 'a flag' => ['/^a$/x', null];
        yield 'no delimiter' => ['a', null];
        yield 'a letter as delimiter' => ['abc', null];
        yield 'a POSIX class' => ['/^[[:alpha:]]+$/', null];
        yield 'an escaped delimiter' => ['#^a\#b$#', null];
        yield 'a possessive quantifier' => ['/^a++$/', null];
        yield 'possessive after a count' => ['/^a{2}+$/', null];
        yield 'an atomic group' => ['/^(?>a)$/', null];
        yield 'an inline flag' => ['/^(?i)a$/', null];
        yield 'a PCRE escape' => ['/^\h$/', null];
        yield 'a braced hex escape' => ['/^\x{41}$/', null];
        yield 'an anchor in the middle' => ['/x\Ay/', null];
    }

    #[DataProvider('patterns')]
    public function test_a_pattern_is_carried_only_when_it_means_the_same(string $regex, ?string $expected): void
    {
        self::assertSame($expected, SchemaConverter::pattern($regex));
    }
}
