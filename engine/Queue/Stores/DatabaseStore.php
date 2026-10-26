<?php

declare(strict_types=1);

namespace App\Engine\Queue\Stores;

use App\Engine\Database\Connection;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueException;
use App\Engine\Queue\QueueStore;

/**
 * A queue in a table, which is the queue for more than one machine.
 *
 * The file store's rename() is atomic on one filesystem and a bet on a shared
 * one; a table is shared by design. Every statement goes through the query
 * builder, so the store runs on each database the builder writes for. The
 * table comes from QueueTableMigration: waiting, reserved and failed jobs in
 * one, with failed_at telling them apart.
 *
 * **Reserving is the part that has to be right.** Inside a transaction, the
 * next due job is read with lockForUpdate(), then claimed by an UPDATE whose
 * WHERE repeats "not reserved, or its reservation has lapsed". The lock makes
 * a second worker wait for the first; the repeated condition makes the claim
 * safe even where a database's lock would let both through, because only one
 * UPDATE can change the row from unreserved to reserved. A worker whose claim
 * changed nothing gets null and asks again.
 *
 * A reservation expires, as the interface requires: reserve() treats a lapsed
 * one as available, so a killed worker's job comes back with its attempts
 * intact.
 *
 * The connection is looked up on first use, so a request that queues nothing
 * never opens one.
 */
final class DatabaseStore implements QueueStore
{
    public const DEFAULT_TABLE = 'jobs';

    private ?Connection $connection = null;

    /** @param \Closure(): Connection $connect */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {}

    public function describe(): string
    {
        return \sprintf('database %s.%s', $this->connection()->name(), $this->table);
    }

    public function push(QueuedJob $job): bool
    {
        $this->run(fn() => $this->jobs()->insert($this->row($job)));

        return true;
    }

    public function reserve(string $queue, int $seconds): ?QueuedJob
    {
        $now = \time();

        return $this->run(fn(): ?QueuedJob => $this->connection()->transaction(function () use ($queue, $seconds, $now): ?QueuedJob {
            $row = $this->available($this->waiting()->where('queue', $queue)->where('available_at', '<=', $now), $now)
                ->orderBy('available_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $job = $row === null ? null : $this->job($row);

            if ($job === null) {
                return null;
            }

            $held = $job->reservedFor($seconds, $now);
            $claimed = $this->available($this->waiting()->where('id', $job->id), $now)->update([
                'attempts' => $held->attempts,
                'reserved_until' => $held->reservedUntil,
            ]);

            return $claimed === 1 ? $held : null;
        }));
    }

    public function acknowledge(QueuedJob $job): bool
    {
        $this->run(fn() => $this->waiting()->where('id', $job->id)->delete());

        return true;
    }

    /** False when the job is no longer on the queue: it was acknowledged, failed or cleared meanwhile. */
    public function release(QueuedJob $job): bool
    {
        return $this->run(fn(): int => $this->waiting()->where('id', $job->id)->update([
            'attempts' => $job->attempts,
            'available_at' => $job->availableAt,
            'reserved_until' => $job->reservedUntil,
        ])) > 0;
    }

    public function fail(QueuedJob $job): bool
    {
        $failed = [
            'attempts' => $job->attempts,
            'reserved_until' => null,
            'error' => $job->error,
            'failed_at' => \time(),
        ];

        $this->run(function () use ($job, $failed): void {
            $this->connection()->transaction(function () use ($job, $failed): void {
                // A job failed straight from a push, never queued, is kept too.
                if ($this->jobs()->where('id', $job->id)->update($failed) === 0) {
                    $this->jobs()->insert([...$this->row($job), ...$failed]);
                }
            });
        });

        return true;
    }

    public function failed(): array
    {
        $rows = $this->run(fn(): array => $this->jobs()->whereNotNull('failed_at')->orderBy('failed_at')->orderBy('id')->get());
        $jobs = [];

        foreach ($rows as $row) {
            $job = $this->job($row);

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function forget(string $id): bool
    {
        $this->run(fn() => $this->jobs()->where('id', $id)->whereNotNull('failed_at')->delete());

        return true;
    }

    public function size(string $queue): int
    {
        return $this->run(fn(): int => $this->waiting()->where('queue', $queue)->count());
    }

    public function queues(): array
    {
        $rows = $this->run(fn(): array => $this->waiting()->select('queue')->groupBy('queue')->orderBy('queue')->get());

        return \array_values(\array_map(static fn(array $row): string => (string) $row['queue'], $rows));
    }

    public function clear(?string $queue = null): bool
    {
        $this->run(fn() => ($queue === null ? $this->waiting() : $this->waiting()->where('queue', $queue))->delete());

        return true;
    }

    private function connection(): Connection
    {
        return $this->connection ??= ($this->connect)();
    }

    private function jobs(): QueryBuilder
    {
        return $this->connection()->table($this->table);
    }

    /** Jobs on a queue, reserved or not: everything that has not failed. */
    private function waiting(): QueryBuilder
    {
        return $this->jobs()->whereNull('failed_at');
    }

    /** Narrowed to jobs nobody holds: never reserved, or reserved and lapsed. */
    private function available(QueryBuilder $query, int $now): QueryBuilder
    {
        return $query->where(
            static fn(QueryBuilder $unheld): QueryBuilder => $unheld->whereNull('reserved_until')->orWhere('reserved_until', '<=', $now),
        );
    }

    /**
     * A missing table named in the queue's own words; anything else is the
     * database, and rethrown as it is.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function run(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (DatabaseException $e) {
            if ($e->meansMissingTable()) {
                throw QueueException::missingTable($this->table, $this->connection()->name());
            }

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function row(QueuedJob $job): array
    {
        return [
            'id' => $job->id,
            'queue' => $job->queue,
            'class' => $job->class,
            // serialize() output is bytes, not text.
            'payload' => \base64_encode($job->payload),
            'attempts' => $job->attempts,
            'available_at' => $job->availableAt,
            'reserved_until' => $job->reservedUntil,
            'created_at' => $job->createdAt,
            'error' => $job->error,
            'correlation_id' => $job->correlationId,
        ];
    }

    /**
     * Back from a row, or null for one this cannot read. Integers are cast:
     * some drivers hand a BIGINT back as a string.
     *
     * @param array<string, mixed> $row
     */
    private function job(array $row): ?QueuedJob
    {
        $payload = \is_string($row['payload'] ?? null) ? \base64_decode($row['payload'], true) : false;

        if ($payload === false) {
            return null;
        }

        $integer = static fn(mixed $value): ?int => \is_numeric($value) ? (int) $value : null;

        return QueuedJob::fromArray([
            'id' => $row['id'] ?? null,
            'queue' => $row['queue'] ?? null,
            'class' => $row['class'] ?? null,
            'payload' => $payload,
            'attempts' => $integer($row['attempts'] ?? null) ?? 0,
            'availableAt' => $integer($row['available_at'] ?? null) ?? 0,
            'reservedUntil' => $integer($row['reserved_until'] ?? null),
            'createdAt' => $integer($row['created_at'] ?? null) ?? 0,
            'error' => $row['error'] ?? null,
            'correlationId' => $row['correlation_id'] ?? null,
        ]);
    }
}
