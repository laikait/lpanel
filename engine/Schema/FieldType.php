<?php

declare(strict_types=1);

namespace App\Engine\Schema;

/**
 * The kinds of value a schema can describe.
 *
 * Four scalars and two composites, which is the whole vocabulary. There is no
 * Email, Url, Uuid or Date case, and that is deliberate: those are not kinds of
 * value, they are constraints on a string, and a framework that starts
 * enumerating them never stops. Field::check() covers all of them in one line
 * without the engine having an opinion about what a valid postcode looks like
 * in your country.
 */
enum FieldType: string
{
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Bool = 'bool';

    /** A nested shape, described by another Schema. */
    case Object = 'object';

    /** A sequential list whose elements are all described by one Field. */
    case List = 'list';

    public function isScalar(): bool
    {
        return $this === self::Int || $this === self::Float || $this === self::String || $this === self::Bool;
    }

    public function isNumeric(): bool
    {
        return $this === self::Int || $this === self::Float;
    }

    /** The wording used in a validation message: "must be an integer". */
    public function article(): string
    {
        return match ($this) {
            self::Int => 'an integer',
            self::Float => 'a number',
            self::String => 'a string',
            self::Bool => 'a boolean',
            self::Object => 'an object',
            self::List => 'a list',
        };
    }
}
