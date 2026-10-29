<?php

declare(strict_types=1);

namespace App\Tests\Unit\System;

use App\Engine\Error\FrameworkException;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandFailedException;
use App\Engine\System\Command\CommandNotFoundException;
use App\Engine\System\Command\CommandPolicy;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Command\CommandResult;
use App\Engine\System\Command\CommandTimeoutException;
use App\Engine\System\Cron\CronException;
use App\Engine\System\Cron\CronJob;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\CronValidationException;
use App\Engine\System\Filesystem\FilesystemException;
use App\Engine\System\Filesystem\FilesystemPolicy;
use App\Engine\System\Filesystem\FilesystemPolicyException;
use App\Engine\System\Filesystem\PathTraversalException;
use App\Engine\System\Filesystem\SystemFilesystem;
use App\Engine\System\Service\ServiceAction;
use App\Engine\System\Service\ServiceException;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServiceNotFoundException;
use App\Engine\System\Service\ServicePolicy;
use App\Engine\System\SystemException;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * Which exception is which, and what every one of them has in common.
 */
final class ErrorModelTest extends TestCase
{
    private function thrown(\Closure $attempt): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('nothing was thrown');
    }

    public function test_a_missing_program_or_script_is_its_own_type(): void
    {
        $executor = new CommandExecutor(shell: true);

        $missing = $this->thrown(static fn() => $executor->run(new Command('lphp-no-such-program', environment: ['PATH' => \dirname(\PHP_BINARY)])));
        $script = $this->thrown(static fn() => $executor->run(\App\Engine\System\Command\ShellCommand::bash('/srv/lphp/no-such-script.sh')));

        self::assertInstanceOf(CommandNotFoundException::class, $missing);
        self::assertInstanceOf(CommandException::class, $missing);

        // On Windows, bash itself may be what is missing; either way it is "not found".
        self::assertInstanceOf(CommandNotFoundException::class, $script);
    }

    public function test_a_policy_refusal_is_not_a_broken_command(): void
    {
        $refused = $this->thrown(static fn() => (new CommandExecutor(policy: CommandPolicy::allowlist()))->run(new Command(\PHP_BINARY, ['-v'])));

        // Not a CommandException, so `catch (CommandException)` never swallows a
        // refusal; static analysis holds that, which is why it is not asserted.
        self::assertInstanceOf(CommandPolicyException::class, $refused);
    }

    // ---- results as exceptions, on request ---------------------------------------------------

    public function test_or_fail_returns_a_successful_result_unchanged(): void
    {
        $result = new CommandResult(0, 'ok');

        self::assertSame($result, $result->orFail());
    }

    public function test_or_fail_turns_a_failure_into_an_exception_that_keeps_the_result(): void
    {
        $result = new CommandResult(2, 'partial output', 'password=S3cret in a stack trace');

        $e = $this->thrown(static fn() => $result->orFail());

        self::assertInstanceOf(CommandFailedException::class, $e);
        self::assertNotInstanceOf(CommandTimeoutException::class, $e);
        self::assertSame($result, $e->result);
        self::assertSame('The command exited with 2. What it wrote is on the result.', $e->getMessage());
        self::assertStringNotContainsString('S3cret', $e->getMessage());
    }

    public function test_or_fail_distinguishes_a_timeout_and_a_signal(): void
    {
        $timeout = $this->thrown(static fn() => (new CommandResult(null, '', '', 30.0, timedOut: true))->orFail());
        $signal = $this->thrown(static fn() => (new CommandResult(null))->orFail());

        self::assertInstanceOf(CommandTimeoutException::class, $timeout);
        self::assertInstanceOf(CommandFailedException::class, $timeout, 'a timeout is caught as a failure too');
        self::assertStringContainsString('after 30.0 seconds', $timeout->getMessage());

        self::assertInstanceOf(CommandFailedException::class, $signal);
        self::assertStringContainsString('ended by a signal', $signal->getMessage());
    }

    public function test_a_real_timeout_through_or_fail(): void
    {
        $this->expectException(CommandTimeoutException::class);

        (new CommandExecutor())->run(new Command(\PHP_BINARY, ['-n', '-r', 'sleep(10);'], timeout: 0.3))->orFail();
    }

    // ---- cron, filesystem, services ------------------------------------------------------------

    public function test_a_job_that_could_never_be_written_is_a_validation_error(): void
    {
        $invalid = $this->thrown(static fn() => new CronJob('Bad Id', '* * * * *', new Command('/bin/true')));
        $corrupt = $this->thrown(static fn() => (new CronManager(new MemoryCronTable("# BEGIN LPHP CRON shop\n"), 'shop'))->jobs());

        self::assertInstanceOf(CronValidationException::class, $invalid);
        self::assertInstanceOf(CronException::class, $corrupt);
        self::assertNotInstanceOf(CronValidationException::class, $corrupt, 'a crontab problem is not a code mistake');
    }

    public function test_filesystem_refusals_traversal_and_failures_are_told_apart(): void
    {
        $root = \str_replace('\\', '/', (string) \realpath(\sys_get_temp_dir())) . '/lphp-errors-' . \bin2hex(\random_bytes(4));
        \mkdir($root);
        $files = new SystemFilesystem(FilesystemPolicy::none()->allowWrite($root));

        try {
            $traversal = $this->thrown(static fn() => $files->write($root . '/../escape.txt', 'x'));
            $outside = $this->thrown(static fn() => $files->write(\dirname($root) . '/elsewhere.txt', 'x'));
            $missing = $this->thrown(static fn() => $files->inspect($root . '/missing.txt'));
        } finally {
            \rmdir($root);
        }

        self::assertInstanceOf(PathTraversalException::class, $traversal);
        self::assertInstanceOf(FilesystemPolicyException::class, $traversal);
        self::assertTrue($traversal->isRefusal());

        self::assertInstanceOf(FilesystemPolicyException::class, $outside);
        self::assertNotInstanceOf(PathTraversalException::class, $outside);

        self::assertInstanceOf(FilesystemException::class, $missing);
        self::assertNotInstanceOf(FilesystemPolicyException::class, $missing);
        self::assertFalse($missing->isRefusal());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_unit_systemd_does_not_have_is_its_own_type(): void
    {
        $directory = \sys_get_temp_dir() . '/lphp-systemctl-' . \bin2hex(\random_bytes(4));
        \mkdir($directory);
        $systemctl = $directory . '/systemctl';
        \file_put_contents($systemctl, '#!' . \PHP_BINARY . " -n\n<?php fwrite(STDERR, \"Unit nope.service not found.\\n\"); exit(5);\n");
        \chmod($systemctl, 0o755);

        try {
            $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none()->allow('nope', ServiceAction::Restart), $systemctl);
            $e = $this->thrown(static fn() => $services->restart('nope'));
        } finally {
            \unlink($systemctl);
            \rmdir($directory);
        }

        self::assertInstanceOf(ServiceNotFoundException::class, $e);
        self::assertInstanceOf(ServiceException::class, $e);
    }

    // ---- what they all share -----------------------------------------------------------------------

    /**
     * Every exception engine/System throws is a SystemException, and so a
     * FrameworkException, whose message this framework wrote in full.
     */
    public function test_every_system_exception_is_catchable_as_one_and_says_only_what_the_framework_wrote(): void
    {
        $base = \dirname(__DIR__, 3) . '/engine/System';
        $checked = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !\str_ends_with($file->getFilename(), 'Exception.php')) {
                continue;
            }

            $relative = \substr(\str_replace('\\', '/', $file->getPathname()), \strlen(\str_replace('\\', '/', $base)) + 1, -4);
            $class = 'App\\Engine\\System\\' . \str_replace('/', '\\', $relative);

            self::assertTrue(\is_a($class, SystemException::class, true), $class . ' is not a SystemException');
            ++$checked;
        }

        self::assertGreaterThanOrEqual(17, $checked);
        self::assertTrue((new \ReflectionClass(SystemException::class))->isSubclassOf(FrameworkException::class));
    }
}
