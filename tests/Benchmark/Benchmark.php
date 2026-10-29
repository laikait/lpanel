<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

/**
 * One measured operation.
 *
 * $prepare runs once, untimed, and returns the closure that is timed. That
 * split is the whole discipline: building a 500-route table is not part of
 * matching a route, and a benchmark that times both reports the wrong thing
 * precisely and repeatably.
 *
 * The subject is one of the specification's own list (see Suite::SUBJECTS), so
 * that "is every one of them measured" is a question a test can answer.
 */
final class Benchmark
{
    /**
     * @param \Closure(): (\Closure(): mixed) $prepare
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $name,
        public readonly \Closure $prepare,
    ) {}

    /** A stable key for saved results: renaming a benchmark starts a new history. */
    public function key(): string
    {
        return $this->subject . ' / ' . $this->name;
    }
}
