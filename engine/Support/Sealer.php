<?php

declare(strict_types=1);

namespace App\Engine\Support;

use App\Engine\Security\Signer;

/**
 * An HMAC around bytes that are about to be unserialize()d.
 *
 *     $sealed = $sealer->seal(\serialize($value));      // stored
 *     $bytes = $sealer->open($sealed);                  // null: not ours, or changed
 *
 * unserialize() turns stored bytes into objects, and an object's __wakeup and
 * __destruct run whatever its class says. Bytes that somebody else wrote --
 * into a cache directory, a cache table, a queue -- are therefore code, and
 * object injection is the name for running it. The cache and the queue keep
 * serialize() because they store objects on purpose, so what they read back is
 * checked first: open() answers null for anything this application did not
 * write with this key, and nothing is unserialized at all.
 *
 * **Deterministic**, with no nonce: the same bytes always seal the same way,
 * because a store's forgetIf() finds a row by its stored value.
 *
 * **Without a key nothing is sealed**, and open() hands back what it was given
 * -- the behaviour from before, and an unprotected one: whoever can write the
 * cache directory, the cache table or the queue then decides what is
 * unserialized. That is why security:check fails a production deployment
 * without APP_KEY. A key comes from APP_KEY, derived per purpose
 * so a sealed cache entry is not a sealed job. Bytes sealed under a key that is
 * no longer configured read as null.
 *
 * In Support rather than Security because the cache layer may depend on
 * nothing above it; Bootstrap derives the key and hands over a string. The
 * HMAC and its constant-time comparison are still Signer's, the one place
 * either is written.
 */
final class Sealer
{
    public const PREFIX = 'lphp-sealed:v1:';

    private const ALGORITHM = 'sha256';

    /** Hex characters in a SHA-256 HMAC. */
    private const MAC_LENGTH = 64;

    public function __construct(#[\SensitiveParameter] private readonly string $key = '') {}

    /**
     * A sealer keyed by $secret for one $purpose, such as "cache" or "queue".
     * An empty secret is a sealer that seals nothing.
     */
    public static function derive(#[\SensitiveParameter] string $secret, string $purpose): self
    {
        return new self($secret === '' ? '' : \hash_hkdf(self::ALGORITHM, $secret, 32, 'lphp/sealed/' . $purpose));
    }

    public function isActive(): bool
    {
        return $this->key !== '';
    }

    public function seal(string $bytes): string
    {
        if (!$this->isActive()) {
            return $bytes;
        }

        return self::PREFIX . Signer::hmac(self::ALGORITHM, $bytes, $this->key) . ':' . $bytes;
    }

    /** The bytes seal() was given, or null when $sealed is not what it made. */
    public function open(string $sealed): ?string
    {
        $isSealed = \str_starts_with($sealed, self::PREFIX);

        if (!$this->isActive()) {
            return $isSealed ? null : $sealed;
        }

        $headerLength = \strlen(self::PREFIX) + self::MAC_LENGTH + 1;

        if (!$isSealed || \strlen($sealed) < $headerLength || $sealed[$headerLength - 1] !== ':') {
            return null;
        }

        $mac = \substr($sealed, \strlen(self::PREFIX), self::MAC_LENGTH);
        $bytes = \substr($sealed, $headerLength);

        return Signer::matches(Signer::hmac(self::ALGORITHM, $bytes, $this->key), $mac) ? $bytes : null;
    }
}
