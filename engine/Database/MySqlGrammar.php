<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Database\Structure\Column;
use App\Engine\Database\Structure\ColumnType;
use App\Engine\Database\Structure\Index;
use App\Engine\Database\Structure\Table;

/**
 * MySQL and MariaDB.
 *
 * Renaming a column is the standard RENAME COLUMN, which needs MySQL 8.0 or
 * MariaDB 10.5.2. Before those the only way was CHANGE, which restates the
 * whole column, and so needs a definition the change does not have: on an
 * older server Tables refuses the rename before anything runs.
 */
final class MySqlGrammar extends Grammar
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => false,
            Capability::Upsert => true,
            Capability::RightJoin => true,
            Capability::TransactionalDdl => false,
        };
    }

    public function supportsIsolation(IsolationLevel $level): bool
    {
        return true;
    }

    /**
     * Before BEGIN, without SESSION or GLOBAL: MySQL applies it to the next
     * transaction only, so there is nothing to put back afterwards.
     */
    public function compileIsolation(IsolationLevel $level): array
    {
        return ['before' => 'SET TRANSACTION ISOLATION LEVEL ' . $level->value, 'after' => null, 'reset' => null];
    }

    /** 1213 is InnoDB's deadlock, which some versions report under HY000 rather than 40001. */
    public function isRetryable(\PDOException $failure): bool
    {
        return parent::isRetryable($failure) || ($failure->errorInfo[1] ?? null) === 1213;
    }

    /** Backticks: MySQL reads a double-quoted name as a string unless ANSI_QUOTES is set. */
    protected function quote(string $name): string
    {
        return '`' . $name . '`';
    }

    /**
     * ON DUPLICATE KEY UPDATE, judged by whichever unique key the row collides
     * with: MySQL takes no conflict target, so $uniqueBy is not written here.
     *
     * VALUES(column) is the incoming value. MySQL 8 deprecates it in favour of
     * a row alias that MariaDB does not understand; VALUES() is what both run.
     * An empty $update still needs an assignment, so the first unique column
     * is set to itself, which changes nothing.
     */
    protected function upsertClause(array $uniqueBy, array $update): string
    {
        if ($update === []) {
            $column = $this->identifier($uniqueBy[0] ?? '');

            return ' ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column;
        }

        return ' ON DUPLICATE KEY UPDATE ' . \implode(', ', \array_map(
            fn(string $column): string => $this->identifier($column) . ' = VALUES(' . $this->identifier($column) . ')',
            $update,
        ));
    }

    /** The protocol counts placeholders in two bytes. */
    public function maxBindings(): int
    {
        return 65535;
    }

    /**
     * InnoDB, because MyISAM accepts a foreign key and then ignores it, and
     * has no transactions. utf8mb4, because a table otherwise takes the
     * database's default, which on many installations is latin1 and cannot
     * hold most of the world's text; the other three dialects store Unicode
     * whatever the database says. The collation is utf8mb4's own default.
     */
    protected function tableOptions(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    public function compileTableExists(string $table): array
    {
        return [
            'sql' => 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            'bindings' => [$table],
        ];
    }

    /** GET_LOCK answers 1 when taken, 0 when another session holds it; a timeout of 0 does not wait. */
    public function compileAcquireLock(string $name): array
    {
        return ['sql' => 'SELECT GET_LOCK(?, 0)', 'bindings' => [$name]];
    }

    public function compileReleaseLock(string $name): array
    {
        return ['sql' => 'SELECT RELEASE_LOCK(?)', 'bindings' => [$name]];
    }

    /** An index belongs to its table here, and is dropped from it. */
    protected function compileDropIndex(Table $table, Index $index): string
    {
        return 'DROP INDEX ' . $this->identifier($this->indexName($table, $index)) . ' ON ' . $this->structureTable($table->name);
    }

    /**
     * MySQL's own words. The index MySQL made for the key, if it needed one,
     * stays; dropping the column takes it along.
     */
    protected function compileDropForeignKey(Table $table, string $column): string
    {
        return \sprintf(
            'ALTER TABLE %s DROP FOREIGN KEY %s',
            $this->structureTable($table->name),
            $this->identifier($table->nameFor([$column], 'foreign')),
        );
    }

    /** Signed, like every other dialect's, so a bigInteger column can point at it. */
    protected function idColumn(): string
    {
        return 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    }

    protected function columnType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::Id, ColumnType::BigInteger => 'BIGINT',
            ColumnType::Integer => 'INT',
            ColumnType::Decimal => \sprintf('DECIMAL(%d,%d)', $column->precision, $column->scale),
            ColumnType::String => \sprintf('VARCHAR(%d)', $column->length),
            ColumnType::Text => 'LONGTEXT',
            ColumnType::Boolean => 'TINYINT(1)',
            ColumnType::Date => 'DATE',
            ColumnType::DateTime => 'DATETIME(6)',
            ColumnType::Binary => 'LONGBLOB',
        };
    }
}
