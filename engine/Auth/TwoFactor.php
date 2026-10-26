<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Hook\HookEngine;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\Secret;
use App\Engine\Session\SessionManager;
use App\Engine\Support\QrCode;

/**
 * Two-factor login with an authenticator app (TOTP), and recovery codes.
 *
 *     // POST /login -- instead of AuthManager::attempt()
 *     match ($twoFactor->attempt($email, new Secret($password))) {
 *         TwoFactorResult::LoggedIn => …,             // no second factor on this account
 *         TwoFactorResult::ChallengeRequired => …,    // show the code form
 *         TwoFactorResult::Failed => …,               // wrong details; say no more
 *     };
 *
 *     // POST /login/code
 *     $identity = $twoFactor->challenge($code);      // a TOTP code or a recovery code; null if wrong
 *
 * **The password alone logs nobody in** on an account with a secret: the
 * account id waits in the session for five minutes and five tries, and only a
 * right code calls AuthManager::login() -- which is when the session id
 * changes. After five wrong codes the password has to be typed again.
 *
 * **Codes are limited per account too**, not only per session: the session's
 * five tries are reset by typing the password again, or sidestepped by opening
 * several sessions at once, so with a RateLimiter an account takes ten codes in
 * fifteen minutes however many sessions they arrive through. Somebody who has
 * the password can lock the owner out of the code form for that long, which is
 * the trade: they cannot try a million codes instead.
 *
 * **Every way in that is not the password goes through start()** -- a social
 * sign-in, a magic link -- so that a second factor turned on for an account is
 * asked for whichever first factor was used.
 *
 * **Switching it on** is two steps, so a secret nobody scanned never locks
 * anybody out: begin() makes a secret and its QR code, kept in the session;
 * confirm() with a code from the app stores it and hands back ten recovery
 * codes, once, in plain text. Only their hashes are kept. Each works once.
 *
 * The provider implements TwoFactorAccounts; the pages are the application's.
 */
final class TwoFactor
{
    /** Session keys, reserved: a form field cannot reach them. */
    public const PENDING = 'two_factor.pending';

    public const ENROLMENT = 'two_factor.enrolment';

    /** How long the code form waits after the password. */
    public const TTL = 300;

    public const MAX_TRIES = 5;

    public const RECOVERY_CODES = 10;

    /** Codes one account may try, across every session, before it waits. */
    public const LIMIT_PER_ACCOUNT = '10/15m';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly UserProvider $provider,
        private readonly Password $passwords,
        private readonly SessionManager $sessions,
        private readonly string $issuer = 'LPHP',
        private readonly ?HookEngine $hooks = null,
        private readonly ?\Closure $clock = null,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    /** Check the password; log in, or wait for the code. */
    public function attempt(string $login, Secret $password): TwoFactorResult
    {
        $account = $this->auth->verify($login, $password);

        if ($account === null) {
            return TwoFactorResult::Failed;
        }

        return $this->start($account);
    }

    /**
     * An account whose first factor has already been checked: log it in, or,
     * when it has two-factor turned on, wait for the code.
     */
    public function start(Account $account): TwoFactorResult
    {
        if (!$account->active) {
            return TwoFactorResult::Failed;
        }

        if (!$this->isEnabled($account)) {
            $this->auth->login($account->identity);

            return TwoFactorResult::LoggedIn;
        }

        $this->sessions->session()->setReserved(self::PENDING, [
            'id' => $account->identity->id,
            'until' => $this->now() + self::TTL,
            'tries' => 0,
        ]);

        return TwoFactorResult::ChallengeRequired;
    }

    /** Whether a password was accepted and the code is awaited. */
    public function pending(): bool
    {
        return $this->pendingAccount() !== null;
    }

    /**
     * Finish the login with a code from the app, or a recovery code.
     *
     * @return ?Identity logged in, or null for a wrong code -- or no login waiting
     */
    public function challenge(string $code): ?Identity
    {
        $accounts = $this->accounts();
        $account = $this->pendingAccount();

        if ($account === null) {
            return null;
        }

        // Counted before the code is looked at, success included, so that
        // requests sent at once cannot all slip in under the limit.
        $throttle = 'two-factor:' . \hash('sha256', $account->identity->id);

        if ($this->limiter !== null && !$this->limiter->attempt($throttle, self::LIMIT_PER_ACCOUNT)->allowed) {
            $this->countFailure();
            $this->hooks?->do('auth.two_factor_throttled', $account->identity);

            return null;
        }

        $secret = $accounts->twoFactorSecret($account);
        $step = $secret === null ? null : Totp::verify($secret, $code, $accounts->lastTwoFactorStep($account), $this->now());

        // lastTwoFactorStep() is read above and written below, with nothing
        // in between to stop two requests carrying the same code from both
        // reading the old value -- one code, two logins. The claim is the
        // counter store's atomic increment, which every process shares: the
        // second request to claim a step loses, whatever the provider does.
        if ($step !== null && !$this->claim('step:' . $step, $account)) {
            $step = null;
        }

        $recovered = $step === null
            && self::looksLikeRecoveryCode($code)
            && $this->claim('recovery:' . \hash('sha256', self::normaliseRecovery($code)), $account)
            && $this->useRecoveryCode($account, $code);

        if ($step === null && !$recovered) {
            $this->countFailure();
            $this->hooks?->do('auth.two_factor_failed', $account->identity);

            return null;
        }

        if ($step !== null) {
            $accounts->recordTwoFactorStep($account, $step);
        }

        $this->limiter?->clear($throttle);
        $this->sessions->session()->forgetReserved(self::PENDING);
        $this->auth->login($account->identity);

        if ($recovered) {
            $this->hooks?->do('auth.recovery_code_used', $account->identity, \count($accounts->recoveryCodes($account)));
        }

        return $account->identity;
    }

