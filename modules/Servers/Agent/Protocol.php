<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

/**
 * The LPA <-> LPanel wire format, version 1 (design/AGENT_PROTOCOL.md).
 *
 * The PHP twin of the agent's internal/protocol package. The golden vectors
 * in tests/Fixtures/lpa/vectors.json come from the Go side, and
 * AgentProtocolVectorsTest checks this class signs and verifies exactly the
 * same bytes.
 */
final class Protocol
{
    public const VERSION = 1;

    public const MAX_BODY = 1048576;

    public const MAX_CLOCK_SKEW = 60;

    public const MAX_LIFETIME = 120;

    /** How long after expires_at a request id is remembered, so a replay inside the window is caught. */
    public const REPLAY_MARGIN = 60;

    /** The LPA-Agent value of the enrollment request, made before the agent has an id. */
    public const ENROLL_AGENT = 'enroll';

    public const HEADER_AGENT = 'LPA-Agent';

    public const HEADER_KEY = 'LPA-Key';

    public const HEADER_REQUEST_ID = 'LPA-Request-Id';

    public const HEADER_ISSUED_AT = 'LPA-Issued-At';

    public const HEADER_EXPIRES_AT = 'LPA-Expires-At';

    public const HEADER_CONTENT_SHA256 = 'LPA-Content-SHA256';

    public const HEADER_SIGNATURE = 'LPA-Signature';

    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public const KID = '/^[A-Za-z0-9_-]{16}$/';

    public const HEX64 = '/^[0-9a-f]{64}$/';

    public const PATH = '#^/agent/v1(/[a-z0-9_\-]+)+$#';

    /** base64url without padding: the only base64 the protocol uses. */
    public static function b64(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The bytes, or null for anything that is not unpadded base64url. */
    public static function unb64(string $text): ?string
    {
        if ($text === '' || \preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1 || \strlen($text) % 4 === 1) {
            return null;
        }

        $bytes = \base64_decode(\strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }

    /** Names an Ed25519 public key: the first 16 characters of base64url(sha256(key)). */
    public static function keyId(string $publicKey): string
    {
        return \substr(self::b64(\hash('sha256', $publicKey, true)), 0, 16);
    }

    public static function sha256(string $bytes): string
    {
        return \hash('sha256', $bytes);
    }

    /** A random lower-case UUIDv4, the only id format on the wire. */
    public static function uuid(): string
    {
        $b = \random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);
        $h = \bin2hex($b);

        return \sprintf('%s-%s-%s-%s-%s', \substr($h, 0, 8), \substr($h, 8, 4), \substr($h, 12, 4), \substr($h, 16, 4), \substr($h, 20));
    }

    /** The exact byte string a request signature is over (AGENT_PROTOCOL.md §3). */
    public static function signingString(
        string $method,
        string $path,
        string $agentId,
        string $kid,
        string $requestId,
        int $issuedAt,
        int $expiresAt,
        string $contentSha256,
    ): string {
        return \implode("\n", ['LPA-v1', $method, $path, $agentId, $kid, $requestId, (string) $issuedAt, (string) $expiresAt, $contentSha256]);
    }

    /** Is $signature (base64url) $publicKey's Ed25519 signature over $message? */
    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        $raw = self::unb64($signature);

        if ($raw === null || \strlen($raw) !== \SODIUM_CRYPTO_SIGN_BYTES || \strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return \sodium_crypto_sign_verify_detached($raw, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * The panel's signature over an operation's exact bytes (§6.1).
     *
     * @return array{operation: string, kid: string, signature: string}
     */
    public static function signOperation(string $secretKey, string $operationJson): array
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('An operation needs a signing key.');
        }

        return [
            'operation' => self::b64($operationJson),
            'kid' => self::keyId(\sodium_crypto_sign_publickey_from_secretkey($secretKey)),
            'signature' => self::b64(\sodium_crypto_sign_detached("LPA-OP-v1\n" . $operationJson, $secretKey)),
        ];
    }

    /** What a key rotation's proof is over: the new key signs it, showing the agent holds it. */
    public static function rotationProofString(string $agentId, string $newKid, string $newCertSha256): string
    {
        return \implode("\n", ['LPA-ROTATE-v1', $agentId, $newKid, $newCertSha256]);
    }
}
