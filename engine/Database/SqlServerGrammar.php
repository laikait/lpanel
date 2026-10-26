<?php

declare(strict_types=1);

namespace App\Engine\Database;

use App\Engine\Database\Structure\Column;
use App\Engine\Database\Structure\ColumnType;
use App\Engine\Database\Structure\Index;
use App\Engine\Database\Structure\Table;

/**
 * SQL Server.
 *
 * The dialect that differs most: square brackets, no LIMIT, and savepoints
 * that are "saved transactions" with no release.
 */
final class SqlServerGrammar extends Grammar
{
    public function driver(): string
    {
        return 'sqlsrv';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Savepoints => true,
            Capability::Returning => true,
            Capability::Upsert => false,
            Capability::RightJoin => true,
            Capability::TransactionalDdl => true,
        };
    }

    public function supportsIsolation(IsolationLevel $level): bool
    {
        return true;
    }

    /**
     * Before BEGIN, where it lasts for the rest of the session, not just the
     * transaction -- so afterwards the session is put back to READ COMMITTED,
     * SQL Server's default, or the next unrelated query would inherit it.
     */
    public function compileIsolation(IsolationLevel $level): array
    {
        return [
            'before' => 'SET TRANSACTION ISOLATION LEVEL ' . $level->value,
            'after' => null,
            'reset' => $level === IsolationLevel::ReadCommitted
                ? null
                : 'SET TRANSACTION ISOLATION LEVEL ' . IsolationLevel::ReadCommitted->value,
        ];
    }

    protected function quote(string $name): string
    {
        return '[' . $name . ']';
    }

    /** 2100 parameters, a few of which the server keeps for itself. */
    public function maxBindings(): int
    {
        return 2000;
    }

    /**
     * OFFSET ... FETCH is part of ORDER BY and cannot appear without one.
     * "(SELECT NULL)" orders by nothing, which is what a query that asked for
     * no order meant.
     */
    protected function compileOrderAndSlice(string $order, ?int $limit, int $offset): string
    {
        $slice = $this->compileLimit($limit, $offset);

        if ($slice !== '' && $order === '') {
            $order = ' ORDER BY (SELECT NULL)';
        }

        return $order . $slice;
    }

    /**
     * SQL Server refuses to fetch zero rows, so a limit of zero skips past every
     * row instead, which answers with the same nothing.
     */
    public function compileLimit(?int $limit, int $offset): string
    {
        if ($limit === null && $offset === 0) {
            return '';
        }

        if ($limit !== null && $limit <= 0) {
            return ' OFFSET ' . \PHP_INT_MAX . ' ROWS FETCH NEXT 1 ROWS ONLY';
        }

        $sql = ' OFFSET ' . \max(0, $offset) . ' ROWS';

        return $limit === null ? $sql : $sql . ' FETCH NEXT ' . $limit . ' ROWS ONLY';
    }

    /**
     * OUTPUT INSERTED comes between the column list and VALUES. It is refused
     * by a table with a trigger; such a table needs its key read another way.
     */
    protected function insertStatement(string $table, string $columns, string $values, ?string $returning): string
    {
        return 'INSERT INTO ' . $table . ' (' . $columns . ')'
            . ($returning === null ? '' : ' OUTPUT INSERTED.' . $this->identifier($returning))
            . ' VALUES ' . $values;
    }

    /**
     * SQL Server averages an integer column in integers: AVG of 3 and 4 is 3.
     * Everywhere else it is 3.5, so the column is made a decimal first. Times
     * 1.0 rather than a cast: exact, and a DECIMAL column keeps every digit it
     * had, where a cast would have to choose a precision for it.
     */
    protected function aggregateCall(string $function, string $argument): string
    {
        return $function === 'AVG' ? 'AVG(' . $argument . ' * 1.0)' : parent::aggregateCall($function, $argument);
    }

    /**
     * SQL Server has no FOR UPDATE; a table hint does it. UPDLOCK holds the
     * rows read until the transaction ends and lets no other UPDLOCK take
     * them, ROWLOCK keeps it to those rows.
     */
    protected function lockClause(): string
    {
        return '';
    }

    protected function lockHint(): string
    {
        return ' WITH (UPDLOCK, ROWLOCK)';
    }

    public function compileSavepoint(string $name): string
    {
        return 'SAVE TRANSACTION ' . $this->plainName($name);
    }

    /** A saved transaction lasts until the transaction ends; there is no release. */
    public function compileReleaseSavepoint(string $name): ?string
    {
        return null;
    }

    public function compileRollbackToSavepoint(string $name): string
    {
        return 'ROLLBACK TRANSACTION ' . $this->plainName($name);
    }

    public function compileTableExists(string $table): array
    {
        return [
            'sql' => 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = ?',
            'bindings' => [$table],
        ];
    }

    /**
     * sp_getapplock, owned by the session and not waiting. It answers 0 or 1
     * when granted and a negative number when not; NOCOUNT keeps the SELECT
     * the only result the driver sees.
     */
    public function compileAcquireLock(string $name): array
    {
        return [
            'sql' => 'SET NOCOUNT ON; DECLARE @result INT; '
                . "EXEC @result = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 0; "
                . 'SELECT CASE WHEN @result >= 0 THEN 1 ELSE 0 END',
            'bindings' => [$name],
        ];
    }

    public function compileReleaseLock(string $name): array
    {
        return ['sql' => "EXEC sp_releaseapplock @Resource = ?, @LockOwner = 'Session'", 'bindings' => [$name]];
    }

    protected function idColumn(): string
    {
        return 'BIGINT IDENTITY(1,1) PRIMARY KEY';
    }

    /**
     * N'...' for a string column's default. PDO::quote() writes a plain '...',
     * which SQL Server reads as VARCHAR in the server's code page, so anything
     * outside it arrives in the NVARCHAR column as question marks.
     */
    protected function stringDefault(Column $column, string $quoted): string
    {
        return $column->type === ColumnType::String && !\str_starts_with($quoted, 'N') ? 'N' . $quoted : $quoted;
    }

    /** ADD, without COLUMN, which T-SQL does not accept. */
    protected function compileAddColumn(Table $table, Column $column, \Closure $literal): string
    {
        return 'ALTER TABLE ' . $this->structureTable($table->name) . ' ADD ' . $this->columnDefinition($column, $literal);
    }

    /**
     * A column with a default cannot be dropped while the default is there,
     * and the default is a constraint with a name the server made up. So the
     * batch looks the name up and drops it first.
     *
     * The names written into the strings are checked identifiers, letters,
     * digits and underscores in brackets, so they cannot end a string early.
     */
    protected function compileDropColumn(Table $table, string $column): string
    {
        $name = $this->structureTable($table->name);

        return 'SET NOCOUNT ON; DECLARE @default SYSNAME, @sql NVARCHAR(MAX); '
            . 'SELECT @default = d.name FROM sys.default_constraints d '
            . 'JOIN sys.columns c ON c.object_id = d.parent_object_id AND c.column_id = d.parent_column_id '
            . "WHERE d.parent_object_id = OBJECT_ID(N'" . $name . "') AND c.name = N'" . $this->plainName($column) . "'; "
            . "IF @default IS NOT NULL BEGIN SET @sql = N'ALTER TABLE " . $name . " DROP CONSTRAINT ' + QUOTENAME(@default); EXEC sp_executesql @sql; END; "
            . 'ALTER TABLE ' . $name . ' DROP COLUMN ' . $this->identifier($column);
    }

    /** sp_rename takes the old name whole, as a string, and the new one bare. */
    protected function compileRenameColumn(Table $table, string $from, string $to): string
    {
        return \sprintf(
            "EXEC sp_rename N'%s.%s', N'%s', N'COLUMN'",
            $this->structureTable($table->name),
            $this->identifier($from),
            $this->plainName($to),
        );
    }

    /** An index belongs to its table here, and is dropped from it. */
    protected function compileDropIndex(Table $table, Index $index): string
    {
        return 'DROP INDEX ' . $this->identifier($this->indexName($table, $index)) . ' ON ' . $this->structureTable($table->name);
    }

    /** NVARCHAR throughout: VARCHAR holds only the server's code page, not UTF-8 everywhere. */
    protected function columnType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::Id, ColumnType::BigInteger => 'BIGINT',
            ColumnType::Integer => 'INT',
            ColumnType::Decimal => \sprintf('DECIMAL(%d,%d)', $column->precision, $column->scale),
            ColumnType::String => \sprintf('NVARCHAR(%d)', $column->length),
            ColumnType::Text => 'NVARCHAR(MAX)',
            ColumnType::Boolean => 'BIT',
            ColumnType::Date => 'DATE',
            ColumnType::DateTime => 'DATETIME2(6)',
            ColumnType::Binary => 'VARBINARY(MAX)',
        };
    }

    /**
     * SQL Server has no RESTRICT. NO ACTION refuses the same change, checked
     * at the same moment, since SQL Server has no deferred constraints for the
     * two to differ on.
     */
    protected function foreignKeyAction(string $action): string
    {
        return $action === 'restrict' ? 'NO ACTION' : parent::foreignKeyAction($action);
    }

    /**
     * A unique index that lets any number of rows hold NULL, as on the other
     * three.
     *
     * SQL Server alone counts NULLs as equal in a unique index, so a second
     * row without a value would be refused. Leaving out the rows with a NULL
     * in any of the columns gives the standard's answer: those rows are never
     * duplicates. A column already in an altered table may be nullable for
     * all the description knows, so it is left out the same way; on a column
     * that is not, the condition leaves out nothing.
     */
    protected function compileIndex(Table $table, Index $index): string
    {
        $sql = parent::compileIndex($table, $index);

        if (!$index->unique) {
            return $sql;
        }

        $nullable = [];

        foreach ($index->columns as $name) {
            if ($table->column($name)?->isNullable() !== false) {
                $nullable[] = $this->identifier($name) . ' IS NOT NULL';
            }
        }

        return $nullable === [] ? $sql : $sql . ' WHERE ' . \implode(' AND ', $nullable);
    }
}
