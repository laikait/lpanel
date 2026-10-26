<?php

declare(strict_types=1);

namespace App\Engine\Queue;

use App\Engine\Support\Sealer;

/**
 * A job on a queue: the work, plus everything the queue knows about it.
 *
 * The envelope is separate from the job because the two have different owners.
 * A job knows about invoices; this knows about attempts, reservations and when
 * it may next be tried, none of which is the application's business and all of
 * which a worker needs.
 *
 * It carries the class name next to the payload so that queue:status and
 * queue:failed can say what a job is without unserialising it. Reading a failed
 * job's payload means instantiating a class from a store, and a listing command
 * should not have to do that to print a line.
 *
 * Every change hands back a new envelope. A queue is a place where two
 * processes look at the same work, and a value that cannot be edited in place
 * is one fewer way for them to disagree.
 */
final class QueuedJob
{
    // A readonly class would say this once. The properties carry it
    // individually instead, because composer.json declares PHP 8.1 and
    // readonly classes arrived in 8.2; readonly properties did not.
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        /** The job's class, for display. The payload is the truth. */
        public readonly string $class,
        /** The serialised job. */
        public readonly string $payload,
        /** How many times a worker has taken this on, including the attempt in progress. */
        public readonly int $attempts = 0,
        /** Unix time from which this may be reserved. */
        public readonly int $availableAt = 0,
        /** Unix time at which a reservation lapses, or null when it is not reserved. */
        public readonly ?int $reservedUntil = null,
        public readonly int $createdAt = 0,
        /** Why it failed, once it has. */
        public readonly ?string $error = null,
        /**
         * The correlation id of the work that queued this, so the job's log lines
         * can be found from the request that caused them. Null for a job queued
         * by an older version of this framework, which simply starts a chain of
         * its own.
         */
        public readonly ?string $correlationId = null,
    ) {}

    /**
     * The job itself.
     *
     * The payload is checked by $sealer before anything is unserialized:
     * unserialize() on bytes somebody else wrote runs their objects' code.
     *
     * @throws QueueException when the payload no longer describes a usable job --
     *                        a class that has been renamed or deleted since it was queued --
     *                        or was not sealed by this application
     */
    public function job(Sealer $sealer = new Sealer()): Job
    {
        $bytes = $sealer->open($this->payload) ?? throw QueueException::unsealedPayload($this->class)->withheld();

        try {
            /** @var mixed $job */
            $job = @\unserialize($bytes);
        } catch (\Throwable $e) {
            throw QueueException::unreadablePayload($this->class, $e)->withheld();
        }

        return $job instanceof Job
            ? $job
            : throw QueueException::unreadablePayload($this->class, null);
    }

    public function isAvailable(?int $now = null): bool
    {
        $now ??= \time();

        return $this->availableAt <= $now && ($this->reservedUntil === null || $this->reservedUntil <= $now);
    }

    public function isReserved(?int $now = null): bool
    {
        return $this->reservedUntil !== null && $this->reservedUntil > ($now ?? \time());
    }

    /** Taken on by a worker: one more attempt, and reserved for as long as it is allowed to run. */
    public function reservedFor(int $seconds, ?int $now = null): self
    {
        return $this->with(
            attempts: $this->attempts + 1,
            reservedUntil: ($now ?? \time()) + $seconds,
        );
    }

    /** Put back, to be tried again after a delay. */
    public function releasedAfter(int $delay, ?int $now = null): self
    {
        return $this->with(
            availableAt: ($now ?? \time()) + \max(0, $delay),
            reservedUntil: null,
        );
    }

    public function failedWith(\Throwable $e): self
    {
        return $this->with(
            reservedUntil: null,
            error: \sprintf('%s: %s', $e::class, $e->getMessage()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'class' => $this->class,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'availableAt' => $this->availableAt,
            'reservedUntil' => $this->reservedUntil,
            'createdAt' => $this->createdAt,
            'error' => $this->error,
            'correlationId' => $this->correlationId,
        ];
    }

    /**
     * Rebuild one, or null when the array is not one of ours.
     *
     * Null rather than an exception: this reads whatever is in a store, and a
     * file written by an older version of this framework should be skipped
     * rather than stop a worker from draining the rest of the queue.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['id', 'queue', 'class', 'payload'] as $required) {
            if (!isset($data[$required]) || !\is_string($data[$required])) {
                return null;
            }
        }

        /** @var array{id: string, queue: string, class: string, payload: string} $data */
        return new self(
            id: $data['id'],
            queue: $data['queue'],
            class: $data['class'],
            payload: $data['payload'],
            attempts: self::integer($data['attempts'] ?? 0) ?? 0,
            availableAt: self::integer($data['availableAt'] ?? 0) ?? 0,
            reservedUntil: self::integer($data['reservedUntil'] ?? null),
            createdAt: self::integer($data['createdAt'] ?? 0) ?? 0,
            error: isset($data['error']) && \is_string($data['error']) ? $data['error'] : null,
            correlationId: isset($data['correlationId']) && \is_string($data['correlationId']) ? $data['correlationId'] : null,
        );
    }

    private static function integer(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private function with(
        ?int $attempts = null,
        ?int $availableAt = null,
        ?int $reservedUntil = null,
        ?string $error = null,
    ): self {
        return new self(
            id: $this->id,
            queue: $this->queue,
            class: $this->class,
            payload: $this->payload,
            attempts: $attempts ?? $this->attempts,
            availableAt: $availableAt ?? $this->availableAt,
            // Explicitly nullable: releasing and failing both clear a
            // reservation, so "null" here has to mean null rather than "keep".
            reservedUntil: $reservedUntil,
            createdAt: $this->createdAt,
            error: $error ?? $this->error,
            correlationId: $this->correlationId,
        );
    }
}
