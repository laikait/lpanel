<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Core\HttpKernel;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

/**
 * GET /health, as the shipped Shared module answers it.
 */
final class HealthSliceTest extends TestCase
{
    /**
     * The storage check writes a file; in memory here, not in the project's
     * own system/Storage.
     *
     * @param array<string, mixed> $config
     */
    private function health(array $config = []): Response
    {
        return $this->handle($this->shippedApplication(\array_replace_recursive([
            'storage' => ['disks' => ['local' => ['driver' => 'memory']]],
        ], $config))->boot());
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        /** @var array<string, mixed> */
        return \json_decode($response->body(), true, 16, \JSON_THROW_ON_ERROR);
    }

    /** A connection that cannot open: a SQLite file in a directory that does not exist. */
    private const BROKEN_DATABASE = ['connections' => ['default' => ['dsn' => 'sqlite:/no/such/directory/health.sqlite']]];

    public function test_a_healthy_instance_answers_200(): void
    {
        $response = $this->health();
        $body = self::body($response);

        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('ok', $body['status']);
        self::assertSame(
            [
                'database' => ['status' => 'skipped'],
                'cache' => ['status' => 'ok'],
                'queue' => ['status' => 'skipped'],
                'disk' => ['status' => 'ok'],
                'mail' => ['status' => 'skipped'],
                'storage' => ['status' => 'ok'],
                'maintenance' => ['status' => 'ok'],
            ],
            $body['checks'],
            'no database is configured, the queue is sync and mail goes to the log, so none of them is checked',
        );
    }

    public function test_the_answer_is_never_cached(): void
    {
        self::assertSame('no-store', $this->health()->header('Cache-Control'));
    }

    public function test_a_failing_database_answers_503(): void
    {
        $response = $this->health(['database' => self::BROKEN_DATABASE]);
        $body = self::body($response);

        self::assertSame(503, $response->status());
        self::assertSame('fail', $body['status']);
        self::assertSame(['status' => 'fail'], $body['checks']['database'] ?? null);
    }

    public function test_a_failure_names_nothing_outside_debug_mode(): void
    {
        $response = $this->health(['app' => ['debug' => false], 'database' => self::BROKEN_DATABASE]);

        self::assertArrayNotHasKey('details', self::body($response));
        self::assertStringNotContainsString('no/such/directory', $response->body());
        self::assertStringNotContainsString('sqlite', $response->body());
    }

    public function test_debug_mode_says_why_a_check_failed(): void
    {
        $body = self::body($this->health(['app' => ['debug' => true], 'database' => self::BROKEN_DATABASE]));

        self::assertIsArray($body['details'] ?? null);
        self::assertArrayHasKey('database', $body['details']);
    }

    public function test_a_queue_that_holds_jobs_reports_its_backlog_without_failing(): void
    {
        $response = $this->health(['queue' => ['store' => 'memory']]);
        $body = self::body($response);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $body['checks']['queue']['status'] ?? null);
        self::assertIsArray($body['checks']['queue']['pending'] ?? null);
    }

    public function test_a_queue_over_its_backlog_warns_without_failing(): void
    {
        $application = $this->shippedApplication(['queue' => ['store' => 'memory'], 'Shared' => ['health' => ['queue_backlog' => 1]], 'storage' => ['disks' => ['local' => ['driver' => 'memory']]]])->boot();
        $queue = $application->container()->get(\App\Engine\Queue\Queue::class);
        $queue->push(new \App\Tests\Fixtures\Queue\RecordingJob('a'));
        $queue->push(new \App\Tests\Fixtures\Queue\RecordingJob('b'));

        $response = $this->handle($application);

        self::assertSame(200, $response->status());
        self::assertSame('ok', self::body($response)['status']);
        self::assertSame('warn', self::body($response)['checks']['queue']['status'] ?? null);
    }

    public function test_an_unreachable_mail_server_fails_and_the_answer_is_reused(): void
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($socket, false), ':'), 1);
        \fclose($socket);

        $application = $this->shippedApplication([
            'app' => ['debug' => true],
            'cache' => ['store' => 'array'],
            'mail' => ['transport' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'timeout' => 1]],
            'storage' => ['disks' => ['local' => ['driver' => 'memory']]],
        ])->boot();

        $first = self::body($this->handle($application));
        self::assertSame(['status' => 'fail'], $first['checks']['mail'] ?? null);
        self::assertArrayHasKey('mail', $first['details'] ?? []);

        $cache = $application->container()->get(\App\Engine\Cache\Cache::class);
        self::assertSame(['status' => 'fail'], $cache->get('health.result.mail'), 'Not remembered.');
    }

    public function test_a_disk_that_cannot_be_written_answers_503(): void
    {
        $response = $this->health(['storage' => ['default' => 'broken', 'disks' => ['broken' => ['driver' => 'local', 'root' => '/proc/no-such-place']]]]);

        self::assertSame(503, $response->status());
        self::assertSame(['status' => 'fail'], self::body($response)['checks']['storage'] ?? null);
    }

    public function test_maintenance_mode_is_reported_to_whoever_still_gets_in(): void
    {
        $application = $this->shippedApplication(['storage' => ['disks' => ['local' => ['driver' => 'memory']]]])->boot();
        $maintenance = $application->container()->get(\App\Engine\Core\Maintenance::class);
        $maintenance->down(allow: ['127.0.0.1']);

        try {
            $kernel = $application->container()->get(HttpKernel::class);
            self::assertInstanceOf(HttpKernel::class, $kernel);
            $response = $kernel->handle(Request::create('GET', '/health', ['server' => ['REMOTE_ADDR' => '127.0.0.1']]));

            self::assertSame(200, $response->status());
            self::assertSame(['status' => 'warn', 'down' => true], self::body($response)['checks']['maintenance'] ?? null);
        } finally {
            $maintenance->up();
        }
    }

    public function test_a_module_adds_a_check_through_the_filter(): void
    {
        $application = $this->shippedApplication(['storage' => ['disks' => ['local' => ['driver' => 'memory']]]])->boot();
        $application->container()->get(\App\Engine\Filter\FilterEngine::class)->add('health.checks', static fn(array $checks): array => [
            ...$checks,
            'payments' => static fn(): array => ['status' => 'fail'],
        ]);

        $response = $this->handle($application);

        self::assertSame(503, $response->status());
        self::assertSame(['status' => 'fail'], self::body($response)['checks']['payments'] ?? null);
    }

    public function test_a_module_can_replace_it(): void
    {
        $application = $this->shippedApplication(['modules' => ['paths' => ['modules/Shared', 'tests/Fixtures/Modules/Health']]]);
        $response = $this->handle($application->boot());

        self::assertSame(['status' => 'custom'], self::body($response));
    }

    private function handle(Application $application): Response
    {
        $kernel = $application->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel->handle(Request::create('GET', '/health'));
    }
}
