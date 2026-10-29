<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cli;

use App\Engine\Cli\Output;
use App\Tests\Support\TestCase;

/**
 * Where a command writes.
 *
 * The two-stream split is the part that matters. A command whose result is
 * piped somewhere must not have a warning mixed into the pipe, and a command
 * whose errors are redirected must still be able to print its result.
 */
final class OutputTest extends TestCase
{
    /** @var list<resource> */
    private array $streams = [];

    /** @return resource */
    private function stream(): mixed
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $this->streams[] = $stream;

        return $stream;
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        \rewind($stream);

        return (string) \stream_get_contents($stream);
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }

        parent::tearDown();
    }

    public function test_a_result_goes_to_standard_output(): void
    {
        $out = $this->stream();
        $err = $this->stream();

        (new Output($out, $err))->line('the result');

        self::assertStringContainsString('the result', $this->read($out));
        self::assertSame('', $this->read($err));
    }

    public function test_a_complaint_goes_to_the_error_stream(): void
    {
        $out = $this->stream();
        $err = $this->stream();

        $output = new Output($out, $err);
        $output->error('it broke');
        $output->warning('careful');

        self::assertSame('', $this->read($out), 'nothing about a failure belongs in what a pipe carries');
        self::assertStringContainsString('it broke', $this->read($err));
        self::assertStringContainsString('careful', $this->read($err));
    }

    /** One stream given means everything lands there, which is what a log wants. */
    public function test_one_stream_receives_everything(): void
    {
        $stream = $this->stream();

        $output = new Output($stream);
        $output->line('the result');
        $output->error('it broke');

        $text = $this->read($stream);

        self::assertStringContainsString('the result', $text);
        self::assertStringContainsString('it broke', $text);
    }

    public function test_write_does_not_add_a_newline_and_line_does(): void
    {
        $stream = $this->stream();

        $output = new Output($stream);
        $output->write('a');
        $output->write('b');
        $output->line('c');

        self::assertSame('abc' . \PHP_EOL, $this->read($stream));
    }

    public function test_pairs_are_aligned_on_the_longest_label(): void
    {
        $stream = $this->stream();

        (new Output($stream))->pairs(['PHP' => '8.3', 'Environment' => 'testing']);

        self::assertStringContainsString('  PHP          8.3', $this->read($stream));
    }

    public function test_a_table_pads_every_column_to_its_widest_cell(): void
    {
        $stream = $this->stream();

        (new Output($stream))->table(['ID', 'NAME'], [['1', 'Ada'], ['200', 'Katherine']]);

        $lines = \explode(\PHP_EOL, \trim($this->read($stream)));

        self::assertCount(3, $lines);
        self::assertStringContainsString('ID   NAME', $lines[0]);
        self::assertStringContainsString('1    Ada', $lines[1]);
        self::assertStringContainsString('200  Katherine', $lines[2]);
    }

    public function test_a_table_row_is_not_padded_past_its_last_cell(): void
    {
        $stream = $this->stream();

        (new Output($stream))->table(['ID', 'NAME'], [['1', 'Ada']]);

        $lines = \explode(\PHP_EOL, \trim($this->read($stream)));

        self::assertSame('  1   Ada', $lines[1], 'trailing padding would show up in a diff');
    }

    /** Redirected output must carry no escape codes, whatever the method. */
    public function test_nothing_is_coloured_when_no_terminal_is_attached(): void
    {
        $stream = $this->stream();

        $output = new Output($stream);
        $output->heading('Heading');
        $output->success('Good');
        $output->error('Bad');
        $output->warning('Careful');

        self::assertStringNotContainsString("\033[", $this->read($stream));
    }
}
