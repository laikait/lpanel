<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

use App\Engine\Error\FrameworkException;

/**
 * An outgoing request that got no usable answer: it could not connect, ran out
 * of time, was refused before it left, or -- through ClientResponse::throw() --
 * was answered with an error status.
 *
 * Messages name the method, the host and the status, never the query string or
 * a header: either can carry a token.
 */
final class HttpClientException extends FrameworkException
{
    private ?ClientResponse $response = null;

    public static function connection(string $method, string $url, string $why): self
    {
        return new self(\sprintf('%s %s failed: %s.', $method, self::where($url), $why));
    }

    public static function timeout(string $method, string $url, float $seconds): self
    {
        return new self(\sprintf('%s %s did not answer within %s seconds.', $method, self::where($url), (string) $seconds));
    }

    public static function status(string $method, string $url, ClientResponse $response): self
    {
        $exception = new self(\sprintf('%s %s answered %d.', $method, self::where($url), $response->status()));
        $exception->response = $response;

        return $exception;
    }

    public static function invalidUrl(string $url): self
    {
        return new self(\sprintf('"%s" is not an http or https URL.', self::where($url)));
    }

    public static function notPublic(string $url, string $address): self
    {
        return new self(\sprintf(
            '%s resolves to %s, which is not a public address. publicOnly() refuses it, so a URL somebody '
            . 'supplied cannot reach this machine\'s own network.',
            self::where($url),
            $address,
        ));
    }

    public static function unresolvable(string $url): self
    {
        return new self(\sprintf('%s does not resolve to an address.', self::where($url)));
    }

    public static function tooManyRedirects(string $url, int $limit): self
    {
        return new self(\sprintf('%s redirected more than %d times.', self::where($url), $limit));
    }

    public static function unencodable(string $why): self
    {
        return new self(\sprintf('The request body cannot be encoded: %s.', $why));
    }

    public static function noResponseQueued(string $method, string $url): self
    {
        return new self(\sprintf('FakeTransport has no response for %s %s.', $method, self::where($url)));
    }

    /** The response, when the failure was an error status. */
    public function response(): ?ClientResponse
    {
        return $this->response;
    }

    /** scheme://host/path -- the query string is left out, because it is where tokens go. */
    public static function where(string $url): string
    {
        $parts = \parse_url($url);

        if (!\is_array($parts) || !isset($parts['host'])) {
            return \strtok($url, '?') ?: $url;
        }

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }
}
