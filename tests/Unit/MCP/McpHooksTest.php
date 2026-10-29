<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\MCP\Capability;
use App\Engine\MCP\McpContext;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpError;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Prompt\PromptMessage;
use App\Engine\MCP\Prompt\PromptResult;
use App\Engine\MCP\Protocol\Response;
use App\Engine\MCP\Resource\ResourceContents;
use App\Engine\MCP\Tool\ToolException;
use App\Engine\MCP\Tool\ToolResult;
use App\Tests\Fixtures\MCP\Servers;
use App\Tests\Support\TestCase;

/**
 * MCP's hooks and filters, through the server as a client reaches it.
 */
final class McpHooksTest extends TestCase
{
    private HookEngine $hooks;

    private FilterEngine $filters;

    /** @var list<\Throwable> */
    private array $reported = [];

    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hooks = new HookEngine();
        $this->filters = new FilterEngine(debug: true);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the decoded answer
     */
    private function ask(string $method, array $params = [], ?Identity $identity = null): array
    {
        $server = Servers::make($this->reported, $this->hooks, $this->filters);
        $session = new McpSession($identity ?? new Identity('7', 'ada', ['agent']), 'stdio');
        $session->initialize('2025-06-18', 'test', '1');

        $answer = $server->handle((string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]), $session);
        self::assertIsString($answer);

