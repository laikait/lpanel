<?php

declare(strict_types=1);

namespace App\Engine\Database\Query;

/**
 * One condition in a WHERE clause, as data.
 *
 * The builder records these and the Grammar writes them; nothing here is SQL.
 * `boolean` is how the condition joins the one before it, and is ignored on the
 * first. A group holds its own conditions, which is what parentheses are.
 *
 * @internal built by QueryBuilder, read by Grammar
 */
final class Condition
{
    public const COMPARE = 'compare';
    public const NULL = 'null';
    public const IN = 'in';
    public const BETWEEN = 'between';
    public const GROUP = 'group';
    public const RAW = 'raw';
    public const COLUMNS = 'columns';
    public const AGGREGATE = 'aggregate';

    /**
     * @param 'and'|'or'      $boolean
     * @param list<Condition> $nested
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $boolean,
        public readonly string $column = '',
        public readonly string $operator = '',
        public readonly mixed $value = null,
        public readonly bool $negated = false,
        public readonly array $nested = [],
        public readonly ?RawExpression $raw = null,
        public readonly ?Aggregate $aggregate = null,
    ) {}

    /** @param 'and'|'or' $boolean */
    public static function compare(string $boolean, string $column, string $operator, mixed $value): self
    {
        return new self(self::COMPARE, $boolean, $column, $operator, $value);
    }

    /** @param 'and'|'or' $boolean */
    public static function null(string $boolean, string $column, bool $negated): self
    {
        return new self(self::NULL, $boolean, $column, negated: $negated);
    }

    /**
     * @param 'and'|'or'  $boolean
     * @param list<mixed> $values
     */
    public static function in(string $boolean, string $column, array $values, bool $negated): self
    {
        return new self(self::IN, $boolean, $column, value: $values, negated: $negated);
    }

    /** @param 'and'|'or' $boolean */
    public static function between(string $boolean, string $column, mixed $low, mixed $high, bool $negated): self
    {
        return new self(self::BETWEEN, $boolean, $column, value: [$low, $high], negated: $negated);
    }

    /**
     * @param 'and'|'or'      $boolean
     * @param list<Condition> $conditions
     */
    public static function group(string $boolean, array $conditions): self
    {
        return new self(self::GROUP, $boolean, nested: $conditions);
    }

    /**
     * One column compared with another; the second is a name, never a value.
     *
     * @param 'and'|'or' $boolean
     */
    public static function columns(string $boolean, string $first, string $operator, string $second): self
    {
        return new self(self::COLUMNS, $boolean, $first, $operator, $second);
    }

    /** @param 'and'|'or' $boolean */
    public static function aggregate(string $boolean, Aggregate $aggregate, string $operator, mixed $value): self
    {
        return new self(self::AGGREGATE, $boolean, operator: $operator, value: $value, aggregate: $aggregate);
    }

    /** @param 'and'|'or' $boolean */
    public static function raw(string $boolean, RawExpression $raw): self
    {
        return new self(self::RAW, $boolean, raw: $raw);
    }
}
