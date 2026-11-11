<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * Bandwidth: what each account's sites sent and received this month,
 * against its package's limit.
 *
 * The agent counts the bytes in the access logs per account and day and
 * answers running totals (web.traffic), which are stored as they come; so
 * asking twice, or missing an answer, changes nothing. web:traffic asks
 * every 15 minutes, then enforces each package's choice: "block" stops the
 * sites until the month ends or the limit is raised, "warn" only shows it.
 */
final class Bandwidth
{
    /** How long daily totals are kept, for the panel's history. */
    private const KEEP_DAYS = 400;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly Packages $packages,
        private readonly WebSync $sync,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Fetch the agent's totals.
     *
     * @return bool false when the agent could not be asked
     */
    public function refresh(): bool
    {
        $ids = [];

        foreach ($this->connections->connection()->table('accounts')->get() as $a) {
            $ids[(string) $a['username']] = (int) $a['id'];
        }

        // A first look at large logs takes more than one call.
        for ($round = 0; $round < 10; ++$round) {
            try {
                $reply = $this->agent->call('web.traffic', [], timeout: 320.0);
            } catch (AgentUnavailable) {
                return false;
            }

            if (!$reply->done()) {
                return false;
            }

            $rows = [];

            foreach (\is_array($reply->result['accounts'] ?? null) ? $reply->result['accounts'] : [] as $username => $days) {
                if (!isset($ids[(string) $username]) || !\is_array($days)) {
                    continue;
                }

                foreach ($days as $day => $bytes) {
                    if (\is_string($day) && \preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && \is_int($bytes) && $bytes >= 0) {
                        $rows[] = ['account_id' => $ids[(string) $username], 'day' => $day, 'bytes' => $bytes];
                    }
                }
            }

            if ($rows !== []) {
                $this->table()->upsert($rows, ['account_id', 'day'], ['bytes']);
            }

            if (($reply->result['more'] ?? false) !== true) {
                break;
            }
        }

        $this->table()->where('day', '<', \date('Y-m-d', \time() - self::KEEP_DAYS * 86400))->delete();

        return true;
    }

    /** Bytes the account's sites sent and received since the 1st of this month. */
    public function usedThisMonth(int $accountId): int
    {
        $total = 0;

        foreach ($this->table()->where('account_id', $accountId)->where('day', '>=', \date('Y-m-01'))->get() as $row) {
            $total += (int) $row['bytes'];
        }

        return $total;
    }

    /**
     * An account's bandwidth this month, for the panels and the API.
     *
     * @param array<string, mixed> $account
     *
     * @return array{used: int, limit: int, percent: int, over: bool, limited: bool, action: string}
     */
    public function usage(array $account): array
    {
        $package = $account['package_id'] === null ? null : $this->packages->byId((int) $account['package_id']);
        $limit = (int) ($package['bandwidth_mb'] ?? 0) * 1048576;
        $used = $this->usedThisMonth((int) $account['id']);

        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => $limit > 0 ? (int) \min(100, \floor($used * 100 / $limit)) : 0,
            'over' => $limit > 0 && $used >= $limit,
            'limited' => (bool) ($this->sync->stateOf((int) $account['id'])['limited'] ?? false),
            'action' => (string) ($package['bandwidth_action'] ?? 'block'),
        ];
    }

    /**
     * Stop the sites of accounts over their limit whose package says so, and
     * start again those no longer over it (a new month, a higher limit, a
     * package that only warns).
     *
     * @return array{limited: int, restored: int}
     */
    public function enforce(): array
    {
        $counts = ['limited' => 0, 'restored' => 0];

        foreach ([Accounts::ACTIVE, Accounts::SUSPENDED] as $status) {
            foreach ($this->connections->connection()->table('accounts')->where('status', $status)->get() as $account) {
                $usage = $this->usage($account);
                $stop = $usage['over'] && $usage['action'] === 'block';

                if ($stop === $usage['limited']) {
                    continue;
                }

                $outcome = $this->sync->setLimited((int) $account['id'], $stop);
                ++$counts[$stop ? 'limited' : 'restored'];
                $this->audit->record($stop ? 'account.bandwidth_limited' : 'account.bandwidth_restored', null, null, (string) $account['username'], $outcome?->status === WebOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, [
                    'used' => $usage['used'], 'limit' => $usage['limit'],
                ]);
            }
        }

        return $counts;
    }

    /**
     * Daily totals for the last $days days, oldest first, with the missing
     * days as 0.
     *
     * @return list<array{day: string, bytes: int}>
     */
    public function daily(int $accountId, int $days = 30): array
    {
        $from = \date('Y-m-d', \time() - ($days - 1) * 86400);
        $have = [];

        foreach ($this->table()->where('account_id', $accountId)->where('day', '>=', $from)->get() as $row) {
            $have[\substr((string) $row['day'], 0, 10)] = (int) $row['bytes'];
        }

        $out = [];

        for ($i = $days - 1; $i >= 0; --$i) {
            $day = \date('Y-m-d', \time() - $i * 86400);
            $out[] = ['day' => $day, 'bytes' => $have[$day] ?? 0];
        }

        return $out;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('web_traffic');
    }
}
