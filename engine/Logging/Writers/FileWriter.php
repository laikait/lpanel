<?php

declare(strict_types=1);

namespace App\Engine\Logging\Writers;

use App\Engine\Logging\Level;
use App\Engine\Logging\LineFormatter;
use App\Engine\Logging\LoggingException;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;
use App\Engine\Support\Path;

/**
 * Lines to a file under system/Logs, one file per day.
 *
 * Daily files rather than one growing file, because the two questions anybody
 * asks of a log are "what happened just now" and "what happened on the day the
 * invoices went wrong", and a date in the filename answers the second one
 * without reading the first. Rotation by size answers neither: the boundary
 * lands in the middle of an afternoon and the file numbering tells you nothing
 * about when.
 *
 * Retention is a count of days and it deletes. That is the one destructive
 * thing in this framework, so it is bounded hard: only files in this directory,
 * only ones matching this writer's own naming, and only when a positive number
 * of days is configured. Zero means keep everything, which is the default,
 * because deleting an audit trail because a default said so is a worse failure
 * than a large directory.
 *
 * The handle stays open for the life of the process. A request that logs forty
 * times should open one file, not forty.
 */
final class FileWriter implements LogWriter
{
    public const EXTENSION = '.log';

    /** @var resource|null */
    private mixed $handle = null;

    private string $openPath = '';

    public function __construct(
        private readonly string $directory,
        private readonly Level $minimum = Level::Debug,
        private readonly string $prefix = 'app',
        private readonly int $retentionDays = 0,
        private readonly LineFormatter $formatter = new LineFormatter(),
    ) {}

    public function describe(): string
    {
        return \sprintf('file %s-<date>%s (>= %s)', $this->prefix, self::EXTENSION, $this->minimum->label());
    }

    public function accepts(LogRecord $record): bool
    {
        return $record->level->isAtLeast($this->minimum);
    }

    public function write(LogRecord $record): void
    {
        $handle = $this->handleFor($this->pathFor($record));

        $written = @\fwrite($handle, $this->formatter->format($record) . \PHP_EOL);

        if ($written === false) {
            throw LoggingException::writeFailed($this->openPath);
        }
    }

    public function pathFor(LogRecord $record): string
    {
        return Path::join(
            $this->directory,
            $this->prefix . '-' . $record->at()->format('Y-m-d') . self::EXTENSION,
        );
    }

    /** @return resource */
    private function handleFor(string $path): mixed
    {
        if ($path === $this->openPath && \is_resource($this->handle)) {
            return $this->handle;
        }

        if (\is_resource($this->handle)) {
            \fclose($this->handle);
        }

        if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0o775, true) && !\is_dir($this->directory)) {
            throw LoggingException::directoryUnavailable($this->directory);
        }

        $handle = @\fopen($path, 'a');

        if ($handle === false) {
            throw LoggingException::cannotOpen($path);
        }

        $this->handle = $handle;
        $this->openPath = $path;

        $this->prune();

        return $handle;
    }

    /**
     * Delete files older than the retention window.
     *
     * Run when a new day's file is opened rather than on every write, which is
     * once a day in a long-lived process and once a request in a short-lived
     * one -- cheap either way, and it means a machine that is never restarted
     * still cleans up.
     */
    private function prune(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', $this->retentionDays));
        $pattern = '/^' . \preg_quote($this->prefix, '/') . '-(\d{4}-\d{2}-\d{2})' . \preg_quote(self::EXTENSION, '/') . '$/';

        $entries = @\scandir($this->directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (\preg_match($pattern, $entry, $matches) !== 1) {
                continue;
            }

            $day = \DateTimeImmutable::createFromFormat('Y-m-d|', $matches[1]);

            if ($day !== false && $day < $cutoff) {
                @\unlink(Path::join($this->directory, $entry));
            }
        }
    }
}
