<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * PHP's own http and https stream wrapper: no extension, available everywhere.
 *
 * The response headers come from stream_get_meta_data(), which works the same
 * on every PHP this framework supports -- http_get_last_response_headers() is
 * 8.4+ and $http_response_header is deprecated from 8.5. TLS certificates are
 * verified, as PHP does by default.
 */
final class StreamTransport implements Transport
{
    public function send(ClientRequest $request): ClientResponse
    {
        $headers = '';

        foreach ($request->headers as $name => $value) {
            $headers .= $name . ': ' . $value . "\r\n";
        }

        $url = $request->url;
        $options = [
            'http' => [
                'method' => $request->method,
                'header' => $headers,
                'content' => $request->body,
                'timeout' => $request->timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
        ];

        // A checked address is the one connected to, so the stream wrapper
        // never looks the host up again. The host still goes in the Host header
        // and is what TLS checks the certificate and SNI against.
        if ($request->address !== null) {
            [$url, $options] = self::pinned($request, $request->address, $options);
        }

        $context = \stream_context_create($options);

        $started = \microtime(true);
        $stream = @\fopen($url, 'r', false, $context);

        if ($stream === false) {
            if (\microtime(true) - $started >= $request->timeout) {
                throw HttpClientException::timeout($request->method, $request->url, $request->timeout);
            }

            $error = \error_get_last();

            throw HttpClientException::connection($request->method, $request->url, self::reason($error['message'] ?? 'no connection'));
        }

        try {
            $meta = \stream_get_meta_data($stream);
            $body = \stream_get_contents($stream);
            $timedOut = \stream_get_meta_data($stream)['timed_out'];
        } finally {
            \fclose($stream);
        }

        if ($timedOut || $body === false) {
            throw HttpClientException::timeout($request->method, $request->url, $request->timeout);
        }

        $lines = \array_values(\array_filter(\is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [], \is_string(...)));

        return ClientResponse::fromHeaderLines($lines, $body, $request->method, $request->url);
    }

    /** PHP's warning without the "fopen(<url>):" prefix, which would repeat the query string. */
    /**
     * The URL with its host replaced by $address, and the options that keep
     * the original host on the wire.
     *
     * @param array<string, array<string, mixed>> $options
     *
     * @return array{string, array<string, array<string, mixed>>}
     */
    private static function pinned(ClientRequest $request, string $address, array $options): array
    {
        $host = $request->host();
        $literal = \str_contains($address, ':') ? '[' . \trim($address, '[]') . ']' : $address;
        $at = \strpos($request->url, $host);
        $url = $at === false ? $request->url : \substr_replace($request->url, $literal, $at, \strlen($host));

        if ($request->header('Host') === null) {
            $standard = $request->port() === (\str_starts_with(\strtolower($request->url), 'https:') ? 443 : 80);
            $options['http']['header'] = 'Host: ' . $host . ($standard ? '' : ':' . $request->port()) . "\r\n" . $options['http']['header'];
        }

        $options['ssl'] = ['peer_name' => \trim($host, '[]'), 'SNI_enabled' => true];

        return [$url, $options];
    }

    private static function reason(string $message): string
    {
        $message = (string) \preg_replace('/^fopen\([^)]*\):\s*/', '', $message);

        return \rtrim($message, '.') ?: 'no connection';
    }
}
