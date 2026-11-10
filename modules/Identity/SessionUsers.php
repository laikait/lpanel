<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Engine\Auth\Account;
use App\Engine\Auth\Authenticators\SessionAuthenticator;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TokenProvider;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Session\SessionManager;

/**
 * PanelUsers as the framework's user provider, with two things only the
 * session can know, applied when the framework looks up the login a session
 * names (once per request):
 *
 * - Session epochs. A session remembers the login's epoch from when it signed
 *   in. Once PanelUsers::signOutEverywhere() raises it (a new password,
 *   suspension, "sign out everywhere"), the session names nobody.
 * - "Log in as". While an administrator or reseller is signed in as someone
 *   else, the identity carries `impersonator` (the real person's username),
 *   which the audit log records as "on behalf of", and `impersonator_id`.
 *
 * Every other lookup (tokens, two-factor, the panel's own code) is PanelUsers
 * unchanged.
 */
final class SessionUsers implements TokenProvider, TwoFactorAccounts
{
    /** The epoch the session signed in with. */
    public const EPOCH = 'identity.epoch';

    /** {id, username} of the person really signed in, while logged in as someone else. */
    public const IMPERSONATOR = 'identity.impersonator';

    /** @param \Closure(): SessionManager $sessions */
    public function __construct(
        private readonly PanelUsers $users,
        private readonly \Closure $sessions,
    ) {}

    public function describe(): string
    {
        return $this->users->describe();
    }

    public function byId(string $id): ?Account
    {
        $account = $this->users->byId($id);
        $sessions = ($this->sessions)();

        if ($account === null || !$sessions->isResumable()) {
            return $account;
        }

        $session = $sessions->session();

        // Only the login this session is signed in as.
        if ($session->reserved(SessionAuthenticator::KEY) !== $id) {
            return $account;
        }

        if ((int) $session->get(self::EPOCH, 0) !== (int) $account->identity->attribute('epoch', 0)) {
            return null;
        }

        $by = $session->get(self::IMPERSONATOR);

        if (!\is_array($by) || !isset($by['id'], $by['username'])) {
            return $account;
        }

        $identity = $account->identity;

        return new Account(
            new Identity($identity->id, $identity->name, $identity->roles, $identity->attributes + [
                'impersonator' => (string) $by['username'],
                'impersonator_id' => (string) $by['id'],
            ]),
            $account->passwordHash,
            $account->active,
        );
    }

    public function byLogin(string $login): ?Account
    {
        return $this->users->byLogin($login);
    }

    public function byToken(string $token): ?Account
    {
        return $this->users->byToken($token);
    }

    public function twoFactorSecret(Account $account): ?string
    {
        return $this->users->twoFactorSecret($account);
    }

    public function lastTwoFactorStep(Account $account): ?int
    {
        return $this->users->lastTwoFactorStep($account);
    }

    public function recordTwoFactorStep(Account $account, int $step): void
    {
        $this->users->recordTwoFactorStep($account, $step);
    }

    public function recoveryCodes(Account $account): array
    {
        return $this->users->recoveryCodes($account);
    }

    public function replaceRecoveryCodes(Account $account, array $hashes): void
    {
        $this->users->replaceRecoveryCodes($account, $hashes);
    }

    public function enableTwoFactor(Account $account, string $secret, array $recoveryHashes): void
    {
        $this->users->enableTwoFactor($account, $secret, $recoveryHashes);
    }

    public function disableTwoFactor(Account $account): void
    {
        $this->users->disableTwoFactor($account);
    }
}
