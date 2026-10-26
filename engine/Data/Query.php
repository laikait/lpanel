<?php

declare(strict_types=1);

namespace App\Engine\Data;

use App\Engine\Model\Model;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelManager;
use App\Engine\Model\ReadModel;

/**
 * A description of a read, and the ways to run it.
 *
 * Building a query does nothing. No source is touched until a terminal method
 * is called, so a query can be passed around, refined by a repository method,
 * narrowed by a caller, and still cost nothing if it is never used.
 *
 *     $query->whereIs('active', true)
 *           ->where('balance', Operator::Gt, 0)
 *           ->orderBy('name')
 *           ->limit(50)
 *           ->get();
 *
 * Queries are immutable. Every builder method returns a new query, so a
 * repository can hold a base query and hand out narrowed copies without any
 * caller being able to change what the next one sees.
 *
 * **How much hydration is the caller's choice**, and that is the point. The
 * same query answers with raw arrays, single scalars, a column, read models or
 * domain models. A list screen that builds ten thousand domain objects to show
 * three columns is the most common way a fast query becomes a slow page, so the
 * cheap options are first-class rather than an optimisation to discover later.
 *
 * Two things this deliberately does not have:
 *
 *   **No OR.** Criteria are combined with AND. A general boolean tree is the
 *   point at which a query builder becomes a query language, with its own
 *   precedence rules and its own bugs. A read that genuinely needs one is a
 *   named repository method over SQL the database layer runs directly, where it
 *   can be seen and explained.
 *
 *   **No join.** A join is a relational idea, and an interface five methods
 *   wide cannot honour it for every kind of source. Data from two places is
 *   loaded in two queries and linked explicitly, which the model layer already
 *   does and which cannot degrade into N+1 the way a lazy association can.
 */
final class Query
{
    /** @var list<Criterion> */
    private array $criteria = [];

    /** @var list<Order> */
    private array $orders = [];

    /** @var list<string> */
    private array $columns = [];

    private ?int $limit = null;

    private int $offset = 0;

    /**
     * @param class-string<Model>|null $model the class get() builds, if any
     * @param string                   $key   the unique field that breaks ties in a cursor walk
     */
    private function __construct(
        private readonly DataSource $source,
        private readonly string $collection,
        private readonly ModelManager $models,
        private readonly ?string $model = null,
        private readonly string $key = 'id',
    ) {}

    /**
     * @param class-string<Model>|null $model
     */
    public static function on(
        DataSource $source,
        string $collection,
        ModelManager $models,
        ?string $model = null,
        string $key = 'id',
    ): self {
        return new self($source, $collection, $models, $model, $key);
    }

    // ---- building ---------------------------------------------------------

    public function where(string $field, Operator $operator, mixed $value = null): self
    {
        return $this->withCriterion(new Criterion($field, $operator, $value));
    }

    public function whereIs(string $field, mixed $value): self
    {
        return $this->withCriterion(new Criterion($field, Operator::Eq, $value));
    }

    public function whereNot(string $field, mixed $value): self
    {
        return $this->withCriterion(new Criterion($field, Operator::NotEq, $value));
    }

    /** @param list<mixed> $values */
    public function whereIn(string $field, array $values): self
    {
        return $this->withCriterion(new Criterion($field, Operator::In, $values));
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $field, array $values): self
    {
        return $this->withCriterion(new Criterion($field, Operator::NotIn, $values));
    }

    public function whereNull(string $field): self
    {
        return $this->withCriterion(new Criterion($field, Operator::IsNull));
    }

    public function whereNotNull(string $field): self
    {
        return $this->withCriterion(new Criterion($field, Operator::IsNotNull));
    }

    /** SQL LIKE semantics: % matches any run of characters, _ matches one. */
    public function whereLike(string $field, string $pattern): self
    {
        return $this->withCriterion(new Criterion($field, Operator::Like, $pattern));
    }

    /**
     * Read only these columns.
     *
     * The cheapest optimisation there is, and the one most often missed: a
     * table with a text column costs the same to select as one without until
     * somebody writes this line.
     */
    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->columns = \array_values($columns);

