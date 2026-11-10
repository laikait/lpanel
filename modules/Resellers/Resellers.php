<?php

declare(strict_types=1);

namespace App\Modules\Resellers;

use App\Engine\Auth\Capability;
use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Security\Secret;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * Resellers: their allowance (how many accounts, how much disk and bandwidth,
 * overselling or not) and their ACL (which actions they may take).
 *
 * Both are enforced from here, through filters other modules ask:
 *   authorization.decision  the ACL: a reseller lacking an action is refused it
 *   accounts.limits         a new account, or a package change, must fit
 *   packages.validate       a reseller's package may not outgrow the allowance
 *
 * A reseller without a row has no limits and every action.
 */
final class Resellers
{
    /** What a reseller may be allowed to do; each is a capability of its role. */
    public const ACL = ['accounts.create', 'accounts.modify', 'accounts.suspend', 'accounts.terminate', 'accounts.login_as', 'packages.manage'];

    /** @var array<int, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly PanelUsers $users,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
    ) {}

    /** @return list<array<string, mixed>> every reseller login, with limits and usage */
    public function all(): array
    {
        return \array_map($this->describe(...), $this->users->ofKinds(Kind::Reseller));
    }

    /** @return array<string, mixed>|null one reseller, with limits and usage */
    public function find(string|int $id): ?array
    {
        $row = $this->users->row((string) $id);

        return $row !== null && $row['kind'] === Kind::Reseller->value ? $this->describe($row) : null;
    }

    /** @return array<string, mixed>|null by username */
    public function findByUsername(string $username): ?array
    {
        $row = $this->users->find($username);

        return $row !== null && $row['kind'] === Kind::Reseller->value ? $this->describe($row) : null;
    }

    /**
     * The limits of a reseller: the stored row, or "unlimited, everything".
     *
     * @return array{max_accounts: int, disk_mb: int, bandwidth_mb: int, oversell: bool, acl: list<string>}
     */
    public function limits(int $userId): array
    {
        if (!\array_key_exists($userId, $this->cache)) {
            $this->cache[$userId] = $this->table()->where('user_id', $userId)->first();
        }

        $row = $this->cache[$userId];

        if ($row === null) {
            return ['max_accounts' => 0, 'disk_mb' => 0, 'bandwidth_mb' => 0, 'oversell' => false, 'acl' => self::ACL];
        }

        $acl = \json_decode((string) $row['acl'], true);

        return [
            'max_accounts' => (int) $row['max_accounts'],
            'disk_mb' => (int) $row['disk_mb'],
            'bandwidth_mb' => (int) $row['bandwidth_mb'],
            'oversell' => (bool) $row['oversell'],
            'acl' => \is_array($acl) ? \array_values(\array_intersect(self::ACL, $acl)) : [],
        ];
    }

    /**
     * What a reseller's accounts use of the allowance: packages' disk and
     * bandwidth added up, and how many accounts are on unlimited packages.
     *
     * @return array{accounts: int, disk_mb: int, bandwidth_mb: int, unlimited_disk: int, unlimited_bandwidth: int}
     */
    public function usage(int $userId, ?int $exceptAccountId = null): array
    {
        $usage = ['accounts' => 0, 'disk_mb' => 0, 'bandwidth_mb' => 0, 'unlimited_disk' => 0, 'unlimited_bandwidth' => 0];
        $packages = [];

        foreach ($this->accounts->ownedBy($userId) as $account) {
            if ($exceptAccountId !== null && (int) $account['id'] === $exceptAccountId) {
                continue;
            }

            $id = (int) $account['package_id'];
            $package = $packages[$id] ??= $this->packages->byId($id);
            ++$usage['accounts'];

            foreach (['disk_mb' => 'unlimited_disk', 'bandwidth_mb' => 'unlimited_bandwidth'] as $limit => $unlimited) {
                $value = (int) ($package[$limit] ?? 0);
                $value > 0 ? $usage[$limit] += $value : ++$usage[$unlimited];
            }
        }

        return $usage;
    }

    /**
     * Check a reseller form or API body.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validateLimits(array $input): array
    {
        $clean = [];
        $errors = [];

        foreach (['max_accounts', 'disk_mb', 'bandwidth_mb'] as $field) {
            $value = $input[$field] ?? 0;
            $value = \is_string($value) ? \trim($value) : $value;
            $value = $value === '' ? 0 : $value;

            if ((!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) || (int) $value > Packages::MAX_LIMIT) {
                $errors[$field] = 'Resellers.error_limit';
                $value = 0;
            }

            $clean[$field] = (int) $value;
        }

        $oversell = $input['oversell'] ?? false;
        $clean['oversell'] = $oversell === true || $oversell === '1' || $oversell === 1 || $oversell === 'on';
        $acl = $input['acl'] ?? self::ACL;
        $clean['acl'] = \is_array($acl) ? \array_values(\array_intersect(self::ACL, \array_filter($acl, 'is_string'))) : self::ACL;

        return [$clean, $errors];
    }

    /** @param array<string, mixed> $clean from validateLimits() */
    public function saveLimits(int $userId, array $clean): void
    {
        $values = [
            'max_accounts' => $clean['max_accounts'],
            'disk_mb' => $clean['disk_mb'],
            'bandwidth_mb' => $clean['bandwidth_mb'],
            'oversell' => $clean['oversell'],
            'acl' => \json_encode(\array_values($clean['acl']), \JSON_THROW_ON_ERROR),
        ];
        $now = \gmdate('Y-m-d H:i:s');

        if ($this->table()->where('user_id', $userId)->first() === null) {
            $this->table()->insert($values + ['user_id' => $userId, 'created_at' => $now]);
        } else {
            $this->table()->where('user_id', $userId)->update($values + ['updated_at' => $now]);
        }

        unset($this->cache[$userId]);
    }

