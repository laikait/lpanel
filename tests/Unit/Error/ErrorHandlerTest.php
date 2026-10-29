<?php

declare(strict_types=1);

namespace App\Tests\Unit\Error;

use App\Engine\Config\Config;
use App\Engine\Database\DatabaseException;
use App\Engine\Error\ErrorContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Module\ModuleException;
use App\Tests\Support\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private HookEngine $hooks;

    protected function setUp(): void
    {
        $this->hooks = new HookEngine();
    }

    private function handler(bool $debug): ErrorHandler
    {
        return new ErrorHandler(new Config(['app' => ['debug' => $debug]]), $this->hooks);
    }

    // ---- HTTP exceptions --------------------------------------------------

    public function test_an_http_exception_keeps_its_status(): void
    {
        $response = $this->handler(false)->toResponse(HttpException::notFound('/nope'));

        self::assertSame(404, $response->status());
    }

    /**
     * A 405 without an Allow header is a protocol violation, and the error
     * handler is the last place that could drop it.
     */
    public function test_a_method_not_allowed_keeps_its_allow_header(): void
    {
        $response = $this->handler(false)->toResponse(HttpException::methodNotAllowed(['POST', 'GET']));

        self::assertSame(405, $response->status());
        self::assertSame('GET, POST', $response->header('Allow'));
    }

    public function test_an_http_exception_message_is_shown_even_outside_debug(): void
    {
        $response = $this->handler(false)->toResponse(HttpException::badRequest('The id must be an integer.'));

        self::assertSame(400, $response->status());
        self::assertStringContainsString('The id must be an integer.', $response->body());
    }

    public function test_an_ordinary_throwable_becomes_a_five_hundred(): void
    {
        self::assertSame(500, $this->handler(false)->toResponse(new \RuntimeException('boom'))->status());
    }

    // ---- content negotiation ----------------------------------------------

    public function test_a_json_client_gets_a_json_error(): void
    {
        $request = Request::create('GET', '/api/customers', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        $response = $this->handler(false)->toResponse(HttpException::notFound('/api/nope'), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('application/json; charset=UTF-8', $response->contentType());

        $data = $response->data();
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertIsArray($data['error']);
        self::assertSame(404, $data['error']['status']);
        self::assertSame('Not Found', $data['error']['title']);
    }

    public function test_a_browser_gets_an_html_error(): void
    {
        $request = Request::create('GET', '/customers', ['headers' => ['Accept' => 'text/html']]);

        $response = $this->handler(false)->toResponse(new \RuntimeException('boom'), $request);

        self::assertSame('text/html; charset=UTF-8', $response->contentType());
        self::assertStringContainsString('<h1>500 Internal Server Error</h1>', $response->body());
    }

    public function test_no_request_still_produces_a_response(): void
    {
        $response = $this->handler(false)->toResponse(new \RuntimeException('boom'));

        self::assertSame('text/html; charset=UTF-8', $response->contentType());
    }

    // ---- information disclosure -------------------------------------------
    //
    // This is the security boundary of the whole error path. A production
    // response must not leak the message, the class, the file, the line or the
    // trace of an exception the framework did not write itself.

    public function test_production_leaks_nothing_about_the_exception(): void
    {
        $exception = new \RuntimeException('SQLSTATE[HY000] password=hunter2');

        $response = $this->handler(false)->toResponse($exception);
        $body = $response->body();

        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString(__FILE__, $body);
        self::assertStringNotContainsString('#0', $body);
        self::assertStringContainsString('Internal Server Error', $body);
    }

    public function test_production_json_leaks_nothing_either(): void
    {
        $request = Request::create('GET', '/api', ['headers' => ['Accept' => 'application/json']]);
        $exception = new \RuntimeException('password=hunter2');

        $response = $this->handler(false)->toResponse($exception, $request);

        self::assertStringNotContainsString('hunter2', $response->body());
        self::assertStringNotContainsString('RuntimeException', $response->body());
        self::assertStringNotContainsString('trace', $response->body());
    }

    public function test_debug_shows_the_diagnostics(): void
    {
        $exception = new \RuntimeException('boom');

        $body = $this->handler(true)->toResponse($exception)->body();

        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('boom', $body);
        self::assertStringContainsString(__FILE__, $body);
    }

    public function test_debug_json_includes_the_trace(): void
    {
        $request = Request::create('GET', '/api', ['headers' => ['Accept' => 'application/json']]);

        $response = $this->handler(true)->toResponse(new \RuntimeException('boom'), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = $response->data();

        self::assertIsArray($data);
        self::assertIsArray($data['error']);
        self::assertSame(\RuntimeException::class, $data['error']['exception']);
        self::assertSame(__FILE__, $data['error']['file']);
        self::assertIsArray($data['error']['trace']);
    }

    public function test_the_html_error_escapes_the_exception_message(): void
    {
        $exception = HttpException::badRequest('<script>alert(1)</script>');

        $body = $this->handler(false)->toResponse($exception)->body();

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    // ---- CLI --------------------------------------------------------------

    /**
     * The console obeys the same message rule the web does.
     *
     * It did not until the console grew a real error path: an arbitrary
     * exception's message was printed verbatim, and a data-layer exception
     * carrying "dsn=..." would have put a password in a cron log. The class
     * name stays, because a class name is a fact about the code rather than a
     * secret, and the person reading a console error is running that code.
     */
    public function test_cli_rendering_does_not_print_an_arbitrary_message_in_production(): void
    {
        $output = $this->handler(false)->renderCli(new \RuntimeException('dsn=secret-hunter2'));

        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringNotContainsString('secret-hunter2', $output);
        self::assertStringContainsString('Internal Server Error', $output);
        self::assertStringNotContainsString('#0', $output);
    }

    public function test_cli_rendering_says_how_to_see_the_withheld_message(): void
    {
        $output = $this->handler(false)->renderCli(new \RuntimeException('dsn=secret-hunter2'));

        self::assertStringContainsString('APP_DEBUG=1', $output);
    }

    /** A framework-authored message is written by us, so it survives. */
    public function test_cli_rendering_keeps_a_framework_message_in_production(): void
    {
        $output = $this->handler(false)->renderCli(HttpException::notFound('/nope'));

        self::assertStringContainsString('/nope', $output);
    }

    public function test_cli_rendering_says_everything_in_debug(): void
    {
        $output = $this->handler(true)->renderCli(new \RuntimeException('dsn=secret-hunter2'));

        self::assertStringContainsString('secret-hunter2', $output);
    }

    public function test_cli_rendering_includes_a_trace_in_debug(): void
    {
        $output = $this->handler(true)->renderCli(new \RuntimeException('boom'));

        self::assertStringContainsString(__FILE__, $output);
        self::assertStringContainsString('#0', $output);
    }

    // ---- who is reading ----------------------------------------------------

    /**
     * A framework message is an inventory of the application.
     *
     * "Command X is already registered by module Y" tells an operator exactly
     * what they need and tells an anonymous client what modules exist.
     */
    public function test_a_framework_message_never_reaches_a_web_client(): void
    {
        $exception = ModuleException::entryMustReturnClosure('Secret', '/srv/app/modules/x', 'array');

        $body = $this->handler(false)->toResponse($exception)->body();

        self::assertStringNotContainsString('Secret', $body);
        self::assertStringNotContainsString('/srv/app', $body);
    }

    public function test_the_same_message_does_reach_an_operator(): void
    {
        $exception = ModuleException::entryMustReturnClosure('Secret', '/srv/app/modules/x', 'array');

        $output = $this->handler(false)->renderCli($exception);

        self::assertStringContainsString('Secret', $output);
    }

    /**
     * Except when the framework did not write all of it.
     *
     * DatabaseException quotes the driver back, and a driver quotes the DSN.
     */
    public function test_a_withheld_message_is_kept_from_the_operator_too(): void
    {
        $exception = DatabaseException::cannotConnect(
            'reporting',
            'mysql',
            new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user root@localhost (password: hunter2)'),
        );

        $output = $this->handler(false)->renderCli($exception);

        self::assertStringNotContainsString('hunter2', $output);
        self::assertStringNotContainsString('Access denied', $output);
        self::assertStringContainsString('DatabaseException', $output);
    }

    public function test_a_withheld_message_still_appears_in_debug(): void
    {
        $exception = DatabaseException::cannotConnect('reporting', 'mysql', new \RuntimeException('hunter2'));

        self::assertStringContainsString('hunter2', $this->handler(true)->renderCli($exception));
    }

    /**
     * A fatal between the kernel returning and the last byte has no Request
     * argument to work from, so the handler has to have been told.
     */
    public function test_the_remembered_request_decides_the_rendering(): void
    {
        $handler = $this->handler(false);
        $handler->serving(Request::create('GET', '/api/customers', [
            'headers' => ['Accept' => 'application/json'],
        ]));

        $response = $handler->toResponse(new \RuntimeException('boom'));

        self::assertInstanceOf(JsonResponse::class, $response, 'a program must not be sent an HTML page');
    }

    public function test_an_explicit_request_wins_over_the_remembered_one(): void
    {
        $handler = $this->handler(false);
        $handler->serving(Request::create('GET', '/api', ['headers' => ['Accept' => 'application/json']]));

        $response = $handler->toResponse(
            new \RuntimeException('boom'),
            Request::create('GET', '/page', ['headers' => ['Accept' => 'text/html']]),
        );

        self::assertSame('text/html; charset=UTF-8', $response->contentType());
    }

    // ---- taking over from PHP -----------------------------------------------

    /**
     * The single most security-relevant line in this phase.
     *
     * With display_errors left on, PHP writes a warning -- complete with the
     * absolute path of the file that raised it -- straight into the response
     * body, above the doctype, before any framework code runs and with no way
     * to intercept it afterwards. "Never expose filesystem paths in production"
     * is not achievable without switching it off.
     */
    public function test_registering_stops_php_printing_on_its_own_account(): void
    {
        $before = \ini_get('display_errors');

        try {
            $this->handler(false)->register();

            self::assertSame('0', \ini_get('display_errors'));
            self::assertSame(\E_ALL, \error_reporting(), 'the handler still needs to see everything');
        } finally {
            \restore_error_handler();
            \restore_exception_handler();
            @\ini_set('display_errors', $before === false ? '1' : $before);
        }
    }

    /**
     * Debug too. A fatal is printed by PHP before the shutdown handler runs, so
     * with display_errors on the debug page came after PHP's message, or --
     * once those bytes had gone out -- not at all. The framework renders every
     * error; PHP displaying it as well can only get in the way.
     */
    public function test_debug_mode_keeps_php_quiet_too(): void
    {
        $handler = $this->handler(true);

        try {
            $handler->register();

            self::assertSame('0', \ini_get('display_errors'));
            self::assertSame('0', \ini_get('display_startup_errors'));
        } finally {
            $handler->unregister();
        }
    }

    /** For a caller that switched handle_errors off after Bootstrap registered. */
    public function test_unregistering_gives_php_its_handlers_back(): void
    {
        $before = \ini_get('display_errors');
        $previousError = static fn(): bool => false;
        $previousException = static function (\Throwable $e): void {};

        \set_error_handler($previousError);
        \set_exception_handler($previousException);

        try {
            $handler = $this->handler(false);
            $handler->register();

            self::assertTrue($handler->isRegistered());

            $handler->unregister();

            self::assertFalse($handler->isRegistered());
            self::assertSame($previousError, \set_error_handler(null), 'the error handler from before');
            self::assertSame($previousException, \set_exception_handler(null), 'the exception handler from before');
            self::assertSame($before, \ini_get('display_errors'));
        } finally {
            \restore_error_handler();
            \restore_error_handler();
            \restore_exception_handler();
            \restore_exception_handler();
        }
    }

    // ---- rendering an uncaught error ------------------------------------------

    /**
     * Run $render with a capture buffer at the handler's floor and stale output
     * above it, as a request that printed something before failing would have.
     *
     * @param \Closure(ErrorHandler): void $render
     */
    private function captured(bool $debug, \Closure $render, ?HookEngine $hooks = null): string
    {
        \ob_start();
        $handler = new ErrorHandler(
            new Config(['app' => ['debug' => $debug]]),
            $hooks ?? $this->hooks,
            outputFloor: \ob_get_level(),
            // As in a web request, where nothing has gone out yet. The real
            // headers_sent() is true here once PHPUnit has printed anything,
            // which made these tests pass or fail by what ran before them.
            headersSent: static fn(): bool => false,
        );

        \ob_start();
        echo 'STALE-OUTPUT';
        \ob_start();
        echo 'MORE-STALE-OUTPUT';

        $render($handler);

        return (string) \ob_get_clean();
    }

    /** A stray echo before the failure must not come out ahead of the page. */
    public function test_an_uncaught_error_discards_what_was_printed_before_it(): void
    {
        $page = $this->captured(false, static function (ErrorHandler $handler): void {
            $handler->renderForBrowser(new \RuntimeException('boom'));
        });

        self::assertStringNotContainsString('STALE-OUTPUT', $page);
        self::assertStringStartsWith('<!DOCTYPE html>', $page);
    }

    /**
     * The kernel path: an exception caught and rendered by toResponse() while
     * the handler owns the process. The page it is sent with must not have the
     * handler's echo in front of it either.
     */
    public function test_a_registered_handler_discards_stale_output_when_it_builds_a_response(): void
    {
        $page = $this->captured(false, static function (ErrorHandler $handler): void {
            $handler->register();

            try {
                $handler->toResponse(new \RuntimeException('boom'))->send();
            } finally {
                $handler->unregister();
            }
        });

        self::assertStringNotContainsString('STALE-OUTPUT', $page);
        self::assertStringStartsWith('<!DOCTYPE html>', $page);
    }

    /** Not registered -- a test, a caller of its own -- the caller's buffers are left alone. */
    public function test_an_unregistered_handler_leaves_the_callers_buffers_alone(): void
    {
        $page = $this->captured(false, static function (ErrorHandler $handler): void {
            $handler->toResponse(new \RuntimeException('boom'));
            echo \ob_get_level() > 0 ? '|still-buffered' : '';
            \ob_end_flush();
            \ob_end_flush();
        });

        self::assertStringContainsString('STALE-OUTPUT', $page);
        self::assertStringContainsString('|still-buffered', $page);
    }

    /**
     * The error page itself failing -- here, a listener on error.reported that
     * throws -- still ends in a page, never in PHP's own message.
     */
    public function test_an_error_page_that_fails_still_ends_in_a_plain_500(): void
    {
        $hooks = new HookEngine();
        $hooks->add('error.reported', static function (): void {
            throw new \LogicException('the reporter broke');
        });

        $render = static function (ErrorHandler $handler): void {
            $handler->renderForBrowser(new \RuntimeException('the original'));
        };

        $production = $this->captured(false, $render, $hooks);

        self::assertStringContainsString('500 Internal Server Error', $production);
        self::assertStringNotContainsString('the original', $production, 'production names neither');
        self::assertStringNotContainsString('STALE-OUTPUT', $production);

        $debug = $this->captured(true, $render, $hooks);

        self::assertStringContainsString('RuntimeException', $debug);
        self::assertStringContainsString('the original', $debug);
        self::assertStringContainsString('LogicException', $debug);
        self::assertStringContainsString('the reporter broke', $debug);
    }

    /** Escaped like every other page: a message can carry request data. */
    public function test_the_plain_500_escapes_what_it_names(): void
    {
        $hooks = new HookEngine();
        $hooks->add('error.reported', static function (): void {
            throw new \LogicException('<script>alert(2)</script>');
        });

        $page = $this->captured(true, static function (ErrorHandler $handler): void {
            $handler->renderForBrowser(new \RuntimeException('<img src=x onerror=alert(1)>'));
        }, $hooks);

        self::assertStringNotContainsString('<script>', $page);
        self::assertStringNotContainsString('<img', $page);
        self::assertStringContainsString('&lt;script&gt;', $page);
    }

    /**
     * Headroom for rendering a fatal.
     *
     * When PHP hits the memory limit, every later allocation fails -- including
     * the ones the shutdown handler needs to build a page. The reserve exists
     * to be given back at that moment, which is why the release is a method
     * rather than a line nothing can observe.
     */
    public function test_memory_is_reserved_for_rendering_a_fatal_and_given_back(): void
    {
        $handler = $this->handler(false);

        self::assertFalse($handler->holdsReserve(), 'nothing is held before registering');

        try {
            $handler->register();

            self::assertTrue($handler->holdsReserve());

            $handler->releaseReserve();

            self::assertFalse($handler->holdsReserve());
        } finally {
            \restore_error_handler();
            \restore_exception_handler();
            @\ini_set('display_errors', '1');
        }
    }

    // ---- reporting ----------------------------------------------------------

    /**
     * The seam the logging phase attaches to.
     *
     * A logger is a listener rather than a dependency, which is what keeps
     * recording an error independent of rendering it.
     */
    public function test_every_handled_error_is_announced(): void
    {
        $seen = [];

        $this->hooks->add('error.reported', static function (\Throwable $e, ErrorContext $context) use (&$seen): void {
            $seen[] = [$e::class, $context];
        });

        $handler = $this->handler(false);
        $handler->toResponse(HttpException::notFound('/nope'));
        $handler->renderCli(new \RuntimeException('boom'));

        self::assertSame(
            [[HttpException::class, ErrorContext::Browser], [\RuntimeException::class, ErrorContext::Console]],
            $seen,
        );
    }

    public function test_the_announcement_carries_the_throwable_itself(): void
    {
        $caught = null;

        $this->hooks->add('error.reported', static function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $thrown = new \RuntimeException('boom', 0, new \LogicException('the real cause'));
        $this->handler(false)->toResponse($thrown);

        self::assertSame($thrown, $caught, 'a listener that wants the previous exception must be able to reach it');
    }

    public function test_an_api_error_is_reported_as_one(): void
    {
        $context = null;

        $this->hooks->add('error.reported', static function (\Throwable $e, ErrorContext $seen) use (&$context): void {
            $context = $seen;
        });

        $this->handler(false)->toResponse(
            new \RuntimeException('boom'),
            Request::create('GET', '/api', ['headers' => ['Accept' => 'application/json']]),
        );

        self::assertSame(ErrorContext::Api, $context);
    }
}
