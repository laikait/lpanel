<?php

declare(strict_types=1);

namespace App\Engine\MCP\Validation;

use App\Engine\MCP\McpContractException;

/**
 * Checks arguments against a tool's JSON Schema, and returns them normalized.
 *
 * **A deliberate subset, and it fails closed.** Supported keywords:
 *
 *     type                 object, string, integer, number, boolean, array, null
 *     properties, required, additionalProperties (boolean)
 *     items, minItems, maxItems
 *     enum, const
 *     minLength, maxLength, pattern
 *     minimum, maximum
 *     default, description, title
 *
 * A schema that uses anything else -- `format`, `oneOf`, `$ref` -- is a
 * contract error, not ignored: a schema that looks like it restricts an input
 * and silently does not is worse than one that refuses to load.
 *
 * **Stricter than JSON Schema's defaults where it matters:** a property the
 * schema does not list is refused unless `additionalProperties` is true, and a
 * value with a type is always checked for it.
 *
 * **Normalized:** a missing optional property with a `default` gets it, and an
 * integer written as `5.0` becomes `5`.
 *
 * **Bounded:** at most MAX_BYTES of encoded arguments and MAX_DEPTH levels,
 * and at most MAX_ERRORS problems reported, each as a path and a reason.
 */
final class SchemaValidator
{
    public const MAX_BYTES = 262_144;

    public const MAX_DEPTH = 32;

    public const MAX_ERRORS = 10;

