<?php

declare(strict_types=1);

namespace App\Engine\Observability;

use App\Engine\Http\Request;

/**
 * Which unit of work is running, right now.
 *
 * Always on, because what it does costs a random id and a clock reading per
 * request, and because the one time an id is needed is the time nobody thought
 * to switch it on. Everything that runs work opens a trace around it -- the
 * application around a request and around a command, the job runner around a
 * job -- and everything that wants to say which work it belongs to asks here:
 * the log for every record, the queue when it stamps a job's correlation, the
 * response for its X-Request-Id header.
 *
 * Traces nest. A job run synchronously inside a request is a job trace inside
 * the request's, with its own id and the request's correlation; when it ends,
 * the request is current again. A trace that was never ended -- a test that
 * handled a request and threw the application away half-way -- is closed by the
 * next root beginning, so a long-lived process cannot accumulate them.
 */
final class Tracer
{
    public const REQUEST_ID_HEADER = 'X-Request-Id';

    public const CORRELATION_ID_HEADER = 'X-Correlation-Id';

    /** @var list<Trace> */
    private array $stack = [];

    private ?Trace $process = null;

    /** @var list<\Closure(string, Trace): void> */
    private array $observers = [];

    /**
     * @param bool $trustIncomingIds whether a request may name its own ids; see beginRequest()
     */
    public function __construct(private readonly bool $trustIncomingIds = false) {}

    /**
     * Be told when a trace begins or ends.
     *
     * The profiler's way in: it opens a scope when a trace begins and closes it
     * when the trace ends, without the tracer knowing a profiler exists.
     *
     * @param \Closure(string, Trace): void $observer called with "begin" or "end"
     */
    public function observe(\Closure $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * The unit of work running now.
     *
     * Never null. Outside any request, command or job there is still a process,
     * and a log line written while bootstrapping still deserves an id.
     */
    public function current(): Trace
    {
        return $this->stack[\count($this->stack) - 1] ?? ($this->process ??= Trace::begin(TraceKind::Process));
    }

    /** @return list<Trace> outermost first */
    public function stack(): array
    {
        return $this->stack;
    }

    /**
     * Begin a unit of work inside the current one.
     *
     * The correlation is inherited unless one is given, which is the whole
     * mechanism: nothing has to pass an id along by hand for work started inside
     * a request to be correlated with it.
     */
    public function begin(TraceKind $kind, ?string $correlationId = null): Trace
    {
        $parent = $this->stack === [] ? null : $this->current();

        return $this->push(Trace::begin(
            $kind,
            $correlationId ?? $parent?->correlationId,
            null,
            $parent?->id,
        ));
    }

    /**
     * Begin a request, which is always a root.
     *
     * A request is where a chain starts, so whatever was left open is closed
     * first -- see the class docblock.
     *
     * **Ids from outside are refused unless the deployment says to trust them.**
     * A load balancer or an API gateway that stamps X-Request-Id wants the same
     * id in this application's log, and observability.trust_incoming_ids is how
     * it gets it. Without that setting a client could choose the id its own
     * requests are logged under -- harmless most of the time, and exactly what
     * somebody hiding in a log would want. A trusted id is still pattern-checked
     * before it is used, because it ends up in every log line.
     */
    public function beginRequest(Request $request): Trace
    {
        $this->endAll();

        $id = null;
        $correlation = null;

        if ($this->trustIncomingIds) {
            $id = $request->header(self::REQUEST_ID_HEADER);
            $correlation = $request->header(self::CORRELATION_ID_HEADER);
        }

        return $this->push(Trace::begin(TraceKind::Http, $correlation, $id));
    }

    /**
     * Run $work inside a trace, and end the trace however $work finishes.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function within(Trace $trace, \Closure $work): mixed
    {
        if (!\in_array($trace, $this->stack, true)) {
            $this->push($trace);
        }

        try {
            return $work();
        } finally {
            $this->end($trace);
        }
    }

    /**
     * End a trace, and anything still open inside it.
     *
     * Ending an inner trace nobody closed along with its parent is the only
     * answer that leaves the stack meaning something. Ending a trace that is not
     * open does nothing.
     */
    public function end(Trace $trace): void
    {
        $position = \array_search($trace, $this->stack, true);

        if ($position === false) {
            return;
        }

        while (\count($this->stack) > $position) {
            $ended = \array_pop($this->stack);
            $this->notify('end', $ended);
        }
    }

    /**
     * The fields every log record is given.
     *
     * @return array{trace: string, request_id: string, correlation_id: string}
     */
    public function logContext(): array
    {
        return $this->current()->toLogContext();
    }

    private function push(Trace $trace): Trace
    {
        $this->stack[] = $trace;
        $this->notify('begin', $trace);

        return $trace;
    }

    private function endAll(): void
    {
        if ($this->stack !== []) {
            $this->end($this->stack[0]);
        }
    }

    private function notify(string $event, Trace $trace): void
    {
        foreach ($this->observers as $observer) {
            $observer($event, $trace);
        }
    }
}
