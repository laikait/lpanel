<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Cache\Cache;
use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Request;

/**
 * Any OpenID Connect provider: Google, Microsoft, Apple, Okta, Auth0,
 * Keycloak, GitLab…
 *
 *     OidcProvider::google($http, $clientId, $secret);
 *     new OidcProvider($http, 'keycloak', 'https://sso.example.com/realms/staff', $clientId, $secret);
 *
 * Everything else comes from the issuer's discovery document,
 * <issuer>/.well-known/openid-configuration: where to send the visitor, where
 * to exchange the code, and the keys its tokens are signed with.
 *
 * **The id_token is checked, not trusted.** Its signature against the
 * issuer's published keys (a key that is not there is fetched again once, for
 * a provider that has just rotated), and then its claims: the issuer, that
 * this application is the audience, that it has not expired, and the nonce
 * that ties it to this sign-in. A token that fails any of them is refused.
 *
 * **An email counts as verified only when the token says email_verified.**
 * Google does; Apple does (as the string "true"); Microsoft does not, so a
 * Microsoft address is never used to link an existing account.
 *
 * Discovery and keys are cached when a Cache is given -- a day and an hour --
 * so a sign-in costs one request to the provider, the code exchange.
 */
final class OidcProvider extends OAuth2Provider
{
    public const DISCOVERY_TTL = 86400;

    public const KEYS_TTL = 3600;

    /** @var ?array<string, mixed> */
    private ?array $discovery = null;

    /**
     * @param list<string>                 $scopes
     * @param array<string, string>        $authorizationParameters
     * @param ?\Closure(): string          $secretFactory  for a client secret that is made, not stored (Apple)
     * @param ?\Closure(): int             $clock
     */
    public function __construct(
        Client $http,
        string $name,
        private readonly string $issuer,
        string $clientId,
        #[\SensitiveParameter]
        string $clientSecret = '',
        array $scopes = ['openid', 'email', 'profile'],
        array $authorizationParameters = [],
        private readonly ?Cache $cache = null,
        private readonly ?\Closure $secretFactory = null,
        private readonly int $leeway = 60,
        private readonly ?\Closure $clock = null,
    ) {
        if (\preg_match('#^https?://#', $issuer) !== 1) {
            throw SocialException::misconfigured($name, 'issuer must be an http(s) URL.');
        }

        parent::__construct($http, $name, $clientId, $clientSecret, $scopes, $authorizationParameters);
    }

