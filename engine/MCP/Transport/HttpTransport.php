<?php

declare(strict_types=1);

namespace App\Engine\MCP\Transport;

use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Protocol\ProtocolVersion;
use App\Engine\MCP\Protocol\Response as McpResponse;

/**
 * MCP over HTTP: one JSON-RPC message per POST to one configured path.
 *
 * The kernel asks handles() before routing, as it does for assets, so MCP
 * never becomes a route: route meta, CSRF, the route guard and the router's
 * 404/405 have nothing to say about it, and protocol semantics stay out of
 * REST routing. Everything else about a request -- the size limit, security
 * headers, the response filter -- applies as usual.
 *
 * **Off unless configured.** handles() is false while `mcp.transports.http`
 * is false, so installing the framework opens nothing.
 *
 * **Bearer tokens only.** The session cookie is never consulted: a cookie is a
 * credential the browser attaches on its own, and an endpoint that honoured it
 * would let any page the user visits call tools as the user. A request with no
 * valid token is 401 with `WWW-Authenticate: Bearer` -- unless guests are
 * allowed, and then it is a guest, who sees what guests may see.
 *
 * **Stateless.** There is no Mcp-Session-Id and nothing is kept between
 * requests: each POST is a session of one message, already initialized at the
 * version the client names in `MCP-Protocol-Version` (2025-03-26 when it names
 * none, as the specification says). An `initialize` is still answered, which
 * is how a client learns what is offered.
 *
 * **Status codes carry transport problems; JSON-RPC carries protocol ones.**
 *
 *     200  a request, answered (a tool's failure included)
 *     202  a notification or a client's response: accepted, no body
 *     400  unreadable JSON, an invalid message, an unsupported protocol version
 *     401  no valid bearer token
 *     403  an Origin header naming another site (DNS rebinding)
 *     405  anything but POST; no event stream is offered
 *     406  an Accept header that excludes JSON
 *     415  a body that is not application/json
 *
 * Every refusal carries a JSON-RPC error with a null id, which is what the MCP
 * specification allows and what a client can show.
 */
final class HttpTransport
{
    /**
     * What the specification says to assume when a client sends no version
     * header: the revision before the header existed.
     */
    public const ASSUMED_VERSION = '2025-03-26';

    public const VERSION_HEADER = 'MCP-Protocol-Version';

