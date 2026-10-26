<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * One page of a keyset walk: the items, and where to go from here.
 *
 * There is no total and no page number, and that is the trade that makes it
 * fast. Page contains both, paid for with an offset and a count on every page;
 * this pays for neither, so the thousandth page costs what the first does.
 * Use Page for "page 3 of 12", this for a feed, an export or an API list.
 *
 * The cursors are strings for a query parameter: ?cursor=<nextCursor()> for
 * the next page, ?cursor=<previousCursor()> for the one before. Each is null
 * where there is nowhere to go.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class CursorPage implements \Countable, \IteratorAggregate
{
    /** @param list<mixed> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $perPage,
        private readonly ?string $next = null,
        private readonly ?string $previous = null,
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

    public function nextCursor(): ?string
    {
        return $this->next;
    }

    public function previousCursor(): ?string
    {
        return $this->previous;
    }

    public function hasMore(): bool
    {
        return $this->next !== null;
    }

    public function isFirst(): bool
    {
        return $this->previous === null;
    }
}
