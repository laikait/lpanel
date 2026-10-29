<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Logging;

use App\Engine\Logging\LoggingException;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;

/** A writer standing in for a full disk: it fails, and it keeps failing. */
final class BrokenWriter implements LogWriter
{
    public int $attempts = 0;

    public function __construct(private readonly string $name = 'broken') {}

    public function describe(): string
    {
        return $this->name;
    }

    public function accepts(LogRecord $record): bool
    {
        return true;
    }

    public function write(LogRecord $record): void
    {
        ++$this->attempts;

        throw LoggingException::writeFailed($this->name);
    }
}
