<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Auth\Authenticators\SessionAuthenticator;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\Secret;
use App\Engine\Session\SessionManager;

/**
 * Who this request is, and how it stops or starts being somebody.
 *
 * **Lazy, like the session underneath it.** Authenticators run the first time
 * something asks, so a public page that never asks pays nothing and never
 * touches storage. AuthGuard asks only for routes that declared a requirement.
 *
 * **Authenticators run in order and the first answer wins.** Bearer token
 * before session cookie: a token is an explicit credential put on the request
 * on purpose, a cookie is one the browser attached on its own, and when both
 * are present the deliberate one is the one that was meant.
 */
final class AuthManager
{
    private ?Request $request = null;

    private ?Identity $identity = null;

    /** See ignoreSession(). */
    private bool $sessionIgnored = false;

    /** Whether $identity came from the session cookie, and so goes with it. */
    private bool $identifiedBySession = false;

    /** @param list<Authenticator> $authenticators */
    public function __construct(
        private readonly UserProvider $provider,
        private readonly Password $passwords,
        private readonly array $authenticators = [],
        private readonly ?SessionManager $sessions = null,
        private readonly ?HookEngine $hooks = null,
        /** @var list<string> */
        private readonly array $guestRoles = [],
        /** Counts failed password checks; null switches the throttle off. See throttled(). */
        private readonly ?RateLimiter $limiter = null,
        /** @var array{per_login_ip?: string, per_login?: string, per_ip?: string} */
        private readonly array $throttle = [],
    ) {}

    /**
     * Failed password checks allowed before verify() answers 429.
     *
     * Three limits, because each stops a different attack and none can do the
     * others' job:
     *
     *   per_login_ip  one address guessing one account's password -- the
     *                 common case, and the strict one
     *   per_ip        one address trying a list of accounts ("spraying")
     *   per_login     many addresses, one account: a botnet
     *
     * The per-account limits are what an attacker can use to lock somebody
     * else out, so the one that applies to every address is deliberately
     * generous: it stops a distributed guess, not a user who mistyped. Only
     * failures are counted, and the right password clears the strict one.
     */
    public const THROTTLE = [
        'per_login_ip' => '5/15m',
        'per_ip' => '30/15m',
        'per_login' => '100/1h',
    ];

    public function describe(): string
    {
        $names = \array_map(
            static fn(Authenticator $authenticator): string => $authenticator->describe(),
            $this->authenticators,
        );

        return $names === [] ? 'nothing attached' : \implode(', ', $names);
    }

    public function provider(): UserProvider
    {
        return $this->provider;
    }

    /** request.received: remember what is being served, and identify nobody yet. */
    public function onRequest(Request $request): void
    {
        $this->request = $request;
        $this->identity = null;
        $this->sessionIgnored = false;
        $this->identifiedBySession = false;
    }

    /**
     * For the rest of this request, the session cookie identifies nobody.
     *
     * Called for a route that opted out of CSRF: a cookie the browser attached
     * by itself is exactly what CSRF exploits, so with the check off only a
     * credential the client put on the request deliberately -- a bearer token
     * -- may say who it is. A login during the request still counts.
     */
    public function ignoreSession(): void
    {
        $this->sessionIgnored = true;

        if ($this->identifiedBySession) {
            $this->identity = null;
            $this->identifiedBySession = false;
        }
    }

    /**
     * Who this request is -- a guest if nothing said otherwise.
     *
     * Never null. An unauthenticated request is Identity::guest(), so nothing
     * downstream has a null case to forget, and a guest can still be granted
     * capabilities by configuration if an application wants anonymous readers.
     */
    public function identity(): Identity
    {
        if ($this->identity !== null) {
            return $this->identity;
        }

        $request = $this->request;

        if ($request === null) {
            return $this->identity = Identity::guest($this->guestRoles);
        }

        foreach ($this->authenticators as $authenticator) {
            $bySession = $authenticator instanceof SessionAuthenticator;

            if ($bySession && $this->sessionIgnored) {
                continue;
            }

            $identity = $authenticator->identify($request);

            if ($identity !== null) {
                $this->hooks?->do('auth.identified', $identity, $authenticator->describe());
                $this->identifiedBySession = $bySession;

                return $this->identity = $identity;
            }
        }

        return $this->identity = Identity::guest($this->guestRoles);
    }

    public function check(): bool
    {
        return !$this->identity()->isGuest();
    }

    public function guest(): bool
    {
        return $this->identity()->isGuest();
    }

    /**
     * Check a password and log in if it is right.
     *
     * Returns null for every kind of failure -- no such account, wrong
     * password, suspended -- and takes about the same time doing it. **The
     * caller must not explain which.** "No account with that email" is a way to
     * find out which addresses are registered, and the usual next step is to
     * try that address on other sites.
     *
     * The hook fires for both outcomes, because a failed attempt is the thing
     * worth counting: rate limiting a login form and alerting on a burst both
     * need to hear about the failures, not the successes.
     */
    public function attempt(string $login, Secret $password): ?Identity
    {
        $account = $this->verify($login, $password);

        if ($account === null) {
            return null;
        }

        $this->login($account->identity);

        return $account->identity;
    }

