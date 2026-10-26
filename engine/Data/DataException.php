<?php

declare(strict_types=1);

namespace App\Engine\Data;

use App\Engine\Error\FrameworkException;

final class DataException extends FrameworkException
{
    // ---- bulk writes ------------------------------------------------------

    public static function bulkQueryCarries(string $collection, string $operation, string $what): self
    {
        return new self(\sprintf(
            'A bulk %s on "%s" was given a query with %s. A bulk write touches every row the criteria match '
            . 'and nothing else about the query applies -- UPDATE and DELETE with an order or a limit mean '
            . 'different things on different databases. Build the query from criteria only.',
            $operation,
            $collection,
            $what,
        ));
    }

    /**
     * Refused on purpose. "Every row" should be written, not arrived at by a
     * filter somebody forgot to apply.
     */
    public static function bulkWithoutCriteria(string $collection, string $operation): self
    {
        return new self(\sprintf(
            'A bulk %s on "%s" was given a query with no criteria, which would touch every row. If that is '
            . 'really what is meant, say so with a criterion that matches everything, such as whereNotNull() '
            . 'on the key.',
            $operation,
            $collection,
        ));
    }

    public static function bulkRowWithoutColumns(string $collection): self
    {
        return new self(\sprintf('A bulk insert into "%s" was given a row with no columns.', $collection));
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    public static function inconsistentBulkRows(string $collection, int $index, array $expected, array $actual): self
    {
        return new self(\sprintf(
            'A bulk insert into "%s" needs every row to name the same columns. Row %d names [%s]; the first '
            . 'names [%s]. Missing columns are not filled with NULL, because NULL would override the '
            . 'column\'s default.',
            $collection,
            $index,
            \implode(', ', $actual),
            \implode(', ', $expected),
        ));
    }

    public static function bulkUnsupported(string $collection, string $source): self
    {
        return new self(\sprintf(
            'The data source behind "%s" (%s) does not implement BulkWrites, so it cannot write a set of rows '
            . 'in one operation. Write them one at a time, or use a source that can.',
            $collection,
            $source,
        ));
    }

    public static function operatorNeedsAList(string $field, Operator $operator): self
    {
        return new self(\sprintf(
            'The "%s" criterion uses %s, which compares against a list, but it was given a single value.',
            $field,
            $operator->value,
        ));
    }

    /**
     * An empty IN would match nothing, which is almost always a bug upstream --
     * an unfiltered list of keys, most often. Saying so beats returning an
     * empty result that looks like valid data.
     */
    public static function emptyList(string $field, Operator $operator): self
    {
        return new self(\sprintf(
            'The "%s" criterion uses %s with an empty list, which can never match. '
            . 'Check the result of whatever produced the list before querying.',
            $field,
            $operator->value,
        ));
    }

    public static function noModel(string $collection, string $method): self
    {
        return new self(\sprintf(
            'This query over "%s" was not given a model class, so %s() has nothing to build. '
            . 'Use rows() for plain arrays, into() for read models, or create the query from a repository.',
            $collection,
            $method,
        ));
    }

    public static function negativePage(int $page, int $perPage): self
    {
        return new self(\sprintf(
            'A page must be at least 1 and a page size at least 1; %d and %d were given.',
            $page,
            $perPage,
        ));
    }

    public static function nothingToPersist(string $model): self
    {
        return new self(\sprintf(
            'There is nothing to persist: the %s has no changes. '
            . 'A model loaded from storage and left alone does not need to be written.',
            $model,
        ));
    }

    public static function cannotRemoveWithoutIdentity(string $model): self
    {
        return new self(\sprintf('A %s with no identity has never been stored, so it cannot be removed.', $model));
    }

    public static function insertReturnedNoIdentity(string $collection, string $key): self
    {
        return new self(\sprintf(
            'Inserting into "%s" produced no value for the "%s" key. '
            . 'Either the source assigns identities and did not, or the model should carry its own.',
            $collection,
            $key,
        ));
    }

    public static function unknownCollection(string $collection): self
    {
        return new self(\sprintf('No collection named "%s" exists in this source.', $collection));
    }

    public static function invalidCursor(string $reason): self
    {
        return new self(\sprintf(
            'The cursor cannot be used: %s. Take cursors only from nextCursor() or previousCursor() of the same '
            . 'listing, and start again without one if the listing\'s order changed.',
            $reason,
        ));
    }

    public static function seekOnNull(string $field): self
    {
        return new self(\sprintf(
            'A cursor cannot continue from a row whose "%s" is null: null has no position between two values, '
            . 'so "the rows after it" is not defined. Order a cursor listing by columns that are never null, '
            . 'or filter the nulls out with whereNotNull("%s").',
            $field,
            $field,
        ));
    }

    public static function cursorPageSize(int $perPage): self
    {
        return new self(\sprintf('A cursor page size must be at least 1; %d was given.', $perPage));
    }
}