    /**
     * A new reseller login with its limits.
     *
     * @param array<string, mixed> $limits from validateLimits()
     */
    public function create(string $username, string $name, ?string $email, Secret $password, array $limits): string
    {
        $id = $this->users->create(Kind::Reseller, $username, $name, $email, $password);
        $this->saveLimits((int) $id, $limits);

        return $id;
    }

    /** A reseller with no accounts and no packages may be removed. */
    public function removable(int $userId): bool
    {
        return $this->accounts->ownedBy($userId) === [] && $this->packages->ownedBy($userId) === [];
    }

    public function delete(int $userId): void
    {
        $this->table()->where('user_id', $userId)->delete();
        $this->users->delete((string) $userId);
        unset($this->cache[$userId]);
    }

    // ---- Filters ------------------------------------------------------------

    /** authorization.decision: a reseller may take only the actions in their ACL. */
    public function enforceAcl(bool $allowed, Capability $capability, Identity $identity): bool
    {
        if (!$allowed || $identity->attribute('kind') !== Kind::Reseller->value || !\in_array($capability->name, self::ACL, true)) {
            return $allowed;
        }

        return \in_array($capability->name, $this->limits((int) $identity->id)['acl'], true);
    }

    /**
     * accounts.limits: whether one more account (or this one moved) on
     * $package fits the owner's allowance.
     *
     * @param array<string, mixed>      $package
     * @param array<string, mixed>|null $account
     */
    public function checkAccount(?string $refusal, int $ownerId, array $package, ?array $account): ?string
    {
        if ($refusal !== null) {
            return $refusal;
        }

        $limits = $this->limits($ownerId);
        $usage = $this->usage($ownerId, $account === null ? null : (int) $account['id']);

        if ($account === null && $limits['max_accounts'] > 0 && $usage['accounts'] >= $limits['max_accounts']) {
            return 'Resellers.limit_accounts';
        }

        if ($limits['oversell']) {
            return null;
        }

        foreach (['disk_mb' => 'disk', 'bandwidth_mb' => 'bandwidth'] as $limit => $word) {
            if ($limits[$limit] <= 0) {
                continue;
            }

            if ((int) $package[$limit] === 0) {
                return 'Resellers.limit_unlimited_' . $word;
            }

            if ($usage[$limit] + (int) $package[$limit] > $limits[$limit]) {
                return 'Resellers.limit_' . $word;
            }
        }

        return null;
    }

    /**
     * packages.validate: a reseller's package may not be unlimited where the
     * allowance is limited, nor grow the accounts on it past the allowance.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $clean
     *
     * @return array<string, string>
     */
    public function checkPackage(array $errors, array $clean, ?int $ownerId, ?int $packageId): array
    {
        if ($ownerId === null) {
            return $errors;
        }

        $limits = $this->limits($ownerId);

        if ($limits['oversell']) {
            return $errors;
        }

        $old = $packageId === null ? null : $this->packages->byId($packageId);
        $onIt = $packageId === null ? 0 : \count(\array_filter($this->accounts->ownedBy($ownerId), static fn(array $a): bool => (int) $a['package_id'] === $packageId));
        $usage = $this->usage($ownerId);

        foreach (['disk_mb' => 'disk', 'bandwidth_mb' => 'bandwidth'] as $limit => $word) {
            if ($limits[$limit] <= 0 || isset($errors[$limit])) {
                continue;
            }

            $new = (int) $clean[$limit];

            if ($new === 0) {
                $errors[$limit] = 'Resellers.limit_unlimited_' . $word;
            } elseif ($new > $limits[$limit] || $usage[$limit] - $onIt * (int) ($old[$limit] ?? 0) + $onIt * $new > $limits[$limit]) {
                $errors[$limit] = 'Resellers.limit_' . $word;
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row a panel_users row
     *
     * @return array<string, mixed>
     */
    private function describe(array $row): array
    {
        $id = (int) $row['id'];
        unset($row['password_hash'], $row['totp_secret'], $row['recovery_codes']);

        return $row + ['limits' => $this->limits($id), 'usage' => $this->usage($id), 'packages' => \count($this->packages->ownedBy($id))];
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('reseller_limits');
    }
}
