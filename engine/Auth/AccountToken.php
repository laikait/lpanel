<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Security\SecurityException;
use App\Engine\Security\Signer;

/**
 * A signed, expiring token naming an account: what a reset or verification
 * link carries.
 *
 * Stateless: nothing is stored, so nothing has to be cleaned up and a cache
 * flush cannot lose one. What makes a token single-use is what it is bound
 * to -- the password hash for a reset, the address for a verification -- so
 * the act it authorises is the act that invalidates it.
 *
 * The contents are readable (an account id, an expiry, a short fingerprint);
 * the signature is what nobody can make without APP_KEY.
 */
final class AccountToken
{
    /**
     * @param array<string, string|int> $claims
     *
     * @throws SecurityException without APP_KEY
     */
    public static function issue(Signer $signer, string $purpose, array $claims): string
    {
        if (!$signer->isConfigured()) {
            throw SecurityException::keyNotSet();
        }

        $payload = \rtrim(\strtr(\base64_encode(\json_encode($claims, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $signer->sign($payload, $purpose);
    }

    /**
     * The claims, or null for a token that is not one of ours, is for another
     * purpose, or has expired ("exp").
     *
     * @return ?array<string, mixed>
     */
    public static function read(Signer $signer, string $purpose, string $token, ?int $now = null): ?array
    {
        if (!$signer->isConfigured() || $token === '' || \strlen($token) > 1024) {
            return null;
        }

        $payload = $signer->verify($token, $purpose);

        if ($payload === null) {
            return null;
        }

        $json = \base64_decode(\strtr($payload, '-_', '+/'), true);
        $claims = \is_string($json) ? \json_decode($json, true) : null;

        if (!\is_array($claims) || !\is_int($claims['exp'] ?? null) || $claims['exp'] < ($now ?? \time())) {
            return null;
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /** A short, one-way fingerprint of something the token must still match. */
    public static function fingerprint(string $value): string
    {
        return \substr(\hash('sha256', $value), 0, 16);
    }
}
