<?php

declare(strict_types=1);

namespace App\Engine\Logging;

/**
 * One thing worth writing down.
 *
 * A value, passed to every writer. Writers receive this rather than a formatted
 * string because the formatting is the writer's business: a file wants a line, a
 * syslog daemon wants a message and a severity, and something shipping to a log
 * aggregator wants the fields intact. Formatting once, up front, would make the
 * last of those impossible.
 *
 * The context is already normalised by the time a record exists -- see Context
 * -- so a writer can encode it without checking whether it will explode.
 */
final class LogRecord
{
    /** @param array<array-key, mixed> $context */
    public function __construct(
        public readonly Level $level,
        public readonly string $message,
        public readonly array $context = [],
        public readonly string $channel = LogManager::DEFAULT_CHANNEL,
        public readonly float $time = 0.0,
    ) {}

    public function at(): \DateTimeImmutable
    {
        $time = $this->time > 0.0 ? $this->time : \microtime(true);

        return \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $time))
            ?: new \DateTimeImmutable();
    }

    /** @param array<array-key, mixed> $context merged over what is already there */
    public function with(array $context): self
    {
        return new self(
            $this->level,
            $this->message,
            [...$this->context, ...$context],
            $this->channel,
            $this->time,
        );
    }

    public function inChannel(string $channel): self
    {
        return new self($this->level, $this->message, $this->context, $channel, $this->time);
    }
}
