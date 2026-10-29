<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Auth\UserProvider;

/** Accounts with optional two-factor login, in arrays. */
final class TwoFactorMemoryAccounts implements UserProvider, TwoFactorAccounts
{
    /** @var array<string, array{email: string, hash: string, secret: ?string, step: ?int, codes: list<string>}> */
    private array $accounts = [];

    public function add(string $id, string $email, string $hash, ?string $secret = null): void
    {
        $this->accounts[$id] = ['email' => $email, 'hash' => $hash, 'secret' => $secret, 'step' => null, 'codes' => []];
    }

    public function secretOf(string $id): ?string
    {
        return $this->accounts[$id]['secret'] ?? null;
    }

    public function describe(): string
    {
        return 'memory, with two-factor';
    }

    public function byId(string $id): ?Account
    {
        $row = $this->accounts[$id] ?? null;

        return $row === null ? null : new Account(new Identity($id, $row['email']), $row['hash']);
    }

    public function byLogin(string $login): ?Account
    {
        foreach ($this->accounts as $id => $row) {
            if ($row['email'] === $login) {
                return $this->byId((string) $id);
            }
        }

        return null;
    }

    public function twoFactorSecret(Account $account): ?string
    {
        return $this->accounts[$account->identity->id]['secret'] ?? null;
    }

    public function lastTwoFactorStep(Account $account): ?int
    {
        return $this->accounts[$account->identity->id]['step'] ?? null;
    }

    public function recordTwoFactorStep(Account $account, int $step): void
    {
        $this->update($account, ['step' => $step]);
    }

    /**
     * As if the step had never been recorded: what a second request sent at
     * the same moment as the first sees, having read before the first wrote.
     */
    public function forgetStep(string $id): void
    {
        $this->accounts[$id]['step'] = null;
    }

    public function recoveryCodes(Account $account): array
    {
        return $this->accounts[$account->identity->id]['codes'] ?? [];
    }

    public function replaceRecoveryCodes(Account $account, array $hashes): void
    {
        $this->update($account, ['codes' => $hashes]);
    }

    public function enableTwoFactor(Account $account, string $secret, array $recoveryHashes): void
    {
        $this->update($account, ['secret' => $secret, 'codes' => $recoveryHashes]);
    }

    public function disableTwoFactor(Account $account): void
    {
        $this->update($account, ['secret' => null, 'codes' => [], 'step' => null]);
    }

    /** @param array{secret?: ?string, step?: ?int, codes?: list<string>} $changes */
    private function update(Account $account, array $changes): void
    {
        $id = $account->identity->id;

        if (isset($this->accounts[$id])) {
            $this->accounts[$id] = \array_replace($this->accounts[$id], $changes);
        }
    }
}
