<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Error\FrameworkException;

/**
 * A schema is wrong, or data does not match one.
 *
 * Note which of these are which. A malformed declaration is thrown when the
 * schema is built, so a mistake in a contract surfaces at boot rather than on
 * the first request that happens to exercise the field.
 */
final class SchemaException extends FrameworkException
{
    private ?ValidationResult $result = null;

    /** The errors, when this exception came from validating data. */
    public function result(): ValidationResult
    {
        return $this->result ?? ValidationResult::valid();
    }

    // ---- a broken declaration ---------------------------------------------

    public static function duplicateField(string $schema, string $field): self
    {
        return new self(\sprintf(
            'The "%s" schema declares "%s" twice. Field names are the keys of the data, so they must be unique.',
            $schema,
            $field,
        ));
    }

    public static function unknownField(string $schema, string $field, string $declared): self
    {
        return new self(\sprintf(
            'The "%s" schema has no "%s" field. Declared: %s.',
            $schema,
            $field,
            $declared === '' ? '(none)' : $declared,
        ));
    }

    /**
     * A constraint that cannot mean anything for the field it was put on, such
     * as a length on an integer. Thrown while the schema is being declared.
     */
    public static function constraintNotApplicable(string $field, FieldType $type, string $constraint, string $applies): self
    {
        return new self(\sprintf(
            '%s() cannot be applied to "%s", which is %s. %s',
            $constraint,
            $field,
            $type->article(),
            $applies,
        ));
    }

    public static function emptyChoice(string $field): self
    {
        return new self(\sprintf('oneOf() on "%s" was given no values, so nothing could ever be valid.', $field));
    }

    public static function invalidBounds(string $field, string $constraint): self
    {
        return new self(\sprintf(
            '%s() on "%s" has a minimum above its maximum, so nothing could ever be valid.',
            $constraint,
            $field,
        ));
    }

    // ---- data that does not match -----------------------------------------

    /** Input from outside did not satisfy the contract. The sender is at fault. */
    public static function invalidInput(string $schema, ValidationResult $result): self
    {
        $exception = new self(\sprintf(
            'The data does not satisfy the "%s" schema: %s',
            $schema,
            $result->summary(),
        ));

        $exception->result = $result;

        return $exception;
    }

    /**
     * Data on its way out did not satisfy its own contract.
     *
     * This is not a client error and must never be reported as one: the
     * application promised a shape and then failed to produce it.
     */
    public static function doesNotMatchContract(string $schema, ValidationResult $result): self
    {
        $exception = new self(\sprintf(
            'Data being serialised does not match the "%s" schema it claims to follow: %s. '
            . 'This is a fault in the code producing the data, not in anything a client sent.',
            $schema,
            $result->summary(),
        ));

        $exception->result = $result;

        return $exception;
    }
}
