<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Engine\Auth\Social\Jwt;
use App\Engine\Http\Client\ClientRequest;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\Transport;

/**
 * An OpenID Connect issuer in a Transport: discovery, keys, and a token
 * endpoint that holds the client to the protocol -- the code once, the PKCE
 * verifier that matches the challenge, the secret, the same redirect_uri.
 *
 * A test plays the browser: it reads the authorization URL SocialLogin made,
 * and calls authorize() as the visitor approving the consent screen.
 */
final class FakeOidc implements Transport
{
    public const ISSUER = 'https://idp.example';

    /** @var array<string, array{challenge: string, nonce: string, redirect: string, claims: array<string, mixed>}> */
    private array $codes = [];

    /** @var array<string, mixed> claims to change in the next id_token, after the defaults */
    public array $tamper = [];

    public int $discoveryFetches = 0;

    public int $keyFetches = 0;

    private readonly string $privateKey;

    /** One key for every instance: generating RSA keys is most of a test's time. */
    private static ?\OpenSSLAsymmetricKey $key = null;

    /** @var array<string, mixed> */
    private readonly array $jwk;

    public function __construct(
        private readonly string $clientId = 'client-1',
        private readonly string $clientSecret = 'secret-1',
        public string $kid = 'key-1',
    ) {
        $key = self::$key ??= \openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]) ?: throw new \RuntimeException('openssl cannot make a key.');
        \openssl_pkey_export($key, $pem);
        $this->privateKey = (string) $pem;
        $details = \openssl_pkey_get_details($key);
        \assert(\is_array($details));
        $this->jwk = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'n' => Jwt::encode($details['rsa']['n']), 'e' => Jwt::encode($details['rsa']['e'])];
    }

    /**
     * The visitor approves: a code for the authorization URL's parameters.
     *
     * @param array<string, mixed> $claims
     */
    public function authorize(string $authorizationUrl, array $claims): string
    {
        \parse_str((string) \parse_url($authorizationUrl, \PHP_URL_QUERY), $query);
        \assert(($query['client_id'] ?? null) === $this->clientId && ($query['code_challenge_method'] ?? null) === 'S256');

        $code = \bin2hex(\random_bytes(8));
        $this->codes[$code] = [
            'challenge' => self::str($query, 'code_challenge'),
            'nonce' => self::str($query, 'nonce'),
            'redirect' => self::str($query, 'redirect_uri'),
            'claims' => $claims,
        ];

        return $code;
    }

    public function send(ClientRequest $request): ClientResponse
    {
        $path = (string) \parse_url($request->url, \PHP_URL_PATH);

        return match ($path) {
            '/.well-known/openid-configuration' => $this->discovery(),
            '/keys' => $this->keys(),
            '/token' => $this->token($request),
            default => new ClientResponse(404),
        };
    }

    private function discovery(): ClientResponse
    {
        ++$this->discoveryFetches;

        return self::json([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/keys',
        ]);
    }

    private function keys(): ClientResponse
    {
        ++$this->keyFetches;

        return self::json(['keys' => [$this->jwk + ['kid' => $this->kid]]]);
    }

    private function token(ClientRequest $request): ClientResponse
    {
        \parse_str($request->body, $form);
        $code = $this->codes[self::str($form, 'code')] ?? null;

        if ($code === null) {
            return self::json(['error' => 'invalid_grant'], 400);
        }

        unset($this->codes[self::str($form, 'code')]);

        if (($form['client_id'] ?? null) !== $this->clientId || ($form['client_secret'] ?? null) !== $this->clientSecret) {
            return self::json(['error' => 'invalid_client'], 401);
        }

        if (Jwt::encode(\hash('sha256', self::str($form, 'code_verifier'), true)) !== $code['challenge']) {
            return self::json(['error' => 'invalid_grant', 'error_description' => 'PKCE'], 400);
        }

        if (($form['redirect_uri'] ?? null) !== $code['redirect']) {
            return self::json(['error' => 'invalid_grant', 'error_description' => 'redirect_uri'], 400);
        }

        $claims = [
            'iss' => self::ISSUER,
            'aud' => $this->clientId,
            'iat' => \time(),
            'exp' => \time() + 300,
            'nonce' => $code['nonce'],
            ...$code['claims'],
            ...$this->tamper,
        ];

        return self::json([
            'access_token' => 'at-' . \bin2hex(\random_bytes(4)),
            'token_type' => 'Bearer',
            'id_token' => Jwt::sign($claims, $this->privateKey, 'RS256', $this->kid),
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): ClientResponse
    {
        return new ClientResponse($status, ['Content-Type' => 'application/json'], \json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
