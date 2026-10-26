<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * How one field is compared to one value.
 *
 * A closed set. Every entry has to be implementable by every data source, and a
 * source that cannot express one of these is not a data source -- so the list
 * stays short enough that an in-memory array, a SQL table and whatever comes
 * later can all honour it.
 */
enum Operator: string
{
    case Eq = '=';
    case NotEq = '!=';
    case Lt = '<';
    case Lte = '<=';
    case Gt = '>';
    case Gte = '>=';
    case In = 'in';
    case NotIn = 'not in';
    case Like = 'like';
    case IsNull = 'is null';
    case IsNotNull = 'is not null';

    /**
     * After a boundary row in an order: keyset pagination's criterion. Its
     * value is a Seek and its field only a label; see Seek. Built by
     * Query::cursor() and chunk(), not written by hand.
     */
    case Seek = 'seek';

    /** Whether the operator compares against a value at all. */
    public function takesValue(): bool
    {
        return $this !== self::IsNull && $this !== self::IsNotNull;
    }

    public function expectsList(): bool
    {
        return $this === self::In || $this === self::NotIn;
    }
}
