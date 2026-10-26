<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\Filter\FilterEngine;
use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;

/**
 * Runs a Command and waits for it.
 *
 *     $result = $executor->run(new Command('rsync', ['-a', '--', $from, $to], timeout: 600.0));
 *
 *     if ($result->failed()) { ... $result->stderr() ... }
 *
 * To start a process and carry on, see Process\ProcessManager; both prepare a
 * command the same way, through Invocation.
 *
 * **No shell, unless it was asked for by type.** The executable and its
 * arguments go to proc_open() as an array, which PHP passes to the operating
 * system as separate strings. Nothing is joined, quoted or interpreted on the
 * way. A ShellCommand runs bash on a script file, and even then its arguments
 * travel the same way, as $1, $2, ....
 *
 * **Throws only when nothing ran.** A missing executable, a missing working
 * directory, a host without proc_open(): CommandException. A process that
 * started always produces a CommandResult -- a non-zero exit, a signal and a
 * timeout included -- because its output is the explanation and an exception
 * would lose it.
 *
 * **The executable is found before anything starts.** A bare name is looked up
 * on the PATH the child will have, and a path must be an executable file. On
 * Linux proc_open() forks before it discovers a missing program, so the only
 * signal it gives is a child that exits 127 -- which is also what a script
 * returns when a command inside it is missing. Looking first is the only way
 * to say "not found" and mean it. On Windows, a .bat or .cmd file is refused:
 * Windows runs those through cmd.exe, which reads the arguments as shell syntax.
 *
 * **A timeout stops the process.** It is asked to stop (SIGTERM), given a
 * second, then killed. Output written before that is kept, and the result says
 * timedOut() with no exit code. Only the process itself is stopped: anything it
 * started of its own is not.
 *
 * **Output is bounded.** Each stream keeps at most the output limit from its
 * start; the rest is read and dropped, so a noisy process is not blocked by a
 * full pipe and memory does not grow with it, and the result says truncated().
 *
 * **The environment is small by default.** A Command that names no environment
 * gets only INHERITED_ENVIRONMENT from this process -- PATH, HOME, locale, time
 * zone, temporary directory -- and never the rest: APP_KEY, DB_PASSWORD and
 * every other secret a PHP process holds would otherwise reach every program it
 * runs. A command that needs more says so in its own environment.
 *
 * **Two ways to wait, one behaviour.** On Linux the executor reads the pipes as
 * data arrives, with stream_select(), so input and output cannot deadlock. On
 * Windows stream_select() does not work on process pipes, so input and output go
 * through temporary files that are removed when the command finishes; output
 * past the limit is written to that file and never read. Windows is a
 * development platform here, not a deployment target.
 */
final class CommandExecutor
{
    public const DEFAULT_TIMEOUT = 60.0;

    /** 1 MiB per stream. */
    public const DEFAULT_MAX_OUTPUT = 1_048_576;

    /** What a Command with no environment of its own is given from this process. */
    public const INHERITED_ENVIRONMENT = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR'];

    /** Without these a Windows program cannot find its own system libraries or temp directory. */
    public const INHERITED_ON_WINDOWS = ['SYSTEMROOT', 'WINDIR', 'PATHEXT', 'TEMP', 'TMP'];

    /** Seconds between asking a timed-out process to stop and killing it. */
    public const TERMINATE_GRACE = 1.0;

    private const CHUNK = 65_536;

    /**
     * @param float          $defaultTimeout   seconds, for a Command that names none
     * @param int            $defaultMaxOutput bytes per stream, for a Command that names none
     * @param ?CommandPolicy $policy           when given, only what it allows runs
     * @param ?SystemAudit   $audit            when given, every run and every refusal is recorded
     * @param bool           $shell            whether a ShellCommand may run; off unless asked for
     * @param string         $shellBinary      the bash that runs one: "bash" on PATH, or an absolute path
     * @param ?ConcurrencyLimit $concurrency   when given, a command runs only while it holds a slot
     * @param ?FilterEngine  $filters          when given, system.command.timeout and .max_output may narrow the limits
     */
    public function __construct(
        private readonly float $defaultTimeout = self::DEFAULT_TIMEOUT,
        private readonly int $defaultMaxOutput = self::DEFAULT_MAX_OUTPUT,
        private readonly ?CommandPolicy $policy = null,
        private readonly ?SystemAudit $audit = null,
        private readonly bool $shell = false,
        private readonly string $shellBinary = ShellCommand::BASH,
        private readonly ?ConcurrencyLimit $concurrency = null,
        private readonly ?FilterEngine $filters = null,
    ) {
        Invocation::checkDefaults($defaultTimeout, $defaultMaxOutput);
        Invocation::checkShellBinary($shellBinary);
    }

