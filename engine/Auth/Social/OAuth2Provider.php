<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\HttpClientException;

/**
 * What every OAuth 2 authorization-code provider shares: the authorization
 * URL, with PKCE, and the code exchange.
 */
abstract class OAuth2Provider implements Provider
{
    /**
     * @param list<string>          $scopes
     * @param array<string, string> $authorizationParameters added to the authorization URL
     */
    public function __construct(
        protected readonly Client $http,
        protected readonly string $name,
        protected readonly string $clientId,
        #[\SensitiveParameter]
        protected readonly string $clientSecret,
        protected readonly array $scopes = [],
        protected readonly array $authorizationParameters = [],
    ) {
        if ($clientId === '') {
            throw SocialException::misconfigured($name, 'client_id is empty.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    abstract protected function authorizationEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    public function authorizationUrl(string $redirectUri, string $state, string $challenge, string $nonce): string
    {
        $endpoint = $this->authorizationEndpoint();
        $query = \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => \implode(' ', $this->scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            ...$this->nonceParameter($nonce),
            ...$this->authorizationParameters,
        ], '', '&', \PHP_QUERY_RFC3986);

        return $endpoint . (\str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * OpenID providers send the nonce; plain OAuth 2 has nowhere to put it.
     *
     * @return array<string, string>
     */
    protected function nonceParameter(string $nonce): array
    {
        return [];
    }

    protected function secret(): string
    {
        return $this->clientSecret;
    }

    /**
     * The token endpoint's answer to the code.
     *
     * @return array<string, mixed>
     */
    protected function exchange(string $code, string $redirectUri, string $verifier): array
    {
        $response = $this->send(fn(): ClientResponse => $this->http->asForm()->accept('application/json')->post($this->tokenEndpoint(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->secret(),
            'code_verifier' => $verifier,
        ]), 'exchanging the code');

        $token = $response->json();

        if (!\is_array($token)) {
            throw SocialException::providerFailed($this->name, 'exchanging the code', $response->status(), 'not JSON');
        }

        if (isset($token['error'])) {
            throw SocialException::providerFailed($this->name, 'exchanging the code', $response->status(), \is_string($token['error']) ? $token['error'] : 'error');
        }

        /** @var array<string, mixed> $token */
        return $token;
    }

    /**
     * A JSON object from an API, with the access token.
     *
     * @param array<string, string> $headers
     *
     * @return array<array-key, mixed>
     */
    protected function api(string $url, string $accessToken, string $step, array $headers = []): array
    {
        $response = $this->send(fn(): ClientResponse => $this->http->withToken($accessToken)->withHeaders($headers)->accept('application/json')->get($url), $step);
        $data = $response->json();

        if (!\is_array($data)) {
            throw SocialException::providerFailed($this->name, $step, $response->status(), 'not JSON');
        }

        return $data;
    }

    /** @param \Closure(): ClientResponse $request */
    protected function send(\Closure $request, string $step): ClientResponse
    {
        try {
            $response = $request();
        } catch (HttpClientException $e) {
            throw SocialException::providerFailed($this->name, $step, 0, $e->getMessage());
        }

        if (!$response->successful()) {
            $data = $response->json();
            $detail = \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : '';

            throw SocialException::providerFailed($this->name, $step, $response->status(), $detail);
        }

        return $response;
    }

    /** @param array<array-key, mixed> $data */
    protected static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
