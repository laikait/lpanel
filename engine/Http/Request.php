<?php

declare(strict_types=1);

namespace App\Engine\Http;

use App\Engine\Network\IpAddress;
use App\Engine\Network\IpSet;
use App\Engine\Security\Encrypter;
use App\Engine\Support\Unicode;

/**
 * An incoming HTTP request.
 *
 * Immutable, and deliberately ignorant of routing: there is no route(), no
 * routeParam(), no user(). Route parameters reach handlers as named arguments,
 * not by being stuffed in here. The generic attribute bag exists for
 * application use, and the dispatcher does not depend on it.
 */
final class Request
{
    /** How deeply a JSON body may nest before it is treated as malformed. */
    public const JSON_DEPTH = 32;

    private bool $jsonParsed = false;

    private mixed $json = null;

    private ?IpSet $proxies = null;

    /**
     * @param array<string, mixed>                            $query
     * @param array<string, mixed>                            $parsedBody
     * @param array<string, string>                           $headers    keyed by lower-case name
     * @param array<string, string>                           $cookies
     * @param array<string, UploadedFile|list<UploadedFile>>  $files
     * @param array<string, mixed>                            $server
     * @param array<string, mixed>                            $attributes
     * @param list<string>                                    $trustedProxies
     */
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly string $basePath,
        private readonly string $path,
        private readonly array $query,
        private readonly array $parsedBody,
        private readonly string $body,
        private readonly array $headers,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $server,
        private readonly array $attributes = [],
        private readonly array $trustedProxies = [],
        private readonly bool $bodyTooLarge = false,
    ) {}

    /**
     * @param list<string> $trustedProxies
     * @param int|null     $maxBodyBytes   read at most this much of the body; see readBody()
     */
    public static function fromGlobals(?string $basePath = null, array $trustedProxies = [], ?int $maxBodyBytes = null): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, string> $cookies */
        $cookies = $_COOKIE;
        /** @var array<string, mixed> $files */
        $files = $_FILES;

        [$body, $tooLarge] = self::readBody($maxBodyBytes);

        return self::build(
            method: self::readMethod($server),
            server: $server,
            query: $query,
            parsedBody: $post,
            body: $body,
            headers: Headers::fromServer($server),
            cookies: $cookies,
            files: UploadedFile::normalizeAll($files),
            basePath: $basePath,
            trustedProxies: $trustedProxies,
            bodyTooLarge: $tooLarge,
        );
    }

    /**
     * The raw body, read no further than the limit.
     *
     * The size limit used to be judged from Content-Length after the whole
     * body had already been read into memory -- and a request without the
     * header (chunked transfer) was not judged at all. One byte past the limit
     * is read, so "exactly the limit" and "over it" can be told apart, and
     * what is over is dropped rather than kept: RequestLimits answers 413 for
     * it before anything parses it.
     *
     * @param string $source php://input; a file in a test, since the CLI has no request body
     *
     * @return array{string, bool} the body, and whether it was over the limit
     */
    private static function readBody(?int $maxBytes, string $source = 'php://input'): array
    {
        if ($maxBytes === null || $maxBytes <= 0) {
            return [(string) \file_get_contents($source), false];
        }

        $stream = @\fopen($source, 'rb');

        if ($stream === false) {
            return ['', false];
        }

        try {
            $body = (string) \stream_get_contents($stream, $maxBytes + 1);
        } finally {
            \fclose($stream);
        }

        return \strlen($body) > $maxBytes ? ['', true] : [$body, false];
    }

    /**
     * Whether the body was longer than the limit fromGlobals() was given, and
     * so was not kept. RequestLimits turns this into a 413.
     */
    public function bodyTooLarge(): bool
    {
        return $this->bodyTooLarge;
    }

    /**
     * Build a request directly. This is what tests and the CLI use, and it takes
     * exactly the same path through base-path derivation as fromGlobals().
     *
     * @param array{
     *     query?: array<string, mixed>,
     *     body?: array<string, mixed>|string,
     *     headers?: array<string, string>,
     *     cookies?: array<string, string>,
     *     files?: array<string, UploadedFile|list<UploadedFile>>,
     *     server?: array<string, mixed>,
     *     basePath?: string,
     *     trustedProxies?: list<string>,
     *     maxBodyBytes?: int
     * } $options
     */
    public static function create(string $method, string $uri, array $options = []): self
    {
        $server = $options['server'] ?? [];
        $server['REQUEST_URI'] ??= $uri;
        $server['REQUEST_METHOD'] ??= \strtoupper($method);

        $headers = [];

        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[Headers::normalize($name)] = $value;
        }

        $rawBody = $options['body'] ?? '';
        $parsedBody = \is_array($rawBody) ? $rawBody : [];
        $body = \is_string($rawBody) ? $rawBody : \http_build_query($rawBody);

        if (\is_array($rawBody) && $rawBody !== [] && !isset($headers['content-type'])) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        // The same rule fromGlobals() applies to the input stream, so a test
        // can build the request a client sent too much for.
        $maxBodyBytes = $options['maxBodyBytes'] ?? null;
        $bodyTooLarge = \is_int($maxBodyBytes) && $maxBodyBytes > 0 && \strlen($body) > $maxBodyBytes;

        if ($bodyTooLarge) {
            $body = '';
            $parsedBody = [];
        }

        $query = $options['query'] ?? [];

        if ($query === [] && \str_contains($uri, '?')) {
            $parsed = [];
            \parse_str((string) \substr($uri, (int) \strpos($uri, '?') + 1), $parsed);

            // parse_str() produces integer keys for names like "0"; the rest of
            // the request treats parameter names as strings, so normalise here.
            foreach ($parsed as $name => $value) {
                $query[(string) $name] = $value;
            }
        }

        return self::build(
            method: \strtoupper($method),
            server: $server,
            query: $query,
            parsedBody: $parsedBody,
            body: $body,
            headers: $headers,
            cookies: $options['cookies'] ?? [],
            files: $options['files'] ?? [],
            basePath: $options['basePath'] ?? null,
            trustedProxies: $options['trustedProxies'] ?? [],
            bodyTooLarge: $bodyTooLarge,
        );
    }

    /**
     * @param array<string, mixed>                           $server
     * @param array<string, mixed>                           $query
     * @param array<string, mixed>                           $parsedBody
     * @param array<string, string>                          $headers
     * @param array<string, string>                          $cookies
     * @param array<string, UploadedFile|list<UploadedFile>> $files
     * @param list<string>                                   $trustedProxies
     */
    private static function build(
        string $method,
        array $server,
        array $query,
        array $parsedBody,
        string $body,
        array $headers,
        array $cookies,
        array $files,
        ?string $basePath,
        array $trustedProxies,
        bool $bodyTooLarge = false,
    ): self {
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $resolvedBase = $basePath ?? self::deriveBasePath($server);

        return new self(
            method: $method,
            uri: $uri,
            basePath: $resolvedBase,
            path: self::derivePath($uri, $resolvedBase),
            query: self::nfcAll($query),
            parsedBody: self::nfcAll($parsedBody),
            body: $body,
            headers: $headers,
            cookies: self::nfcAll($cookies),
            files: $files,
            server: $server,
            trustedProxies: $trustedProxies,
            bodyTooLarge: $bodyTooLarge,
        );
    }

    /**
     * Every string in $values in NFC, so the same word typed on two keyboards
     * is the same bytes to a comparison, a unique index and a rate-limit key.
     * Text that is not valid UTF-8 is left as it is; RequestLimits refuses it.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function nfcAll(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = Unicode::nfc($value);
            } elseif (\is_array($value)) {
                $values[$key] = self::nfcAll($value);
            }
        }

        return $values;
    }

    /**
     * Whether any name or value in the query, the body or the cookies is not
     * valid UTF-8 or contains a NUL byte -- which no form field has a use for,
     * and which truncates a string inside the C functions behind file and
     * database calls.
     */
    public function hasMalformedInput(): bool
    {
        return self::malformed($this->query)
            || self::malformed($this->input())
            || self::malformed($this->cookies);
    }

    /** @param array<array-key, mixed> $values */
    private static function malformed(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && !self::clean($key)) {
                return true;
            }

            if (\is_string($value) ? !self::clean($value) : (\is_array($value) && self::malformed($value))) {
                return true;
            }
        }

        return false;
    }

    private static function clean(string $text): bool
    {
        return !\str_contains($text, "\0") && \mb_check_encoding($text, 'UTF-8');
    }

    /**
     * Work out the prefix of the URI that addresses the front controller rather
     * than the application.
     *
     * Four deployments have to come out right:
     *
     *   DocumentRoot=public/  SCRIPT_NAME=/index.php                 URI=/customers  -> ""
     *   forwarded to public/  SCRIPT_NAME=/framework/public/index.php
     *                                                   URI=/framework/customers     -> "/framework"
     *                         (the project directory is served and its .htaccess rewrites into
     *                         public/, so the visitor never sees "/public" and neither may a URL)
     *   no rewrite            SCRIPT_NAME=/framework/public/index.php
     *                                                   URI=/framework/public/index.php/customers
     *                                                                                -> "/framework/public/index.php"
     *   php -S + server       SCRIPT_NAME=/index.php                 URI=/customers  -> ""
     *                         (the server router sets it; PHP's own guess for /templates or
     *                         /customers.json is the path itself, which would be swallowed whole)
     *
     * Public because the answer is needed before a Request exists: the asset
     * manager builds URLs whether or not this process is serving a request, and
     * they have to come out identical to the ones built while it is. Two
     * implementations of this derivation would be two subtly different answers.
     *
     * @param array<string, mixed> $server
     */
    public static function basePathFrom(array $server): string
    {
        return self::deriveBasePath($server);
    }

    /** @param array<string, mixed> $server */
    private static function deriveBasePath(array $server): string
    {
        $scriptName = \is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '';
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $uri = (string) \strtok($uri, '?');

        if ($uri === '') {
            $uri = '/';
        }

        // The front controller is named in the URI: no rewriting happened.
        if ($scriptName !== '' && \str_starts_with($uri, $scriptName)) {
            return $scriptName;
        }

        $directory = \rtrim(\str_replace('\\', '/', \dirname($scriptName)), '/');

        if ($directory !== '' && ($uri === $directory || \str_starts_with($uri, $directory . '/'))) {
            return $directory;
        }

        // Forwarded into public/ by the project directory's .htaccess: the script
        // is in public/, the address the visitor used is its parent.
        if (\str_ends_with($directory, '/public')) {
            $parent = \substr($directory, 0, -\strlen('/public'));

            if ($parent !== '' && ($uri === $parent || \str_starts_with($uri, $parent . '/'))) {
                return $parent;
            }
        }

        return '';
    }

    private static function derivePath(string $uri, string $basePath): string
    {
        $path = (string) \strtok($uri, '?');

        if ($basePath !== '' && \str_starts_with($path, $basePath)) {
            $path = \substr($path, \strlen($basePath));
        }

        // Decoded, then normalised: a path in any language is compared as the
        // same bytes however the visitor's system happened to spell it.
        $path = Unicode::nfc(\rawurldecode($path));
        $path = '/' . \ltrim($path, '/');

        // A trailing slash is not a different resource, but "/" itself is.
        return $path === '/' ? $path : \rtrim($path, '/');
    }

    /** @param array<string, mixed> $server */
    private static function readMethod(array $server): string
    {
        $method = \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';

        return \strtoupper($method);
    }

    // ---- request line ----------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === \strtoupper($method);
    }

    /** The raw REQUEST_URI, exactly as it arrived. */
    public function uri(): string
    {
        return $this->uri;
    }

    /** The routable path: base stripped, percent-decoded, no trailing slash. */
    public function path(): string
    {
        return $this->path;
    }

    /** The prefix that addresses the front controller, e.g. "/framework". */
    public function basePath(): string
    {
        return $this->basePath;
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function host(): string
    {
        $host = $this->header('Host');

        if ($host === null || $host === '') {
            $name = $this->server('SERVER_NAME');

            return \is_string($name) ? $name : 'localhost';
        }

        // Strip the port; host() is the name, port() is the port.
        return (string) \preg_replace('/:\d+$/', '', $host);
    }

    public function port(): int
    {
        $host = $this->header('Host');

        if ($host !== null && \preg_match('/:(\d+)$/', $host, $matches) === 1) {
            return (int) $matches[1];
        }

        $port = $this->server('SERVER_PORT');

        if (\is_numeric($port)) {
            return (int) $port;
        }

        return $this->isSecure() ? 443 : 80;
    }

    public function isSecure(): bool
    {
        $https = $this->server('HTTPS');

        if (\is_string($https) && $https !== '' && \strtolower($https) !== 'off') {
            return true;
        }

        if ((int) $this->server('SERVER_PORT', 0) === 443) {
            return true;
        }

        if ($this->fromTrustedProxy()) {
            return \strtolower((string) $this->header('X-Forwarded-Proto', '')) === 'https';
        }

        return false;
    }

    public function url(): string
    {
        $port = $this->port();
        $standard = ($this->isSecure() && $port === 443) || (!$this->isSecure() && $port === 80);

        return \sprintf(
            '%s://%s%s%s',
            $this->scheme(),
            $this->host(),
            $standard ? '' : ':' . $port,
            $this->uri,
        );
    }

    // ---- input -----------------------------------------------------------

    /** @return ($key is null ? array<string, mixed> : mixed) */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Body input, whether the client sent a form or JSON.
     *
     * Query parameters are deliberately NOT merged in: "where did this value
     * come from?" should have one answer.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->parsedBody;

        if ($data === [] && $this->isJson()) {
            $decoded = $this->json();
            $data = \is_array($decoded) ? $decoded : [];
        }

        if ($key === null) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        return $data[$key] ?? $default;
    }

    /**
     * The decoded JSON body, or null when the body is absent or malformed.
     *
     * Malformed JSON returns null rather than throwing: deciding whether that
     * is a 400 belongs to the handler, not to the request object.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->jsonParsed) {
            $this->jsonParsed = true;
            // Nested no deeper than any real payload is; json_decode() returns
            // null past it, which is "malformed" to every caller.
            $this->json = $this->body === '' ? null : \json_decode($this->body, true, self::JSON_DEPTH);

            if (\json_last_error() !== \JSON_ERROR_NONE) {
                $this->json = null;
            }
        }

        if ($key === null) {
            return $this->json;
        }

        return \is_array($this->json) ? ($this->json[$key] ?? $default) : $default;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isJson(): bool
    {
        return \str_contains(\strtolower((string) $this->header('Content-Type', '')), 'json');
    }

    // ---- headers, cookies, files ----------------------------------------

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[Headers::normalize($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[Headers::normalize($name)]);
    }

    /**
     * Whether If-None-Match names this entity tag: the tag itself, its weak
     * form (W/"…", which is what a compressing proxy turns a strong tag
     * into), or "*". Null when the header is absent, so a caller can fall
     * back to If-Modified-Since; RFC 9110 says to ignore that one otherwise.
     *
     * @param string $etag quoted, as sent: "\"abc\""
     */
    public function matchesEtag(string $etag): ?bool
    {
        $noneMatch = $this->header('If-None-Match');

        if ($noneMatch === null || $noneMatch === '') {
            return null;
        }

        foreach (\explode(',', $noneMatch) as $candidate) {
            $candidate = \trim($candidate);

            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> keyed by the canonical header name */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[Headers::canonical($name)] = $value;
        }

        return $headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * A cookie set with Cookie::encrypted(), decrypted: null when it is
     * missing, was changed, was copied from another cookie, or was made with a
     * key this application no longer has.
     */
    public function decryptedCookie(Encrypter $encrypter, string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return $value === null || $value === '' ? null : $encrypter->decrypt($value, Cookie::purpose($name));
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function file(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;

        if ($file instanceof UploadedFile) {
            return $file;
        }

        return \is_array($file) ? ($file[0] ?? null) : null;
    }

    /** @return array<string, UploadedFile|list<UploadedFile>> */
    public function files(): array
    {
        return $this->files;
    }

    // ---- environment -----------------------------------------------------

    /** @return ($key is null ? array<string, mixed> : mixed) */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    /**
     * The client address.
     *
     * X-Forwarded-For is honoured only when REMOTE_ADDR is itself a configured
     * trusted proxy. Trusting it unconditionally would let any client claim any
     * address, which quietly breaks rate limiting and audit logs.
     *
     * Even then the header is read from the right. Each proxy appends the
     * address it received the request from, so the right-hand end was written
     * by proxies this application trusts and the left-hand end by whoever sent
     * the request -- who can put anything there. The client is the first
     * address, reading leftwards, that is not a trusted proxy. Reading stops at
     * an entry that is not an address, since nothing trusted wrote it.
     */
    public function ip(): ?string
    {
        $remote = $this->server('REMOTE_ADDR');
        $remote = \is_string($remote) ? $remote : null;

        if (!$this->fromTrustedProxy()) {
            return $remote;
        }

        $client = $remote;

        foreach (\array_reverse($this->forwardedFor()) as $entry) {
            $address = IpAddress::tryParse($entry);

            if ($address === null) {
                break;
            }

            $client = $address->toString();

            if (!$this->trustedProxies()->contains($address)) {
                break;
            }
        }

        return $client;
    }

    /**
     * The client address, parsed: null when there is none or it cannot be read.
     *
     * An IPv4 address that a dual-stack socket reported as ::ffff:a.b.c.d is
     * returned as the IPv4 address it is.
     */
    public function ipAddress(): ?IpAddress
    {
        $ip = $this->ip();
        $address = $ip === null ? null : IpAddress::tryParse($ip);

        return $address?->toV4() ?? $address;
    }

    /**
     * Every address the request passed through, client first and REMOTE_ADDR
     * last. X-Forwarded-For is included only from a trusted proxy, and the
     * addresses at its left-hand end are whatever the client claimed: for the
     * one to believe, use ip().
     *
     * @return list<string>
     */
    public function ips(): array
    {
        $remote = $this->server('REMOTE_ADDR');
        $chain = $this->fromTrustedProxy() ? $this->forwardedFor() : [];

        if (\is_string($remote) && $remote !== '') {
            $chain[] = $remote;
        }

        return $chain;
    }

    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    public function isAjax(): bool
    {
        return \strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Whether a JSON error body is more useful to this client than an HTML one.
     *
     * Two shortcuts first, because both are statements rather than preferences:
     * a client that sent JSON can read JSON, and XMLHttpRequest is not going to
     * render a page. Otherwise the Accept header decides, through real
     * negotiation rather than a substring test -- which is the difference
     * between honouring
     *
     *     Accept: application/json;q=0.9, text/html;q=0.8
     *
     * and serving that client a web page because "text/html" appears in the
     * string somewhere.
     *
     * HTML is the tie-break, so a browser sending Accept: *\/* still gets a page.
     */
    public function expectsJson(): bool
    {
        if ($this->isAjax() || $this->isJson()) {
            return true;
        }

        return Negotiator::prefersJson($this->header('Accept'));
    }

    /**
     * Negotiate what this endpoint should produce, or refuse with a 406.
     *
     * On Request because that is where a handler already is, and because the
     * offers belong next to the code that can produce them. Nothing negotiates
     * on the handler's behalf: the framework does not know what a route can
     * return.
     *
     * @param list<string> $offered most preferred first
     *
     * @throws HttpException 406
     */
    public function negotiate(array $offered): string
    {
        return Negotiator::require($this, $offered);
    }

    /**
     * Refuse a request body this endpoint cannot read, with a 415.
     *
     * Worth the line it costs: without it a POST whose Content-Type is
     * text/plain reaches the schema as an empty payload, and the client is told
     * its fields are missing -- which sends whoever is debugging it to look at
     * the fields instead of at the header.
     *
     * @param list<string> $accepted
     *
     * @throws HttpException 415
     */
    public function requirePayload(array $accepted = ['application/json']): void
    {
        Negotiator::requirePayload($this, $accepted);
    }

    /**
     * Whether REMOTE_ADDR is one of the configured trusted proxies.
     *
     * The one answer to "may a forwarded header be believed", so X-Forwarded-For
     * and a CDN's country header are trusted under the same rule. An entry is
     * an address or a CIDR block, of either version: "10.0.0.0/8" trusts a
     * whole private network, and a CDN's published ranges can be listed as
     * they are published.
     */
    public function fromTrustedProxy(): bool
    {
        if ($this->trustedProxies === []) {
            return false;
        }

        $remote = $this->server('REMOTE_ADDR');

        return \is_string($remote) && $this->trustedProxies()->contains($remote);
    }

    /**
     * Configuration is checked by security:check, which is where a typo is
     * reported; here an unreadable entry is skipped rather than failing
     * every request.
     */
    private function trustedProxies(): IpSet
    {
        return $this->proxies ??= IpSet::lenient($this->trustedProxies);
    }

    /**
     * X-Forwarded-For as a list, left to right, with any port removed:
     * some load balancers write "203.0.113.9:51234".
     *
     * @return list<string>
     */
    private function forwardedFor(): array
    {
        $header = (string) $this->header('X-Forwarded-For', '');
        $entries = [];

        foreach (\explode(',', $header) as $entry) {
            $entry = \trim($entry);

            if ($entry === '') {
                continue;
            }

            if (\preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $entry, $match) === 1
                || \preg_match('/^\[([^\]]+)\](?::\d+)?$/', $entry, $match) === 1) {
                $entry = $match[1];
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    // ---- attributes ------------------------------------------------------

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            method: $this->method,
            uri: $this->uri,
            basePath: $this->basePath,
            path: $this->path,
            query: $this->query,
            parsedBody: $this->parsedBody,
            body: $this->body,
            headers: $this->headers,
            cookies: $this->cookies,
            files: $this->files,
            server: $this->server,
            attributes: $attributes,
            trustedProxies: $this->trustedProxies,
            bodyTooLarge: $this->bodyTooLarge,
        );
    }
}
