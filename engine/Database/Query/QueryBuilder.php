<?php

declare(strict_types=1);

namespace App\Engine\Database\Query;

use App\Engine\Database\Connection;
use App\Engine\Database\DatabaseException;

/**
 * A SELECT, built a method at a time.
 *
 *     $connection->table('invoices')
 *         ->select('id', 'customer_id', 'total')
 *         ->where('status', 'open')
 *         ->where(fn (QueryBuilder $q) => $q->where('total', '>', 1000)->orWhere('overdue', true))
 *         ->orderBy('id', 'desc')
 *         ->limit(50)
 *         ->get();
 *
 * **Building runs nothing.** Every method until a terminal one -- get(),
 * first(), cursor(), count(), exists() -- only describes the query, and the
 * connection is not opened until then.
 *
 * **Immutable.** Each method returns a new builder, so a base query can be held
 * and narrowed in several directions without one caller changing what the next
 * one sees. A grouping closure must therefore *return* the builder it made.
 *
 * **Values are bound; names are checked.** A value never reaches the SQL
 * string. A column or table name is checked against the identifier pattern by
 * the Grammar, part by part for `orders.customer_id` and `name AS label`, and an
 * operator or a direction must be one of a short list. A request can supply the
 * values; it should never supply the names.
 *
 * This is the SQL side of the framework. `Data\Query` stays what it is -- AND
 * only, no joins, the same on every DataSource -- and a repository reaches for
 * this when a read is genuinely relational.
 */
final class QueryBuilder
{
    /** The comparisons a where() may make. Anything else is refused. */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    private function __construct(
        private readonly Connection $connection,
        private readonly QueryState $state,
    ) {}

    public static function on(Connection $connection, string $table): self
    {
        return new self($connection, new QueryState($table));
    }

    public function state(): QueryState
    {
        return $this->state;
    }

    // ---- columns ----------------------------------------------------------

    /**
     * The columns to read; none means all of them.
     *
     * `name`, `orders.total`, `orders.*` and `total AS amount` are accepted,
     * each part checked, as is an Aggregate: `Aggregate::sum('total', as: 'revenue')`.
     * Calling select() again replaces the list.
     */
    public function select(string|RawExpression|Aggregate ...$columns): self
    {
        return $this->with($this->state->with(columns: \array_values($columns)));
    }

    // ---- conditions -------------------------------------------------------

    /**
     * A condition, joined to the ones before it with AND.
     *
     *     ->where('status', 'open')                 // =
     *     ->where('total', '>', 1000)               // one of the listed operators
     *     ->where(fn (QueryBuilder $q) => ...)      // a group, in parentheses
     *     ->where(new RawExpression('...', [...]))  // hand-written, in parentheses
     *
     * Comparing with null is refused: `= NULL` is never true in SQL. Use
     * whereNull(), which says what it means.
     */
    public function where(string|\Closure|RawExpression $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->condition('and', $column, $operator, $value, \func_num_args());
    }

    /** As where(), joined with OR. */
    public function orWhere(string|\Closure|RawExpression $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->condition('or', $column, $operator, $value, \func_num_args());
    }

    public function whereNull(string $column): self
    {
        return $this->adding(Condition::null('and', $column, false));
    }

    public function whereNotNull(string $column): self
    {
        return $this->adding(Condition::null('and', $column, true));
    }

    /**
     * An empty list matches nothing, rather than being the syntax error `IN ()`
     * is on some databases. A list is bound one placeholder per value.
     *
     * @param array<array-key, mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->adding(Condition::in('and', $column, \array_values($values), false));
    }

    /**
     * An empty list excludes nothing.
     *
     * @param array<array-key, mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->adding(Condition::in('and', $column, \array_values($values), true));
    }

    /** Inclusive at both ends, as SQL's BETWEEN is. */
    public function whereBetween(string $column, mixed $low, mixed $high): self
    {
        return $this->adding(Condition::between('and', $column, $low, $high, false));
    }

    public function whereNotBetween(string $column, mixed $low, mixed $high): self
    {
        return $this->adding(Condition::between('and', $column, $low, $high, true));
    }

