<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\Cookie;
use App\Engine\Http\Request;
use App\Engine\Security\Encrypter;
use App\Engine\Security\SecurityException;
use App\Tests\Support\TestCase;

final class EncryptedCookieTest extends TestCase
{
    private const KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private Encrypter $encrypter;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            self::markTestSkipped('sodium is not available.');
        }

        $this->encrypter = Encrypter::fromEnvironment(self::KEY);
    }

    public function test_a_value_comes_back(): void
    {
        $cookie = Cookie::encrypted($this->encrypter, 'cart', '{"items":[3,4]}', 0, '/', '', true);

        self::assertStringNotContainsString('items', $cookie->value);
        self::assertTrue($cookie->secure);
        self::assertTrue($cookie->httpOnly);

        $request = Request::create('GET', '/', ['cookies' => ['cart' => $cookie->value]]);

        self::assertSame('{"items":[3,4]}', $request->decryptedCookie($this->encrypter, 'cart'));
    }

    public function test_the_value_survives_the_header(): void
    {
        $cookie = Cookie::encrypted($this->encrypter, 'cart', 'x');

        // What PHP puts in $_COOKIE is the urldecoded header value.
        \preg_match('/^cart=([^;]*)/', $cookie->toHeaderValue(), $match);
        $request = Request::create('GET', '/', ['cookies' => ['cart' => \rawurldecode($match[1] ?? '')]]);

        self::assertSame('x', $request->decryptedCookie($this->encrypter, 'cart'));
    }

    public function test_a_changed_value_is_null(): void
    {
        $value = Cookie::encrypted($this->encrypter, 'cart', 'x')->value;
        $changed = \substr($value, 0, -2) . (\str_ends_with($value, 'AA') ? 'BB' : 'AA');

        $request = Request::create('GET', '/', ['cookies' => ['cart' => $changed]]);

        self::assertNull($request->decryptedCookie($this->encrypter, 'cart'));
    }

    public function test_a_value_moved_to_another_cookie_is_null(): void
    {
        $value = Cookie::encrypted($this->encrypter, 'role', 'admin')->value;
        $request = Request::create('GET', '/', ['cookies' => ['theme' => $value]]);

        self::assertNull($request->decryptedCookie($this->encrypter, 'theme'));
    }

    public function test_a_missing_or_plain_cookie_is_null(): void
    {
        $request = Request::create('GET', '/', ['cookies' => ['plain' => 'hello', 'empty' => '']]);

        self::assertNull($request->decryptedCookie($this->encrypter, 'missing'));
        self::assertNull($request->decryptedCookie($this->encrypter, 'plain'));
        self::assertNull($request->decryptedCookie($this->encrypter, 'empty'));
    }

    public function test_without_a_key_it_refuses_to_send(): void
    {
        $this->expectException(SecurityException::class);

        Cookie::encrypted(new Encrypter(), 'cart', 'x');
    }
}
