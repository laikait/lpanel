<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\Error\FrameworkException;

/**
 * A writer could not write.
 *
 * Almost always caught rather than propagated: the manager catches these,
 * retires the writer that threw and records the reason, because the
 * specification's rule is that logging must not make a working request fail.
 * They exist as exceptions anyway, rather than as booleans, so that a writer
 * can say what went wrong and "log:status" can repeat it.
 */
final class LoggingException extends FrameworkException
{
    public static function notAStream(string $name): self
    {
        return new self(\sprintf('The "%s" writer was given something that is not an open stream.', $name));
    }

    public static function streamClosed(string $name): self
    {
        return new self(\sprintf('The stream behind the "%s" writer has been closed.', $name));
    }

    public static function writeFailed(string $target): self
    {
        return new self(\sprintf('Writing to %s failed. The disk may be full or the file unwritable.', $target));
    }

    public static function directoryUnavailable(string $directory): self
    {
        return new self(\sprintf('The log directory %s does not exist and could not be created.', $directory));
    }

    public static function cannotOpen(string $path): self
    {
        return new self(\sprintf('The log file %s could not be opened for appending.', $path));
    }

    public static function missingTable(string $table, string $connection): self
    {
        return new self(\sprintf(
            'The log table "%s" does not exist on the "%s" connection. Run: php laika migrate --connection=%s',
            $table,
            $connection,
            $connection,
        ));
    }

    public static function unusableChannel(string $name): self
    {
        return new self(\sprintf(
            'Channel name "%s" is invalid. A channel is a short lowercase name, such as "app" or "billing".',
            $name,
        ));
    }
}
