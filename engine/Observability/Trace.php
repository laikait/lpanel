<?php

declare(strict_types=1);

namespace App\Engine\Observability;

/**
 * One unit of work, identified: a request, a command, a job.
 *
 * Two ids, and the difference is the whole reason there are two. The **id**
 * names this unit of work and nothing else. The **correlation id** names the
 * thing that started the chain: a request that queues an invoice run, whose job
 * sends four emails, gives every one of those units its own id and all of them
 * the request's id as their correlation. Searching the log for a correlation id
 * is how "what happened because of that click" gets answered across a queue
 * boundary, which is exactly where a request id alone stops.
 *
 * The clock and the memory reading are taken when the trace begins, so that
 * elapsed time and memory growth are properties of the unit of work rather than
 * of whoever asks.
 */
final class Trace
{
    /** What an id from outside must look like before it is believed. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/';

    public function __construct(
        public readonly TraceKind $kind,
        public readonly string $id,
        public readonly string $correlationId,
        public readonly ?string $parentId = null,
        /** hrtime(true) when the trace began. */
        public readonly int $startedAt = 0,
        /** memory_get_usage() when the trace began. */
        public readonly int $startedMemory = 0,
    ) {}

    /**
     * Begin one now.
     *
     * A correlation id that is not given, or not usable, becomes this trace's
     * own id: a unit of work nothing started is the start of its own chain.
     */
    public static function begin(
        TraceKind $kind,
        ?string $correlationId = null,
        ?string $id = null,
        ?string $parentId = null,
    ): self {
        $id = $id !== null && self::isValidId($id) ? $id : self::generateId();

        return new self(
            $kind,
            $id,
            $correlationId !== null && self::isValidId($correlationId) ? $correlationId : $id,
            $parentId,
            \hrtime(true),
            \memory_get_usage(),
        );
    }

    /**
     * A new id: 24 hex characters, sortable by when it was made.
     *
     * The time prefix is not decoration. Ids that sort chronologically make a
     * directory of log files and a list of failed jobs read in order without a
     * second index, which is the same reason queued job ids are built this way.
     */
    public static function generateId(): string
    {
        return \sprintf('%08x', \time()) . \bin2hex(\random_bytes(8));
    }

    /**
     * Whether a string is fit to be an id.
     *
     * It is checked because an id from outside ends up in every log line: a
     * header carrying a newline would otherwise let a client write a line of
     * its own into the log, and one carrying a megabyte would write that.
     */
    public static function isValidId(string $id): bool
    {
        return \preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function elapsedNanoseconds(): int
    {
        return $this->startedAt === 0 ? 0 : \hrtime(true) - $this->startedAt;
    }

    public function elapsedMilliseconds(): float
    {
        return $this->elapsedNanoseconds() / 1e6;
    }

    /**
     * How much memory the process holds now beyond what it held when this began.
     *
     * Current rather than peak, because peak is a property of the whole process:
     * a worker's hundredth job would otherwise report the peak of its first.
     */
    public function memoryGrowth(): int
    {
        return \memory_get_usage() - $this->startedMemory;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /**
     * The fields a log record carries.
     *
     * @return array{trace: string, request_id: string, correlation_id: string}
     */
    public function toLogContext(): array
    {
        return [
            'trace' => $this->kind->value,
            'request_id' => $this->id,
            'correlation_id' => $this->correlationId,
        ];
    }
}
