<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * The panel's half of lpa's wire format (dist/lpa/internal/protocol).
 *
 * The signature covers SignedBytes: the request's fields joined by newlines,
 * ending with the SHA-256 of the args exactly as written on the line. The
 * agent hashes the bytes it received, so no JSON canonicalisation is needed.
 * tests/Unit/AgentProtocolTest.php checks this against the Go golden vectors.
 */
final class Protocol
{
    public const VERSION = 1;

    public static function signedBytes(string $id, string $op, int $ts, string $nonce, string $args): string
    {
        return "lpa-request-v1\n{$id}\n{$op}\n{$ts}\n{$nonce}\n" . \hash('sha256', $args);
    }

    /**
     * One request line, newline included.
     *
     * Written by hand rather than with json_encode() of the whole envelope,
     * because the args must appear on the line byte for byte as they were
     * hashed.
     */
    public static function line(string $id, string $op, int $ts, string $nonce, string $args, string $signature): string
    {
        $text = static fn(string $value): string => \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return '{"v":' . self::VERSION
            . ',"id":' . $text($id)
            . ',"op":' . $text($op)
            . ',"ts":' . $ts
            . ',"nonce":' . $text($nonce)
            . ',"args":' . $args
            . ',"sig":' . $text($signature)
            . "}\n";
    }

    /**
     * The args object as it goes on the line. A list is refused here, at
     * runtime, because it would encode as a JSON array.
     *
     * @param array<mixed> $args
     */
    public static function encodeArgs(array $args): string
    {
        if ($args !== [] && \array_is_list($args)) {
            throw new \InvalidArgumentException('Agent arguments are named: an object, not a list.');
        }

        return $args === [] ? '{}' : \json_encode($args, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /** A request id: 32 hex characters, unique per piece of work. */
    public static function newId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /** A nonce: 16 random bytes, base64url without padding (22 characters). */
    public static function newNonce(): string
    {
        return \rtrim(\strtr(\base64_encode(\random_bytes(16)), '+/', '-_'), '=');
    }
}
