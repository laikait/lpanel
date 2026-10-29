<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cache\Cache;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Database\ConnectionManager;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Logging\Logger;
use App\Engine\Logging\LogManager;
use App\Engine\Queue\Queue;
use App\Tests\Fixtures\Queue\RecordingJob;
use App\Tests\Support\TestCase;

/**
 * The migrate commands, as a deploy script runs them: two modules, one needing
 * the other's table, on one in-memory database for the whole application.
 */
final class MigrationSliceTest extends TestCase
{
    private function app(string $env = 'local'): Application
    {
        return $this->application([
            'app' => ['env' => $env],
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Migrations/Plugins']],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();
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

    public function test_pretend_then_migrate_then_status_then_rollback(): void
    {
        $app = $this->app();
        $db = $app->container()->get(ConnectionManager::class)->connection();

        [$status, $output] = $this->console($app, 'migrate', '--pretend');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Billing:2026_02_01_000000_create_invoices', $output);
        self::assertStringContainsString('CREATE TABLE "laika_mig_invoices"', $output);
        self::assertStringContainsString('3 migration(s) would run. Nothing was run.', $output);
        self::assertFalse($db->tables()->exists('laika_mig_invoices'));

        [$status, $output] = $this->console($app, 'migrate');
        self::assertSame(0, $status, $output);
        self::assertMatchesRegularExpression('/ran\s+Customers:2026_01_01_000000_create_customers.*\n.*create_customer_notes.*\n.*create_invoices/', $output);
        self::assertTrue($db->tables()->exists('laika_mig_invoices'));

        [$status, $output] = $this->console($app, 'migrate');
        self::assertSame(0, $status);
        self::assertStringContainsString('Nothing to migrate.', $output);

        [$status, $output] = $this->console($app, 'migrate:status');
        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/Billing:2026_02_01_000000_create_invoices\s+ran\s+1/', $output);
        self::assertStringContainsString('0 pending.', $output);

        [$status, $output] = $this->console($app, 'migrate:rollback');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('3 migration(s) undone.', $output);
        self::assertFalse($db->tables()->exists('laika_mig_customers'));
    }

    /**
     * A failed migration says which one, on which database, and what was kept;
     * the database's own words follow only in debug mode, as for any error.
     */
    public function test_a_failed_migration_names_itself_and_withholds_the_databases_words(): void
    {
        foreach ([false => 'App\Engine\Database\DatabaseException, whose message is shown only with APP_DEBUG=1.', true => 'THIS IS NOT SQL'] as $debug => $cause) {
            $app = $this->application([
                'app' => ['debug' => (bool) $debug],
                'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/MigrationsFailing/Plugins']],
                'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
            ])->boot();

            [$status, $output] = $this->console($app, 'migrate');

            self::assertSame(1, $status);
            self::assertStringContainsString('Broken:2026_01_01_000000_half_done failed on sqlite. Nothing of it was kept', $output);
            self::assertStringContainsString('Cause: ', $output);
            self::assertStringContainsString($cause, $output);
        }
    }

    public function test_migrate_then_seed_then_seed_one_module(): void
    {
        $app = $this->app();
        $db = $app->container()->get(ConnectionManager::class)->connection();
        $this->console($app, 'migrate');

        [$status, $output] = $this->console($app, 'db:seed');
        self::assertSame(0, $status, $output);
        self::assertMatchesRegularExpression('/seeded\s+Customers:customers.*\n.*seeded\s+Billing:invoices/', $output);
        self::assertStringContainsString('2 seeder(s) ran.', $output);
        self::assertSame(1, $db->table('laika_mig_invoices')->count());

        [$status, $output] = $this->console($app, 'db:seed', '--module=Customers');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('1 seeder(s) ran.', $output);
        self::assertSame(1, $db->table('laika_mig_customers')->count(), 'a seeder that looks first adds nothing the second time');

        [$status, $output] = $this->console($app, 'db:seed', '--module=Nobody');
        self::assertSame(1, $status);
        self::assertStringContainsString('There is no enabled module "Nobody"', $output);
    }

    /** Rows meant for a developer's database do not go into the live one by accident. */
    public function test_seeding_production_needs_force(): void
    {
        $app = $this->app('production');
        $this->console($app, 'migrate');

        [$status, $output] = $this->console($app, 'db:seed');
        self::assertSame(1, $status);
        self::assertStringContainsString('Pass --force', $output);
        self::assertSame(0, $app->container()->get(ConnectionManager::class)->connection()->table('laika_mig_customers')->count());

        [$status] = $this->console($app, 'db:seed', '--force');
        self::assertSame(0, $status);
    }

    /**
     * Sessions, the cache, the queue and the log all kept in the database:
     * `migrate` makes their four tables before any module's, and each works
     * on what it made -- down to a worker taking a job off the table.
     */
    public function test_the_database_stores_get_their_tables_from_migrate(): void
    {
        RecordingJob::reset();
        $app = $this->application([
            'session' => ['store' => 'database'],
            'cache' => ['store' => 'database'],
            'queue' => ['store' => 'database'],
            'logging' => ['writers' => ['database']],
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Migrations/Plugins']],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();

        [$status, $output] = $this->console($app, 'migrate');
        self::assertSame(0, $status, $output);
        self::assertMatchesRegularExpression(
            '/framework:2026_09_19_000000_create_sessions.*\n.*framework:2026_09_19_000001_create_cache.*\n.*framework:2026_09_19_000002_create_jobs.*\n.*framework:2026_09_19_000003_create_logs.*\n.*Customers/',
            $output,
        );

        $cache = $app->container()->get(Cache::class);
        $cache->set('answer', 42);
        self::assertSame(42, $cache->get('answer'));

        $queue = $app->container()->get(Queue::class);
        $queue->push(new RecordingJob('from the table'));
        self::assertSame(1, $queue->pending());

        [$status, $output] = $this->console($app, 'queue:work', '--drain');
        self::assertSame(0, $status, $output);
        self::assertSame(['from the table'], RecordingJob::$ran);
        self::assertSame(0, $queue->pending());

        $db = $app->container()->get(ConnectionManager::class)->connection();
        self::assertSame(1, $db->table('cache')->count());
        self::assertSame(0, $db->table('jobs')->count());

        $app->container()->get(Logger::class)->warning('Invoice {number} was paid twice', ['number' => 7]);
        self::assertSame([], $app->container()->get(LogManager::class)->failures());
        self::assertSame(
            ['Invoice {number} was paid twice'],
            \array_column($db->table('logs')->where('level_name', 'warning')->get(), 'message'),
        );
    }

    /** Undoing a migration usually drops a table and its data; production has to say so. */
    public function test_a_rollback_in_production_needs_force(): void
    {
        $app = $this->app('production');
        $this->console($app, 'migrate');

        [$status, $output] = $this->console($app, 'migrate:rollback');
        self::assertSame(1, $status);
        self::assertStringContainsString('Pass --force', $output);
        self::assertTrue($app->container()->get(ConnectionManager::class)->connection()->tables()->exists('laika_mig_customers'));

        [$status] = $this->console($app, 'migrate:rollback', '--force');
        self::assertSame(0, $status);
    }
}
