<?php

declare(strict_types=1);

namespace App\Engine\Error;

use App\Engine\Config\Config;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Http\Response;

/**
 * One place where anything thrown becomes something the caller can be shown.
 *
 * Centralised means centralised: a handler's validation failure, a route that
 * did not match, a PHP warning, an uncaught exception and a fatal that killed
 * the process all arrive here and leave as the same document. What differs is
 * the rendering, and the rendering is chosen by who is reading -- see
 * ErrorContext.
 *
 *   Browser   an HTML page, the application's own if it has one
 *   Api       the JSON error document
 *   Console   two lines and, in debug, a trace
 *
 * Production versus development is a separate axis and decides how much is
 * said, never who says it.
 *
 * Nothing here logs, and there is no logger interface to implement. Every
 * handled error fires the error.reported hook with the throwable and its
 * context, which is the seam the logging phase attaches to: a logger is a
 * listener, not a dependency of this class. That is what keeps error rendering
 * and error recording independent -- an application can record errors without
 * changing how they are shown, and change how they are shown without touching
 * what is recorded.
 */
final class ErrorHandler
{
    /**
     * Memory held back so that a fatal out-of-memory can still be rendered.
     *
     * When PHP hits the memory limit every later allocation fails, including
     * the ones the shutdown handler needs to build a page. Releasing this at the
     * top of that handler buys back enough room to finish, and without it an
     * exhausted process answers with a blank page instead of a 500.
     *
     * The size is measured rather than guessed. Rendering a fatal costs more
     * than it looks: building the ErrorException captures a backtrace, the
     * document and the page are strings, and in debug the trace is exploded
     * into an array. At 32KB the shutdown handler ran out of memory a second
     * time and produced nothing at all; 64KB was enough for production and
     * 256KB leaves room for a debug trace on a deep stack. A quarter of a
     * megabyte of a process's lifetime is a cheap insurance premium against the
     * failure mode that is hardest to diagnose -- no output whatsoever.
     */
    public const RESERVED_MEMORY = 262144;

    /**
     * Extra memory_limit granted to render an out-of-memory fatal.
     *
     * The reserve above is enough for the built-in page; the debug page is not
     * built-in, and Whoops reads source files and formats every frame. Without
     * this an exhausted process in debug gave PHP's own message and a 200.
     */
    public const FATAL_MEMORY_HEADROOM = 67108864;

    /** Seconds granted to render a fatal that was a timeout. */
    public const FATAL_TIME_HEADROOM = 30;

    private bool $registered = false;

    private ?string $reserve = null;

    private ?Request $request = null;

    /** display_errors as it was before register(), for unregister(). */
    private string|false $previousDisplay = false;

    /**
     * @param int $outputFloor the output-buffer level an uncaught error clears
     *                         down to before rendering. 0 in production: every
     *                         buffer, so nothing printed before the error -- a
     *                         stray echo, half a page -- comes out in front of
     *                         it. A test passes ob_get_level(), so the test
     *                         runner's own buffer survives.
     * @param ?\Closure(): bool $headersSent whether the response has already
     *                         begun, which decides whether to render a page
     *                         at all; null asks headers_sent(). A test answers
     *                         for itself, because in the CLI headers_sent() is
     *                         true as soon as anything reached stdout -- a
     *                         deprecation another test file triggered, say --
     *                         which made the browser path depend on what ran
     *                         before the test. Calls to header() still ask PHP.
     */
    public function __construct(
        private Config $config,
        private HookEngine $hooks = new HookEngine(),
        private ErrorPage $page = new ErrorPage(),
        private readonly int $outputFloor = 0,
        private readonly ?\Closure $headersSent = null,
    ) {}

    /**
     * Hand over the real configuration, hooks and page.
     *
     * Bootstrap registers this handler before it has read the configuration,
     * so that a config file that throws is still rendered by the framework and
     * not by PHP -- which means it has to start with a provisional Config (debug
     * from APP_DEBUG) and a page that knows no templates yet. This is where it
     * gets the real ones, once they exist.
     */
    public function configure(Config $config, HookEngine $hooks, ErrorPage $page): void
    {
        $this->config = $config;
        $this->hooks = $hooks;
        $this->page = $page;

        if ($this->registered) {
            $this->silencePhpsOwnOutput();
        }
    }

