<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Modules\Shared\Support\Ulid;

/**
 * websites and website_domains: the only code that touches them. Status
 * changes are guarded UPDATEs naming what they expect (see AccountStore);
 * a domain is reserved for one website per server by a unique index, so two
 * administrators cannot give the same name to two websites.
 *
 * The query builder is immutable: every condition is chained or assigned back.
 */
final class WebsiteStore
{
    /** List filters, as the screens and the API name them. */
    public const GROUPS = [
        'current' => [Website::PROVISIONING, Website::ACTIVE, Website::UPDATING, Website::SUSPENDING, Website::SUSPENDED, Website::RESUMING, Website::DELETING, Website::FAILED, Website::SECURING],
        'active' => [Website::ACTIVE],
        'suspended' => [Website::SUSPENDED],
        'busy' => Website::BUSY,
        'failed' => [Website::FAILED],
        'deleted' => [Website::DELETED],
    ];

    /** Statuses whose Nginx file exists on the server. */
    public const SERVED = [Website::ACTIVE, Website::UPDATING, Website::SUSPENDING, Website::SUSPENDED, Website::RESUMING, Website::DELETING, Website::SECURING];

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

    /**
     * A new website, provisioning, its domains reserved. Null if a domain is
     * another website's on this server.
     *
     * @param array<string, mixed> $settings
     */
    public function create(int $serverId, int $accountId, string $ownerUid, array $settings, ?string $createdBy): ?Website
    {
        $uid = Ulid::generate();
        $now = self::now();
        /** @var list<string> $domains */
        $domains = $settings['domains'];

        if ($this->taken($serverId, $domains, null) !== []) {
            return null;
        }

        $this->db()->table('websites')->insert([
            'uid' => $uid,
            'server_id' => $serverId,
            'account_id' => $accountId,
            'owner_uid' => $ownerUid,
            'domain' => $domains[0],
            'settings' => self::json($settings),
            'status' => Website::PROVISIONING,
            'last_action' => 'create',
            'config_state' => Website::CONFIG_OK,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $website = $this->byUid($uid) ?? throw new \LogicException('A website just created cannot be read back.');
        $this->reserve($website, $domains);

        return $website;
    }

    public function byId(int $id): ?Website
    {
        return self::hydrate($this->db()->table('websites')->where('id', $id)->first());
    }

    public function byUid(string $uid): ?Website
    {
        return Ulid::isValid($uid) ? self::hydrate($this->db()->table('websites')->where('uid', $uid)->first()) : null;
    }

    /**
     * The domains of $domains another website on $serverId holds.
     *
     * @param list<string> $domains
     *
     * @return list<string>
     */
    public function taken(int $serverId, array $domains, ?int $exceptWebsiteId): array
    {
        if ($domains === []) {
            return [];
        }

        $query = $this->db()->table('website_domains')->where('server_id', $serverId)->whereIn('domain', $domains);

        if ($exceptWebsiteId !== null) {
            $query = $query->where('website_id', '!=', $exceptWebsiteId);
        }

        return \array_values(\array_map(static fn(array $row): string => (string) $row['domain'], $query->get()));
    }

    /**
     * Newest first; $ownerUid limits it to one customer's, $accountId to one account's.
     *
     * @return list<Website>
     */
    public function page(?string $ownerUid = null, string $group = 'current', ?int $serverId = null, ?int $accountId = null, bool $editedOnly = false, int $limit = 200): array
    {
        $query = $this->db()->table('websites')->whereIn('status', self::GROUPS[$group] ?? self::GROUPS['current']);

        if ($ownerUid !== null) {
            $query = $query->where('owner_uid', $ownerUid);
        }

        if ($serverId !== null) {
            $query = $query->where('server_id', $serverId);
        }

        if ($accountId !== null) {
            $query = $query->where('account_id', $accountId);
        }

        if ($editedOnly) {
            $query = $query->where('config_state', '!=', Website::CONFIG_OK);
        }

        $rows = $query->orderByDesc('id')->limit(\max(1, \min(500, $limit)))->get();

        return \array_values(\array_filter(\array_map(self::hydrate(...), $rows)));
    }

    /** @return array<string, int> per group, "edited", and "total" (not deleted) */
    public function counts(?string $ownerUid = null): array
    {
        $query = $this->db()->table('websites');

        if ($ownerUid !== null) {
            $query = $query->where('owner_uid', $ownerUid);
        }

        $by = [];
        $edited = 0;

        foreach ($query->get() as $row) {
            $status = (string) $row['status'];
            $by[$status] = ($by[$status] ?? 0) + 1;

            if ($status !== Website::DELETED && (string) $row['config_state'] !== Website::CONFIG_OK) {
                ++$edited;
            }
        }

        $counts = [];

        foreach (self::GROUPS as $group => $statuses) {
            $counts[$group] = \array_sum(\array_map(static fn(string $s): int => $by[$s] ?? 0, $statuses));
        }

        $counts['edited'] = $edited;
        $counts['total'] = $counts['current'];

        return $counts;
    }

    /** How many websites of $accountId are not deleted. */
    public function countForAccount(int $accountId): int
    {
        return $this->db()->table('websites')->where('account_id', $accountId)->where('status', '!=', Website::DELETED)->count();
    }

    /** Whether any website on $serverId has a file on the server to check. */
    public function servedOn(int $serverId): bool
    {
        return $this->db()->table('websites')->where('server_id', $serverId)->whereIn('status', self::SERVED)->exists();
    }

    /**
     * $action begins. For an update, $pending holds the settings asked for,
     * and any new domain is reserved now. False if something moved it first,
     * or (with $pending) a new domain is another website's.
     *
     * @param array<string, mixed>|null $pending
     */
    public function begin(Website $website, string $action, string $operationUid, ?array $pending = null): bool
    {
        if ($pending !== null) {
            /** @var list<string> $domains */
            $domains = $pending['domains'];

            if ($this->taken($website->serverId, $domains, $website->id) !== []) {
                return false;
            }
        }

        $moved = $this->db()->table('websites')
            ->where('id', $website->id)
            ->where('status', $website->status)
            ->update([
                'status' => Website::ACTIONS[$action]['during'],
                'previous_status' => $website->status,
                'last_action' => $action,
                'operation_uid' => $operationUid,
                'pending_settings' => $pending === null ? null : self::json($pending),
                'last_error_code' => null,
                'last_error' => null,
                'updated_at' => self::now(),
            ]) === 1;

        if ($moved && $pending !== null) {
            /** @var list<string> $domains */
            $domains = $pending['domains'];
            $this->reserve($website, $domains);
        }

        return $moved;
    }

    /**
     * The operation under way settled. Only the website's current operation
     * may settle it. Its domains become exactly $domains (null: all released).
     *
     * @param array<string, mixed> $changes
     * @param list<string>|null    $domains
     */
    public function settle(Website $website, string $operationUid, string $status, array $changes, ?array $domains): bool
    {
        $now = self::now();
        $settled = $this->db()->table('websites')
            ->where('id', $website->id)
            ->where('operation_uid', $operationUid)
            ->where('status', $website->status)
            ->update($changes + [
                'status' => $status,
                'pending_settings' => null,
                'suspended_at' => $status === Website::SUSPENDED ? ($website->suspendedAt ?? $now) : null,
                'deleted_at' => $status === Website::DELETED ? $now : null,
                'updated_at' => $now,
            ]) === 1;

        if ($settled) {
            $query = $this->db()->table('website_domains')->where('website_id', $website->id);

            if ($domains !== null && $domains !== []) {
                $query = $query->whereNotIn('domain', $domains);
            }

            $query->delete();
        }

        return $settled;
    }

    /**
     * What a check found on the server, for websites not busy: ok, edited or
     * missing. Returns the websites whose state changed to not ok.
     *
     * @param list<string> $edited
     * @param list<string> $missing
     *
     * @return list<Website>
     */
    public function recordCheck(int $serverId, array $edited, array $missing): array
    {
        $now = self::now();
        $newlyChanged = [];

        foreach ($this->db()->table('websites')->where('server_id', $serverId)->whereIn('status', [Website::ACTIVE, Website::SUSPENDED])->get() as $row) {
            $website = self::hydrate($row);

            if ($website === null || $website->revision === null) {
                continue;
            }

            $state = \in_array($website->uid, $edited, true) ? Website::CONFIG_EDITED : (\in_array($website->uid, $missing, true) ? Website::CONFIG_MISSING : Website::CONFIG_OK);
            $this->db()->table('websites')->where('id', $website->id)->where('status', $website->status)->update(['config_state' => $state, 'config_checked_at' => $now]);

            if ($state !== Website::CONFIG_OK && $state !== $website->configState) {
                $newlyChanged[] = $website;
            }
        }

        return $newlyChanged;
    }

    /** The agent refused to touch a hand-edited file. */
    public function markEdited(Website $website): void
    {
        $this->db()->table('websites')->where('id', $website->id)->update(['config_state' => Website::CONFIG_EDITED, 'config_checked_at' => self::now()]);
    }

    /** @param list<string> $domains */
    private function reserve(Website $website, array $domains): void
    {
        $have = \array_map(static fn(array $row): string => (string) $row['domain'], $this->db()->table('website_domains')->where('website_id', $website->id)->get());

        foreach (\array_diff($domains, $have) as $domain) {
            $this->db()->table('website_domains')->insert(['server_id' => $website->serverId, 'website_id' => $website->id, 'domain' => $domain]);
        }
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed>|null $row */
    private static function hydrate(?array $row): ?Website
    {
        if ($row === null) {
            return null;
        }

        $text = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;
        $settings = \json_decode((string) ($row['settings'] ?? '{}'), true);
        $pending = isset($row['pending_settings']) && $row['pending_settings'] !== '' ? \json_decode((string) $row['pending_settings'], true) : null;
        // Websites from before certificates (ADR-0015) redirect to HTTPS once they have one: the default.
        $settings = \is_array($settings) ? $settings + ['https_redirect' => true] : [];
        $pending = \is_array($pending) ? $pending + ['https_redirect' => true] : null;

        return new Website(
            id: (int) $row['id'],
            uid: (string) $row['uid'],
            serverId: (int) $row['server_id'],
            accountId: (int) $row['account_id'],
            ownerUid: (string) $row['owner_uid'],
            domain: (string) $row['domain'],
            settings: $settings,
            pendingSettings: $pending,
            status: (string) $row['status'],
            previousStatus: $text($row['previous_status'] ?? null),
            lastAction: (string) $row['last_action'],
            operationUid: $text($row['operation_uid'] ?? null),
            lastErrorCode: $text($row['last_error_code'] ?? null),
            lastError: $text($row['last_error'] ?? null),
            revision: $text($row['revision'] ?? null),
            configState: (string) ($row['config_state'] ?? Website::CONFIG_OK),
            configCheckedAt: $text($row['config_checked_at'] ?? null),
            createdBy: $text($row['created_by'] ?? null),
            suspendedAt: $text($row['suspended_at'] ?? null),
            deletedAt: $text($row['deleted_at'] ?? null),
            createdAt: $text($row['created_at'] ?? null),
            updatedAt: $text($row['updated_at'] ?? null),
        );
    }
}
