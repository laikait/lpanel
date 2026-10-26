<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * Rows held in memory.
 *
 * This is not a placeholder for the real thing. A repository tested against
 * this is tested at full speed, with no database to install, no fixtures to
 * load and no cleanup between tests -- and because it is written against the
 * same DataSource interface as everything else, the test is exercising the real
 * repository, the real query and the real hydration, not a mock that agrees
 * with whatever the code does.
 *
 * It honours the same semantics a SQL source must: criteria combine with AND,
 * null never takes part in an ordering comparison, LIKE uses % and _, and a
 * count ignores limit and offset. Where the two could disagree, this follows
 * SQL, because the day the source changes is not the day to discover a
 * difference.
 *
 * What it is not is a database. There are no transactions, no concurrency and
 * no durability, and sorting a hundred thousand rows in PHP is not a plan.
 */
final class ArraySource implements DataSource, BulkWrites
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $collections = [];

    /** @param array<string, list<array<string, mixed>>> $collections */
    public function __construct(array $collections = [])
    {
        $this->collections = $collections;
    }

    /** @param list<array<string, mixed>> $rows */
    public function seed(string $collection, array $rows): void
    {
        $this->collections[$collection] = $rows;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $collection): array
    {
        return $this->collections[$collection] ?? [];
    }

    public function has(string $collection): bool
    {
        return isset($this->collections[$collection]);
    }

    public function truncate(string $collection): void
    {
        $this->collections[$collection] = [];
    }

    // ---- reading ----------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function fetch(Query $query): iterable
    {
        $rows = $this->matching($query);
        $rows = $this->sorted($rows, $query->orders());

        $limit = $query->limitValue();
        $rows = \array_slice($rows, $query->offsetValue(), $limit ?? null);

        $columns = $query->columns();

        if ($columns === []) {
            return $rows;
        }

        // Column selection has to be real here too, or a test passes against
        // this source and fails against one where the column is genuinely
        // absent from the result.
        return \array_map(
            static fn(array $row): array => \array_intersect_key($row, \array_flip($columns)),
            $rows,
        );
    }

    /** The count ignores limit and offset, the way a SQL count does. */
    public function count(Query $query): int
    {
        return \count($this->matching($query));
    }

    /** @return list<array<string, mixed>> */
    private function matching(Query $query): array
    {
        $rows = $this->collections[$query->collection()] ?? [];

        foreach ($query->criteria() as $criterion) {
            $rows = \array_values(\array_filter(
                $rows,
                static fn(array $row): bool => $criterion->matchesRow($row),
            ));
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<Order>                $orders
     *
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, array $orders): array
    {
        if ($orders === []) {
            return $rows;
        }

        \usort($rows, static function (array $left, array $right) use ($orders): int {
            foreach ($orders as $order) {
                /** @var mixed $a */
                $a = $left[$order->field] ?? null;
                /** @var mixed $b */
                $b = $right[$order->field] ?? null;

                // Nulls sort last ascending, which is one of the two answers
                // SQL engines give and the less surprising one. Shared with
                // Seek, so a cursor and a sort agree on what comes next.
                $comparison = Seek::compare($a, $b);

                if ($comparison !== 0) {
                    return $order->isDescending() ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    // ---- writing ----------------------------------------------------------

    public function insert(string $collection, string $key, array $row): int|string|null
    {
        $this->collections[$collection] ??= [];

        // An identity the row already carries is kept -- a country keyed by its
        // code assigns its own -- and otherwise one is generated the way an
        // auto-increment column would.
        /** @var mixed $identity */
        $identity = $row[$key] ?? null;

        if ($identity === null) {
            $identity = $this->nextIdentity($collection, $key);
            $row[$key] = $identity;
        }

        $this->collections[$collection][] = $row;

        return \is_int($identity) || \is_string($identity) ? $identity : null;
    }

    public function update(string $collection, string $key, int|string $identity, array $changes): int
    {
        $changed = 0;

        foreach ($this->collections[$collection] ?? [] as $index => $row) {
            if (($row[$key] ?? null) === $identity) {
                $this->collections[$collection][$index] = [...$row, ...$changes];
                ++$changed;
            }
        }

        return $changed;
    }

    public function delete(string $collection, string $key, int|string $identity): int
    {
        $rows = $this->collections[$collection] ?? [];
        $remaining = \array_values(\array_filter(
            $rows,
            static fn(array $row): bool => ($row[$key] ?? null) !== $identity,
        ));

        $this->collections[$collection] = $remaining;

        return \count($rows) - \count($remaining);
    }

    // ---- bulk writes ------------------------------------------------------

    /**
     * Row by row underneath, which is fine in memory and is the point: the
     * refusals, the counts and the identities a later read sees are the same as
     * a database's, so a test of a bulk write here is a test of it there.
     */
    public function insertMany(string $collection, string $key, array $rows): int
    {
        Bulk::columns($collection, $rows);

        foreach ($rows as $row) {
            $this->insert($collection, $key, $row);
        }

        return \count($rows);
    }

    public function updateWhere(Query $query, array $changes): int
    {
        $criteria = Bulk::criteria($query, 'update');

        if ($changes === []) {
            return 0;
        }

        $changed = 0;

        foreach ($this->collections[$query->collection()] ?? [] as $index => $row) {
            if (self::matchesAll($row, $criteria)) {
                $this->collections[$query->collection()][$index] = [...$row, ...$changes];
                ++$changed;
            }
        }

        return $changed;
    }

    public function deleteWhere(Query $query): int
    {
        $criteria = Bulk::criteria($query, 'delete');
        $rows = $this->collections[$query->collection()] ?? [];

        $remaining = \array_values(\array_filter(
            $rows,
            static fn(array $row): bool => !self::matchesAll($row, $criteria),
        ));

        $this->collections[$query->collection()] = $remaining;

        return \count($rows) - \count($remaining);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Criterion>      $criteria
     */
    private static function matchesAll(array $row, array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            if (!$criterion->matchesRow($row)) {
                return false;
            }
        }

        return true;
    }

    private function nextIdentity(string $collection, string $key): int
    {
        $highest = 0;

        foreach ($this->collections[$collection] ?? [] as $row) {
            /** @var mixed $existing */
            $existing = $row[$key] ?? null;

            if (\is_int($existing) && $existing > $highest) {
                $highest = $existing;
            }
        }

        return $highest + 1;
    }
}
