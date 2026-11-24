<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Hook\HookEngine;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Backup runs: started by the schedule (backup:due, every minute) or by
 * hand, carried out by the agent in the background (backup.run), and
 * followed here (backup:settle, every minute) until the agent has the
 * outcome of every account. One run at a time.
 */
final class BackupRuns
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';

    /** A run the agent has not heard of after this long is given up. */
    private const LOST_AFTER = 600;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Destinations $destinations,
        private readonly BackupSettings $settings,
        private readonly AccountExport $export,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly HookEngine $hooks,
    ) {}

    /**
     * Start a run: every account on the server (active or suspended), or
     * those named, to a destination (the schedule's when none is given).
     *
     * @param list<string>|null $usernames
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} the run, or why not (a language key or the agent's words)
     */
    public function start(?Identity $actor, ?Request $request, string $kind, ?int $destinationId = null, ?array $usernames = null): array
    {
        $s = $this->settings->get();
        $destination = $this->destinations->find($destinationId ?? $s['destination_id']);

        if ($destination === null) {
            return [null, 'Backups.error_destination_needed'];
        }

        $accounts = $this->accounts->withSites();

        if ($usernames !== null) {
            $accounts = \array_values(\array_filter($accounts, static fn(array $a): bool => \in_array($a['username'], $usernames, true)));

            if (\count($accounts) !== \count(\array_unique($usernames))) {
                return [null, 'Backups.error_accounts'];
            }
        }

        if ($accounts === []) {
            return [null, 'Backups.error_no_accounts'];
        }

        if ($this->running() !== null) {
            return [null, 'Backups.error_busy'];
        }

        $names = \array_map(static fn(array $a): string => (string) $a['username'], $accounts);
        $opId = Protocol::newId();
        $now = Accounts::now();
        $id = (int) $this->table()->insert([
            'destination_id' => (int) $destination['id'],
            'op_id' => $opId,
            'kind' => $kind,
            'state' => self::RUNNING,
            'accounts' => \count($accounts),
            'usernames' => \json_encode($names, \JSON_THROW_ON_ERROR),
            'started_by' => $actor === null ? 'schedule' : (string) ($actor->attribute('username') ?? $actor->id),
            'started_at' => $now,
        ], 'id');

        $args = [
            'destination' => Destinations::agentId((int) $destination['id']),
            'accounts' => \array_map(fn(array $a): array => ['username' => (string) $a['username'], 'meta' => $this->export->export($a)], $accounts),
            'keep' => ['daily' => $s['keep_daily'], 'weekly' => $s['keep_weekly'], 'monthly' => $s['keep_monthly']],
            // Freeing space is slow: once a week, and always when started by hand with every account.
            'prune' => (int) \gmdate('w') === $s['prune_day'] || ($kind === 'manual' && $usernames === null),
        ];

        try {
            $reply = $this->agent->call('backup.run', $args, id: $opId, timeout: 60.0);
            $why = \in_array($reply->status, ['accepted', 'running', 'done'], true) ? null
                : ($reply->errorCode === 'backup_busy' ? 'Backups.error_busy' : (string) ($reply->errorMessage ?? 'Backups.error_failed'));
        } catch (AgentUnavailable) {
            $why = 'Backups.error_unreachable';
            $reply = null;
        }

        $details = ['destination' => $destination['name'], 'accounts' => \count($names), 'kind' => $kind];

        if ($why !== null) {
            $this->table()->where('id', $id)->update(['state' => self::FAILED, 'error' => $why, 'finished_at' => Accounts::now()]);
            $this->audit->record('backup.run', $actor, $request, (string) $destination['name'], AuditLog::FAILED, $details + ['error' => $why]);

            return [$this->find($id), $why];
        }

        $this->audit->record('backup.run', $actor, $request, (string) $destination['name'], AuditLog::OK, $details + ['state' => 'started']);

        if ($reply !== null && $reply->done()) {
            $this->finish($this->find($id) ?? [], $reply->result, null);
        }

        return [$this->find($id), null];
    }

    /**
     * Ask the agent how running runs went; returns how many finished.
     */
    public function settle(): int
    {
        $finished = 0;

        foreach ($this->table()->where('state', self::RUNNING)->get() as $run) {
            try {
                $reply = $this->agent->status((string) $run['op_id']);
            } catch (AgentUnavailable) {
                continue;
            }

            if ($reply->status === 'accepted' || $reply->status === 'running') {
                continue;
            }

            if ($reply->done()) {
                $this->finish($run, $reply->result, null);
                $finished++;
            } elseif ($reply->errorCode === 'not_found') {
                // The agent never took it (or lost its journal): give up after a while.
                if (\strtotime((string) $run['started_at'] . ' UTC') < \time() - self::LOST_AFTER) {
                    $this->finish($run, [], 'Backups.error_lost');
                    $finished++;
                }
            } else {
                $this->finish($run, [], (string) ($reply->errorMessage ?? 'Backups.error_failed'));
                $finished++;
            }
        }

        return $finished;
    }

    /**
     * Start the scheduled run when it is due: enabled, past today's time
     * (in the server's time zone), and none started today.
     */
    public function due(?\DateTimeImmutable $now = null): bool
    {
        $s = $this->settings->get();
        $now ??= new \DateTimeImmutable();

        if (!$s['enabled'] || $s['destination_id'] === 0 || $now->format('H:i') < $s['time']) {
            return false;
        }

        $today = $now->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if ($this->table()->where('kind', 'scheduled')->where('started_at', '>=', $today)->first() !== null) {
            return false;
        }

        [$run] = $this->start(null, null, 'scheduled');

        return $run !== null;
    }

    /** @return array<string, mixed>|null */
    public function running(): ?array
    {
        return $this->table()->where('state', self::RUNNING)->first();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : self::decode($row);
    }

    /** @return list<array<string, mixed>> newest first */
    public function recent(int $limit = 20): array
    {
        return \array_map(self::decode(...), $this->table()->orderBy('id', 'desc')->limit($limit)->get());
    }

    /**
     * The last outcome for an account: from the newest finished run that
     * had it.
     *
     * @return array<string, mixed>|null its result, with the run's finished_at
     */
    public function lastFor(string $username): ?array
    {
        foreach ($this->table()->where('state', '!=', self::RUNNING)->orderBy('id', 'desc')->limit(60)->get() as $row) {
            $run = self::decode($row);

            foreach ($run['results'] as $r) {
                if (($r['username'] ?? null) === $username) {
                    return $r + ['finished_at' => $run['finished_at'], 'run_id' => (int) $run['id']];
                }
            }
        }

        return null;
    }

    /**
     * The run going on now, as the agent sees it.
     *
     * @return array<string, mixed>|null
     */
    public function progress(): ?array
    {
        try {
            $reply = $this->agent->call('backup.status', [], timeout: 10.0);
        } catch (AgentUnavailable) {
            return null;
        }

        return $reply->done() ? $reply->result : null;
    }

    /**
     * An account's snapshots at a destination, newest first.
     *
     * @return list<array<string, mixed>>|null null when the agent cannot tell
     */
    public function snapshots(int $destinationId, ?string $username = null): ?array
    {
        try {
            $reply = $this->agent->call('backup.snapshots', ['destination' => Destinations::agentId($destinationId), 'username' => $username ?? ''], timeout: 130.0);
        } catch (AgentUnavailable) {
            return null;
        }

        if (!$reply->done()) {
            return null;
        }

        return \array_values(\array_filter($reply->result, 'is_array'));
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $result
     */
    private function finish(array $run, array $result, ?string $error): void
    {
        $ok = (int) ($result['ok'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $state = match (true) {
            $error !== null => self::FAILED,
            $failed === 0 && $ok > 0 => self::DONE,
            $ok > 0 => self::PARTIAL,
            default => self::FAILED,
        };

        $this->table()->where('id', (int) $run['id'])->update([
            'state' => $state,
            'ok' => $ok,
            'failed' => $failed,
            'result' => $result === [] ? null : \json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'error' => $error ?? ($state === self::FAILED ? 'Backups.error_all_failed' : null),
            'finished_at' => Accounts::now(),
        ]);

        $destination = $this->destinations->find((int) $run['destination_id']);
        $this->audit->record('backup.finished', null, null, (string) ($destination['name'] ?? $run['destination_id']), $state === self::DONE ? AuditLog::OK : AuditLog::FAILED, [
            'run' => (int) $run['id'], 'state' => $state, 'ok' => $ok, 'failed' => $failed,
        ] + ($error === null ? [] : ['error' => $error]) + self::failures($result));
        // For alerts and anything else that wants to know how a run went.
        $this->hooks->do('backups.finished', (int) $run['id'], $state, $ok, $failed, (string) ($destination['name'] ?? $run['destination_id']), $error);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private static function failures(array $result): array
    {
        $out = [];

        foreach (\is_array($result['accounts'] ?? null) ? $result['accounts'] : [] as $r) {
            if (\is_array($r) && ($r['ok'] ?? false) !== true) {
                $out['failed_' . ($r['username'] ?? '?')] = \mb_substr((string) ($r['error'] ?? ''), 0, 200);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $result = \json_decode((string) ($row['result'] ?? ''), true);
        $names = \json_decode((string) $row['usernames'], true);
        $row['results'] = \is_array($result) && \is_array($result['accounts'] ?? null) ? \array_values(\array_filter($result['accounts'], 'is_array')) : [];
        $row['pruned'] = \is_array($result) && ($result['pruned'] ?? false) === true;
        $row['usernames'] = \is_array($names) ? $names : [];

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('backup_runs');
    }
}