        return $clone;
    }

    public function orderBy(string $field, Direction $direction = Direction::Asc): self
    {
        $clone = clone $this;
        $clone->orders = [...$this->orders, new Order($field, $direction)];

        return $clone;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, Direction::Desc);
    }

    public function limit(?int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = \max(0, $offset);

        return $clone;
    }

    private function withCriterion(Criterion $criterion): self
    {
        $clone = clone $this;
        $clone->criteria = [...$this->criteria, $criterion];

        return $clone;
    }

    // ---- what a source needs to know --------------------------------------

    public function collection(): string
    {
        return $this->collection;
    }

    /** @return list<Criterion> */
    public function criteria(): array
    {
        return $this->criteria;
    }

    /** @return list<Order> */
    public function orders(): array
    {
        return $this->orders;
    }

    /** @return list<string> empty means every column */
    public function columns(): array
    {
        return $this->columns;
    }

    public function limitValue(): ?int
    {
        return $this->limit;
    }

    public function offsetValue(): int
    {
        return $this->offset;
    }

    /** @return class-string<Model>|null */
    public function modelClass(): ?string
    {
        return $this->model;
    }

    /**
     * The query as plain data, for logging and for tests that want to assert on
     * what would be read without reading anything.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'collection' => $this->collection,
            'columns' => $this->columns,
            'criteria' => \array_map(
                static fn(Criterion $criterion): array => $criterion->describe(),
                $this->criteria,
            ),
            'orders' => \array_map(
                static fn(Order $order): array => ['field' => $order->field, 'direction' => $order->direction->value],
                $this->orders,
            ),
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }

    // ---- running it: raw --------------------------------------------------

    /**
     * The matching rows, exactly as the source produced them.
     *
     * Nothing is built, nothing is tracked, nothing is mapped. For a report, an
     * export or an aggregate, this is the whole answer.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->source->fetch($this) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function firstRow(): ?array
    {
        return $this->limit(1)->rows()[0] ?? null;
    }

    /**
     * One column across the matching rows.
     *
     * Selecting just that column as well, because reading a whole row to keep
     * one field is the thing this method exists to avoid.
     *
     * @return list<mixed>
     */
    public function column(string $field): array
    {
        $values = [];

        foreach ($this->select($field)->rows() as $row) {
            if (\array_key_exists($field, $row)) {
                /** @var mixed $value */
                $value = $row[$field];
                $values[] = $value;
            }
        }

        return $values;
    }

    /** One field of the first matching row. */
    public function value(string $field): mixed
    {
        $row = $this->select($field)->limit(1)->rows()[0] ?? null;

        return $row[$field] ?? null;
    }

    public function count(): int
    {
        return $this->source->count($this);
    }

    public function exists(): bool
    {
        return $this->limit(1)->count() > 0;
    }

    // ---- running it: projected -------------------------------------------

    /**
     * Read models, built from only the columns they declare.
     *
     * The columns come from the read model's own constructor, so asking for a
     * projection also narrows what is read. This is the answer to a list screen
     * over a large table.
     *
     * @template T of ReadModel
     *
     * @param class-string<T> $readModel
     *
     * @return list<T>
     */
    public function into(string $readModel): array
    {
        $records = [];

        foreach ($this->narrowedTo($readModel)->rows() as $row) {
            $records[] = $this->models->project($readModel, $row);
        }

        return $records;
    }

    /**
     * @template T of ReadModel
     *
     * @param class-string<T> $readModel
     *
     * @return T|null
     */
    public function firstInto(string $readModel): ?ReadModel
    {
        return $this->limit(1)->into($readModel)[0] ?? null;
    }

    // ---- running it: domain models ---------------------------------------

    /** Full domain models, hydrated and identity-mapped. */
    public function get(): ModelCollection
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'get');

        return $this->models->hydrateAll($model, $this->source->fetch($this));
    }

    public function first(): ?Model
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'first');
        $row = $this->limit(1)->firstRow();

        return $row === null ? null : $this->models->hydrate($model, $row);
    }

    /**
     * The matching models one at a time.
     *
     * The source decides whether it can genuinely stream; a generator here at
     * least means the caller is not handed an array of everything.
     *
     * @return \Generator<int, Model>
     */
    public function stream(): \Generator
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'stream');

        foreach ($this->source->fetch($this) as $row) {
            yield $this->models->hydrate($model, $row);
        }
    }

    // ---- running it: in batches ------------------------------------------

    /**
     * Walk the whole result in batches, calling back with each one.
     *
     * For a job over a table too large to hold in memory. Returning nothing is
     * the usual case; returning exactly false stops the walk early.
     *
     * The walk is by key, not by offset: each batch starts after the last row
     * of the one before, in the query's order with the key as the final
     * tie-breaker. So every batch costs the same however far in, and rows
     * added or deleted while it runs -- by the callback, say -- neither shift
     * a row into a batch twice nor out of every batch.
     *
     * @param \Closure(ModelCollection): mixed $callback
     */
    public function chunk(int $size, \Closure $callback): void
    {
        if ($size < 1) {
            throw DataException::negativePage(1, $size);
        }

        $model = $this->model ?? throw DataException::noModel($this->collection, 'chunk');
        $orders = $this->seekOrders();
        $walk = $this->walking($orders);
        $boundary = null;

        while (true) {
            $query = $walk->limit($size)->offset($boundary === null ? $this->offset : 0);

            if ($boundary !== null) {
                $query = $query->withCriterion(Criterion::seek(new Seek($orders, $boundary)));
            }

            $rows = $query->rows();

            if ($rows === []) {
                return;
            }

            if ($callback($this->models->hydrateAll($model, $rows)) === false) {
                return;
            }

            if (\count($rows) < $size) {
                return;
            }

            $boundary = Cursor::at($orders, $rows[\count($rows) - 1], false)->values;
        }
    }

    /**
     * One page of domain models by cursor: fast at any depth, with no total.
     *
     *     $page = $query->orderByDesc('created_at')->cursor(20, $request->query('cursor'));
     *
     * The first page is cursor(20). Link the next with
     * ?cursor=<nextCursor()> and the previous with ?cursor=<previousCursor()>;
     * both are null where there is nowhere to go. The order is the query's,
     * with the key added last so that no two rows tie; with no order at all it
     * is the key ascending. An index on the order columns is what makes it
     * fast.
     *
     * page() is the alternative when a page number and a total are wanted, at
     * the price of an offset and a count on every page.
     *
     * @throws DataException when the cursor is malformed or was made for another order
     */
    public function cursor(int $perPage, ?string $cursor = null): CursorPage
    {
        $model = $this->model ?? throw DataException::noModel($this->collection, 'cursor');

        return $this->cursorWalk(
            $this,
            $perPage,
            $cursor,
            fn(array $rows): array => $this->models->hydrateAll($model, $rows)->all(),
        );
    }

    /**
     * One page of read models by cursor: cursor() for a list screen.
     *
     * @param class-string<ReadModel> $readModel
     *
     * @throws DataException when the cursor is malformed or was made for another order
     */
    public function cursorInto(string $readModel, int $perPage, ?string $cursor = null): CursorPage
    {
        return $this->cursorWalk(
            $this->narrowedTo($readModel),
            $perPage,
            $cursor,
            fn(array $rows): array => \array_map(
                fn(array $row): ReadModel => $this->models->project($readModel, $row),
                $rows,
            ),
        );
    }

    /** One page of domain models, with the totals needed to show a pager. */
    public function page(int $page, int $perPage): Page
    {
        [$page, $perPage] = $this->pageBounds($page, $perPage);

        return new Page(
            $this->limit($perPage)->offset(($page - 1) * $perPage)->get()->all(),
            $this->count(),
            $page,
            $perPage,
        );
    }

    /**
     * One page of read models.
     *
     * The paginated form of into(), and the one a list screen should reach for:
     * it reads the declared columns and never builds a domain object.
     *
     * @param class-string<ReadModel> $readModel
     */
    public function pageInto(string $readModel, int $page, int $perPage): Page
    {
        [$page, $perPage] = $this->pageBounds($page, $perPage);

        return new Page(
            $this->limit($perPage)->offset(($page - 1) * $perPage)->into($readModel),
            $this->count(),
            $page,
            $perPage,
        );
    }

    /** @return array{0: int, 1: int} */
    private function pageBounds(int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            throw DataException::negativePage($page, $perPage);
        }

        return [$page, $perPage];
    }

    /**
     * @param \Closure(list<array<string, mixed>>): list<mixed> $build
     */
    private function cursorWalk(self $query, int $perPage, ?string $cursor, \Closure $build): CursorPage
    {
        if ($perPage < 1) {
            throw DataException::cursorPageSize($perPage);
        }

        $orders = $query->seekOrders();
        $position = $cursor === null || $cursor === '' ? null : Cursor::decode($cursor, $orders);
        $backward = $position !== null && $position->backward;

        // Backward is the same walk with every direction flipped, from the
        // same boundary, and the rows turned round again afterwards.
        $walkOrders = $backward ? self::flipped($orders) : $orders;
        $walk = $query->walking($walkOrders)->limit($perPage + 1)->offset(0);

        if ($position !== null) {
            $walk = $walk->withCriterion(Criterion::seek(new Seek($walkOrders, $position->values)));
        }

        $rows = $walk->rows();

        // One row more than a page answers "is there another" without a count.
        $more = \count($rows) > $perPage;
        $rows = \array_slice($rows, 0, $perPage);

        if ($backward) {
            $rows = \array_reverse($rows);
        }

        if ($rows === []) {
            return new CursorPage([], $perPage);
        }

        $hasNext = $backward ? $position !== null : $more;
        $hasPrevious = $backward ? $more : $position !== null;

        return new CursorPage(
            $build($rows),
            $perPage,
            $hasNext ? Cursor::at($orders, $rows[\count($rows) - 1], false)->encode() : null,
            $hasPrevious ? Cursor::at($orders, $rows[0], true)->encode() : null,
        );
    }

    /**
     * The query's order, made total: the key is added last unless it is
     * already there, so no two rows can tie.
     *
     * The key takes the direction of the last column before it. An index on
     * (created_at, id) read backwards is created_at DESC, id DESC; a key added
     * ascending after a descending column would make the database sort the
     * page itself instead of reading it off the index, which on a large table
     * is most of the time the cursor exists to save.
     *
     * @return list<Order>
     */
    private function seekOrders(): array
    {
        foreach ($this->orders as $order) {
            if ($order->field === $this->key) {
                return $this->orders;
            }
        }

        $direction = $this->orders === [] ? Direction::Asc : $this->orders[\count($this->orders) - 1]->direction;

        return [...$this->orders, new Order($this->key, $direction)];
    }

    /**
     * This query in $orders, reading at least the order columns.
     *
     * A cursor is built from the order columns of the last row, so a narrowed
     * select gets them added rather than failing on the next page.
     *
     * @param list<Order> $orders
     */
    private function walking(array $orders): self
    {
        $clone = clone $this;
        $clone->orders = $orders;

        if ($clone->columns !== []) {
            foreach ($orders as $order) {
                if (!\in_array($order->field, $clone->columns, true)) {
                    $clone->columns[] = $order->field;
                }
            }
        }

        return $clone;
    }

    /**
     * @param list<Order> $orders
     *
     * @return list<Order>
     */
    private static function flipped(array $orders): array
    {
        return \array_map(
            static fn(Order $order): Order => new Order(
                $order->field,
                $order->isDescending() ? Direction::Asc : Direction::Desc,
            ),
            $orders,
        );
    }

    /**
     * Narrow the selection to the columns a read model declares.
     *
     * An explicit select() wins: the caller knew something the read model does
     * not, such as a column needed by an ordering.
     *
     * @param class-string<ReadModel> $readModel
     */
    private function narrowedTo(string $readModel): self
    {
        if ($this->columns !== []) {
            return $this;
        }

        $columns = $this->models->columnsFor($readModel);

        return $columns === [] ? $this : $this->select(...$columns);
    }
}