    /** Two columns compared with each other: `whereColumn('updated_at', '>', 'created_at')`. */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        return $this->adding(Condition::columns('and', $first, JoinClause::checked($operator), $second));
    }

    // ---- joins ------------------------------------------------------------

    /**
     * An inner join: only rows with a match on both sides.
     *
     *     ->join('orders AS o', 'o.customer_id', '=', 'c.id')
     *     ->join('orders AS o', fn (JoinClause $j) => $j->on('o.customer_id', '=', 'c.id')->where('o.status', 'open'))
     *
     * The four-argument form compares two columns. The closure form adds more,
     * including a column against a bound value, and must return the clause.
     */
    public function join(string $table, string|\Closure $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->joining(JoinClause::INNER, $table, $first, $operator, $second);
    }

    /** Every row on the left, with the right side's columns null where nothing matched. */
    public function leftJoin(string $table, string|\Closure $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->joining(JoinClause::LEFT, $table, $first, $operator, $second);
    }

    /**
     * Every row on the right. Where the database has Capability::RightJoin,
     * which SQLite is not counted as having; a left join with the tables the
     * other way round is the same question everywhere.
     */
    public function rightJoin(string $table, string|\Closure $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->joining(JoinClause::RIGHT, $table, $first, $operator, $second);
    }

    // ---- grouping ---------------------------------------------------------

    /** One result row per distinct value of these columns. */
    public function groupBy(string ...$columns): self
    {
        return $this->with($this->state->with(groups: [...$this->state->groups, ...\array_values($columns)]));
    }

    /**
     * A condition on the groups, joined with AND.
     *
     *     ->having(Aggregate::sum('total'), '>', 1000)
     *     ->having('status', 'open')                     // a grouped column
     *     ->having(new RawExpression('COUNT(DISTINCT customer_id) > ?', [5]))
     *
     * A selected alias is not accepted by name: PostgreSQL and SQL Server
     * cannot see one in HAVING. Repeat the aggregate instead.
     */
    public function having(string|Aggregate|RawExpression $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->havingCondition('and', $column, $operator, $value, \func_num_args());
    }

    public function orHaving(string|Aggregate|RawExpression $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->havingCondition('or', $column, $operator, $value, \func_num_args());
    }

    // ---- order and slice --------------------------------------------------

    /** Ascending unless 'desc' is asked for; no other word is accepted. */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $descending = match (\strtolower($direction)) {
            'asc' => false,
            'desc' => true,
            default => throw DatabaseException::unknownDirection($direction),
        };

        return $this->with($this->state->with(
            orders: [...$this->state->orders, ['column' => $column, 'descending' => $descending]],
        ));
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    /** At most this many rows; null removes the limit. */
    public function limit(?int $limit): self
    {
        return $this->with($this->state->with(limit: $limit, clearLimit: $limit === null));
    }

    public function offset(int $offset): self
    {
        return $this->with($this->state->with(offset: \max(0, $offset)));
    }

    /**
     * Lock the rows read until the transaction ends, so that no other
     * transaction can change them -- or lock them -- in between. For a
     * read-modify-write:
     *
     *     $db->transaction(function (Connection $db) use ($id): void {
     *         $row = $db->table('accounts')->where('id', $id)->lockForUpdate()->first();
     *         $db->table('accounts')->where('id', $id)->update(['balance' => $row['balance'] - 10]);
     *     });
     *
     * FOR UPDATE on MySQL and PostgreSQL, UPDLOCK on SQL Server. SQLite has
     * no row locks and needs none: a write locks the whole database, and one
     * writer runs at a time. Outside a transaction a lock would end with the
     * statement that took it, so reading is refused there.
     */
    public function lockForUpdate(): self
    {
        return $this->with($this->state->with(lock: true));
    }

    // ---- running it -------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        $compiled = $this->compileLocked();

        return $this->connection->select($compiled['sql'], $compiled['bindings']);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $compiled = $this->limit(1)->compileLocked();

        return $this->connection->selectOne($compiled['sql'], $compiled['bindings']);
    }

    /**
     * The rows one at a time, for a result that should not be held whole.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        $compiled = $this->compileLocked();

        return $this->connection->cursor($compiled['sql'], $compiled['bindings']);
    }

    /**
     * compile(), refusing a lock that would not outlive its own statement.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function compileLocked(): array
    {
        if ($this->state->lock && !$this->connection->inTransaction()) {
            throw DatabaseException::lockOutsideTransaction($this->state->table);
        }

        return $this->compile();
    }

    /** How many rows match; the order, limit and offset do not change the answer. */
    public function count(): int
    {
        $compiled = $this->connection->grammar()->compileQueryCount($this->state);

        return (int) $this->connection->scalar($compiled['sql'], $compiled['bindings']);
    }

    /** Whether any row matches, reading at most one. */
    public function exists(): bool
    {
        $compiled = $this->connection->grammar()->compileQuery(
            $this->state->with(columns: [new RawExpression('1')], orders: [], limit: 1, offset: 0),
        );

        return $this->connection->selectOne($compiled['sql'], $compiled['bindings']) !== null;
    }

    /**
     * The total of a column over every matching row, as the database returns it.
     *
     * An integer column sums to an int. A DECIMAL sums to a string, so that no
     * digit of an amount of money is lost to a float. No matching rows is
     * null, as in SQL, not zero.
     */
    public function sum(string $column): int|float|string|null
    {
        return $this->numeric($this->aggregateOf(Aggregate::sum($column)));
    }

    /**
     * The mean of a column. Most databases answer in DECIMAL, so this is a string
     * as often as a float; SQL Server averages an integer column as an integer.
     */
    public function avg(string $column): int|float|string|null
    {
        return $this->numeric($this->aggregateOf(Aggregate::avg($column)));
    }

    /** The smallest value: a number, a string or a date, as the column holds it. */
    public function min(string $column): mixed
    {
        return $this->aggregateOf(Aggregate::min($column));
    }

    public function max(string $column): mixed
    {
        return $this->aggregateOf(Aggregate::max($column));
    }

    /**
     * The statement and its bindings, without running it.
     *
     * For a test, a log line or an EXPLAIN. The bindings stay separate: this
     * never writes a value into the SQL, not even to make it easier to read.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compile(): array
    {
        return $this->connection->grammar()->compileQuery($this->state);
    }

    // ---- writing ----------------------------------------------------------

    /**
     * Insert one row.
     *
     * Name the key column to get back the value the database assigned it --
     * from the statement itself where the database can hand it back (RETURNING,
     * OUTPUT), otherwise from the connection. Without a key, null.
     *
     *     $id = $db->table('invoices')->insert(['customer_id' => 7, 'total' => 120.5], 'id');
     *
     * A value may be a RawExpression: `['created_at' => new RawExpression('CURRENT_TIMESTAMP')]`.
     *
     * @param array<array-key, mixed> $row column => value; a list is refused
     */
    public function insert(array $row, ?string $key = null): int|string|null
    {
        $this->assertWritable('insert', conditions: false);
        $row = $this->named($row);

        $compiled = $this->connection->grammar()->compileQueryInsert($this->state, $row, $key);

        if ($key === null) {
            $this->connection->execute($compiled['sql'], $compiled['bindings']);

            return null;
        }

        return $this->connection->insert($compiled['sql'], $compiled['bindings']);
    }

    /**
     * Insert many rows, in as few statements as each database's placeholder
     * limit allows, and say how many were written.
     *
     * Every row names the same columns, in any order. A row missing one is
     * refused rather than filled with null, which would overwrite a column
     * default without anybody deciding to. Wrap the call in a transaction when
     * the rows must be written all or none: more than one statement may run.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        $this->assertWritable('insert', conditions: false);

        if ($rows === []) {
            return 0;
        }

        $columns = $this->columnsOf($rows);
        $written = 0;

        foreach ($this->connection->grammar()->compileQueryInsertMany($this->state, $columns, $rows) as $statement) {
            $written += $this->connection->execute($statement['sql'], $statement['bindings']);
        }

        return $written;
    }

    /**
     * Change the rows the conditions match, and say how many that was.
     *
     * Refused without a condition: a forgotten where() should be an exception,
     * not a table rewritten. updateAll() is for when every row is meant. A
     * value may be a RawExpression: `['stock' => new RawExpression('stock - ?', [2])]`.
     *
     * "How many" is the database's count, and MySQL counts only rows whose
     * values actually changed.
     *
     * @param array<array-key, mixed> $changes column => value; a list is refused
     */
    public function update(array $changes): int
    {
        return $this->updating($changes, all: false);
    }

    /**
     * @param array<array-key, mixed> $changes column => value; a list is refused
     */
    public function updateAll(array $changes): int
    {
        return $this->updating($changes, all: true);
    }

    /** Delete the rows the conditions match. Refused without one; see deleteAll(). */
    public function delete(): int
    {
        return $this->deleting(all: false);
    }

    public function deleteAll(): int
    {
        return $this->deleting(all: true);
    }

    /**
     * Insert each row, or update the one already there with the same unique key.
     *
     *     $db->table('stock')->upsert($rows, uniqueBy: ['sku'], update: ['quantity']);
     *
     * $update names what a conflict overwrites. It defaults to every column
     * that is not part of the key; an empty list leaves an existing row alone.
     *
     * Only where the database has Capability::Upsert -- MySQL, PostgreSQL and
     * SQLite. SQL Server's MERGE is a different statement with different
     * locking, and is not faked. MySQL judges a conflict by any unique key the
     * row collides with, whatever $uniqueBy says; PostgreSQL refuses one
     * statement that names the same key twice, so keep keys in a batch distinct.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $uniqueBy
     * @param list<string>|null          $update
     */
    public function upsert(array $rows, array $uniqueBy, ?array $update = null): void
    {
        $this->assertWritable('upsert', conditions: false);

        if ($uniqueBy === []) {
            throw DatabaseException::upsertNeedsUniqueBy($this->state->table);
        }

        if ($rows === []) {
            return;
        }

        $columns = $this->columnsOf($rows);
        $update ??= \array_values(\array_diff($columns, $uniqueBy));

        $statements = $this->connection->grammar()->compileUpsert($this->state, $columns, $rows, $uniqueBy, $update);

        foreach ($statements as $statement) {
            $this->connection->execute($statement['sql'], $statement['bindings']);
        }
    }

    // ---- internals --------------------------------------------------------

    /** @param JoinClause::INNER|JoinClause::LEFT|JoinClause::RIGHT $type */
    private function joining(string $type, string $table, string|\Closure $first, ?string $operator, ?string $second): self
    {
        $join = new JoinClause($type, $table);

        if ($first instanceof \Closure) {
            $join = $first($join);

            if (!$join instanceof JoinClause) {
                throw DatabaseException::groupReturnedNothing(\get_debug_type($join));
            }
        } else {
            $join = $join->on($first, $operator ?? '=', $second ?? '');
        }

        return $this->with($this->state->with(joins: [...$this->state->joins, $join]));
    }

    /** @param 'and'|'or' $boolean */
    private function havingCondition(
        string $boolean,
        string|Aggregate|RawExpression $column,
        mixed $operator,
        mixed $value,
        int $arguments,
    ): self {
        if ($column instanceof RawExpression) {
            $condition = Condition::raw($boolean, $column);
        } else {
            if ($arguments === 2) {
                $value = $operator;
                $operator = '=';
            }

            $operator = JoinClause::checked($operator);

            if ($value === null) {
                throw DatabaseException::comparedWithNull($column instanceof Aggregate ? $column->function : $column);
            }

            $condition = $column instanceof Aggregate
                ? Condition::aggregate($boolean, $column, $operator, $value)
                : Condition::compare($boolean, $column, $operator, $value);
        }

        return $this->with($this->state->with(havings: [...$this->state->havings, $condition]));
    }

    private function aggregateOf(Aggregate $aggregate): mixed
    {
        if ($this->state->isGrouped()) {
            throw DatabaseException::aggregateOfGroups($this->state->table, $aggregate->function);
        }

        $compiled = $this->connection->grammar()->compileQueryAggregate($this->state, $aggregate);

        return $this->connection->scalar($compiled['sql'], $compiled['bindings']);
    }

    private function numeric(mixed $value): int|float|string|null
    {
        return \is_int($value) || \is_float($value) || \is_string($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $changes */
    private function updating(array $changes, bool $all): int
    {
        $this->assertWritable('update', conditions: true, all: $all);
        $changes = $this->named($changes);

        // Nothing to change changes nothing, and runs nothing.
        if ($changes === []) {
            return 0;
        }

        $compiled = $this->connection->grammar()->compileQueryUpdate($this->state, $changes);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }

    private function deleting(bool $all): int
    {
        $this->assertWritable('delete', conditions: true, all: $all);

        $compiled = $this->connection->grammar()->compileQueryDelete($this->state);

        return $this->connection->execute($compiled['sql'], $compiled['bindings']);
    }

    /**
     * A write carries nothing that only a read can honour, and an UPDATE or
     * DELETE carries a condition unless every row was asked for by name.
     */
    private function assertWritable(string $operation, bool $conditions, bool $all = false): void
    {
        $table = $this->state->table;

        foreach ([
            'a column list' => $this->state->columns !== [],
            'an order' => $this->state->orders !== [],
            'a limit' => $this->state->limit !== null,
            'an offset' => $this->state->offset !== 0,
            'a join' => $this->state->joins !== [],
            'a grouping' => $this->state->isGrouped(),
            'conditions' => !$conditions && $this->state->wheres !== [],
        ] as $what => $present) {
            if ($present) {
                throw DatabaseException::writeCarries($table, $operation, $what);
            }
        }

        if ($conditions && !$all && $this->state->wheres === []) {
            throw DatabaseException::writeWithoutConditions($table, $operation);
        }
    }

    /**
     * The row, once every key is known to be a column name.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function named(array $row): array
    {
        $named = [];

        foreach ($row as $column => $value) {
            if (!\is_string($column)) {
                throw DatabaseException::rowNeedsColumnNames($this->state->table);
            }

            $named[$column] = $value;
        }

        return $named;
    }

    /**
     * The columns every row names, in the first row's order.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<string>
     */
    private function columnsOf(array $rows): array
    {
        $columns = null;
        $expected = [];

        foreach (\array_values($rows) as $index => $row) {
            if (!\is_array($row)) {
                throw DatabaseException::rowNeedsColumnNames($this->state->table);
            }

            $row = $this->named($row);

            foreach ($row as $value) {
                if ($value instanceof RawExpression) {
                    throw DatabaseException::rawInBulk($this->state->table);
                }
            }

            /** @var list<string> $names */
            $names = \array_keys($row);
            $sorted = $names;
            \sort($sorted);

            if ($columns === null) {
                $columns = $names;
                $expected = $sorted;
            } elseif ($sorted !== $expected) {
                throw DatabaseException::inconsistentRows($this->state->table, $index);
            }
        }

        return $columns ?? [];
    }

    /** @param 'and'|'or' $boolean */
    private function condition(
        string $boolean,
        string|\Closure|RawExpression $column,
        mixed $operator,
        mixed $value,
        int $arguments,
    ): self {
        if ($column instanceof RawExpression) {
            return $this->adding(Condition::raw($boolean, $column));
        }

        if ($column instanceof \Closure) {
            return $this->group($boolean, $column);
        }

        if ($arguments === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (!\is_string($operator) || !\in_array(\strtolower($operator), self::OPERATORS, true)) {
            throw DatabaseException::unknownOperator(\is_string($operator) ? $operator : \get_debug_type($operator));
        }

        if ($value === null) {
            throw DatabaseException::comparedWithNull($column);
        }

        return $this->adding(Condition::compare($boolean, $column, \strtolower($operator), $value));
    }

    /** @param 'and'|'or' $boolean */
    private function group(string $boolean, \Closure $build): self
    {
        $inner = $build(new self($this->connection, new QueryState($this->state->table)));

        if (!$inner instanceof self) {
            throw DatabaseException::groupReturnedNothing(\get_debug_type($inner));
        }

        // An empty group is no condition at all, not "()".
        if ($inner->state->wheres === []) {
            return $this;
        }

        return $this->adding(Condition::group($boolean, $inner->state->wheres));
    }

    private function adding(Condition $condition): self
    {
        return $this->with($this->state->with(wheres: [...$this->state->wheres, $condition]));
    }

    private function with(QueryState $state): self
    {
        return new self($this->connection, $state);
    }
}
