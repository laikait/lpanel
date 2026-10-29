<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Security\Encrypter;
use App\Engine\Security\SecurityException;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('sodium')]
final class EncrypterTest extends TestCase
{
    private const KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const OTHER = 'base64:ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=';

    public function test_a_value_comes_back(): void
    {
        $encrypter = Encrypter::fromEnvironment(self::KEY);
        $token = $encrypter->encrypt('4111 1111 1111 1111', 'card');

        self::assertStringStartsWith('v1.', $token);
        self::assertStringNotContainsString('4111', $token);
        self::assertSame('4111 1111 1111 1111', $encrypter->decrypt($token, 'card'));
        self::assertSame('', $encrypter->decrypt($encrypter->encrypt(''), ''), 'the empty string is a value too');
        self::assertSame("bin\0ary\xff", $encrypter->decrypt($encrypter->encrypt("bin\0ary\xff")));
    }

    public function test_the_same_value_never_encrypts_the_same_way(): void
    {
        $encrypter = Encrypter::fromEnvironment(self::KEY);

        self::assertNotSame($encrypter->encrypt('same'), $encrypter->encrypt('same'));
    }

    /** A token is URL- and cookie-safe as it is. */
    public function test_a_token_needs_no_escaping(): void
    {
        $token = Encrypter::fromEnvironment(self::KEY)->encrypt(\random_bytes(200));

        self::assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]+$/D', $token);
    }

    /** @return array<string, array{\Closure(string): string, string}> */
    public static function spoiled(): array
    {
        return [
            'another context' => [static fn(string $t): string => $t, 'api-token'],
            'a flipped byte' => [static fn(string $t): string => \substr($t, 0, -2) . ($t[-2] === 'A' ? 'B' : 'A') . $t[-1], 'card'],
            'truncated' => [static fn(string $t): string => \substr($t, 0, 20), 'card'],
            'an unknown version' => [static fn(string $t): string => 'v9' . \substr($t, 2), 'card'],
            'not base64url' => [static fn(string $t): string => 'v1.not*valid', 'card'],
            'not a token' => [static fn(string $t): string => 'hello', 'card'],
            'empty' => [static fn(string $t): string => '', 'card'],
        ];
    }

    /** @param \Closure(string): string $spoil */
    #[DataProvider('spoiled')]
    public function test_anything_wrong_decrypts_to_null(\Closure $spoil, string $context): void
    {
        $encrypter = Encrypter::fromEnvironment(self::KEY);

        self::assertNull($encrypter->decrypt($spoil($encrypter->encrypt('secret', 'card')), $context));
    }

    public function test_another_key_cannot_decrypt(): void
    {
        $token = Encrypter::fromEnvironment(self::KEY)->encrypt('secret');

        self::assertNull(Encrypter::fromEnvironment(self::OTHER)->decrypt($token));
    }

    /** Move the old key to APP_PREVIOUS_KEYS, set a new APP_KEY: old tokens still open, new ones use the new key. */
    public function test_a_previous_key_decrypts_but_never_encrypts(): void
    {
        $old = Encrypter::fromEnvironment(self::KEY)->encrypt('secret');
        $rotated = Encrypter::fromEnvironment(self::OTHER, ' ' . self::KEY . ' ,');

        self::assertSame(1, $rotated->previousKeys());
        self::assertSame('secret', $rotated->decrypt($old));

        $fresh = $rotated->encrypt('secret');
        self::assertNull(Encrypter::fromEnvironment(self::KEY)->decrypt($fresh), 'a new token is made with the new key');
        self::assertSame('secret', Encrypter::fromEnvironment(self::OTHER)->decrypt($fresh));
    }

    /** HKDF gives encryption a key of its own: the raw APP_KEY never seals anything. */
    public function test_the_raw_key_is_not_the_encryption_key(): void
    {
        $token = Encrypter::fromEnvironment(self::KEY)->encrypt('secret');
        $bytes = \base64_decode(\strtr(\substr($token, 3), '-_', '+/'), true);
        self::assertIsString($bytes);

        $raw = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(\substr($bytes, 24), "v1\0", \substr($bytes, 0, 24), Signer::readKey(self::KEY)->reveal());

        self::assertFalse($raw);
    }

    public function test_without_a_key_nothing_is_encrypted(): void
    {
        $encrypter = Encrypter::fromEnvironment(null);

        self::assertFalse($encrypter->isConfigured());
        self::assertNull($encrypter->decrypt('v1.anything'));

        $this->expectException(SecurityException::class);
        $encrypter->encrypt('secret');
    }

    public function test_an_unreadable_previous_key_is_refused(): void
    {
        $this->expectException(SecurityException::class);
        Encrypter::fromEnvironment(self::KEY, 'not base64!');
    }
}
