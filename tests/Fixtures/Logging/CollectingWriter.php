<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Logging;

use App\Engine\Logging\Level;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;

/** A writer that keeps what it was given, so a test can look at it. */
final class CollectingWriter implements LogWriter
{
    /** @var list<LogRecord> */
    public array $records = [];

    public function __construct(
        private readonly Level $minimum = Level::Debug,
        private readonly string $name = 'collecting',
    ) {}

    public function describe(): string
    {
        return $this->name;
    }

    public function accepts(LogRecord $record): bool
    {
        return $record->level->isAtLeast($this->minimum);
    }

    public function write(LogRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return \array_map(static fn(LogRecord $record): string => $record->message, $this->records);
    }

    public function last(): ?LogRecord
    {
        return $this->records === [] ? null : $this->records[\count($this->records) - 1];
    }
}
