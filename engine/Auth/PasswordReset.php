<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Hook\HookEngine;
use App\Engine\Mail\Mailer;
use App\Engine\Mail\Message;
use App\Engine\Routing\AppUrl;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\Secret;
use App\Engine\Security\Signer;

/**
 * "Forgot your password?"
 *
 *     // POST /forgot-password
 *     if (!$resets->request($email, $request->ip())) {
 *         throw HttpException::tooManyRequests(...);
 *     }
 *     // the same answer whether or not the address has an account
 *
 *     // POST /reset-password
 *     $identity = $resets->reset($token, new Secret($password));   // null: invalid, expired or used
 *
 * The application declares the two routes and their pages, as it does its
 * login; the link in the email points at the route named
 * auth.passwords.route (password.reset) under APP_URL, with ?token=….
 *
 * **Nothing tells a visitor whether an address is registered.** request()
 * returns the same for every address; it is false only when the rate limit
 * is hit, and that limit counts addresses that do not exist too. The email is
 * queued, so its sending time is not in the response either -- unless the
 * queue is "sync", which sends it inline.
 *
 * **A link works once, for an hour.** The token is signed (APP_KEY) and bound
 * to the account's current password hash: setting the new password changes
 * the hash, so the link stops working, and so does every other outstanding
 * link -- as it does after any password change.
 */
final class PasswordReset
{
    public const PURPOSE = 'password-reset';

    public const TTL = 3600;

    /** Per address, and per client address: enough for a typo, not for a mail bomb. */
    public const LIMIT_PER_LOGIN = '3/15m';

    public const LIMIT_PER_IP = '10/15m';

    public const TEMPLATE = 'emails/password-reset';

    public function __construct(
        private readonly UserProvider $provider,
        private readonly Password $passwords,
        private readonly Signer $signer,
        private readonly Mailer $mailer,
        private readonly AppUrl $urls,
        private readonly ?RateLimiter $limiter = null,
        private readonly ?HookEngine $hooks = null,
        private readonly int $ttl = self::TTL,
        private readonly string $route = 'password.reset',
    ) {}

    /**
     * Mail a reset link to the account with this login, if there is one.
     *
     * @param ?string $ip the client's address, for the per-IP limit
     *
     * @return bool false only when rate limited -- never because there is no such account
     */
    public function request(string $login, ?string $ip = null): bool
    {
        $provider = $this->resettable();

        if ($this->limiter !== null) {
            $perLogin = $this->limiter->attempt('password-reset:login:' . \hash('sha256', \mb_strtolower(\trim($login))), self::LIMIT_PER_LOGIN);
            $perIp = $ip === null ? null : $this->limiter->attempt('password-reset:ip:' . $ip, self::LIMIT_PER_IP);

            if (!$perLogin->allowed || ($perIp !== null && !$perIp->allowed)) {
                return false;
            }
        }

        $account = $login === '' ? null : $this->provider->byLogin($login);
        $email = $account !== null && $account->active ? $provider->emailFor($account) : null;

        if ($account === null || $email === null || $email === '') {
            return true;
        }

        $this->mailer->queue(Message::create()
            ->to($email, $account->identity->name)
            ->subject('Reset your password')
            ->view(self::TEMPLATE, [
                'link' => $this->urls->route($this->route, ['token' => $this->token($account)]),
                'name' => $account->identity->name,
                'minutes' => \intdiv($this->ttl, 60),
            ]));

        $this->hooks?->do('auth.password_reset_requested', $account->identity);

        return true;
    }

    /** A reset token for this account, for a flow that sends it some other way. */
    public function token(Account $account): string
    {
        return AccountToken::issue($this->signer, self::PURPOSE, [
            'sub' => $account->identity->id,
            'exp' => \time() + $this->ttl,
            'pw' => AccountToken::fingerprint($account->passwordHash ?? ''),
        ]);
    }

    /**
     * The account a token is for, while it is still good: signed here, not
     * expired, and the password unchanged since. For showing the form.
     */
    public function check(string $token): ?Account
    {
        $claims = AccountToken::read($this->signer, self::PURPOSE, $token);

        if ($claims === null || !\is_string($claims['sub'] ?? null) || !\is_string($claims['pw'] ?? null)) {
            return null;
        }

        $account = $this->provider->byId($claims['sub']);

        if ($account === null || !$account->active || !Signer::matches(AccountToken::fingerprint($account->passwordHash ?? ''), $claims['pw'])) {
            return null;
        }

        return $account;
    }

    /**
     * Set a new password. Does not log in: whether a reset also signs the
     * visitor in is the application's decision (AuthManager::login()).
     *
     * @return ?Identity whose password changed, or null when the token is not good
     */
    public function reset(string $token, Secret $password): ?Identity
    {
        $provider = $this->resettable();
        $account = $this->check($token);

        if ($account === null) {
            return null;
        }

        $provider->resetPassword($account, $this->passwords->hash($password));
        $this->hooks?->do('auth.password_reset', $account->identity);

        return $account->identity;
    }

    private function resettable(): PasswordResettable
    {
        return $this->provider instanceof PasswordResettable
            ? $this->provider
            : throw AuthException::providerLacks(PasswordResettable::class, $this->provider::class, 'Password reset');
    }
}
