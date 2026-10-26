<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Auth\Account;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TwoFactor;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Auth\TwoFactorResult;
use App\Engine\Auth\UserProvider;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Cookie;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Routing\AppUrl;
use App\Engine\Security\Encrypter;
use App\Engine\Security\Signer;

/**
 * Sign in with Google, Microsoft, Apple, GitHub, Facebook, or any OpenID
 * Connect provider.
 *
 *     // GET /auth/{provider}
 *     return $social->redirect($provider, $request, returnTo: '/account');
 *
 *     // GET|POST /auth/{provider}/callback   (named social.callback)
 *     $user = $social->callback($provider, $request);       // who the provider says it is
 *     $result = $social->attempt($user);                    // a TwoFactorResult, as for a password
 *
 * An account with two-factor turned on is not logged in by the provider alone:
 * attempt() answers ChallengeRequired and TwoFactor::challenge() finishes it.
 *
 * The Shared module declares both routes, so configuring a provider under
 * auth.social.providers is all a fresh application needs.
 *
 * **The flow is the authorization-code flow with PKCE.** The state, the PKCE
 * verifier and the OpenID nonce are made here, kept in a short-lived cookie
 * encrypted with APP_KEY, and checked on the way back: a callback that does
 * not carry this browser's state is refused, which is what stops somebody
 * logging a victim into the attacker's account. The cookie rather than the
 * session, because Apple posts its callback from another site, and a Lax
 * session cookie does not come with it.
 *
 * **Who the visitor is, is decided in one place**, account(), and the
 * engine's default answer is the cautious one: an account already linked to
 * that provider id; else an account with the same email, linked now, only
 * when the provider vouches the email is verified; else a new account when
 * auth.social.register is on; else nobody. The social.account filter can
 * change the answer.
 */
final class SocialLogin
{
    public const COOKIE = 'lphp_social';

    /** How long a visitor has on the provider's pages. */
    public const TTL = 600;

    /** @var array<string, Provider|\Closure(): Provider> */
    private array $providers;

    /**
     * @param array<string, Provider|\Closure(): Provider> $providers
     */
    public function __construct(
        array $providers,
        private readonly Encrypter $encrypter,
        private readonly AppUrl $urls,
        private readonly AuthManager $auth,
        private readonly UserProvider $accounts,
        private readonly ?FilterEngine $filters = null,
        private readonly ?HookEngine $hooks = null,
        private readonly bool $register = false,
        private readonly string $callbackRoute = 'social.callback',
        private readonly ?TwoFactor $twoFactor = null,
    ) {
        $this->providers = $providers;
    }

    /** Add a provider, or replace one: your own Provider, or a built-in one configured in code. */
    public function extend(string $name, Provider|\Closure $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_map(\strval(...), \array_keys($this->providers));
    }

    /** @throws SocialException for a provider that is not configured */
    public function provider(string $name): Provider
    {
        $provider = $this->providers[$name] ?? throw SocialException::unknownProvider($name, $this->names());

        if ($provider instanceof \Closure) {
            $provider = $this->providers[$name] = $provider();
        }

        return $provider;
    }

