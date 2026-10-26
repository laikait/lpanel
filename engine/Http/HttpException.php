<?php

declare(strict_types=1);

namespace App\Engine\Http;

use App\Engine\Error\FrameworkException;

/**
 * An exception that carries the HTTP status it should become.
 *
 * This is how the kernel expresses 404, 405 and 400 without the router or the
 * dispatcher needing to know anything about how errors get rendered. The error
 * handler turns it into a response; nothing else has to special-case it.
 */
class HttpException extends FrameworkException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message === '' ? Response::statusText($status) : $message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function notFound(string $path = ''): self
    {
        return new self(404, $path === '' ? 'Not Found' : \sprintf('No route matches %s', $path));
    }

    /**
     * 405 must advertise what is allowed; the Allow header is not optional.
     *
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        \sort($allowed);

        return new self(
            405,
            \sprintf('Method not allowed. Allowed: %s', \implode(', ', $allowed)),
            ['Allow' => \implode(', ', $allowed)],
        );
    }

    public static function unsupportedMediaType(string $message = ''): self
    {
        return new self(415, $message);
    }

    /**
     * 401, for a request that has not said who it is.
     *
     * The pair with 403 is worth keeping straight, because getting it wrong
     * costs a client an afternoon: **401 means "say who you are", 403 means "I
     * know who you are and the answer is still no"**. Retrying a 401 with
     * credentials may work; retrying a 403 with the same ones never will.
     *
     * The WWW-Authenticate header is not optional in RFC 9110 -- a 401 without
     * one is malformed -- and Bearer is the scheme this framework actually
     * accepts. Basic is deliberately not offered: it would make browsers show a
     * dialog the application cannot style, cancel or explain.
     */
    public static function unauthorized(string $message = ''): self
    {
        return new self(
            401,
            $message === '' ? 'This endpoint needs a login.' : $message,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }

    /**
     * 403, for a request that was understood and refused.
     *
     * Distinct from 401, which the authentication phase owns: 401 means "say
     * who you are", 403 means "I know who you are and the answer is still no".
     * A failed CSRF check is this one -- the credentials were fine, the proof
     * of origin was not.
     */
    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    /**
     * 413, naming the limit rather than only refusing.
     *
     * A client that is told "too large" and not how large has to bisect its way
     * to the answer, and the usual outcome is that somebody decides the upload
     * feature is broken.
     */
    public static function payloadTooLarge(int $limit = 0, string $message = ''): self
    {
        if ($message === '') {
            $message = $limit > 0
                ? \sprintf('The request body is larger than the %d bytes this endpoint accepts.', $limit)
                : 'The request body is larger than this endpoint accepts.';
        }

        return new self(413, $message);
    }

    /**
     * 429, carrying Retry-After.
     *
     * The header is the whole point of the status. Without it a client that is
     * being throttled has no information except "not now", and a well-written
     * one will retry immediately, which is the behaviour the limit exists to
     * stop.
     *
     * @param array<string, string> $headers the limiter's own RateLimit-* headers
     */
    public static function tooManyRequests(int $retryAfter = 0, array $headers = []): self
    {
        return new self(
            429,
            $retryAfter > 0
                ? \sprintf('Too many requests. Try again in %d second(s).', $retryAfter)
                : 'Too many requests.',
            $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter, ...$headers] : $headers,
        );
    }

    /**
     * 406, carrying what the endpoint could have produced.
     *
     * The list is the whole value of the response. A client that asked for
     * something unavailable needs to know what to ask for instead, and a bare
     * "Not Acceptable" sends whoever is debugging it to the source.
     *
     * @param list<string> $offered
     */
    public static function notAcceptable(array $offered): self
    {
        return new self(406, \sprintf(
            'None of the media types this endpoint produces were acceptable. It can return: %s.',
            \implode(', ', $offered),
        ));
    }

    /**
     * 503: the application is down on purpose (see Core\Maintenance), or a
     * dependency is. Retry-After tells clients and crawlers when to come back.
     */
    public static function serviceUnavailable(int $retryAfter = 0, string $message = ''): self
    {
        return new self(503, $message, $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : []);
    }
}
