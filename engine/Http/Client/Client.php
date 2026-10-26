<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Network\IpAddress;

/**
 * Outgoing HTTP: call another service and read its answer.
 *
 *     public function __construct(private readonly Client $http) {}
 *
 *     $charge = $this->http
 *         ->withToken($apiKey)
 *         ->timeout(10)
 *         ->retry(2)
 *         ->post('https://api.example.com/charges', ['amount' => 500])   // an array is sent as JSON
 *         ->throw()                                                       // 4xx/5xx become exceptions
 *         ->json('id');
 *
 * **Immutable.** Every with-method returns a new client, so one configured in
 * a constructor can be shared without a caller's token leaking into another's
 * request.
 *
 * **Redirects are followed here, not by the transport** -- up to five, and a
 * 301, 302 or 303 turns the request into a GET, as browsers do. That is what
 * lets publicOnly() check every hop, and makes both transports behave alike.
 *
 * **Retries are for failures that might pass**: no connection, a timeout, 429
 * and 5xx. Only GET, HEAD, PUT, DELETE and OPTIONS are retried unless
 * retry(..., always: true) says the request is safe to repeat -- a POST that
 * timed out may have charged the card. The wait doubles each time, and a
 * Retry-After header is honoured, up to a minute.
 *
 * **publicOnly() is for URLs somebody else supplied** -- a webhook address, an
 * avatar to fetch. The host is resolved and the request refused if any address
 * is private, loopback, link-local or reserved, so a user cannot point this
 * server at its own database or a cloud metadata endpoint.
 *
 * Extension points: the http.client.request filter receives each ClientRequest
 * before it is sent, and the http.client.sent hook announces method, host,
 * status and milliseconds afterwards. Neither is given the query string or a
 * header value, which is where tokens live -- the filter has the request
 * itself, because changing it is its purpose.
 */
final class Client
{
    public const DEFAULT_TIMEOUT = 30.0;

    public const MAX_REDIRECTS = 5;

    /** Methods that may be sent twice without doing anything twice. */
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /**
     * @param array<string, string>   $headers
     * @param ?\Closure(int): void    $sleep   milliseconds; replaced in tests
     * @param ?\Closure(string): list<string> $resolve host => addresses; replaced in tests
     */
    public function __construct(
        private readonly Transport $transport = new StreamTransport(),
        private readonly ?FilterEngine $filters = null,
        private readonly ?HookEngine $hooks = null,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly array $headers = [],
        private readonly string $baseUrl = '',
        private readonly bool $form = false,
        private readonly int $retries = 0,
        private readonly int $retryDelay = 100,
        private readonly bool $retryAlways = false,
        private readonly bool $publicOnly = false,
        private readonly ?\Closure $sleep = null,
        private readonly ?\Closure $resolve = null,
    ) {}

    // ---- configuring -------------------------------------------------------

