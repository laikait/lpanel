<?php

declare(strict_types=1);

namespace App\Modules\Shared\Health;

use App\Engine\Cache\Cache;
use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Core\Maintenance;
use App\Engine\Database\ConnectionManager;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\JsonResponse;
use App\Engine\Mail\Transport;
use App\Engine\Mail\Transports\SmtpTransport;
use App\Engine\Queue\Queue;
use App\Engine\Storage\Storage;

/**
 * GET /health: whether this instance can do its job, for a load balancer or an
 * uptime monitor.
 *
 * Each check answers "ok", "warn", "fail" or "skipped", and the whole answers
 * 200 when nothing failed and 503 when something did -- the status code is what
 * a load balancer reads. "warn" is for what a person should know and a load
 * balancer should not act on: a queue over its backlog, maintenance mode. The
 * body names checks and states only: no DSN, no path, no error message,
 * because /health is public. With app.debug on, a failed check says why.
 *
 * **Mail and storage are checked at most every five minutes**
 * (Shared.health.cache_seconds), and the answer is remembered in the cache in
 * between: a load balancer asks every few seconds, and an SMTP relay or an S3
 * bill should not hear about it each time. That takes a cache shared between
 * requests (file or database); the array store remembers nothing.
 *
 * **A module adds its own check** with the health.checks filter: a name =>
 * closure returning ['status' => 'ok'|'warn'|'fail'|'skipped'].
 *
 * A module that wants a different check declares GET /health itself; every
 * module registers after Shared, and the route declared last wins.
 */
final class HealthCheck
{
    /** Below this much free space in system/, logs, sessions and caches start failing. */
    public const MIN_FREE_BYTES = 52428800;

    /** @var array<string, string> check => why it failed, for debug mode */
    private array $details = [];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Cache $cache,
        private readonly Queue $queue,
        private readonly Application $application,
        private readonly Config $config,
        private readonly ?Transport $mail = null,
        private readonly ?Storage $storage = null,
        private readonly ?Maintenance $maintenance = null,
        private readonly ?FilterEngine $filters = null,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var array<string, \Closure(): array<string, mixed>> $probes */
        $probes = [
            'database' => $this->database(...),
            'cache' => $this->cache(...),
            'queue' => $this->queue(...),
            'disk' => $this->disk(...),
            'mail' => fn(): array => $this->remembered('mail', $this->mail(...)),
            'storage' => fn(): array => $this->remembered('storage', $this->storage(...)),
            'maintenance' => $this->maintenance(...),
        ];

        if ($this->filters !== null) {
            /** @var array<string, \Closure(): array<string, mixed>> $probes */
            $probes = $this->filters->apply('health.checks', $probes);
        }

        $checks = [];

        foreach ($probes as $name => $probe) {
            try {
                $checks[$name] = $probe();
            } catch (\Throwable $e) {
                $this->details[$name] = $e->getMessage();
                $checks[$name] = ['status' => 'fail'];
            }
        }

        $failed = \in_array('fail', \array_column($checks, 'status'), true);
        $body = ['status' => $failed ? 'fail' : 'ok', 'checks' => $checks];

        if ($this->config->bool('app.debug') && $this->details !== []) {
            $body['details'] = $this->details;
        }

