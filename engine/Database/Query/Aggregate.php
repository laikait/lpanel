<?php

declare(strict_types=1);

namespace App\Engine\Database\Query;

use App\Engine\Database\DatabaseException;

/**
 * COUNT, SUM, AVG, MIN or MAX of a column, as data rather than as SQL text.
 *
 *     ->select('status', Aggregate::count(as: 'orders'), Aggregate::sum('total', as: 'revenue'))
 *     ->groupBy('status')
 *     ->having(Aggregate::sum('total'), '>', 1000)
 *
 * The function comes from a fixed list and the column is checked like any
 * other name, so an aggregate needs no RawExpression and can carry nothing
 * but what it says. Every database writes these five the same way.
 */
final class Aggregate
{
    private const FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    private function __construct(
        public readonly string $function,
        public readonly string $column,
        public readonly ?string $alias,
    ) {
        if (!\in_array($function, self::FUNCTIONS, true)) {
            throw DatabaseException::unknownAggregate($function);
        }

        if ($column === '*' && $function !== 'count') {
            throw DatabaseException::aggregateOfEverything($function);
        }
    }

    /** COUNT(*) by default: every row, nulls included. A column counts its non-null values. */
    public static function count(string $column = '*', ?string $as = null): self
    {
        return new self('count', $column, $as);
    }

    public static function sum(string $column, ?string $as = null): self
    {
        return new self('sum', $column, $as);
    }

    public static function avg(string $column, ?string $as = null): self
    {
        return new self('avg', $column, $as);
    }

    public static function min(string $column, ?string $as = null): self
    {
        return new self('min', $column, $as);
    }

    public static function max(string $column, ?string $as = null): self
    {
        return new self('max', $column, $as);
    }

    /** The same aggregate under another name, or none. */
    public function as(?string $alias): self
    {
        return new self($this->function, $this->column, $alias);
    }
}