    /** @param list<string> $scopes */
    public static function google(Client $http, string $clientId, #[\SensitiveParameter] string $clientSecret, ?Cache $cache = null, array $scopes = ['openid', 'email', 'profile']): self
    {
        return new self($http, 'google', 'https://accounts.google.com', $clientId, $clientSecret, $scopes, [], $cache);
    }

    /**
     * @param string       $tenant "common", "organizations", "consumers", or a tenant id for one organisation
     * @param list<string> $scopes
     */
    public static function microsoft(Client $http, string $clientId, #[\SensitiveParameter] string $clientSecret, string $tenant = 'common', ?Cache $cache = null, array $scopes = ['openid', 'email', 'profile']): self
    {
        return new self($http, 'microsoft', 'https://login.microsoftonline.com/' . \rawurlencode($tenant) . '/v2.0', $clientId, $clientSecret, $scopes, [], $cache);
    }

    /**
     * Sign in with Apple. Its client secret is a token signed with the key
     * downloaded from the Apple developer account, made fresh as needed.
     *
     * Apple posts the callback (response_mode=form_post), so the callback
     * route must accept POST, without a CSRF token: the state check is what
     * protects it.
     *
     * @param string       $clientId   the Services ID
     * @param string       $privateKey the .p8 key's PEM contents
     * @param list<string> $scopes
     */
    public static function apple(
        Client $http,
        string $clientId,
        string $teamId,
        string $keyId,
        #[\SensitiveParameter]
        string $privateKey,
        ?Cache $cache = null,
        array $scopes = ['openid', 'email', 'name'],
    ): self {
        if ($teamId === '' || $keyId === '' || $privateKey === '') {
            throw SocialException::misconfigured('apple', 'team_id, key_id and private_key are all required.');
        }

        $secret = static fn(): string => Jwt::sign([
            'iss' => $teamId,
            'iat' => \time(),
            'exp' => \time() + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ], $privateKey, 'ES256', $keyId);

        return new self($http, 'apple', 'https://appleid.apple.com', $clientId, '', $scopes, ['response_mode' => 'form_post'], $cache, $secret);
    }

    public function user(Request $callback, string $code, string $redirectUri, string $verifier, string $nonce): SocialUser
    {
        $token = $this->exchange($code, $redirectUri, $verifier);
        $idToken = self::string($token, 'id_token') ?? throw SocialException::invalidToken('the provider returned no id_token; is "openid" among the scopes?');
        $claims = $this->claims($idToken, $nonce);

        $email = self::string($claims, 'email');
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true';
        $name = self::string($claims, 'name') ?? \trim((self::string($claims, 'given_name') ?? '') . ' ' . (self::string($claims, 'family_name') ?? ''));

        // Apple sends the name once, on the first sign-in, as a form field --
        // never in the token.
        $posted = $callback->input('user');

        if ($name === '' && \is_string($posted)) {
            $user = \json_decode($posted, true);
            $first = \is_array($user) && \is_array($user['name'] ?? null) ? $user['name'] : [];
            $name = \trim((\is_string($first['firstName'] ?? null) ? $first['firstName'] : '') . ' ' . (\is_string($first['lastName'] ?? null) ? $first['lastName'] : ''));
        }

        return new SocialUser(
            $this->name,
            (string) self::string($claims, 'sub'),
            $email === null ? null : \mb_strtolower($email),
            $email !== null && $verified,
            $name,
            self::string($claims, 'picture'),
            $claims,
        );
    }

    /**
     * The id_token's claims, after every check.
     *
     * @return array<string, mixed>
     */
    public function claims(string $idToken, string $nonce): array
    {
        try {
            $claims = Jwt::verify($idToken, $this->keys(false));
        } catch (SocialException $e) {
            // A key that is not published may have just been rotated in.
            if (!$e->isUnknownKey()) {
                throw $e;
            }

            $claims = Jwt::verify($idToken, $this->keys(true));
        }

        $now = $this->clock !== null ? ($this->clock)() : \time();
        $issuer = (string) ($this->discovery()['issuer'] ?? $this->issuer);

        // Microsoft's multi-tenant endpoints publish "{tenantid}" in place of
        // the tenant, which each token names in its tid claim.
        if (\str_contains($issuer, '{tenantid}') && \is_string($claims['tid'] ?? null)) {
            $issuer = \str_replace('{tenantid}', $claims['tid'], $issuer);
        }

        if (($claims['iss'] ?? null) !== $issuer) {
            throw SocialException::claim('iss', 'is not this provider.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = \is_array($audience) ? $audience : [$audience];

        if (!\in_array($this->clientId, $audiences, true)) {
            throw SocialException::claim('aud', 'is not this application.');
        }

        if (\count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) {
            throw SocialException::claim('azp', 'is not this application.');
        }

        if (!\is_int($claims['exp'] ?? null) || $claims['exp'] + $this->leeway < $now) {
            throw SocialException::claim('exp', 'has passed.');
        }

        if (\is_int($claims['iat'] ?? null) && $claims['iat'] - $this->leeway > $now) {
            throw SocialException::claim('iat', 'is in the future.');
        }

        if (!\is_string($claims['nonce'] ?? null) || !\hash_equals($nonce, $claims['nonce'])) {
            throw SocialException::claim('nonce', 'does not belong to this sign-in.');
        }

        if (self::string($claims, 'sub') === null) {
            throw SocialException::claim('sub', 'is missing.');
        }

        return $claims;
    }

    protected function nonceParameter(string $nonce): array
    {
        return ['nonce' => $nonce];
    }

    protected function secret(): string
    {
        return $this->secretFactory !== null ? ($this->secretFactory)() : parent::secret();
    }

    protected function authorizationEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    protected function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint');
    }

    private function endpoint(string $name): string
    {
        $url = $this->discovery()[$name] ?? null;

        return \is_string($url) && $url !== '' ? $url : throw SocialException::misconfigured($this->name, \sprintf('the discovery document has no %s.', $name));
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }

        $url = \rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
        $fetch = function () use ($url): array {
            $document = $this->send(fn(): ClientResponse => $this->http->accept('application/json')->get($url), 'fetching its discovery document')->json();

            return \is_array($document) ? $document : throw SocialException::providerFailed($this->name, 'reading its discovery document', 200, 'not JSON');
        };

        /** @var array<string, mixed> $document */
        $document = $this->cache !== null
            ? $this->cache->remember('social.discovery.' . \hash('sha256', $url), $fetch, self::DISCOVERY_TTL)
            : $fetch();

        return $this->discovery = $document;
    }

    /** @return list<array<string, mixed>> */
    private function keys(bool $fresh): array
    {
        $url = $this->endpoint('jwks_uri');
        $key = 'social.jwks.' . \hash('sha256', $url);
        $fetch = function () use ($url): array {
            $set = $this->send(fn(): ClientResponse => $this->http->accept('application/json')->get($url), 'fetching its signing keys')->json();
            $keys = \is_array($set) && \is_array($set['keys'] ?? null) ? $set['keys'] : [];

            return \array_values(\array_filter($keys, \is_array(...)));
        };

        if ($this->cache === null) {
            return $fetch();
        }

        if ($fresh) {
            $keys = $fetch();
            $this->cache->set($key, $keys, self::KEYS_TTL);

            return $keys;
        }

        /** @var list<array<string, mixed>> */
        return $this->cache->remember($key, $fetch, self::KEYS_TTL);
    }
}
