<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Audit;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Hook\HookEngine;
use App\Engine\Logging\Level;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\SystemAuditLog;
use App\Engine\Security\Secret;
use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\AuditRecord;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicy;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Cron\CronJob;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Filesystem\FilesystemException;
use App\Engine\System\Filesystem\FilesystemPolicy;
use App\Engine\System\Filesystem\SystemFilesystem;
use App\Engine\System\Permission\PermissionException;
use App\Engine\System\Permission\PermissionManager;
use App\Engine\System\Process\ProcessManager;
use App\Engine\System\Security\SystemAuthorizationException;
use App\Engine\System\Security\SystemAuthorizer;
use App\Engine\System\Security\SystemCapability;
use App\Engine\System\Service\ServiceException;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServicePolicy;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;

final class SystemAuditTest extends TestCase
{
    private const SECRET = 'S3cret-VALUE-9f2c';

    /** @var list<AuditRecord> */
    private array $records = [];

    private SystemAudit $audit;

    private string $directory = '';

    protected function setUp(): void
    {
        $hooks = new HookEngine();
        $hooks->add(SystemAudit::HOOK, function (AuditRecord $record): void {
            $this->records[] = $record;
        });

        $this->audit = new SystemAudit($hooks);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        if ($this->directory === '') {
            return;
        }

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        @\rmdir($this->directory);
    }

    /** @return list<string> */
    private function events(): array
    {
        return \array_map(static fn(AuditRecord $r): string => $r->event . ':' . $r->outcome->value, $this->records);
    }

    private function directory(): string
    {
        $this->directory = \str_replace('\\', '/', (string) \realpath(\sys_get_temp_dir())) . '/lphp-audit-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);

        return $this->directory;
    }

    // ---- commands and processes -----------------------------------------------------------

    public function test_a_command_is_recorded_when_it_starts_and_when_it_ends(): void
    {
        (new CommandExecutor(audit: $this->audit))->run(new Command(\PHP_BINARY, ['-n', '-r', 'exit(3);']));

        self::assertSame(['system.command.started:started', 'system.command.failed:failed'], $this->events());

        $end = $this->records[1]->toArray();
        self::assertSame(3, $end['exit_code']);
        self::assertSame(\basename(\PHP_BINARY), $end['program']);
        self::assertSame(3, $end['arguments']);
        self::assertIsInt($end['duration_ms']);
    }

    public function test_a_timeout_and_a_refusal_are_their_own_events(): void
    {
        (new CommandExecutor(audit: $this->audit))->run(new Command(\PHP_BINARY, ['-n', '-r', 'sleep(10);'], timeout: 0.3));

        try {
            (new CommandExecutor(policy: CommandPolicy::allowlist(), audit: $this->audit))->run(new Command(\PHP_BINARY, ['-v']));
            self::fail('no refusal');
        } catch (CommandPolicyException) {
        }

        self::assertSame(
            ['system.command.started:started', 'system.command.timeout:failed', 'system.command.refused:refused'],
            $this->events(),
        );
    }

    public function test_a_process_is_recorded_when_it_starts_and_when_it_is_terminated(): void
    {
        $process = (new ProcessManager(audit: $this->audit))->start(new Command(\PHP_BINARY, ['-n', '-r', 'sleep(10);']));
        $process->terminate();
        $process->wait();

        self::assertSame(['system.process.started:started', 'system.process.terminated:failed'], $this->events());
        self::assertSame($process->pid(), $this->records[0]->context['pid']);
    }

    // ---- services, cron, files, permissions ---------------------------------------------------

    public function test_a_service_change_the_policy_refuses_is_recorded(): void
    {
        try {
            (new ServiceManager(new CommandExecutor(), ServicePolicy::none(), '/nonexistent/systemctl', audit: $this->audit))->stop('ssh');
            self::fail('no refusal');
        } catch (ServiceException) {
        }

        self::assertSame(['system.service.refused:refused'], $this->events());
        self::assertSame('ssh.service', $this->records[0]->target);
        self::assertSame('stop', $this->records[0]->context['action']);
    }

    public function test_cron_changes_are_recorded_and_an_unchanged_install_is_not(): void
    {
        $cron = new CronManager(new MemoryCronTable(), 'shop', $this->audit);
        $job = new CronJob('app.schedule', '* * * * *', new Command('/usr/bin/php', ['laika', 'schedule:run']));

        $cron->install($job);
        $cron->install($job);
        $cron->install(new CronJob('app.schedule', '*/5 * * * *', $job->command()));
        $cron->remove('app.schedule');

        self::assertSame(
            ['system.cron.created:succeeded', 'system.cron.updated:succeeded', 'system.cron.deleted:succeeded'],
            $this->events(),
        );
        self::assertSame('*/5 * * * *', $this->records[1]->context['schedule']);
    }

