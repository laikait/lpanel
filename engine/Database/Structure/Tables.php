<?php

declare(strict_types=1);

namespace App\Engine\Database\Structure;

use App\Engine\Database\Connection;
use App\Engine\Database\DatabaseException;

/**
 * Creating, altering and dropping tables on one connection, in its own dialect.
 *
 *     $connection->tables()->create('invoices', static function (Table $table): void { ... });
 *     $connection->tables()->alter('invoices', static function (Table $table): void { ... });
 *     $connection->tables()->drop('invoices');
 *
 * The table is described once; the connection's grammar writes the statements
 * for its database, and they are run in order. A table and its indexes are
 * separate statements, so on a database without Capability::TransactionalDdl
 * a failing index leaves the table behind -- run it inside a transaction where
 * the database has one.
 *
 * **Pretending** writes the statements and runs none of them: statements()
 * then says exactly what this database would have been sent. It is what
 * `migrate --pretend` shows.
 */
final class Tables
{
    /** @var list<array{sql: string, raw: bool}> */
    private array $statements = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly bool $pretend = false,
    ) {}

    /** @param \Closure(Table): void $define */
    public function create(string $table, \Closure $define): void
    {
        $definition = new Table($table);
        $define($definition);

        foreach ($this->connection->grammar()->compileCreateTable($definition, $this->literal(...)) as $statement) {
            $this->send($statement, false);
        }
    }

    /**
     * Change a table that exists: the description holds only the change.
     *
     *     $tables->alter('invoices', static function (Table $table): void {
     *         $table->string('reference', 40)->nullable();
     *         $table->dropColumn('notes');
     *     });
     *
     * Every statement is written before the first is sent, so a change this
     * database cannot make is refused with nothing run. Each change is its
     * own statement, so on a database without Capability::TransactionalDdl a
     * later one failing leaves the earlier ones made.
     *
     * @param \Closure(Table): void $define
     */
    public function alter(string $table, \Closure $define): void
    {
        $definition = new Table($table, altering: true);
        $define($definition);

        if ($definition->renamedColumns() !== [] && !$this->canRenameColumns()) {
            throw DatabaseException::renameNeedsNewerServer($this->serverVersion(), $table);
        }

        foreach ($this->connection->grammar()->compileAlterTable($definition, $this->literal(...)) as $statement) {
            $this->send($statement, false);
        }
    }

    public function drop(string $table): void
    {
        $this->send($this->connection->grammar()->compileDropTable($table), false);
    }

    /** Whether the connection can see a table of that name in its current schema. Asked even when pretending. */
    public function exists(string $table): bool
    {
        $query = $this->connection->grammar()->compileTableExists($table);

        return (int) $this->connection->scalar($query['sql'], $query['bindings']) > 0;
    }

    /**
     * Hand-written SQL, run only on the drivers named.
     *
     * For what the builder does not describe, and for fixing data. It is not
     * portable, so it says which databases it was written for, and on any other
     * it refuses rather than running something nobody wrote for it.
     */
    public function raw(string $sql, string $driver, string ...$drivers): void
    {
        $for = [$driver, ...\array_values($drivers)];

        if (!\in_array($this->connection->driver(), $for, true)) {
            throw DatabaseException::rawNotForDriver($this->connection->driver(), $for);
        }

        $this->send($sql, true);
    }

    /**
     * Every statement sent -- or, pretending, that would have been -- in
     * order, and whether it was written by hand.
     *
     * @return list<array{sql: string, raw: bool}>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    private function send(string $sql, bool $raw): void
    {
        $this->statements[] = ['sql' => $sql, 'raw' => $raw];

        if (!$this->pretend) {
            $this->connection->execute($sql);
        }
    }

    /**
     * RENAME COLUMN arrived in MySQL 8.0 and MariaDB 10.5.2; every other
     * dialect has had its rename for longer than the framework supports them.
     *
     * A version, not a Capability: the grammar speaks for MySQL and MariaDB
     * of every release, and only the server knows which one it is. MariaDB
     * reports itself as "10.4.32-MariaDB", or behind a "5.5.5-" prefix that
     * older clients needed.
     */
    private function canRenameColumns(): bool
    {
        if ($this->connection->driver() !== 'mysql') {
            return true;
        }

        $version = $this->serverVersion();

        return \preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $mariadb) === 1
            ? \version_compare($mariadb[1], '10.5.2', '>=')
            : \version_compare($version, '8.0.0', '>=');
    }

    private function serverVersion(): string
    {
        $version = $this->connection->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return \is_string($version) ? $version : '';
    }

    /**
     * A string default, quoted by the driver itself.
     *
     * A DEFAULT is part of the statement and cannot be bound, and only the
     * driver knows its own escaping: MySQL treats a backslash as an escape
     * unless its SQL mode says otherwise, and PDO::quote() asks.
     */
    private function literal(string $value): string
    {
        $quoted = $this->connection->pdo()->quote($value);

        if (!\is_string($quoted)) {
            throw DatabaseException::cannotQuote($this->connection->driver());
        }

        return $quoted;
    }
}
