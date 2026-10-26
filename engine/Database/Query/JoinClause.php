<?php

declare(strict_types=1);

namespace App\Engine\Database\Query;

use App\Engine\Database\DatabaseException;

/**
 * One JOIN and its ON conditions, as data.
 *
 *     ->join('orders AS o', 'o.customer_id', '=', 'c.id')
 *     ->leftJoin('payments AS p', fn (JoinClause $j) => $j
 *         ->on('p.order_id', '=', 'o.id')
 *         ->where('p.status', 'settled'))
 *
 * on() compares two columns; where() compares a column with a value, which is
 * bound. Immutable, like the builder: a closure returns the clause it built.
 */
final class JoinClause
{
    public const INNER = 'inner';
    public const LEFT = 'left';
    public const RIGHT = 'right';

    /** Comparisons between two columns, or a column and a value. */
    public const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /**
     * @param self::INNER|self::LEFT|self::RIGHT $type
     * @param list<Condition>                    $conditions
     */
    public function __construct(
        public readonly string $type,
        public readonly string $table,
        public readonly array $conditions = [],
    ) {}

    /** The two columns are equal, or compare as $operator says. Joined with AND. */
    public function on(string $first, string $operator, string $second): self
    {
        return $this->adding(Condition::columns('and', $first, self::checked($operator), $second));
    }

    public function orOn(string $first, string $operator, string $second): self
    {
        return $this->adding(Condition::columns('or', $first, self::checked($operator), $second));
    }

    /**
     * A column compared with a value, which is bound: `where('p.status', 'settled')`
     * or `where('p.amount', '>', 0)`.
     */
    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if (\func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if ($value === null) {
            throw DatabaseException::comparedWithNull($column);
        }

        return $this->adding(Condition::compare('and', $column, self::checked($operator), $value));
    }

    public static function checked(mixed $operator): string
    {
        if (!\is_string($operator) || !\in_array($operator, self::OPERATORS, true)) {
            throw DatabaseException::unknownOperator(\is_string($operator) ? $operator : \get_debug_type($operator));
        }

        return $operator;
    }

    private function adding(Condition $condition): self
    {
        return new self($this->type, $this->table, [...$this->conditions, $condition]);
    }
}