    public function baseUrl(string $url): self
    {
        return $this->with(baseUrl: \rtrim($url, '/'));
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $merged = $this->headers;

        foreach ($headers as $name => $value) {
            self::assertHeader($name, $value);
            $merged = \array_filter($merged, static fn(string $key): bool => \strcasecmp($key, $name) !== 0, \ARRAY_FILTER_USE_KEY);
            $merged[$name] = $value;
        }

        return $this->with(headers: $merged);
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => $type . ' ' . $token]);
    }

    public function accept(string $mediaType): self
    {
        return $this->withHeaders(['Accept' => $mediaType]);
    }

    /** Send an array body as application/x-www-form-urlencoded instead of JSON. */
    public function asForm(): self
    {
        return $this->with(form: true);
    }

    public function timeout(float $seconds): self
    {
        return $this->with(timeout: \max(0.001, $seconds));
    }

    /**
     * Try again after a failure that might pass.
     *
     * @param int  $times        attempts after the first
     * @param int  $milliseconds the first wait; it doubles each time
     * @param bool $always       retry a POST or PATCH too -- only when sending it twice is harmless
     */
    public function retry(int $times, int $milliseconds = 100, bool $always = false): self
    {
        return $this->with(retries: \max(0, $times), retryDelay: \max(0, $milliseconds), retryAlways: $always);
    }

    /** Refuse any host -- or redirect -- that resolves to a non-public address. */
    public function publicOnly(): self
    {
        return $this->with(publicOnly: true);
    }

    // ---- sending -----------------------------------------------------------

    /** @param array<string, scalar|null> $query */
    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->send('GET', self::withQuery($url, $query));
    }

    /** @param array<string, scalar|null> $query */
    public function head(string $url, array $query = []): ClientResponse
    {
        return $this->send('HEAD', self::withQuery($url, $query));
    }

    /** @param array<mixed>|string|null $body an array is JSON, or a form after asForm() */
    public function post(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('POST', $url, $body);
    }

    /** @param array<mixed>|string|null $body */
    public function put(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('PUT', $url, $body);
    }

    /** @param array<mixed>|string|null $body */
    public function patch(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('PATCH', $url, $body);
    }

    /** @param array<mixed>|string|null $body */
    public function delete(string $url, array|string|null $body = null): ClientResponse
    {
        return $this->send('DELETE', $url, $body);
    }

    /**
     * @param array<mixed>|string|null $body
     *
     * @throws HttpClientException when there is no answer, or the request is refused before it leaves
     */
    public function send(string $method, string $url, array|string|null $body = null): ClientResponse
    {
        $request = $this->build(\strtoupper($method), $url, $body);

        if ($this->filters !== null) {
            /** @var ClientRequest $request */
            $request = $this->filters->apply('http.client.request', $request);
        }

        $attempt = 0;

        while (true) {
            $started = \microtime(true);

            try {
                $response = $this->follow($request);
                $failure = null;
            } catch (HttpClientException $e) {
                $response = null;
                $failure = $e;
            }

            $this->hooks?->do('http.client.sent', [
                'method' => $request->method,
                'host' => $request->host(),
                'status' => $response?->status(),
                'ms' => (int) \round((\microtime(true) - $started) * 1000),
                'attempt' => $attempt + 1,
            ]);

            $retryable = $failure !== null || $response === null || $response->status() === 429 || $response->serverError();

            if (!$retryable || $attempt >= $this->retries || !$this->mayRetry($request)) {
                if ($failure !== null) {
                    throw $failure;
                }

                /** @var ClientResponse $response */
                return $response;
            }

            $this->wait($this->delay($attempt, $response));
            ++$attempt;
        }
    }

    // ---- internals ---------------------------------------------------------

    /** @param array<mixed>|string|null $body */
    private function build(string $method, string $url, array|string|null $body): ClientRequest
    {
        $url = $this->baseUrl !== '' && !\preg_match('#^https?://#i', $url) ? $this->baseUrl . '/' . \ltrim($url, '/') : $url;
        $headers = ['User-Agent' => 'LPHP', 'Accept' => 'application/json, */*;q=0.8', ...$this->headers];

        if (\is_array($body)) {
            if ($this->form) {
                $content = \http_build_query($body, '', '&', \PHP_QUERY_RFC1738);
                $type = 'application/x-www-form-urlencoded';
            } else {
                try {
                    $content = \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
                } catch (\JsonException $e) {
                    throw HttpClientException::unencodable($e->getMessage());
                }

                $type = 'application/json';
            }

            $headers = self::setDefault($headers, 'Content-Type', $type);
        } else {
            $content = $body ?? '';
        }

        if ($content !== '' || \in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers = self::setDefault($headers, 'Content-Length', (string) \strlen($content));
        }

        return new ClientRequest($method, $url, $headers, $content, $this->timeout);
    }

    private function follow(ClientRequest $request): ClientResponse
    {
        for ($hop = 0; ; ++$hop) {
            // Every hop is checked afresh, and under publicOnly() it goes to the
            // very address that passed: a host looked up twice can answer the
            // second time with somewhere private.
            $checked = $this->assertSendable($request->url);

            $response = $this->transport->send($checked === null ? $request : $request->withAddress($checked));
            $location = $response->header('Location');

            if (!$response->redirect() || $location === null) {
                return $response;
            }

            if ($hop >= self::MAX_REDIRECTS) {
                throw HttpClientException::tooManyRedirects($request->url, self::MAX_REDIRECTS);
            }

            $next = self::resolveLocation($request->url, $location);
            $sameOrigin = self::origin($next) === self::origin($request->url);
            $request = \in_array($response->status(), [301, 302, 303], true) && $request->method !== 'HEAD'
                ? $request->asGet()->withUrl($next)
                : $request->withUrl($next);

            // Credentials go to the host they were meant for and no further.
            if (!$sameOrigin) {
                $request = new ClientRequest(
                    $request->method,
                    $request->url,
                    \array_filter($request->headers, static fn(string $name): bool => !\in_array(\strtolower($name), ['authorization', 'cookie', 'proxy-authorization'], true), \ARRAY_FILTER_USE_KEY),
                    $request->body,
                    $request->timeout,
                );
            }
        }
    }

    /**
     * Refuse what may not be sent.
     *
     * @return ?string under publicOnly(), the checked address to connect to; otherwise null
     */
    private function assertSendable(string $url): ?string
    {
        $parts = \parse_url($url);
        $scheme = \is_array($parts) ? \strtolower($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';

        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw HttpClientException::invalidUrl($url);
        }

        if (!$this->publicOnly) {
            return null;
        }

        // The host checked here has to be the host the transport connects to.
        // A user part or a backslash is where parse_url() and curl disagree
        // about which host a URL names -- http://a.example\@127.0.0.1/ -- so
        // a URL with either is refused rather than interpreted.
        $rest = (string) \preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url);
        $authority = \substr($rest, 0, \strcspn($rest, '/?#'));

        if (isset($parts['user']) || isset($parts['pass']) || \str_contains($authority, '@') || \str_contains($url, '\\')) {
            throw HttpClientException::invalidUrl($url);
        }

        $addresses = $this->resolve !== null ? ($this->resolve)($host) : self::addressesOf($host);

        if ($addresses === []) {
            throw HttpClientException::unresolvable($url);
        }

        foreach ($addresses as $address) {
            $parsed = IpAddress::tryParse($address);

            if ($parsed === null || !$parsed->isPublic()) {
                throw HttpClientException::notPublic($url, $address);
            }
        }

        return $addresses[0];
    }

    /**
     * Every address a host resolves to, IPv4 and IPv6. A host given as an
     * address resolves to itself.
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        $host = \trim($host, '[]');

        if (IpAddress::isValid($host)) {
            return [$host];
        }

        $addresses = \gethostbynamel($host) ?: [];
        $records = @\dns_get_record($host, \DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (\is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return \array_values(\array_unique($addresses));
    }

    private function mayRetry(ClientRequest $request): bool
    {
        return $this->retryAlways || \in_array($request->method, self::IDEMPOTENT, true);
    }

    /** Milliseconds before the next attempt: Retry-After when the server says, doubling otherwise. */
    private function delay(int $attempt, ?ClientResponse $response): int
    {
        $after = $response?->header('Retry-After');

        if ($after !== null) {
            $seconds = \ctype_digit($after) ? (int) $after : \max(0, (int) \strtotime($after) - \time());

            return \min($seconds, 60) * 1000;
        }

        return $this->retryDelay * (2 ** $attempt);
    }

    private function wait(int $milliseconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($milliseconds);
        } elseif ($milliseconds > 0) {
            \usleep($milliseconds * 1000);
        }
    }

    /** @param array<string, scalar|null> $query */
    private static function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url . (\str_contains($url, '?') ? '&' : '?') . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (\preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = \parse_url($base);
        $origin = self::origin($base);

        if (\str_starts_with($location, '//')) {
            return (\is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https') . ':' . $location;
        }

        if (\str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = \is_array($parts) ? ($parts['path'] ?? '/') : '/';

        return $origin . \substr($path, 0, (int) \strrpos($path, '/') + 1) . $location;
    }

    private static function origin(string $url): string
    {
        $parts = \parse_url($url);

        if (!\is_array($parts)) {
            return '';
        }

        return \strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function setDefault(array $headers, string $name, string $value): array
    {
        foreach (\array_keys($headers) as $key) {
            if (\strcasecmp($key, $name) === 0) {
                return $headers;
            }
        }

        $headers[$name] = $value;

        return $headers;
    }

    /** A header name or value with a line break would let a caller's input write a header of its own. */
    private static function assertHeader(string $name, string $value): void
    {
        if (\preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1 || \preg_match('/[\r\n\0]/', $value) === 1) {
            throw HttpClientException::unencodable(\sprintf('the header "%s" has a character no header may contain', \preg_replace('/[^\x20-\x7e]/', '?', $name)));
        }
    }

    /** @param array<string, string>|null $headers */
    private function with(
        ?float $timeout = null,
        ?array $headers = null,
        ?string $baseUrl = null,
        ?bool $form = null,
        ?int $retries = null,
        ?int $retryDelay = null,
        ?bool $retryAlways = null,
        ?bool $publicOnly = null,
    ): self {
        return new self(
            $this->transport,
            $this->filters,
            $this->hooks,
            $timeout ?? $this->timeout,
            $headers ?? $this->headers,
            $baseUrl ?? $this->baseUrl,
            $form ?? $this->form,
            $retries ?? $this->retries,
            $retryDelay ?? $this->retryDelay,
            $retryAlways ?? $this->retryAlways,
            $publicOnly ?? $this->publicOnly,
            $this->sleep,
            $this->resolve,
        );
    }
}