        return new JsonResponse($body, $failed ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array{status: string} */
    private function database(): array
    {
        if (!$this->connections->isConfigured()) {
            return ['status' => 'skipped'];
        }

        return $this->attempt('database', function (): void {
            $this->connections->connection()->scalar('SELECT 1');
        });
    }

    /** @return array{status: string} */
    private function cache(): array
    {
        return $this->attempt('cache', function (): void {
            $key = 'health.' . \bin2hex(\random_bytes(4));
            $this->cache->set($key, 'ok', 60);
            $read = $this->cache->get($key);
            $this->cache->delete($key);

            if ($read !== 'ok') {
                throw new \RuntimeException('a value written to the cache did not come back');
            }
        });
    }

    /** @return array{status: string, pending?: array<string, int>} */
    private function queue(): array
    {
        // Sync runs jobs where they are dispatched; nothing ever waits.
        if ($this->config->string('queue.store', 'sync') === 'sync') {
            return ['status' => 'skipped'];
        }

        $pending = [];

        try {
            foreach ($this->queue->queues() as $name) {
                $pending[$name] = $this->queue->pending($name);
            }
        } catch (\Throwable $e) {
            $this->details['queue'] = $e->getMessage();

            return ['status' => 'fail'];
        }

        // A backlog is information, not a failure: a load balancer should not
        // pull an instance out of rotation because workers are behind. Over
        // the threshold it is a warning somebody should look at.
        $limit = $this->config->int('Shared.health.queue_backlog', 1000) ?? 1000;
        $over = $limit > 0 && $pending !== [] && \max($pending) > $limit;

        return ['status' => $over ? 'warn' : 'ok', 'pending' => $pending];
    }

    /** @return array{status: string} */
    private function mail(): array
    {
        if ($this->mail instanceof SmtpTransport) {
            return $this->attempt('mail', fn() => $this->mail->probe());
        }

        if ($this->config->string('mail.transport') === 'sendmail') {
            return $this->attempt('mail', function (): void {
                $path = \strtok((string) $this->config->string('mail.sendmail.path', '/usr/sbin/sendmail'), ' ');

                if (!\is_string($path) || !\is_executable($path)) {
                    throw new \RuntimeException(\sprintf('%s is not executable', (string) $path));
                }
            });
        }

        // log and array send nothing anywhere: nothing to reach.
        return ['status' => 'skipped'];
    }

    /** @return array{status: string} */
    private function storage(): array
    {
        if ($this->storage === null) {
            return ['status' => 'skipped'];
        }

        /** @var mixed $disks */
        $disks = $this->config->get('Shared.health.disks');
        $names = \is_array($disks) && $disks !== [] ? \array_values(\array_filter($disks, \is_string(...))) : [$this->storage->defaultName()];

        return $this->attempt('storage', function () use ($names): void {
            foreach ($names as $name) {
                $disk = $this->storage->disk($name);
                $path = 'health/' . \bin2hex(\random_bytes(6)) . '.txt';
                $disk->put($path, 'ok');
                $read = $disk->get($path);
                $disk->delete($path);

                if ($read !== 'ok') {
                    throw new \RuntimeException(\sprintf('a file written to the %s disk did not come back', $name));
                }
            }
        });
    }

    /** @return array{status: string, down?: bool} */
    private function maintenance(): array
    {
        if ($this->maintenance === null) {
            return ['status' => 'skipped'];
        }

        // Only an allowed address gets this far while it is down; it is told,
        // not failed -- the instance is fine, it was asked to stand aside.
        return $this->maintenance->isDown() ? ['status' => 'warn', 'down' => true] : ['status' => 'ok'];
    }

    /**
     * A slow or costly check, answered from the cache when it ran lately.
     *
     * @param \Closure(): array<string, mixed> $probe
     *
     * @return array<string, mixed>
     */
    private function remembered(string $name, \Closure $probe): array
    {
        $seconds = $this->config->int('Shared.health.cache_seconds', 300) ?? 300;

        if ($seconds <= 0) {
            return $probe();
        }

        $key = 'health.result.' . $name;
        $cached = $this->cache->get($key);

        if (\is_array($cached) && \is_string($cached['status'] ?? null)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $result = $probe();
        $this->cache->set($key, $result, $seconds);

        return $result;
    }

    /** @return array{status: string} */
    private function disk(): array
    {
        return $this->attempt('disk', function (): void {
            $system = $this->application->basePath('system');

            if (!\is_dir($system) || !\is_writable($system)) {
                throw new \RuntimeException('system/ is not writable');
            }

            $free = @\disk_free_space($system);

            if ($free !== false && $free < self::MIN_FREE_BYTES) {
                throw new \RuntimeException(\sprintf('only %d bytes free in system/', (int) $free));
            }
        });
    }

    /** @return array{status: string} */
    private function attempt(string $check, \Closure $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->details[$check] = $e->getMessage();

            return ['status' => 'fail'];
        }
    }
}
