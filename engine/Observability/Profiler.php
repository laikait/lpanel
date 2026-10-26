<?php

declare(strict_types=1);

namespace App\Engine\Observability;

use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleManager;
use App\Engine\Support\Callback;
use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\AuditRecord;
use App\Engine\System\Audit\SystemAudit;

/**
 * Where the time went, inside one unit of work.
 *
 * **Off unless asked for**, and off means absent rather than idle: when
 * observability.profile is false nothing is attached to anything, so a hook
 * firing pays for a null check and not for a clock. The subsystems it measures
 * do not know it exists. Each exposes an observe() seam -- the hook and filter
 * engines, the module manager, database connections -- and this directory is the
 * only place those seams are connected: instrument() here, and
 * Report::watchQueries() for the connections. An architecture test keeps it that
 * way, so that "profiling is off" stays a statement about wiring rather than
 * about a flag every subsystem has to remember to check.
 *
 * **Aggregated, not a list of events.** A page fires a few thousand filter
 * listeners; recording each one would make profiling the slowest thing on the
 * page and a worker's memory grow without end. Each measurement is folded into a
 * running count, total and maximum under its name, and the number of distinct
 * names kept per category is capped. The one thing aggregation hides is the
 * order things happened in, and for "which query ran fifty times" the count is
 * the more useful answer anyway.
 *
 * **Scoped to traces.** Measurements go to the unit of work currently running.
 * When a job run inside a request ends, what it measured is added to the
 * request's, so the request's summary is inclusive and the job's is its own.
 *
 * **Times are inclusive.** A hook listener that applies a filter counts the
 * filter's time as its own, and the filter counts it again. The categories are
 * therefore not a breakdown that adds up to the request; they answer "how much
 * time was spent inside hooks" and "inside queries" separately, which is the
 * question somebody diagnosing a slow page is asking.
 */
final class Profiler
{
    public const CATEGORY_PATTERN = '/^[a-z][a-z0-9_.-]*$/';

    /** Distinct names kept per category before the rest are folded together. */
    public const MAX_NAMES = 500;

    public const OTHER = '(other)';

    /** @var array<string, array<string, array<string, array{name: string, detail: ?string, count: int, ns: int, max: int}>>> trace id => category => key => entry */
    private array $scopes = [];

    /** @var list<string> open scopes, outermost first */
    private array $open = [];

    /** @var array<string, true> category names already checked */
    private array $categories = [];

    /** @var array<string, string> listener handle => readable name */
    private array $listenerNames = [];

    public function __construct(private readonly bool $enabled = false) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Connect every seam, when profiling is on. Nothing, when it is off.
     *
     * The one place the framework's observe() seams meet this class.
     */
    public function instrument(
        Tracer $tracer,
        HookEngine $hooks,
        FilterEngine $filters,
        ModuleManager $modules,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $tracer->observe(function (string $event, Trace $trace): void {
            $event === 'begin' ? $this->open($trace) : $this->close($trace);
        });

        $hooks->observe(function (string $hook, Callback $listener, int $nanoseconds): void {
            $this->record('hook', $hook, $nanoseconds, $this->listenerName($listener));
        });

        $filters->observe(function (string $filter, Callback $listener, int $nanoseconds): void {
            $this->record('filter', $filter, $nanoseconds, $this->listenerName($listener));
        });

        $modules->observe(function (string $stage, ?string $module, int $nanoseconds): void {
            $this->record('module', $stage, $nanoseconds, $module);
        });

        // System operations announce themselves on system.audit, with their
        // duration; that stream is their seam. Each finished or refused
        // operation is one measurement under its event name, so "commands run",
        // "commands failed", "timeouts" and "service changes" are the counts of
        // system.command.completed, .failed, .timeout and system.service.changed.
        // A start is not counted: its end is.
        $hooks->add(SystemAudit::HOOK, function (AuditRecord $record): void {
            if ($record->outcome === AuditOutcome::Started) {
                return;
            }

            $milliseconds = $record->context['duration_ms'] ?? 0;

            $this->record('system', $record->event, \is_int($milliseconds) ? $milliseconds * 1_000_000 : 0, $record->target);
        }, 10, 'engine');
    }

    /**
     * A statement, timed.
     *
     * Not attached by instrument(), because the connections' one seam is shared
     * with the slow-query warning, which runs with profiling off; see
     * Report::watchQueries(), which calls this.
     */
    public function recordQuery(string $sql, int $nanoseconds, string $connection): void
    {
        $this->record('query', self::statementName($sql), $nanoseconds, $connection);
    }

    // ---- measuring -----------------------------------------------------------

