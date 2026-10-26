<?php

declare(strict_types=1);

namespace App\Engine\Logging;

/**
 * How serious a log record is.
 *
 * The eight RFC 5424 severities, which are also the eight PSR-3 levels and the
 * eight every log aggregator already understands. The backing value is the
 * RFC 5424 code, so comparing severity is comparing integers and a record
 * written to a file carries the number every parser expects.
 *
 * It is *not* automatically what PHP's syslog() wants -- see priority().
 *
 * Note the direction: a *lower* number is *more* severe. That is syslog's
 * convention and inverting it here would mean disagreeing with every tool that
 * reads the output, so isAtLeast() exists to keep call sites from having to
 * remember which way the comparison goes.
 *
 * The specification's list also contains "log", which is not a severity -- it
 * is the name of the generic method that takes one, and Logger::log() is it.
 * Adding a ninth level with no place in the ordering would make "is this record
 * severe enough to write" unanswerable.
 */
enum Level: int
{
    case Emergency = 0;

    case Alert = 1;

    case Critical = 2;

    case Error = 3;

    case Warning = 4;

    case Notice = 5;

    case Info = 6;

    case Debug = 7;

    /** The lowercase name used in configuration and in a log line. */
    public function label(): string
    {
        return \strtolower($this->name);
    }

    /**
     * Parse a configured level, falling back rather than throwing.
     *
     * A typo in a configured level must not stop an application from starting.
     * The fallback is the caller's default, which is always at least as
     * talkative as the mistake would have been.
     */
    public static function fromName(mixed $name, self $default = self::Debug): self
    {
        if ($name instanceof self) {
            return $name;
        }

        if (!\is_string($name)) {
            return $default;
        }

        foreach (self::cases() as $case) {
            if ($case->label() === \strtolower(\trim($name))) {
                return $case;
            }
        }

        return $default;
    }

    /** Whether this record is severe enough for a threshold of $minimum. */
    public function isAtLeast(self $minimum): bool
    {
        return $this->value <= $minimum->value;
    }

    public function isMoreSevereThan(self $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Whether this level describes something that went wrong.
     *
     * The line is drawn at error rather than at warning because a warning is
     * something worth reading later and an error is something worth being woken
     * up for, and every alerting rule anybody writes starts from that split.
     */
    public function isFailure(): bool
    {
        return $this->value <= self::Error->value;
    }

    /**
     * This level as the priority PHP's syslog() expects.
     *
     * Not the same thing as the backing value, and finding that out the hard
     * way is what this method exists to prevent. On a Unix host PHP's LOG_*
     * constants are the RFC 5424 codes and the two are identical. On Windows
     * they are not: there is no syslog, so PHP maps them onto the event log's
     * three record types and LOG_EMERG is 1, LOG_ERR is 4 and everything from
     * notice down is 6. Passing the backing value there would file records
     * under the wrong severity on a platform where nothing would look broken.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Emergency => \LOG_EMERG,
            self::Alert => \LOG_ALERT,
            self::Critical => \LOG_CRIT,
            self::Error => \LOG_ERR,
            self::Warning => \LOG_WARNING,
            self::Notice => \LOG_NOTICE,
            self::Info => \LOG_INFO,
            self::Debug => \LOG_DEBUG,
        };
    }

    /** @return list<string> every level name, most severe first */
    public static function names(): array
    {
        return \array_map(static fn(self $case): string => $case->label(), self::cases());
    }
}
