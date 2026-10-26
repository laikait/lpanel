<?php

declare(strict_types=1);

namespace App\Engine\Logging\Writers;

use App\Engine\Logging\Level;
use App\Engine\Logging\LineFormatter;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;

/**
 * Records to the system logger.
 *
 * The one destination that already exists on the machine: journald, rsyslog or
 * the Windows event log, with rotation, retention and shipping already solved
 * by somebody whose job that is. For an application that has an operations team
 * this is usually the right answer and the file writer is the fallback.
 *
 * The level goes across as a syslog priority rather than as text, so "show me
 * everything at error or worse" is a question the system logger can answer
 * without parsing messages.
 */
final class SyslogWriter implements LogWriter
{
    private bool $opened = false;

    public function __construct(
        private readonly string $identity = 'app',
        private readonly Level $minimum = Level::Warning,
        private readonly int $facility = \LOG_USER,
        private readonly LineFormatter $formatter = new LineFormatter(includePrefix: false),
    ) {}

    public function describe(): string
    {
        return \sprintf('syslog as "%s" (>= %s)', $this->identity, $this->minimum->label());
    }

    public function accepts(LogRecord $record): bool
    {
        return $record->level->isAtLeast($this->minimum);
    }

    /**
     * Neither call is error-checked, and that is not an oversight.
     *
     * openlog() and syslog() return true unconditionally in PHP; a failure
     * arrives as a warning instead. Suppressing the warning with @ and testing
     * the return value would look careful and check nothing. Letting the
     * warning through means the framework's own error handler turns it into an
     * ErrorException, which the manager catches, and this writer is retired
     * with the reason recorded -- the same path every other writer's failure
     * takes.
     */
    public function write(LogRecord $record): void
    {
        if (!$this->opened) {
            \openlog($this->identity, \LOG_PID | \LOG_ODELAY, $this->facility);

            $this->opened = true;
        }

        // priority(), not value(): on Windows PHP's LOG_* constants are not the
        // RFC 5424 codes, and the backing value would land in the wrong bucket.
        //
        // The timestamp and the level are the daemon's job; sending them again
        // would put them in the line twice.
        \syslog($record->level->priority(), $this->formatter->format($record));
    }
}
