<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;

/**
 * A URL that carries proof it was made here: an unsubscribe link, a password
 * reset, a download that works for ten minutes.
 *
 *     $url = $signed->sign('/unsubscribe/7', expiresAt: time() + 7 * 86400);
 *     // /unsubscribe/7?expires=1760000000&signature=...
 *
 *     $signed->verify($request);   // or meta(['signed' => true]) on the route
 *
 * The signature covers the path and every query parameter, sorted -- so
 * changing ?id=7 to ?id=8, or stretching the expiry, breaks it -- and nothing
 * else: the scheme and host are left out, so a link survives the proxy and the
 * CDN in front of the application. It is computed by Signer, under its own
 * purpose, so a CSRF token cannot pass as a link signature or the reverse.
 *
 * Without APP_KEY nothing can be signed, and sign() refuses: a link anyone
 * could forge is worse than no link.
 */
final class SignedUrl
{
    public const SIGNATURE = 'signature';

    public const EXPIRES = 'expires';

    private const PURPOSE = 'signed-url';

    public function __construct(private readonly Signer $signer) {}

    /**
     * @param string $url a path with an optional query, as Router::url() returns it
     *
     * @throws SecurityException without APP_KEY
     */
    public function sign(string $url, ?int $expiresAt = null): string
    {
        if (!$this->signer->isConfigured()) {
            throw SecurityException::keyNotSet();
        }

        [$path, $query] = self::split($url);
        unset($query[self::SIGNATURE]);

        if ($expiresAt !== null) {
            $query[self::EXPIRES] = (string) $expiresAt;
        }

        $query[self::SIGNATURE] = $this->signature($path, $query);

        return $path . '?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @throws HttpException 403 for a missing or wrong signature, 410 for an expired one
     */
    public function verify(Request $request, ?int $now = null): void
    {
        /** @var array<string, mixed> $query */
        $query = $request->query();
        $given = $query[self::SIGNATURE] ?? null;

        if (!$this->signer->isConfigured() || !\is_string($given) || $given === '') {
            throw HttpException::forbidden('This link is not valid.');
        }

        unset($query[self::SIGNATURE]);

        if (!Signer::matches($this->signature($request->basePath() . $request->path(), $query), $given)) {
            throw HttpException::forbidden('This link is not valid.');
        }

        $expires = $query[self::EXPIRES] ?? null;

        if ($expires !== null && (!\is_string($expires) || !\ctype_digit($expires) || (int) $expires < ($now ?? \time()))) {
            throw new HttpException(410, 'This link has expired.');
        }
    }

    /** @param array<array-key, mixed> $query */
    private function signature(string $path, array $query): string
    {
        $canonical = self::canonical($query);
        $value = $path . ($canonical === '' ? '' : '?' . $canonical);
        $signed = $this->signer->sign($value, self::PURPOSE);

        return \substr($signed, \strlen($value) + \strlen(Signer::SEPARATOR));
    }

    /**
     * The query in one spelling, whatever order or encoding the browser used.
     *
     * @param array<array-key, mixed> $query
     */
    private static function canonical(array $query): string
    {
        \ksort($query, \SORT_STRING);

        return \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /** @return array{string, array<array-key, mixed>} */
    private static function split(string $url): array
    {
        $at = \strpos($url, '?');

        if ($at === false) {
            return [$url, []];
        }

        \parse_str(\substr($url, $at + 1), $query);

        return [\substr($url, 0, $at), $query];
    }
}