    public function test_filesystem_changes_refusals_and_failures_are_told_apart(): void
    {
        $root = $this->directory();
        $files = new SystemFilesystem(FilesystemPolicy::none()->allowWrite($root), $this->audit);

        $files->write($root . '/a.txt', 'contents');

        foreach ([
            static fn() => $files->write($root . '/../escape.txt', 'x'),
            static fn() => $files->write($root . '/a.txt', 'again'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('no exception');
            } catch (FilesystemException) {
            }
        }

        self::assertSame(
            ['system.filesystem.changed:succeeded', 'system.filesystem.refused:refused', 'system.filesystem.failed:failed'],
            $this->events(),
        );
        self::assertSame(8, $this->records[0]->context['bytes']);
        self::assertSame('write', $this->records[0]->context['operation']);
    }

    public function test_a_refused_permission_change_is_recorded(): void
    {
        $root = $this->directory();
        \file_put_contents($root . '/a.txt', 'x');
        $permissions = new PermissionManager(FilesystemPolicy::none()->allowWrite($root), audit: $this->audit);

        try {
            $permissions->chmod($root . '/a.txt', 0o777);
            self::fail('no refusal');
        } catch (PermissionException) {
        }

        self::assertSame(['system.permission.refused:refused'], $this->events());
        self::assertSame('0777', $this->records[0]->context['mode']);
    }

    public function test_authorization_records_the_principal_and_questions_record_nothing(): void
    {
        $access = new AccessRegistry();
        $collector = new AccessCollector($access, 'Server');
        SystemCapability::declare($collector, SystemCapability::ServiceRestart);
        $collector->role('operator', [SystemCapability::ServiceRestart->value]);
        $system = new SystemAuthorizer(new Authorizer($access), $this->audit);

        $system->allows(new Identity('7', 'ada', ['operator']), SystemCapability::ServiceRestart, 'nginx.service');
        self::assertSame([], $this->events(), 'allows() is a question');

        $system->authorize(new Identity('7', 'ada', ['operator']), SystemCapability::ServiceRestart, 'nginx.service');

        try {
            $system->authorize(new Identity('8', 'bob'), SystemCapability::ServiceRestart, 'nginx.service');
            self::fail('no refusal');
        } catch (SystemAuthorizationException) {
        }

        self::assertSame(['system.authorization.granted:succeeded', 'system.authorization.denied:refused'], $this->events());
        self::assertSame('7', $this->records[0]->context['principal']);
        self::assertSame('8', $this->records[1]->context['principal']);
        self::assertSame('system.service.restart', $this->records[1]->context['capability']);
    }

    // ---- what is never recorded --------------------------------------------------------------

    /**
     * A secret, put everywhere a caller can put one, and then looked for in
     * every record every manager made.
     */
    public function test_no_record_contains_a_secret_value(): void
    {
        $root = $this->directory();
        $secret = new Secret(self::SECRET);

        (new CommandExecutor(audit: $this->audit))->run(new Command(
            \PHP_BINARY,
            ['-n', '-r', 'echo getenv("TOKEN"), $argv[1], stream_get_contents(STDIN);', $secret],
            environment: ['TOKEN' => $secret],
            stdin: self::SECRET,
        ));
        (new ProcessManager(audit: $this->audit))->start(new Command(\PHP_BINARY, ['-n', '-r', 'echo 1;', self::SECRET]))->wait();
        (new SystemFilesystem(FilesystemPolicy::none()->allowWrite($root), $this->audit))->write($root . '/token.txt', self::SECRET);

        self::assertGreaterThanOrEqual(5, \count($this->records));

        foreach ($this->records as $record) {
            self::assertStringNotContainsString(self::SECRET, (string) \json_encode($record->toArray()), $record->event);
        }
    }

    // ---- the log ------------------------------------------------------------------------------

    public function test_the_log_bridge_writes_the_audit_channel_at_a_level_for_its_reader(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);
        $bridge = new SystemAuditLog($logs);

        $bridge(new AuditRecord('system.service.changed', AuditOutcome::Succeeded, 'nginx.service', ['action' => 'reload']));
        $bridge(new AuditRecord('system.service.refused', AuditOutcome::Refused, 'ssh.service', ['action' => 'stop']));
        $bridge(new AuditRecord('system.command.failed', AuditOutcome::Failed, 'rsync', ['exit_code' => 23]));

        self::assertSame(['system.service.changed succeeded', 'system.service.refused refused', 'system.command.failed failed'], $writer->messages());
        self::assertSame([Level::Info, Level::Warning, Level::Error], \array_map(static fn($r) => $r->level, $writer->records));
        self::assertSame(['audit'], \array_values(\array_unique(\array_map(static fn($r) => $r->channel, $writer->records))));
        self::assertSame('ssh.service', $writer->records[1]->context['target']);
    }

    /** Bootstrap attaches the bridge, so a module only has to build its managers with a SystemAudit. */
    public function test_a_booted_application_writes_audit_records_to_the_log(): void
    {
        $app = $this->shippedApplication();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        (new SystemAudit($app->container()->get(HookEngine::class)))->record('system.cron.deleted', AuditOutcome::Succeeded, 'app.schedule');

        $record = $writer->last();

        self::assertNotNull($record);
        self::assertSame('system.cron.deleted succeeded', $record->message);
        self::assertSame('audit', $record->channel);
    }
}
