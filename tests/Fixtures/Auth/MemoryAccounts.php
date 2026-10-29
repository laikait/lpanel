<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\EmailVerifiable;
use App\Engine\Auth\Identity;
use App\Engine\Auth\PasswordResettable;
use App\Engine\Auth\UserProvider;

/** Accounts in an array, with the optional reset and verification interfaces. */
final class MemoryAccounts implements UserProvider, PasswordResettable, EmailVerifiable
{
    /** @var array<string, array{email: string, hash: ?string, active: bool, verified: bool, name: string}> */
    public array $accounts = [];

    public function add(string $id, string $email, ?string $hash, bool $active = true, string $name = ''): void
    {
        $this->accounts[$id] = ['email' => $email, 'hash' => $hash, 'active' => $active, 'verified' => false, 'name' => $name];
    }

    public function hashOf(string $id): ?string
    {
        return $this->accounts[$id]['hash'] ?? null;
    }

    public function isVerified(string $id): bool
    {
        return $this->accounts[$id]['verified'] ?? false;
    }

    public function change(string $id, ?string $email = null, ?string $hash = null): void
    {
        if (!isset($this->accounts[$id])) {
            return;
        }

        if ($email !== null) {
            $this->accounts[$id]['email'] = $email;
        }

        if ($hash !== null) {
            $this->accounts[$id]['hash'] = $hash;
        }
    }

    public function describe(): string
    {
        return 'memory';
    }

    public function byId(string $id): ?Account
    {
        $row = $this->accounts[$id] ?? null;

        return $row === null ? null : new Account(new Identity($id, $row['name']), $row['hash'], $row['active']);
    }

    public function byLogin(string $login): ?Account
    {
        foreach ($this->accounts as $id => $row) {
            if (\strcasecmp($row['email'], $login) === 0) {
                return $this->byId((string) $id);
            }
        }

        return null;
    }

    public function emailFor(Account $account): ?string
    {
        return $this->accounts[$account->identity->id]['email'] ?? null;
    }

    public function resetPassword(Account $account, string $passwordHash): void
    {
        $this->change($account->identity->id, hash: $passwordHash);
    }

    public function isEmailVerified(Account $account): bool
    {
        return $this->accounts[$account->identity->id]['verified'] ?? false;
    }

    public function markEmailVerified(Account $account): void
    {
        if (isset($this->accounts[$account->identity->id])) {
            $this->accounts[$account->identity->id]['verified'] = true;
        }
    }
}
