<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Data\Criterion;
use App\Engine\Data\Operator;
use App\Engine\Data\Order;
use App\Engine\Data\Query;
use App\Engine\Data\Seek;
use App\Engine\Database\Query\Aggregate;
use App\Engine\Database\Query\Condition;
use App\Engine\Database\Query\JoinClause;
use App\Engine\Database\Query\QueryState;
use App\Engine\Database\Query\RawExpression;
use App\Engine\Database\Structure\Column;
use App\Engine\Database\Structure\ColumnType;
use App\Engine\Database\Structure\ForeignKey;
use App\Engine\Database\Structure\Index;
use App\Engine\Database\Structure\Table;

/**
 * Turns a Query into SQL and a list of values to bind.
 *
 * Everything a caller supplied as a *value* is bound. Everything written into
 * the statement is either generated here or an identifier, and an identifier is
 * checked against one pattern before it goes anywhere near the string:
 *
 *     /^[A-Za-z_][A-Za-z0-9_]*$/
 *
 * Table and column names cannot be bound -- no database accepts a parameter
 * where a column goes -- so they are the one part of a statement built by
 * interpolation, and that makes this the place injection would live if it lived
 * anywhere. Names reach here from repository declarations, not from requests.
 * The check is what keeps that true on the day somebody passes a sort column
 * straight from a query string.
 *
 * Limits and offsets are written in rather than bound. They are typed int in
 * PHP by the time they arrive, so there is nothing to inject, and binding them
 * is the one thing several drivers get wrong.
 *
 * **One dialect per driver.** This class writes standard SQL, and is what a
 * driver nobody has written a dialect for gets. MySqlGrammar, PostgresGrammar,
 * SqliteGrammar and SqlServerGrammar override only where their database
 * differs -- the quoting, the paging, the savepoints, how many placeholders fit
 * in one statement -- so a difference is a method in one file rather than a
 * condition on the driver name wherever the SQL is written. for() picks one.
 *
 * What a dialect can do at all is supports(): a feature one database lacks is
 * refused with a sentence, not attempted and answered with a syntax error.
 */
