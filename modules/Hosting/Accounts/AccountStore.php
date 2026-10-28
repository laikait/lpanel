<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Accounts;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Modules\Shared\Support\Ulid;

/**
 * hosting_accounts: the only code that touches it. Every change of status is
 * a guarded UPDATE naming the status (or the operation) it expects, so an
 * administrator's click and an agent's result cannot both win.
 *
 * The query builder is immutable: every condition is chained or assigned back.
 */
final class AccountStore
{
    /** List filters, as the screens and the API name them. */
    public const GROUPS = [
        'current' => [Account::PROVISIONING, Account::ACTIVE, Account::SUSPENDING, Account::SUSPENDED, Account::RESUMING, Account::DELETING, Account::FAILED],
        'active' => [Account::ACTIVE],
        'suspended' => [Account::SUSPENDED],
        'busy' => Account::BUSY,
        'failed' => [Account::FAILED],
        'deleted' => [Account::DELETED],
    ];

    public function __construct(private readonly ConnectionManager $connections) {}

    private function db(): Connection
    {
        return $this->connections->connection();
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        return $this->db()->transaction(static fn(): mixed => $work());
    }

    /** A new account, provisioning, with its first action "create". */
    public function create(int $serverId, string $ownerUid, string $label, string $systemUser, ?string $createdBy): Account
    {
        $uid = Ulid::generate();
        $now = self::now();

        $this->db()->table('hosting_accounts')->insert([
            'uid' => $uid,
            'server_id' => $serverId,
            'owner_uid' => $ownerUid,
            'label' => $label,
            'system_user' => $systemUser,
            'status' => Account::PROVISIONING,
            'last_action' => 'create',
            'limits' => '{}',
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->byUid($uid) ?? throw new \LogicException('An account just created cannot be read back.');
    }

    public function byUid(string $uid): ?Account
    {
        return Ulid::isValid($uid) ? self::hydrate($this->db()->table('hosting_accounts')->where('uid', $uid)->first()) : null;
    }

    public function byId(int $id): ?Account
    {
        return self::hydrate($this->db()->table('hosting_accounts')->where('id', $id)->first());
    }

    public function systemUserTaken(int $serverId, string $systemUser): bool
    {
        return $this->db()->table('hosting_accounts')->where('server_id', $serverId)->where('system_user', $systemUser)->exists();
    }

    /**
     * Newest first. $ownerUid limits it to one customer's.
     *
     * @return list<Account>
     */
    public function page(?string $ownerUid = null, string $group = 'current', ?int $serverId = null, int $limit = 200): array
    {
        $query = $this->db()->table('hosting_accounts')->whereIn('status', self::GROUPS[$group] ?? self::GROUPS['current']);

        if ($ownerUid !== null) {
            $query = $query->where('owner_uid', $ownerUid);
        }

        if ($serverId !== null) {
            $query = $query->where('server_id', $serverId);
        }

        $rows = $query->orderByDesc('id')->limit(\max(1, \min(500, $limit)))->get();

        return \array_values(\array_filter(\array_map(self::hydrate(...), $rows)));
    }

    /** @return array<string, int> per group, and "total" (not deleted) */
    public function counts(?string $ownerUid = null): array
    {
        $query = $this->db()->table('hosting_accounts');

        if ($ownerUid !== null) {
            $query = $query->where('owner_uid', $ownerUid);
        }

        $by = [];

        foreach ($query->get() as $row) {
            $status = (string) $row['status'];
            $by[$status] = ($by[$status] ?? 0) + 1;
        }

        $counts = [];

        foreach (self::GROUPS as $group => $statuses) {
            $counts[$group] = \array_sum(\array_map(static fn(string $s): int => $by[$s] ?? 0, $statuses));
        }

        $counts['total'] = $counts['current'];

        return $counts;
    }

    /**
     * $action begins: the account moves to its -ing status, remembering where
     * it was. False if something else moved it first.
     */
    public function begin(Account $account, string $action, string $operationUid): bool
    {
        return $this->db()->table('hosting_accounts')
            ->where('id', $account->id)
            ->where('status', $account->status)
            ->update([
                'status' => Account::ACTIONS[$action]['during'],
                'previous_status' => $account->status,
                'last_action' => $action,
                'operation_uid' => $operationUid,
                'last_error_code' => null,
                'last_error' => null,
                'updated_at' => self::now(),
            ]) === 1;
    }

    /**
     * The operation under way settled. Only the account's current operation
     * may settle it: a late result of an older one changes nothing.
     *
     * @param array<string, mixed> $changes
     */
    public function settle(Account $account, string $operationUid, string $status, array $changes = []): bool
    {
        $now = self::now();

        return $this->db()->table('hosting_accounts')
            ->where('id', $account->id)
            ->where('operation_uid', $operationUid)
            ->where('status', $account->status)
            ->update($changes + [
                'status' => $status,
                'suspended_at' => $status === Account::SUSPENDED ? $now : null,
                'deleted_at' => $status === Account::DELETED ? $now : null,
                'updated_at' => $now,
            ]) === 1;
    }

    /** @param array<string, mixed>|null $row */
    private static function hydrate(?array $row): ?Account
    {
        if ($row === null) {
            return null;
        }

        $text = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;
        $limits = \json_decode((string) ($row['limits'] ?? '{}'), true);

        return new Account(
            id: (int) $row['id'],
            uid: (string) $row['uid'],
            serverId: (int) $row['server_id'],
            ownerUid: (string) $row['owner_uid'],
            label: (string) $row['label'],
            systemUser: (string) $row['system_user'],
            systemUid: isset($row['system_uid']) ? (int) $row['system_uid'] : null,
            systemGid: isset($row['system_gid']) ? (int) $row['system_gid'] : null,
            phpVersion: $text($row['php_version'] ?? null),
            status: (string) $row['status'],
            previousStatus: $text($row['previous_status'] ?? null),
            lastAction: (string) $row['last_action'],
            operationUid: $text($row['operation_uid'] ?? null),
            lastErrorCode: $text($row['last_error_code'] ?? null),
            lastError: $text($row['last_error'] ?? null),
            limits: \is_array($limits) ? $limits : [],
            createdBy: $text($row['created_by'] ?? null),
            suspendedAt: $text($row['suspended_at'] ?? null),
            deletedAt: $text($row['deleted_at'] ?? null),
            createdAt: $text($row['created_at'] ?? null),
            updatedAt: $text($row['updated_at'] ?? null),
        );
    }
}
