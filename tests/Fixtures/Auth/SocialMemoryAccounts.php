<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Social\SocialAccounts;
use App\Engine\Auth\Social\SocialUser;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Auth\UserProvider;

/** Accounts and their links to outside providers, in arrays; two-factor when a secret is set. */
final class SocialMemoryAccounts implements UserProvider, SocialAccounts, TwoFactorAccounts
{
    /** @var array<string, array{email: string, active: bool}> */
    private array $accounts = [];

    /** @var array<string, string> "provider:id" => account id */
    public array $links = [];

    /** @var array<string, string> account id => TOTP secret, for those with two-factor on */
    public array $secrets = [];

    /** @var array<string, int> */
    private array $steps = [];

    public function add(string $id, string $email, bool $active = true): void
    {
        $this->accounts[$id] = ['email' => $email, 'active' => $active];
    }

    public function count(): int
    {
        return \count($this->accounts);
    }

    public function describe(): string
    {
        return 'memory, with social links';
    }

    public function byId(string $id): ?Account
    {
        $row = $this->accounts[$id] ?? null;

        return $row === null ? null : new Account(new Identity($id, $row['email']), null, $row['active']);
    }

    public function byLogin(string $login): ?Account
    {
        return $this->findByEmail($login);
    }

    public function findBySocial(string $provider, string $id): ?Account
    {
        $account = $this->links[$provider . ':' . $id] ?? null;

        return $account === null ? null : $this->byId($account);
    }

    public function findByEmail(string $email): ?Account
    {
        foreach ($this->accounts as $id => $row) {
            if (\strcasecmp($row['email'], $email) === 0) {
                return $this->byId((string) $id);
            }
        }

        return null;
    }

    public function linkSocial(Account $account, SocialUser $user): void
    {
        $this->links[$user->provider . ':' . $user->id] = $account->identity->id;
    }

    public function createFromSocial(SocialUser $user): Account
    {
        $id = 'new-' . (\count($this->accounts) + 1);
        $this->add($id, $user->email ?? '');
        $account = $this->byId($id);
        \assert($account !== null);
        $this->linkSocial($account, $user);

        return $account;
    }

    public function twoFactorSecret(Account $account): ?string
    {
        return $this->secrets[$account->identity->id] ?? null;
    }

    public function lastTwoFactorStep(Account $account): ?int
    {
        return $this->steps[$account->identity->id] ?? null;
    }

    public function recordTwoFactorStep(Account $account, int $step): void
    {
        $this->steps[$account->identity->id] = $step;
    }

    public function recoveryCodes(Account $account): array
    {
        return [];
    }

    public function replaceRecoveryCodes(Account $account, array $hashes): void {}

    public function enableTwoFactor(Account $account, string $secret, array $recoveryHashes): void
    {
        $this->secrets[$account->identity->id] = $secret;
    }

    public function disableTwoFactor(Account $account): void
    {
        unset($this->secrets[$account->identity->id]);
    }
}
