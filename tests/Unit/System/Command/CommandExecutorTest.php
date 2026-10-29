<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\Security\Secret;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\CommandExecutor;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * Real processes, and every one of them is this PHP.
 *
 * PHP_BINARY is the one program certain to exist wherever the suite runs --
 * Windows under XAMPP, Ubuntu in CI -- and `php -r` makes a child that does
 * exactly what a test needs, which a system binary would not do the same way
 * on both.
 */
final class CommandExecutorTest extends TestCase
{
    private CommandExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new CommandExecutor();
    }

    /** @param list<string|Secret> $arguments */
    private function php(string $code, array $arguments = [], mixed ...$options): Command
    {
        return new Command(\PHP_BINARY, ['-n', '-r', $code, '--', ...$arguments], ...$options);
    }

    // ---- running ---------------------------------------------------------------

    public function test_a_successful_command_returns_its_output_and_a_zero_exit(): void
    {
        $result = $this->executor->run($this->php('echo "hello";'));

        self::assertTrue($result->successful());
        self::assertSame(0, $result->exitCode());
        self::assertSame('hello', $result->stdout());
        self::assertSame('', $result->stderr());
        self::assertFalse($result->timedOut());
        self::assertFalse($result->truncated());
        self::assertGreaterThan(0.0, $result->duration());
    }

    public function test_a_non_zero_exit_is_a_result_not_an_exception(): void
    {
        $result = $this->executor->run($this->php('echo "partial"; fwrite(STDERR, "went wrong"); exit(3);'));

        self::assertTrue($result->failed());
        self::assertSame(3, $result->exitCode());
        self::assertSame('partial', $result->stdout());
        self::assertSame('went wrong', $result->stderr());
    }

    public function test_stderr_alone_does_not_fail_a_command(): void
    {
        $result = $this->executor->run($this->php('fwrite(STDERR, "warning: deprecated");'));

        self::assertTrue($result->successful());
        self::assertSame('warning: deprecated', $result->stderr());
    }

    // ---- injection -------------------------------------------------------------

    /**
     * The test that the whole design exists to pass. Each of these would be a
     * second command, a substitution or a split in a shell; here the child
     * receives them as the exact strings it was given.
     */
    public function test_every_argument_reaches_the_process_byte_for_byte(): void
    {
        $arguments = [
            'file; echo INJECTED',
            '$(echo INJECTED)',
            '`echo INJECTED`',
            '| echo INJECTED',
            '&& echo INJECTED',
            '$HOME',
            '"double" \'single\'',
            'a b  c',
            '',
            ' ',
            'trailing\\',
            'C:\\path with\\spaces\\',
            '%PATH% ^& !x!',
            "line\nbreak",
            '*.php',
            'ঢাকা',
        ];

        $result = $this->executor->run($this->php('echo json_encode(array_slice($argv, 1));', $arguments));

        self::assertSame(0, $result->exitCode(), $result->stderr());
        self::assertSame($arguments, \json_decode($result->stdout(), true));
        self::assertStringNotContainsString('INJECTED' . \PHP_EOL, $result->stdout());
    }

    public function test_a_secret_argument_is_revealed_to_the_process_only(): void
    {
        $command = $this->php('echo $argv[1];', [new Secret('S3cret')]);

        self::assertStringNotContainsString('S3cret', \print_r($command, true));
        self::assertSame('S3cret', $this->executor->run($command)->stdout());
    }

    // ---- the executable --------------------------------------------------------

    public function test_a_bare_name_is_found_on_the_path_the_child_is_given(): void
    {
        $name = \basename(\PHP_BINARY, '.exe');
        $environment = ['PATH' => \dirname(\PHP_BINARY)];

        if (\PHP_OS_FAMILY === 'Windows') {
            $environment['SYSTEMROOT'] = (string) \getenv('SYSTEMROOT');
        }

        $result = $this->executor->run(new Command($name, ['-n', '-r', 'echo "found";'], environment: $environment));

        self::assertSame('found', $result->stdout(), $result->stderr());
    }

    public function test_a_bare_name_that_is_not_on_the_path_is_not_found_by_name(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('No executable named "lphp-no-such-program" was found');

        $this->executor->run(new Command('lphp-no-such-program', environment: ['PATH' => \dirname(\PHP_BINARY)]));
    }

    public function test_a_missing_path_is_not_found_and_not_repeated(): void
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'no such dir' . \DIRECTORY_SEPARATOR . 'mysql -pS3cret';

        try {
            $this->executor->run(new Command($path));
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString('does not name a file this process may execute', $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
        }
    }

    public function test_a_directory_is_not_an_executable(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('does not name a file');

        $this->executor->run(new Command(\dirname(\PHP_BINARY)));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_file_without_execute_permission_is_not_run(): void
    {
        $script = \tempnam(\sys_get_temp_dir(), 'noexec');
        self::assertIsString($script);
        \file_put_contents($script, "#!/bin/sh\necho ran\n");
        \chmod($script, 0o644);

        try {
            $this->expectException(CommandException::class);
            $this->executor->run(new Command($script));
        } finally {
            \unlink($script);
        }
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_a_windows_batch_file_is_refused(): void
    {
        $script = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'lphp-' . \bin2hex(\random_bytes(4)) . '.bat';
        \file_put_contents($script, "@echo ran\r\n");

        try {
            $this->expectException(CommandException::class);
            $this->expectExceptionMessage('is a Windows batch file');
            $this->executor->run(new Command($script, ['&& echo INJECTED']));
        } finally {
            \unlink($script);
        }
    }

    // ---- working directory -----------------------------------------------------

    public function test_the_process_runs_in_the_working_directory(): void
    {
        $directory = (string) \realpath(\sys_get_temp_dir());
        $result = $this->executor->run($this->php('echo getcwd();', workingDirectory: $directory));

        self::assertSame($directory, \realpath($result->stdout()));
    }

    public function test_a_missing_working_directory_is_refused_before_anything_runs(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('does not exist or is not a directory');

        $this->executor->run($this->php('echo 1;', workingDirectory: \sys_get_temp_dir() . '/lphp-missing-' . \bin2hex(\random_bytes(4))));
    }

    // ---- environment -----------------------------------------------------------

    public function test_a_given_environment_is_the_whole_environment(): void
    {
        $result = $this->executor->run($this->php(
            'echo json_encode([getenv("GREETING"), getenv("API_KEY"), getenv("PATH")]);',
            environment: ['GREETING' => 'hello world', 'API_KEY' => new Secret('k3y')],
        ));

        self::assertSame(['hello world', 'k3y', false], \json_decode($result->stdout(), true), $result->stderr());
    }

    /**
     * The parent's secrets do not travel. Whatever this PHP holds -- APP_KEY,
     * DB_PASSWORD, CI tokens -- the child of a Command with no environment sees
     * only the short inherited list.
     */
    public function test_the_default_environment_passes_only_the_inherited_names(): void
    {
        $result = $this->executor->run($this->php('echo json_encode(array_keys(getenv()));'));

        $allowed = \array_map('strtoupper', [...CommandExecutor::INHERITED_ENVIRONMENT, ...CommandExecutor::INHERITED_ON_WINDOWS]);
        $names = \json_decode($result->stdout(), true);

        self::assertIsArray($names, $result->stderr());

        foreach ($names as $name) {
            self::assertIsString($name);
            self::assertContains(\strtoupper($name), $allowed, \sprintf('%s reached the child process', $name));
        }
    }

    // ---- input -----------------------------------------------------------------

    public function test_stdin_is_written_to_the_process_and_closed(): void
    {
        $result = $this->executor->run($this->php('echo strtoupper(stream_get_contents(STDIN));', stdin: 'shout'));

        self::assertSame('SHOUT', $result->stdout());
    }

    public function test_without_stdin_the_process_reads_end_of_input_rather_than_waiting(): void
    {
        $result = $this->executor->run($this->php('echo strlen(stream_get_contents(STDIN));', timeout: 10.0));

        self::assertFalse($result->timedOut());
        self::assertSame('0', $result->stdout());
    }

    /**
     * Megabytes each way at once. Written and read naively, the child blocks on
     * a full output pipe while the parent blocks on a full input pipe, and
     * nothing moves until the timeout.
     */
    public function test_large_input_and_output_together_do_not_deadlock(): void
    {
        $input = \str_repeat('0123456789abcdef', 256 * 1024);

        $result = $this->executor->run($this->php(
            'while (!feof(STDIN)) { echo fread(STDIN, 8192); }',
            stdin: $input,
            timeout: 30.0,
            maxOutput: 8 * 1024 * 1024,
        ));

        self::assertFalse($result->timedOut());
        self::assertSame(\strlen($input), \strlen($result->stdout()));
        self::assertSame(\md5($input), \md5($result->stdout()));
    }

    // ---- limits ----------------------------------------------------------------

    public function test_a_timeout_stops_the_process_and_keeps_its_output(): void
    {
        $result = $this->executor->run($this->php('echo "before"; fwrite(STDERR, "err"); sleep(30);', timeout: 0.5));

        self::assertTrue($result->timedOut());
        self::assertNull($result->exitCode());
        self::assertTrue($result->failed());
        self::assertSame('before', $result->stdout());
        self::assertSame('err', $result->stderr());
        self::assertGreaterThanOrEqual(0.5, $result->duration());
        self::assertLessThan(5.0, $result->duration());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_process_that_ignores_a_polite_stop_is_killed(): void
    {
        if (!\extension_loaded('pcntl')) {
            self::markTestSkipped('pcntl is needed for the child to ignore SIGTERM');
        }

        $result = $this->executor->run(new Command(
            \PHP_BINARY,
            ['-r', 'pcntl_signal(SIGTERM, SIG_IGN); echo "stubborn"; while (true) { usleep(10000); }'],
            timeout: 0.3,
        ));

        self::assertTrue($result->timedOut());
        self::assertSame('stubborn', $result->stdout());
        self::assertLessThan(5.0, $result->duration());
    }

    public function test_output_past_the_limit_is_dropped_and_reported(): void
    {
        $result = $this->executor->run($this->php(
            'echo str_repeat("x", 200000); fwrite(STDERR, str_repeat("e", 10));',
            maxOutput: 1000,
        ));

        self::assertTrue($result->successful());
        self::assertTrue($result->truncated());
        self::assertSame(\str_repeat('x', 1000), $result->stdout());
        self::assertSame(\str_repeat('e', 10), $result->stderr());
    }

    public function test_output_exactly_at_the_limit_is_not_truncated(): void
    {
        $result = $this->executor->run($this->php('echo str_repeat("x", 1000);', maxOutput: 1000));

        self::assertFalse($result->truncated());
        self::assertSame(1000, \strlen($result->stdout()));
    }

    public function test_the_executor_defaults_apply_when_the_command_names_none(): void
    {
        $executor = new CommandExecutor(defaultTimeout: 0.5, defaultMaxOutput: 3);
        $result = $executor->run($this->php('echo "abcdef"; sleep(30);'));

        self::assertTrue($result->timedOut());
        self::assertTrue($result->truncated());
        self::assertSame('abc', $result->stdout());
    }

    public function test_the_executor_refuses_defaults_that_are_not_limits(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is not a timeout');

        new CommandExecutor(defaultTimeout: 0.0);
    }

    public function test_the_executor_refuses_an_output_limit_that_is_not_a_limit(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is not a limit');

        new CommandExecutor(defaultMaxOutput: 0);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_process_ended_by_a_signal_has_no_exit_code(): void
    {
        if (!\extension_loaded('posix')) {
            self::markTestSkipped('posix is needed for the child to signal itself');
        }

        $result = $this->executor->run(new Command(\PHP_BINARY, ['-r', 'posix_kill(getmypid(), 9);']));

        self::assertNull($result->exitCode());
        self::assertFalse($result->timedOut());
        self::assertTrue($result->failed());
    }
}