    /**
     * The server and the token check are closures, called on the first MCP
     * request: the kernel holds this transport for every request, and a page
     * must not build the user provider or the MCP runners to learn that it is
     * not an MCP request.
     *
     * @param \Closure(): McpServer                 $server
     * @param \Closure(): (TokenAuthenticator|null) $tokens null when the user provider has no tokens
     * @param (\Closure(string): void)|null         $diagnose told about anything printed during a message
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $path,
        private readonly \Closure $server,
        private readonly \Closure $tokens,
        private readonly bool $allowGuests = false,
        private readonly ?\Closure $diagnose = null,
        /**
         * The application's own origin, from APP_URL: what Origin is compared
         * with when it is known. Null falls back to the request's own scheme,
         * Host and port. See sameOrigin().
         */
        private readonly ?string $publicOrigin = null,
    ) {}

    public function handles(string $path): bool
    {
        return $this->enabled && $path === $this->path;
    }

    public function handle(Request $request): Response
    {
        if (!$this->sameOrigin($request)) {
            return self::refuse(403, 'Forbidden: the Origin is not this server.');
        }

        if ($request->method() !== 'POST') {
            return self::refuse(405, 'Method not allowed: send each message as a POST.')->withHeader('Allow', 'POST');
        }

        $identity = $this->identify($request);

        if ($identity === null) {
            return self::refuse(401, 'Unauthorized: a valid bearer token is required.')
                ->withHeader('WWW-Authenticate', 'Bearer realm="mcp"');
        }

        if (!self::acceptsJson($request)) {
            return self::refuse(406, 'Not acceptable: answers are application/json.');
        }

        if (self::mediaType($request->header('Content-Type')) !== 'application/json') {
            return self::refuse(415, 'Unsupported media type: send application/json.');
        }

        $version = $request->header(self::VERSION_HEADER) ?? self::ASSUMED_VERSION;

        if (!ProtocolVersion::isSupported($version)) {
            return self::refuse(400, 'Bad request: unsupported MCP protocol version.', [
                'supported' => ProtocolVersion::SUPPORTED,
            ]);
        }

        $session = new McpSession($identity, 'http');
        $session->initialize($version, '', '');

        $answer = $this->answer($request->body(), $session);

        if ($answer === null) {
            return new Response('', 202);
        }

        return self::json($answer, self::isUnaddressedError($answer) ? 400 : 200);
    }

    private function answer(string $body, McpSession $session): ?string
    {
        \ob_start();

        try {
            $answer = ($this->server)()->handle($body, $session);
        } finally {
            $stray = (string) \ob_get_clean();
        }

        if ($stray !== '' && $this->diagnose !== null) {
            ($this->diagnose)(\sprintf('A handler printed %d bytes during an MCP message; they were not sent.', \strlen($stray)));
        }

        return $answer;
    }

    /** Who is calling: a token's owner, a guest if guests are allowed, or nobody. */
    private function identify(Request $request): ?Identity
    {
        $identity = ($this->tokens)()?->identify($request);

        if ($identity !== null) {
            return $identity;
        }

        // A token that was sent and not accepted is never downgraded to a
        // guest: the client meant to be somebody, and should hear that it
        // is not.
        if ($this->allowGuests && TokenAuthenticator::tokenFrom($request) === null) {
            return Identity::guest();
        }

        return null;
    }

    /**
     * No Origin (a non-browser client) or one naming this application.
     *
     * A browser always sends Origin on a cross-site POST. Without this check a
     * page on the internet could reach an MCP server on localhost by rebinding
     * its own DNS name to 127.0.0.1.
     *
     * **What it is compared with matters.** Against the request's own Host
     * header the check proves nothing under rebinding: the attacker's page is
     * served from evil.example, the browser sends Origin evil.example *and*
     * Host evil.example, and the two agree. With APP_URL set the comparison is
     * with the application's real origin, which the attacker cannot make the
     * browser send. Without it, security.trusted_hosts is what refuses the
     * attacker's Host -- security:check says so.
     */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');

        if ($origin === null) {
            return true;
        }

        $sent = self::originOf($origin);

        if ($sent === null) {
            return false;
        }

        $expected = $this->publicOrigin !== null
            ? self::originOf($this->publicOrigin)
            : [$request->scheme(), \strtolower(\trim($request->host(), '[]')), $request->port()];

        return $expected !== null && $sent === $expected;
    }

    /**
     * An origin as [scheme, lower-cased host, port], with the default port filled in.
     *
     * @return array{string, string, int}|null
     */
    public static function originOf(string $url): ?array
    {
        $parts = \parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = \strtolower($parts['scheme']);

        return [$scheme, \strtolower(\trim($parts['host'], '[]')), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    private static function acceptsJson(Request $request): bool
    {
        $accept = $request->header('Accept');

        if ($accept === null || \trim($accept) === '') {
            return true;
        }

        foreach (\explode(',', $accept) as $range) {
            if (\in_array(self::mediaType($range), ['application/json', 'application/*', '*/*'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function mediaType(?string $value): string
    {
        return \strtolower(\trim(\explode(';', (string) $value)[0]));
    }

    /** A parse error or an invalid message: an error the client cannot match to a request. */
    private static function isUnaddressedError(string $answer): bool
    {
        $decoded = \json_decode($answer, true);

        return \is_array($decoded) && isset($decoded['error']) && ($decoded['id'] ?? null) === null;
    }

    /** @param array<string, mixed>|null $data */
    private static function refuse(int $status, string $message, ?array $data = null): Response
    {
        $error = new McpError(McpErrorCode::InvalidRequest, $message, $data);

        return self::json(McpResponse::error(null, $error)->toJson(), $status);
    }

    private static function json(string $body, int $status): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
        ]);
    }
}
