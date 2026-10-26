<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

/**
 * JSON Web Tokens, as far as signing in with an OpenID provider needs them:
 * check an id_token against the provider's published keys, and sign Apple's
 * client secret.
 *
 *     $claims = Jwt::verify($idToken, $jwks['keys']);
 *     $secret = Jwt::sign(['iss' => $team, …], $privateKeyPem, 'ES256', $keyId);
 *
 * **Only asymmetric algorithms**: RS256/384/512 and ES256/384/512, through
 * openssl. "none" and the HS family are refused outright -- an HS256 token
 * "signed" with a provider's public key is the classic way to forge one, and
 * a provider never needs them here. The key is chosen by "kid" and must be of
 * the type the algorithm names.
 *
 * This checks the signature only. What the claims must say -- issuer,
 * audience, expiry, nonce -- is OidcProvider's to check, because only it knows.
 */
final class Jwt
{
    /** alg => [openssl digest, key type, EC coordinate bytes or 0] */
    private const ALGORITHMS = [
        'RS256' => [\OPENSSL_ALGO_SHA256, 'RSA', 0],
        'RS384' => [\OPENSSL_ALGO_SHA384, 'RSA', 0],
        'RS512' => [\OPENSSL_ALGO_SHA512, 'RSA', 0],
        'ES256' => [\OPENSSL_ALGO_SHA256, 'EC', 32],
        'ES384' => [\OPENSSL_ALGO_SHA384, 'EC', 48],
        'ES512' => [\OPENSSL_ALGO_SHA512, 'EC', 66],
    ];

    /** curve => [DER-encoded OID, coordinate bytes] */
    private const CURVES = [
        'P-256' => ["\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07", 32],
        'P-384' => ["\x06\x05\x2B\x81\x04\x00\x22", 48],
        'P-521' => ["\x06\x05\x2B\x81\x04\x00\x23", 66],
    ];

    /**
     * The claims of a token whose signature one of $keys verifies.
     *
     * @param list<array<string, mixed>> $keys a JWKS "keys" array
     *
     * @return array<string, mixed>
     *
     * @throws SocialException
     */
    public static function verify(string $jwt, array $keys): array
    {
        [$header, $claims, $input, $signature] = self::decode($jwt);
        $alg = $header['alg'] ?? null;

        if (!\is_string($alg) || !isset(self::ALGORITHMS[$alg])) {
            throw SocialException::invalidToken(\sprintf('the algorithm "%s" is not accepted.', \is_string($alg) ? $alg : '?'));
        }

        [$digest, $type, $size] = self::ALGORITHMS[$alg];
        $kid = $header['kid'] ?? null;
        $candidates = \array_values(\array_filter(
            $keys,
            static fn(array $key): bool => ($key['kty'] ?? null) === $type
                && (!isset($key['use']) || $key['use'] === 'sig')
                && (!isset($key['alg']) || $key['alg'] === $alg)
                && ($kid === null || ($key['kid'] ?? null) === $kid),
        ));

        if ($candidates === []) {
            throw SocialException::unknownKey(\is_string($kid) ? $kid : '');
        }

        if ($size > 0) {
            if (\strlen($signature) !== 2 * $size) {
                throw SocialException::invalidToken('the signature is the wrong length.');
            }

            $signature = self::rawToDer($signature);
        }

        foreach ($candidates as $key) {
            if (\openssl_verify($input, $signature, self::pem($key), $digest) === 1) {
                return $claims;
            }
        }

        throw SocialException::invalidToken('the signature does not verify.');
    }