        $decoded = \json_decode($answer, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** Record that a hook fired, by name. */
    private function record(string ...$hooks): void
    {
        foreach ($hooks as $hook) {
            $this->hooks->add($hook, function () use ($hook): void {
                $this->seen[] = $hook;
            });
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function greet(array $arguments = ['name' => 'Ada'], string $tool = 'greet', ?Identity $identity = null): array
    {
        return $this->ask('tools/call', ['name' => $tool, 'arguments' => $arguments], $identity);
    }

    // ---- callbacks ----------------------------------------------------------

    public function test_a_tool_call_fires_its_hooks_in_order_with_one_context(): void
    {
        $this->record('mcp.request.received', 'mcp.tool.before', 'mcp.tool.after', 'mcp.tool.failed', 'mcp.request.failed', 'mcp.response.created');

        $ids = [];
        $this->hooks->add('mcp.request.received', static function (string $method, McpContext $context) use (&$ids): void {
            $ids[] = $context->requestId;
        });
        $this->hooks->add('mcp.tool.before', static function (Capability $tool, array $arguments, McpContext $context) use (&$ids): void {
            $ids[] = $context->requestId;
        });

        $answer = $this->greet();

        self::assertSame(['mcp.request.received', 'mcp.tool.before', 'mcp.tool.after', 'mcp.response.created'], $this->seen);
        self::assertCount(1, \array_unique($ids), 'the request hooks and the tool saw different contexts');
        self::assertSame($ids[0], $answer['result']['structuredContent']['request'] ?? null, 'the tool saw another context');
    }

    public function test_the_hooks_carry_what_happened(): void
    {
        $this->hooks->add('mcp.tool.before', function (Capability $tool, array $arguments): void {
            $this->seen[] = $tool->name . ' ' . \json_encode($arguments);
        });
        $this->hooks->add('mcp.tool.after', function (Capability $tool, ToolResult $result): void {
            $this->seen[] = $tool->name . ' ' . ($result->isError() ? 'error' : 'ok');
        });
        $this->hooks->add('mcp.response.created', function (Response $response): void {
            $this->seen[] = 'response ' . ($response->isError() ? 'error' : 'ok');
        });

        $this->greet();

        self::assertSame(['greet {"name":"Ada"}', 'greet ok', 'response ok'], $this->seen);
    }

    public function test_reading_a_resource_and_loading_a_prompt_fire_their_hooks(): void
    {
        $this->hooks->add('mcp.resource.read', function (Capability $resource, string $uri): void {
            $this->seen[] = $resource->name . ' ' . $uri;
        });
        $this->hooks->add('mcp.prompt.loaded', function (Capability $prompt, PromptResult $result): void {
            $this->seen[] = $prompt->name . ' ' . \count($result->toArray()['messages']);
        });

        $this->ask('resources/read', ['uri' => 'customer://42']);
        $this->ask('prompts/get', ['name' => 'customer.support', 'arguments' => ['customer' => '42']]);

        self::assertSame(['customer://{id} customer://42', 'customer.support 2'], $this->seen);
    }

    // ---- priority ordering -----------------------------------------------------

    public function test_filters_run_in_priority_order_whatever_order_they_were_added(): void
    {
        $this->filters->add('mcp.tool.input', static fn(array $arguments): array => ['name' => $arguments['name'] . ' second'], 20);
        $this->filters->add('mcp.tool.input', static fn(array $arguments): array => ['name' => $arguments['name'] . ' first'], 5);

        self::assertSame('Hello, Ada first second', $this->greet()['result']['structuredContent']['greeting'] ?? null);
    }

    // ---- transformations ------------------------------------------------------

    public function test_the_output_filters_transform_what_the_client_receives(): void
    {
        $this->filters->add('mcp.tool.output', static fn(ToolResult $result): ToolResult => ToolResult::text('redacted'));
        $this->filters->add('mcp.resource.output', static fn(ResourceContents $contents): ResourceContents => ResourceContents::text('customer://42', 'filtered'));
        $this->filters->add('mcp.prompt.output', static fn(PromptResult $result): PromptResult => new PromptResult([PromptMessage::user('filtered')]));

        self::assertSame('redacted', $this->greet()['result']['content'][0]['text'] ?? null);
        self::assertSame('filtered', $this->ask('resources/read', ['uri' => 'customer://42'])['result']['contents'][0]['text'] ?? null);
        self::assertSame('filtered', $this->ask('prompts/get', ['name' => 'customer.support', 'arguments' => ['customer' => '42']])['result']['messages'][0]['content']['text'] ?? null);
    }

    public function test_the_description_filter_changes_what_tools_list_says(): void
    {
        $this->filters->add('mcp.tool.description', static fn(string $description, Capability $tool): string => $tool->name === 'greet' ? 'Greets, politely.' : $description);

        $tools = \array_column($this->ask('tools/list')['result']['tools'] ?? [], 'description', 'name');

        self::assertSame('Greets, politely.', $tools['greet'] ?? null);
    }

    // ---- failure behaviour ----------------------------------------------------

    public function test_a_failing_tool_fires_failed_instead_of_after(): void
    {
        $this->record('mcp.tool.after', 'mcp.response.created');
        $this->hooks->add('mcp.tool.failed', function (Capability $tool, \Throwable $e): void {
            $this->seen[] = 'failed ' . $e::class;
        });

        $answer = $this->greet(['how' => 'throw'], 'broken');

        self::assertTrue($answer['result']['isError'] ?? false);
        self::assertSame(['failed ' . \RuntimeException::class, 'mcp.response.created'], $this->seen);
    }

    public function test_a_protocol_error_fires_request_failed_with_the_error_the_client_gets(): void
    {
        $this->hooks->add('mcp.request.failed', function (string $method, McpError $error): void {
            $this->seen[] = $method . ' ' . $error->code->value;
        });

        $answer = $this->ask('tools/call', ['name' => 'no-such-tool']);

        self::assertSame(['tools/call ' . ($answer['error']['code'] ?? 'none')], $this->seen);
    }

    /** A listener may refuse, in protocol terms; the tool does not run. */
    public function test_a_before_listener_can_refuse_the_call(): void
    {
        $this->hooks->add('mcp.tool.before', static function (): void {
            throw ToolException::unknown('greet');
        });
        $this->record('mcp.tool.after');

        $answer = $this->greet();

        self::assertArrayHasKey('error', $answer);
        self::assertSame([], $this->seen);
    }

    /** A listener that breaks is a bug: reported, and the tool does not run. */
    public function test_a_broken_before_listener_stops_the_call_and_is_reported(): void
    {
        $this->hooks->add('mcp.tool.before', static function (): void {
            throw new \LogicException('listener bug');
        });
        $this->record('mcp.tool.after');

        $answer = $this->greet();

        self::assertTrue($answer['result']['isError'] ?? false);
        self::assertStringNotContainsString('listener bug', (string) \json_encode($answer));
        self::assertSame([], $this->seen);
        self::assertInstanceOf(\LogicException::class, $this->reported[0] ?? null);
    }

    /** Nothing a listener throws escapes the server; over STDIO it would end the session. */
    public function test_a_broken_response_listener_becomes_an_internal_error(): void
    {
        $this->hooks->add('mcp.response.created', static function (): void {
            throw new \LogicException('listener bug');
        });

        $answer = $this->greet();

        self::assertSame(-32603, $answer['error']['code'] ?? null);
        self::assertCount(1, $this->reported);
    }

    public function test_a_filter_that_changes_the_kind_of_value_is_a_reported_contract_failure(): void
    {
        $this->filters->add('mcp.tool.output', static fn(): string => 'not a result');

        $answer = $this->greet();

        self::assertTrue($answer['result']['isError'] ?? false);
        self::assertInstanceOf(McpContractException::class, $this->reported[0] ?? null);
        self::assertStringContainsString('mcp.tool.output', $this->reported[0]->getMessage());
    }

    // ---- security ordering --------------------------------------------------------

    /** Authorization comes first: a refused caller reaches no listener that could change anything. */
    public function test_an_unauthorized_call_reaches_no_filter_or_tool_hook(): void
    {
        $this->filters->add('mcp.tool.input', function (array $arguments): array {
            $this->seen[] = 'input';

            return $arguments;
        });
        $this->record('mcp.tool.before', 'mcp.tool.after', 'mcp.tool.failed');

        $answer = $this->greet(tool: 'customer.get', identity: new Identity('9', 'eve'));

        self::assertArrayHasKey('error', $answer);
        self::assertSame([], $this->seen);
    }

    /** Validation comes after the input filter, so a filter cannot smuggle input past the schema. */
    public function test_input_a_filter_adds_is_still_validated(): void
    {
        $this->filters->add('mcp.tool.input', static fn(array $arguments): array => $arguments + ['admin' => true]);
        $this->record('mcp.tool.before');

        $answer = $this->greet();

        self::assertSame(-32602, $answer['error']['code'] ?? null);
        self::assertSame([], $this->seen, 'the tool was about to run');
    }

    /** Filters see only what the caller may see. */
    public function test_the_description_filter_sees_only_visible_tools(): void
    {
        $this->filters->add('mcp.tool.description', function (string $description, Capability $tool): string {
            $this->seen[] = $tool->name;

            return $description;
        });

        $this->ask('tools/list', identity: new Identity('9', 'eve'));

        self::assertNotContains('customer.get', $this->seen);
        self::assertContains('greet', $this->seen);
    }
}
