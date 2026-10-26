<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Request;

/**
 * Sign in with Facebook: OAuth 2, then the Graph API's /me.
 *
 * **The email is treated as unverified** unless trust_email is set: Facebook
 * does not say whether the address was confirmed, and linking an existing
 * account on an unproven address is how one person signs in as another.
 */
final class FacebookProvider extends OAuth2Provider
{
    public const VERSION = 'v19.0';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        Client $http,
        string $clientId,
        #[\SensitiveParameter]
        string $clientSecret,
        array $scopes = ['email', 'public_profile'],
        private readonly bool $trustEmail = false,
        string $name = 'facebook',
    ) {
        parent::__construct($http, $name, $clientId, $clientSecret, $scopes);
    }

    protected function authorizationEndpoint(): string
    {
        return 'https://www.facebook.com/' . self::VERSION . '/dialog/oauth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://graph.facebook.com/' . self::VERSION . '/oauth/access_token';
    }

    public function user(Request $callback, string $code, string $redirectUri, string $verifier, string $nonce): SocialUser
    {
        $access = self::string($this->exchange($code, $redirectUri, $verifier), 'access_token')
            ?? throw SocialException::providerFailed($this->name, 'exchanging the code', 200, 'no access_token');
        $me = $this->api('https://graph.facebook.com/' . self::VERSION . '/me?fields=id,name,email,picture.type(large)', $access, 'reading the user');
        $email = self::string($me, 'email');
        $picture = \is_array($me['picture'] ?? null) && \is_array($me['picture']['data'] ?? null) ? self::string($me['picture']['data'], 'url') : null;

        return new SocialUser(
            $this->name,
            self::string($me, 'id') ?? throw SocialException::providerFailed($this->name, 'reading the user', 200, 'no id'),
            $email === null ? null : \mb_strtolower($email),
            $email !== null && $this->trustEmail,
            self::string($me, 'name') ?? '',
            $picture,
            $me,
        );
    }
}
