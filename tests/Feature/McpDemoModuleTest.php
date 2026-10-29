<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Identity;
use App\Engine\Auth\UserProvider;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Transport\StdioTransport;
use App\Engine\Security\Signer;
use App\Tests\Fixtures\Auth\FakeProvider;
use App\Tests\Support\TestCase;

/**
 * MCP-19: the demonstration module, end to end, over both transports.
 */
final class McpDemoModuleTest extends TestCase
{
    private const VIEWER_TOKEN = 'viewer-token-do-not-use';

    private function app(): Application
    {
        return $this->application([
            'security' => ['key' => Signer::generate()],
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/McpDemo/Plugins']],
            'mcp' => ['transports' => ['http' => true]],
        ])->boot();
    }

    /**
     * One STDIO session, as `mcp:stdio` runs it: every line in, every answer out.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function stdio(Application $app, Identity $identity, array $messages): array
    {
        $input = \fopen('php://memory', 'r+');
        $output = \fopen('php://memory', 'r+');
        self::assertIsResource($input);
        self::assertIsResource($output);

        foreach ($messages as $message) {
            \fwrite($input, \json_encode($message) . "\n");
        }

        \rewind($input);
        $app->container()->get(StdioTransport::class)->run($input, $output, new McpSession($identity, 'stdio'));
        \rewind($output);

        $answers = [];

        foreach (\explode("\n", \trim((string) \stream_get_contents($output))) as $line) {
            $decoded = \json_decode($line, true);
            self::assertIsArray($decoded, $line);
            $answers[] = $decoded;
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function request(int $id, string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function http(Application $app, string $method, array $params = [], string $token = 'ada-token-do-not-use'): array
    {
        $response = $app->handle(Request::create('POST', '/mcp', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => (string) \json_encode(self::request(1, $method, $params)),
        ]));

        $decoded = \json_decode($response->body(), true);
        self::assertIsArray($decoded, $response->body());

        return $decoded;
    }

    private static function viewer(): Identity
    {
        return new Identity('20', 'vera', ['mcp-demo-viewer']);
    }

    // ---- registration and discovery -----------------------------------------------

    public function test_the_module_registers_one_of_each_and_mcp_list_shows_them(): void
    {
        $app = $this->app();
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $container = $app->container();
        (new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(['laika', 'mcp:list']));
        \rewind($stream);
        $listing = (string) \stream_get_contents($stream);

        foreach (['tool\s+demo\.echo\s+McpDemo\s+any user', 'tool\s+demo\.status\s+McpDemo\s+mcpdemo\.status\.read', 'resource\s+status://app', 'prompt\s+demo\.diagnostics'] as $row) {
            self::assertMatchesRegularExpression('#' . $row . '#', $listing);
        }
    }

    public function test_initialize_advertises_tools_resources_and_prompts(): void
    {
        $result = self::http($this->app(), 'initialize', ['protocolVersion' => '2025-06-18'])['result'] ?? [];

        self::assertSame(['tools', 'resources', 'prompts'], \array_keys($result['capabilities'] ?? []));
    }

    // ---- invocation, and the module's filter --------------------------------------------

    public function test_echo_over_http_as_the_token_holder_with_its_input_trimmed_by_the_modules_filter(): void
    {
        $answer = self::http($this->app(), 'tools/call', ['name' => 'demo.echo', 'arguments' => ['text' => '  hello  ']]);

        self::assertSame(['text' => 'hello', 'for' => '10'], $answer['result']['structuredContent'] ?? null);
    }

    public function test_the_schema_bounds_what_echo_accepts(): void
    {
        $answer = self::http($this->app(), 'tools/call', ['name' => 'demo.echo', 'arguments' => ['text' => \str_repeat('x', 1001)]]);

        self::assertSame(-32602, $answer['error']['code'] ?? null);
    }

    // ---- permissions -------------------------------------------------------------------------

    /** An administrator of the application is not thereby a viewer of the demo. */
    public function test_status_needs_its_permission_and_is_invisible_without_it(): void
    {
        $app = $this->app();

        $tools = \array_column(self::http($app, 'tools/list')['result']['tools'] ?? [], 'name');
        self::assertSame(['demo.echo'], $tools);
        self::assertSame('Unknown tool.', self::http($app, 'tools/call', ['name' => 'demo.status'])['error']['message'] ?? null);
        self::assertSame(-32002, self::http($app, 'resources/read', ['uri' => 'status://app'])['error']['code'] ?? null);
    }

    public function test_a_token_whose_user_holds_the_role_reads_status_over_http(): void
    {
        $app = $this->app();
        $app->container()->instance(UserProvider::class, (new FakeProvider())->add('20', 'vera', roles: ['mcp-demo-viewer'])->addToken('20', self::VIEWER_TOKEN));

        $status = self::http($app, 'tools/call', ['name' => 'demo.status'], self::VIEWER_TOKEN)['result']['structuredContent'] ?? [];

        self::assertSame(\PHP_VERSION, $status['php'] ?? null);
        self::assertGreaterThan(0, $status['modules'] ?? 0);
    }

    // ---- STDIO: resources, prompts and the module's hook -------------------------------------------

    public function test_a_stdio_session_reads_the_resource_gets_the_prompt_and_counts_calls(): void
    {
        $answers = $this->stdio($this->app(), self::viewer(), [
            self::request(1, 'initialize', ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'test', 'version' => '1']]),
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            self::request(2, 'tools/call', ['name' => 'demo.echo', 'arguments' => ['text' => 'one']]),
            self::request(3, 'tools/call', ['name' => 'demo.echo', 'arguments' => ['text' => 'two']]),
            self::request(4, 'resources/read', ['uri' => 'status://app']),
            self::request(5, 'prompts/get', ['name' => 'demo.diagnostics', 'arguments' => ['symptom' => 'pages are slow', 'since' => 'this morning']]),
            self::request(6, 'prompts/get', ['name' => 'demo.diagnostics', 'arguments' => []]),
        ]);

        self::assertSame([1, 2, 3, 4, 5, 6], \array_column($answers, 'id'));

        // The module's mcp.tool.after listener counted both echoes.
        $status = \json_decode((string) ($answers[3]['result']['contents'][0]['text'] ?? ''), true);
        self::assertIsArray($status);
        self::assertSame(2, $status['tool_calls'] ?? null);
        self::assertSame('application/json', $answers[3]['result']['contents'][0]['mimeType'] ?? null);

        self::assertStringContainsString('pages are slow. It started this morning.', (string) \json_encode($answers[4]['result']['messages'] ?? []));
        self::assertSame(-32602, $answers[5]['error']['code'] ?? null, 'the required argument is enforced');
    }

    public function test_a_guest_session_is_refused_everything(): void
    {
        $answers = $this->stdio($this->app(), Identity::guest(), [
            self::request(1, 'initialize', ['protocolVersion' => '2025-06-18']),
            self::request(2, 'tools/list'),
            self::request(3, 'tools/call', ['name' => 'demo.echo', 'arguments' => ['text' => 'x']]),
        ]);

        self::assertSame([], $answers[1]['result']['tools'] ?? null);
        self::assertSame('Unknown tool.', $answers[2]['error']['message'] ?? null);
    }
}
