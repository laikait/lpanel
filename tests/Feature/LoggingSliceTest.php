<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Error\ErrorContext;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Logging\ErrorLog;
use App\Engine\Logging\Level;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\Writers\FileWriter;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Support\TestCase;

/**
 * Logging through a real application.
 *
 * The point being proved is the one the specification insists on: logging is
 * independent from error rendering. Nothing in engine/Error knows this layer
 * exists -- ErrorLog is an ordinary listener on error.reported, registered in
 * exactly the way a module would register one.
 */
final class LoggingSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): \App\Engine\Core\Application
    {
        return $this->fixtureApplication($config)->boot();
    }

    // ---- the bridge -------------------------------------------------------------

    public function test_a_failed_request_reaches_the_log(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $app->handle(Request::create('GET', '/kaboom'));

        self::assertCount(1, $writer->records);

        $record = $writer->records[0];

        self::assertSame('error', $record->channel);
        self::assertSame(Level::Error, $record->level);
    }

    public function test_a_successful_request_logs_nothing(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $app->handle(Request::create('GET', '/items'));

        self::assertSame([], $writer->records);
    }

    /**
     * A scanner sweeping for /wp-admin must not page anybody at three in the
     * morning, so a 404 is a notice and a 500 is an error.
     */
    public function test_a_missing_page_is_not_an_incident(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $app->handle(Request::create('GET', '/nope'));

        self::assertCount(1, $writer->records);

        $record = $writer->records[0];

        self::assertSame(Level::Notice, $record->level);
        self::assertFalse($record->level->isFailure());
    }

    public function test_the_record_carries_the_exception_and_the_audience(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $app->handle(Request::create('GET', '/kaboom', ['headers' => ['Accept' => 'application/json']]));

        self::assertCount(1, $writer->records);

        $context = $writer->records[0]->context;

        self::assertIsArray($context['exception']);
        self::assertSame(\RuntimeException::class, $context['exception']['class']);
        self::assertSame(ErrorContext::Api->value, $context['audience']);
    }

    /**
     * The log is where a withheld message belongs.
     *
     * A response must not repeat "dsn=secret-hunter2"; a log file exists so
     * that somebody can find out what actually happened.
     */
    public function test_the_log_keeps_what_the_response_withholds(): void
    {
        $app = $this->app(['app' => ['debug' => false]]);
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $response = $app->handle(Request::create('GET', '/kaboom'));

        self::assertCount(1, $writer->records);

        $context = $writer->records[0]->context;

        self::assertStringNotContainsString('secret-hunter2', $response->body());
        self::assertIsArray($context['exception']);
        self::assertStringContainsString('secret-hunter2', $context['exception']['message']);
    }

    // ---- independence -------------------------------------------------------------

    /**
     * Removing the listener removes logging, and nothing else changes. That is
     * what "independent from error rendering" has to mean if it means anything.
     */
    public function test_removing_the_listener_stops_logging_and_nothing_else(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $hooks = $app->container()->get(HookEngine::class);

        foreach ($hooks->listeners('error.reported') as $listener) {
            $hooks->remove('error.reported', $listener['callback']);
        }

        $response = $app->handle(Request::create('GET', '/kaboom'));

        self::assertSame(500, $response->status(), 'the error still renders');
        self::assertSame([], $writer->records, 'and nothing is logged');
    }

    /** A module can listen to the same hook without displacing the framework's listener. */
    public function test_an_application_can_listen_alongside(): void
    {
        $app = $this->app();
        $writer = new CollectingWriter();
        $app->container()->get(LogManager::class)->add($writer);

        $seen = 0;
        $app->container()->get(HookEngine::class)->add(
            'error.reported',
            static function () use (&$seen): void {
                ++$seen;
            },
        );

        $app->handle(Request::create('GET', '/kaboom'));

        self::assertSame(1, $seen);
        self::assertCount(1, $writer->records);
    }

    // ---- configuration ---------------------------------------------------------------

    public function test_nothing_is_attached_by_default(): void
    {
        self::assertSame(
            [],
            $this->app()->container()->get(LogManager::class)->writers(),
            'a framework that writes files nobody asked for fills somebody else\'s disk',
        );
    }

    public function test_a_configured_writer_is_attached(): void
    {
        $logs = $this->app(['logging' => ['writers' => ['stderr'], 'level' => 'warning']])
            ->container()
            ->get(LogManager::class);

        self::assertCount(1, $logs->writers());
        self::assertSame(Level::Warning, $logs->minimum());
        self::assertStringContainsString('stderr', $logs->writers()[0]->describe());
    }

    /** A typo in a deployment's configuration must not stop the application. */
    public function test_an_unknown_writer_name_is_ignored(): void
    {
        $logs = $this->app(['logging' => ['writers' => ['carrier-pigeon', 'stderr']]])
            ->container()
            ->get(LogManager::class);

        self::assertCount(1, $logs->writers());
    }

    public function test_an_unknown_level_falls_back_rather_than_failing(): void
    {
        $logs = $this->app(['app' => ['debug' => false], 'logging' => ['level' => 'wraning']])
            ->container()
            ->get(LogManager::class);

        self::assertSame(Level::Info, $logs->minimum());
    }

    public function test_the_file_writer_writes_where_it_says_it_will(): void
    {
        $directory = $this->basePath('system/Logs');

        foreach (\glob($directory . '/probe-*.log') ?: [] as $file) {
            @\unlink($file);
        }

        $app = $this->app([
            'logging' => ['writers' => ['file'], 'level' => 'debug', 'file' => ['prefix' => 'probe']],
        ]);

        $app->container()->get(LogManager::class)->channel()->error('written by a test');

        $written = \glob($directory . '/probe-*.log') ?: [];

        try {
            self::assertCount(1, $written);
            self::assertStringContainsString('written by a test', (string) \file_get_contents($written[0]));
        } finally {
            foreach ($written as $file) {
                @\unlink($file);
            }
        }
    }

    // ---- the injected logger --------------------------------------------------------

    public function test_a_handler_can_take_a_logger_by_injection(): void
    {
        $logger = $this->app()->container()->get(\App\Engine\Logging\Logger::class);

        self::assertSame(LogManager::DEFAULT_CHANNEL, $logger->channel);
    }

    public function test_the_error_channel_is_separate_from_the_application_channel(): void
    {
        self::assertNotSame(ErrorLog::CHANNEL, LogManager::DEFAULT_CHANNEL);
    }

    public function test_the_file_writer_is_not_a_default(): void
    {
        self::assertSame(
            [],
            $this->app()->container()->get(LogManager::class)->writers(),
        );

        self::assertTrue(\class_exists(FileWriter::class), 'it exists, it is just not switched on');
    }
}
