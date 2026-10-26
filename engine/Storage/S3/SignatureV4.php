<?php

declare(strict_types=1);

namespace App\Engine\Storage\S3;

use App\Engine\Security\Signer;

/**
 * AWS Signature Version 4: how S3, R2, Spaces, B2 and MinIO know a request is yours.
 *
 *     $sig = new SignatureV4($key, $secret, 'eu-central-1');
 *
 *     $headers = $sig->authorize('PUT', $url, ['x-amz-content-sha256' => $hash], $hash);
 *     $link    = $sig->presign('GET', $url, 600);
 *
 * Written out rather than taken from the AWS SDK, which is forty megabytes for
 * the four hundred lines a bucket needs. It is checked against AWS's own
 * published examples (tests/Unit/Storage/SignatureV4Test.php).
 *
 * **What is signed** is the method, the path, the query, the payload's hash and
 * the headers handed to authorize() plus host and x-amz-date -- never
 * whatever else the HTTP client adds on the way out, so a User-Agent or an
 * http.client.request filter cannot break a signature.
 *
 * The URL must already be percent-encoded the way it will be sent; S3 signs
 * the path as it arrives, without normalising it.
 */
final class SignatureV4
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /** The longest a presigned URL may live: seven days. */
    public const MAX_EXPIRY = 604800;

    public function __construct(
        private readonly string $key,
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {}

    /**
     * The headers to send: those given, plus Host, X-Amz-Date and Authorization.
     *
     * @param array<string, string> $headers headers to sign as well
     *
     * @return array<string, string>
     */
    public function authorize(string $method, string $url, array $headers, string $payloadHash, ?\DateTimeImmutable $now = null): array
    {
        $time = self::time($now);
        $parts = self::parse($url);

        $signed = ['host' => $parts['host']];

        foreach ($headers as $name => $value) {
            $signed[\strtolower($name)] = $value;
        }

        $signed['x-amz-date'] = $time;

        [$canonicalHeaders, $signedHeaders] = self::headers($signed);
        $canonical = \implode("\n", [
            \strtoupper($method),
            $parts['path'],
            self::query($parts['query']),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $this->scope($time);
        $signature = $this->signature($time, $scope, $canonical);

        // Given spellings of these two are replaced, not sent twice.
        $out = \array_filter($headers, static fn(string $name): bool => !\in_array(\strtolower($name), ['host', 'x-amz-date'], true), \ARRAY_FILTER_USE_KEY);
        $out['Host'] = $parts['host'];
        $out['X-Amz-Date'] = $time;
        $out['Authorization'] = \sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->key,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $out;
    }

    /**
     * A URL anyone can use, without credentials, until it expires.
     *
     * @param int $seconds how long it works, at most seven days
     */
    public function presign(string $method, string $url, int $seconds, ?\DateTimeImmutable $now = null): string
    {
        $time = self::time($now);
        $parts = self::parse($url);
        $scope = $this->scope($time);

        $query = [
            ...self::pairs($parts['query']),
            ['X-Amz-Algorithm', self::ALGORITHM],
            ['X-Amz-Credential', $this->key . '/' . $scope],
            ['X-Amz-Date', $time],
            ['X-Amz-Expires', (string) \max(1, \min(self::MAX_EXPIRY, $seconds))],
            ['X-Amz-SignedHeaders', 'host'],
        ];

        $canonicalQuery = self::encodePairs($query);
        [$canonicalHeaders, $signedHeaders] = self::headers(['host' => $parts['host']]);
        $canonical = \implode("\n", [
            \strtoupper($method),
            $parts['path'],
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            self::UNSIGNED_PAYLOAD,
        ]);

        return $parts['scheme'] . '://' . $parts['host'] . $parts['path']
            . '?' . $canonicalQuery . '&X-Amz-Signature=' . $this->signature($time, $scope, $canonical);
    }

    private function scope(string $time): string
    {
        return \substr($time, 0, 8) . '/' . $this->region . '/' . $this->service . '/aws4_request';
    }

    private function signature(string $time, string $scope, string $canonical): string
    {
        $toSign = \implode("\n", [self::ALGORITHM, $time, $scope, \hash('sha256', $canonical)]);

        $key = Signer::hmac('sha256', \substr($time, 0, 8), 'AWS4' . $this->secret, true);
        $key = Signer::hmac('sha256', $this->region, $key, true);
        $key = Signer::hmac('sha256', $this->service, $key, true);
        $key = Signer::hmac('sha256', 'aws4_request', $key, true);

        return Signer::hmac('sha256', $toSign, $key);
    }

    private static function time(?\DateTimeImmutable $now): string
    {
        return ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** @return array{scheme: string, host: string, path: string, query: string} */
    private static function parse(string $url): array
    {
        $parts = \parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an absolute URL.', $url));
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $host .= ':' . $port;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'path' => ($parts['path'] ?? '') === '' ? '/' : $parts['path'],
            'query' => $parts['query'] ?? '',
        ];
    }

    /**
     * @param array<string, string> $headers lowercase names
     *
     * @return array{string, string} the canonical block, and the signed names
     */
    private static function headers(array $headers): array
    {
        \ksort($headers, \SORT_STRING);
        $lines = '';

        foreach ($headers as $name => $value) {
            $lines .= $name . ':' . \trim((string) \preg_replace('/\s+/', ' ', $value)) . "\n";
        }

        return [$lines, \implode(';', \array_keys($headers))];
    }

    private static function query(string $query): string
    {
        return self::encodePairs(self::pairs($query));
    }

    /** @return list<array{string, string}> decoded name => value pairs, in order */
    private static function pairs(string $query): array
    {
        $pairs = [];

        foreach ($query === '' ? [] : \explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = \array_pad(\explode('=', $pair, 2), 2, '');
            $pairs[] = [\rawurldecode($name), \rawurldecode($value)];
        }

        return $pairs;
    }

    /**
     * Sorted by encoded name, then encoded value, and joined: the canonical form.
     *
     * @param list<array{string, string}> $pairs
     */
    private static function encodePairs(array $pairs): string
    {
        $encoded = \array_map(static fn(array $pair): array => [\rawurlencode($pair[0]), \rawurlencode($pair[1])], $pairs);

        \usort($encoded, static fn(array $a, array $b): int => \strcmp($a[0], $b[0]) ?: \strcmp($a[1], $b[1]));

        return \implode('&', \array_map(static fn(array $pair): string => $pair[0] . '=' . $pair[1], $encoded));
    }
}
