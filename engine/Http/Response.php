<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * An HTTP response.
 *
 * Responses are immutable: every mutator returns a clone. That matters because
 * a response is handed to every listener on the response filter, and
 * immutability is what makes "which module changed this header?" an answerable
 * question instead of a debugging session.
 *
 * This class knows nothing about routing, and nothing about how it was
 * produced.
 */
class Response
{
    /** @var array<string, string> keyed by the lower-case header name */
    protected array $headers = [];

    /** @var list<Cookie> */
    protected array $cookies = [];

    protected bool $sent = false;

    /** @param array<string, string> $headers */
    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[Headers::normalize($name)] = Headers::sanitizeValue($value);
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withStatus(int $status): static
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(\sprintf('Invalid HTTP status code %d.', $status));
        }

        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function header(string $name): ?string
    {
        return $this->headers[Headers::normalize($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[Headers::normalize($name)]);
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

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[Headers::normalize($name)] = Headers::sanitizeValue($value);

        return $clone;
    }

    /**
     * Append to an existing header, comma-joined per RFC 9110.
     *
     * Set-Cookie is deliberately not expressible this way; use withCookie(),
     * which emits a separate line per cookie as that header requires.
     */
    public function withAddedHeader(string $name, string $value): static
    {
        $normalized = Headers::normalize($name);
        $existing = $this->headers[$normalized] ?? null;
        $value = Headers::sanitizeValue($value);

        $clone = clone $this;
        $clone->headers[$normalized] = $existing === null || $existing === ''
            ? $value
            : $existing . ', ' . $value;

        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[Headers::normalize($name)]);

        return $clone;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withBody(string $body): static
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function contentType(): ?string
    {
        return $this->header('Content-Type');
    }

    public function withContentType(string $type, string $charset = 'UTF-8'): static
    {
        return $this->withHeader(
            'Content-Type',
            $charset === '' ? $type : \sprintf('%s; charset=%s', $type, $charset),
        );
    }

    public function withCookie(Cookie $cookie): static
    {
        $clone = clone $this;
        $clone->cookies[] = $cookie;

        return $clone;
    }

    /** @return list<Cookie> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Write the response to the client.
     *
     * $omitBody exists for HEAD, which must carry the same headers as the GET
     * it mirrors but no body at all.
     */
    public function send(bool $omitBody = false): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;

        $this->sendHeaders();

        if (!$omitBody) {
            $this->sendBody();
        }
    }

    protected function sendHeaders(): void
    {
        if (\headers_sent()) {
            return;
        }

        \http_response_code($this->status);

        // PHP adds X-Powered-By: PHP/<version> when expose_php is on, which
        // tells a scanner exactly which interpreter bugs to try. Gone unless a
        // response sets one itself, below.
        \header_remove('X-Powered-By');

        foreach ($this->headers as $name => $value) {
            \header(Headers::canonical($name) . ': ' . $value, true);
        }

        foreach ($this->cookies as $cookie) {
            \header('Set-Cookie: ' . $cookie->toHeaderValue(), false);
        }
    }

    protected function sendBody(): void
    {
        echo $this->body;
    }

    public static function statusText(int $status): string
    {
        return match ($status) {
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            206 => 'Partial Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            409 => 'Conflict',
            410 => 'Gone',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            418 => "I'm a teapot",
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Unknown Status',
        };
    }
}
