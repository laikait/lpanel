<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Support\Coercion;

/**
 * One field in a schema: its type, whether it has to be there, and what has to
 * be true of it.
 *
 * Declared with typed constructors rather than a string rule language:
 *
 *     Field::string('name')->length(1, 120)
 *     Field::int('age')->optional()->range(0, 150)
 *     Field::string('status')->oneOf(['active', 'suspended'])->default('active')
 *     Field::object('address', AddressSchema::schema())->nullable()
 *     Field::listOf('tags', Field::string('tag')->length(1, 40))->size(0, 10)
 *
 * "required|string|max:255" is shorter to type and worse in every other way:
 * nothing checks it, an editor cannot complete it, a typo is discovered by a
 * user, and the parser it needs is a second language inside the first. Here a
 * mistake is a PHP error, and a constraint that cannot apply -- a length on an
 * integer -- is refused while the schema is being built rather than on the
 * first request that happens to exercise the field.
 *
 * Fields are immutable. Every modifier returns a new Field, so one can be
 * declared once and reused across schemas without a distant edit changing it.
 *
 * **Required is the default.** A field you declared and forgot to mark is
 * required, so the mistake is a loud validation error rather than a value that
 * quietly is not there.
 */
final class Field
{
    private bool $required = true;

    private bool $nullable = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    /** Numeric bound, string length or list size, depending on the type. */
    private int|float|null $min = null;

    private int|float|null $max = null;

    private ?string $pattern = null;

    private ?string $patternExpectation = null;

    /** @var list<int|float|string|bool>|null */
    private ?array $choices = null;

    /** @var list<array{expectation: string, predicate: \Closure(mixed): bool}> */
    private array $checks = [];