    /**
     * attempt() without the logging in: the account whose password this is,
     * or null, with the same care about timing and the same hooks. For a
     * second step before login() -- a TOTP code (see TwoFactor).
     */
    public function verify(string $login, Secret $password): ?Account
    {
        if ($login === '') {
            throw AuthException::emptyLogin();
        }

        $keys = $this->throttleKeys($login);
        $this->refuseIfThrottled($keys);

        $account = $this->provider->byLogin($login);

        // Even when there is no account: see Password::verify().
        $verified = $this->passwords->verify($password, $account?->passwordHash);

        if ($account === null || !$account->canUsePassword() || !$verified) {
            $this->countFailure($keys);
            $this->hooks?->do('auth.failed', $login, $this->request);

            return null;
        }

        if ($this->limiter !== null && isset($keys['per_login_ip'])) {
            $this->limiter->clear($keys['per_login_ip']);
        }

        // The one moment the application holds the plaintext, and so the only
        // moment an old hash can be upgraded. A framework that did not say so
        // would leave every account on whatever was default the year it was
        // created -- but writing it is the application's job, because only the
        // provider knows where the hash lives.
        if ($account->passwordHash !== null && $this->passwords->needsRehash($account->passwordHash)) {
            $this->hooks?->do('auth.rehash', $account->identity, $this->passwords->hash($password));
        }

        return $account;
    }

    /**
     * The counter keys for this attempt, by limit name.
     *
     * The login is hashed -- a counter's key is a file name in one store and a
     * row in another, and neither should be a list of the addresses somebody
     * tried. Without a client address (a console command) only the per-login
     * limit applies.
     *
     * @return array<string, string>
     */
    private function throttleKeys(string $login): array
    {
        if ($this->limiter === null) {
            return [];
        }

        $who = \hash('sha256', \mb_strtolower(\trim($login)));
        $ip = $this->request?->ip();

        $keys = ['per_login' => 'login:' . $who];

        if ($ip !== null && $ip !== '') {
            $keys['per_login_ip'] = 'login:' . $who . '|' . $ip;
            $keys['per_ip'] = 'login-ip:' . $ip;
        }

        return $keys;
    }

    /**
     * Refuse before any password work when a limit is already used up.
     *
     * Before, not after: the point is that a guess past the limit is never
     * checked at all, so a correct one arriving there tells the attacker
     * nothing -- it gets the same 429 as a wrong one.
     *
     * @param array<string, string> $keys
     */
    private function refuseIfThrottled(array $keys): void
    {
        if ($this->limiter === null) {
            return;
        }

        foreach ($keys as $name => $key) {
            $standing = $this->limiter->check($key, $this->limitFor($name));

            if (!$standing->allowed) {
                $this->hooks?->do('auth.throttled', $name, $this->request);

                throw HttpException::tooManyRequests($standing->retryAfter);
            }
        }
    }

    /** @param array<string, string> $keys */
    private function countFailure(array $keys): void
    {
        foreach ($keys as $name => $key) {
            $this->limiter?->attempt($key, $this->limitFor($name));
        }
    }

    private function limitFor(string $name): string
    {
        return $this->throttle[$name] ?? self::THROTTLE[$name];
    }

    /**
     * Make this identity the current one, and say so to the session.
     *
     * **The session id is regenerated here**, which is the reason
     * Session::regenerate() exists. Without it, an attacker who planted a
     * session id in the victim's browser before the login is holding a valid
     * authenticated session after it -- session fixation, and the one place in
     * a web application where it can be closed for good.
     *
     * Callable directly, without a password, which is what a "log in as" for
     * support staff or a single-sign-on callback needs. That is deliberate and
     * it is why it fires a hook: an application that wants to audit who became
     * whom listens here rather than trusting every caller to remember.
     */
    public function login(Identity $identity): void
    {
        $this->identity = $identity;
        $this->identifiedBySession = false;

        if ($this->sessions !== null) {
            $session = $this->sessions->session();
            // Not followable: an id planted before the login must not lead to
            // the session the login created, even for the grace window.
            $session->regenerate(followable: false);
            SessionAuthenticator::remember($session, $identity);
        }

        $this->hooks?->do('auth.login', $identity);
    }

    /**
     * Stop being anybody.
     *
     * invalidate(), not just forgetting the key: the session id changes and the
     * data goes with it. A logout that left the session intact would leave
     * whatever was in it -- a basket, a half-finished form, the previous user's
     * filters -- for whoever sits down at that machine next.
     */
    public function logout(): void
    {
        $previous = $this->identity();

        if ($this->sessions !== null && $this->sessions->isResumable()) {
            $this->sessions->session()->invalidate();
        }

        $this->identity = Identity::guest($this->guestRoles);
        $this->identifiedBySession = false;

        if (!$previous->isGuest()) {
            $this->hooks?->do('auth.logout', $previous);
        }
    }
}