    /**
     * Send the visitor to the provider.
     *
     * @param ?string $returnTo a path on this site to land on afterwards; anything else is ignored
     */
    public function redirect(string $name, Request $request, ?string $returnTo = null): RedirectResponse
    {
        $provider = $this->provider($name);
        $state = Signer::token(32);
        $verifier = Signer::token(48);
        $nonce = Signer::token(32);

        $flow = \json_encode([
            'p' => $name,
            's' => $state,
            'v' => $verifier,
            'n' => $nonce,
            'r' => self::localPath($returnTo),
            'x' => \time() + self::TTL,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $url = $provider->authorizationUrl(
            $this->callbackUrl($name),
            $state,
            Jwt::encode(\hash('sha256', $verifier, true)),
            $nonce,
        );

        return (new RedirectResponse($url))->withCookie(Cookie::encrypted(
            $this->encrypter,
            self::COOKIE,
            $flow,
            \time() + self::TTL,
            '/',
            '',
            $request->isSecure(),
            true,
            // None so that Apple's cross-site POST brings it back; it needs
            // Secure, so over plain HTTP (development) it is Lax.
            $request->isSecure() ? 'None' : 'Lax',
        ));
    }

    /**
     * Who the provider says the visitor is.
     *
     * @throws SocialException a client error for a stale, replayed or denied sign-in
     */
    public function callback(string $name, Request $request): SocialUser
    {
        $provider = $this->provider($name);
        $error = self::parameter($request, 'error');

        if ($error !== null) {
            throw SocialException::denied($error, self::parameter($request, 'error_description') ?? '');
        }

        $flow = $this->flow($request);
        $state = self::parameter($request, 'state');
        $code = self::parameter($request, 'code');

        if ($flow === null || $flow['p'] !== $name || $state === null || !\hash_equals($flow['s'], $state) || $code === null) {
            throw SocialException::stateMismatch();
        }

        $user = $provider->user($request, $code, $this->callbackUrl($name), $flow['v'], $flow['n']);
        $this->hooks?->do('auth.social_user', $user);

        return $user;
    }

    /** Where to land after the callback: the path redirect() was given, or the front page. */
    public function returnTo(Request $request): string
    {
        return $this->flow($request)['r'] ?? ($request->basePath() . '/');
    }

    /** Clears the flow cookie: attach it to the callback's response. */
    public function forgetCookie(): Cookie
    {
        return Cookie::forget(self::COOKIE);
    }

    /**
     * The account $user is, by the rules in the class description and the
     * social.account filter. Null for nobody.
     *
     * @throws AuthException when the UserProvider does not implement SocialAccounts
     */
    public function account(SocialUser $user): ?Account
    {
        $accounts = $this->accounts instanceof SocialAccounts
            ? $this->accounts
            : throw AuthException::providerLacks(SocialAccounts::class, $this->accounts::class, 'Social login');

        $account = $accounts->findBySocial($user->provider, $user->id);

        if ($account === null && $user->emailVerified && $user->email !== null) {
            $account = $accounts->findByEmail($user->email);

            if ($account !== null) {
                $accounts->linkSocial($account, $user);
            }
        }

        if ($account === null && $this->register) {
            $account = $accounts->createFromSocial($user);
        }

        if ($this->filters !== null) {
            /** @var Account|false $filtered */
            $filtered = $this->filters->apply('social.account', $account ?? false, $user);
            $account = $filtered instanceof Account ? $filtered : null;
        }

        return $account;
    }

    /**
     * Sign in as whoever $user is, the way TwoFactor::attempt() does for a
     * password: logged in, waiting for a code, or refused.
     *
     * **The provider is a first factor, never both.** An account with
     * two-factor turned on is left waiting for its code -- TwoFactor::pending()
     * and challenge() finish it -- because otherwise turning two-factor on would
     * protect nothing for anybody who can sign in to the provider, or create an
     * account there with the same verified email.
     */
    public function attempt(SocialUser $user): TwoFactorResult
    {
        $account = $this->account($user);

        if ($account === null || !$account->active) {
            return TwoFactorResult::Failed;
        }

        if ($this->twoFactor !== null) {
            return $this->twoFactor->start($account);
        }

        // Built without a TwoFactor there is nowhere to wait for the code, so an
        // account that needs one is refused rather than let in on the provider's
        // word alone.
        if ($this->accounts instanceof TwoFactorAccounts && $this->accounts->twoFactorSecret($account) !== null) {
            return TwoFactorResult::Failed;
        }

        $this->auth->login($account->identity);

        return TwoFactorResult::LoggedIn;
    }

    /**
     * Log in as whoever $user is. Null when that is nobody, a suspended account,
     * or an account with two-factor turned on -- which attempt() leaves waiting
     * for its code instead.
     */
    public function login(SocialUser $user): ?Identity
    {
        return $this->attempt($user) === TwoFactorResult::LoggedIn ? $this->auth->identity() : null;
    }

    private function callbackUrl(string $name): string
    {
        return $this->urls->route($this->callbackRoute, ['provider' => $name]);
    }

    /** @return ?array{p: string, s: string, v: string, n: string, r: ?string, x: int} */
    private function flow(Request $request): ?array
    {
        $json = $request->decryptedCookie($this->encrypter, self::COOKIE);
        $flow = $json === null ? null : \json_decode($json, true);

        if (!\is_array($flow) || !\is_int($flow['x'] ?? null) || $flow['x'] < \time()) {
            return null;
        }

        foreach (['p', 's', 'v', 'n'] as $key) {
            if (!\is_string($flow[$key] ?? null)) {
                return null;
            }
        }

        /** @var array{p: string, s: string, v: string, n: string, r: ?string, x: int} $flow */
        return $flow;
    }

    private static function parameter(Request $request, string $name): ?string
    {
        $value = $request->query($name) ?? $request->input($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** Only a path on this site: "/x", never "//evil.example" or "https://…". */
    private static function localPath(?string $path): ?string
    {
        if ($path === null || !\str_starts_with($path, '/') || \str_starts_with($path, '//') || \str_starts_with($path, '/\\') || \preg_match('/[\x00-\x1F]/', $path) === 1) {
            return null;
        }

        return $path;
    }
}
