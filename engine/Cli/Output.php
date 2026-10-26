<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * Where a command writes.
 *
 * Two streams, because the separation is what makes a command usable from a
 * script: `laika route:list | grep customers` must not have an error message
 * mixed into what it pipes, and `laika customer:sync 2>errors.log` must be
 * able to separate the two. Anything that is a message about the run goes to
 * the error stream; anything that is the result goes to standard output.
 *
 * An Output constructed with a single stream writes everything to it, which is
 * what a test wants and what a log file wants.
 *
 * Colour is applied only when a terminal is attached, so redirected output
 * never carries escape codes.
 */
final class Output
{
    /** @var resource|null */
    private mixed $stream;

    /** @var resource|null */
    private mixed $errorStream;

    /**
     * @param resource|null $stream
     * @param resource|null $errorStream defaults to $stream when one was given
     */
    public function __construct(mixed $stream = null, mixed $errorStream = null)
    {
        $this->stream = \is_resource($stream) ? $stream : self::standard('STDOUT', 'php://output');

        $this->errorStream = match (true) {
            \is_resource($errorStream) => $errorStream,
            \is_resource($stream) => $stream,
            default => self::standard('STDERR', 'php://stderr'),
        };
    }

    public function write(string $text): void
    {
        $this->writeTo($this->stream, $text);
    }

    public function line(string $text = ''): void
    {
        $this->write($text . \PHP_EOL);
    }

    public function heading(string $text): void
    {
        $this->line($this->paint($this->stream, $text, '1;36'));
    }

    public function success(string $text): void
    {
        $this->line($this->paint($this->stream, $text, '32'));
    }

    /** A message about the run rather than the result, so: the error stream. */
    public function error(string $text): void
    {
        $this->writeTo($this->errorStream, $this->paint($this->errorStream, $text, '31') . \PHP_EOL);
    }

    public function warning(string $text): void
    {
        $this->writeTo($this->errorStream, $this->paint($this->errorStream, $text, '33') . \PHP_EOL);
    }

    /** A blank line on the error stream, for spacing around a message. */
    public function errorLine(string $text = ''): void
    {
        $this->writeTo($this->errorStream, $text . \PHP_EOL);
    }

    /** @param array<string, string|int> $rows */
    public function pairs(array $rows): void
    {
        $width = 0;

        foreach (\array_keys($rows) as $label) {
            $width = \max($width, \strlen($label));
        }

        foreach ($rows as $label => $value) {
            $this->line(\sprintf('  %-' . $width . 's  %s', $label, $value));
        }
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = \array_map(\strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = \max($widths[$index] ?? 0, \strlen($cell));
            }
        }

        $render = function (array $cells) use ($widths): string {
            $parts = [];

            foreach ($cells as $index => $cell) {
                $parts[] = \sprintf('%-' . ($widths[$index] ?? 0) . 's', $cell);
            }

            return '  ' . \rtrim(\implode('  ', $parts));
        };

        $this->line($this->paint($this->stream, $render($headers), '1'));

        foreach ($rows as $row) {
            $this->line($render($row));
        }
    }

    /**
     * The process stream if it exists, otherwise an output wrapper.
     *
     * fopen() can fail, in which case writing simply does nothing rather than
     * taking the process down over console output.
     *
     * @return resource|null
     */
    private static function standard(string $constant, string $fallback): mixed
    {
        if (\defined($constant)) {
            /** @var resource $stream */
            $stream = \constant($constant);

            return $stream;
        }

        $opened = @\fopen($fallback, 'w');

        return $opened === false ? null : $opened;
    }

    /** @param resource|null $stream */
    private function writeTo(mixed $stream, string $text): void
    {
        if (\is_resource($stream)) {
            \fwrite($stream, $text);
        }
    }

    /**
     * Colour only when a terminal is actually attached; never in a pipe or a test.
     *
     * @param resource|null $stream
     */
    private function paint(mixed $stream, string $text, string $code): string
    {
        if (!\is_resource($stream) || !\function_exists('stream_isatty') || !@\stream_isatty($stream)) {
            return $text;
        }

        return "\033[" . $code . 'm' . $text . "\033[0m";
    }
}