    /**
     * Time a piece of application work under a name of its choosing.
     *
     *     $profiler->measure('billing', 'invoice run', fn () => $run->execute());
     *
     * With profiling off this is a function call and nothing else.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function measure(string $category, string $name, \Closure $work, ?string $detail = null): mixed
    {
        if (!$this->enabled) {
            return $work();
        }

        $started = \hrtime(true);

        try {
            return $work();
        } finally {
            $this->record($category, $name, \hrtime(true) - $started, $detail);
        }
    }

    /** Add a measurement taken elsewhere. Ignored when profiling is off. */
    public function record(string $category, string $name, int $nanoseconds, ?string $detail = null): void
    {
        if (!$this->enabled) {
            return;
        }

        // Checked once per category rather than once per measurement: a page's
        // few thousand filter calls share three category names.
        if (!isset($this->categories[$category]) && \preg_match(self::CATEGORY_PATTERN, $category) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A profiling category is a lower-case name such as "billing" or "cache.store"; "%s" is not.',
                $category,
            ));
        }

        $this->categories[$category] = true;

        $scope = $this->open[\count($this->open) - 1] ?? TraceKind::Process->value;
        $this->scopes[$scope] ??= [];

        self::fold($this->scopes[$scope], $category, [
            'name' => $name,
            'detail' => $detail,
            'count' => 1,
            'ns' => \max(0, $nanoseconds),
            'max' => \max(0, $nanoseconds),
        ]);
    }

    // ---- reading ---------------------------------------------------------------

    /**
     * What the current unit of work has measured so far.
     *
     * Categories by total time, and within each the most expensive names first.
     *
     * @return array{
     *     categories: array<string, array{count: int, ms: float}>,
     *     slowest: array<string, list<array{name: string, detail: ?string, count: int, ms: float, max_ms: float}>>
     * }
     */
    public function summary(int $slowest = 5): array
    {
        $scope = $this->scopes[$this->open[\count($this->open) - 1] ?? TraceKind::Process->value] ?? [];

        $categories = [];
        $top = [];

        foreach ($scope as $category => $entries) {
            $count = 0;
            $total = 0;

            foreach ($entries as $entry) {
                $count += $entry['count'];
                $total += $entry['ns'];
            }

            $categories[$category] = ['count' => $count, 'ms' => \round($total / 1e6, 3)];

            \usort($entries, static fn(array $a, array $b): int => $b['ns'] <=> $a['ns']);

            $top[$category] = \array_map(static fn(array $entry): array => [
                'name' => $entry['name'],
                'detail' => $entry['detail'],
                'count' => $entry['count'],
                'ms' => \round($entry['ns'] / 1e6, 3),
                'max_ms' => \round($entry['max'] / 1e6, 3),
            ], \array_slice($entries, 0, \max(0, $slowest)));
        }

        \uasort($categories, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return ['categories' => $categories, 'slowest' => $top];
    }

    /** Forget everything measured. For a test, or a process that wants a clean start. */
    public function reset(): void
    {
        $this->scopes = [];
        $this->open = [];
    }

    // ---- scopes ------------------------------------------------------------------

    private function open(Trace $trace): void
    {
        $this->open[] = $trace->id;
        $this->scopes[$trace->id] = [];
    }

    /**
     * Close a trace's scope and add what it measured to the one it was inside.
     *
     * A root closes into nothing, and what it measured is gone. That is on
     * purpose: whoever wanted a request's numbers read them while the request
     * was still current, and a worker that kept every job's would be leaking.
     */
    private function close(Trace $trace): void
    {
        $position = \array_search($trace->id, $this->open, true);

        if ($position === false) {
            return;
        }

        $closed = $this->scopes[$trace->id] ?? [];
        unset($this->scopes[$trace->id]);
        \array_splice($this->open, $position, 1);

        $parent = $this->open[$position - 1] ?? null;

        if ($parent === null) {
            return;
        }

        foreach ($closed as $category => $entries) {
            foreach ($entries as $entry) {
                self::fold($this->scopes[$parent], $category, $entry);
            }
        }
    }

    /**
     * @param array<string, array<string, array{name: string, detail: ?string, count: int, ns: int, max: int}>> $scope
     * @param array{name: string, detail: ?string, count: int, ns: int, max: int}                            $entry
     */
    private static function fold(array &$scope, string $category, array $entry): void
    {
        $key = $entry['name'] . "\0" . ($entry['detail'] ?? '');

        if (!isset($scope[$category][$key]) && \count($scope[$category] ?? []) >= self::MAX_NAMES) {
            $key = self::OTHER;
            $entry['name'] = self::OTHER;
            $entry['detail'] = null;
        }

        $existing = $scope[$category][$key] ?? null;

        $scope[$category][$key] = $existing === null ? $entry : [
            'name' => $existing['name'],
            'detail' => $existing['detail'],
            'count' => $existing['count'] + $entry['count'],
            'ns' => $existing['ns'] + $entry['ns'],
            'max' => \max($existing['max'], $entry['max']),
        ];
    }

    /**
     * A listener, named so that a person can find it.
     *
     * Callback::identity() names a closure by its object id, which is right for
     * removing a listener and useless in a profile: "engine Closure#154" was the
     * first thing live verification printed, and it is a different number on the
     * next request. A closure made from a method -- $guard->onRequest(...) --
     * is named for the method; any other closure for the file and line it was
     * written on. Worked out once per listener, by its handle, because it takes
     * reflection.
     */
    private function listenerName(Callback $listener): string
    {
        return $this->listenerNames[$listener->handle] ??= ($listener->module ?? 'global') . ' ' . self::nameOf($listener);
    }

    private static function nameOf(Callback $listener): string
    {
        if (!$listener->callback instanceof \Closure) {
            return $listener->identity();
        }

        $function = new \ReflectionFunction($listener->callback);
        $class = $function->getClosureScopeClass();

        if ($class !== null && !\str_contains($function->getName(), '{closure')) {
            return $class->getName() . '::' . $function->getName();
        }

        $file = $function->getFileName();

        return $file === false
            ? $listener->identity()
            : \sprintf('closure at %s:%d', \basename($file), (int) $function->getStartLine());
    }

    /**
     * A statement as a name: whitespace collapsed, and cut at 200 characters.
     *
     * Bindings are never part of it, and never reach this class at all -- the
     * connection's seam does not pass them. A value bound into a query is the
     * one place in a statement a password or a card number can be, and a
     * profile is exactly the kind of output that gets pasted into a ticket.
     */
    private static function statementName(string $sql): string
    {
        $collapsed = \trim((string) \preg_replace('/\s+/', ' ', $sql));

        return \strlen($collapsed) > 200 ? \substr($collapsed, 0, 197) . '...' : $collapsed;
    }
}
