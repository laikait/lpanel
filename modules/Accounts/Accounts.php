<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;

/**
 * The accounts table: what the panel knows about each hosting account.
 *
 * Logins with accounts.all see every account; a reseller sees only the
 * accounts they own. Anything another login may not see is "not found".
 */
final class Accounts
{
    public const CREATING = 'creating';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const TERMINATING = 'terminating';
    public const FAILED = 'failed';

    public const STATUSES = [self::CREATING, self::ACTIVE, self::SUSPENDED, self::TERMINATING, self::FAILED];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Authorizer $authorizer,
    ) {}

    /** null when this login sees every account, else the owner id it is limited to. */
    public function scopeOf(Identity $identity): ?int
    {
        return $this->authorizer->allows($identity, 'accounts.all') ? null : (int) $identity->id;
    }

    /**
     * The accounts a login may see, newest first, optionally filtered.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(Identity $identity, string $search = '', string $status = ''): array
    {
        $query = $this->table()->orderBy('id', 'desc');
        $scope = $this->scopeOf($identity);

        if ($scope !== null) {
            $query = $query->where('owner_id', $scope);
        }

        if (\in_array($status, self::STATUSES, true)) {
            $query = $query->where('status', $status);
        }

        $rows = \array_values($query->get());
        $search = \mb_strtolower(\trim($search));

        if ($search === '') {
            return $rows;
        }

        return \array_values(\array_filter($rows, static fn(array $row): bool => \str_contains((string) $row['username'], $search) || \str_contains((string) $row['domain'], $search)));
    }

    /** @return array<string, mixed>|null an account this login may see */
    public function find(Identity $identity, string|int $id): ?array
    {
        $row = $this->byId($id);

        return $row !== null && $this->canSee($identity, $row) ? $row : null;
    }

    /** @return array<string, mixed>|null an account this login may see, by username */
    public function findByUsername(Identity $identity, string $username): ?array
    {
        $row = $this->table()->where('username', \strtolower($username))->first();

        return $row !== null && $this->canSee($identity, $row) ? $row : null;
    }

    /** @return array<string, mixed>|null for the system's own use, not for a login */
    public function findByUsernameUnscoped(string $username): ?array
    {
        return $username === '' ? null : $this->table()->where('username', \strtolower($username))->first();
    }

    /** @param array<string, mixed> $row */
    public function canSee(Identity $identity, array $row): bool
    {
        $scope = $this->scopeOf($identity);

        return $scope === null || ($row['owner_id'] !== null && (int) $row['owner_id'] === $scope);
    }

    /** @return array<string, mixed>|null */
    public function byId(string|int $id): ?array
    {
        return \is_int($id) || \ctype_digit($id) ? $this->table()->where('id', (int) $id)->first() : null;
    }

    /** @return array<string, mixed>|null the account a login owns */
    public function byLogin(string|int $loginId): ?array
    {
        return $this->table()->where('login_id', (int) $loginId)->first();
    }

    public function usernameTaken(string $username): bool
    {
        return $this->table()->where('username', $username)->first() !== null;
    }

    public function domainTaken(string $domain): bool
    {
        return $this->table()->where('domain', $domain)->first() !== null;
    }

    /** @return list<array<string, mixed>> accounts with an agent operation in flight */
    public function pending(): array
    {
        return \array_values($this->table()->where('op_id', '!=', '')->orderBy('id')->get());
    }

    /** @return list<array<string, mixed>> */
    public function onPackage(int $packageId): array
    {
        return \array_values($this->table()->where('package_id', $packageId)->orderBy('id')->get());
    }

    /** @return list<array<string, mixed>> a reseller's accounts, whatever their status */
    public function ownedBy(int $ownerId): array
    {
        return \array_values($this->table()->where('owner_id', $ownerId)->orderBy('id')->get());
    }

    public function countOnPackage(int $packageId): int
    {
        return $this->table()->where('package_id', $packageId)->count();
    }

    /** @return array<string, int> status => count, for this login's accounts */
    public function counts(Identity $identity): array
    {
        $counts = \array_fill_keys(self::STATUSES, 0);

        foreach ($this->visibleTo($identity) as $row) {
            $counts[(string) $row['status']] = ($counts[(string) $row['status']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return int the new account's id
     */
    public function insert(array $values): int
    {
        return (int) $this->table()->insert($values + ['created_at' => self::now()], 'id');
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $this->table()->where('id', $id)->update($changes + ['updated_at' => self::now()]);
    }

    public function delete(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    public static function now(): string
    {
        return \gmdate('Y-m-d H:i:s');
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('accounts');
    }
}
