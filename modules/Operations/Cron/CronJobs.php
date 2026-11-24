<?php

declare(strict_types=1);

namespace App\Modules\Operations\Cron;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * An account's scheduled jobs. The panel keeps them; the server runs them
 * (cron, as the account) from the whole list sent by `cron.apply`, which is
 * sent again whenever the list, the account's state or its package changes.
 * A suspended account, or one whose package has no cron feature, runs
 * nothing, but keeps its list.
 */
final class CronJobs
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly Packages $packages,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forAccount(int $accountId): array
    {
        return \array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'account_id' => (int) $r['account_id']] + $r,
            \array_values($this->table()->where('account_id', $accountId)->orderBy('id')->get()),
        );
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array{0: int|null, 1: array<string, string>} the new job's id, or what is wrong (field => language key or the server's words)
     */
    public function add(?Identity $actor, ?Request $request, array $account, string $schedule, string $command): array
    {
        $errors = CronRules::check($schedule, $command);

        if (\count($this->forAccount((int) $account['id'])) >= CronRules::MAX_JOBS) {
            $errors['schedule'] = 'Operations.cron_error_limit';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $id = (int) $this->table()->insert([
            'account_id' => (int) $account['id'], 'schedule' => CronRules::normalize($schedule), 'command' => $command, 'created_at' => \gmdate('Y-m-d H:i:s'),
        ], 'id');
        [$ok, $why] = $this->sync($account);
        $this->audit->record('cron.add', $actor, $request, (string) $account['username'], $ok ? AuditLog::OK : AuditLog::FAILED, ['schedule' => CronRules::normalize($schedule)] + ($ok ? [] : ['error' => $why]));

        if (!$ok) {
            $this->table()->where('id', $id)->delete();

            return [null, ['server' => (string) $why]];
        }

        return [$id, []];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array{0: bool, 1: string|null}
     */
    public function remove(?Identity $actor, ?Request $request, array $account, int $id): array
    {
        $row = $this->table()->where('id', $id)->where('account_id', (int) $account['id'])->first();

        if ($row === null) {
            return [false, 'Operations.cron_error_missing'];
        }

        $this->table()->where('id', $id)->delete();
        [$ok, $why] = $this->sync($account);
        $this->audit->record('cron.remove', $actor, $request, (string) $account['username'], $ok ? AuditLog::OK : AuditLog::FAILED, ['schedule' => (string) $row['schedule']] + ($ok ? [] : ['error' => $why]));

        if (!$ok) {
            // The server still has the job, so the panel must too.
            $this->table()->insert(['id' => (int) $row['id'], 'account_id' => (int) $row['account_id'], 'schedule' => $row['schedule'], 'command' => $row['command'], 'created_at' => $row['created_at']]);
        }

        return [$ok, $why];
    }

    /**
     * Send the account's jobs to the server as they should run now.
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: bool, 1: string|null}
     */
    public function sync(array $account): array
    {
        $package = $this->packages->byId((int) ($account['package_id'] ?? 0));
        $allowed = $package !== null && Packages::has($package, 'cron');
        $jobs = $allowed ? \array_map(static fn(array $j): array => ['schedule' => (string) $j['schedule'], 'command' => (string) $j['command']], $this->forAccount((int) $account['id'])) : [];

        try {
            $reply = $this->agent->call('cron.apply', [
                'username' => (string) $account['username'],
                'suspended' => ($account['status'] ?? '') !== Accounts::ACTIVE,
                'jobs' => $jobs,
            ], id: Protocol::newId(), timeout: 30.0);
        } catch (AgentUnavailable) {
            return [false, 'Operations.cron_error_unreachable'];
        }

        return $reply->done() ? [true, null] : [false, (string) ($reply->errorMessage ?? 'Operations.error_failed')];
    }

    /**
     * An account was removed: its jobs go, and so does its file.
     *
     * @param array<string, mixed> $account
     */
    public function forget(array $account): void
    {
        $this->table()->where('account_id', (int) $account['id'])->delete();

        try {
            $this->agent->call('cron.apply', ['username' => (string) $account['username'], 'suspended' => true, 'jobs' => []], id: Protocol::newId(), timeout: 30.0);
        } catch (AgentUnavailable) {
            // The account is gone; cron ignores a file whose user does not exist.
        }
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('cron_jobs');
    }
}