class Grammar
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** The dialect for a PDO driver name, or standard SQL for one without. */
    public static function for(string $driver): self
    {
        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            'sqlsrv' => new SqlServerGrammar(),
            default => new self(),
        };
    }

    /** Which PDO driver this dialect is written for; empty for standard SQL. */
    public function driver(): string
    {
        return '';
    }

    /**
     * Whether this dialect can do something at all.
     *
     * Standard SQL claims nothing beyond the statements every relational
     * database runs: a driver without its own dialect is one nobody has
     * checked, and a feature assumed there is an error on somebody's server.
     */
    public function supports(Capability $capability): bool
    {
        return false;
    }

    // ---- identifiers ------------------------------------------------------

    public function identifier(string $name): string
    {
        if (\preg_match(self::IDENTIFIER, $name) !== 1) {
            throw DatabaseException::unsafeIdentifier($name);
        }

        return $this->quote($name);
    }

    /** Quote a name that has already been checked. */
    protected function quote(string $name): string
    {
        return '"' . $name . '"';
    }

    /**
     * A name checked but written bare, where every dialect takes it that way.
     *
     * Savepoint names are generated, and quoting rules for them differ more
     * than they do for columns; a plain name needs none on any driver.
     */
    protected function plainName(string $name): string
    {
        if (\preg_match(self::IDENTIFIER, $name) !== 1) {
            throw DatabaseException::unsafeIdentifier($name);
        }

        return $name;
    }

    /** @param list<string> $names */
    public function columnList(array $names): string
    {
        if ($names === []) {
            return '*';
        }

        return \implode(', ', \array_map(
            fn(string $name): string => $this->identifier($name),
            $names,
        ));
    }

    // ---- reading ----------------------------------------------------------

    /** @return array{sql: string, bindings: list<mixed>} */
    public function compileSelect(Query $query): array
    {
        $where = $this->compileWhere($query->criteria());

        $sql = \sprintf(
            'SELECT %s FROM %s',
            $this->columnList($query->columns()),
            $this->identifier($query->collection()),
        );

        return [
            'sql' => $sql . $where['sql'] . $this->compileOrderAndSlice(
                $this->compileOrder($query->orders()),
                $query->limitValue(),
                $query->offsetValue(),
            ),
            'bindings' => $where['bindings'],
        ];
    }

    /**
     * ORDER BY and the slice together, because on some databases the slice is
     * part of the ORDER BY clause and cannot be written without one.
     *
     * @param string $order the ORDER BY clause already written, or ''
     */
    protected function compileOrderAndSlice(string $order, ?int $limit, int $offset): string
    {
        return $order . $this->compileLimit($limit, $offset);
    }

    // ---- the query builder ------------------------------------------------

    /**
     * A builder's SELECT.
     *
     * SELECT columns FROM table JOIN ... WHERE ... GROUP BY ... HAVING ...
     * ORDER BY ... and the slice. Bindings follow the order the placeholders
     * appear in: columns, joins, conditions, then the having conditions.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileQuery(QueryState $state): array
    {
        if ($state->lock && $state->isGrouped()) {
            throw DatabaseException::lockOnGroups($state->table);
        }

        $columns = $this->compileColumns($state->columns);
        $body = $this->compileBody($state);

        $order = $state->orders === [] ? '' : ' ORDER BY ' . \implode(', ', \array_map(
            fn(array $order): string => $this->qualified($order['column']) . ($order['descending'] ? ' DESC' : ' ASC'),
            $state->orders,
        ));

        return [
            'sql' => 'SELECT ' . $columns['sql'] . $body['sql']
                . $this->compileOrderAndSlice($order, $state->limit, $state->offset)
                . ($state->lock ? $this->lockClause() : ''),
            'bindings' => [...$columns['bindings'], ...$body['bindings']],
        ];
    }

    /**
     * What ends a SELECT whose rows stay locked until the transaction does.
     * The standard's FOR UPDATE; a dialect that locks another way returns ''.
     */
    protected function lockClause(): string
    {
        return ' FOR UPDATE';
    }

    /** What follows the table's name when its rows are locked. Nothing, in the standard. */
    protected function lockHint(): string
    {
        return '';
    }

    /**
     * A count of what the builder matches. Order and slice do not change how
     * many rows match, so they are left out, as the data layer's count does.
     *
     * A grouped query is counted in groups -- one result row each -- by
     * counting the rows of the grouped query itself. The derived table's
     * column is named, which SQL Server insists on.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileQueryCount(QueryState $state): array
    {
        $body = $this->compileBody($state);

        if (!$state->isGrouped()) {
            return ['sql' => 'SELECT COUNT(*)' . $body['sql'], 'bindings' => $body['bindings']];
        }

        return [
            'sql' => 'SELECT COUNT(*) FROM (SELECT 1 AS ' . $this->identifier('grouped_row') . $body['sql'] . ') AS '
                . $this->identifier('grouped_rows'),
            'bindings' => $body['bindings'],
        ];
    }

    /**
     * One aggregate over every row the conditions match.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileQueryAggregate(QueryState $state, Aggregate $aggregate): array
    {
        $body = $this->compileBody($state);

        return ['sql' => 'SELECT ' . $this->aggregate($aggregate->as(null)) . $body['sql'], 'bindings' => $body['bindings']];
    }

    /**
     * FROM, the joins, WHERE, GROUP BY and HAVING: everything between the
     * column list and the order, which a select, a count and an aggregate share.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function compileBody(QueryState $state): array
    {
        $sql = ' FROM ' . $this->table($state->table) . ($state->lock ? $this->lockHint() : '');
        $bindings = [];

        foreach ($state->joins as $join) {
            $compiled = $this->compileJoin($join);
            $sql .= $compiled['sql'];
            $bindings = [...$bindings, ...$compiled['bindings']];
        }

        $where = $this->compileConditions($state->wheres);

        if ($where['sql'] !== '') {
            $sql .= ' WHERE ' . $where['sql'];
            $bindings = [...$bindings, ...$where['bindings']];
        }

        if ($state->groups !== []) {
            $sql .= ' GROUP BY ' . \implode(', ', \array_map(fn(string $column): string => $this->qualified($column), $state->groups));
        }

        $having = $this->compileConditions($state->havings);

        if ($having['sql'] !== '') {
            $sql .= ' HAVING ' . $having['sql'];
            $bindings = [...$bindings, ...$having['bindings']];
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileJoin(JoinClause $join): array
    {
        if ($join->type === JoinClause::RIGHT && !$this->supports(Capability::RightJoin)) {
            throw DatabaseException::unsupported(Capability::RightJoin, $this->driver());
        }

        if ($join->conditions === []) {
            throw DatabaseException::joinWithoutConditions($join->table);
        }

        $on = $this->compileConditions($join->conditions);
        $keyword = match ($join->type) {
            JoinClause::LEFT => ' LEFT JOIN ',
            JoinClause::RIGHT => ' RIGHT JOIN ',
            default => ' INNER JOIN ',
        };

        return ['sql' => $keyword . $this->table($join->table) . ' ON ' . $on['sql'], 'bindings' => $on['bindings']];
    }

    /**
     * FUNCTION(column), and AS alias when it has one. The function is one of
     * five and upper-cased from that list; the column is checked like any name.
     */
    private function aggregate(Aggregate $aggregate): string
    {
        $written = $this->aggregateCall(
            \strtoupper($aggregate->function),
            $aggregate->column === '*' ? '*' : $this->qualified($aggregate->column),
        );

        return $aggregate->alias === null ? $written : $written . ' AS ' . $this->identifier($aggregate->alias);
    }

    /** FUNCTION(argument), for a dialect whose function means something else to say so. */
    protected function aggregateCall(string $function, string $argument): string
    {
        return $function . '(' . $argument . ')';
    }

    /**
     * @param list<string|RawExpression|Aggregate> $columns
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function compileColumns(array $columns): array
    {
        if ($columns === []) {
            return ['sql' => '*', 'bindings' => []];
        }

        $written = [];
        $bindings = [];

        foreach ($columns as $column) {
            if ($column instanceof RawExpression) {
                $written[] = $column->sql;
                $bindings = [...$bindings, ...$column->bindings];
            } elseif ($column instanceof Aggregate) {
                $written[] = $this->aggregate($column);
            } else {
                $written[] = $this->column($column);
            }
        }

        return ['sql' => \implode(', ', $written), 'bindings' => $bindings];
    }

    /**
     * The conditions, without the WHERE, so a group can use it for its inside.
     *
     * @param list<Condition> $conditions
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function compileConditions(array $conditions): array
    {
        $sql = '';
        $bindings = [];

        foreach ($conditions as $index => $condition) {
            $compiled = $this->compileCondition($condition);

            $sql .= ($index === 0 ? '' : ($condition->boolean === 'or' ? ' OR ' : ' AND ')) . $compiled['sql'];
            $bindings = [...$bindings, ...$compiled['bindings']];
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileCondition(Condition $condition): array
    {
        switch ($condition->kind) {
            case Condition::NULL:
                return [
                    'sql' => $this->qualified($condition->column) . ($condition->negated ? ' IS NOT NULL' : ' IS NULL'),
                    'bindings' => [],
                ];

            case Condition::IN:
                /** @var list<mixed> $values */
                $values = $condition->value;

                // "IN ()" is a syntax error on some databases and matches
                // nothing on others. An empty list means exactly that nothing,
                // or for NOT IN, everything, and both are written as such.
                if ($values === []) {
                    return ['sql' => $condition->negated ? '1 = 1' : '1 = 0', 'bindings' => []];
                }

                return [
                    'sql' => $this->qualified($condition->column) . ($condition->negated ? ' NOT IN (' : ' IN (')
                        . \implode(', ', \array_fill(0, \count($values), '?')) . ')',
                    'bindings' => $values,
                ];

            case Condition::BETWEEN:
                /** @var array{mixed, mixed} $range */
                $range = $condition->value;

                return [
                    'sql' => $this->qualified($condition->column) . ($condition->negated ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?'),
                    'bindings' => [$range[0], $range[1]],
                ];

            case Condition::GROUP:
                $inner = $this->compileConditions($condition->nested);

                return ['sql' => '(' . $inner['sql'] . ')', 'bindings' => $inner['bindings']];

            case Condition::COLUMNS:
                /** @var string $second */
                $second = $condition->value;

                return [
                    'sql' => $this->qualified($condition->column) . ' ' . $condition->operator . ' ' . $this->qualified($second),
                    'bindings' => [],
                ];

            case Condition::AGGREGATE:
                $aggregate = $condition->aggregate ?? Aggregate::count();

                return [
                    'sql' => $this->aggregate($aggregate->as(null)) . ' ' . $condition->operator . ' ?',
                    'bindings' => [$condition->value],
                ];

            case Condition::RAW:
                $raw = $condition->raw ?? new RawExpression('1 = 1');

                return ['sql' => '(' . $raw->sql . ')', 'bindings' => $raw->bindings];

            default:
                // The builder checked the operator against its list; it is
                // written from that list, upper-cased, never from free text.
                return [
                    'sql' => $this->qualified($condition->column) . ' ' . \strtoupper($condition->operator) . ' ?',
                    'bindings' => [$condition->value],
                ];
        }
    }

    // ---- names with more than one part ------------------------------------

    /**
     * A dotted name, each part checked and quoted: `orders.customer_id`, or
     * `sales.orders.customer_id` with a schema. Three parts at most.
     */
    public function qualified(string $name): string
    {
        $parts = \explode('.', $name);

        if (\count($parts) > 3) {
            throw DatabaseException::unsafeIdentifier($name);
        }

        return \implode('.', \array_map(fn(string $part): string => $this->identifier($part), $parts));
    }

    /**
     * A selected column: a name, `table.*`, `*`, or any of those `AS` an alias.
     */
    public function column(string $expression): string
    {
        [$name, $alias] = $this->splitAlias($expression);

        if ($name === '*') {
            $written = '*';
        } elseif (\str_ends_with($name, '.*')) {
            $written = $this->qualified(\substr($name, 0, -2)) . '.*';
        } else {
            $written = $this->qualified($name);
        }

        return $alias === null ? $written : $written . ' AS ' . $this->identifier($alias);
    }

    /** A table: `orders`, `sales.orders`, or either `AS` an alias. */
    public function table(string $expression): string
    {
        [$name, $alias] = $this->splitAlias($expression);

        if (\substr_count($name, '.') > 1) {
            throw DatabaseException::unsafeIdentifier($name);
        }

        return $alias === null
            ? $this->qualified($name)
            : $this->qualified($name) . ' AS ' . $this->identifier($alias);
    }

    /**
     * "name AS alias" into its two halves. Anything with spaces that is not
     * exactly that shape is left whole, for the identifier check to refuse.
     *
     * @return array{string, string|null}
     */
    private function splitAlias(string $expression): array
    {
        if (\preg_match('/^(\S+)\s+as\s+(\S+)$/i', \trim($expression), $match) === 1) {
            return [$match[1], $match[2]];
        }

        return [$expression, null];
    }

    /**
     * A count ignores limit and offset, the way the data layer promises.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileCount(Query $query): array
    {
        $where = $this->compileWhere($query->criteria());

        return [
            'sql' => \sprintf(
                'SELECT COUNT(*) FROM %s%s',
                $this->identifier($query->collection()),
                $where['sql'],
            ),
            'bindings' => $where['bindings'],
        ];
    }

    /**
     * @param list<Criterion> $criteria
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileWhere(array $criteria): array
    {
        if ($criteria === []) {
            return ['sql' => '', 'bindings' => []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($criteria as $criterion) {
            $compiled = $this->compileCriterion($criterion);
            $clauses[] = $compiled['sql'];
            $bindings = [...$bindings, ...$compiled['bindings']];
        }

        // AND only. The data layer has no OR by design; see Data\Query.
        return ['sql' => ' WHERE ' . \implode(' AND ', $clauses), 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileCriterion(Criterion $criterion): array
    {
        if ($criterion->value instanceof Seek) {
            return $this->compileSeek($criterion->value);
        }

        $column = $this->identifier($criterion->field);

        return match ($criterion->operator) {
            Operator::IsNull => ['sql' => $column . ' IS NULL', 'bindings' => []],
            Operator::IsNotNull => ['sql' => $column . ' IS NOT NULL', 'bindings' => []],
            Operator::In, Operator::NotIn => $this->compileIn($column, $criterion),
            Operator::Like => ['sql' => $column . ' LIKE ?', 'bindings' => [$criterion->value]],
            default => [
                'sql' => $column . ' ' . $criterion->operator->value . ' ?',
                'bindings' => [$criterion->value],
            ],
        };
    }

    /**
     * Rows after a boundary, in an order: the expanded form of a row-value
     * comparison, behind a plain range on the first column.
     *
     *     created_at DESC, id DESC after (T, 7)
     *     (created_at <= ? AND ((created_at < ?) OR (created_at = ? AND id < ?)))
     *
     * Expanded rather than (created_at, id) < (?, ?), because a row value
     * cannot mix ascending and descending columns and is not supported the
     * same way everywhere.
     *
     * The leading "created_at <= ?" is redundant logically and is the whole
     * point physically. Planners do not seek into an index through an OR;
     * given only the OR, SQLite scanned the index from the start, and a page
     * 190,000 rows in took 15 ms instead of 0.03. The plain range is something
     * every planner turns into an index seek, and the OR then only refines the
     * boundary row's ties.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function compileSeek(Seek $seek): array
    {
        $first = $seek->orders[0];
        $range = $this->identifier($first->field) . ($first->isDescending() ? ' <= ?' : ' >= ?');
        $branches = [];
        $bindings = [$seek->values[0]];

        foreach ($seek->orders as $index => $order) {
            $parts = [];

            for ($previous = 0; $previous < $index; ++$previous) {
                $parts[] = $this->identifier($seek->orders[$previous]->field) . ' = ?';
                $bindings[] = $seek->values[$previous];
            }

            $parts[] = $this->identifier($order->field) . ($order->isDescending() ? ' < ?' : ' > ?');
            $bindings[] = $seek->values[$index];
            $branches[] = '(' . \implode(' AND ', $parts) . ')';
        }

        return ['sql' => '(' . $range . ' AND (' . \implode(' OR ', $branches) . '))', 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileIn(string $column, Criterion $criterion): array
    {
        /** @var list<mixed> $values */
        $values = \is_array($criterion->value) ? \array_values($criterion->value) : [];

        // The data layer refuses an empty list before it reaches here, because
        // "IN ()" is a syntax error on some drivers and silently matches
        // nothing on others.
        $placeholders = \implode(', ', \array_fill(0, \max(1, \count($values)), '?'));
        $keyword = $criterion->operator === Operator::In ? 'IN' : 'NOT IN';

        return ['sql' => $column . ' ' . $keyword . ' (' . $placeholders . ')', 'bindings' => $values];
    }

    /** @param list<Order> $orders */
    public function compileOrder(array $orders): string
    {
        if ($orders === []) {
            return '';
        }

        return ' ORDER BY ' . \implode(', ', \array_map(
            fn(Order $order): string => $this->identifier($order->field)
                . ($order->isDescending() ? ' DESC' : ' ASC'),
            $orders,
        ));
    }

    public function compileLimit(?int $limit, int $offset): string
    {
        if ($limit === null && $offset === 0) {
            return '';
        }

        // An offset without a limit needs one anyway on most drivers, and the
        // largest signed 64-bit value is the conventional way to say "all".
        $sql = ' LIMIT ' . \max(0, $limit ?? \PHP_INT_MAX);

        return $offset > 0 ? $sql . ' OFFSET ' . $offset : $sql;
    }

    // ---- writing ----------------------------------------------------------

    /**
     * One row.
     *
     * Given $returning, a dialect with Capability::Returning hands the
     * generated value of that column back from the statement itself, and
     * Connection::insert() reads it from there.
     *
     * @param array<string, mixed> $row
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileInsert(string $collection, array $row, ?string $returning = null): array
    {
        if ($row === []) {
            throw DatabaseException::noColumnsToInsert($collection);
        }

        $columns = \array_keys($row);

        return [
            'sql' => $this->insertStatement(
                $this->identifier($collection),
                $this->columnList($columns),
                '(' . \implode(', ', \array_fill(0, \count($columns), '?')) . ')',
                $returning,
            ),
            'bindings' => \array_values($row),
        ];
    }

    /**
     * INSERT INTO table (columns) VALUES values, handing back $returning where
     * the dialect can.
     *
     * @param string $values one or more tuples, already written
     */
    protected function insertStatement(string $table, string $columns, string $values, ?string $returning): string
    {
        $sql = 'INSERT INTO ' . $table . ' (' . $columns . ') VALUES ' . $values;

        return $returning !== null && $this->supports(Capability::Returning)
            ? $sql . ' RETURNING ' . $this->identifier($returning)
            : $sql;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileUpdate(string $collection, string $key, int|string $identity, array $changes): array
    {
        if ($changes === []) {
            throw DatabaseException::noRowsToUpdate($collection);
        }

        $assignments = \implode(', ', \array_map(
            fn(string $column): string => $this->identifier($column) . ' = ?',
            \array_keys($changes),
        ));

        return [
            'sql' => \sprintf(
                'UPDATE %s SET %s WHERE %s = ?',
                $this->identifier($collection),
                $assignments,
                $this->identifier($key),
            ),
            'bindings' => [...\array_values($changes), $identity],
        ];
    }

    // ---- writing many -----------------------------------------------------

    /**
     * How many placeholders one statement may carry on this driver.
     *
     * Each dialect states its own. Standard SQL assumes the lowest in common
     * use, SQLite's historic 999, because the cost of a smaller statement is a
     * second round trip and the cost of a larger one is an error on somebody
     * else's server.
     */
    public function maxBindings(): int
    {
        return 999;
    }

    /**
     * One multi-row INSERT per batch that fits the driver's placeholder limit.
     *
     * @param list<string>               $columns the column order every row is written in
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    public function compileInsertMany(string $collection, array $columns, array $rows): array
    {
        if ($columns === []) {
            throw DatabaseException::noColumnsToInsert($collection);
        }

        return $this->batches($this->identifier($collection), $columns, $rows, '');
    }

    /**
     * Multi-row INSERTs, each within the placeholder limit, each ending in
     * $suffix -- nothing for a plain insert, the conflict clause for an upsert.
     *
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    private function batches(string $table, array $columns, array $rows, string $suffix): array
    {
        $columnList = $this->columnList($columns);
        $tuple = '(' . \implode(', ', \array_fill(0, \count($columns), '?')) . ')';
        $perStatement = \max(1, \intdiv($this->maxBindings(), \count($columns)));

        $statements = [];

        foreach (\array_chunk($rows, $perStatement) as $batch) {
            $bindings = [];

            foreach ($batch as $row) {
                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
            }

            $statements[] = [
                'sql' => 'INSERT INTO ' . $table . ' (' . $columnList . ') VALUES '
                    . \implode(', ', \array_fill(0, \count($batch), $tuple)) . $suffix,
                'bindings' => $bindings,
            ];
        }

        return $statements;
    }

    // ---- the query builder: writing ---------------------------------------

    /**
     * @param array<string, mixed> $row values, or a RawExpression per column
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileQueryInsert(QueryState $state, array $row, ?string $returning = null): array
    {
        if ($row === []) {
            throw DatabaseException::noColumnsToInsert($state->table);
        }

        $values = [];
        $bindings = [];

        foreach ($row as $value) {
            $written = $this->value($value);
            $values[] = $written['sql'];
            $bindings = [...$bindings, ...$written['bindings']];
        }

        return [
            'sql' => $this->insertStatement(
                $this->writeTable($state),
                $this->columnList(\array_keys($row)),
                '(' . \implode(', ', $values) . ')',
                $returning,
            ),
            'bindings' => $bindings,
        ];
    }

    /**
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    public function compileQueryInsertMany(QueryState $state, array $columns, array $rows): array
    {
        if ($columns === []) {
            throw DatabaseException::noColumnsToInsert($state->table);
        }

        return $this->batches($this->writeTable($state), $columns, $rows, '');
    }

    /**
     * SET values bind first, then the conditions, in placeholder order.
     *
     * @param array<string, mixed> $changes values, or a RawExpression per column
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileQueryUpdate(QueryState $state, array $changes): array
    {
        if ($changes === []) {
            throw DatabaseException::noRowsToUpdate($state->table);
        }

        $assignments = [];
        $bindings = [];

        foreach ($changes as $column => $value) {
            $written = $this->value($value);
            $assignments[] = $this->identifier($column) . ' = ' . $written['sql'];
            $bindings = [...$bindings, ...$written['bindings']];
        }

        $where = $this->compileConditions($state->wheres);

        return [
            'sql' => 'UPDATE ' . $this->writeTable($state) . ' SET ' . \implode(', ', $assignments)
                . ($where['sql'] === '' ? '' : ' WHERE ' . $where['sql']),
            'bindings' => [...$bindings, ...$where['bindings']],
        ];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    public function compileQueryDelete(QueryState $state): array
    {
        $where = $this->compileConditions($state->wheres);

        return [
            'sql' => 'DELETE FROM ' . $this->writeTable($state)
                . ($where['sql'] === '' ? '' : ' WHERE ' . $where['sql']),
            'bindings' => $where['bindings'],
        ];
    }

    /**
     * Insert each row, or update the one a unique key says is already there.
     *
     * Only where the dialect has Capability::Upsert; nothing is emulated with a
     * read and a write, which would race.
     *
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $uniqueBy the columns of the unique key a conflict is judged by
     * @param list<string>               $update   what a conflict overwrites; empty leaves the row alone
     *
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    public function compileUpsert(QueryState $state, array $columns, array $rows, array $uniqueBy, array $update): array
    {
        if (!$this->supports(Capability::Upsert)) {
            throw DatabaseException::unsupported(Capability::Upsert, $this->driver());
        }

        if ($columns === []) {
            throw DatabaseException::noColumnsToInsert($state->table);
        }

        return $this->batches($this->writeTable($state), $columns, $rows, $this->upsertClause($uniqueBy, $update));
    }

    /**
     * The standard conflict clause, which PostgreSQL and SQLite share.
     *
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    protected function upsertClause(array $uniqueBy, array $update): string
    {
        $target = ' ON CONFLICT (' . $this->columnList($uniqueBy) . ')';

        if ($update === []) {
            return $target . ' DO NOTHING';
        }

        return $target . ' DO UPDATE SET ' . \implode(', ', \array_map(
            fn(string $column): string => $this->identifier($column) . ' = EXCLUDED.' . $this->identifier($column),
            $update,
        ));
    }

    /**
     * The table a write goes to. A qualified name is fine; an alias is not,
     * because the dialects disagree about where one goes in UPDATE and DELETE.
     */
    private function writeTable(QueryState $state): string
    {
        [$name, $alias] = $this->splitAlias($state->table);

        if ($alias !== null) {
            throw DatabaseException::aliasInWrite($state->table);
        }

        return $this->table($name);
    }

    /**
     * A value to write: bound, or a RawExpression written in parentheses.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function value(mixed $value): array
    {
        return $value instanceof RawExpression
            ? ['sql' => '(' . $value->sql . ')', 'bindings' => $value->bindings]
            : ['sql' => '?', 'bindings' => [$value]];
    }

    /**
     * @param list<Criterion>      $criteria
     * @param array<string, mixed> $changes
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileUpdateWhere(string $collection, array $criteria, array $changes): array
    {
        if ($changes === []) {
            throw DatabaseException::noRowsToUpdate($collection);
        }

        $assignments = \implode(', ', \array_map(
            fn(string $column): string => $this->identifier($column) . ' = ?',
            \array_keys($changes),
        ));

        $where = $this->compileWhere($criteria);

        return [
            'sql' => \sprintf('UPDATE %s SET %s%s', $this->identifier($collection), $assignments, $where['sql']),
            'bindings' => [...\array_values($changes), ...$where['bindings']],
        ];
    }

    /**
     * @param list<Criterion> $criteria
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileDeleteWhere(string $collection, array $criteria): array
    {
        $where = $this->compileWhere($criteria);

        return [
            'sql' => \sprintf('DELETE FROM %s%s', $this->identifier($collection), $where['sql']),
            'bindings' => $where['bindings'],
        ];
    }

    // ---- table structure --------------------------------------------------

    /**
     * The statements that create $table: the CREATE TABLE, with its foreign
     * keys inside it, then one CREATE INDEX per index.
     *
     * Every name is checked like any other. A string default cannot be bound,
     * so $literal -- the driver's own quoting, handed in by Structure\Tables --
     * writes it; this class never escapes a value itself.
     *
     * @param \Closure(string): string $literal
     *
     * @return list<string>
     */
    public function compileCreateTable(Table $table, \Closure $literal): array
    {
        $table->check();

        $lines = [];

        foreach ($table->columns() as $column) {
            $lines[] = $this->columnDefinition($column, $literal);
        }

        foreach ($table->foreignKeys() as $key) {
            $lines[] = $this->foreignKeyClause($table, $key);
        }

        $statements = ['CREATE TABLE ' . $this->structureTable($table->name) . ' (' . \implode(', ', $lines) . ')' . $this->tableOptions()];

        foreach ($table->indexes() as $index) {
            $statements[] = $this->compileIndex($table, $index);
        }

        return $statements;
    }

    /**
     * The statements that change $table as it describes, one change each:
     * first what goes -- foreign keys, indexes, columns -- then renames, then
     * what comes: columns, indexes, foreign keys. So a column can be dropped
     * once its index is gone, and a new index can name a renamed column.
     *
     * Everything is compiled before anything is returned, so a change this
     * database cannot make is refused with nothing run.
     *
     * @param \Closure(string): string $literal
     *
     * @return list<string>
     */
    public function compileAlterTable(Table $table, \Closure $literal): array
    {
        // A drop needs no column types, but nobody has checked this database's
        // ALTER TABLE either.
        if ($this->driver() === '') {
            throw DatabaseException::noStructureDialect('');
        }

        $table->check();

        $statements = [];

        foreach ($table->droppedForeignKeys() as $column) {
            $statements[] = $this->compileDropForeignKey($table, $column);
        }

        foreach ($table->droppedIndexes() as $index) {
            $statements[] = $this->compileDropIndex($table, $index);
        }

        foreach ($table->droppedColumns() as $column) {
            $statements[] = $this->compileDropColumn($table, $column);
        }

        foreach ($table->renamedColumns() as $from => $to) {
            $statements[] = $this->compileRenameColumn($table, $from, $to);
        }

        foreach ($table->columns() as $column) {
            $statements[] = $this->compileAddColumn($table, $column, $literal);
        }

        foreach ($table->indexes() as $index) {
            $statements[] = $this->compileIndex($table, $index);
        }

        foreach ($table->foreignKeys() as $key) {
            $statement = $this->compileAddForeignKey($table, $key);

            if ($statement !== null) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    public function compileDropTable(string $table): string
    {
        return 'DROP TABLE ' . $this->structureTable($table);
    }

    /**
     * A query answering how many tables named $table the connection can see
     * in its current schema: 1 or 0. Every database keeps its own catalogue.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function compileTableExists(string $table): array
    {
        throw DatabaseException::noStructureDialect($this->driver());
    }

    /**
     * A query that tries, once and without waiting, to take the lock named
     * $name for this session, answering 1 when it did. Null where the
     * database needs no lock of its own, as SQLite, with its one writer.
     *
     * The lock belongs to the session, not a transaction, so it holds across
     * the commits in between; compileReleaseLock() gives it back. Whoever
     * waits for it polls.
     *
     * @return array{sql: string, bindings: list<mixed>}|null
     */
    public function compileAcquireLock(string $name): ?array
    {
        throw DatabaseException::noStructureDialect($this->driver());
    }

    /** @return array{sql: string, bindings: list<mixed>}|null */
    public function compileReleaseLock(string $name): ?array
    {
        throw DatabaseException::noStructureDialect($this->driver());
    }

    /**
     * The generated key's whole definition after its name. Every dialect has
     * its own way of saying "a 64-bit integer the database counts up".
     */
    protected function idColumn(): string
    {
        throw DatabaseException::noStructureDialect($this->driver());
    }

    /** The database's type for a column. Standard SQL has no dialect to answer, so it refuses. */
    protected function columnType(Column $column): string
    {
        throw DatabaseException::noStructureDialect($this->driver());
    }

    /** Anything the database needs said after the column list. Standard SQL needs nothing. */
    protected function tableOptions(): string
    {
        return '';
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    /** RESTRICT, CASCADE and the rest, as the statement spells them. */
    protected function foreignKeyAction(string $action): string
    {
        return \strtoupper($action);
    }

    protected function compileIndex(Table $table, Index $index): string
    {
        return \sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $index->unique ? 'UNIQUE ' : '',
            $this->identifier($this->indexName($table, $index)),
            $this->structureTable($table->name),
            \implode(', ', \array_map($this->identifier(...), $index->columns)),
        );
    }

    /** @param \Closure(string): string $literal */
    protected function compileAddColumn(Table $table, Column $column, \Closure $literal): string
    {
        return 'ALTER TABLE ' . $this->structureTable($table->name) . ' ADD COLUMN ' . $this->columnDefinition($column, $literal);
    }

    protected function compileDropColumn(Table $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->structureTable($table->name) . ' DROP COLUMN ' . $this->identifier($column);
    }

    protected function compileRenameColumn(Table $table, string $from, string $to): string
    {
        return \sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->structureTable($table->name),
            $this->identifier($from),
            $this->identifier($to),
        );
    }

    /**
     * An index lives in its table's schema, and is named there: DROP INDEX
     * takes the schema, not the table.
     */
    protected function compileDropIndex(Table $table, Index $index): string
    {
        $schema = \strrpos($table->name, '.');
        $name = $this->indexName($table, $index);

        return 'DROP INDEX ' . $this->structureTable($schema === false ? $name : \substr($table->name, 0, $schema) . '.' . $name);
    }

    /** Null where the key was already written with its column, as SQLite writes one. */
    protected function compileAddForeignKey(Table $table, ForeignKey $key): ?string
    {
        return 'ALTER TABLE ' . $this->structureTable($table->name) . ' ADD ' . $this->foreignKeyClause($table, $key);
    }

    protected function compileDropForeignKey(Table $table, string $column): string
    {
        return \sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            $this->structureTable($table->name),
            $this->identifier($table->nameFor([$column], 'foreign')),
        );
    }

    protected function indexName(Table $table, Index $index): string
    {
        return $table->nameFor($index->columns, $index->unique ? 'unique' : 'index');
    }

    /** @param \Closure(string): string $literal */
    protected function columnDefinition(Column $column, \Closure $literal): string
    {
        if ($column->type === ColumnType::Id) {
            return $this->identifier($column->name) . ' ' . $this->idColumn();
        }

        $sql = $this->identifier($column->name) . ' ' . $this->columnType($column)
            . ($column->isNullable() ? ' NULL' : ' NOT NULL');

        if ($column->hasDefault()) {
            $default = $column->defaultValue();

            $sql .= ' DEFAULT ' . match (true) {
                $default === null => 'NULL',
                \is_bool($default) => $this->booleanLiteral($default),
                \is_int($default) => (string) $default,
                default => $this->stringDefault($column, $literal($default)),
            };
        }

        return $column->isPrimary() ? $sql . ' PRIMARY KEY' : $sql;
    }

    /** A string default, as the driver quoted it. A dialect that marks its literals says so here. */
    protected function stringDefault(Column $column, string $quoted): string
    {
        return $quoted;
    }

    private function foreignKeyClause(Table $table, ForeignKey $key): string
    {
        return \sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) %s',
            $this->identifier($table->nameFor([$key->column], 'foreign')),
            $this->identifier($key->column),
            $this->foreignKeyReference($key),
        );
    }

    /** REFERENCES and the actions: the part of a foreign key a column can carry by itself. */
    protected function foreignKeyReference(ForeignKey $key): string
    {
        return \sprintf(
            'REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $this->structureTable((string) $key->table()),
            $this->identifier($key->referencedColumn()),
            $this->foreignKeyAction($key->deleteAction()),
            $this->foreignKeyAction($key->updateAction()),
        );
    }

    /** A table to create, alter or drop: a name, or schema.name. Never an alias. */
    protected function structureTable(string $name): string
    {
        if (\substr_count($name, '.') > 1) {
            throw DatabaseException::unsafeIdentifier($name);
        }

        return $this->qualified($name);
    }

    // ---- isolation and retry ----------------------------------------------

    /**
     * Whether this database can run a transaction at $level.
     *
     * Standard SQL claims none: a driver without a dialect is one nobody has
     * checked, and a level assumed there could be quietly weaker on the server.
     */
    public function supportsIsolation(IsolationLevel $level): bool
    {
        return false;
    }

    /**
     * The statements that give the next transaction $level: one run before
     * BEGIN, one run first inside it, and one run after it ends to put the
     * session back. Each is null where the database needs none.
     *
     * This standard form is PostgreSQL's, where the level is set inside the
     * transaction and ends with it.
     *
     * @return array{before: string|null, after: string|null, reset: string|null}
     */
    public function compileIsolation(IsolationLevel $level): array
    {
        if (!$this->supportsIsolation($level)) {
            throw DatabaseException::isolationUnsupported($level, $this->driver());
        }

        return ['before' => null, 'after' => 'SET TRANSACTION ISOLATION LEVEL ' . $level->value, 'reset' => null];
    }

    /**
     * Whether a failure is one that running the same transaction again can
     * cure: a deadlock, or a serialization failure. Nothing else is -- not a
     * constraint, not a syntax error, not a lost connection -- and retrying
     * those would repeat the failure or, worse, the side effects before it.
     *
     * SQLSTATE 40001 is the standard's serialization failure, which MySQL and
     * SQL Server also report their deadlocks as; 40P01 is PostgreSQL's deadlock.
     */
    public function isRetryable(\PDOException $failure): bool
    {
        return \in_array(self::sqlState($failure), ['40001', '40P01'], true);
    }

    protected static function sqlState(\PDOException $failure): string
    {
        $state = $failure->errorInfo[0] ?? $failure->getCode();

        return \is_string($state) || \is_int($state) ? (string) $state : '';
    }

    // ---- savepoints -------------------------------------------------------

    /**
     * The standard statements. A dialect that writes them differently overrides
     * them, and one without Capability::Savepoints is never asked for them.
     */
    public function compileSavepoint(string $name): string
    {
        return 'SAVEPOINT ' . $this->plainName($name);
    }

    /**
     * Null where there is nothing to say: a database that cannot release a
     * savepoint keeps it until the transaction ends.
     */
    public function compileReleaseSavepoint(string $name): ?string
    {
        return 'RELEASE SAVEPOINT ' . $this->plainName($name);
    }

    public function compileRollbackToSavepoint(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . $this->plainName($name);
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    public function compileDelete(string $collection, string $key, int|string $identity): array
    {
        return [
            'sql' => \sprintf(
                'DELETE FROM %s WHERE %s = ?',
                $this->identifier($collection),
                $this->identifier($key),
            ),
            'bindings' => [$identity],
        ];
    }
}
