<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Identity;
use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Observability\Tracer;
use App\Engine\Queue\Worker;
use App\Engine\Queue\WorkerOptions;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Filesystem\FilesystemException;
use App\Engine\System\Security\SystemAuthorizationException;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Fixtures\Modules\System\Plugins\Backup\Services\BackupService;
use App\Tests\Support\TestCase;

/**
 * engine/System through a real application and a module that uses it.
 *
 * The claim: a module owns the operation and declares who may ask for it;
 * the application's configuration decides what the machine will do at all;
 * both have to say yes; and all of it is written to the audit log.
 */
final class SystemSliceTest extends TestCase
{
    private string $backups;

    private CollectingWriter $log;

    protected function setUp(): void
    {
        $this->backups = \str_replace('\\', '/', (string) \realpath(\sys_get_temp_dir())) . '/lphp-backups-' . \bin2hex(\random_bytes(4));
        \mkdir($this->backups);
        $this->log = new CollectingWriter();
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->backups . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->backups);

        parent::tearDown();
    }

    /** @param array<string, mixed> $system */
    private function app(array $system = []): Application
    {
        $app = $this->shippedApplication([
            'modules' => ['paths' => ['modules/Shared', 'tests/Fixtures/Modules/System/Plugins']],
            'Backup' => ['directory' => $this->backups],
            'system' => \array_replace_recursive([
                'filesystem' => ['write' => [$this->backups]],
                'commands' => ['allowed' => [\PHP_BINARY]],
                // No slot files in this repository's system/ directory.
                'execution' => ['max_concurrent' => null],
            ], $system),
        ])->boot();

        $app->container()->get(LogManager::class)->add($this->log);

        return $app;
    }

    private function operator(): Identity
    {
        return new Identity('7', 'ada', ['backup-operator']);
    }

    /** @return list<string> */
    private function audit(): array
    {
        $events = [];

        foreach ($this->log->records as $record) {
            if ($record->channel === 'audit') {
                $events[] = $record->message;
            }
        }

        return $events;
    }

    public function test_an_operator_makes_a_backup_through_the_injected_managers(): void
    {
        $checksum = $this->app()->container()->get(BackupService::class)->create($this->operator(), 'nightly');

        self::assertFileExists($this->backups . '/nightly.json');
        self::assertSame(\hash_file('sha256', $this->backups . '/nightly.json'), $checksum);
        self::assertSame([
            'system.authorization.granted succeeded',
            'system.authorization.granted succeeded',
            'system.filesystem.changed succeeded',
            'system.command.started started',
            'system.command.completed succeeded',
        ], $this->audit());

        $granted = \array_values(\array_filter($this->log->records, static fn(LogRecord $r): bool => $r->message === 'system.authorization.granted succeeded'));
        self::assertSame('7', $granted[0]->context['principal']);
    }

    public function test_somebody_without_the_role_is_refused_before_anything_happens(): void
    {
        try {
            $this->app()->container()->get(BackupService::class)->create(new Identity('8', 'bob'), 'nightly');
            self::fail('bob made a backup');
        } catch (SystemAuthorizationException $e) {
            self::assertFalse($e->guest);
        }

        self::assertFileDoesNotExist($this->backups . '/nightly.json');
        self::assertSame(['system.authorization.denied refused'], $this->audit());
    }

    /**
     * Holding the capability is not enough when the application will not do
     * it: the filesystem policy is the application's, and it says no.
     */
    public function test_the_role_cannot_widen_what_the_application_allows(): void
    {
        $elsewhere = $this->backups . '-other';
        \mkdir($elsewhere);

        try {
            $this->app(['filesystem' => ['write' => [$elsewhere]]])->container()->get(BackupService::class)->create($this->operator(), 'nightly');
            self::fail('the backup was written outside the policy');
        } catch (FilesystemException $e) {
            self::assertTrue($e->isRefusal());
        } finally {
            \rmdir($elsewhere);
        }

        self::assertContains('system.filesystem.refused refused', $this->audit());
    }

    public function test_the_command_allowlist_is_the_applications_too(): void
    {
        $this->expectException(CommandPolicyException::class);

        $this->app(['commands' => ['allowed' => ['/usr/bin/tar']]])->container()->get(BackupService::class)->create($this->operator(), 'nightly');
    }

    /**
     * The long-running path: decided and audited on the request, done by a
     * worker, and every record of both carries the same correlation id -- so
     * the operation can be traced back to the person who asked.
     */
    public function test_a_request_decides_and_a_worker_does_the_work_under_one_correlation(): void
    {
        $container = $this->shippedApplication([
            'modules' => ['paths' => ['modules/Shared', 'tests/Fixtures/Modules/System/Plugins']],
            'Backup' => ['directory' => $this->backups],
            'queue' => ['store' => 'memory'],
            'system' => [
                'filesystem' => ['write' => [$this->backups]],
                'commands' => ['allowed' => [\PHP_BINARY]],
                'execution' => ['max_concurrent' => null],
            ],
        ])->boot()->container();
        $container->get(LogManager::class)->add($this->log);
        $tracer = $container->get(Tracer::class);

        $request = $tracer->beginRequest(Request::create('POST', '/backups', ['server' => ['SCRIPT_NAME' => '/index.php']]));
        $container->get(BackupService::class)->queue($this->operator(), 'weekly');
        $tracer->end($request);

        self::assertFileDoesNotExist($this->backups . '/weekly.json', 'the request did the work itself');
        self::assertSame(['system.authorization.granted succeeded', 'system.authorization.granted succeeded'], $this->audit());

        $container->get(Worker::class)->runOnce(new WorkerOptions(tries: 1, timeout: 60, sleep: 0, stopWhenEmpty: true));

        self::assertFileExists($this->backups . '/weekly.json');

        $correlations = [];

        foreach ($this->log->records as $record) {
            if ($record->channel === 'audit') {
                $correlations[$record->message][] = $record->context['correlation_id'] ?? null;
            }
        }

        self::assertSame([$request->id, $request->id], $correlations['system.authorization.granted succeeded']);
        self::assertSame([$request->id], $correlations['system.filesystem.changed succeeded']);
        self::assertSame([$request->id], $correlations['system.command.completed succeeded']);
    }

    public function test_the_module_declared_the_capabilities_it_checks(): void
    {
        $declared = [];

        foreach ($this->app()->container()->get(AccessRegistry::class)->permissions() as $permission) {
            $declared[$permission->capability] = $permission->module;
        }

        self::assertSame('Backup', $declared['system.filesystem.write'] ?? null);
        self::assertSame('Backup', $declared['system.command.execute'] ?? null);
        self::assertArrayNotHasKey('system.shell.execute', $declared, 'nothing the module did not declare');
    }
}
