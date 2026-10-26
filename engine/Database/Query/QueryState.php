<?php

declare(strict_types=1);

namespace App\Engine\Database\Query;

/**
 * What a query asks for, before anybody has written it as SQL.
 *
 * The builder changes this and the Grammar reads it. Keeping the two apart is
 * what lets one query be written in four dialects: nothing here knows how
 * SQL Server pages or how MySQL quotes.
 *
 * Immutable; with() returns a changed copy.
 *
 * @internal built by QueryBuilder, read by Grammar
 */
final class QueryState
{
    /**
     * @param list<string|RawExpression|Aggregate>           $columns empty means every column
     * @param list<Condition>                                $wheres
     * @param list<array{column: string, descending: bool}> $orders
     * @param list<JoinClause>                               $joins
     * @param list<string>                                   $groups
     * @param list<Condition>                                $havings
     * @param bool                                           $lock    whether the rows read stay locked until the transaction ends
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns = [],
        public readonly array $wheres = [],
        public readonly array $orders = [],
        public readonly ?int $limit = null,
        public readonly int $offset = 0,
        public readonly array $joins = [],
        public readonly array $groups = [],
        public readonly array $havings = [],
        public readonly bool $lock = false,
    ) {}

    /**
     * @param list<string|RawExpression|Aggregate>|null           $columns
     * @param list<Condition>|null                                $wheres
     * @param list<array{column: string, descending: bool}>|null $orders
     * @param list<JoinClause>|null                               $joins
     * @param list<string>|null                                   $groups
     * @param list<Condition>|null                                $havings
     */
    public function with(
        ?array $columns = null,
        ?array $wheres = null,
        ?array $orders = null,
        ?int $limit = null,
        bool $clearLimit = false,
        ?int $offset = null,
        ?array $joins = null,
        ?array $groups = null,
        ?array $havings = null,
        ?bool $lock = null,
    ): self {
        return new self(
            $this->table,
            $columns ?? $this->columns,
            $wheres ?? $this->wheres,
            $orders ?? $this->orders,
            $clearLimit ? null : ($limit ?? $this->limit),
            $offset ?? $this->offset,
            $joins ?? $this->joins,
            $groups ?? $this->groups,
            $havings ?? $this->havings,
            $lock ?? $this->lock,
        );
    }

    /** Whether rows are grouped, so that one result row stands for many. */
    public function isGrouped(): bool
    {
        return $this->groups !== [] || $this->havings !== [];
    }
}
