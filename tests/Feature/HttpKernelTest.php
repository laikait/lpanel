<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\HttpKernel;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

/**
 * The kernel against the fixture modules.
 *
 * These exercise the lifecycle itself rather than the demo application, so they
 * use tests/Fixtures/Modules and do not care what modules/ happens to contain.
 */
final class HttpKernelTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function kernel(array $config = []): HttpKernel
    {
        return $this->fixtureApplication($config)->boot()->container()->get(HttpKernel::class);
    }

    // ---- purity -----------------------------------------------------------

    /**
     * handle() must never echo, exit, send a header or read a superglobal.
     * That purity is what makes the whole lifecycle testable without a server,
     * and it is why every test in this file asserts on an object.
     */
    public function test_handling_a_request_produces_no_output(): void
    {
        $kernel = $this->kernel();

        \ob_start();
        $kernel->handle(Request::create('GET', '/items'));
        $output = (string) \ob_get_clean();

        self::assertSame('', $output);
    }

    public function test_the_response_is_not_sent_by_the_kernel(): void
    {
        self::assertFalse($this->kernel()->handle(Request::create('GET', '/items'))->isSent());
    }

    // ---- happy paths ------------------------------------------------------

    public function test_a_module_route_returns_json(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/items'));

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->contentType());
        self::assertStringContainsString('"one"', $response->body());
    }

    public function test_a_parametric_route_coerces_and_dispatches(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/api/v1/items/42'));

        self::assertSame(200, $response->status());
        self::assertSame('{"id":42}', $response->body());
    }

    public function test_the_shared_module_stamps_every_response(): void
    {
        self::assertSame('shared', $this->kernel()->handle(Request::create('GET', '/items'))->header('X-Engine'));
    }

    // ---- HEAD and OPTIONS -------------------------------------------------

    public function test_head_produces_the_same_headers_as_get(): void
    {
        $kernel = $this->kernel();

        $get = $kernel->handle(Request::create('GET', '/items'));
        $head = $kernel->handle(Request::create('HEAD', '/items'));

        self::assertSame($get->status(), $head->status());
        self::assertSame($get->headers(), $head->headers());
    }

    /**
     * The kernel produces the body; omitting it for HEAD is the sender's job,
     * which keeps handle() a pure function of the request.
     */
    public function test_head_omits_the_body_only_when_sent(): void
    {
        $response = $this->kernel()->handle(Request::create('HEAD', '/items'));

        self::assertNotSame('', $response->body());

        \ob_start();
        $response->send(omitBody: true);

        self::assertSame('', (string) \ob_get_clean());
    }

    // ---- error paths ------------------------------------------------------

    public function test_an_unknown_path_is_a_404(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/nope'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('/nope', $response->body());
    }

    public function test_a_wrong_method_is_a_405_with_allow(): void
    {
        $response = $this->kernel()->handle(Request::create('PUT', '/api/v1/items'));

        self::assertSame(405, $response->status());
        // Alpha declares POST /api/v1/items only; the GET route in that group is
        // /api/v1/items/{id}, which is a different path.
        self::assertSame('POST', $response->header('Allow'));
    }

    public function test_a_throwing_handler_becomes_a_response_rather_than_an_exception(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/boom'));

        self::assertSame(500, $response->status());
    }

    public function test_request_failed_fires_with_the_throwable_and_the_request(): void
    {
        $app = $this->fixtureApplication()->boot();
        $seen = null;

        $app->container()->get(HookEngine::class)->add(
            'request.failed',
            static function (\Throwable $e, Request $request) use (&$seen): void {
                $seen = [$e::class, $request->path()];
            },
        );

        $app->container()->get(HttpKernel::class)->handle(Request::create('GET', '/nope'));

        self::assertSame(['App\Engine\Http\HttpException', '/nope'], $seen);
    }

    public function test_the_response_filter_runs_for_errors_too(): void
    {
        self::assertSame('shared', $this->kernel()->handle(Request::create('GET', '/nope'))->header('X-Engine'));
    }

    // ---- lifecycle extension points ---------------------------------------

    public function test_the_lifecycle_hooks_and_filters_fire_in_order(): void
    {
        $app = $this->fixtureApplication()->boot();
        $hooks = $app->container()->get(HookEngine::class);
        $filters = $app->container()->get(FilterEngine::class);

        $order = [];

        $filters->add('request.instance', static function (Request $r) use (&$order): Request {
            $order[] = 'request.instance';

            return $r;
        });
        $hooks->add('request.received', static function () use (&$order): void {
            $order[] = 'request.received';
        });
        $filters->add('router.path', static function (string $p) use (&$order): string {
            $order[] = 'router.path';

            return $p;
        });
        $filters->add('route.match', static function (mixed $m) use (&$order): mixed {
            $order[] = 'route.match';

            return $m;
        });
        $hooks->add('route.matched', static function () use (&$order): void {
            $order[] = 'route.matched';
        });
        $hooks->add('dispatch.after', static function () use (&$order): void {
            $order[] = 'dispatch.after';
        });
        $filters->add('response.instance', static function (Response $r) use (&$order): Response {
            $order[] = 'response.instance';

            return $r;
        }, priority: 1);

        $app->container()->get(HttpKernel::class)->handle(Request::create('GET', '/items'));

        self::assertSame([
            'request.instance',
            'request.received',
            'router.path',
            'route.match',
            'route.matched',
            'dispatch.after',
            'response.instance',
        ], $order);
    }

    public function test_the_router_path_filter_can_rewrite_the_path(): void
    {
        $app = $this->fixtureApplication()->boot();

        $app->container()->get(FilterEngine::class)->add(
            'router.path',
            static fn(string $path): string => $path === '/legacy-items' ? '/items' : $path,
        );

        $response = $app->container()->get(HttpKernel::class)->handle(Request::create('GET', '/legacy-items'));

        self::assertSame(200, $response->status());
    }

    public function test_the_response_filter_is_applied_last_of_all(): void
    {
        $app = $this->fixtureApplication()->boot();

        $app->container()->get(FilterEngine::class)->add(
            'response.instance',
            static fn(Response $r): Response => $r->withHeader('X-Last', 'yes'),
            priority: 200,
        );

        $response = $app->container()->get(HttpKernel::class)->handle(Request::create('GET', '/items'));

        self::assertSame('yes', $response->header('X-Last'));
        self::assertSame('shared', $response->header('X-Engine'));
    }

    // ---- debug and disclosure ---------------------------------------------

    /**
     * An application exception may carry a DSN, a path or a credential in its
     * message, so production replaces the whole thing with the status text.
     */
    public function test_production_does_not_leak_an_application_exception(): void
    {
        $response = $this->kernel(['app' => ['debug' => false]])->handle(Request::create('GET', '/kaboom'));

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('hunter2', $response->body());
        self::assertStringNotContainsString('RuntimeException', $response->body());
        self::assertStringNotContainsString('HttpKernel.php', $response->body());
        self::assertStringContainsString('Internal Server Error', $response->body());
    }

    public function test_debug_shows_the_application_exception(): void
    {
        $response = $this->kernel(['app' => ['debug' => true]])->handle(Request::create('GET', '/kaboom'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('hunter2', $response->body());
        self::assertStringContainsString('RuntimeException', $response->body());
    }

    /**
     * An HttpException message is written by the framework, or by a handler that
     * meant it for the client, so it is shown in production too. That is the
     * deliberate distinction from the test above.
     */
    public function test_a_framework_authored_message_is_shown_in_production(): void
    {
        $response = $this->kernel(['app' => ['debug' => false]])->handle(Request::create('GET', '/boom'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('deliberate failure', $response->body());
    }
}
