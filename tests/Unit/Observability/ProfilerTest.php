<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleManager;
use App\Engine\Observability\Profiler;
use App\Engine\Observability\TraceKind;
use App\Engine\Observability\Tracer;
use App\Engine\Routing\Router;
use App\Tests\Support\TestCase;

final class ProfilerTest extends TestCase
{
    public function test_a_disabled_profiler_records_nothing_and_attaches_nothing(): void
    {
        $profiler = new Profiler(enabled: false);
        $hooks = new HookEngine();
        $heard = false;

        $profiler->instrument(new Tracer(), $hooks, new FilterEngine(), $this->modules());
        $hooks->add('x.happened', static function () use (&$heard): void {
            $heard = true;
        });
        $hooks->do('x.happened');
        $profiler->record('billing', 'run', 5_000_000);

        self::assertTrue($heard);
        self::assertSame(['categories' => [], 'slowest' => []], $profiler->summary());
    }

    public function test_measure_runs_the_work_either_way(): void
    {
        self::assertSame(42, (new Profiler(false))->measure('billing', 'run', static fn(): int => 42));
        self::assertSame(42, (new Profiler(true))->measure('billing', 'run', static fn(): int => 42));
    }

    /** Aggregated: a name measured many times is one entry with a count, a total and a maximum. */
    public function test_measurements_under_one_name_are_folded_together(): void
    {
        $profiler = new Profiler(true);

        $profiler->record('query', 'SELECT 1', 2_000_000, 'default');
        $profiler->record('query', 'SELECT 1', 6_000_000, 'default');
        $profiler->record('query', 'SELECT 2', 1_000_000, 'default');

        $summary = $profiler->summary();

        self::assertSame(['count' => 3, 'ms' => 9.0], $summary['categories']['query']);
        self::assertSame(
            ['name' => 'SELECT 1', 'detail' => 'default', 'count' => 2, 'ms' => 8.0, 'max_ms' => 6.0],
            $summary['slowest']['query'][0],
        );
    }

    public function test_categories_are_ordered_by_time_and_names_by_cost(): void
    {
        $profiler = new Profiler(true);

        $profiler->record('hook', 'cheap', 1_000_000);
        $profiler->record('query', 'dear', 9_000_000);
        $profiler->record('query', 'middling', 3_000_000);

        $summary = $profiler->summary();

        self::assertSame(['query', 'hook'], \array_keys($summary['categories']));
        self::assertSame(['dear', 'middling'], \array_column($summary['slowest']['query'], 'name'));
    }

    /** A worker runs for hours. The number of names kept cannot grow with it. */
    public function test_the_names_kept_per_category_are_capped(): void
    {
        $profiler = new Profiler(true);

        for ($i = 0; $i < Profiler::MAX_NAMES + 50; ++$i) {
            $profiler->record('query', 'SELECT ' . $i, 1_000);
        }

        $summary = $profiler->summary(Profiler::MAX_NAMES + 50);

        self::assertCount(Profiler::MAX_NAMES + 1, $summary['slowest']['query']);
        self::assertSame(Profiler::MAX_NAMES + 50, $summary['categories']['query']['count'], 'nothing was lost, only folded');
        self::assertContains(Profiler::OTHER, \array_column($summary['slowest']['query'], 'name'));
    }

    public function test_a_category_must_be_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Profiler(true))->record('Billing Run', 'x', 1);
    }

    // ---- scopes ------------------------------------------------------------

    /**
     * A job inside a request: the job's summary is its own, and when it ends
     * the request's summary includes it.
     */
    public function test_a_nested_unit_of_work_reports_its_own_and_adds_to_its_parent(): void
    {
        $tracer = new Tracer();
        $profiler = new Profiler(true);
        $profiler->instrument($tracer, new HookEngine(), new FilterEngine(), $this->modules());

        $request = $tracer->begin(TraceKind::Http);
        $profiler->record('query', 'in the request', 1_000_000);

        $job = $tracer->begin(TraceKind::Job);
        $profiler->record('query', 'in the job', 2_000_000);

        self::assertSame(1, $profiler->summary()['categories']['query']['count'], 'the job sees only its own');

        $tracer->end($job);

        self::assertSame(['count' => 2, 'ms' => 3.0], $profiler->summary()['categories']['query']);

        $tracer->end($request);
    }

    public function test_hooks_and_filters_are_timed_per_listener_once_instrumented(): void
    {
        $tracer = new Tracer();
        $hooks = new HookEngine();
        $filters = new FilterEngine();
        $profiler = new Profiler(true);
        $profiler->instrument($tracer, $hooks, $filters, $this->modules());
        $tracer->begin(TraceKind::Http);

        $hooks->add('invoice.paid', static function (): void {
            \usleep(1000);
        }, 10, 'Billing');
        $filters->add('invoice.total', static fn(int $total): int => $total + 1, 10, 'Tax');

        $hooks->do('invoice.paid');
        $hooks->do('invoice.paid');
        $filters->apply('invoice.total', 1);

        $summary = $profiler->summary();

        self::assertSame(2, $summary['categories']['hook']['count']);
        self::assertGreaterThanOrEqual(1.0, $summary['categories']['hook']['ms']);
        self::assertSame('invoice.paid', $summary['slowest']['hook'][0]['name']);
        self::assertStringStartsWith('Billing ', (string) $summary['slowest']['hook'][0]['detail']);
        self::assertSame(1, $summary['categories']['filter']['count']);
    }

    /**
     * "engine Closure#154" is what the first live run printed: an object id,
     * different on every request and meaningless to whoever reads the log.
     */
    public function test_a_listener_is_named_so_a_person_can_find_it(): void
    {
        $tracer = new Tracer();
        $hooks = new HookEngine();
        $profiler = new Profiler(true);
        $profiler->instrument($tracer, $hooks, new FilterEngine(), $this->modules());
        $tracer->begin(TraceKind::Http);

        $hooks->add('x.happened', $tracer->current(...), 10, 'engine');
        $line = __LINE__ + 1;
        $hooks->add('x.happened', static function (): void {}, 20, 'Example');
        $hooks->do('x.happened');

        $details = \array_column($profiler->summary()['slowest']['hook'], 'detail');
        \sort($details);

        self::assertSame([
            \sprintf('Example closure at ProfilerTest.php:%d', $line),
            'engine ' . Tracer::class . '::current',
        ], $details);
    }

    public function test_reset_forgets_everything(): void
    {
        $profiler = new Profiler(true);
        $profiler->record('query', 'x', 1);
        $profiler->reset();

        self::assertSame([], $profiler->summary()['categories']);
    }

    private function modules(): ModuleManager
    {
        return new ModuleManager(new Container(), new Config(), new Router(), new HookEngine(), new FilterEngine());
    }
}
