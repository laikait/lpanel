<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Auth\UserProvider;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;

/**
 * LPanel's accounts, as the framework sees them.
 *
 * Root is an Account with no password hash, so AuthManager::attempt() can
 * never log it in: the only way in as root is RootLogin, through
 * lpanel-authd (design/adr/0010-root-login-via-authd.md).
 */
final class PanelUserProvider implements UserProvider, TwoFactorAccounts
{
    public function __construct(private readonly UserStore $users) {}

    public function describe(): string
    {
        return 'LPanel users (users table); root through lpanel-authd';
    }

    public function byId(string $id): ?Account
    {
        $user = $this->users->byUid($id);

        return $user === null ? null : self::account($user);
    }

    public function byLogin(string $login): ?Account
    {
        $user = $this->users->byLogin($login);

        return $user === null ? null : self::account($user);
    }

    public static function account(User $user): Account
    {
        return new Account(
            new Identity($user->uid, $user->name, $user->effectiveRoles(), ['login' => $user->login, 'kind' => $user->kind]),
            $user->kind === User::KIND_SYSTEM ? null : $user->passwordHash,
            $user->status === User::ACTIVE,
        );
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
