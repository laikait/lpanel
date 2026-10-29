<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Engine\Http\Request;
use App\Engine\Observability\Trace;
use App\Engine\Observability\TraceKind;
use App\Engine\Observability\Tracer;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TracerTest extends TestCase
{
    // ---- ids ---------------------------------------------------------------

    public function test_a_generated_id_is_valid_unique_and_sorts_by_time(): void
    {
        $first = Trace::generateId();
        $second = Trace::generateId();

        self::assertTrue(Trace::isValidId($first));
        self::assertNotSame($first, $second);
        self::assertSame(24, \strlen($first));
        self::assertLessThanOrEqual(\substr($second, 0, 8), \substr($first, 0, 8));
    }

    /** @return array<string, array{string}> */
    public static function unusableIds(): array
    {
        return [
            'empty' => [''],
            'too short' => ['abc'],
            'a newline, which would write a log line of its own' => ["abcdefgh\nERROR forged"],
            'spaces' => ['abcd efgh ijkl'],
            'too long' => [\str_repeat('a', 129)],
            'starts with punctuation' => ['-abcdefghij'],
            'a quote' => ['abcdefgh"ijk'],
        ];
    }

    #[DataProvider('unusableIds')]
    public function test_an_unusable_id_is_refused(string $id): void
    {
        self::assertFalse(Trace::isValidId($id));

        $trace = Trace::begin(TraceKind::Job, $id, $id);

        self::assertNotSame($id, $trace->id, 'the unusable id was used');
        self::assertSame($trace->id, $trace->correlationId, 'an unusable correlation starts a chain of its own');
    }

    public function test_a_gateway_style_id_is_accepted(): void
    {
        self::assertTrue(Trace::isValidId('req-7f3a9c2e.b41d:0001'));
        self::assertTrue(Trace::isValidId('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
    }

    // ---- the stack ---------------------------------------------------------

    public function test_outside_any_work_there_is_still_a_process_trace(): void
    {
        $tracer = new Tracer();

        self::assertSame(TraceKind::Process, $tracer->current()->kind);
        self::assertSame($tracer->current(), $tracer->current(), 'the process trace is one trace, not one per call');
    }

    /**
     * The mechanism, in one test: work started inside other work inherits its
     * correlation without anybody passing an id along.
     */
    public function test_work_begun_inside_other_work_is_correlated_with_it(): void
    {
        $tracer = new Tracer();
        $request = $tracer->beginRequest(Request::create('GET', '/'));
        $job = $tracer->begin(TraceKind::Job);

        self::assertNotSame($request->id, $job->id);
        self::assertSame($request->id, $job->correlationId);
        self::assertSame($request->id, $job->parentId);
        self::assertSame($job, $tracer->current());

        $tracer->end($job);

        self::assertSame($request, $tracer->current());
    }

    public function test_an_explicit_correlation_wins_over_the_inherited_one(): void
    {
        $tracer = new Tracer();
        $tracer->beginRequest(Request::create('GET', '/'));

        $job = $tracer->begin(TraceKind::Job, 'queued-by-something-else-01');

        self::assertSame('queued-by-something-else-01', $job->correlationId);
    }

    /** A synchronous job that throws must not leave its id on the rest of the request's log. */
    public function test_within_ends_the_trace_even_when_the_work_throws(): void
    {
        $tracer = new Tracer();
        $request = $tracer->beginRequest(Request::create('GET', '/'));

        try {
            $tracer->within($tracer->begin(TraceKind::Job), static function (): never {
                throw new \RuntimeException('job failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame($request, $tracer->current());
    }

    public function test_ending_a_trace_ends_anything_left_open_inside_it(): void
    {
        $tracer = new Tracer();
        $outer = $tracer->begin(TraceKind::Console);
        $tracer->begin(TraceKind::Job);
        $tracer->begin(TraceKind::Job);

        $tracer->end($outer);

        self::assertSame([], $tracer->stack());
    }

    /** A long-lived process handling many requests cannot pile up traces nobody closed. */
    public function test_a_new_request_closes_whatever_the_last_one_left_open(): void
    {
        $tracer = new Tracer();
        $tracer->beginRequest(Request::create('GET', '/one'));
        $tracer->begin(TraceKind::Job);

        $second = $tracer->beginRequest(Request::create('GET', '/two'));

        self::assertSame([$second], $tracer->stack());
    }

    public function test_observers_hear_begin_and_end_innermost_first(): void
    {
        $tracer = new Tracer();
        $heard = [];
        $tracer->observe(static function (string $event, Trace $trace) use (&$heard): void {
            $heard[] = $event . ' ' . $trace->kind->value;
        });

        $console = $tracer->begin(TraceKind::Console);
        $tracer->begin(TraceKind::Job);
        $tracer->end($console);

        self::assertSame(['begin console', 'begin job', 'end job', 'end console'], $heard);
    }

    // ---- ids from outside --------------------------------------------------

    public function test_a_request_cannot_choose_its_own_id_by_default(): void
    {
        $trace = (new Tracer())->beginRequest($this->requestWithIds('gateway-request-0001', 'gateway-chain-0001'));

        self::assertNotSame('gateway-request-0001', $trace->id);
        self::assertSame($trace->id, $trace->correlationId);
    }

    public function test_a_trusted_gateway_names_the_request_and_its_chain(): void
    {
        $trace = (new Tracer(trustIncomingIds: true))
            ->beginRequest($this->requestWithIds('gateway-request-0001', 'gateway-chain-0001'));

        self::assertSame('gateway-request-0001', $trace->id);
        self::assertSame('gateway-chain-0001', $trace->correlationId);
    }

    /** Trusted is not unchecked: an id still ends up in every log line. */
    public function test_even_a_trusted_id_is_checked(): void
    {
        $trace = (new Tracer(trustIncomingIds: true))
            ->beginRequest($this->requestWithIds("forged\nERROR admin logged in", 'gateway-chain-0001'));

        self::assertTrue(Trace::isValidId($trace->id));
        self::assertStringNotContainsString("\n", $trace->id);
        self::assertSame('gateway-chain-0001', $trace->correlationId);
    }

    public function test_the_log_context_names_the_current_work(): void
    {
        $tracer = new Tracer();
        $request = $tracer->beginRequest(Request::create('GET', '/'));

        self::assertSame(
            ['trace' => 'http', 'request_id' => $request->id, 'correlation_id' => $request->id],
            $tracer->logContext(),
        );
    }

    public function test_a_trace_measures_time_and_memory_from_when_it_began(): void
    {
        $trace = Trace::begin(TraceKind::Job);
        $ballast = \str_repeat('x', 200_000);

        self::assertGreaterThan(0, $trace->elapsedNanoseconds());
        self::assertGreaterThan(100_000, $trace->memoryGrowth());
        self::assertSame(200_000, \strlen($ballast));
    }

    private function requestWithIds(string $requestId, string $correlationId): Request
    {
        return Request::create('GET', '/', ['headers' => [
            Tracer::REQUEST_ID_HEADER => $requestId,
            Tracer::CORRELATION_ID_HEADER => $correlationId,
        ]]);
    }
}
