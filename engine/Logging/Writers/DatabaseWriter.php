<?php

declare(strict_types=1);

namespace App\Engine\Logging\Writers;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Logging\Level;
use App\Engine\Logging\LoggingException;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;

/**
 * Records as rows, for an application whose machines share a database and
 * whose people would rather query a log than grep one.
 *
 * Not a replacement for the file or the system logger. A log kept in the
 * database that is failing cannot hold the records about the failure: when
 * the database goes, this writer is retired with the rest of the application's
 * queries, and only another writer is left to say why. Name one alongside it.
 *
 * **Its own connection.** On MySQL, PostgreSQL and SQL Server the writer does
 * not share the application's connection. Sharing it would put every record
 * inside whatever transaction was open, and a rollback would take the records
 * of what went wrong with it. On SQLite, which has one writer at a time, a
 * second connection would wait on the application's own open transaction; it
 * shares the connection there -- see ownConnection().
 *
 * Every statement goes through the query builder, so it runs on each database
 * the builder writes for. The table comes from LogTableMigration, and times
 * are UTC.
 *
 * Like every writer, it reports a failure and catches none: the manager
 * retires it and log:status says why.
 */
final class DatabaseWriter implements LogWriter
{
    public const DEFAULT_TABLE = 'logs';

    /** As long as the channel column is. Channel names are chosen in code, so this is a guard, not a limit anyone meets. */
    private const CHANNEL_LENGTH = 100;

    private ?Connection $connection = null;

    /** @param \Closure(): Connection $connect called on the first record, not before */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $table = self::DEFAULT_TABLE,
        private readonly Level $minimum = Level::Debug,
        private readonly int $retentionDays = 0,
    ) {}

    /**
     * The connection a database log should write through.
     *
     * A connection of its own, built from the same configuration, so that it
     * stands outside the application's transactions -- and outside the
     * manager's query observers too, so writing a record is never itself a
     * slow query to log. SQLite is the exception: one writer at a time means
     * a second connection would wait out the busy timeout behind any open
     * transaction, and an in-memory database is a different database on every
     * connection.
     */
    public static function ownConnection(ConnectionManager $connections, ?string $name = null): Connection
    {
        $name ??= $connections->defaultName();
        $config = $connections->config($name);

        return $config->driver() === 'sqlite' ? $connections->connection($name) : new Connection($config);
    }

    public function describe(): string
    {
        return \sprintf('database table %s (>= %s)', $this->table, $this->minimum->label());
    }

    public function accepts(LogRecord $record): bool
    {
        return $record->level->isAtLeast($this->minimum);
    }

    public function write(LogRecord $record): void
    {
        $this->records()->insert([
            'logged_at' => $record->at(),
            'level' => $record->level->value,
            'level_name' => $record->level->label(),
            'channel' => \mb_substr($record->channel, 0, self::CHANNEL_LENGTH),
            'message' => $record->message,
            // The context is normalised before a writer sees it, so this
            // cannot meet a resource or a cycle; bytes that are not UTF-8 are
            // replaced rather than refused.
            'context' => $record->context === [] ? null : \json_encode(
                $record->context,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    /**
     * The table, checked once per process before the first record.
     *
     * A missing table is a deployment step not taken, and saying so beats the
     * driver's own words about an object it cannot find. Rows past the
     * retention window go at the same moment: once per process, as the file
     * writer does with its files.
     */
    private function records(): QueryBuilder
    {
        $connection = $this->connection;

        if ($connection === null) {
            $connection = ($this->connect)();

            if (!$connection->tables()->exists($this->table)) {
                throw LoggingException::missingTable($this->table, $connection->name());
            }

            $this->prune($connection);
            $this->connection = $connection;
        }

        return $connection->table($this->table);
    }

    private function prune(Connection $connection): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', $this->retentionDays), new \DateTimeZone('UTC'));

        $connection->table($this->table)->where('logged_at', '<', $cutoff)->delete();
    }
}
