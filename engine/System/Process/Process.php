<?php

declare(strict_types=1);

namespace App\Engine\System\Process;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandResult;
use App\Engine\System\Command\CommandSlot;
use App\Engine\System\Command\Invocation;

/**
 * One process this PHP process started, and is still responsible for.
 *
 *     $process->pid();          // the operating system's id
 *     $process->isRunning();
 *     $process->exitCode();     // null while running, or when a signal ended it
 *     $process->signal(10);     // POSIX only
 *     $process->terminate();    // SIGTERM, a grace period, then SIGKILL
 *     $process->wait();         // a CommandResult, as CommandExecutor::run() returns
 *
 * **The timeout still applies, when the process is looked at.** Nothing runs in
 * the background to enforce it -- PHP has nowhere for that to run -- so it is
 * checked by isRunning(), exitCode() and wait(). A process past its timeout is
 * stopped the moment any of those is asked, and wait() reports timedOut(). One
 * that is never looked at again is stopped when this object is destroyed.
 *
 * **Destroying a running Process terminates it.** Its owner forgetting it --
 * the request ending, the variable going out of scope -- does not leave a
 * process behind that nothing will ever wait for, read, or stop. A process meant
 * to outlive its owner is a service or a queued job, not this.
 *
 * **Output goes to temporary files**, removed once the result has been read or
 * the object is destroyed, and read into the result up to the output limit.
 * Past the limit it is still written to disk until then, so a long-running,
 * noisy process should write its own log instead. Input is a temporary file too,
 * for the process's whole life -- stdin holding a secret spends that long on
 * the temporary disk.
 *
 * Only the process itself is signalled or stopped; children it started of its
 * own are not.
 */
final class Process
{
    public const MAX_SIGNAL = 64;

    private readonly int $pid;

    private bool $running = true;

    private ?int $exitCode = null;

    private bool $timedOut = false;

    private float $seconds = 0.0;

    private ?CommandResult $result = null;

    /** Stopped on request -- by terminate() or by being destroyed -- rather than ending on its own. */
    private bool $stopped = false;

    /**
     * Internal: made by ProcessManager::start().
     *
     * @param resource                                   $process
     * @param array<int, string>                         $files     stdin, stdout and stderr, by descriptor
     * @param array<string, string|int|float|bool|null> $described what the audit may say about the command
     */
    public function __construct(
        private $process,
        private readonly int|float $started,
        private readonly float $timeout,
        private readonly int $limit,
        private array $files,
        private readonly ?SystemAudit $audit = null,
        private readonly array $described = [],
        private readonly ?CommandSlot $slot = null,
    ) {
        $this->pid = \proc_get_status($process)['pid'];
    }

    public function __destruct()
    {
        // Already released by wait(), or freed first during shutdown.
        if (!\is_resource($this->process)) {
            $this->release();

            return;
        }

        $this->observe();

        if ($this->running) {
            $this->stopped = true;
            $this->finish(Invocation::stop($this->process, CommandExecutor::TERMINATE_GRACE));
        }

        $this->release();
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        $this->observe();

        return $this->running;
    }

    /** Null while it runs, and after a signal or a timeout ended it. */
    public function exitCode(): ?int
    {
        $this->observe();

        return $this->exitCode;
    }

    /**
     * Send a signal. POSIX only: PHP on Windows cannot deliver one, and
     * pretending a signal was sent by terminating the process instead would
     * make SIGHUP mean something it does not.
     */
    public function signal(int $signal): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            throw ProcessException::signalsUnsupported();
        }

        if ($signal < 1 || $signal > self::MAX_SIGNAL) {
            throw ProcessException::invalidSignal($signal);
        }

        $this->observe();

        if (!$this->running) {
            throw ProcessException::notRunning($this->pid, 'signalled');
        }

        \proc_terminate($this->process, $signal);
    }

    /**
     * Ask it to stop, give it $grace seconds, then kill it, and return once it
     * is gone. A process that has already finished is left as it is: the
     * result of stopping something is that it has stopped.
     */
    public function terminate(float $grace = CommandExecutor::TERMINATE_GRACE): void
    {
        if (!\is_finite($grace) || $grace < 0.0) {
            throw ProcessException::invalidGrace($grace);
        }

        $this->observe();

        if ($this->running) {
            $this->stopped = true;
            $this->finish(Invocation::stop($this->process, $grace));
        }
    }

    /**
     * Block until it ends, or until its timeout stops it. Calling it again
     * returns the same result.
     */
    public function wait(): CommandResult
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $interval = 1_000;

        while ($this->isRunning()) {
            \usleep($interval);
            $interval = \min($interval * 2, 20_000);
        }

        $truncated = false;
        $stdout = Invocation::readFile($this->files[1], $this->limit, $truncated);
        $stderr = Invocation::readFile($this->files[2], $this->limit, $truncated);

        $result = new CommandResult($this->exitCode, $stdout, $stderr, $this->seconds, $this->timedOut, $truncated);
        $this->result = $result;
        $this->release();

        return $result;
    }

    /** Catch up with the operating system, and enforce the timeout while doing so. */
    private function observe(): void
    {
        if (!$this->running) {
            return;
        }

        $status = \proc_get_status($this->process);

        if (!$status['running']) {
            $this->finish($status);

            return;
        }

        if (Invocation::since($this->started) >= $this->timeout) {
            $this->timedOut = true;
            $this->finish(Invocation::stop($this->process, CommandExecutor::TERMINATE_GRACE));
        }
    }

    /**
     * Record the status that saw the process gone. PHP reports a real exit code
     * only once -- before 8.3, a second proc_get_status() says -1 -- so this is
     * the only place it is read.
     *
     * @param array{signaled: bool, exitcode: int} $status
     */
    private function finish(array $status): void
    {
        $this->running = false;
        $this->seconds = Invocation::since($this->started);
        $this->exitCode = $this->timedOut ? null : Invocation::exitCodeOf($status);

        // The concurrency slot goes back the moment the process is known to be gone.
        $this->slot?->release();

        $this->audit?->record(
            match (true) {
                $this->timedOut => 'system.process.timeout',
                $this->stopped => 'system.process.terminated',
                default => 'system.process.finished',
            },
            $this->exitCode === 0 ? AuditOutcome::Succeeded : AuditOutcome::Failed,
            \is_string($this->described['program'] ?? null) ? $this->described['program'] : null,
            [...$this->described, 'pid' => $this->pid, 'exit_code' => $this->exitCode, 'duration_ms' => (int) \round($this->seconds * 1000)],
        );
    }

    /** Close the handle and remove the files; safe to call more than once. */
    private function release(): void
    {
        if (\is_resource($this->process)) {
            \proc_close($this->process);
        }

        foreach ($this->files as $file) {
            @\unlink($file);
        }

        $this->files = [];
    }
}
