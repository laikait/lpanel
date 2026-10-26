<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * Encrypt a value so that only this application can read it -- and can tell
 * if anybody changed it.
 *
 *     $token = $encrypter->encrypt($cardNumber, 'card');   // "v1.<base64url>"
 *     $card  = $encrypter->decrypt($token, 'card');         // the value, or null
 *
 * Signer proves a value was not changed but leaves it readable; this hides it
 * too. Use it for what must be stored or sent but not read: an API token kept
 * in the database, a value in a cookie.
 *
 * **XChaCha20-Poly1305, from libsodium**, with a fresh random nonce per
 * message: two encryptions of one value never look alike, and a tampered or
 * truncated token fails authentication instead of decrypting to garbage.
 *
 * **The context is bound in**, as associated data. A token encrypted for
 * 'card' does not decrypt as 'api-token', so a value cannot be lifted from one
 * column into another where it would mean something else.
 *
 * **The key is derived from APP_KEY, never used raw.** HKDF gives this class a
 * key of its own, so encryption and Signer's HMAC never share one.
 *
 * **Rotation.** Keys in APP_PREVIOUS_KEYS are tried when decrypting, never
 * used to encrypt. Move the old key there, set a new APP_KEY, re-encrypt what
 * you stored, then remove it -- security:check reminds you while one is set.
 *
 * **decrypt() returns null for anything wrong**, as Signer::verify() does: a
 * bad token is ordinary input. encrypt() without a key throws, because
 * quietly storing plaintext would be worse than failing.
 */
final class Encrypter
{
    public const VERSION = 'v1';

    private const SEPARATOR = '.';

    private const INFO = 'lphp/encrypt/' . self::VERSION;

    /**
     * XChaCha20-Poly1305's sizes, written out: sodium's own constants do not
     * exist when the extension is off, and this class must still construct
     * then, to say so politely.
     */
    private const KEY_BYTES = 32;

    private const NONCE_BYTES = 24;

    private const TAG_BYTES = 16;

    /** @var list<string> derived keys, the current one first */
    private readonly array $keys;

    /**
     * @param ?Secret      $key      the current key; null for an application without APP_KEY
     * @param list<Secret> $previous older keys, accepted only for decrypting
     */
    public function __construct(?Secret $key = null, array $previous = [])
    {
        $keys = [];

        foreach ($key === null || $key->isEmpty() ? [] : [$key, ...$previous] as $secret) {
            $keys[] = \hash_hkdf('sha256', $secret->reveal(), self::KEY_BYTES, self::INFO);
        }

        $this->keys = $keys;
    }

    /**
     * From APP_KEY and APP_PREVIOUS_KEYS as written in the environment.
     *
     * @param ?string $previous comma separated, in the same form as APP_KEY
     *
     * @throws SecurityException when a key cannot be read
     */
    public static function fromEnvironment(?string $key, ?string $previous = null): self
    {
        if ($key === null || $key === '') {
            return new self();
        }

        $older = [];

        foreach (\explode(',', $previous ?? '') as $value) {
            if (\trim($value) !== '') {
                $older[] = Signer::readKey(\trim($value));
            }
        }

        return new self(Signer::readKey($key), $older);
    }

    public function isConfigured(): bool
    {
        return $this->keys !== [];
    }

    /** How many retired keys are still accepted for decrypting. */
    public function previousKeys(): int
    {
        return \max(0, \count($this->keys) - 1);
    }

    /**
     * @throws SecurityException without a key, or without the sodium extension
     */
    public function encrypt(string $value, string $context = ''): string
    {
        self::assertSodium();

        if ($this->keys === []) {
            throw SecurityException::keyNotSet();
        }

        $nonce = \random_bytes(self::NONCE_BYTES);
        $sealed = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, self::aad($context), $nonce, $this->keys[0]);

        return self::VERSION . self::SEPARATOR . self::encode($nonce . $sealed);
    }

    /**
     * The value, or null if the token was changed, is for another context, was
     * made with an unknown key, or is not a token at all.
     *
     * @throws SecurityException without the sodium extension
     */
    public function decrypt(string $token, string $context = ''): ?string
    {
        self::assertSodium();

        $prefix = self::VERSION . self::SEPARATOR;

        if ($this->keys === [] || !\str_starts_with($token, $prefix)) {
            return null;
        }

        $bytes = self::decode(\substr($token, \strlen($prefix)));
        $nonceLength = self::NONCE_BYTES;

        if ($bytes === null || \strlen($bytes) < $nonceLength + self::TAG_BYTES) {
            return null;
        }

        $nonce = \substr($bytes, 0, $nonceLength);
        $sealed = \substr($bytes, $nonceLength);

        foreach ($this->keys as $key) {
            $value = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed, self::aad($context), $nonce, $key);

            if ($value !== false) {
                return $value;
            }
        }

        return null;
    }

    private static function aad(string $context): string
    {
        return self::VERSION . "\0" . $context;
    }

    private static function encode(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        if (\preg_match('/^[A-Za-z0-9_-]+$/D', $text) !== 1) {
            return null;
        }

        $bytes = \base64_decode(\strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }

    private static function assertSodium(): void
    {
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw SecurityException::noSodium();
        }
    }
}
