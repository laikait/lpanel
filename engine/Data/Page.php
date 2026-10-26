<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * One page of results and enough context to ask for another.
 *
 * The total is a separate count against the same criteria, which is one extra
 * read per page. That is the price of knowing how many pages there are, it is
 * paid explicitly here rather than hidden. It also reads past every earlier
 * row to reach the page, which is slow deep into a large table; a caller that
 * does not need numbered pages should use Query::cursor() and CursorPage.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Page implements \Countable, \IteratorAggregate
{
    /** @param list<mixed> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /** @return list<mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function pages(): int
    {
        return $this->perPage < 1 ? 0 : (int) \ceil($this->total / $this->perPage);
    }

    public function hasMore(): bool
    {
        return $this->page < $this->pages();
    }

    public function isFirst(): bool
    {
        return $this->page <= 1;
    }

    public function isLast(): bool
    {
        return !$this->hasMore();
    }

    /** The one-based position of the first item on this page, within the whole. */
    public function from(): int
    {
        return $this->isEmpty() ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return $this->isEmpty() ? 0 : $this->from() + $this->count() - 1;
    }

    /**
     * The same page with its items transformed.
     *
     * For turning already-loaded models into something else. To avoid loading
     * them in the first place, ask the query for read models instead.
     *
     * @param \Closure(mixed): mixed $callback
     */
    public function map(\Closure $callback): self
    {
        return new self(\array_map($callback, $this->items), $this->total, $this->page, $this->perPage);
    }

    /** @return array{total: int, page: int, per_page: int, pages: int, count: int} */
    public function meta(): array
    {
        return [
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'pages' => $this->pages(),
            'count' => $this->count(),
        ];
    }
}
