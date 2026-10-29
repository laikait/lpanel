<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Security\Secret;
use App\Engine\Security\SecurityException;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

final class SignerTest extends TestCase
{
    private function keyed(): Signer
    {
        return Signer::fromEnvironment(Signer::generate());
    }

    // ---- the key ---------------------------------------------------------------

    public function test_a_generated_key_is_long_enough_and_readable_back(): void
    {
        $key = Signer::generate();

        self::assertStringStartsWith(Signer::PREFIX, $key);
        self::assertTrue(Signer::fromEnvironment($key)->isConfigured());
    }

    public function test_two_generated_keys_differ(): void
    {
        self::assertNotSame(Signer::generate(), Signer::generate());
    }

    public function test_no_key_means_not_configured(): void
    {
        self::assertFalse(Signer::fromEnvironment(null)->isConfigured());
        self::assertFalse(Signer::fromEnvironment('')->isConfigured());
        self::assertFalse((new Signer())->isConfigured());
    }

    /**
     * A key is 32 bytes of binary, so it travels base64 encoded -- and a
     * truncated or mangled one has to be an error here rather than a signature
     * that silently never verifies.
     */
    public function test_a_short_key_is_refused_and_says_how_short(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('at least 32');

        Signer::fromEnvironment('base64:' . \base64_encode('too short'));
    }

    public function test_a_key_that_is_not_base64_is_refused(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not valid base64');

        Signer::fromEnvironment('base64:!!! not base64 !!!');
    }

    /** The prefix is a convention, not a requirement; a bare key still works. */
    public function test_the_prefix_is_optional(): void
    {
        self::assertTrue(Signer::fromEnvironment(\base64_encode(\random_bytes(32)))->isConfigured());
    }

    // ---- signing ---------------------------------------------------------------

    public function test_a_signed_value_verifies_back_to_itself(): void
    {
        $signer = $this->keyed();
        $signed = $signer->sign('abc');

        self::assertNotSame('abc', $signed);
        self::assertSame('abc', $signer->verify($signed));
    }

    public function test_a_tampered_value_does_not_verify(): void
    {
        $signer = $this->keyed();
        $signed = $signer->sign('abc');

        self::assertNull($signer->verify('abd' . \substr($signed, 3)));
    }

    public function test_a_tampered_signature_does_not_verify(): void
    {
        $signer = $this->keyed();
        $signed = $signer->sign('abc');

        self::assertNull($signer->verify(\substr($signed, 0, -1) . 'x'));
    }

    public function test_an_unsigned_value_does_not_verify(): void
    {
        self::assertNull($this->keyed()->verify('abc'));
    }

    public function test_another_key_does_not_verify(): void
    {
        self::assertNull($this->keyed()->verify($this->keyed()->sign('abc')));
    }

    /**
     * The reason sign() takes a context at all.
     *
     * A token signed for CSRF must not verify as a signed URL, or anyone who
     * can obtain one gets the other for free.
     */
    public function test_a_signature_is_bound_to_its_purpose(): void
    {
        $signer = $this->keyed();
        $signed = $signer->sign('abc', 'csrf');

        self::assertSame('abc', $signer->verify($signed, 'csrf'));
        self::assertNull($signer->verify($signed, 'url'));
        self::assertNull($signer->verify($signed));
    }

    /** A value containing the separator must still round-trip. */
    public function test_a_value_with_a_dot_in_it_round_trips(): void
    {
        $signer = $this->keyed();

        self::assertSame('a.b.c', $signer->verify($signer->sign('a.b.c')));
    }

    // ---- no key ----------------------------------------------------------------

    /**
     * Without a key nothing can be signed, and the honest behaviour is to say
     * so rather than to return something that looks signed. What depends on
     * this -- CSRF -- degrades to a weaker mode it can describe.
     */
    public function test_without_a_key_signing_returns_the_value_unchanged(): void
    {
        $signer = new Signer();

        self::assertSame('abc', $signer->sign('abc'));
        self::assertSame('abc', $signer->verify('abc'));
    }

    // ---- the primitives --------------------------------------------------------

    public function test_matching_is_exact(): void
    {
        self::assertTrue(Signer::matches('abc', 'abc'));
        self::assertFalse(Signer::matches('abc', 'abd'));
        self::assertFalse(Signer::matches('abc', 'ab'));
    }

    public function test_tokens_are_url_safe_and_unique(): void
    {
        $token = Signer::token();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        self::assertNotSame($token, Signer::token());
    }

    /** Sixteen bytes is the floor, whatever is asked for. */
    public function test_a_token_is_never_trivially_short(): void
    {
        self::assertGreaterThanOrEqual(20, \strlen(Signer::token(1)));
    }

    public function test_a_secret_can_be_handed_in_directly(): void
    {
        $signer = new Signer(new Secret(\str_repeat('k', 32)));

        self::assertTrue($signer->isConfigured());
        self::assertSame('abc', $signer->verify($signer->sign('abc')));
    }
}
