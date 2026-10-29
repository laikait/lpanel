<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Validation;

use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\Validation\SchemaValidator;
use App\Engine\MCP\Validation\ValidationException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaValidatorTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'status' => ['type' => 'string', 'enum' => ['open', 'paid']],
            'note' => ['type' => 'string', 'maxLength' => 5, 'default' => ''],
            'code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
            'address' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string', 'minLength' => 1]],
                'required' => ['city'],
            ],
            'amount' => ['type' => 'number', 'maximum' => 100],
            'paid' => ['type' => 'boolean'],
        ],
        'required' => ['id'],
    ];

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $schema
     */
    private function invalid(array $arguments, array $schema = self::SCHEMA): ValidationException
    {
        try {
            (new SchemaValidator())->validate('invoice.update', $schema, $arguments);
            self::fail('invalid arguments were accepted');
        } catch (ValidationException $e) {
            self::assertSame(McpErrorCode::InvalidParams, $e->error()->code);

            return $e;
        }
    }

    /** @return list<array{path: string, message: string}> */
    private static function errors(ValidationException $e): array
    {
        $errors = $e->error()->data['errors'] ?? [];
        self::assertIsArray($errors);

        /** @var list<array{path: string, message: string}> $errors */
        return $errors;
    }

    public function test_valid_arguments_come_back_normalized(): void
    {
        $normalized = (new SchemaValidator())->validate('invoice.update', self::SCHEMA, [
            'id' => 7.0,
            'status' => 'paid',
            'code' => 'EUR',
            'tags' => ['a', 'b'],
            'address' => ['city' => 'Dhaka'],
            'amount' => 99.5,
            'paid' => true,
        ]);

        self::assertSame(7, $normalized['id'], 'an integer written as 7.0 is an integer');
        self::assertSame('', $normalized['note'], 'a default fills a missing optional property');
        self::assertSame(['city' => 'Dhaka'], $normalized['address']);
    }

    public function test_a_missing_required_property_is_reported_by_path(): void
    {
        self::assertSame([['path' => 'id', 'message' => 'is required']], self::errors($this->invalid([])));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function wrongValues(): iterable
    {
        yield 'a string for an integer' => [['id' => '7'], 'id', 'must be an integer'];
        yield 'a fraction for an integer' => [['id' => 7.5], 'id', 'must be an integer'];
        yield 'below the minimum' => [['id' => 0], 'id', 'must be at least 1'];
        yield 'not in the enum' => [['id' => 1, 'status' => 'void'], 'status', 'is not one of the allowed values'];
        yield 'too long' => [['id' => 1, 'note' => 'far too long'], 'note', 'must be at most 5 characters'];
        yield 'against the pattern' => [['id' => 1, 'code' => 'eur'], 'code', 'does not match the required pattern'];
        yield 'too many items' => [['id' => 1, 'tags' => ['a', 'b', 'c']], 'tags', 'must have at most 2 items'];
        yield 'a wrong item' => [['id' => 1, 'tags' => ['a', 5]], 'tags[1]', 'must be a string'];
        yield 'a nested requirement' => [['id' => 1, 'address' => []], 'address.city', 'is required'];
        yield 'a nested bound' => [['id' => 1, 'address' => ['city' => '']], 'address.city', 'must be at least 1 characters'];
        yield 'an object where a list goes' => [['id' => 1, 'tags' => ['x' => 'a']], 'tags', 'must be an array'];
        yield 'a string for a boolean' => [['id' => 1, 'paid' => 'yes'], 'paid', 'must be a boolean'];
        yield 'above the maximum' => [['id' => 1, 'amount' => 100.01], 'amount', 'must be at most 100'];
        yield 'a property the schema does not list' => [['id' => 1, 'is_admin' => true], 'is_admin', 'is not an accepted property'];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('wrongValues')]
    public function test_a_wrong_value_is_reported_by_path_without_the_value(array $arguments, string $path, string $message): void
    {
        $errors = self::errors($this->invalid($arguments));

        self::assertContains(['path' => $path, 'message' => $message], $errors);
        self::assertStringNotContainsString('far too long', (string) \json_encode($errors));
    }

    public function test_additional_properties_are_allowed_only_when_the_schema_says_so(): void
    {
        $normalized = (new SchemaValidator())->validate('x', ['type' => 'object', 'additionalProperties' => true], ['anything' => 1]);

        self::assertSame(['anything' => 1], $normalized);
    }

    public function test_every_problem_is_reported_up_to_a_limit(): void
    {
        $arguments = [];

        for ($i = 0; $i < 30; ++$i) {
            $arguments['extra' . $i] = $i;
        }

        self::assertCount(SchemaValidator::MAX_ERRORS, self::errors($this->invalid($arguments)));
    }

    public function test_oversized_arguments_are_refused_before_they_are_walked(): void
    {
        $e = $this->invalid(['id' => 1, 'note' => \str_repeat('x', SchemaValidator::MAX_BYTES)]);

        self::assertStringContainsString('at most', $e->error()->message);
    }

    public function test_absurd_nesting_is_refused(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => true];
        $deep = ['leaf' => 1];

        for ($i = 0; $i < SchemaValidator::MAX_DEPTH + 2; ++$i) {
            $deep = ['n' => $deep];
            $schema = ['type' => 'object', 'properties' => ['n' => $schema]];
        }

        self::assertContains('is nested too deeply', \array_column(self::errors($this->invalid($deep, $schema)), 'message'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsupportedSchemas(): iterable
    {
        yield 'format' => [['type' => 'object', 'properties' => ['email' => ['type' => 'string', 'format' => 'email']]]];
        yield 'oneOf' => [['type' => 'object', 'oneOf' => []]];
        yield 'a reference' => [['type' => 'object', 'properties' => ['a' => ['$ref' => '#/defs/a']]]];
        yield 'an unknown type' => [['type' => 'object', 'properties' => ['a' => ['type' => 'date']]]];
        yield 'a broken pattern' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'pattern' => '[unclosed']]]];
    }

    /**
     * A keyword that is not enforced would look like protection and not be
     * any, so the schema is refused instead.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('unsupportedSchemas')]
    public function test_a_schema_using_what_is_not_enforced_is_a_contract_error(array $schema): void
    {
        $this->expectException(McpContractException::class);

        (new SchemaValidator())->validate('x', $schema, ['email' => 'a@b.c', 'a' => 'x']);
    }
}