    /**
     * A signed token: what Apple wants as a client secret.
     *
     * @param array<string, mixed> $claims
     */
    public static function sign(array $claims, #[\SensitiveParameter] string $privateKey, string $alg = 'ES256', ?string $kid = null): string
    {
        if (!isset(self::ALGORITHMS[$alg])) {
            throw SocialException::invalidToken(\sprintf('cannot sign with "%s".', $alg));
        }

        [$digest, , $size] = self::ALGORITHMS[$alg];
        $header = ['alg' => $alg, 'typ' => 'JWT'] + ($kid === null ? [] : ['kid' => $kid]);
        $input = self::encode(\json_encode($header, \JSON_THROW_ON_ERROR)) . '.' . self::encode(\json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        $key = \openssl_pkey_get_private($privateKey);

        if ($key === false || !\openssl_sign($input, $signature, $key, $digest)) {
            throw SocialException::invalidToken('the private key cannot sign; is it a PEM key of the right type?');
        }

        /** @var string $signature */
        return $input . '.' . self::encode($size > 0 ? self::derToRaw($signature, $size) : $signature);
    }

    /**
     * The parts of a token, unverified.
     *
     * @return array{array<string, mixed>, array<string, mixed>, string, string} header, claims, signing input, signature bytes
     */
    public static function decode(string $jwt): array
    {
        $parts = \explode('.', $jwt);

        if (\count($parts) !== 3) {
            throw SocialException::invalidToken('it is not three dot-separated parts.');
        }

        $header = \json_decode(self::decodePart($parts[0]), true);
        $claims = \json_decode(self::decodePart($parts[1]), true);

        if (!\is_array($header) || !\is_array($claims)) {
            throw SocialException::invalidToken('its header or claims are not JSON objects.');
        }

        /** @var array<string, mixed> $header */
        /** @var array<string, mixed> $claims */
        return [$header, $claims, $parts[0] . '.' . $parts[1], self::decodePart($parts[2])];
    }

    /**
     * A JWK as a PEM public key.
     *
     * @param array<string, mixed> $jwk
     */
    public static function pem(array $jwk): string
    {
        $der = match ($jwk['kty'] ?? null) {
            'RSA' => self::rsa($jwk),
            'EC' => self::ec($jwk),
            default => throw SocialException::invalidToken('a key is neither RSA nor EC.'),
        };

        return "-----BEGIN PUBLIC KEY-----\n" . \chunk_split(\base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function encode(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decodePart(string $part): string
    {
        $bytes = \base64_decode(\strtr($part, '-_', '+/'), true);

        if ($bytes === false) {
            throw SocialException::invalidToken('a part is not base64url.');
        }

        return $bytes;
    }

    /** @param array<string, mixed> $jwk */
    private static function rsa(array $jwk): string
    {
        if (!\is_string($jwk['n'] ?? null) || !\is_string($jwk['e'] ?? null)) {
            throw SocialException::invalidToken('an RSA key has no n or e.');
        }

        $key = self::sequence(self::integer(self::decodePart($jwk['n'])) . self::integer(self::decodePart($jwk['e'])));
        $algorithm = self::sequence("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");

        return self::sequence($algorithm . self::der(0x03, "\x00" . $key));
    }

    /** @param array<string, mixed> $jwk */
    private static function ec(array $jwk): string
    {
        $curve = self::CURVES[\is_string($jwk['crv'] ?? null) ? $jwk['crv'] : ''] ?? null;

        if ($curve === null || !\is_string($jwk['x'] ?? null) || !\is_string($jwk['y'] ?? null)) {
            throw SocialException::invalidToken('an EC key has an unknown curve or no x and y.');
        }

        [$oid, $size] = $curve;
        $point = "\x04" . \str_pad(self::decodePart($jwk['x']), $size, "\x00", \STR_PAD_LEFT) . \str_pad(self::decodePart($jwk['y']), $size, "\x00", \STR_PAD_LEFT);
        $algorithm = self::sequence("\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . $oid);

        return self::sequence($algorithm . self::der(0x03, "\x00" . $point));
    }

    /** JOSE's r||s to the DER SEQUENCE openssl verifies. */
    private static function rawToDer(string $raw): string
    {
        $half = \intdiv(\strlen($raw), 2);

        return self::sequence(self::integer(\substr($raw, 0, $half)) . self::integer(\substr($raw, $half)));
    }

    /** openssl's DER signature to JOSE's fixed-width r||s. */
    private static function derToRaw(string $der, int $size): string
    {
        $offset = 2 + ((\ord($der[1]) & 0x80) !== 0 ? \ord($der[1]) & 0x7F : 0);
        $raw = '';

        for ($i = 0; $i < 2; ++$i) {
            $length = \ord($der[$offset + 1]);
            $value = \ltrim(\substr($der, $offset + 2, $length), "\x00");
            $raw .= \str_pad($value, $size, "\x00", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    private static function integer(string $bytes): string
    {
        $bytes = \ltrim($bytes, "\x00");

        if ($bytes === '' || (\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function sequence(string $contents): string
    {
        return self::der(0x30, $contents);
    }

    private static function der(int $tag, string $contents): string
    {
        $length = \strlen($contents);

        if ($length < 0x80) {
            return \chr($tag) . \chr($length) . $contents;
        }

        $bytes = \ltrim(\pack('N', $length), "\x00");

        return \chr($tag) . \chr(0x80 | \strlen($bytes)) . $bytes . $contents;
    }
}
