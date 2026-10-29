<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\DatabaseException;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Observability\Profiler;
use App\Engine\Observability\Report;
use App\Engine\Observability\Tracer;
use App\Engine\Queue\Queue;
use App\Engine\Queue\Worker;
use App\Engine\Queue\WorkerOptions;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Fixtures\Queue\TracedJob;
use App\Tests\Support\TestCase;

/**
 * Phase 28, end to end, through a real application.
 *
 * The specification's instruction is "first build reliable instrumentation
 * APIs", and "reliable" is what this file is about: that every response can be
 * named, that every log line written while serving it carries the same name,
 * that the name survives a trip through the queue, and that the profiler reports
 * where the time went -- and that none of it happens when it was not asked for.
 */
final class ObservabilitySliceTest extends TestCase
{
    // ---- request ids -----------------------------------------------------------

    public function test_every_response_carries_a_request_id_of_its_own(): void
    {
        $app = $this->fixtureApplication();

        $first = $app->handle($this->request('/items'));
        $second = $app->handle($this->request('/items'));

        self::assertNotNull($first->header('X-Request-Id'));
        self::assertNotSame($first->header('X-Request-Id'), $second->header('X-Request-Id'));
        self::assertNull($first->header('X-Correlation-Id'), 'a request that started its own chain does not repeat its id');
    }

    /** Including the responses somebody is most likely to quote to support. */
    public function test_errors_not_found_and_assets_are_identified_too(): void
    {
        $app = $this->fixtureApplication();

        foreach (['/kaboom', '/nowhere', '/assets/core/css/app.css'] as $path) {
            self::assertNotNull($app->handle($this->request($path))->header('X-Request-Id'), $path);
        }
    }

    /**
     * Error context: the error record names the request by the same id the
     * client was given, and says what the request was without its query string.
     */
    public function test_an_error_is_logged_under_the_id_the_client_received(): void
    {
        [$app, $log] = $this->logged($this->fixtureApplication());

        $response = $app->handle($this->request('/kaboom?token=query-token-zz9'));
        $error = $this->recordIn($log, 'error');

        self::assertSame(500, $response->status());
        self::assertSame($response->header('X-Request-Id'), $error->context['request_id']);
        self::assertSame('http', $error->context['trace']);
        self::assertSame('GET', $error->context['method']);
        self::assertSame('/kaboom', $error->context['path']);
        self::assertStringNotContainsString('query-token-zz9', \json_encode($error->context, \JSON_THROW_ON_ERROR));
    }

    public function test_a_gateways_ids_are_used_only_when_the_deployment_trusts_them(): void
    {
        $request = $this->request('/items', ['headers' => [
            'X-Request-Id' => 'gateway-request-0042',
            'X-Correlation-Id' => 'gateway-chain-0042',
        ]]);

        $untrusted = $this->fixtureApplication()->handle($request);
        $trusted = $this->fixtureApplication(['observability' => ['trust_incoming_ids' => true]])->handle($request);

        self::assertNotSame('gateway-request-0042', $untrusted->header('X-Request-Id'));
        self::assertSame('gateway-request-0042', $trusted->header('X-Request-Id'));
        self::assertSame('gateway-chain-0042', $trusted->header('X-Correlation-Id'));
    }

    // ---- correlation across the queue -------------------------------------------

    /** Queued while serving a request, run by a worker afterwards: one chain. */
    public function test_a_job_queued_by_a_request_runs_under_that_requests_correlation(): void
    {
        TracedJob::reset();
        $app = $this->fixtureApplication(['queue' => ['store' => 'memory']])->boot();
        $container = $app->container();
        $tracer = $container->get(Tracer::class);

        $request = $tracer->beginRequest($this->request('/invoices/run'));
        $container->get(Queue::class)->push(new TracedJob());
        $tracer->end($request);

        $container->get(Worker::class)->runOnce(new WorkerOptions(tries: 1, timeout: 60, sleep: 0, stopWhenEmpty: true));

        $ranAs = TracedJob::$ranAs;

        self::assertNotNull($ranAs, 'the job did not run');
        self::assertSame($request->id, $ranAs->correlationId);
        self::assertNotSame($request->id, $ranAs->id);
    }

