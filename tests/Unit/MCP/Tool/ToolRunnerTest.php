<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Tool;

use App\Engine\Container\Container;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\Tool\ToolException;
use App\Engine\MCP\Tool\ToolResult;
use App\Engine\MCP\Tool\ToolRunner;
use App\Tests\Fixtures\MCP\Authorizers;
use App\Tests\Fixtures\MCP\BrokenTool;
use App\Tests\Fixtures\MCP\Contexts;
use App\Tests\Fixtures\MCP\GreetTool;
use App\Tests\Fixtures\MCP\ListSchemaTool;
use App\Tests\Support\TestCase;

final class ToolRunnerTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    private function runner(McpRegistry $registry): ToolRunner
    {
        return new ToolRunner($registry, new Container(), Authorizers::open(), function (\Throwable $e): void {
            $this->reported[] = $e;
        });
    }

    private function registry(): McpRegistry
    {
        $registry = new McpRegistry();
        (new McpCollector($registry, 'Demo'))
            ->tool('greet', GreetTool::class, 'Say hello.')
            ->tool('broken', BrokenTool::class);

        return $registry;
    }

    public function test_tools_are_listed_with_their_schemas_in_registration_order(): void
    {
        $list = $this->runner($this->registry())->list(Contexts::make());

        self::assertSame(['greet', 'broken'], \array_column($list, 'name'));
        self::assertSame('Say hello.', $list[0]['description']);
        self::assertSame(['name'], $list[0]['inputSchema']['required'] ?? null);
    }

    public function test_a_successful_call_returns_structured_content_and_its_text(): void
    {
        $result = $this->runner($this->registry())->call('greet', ['name' => 'Ada'], Contexts::make());

        self::assertSame(
            [
                'content' => [['type' => 'text', 'text' => '{"greeting":"Hello, Ada","length":3,"asked_by":"","request":"test-request"}']],
                'structuredContent' => ['greeting' => 'Hello, Ada', 'length' => 3, 'asked_by' => '', 'request' => 'test-request'],
                'isError' => false,
            ],
            $result->toArray(),
        );
    }

    public function test_an_unknown_tool_is_invalid_params_naming_what_was_asked_for(): void
    {
        try {
            $this->runner($this->registry())->call('shell.execute', [], Contexts::make());
            self::fail('an unknown tool ran');
        } catch (ToolException $e) {
            self::assertSame(McpErrorCode::InvalidParams, $e->error()->code);
            self::assertSame(['tool' => 'shell.execute'], $e->error()->data);
        }
    }

    /** The runner validates before the tool runs: GreetTool requires a name. */
    public function test_arguments_are_checked_against_the_schema_before_the_tool_runs(): void
    {
        try {
            $this->runner($this->registry())->call('greet', ['nme' => 'typo'], Contexts::make());
            self::fail('invalid arguments reached the tool');
        } catch (\App\Engine\MCP\Validation\ValidationException $e) {
            self::assertSame(
                [['path' => 'name', 'message' => 'is required'], ['path' => 'nme', 'message' => 'is not an accepted property']],
                $e->error()->data['errors'] ?? null,
            );
        }
    }

    public function test_arguments_that_are_not_an_object_are_refused(): void
    {
        foreach (['a string', 42, null, ['positional', 'list']] as $arguments) {
            try {
                $this->runner($this->registry())->call('greet', $arguments, Contexts::make());
                self::fail('arguments were accepted: ' . \json_encode($arguments));
            } catch (ToolException $e) {
                self::assertSame('Tool arguments must be an object.', $e->error()->message);
            }
        }
    }

    /**
     * The failure goes to the log, whole; the client learns only that the tool
     * failed -- not the SQL state, the host, or the password in the message.
     */
    public function test_an_unexpected_failure_is_reported_and_never_shown_to_the_client(): void
    {
        $result = $this->runner($this->registry())->call('broken', ['how' => 'throw'], Contexts::make());

        self::assertTrue($result->isError());
        self::assertSame('The tool failed. The error has been logged.', $result->toArray()['content'][0]['text']);
        self::assertStringNotContainsString('S3cret', (string) \json_encode($result->toArray()));
        self::assertCount(1, $this->reported);
        self::assertStringContainsString('S3cret', $this->reported[0]->getMessage());
    }

    public function test_a_tools_own_error_is_shown_as_it_wrote_it(): void
    {
        $result = $this->runner($this->registry())->call('broken', ['how' => 'own-error'], Contexts::make());

        self::assertSame(['content' => [['type' => 'text', 'text' => 'Invoice 42 is already paid.']], 'isError' => true], $result->toArray());
        self::assertSame([], $this->reported, 'a tool reporting its own failure is not an error to log');
    }

    public function test_a_protocol_refusal_from_a_tool_passes_through(): void
    {
        $this->expectException(ToolException::class);

        $this->runner($this->registry())->call('broken', ['how' => 'refuse'], Contexts::make());
    }

    /** An object in a result is refused by the result, and reported like any failure. */
    public function test_an_object_in_a_result_never_reaches_the_client(): void
    {
        $result = $this->runner($this->registry())->call('broken', ['how' => 'object'], Contexts::make());

        self::assertTrue($result->isError());
        self::assertStringNotContainsString('S3cret', (string) \json_encode($result->toArray()));
        self::assertCount(1, $this->reported, 'a tool breaking the contract is a bug, and is logged');
    }

    public function test_a_handler_that_is_not_a_tool_is_a_contract_error(): void
    {
        $registry = new McpRegistry();
        (new McpCollector($registry, 'Demo'))->tool('not-a-tool', \ArrayObject::class);

        $this->expectException(McpContractException::class);
        $this->expectExceptionMessage('does not implement');

        $this->runner($registry)->call('not-a-tool', [], Contexts::make());
    }

    public function test_a_schema_that_is_not_an_object_schema_is_a_contract_error(): void
    {
        $registry = new McpRegistry();
        (new McpCollector($registry, 'Demo'))->tool('lists', ListSchemaTool::class);

        $this->expectException(McpContractException::class);
        $this->expectExceptionMessage('input schema');

        $this->runner($registry)->list(Contexts::make());
    }

    public function test_results_are_built_only_from_plain_data(): void
    {
        self::assertSame(['content' => [['type' => 'text', 'text' => 'done']], 'isError' => false], ToolResult::text('done')->toArray());
        self::assertSame('2 customers', ToolResult::structured(['count' => 2], '2 customers')->toArray()['content'][0]['text']);

        $this->expectException(McpContractException::class);

        ToolResult::structured(['when' => new \DateTimeImmutable()]);
    }
}
