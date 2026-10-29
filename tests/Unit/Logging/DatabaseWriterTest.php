<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\ConnectionManager;
use App\Engine\Logging\Level;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogTableMigration;
use App\Engine\Logging\Writers\DatabaseWriter;
use App\Tests\Support\TestCase;
use App\Tests\Support\TestDatabases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The database log writer, on every database a run can reach: a record is a
 * row that reads back as it was written, a missing table retires the writer
 * with the command that fixes it, old rows go, and on a server a record
 * outlives the transaction it was written in.
 */
final class DatabaseWriterTest extends TestCase
{
    /** Its own name, so a server's real log table is never touched. */
    private const TABLE = 'laika_test_logs';

    /** @var list<Connection> */
    private static array $connections = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$connections as $connection) {
            if ($connection->driver() !== 'sqlite' && $connection->tables()->exists(self::TABLE)) {
                $connection->tables()->drop(self::TABLE);
            }

            $connection->disconnect();
        }

        self::$connections = [];
    }

    /** @return array<string, array{ConnectionConfig}> */
    public static function databases(): array
    {
        return TestDatabases::available();
    }

    #[DataProvider('databases')]
    public function test_a_record_is_a_row_that_reads_back_as_written(ConnectionConfig $config): void
    {
        [$connections, $db] = $this->migrated($config);
        $writer = new DatabaseWriter(static fn(): Connection => DatabaseWriter::ownConnection($connections), self::TABLE);

        $writer->write(new LogRecord(
            Level::Warning,
            'Invoice 7 was paid twice',
            ['invoice' => 7, 'city' => 'কলকাতা', 'path' => '/billing/7'],
            'billing',
            1_767_323_045.25,
        ));
        $writer->write(new LogRecord(Level::Info, 'nothing else to say', [], 'app', 1_767_323_046.0));

        $rows = $db->table(self::TABLE)->orderBy('id')->get();
        self::assertCount(2, $rows);

        self::assertSame(Level::Warning->value, (int) $rows[0]['level']);
        self::assertSame('warning', $rows[0]['level_name']);
        self::assertSame('billing', $rows[0]['channel']);
        self::assertSame('Invoice 7 was paid twice', $rows[0]['message']);
        self::assertSame(
            ['invoice' => 7, 'city' => 'কলকাতা', 'path' => '/billing/7'],
            \json_decode((string) $rows[0]['context'], true),
        );
        // 2026-01-02 03:04:05.25, in UTC whatever the process's zone.
        self::assertSame(
            '2026-01-02 03:04:05.250000',
            (new \DateTimeImmutable((string) $rows[0]['logged_at']))->format('Y-m-d H:i:s.u'),
        );

        self::assertNull($rows[1]['context'], 'no context is no JSON');
    }

    /** "Error or worse" is a comparison on the code, as it is in the manager. */
    #[DataProvider('databases')]
    public function test_the_level_code_answers_error_or_worse(ConnectionConfig $config): void
    {
        [$connections, $db] = $this->migrated($config);
        $logs = new LogManager(Level::Debug);
        $logs->add(new DatabaseWriter(static fn(): Connection => DatabaseWriter::ownConnection($connections), self::TABLE));

        foreach ([Level::Debug, Level::Warning, Level::Error, Level::Critical] as $level) {
            $logs->channel()->log($level, $level->label());
        }

        $worst = $db->table(self::TABLE)->where('level', '<=', Level::Error->value)->orderBy('level')->get();

        self::assertSame(['critical', 'error'], \array_column($worst, 'level_name'));
    }

    #[DataProvider('databases')]
    public function test_a_missing_table_retires_the_writer_with_the_command_to_run(ConnectionConfig $config): void
    {
        [$connections, $db] = $this->connected($config);

        if ($db->tables()->exists(self::TABLE)) {
            $db->tables()->drop(self::TABLE);
        }

        $logs = new LogManager();
        $logs->add(new DatabaseWriter(static fn(): Connection => DatabaseWriter::ownConnection($connections), self::TABLE));

        $logs->channel()->error('nobody ran migrate');

        self::assertSame([], $logs->writers());
        self::assertCount(1, $logs->failures());
        self::assertStringContainsString(
            \sprintf('The log table "%s" does not exist on the "%s" connection. Run: php laika migrate', self::TABLE, $config->name),
            $logs->failures()[0],
        );
    }

    #[DataProvider('databases')]
    public function test_rows_past_retention_go_before_the_first_record(ConnectionConfig $config): void
    {
        [$connections, $db] = $this->migrated($config);
        $utc = new \DateTimeZone('UTC');

        foreach (['-10 days' => 'old', '-2 days' => 'recent'] as $when => $message) {
            $db->table(self::TABLE)->insert([
                'logged_at' => new \DateTimeImmutable($when, $utc),
                'level' => Level::Info->value,
                'level_name' => 'info',
                'channel' => 'app',
                'message' => $message,
            ]);
        }

        $writer = new DatabaseWriter(
            static fn(): Connection => DatabaseWriter::ownConnection($connections),
            self::TABLE,
            retentionDays: 7,
        );
        $writer->write(new LogRecord(Level::Info, 'now'));

        self::assertSame(['recent', 'now'], \array_column($db->table(self::TABLE)->orderBy('id')->get(), 'message'));
    }

    /**
     * The reason for a connection of its own: the application rolls back, and
     * the record of why is still there. SQLite shares the connection instead,
     * because a second one would wait behind the first one's transaction.
     */
    #[DataProvider('databases')]
    public function test_a_record_outlives_the_transaction_it_was_written_in(ConnectionConfig $config): void
    {
        [$connections, $db] = $this->migrated($config);

        if ($config->driver() === 'sqlite') {
            self::assertSame($db, DatabaseWriter::ownConnection($connections), 'SQLite shares the connection');

            return;
        }

        self::assertNotSame($db, DatabaseWriter::ownConnection($connections));

        $writer = new DatabaseWriter(static fn(): Connection => DatabaseWriter::ownConnection($connections), self::TABLE);

        try {
            $db->transaction(static function () use ($writer): void {
                $writer->write(new LogRecord(Level::Error, 'the payment failed'));

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(['the payment failed'], \array_column($db->table(self::TABLE)->get(), 'message'));
    }

    public function test_only_records_at_or_above_its_minimum_are_accepted(): void
    {
        $writer = new DatabaseWriter(
            static fn(): Connection => throw new \LogicException('asked for a connection'),
            minimum: Level::Warning,
        );

        self::assertFalse($writer->accepts(new LogRecord(Level::Info, 'x')));
        self::assertTrue($writer->accepts(new LogRecord(Level::Error, 'x')));
        self::assertSame('database table logs (>= warning)', $writer->describe());
    }

    /** @return array{ConnectionManager, Connection} with the table made fresh */
    private function migrated(ConnectionConfig $config): array
    {
        [$connections, $db] = $this->connected($config);

        if ($db->tables()->exists(self::TABLE)) {
            $db->tables()->drop(self::TABLE);
        }

        (new LogTableMigration(self::TABLE))->up($db->tables());

        return [$connections, $db];
    }

    /** @return array{ConnectionManager, Connection} */
    private function connected(ConnectionConfig $config): array
    {
        $connections = new ConnectionManager([$config]);
        $db = $connections->connection();
        self::$connections[] = $db;

        return [$connections, $db];
    }
}
