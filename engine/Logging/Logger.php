<?php

declare(strict_types=1);

namespace App\Engine\Logging;

/**
 * A logger bound to one channel.
 *
 * This is what gets injected. A module takes a Logger, calls
 * $log->error('Payment declined', ['order' => $id]), and never learns where the
 * record went -- which is the point, because that is a deployment decision and
 * it changes without the module changing.
 *
 *     final class ChargeCard
 *     {
 *         public function __construct(private readonly Logger $log) {}
 *     }
 *
 * The eight methods are the eight RFC 5424 severities, with the signatures
 * PSR-3 uses. That is deliberate but it is not an implementation of PSR-3:
 * psr/log types its level as a plain string and this framework types it as an
 * enum, and an enum is worth more here than the interface is. An application
 * that needs to hand a PSR-3 logger to a vendor SDK writes a dozen-line adapter
 * that forwards to this -- see docs/reference/logging.md -- rather than this framework
 * flattening its own API to match.
 *
 * There is no {placeholder} interpolation. A message is a message and context
 * is data; splicing one into the other produces a line that is harder to grep
 * and a context that has been said twice.
 */
final class Logger
{
    /** @param array<array-key, mixed> $context added to every record this logger makes */
    public function __construct(
        private readonly LogManager $manager,
        public readonly string $channel = LogManager::DEFAULT_CHANNEL,
        private readonly array $context = [],
    ) {}

    /**
     * A logger for the same channel that adds this context to every record.
     *
     * @param array<array-key, mixed> $context
     */
    public function with(array $context): self
    {
        return new self($this->manager, $this->channel, [...$this->context, ...$context]);
    }

    /** @param array<array-key, mixed> $context */
    public function log(Level $level, string $message, array $context = []): void
    {
        $this->manager->record(new LogRecord(
            $level,
            $message,
            $this->context === [] ? $context : [...$this->context, ...$context],
            $this->channel,
            \microtime(true),
        ));
    }

    /** @param array<array-key, mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    /** @param array<array-key, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }
}
