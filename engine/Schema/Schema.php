<?php

declare(strict_types=1);

namespace App\Engine\Schema;

/**
 * The shape of a piece of structured data.
 *
 * A schema describes data, not a class and not a table. The same mechanism
 * covers a request body, a response representation, a configuration block and
 * an API contract, because all four are the same question: which fields, of
 * what type, under what rules.
 *
 *     final class CustomerSchema
 *     {
 *         public static function input(): Schema
 *         {
 *             return Schema::of('customer.input',
 *                 Field::string('name')->length(1, 120),
 *                 Field::string('email')->check('an email address', ...),
 *                 Field::bool('active')->default(true),
 *             );
 *         }
 *     }
 *
 * Note what that is not. It is not a base class anybody extends, it is not
 * resolved out of a handler signature, and it knows nothing about HTTP -- there
 * is no Request here, no 422, and no automatic anything. A handler calls the
 * schema where it wants to, and decides itself what a failure means. That is
 * the difference between a schema and a form-request object, and it is enforced
 * by an architecture test rather than left to good intentions.
 *
 * Three operations, and the difference between the last two is who is at fault:
 *
 *   validate()     collects everything wrong with data, and throws nothing.
 *   deserialize()  data arriving from outside. Converts, applies defaults and
 *                  drops unknown keys. A failure is the sender's fault.
 *   serialize()    data heading outside. Same shaping, but a failure means the
 *                  application broke its own promise, which is a bug and must
 *                  never be reported to a client as their mistake.
 */
final class Schema
{
    /** @param array<string, Field> $fields */
    private function __construct(
        private readonly string $name,
        private readonly array $fields,
    ) {}

    public static function of(string $name, Field ...$fields): self
    {
        $declared = [];

        foreach ($fields as $field) {
            if (isset($declared[$field->name])) {
                throw SchemaException::duplicateField($name, $field->name);
            }

            $declared[$field->name] = $field;
        }

        return new self($name, $declared);
    }

    /**
     * A new schema with more fields, for deriving one contract from another --
     * a resource representation from the input that creates it, say. Redeclared
     * names replace rather than collide, because that is the point.
     */
    public function with(Field ...$fields): self
    {
        $declared = $this->fields;

        foreach ($fields as $field) {
            $declared[$field->name] = $field;
        }

        return new self($this->name, $declared);
    }

    /** A new schema under a different name, otherwise identical. */
    public function named(string $name): self
    {
        return new self($name, $this->fields);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return \array_keys($this->fields);
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function field(string $field): Field
    {
        return $this->fields[$field] ?? throw SchemaException::unknownField(
            $this->name,
            $field,
            \implode(', ', $this->fieldNames()),
        );
    }

    // ---- using it ---------------------------------------------------------

    /**
     * Everything wrong with this data, and nothing thrown.
     *
     * Validation does not stop at the first problem. Four bad fields are
     * reported as four bad fields, not as one round trip each.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ValidationResult
    {
        return $this->shape($data)['result'];
    }

    /**
     * Data from outside, converted and cleaned.
     *
     * Unknown keys are dropped rather than rejected: a client sending a field
     * this version does not know about is not an error, and the result contains
     * only what the contract declared, so nothing unexpected can travel onwards
     * by accident.
     *
     * The result is an array, not an object. That is what keeps a schema from
     * becoming a model factory: hand it to whatever you like --
     * ModelManager::hydrate(), a repository, a queue payload -- and neither side
     * needs to know about the other.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function deserialize(array $data): array
    {
        $shaped = $this->shape($data);

        if (!$shaped['result']->isValid()) {
            throw SchemaException::invalidInput($this->name, $shaped['result']);
        }

        return $shaped['value'];
    }

    /**
     * Data on its way out, shaped to the contract.
     *
     * Mechanically the same as deserialize(), and deliberately a separate
     * method: a failure here is the application failing to produce the shape it
     * advertises, so the exception says so. Reporting it as a client error
     * would send somebody looking for a bug at the other end of the wire.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function serialize(array $data): array
    {
        $shaped = $this->shape($data);

        if (!$shaped['result']->isValid()) {
            throw SchemaException::doesNotMatchContract($this->name, $shaped['result']);
        }

        return $shaped['value'];
    }

    /**
     * Convert and validate in one pass.
     *
     * Internal to the schema layer: Field calls it to descend into a nested
     * object. Doing both at once is what guarantees the value a caller receives
     * is the one validation approved.
     *
     * @param array<string, mixed> $data
     *
     * @return array{value: array<string, mixed>, result: ValidationResult}
     */
    public function shape(array $data): array
    {
        $values = [];
        $errors = [];

        foreach ($this->fields as $name => $field) {
            if (!\array_key_exists($name, $data)) {
                if ($field->hasDefault()) {
                    $values[$name] = $field->defaultValue();

                    continue;
                }

                if ($field->isRequired()) {
                    $errors[] = new ValidationError($name, 'is required');
                }

                // Optional and absent: genuinely not there, so it is not in the
                // result either. An absent field and a null one are different
                // things and stay different here.
                continue;
            }

            /** @var mixed $value */
            $value = $data[$name];
            $applied = $field->apply($value, $name);

            $values[$name] = $applied['value'];
            $errors = [...$errors, ...$applied['errors']];
        }

        return ['value' => $values, 'result' => ValidationResult::of($errors)];
    }

    // ---- the contract -----------------------------------------------------

    /**
     * The schema as plain data.
     *
     * This is the API contract in a form something else can read: an endpoint
     * that documents itself, a 400 that tells a client what was expected, a
     * generator for whatever description format is in fashion. It is derived
     * from the declaration, so it cannot drift from what is enforced.
     *
     * @return array{name: string, fields: array<string, array<string, mixed>>}
     */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'fields' => \array_map(
                static fn(Field $field): array => $field->describe(),
                $this->fields,
            ),
        ];
    }
}
