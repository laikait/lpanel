<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

use App\Engine\Http\Request;

/**
 * The TLS client certificate the agent presented, as panel Nginx hands it on.
 *
 * Nginx asks for a certificate without checking it against any CA
 * (`ssl_verify_client optional_no_ca`) and passes it as the FastCGI param
 * SSL_CLIENT_ESCAPED_CERT: URL-encoded PEM. Its SHA-256 over the DER bytes is
 * what an agent's credential names (AGENT_PROTOCOL.md §2). Nginx's own
 * $ssl_client_fingerprint is SHA-1, and is not used.
 *
 * A FastCGI param cannot be set by the client: a request header arrives as
 * HTTP_*, never under this name. A panel served without that Nginx config has
 * no certificate here, and every agent request is refused at check 2.
 */
final class ClientCertificate
{
    public function __construct(private readonly string $param = 'SSL_CLIENT_ESCAPED_CERT') {}

    /** Lower-case hex SHA-256 of the certificate's DER bytes, or null when none was presented. */
    public function fingerprint(Request $request): ?string
    {
        $escaped = $request->server($this->param);

        if (!\is_string($escaped) || $escaped === '' || \strlen($escaped) > 16384) {
            return null;
        }

        return self::fingerprintOfPem(\rawurldecode($escaped));
    }

    public static function fingerprintOfPem(string $pem): ?string
    {
        if (\preg_match('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $pem, $m) !== 1) {
            return null;
        }

        $der = \base64_decode((string) \preg_replace('/\s+/', '', $m[1]), true);

        return $der === false || $der === '' ? null : \hash('sha256', $der);
    }
}
