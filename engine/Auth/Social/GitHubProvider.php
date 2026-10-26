<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Http\Request;

/**
 * Sign in with GitHub: OAuth 2, then the user and email APIs.
 *
 * GitHub is not an OpenID provider, so there is no id_token to check; the
 * access token is fetched over TLS straight from GitHub, which is what makes
 * the answers to /user and /user/emails trustworthy. The email used is the
 * primary one, verified only if GitHub says it is.
 */
final class GitHubProvider extends OAuth2Provider
{
    public const AUTHORIZE = 'https://github.com/login/oauth/authorize';

    public const TOKEN = 'https://github.com/login/oauth/access_token';

    public const API = 'https://api.github.com';

    protected function authorizationEndpoint(): string
    {
        return self::AUTHORIZE;
    }

    protected function tokenEndpoint(): string
    {
        return self::TOKEN;
    }

    public function user(Request $callback, string $code, string $redirectUri, string $verifier, string $nonce): SocialUser
    {
        $access = self::string($this->exchange($code, $redirectUri, $verifier), 'access_token')
            ?? throw SocialException::providerFailed($this->name, 'exchanging the code', 200, 'no access_token');
        $headers = ['X-GitHub-Api-Version' => '2022-11-28'];

        $user = $this->api(self::API . '/user', $access, 'reading the user', $headers);
        $email = null;
        $verified = false;

        if (\in_array('user:email', $this->scopes, true)) {
            foreach ($this->api(self::API . '/user/emails', $access, 'reading the email addresses', $headers) as $address) {
                if (\is_array($address) && ($address['primary'] ?? false) === true && \is_string($address['email'] ?? null)) {
                    $email = \mb_strtolower($address['email']);
                    $verified = ($address['verified'] ?? false) === true;
                }
            }
        }

        $id = self::string($user, 'id') ?? throw SocialException::providerFailed($this->name, 'reading the user', 200, 'no id');

        return new SocialUser(
            $this->name,
            $id,
            $email,
            $verified,
            self::string($user, 'name') ?? self::string($user, 'login') ?? '',
            self::string($user, 'avatar_url'),
            $user,
        );
    }
}