    // ---- profiling ----------------------------------------------------------------

    /** Off means absent: no header, no record, nothing measured. */
    public function test_with_profiling_off_nothing_is_measured_or_reported(): void
    {
        [$app, $log] = $this->logged($this->fixtureApplication(['app' => ['debug' => true]]));

        $response = $app->handle($this->request('/items'));

        self::assertNull($response->header('Server-Timing'));
        self::assertSame([], $this->recordsIn($log, Report::CHANNEL));
        self::assertSame([], $app->container()->get(Profiler::class)->summary()['categories']);
    }

    public function test_with_profiling_on_a_request_reports_where_its_time_went(): void
    {
        [$app, $log] = $this->logged($this->fixtureApplication([
            'app' => ['debug' => true],
            'observability' => ['profile' => true],
        ]));

        $response = $app->handle($this->request('/items'));

        $record = $this->recordIn($log, Report::CHANNEL);
        self::assertSame($response->header('X-Request-Id'), $record->context['request_id']);
        self::assertStringStartsWith('GET /items 200 in ', $record->message);
        self::assertIsFloat($record->context['elapsed_ms']);
        self::assertArrayHasKey('memory_peak_mb', $record->context);

        /** @var array<string, mixed> $categories */
        $categories = $record->context['categories'];

        // The first request boots, so module timing belongs to it; hooks and
        // filters fire on every request.
        foreach (['module', 'hook', 'filter'] as $category) {
            self::assertArrayHasKey($category, $categories, $category . ' was not measured');
        }

        $timing = (string) $response->header('Server-Timing');
        self::assertMatchesRegularExpression('/^app;dur=[0-9.]+/', $timing);
        self::assertMatchesRegularExpression('/module;dur=[0-9.]+;desc="\d+"/', $timing);
    }

    /**
     * Server-Timing describes exactly where a request spent its time, which is
     * what a production system should not hand to every client. The log still
     * gets it.
     */
    public function test_outside_debug_the_profile_is_logged_but_not_sent(): void
    {
        [$app, $log] = $this->logged($this->fixtureApplication([
            'app' => ['debug' => false],
            'observability' => ['profile' => true],
        ]));

        $response = $app->handle($this->request('/items'));

        self::assertNull($response->header('Server-Timing'));
        self::assertCount(1, $this->recordsIn($log, Report::CHANNEL));
    }

