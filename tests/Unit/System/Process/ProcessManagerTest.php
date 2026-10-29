<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Process;

use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\ShellCommand;
use App\Engine\System\Process\Process;
use App\Engine\System\Process\ProcessException;
use App\Engine\System\Process\ProcessManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * Started processes, all of them this PHP, so the timings are the child's own
 * and the same assertions hold on Windows and Linux.
 */
final class ProcessManagerTest extends TestCase
{
    private ProcessManager $processes;

    protected function setUp(): void
    {
        $this->processes = new ProcessManager();
    }

    private function php(string $code, mixed ...$options): Command
    {
        return new Command(\PHP_BINARY, ['-n', '-r', $code], ...$options);
    }

    // ---- start and wait ------------------------------------------------------------

    public function test_start_returns_while_the_process_is_still_running(): void
    {
        // A two-second child and a 1.5 s bound: wide enough for a loaded machine,
        // still impossible if start() had waited.
        $before = \hrtime(true);
        $process = $this->processes->start($this->php('usleep(2000000); echo "done";'));

        self::assertLessThan(1.5, (\hrtime(true) - $before) / 1e9, 'start() waited for the process');
        self::assertTrue($process->isRunning());
        self::assertNull($process->exitCode());

        $result = $process->wait();

        self::assertFalse($process->isRunning());
        self::assertSame(0, $result->exitCode());
        self::assertSame('done', $result->stdout());
        self::assertGreaterThanOrEqual(2.0, $result->duration());
    }

    public function test_the_pid_is_the_processs_own(): void
    {
        $process = $this->processes->start($this->php('echo getmypid();'));

        self::assertGreaterThan(0, $process->pid());
        self::assertSame((string) $process->pid(), $process->wait()->stdout());
    }

    public function test_the_exit_code_and_stderr_are_kept(): void
    {
        $process = $this->processes->start($this->php('fwrite(STDERR, "bad input"); exit(7);'));
        $result = $process->wait();

        self::assertSame(7, $result->exitCode());
        self::assertSame(7, $process->exitCode());
        self::assertSame('bad input', $result->stderr());
        self::assertTrue($result->failed());
    }

    public function test_waiting_again_returns_the_same_result(): void
    {
        $process = $this->processes->start($this->php('echo "once";'));

        self::assertSame($process->wait(), $process->wait());
    }

    public function test_stdin_reaches_a_started_process(): void
    {
        $result = $this->processes->start($this->php('echo strrev(stream_get_contents(STDIN));', stdin: 'abc'))->wait();

        self::assertSame('cba', $result->stdout());
    }

    /**
     * Three one-second processes finish in about one second, not three. The
     * bound leaves a loaded machine room and still rules out running them in turn.
     */
    public function test_processes_run_at_the_same_time(): void
    {
        $before = \hrtime(true);
        $started = [];

        foreach ([1, 2, 3] as $n) {
            $started[] = $this->processes->start($this->php('usleep(1000000); echo ' . $n . ';'));
        }

        $outputs = \array_map(static fn(Process $p): string => $p->wait()->stdout(), $started);

        self::assertSame(['1', '2', '3'], $outputs);
        self::assertLessThan(2.5, (\hrtime(true) - $before) / 1e9);
    }

    public function test_output_past_the_limit_is_dropped_and_reported(): void
    {
        $result = $this->processes->start($this->php('echo str_repeat("y", 50000);', maxOutput: 100))->wait();

        self::assertTrue($result->truncated());
        self::assertSame(\str_repeat('y', 100), $result->stdout());
    }

    public function test_nothing_starts_when_the_command_cannot_run(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('No executable named "lphp-no-such-program"');

        $this->processes->start(new Command('lphp-no-such-program', environment: ['PATH' => \dirname(\PHP_BINARY)]));
    }

    // ---- stopping ---------------------------------------------------------------------

    public function test_terminate_stops_a_running_process(): void
    {
        $process = $this->processes->start($this->php('echo "working"; sleep(30);'));
        \usleep(300_000);

        $process->terminate();

        self::assertFalse($process->isRunning());

        $result = $process->wait();

        self::assertTrue($result->failed());
        self::assertFalse($result->timedOut());
        self::assertSame('working', $result->stdout());
        self::assertLessThan(5.0, $result->duration());
    }

    public function test_terminating_a_finished_process_changes_nothing(): void
    {
        $process = $this->processes->start($this->php('exit(0);'));
        $process->wait();

        $process->terminate();

        self::assertSame(0, $process->exitCode());
    }

