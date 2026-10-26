<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

/**
 * What one finished command did.
 *
 * This is the contract every way of running something in engine/System returns
 * -- a direct executable, an explicit shell script, a process that was waited
 * on -- so that the code deciding what to do next never has to know which one
 * ran.
 *
 * **A missing exit code is a state, not a zero.** On Linux a process either
 * exits with 0 to 255 or is ended by a signal, and one ended by a signal has no
 * exit code at all. The executor ends a process that runs past its timeout, so
 * that one has none either. Both are null here. Reporting -1 or 0 instead is how
 * a killed backup gets logged as a successful one.
 *
 * **Output is kept, not judged.** stdout and stderr are whatever the process
 * wrote, including what it wrote before it failed or was stopped -- which is
 * usually the only explanation there is. A tool that prints warnings to stderr
 * and exits 0 succeeded; successful() looks at the exit code alone.
 *
 * **Output can be cut short, and says so.** Each stream keeps at most the
 * command's output limit, from the start. truncated() is true when anything was
 * dropped, from either stream, so a result that looks complete is complete.
 * Truncation is not failure: the process ran to its own end.
 *
 * Immutable: a result describes something that already happened.
 */
final class CommandResult
{
    /** The largest exit status a POSIX process can report. */
    public const MAX_EXIT_CODE = 255;

    /**
     * @param ?int   $exitCode 0-255, or null when the process did not exit on its own
     * @param float  $seconds  wall-clock time from start to finish
     * @param bool   $timedOut  whether the executor stopped it for running too long
     * @param bool   $truncated whether output past the limit was dropped
     */
    public function __construct(
        private readonly ?int $exitCode,
        private readonly string $stdout = '',
        private readonly string $stderr = '',
        private readonly float $seconds = 0.0,
        private readonly bool $timedOut = false,
        private readonly bool $truncated = false,
    ) {
        if ($exitCode !== null && ($exitCode < 0 || $exitCode > self::MAX_EXIT_CODE)) {
            throw CommandException::exitCodeOutOfRange($exitCode);
        }

        if (!\is_finite($seconds) || $seconds < 0.0) {
            throw CommandException::invalidDuration($seconds);
        }

        if ($timedOut && $exitCode !== null) {
            throw CommandException::timedOutWithExitCode($exitCode);
        }
    }

    /** 0-255, or null when the process was killed or timed out. */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    /** Seconds, as a float; a command's duration is often well under one. */
    public function duration(): float
    {
        return $this->seconds;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    /** Some of stdout or stderr was dropped at the output limit. */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** It exited, and with 0. */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /** Anything else: a non-zero exit, a signal, a timeout. */
    public function failed(): bool
    {
        return !$this->successful();
    }

    /**
     * This result, if it succeeded; otherwise an exception carrying it.
     *
     *     $checksum = $executor->run($command)->orFail()->stdout();
     *
     * For code where a failed command means there is nothing sensible left to
     * do. Code that has something to say about a failure reads the result
     * instead.
     *
     * @throws CommandTimeoutException when it was stopped at its timeout
     * @throws CommandFailedException  for a non-zero exit or a signal
     */
    public function orFail(): self
    {
        if ($this->successful()) {
            return $this;
        }

        throw CommandFailedException::from($this);
    }
}
