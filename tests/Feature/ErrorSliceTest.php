<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Error\ErrorContext;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

/**
 * The error path, end to end through a real application.
 *
 * The unit tests check each rule; this checks that a request which goes wrong
 * actually reaches them. Two fixture routes do the work: /boom throws an
 * HttpException, whose message this framework wrote and may repeat, and /kaboom
 * throws a RuntimeException whose message is the kind of thing an application
 * exception carries.
 */
final class ErrorSliceTest extends TestCase
{
    /**
     * Production by default.
     *
     * phpunit.xml turns APP_DEBUG on for the whole suite, which is right for
     * every other test and wrong for this one: nearly everything here is about
     * what an application says when it is *not* in development. The two tests
     * that want diagnostics ask for them.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $config
     */
    private function get(string $path, array $headers = [], array $config = ['app' => ['debug' => false]]): Response
    {
        $app = $this->fixtureApplication($config)->boot();

        return $app->handle(Request::create('GET', $path, ['headers' => $headers]));
    }

    // ---- the three renderings -----------------------------------------------

    public function test_a_browser_gets_the_applications_own_page(): void
    {
        $response = $this->get('/nope', ['Accept' => 'text/html']);

        self::assertSame(404, $response->status());
        self::assertSame('text/html; charset=UTF-8', $response->contentType());

        // templates/errors/404.twig, extending the ordinary layout.
        self::assertStringContainsString('That page is not here', $response->body());
        self::assertStringContainsString('<footer class="site-footer">', $response->body());
    }

    public function test_a_status_without_its_own_template_falls_to_the_generic_one(): void
    {
        $response = $this->get('/boom', ['Accept' => 'text/html']);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Something went wrong at our end', $response->body());
        self::assertStringNotContainsString('That page is not here', $response->body());
    }

    public function test_a_program_gets_the_error_document(): void
    {
        $response = $this->get('/nope', ['Accept' => 'application/json']);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->status());

        $data = $response->data();
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertIsArray($data['error']);
        // Always these three, always first. Debug adds more after them; a
        // client that reads only the first three keeps working either way.
        self::assertSame(['status', 'title', 'message'], \array_slice(\array_keys($data['error']), 0, 3));
    }

    // ---- production versus development ---------------------------------------

    public function test_an_application_exception_leaks_nothing_in_production(): void
    {
        $response = $this->get('/kaboom', ['Accept' => 'text/html'], ['app' => ['debug' => false]]);

        $body = $response->body();

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('secret-hunter2', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('#0', $body);
        self::assertStringNotContainsString($this->basePath(), $body);
    }

    public function test_the_same_request_in_debug_says_everything(): void
    {
        $body = $this->get('/kaboom', ['Accept' => 'text/html'], ['app' => ['debug' => true]])->body();

        self::assertStringContainsString('secret-hunter2', $body);
        self::assertStringContainsString('RuntimeException', $body);
        // Whoops, titled with the real error rather than the visitor's message.
        // (The visitor's wording can still appear in the source it shows.)
        self::assertStringContainsString('<title>RuntimeException: dsn=secret-hunter2', $body);
    }

    public function test_an_error_template_is_previewed_with_debug_off(): void
    {
        $debug = $this->get('/nope', ['Accept' => 'text/html'], ['app' => ['debug' => true]])->body();

        self::assertStringContainsString('<title>App\\Engine\\Http\\HttpException', $debug, 'debug wants the trace, not the visitor page');
        self::assertStringNotContainsString('<title>That page is not here', $debug);
    }

    /** A message this framework wrote for the client survives production. */
    public function test_a_framework_authored_http_message_survives(): void
    {
        $response = $this->get('/nope', ['Accept' => 'application/json'], ['app' => ['debug' => false]]);

        self::assertStringContainsString('/nope', $response->body());
    }

    // ---- reporting -------------------------------------------------------------

    public function test_a_failed_request_is_announced_once(): void
    {
        $app = $this->fixtureApplication()->boot();
        $seen = [];

        $app->container()->get(HookEngine::class)->add(
            'error.reported',
            static function (\Throwable $e, ErrorContext $context) use (&$seen): void {
                $seen[] = [$e::class, $context->value];
            },
        );

        $app->handle(Request::create('GET', '/kaboom'));

        self::assertSame([[\RuntimeException::class, 'browser']], $seen);
    }

    public function test_a_successful_request_announces_nothing(): void
    {
        $app = $this->fixtureApplication()->boot();
        $reported = 0;

        $app->container()->get(HookEngine::class)->add(
            'error.reported',
            static function () use (&$reported): void {
                ++$reported;
            },
        );

        $app->handle(Request::create('GET', '/items'));

        self::assertSame(0, $reported);
    }

    /**
     * The context reaches the listener, so a logger can record that an API
     * client saw the failure rather than a person.
     */
    public function test_the_reported_context_follows_the_request(): void
    {
        $app = $this->fixtureApplication()->boot();
        $context = null;

        $app->container()->get(HookEngine::class)->add(
            'error.reported',
            static function (\Throwable $e, ErrorContext $seen) use (&$context): void {
                $context = $seen;
            },
        );

        $app->handle(Request::create('GET', '/kaboom', ['headers' => ['Accept' => 'application/json']]));

        self::assertSame(ErrorContext::Api, $context);
    }
}