    public function test_queries_are_timed_by_statement_without_their_values(): void
    {
        $app = $this->fixtureApplication([
            'observability' => ['profile' => true],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();
        $container = $app->container();
        $tracer = $container->get(Tracer::class);

        $summary = $tracer->within($tracer->beginRequest($this->request('/')), static function () use ($container): array {
            $connection = $container->get(ConnectionManager::class)->connection();

            for ($i = 0; $i < 3; ++$i) {
                $connection->scalar('SELECT ?', ['hunter2-card-4111']);
            }

            return $container->get(Profiler::class)->summary();
        });

        self::assertSame(3, $summary['categories']['query']['count']);
        self::assertSame('SELECT ?', $summary['slowest']['query'][0]['name']);
        self::assertSame(3, $summary['slowest']['query'][0]['count'], 'the same statement three times is one line with a count');
        self::assertStringNotContainsString('hunter2', \var_export($summary, true));
    }

    /** The one timing worth paying for on every statement, profiling or not. */
    public function test_a_slow_query_is_logged_with_its_statement_and_never_its_values(): void
    {
        [$app, $log] = $this->logged($this->fixtureApplication([
            'observability' => ['slow_query_ms' => 1],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ]));

        $app->boot()->container()->get(ConnectionManager::class)->connection()->scalar(
            'WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < ?) SELECT COUNT(*) FROM n',
            [400000],
        );

        $warning = $this->recordIn($log, Report::SLOW_QUERY_CHANNEL);

        self::assertStringStartsWith('Slow query on default: ', $warning->message);
        self::assertStringContainsString('WITH RECURSIVE', (string) $warning->context['sql']);
        self::assertSame(1, $warning->context['bindings'], 'how many values, not what they were');
        self::assertSame(1, $warning->context['rows']);
        self::assertStringNotContainsString('400000', \json_encode($warning->context, \JSON_THROW_ON_ERROR));
        self::assertArrayHasKey('request_id', $warning->context, 'even outside a request, the line names its process');
    }

    /** The database layer announces; the bootstrap turns each announcement into a database.* hook. */
    public function test_statements_and_transactions_fire_database_hooks(): void
    {
        $container = $this->fixtureApplication([
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot()->container();

        $heard = [];
        $hooks = $container->get(HookEngine::class);

        foreach (['query.failed', 'transaction.committed', 'transaction.rolled_back', 'transaction.retrying'] as $event) {
            $hooks->add('database.' . $event, static function () use (&$heard, $event): void {
                $heard[] = $event;
            });
        }

        $connection = $container->get(ConnectionManager::class)->connection();
        $connection->transaction(static fn(): bool => true);

        try {
            $connection->transaction(static fn(Connection $db): mixed => $db->select('SELECT * FROM no_such_table'));
        } catch (DatabaseException) {
        }

        self::assertSame(['transaction.committed', 'query.failed', 'transaction.rolled_back'], $heard);
    }

    public function test_a_console_command_is_traced_and_profiled(): void
    {
        $app = $this->fixtureApplication(['observability' => ['profile' => true]], cli: ['laika', 'module:list']);
        [$app, $log] = $this->logged($app);

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $app->container()->instance(Output::class, new Output($stream));

        self::assertSame(0, $app->run());

        $record = $this->recordIn($log, Report::CHANNEL);
        self::assertStringStartsWith('console module:list exited 0 in ', $record->message);
        self::assertSame('console', $record->context['trace']);
    }

    // ---- helpers --------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     * @param list<string>|null    $cli
     */
    protected function fixtureApplication(array $config = [], ?array $cli = null): Application
    {
        $app = parent::fixtureApplication($config);

        if ($cli === null) {
            return $app;
        }

        // The fixture helper builds an HTTP context; a console run needs its own.
        return \App\Engine\Bootstrap\Bootstrap::create(
            $this->basePath(),
            ExecutionContext::cli($cli),
            [
                ...$config,
                'app' => ['handle_errors' => false],
                'security' => ['counters' => 'memory'],
                'session' => ['store' => 'memory'],
                'modules' => ['paths' => [
                    'tests/Fixtures/Modules/Shared',
                    'tests/Fixtures/Modules/Plugins',
                    'tests/Fixtures/Modules/Gateways',
                ]],
            ],
        );
    }

    /** @param array<string, mixed> $options */
    private function request(string $path, array $options = []): Request
    {
        return Request::create('GET', $path, ['server' => ['SCRIPT_NAME' => '/index.php'], ...$options]);
    }

    /** @return array{Application, CollectingWriter} */
    private function logged(Application $app): array
    {
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        return [$app, $writer];
    }

    /** @return list<LogRecord> */
    private function recordsIn(CollectingWriter $log, string $channel): array
    {
        return \array_values(\array_filter($log->records, static fn(LogRecord $record): bool => $record->channel === $channel));
    }

    private function recordIn(CollectingWriter $log, string $channel): LogRecord
    {
        $records = $this->recordsIn($log, $channel);
        self::assertNotSame([], $records, 'nothing was logged on the ' . $channel . ' channel');

        return $records[\count($records) - 1];
    }
}