    private const KEYWORDS = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'maxItems',
        'enum', 'const', 'minLength', 'maxLength', 'pattern', 'minimum', 'maximum',
        'default', 'description', 'title',
    ];

    private const TYPES = ['object', 'string', 'integer', 'number', 'boolean', 'array', 'null'];

    /** @var list<array{path: string, message: string}> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $schema    an object schema
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException  when the arguments do not match
     * @throws McpContractException when the schema uses what this validator does not support
     */
    public function validate(string $capability, array $schema, array $arguments): array
    {
        if (\strlen((string) \json_encode($arguments)) > self::MAX_BYTES) {
            throw ValidationException::tooLarge($capability, self::MAX_BYTES);
        }

        $this->errors = [];
        $normalized = $this->check($capability, $schema, $arguments, '', 0);
        $errors = $this->collected();

        if ($errors !== []) {
            throw ValidationException::invalid($capability, $errors);
        }

        /** @var array<string, mixed> $normalized */
        return $normalized;
    }

    /** @param array<mixed> $schema */
    private function check(string $capability, array $schema, mixed $value, string $path, int $depth): mixed
    {
        foreach (\array_keys($schema) as $keyword) {
            if (!\in_array($keyword, self::KEYWORDS, true)) {
                throw McpContractException::invalidInputSchema($capability, \sprintf('"%s" at %s is not supported; see SchemaValidator', $keyword, $path === '' ? 'the root' : $path));
            }
        }

        if ($depth > self::MAX_DEPTH) {
            $this->fail($path, 'is nested too deeply');

            return $value;
        }

        if (isset($schema['type'])) {
            if (!\is_string($schema['type']) || !\in_array($schema['type'], self::TYPES, true)) {
                throw McpContractException::invalidInputSchema($capability, 'a type is one of ' . \implode(', ', self::TYPES));
            }

            $value = $this->type($schema['type'], $value, $path);

            if ($value === null && $schema['type'] !== 'null') {
                return null;
            }
        }

        if (\array_key_exists('const', $schema) && $value !== $schema['const']) {
            $this->fail($path, 'is not the one allowed value');
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $this->fail($path, 'is not one of the allowed values');
        }

        if (\is_string($value)) {
            $this->string($capability, $schema, $value, $path);
        } elseif (\is_int($value) || \is_float($value)) {
            $this->number($schema, $value, $path);
        } elseif (\is_array($value) && ($schema['type'] ?? null) === 'array') {
            $value = $this->list($capability, $schema, $value, $path, $depth);
        } elseif (\is_array($value) && ($schema['type'] ?? null) === 'object') {
            $value = $this->object($capability, $schema, $value, $path, $depth);
        }

        return $value;
    }

    /** The value, if it is of the type -- normalized; null, with an error recorded, if not. */
    private function type(string $type, mixed $value, string $path): mixed
    {
        $ok = match ($type) {
            'object' => \is_array($value) && ($value === [] || !\array_is_list($value)),
            'array' => \is_array($value) && \array_is_list($value),
            'string' => \is_string($value),
            'integer' => \is_int($value) || (\is_float($value) && \floor($value) === $value && \abs($value) < 2 ** 53),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => $value === null,
            default => false,
        };

        if (!$ok) {
            $this->fail($path, 'must be ' . ($type === 'integer' || $type === 'array' || $type === 'object' ? 'an ' : 'a ') . $type);

            return null;
        }

        return $type === 'integer' && \is_float($value) ? (int) $value : $value;
    }

    /** @param array<mixed> $schema */
    private function string(string $capability, array $schema, string $value, string $path): void
    {
        $length = \mb_strlen($value, 'UTF-8');

        if (\is_int($schema['minLength'] ?? null) && $length < $schema['minLength']) {
            $this->fail($path, \sprintf('must be at least %d characters', $schema['minLength']));
        }

        if (\is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']) {
            $this->fail($path, \sprintf('must be at most %d characters', $schema['maxLength']));
        }

        if (isset($schema['pattern'])) {
            if (!\is_string($schema['pattern'])) {
                throw McpContractException::invalidInputSchema($capability, 'a pattern is a string');
            }

            $matched = @\preg_match('~' . \str_replace('~', '\~', $schema['pattern']) . '~u', $value);

            if ($matched === false) {
                throw McpContractException::invalidInputSchema($capability, 'a pattern is not a valid regular expression');
            }

            if ($matched !== 1) {
                $this->fail($path, 'does not match the required pattern');
            }
        }
    }

    /** @param array<mixed> $schema */
    private function number(array $schema, int|float $value, string $path): void
    {
        if ((\is_int($schema['minimum'] ?? null) || \is_float($schema['minimum'] ?? null)) && $value < $schema['minimum']) {
            $this->fail($path, 'must be at least ' . $schema['minimum']);
        }

        if ((\is_int($schema['maximum'] ?? null) || \is_float($schema['maximum'] ?? null)) && $value > $schema['maximum']) {
            $this->fail($path, 'must be at most ' . $schema['maximum']);
        }
    }

    /**
     * @param array<mixed> $schema
     * @param array<mixed> $value  a list, as type() has already checked
     *
     * @return array<mixed>
     */
    private function list(string $capability, array $schema, array $value, string $path, int $depth): array
    {
        if (\is_int($schema['minItems'] ?? null) && \count($value) < $schema['minItems']) {
            $this->fail($path, \sprintf('must have at least %d items', $schema['minItems']));
        }

        if (\is_int($schema['maxItems'] ?? null) && \count($value) > $schema['maxItems']) {
            $this->fail($path, \sprintf('must have at most %d items', $schema['maxItems']));
        }

        if (isset($schema['items']) && \is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $value[$index] = $this->check($capability, $schema['items'], $item, $path . '[' . $index . ']', $depth + 1);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $schema
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function object(string $capability, array $schema, array $value, string $path, int $depth): array
    {
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (\is_string($name) && !\array_key_exists($name, $value)) {
                $this->fail(self::join($path, $name), 'is required');
            }
        }

        foreach ($value as $name => $item) {
            $name = (string) $name;

            if (!isset($properties[$name])) {
                if (($schema['additionalProperties'] ?? false) !== true) {
                    $this->fail(self::join($path, \substr($name, 0, 64)), 'is not an accepted property');
                }

                continue;
            }

            if (!\is_array($properties[$name])) {
                throw McpContractException::invalidInputSchema($capability, 'a property schema is an object');
            }

            $value[$name] = $this->check($capability, $properties[$name], $item, self::join($path, $name), $depth + 1);
        }

        foreach ($properties as $name => $property) {
            if (\is_array($property) && \array_key_exists('default', $property) && !\array_key_exists($name, $value)) {
                $value[$name] = $property['default'];
            }
        }

        return $value;
    }

    /** @return list<array{path: string, message: string}> what check() found */
    private function collected(): array
    {
        return $this->errors;
    }

    private function fail(string $path, string $message): void
    {
        if (\count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = ['path' => $path === '' ? '(arguments)' : $path, 'message' => $message];
        }
    }

    private static function join(string $path, string $name): string
    {
        return $path === '' ? $name : $path . '.' . $name;
    }
}