    /**
     * Take over PHP's own error reporting.
     *
     * Four things are installed. PHP's own output is silenced, because the
     * framework renders every error and PHP printing one as well can only
     * print it twice, or print it first and break the page. Errors become
     * ErrorException so they can be caught like anything else. Uncaught
     * exceptions get rendered rather than printing a trace. And a shutdown
     * handler catches the fatals that none of the first three can see.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $this->reserve = \str_repeat('x', self::RESERVED_MEMORY);
        $this->previousDisplay = \ini_get('display_errors');

        $this->silencePhpsOwnOutput();

        \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect the configured error_reporting level: a suppressed notice
            // should stay suppressed rather than become a fatal exception.
            if ((\error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        \set_exception_handler(function (\Throwable $e): void {
            $this->renderUncaught($e);
        });

        \register_shutdown_function(function (): void {
            // A shutdown function cannot be removed, so unregister() leaves
            // this one in place and switched off.
            if (!$this->registered) {
                return;
            }

            $this->releaseReserve();

            $error = \error_get_last();

            if ($error === null) {
                return;
            }

            $fatal = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;

            if (($error['type'] & $fatal) === 0) {
                return;
            }

            $this->grantHeadroom($error['message']);

            // A fatal bypasses the error and exception handlers entirely, so
            // without this the client gets a blank 200.
            $this->renderUncaught(new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line'],
            ));
        });
    }

    /**
     * Give PHP's error handling back: for a caller that turned
     * app.handle_errors off after Bootstrap had already registered this.
     *
     * The error and exception handlers are restored and display_errors is put
     * back as it was. The shutdown function cannot be removed, so it stays and
     * does nothing.
     */
    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        $this->registered = false;
        $this->reserve = null;

        \restore_error_handler();
        \restore_exception_handler();

        if ($this->previousDisplay !== false) {
            @\ini_set('display_errors', $this->previousDisplay);
        }
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * Whether the rendering headroom is still held.
     *
     * Public so that the mechanism is observable rather than a property nothing
     * ever looks at. "We reserved memory" and "the reserve is given back before
     * the page is built" are two different claims, and only the second one is
     * worth anything.
     */
    public function holdsReserve(): bool
    {
        return $this->reserve !== null;
    }

    /** Give the headroom back, so that a fatal still has room to render. */
    public function releaseReserve(): void
    {
        $this->reserve = null;
    }

    /**
     * Remember the request being served.
     *
     * The one piece of mutable state in this class, and it is here because
     * PHP's exception and shutdown handlers take no arguments: when a fatal
     * happens between the kernel returning and the response being sent, this is
     * the only way to know whether the thing waiting for an answer is a browser
     * or a program. Without it, an API client receives an HTML page.
     */
    public function serving(Request $request): void
    {
        $this->request = $request;
    }

    public function toResponse(\Throwable $e, ?Request $request = null): Response
    {
        // Output a handler printed before it failed -- an echo, a var_dump --
        // would otherwise be sent ahead of this response and break it: a
        // debug page with text above the doctype, an API error that is not
        // JSON. Only when this handler owns the process: a test that builds
        // responses from it keeps its own buffers.
        if ($this->registered) {
            $this->discardOutput();
        }

        $request ??= $this->request;
        $context = $request === null ? ErrorContext::Browser : ErrorContext::of($request);

        $this->report($e, $context);

        $document = ErrorDocument::fromThrowable($e, $this->debug(), $context);
        $headers = $e instanceof HttpException ? $e->headers() : [];

        // One document, two renderings. Before this, a validation failure from
        // a handler and an uncaught exception from the kernel were built by two
        // different pieces of code that agreed on a shape by coincidence.
        if ($context->isMachine()) {
            return $document->toResponse($headers);
        }

        return (new Response($this->page->render($document, $request, $e), $document->status, $headers))
            ->withContentType('text/html');
    }

    /**
     * The same error, for a terminal.
     *
     * Built from an ErrorDocument rather than from the exception directly, so
     * that the rule about which messages are safe to show is stated once and
     * applies everywhere. Without this the console was the one context that
     * printed an arbitrary exception message in production -- and "dsn=..." is
     * exactly the kind of message a data-layer exception carries.
     *
     * The class name survives outside debug mode. A path, a trace or a message
     * can carry a secret; a class name is a fact about the code, and the person
     * reading a console error is running the code already.
     */
    public function renderCli(\Throwable $e): string
    {
        $this->report($e, ErrorContext::Console);

        $document = ErrorDocument::fromThrowable($e, $this->debug(), ErrorContext::Console);

        $lines = [
            '',
            \sprintf('  %s', $e::class),
            \sprintf('  %s', $document->message),
        ];

        if ($this->debug()) {
            $lines[] = '';
            $lines[] = \sprintf('  at %s:%d', $e->getFile(), $e->getLine());
            $lines[] = '';
            $lines[] = $e->getTraceAsString();

            return \implode(\PHP_EOL, $lines) . \PHP_EOL;
        }

        // Withholding the message is right, and leaving the operator with
        // nowhere to go is not. A declaration mistake -- two modules claiming
        // one command name, a route that cannot compile -- fails here long
        // before the console kernel gets a chance to explain it, and the person
        // reading this is the person who can turn debug on.
        $lines[] = '';
        $lines[] = '  Set APP_DEBUG=1 for the full message and a stack trace.';

        return \implode(\PHP_EOL, $lines) . \PHP_EOL;
    }

    /**
     * Announce that an error was handled.
     *
     * Fired from inside the renderers rather than left to their callers, so
     * that nothing which produces an error body can forget to report it. The
     * argument is the throwable itself, not a formatted string: a listener that
     * wants the class, the previous exception or the trace should not have to
     * parse them back out of a sentence.
     */
    public function report(\Throwable $e, ErrorContext $context): void
    {
        $this->hooks->do('error.reported', $e, $context, $this->request);
    }

