<?php

declare(strict_types=1);

namespace App\Engine\Observability;

use App\Engine\Cli\Command;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Logging\LogManager;
use App\Engine\Queue\QueuedJob;

/**
 * Where what was observed goes: two response headers and the log. Nowhere else.
 *
 * The specification says not to build a debug dashboard first, and this is
 * what taking that seriously looks like. Everything measured leaves through a
 * channel that already exists and that every deployment already reads -- the
 * log a deployment already ships somewhere, and headers that a load balancer
 * already records and a browser's developer tools already draw as a waterfall
 * (Server-Timing). There is no storage, no page and no endpoint, and an
 * architecture test holds this directory to that: nothing here opens a file or
 * prints.
 *
 * All of it is attached by the bootstrap as ordinary listeners.
 */
final class Report
{
    public const CHANNEL = 'profile';

    public const SLOW_QUERY_CHANNEL = 'database';

    public function __construct(
        private readonly Tracer $tracer,
        private readonly Profiler $profiler,
        private readonly LogManager $logs,
        private readonly bool $debug = false,
    ) {}

    /**
     * The last response.instance listener: identify the response, and report.
     *
     * X-Request-Id always -- it is what a user quotes to support and what joins
     * a load balancer's access log to this application's. X-Correlation-Id only
     * when it differs, which for a request it only does when a trusted caller
     * sent one.
     *
     * Server-Timing only when profiling AND debug are on. It is an honest
     * description of where a request spent its time, which is precisely what
     * should not be handed to every client of a production system.
     */
    public function onResponse(Response $response, ?Request $request = null): Response
    {
        $trace = $this->tracer->current();

        $response = $response->withHeader(Tracer::REQUEST_ID_HEADER, $trace->id);

        if ($trace->correlationId !== $trace->id) {
            $response = $response->withHeader(Tracer::CORRELATION_ID_HEADER, $trace->correlationId);
        }

        if (!$this->profiler->isEnabled()) {
            return $response;
        }

        $summary = $this->profiler->summary();

        $this->write(
            \sprintf(
                '%s %s %d in %.2f ms',
                $request?->method() ?? 'HTTP',
                $request?->path() ?? '',
                $response->status(),
                $trace->elapsedMilliseconds(),
            ),
            $trace,
            $summary,
        );

        return $this->debug
            ? $response->withHeader('Server-Timing', self::serverTiming($trace, $summary))
            : $response;
    }

    /** command.finished: one record for the command, when profiling. */
    public function onCommand(int $status, Command $command): void
    {
        if (!$this->profiler->isEnabled()) {
            return;
        }

        $trace = $this->tracer->current();

        $this->write(
            \sprintf('console %s exited %d in %.2f ms', $command->name, $status, $trace->elapsedMilliseconds()),
            $trace,
            $this->profiler->summary(),
        );
    }

    /**
     * job.finished: one record for the job, when profiling.
     *
     * A job that throws gets no profile record. Its failure is logged as an
     * error, under its own correlation, and the numbers of a run that did not
     * finish describe the part that happened to run.
     */
    public function onJob(QueuedJob $queued): void
    {
        if (!$this->profiler->isEnabled()) {
            return;
        }

        $trace = $this->tracer->current();

        $this->write(
            \sprintf('job %s finished in %.2f ms', $queued->class, $trace->elapsedMilliseconds()),
            $trace,
            [...$this->profiler->summary(), 'job' => $queued->id, 'attempt' => $queued->attempts],
        );
    }

    /**
     * Connect the one seam every connection shares, if anything wants it.
     *
     * Two things can: the profiler, and the slow-query warning. The warning runs
     * with profiling off -- it is the one piece of timing worth paying for on
     * every statement in production, because a query that took four seconds is
     * a fact nobody should have to reproduce to learn about -- so it cannot live
     * inside instrument(), and one seam holds one observer.
     *
     * With neither wanted, nothing is attached and a statement costs what it
     * did before observability existed.
     */
    public function watchQueries(ConnectionManager $connections, int $slowMilliseconds): void
    {
        $profiling = $this->profiler->isEnabled();
        $threshold = \max(0, $slowMilliseconds) * 1_000_000;

        if (!$profiling && $threshold === 0) {
            return;
        }

        $connections->observe(function (string $sql, int $nanoseconds, string $connection, int $bindings, ?int $rows) use ($profiling, $threshold): void {
            if ($profiling) {
                $this->profiler->recordQuery($sql, $nanoseconds, $connection);
            }

            if ($threshold > 0 && $nanoseconds >= $threshold) {
                $this->logs->channel(self::SLOW_QUERY_CHANNEL)->warning(
                    \sprintf('Slow query on %s: %.1f ms', $connection, $nanoseconds / 1e6),
                    // The statement and how much it carried, never the values:
                    // see Connection::observe(). A slow statement with ten
                    // thousand values bound is a different problem from one
                    // with two, or one that read ten thousand rows.
                    [
                        'sql' => (string) \preg_replace('/\s+/', ' ', \trim($sql)),
                        'ms' => \round($nanoseconds / 1e6, 3),
                        'bindings' => $bindings,
                        'rows' => $rows,
                    ],
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function write(string $message, Trace $trace, array $summary): void
    {
        $this->logs->channel(self::CHANNEL)->info($message, [
            'elapsed_ms' => \round($trace->elapsedMilliseconds(), 3),
            'memory_growth_kb' => \round($trace->memoryGrowth() / 1024, 1),
            'memory_peak_mb' => \round(\memory_get_peak_usage(true) / 1048576, 1),
            ...$summary,
        ]);
    }

    /**
     * The W3C Server-Timing header: the whole request, then each category.
     *
     * Category names are already restricted to characters a header token may
     * hold (see Profiler::CATEGORY_PATTERN), so nothing here needs escaping; the
     * description is only ever a number.
     *
     * @param array{categories: array<string, array{count: int, ms: float}>, slowest: mixed} $summary
     */
    private static function serverTiming(Trace $trace, array $summary): string
    {
        $parts = [\sprintf('app;dur=%.2f', $trace->elapsedMilliseconds())];

        foreach ($summary['categories'] as $category => $totals) {
            $parts[] = \sprintf('%s;dur=%.2f;desc="%d"', $category, $totals['ms'], $totals['count']);
        }

        return \implode(', ', $parts);
    }
}
