<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * Header name and value hygiene.
 *
 * Header names are matched case-insensitively, so everything is keyed by the
 * lower-case form and rendered back in canonical form only at the boundary.
 *
 * sanitizeValue() is the single place CR/LF is stripped. Header injection is a
 * response-splitting vulnerability, and having exactly one chokepoint for it is
 * worth more than the convenience of setting headers from several places.
 */
final class Headers
{
    public static function normalize(string $name): string
    {
        return \strtolower(\trim($name));
    }

    /** Render "content-type" back as "Content-Type" for the wire. */
    public static function canonical(string $name): string
    {
        $normalized = self::normalize($name);

        return \implode('-', \array_map(
            static fn(string $part): string => \ucfirst($part),
            \explode('-', $normalized),
        ));
    }

    /**
     * Remove anything that could terminate the header or start a new one.
     *
     * Bare CR and LF are removed rather than escaped: there is no legitimate
     * reason for either to appear in a value we generate.
     */
    public static function sanitizeValue(string $value): string
    {
        return \trim(\str_replace(["\r", "\n", "\0"], '', $value));
    }

    /**
     * Build the header map from a $_SERVER-shaped array.
     *
     * HTTP_* entries carry most headers; Content-Type and Content-Length are the
     * two that PHP reports without the prefix.
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, string> keyed by the lower-case header name
     */
    public static function fromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!\is_string($key) || !\is_scalar($value)) {
                continue;
            }

            if (\str_starts_with($key, 'HTTP_')) {
                $name = \str_replace('_', '-', \substr($key, 5));
                $headers[self::normalize($name)] = (string) $value;

                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' || $key === 'CONTENT_MD5') {
                $name = \str_replace('_', '-', $key);
                $headers[self::normalize($name)] = (string) $value;
            }
        }

        return self::withAuthorization($headers, $server);
    }

    /**
     * Put Authorization back, because the server may have taken it away.
     *
     * This is the one header a web server routinely receives and does not pass
     * on. Apache strips it from the CGI environment unless `CGIPassAuth On` is
     * set or a rewrite copies it, on the reasoning that it is the server's own
     * business -- so `$_SERVER['HTTP_AUTHORIZATION']` is simply absent, and a
     * bearer token that the client definitely sent is invisible to the
     * application.
     *
     * **The failure it causes is the worst kind: silent and environment-
     * specific.** Token authentication passes every test, works under `php -S`,
     * and answers 401 to every request on the server it is deployed to -- and
     * the request looks correct in the access log, because it was.
     *
     * Two fallbacks, in the order they are worth trusting. REDIRECT_ prefixed
     * copies survive an internal rewrite, which is how the .htaccess rule
     * passes it through. getallheaders() asks Apache what it actually received;
     * it exists only under some SAPIs, which is why it is a fallback and not
     * the primary source.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     *
     * @return array<string, string>
     */
    private static function withAuthorization(array $headers, array $server): array
    {
        if (isset($headers[self::normalize('Authorization')])) {
            return $headers;
        }

        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_DIGEST'] as $key) {
            $value = $server[$key] ?? null;

            if (\is_string($value) && $value !== '') {
                $headers[self::normalize('Authorization')] = $value;

                return $headers;
            }
        }

        if (!\function_exists('getallheaders')) {
            return $headers;
        }

        foreach (\getallheaders() as $name => $value) {
            if (\strcasecmp($name, 'Authorization') === 0 && $value !== '') {
                $headers[self::normalize('Authorization')] = self::sanitizeValue($value);

                break;
            }
        }

        return $headers;
    }
}
