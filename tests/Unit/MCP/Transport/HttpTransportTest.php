<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Transport;

use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\Transport\HttpTransport;
use App\Tests\Fixtures\Auth\FakeProvider;
use App\Tests\Fixtures\MCP\Servers;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HttpTransportTest extends TestCase
{
    private const TOKEN = 'mcp-token-do-not-use';

    /** @var list<string> */
    private array $diagnostics = [];

    private int $serversBuilt = 0;

    private function transport(bool $enabled = true, bool $allowGuests = false, bool $tokens = true): HttpTransport
    {
        $provider = (new FakeProvider())
            ->add('7', 'ada', roles: ['agent'])
            ->add('8', 'bob', active: false)
            ->addToken('7', self::TOKEN)
            ->addToken('8', 'bob-token');

        return new HttpTransport(
            $enabled,
            '/mcp',
            function (): McpServer {
                ++$this->serversBuilt;

                return Servers::make();
            },
            static fn(): ?TokenAuthenticator => $tokens ? new TokenAuthenticator($provider) : null,
            $allowGuests,
            function (string $diagnostic): void {
                $this->diagnostics[] = $diagnostic;
            },
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $body, array $headers = [], string $method = 'POST', ?HttpTransport $transport = null): Response
    {
        $request = Request::create($method, '/mcp', [
            'headers' => $headers + [
                'Host' => 'localhost',
                'Authorization' => 'Bearer ' . self::TOKEN,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ],
            'body' => $body,
        ]);

        return ($transport ?? $this->transport())->handle($request);
    }

    /** @return array<string, mixed> */
    private static function decoded(Response $response): array
    {
        $decoded = \json_decode($response->body(), true);
        self::assertIsArray($decoded, $response->body());

        return $decoded;
    }

    // ---- where it answers ---------------------------------------------------

    public function test_it_answers_only_its_own_path_and_only_when_enabled(): void
    {
        self::assertTrue($this->transport()->handles('/mcp'));
        self::assertFalse($this->transport()->handles('/mcp/'));
        self::assertFalse($this->transport()->handles('/mcp/tools'));
        self::assertFalse($this->transport()->handles('/'));
        self::assertFalse($this->transport(enabled: false)->handles('/mcp'), 'off means off');
    }

    public function test_asking_whether_it_handles_a_path_builds_nothing(): void
    {
        self::assertTrue($this->transport()->handles('/mcp'));
        self::assertSame(0, $this->serversBuilt);
    }

    // ---- answers ------------------------------------------------------------

    public function test_a_request_is_answered_as_json(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"greet","arguments":{"name":"Ada"}}}');

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('Hello, Ada', self::decoded($response)['result']['structuredContent']['greeting'] ?? null);
    }

    /** Stateless: no initialize first, and the token's owner is the caller. */
    public function test_each_request_stands_alone_as_the_token_owner(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        $names = \array_column(self::decoded($response)['result']['tools'] ?? [], 'name');

        self::assertContains('customer.get', $names, 'ada has the agent role');
        self::assertSame(1, $this->serversBuilt);
    }

    public function test_initialize_is_answered_so_a_client_can_learn_what_is_offered(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}');

        $result = self::decoded($response)['result'] ?? [];

        self::assertSame('2025-06-18', $result['protocolVersion'] ?? null);
        self::assertSame('test-server', $result['serverInfo']['name'] ?? null);
    }

    public function test_a_notification_is_accepted_with_no_body(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        self::assertSame(202, $response->status());
        self::assertSame('', $response->body());
    }

    /** A tool that failed is an answer, not a transport problem. */
    public function test_a_protocol_error_about_a_request_is_still_200(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":4,"method":"no/such"}');

        self::assertSame(200, $response->status());
        self::assertSame(-32601, self::decoded($response)['error']['code'] ?? null);
    }

    /** @return iterable<string, array{string, int}> */
    public static function unaddressed(): iterable
    {
        yield 'not JSON' => ['{broken', -32700];
        yield 'not a message' => ['{"hello":"world"}', -32600];
        yield 'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]', -32600];
        yield 'nothing' => ['', -32700];
    }

    #[DataProvider('unaddressed')]
    public function test_a_message_that_cannot_be_answered_by_id_is_400(string $body, int $code): void
    {
        $response = $this->post($body);

        self::assertSame(400, $response->status());
        self::assertSame($code, self::decoded($response)['error']['code'] ?? null);
        self::assertNull(self::decoded($response)['id'] ?? null);
    }

    // ---- versions -----------------------------------------------------------

    public function test_the_version_header_is_the_sessions_version(): void
    {
        $response = $this->post(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"greet","arguments":{"name":"Ada"}}}',
            [HttpTransport::VERSION_HEADER => '2024-11-05'],
        );

        self::assertSame(200, $response->status());
    }

    public function test_an_unsupported_version_is_400_and_says_which_are(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', [HttpTransport::VERSION_HEADER => '1999-01-01']);

        self::assertSame(400, $response->status());
        self::assertContains('2025-06-18', self::decoded($response)['error']['data']['supported'] ?? []);
        self::assertSame(0, $this->serversBuilt, 'refused before any message was handled');
    }

    // ---- who ----------------------------------------------------------------

    /** @return iterable<string, array{array<string, string>}> */
    public static function unauthenticated(): iterable
    {
        yield 'no token' => [['Authorization' => '']];
        yield 'an unknown token' => [['Authorization' => 'Bearer nobody-has-this']];
        yield 'an inactive account' => [['Authorization' => 'Bearer bob-token']];
        yield 'not a bearer token' => [['Authorization' => 'Basic YWRhOnNlY3JldA==']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('unauthenticated')]
    public function test_no_valid_token_is_401_with_a_challenge(array $headers): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', $headers);

        self::assertSame(401, $response->status());
        self::assertSame('Bearer realm="mcp"', $response->header('WWW-Authenticate'));
        self::assertStringNotContainsString('nobody-has-this', $response->body());
        self::assertSame(0, $this->serversBuilt);
    }

    /** A session cookie is a credential the browser attaches by itself. */
    public function test_a_session_cookie_is_not_a_credential_here(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', [
            'Authorization' => '',
            'Cookie' => 'lphp_session=anything',
        ]);

        self::assertSame(401, $response->status());
    }

    public function test_an_application_without_tokens_refuses_everyone(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', transport: $this->transport(tokens: false));

        self::assertSame(401, $response->status());
    }

    public function test_where_guests_are_allowed_no_token_is_a_guest(): void
    {
        $transport = $this->transport(allowGuests: true);

        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Authorization' => ''], transport: $transport);

        self::assertSame(200, $response->status());
    }

    /** The client meant to be somebody; being quietly treated as nobody would hide that. */
    public function test_a_rejected_token_is_never_downgraded_to_a_guest(): void
    {
        $transport = $this->transport(allowGuests: true);

        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Authorization' => 'Bearer nobody-has-this'], transport: $transport);

        self::assertSame(401, $response->status());
    }

    // ---- origin -------------------------------------------------------------

    /** @return iterable<string, array{string, int}> */
    public static function origins(): iterable
    {
        yield 'this server' => ['http://localhost', 200];
        yield 'this server, any case' => ['http://LocalHost', 200];
        yield 'this server, default port written out' => ['http://localhost:80', 200];
        yield 'another site' => ['https://evil.example', 403];
        yield 'another port' => ['http://localhost:8080', 403];
        yield 'another scheme' => ['https://localhost', 403];
        yield 'an opaque origin' => ['null', 403];
    }

    #[DataProvider('origins')]
    public function test_a_browser_origin_must_be_this_server(string $origin, int $status): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Origin' => $origin]);

        self::assertSame($status, $response->status());
    }

    /**
     * DNS rebinding: the attacker's page at evil.example, its name re-pointed
     * at this machine. The browser sends Origin evil.example and Host
     * evil.example, and they agree -- so against the Host header the check
     * passes. Against APP_URL's origin it cannot.
     */
    public function test_a_rebound_host_is_refused_when_the_public_origin_is_known(): void
    {
        $rebound = ['Origin' => 'http://evil.example', 'Host' => 'evil.example'];
        $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        self::assertSame(200, $this->post($ping, $rebound)->status(), 'against Host alone, rebinding gets through');

        $known = new HttpTransport(
            true,
            '/mcp',
            static fn(): McpServer => Servers::make(),
            static fn(): TokenAuthenticator => new TokenAuthenticator((new FakeProvider())->add('7', 'ada', roles: ['agent'])->addToken('7', self::TOKEN)),
            publicOrigin: 'https://app.example',
        );

        self::assertSame(403, $this->post($ping, $rebound, transport: $known)->status());
        self::assertSame(200, $this->post($ping, ['Origin' => 'https://app.example', 'Host' => 'app.example'], transport: $known)->status());
        self::assertSame(200, $this->post($ping, ['Origin' => 'https://APP.example:443'], transport: $known)->status());
    }

    // ---- the envelope -------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function otherMethods(): iterable
    {
        yield 'GET: no event stream is offered' => ['GET'];
        yield 'DELETE: there is no session to end' => ['DELETE'];
        yield 'PUT' => ['PUT'];
    }

    #[DataProvider('otherMethods')]
    public function test_anything_but_post_is_405(string $method): void
    {
        $response = $this->post('', method: $method);

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function contentTypes(): iterable
    {
        yield 'json' => ['application/json', 200];
        yield 'json with a charset' => ['application/json; charset=utf-8', 200];
        yield 'json, shouted' => ['APPLICATION/JSON', 200];
        yield 'a form' => ['application/x-www-form-urlencoded', 415];
        yield 'text' => ['text/plain', 415];
        yield 'nothing' => ['', 415];
    }

    #[DataProvider('contentTypes')]
    public function test_the_body_must_be_json(string $type, int $status): void
    {
        self::assertSame($status, $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Content-Type' => $type])->status());
    }

    /** @return iterable<string, array{string, int}> */
    public static function accepts(): iterable
    {
        yield 'json and a stream, as the specification says to send' => ['application/json, text/event-stream', 200];
        yield 'anything' => ['*/*', 200];
        yield 'nothing said' => ['', 200];
        yield 'a stream only' => ['text/event-stream', 406];
        yield 'html only' => ['text/html', 406];
    }

    #[DataProvider('accepts')]
    public function test_the_client_must_accept_json(string $accept, int $status): void
    {
        self::assertSame($status, $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Accept' => $accept])->status());
    }

    public function test_every_refusal_is_a_json_rpc_error_without_an_id(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Content-Type' => 'text/plain']);

        $decoded = self::decoded($response);

        self::assertSame('2.0', $decoded['jsonrpc'] ?? null);
        self::assertArrayHasKey('id', $decoded);
        self::assertNull($decoded['id']);
        self::assertSame(-32600, $decoded['error']['code'] ?? null);
    }

    // ---- output -------------------------------------------------------------

    /** Whatever a handler prints never reaches the body; it is reported instead. */
    public function test_output_from_a_handler_never_reaches_the_body(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"customer://noisy"}}');

        self::assertSame(200, $response->status());
        self::decoded($response);
        self::assertNotEmpty($this->diagnostics);
    }
}
