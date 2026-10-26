<?php

declare(strict_types=1);

namespace App\Engine\System\Process;

use App\Engine\System\SystemException;

/**
 * Something was asked of a started process that cannot be done.
 *
 * Starting one fails with CommandException, exactly as running one does: the
 * command is prepared the same way, and nothing exists yet to be a process.
 */
final class ProcessException extends SystemException
{
    public static function notRunning(int $pid, string $action): self
    {
        return new self(\sprintf('Process %d has already finished, so it cannot be %s.', $pid, $action));
    }

    public static function signalsUnsupported(): self
    {
        return new self(
            'Signals are a POSIX feature, and PHP on Windows cannot send one. terminate() works on '
            . 'every platform.',
        );
    }

    public static function invalidSignal(int $signal): self
    {
        return new self(\sprintf('%d is not a signal number. Signals are 1 to %d.', $signal, Process::MAX_SIGNAL));
    }

    public static function invalidGrace(float $seconds): self
    {
        return new self(\sprintf(
            'A grace period of %s seconds is not usable. It is a finite number of seconds, zero or more.',
            \var_export($seconds, true),
        ));
    }
}
