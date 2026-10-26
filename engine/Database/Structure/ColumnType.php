<?php

declare(strict_types=1);

namespace App\Engine\Database\Structure;

/**
 * What a column holds, in words every database can be asked for.
 *
 * Each dialect's grammar writes its own type for each of these; the table of
 * which is which is in docs/reference/database.md, and the conformance tests
 * hold every dialect to it.
 */
enum ColumnType: string
{
    /** The table's generated key: a 64-bit integer the database counts up. */
    case Id = 'id';
    case Integer = 'integer';
    case BigInteger = 'big_integer';
    case Decimal = 'decimal';
    case String = 'string';
    case Text = 'text';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'date_time';
    case Binary = 'binary';

    /**
     * Whether an index can be put on it everywhere.
     *
     * Unbounded text and binary cannot: MySQL needs a prefix length for them,
     * and SQL Server cannot index its MAX types at all.
     */
    public function indexable(): bool
    {
        return $this !== self::Text && $this !== self::Binary;
    }

    /**
     * Whether it may carry a DEFAULT everywhere.
     *
     * MySQL gives unbounded text and binary no literal default, and a
     * generated key's value is the database's to choose.
     */
    public function takesDefault(): bool
    {
        return $this !== self::Id && $this !== self::Text && $this !== self::Binary;
    }
}
