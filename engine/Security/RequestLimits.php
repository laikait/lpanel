<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;

/**
 * How much a request is allowed to be.
 *
 * Two checks, and the second is the interesting one.
 *
 * **A body over the limit is refused with 413.** Unbounded request bodies are
 * how a single client exhausts a server's memory: a JSON endpoint that decodes
 * whatever arrives will happily try to decode two hundred megabytes. The limit
 * is checked against Content-Length before anything reads the body, so the cost
 * of the refusal is a header parse.
 *
 * **A body PHP silently discarded is reported rather than ignored.** This is
 * the check worth having. When an upload exceeds post_max_size, PHP does not
 * fail: it hands the script an empty $_POST and an empty $_FILES, with
 * Content-Length still describing what was sent. The handler sees a form with
 * no fields and reports "name is required", the user swears the field was
 * filled in, and the actual cause is an ini setting nobody has looked at. The
 * symptom is indistinguishable from a bug in the application, so it is worth
 * the twenty lines to say what really happened.
 *
 * This runs on `request.received` and throws; the kernel turns an HttpException
 * into the right status without anything here knowing how errors are rendered.
 */
final class RequestLimits
{
    /** 8 MiB. Large enough for a form with a document, small enough to notice. */
    public const DEFAULT_BYTES = 8388608;

    /**
     * What a Host header may be: a DNS name, an IPv4 address or a bracketed
     * IPv6 one, then an optional port.
     *
     * Checked whatever trusted_hosts says, because the header is client input
     * that ends up in comparisons, cache keys and log lines: "evil.com/x",
     * "a b" or a name stuffed with quotes is never a host a browser sends.
     * Labels may hold an underscore, which DNS forbids but container networks
     * use; international names arrive as punycode, which this allows.
     */
    private const HOST_PATTERN = '/^(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?'
        . '(?:\.[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)*\.?)(?::[0-9]{1,5})?$/';

    /** Whether a Host header is shaped like a host at all; see HOST_PATTERN. An absent one is fine. */
    public static function isWellFormedHost(?string $header): bool
    {
        return $header === null || $header === '' || (\strlen($header) <= 260 && \preg_match(self::HOST_PATTERN, $header) === 1);
    }

    /**
     * @param list<string> $trustedHosts host names this application answers to
     *                                   ("example.com", "*.example.com"); empty accepts any
     */
    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_BYTES,
        private readonly bool $detectDiscardedBodies = true,
        private readonly array $trustedHosts = [],
    ) {}

    /** Whether $host is one this application answers to. */
    public function trustsHost(string $host): bool
    {
        if ($this->trustedHosts === []) {
            return true;
        }

        $host = \strtolower(\rtrim($host, '.'));

        foreach ($this->trustedHosts as $trusted) {
            $trusted = \strtolower($trusted);

            if ($host === $trusted
                || (\str_starts_with($trusted, '*.') && \str_ends_with($host, \substr($trusted, 1)))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the host names this application answers to; empty means any */
    public function trustedHosts(): array
    {
        return $this->trustedHosts;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** A human figure for `security:check`. */
    public function describe(): string
    {
        return self::format($this->maxBytes)
            . ($this->postMaxSize() > 0 && $this->postMaxSize() < $this->maxBytes
                ? \sprintf(' (php.ini post_max_size is lower, at %s)', self::format($this->postMaxSize()))
                : '');
    }

    public function __invoke(Request $request): void
    {
        if (!self::isWellFormedHost($request->header('Host'))) {
            throw HttpException::badRequest('The Host header is not a host name.');
        }

        if (!$this->trustsHost($request->host())) {
            throw HttpException::badRequest('The Host header is not one this application serves.');
        }

        if ($request->hasMalformedInput()) {
            throw HttpException::badRequest('The request contains text that is not valid UTF-8, or a NUL byte.');
        }

        // Over the limit whatever the headers said -- including a chunked body,
        // which has no Content-Length to judge it by. See Request::readBody().
        if ($request->bodyTooLarge()) {
            throw HttpException::payloadTooLarge($this->maxBytes);
        }

        $length = $this->contentLength($request);

        if ($length === null) {
            return;
        }

        if ($this->maxBytes > 0 && $length > $this->maxBytes) {
            throw HttpException::payloadTooLarge($this->maxBytes);
        }

        if ($this->detectDiscardedBodies && $this->wasDiscarded($request, $length)) {
            throw HttpException::payloadTooLarge(
                $this->postMaxSize(),
                'The request body exceeded php.ini post_max_size, so PHP discarded it before this '
                . 'application saw it. The form arrived with no fields at all.',
            );
        }
    }

    /**
     * A form body that arrived, and then was not there.
     *
     * Content-Length says something was sent, the method is one that carries a
     * body, the content type is a form -- and yet there are no fields and no
     * files. PHP does that exactly once, for exactly one reason.
     */
    private function wasDiscarded(Request $request, int $length): bool
    {
        if ($length <= 0 || !\in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return false;
        }

        $type = \strtolower($request->header('Content-Type') ?? '');

        if (!\str_contains($type, 'form-data') && !\str_contains($type, 'form-urlencoded')) {
            // A JSON body is read from the input stream, not parsed into
            // $_POST, so an empty one means an empty one.
            return false;
        }

        $limit = $this->postMaxSize();

        return $limit > 0 && $length > $limit && $request->input() === [] && $request->files() === [];
    }

    private function contentLength(Request $request): ?int
    {
        $header = $request->header('Content-Length');

        return $header !== null && \ctype_digit($header) ? (int) $header : null;
    }

    private function postMaxSize(): int
    {
        return self::toBytes((string) \ini_get('post_max_size'));
    }

    /** "8M" as 8388608. PHP's shorthand notation, which ini_get returns verbatim. */
    public static function toBytes(string $value): int
    {
        $value = \trim($value);

        if ($value === '' || !\preg_match('/^([0-9]+)\s*([KMG])?$/i', $value, $parts)) {
            return 0;
        }

        $bytes = (int) $parts[1];

        return match (\strtoupper($parts[2] ?? '')) {
            'G' => $bytes * 1073741824,
            'M' => $bytes * 1048576,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }

    public static function format(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return \round($bytes / $size, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' bytes';
    }
}
