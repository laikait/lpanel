<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * A string that does not leak when something goes wrong.
 *
 * The problem this solves is not storage; it is the moment after. A key held in
 * a plain string is one `var_dump($config)` from a screenshot in a ticket, one
 * `throw new Exception("bad key: $key")` from a log aggregator, one
 * `json_encode($settings)` from a debug endpoint. None of those is a decision
 * anybody made -- they are what a plain string does by default when somebody is
 * debugging at two in the morning.
 *
 *     $key = new Secret($raw);
 *
 *     echo $key;                      // [redacted]
 *     var_dump($key);                 // [redacted]
 *     json_encode(['key' => $key]);   // {"key":"[redacted]"}
 *     $key->reveal();                 // the actual bytes
 *
 * **reveal() is the only way out, and that is the design.** Every place that
 * genuinely needs the value says so in one visible word, so `grep -rn reveal()`
 * is a complete list of where a secret is used. A class that returned the real
 * value from __toString would be a wrapper that changes nothing.
 *
 * __toString returns the mask rather than throwing, deliberately. Throwing
 * would mean that the act of logging a failure becomes a second failure, on a
 * path where something has already gone wrong -- which is the same argument
 * the logging layer makes about writers.
 */
final class Secret implements \JsonSerializable, \Stringable
{
    public const MASK = '[redacted]';

    public function __construct(private readonly string $value) {}

    /** True for a secret that was never configured. */
    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function length(): int
    {
        return \strlen($this->value);
    }

    /**
     * The actual bytes.
     *
     * Named to be conspicuous in a diff and greppable in a codebase. If this
     * appears somewhere surprising, that is the point.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Compare without leaking how much of a guess was right.
     *
     * A normal string comparison returns as soon as two bytes differ, and the
     * time that takes is a measurement of how many leading bytes matched. Over
     * enough requests that is a way to learn a secret one byte at a time.
     */
    public function equals(string $candidate): bool
    {
        return \hash_equals($this->value, $candidate);
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /** What var_dump() and print_r() show. */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK, 'bytes' => $this->length()];
    }

    /**
     * Refuse to be serialised at all.
     *
     * A secret inside a queued job, a cached entry or a session payload is a
     * secret written to somewhere it was never meant to be, usually because it
     * was captured by a closure or held on an object nobody thought about. This
     * turns that into an error at the line that tried.
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            'A Secret cannot be serialised. It was probably captured by a queued job, a cached '
            . 'value or a session; hold the thing that reads it instead, and let it read the '
            . 'secret where the work happens.',
        );
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Secret cannot be unserialised, because it is never serialised.');
    }
}
