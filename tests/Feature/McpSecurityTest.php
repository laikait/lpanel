<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Identity;
use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\McpSession;
use App\Engine\Security\Signer;
use App\Tests\Fixtures\MCP\Servers;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MCP-17: the security review, as tests. Each section is one line of the
 * plan's list, attacked through the protocol a client actually speaks.
 *
 * Covered elsewhere, and named here so the list is complete:
 * - malicious schemas: SchemaValidatorTest (unsupported keywords fail closed)
 * - SQL, filesystem and command exposure: ArchitectureTest
 *   (MCP imports no Database, Data, Model, System or Cli, and calls no file or
 *   process function)
 * - hooks and filters bypassing checks: McpHooksTest ("security ordering")
 * - HTTP method, origin and media-type abuse: HttpTransportTest
 */
final class McpSecurityTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function ask(string $method, array $params = [], ?Identity $identity = null): array
    {
        $session = new McpSession($identity ?? new Identity('9', 'eve'), 'stdio');
        $session->initialize('2025-06-18', 'test', '1');

        $answer = Servers::make($this->reported)->handle(
            (string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]),
            $session,
        );
        self::assertIsString($answer);
        $decoded = \json_decode($answer, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $config */
    private function http(array $config = []): Application
    {
        return $this->application([
            'security' => ['key' => Signer::generate()],
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Mcp/Plugins'], 'disabled' => ['Muted']],
            'mcp' => ['transports' => ['http' => true]],
        ] + $config);
    }

    /** @param array<string, string> $headers */
    private static function post(Application $app, string $body, array $headers = [], string $uri = '/mcp'): Response
    {
        return $app->handle(Request::create('POST', $uri, [
            'headers' => $headers + ['Authorization' => 'Bearer ada-token-do-not-use', 'Content-Type' => 'application/json'],
            'body' => $body,
        ]));
    }

    /** @return array<string, mixed> */
    private static function decoded(Response $response): array
    {
        $decoded = \json_decode($response->body(), true);
        self::assertIsArray($decoded, $response->body());

        return $decoded;
    }

    // ---- arbitrary tool access and class invocation ------------------------------

    /** @return iterable<string, array{string}> */
    public static function notTools(): iterable
    {
        yield 'a class name' => [\App\Tests\Fixtures\MCP\GreetTool::class];
        yield 'a short class name' => ['GreetTool'];
        yield 'a static method' => ['App\Engine\Core\Application::boot'];
        yield 'a function' => ['phpinfo'];
        yield 'a near miss by case' => ['GREET'];
        yield 'a near miss by space' => ['greet '];
        yield 'a path' => ['../greet'];
        yield 'a null byte' => ["greet\0"];
    }

    /** Only a registered name resolves; nothing a client sends becomes a class or callable. */
    #[DataProvider('notTools')]
    public function test_a_tool_name_is_a_registry_key_and_nothing_else(string $name): void
    {
        $answer = $this->ask('tools/call', ['name' => $name, 'arguments' => []], new Identity('7', 'ada', ['agent']));

        self::assertSame(-32602, $answer['error']['code'] ?? null);
        self::assertSame('Unknown tool.', $answer['error']['message'] ?? null);
        self::assertSame([], $this->reported, 'nothing was even attempted');
    }

    /** @return iterable<string, array{string}> */
    public static function notResources(): iterable
    {
        yield 'a local file' => ['file:///etc/passwd'];
        yield 'a PHP stream' => ['php://filter/resource=/etc/passwd'];
        yield 'a data URI' => ['data://text/plain;base64,SGVsbG8='];
        yield 'a class as a scheme' => ['App\Engine\Core\Application://x'];
    }

    #[DataProvider('notResources')]
    public function test_a_resource_uri_reaches_only_a_registered_resource(string $uri): void
    {
        $answer = $this->ask('resources/read', ['uri' => $uri], new Identity('7', 'ada', ['agent']));

        self::assertArrayHasKey('error', $answer);
        self::assertArrayNotHasKey('result', $answer);
    }

    // ---- path traversal ---------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function traversals(): iterable
    {
        yield 'dot segments' => ['customer://../../etc/passwd'];
        yield 'an encoded slash' => ['customer://..%2F..%2Fetc%2Fpasswd'];
        yield 'an encoded, doubly' => ['customer://..%252F..%252Fetc'];
        yield 'an encoded null' => ['customer://42%00.json'];
        yield 'a control character' => ['customer://42%0Aadmin'];
    }

    /** A template parameter is one path segment of printable text, or nothing matches. */
    #[DataProvider('traversals')]
    public function test_a_template_parameter_cannot_carry_a_path(string $uri): void
    {
        $answer = $this->ask('resources/read', ['uri' => $uri], new Identity('7', 'ada', ['agent']));

        if (isset($answer['result'])) {
            // Double encoding decodes once, to a literal "%2F" -- harmless, but
            // it must still be one segment with no slash in it.
            $text = (string) ($answer['result']['contents'][0]['text'] ?? '');
            self::assertStringNotContainsString('/', \str_replace('customer://', '', $text));

            return;
        }

        self::assertSame(-32002, $answer['error']['code'] ?? null);
    }

    // ---- unauthorized operations --------------------------------------------------------

    /** A refused capability is indistinguishable from a missing one. */
    public function test_denied_and_missing_answer_identically(): void
    {
        $denied = $this->ask('tools/call', ['name' => 'customer.get', 'arguments' => ['name' => 'x']]);
        $missing = $this->ask('tools/call', ['name' => 'customer.gone', 'arguments' => ['name' => 'x']]);

        self::assertSame($missing['error']['code'], $denied['error']['code']);
        self::assertSame($missing['error']['message'], $denied['error']['message']);
        self::assertSame(\array_keys($missing['error']['data']), \array_keys($denied['error']['data']));

        $tools = \array_column($this->ask('tools/list')['result']['tools'] ?? [], 'name');
        self::assertNotContains('customer.get', $tools, 'and it is not listed either');
    }

    public function test_a_guest_is_refused_everything_by_default(): void
    {
        $answer = $this->ask('tools/call', ['name' => 'greet', 'arguments' => ['name' => 'x']], Identity::guest());

        self::assertSame('Unknown tool.', $answer['error']['message'] ?? null);
        self::assertSame([], $this->ask('tools/list', identity: Identity::guest())['result']['tools'] ?? null);
    }

    /** Who is calling comes from the transport; nothing in a message can say otherwise. */
    public function test_a_client_cannot_name_its_own_identity(): void
    {
        $response = self::post(
            $this->http(),
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"zulu.greet","arguments":{"name":"x"},"_meta":{"user":"1","principal":"admin"}}}',
            ['X-User' => '1', 'X-Forwarded-User' => 'admin'],
        );

        self::assertSame('10', self::decoded($response)['result']['structuredContent']['asked_by'] ?? null, 'the token\'s owner, ada (10), and not "1" or "admin"');
    }

    public function test_a_token_in_the_query_string_is_not_a_credential(): void
    {
        $response = self::post($this->http(), '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Authorization' => ''], '/mcp?access_token=ada-token-do-not-use');

        self::assertSame(401, $response->status());
    }

    // ---- disabled module access ------------------------------------------------------------

    public function test_a_disabled_modules_capability_cannot_be_called(): void
    {
        $response = self::post($this->http(), '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"muted.greet","arguments":{"name":"x"}}}');

        self::assertSame('Unknown tool.', self::decoded($response)['error']['message'] ?? null);
    }

    // ---- sensitive output leakage ---------------------------------------------------------

    /** Even with debug on, a failure's message stays in the log. */
    public function test_a_failure_leaks_nothing_even_in_debug(): void
    {
        $response = self::post(
            $this->http(['app' => ['debug' => true]]),
            '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"customer://explode"}}',
        );

        self::assertSame(200, $response->status());
        self::assertArrayHasKey('error', self::decoded($response));

        foreach (['S3cret', '10.0.0.5', 'RuntimeException', '.php', '#0 '] as $secret) {
            self::assertStringNotContainsString($secret, $response->body());
        }
    }

    public function test_an_unknown_name_is_echoed_cut_so_it_cannot_amplify(): void
    {
        $answer = $this->ask('tools/call', ['name' => \str_repeat('x', 100_000), 'arguments' => []]);

        self::assertSame(128, \strlen($answer['error']['data']['tool'] ?? ''));
    }

    // ---- oversized input and transport abuse ------------------------------------------------

    public function test_a_message_over_the_limit_is_refused_before_it_is_parsed(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . \str_repeat('x', 1_100_000) . '"}}';

        $response = self::post($this->http(['security' => ['key' => Signer::generate(), 'max_request_bytes' => 4_000_000]]), $body);

        self::assertSame(400, $response->status());
        self::assertSame(-32600, self::decoded($response)['error']['code'] ?? null);
    }

    public function test_deeply_nested_json_is_a_parse_error_not_a_crash(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"a":' . \str_repeat('[', 5000) . \str_repeat(']', 5000) . '}}';

        $response = self::post($this->http(), $body);

        self::assertSame(400, $response->status());
        self::assertSame(-32700, self::decoded($response)['error']['code'] ?? null);
    }

    public function test_arguments_nested_past_the_limit_are_refused(): void
    {
        $nested = ['name' => 'x'];

        for ($i = 0; $i < 40; ++$i) {
            $nested = ['name' => 'x', 'deeper' => $nested];
        }

        $answer = $this->ask('tools/call', ['name' => 'greet', 'arguments' => $nested], new Identity('7', 'ada', ['agent']));

        self::assertArrayHasKey('error', $answer);
    }
}
