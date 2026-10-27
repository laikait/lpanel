<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\Authenticators\SessionAuthenticator;
use App\Engine\Auth\Identity;
use App\Engine\Auth\PasswordResettable;
use App\Engine\Auth\TokenProvider;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Http\Request;
use App\Engine\Session\SessionManager;
use App\Modules\Identity\Tokens\TokenStore;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;

/**
 * LPanel's accounts, as the framework sees them.
 *
 * Root is an Account with no password hash, so AuthManager::attempt() can
 * never log it in: the only way in as root is RootLogin, through
 * lpanel-authd (design/adr/0010-root-login-via-authd.md). For the same
 * reason root has no reset link and no API token: either would be a way in
 * that skips root's IP allowlist and lockout.
 *
 * A session remembers the user's session_epoch when it starts. When the
 * number has moved on (a new password, "sign out everywhere") the session
 * names nobody, so it ends on its next request.
 */
final class PanelUserProvider implements TokenProvider, TwoFactorAccounts, PasswordResettable
{
    /** Session key holding the epoch the session started under. */
    public const EPOCH = 'identity.epoch';

    private ?string $ip = null;

    public function __construct(
        private readonly UserStore $users,
        private readonly TokenStore $tokens,
        private readonly SessionManager $sessions,
    ) {}

    public function describe(): string
    {
        return 'LPanel users (users table) and API tokens; root through lpanel-authd';
    }

    /** request.received: the client's address, for a token's last_used_ip. */
    public function onRequest(Request $request): void
    {
        $this->ip = $request->ip();
    }

    public function byId(string $id): ?Account
    {
        $user = $this->users->byUid($id);

        if ($user === null || $this->staleSession($user)) {
            return null;
        }

        return self::account($user);
    }

    public function byLogin(string $login): ?Account
    {
        $user = $this->users->byLogin($login);

        return $user === null ? null : self::account($user);
    }

    public function byToken(string $token): ?Account
    {
        $found = $this->tokens->find($token);
        $user = $found === null ? null : $this->users->byId($found->userId);

        if ($found === null || $user === null || $user->kind !== User::KIND_PANEL) {
            return null;
        }

        $this->tokens->touch($found, $this->ip);

        return self::account($user, $found->uid);
    }

    /** auth.login: remember which epoch this session belongs to. */
    public function rememberEpoch(Identity $identity): void
    {
        $user = $this->users->byUid($identity->id);

        if ($user !== null && $identity->attribute('token') === null) {
            $this->sessions->session()->set(self::EPOCH, $user->sessionEpoch);
        }
    }

    /** @param ?string $token the API token's id, when the account is acting through one */
    public static function account(User $user, ?string $token = null): Account
    {
        $attributes = ['login' => $user->login, 'kind' => $user->kind];

        if ($token !== null) {
            $attributes['token'] = $token;
        }

        return new Account(
            new Identity($user->uid, $user->name, $user->effectiveRoles(), $attributes),
            $user->kind === User::KIND_SYSTEM ? null : $user->passwordHash,
            $user->isActive(),
        );
    }

    public function emailFor(Account $account): ?string
    {
        $user = $this->users->byUid($account->identity->id);

        return $user === null || $user->kind !== User::KIND_PANEL ? null : $user->email;
    }

    public function resetPassword(Account $account, string $passwordHash): void
    {
        $user = $this->user($account);

        if ($user->kind !== User::KIND_PANEL) {
            throw new \LogicException('Root\'s password is the server\'s; the panel never sets it.');
        }

        $this->users->setPasswordHash($user->uid, $passwordHash);
    }

    /** Is this the signed-in session's own user, from before their epoch moved on? */
    private function staleSession(User $user): bool
    {
        if (!$this->sessions->isResumable()) {
            return false;
        }

        $session = $this->sessions->session();

        if ($session->reserved(SessionAuthenticator::KEY) !== $user->uid) {
            return false;
        }

        return $session->get(self::EPOCH) !== $user->sessionEpoch;
    }

    private function user(Account $account): User
    {
        return $this->users->byUid($account->identity->id)
            ?? throw new \RuntimeException('Two-factor data was asked for an account that no longer exists.');
    }

    public function twoFactorSecret(Account $account): ?string
    {
        return $this->users->totpSecret($this->user($account));
    }

    public function lastTwoFactorStep(Account $account): ?int
    {
        return $this->user($account)->totpLastStep;
    }

    public function recordTwoFactorStep(Account $account, int $step): void
    {
        $this->users->recordTwoFactorStep($account->identity->id, $step);
    }

    public function recoveryCodes(Account $account): array
    {
        return $this->user($account)->recoveryHashes;
    }

    public function replaceRecoveryCodes(Account $account, array $hashes): void
    {
        $this->users->replaceRecoveryCodes($account->identity->id, \array_values($hashes));
    }

    public function enableTwoFactor(Account $account, string $secret, array $recoveryHashes): void
    {
        $this->users->enableTwoFactor($account->identity->id, $secret, \array_values($recoveryHashes));
    }

    public function disableTwoFactor(Account $account): void
    {
        $this->users->disableTwoFactor($account->identity->id);
    }
}
