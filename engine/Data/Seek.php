<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * "The rows after this one", in a given order: the criterion behind keyset
 * pagination.
 *
 * An offset makes the source read and discard every row before the page; a
 * seek goes straight to the boundary through the index on the order columns,
 * so page 5,000 costs what page 1 does. The boundary is the last row already
 * seen -- its value in each order column -- and "after" means after in that
 * order, column by column, so ties on the first column fall through to the
 * next, and the last column (the key) breaks every tie.
 *
 * It is the one criterion that is not about a single field, because "after
 * (created_at, id)" is (created_at > x) OR (created_at = x AND id > y). The
 * data layer otherwise has no OR, by design; this is that one shape, closed,
 * rather than a general one.
 */
final class Seek
{
    /**
     * @param list<Order> $orders the order to seek in; the last should be unique
     * @param list<mixed> $values the boundary row's value in each order column
     *
     * @throws DataException when the counts differ or a value is null
     */
    public function __construct(
        public readonly array $orders,
        public readonly array $values,
    ) {
        if ($orders === [] || \count($orders) !== \count($values)) {
            throw DataException::invalidCursor('it does not match the query\'s order');
        }

        foreach ($orders as $index => $order) {
            if ($values[$index] === null) {
                throw DataException::seekOnNull($order->field);
            }
        }
    }

    /**
     * Whether $row comes strictly after the boundary in this order.
     *
     * @param array<string, mixed> $row
     */
    public function after(array $row): bool
    {
        foreach ($this->orders as $index => $order) {
            $comparison = self::compare($row[$order->field] ?? null, $this->values[$index]);

            if ($order->isDescending()) {
                $comparison = -$comparison;
            }

            if ($comparison !== 0) {
                return $comparison > 0;
            }
        }

        // Equal in every column is the boundary row itself.
        return false;
    }

    /** "created_at, id": the columns, for describing the query. */
    public function label(): string
    {
        return \implode(', ', \array_map(static fn(Order $order): string => $order->field, $this->orders));
    }

    /**
     * The order two values sort in, as ArraySource sorts them.
     *
     * Nulls last ascending, strings byte-wise, everything else with <=>. Kept
     * in one place so that seeking and sorting in memory can never disagree
     * about which row comes next.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => 1,
            $b === null => -1,
            \is_string($a) && \is_string($b) => \strcmp($a, $b) <=> 0,
            default => $a <=> $b,
        };
    }
}
