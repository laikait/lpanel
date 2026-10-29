<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\Auth\Identity;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Protocol\ProtocolVersion;
use App\Tests\Fixtures\MCP\Servers;
use App\Tests\Support\TestCase;

final class McpServerTest extends TestCase
{
    private McpServer $server;

    private McpSession $session;

    protected function setUp(): void
    {
        $this->server = Servers::make();
        $this->session = new McpSession(new Identity('7', 'ada', ['agent']), 'stdio');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params = [], int|string $id = 1): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if ($params !== []) {
            $message['params'] = $params;
        }

        $answer = $this->server->handle((string) \json_encode($message), $this->session);
        self::assertIsString($answer);

        $decoded = \json_decode($answer, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function initialize(): void
    {
        $this->call('initialize', ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'phpunit', 'version' => '11']]);
    }

    public function test_initialize_agrees_a_version_and_says_what_is_offered(): void
    {
        $answer = $this->call('initialize', ['protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'phpunit', 'version' => '11']]);

        self::assertSame(
            [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => [], 'resources' => [], 'prompts' => []],
                'serverInfo' => ['name' => 'test-server', 'version' => '9.9.9'],
            ],
            $answer['result'] ?? null,
        );
        self::assertSame('2025-03-26', $this->session->protocolVersion());
    }

    public function test_an_unknown_version_is_answered_with_the_newest(): void
    {
        self::assertSame(ProtocolVersion::LATEST, $this->call('initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion'] ?? null);
    }

    public function test_nothing_but_initialize_and_ping_before_initialize(): void
    {
        self::assertSame([], $this->call('ping')['result'] ?? null);

        $answer = $this->call('tools/list');

        self::assertSame(-32600, $answer['error']['code'] ?? null);
        self::assertSame(1, $answer['id']);
    }

    public function test_the_methods_answer_through_the_runners(): void
    {
        $this->initialize();

        self::assertSame(['greet', 'broken', 'customer.get'], \array_column($this->call('tools/list')['result']['tools'] ?? [], 'name'));
        self::assertSame('Hello, Ada', $this->call('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'Ada']])['result']['structuredContent']['greeting'] ?? null);
        self::assertSame(['customer://{id}'], \array_column($this->call('resources/templates/list')['result']['resourceTemplates'] ?? [], 'uriTemplate'));
        self::assertSame([], $this->call('resources/list')['result']['resources'] ?? null);
        self::assertSame('customer://42', $this->call('resources/read', ['uri' => 'customer://42'])['result']['contents'][0]['uri'] ?? null);
        self::assertSame(['customer.support'], \array_column($this->call('prompts/list')['result']['prompts'] ?? [], 'name'));
        self::assertCount(2, $this->call('prompts/get', ['name' => 'customer.support', 'arguments' => ['customer' => '1']])['result']['messages'] ?? []);
    }

    public function test_every_failure_is_an_error_with_the_requests_id(): void
    {
        $this->initialize();

        self::assertSame(['code' => -32601, 'message' => 'Method not found.', 'data' => ['method' => 'shell/exec']], $this->call('shell/exec', [], 'x')['error'] ?? null);
        self::assertSame(-32602, $this->call('tools/call', ['arguments' => []], 2)['error']['code'] ?? null, 'no tool name');
        self::assertSame(-32602, $this->call('tools/call', ['name' => 'greet', 'arguments' => ['nme' => 1]], 3)['error']['code'] ?? null, 'schema');
        self::assertSame(-32002, $this->call('resources/read', ['uri' => 'invoice://1'], 4)['error']['code'] ?? null);
        self::assertSame('x', $this->call('shell/exec', [], 'x')['id']);
    }

    public function test_the_session_identity_decides_what_is_visible(): void
    {
        $this->session = new McpSession(new Identity('8', 'bob'), 'stdio');
        $this->initialize();

        self::assertSame(['greet', 'broken'], \array_column($this->call('tools/list')['result']['tools'] ?? [], 'name'));
    }

    public function test_a_notification_is_never_answered_even_when_it_is_wrong(): void
    {
        self::assertNull($this->server->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}', $this->session));
        self::assertNull($this->server->handle('{"jsonrpc":"2.0","method":"no/such/thing"}', $this->session));
    }

    public function test_unparseable_input_is_answered_with_a_null_id(): void
    {
        $answer = \json_decode((string) $this->server->handle('{not json', $this->session), true);

        self::assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: the message is not valid JSON.']], $answer);
    }
}
