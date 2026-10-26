<?php

declare(strict_types=1);

namespace App\Engine\Model;

use App\Engine\Error\FrameworkException;

/**
 * Every message here names the model, the attribute and what to do about it.
 *
 * A mapping error that says only "expected int, got string" costs more time
 * than the bug did.
 */
final class ModelException extends FrameworkException
{
    /** @param list<string> $available */
    public static function missingAttribute(string $model, string $attribute, string $type, array $available): self
    {
        return new self(\sprintf(
            'Cannot hydrate %s: the row has no "%s" for the required %s constructor parameter. '
            . 'The row provided: %s. Either select that column or give the parameter a default.',
            $model,
            $attribute,
            $type,
            $available === [] ? '(nothing)' : \implode(', ', $available),
        ));
    }

    public static function invalidAttribute(string $model, string $attribute, string $expected, mixed $value): self
    {
        return new self(\sprintf(
            'Cannot hydrate %s: "%s" expects %s but the row held %s. '
            . 'Hydration converts values only when the conversion is unambiguous; '
            . 'convert it in the query or in the repository instead.',
            $model,
            $attribute,
            $expected,
            \get_debug_type($value),
        ));
    }

    public static function notInstantiable(string $model, string $reason): self
    {
        return new self(\sprintf('Cannot hydrate %s: %s', $model, $reason));
    }

    public static function constructorRejected(string $model, string $message): self
    {
        return new self(\sprintf(
            'Cannot hydrate %s: its constructor rejected the row. %s',
            $model,
            $message,
        ));
    }

    /** @param list<string> $attached */
    public static function relationNotLoaded(string $model, string $relation, array $attached): self
    {
        return new self(\sprintf(
            '%s::related("%s") was called but that relation has not been loaded. '
            . 'Nothing here loads it for you: relations are attached explicitly, in a batch, '
            . 'so that a loop over models can never turn into one query per model. '
            . 'Loaded on this model: %s.',
            $model,
            $relation,
            $attached === [] ? '(none)' : \implode(', ', $attached),
        ));
    }

    public static function unknownRelation(string $model, string $relation, string $declared): self
    {
        return new self(\sprintf(
            'No relation "%s" is declared for %s. Declared: %s. '
            . 'Declare it from a module onBoot callback, where every module is registered.',
            $relation,
            $model,
            $declared === '' ? '(none)' : $declared,
        ));
    }

    public static function relationTypeMismatch(string $relation, string $expected, string $actual): self
    {
        return new self(\sprintf(
            'The "%s" relation holds %s, but a collection of %s was given.',
            $relation,
            $expected,
            $actual,
        ));
    }

    public static function unknownAttribute(string $model, string $attribute, string $declared): self
    {
        return new self(\sprintf(
            '%s has no "%s" attribute. Declared: %s.',
            $model,
            $attribute,
            $declared === '' ? '(none)' : $declared,
        ));
    }

    public static function collectionTypeMismatch(string $expected, string $actual): self
    {
        return new self(\sprintf(
            'A ModelCollection of %s cannot hold %s. A collection is single-typed so that '
            . 'identities(), pluck() and relation linking are safe.',
            $expected,
            $actual,
        ));
    }

    public static function notAModel(string $class): self
    {
        return new self(\sprintf('%s is not a %s.', $class, Model::class));
    }

    public static function notAReadModel(string $class): self
    {
        return new self(\sprintf('%s is not a %s.', $class, ReadModel::class));
    }

    public static function identityRequired(string $model, string $operation): self
    {
        return new self(\sprintf(
            'Cannot %s a %s that has no identity yet. Assign its identity after it is persisted.',
            $operation,
            $model,
        ));
    }

    public static function notDecryptable(string $model, string $attribute): self
    {
        return new self(\sprintf(
            'Cannot hydrate %s: "%s" is #[Encrypted] but the stored value does not decrypt. '
            . 'It was written with another APP_KEY (list retired keys in APP_PREVIOUS_KEYS), '
            . 'copied from another column, changed, or never encrypted.',
            $model,
            $attribute,
        ));
    }

    public static function encryptedNotString(string $model, string $attribute, string $type): self
    {
        return new self(\sprintf(
            '%s::$%s is #[Encrypted], so it must be declared string or ?string, not %s.',
            $model,
            $attribute,
            $type,
        ));
    }

    public static function encrypterMissing(string $model, string $attribute): self
    {
        return new self(\sprintf(
            '%s::$%s is #[Encrypted], but this ModelManager has no Encrypter. '
            . 'Construct it with one, or let the container build it.',
            $model,
            $attribute,
        ));
    }
}
