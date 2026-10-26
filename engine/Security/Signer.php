<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * The application key, and the two things it is for: signing and comparing.
 *
 * One class so that "how do we sign something" is answered once. A CSRF token,
 * a signed URL, a remember-me cookie and a session id all want the same
 * primitive, and four implementations of it is four chances to get the
 * comparison wrong.
 *
 *     $signed = $signer->sign($token);        // token.signature
 *     $token  = $signer->verify($signed);     // the token, or null
 *
 * **The signature is over a purpose as well as a value.** A token signed for
 * CSRF must not verify as a signed URL, or an attacker who can obtain one gets
 * the other for free; passing the context into the HMAC makes the two keys
 * different keys without there being two keys to manage.
 *
 * **Verification is constant time.** hash_equals compares every byte whatever
 * happens, so the time it takes does not say how many leading bytes were right.
 * This is not theoretical for a signature: a `===` here is a way to forge one
 * a byte at a time given enough requests.
 *
 * **An unkeyed application still works.** Without APP_KEY nothing can be
 * signed, and rather than pretend otherwise this reports isConfigured() as
 * false and refuses to sign. What depends on it -- CSRF -- degrades to a weaker
 * mode it can describe, instead of to a stronger-looking one it cannot deliver.
 * `security:check` says which mode is running.
 */
final class Signer
{
    public const ALGORITHM = 'sha256';

    /** The prefix an APP_KEY carries, so that a truncated one is obvious. */
    public const PREFIX = 'base64:';

    /** Below this a key is not a key. 32 bytes is the SHA-256 block's worth. */
    public const MINIMUM_BYTES = 32;

    public const SEPARATOR = '.';

    private readonly Secret $key;

    public function __construct(?Secret $key = null)
    {
        $this->key = $key ?? new Secret('');
    }

    /**
     * Read an APP_KEY as it is written in the environment.
     *
     * "base64:..." because a raw key is 32 bytes of arbitrary binary, which
     * does not survive a .env file, a shell, a YAML deployment manifest or a
     * copy and paste. The prefix is what makes a truncated or mangled key an
     * error here rather than a signature that silently never verifies.
     */
    public static function fromEnvironment(?string $value): self
    {
        if ($value === null || $value === '') {
            return new self();
        }

        return new self(self::readKey($value));
    }

    /**
     * An APP_KEY-shaped value decoded into its bytes: "base64:..." or bare base64,
     * at least MINIMUM_BYTES long. Shared with Encrypter, so both read a key the
     * same way and reject the same mistakes.
     *
     * @throws SecurityException when it is not base64 or is too short
     */
    public static function readKey(string $value): Secret
    {
        $encoded = \str_starts_with($value, self::PREFIX)
            ? \substr($value, \strlen(self::PREFIX))
            : $value;

        $decoded = \base64_decode($encoded, true);

        if ($decoded === false) {
            throw SecurityException::unreadableKey();
        }

        if (\strlen($decoded) < self::MINIMUM_BYTES) {
            throw SecurityException::keyTooShort(\strlen($decoded), self::MINIMUM_BYTES);
        }

        return new Secret($decoded);
    }

    /** A new key, in the form APP_KEY expects. */
    public static function generate(): string
    {
        return self::PREFIX . \base64_encode(\random_bytes(self::MINIMUM_BYTES));
    }

    public function isConfigured(): bool
    {
        return !$this->key->isEmpty();
    }

    /**
     * value.signature, or the value alone when there is no key.
     *
     * Returning the bare value rather than throwing is what lets an unkeyed
     * application run. The caller is expected to have asked isConfigured()
     * already if the difference matters to it; what it must not do is assume a
     * returned string was signed.
     */
    public function sign(string $value, string $context = ''): string
    {
        if (!$this->isConfigured()) {
            return $value;
        }

        return $value . self::SEPARATOR . $this->signature($value, $context);
    }

    /**
     * The value back, or null if the signature does not belong to it.
     *
     * Null rather than an exception: a bad signature is an ordinary thing for a
     * server to receive -- an expired form, a truncated cookie, somebody
     * probing -- and the caller turns it into whatever answer fits.
     */
    public function verify(string $signed, string $context = ''): ?string
    {
        if (!$this->isConfigured()) {
            return $signed;
        }

        $at = \strrpos($signed, self::SEPARATOR);

        if ($at === false) {
            return null;
        }

        $value = \substr($signed, 0, $at);
        $signature = \substr($signed, $at + 1);

        return \hash_equals($this->signature($value, $context), $signature) ? $value : null;
    }

    /**
     * Two strings compared without leaking where they diverge.
     *
     * Exposed because it is the primitive everything else in this layer needs
     * and there should be exactly one of it. See Secret::equals() for why a
     * plain === is not equivalent.
     */
    public static function matches(string $known, string $candidate): bool
    {
        return \hash_equals($known, $candidate);
    }

    /**
     * An HMAC under somebody else's key: an S3 secret, a webhook secret.
     *
     * The one place outside this class's own key that hash_hmac() runs, so a
     * protocol that needs a raw HMAC still has one implementation to trust.
     * Compare what it returns with matches(), never ===.
     *
     * @param string $algorithm one of hash_hmac_algos(): sha256, sha1, sha512…
     * @param bool   $binary    raw bytes rather than lowercase hex
     */
    public static function hmac(string $algorithm, string $data, string $key, bool $binary = false): string
    {
        return \hash_hmac($algorithm, $data, $key, $binary);
    }

    /** A random, URL-safe token. */
    public static function token(int $bytes = 32): string
    {
        return \rtrim(\strtr(\base64_encode(\random_bytes(\max(16, $bytes))), '+/', '-_'), '=');
    }

    private function signature(string $value, string $context): string
    {
        return \hash_hmac(self::ALGORITHM, $context . "\0" . $value, $this->key->reveal());
    }
}
