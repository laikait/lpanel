<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Social;

use App\Engine\Auth\Social\Jwt;
use App\Engine\Auth\Social\SocialException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class JwtTest extends TestCase
{
    /**
     * A key pair, as a PEM private key and the JWK a provider would publish.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function pair(string $alg): array
    {
        $options = \str_starts_with($alg, 'RS')
            ? ['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
            : ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => ['ES256' => 'prime256v1', 'ES384' => 'secp384r1', 'ES512' => 'secp521r1'][$alg]];
        $key = \openssl_pkey_new($options);
        self::assertNotFalse($key);
        \openssl_pkey_export($key, $pem);
        $details = \openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $jwk = isset($details['rsa'])
            ? ['kty' => 'RSA', 'n' => Jwt::encode($details['rsa']['n']), 'e' => Jwt::encode($details['rsa']['e'])]
            : ['kty' => 'EC', 'crv' => ['ES256' => 'P-256', 'ES384' => 'P-384', 'ES512' => 'P-521'][$alg], 'x' => Jwt::encode($details['ec']['x']), 'y' => Jwt::encode($details['ec']['y'])];

        return [(string) $pem, $jwk + ['kid' => $alg . '-key', 'use' => 'sig']];
    }

    /** @return iterable<string, array{string}> */
    public static function algorithms(): iterable
    {
        foreach (['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'] as $alg) {
            yield $alg => [$alg];
        }
    }

    #[DataProvider('algorithms')]
    public function test_a_signed_token_verifies_against_the_published_key(string $alg): void
    {
        [$private, $jwk] = self::pair($alg);
        $token = Jwt::sign(['sub' => '42', 'n' => [1, 2]], $private, $alg, $jwk['kid']);

        self::assertSame(['sub' => '42', 'n' => [1, 2]], Jwt::verify($token, [$jwk]));
    }

    /** RFC 7515, appendix A.3: somebody else's ES256 token, so the r||s handling is the standard's. */
    public function test_the_rfc_7515_es256_example(): void
    {
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU', 'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0'];
        $token = 'eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ'
            . '.DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q';

        self::assertSame(['iss' => 'joe', 'exp' => 1300819380, 'http://example.com/is_root' => true], Jwt::verify($token, [$jwk]));
    }

    public function test_a_changed_claim_fails(): void
    {
        [$private, $jwk] = self::pair('RS256');
        [$head, , $signature] = \explode('.', Jwt::sign(['sub' => '42'], $private, 'RS256', $jwk['kid']));

        $this->expectException(SocialException::class);
        $this->expectExceptionMessage('does not verify');

        Jwt::verify($head . '.' . Jwt::encode('{"sub":"43"}') . '.' . $signature, [$jwk]);
    }

    public function test_another_key_with_the_same_id_fails(): void
    {
        [$private, $jwk] = self::pair('ES256');
        [, $impostor] = self::pair('ES256');

        $this->expectException(SocialException::class);

        Jwt::verify(Jwt::sign(['sub' => '1'], $private, 'ES256', $jwk['kid']), [$impostor]);
    }

    public function test_an_unpublished_key_is_reported_as_such(): void
    {
        [$private, $jwk] = self::pair('RS256');

        try {
            Jwt::verify(Jwt::sign(['sub' => '1'], $private, 'RS256', 'rotated-in'), [$jwk]);
            self::fail('An unknown key verified.');
        } catch (SocialException $e) {
            self::assertTrue($e->isUnknownKey());
        }
    }

    public function test_none_and_hmac_are_refused(): void
    {
        [, $jwk] = self::pair('RS256');
        $claims = Jwt::encode('{"sub":"admin"}');

        foreach (['none', 'HS256'] as $alg) {
            $header = Jwt::encode(\json_encode(['alg' => $alg, 'kid' => $jwk['kid']], \JSON_THROW_ON_ERROR));
            // HS256 "signed" with the public key: the classic forgery.
            $signature = $alg === 'none' ? '' : Jwt::encode(\hash_hmac('sha256', $header . '.' . $claims, Jwt::pem($jwk), true));

            try {
                Jwt::verify($header . '.' . $claims . '.' . $signature, [$jwk]);
                self::fail($alg . ' was accepted.');
            } catch (SocialException $e) {
                self::assertStringContainsString('is not accepted', $e->getMessage());
            }
        }
    }

    public function test_a_key_of_the_wrong_type_is_not_tried(): void
    {
        [$private, $jwk] = self::pair('ES256');
        [, $rsa] = self::pair('RS256');

        $this->expectException(SocialException::class);

        Jwt::verify(Jwt::sign(['sub' => '1'], $private, 'ES256'), [$rsa + ['kid' => null]]);
    }

    public function test_garbage_is_refused(): void
    {
        foreach (['', 'a.b', 'a.b.c', '!!.!!.!!', Jwt::encode('[]') . '.' . Jwt::encode('{}') . '.x'] as $token) {
            try {
                Jwt::verify($token, []);
                self::fail($token . ' was accepted.');
            } catch (SocialException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
