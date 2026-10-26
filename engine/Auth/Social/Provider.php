<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Http\Request;

/**
 * One site people can sign in with.
 *
 * The built-in ones are OidcProvider (Google, Microsoft, Apple, any OpenID
 * Connect issuer), GitHubProvider and FacebookProvider. Your own is a class
 * implementing this, registered with SocialLogin::extend().
 *
 * SocialLogin owns the flow -- state, PKCE, the nonce, the cookie that holds
 * them -- so a provider only has to say where to send the visitor and how to
 * turn the code that comes back into a SocialUser.
 */
interface Provider
{
    public function name(): string;

    /**
     * Where to send the visitor.
     *
     * @param string $challenge the PKCE S256 code challenge
     */
    public function authorizationUrl(string $redirectUri, string $state, string $challenge, string $nonce): string;

    /**
     * Exchange the code for the user. The request is the callback, for
     * providers (Apple) that post extra fields to it.
     *
     * @throws SocialException
     */
    public function user(Request $callback, string $code, string $redirectUri, string $verifier, string $nonce): SocialUser;
}
