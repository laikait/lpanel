<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Config\ConfigurationException;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\McpConfig;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

/**
 * The `mcp` block of configuration, as an application writes it in
 * config/mcp.php, taking effect on both transports.
 */
final class McpConfigurationSliceTest extends TestCase
{
    /** @param array<string, mixed> $mcp */
    private function app(array $mcp): Application
    {
        return $this->application([
            'security' => ['key' => Signer::generate()],
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Mcp/Plugins'], 'disabled' => ['Muted']],
            'mcp' => $mcp,
        ]);
    }

    /** @param array<string, string> $headers */
    private static function post(Application $app, string $body, array $headers = []): Response
    {
        return $app->handle(Request::create('POST', '/mcp', [
            'headers' => $headers + [
                'Authorization' => 'Bearer ada-token-do-not-use',
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]));
    }

    /** @return array{int, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $container = $app->boot()->container();
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);

        return [$status, (string) \stream_get_contents($stream)];
    }

    public function test_switching_mcp_off_closes_http_even_where_http_is_on(): void
    {
        $response = self::post($this->app(['enabled' => false, 'transports' => ['http' => true]]), '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        self::assertSame(404, $response->status());
    }

    public function test_switching_mcp_off_stops_mcp_stdio_before_it_reads_anything(): void
    {
        [$status, $output] = $this->console($this->app(['enabled' => false]), 'mcp:stdio');

        self::assertSame(1, $status);
        self::assertStringContainsString('mcp.enabled', $output);
    }

    public function test_switching_stdio_off_stops_mcp_stdio(): void
    {
        [$status, $output] = $this->console($this->app(['transports' => ['stdio' => false]]), 'mcp:stdio');

        self::assertSame(1, $status);
        self::assertStringContainsString('mcp.transports.stdio', $output);
    }

    public function test_the_configured_server_identity_is_what_initialize_says(): void
    {
        $response = self::post(
            $this->app(['transports' => ['http' => true], 'server' => ['name' => 'Acme ERP', 'version' => '4.2.0']]),
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
        );

        $decoded = \json_decode($response->body(), true);
        self::assertIsArray($decoded);
        self::assertSame(['name' => 'Acme ERP', 'version' => '4.2.0'], $decoded['result']['serverInfo'] ?? null);
    }

    /** Allowing guests lets a caller with no token in, and grants what guests may use. */
    public function test_allowing_guests_admits_a_caller_with_no_token(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';

        $refused = self::post($this->app(['transports' => ['http' => true]]), $body, ['Authorization' => '']);
        $admitted = self::post($this->app(['transports' => ['http' => true], 'allow_guests' => true]), $body, ['Authorization' => '']);

        self::assertSame(401, $refused->status());
        self::assertSame(200, $admitted->status());

        $decoded = \json_decode($admitted->body(), true);
        self::assertIsArray($decoded);
        self::assertContains('zulu.greet', \array_column($decoded['result']['tools'] ?? [], 'name'), 'a guest now sees what needs no permission');
    }

    public function test_mcp_list_shows_what_is_exposed_and_to_whom(): void
    {
        [$status, $output] = $this->console($this->app(['transports' => ['http' => true]]), 'mcp:list');

        self::assertSame(0, $status, $output);
        self::assertMatchesRegularExpression('/HTTP\s+POST \/mcp/', $output);
        self::assertMatchesRegularExpression('/Guests\s+refused/', $output);
        self::assertMatchesRegularExpression('/tool\s+zulu\.greet\s+Zulu\s+any user\s+/', $output);
        self::assertMatchesRegularExpression('/prompt\s+alpha\.support\s+Alpha\s+/', $output);
        self::assertStringNotContainsString('muted.greet', $output, 'a disabled module offers nothing');
    }

    public function test_mcp_list_filters_by_module(): void
    {
        [, $output] = $this->console($this->app([]), 'mcp:list', '--module=Alpha');

        self::assertStringContainsString('alpha.support', $output);
        self::assertStringNotContainsString('zulu.greet', $output);
        self::assertMatchesRegularExpression('/HTTP\s+off/', $output);
    }

    /** A mistake in config/mcp.php is found on the first request, naming the key. */
    public function test_a_wrong_value_is_reported_by_key(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('mcp.http.path');

        $this->app(['transports' => ['http' => true], 'http' => ['path' => '/../mcp']])
            ->container()->get(McpConfig::class);
    }
}
