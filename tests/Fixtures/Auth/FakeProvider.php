<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TokenProvider;

/**
 * A user store held in an array, for tests that are about everything except
 * where users come from.
 *
 * It implements TokenProvider so one fixture covers both authenticators, and
 * it counts its lookups -- several tests are about how often the provider is
 * asked, which is the difference between a framework that is lazy and one that
 * says it is.
 */
final class FakeProvider implements TokenProvider
{
    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<string, string> fingerprint => account id */
    private array $tokens = [];

    public int $lookups = 0;

    /**
     * @param list<string>         $roles
     * @param array<string, mixed> $attributes
     */
    public function add(
        string $id,
        string $name,
        ?string $passwordHash = null,
        array $roles = [],
        bool $active = true,
        array $attributes = [],
    ): self {
        $this->accounts[$id] = new Account(
            new Identity($id, $name, $roles, $attributes),
            $passwordHash,
            $active,
        );

        return $this;
    }

    public function addToken(string $id, string $token): self
    {
        $this->tokens[TokenAuthenticator::fingerprint($token)] = $id;

        return $this;
    }

    public function describe(): string
    {
        return 'fixture accounts';
    }

    public function byId(string $id): ?Account
    {
        ++$this->lookups;

        return $this->accounts[$id] ?? null;
    }

    public function byLogin(string $login): ?Account
    {
        ++$this->lookups;

        foreach ($this->accounts as $account) {
            if ($account->identity->name === $login) {
                return $account;
            }
        }

        return null;
    }

    public function byToken(string $token): ?Account
    {
        ++$this->lookups;

        $id = $this->tokens[TokenAuthenticator::fingerprint($token)] ?? null;

        return $id === null ? null : ($this->accounts[$id] ?? null);
    }
}
