<?php

declare(strict_types=1);

namespace App\Engine\Database\Structure;

use App\Engine\Database\DatabaseException;

/**
 * A table, described once for every database:
 *
 *     $tables->create('invoices', static function (Table $table): void {
 *         $table->id();
 *         $table->bigInteger('customer_id')->index();
 *         $table->string('number', 30)->unique();
 *         $table->decimal('total', 12, 2)->default('0.00');
 *         $table->dateTime('issued_at')->nullable();
 *         $table->timestamps();
 *         $table->foreign('customer_id')->references('customers');
 *     });
 *
 * It records what the table is and writes nothing: the connection's grammar
 * turns it into that database's CREATE TABLE and CREATE INDEX statements. The
 * types each dialect writes are listed in docs/reference/database.md.
 *
 * **Altering** a table that exists, the same methods add, and the drop and
 * rename methods take away:
 *
 *     $tables->alter('invoices', static function (Table $table): void {
 *         $table->string('reference', 40)->nullable()->unique();
 *         $table->renameColumn('number', 'code');
 *         $table->dropIndex('customer_id');
 *     });
 *
 * The description then holds only the change. A column it does not describe
 * is taken to be in the table already; the database says if it is not.
 */
final class Table
{
    /** PostgreSQL cuts identifiers at 63 bytes; generated names stay under that. */
    private const MAX_NAME = 60;

    /** @var array<string, Column> */
    private array $columns = [];

    /** @var list<Index> */
    private array $indexes = [];

    /** @var list<ForeignKey> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $droppedColumns = [];

    /** @var array<string, string> the old name => the new */
    private array $renamedColumns = [];

    /** @var list<Index> */
    private array $droppedIndexes = [];

    /** @var list<string> the columns whose foreign keys go */
    private array $droppedForeignKeys = [];

    /** @param bool $altering whether the table exists, and this describes a change to it */
    public function __construct(
        public readonly string $name,
        public readonly bool $altering = false,
    ) {}

    // ---- columns ------------------------------------------------------------

