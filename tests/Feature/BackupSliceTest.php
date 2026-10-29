<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\ConnectionManager;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Tests\Support\TestCase;

/**
 * backup:make / backup:restore through the real console, against a real
 * file-backed SQLite database -- the one driver that needs no external
 * binary and no config/system.php allowlist entry, so this runs unmodified
 * wherever the suite runs.
 */
final class BackupSliceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/backup-slice-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->directory);

        parent::tearDown();
    }

    private function rrmdir(string $directory): void
    {
        foreach (\glob($directory . '/*') ?: [] as $entry) {
            \is_dir($entry) ? $this->rrmdir($entry) : @\unlink($entry);
        }

        @\rmdir($directory);
    }

    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['modules']['paths'] ??= [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Backup'];
        $config['database']['connections']['default']['dsn'] ??= 'sqlite:' . $this->directory . '/app.sqlite';
        $config['Backup']['directory'] ??= $this->directory . '/backups';

        return $this->application($config)->boot();
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    public function test_backup_make_writes_a_timestamped_file(): void
    {
        $app = $this->app();
        $app->container()->get(ConnectionManager::class)->connection()->execute(
            'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)',
        );

        [$status, $output] = $this->console($app, 'backup:make');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Backup written.', $output);

        $files = \glob($this->directory . '/backups/*.sqlite') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/default-sqlite-\d{4}-\d{2}-\d{2}-\d{6}\.sqlite$/', $files[0]);
    }

    public function test_backup_restore_refuses_without_force(): void
    {
        $app = $this->app();
        [$status, $output] = $this->console($app, 'backup:restore', $this->directory . '/does-not-matter.sqlite');

        self::assertSame(1, $status);
        self::assertStringContainsString('--force', $output);
    }

    public function test_backup_restore_refuses_a_missing_file_even_with_force(): void
    {
        $app = $this->app();
        [$status, $output] = $this->console(
            $app,
            'backup:restore',
            $this->directory . '/no-such-backup.sqlite',
            '--force',
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('does not exist', $output);
    }

    public function test_a_full_round_trip_through_the_console(): void
    {
        $app = $this->app();
        $connection = $app->container()->get(ConnectionManager::class)->connection();
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->execute('INSERT INTO t (name) VALUES (?)', ['ada']);

        [$makeStatus] = $this->console($app, 'backup:make');
        self::assertSame(0, $makeStatus);

        $files = \glob($this->directory . '/backups/*.sqlite') ?: [];
        self::assertCount(1, $files);

        $connection->execute('DELETE FROM t');
        self::assertSame([], $connection->select('SELECT * FROM t'));

        [$restoreStatus, $restoreOutput] = $this->console($app, 'backup:restore', $files[0], '--force');
        self::assertSame(0, $restoreStatus, $restoreOutput);

        // The connection held the file open through the delete above; a
        // fresh one is what a real second process restoring into a live
        // database would be anyway.
        $fresh = new Connection(ConnectionConfig::of('fresh', 'sqlite:' . $this->directory . '/app.sqlite'));
        self::assertSame([['id' => 1, 'name' => 'ada']], $fresh->select('SELECT * FROM t'));
    }
}
