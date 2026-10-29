<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Engine\Auth\Identity;
use App\Engine\Hook\HookEngine;
use App\Engine\Logging\Level;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\McpLog;
use App\Engine\MCP\McpSession;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Fixtures\MCP\Servers;
use App\Tests\Support\TestCase;

/**
 * What the mcp log channel records about MCP traffic, and what it never does.
 */
final class McpLogTest extends TestCase
{
    /** @var list<LogRecord> */
    private array $records = [];

    /** @param array<string, mixed> $params */
    private function ask(string $method, array $params = [], ?Identity $identity = null): void
    {
        $writer = new CollectingWriter();
        $hooks = new HookEngine();
        (new McpLog((new LogManager())->add($writer)))->attach($hooks);

        $session = new McpSession($identity ?? new Identity('7', 'ada', ['agent']), 'stdio');
        $session->initialize('2025-06-18', 'Claude Desktop', '1.0');

        Servers::make(hooks: $hooks)->handle((string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]), $session);

        \array_push($this->records, ...$writer->records);
    }

    /** @return list<LogRecord> above debug, which is every request */
    private function significant(): array
    {
        return \array_values(\array_filter($this->records, static fn(LogRecord $r): bool => $r->level !== Level::Debug));
    }

    private function only(): LogRecord
    {
        $records = $this->significant();
        self::assertCount(1, $records, \implode(', ', \array_map(static fn(LogRecord $r): string => $r->message, $records)));

        return $records[0];
    }

    public function test_a_tool_call_is_recorded_with_who_what_and_how_long(): void
    {
        $this->ask('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada Lovelace']]);

        $record = $this->only();

        self::assertSame(McpLog::CHANNEL, $record->channel);
        self::assertSame(Level::Info, $record->level);
        self::assertSame('MCP tool called', $record->message);
        self::assertSame('greet', $record->context['capability']);
        self::assertSame('Demo', $record->context['module']);
        self::assertSame('ok', $record->context['outcome']);
        self::assertSame('7', $record->context['principal']);
        self::assertSame('stdio', $record->context['transport']);
        self::assertSame('Claude Desktop 1.0', $record->context['client']);
        self::assertNotSame('', $record->context['request_id']);
        self::assertIsFloat($record->context['ms']);
    }

    public function test_every_request_is_recorded_at_debug(): void
    {
        $this->ask('tools/list');

        self::assertSame('MCP request', $this->records[0]->message);
        self::assertSame(Level::Debug, $this->records[0]->level);
        self::assertSame('tools/list', $this->records[0]->context['method']);
    }

    /** The client heard "unknown tool"; the log hears the truth. */
    public function test_a_denial_is_a_warning_naming_the_capability(): void
    {
        $this->ask('tools/call', ['name' => 'customer.get', 'arguments' => ['name' => 'x']], new Identity('9', 'eve'));

        $denied = \array_values(\array_filter($this->significant(), static fn(LogRecord $r): bool => $r->level === Level::Warning));

        self::assertCount(1, $denied);
        self::assertSame('MCP tool denied', $denied[0]->message);
        self::assertSame('customer.get', $denied[0]->context['capability']);
        self::assertSame('9', $denied[0]->context['principal']);
    }

    public function test_a_guest_is_recorded_as_no_principal(): void
    {
        $this->ask('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'x']], Identity::guest());

        self::assertNull($this->significant()[0]->context['principal']);
    }

    public function test_a_failure_records_the_exception_class_and_nothing_it_said(): void
    {
        $this->ask('tools/call', ['name' => 'broken', 'arguments' => ['how' => 'throw']]);

        $record = $this->only();

        self::assertSame(Level::Warning, $record->level);
        self::assertSame('MCP tool failed', $record->message);
        self::assertSame(\RuntimeException::class, $record->context['error']);
        self::assertStringNotContainsString('S3cret', (string) \json_encode($record->context));
    }

    public function test_a_refusal_in_protocol_terms_is_a_notice(): void
    {
        $this->ask('tools/call', ['name' => 'broken', 'arguments' => ['how' => 'refuse']]);

        $refused = \array_values(\array_filter($this->significant(), static fn(LogRecord $r): bool => $r->message === 'MCP tool refused'));

        self::assertCount(1, $refused);
        self::assertSame(Level::Notice, $refused[0]->level);
    }

    public function test_a_protocol_error_is_recorded_with_its_code(): void
    {
        $this->ask('no/such-method');

        $record = $this->only();

        self::assertSame('MCP request failed', $record->message);
        self::assertSame(-32601, $record->context['code']);
    }

    public function test_reading_a_resource_records_the_uri_and_loading_a_prompt_its_name(): void
    {
        $this->ask('resources/read', ['uri' => 'customer://42']);
        self::assertSame('customer://42', $this->only()->context['uri']);

        $this->records = [];
        $this->ask('prompts/get', ['name' => 'customer.support', 'arguments' => ['customer' => '42']]);
        self::assertSame('customer.support', $this->only()->context['capability']);
    }

    /** Arguments and results are the payload: a customer's data, or a secret. */
    public function test_neither_arguments_nor_results_are_ever_logged(): void
    {
        $this->ask('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada Lovelace']]);
        $this->ask('prompts/get', ['name' => 'customer.support', 'arguments' => ['customer' => 'Ada Lovelace']]);

        foreach ($this->records as $record) {
            self::assertStringNotContainsString('Lovelace', $record->message . \json_encode($record->context));
        }
    }

    public function test_the_log_can_be_switched_off(): void
    {
        $on = $this->application()->container()->get(HookEngine::class);
        $off = $this->application(['mcp' => ['log' => ['enabled' => false]]])->container()->get(HookEngine::class);

        self::assertTrue($on->has('mcp.tool.after'));
        self::assertFalse($off->has('mcp.tool.after'));
    }
}