    private function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    /**
     * Stop PHP printing anything on its own account -- in debug as well.
     *
     * In production it is a leak: display_errors writes a warning, complete
     * with the absolute path of the file that raised it, directly into the
     * response body, above the doctype, impossible to intercept afterwards.
     *
     * In debug it is the reason the debug page went missing. A fatal -- out of
     * memory, a timeout -- is printed by PHP before the shutdown handler runs,
     * so the page either came after PHP's message or, once those bytes had
     * gone out, not at all. The framework renders every error, Whoops in
     * debug, so there is nothing PHP displaying one could add.
     *
     * error_reporting stays at E_ALL, because the handler above turns errors
     * into exceptions and needs to see them, and log_errors is left alone, so
     * PHP's own log still records everything.
     */
    private function silencePhpsOwnOutput(): void
    {
        \error_reporting(\E_ALL);

        @\ini_set('display_errors', '0');
        @\ini_set('display_startup_errors', '0');
    }

    /**
     * Room to render a fatal that ran out of memory or time.
     *
     * PHP lets a shutdown function raise both, and without it the process that
     * died of exhaustion dies again building the page about it.
     */
    private function grantHeadroom(string $message): void
    {
        if (\str_starts_with($message, 'Allowed memory size')) {
            $limit = \memory_get_usage(true) + self::FATAL_MEMORY_HEADROOM;
            @\ini_set('memory_limit', (string) $limit);
        }

        if (\str_starts_with($message, 'Maximum execution time')) {
            @\set_time_limit(self::FATAL_TIME_HEADROOM);
        }
    }

    private function renderUncaught(\Throwable $e): void
    {
        if (\PHP_SAPI === 'cli') {
            \fwrite(\STDERR, $this->renderCli($e));

            return;
        }

        $this->renderForBrowser($e);
    }

    /**
     * Send the error page for an uncaught throwable, and nothing before it.
     *
     * Whatever is still buffered -- a stray echo, half a page -- is discarded
     * first; a debug page with output in front of it is a broken page.
     *
     * Once a byte has actually gone out there is no status line left to set
     * and no headers to send, so a second half-rendered page on top of a
     * partial one would only make the wreckage harder to read. The error is
     * still reported, which is what a listener needs.
     *
     * Public so the browser path can be exercised from a test, which always
     * runs as "cli".
     */
    public function renderForBrowser(\Throwable $e): void
    {
        if ($this->headersSent()) {
            $this->report($e, ErrorContext::Browser);

            return;
        }

        $this->discardOutput();

        try {
            $this->toResponse($e, $this->request)->send();
        } catch (\Throwable $failure) {
            $this->emergency($e, $failure);
        }
    }

    /** See the constructor: headers_sent(), unless a test answers for it. */
    private function headersSent(): bool
    {
        return $this->headersSent !== null ? ($this->headersSent)() : \headers_sent();
    }

    /**
     * Throw away buffered output down to the floor, unless it already went out.
     *
     * What has been sent cannot be recalled; what is still buffered has not
     * reached anyone and is not part of the answer any more.
     */
    private function discardOutput(): void
    {
        if ($this->headersSent()) {
            return;
        }

        while (\ob_get_level() > $this->outputFloor && @\ob_end_clean()) {
            // One buffer at a time, down to the floor.
        }
    }

    /**
     * The page when rendering the error page itself failed.
     *
     * Nothing here can throw: no template, no Whoops, no configuration beyond
     * the debug flag. Without it a broken error page meant PHP's own message
     * again, or a blank 200. In debug both throwables are named, since the
     * second one is the bug to fix first.
     */
    private function emergency(\Throwable $original, \Throwable $failure): void
    {
        // Whatever the failed page managed to write goes too.
        $this->discardOutput();

        // PHP's own answer, not headersSent(): this is about whether header()
        // can still work, and a test that says "nothing has gone out" must not
        // make it try when something really has.
        if (!\headers_sent()) {
            \http_response_code(500);
            \header('Content-Type: text/html; charset=UTF-8');
        }

        $escape = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE);
        $describe = static fn(\Throwable $e): string => \sprintf(
            '<p><b>%s</b>: %s<br><small>%s:%d</small></p>',
            $escape($e::class),
            $escape($e->getMessage()),
            $escape($e->getFile()),
            $e->getLine(),
        );

        $debug = false;

        try {
            $debug = $this->debug();
        } catch (\Throwable) {
            // A configuration that cannot answer is not one to trust with a trace.
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>500 Internal Server Error</title></head>'
            . '<body><h1>500 Internal Server Error</h1>';

        if ($debug) {
            echo '<p>The error page itself failed while rendering this error:</p>', $describe($original),
            '<p>because of:</p>', $describe($failure);
        }

        echo '</body></html>';
    }
}