    private ?string $description = null;

    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly ?Schema $schema = null,
        public readonly ?self $element = null,
    ) {}

    // ---- declaration ------------------------------------------------------

    public static function int(string $name): self
    {
        return new self($name, FieldType::Int);
    }

    public static function float(string $name): self
    {
        return new self($name, FieldType::Float);
    }

    public static function string(string $name): self
    {
        return new self($name, FieldType::String);
    }

    public static function bool(string $name): self
    {
        return new self($name, FieldType::Bool);
    }

    /** A nested shape. */
    public static function object(string $name, Schema $schema): self
    {
        return new self($name, FieldType::Object, schema: $schema);
    }

    /** A sequential list, every element of which is described by one Field. */
    public static function listOf(string $name, self $element): self
    {
        return new self($name, FieldType::List, element: $element);
    }

    /** A list of nested shapes. Shorthand for listOf() around object(). */
    public static function collection(string $name, Schema $schema): self
    {
        return self::listOf($name, self::object($name, $schema));
    }

    // ---- presence ---------------------------------------------------------

    public function required(): self
    {
        $clone = clone $this;
        $clone->required = true;

        return $clone;
    }

    public function optional(): self
    {
        $clone = clone $this;
        $clone->required = false;

        return $clone;
    }

    /**
     * Null is a distinct question from absence.
     *
     * A required nullable field must be present and may be null; an optional
     * non-nullable field may be absent but must not be null if it is there.
     */
    public function nullable(bool $nullable = true): self
    {
        $clone = clone $this;
        $clone->nullable = $nullable;

        return $clone;
    }

    /**
     * A value used when the field is absent, which also makes it optional.
     *
     * The default is checked against this field's own rules immediately, so a
     * default that could never be valid is a declaration error rather than a
     * surprise on the first request that omits the field.
     */
    public function default(mixed $value): self
    {
        $clone = clone $this;
        $clone->required = false;
        $clone->hasDefault = true;
        $clone->default = $value;

        $applied = $clone->apply($value, $this->name);

        if ($applied['errors'] !== []) {
            throw SchemaException::invalidInput(
                $this->name . ' (default value)',
                ValidationResult::of($applied['errors']),
            );
        }

        return $clone;
    }

    // ---- constraints ------------------------------------------------------

    /** A numeric bound. Either end may be null to leave it open. */
    public function range(int|float|null $min, int|float|null $max): self
    {
        if (!$this->type->isNumeric()) {
            throw SchemaException::constraintNotApplicable(
                $this->name,
                $this->type,
                'range',
                'Use length() for a string or size() for a list.',
            );
        }

        return $this->bounded($min, $max, 'range');
    }

    /** A string length, in characters. */
    public function length(?int $min, ?int $max): self
    {
        if ($this->type !== FieldType::String) {
            throw SchemaException::constraintNotApplicable(
                $this->name,
                $this->type,
                'length',
                'Use range() for a number or size() for a list.',
            );
        }

        return $this->bounded($min, $max, 'length');
    }

    /** How many elements a list may hold. */
    public function size(?int $min, ?int $max): self
    {
        if ($this->type !== FieldType::List) {
            throw SchemaException::constraintNotApplicable(
                $this->name,
                $this->type,
                'size',
                'Use range() for a number or length() for a string.',
            );
        }

        return $this->bounded($min, $max, 'size');
    }

    /**
     * A regular expression the whole value must match.
     *
     * The expectation is what a client is told. "must be a postcode" is worth
     * more to them than the pattern that decides it.
     */
    public function pattern(string $regex, ?string $expectation = null): self
    {
        if ($this->type !== FieldType::String) {
            throw SchemaException::constraintNotApplicable(
                $this->name,
                $this->type,
                'pattern',
                'A pattern only means something for a string.',
            );
        }

        $clone = clone $this;
        $clone->pattern = $regex;
        $clone->patternExpectation = $expectation;

        return $clone;
    }

    /**
     * A closed set of permitted values.
     *
     * @param list<int|float|string|bool> $values
     */
    public function oneOf(array $values): self
    {
        if (!$this->type->isScalar()) {
            throw SchemaException::constraintNotApplicable(
                $this->name,
                $this->type,
                'oneOf',
                'A choice only means something for a scalar.',
            );
        }

        if ($values === []) {
            throw SchemaException::emptyChoice($this->name);
        }

        $clone = clone $this;
        $clone->choices = $values;

        return $clone;
    }

    /**
     * Anything else, as a predicate over the converted value.
     *
     * This is why the engine ships no Email, Url, Uuid or Date rule. It would
     * never be a complete set, every entry would be an opinion someone
     * disagrees with, and each one is a line here:
     *
     *     Field::string('email')->check(
     *         'an email address',
     *         static fn (string $v): bool => \filter_var($v, \FILTER_VALIDATE_EMAIL) !== false,
     *     );
     *
     * The predicate runs only after type conversion and only on a non-null
     * value, so it never has to defend itself against the wrong type.
     *
     * @param \Closure(mixed): bool $predicate
     */
    public function check(string $expectation, \Closure $predicate): self
    {
        $clone = clone $this;
        $clone->checks = [...$this->checks, ['expectation' => $expectation, 'predicate' => $predicate]];

        return $clone;
    }

    /** Documentation, carried into describe(). */
    public function describedAs(string $description): self
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    // ---- reading the declaration ------------------------------------------

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /**
     * The regular expression given to pattern(), delimiters and all.
     *
     * describe() carries the expectation instead, which is what a client is
     * told; a description format that has a pattern of its own -- OpenAPI's
     * JSON Schema -- needs the expression itself.
     */
    public function regex(): ?string
    {
        return $this->pattern;
    }

    // ---- applying it ------------------------------------------------------

    /**
     * Convert a value and report everything wrong with it.
     *
     * Returns the converted value alongside the errors, because doing it in one
     * pass is the only way the conversion the validator accepted is the same
     * one the caller receives. A two-pass "validate then convert" drifts the
     * moment one side gains a rule.
     *
     * @return array{value: mixed, errors: list<ValidationError>}
     */
    public function apply(mixed $value, string $path): array
    {
        if ($value === null) {
            return $this->nullable
                ? ['value' => null, 'errors' => []]
                : ['value' => null, 'errors' => [new ValidationError($path, 'must not be null')]];
        }

        $converted = $this->convert($value);

        if ($converted === null) {
            return [
                'value' => $value,
                'errors' => [new ValidationError($path, 'must be ' . $this->type->article())],
            ];
        }

        return match ($this->type) {
            FieldType::Object => $this->applyObject($converted['value'], $path),
            FieldType::List => $this->applyList($converted['value'], $path),
            default => [
                'value' => $converted['value'],
                'errors' => $this->constraintErrors($converted['value'], $path),
            ],
        };
    }

    /**
     * Convert to the declared type, or null when there is no one reading.
     *
     * The result is wrapped because a successful conversion can itself be a
     * false or a 0, which an unwrapped null-on-failure could not tell apart.
     *
     * @return array{value: mixed}|null
     */
    private function convert(mixed $value): ?array
    {
        $converted = match ($this->type) {
            FieldType::Int => Coercion::toInt($value),
            FieldType::Float => Coercion::toFloat($value),
            FieldType::String => Coercion::toString($value),
            FieldType::Bool => Coercion::toBool($value),
            FieldType::Object => \is_array($value) ? $value : null,
            FieldType::List => \is_array($value) && \array_is_list($value) ? $value : null,
        };

        return $converted === null ? null : ['value' => $converted];
    }

    /** @return array{value: mixed, errors: list<ValidationError>} */
    private function applyObject(mixed $value, string $path): array
    {
        /** @var array<string, mixed> $value */
        $schema = $this->schema ?? throw SchemaException::unknownField($this->name, 'schema', '');
        $shaped = $schema->shape($value);

        return [
            'value' => $shaped['value'],
            'errors' => $shaped['result']->under($path)->errors(),
        ];
    }

    /** @return array{value: mixed, errors: list<ValidationError>} */
    private function applyList(mixed $value, string $path): array
    {
        /** @var list<mixed> $value */
        $element = $this->element ?? throw SchemaException::unknownField($this->name, 'element', '');
        $values = [];
        $errors = $this->constraintErrors($value, $path);

        foreach ($value as $index => $item) {
            $applied = $element->apply($item, $path . '.' . $index);
            $values[] = $applied['value'];
            $errors = [...$errors, ...$applied['errors']];
        }

        return ['value' => $values, 'errors' => $errors];
    }

    /**
     * The constraints, checked against an already converted, non-null value.
     *
     * @return list<ValidationError>
     */
    private function constraintErrors(mixed $value, string $path): array
    {
        $errors = [];
        $measure = $this->measure($value);

        if ($measure !== null && $this->min !== null && $measure < $this->min) {
            $errors[] = new ValidationError($path, $this->boundMessage('at least', $this->min));
        }

        if ($measure !== null && $this->max !== null && $measure > $this->max) {
            $errors[] = new ValidationError($path, $this->boundMessage('at most', $this->max));
        }

        if ($this->choices !== null && !\in_array($value, $this->choices, true)) {
            $errors[] = new ValidationError($path, \sprintf(
                'must be one of: %s',
                \implode(', ', \array_map(
                    static fn(int|float|string|bool $choice): string => \is_bool($choice)
                        ? ($choice ? 'true' : 'false')
                        : (string) $choice,
                    $this->choices,
                )),
            ));
        }

        if ($this->pattern !== null && (!\is_string($value) || \preg_match($this->pattern, $value) !== 1)) {
            $errors[] = new ValidationError($path, $this->patternExpectation !== null
                ? 'must be ' . $this->patternExpectation
                : 'must match ' . $this->pattern);
        }

        foreach ($this->checks as $check) {
            if (($check['predicate'])($value) !== true) {
                $errors[] = new ValidationError($path, 'must be ' . $check['expectation']);
            }
        }

        return $errors;
    }

    /** What min and max are compared against, which depends on the type. */
    private function measure(mixed $value): int|float|null
    {
        return match ($this->type) {
            FieldType::Int, FieldType::Float => \is_int($value) || \is_float($value) ? $value : null,
            FieldType::String => \is_string($value) ? \mb_strlen($value) : null,
            FieldType::List => \is_array($value) ? \count($value) : null,
            default => null,
        };
    }

    private function boundMessage(string $direction, int|float $bound): string
    {
        return match ($this->type) {
            FieldType::String => \sprintf('must be %s %s long', $direction, self::plural($bound, 'character')),
            FieldType::List => \sprintf('must have %s %s', $direction, self::plural($bound, 'item')),
            default => \sprintf('must be %s %s', $direction, $bound),
        };
    }

    private static function plural(int|float $count, string $noun): string
    {
        return \sprintf('%s %s%s', $count, $noun, $count === 1 || $count === 1.0 ? '' : 's');
    }

    private function bounded(int|float|null $min, int|float|null $max, string $constraint): self
    {
        if ($min !== null && $max !== null && $min > $max) {
            throw SchemaException::invalidBounds($this->name, $constraint);
        }

        $clone = clone $this;
        $clone->min = $min;
        $clone->max = $max;

        return $clone;
    }

    // ---- the contract -----------------------------------------------------

    /**
     * This field as plain data, for documentation and API contracts.
     *
     * Closures passed to check() appear as their expectation text, because that
     * is the part anyone outside the code can act on.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $description = [
            'type' => $this->type->value,
            'required' => $this->required,
            'nullable' => $this->nullable,
        ];

        if ($this->hasDefault) {
            $description['default'] = $this->default;
        }

        if ($this->description !== null) {
            $description['description'] = $this->description;
        }

        if ($this->min !== null) {
            $description['min'] = $this->min;
        }

        if ($this->max !== null) {
            $description['max'] = $this->max;
        }

        if ($this->choices !== null) {
            $description['oneOf'] = $this->choices;
        }

        if ($this->pattern !== null) {
            $description['pattern'] = $this->patternExpectation ?? $this->pattern;
        }

        if ($this->checks !== []) {
            $description['checks'] = \array_map(
                static fn(array $check): string => $check['expectation'],
                $this->checks,
            );
        }

        if ($this->schema !== null) {
            $description['schema'] = $this->schema->describe();
        }

        if ($this->element !== null) {
            $description['element'] = $this->element->describe();
        }

        return $description;
    }
}
