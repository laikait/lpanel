<?php

declare(strict_types=1);

namespace App\Tests\Unit\System;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Config\ConfigurationException;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Filter\FilterEngine;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\Invocation;
use App\Tests\Support\TestCase;

/**
 * No command holds a web request longer than system.execution.http_timeout.
 */
final class HttpTimeoutTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(ExecutionContext $context, array $config = []): Application
    {
        return Bootstrap::create($this->basePath(), $context, ['app' => ['handle_errors' => false], ...$config]);
    }

    private function http(): ExecutionContext
    {
        return ExecutionContext::http(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php']);
    }

    private function timeoutFor(Application $app, float $asked): float
    {
        return Invocation::prepare(
            new Command(\PHP_BINARY, ['-v'], timeout: $asked),
            60.0,
            1024,
            filters: $app->container()->get(FilterEngine::class),
        )->timeout;
    }

    public function test_inside_a_web_request_a_command_is_capped_at_the_http_timeout(): void
    {
        $app = $this->app($this->http());

        self::assertSame(10.0, $this->timeoutFor($app, 600.0));
        self::assertSame(2.0, $this->timeoutFor($app, 2.0), 'a shorter timeout is left alone');
    }

    public function test_the_console_and_workers_are_not_capped(): void
    {
        self::assertSame(600.0, $this->timeoutFor($this->app(ExecutionContext::cli(['laika'])), 600.0));
    }

    public function test_the_cap_can_be_changed_or_removed(): void
    {
        self::assertSame(3.0, $this->timeoutFor($this->app($this->http(), ['system' => ['execution' => ['http_timeout' => 3]]]), 600.0));
        self::assertSame(600.0, $this->timeoutFor($this->app($this->http(), ['system' => ['execution' => ['http_timeout' => null]]]), 600.0));
    }

    public function test_a_cap_that_is_not_one_is_a_boot_error(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('system.execution.http_timeout');

        $this->app($this->http(), ['system' => ['execution' => ['http_timeout' => 0]]]);
    }
}