    /**
     * A ShellCommand runs as `bash --noprofile --norc -- <script> <arguments>`:
     * the script is checked to be a readable file first, for the same reason
     * the executable is, and is not quoted when it is not.
     *
     * Audited as system.command.started, then .completed, .failed or .timeout;
     * or .refused when the allowlist says no.
     */
    public function run(Command|ShellCommand $command): CommandResult
    {
        $described = $this->audit === null ? [] : Invocation::describe($command);

        try {
            $invocation = Invocation::prepare($command, $this->defaultTimeout, $this->defaultMaxOutput, $this->policy, $this->shell, $this->shellBinary, $this->filters);
            $slot = $this->concurrency?->acquire();

            if ($this->concurrency !== null && $slot === null) {
                throw CommandPolicyException::tooManyRunning($this->concurrency->slots());
            }
        } catch (CommandPolicyException $e) {
            $this->audit?->record('system.command.refused', AuditOutcome::Refused, $described['program'] ?? null, $described);

            throw $e;
        }

        $this->audit?->record('system.command.started', AuditOutcome::Started, $described['program'] ?? null, $described);

        try {
            $result = \PHP_OS_FAMILY === 'Windows' ? self::runWithFiles($invocation) : self::runWithPipes($invocation);
        } finally {
            $slot?->release();
        }

        $this->audit?->record(
            match (true) {
                $result->timedOut() => 'system.command.timeout',
                $result->successful() => 'system.command.completed',
                default => 'system.command.failed',
            },
            $result->successful() ? AuditOutcome::Succeeded : AuditOutcome::Failed,
            $described['program'] ?? null,
            [...$described, 'exit_code' => $result->exitCode(), 'duration_ms' => (int) \round($result->duration() * 1000), 'truncated' => $result->truncated()],
        );

        return $result;
    }

    private static function runWithPipes(Invocation $invocation): CommandResult
    {
        $argv = $invocation->argv;
        $limit = $invocation->limit;
        $stdin = $invocation->stdin;

        $started = \hrtime(true);
        $process = @\proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $invocation->directory, $invocation->environment);

        if (!\is_resource($process)) {
            throw CommandException::couldNotStart('the operating system refused to create the process');
        }

        foreach ($pipes as $pipe) {
            \stream_set_blocking($pipe, false);
        }

        if ($stdin === '') {
            \fclose($pipes[0]);
            unset($pipes[0]);
        }

        $output = [1 => '', 2 => ''];
        $truncated = false;
        $exitCode = null;
        $timedOut = false;

