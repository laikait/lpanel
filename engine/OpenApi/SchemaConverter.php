<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

use App\Engine\Schema\Field;
use App\Engine\Schema\FieldType;
use App\Engine\Schema\Schema;

/**
 * A Schema as JSON Schema, which is what OpenAPI 3.1 describes bodies with.
 *
 * Read from the declaration -- Schema::describe() and the Field accessors --
 * so the description cannot claim a rule the validator does not enforce, or
 * miss one it does. Every named Schema becomes one entry under
 * components.schemas, and every use of it a $ref, so a resource that appears
 * in ten operations is described once.
 *
 * **What cannot be said in JSON Schema is said in words.** A check() is a PHP
 * closure; its expectation ("an email address") is appended to the field's
 * description, which is the part a client can act on. A pattern() is passed
 * on only when it is a plain expression with no PHP-only flags; otherwise its
 * expectation is, again, in the description.
 */
final class SchemaConverter
{
    private const COMPONENTS = '#/components/schemas/';

    /** @var array<string, array<string, mixed>> name => JSON Schema */
    private array $components = [];

    /** @var array<string, array<string, mixed>> name => Schema::describe(), to tell a reuse from a clash */
    private array $declared = [];

    /**
     * A $ref to this schema, registering it the first time it is seen.
     *
     * @return array{'$ref': string}
     *
     * @throws OpenApiException when two different schemas share a name
     */
    public function reference(Schema $schema): array
    {
        $name = $schema->name();

        if (\preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw OpenApiException::invalidSchemaName($name);
        }

        $described = $schema->describe();

        if (isset($this->declared[$name])) {
            if ($this->declared[$name] !== $described) {
                throw OpenApiException::schemaNameClash($name);
            }

            return ['$ref' => self::COMPONENTS . $name];
        }

        $this->declared[$name] = $described;
        $this->components[$name] = $this->object($schema);

        return ['$ref' => self::COMPONENTS . $name];
    }

    /**
     * One field as JSON Schema: a property, or a parameter's schema.
     *
     * @return array<string, mixed>
     */
    public function field(Field $field): array
    {
        $description = $field->describe();

        $schema = match ($field->type) {
            FieldType::Int => ['type' => 'integer'],
            FieldType::Float => ['type' => 'number'],
            FieldType::String => ['type' => 'string'],
            FieldType::Bool => ['type' => 'boolean'],
            FieldType::Object => $field->schema !== null ? $this->reference($field->schema) : ['type' => 'object'],
            FieldType::List => ['type' => 'array', 'items' => $field->element !== null ? $this->field($field->element) : new \stdClass()],
        };

        [$minimum, $maximum] = match ($field->type) {
            FieldType::Int, FieldType::Float => ['minimum', 'maximum'],
            FieldType::String => ['minLength', 'maxLength'],
            FieldType::List => ['minItems', 'maxItems'],
            default => [null, null],
        };

        if ($minimum !== null && isset($description['min'])) {
            $schema[$minimum] = $description['min'];
        }

        if ($maximum !== null && isset($description['max'])) {
            $schema[$maximum] = $description['max'];
        }

        if (isset($description['oneOf']) && \is_array($description['oneOf'])) {
            $schema['enum'] = \array_values($description['oneOf']);
        }

        $words = \is_string($description['description'] ?? null) ? [$description['description']] : [];
        $regex = $field->regex();

        if ($regex !== null) {
            $pattern = self::pattern($regex);

            if ($pattern !== null) {
                $schema['pattern'] = $pattern;
            } elseif (\is_string($description['pattern'] ?? null)) {
                $words[] = 'Must be ' . $description['pattern'] . '.';
            }
        }

        foreach ((array) ($description['checks'] ?? []) as $check) {
            if (\is_string($check)) {
                $words[] = 'Must be ' . $check . '.';
            }
        }

        if ($words !== []) {
            $schema['description'] = \implode(' ', $words);
        }

        if ($field->hasDefault()) {
            $schema['default'] = $field->defaultValue();
        }

        return $field->isNullable() ? self::nullable($schema) : $schema;
    }

    /** @return array<string, array<string, mixed>> every schema referenced so far, by name, sorted */
    public function components(): array
    {
        $components = $this->components;
        \ksort($components);

        return $components;
    }

    /**
     * PCRE that means something else, or nothing, to an ECMA-262 validator:
     * POSIX classes, atomic groups and inline flags, possessive quantifiers,
     * Python-style named groups, and escapes ECMA does not have.
     */
    private const PCRE_ONLY = '/\[\[:|\(\?(?![:=!]|<[=!]|<[A-Za-z])|[*+?}]\+|\\\\[hHRKGQEXCvVeaz]|\\\\A|\\\\Z|\\\\x\{/';

    /**
     * A PHP regular expression as an ECMA one, or null when it cannot be
     * passed on faithfully -- a wrong pattern is worse than none, because a
     * client would then check different input than the server does.
     *
     * Delimiters go; the u flag is the only one JSON Schema already implies.
     * A leading \A and a trailing \z or \Z become ^ and $; anywhere else they
     * are refused with the rest of PCRE_ONLY. So is a pattern that escapes its
     * own delimiter, since the escape means nothing once the delimiter is gone.
     */
    public static function pattern(string $regex): ?string
    {
        if (\strlen($regex) < 2) {
            return null;
        }

        $open = $regex[0];
        $close = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$open] ?? $open;
        $end = \strrpos($regex, $close);

        if ($end === false || $end === 0 || \ctype_alnum($open) || $open === '\\' || \trim($open) === '') {
            return null;
        }

        if (\trim(\substr($regex, $end + 1), 'uD') !== '') {
            return null;
        }

        $pattern = \substr($regex, 1, $end - 1);

        if ($open !== '/' && \str_contains($pattern, '\\' . $open)) {
            return null;
        }

        // Anchors only where they are unambiguous: first and last, and not
        // the tail of an escaped backslash ("\\A" is a backslash and an A).
        if (\str_starts_with($pattern, '\A')) {
            $pattern = '^' . \substr($pattern, 2);
        }

        if (\preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\[zZ]$/', $pattern) === 1) {
            $pattern = \substr($pattern, 0, -2) . '$';
        }

        // Escaped backslashes and Unicode properties out of the way first:
        // "\\A" is not an anchor, and the "}+" of "\p{L}+" is not possessive.
        $escapes = \preg_replace(['/\\\\\\\\/', '/\\\\[pP]\{[^}]*\}/'], ['', 'p'], $pattern) ?? $pattern;

        return \preg_match(self::PCRE_ONLY, $escapes) === 1 ? null : $pattern;
    }

    /** @return array<string, mixed> */
    private function object(Schema $schema): array
    {
        $properties = [];
        $required = [];

        foreach ($schema->fields() as $name => $field) {
            $properties[$name] = $this->field($field);

            if ($field->isRequired()) {
                $required[] = $name;
            }
        }

        $object = ['type' => 'object'];

        if ($required !== []) {
            $object['required'] = $required;
        }

        $object['properties'] = $properties === [] ? new \stdClass() : $properties;

        return $object;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        if (\is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && !\in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        return $schema;
    }
}
