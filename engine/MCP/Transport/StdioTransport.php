<?php

declare(strict_types=1);

namespace App\Engine\MCP\Transport;

use App\Engine\MCP\McpServer;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Protocol\MessageParser;
use App\Engine\MCP\Protocol\ProtocolException;
use App\Engine\MCP\Protocol\Response;

/**
 * MCP over a process's standard streams: one JSON message per line in, one
 * JSON message per line out.
 *
 * **stdout carries protocol and nothing else.** A client reads every line as a
 * message, so a stray `echo` in a handler, a deprecation notice, a var_dump
 * left behind -- any of them breaks the connection. Everything a message's
 * handling prints is captured and discarded here, and reported so that it is
 * not lost either: diagnostics go to the report callback, which the console
 * sends to stderr, never to stdout.
 *
 * **One session for the life of the process**, authenticated once, before the
 * first line is read.
 *
 * **Lines are bounded.** A line longer than the parser's limit is read to its
 * end, discarded, and answered as an invalid request, so one oversized message
 * cannot become a memory problem or desynchronize the stream.
 *
 * **Ends cleanly** when the client closes its end of stdin. There is nothing
 * to flush and nothing to wait for: each answer is written and flushed before
 * the next line is read.
 */
final class StdioTransport
{
    /** @param (\Closure(string): void)|null $diagnose told about anything printed during a message */
    public function __construct(
        private readonly McpServer $server,
        private readonly int $maxBytes = MessageParser::DEFAULT_MAX_BYTES,
        private readonly ?\Closure $diagnose = null,
    ) {}

    /**
     * @param resource $input
     * @param resource $output
     *
     * @return int the number of messages read
     */
    public function run($input, $output, McpSession $session): int
    {
        $count = 0;

        while (($line = $this->readLine($input)) !== null) {
            ++$count;

            if ($line === false) {
                self::write($output, Response::error(null, ProtocolException::tooLarge($this->maxBytes)->error())->toJson());

                continue;
            }

            if (\trim($line) === '') {
                continue;
            }

            $stray = '';
            \ob_start();

            try {
                $answer = $this->server->handle($line, $session);
            } finally {
                $stray = (string) \ob_get_clean();
            }

            if ($stray !== '' && $this->diagnose !== null) {
                ($this->diagnose)(\sprintf('A handler printed %d bytes during an MCP message; they were not sent.', \strlen($stray)));
            }

            if ($answer !== null) {
                self::write($output, $answer);
            }
        }

        return $count;
    }

    /**
     * The next line without its newline; false for one that was too long (and
     * has been skipped); null at the end of input.
     *
     * @param resource $input
     */
    private function readLine($input): string|false|null
    {
        $line = \fgets($input, \max(2, $this->maxBytes + 2));

        if ($line === false) {
            return null;
        }

        if (\str_ends_with($line, "\n") || \feof($input)) {
            return \rtrim($line, "\r\n");
        }

        // Longer than allowed: read to the end of it and throw it away.
        while (!\feof($input)) {
            $rest = \fgets($input, 65536);

            if ($rest === false || \str_ends_with($rest, "\n")) {
                break;
            }
        }

        return false;
    }

    /** @param resource $output */
    private static function write($output, string $json): void
    {
        \fwrite($output, $json . "\n");
        \fflush($output);
    }
}