    /** The generated key: a 64-bit integer, counted up by the database. One per table. */
    public function id(string $name = 'id'): Column
    {
        if ($this->altering) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                'a generated key cannot be added to a table that exists: SQLite has no way to, and elsewhere it would number the rows already there. Give the table its id() when it is created.',
            );
        }

        foreach ($this->columns as $column) {
            if ($column->type === ColumnType::Id) {
                throw DatabaseException::invalidStructure($this->subject(), \sprintf('it already has a generated key, "%s".', $column->name));
            }
        }

        return $this->add(new Column($name, ColumnType::Id));
    }

    public function integer(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::Integer));
    }

    /** Also the type of a column that holds another table's id(). */
    public function bigInteger(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::BigInteger));
    }

    /** Exact: $precision digits in all, $scale of them after the point. Money is a decimal. */
    public function decimal(string $name, int $precision, int $scale): Column
    {
        // 38 is SQL Server's ceiling, and the lowest of the four.
        if ($precision < 1 || $precision > 38 || $scale < 0 || $scale > $precision) {
            throw DatabaseException::invalidStructure(
                \sprintf('The column "%s"', $name),
                \sprintf('decimal(%d, %d) is not a precision every database has: 1 to 38 digits, with a scale from 0 to the precision.', $precision, $scale),
            );
        }

        return $this->add(new Column($name, ColumnType::Decimal, precision: $precision, scale: $scale));
    }

    /**
     * Text of at most $length characters. SQLite does not enforce the length;
     * the other three do.
     */
    public function string(string $name, int $length = 255): Column
    {
        // 4000 is SQL Server's longest NVARCHAR short of MAX.
        if ($length < 1 || $length > 4000) {
            throw DatabaseException::invalidStructure(
                \sprintf('The column "%s"', $name),
                \sprintf('a string of %d characters is not a length every database has: 1 to 4000. Longer text is text().', $length),
            );
        }

        return $this->add(new Column($name, ColumnType::String, length: $length));
    }

    /** Text of any length. It cannot be indexed or given a default on every database. */
    public function text(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::Text));
    }

    public function boolean(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::Boolean));
    }

    public function date(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::Date));
    }

    /** A date and a time to the microsecond, with no time zone: which zone it is is the application's to know. */
    public function dateTime(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::DateTime));
    }

    /** Bytes of any length. It cannot be indexed or given a default on every database. */
    public function binary(string $name): Column
    {
        return $this->add(new Column($name, ColumnType::Binary));
    }

    /** created_at and updated_at, both nullable dateTime columns. */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    // ---- indexes and keys ---------------------------------------------------

    /** An index over several columns together, in this order. One column is ->index() on the column. */
    public function index(string $column, string ...$more): void
    {
        $this->indexes[] = new Index([$column, ...\array_values($more)], false);
    }

    /** No two rows may hold the same combination. One column is ->unique() on the column. */
    public function unique(string $column, string ...$more): void
    {
        $this->indexes[] = new Index([$column, ...\array_values($more)], true);
    }

    public function foreign(string $column): ForeignKey
    {
        return $this->foreignKeys[] = new ForeignKey($column);
    }

    // ---- taking away, from a table that exists -------------------------------

    /**
     * The columns, and what they hold. Drop their indexes and foreign keys in
     * the same change: only MySQL and PostgreSQL would take those along.
     */
    public function dropColumn(string $column, string ...$more): void
    {
        $this->mustAlter('dropColumn');

        foreach ([$column, ...\array_values($more)] as $name) {
            $this->droppedColumns[] = $name;
        }
    }

    /**
     * Its indexes and keys keep the names they were made with, from the old
     * name: drop them by the old name.
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->mustAlter('renameColumn');

        if (isset($this->renamedColumns[$from])) {
            throw DatabaseException::invalidStructure($this->subject(), \sprintf('the column "%s" is renamed twice.', $from));
        }

        $this->renamedColumns[$from] = $to;
    }

    /** The index made by ->index() on these columns, or index() over them, in this order. */
    public function dropIndex(string $column, string ...$more): void
    {
        $this->mustAlter('dropIndex');
        $this->droppedIndexes[] = new Index([$column, ...\array_values($more)], false);
    }

    /** The unique index made by ->unique() on these columns, or unique() over them. */
    public function dropUnique(string $column, string ...$more): void
    {
        $this->mustAlter('dropUnique');
        $this->droppedIndexes[] = new Index([$column, ...\array_values($more)], true);
    }

    /** The foreign key made by foreign() on this column. The column stays. */
    public function dropForeign(string $column): void
    {
        $this->mustAlter('dropForeign');
        $this->droppedForeignKeys[] = $column;
    }

    // ---- read by the grammar ------------------------------------------------

    /** @return list<Column> */
    public function columns(): array
    {
        return \array_values($this->columns);
    }

    /**
     * Every index: those asked for on a column first, in column order, then
     * the table's own.
     *
     * @return list<Index>
     */
    public function indexes(): array
    {
        $indexes = [];

        foreach ($this->columns as $column) {
            if ($column->isUnique()) {
                $indexes[] = new Index([$column->name], true);
            }

            if ($column->isIndexed()) {
                $indexes[] = new Index([$column->name], false);
            }
        }

        return [...$indexes, ...$this->indexes];
    }

    /** @return list<ForeignKey> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** A column this description adds, or null for one it does not: when altering, one already in the table. */
    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /** @return list<string> */
    public function droppedColumns(): array
    {
        return $this->droppedColumns;
    }

    /** @return array<string, string> the old name => the new */
    public function renamedColumns(): array
    {
        return $this->renamedColumns;
    }

    /** @return list<Index> */
    public function droppedIndexes(): array
    {
        return $this->droppedIndexes;
    }

    /** @return list<string> */
    public function droppedForeignKeys(): array
    {
        return $this->droppedForeignKeys;
    }

    /**
     * What can only be refused once the whole table is described: an index or
     * a key naming a column that is not there, a NULL default on a column that
     * cannot hold one, a key that points nowhere -- and, altering, a column
     * added with no value for the rows already there.
     */
    public function check(): void
    {
        if (!$this->altering && $this->columns === []) {
            throw DatabaseException::invalidStructure($this->subject(), 'it has no columns.');
        }

        if ($this->altering && $this->columns === [] && $this->indexes === [] && $this->foreignKeys === []
            && $this->droppedColumns === [] && $this->renamedColumns === [] && $this->droppedIndexes === [] && $this->droppedForeignKeys === []) {
            throw DatabaseException::invalidStructure($this->subject(), 'the change changes nothing.');
        }

        $keys = \array_filter($this->columns, static fn(Column $column): bool => $column->type === ColumnType::Id || $column->isPrimary());

        if (\count($keys) > 1) {
            throw DatabaseException::invalidStructure($this->subject(), \sprintf('it has more than one key: %s.', \implode(', ', \array_keys($keys))));
        }

        foreach ($this->columns as $column) {
            // SQLite cannot add a key to a table, and elsewhere the rows there
            // would all need a distinct value at once.
            if ($column->isPrimary() && $this->altering) {
                throw DatabaseException::invalidStructure(\sprintf('The column "%s"', $column->name), 'a key cannot be added to a table that exists. Give the table its key when it is created.');
            }

            if ($column->isPrimary() && $column->isNullable()) {
                throw DatabaseException::invalidStructure(\sprintf('The column "%s"', $column->name), 'it is the table\'s key, and a key cannot be NULL.');
            }

            if ($column->hasDefault() && $column->defaultValue() === null && !$column->isNullable()) {
                throw DatabaseException::invalidStructure(
                    \sprintf('The column "%s"', $column->name),
                    'its default is NULL, but it is not nullable. Add ->nullable(), or give it a value.',
                );
            }

            // The database would have to invent a value for every row there:
            // SQLite refuses, PostgreSQL and SQL Server refuse once there are
            // rows, and MySQL quietly writes 0 or ''.
            if ($this->altering && !$column->isNullable() && !$column->hasDefault()) {
                throw DatabaseException::invalidStructure(
                    \sprintf('The column "%s"', $column->name),
                    'it is added to a table that may already hold rows, and has no value for them. Add ->nullable(), or give it a default.',
                );
            }
        }

        foreach ($this->indexes as $index) {
            foreach ($index->columns as $name) {
                $this->existing($name, 'an index')?->assertIndexable();
            }
        }

        foreach ($this->foreignKeys as $key) {
            $column = $this->existing($key->column, 'a foreign key');

            if ($key->table() === null) {
                throw DatabaseException::invalidStructure(
                    \sprintf('The foreign key on "%s"', $key->column),
                    'it references nothing. Add ->references(\'table\').',
                );
            }

            if (($key->deleteAction() === 'set null' || $key->updateAction() === 'set null') && $column?->isNullable() === false) {
                throw DatabaseException::invalidStructure(
                    \sprintf('The foreign key on "%s"', $key->column),
                    'it sets the column to NULL, but the column is not nullable.',
                );
            }
        }
    }

    /**
     * A name for an index or a constraint: the table, the columns and what it
     * is, as in invoices_customer_id_foreign. Shortened with a hash of the
     * whole when it would be too long, so that two long names still differ.
     *
     * @param list<string> $columns
     */
    public function nameFor(array $columns, string $kind): string
    {
        $table = \substr((string) \strrchr('.' . $this->name, '.'), 1);
        $name = \strtolower($table . '_' . \implode('_', $columns) . '_' . $kind);

        if (\strlen($name) <= self::MAX_NAME) {
            return $name;
        }

        return \substr($name, 0, self::MAX_NAME - 9) . '_' . \hash('crc32b', $name);
    }

    private function add(Column $column): Column
    {
        if (isset($this->columns[$column->name])) {
            throw DatabaseException::invalidStructure($this->subject(), \sprintf('it already has a column "%s".', $column->name));
        }

        return $this->columns[$column->name] = $column;
    }

    /**
     * The column an index or a key names. Altering, a column not described is
     * taken to be in the table, and null is returned -- unless this same
     * change takes it away.
     */
    private function existing(string $name, string $what): ?Column
    {
        if (isset($this->columns[$name])) {
            return $this->columns[$name];
        }

        if (!$this->altering) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('%s names the column "%s", which it does not have.', $what, $name),
            );
        }

        if (\in_array($name, $this->droppedColumns, true) || isset($this->renamedColumns[$name])) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('%s names the column "%s", which this change drops or renames.', $what, $name),
            );
        }

        return null;
    }

    private function mustAlter(string $method): void
    {
        if (!$this->altering) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('a table being created has nothing to take away; %s() belongs in $tables->alter().', $method),
            );
        }
    }

    private function subject(): string
    {
        return \sprintf('The table "%s"', $this->name);
    }
}
