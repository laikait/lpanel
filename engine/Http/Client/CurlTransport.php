<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * libcurl, when the curl extension is loaded: connection reuse, HTTP/2, and a
 * connect timeout separate from the total.
 */
final class CurlTransport implements Transport
{
    public static function available(): bool
    {
        return \function_exists('curl_init');
    }

    public function send(ClientRequest $request): ClientResponse
    {
        $handle = \curl_init($request->url);

        if ($handle === false) {
            throw HttpClientException::connection($request->method, $request->url, 'curl could not start');
        }

        $lines = [];
        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        \curl_setopt_array($handle, [
            \CURLOPT_CUSTOMREQUEST => $request->method !== '' ? $request->method : 'GET',
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => (int) \ceil($request->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) \ceil(\min($request->timeout, 10.0) * 1000),
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$lines): int {
                if (\trim($line) !== '') {
                    $lines[] = \rtrim($line, "\r\n");
                }

                return \strlen($line);
            },
        ]);

        // A checked address is the one connected to: curl's own lookup of the
        // host never happens, so a DNS answer that changed since cannot win.
        // TLS still verifies the certificate against the host name.
        if ($request->address !== null) {
            $address = \str_contains($request->address, ':') ? '[' . \trim($request->address, '[]') . ']' : $request->address;
            \curl_setopt($handle, \CURLOPT_RESOLVE, [$request->host() . ':' . $request->port() . ':' . $address]);
        }

        if ($request->method === 'HEAD') {
            \curl_setopt($handle, \CURLOPT_NOBODY, true);
        } elseif ($request->body !== '') {
            \curl_setopt($handle, \CURLOPT_POSTFIELDS, $request->body);
        }

        $body = \curl_exec($handle);
        $errno = \curl_errno($handle);
        $error = \curl_error($handle);

        if ($errno === \CURLE_OPERATION_TIMEDOUT) {
            throw HttpClientException::timeout($request->method, $request->url, $request->timeout);
        }

        if ($errno !== 0 || !\is_string($body)) {
            throw HttpClientException::connection($request->method, $request->url, $error !== '' ? $error : 'curl error ' . $errno);
        }

        /** @var list<string> $lines */
        return ClientResponse::fromHeaderLines($lines, $body, $request->method, $request->url);
    }
}