    public function isEnabled(Account $account): bool
    {
        return $this->provider instanceof TwoFactorAccounts && $this->provider->twoFactorSecret($account) !== null;
    }

    /** Start switching it on: a new secret, its URI and QR code, kept in the session until confirm(). */
    public function begin(Account $account, ?string $label = null): TwoFactorEnrolment
    {
        $this->accounts();
        $secret = Totp::secret();
        $this->sessions->session()->setReserved(self::ENROLMENT, ['id' => $account->identity->id, 'secret' => $secret]);
        $uri = Totp::uri($secret, $label ?? ($account->identity->name !== '' ? $account->identity->name : $account->identity->id), $this->issuer);

        return new TwoFactorEnrolment($secret, $uri, QrCode::svg($uri));
    }

    /**
     * Finish switching it on with a code the app shows.
     *
     * @return ?list<string> the recovery codes, to show once; null for a wrong code
     */
    public function confirm(Account $account, string $code): ?array
    {
        $accounts = $this->accounts();
        $session = $this->sessions->session();
        $enrolment = $session->reserved(self::ENROLMENT);

        if (!\is_array($enrolment) || ($enrolment['id'] ?? null) !== $account->identity->id || !\is_string($enrolment['secret'] ?? null)) {
            return null;
        }

        $step = Totp::verify($enrolment['secret'], $code, null, $this->now());

        if ($step === null) {
            return null;
        }

        [$codes, $hashes] = $this->makeRecoveryCodes();
        $accounts->enableTwoFactor($account, $enrolment['secret'], $hashes);
        $accounts->recordTwoFactorStep($account, $step);
        $session->forgetReserved(self::ENROLMENT);
        $this->hooks?->do('auth.two_factor_enabled', $account->identity);

        return $codes;
    }

    /** @return list<string> a fresh set, replacing the old; show them once */
    public function regenerateRecoveryCodes(Account $account): array
    {
        [$codes, $hashes] = $this->makeRecoveryCodes();
        $this->accounts()->replaceRecoveryCodes($account, $hashes);

        return $codes;
    }

    /** Ask for the password again before calling this: it lowers the account's protection. */
    public function disable(Account $account): void
    {
        $this->accounts()->disableTwoFactor($account);
        $this->hooks?->do('auth.two_factor_disabled', $account->identity);
    }

    private function pendingAccount(): ?Account
    {
        // Without a session there is nothing waiting, and asking would start one.
        if (!$this->sessions->isResumable()) {
            return null;
        }

        $pending = $this->sessions->session()->reserved(self::PENDING);

        if (!\is_array($pending) || !\is_string($pending['id'] ?? null) || !\is_int($pending['until'] ?? null) || $pending['until'] < $this->now()) {
            return null;
        }

        $account = $this->provider->byId($pending['id']);

        return $account !== null && $account->active ? $account : null;
    }

    private function countFailure(): void
    {
        $session = $this->sessions->session();
        $pending = $session->reserved(self::PENDING);

        if (!\is_array($pending)) {
            return;
        }

        $tries = (\is_int($pending['tries'] ?? null) ? $pending['tries'] : 0) + 1;

        if ($tries >= self::MAX_TRIES) {
            $session->forgetReserved(self::PENDING);

            return;
        }

        $pending['tries'] = $tries;
        $session->setReserved(self::PENDING, $pending);
    }

    /** A recovery code, used up if it is one of the account's. */
    /**
     * Take a one-time code for this account, once, across every process.
     *
     * The rate limiter's store is the one place an increment is atomic between
     * requests -- file-locked by default, and security:check fails on one that
     * is not shared. The first request to count a key gets 1 and wins; any
     * other gets 2 and loses. Three minutes outlives the TOTP window (one step
     * either side of now) and the time a recovery code takes to be removed.
     * Without a limiter there is no claim to make, and the provider's own
     * bookkeeping is all there is.
     */
    private function claim(string $what, Account $account): bool
    {
        if ($this->limiter === null) {
            return true;
        }

        return $this->limiter->attempt('two-factor-used:' . \hash('sha256', $account->identity->id) . ':' . $what, '1/3m')->allowed;
    }

    private static function normaliseRecovery(string $code): string
    {
        return \strtolower(\trim($code));
    }

    private static function looksLikeRecoveryCode(string $code): bool
    {
        return \preg_match('/^[a-z2-7]{5}-[a-z2-7]{5}$/D', self::normaliseRecovery($code)) === 1;
    }

    private function useRecoveryCode(Account $account, string $code): bool
    {
        $code = self::normaliseRecovery($code);

        if (!self::looksLikeRecoveryCode($code)) {
            return false;
        }

        $accounts = $this->accounts();
        $hashes = $accounts->recoveryCodes($account);

        foreach ($hashes as $index => $hash) {
            if ($this->passwords->verify(new Secret($code), $hash)) {
                unset($hashes[$index]);
                $accounts->replaceRecoveryCodes($account, \array_values($hashes));

                return true;
            }
        }

        return false;
    }

    /** @return array{list<string>, list<string>} the codes, and their hashes */
    private function makeRecoveryCodes(): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; ++$i) {
            $raw = \strtolower(Totp::base32Encode(\random_bytes(7)));
            $code = \substr($raw, 0, 5) . '-' . \substr($raw, 5, 5);
            $codes[] = $code;
            $hashes[] = $this->passwords->hash(new Secret($code));
        }

        return [$codes, $hashes];
    }

    private function accounts(): TwoFactorAccounts
    {
        return $this->provider instanceof TwoFactorAccounts
            ? $this->provider
            : throw AuthException::providerLacks(TwoFactorAccounts::class, $this->provider::class, 'Two-factor login');
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : \time();
    }
}
