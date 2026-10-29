<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Transport;

use App\Engine\Auth\Identity;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Transport\StdioTransport;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Tests\Fixtures\MCP\Servers;
use App\Tests\Support\TestCase;

final class StdioTransportTest extends TestCase
{
    /** @var list<string> */
    private array $diagnostics = [];

    /**
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>> every line written, decoded
     */
    private function converse(array $lines, int $maxBytes = 4096): array
    {
        $input = \fopen('php://memory', 'r+');
        $output = \fopen('php://memory', 'r+');
        self::assertIsResource($input);
        self::assertIsResource($output);

        \fwrite($input, \implode("\n", $lines) . "\n");
        \rewind($input);

        $transport = new StdioTransport(Servers::make(), $maxBytes, function (string $diagnostic): void {
            $this->diagnostics[] = $diagnostic;
        });
        $transport->run($input, $output, new McpSession(new Identity('7', 'ada'), 'stdio'));

        \rewind($output);
        $written = (string) \stream_get_contents($output);

        $messages = [];

        foreach (\explode("\n", \rtrim($written, "\n")) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = \json_decode($line, true);
            self::assertIsArray($decoded, 'stdout carried something that is not a JSON message: ' . $line);
            $messages[] = $decoded;
        }

        return $messages;
    }

    public function test_a_session_of_several_messages_is_answered_in_order(): void
    {
        $answers = $this->converse([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","clientInfo":{"name":"t","version":"1"}}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"greet","arguments":{"name":"Ada"}}}',
        ]);

        self::assertSame([1, 2, 3], \array_column($answers, 'id'), 'the notification got no answer');
        self::assertSame('Hello, Ada', $answers[2]['result']['structuredContent']['greeting'] ?? null);
    }

    public function test_invalid_lines_are_answered_and_the_session_carries_on(): void
    {
        $answers = $this->converse([
            '{broken',
            '',
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        ]);

        self::assertSame(-32700, $answers[0]['error']['code'] ?? null);
        self::assertSame([], $answers[1]['result'] ?? null);
    }

    public function test_an_oversized_line_is_skipped_whole_and_the_next_one_is_read(): void
    {
        $answers = $this->converse([
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . \str_repeat('x', 10_000) . '"}}',
            '{"jsonrpc":"2.0","id":2,"method":"ping"}',
        ], 1024);

        self::assertCount(2, $answers);
        self::assertSame(-32600, $answers[0]['error']['code'] ?? null);
        self::assertSame(2, $answers[1]['id']);
    }

    /** Whatever a handler prints never reaches stdout; it is reported instead. */
    public function test_output_from_a_handler_never_reaches_stdout(): void
    {
        $answers = $this->converse([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            '{"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"customer://noisy"}}',
        ]);

        self::assertCount(2, $answers);
        self::assertNotEmpty($this->diagnostics);
    }

    public function test_the_end_of_input_ends_the_session_cleanly(): void
    {
        self::assertSame([], $this->converse([]));
    }

    /**
     * The real command, as a client starts it: every line on stdout is a
     * message, and the process exits when stdin closes.
     */
    public function test_bin_console_mcp_stdio_speaks_only_protocol_on_stdout(): void
    {
        $result = (new CommandExecutor())->run(new Command(
            \PHP_BINARY,
            ['laika', 'mcp:stdio'],
            workingDirectory: $this->basePath(),
            stdin: \implode("\n", [
                '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
                '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            ]) . "\n",
            timeout: 30.0,
        ));

        self::assertSame(0, $result->exitCode(), $result->stderr());

        $lines = \explode("\n", \trim($result->stdout()));

        self::assertCount(2, $lines, $result->stdout());

        foreach ($lines as $line) {
            self::assertIsArray(\json_decode($line, true), 'not a JSON message: ' . $line);
        }

        self::assertSame([], \json_decode($lines[1], true)['result']['tools'] ?? null, 'a guest sees nothing');
    }
}
