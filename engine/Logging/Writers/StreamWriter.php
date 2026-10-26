<?php

declare(strict_types=1);

namespace App\Engine\Logging\Writers;

use App\Engine\Logging\Level;
use App\Engine\Logging\LineFormatter;
use App\Engine\Logging\LoggingException;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;

/**
 * Lines to an already-open stream.
 *
 * The writer a container wants: with php://stderr this is twelve-factor
 * logging, where the process writes to standard error and the platform decides
 * what happens next. It is also what the file writer is built on, so there is
 * one implementation of "append a line and notice when that fails".
 *
 * The stream is not closed here. It was opened by whoever passed it in, and a
 * writer that closed STDERR would take the rest of the process down with it.
 */
final class StreamWriter implements LogWriter
{
    /** @var resource */
    private mixed $stream;

    /** @param resource $stream */
    public function __construct(
        mixed $stream,
        private readonly Level $minimum = Level::Debug,
        private readonly LineFormatter $formatter = new LineFormatter(),
        private readonly string $name = 'stream',
    ) {
        if (!\is_resource($stream)) {
            throw LoggingException::notAStream($this->name);
        }

        $this->stream = $stream;
    }

    public function describe(): string
    {
        return \sprintf('%s (>= %s)', $this->name, $this->minimum->label());
    }

    public function accepts(LogRecord $record): bool
    {
        return $record->level->isAtLeast($this->minimum);
    }

    public function write(LogRecord $record): void
    {
        if (!\is_resource($this->stream)) {
            throw LoggingException::streamClosed($this->name);
        }

        $written = @\fwrite($this->stream, $this->formatter->format($record) . \PHP_EOL);

        if ($written === false) {
            throw LoggingException::writeFailed($this->name);
        }
    }
}
