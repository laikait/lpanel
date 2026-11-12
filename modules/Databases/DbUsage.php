<?php

declare(strict_types=1);

namespace App\Modules\Databases;

use App\Engine\Database\ConnectionManager;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\Provisioner;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * How much space each account's databases take (db.sizes), counted against
 * its package's disk space.
 *
 * Databases live in MariaDB's folder, owned by MariaDB, so the kernel's quota
 * on the home never sees them. Instead the home's quota is the package's
 * space less the databases' (Provisioner::limits); when that changes by a
 * megabyte or more, the account gets its new quota.
 */
final class DbUsage
{
    /** A change smaller than this (KiB) does not move the quota. */
    private const QUOTA_STEP_KIB = 1024;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Databases $databases,
        private readonly DbSync $sync,
        private readonly Provisioner $provisioner,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Measure every account's databases (or these accounts').
     *
     * @param list<string> $usernames
     *
     * @return array{measured: int, quotas: int}|null null when the agent could not be asked
     */
    public function refresh(array $usernames = []): ?array
    {
        try {
            $reply = $this->agent->call('db.sizes', $usernames === [] ? [] : ['usernames' => $usernames], timeout: 60.0);
        } catch (AgentUnavailable) {
            return null;
        }

        if (!$reply->done()) {
            return null;
        }

        $sizes = \is_array($reply->result['databases'] ?? null) ? $reply->result['databases'] : [];
        $totals = [];
        $measured = 0;

        foreach ($this->connections->connection()->table('db_databases')->get() as $db) {
            $account = (int) $db['account_id'];

            if ($usernames !== [] && !\in_array($this->username($account), $usernames, true)) {
                continue;
            }

            $bytes = (int) ($sizes[$db['name']] ?? 0);
            $this->databases->setSize((string) $db['name'], $bytes);
            $totals[$account] = ($totals[$account] ?? 0) + $bytes;
            $measured++;
        }

        // Accounts whose last database went still need their total set to 0.
        foreach ($this->connections->connection()->table('accounts')->where('db_used_bytes', '>', 0)->get() as $a) {
            if ($usernames === [] || \in_array((string) $a['username'], $usernames, true)) {
                $totals[(int) $a['id']] ??= 0;
            }
        }

        $quotas = 0;

        foreach ($totals as $accountId => $bytes) {
            $this->accounts->update($accountId, ['db_used_bytes' => $bytes]);
            $kib = (int) \ceil($bytes / 1024);
            $state = $this->sync->stateOf($accountId);

            if (\abs($kib - (int) ($state['quota_kib'] ?? 0)) >= self::QUOTA_STEP_KIB || ($kib === 0 && (int) ($state['quota_kib'] ?? 0) > 0)) {
                if ($this->provisioner->refreshQuota($accountId) !== null) {
                    $this->sync->mark($accountId, ['quota_kib' => $kib]);
                    $quotas++;
                }
            }
        }

        return ['measured' => $measured, 'quotas' => $quotas];
    }

    /** @var array<int, string> */
    private array $names = [];

    private function username(int $accountId): string
    {
        return $this->names[$accountId] ??= (string) ($this->accounts->byId($accountId)['username'] ?? '');
    }
}
