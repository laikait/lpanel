<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * What came back: a status, headers and a body.
 *
 *     $response->successful();          // 2xx
 *     $response->json('data.id');       // decoded, with a dotted path
 *     $response->header('ETag');
 *     $response->throw();               // HttpClientException on 4xx and 5xx; itself otherwise
 */
final class ClientResponse
{
    /** @var array<string, list<string>> lower-case name => values */
    private readonly array $headers;

    private mixed $decoded = null;

    private bool $isDecoded = false;

    /**
     * @param array<string, string|list<string>> $headers any case
     */
    public function __construct(
        private readonly int $status,
        array $headers = [],
        private readonly string $body = '',
        private readonly string $method = 'GET',
        private readonly string $url = '',
    ) {
        $normalised = [];

        foreach ($headers as $name => $value) {
            foreach (\is_array($value) ? $value : [$value] as $one) {
                $normalised[\strtolower((string) $name)][] = $one;
            }
        }

        $this->headers = $normalised;
    }

    /**
     * Status and headers from the raw lines PHP's http wrapper and curl report.
     * After a redirect the lines of every response are present; the last
     * status line starts the answer.
     *
     * @param list<string> $lines
     */
    public static function fromHeaderLines(array $lines, string $body, string $method, string $url): self
    {
        $status = 0;
        $headers = [];

        foreach ($lines as $line) {
            if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $headers = [];

                continue;
            }

            $colon = \strpos($line, ':');

            if ($colon !== false) {
                $headers[\trim(\substr($line, 0, $colon))][] = \trim(\substr($line, $colon + 1));
            }
        }

        return new self($status, $headers, $body, $method, $url);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)][0] ?? null;
    }

    /** @return array<string, list<string>> lower-case name => values */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The body decoded as JSON, or one value inside it by a dotted path. Null
     * when the body is not JSON or the path is not there.
     */
    public function json(?string $path = null): mixed
    {
        if (!$this->isDecoded) {
            try {
                $this->decoded = \json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->decoded = null;
            }

            $this->isDecoded = true;
        }

        $value = $this->decoded;

        foreach ($path === null || $path === '' ? [] : \explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function redirect(): bool
    {
        return \in_array($this->status, [301, 302, 303, 307, 308], true);
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    /**
     * @throws HttpClientException on a 4xx or 5xx, carrying this response
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw HttpClientException::status($this->method, $this->url, $this);
        }

        return $this;
    }
}
