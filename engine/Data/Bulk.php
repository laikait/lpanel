<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * The rules every BulkWrites implementation applies before it writes anything.
 *
 * Kept in one place and outside any source, because the point of the data layer
 * is that a test against ArraySource means something against a database. A
 * refusal that one source makes and the other does not is a test that passes
 * and a deployment that fails.
 */
final class Bulk
{
    /**
     * The criteria of a query about to select rows for a bulk write.
     *
     * @return list<Criterion>
     *
     * @throws DataException when the query carries anything but criteria, or none
     */
    public static function criteria(Query $query, string $operation): array
    {
        $shape = [
            'an order' => $query->orders() !== [],
            'a limit' => $query->limitValue() !== null,
            'an offset' => $query->offsetValue() !== 0,
            'a column list' => $query->columns() !== [],
        ];

        foreach ($shape as $what => $present) {
            if ($present) {
                throw DataException::bulkQueryCarries($query->collection(), $operation, $what);
            }
        }

        if ($query->criteria() === []) {
            throw DataException::bulkWithoutCriteria($query->collection(), $operation);
        }

        foreach ($query->criteria() as $criterion) {
            if ($criterion->operator === Operator::Seek) {
                throw DataException::bulkQueryCarries($query->collection(), $operation, 'a cursor position');
            }
        }

        return $query->criteria();
    }

    /**
     * The columns every row names, in the first row's order.
     *
     * A multi-row insert has one column list, so rows that disagree about their
     * columns cannot be sent as one; refusing is better than filling the gaps
     * with NULL, which would quietly override a column's database default.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    public static function columns(string $collection, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = \array_keys($rows[0]);

        if ($columns === []) {
            throw DataException::bulkRowWithoutColumns($collection);
        }

        $expected = $columns;
        \sort($expected);

        foreach ($rows as $index => $row) {
            $actual = \array_keys($row);
            \sort($actual);

            if ($actual !== $expected) {
                throw DataException::inconsistentBulkRows($collection, $index, $expected, $actual);
            }
        }

        return $columns;
    }
}