    public function test_a_grace_period_that_is_not_one_is_refused(): void
    {
        $process = $this->processes->start($this->php('echo 1;'));

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('A grace period of -1.0 seconds is not usable');

        $process->terminate(-1.0);
    }

    public function test_the_timeout_stops_the_process_while_waiting(): void
    {
        $result = $this->processes->start($this->php('echo "slow"; sleep(30);', timeout: 0.5))->wait();

        self::assertTrue($result->timedOut());
        self::assertNull($result->exitCode());
        self::assertSame('slow', $result->stdout());
        self::assertLessThan(5.0, $result->duration());
    }

    public function test_the_timeout_is_enforced_whenever_the_process_is_looked_at(): void
    {
        $process = $this->processes->start($this->php('sleep(30);', timeout: 0.3));
        \usleep(600_000);

        self::assertFalse($process->isRunning(), 'a process past its timeout still reported running');
        self::assertTrue($process->wait()->timedOut());
    }

    /**
     * Forgetting a process does not leave one behind. The child records its pid
     * and sleeps; once the Process is gone, so is that pid.
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function test_destroying_a_running_process_stops_it(): void
    {
        if (!\extension_loaded('posix')) {
            self::markTestSkipped('posix is needed to look for the pid');
        }

        $process = $this->processes->start($this->php('sleep(30);'));
        $pid = $process->pid();

        self::assertTrue(\posix_kill($pid, 0), 'the process was not running to begin with');

        unset($process);

        self::assertFalse(\posix_kill($pid, 0), 'the process outlived the object that owned it');
    }

    public function test_destroying_a_running_process_returns_promptly(): void
    {
        $process = $this->processes->start($this->php('sleep(30);'));
        $before = \hrtime(true);

        unset($process);

        self::assertLessThan(5.0, (\hrtime(true) - $before) / 1e9);
    }

    // ---- signals ---------------------------------------------------------------------

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_signal_reaches_the_process(): void
    {
        if (!\extension_loaded('pcntl')) {
            self::markTestSkipped('pcntl is needed for the child to handle a signal');
        }

        $process = $this->processes->start(new Command(\PHP_BINARY, [
            '-r',
            'pcntl_async_signals(true); pcntl_signal(SIGUSR1, function () { echo "got usr1"; exit(3); }); '
            . 'echo "ready\n"; while (true) { usleep(10000); }',
        ], timeout: 10.0));

        $this->waitForOutput($process);
        $process->signal(10);

        $result = $process->wait();

        self::assertSame(3, $result->exitCode());
        self::assertStringContainsString('got usr1', $result->stdout());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_process_ended_by_a_signal_has_no_exit_code(): void
    {
        $process = $this->processes->start($this->php('sleep(30);'));
        $process->signal(9);

        self::assertNull($process->wait()->exitCode());
        self::assertFalse($process->wait()->timedOut());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_finished_process_cannot_be_signalled(): void
    {
        $process = $this->processes->start($this->php('exit(0);'));
        $process->wait();

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage(\sprintf('Process %d has already finished, so it cannot be signalled', $process->pid()));

        $process->signal(15);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_signal_number_that_does_not_exist_is_refused(): void
    {
        $process = $this->processes->start($this->php('sleep(30);'));

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('0 is not a signal number');

        $process->signal(0);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_windows_cannot_send_a_signal(): void
    {
        $process = $this->processes->start($this->php('sleep(30);'));

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('Signals are a POSIX feature');

        $process->signal(15);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_shell_script_can_be_started(): void
    {
        $script = \tempnam(\sys_get_temp_dir(), 'proc');
        self::assertIsString($script);
        \file_put_contents($script, 'echo "from bash: $1"');

        try {
            $result = (new ProcessManager(shell: true))->start(ShellCommand::bash($script, ['arg; echo INJECTED']))->wait();

            self::assertSame("from bash: arg; echo INJECTED\n", $result->stdout(), $result->stderr());
        } finally {
            \unlink($script);
        }
    }

    public function test_the_manager_refuses_defaults_that_are_not_limits(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is not a timeout');

        new ProcessManager(defaultTimeout: \INF);
    }

    /** Output arrives in a file; poll for it rather than sleeping a guess. */
    private function waitForOutput(Process $process): void
    {
        $deadline = \hrtime(true) + 5_000_000_000;

        while (\hrtime(true) < $deadline) {
            $files = (new \ReflectionProperty(Process::class, 'files'))->getValue($process);
            self::assertIsArray($files);

            if (\is_string($files[1] ?? null) && (int) @\filesize($files[1]) > 0) {
                return;
            }

            \clearstatcache();
            \usleep(10_000);
        }

        self::fail('the process never became ready');
    }
}
