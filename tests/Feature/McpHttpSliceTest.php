<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AuthManager;
use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\McpServer;
use App\Engine\Security\Signer;
use App\Engine\Session\SessionManager;
use App\Tests\Support\TestCase;

/**
 * MCP over HTTP, through the real kernel: the demo's user module issues the
 * token, the MCP fixture modules offer the capabilities.
 */
final class McpHttpSliceTest extends TestCase
{
    private const TOKEN = 'ada-token-do-not-use';

    /** @param array<string, mixed> $mcp */
    private function app(array $mcp = ['transports' => ['http' => true]]): Application
    {
        return $this->application([
            'security' => ['key' => Signer::generate()],
            'modules' => [
                'paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Mcp/Plugins'],
                'disabled' => ['Muted'],
            ],
            'mcp' => $mcp,
        ]);
    }

    /** @param array<string, string> $headers */
    private static function call(Application $app, string $path, string $body, array $headers = []): Response
    {
        return $app->handle(Request::create('POST', $path, [
            'headers' => $headers + [
                'Authorization' => 'Bearer ' . self::TOKEN,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
            'body' => $body,
        ]));
    }

    public function test_installing_the_framework_opens_no_endpoint(): void
    {
        $response = self::call($this->app([]), '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        self::assertSame(404, $response->status());
        self::assertArrayNotHasKey('jsonrpc', (array) \json_decode($response->body(), true), 'the router answered, not MCP');
    }

    public function test_a_token_holder_calls_a_modules_tool(): void
    {
        $response = self::call(
            $this->app(),
            '/mcp',
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"zulu.greet","arguments":{"name":"Ada"}}}',
        );

        self::assertSame(200, $response->status(), $response->body());
        $decoded = \json_decode($response->body(), true);
        self::assertIsArray($decoded);
        self::assertSame('Hello, Ada', $decoded['result']['structuredContent']['greeting'] ?? null);
    }

    /** No CSRF token was sent, and none is needed: a bearer token is not ambient. */
    public function test_the_endpoint_is_not_a_route_and_skips_what_routes_need(): void
    {
        $response = self::call($this->app(), '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        self::assertSame(200, $response->status(), $response->body());

        foreach ($response->cookies() as $cookie) {
            self::assertNotSame(SessionManager::DEFAULT_COOKIE, $cookie->name, 'an MCP call started a session');
        }
    }

    public function test_the_engines_response_filters_still_run(): void
    {
        $response = self::call($this->app(), '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function test_no_token_is_401(): void
    {
        $response = self::call($this->app(), '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Authorization' => '']);

        self::assertSame(401, $response->status());
        self::assertSame('Bearer realm="mcp"', $response->header('WWW-Authenticate'));
    }

    public function test_the_path_is_configurable_and_only_that_path_answers(): void
    {
        $app = $this->app(['transports' => ['http' => true], 'http' => ['path' => '/ai/mcp/']]);

        self::assertSame(200, self::call($app, '/ai/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}')->status());
        self::assertSame(404, self::call($app, '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}')->status());
    }

    /** Enabling MCP costs a page one string comparison, not an MCP server. */
    public function test_a_page_builds_nothing_of_mcp(): void
    {
        $app = $this->app();

        $app->handle(Request::create('GET', '/'));

        self::assertFalse($app->container()->resolved(McpServer::class));
        self::assertFalse($app->container()->resolved(AuthManager::class));
    }
}
