<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Database\Structure\Column;
use App\Engine\Database\Structure\ColumnType;
use App\Engine\Database\Structure\ForeignKey;
use App\Engine\Database\Structure\Table;

/**
 * SQLite.
 *
 * Standard quoting, paging and savepoints. The placeholder limit stays at the
 * standard 999: raised to 32766 in 3.32, but still compiled lower on some
 * distributions, and the version on the server is not the one on the laptop.
 *
 * Its ALTER TABLE adds, drops (from 3.35) and renames columns, and nothing
 * else: a foreign key cannot be added to a column already there or dropped at
 * all, and those are refused rather than made by rebuilding the table.
 */
final class SqliteGrammar extends Grammar
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => false,
            Capability::Upsert => true,
            Capability::RightJoin => false,
            Capability::TransactionalDdl => true,
        };
    }

    /**
     * SQLite transactions are serializable, and only that: one writer at a
     * time. Asking for a weaker level is refused rather than silently given
     * this stronger one, because code that asked for READ COMMITTED may be
     * counting on not blocking.
     */
    public function supportsIsolation(IsolationLevel $level): bool
    {
        return $level === IsolationLevel::Serializable;
    }

    /** Nothing to say: it is the only level there is. */
    public function compileIsolation(IsolationLevel $level): array
    {
        if (!$this->supportsIsolation($level)) {
            throw DatabaseException::isolationUnsupported($level, $this->driver());
        }

        return ['before' => null, 'after' => null, 'reset' => null];
    }

    /**
     * No row locks. A write locks the whole database and one writer runs at a
     * time, so what the lock would protect is already protected.
     */
    protected function lockClause(): string
    {
        return '';
    }

    public function compileTableExists(string $table): array
    {
        return ['sql' => "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", 'bindings' => [$table]];
    }

    /** One file, one writer at a time: the database is its own lock. */
    public function compileAcquireLock(string $name): ?array
    {
        return null;
    }

    public function compileReleaseLock(string $name): ?array
    {
        return null;
    }

    /**
     * SQLite's ALTER TABLE has no constraints of its own, but a column it adds
     * may carry REFERENCES -- so a foreign key on a column added in the same
     * change is written with the column. SQLite accepts that only on a column
     * whose default is NULL, since the rows already there get the default.
     */
    protected function compileAddColumn(Table $table, Column $column, \Closure $literal): string
    {
        $sql = parent::compileAddColumn($table, $column, $literal);

        foreach ($table->foreignKeys() as $key) {
            if ($key->column !== $column->name) {
                continue;
            }

            if (!$column->isNullable() || ($column->hasDefault() && $column->defaultValue() !== null)) {
                throw DatabaseException::cannotAlter(
                    $this->driver(),
                    $table->name,
                    \sprintf('add the column "%s" with a foreign key unless it is nullable with no default', $column->name),
                );
            }

            $sql .= ' CONSTRAINT ' . $this->identifier($table->nameFor([$key->column], 'foreign')) . ' ' . $this->foreignKeyReference($key);
        }

        return $sql;
    }

    /** Written with its column when the column is new; refused for one already there. */
    protected function compileAddForeignKey(Table $table, ForeignKey $key): ?string
    {
        if ($table->column($key->column) !== null) {
            return null;
        }

        throw DatabaseException::cannotAlter($this->driver(), $table->name, \sprintf('add a foreign key to the column "%s", which is already there', $key->column));
    }

    protected function compileDropForeignKey(Table $table, string $column): string
    {
        throw DatabaseException::cannotAlter($this->driver(), $table->name, \sprintf('drop the foreign key on "%s"', $column));
    }

    /**
     * AUTOINCREMENT, so that a deleted row's key is never handed out again;
     * without it SQLite reuses the largest one.
     */
    protected function idColumn(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * SQLite has five storage classes, not types, and these are its names for
     * them. A string's length is not enforced, and a decimal is stored as a
     * number, so 12.50 reads back as 12.5.
     */
    protected function columnType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::Id, ColumnType::Integer, ColumnType::BigInteger, ColumnType::Boolean => 'INTEGER',
            ColumnType::Decimal => 'NUMERIC',
            ColumnType::String, ColumnType::Text, ColumnType::Date, ColumnType::DateTime => 'TEXT',
            ColumnType::Binary => 'BLOB',
        };
    }
}
