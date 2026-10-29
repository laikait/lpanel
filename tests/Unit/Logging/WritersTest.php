<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Engine\Logging\Level;
use App\Engine\Logging\LineFormatter;
use App\Engine\Logging\LoggingException;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\Writers\FileWriter;
use App\Engine\Logging\Writers\StreamWriter;
use App\Tests\Support\TestCase;

/**
 * The three destinations that need nothing installed.
 *
 * Database and remote writers are deliberately absent -- one needs a schema and
 * a migration runner that do not exist, the other needs an HTTP client that
 * does not exist. LogWriter is three methods, so either is a small class in an
 * application that wants one.
 */
final class WritersTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/logs-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);

        parent::tearDown();
    }

    private function record(Level $level = Level::Error, string $message = 'Payment declined'): LogRecord
    {
        return new LogRecord($level, $message, ['order' => 41], 'billing', \microtime(true));
    }

    // ---- the formatter ---------------------------------------------------------

    public function test_a_line_carries_the_time_level_channel_message_and_context(): void
    {
        $line = (new LineFormatter())->format($this->record());

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}/', $line);
        self::assertStringContainsString('ERROR', $line);
        self::assertStringContainsString('[billing]', $line);
        self::assertStringContainsString('Payment declined', $line);
        self::assertStringContainsString('{"order":41}', $line);
    }

    /**
     * One line per record, always.
     *
     * A multi-line entry breaks every tool that reads logs a line at a time,
     * and a message containing a newline could otherwise forge a second entry.
     */
    public function test_a_newline_in_a_message_cannot_forge_a_second_entry(): void
    {
        $line = (new LineFormatter())->format(
            new LogRecord(Level::Error, "first\nERROR [app] forged", [], 'app', \microtime(true)),
        );

        self::assertSame(1, \substr_count($line, "\n") + 1, 'the formatted line must be one line');
        self::assertStringNotContainsString("\n", $line);
        self::assertStringContainsString('\\n', $line);
    }

    public function test_an_empty_context_is_left_off_entirely(): void
    {
        $line = (new LineFormatter())->format(new LogRecord(Level::Info, 'plain'));

        self::assertStringEndsWith('plain', $line);
    }

    /** A system logger adds its own timestamp and severity. */
    public function test_the_prefix_can_be_left_to_the_destination(): void
    {
        $line = (new LineFormatter(includePrefix: false))->format($this->record());

        self::assertStringStartsWith('[billing] Payment declined', $line);
        self::assertStringNotContainsString('ERROR', $line);
    }

    // ---- the stream writer -------------------------------------------------------

    public function test_a_stream_writer_appends_lines(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $writer = new StreamWriter($stream);
        $writer->write($this->record());
        $writer->write($this->record(Level::Info, 'second'));

        \rewind($stream);
        $contents = (string) \stream_get_contents($stream);
        \fclose($stream);

        self::assertSame(2, \substr_count($contents, \PHP_EOL));
        self::assertStringContainsString('Payment declined', $contents);
        self::assertStringContainsString('second', $contents);
    }

    public function test_a_stream_writer_honours_its_own_threshold(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $writer = new StreamWriter($stream, Level::Warning);

        self::assertTrue($writer->accepts($this->record(Level::Error)));
        self::assertFalse($writer->accepts($this->record(Level::Info)));

        \fclose($stream);
    }

    public function test_a_stream_writer_refuses_something_that_is_not_a_stream(): void
    {
        $this->expectException(LoggingException::class);

        // @phpstan-ignore argument.type (the point of the test is the wrong type)
        new StreamWriter('not a stream');
    }

    public function test_a_closed_stream_is_reported_rather_than_ignored(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $writer = new StreamWriter($stream);
        \fclose($stream);

        $this->expectException(LoggingException::class);

        $writer->write($this->record());
    }

    // ---- the file writer -----------------------------------------------------------

    public function test_a_file_writer_creates_its_directory_and_a_dated_file(): void
    {
        $writer = new FileWriter($this->directory);
        $record = $this->record();

        $writer->write($record);

        $path = $writer->pathFor($record);

        self::assertFileExists($path);
        self::assertStringContainsString($record->at()->format('Y-m-d'), $path);
        self::assertStringContainsString('Payment declined', (string) \file_get_contents($path));
    }

    public function test_a_file_writer_appends_rather_than_truncating(): void
    {
        $writer = new FileWriter($this->directory);

        $writer->write($this->record(Level::Info, 'first'));
        $writer->write($this->record(Level::Info, 'second'));

        $contents = (string) \file_get_contents($writer->pathFor($this->record()));

        self::assertStringContainsString('first', $contents);
        self::assertStringContainsString('second', $contents);
    }

    public function test_a_second_writer_does_not_lose_what_the_first_wrote(): void
    {
        (new FileWriter($this->directory))->write($this->record(Level::Info, 'first'));
        (new FileWriter($this->directory))->write($this->record(Level::Info, 'second'));

        $contents = (string) \file_get_contents((new FileWriter($this->directory))->pathFor($this->record()));

        self::assertStringContainsString('first', $contents);
        self::assertStringContainsString('second', $contents);
    }

    /**
     * Retention deletes, so it is bounded hard: only this directory, only this
     * writer's own naming, and only when a positive number of days is set.
     */
    public function test_retention_deletes_only_old_files_of_its_own(): void
    {
        \mkdir($this->directory, 0o777, true);

        $old = $this->directory . '/app-2020-01-01.log';
        $foreign = $this->directory . '/audit-2020-01-01.log';
        $unrelated = $this->directory . '/notes.txt';

        foreach ([$old, $foreign, $unrelated] as $file) {
            \file_put_contents($file, 'x');
        }

        (new FileWriter($this->directory, retentionDays: 7))->write($this->record());

        self::assertFileDoesNotExist($old);
        self::assertFileExists($foreign, 'another prefix is somebody else\'s file');
        self::assertFileExists($unrelated, 'anything that is not a dated log file is left alone');
    }

    public function test_retention_of_zero_keeps_everything(): void
    {
        \mkdir($this->directory, 0o777, true);

        $old = $this->directory . '/app-2020-01-01.log';
        \file_put_contents($old, 'x');

        (new FileWriter($this->directory))->write($this->record());

        self::assertFileExists($old, 'deleting an audit trail because a default said so is the worse failure');
    }

    public function test_a_file_writer_that_cannot_write_says_so(): void
    {
        // A file where the directory should be, so creating it must fail.
        $path = \sys_get_temp_dir() . '/logs-blocked-' . \bin2hex(\random_bytes(4));
        \file_put_contents($path, 'in the way');

        try {
            $this->expectException(LoggingException::class);

            (new FileWriter($path . '/inside'))->write($this->record());
        } finally {
            @\unlink($path);
        }
    }

    /** The manager retires it rather than letting it break the request. */
    public function test_a_broken_file_writer_is_survivable(): void
    {
        $path = \sys_get_temp_dir() . '/logs-blocked-' . \bin2hex(\random_bytes(4));
        \file_put_contents($path, 'in the way');

        $logs = (new LogManager())->add(new FileWriter($path . '/inside'));
        $logs->channel()->error('the request still worked');

        @\unlink($path);

        self::assertFalse($logs->isHealthy());
        self::assertSame([], $logs->writers());
    }
}