        while (true) {
            $status = \proc_get_status($process);

            if (!$status['running']) {
                $exitCode = Invocation::exitCodeOf($status);
                self::drain($pipes, $output, $limit, $truncated);

                break;
            }

            $remaining = $invocation->timeout - Invocation::since($started);

            if ($remaining <= 0.0) {
                $timedOut = true;
                Invocation::stop($process, self::TERMINATE_GRACE);
                self::drain($pipes, $output, $limit, $truncated);

                break;
            }

            $read = \array_values(\array_filter([$pipes[1] ?? null, $pipes[2] ?? null]));
            $write = isset($pipes[0]) ? [$pipes[0]] : [];
            $except = null;
            $wait = (int) (\min($remaining, 0.05) * 1_000_000);

            if ($read === [] && $write === []) {
                \usleep($wait);

                continue;
            }

            // false is an interrupted wait; the loop simply asks again.
            if (@\stream_select($read, $write, $except, 0, $wait) === false) {
                continue;
            }

            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && \in_array($pipes[$index], $read, true)) {
                    self::readFrom($pipes, $index, $output, $limit, $truncated, 16);
                }
            }

            if (isset($pipes[0]) && $write !== []) {
                $written = @\fwrite($pipes[0], $stdin);

                // false: the process closed its input without reading all of it.
                $stdin = $written === false ? '' : \substr($stdin, $written);

                if ($stdin === '') {
                    \fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }
        }

        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }

        \proc_close($process);

        return new CommandResult($timedOut ? null : $exitCode, $output[1], $output[2], Invocation::since($started), $timedOut, $truncated);
    }

    private static function runWithFiles(Invocation $invocation): CommandResult
    {
        $argv = $invocation->argv;
        $files = [];

        try {
            $files = [0 => Invocation::temporaryFile(), 1 => Invocation::temporaryFile(), 2 => Invocation::temporaryFile()];

            if (\file_put_contents($files[0], $invocation->stdin) === false) {
                throw CommandException::couldNotStart('its input could not be written to a temporary file');
            }

            $started = \hrtime(true);
            $process = @\proc_open(
                $argv,
                [0 => ['file', $files[0], 'r'], 1 => ['file', $files[1], 'w'], 2 => ['file', $files[2], 'w']],
                $pipes,
                $invocation->directory,
                $invocation->environment,
            );

            if (!\is_resource($process)) {
                throw CommandException::couldNotStart('the operating system refused to create the process');
            }

            $exitCode = null;
            $timedOut = false;
            $interval = 1_000;

            while (true) {
                $status = \proc_get_status($process);

                if (!$status['running']) {
                    $exitCode = Invocation::exitCodeOf($status);

                    break;
                }

                if (Invocation::since($started) >= $invocation->timeout) {
                    $timedOut = true;
                    Invocation::stop($process, self::TERMINATE_GRACE);

                    break;
                }

                \usleep($interval);
                $interval = \min($interval * 2, 20_000);
            }

            \proc_close($process);
            $seconds = Invocation::since($started);
            $truncated = false;
            $stdout = Invocation::readFile($files[1], $invocation->limit, $truncated);
            $stderr = Invocation::readFile($files[2], $invocation->limit, $truncated);

            return new CommandResult($timedOut ? null : $exitCode, $stdout, $stderr, $seconds, $timedOut, $truncated);
        } finally {
            // The input may have held a secret; none of these outlives the command.
            foreach ($files as $file) {
                @\unlink($file);
            }
        }
    }

    /**
     * Read whatever is left once the process is gone. Non-blocking, so a
     * background child still holding the pipe open cannot keep this waiting.
     *
     * @param array<int, resource> $pipes
     * @param array<int, string>   $output stdout at 1, stderr at 2
     */
    private static function drain(array &$pipes, array &$output, int $limit, bool &$truncated): void
    {
        foreach ([1, 2] as $index) {
            self::readFrom($pipes, $index, $output, $limit, $truncated, 1_024);
        }
    }

    /**
     * Read up to $chunks chunks of what is available, keeping what fits.
     *
     * @param array<int, resource> $pipes
     * @param array<int, string>   $output stdout at 1, stderr at 2
     */
    private static function readFrom(array &$pipes, int $index, array &$output, int $limit, bool &$truncated, int $chunks): void
    {
        for ($i = 0; $i < $chunks && isset($pipes[$index]); ++$i) {
            $chunk = \fread($pipes[$index], self::CHUNK);

            if ($chunk === false || $chunk === '') {
                if ($chunk === false || \feof($pipes[$index])) {
                    \fclose($pipes[$index]);
                    unset($pipes[$index]);
                }

                return;
            }

            $room = $limit - \strlen($output[$index]);

            if (\strlen($chunk) > $room) {
                $chunk = \substr($chunk, 0, \max(0, $room));
                $truncated = true;
            }

            $output[$index] .= $chunk;
        }
    }
}
