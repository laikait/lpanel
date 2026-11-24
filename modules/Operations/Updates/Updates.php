<?php

declare(strict_types=1);

namespace App\Modules\Operations\Updates;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Keeping the server up to date: checking what can be updated, installing the
 * operating system's and PHP's updates, upgrading LPanel itself, rebooting.
 *
 * Each of those is a run (ops_update_runs), one at a time, done by the agent in
 * the background and settled every minute (like backups). A panel upgrade has
 * one more step: once the agent has downloaded and checked the release and
 * started its upgrade script, the run is "installing" until the script says it
 * is done or that it put the old release back.
 *
 * Every night, in the hour the settings name, what they allow is installed by
 * itself; a check runs once a day and after each update. What happens is
 * announced through the `ops.update_event` hook ($kind, $detail): available,
 * applied, failed, upgraded, reboot (alerts listen to it).
 */
final class Updates
{
    public const RUNNING = 'running';
    public const INSTALLING = 'installing';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public const SCOPES = ['all', 'security', 'php', 'packages'];

    /** A run the agent has no record of is given up on after this long, in seconds. */
    private const LOST_AFTER = 10800;

    /** A failed check is tried again after this long, in seconds. */
    private const RETRY_CHECK = 3600;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly ConnectionManager $connections,
        private readonly UpdateSettings $settings,
        private readonly PanelRelease $release,
        private readonly AuditLog $audit,
        private readonly HookEngine $hooks,
    ) {}

    public static function validVersion(string $v): bool
    {
        return \preg_match('/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,6}(-[0-9A-Za-z.]{1,40})?$/', $v) === 1;
    }

    /** The release of LPanel running now ('' in development). */
    public function installed(): string
    {
        return $this->release->installed();
    }

    /**
     * Ask the agent what can be updated.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} the run, and why it did not start
     */
    public function check(?Identity $actor, ?Request $request): array
    {
        return $this->start($actor, $request, 'check', '', 'updates.check', []);
    }

    /**
     * Install updates: all, security, php, or the named packages.
     *
     * @param list<string> $packages
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function apply(?Identity $actor, ?Request $request, string $scope, array $packages = []): array
    {
        if (!\in_array($scope, self::SCOPES, true)) {
            return [null, 'Operations.updates_error_scope'];
        }

        $packages = \array_values(\array_unique(\array_map('strval', $packages)));

        if ($scope === 'packages') {
            if ($packages === [] || \count($packages) > 200) {
                return [null, 'Operations.updates_error_packages'];
            }

            foreach ($packages as $p) {
                if (\preg_match('/^[A-Za-z0-9_][A-Za-z0-9._+-]{0,127}$/', $p) !== 1) {
                    return [null, 'Operations.updates_error_packages'];
                }
            }
        } else {
            $packages = [];
        }

        $args = ['scope' => $scope, 'exclude' => $this->settings->get()['exclude']];

        if ($packages !== []) {
            $args['packages'] = $packages;
        }

        return $this->start($actor, $request, 'os', $scope, 'updates.apply', $args, \implode(' ', $packages));
    }

    /**
     * Upgrade LPanel to the release the channel offers.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function upgrade(?Identity $actor, ?Request $request, string $version): array
    {
        if (!self::validVersion($version)) {
            return [null, 'Operations.updates_error_version'];
        }

        $installed = $this->installed();

        if ($installed !== '' && \version_compare($version, $installed, '<=')) {
            return [null, 'Operations.updates_error_not_newer'];
        }

        return $this->start($actor, $request, 'panel', '', 'updates.panel', ['version' => $version], $version);
    }

    /**
     * Reboot the server a minute from now, or call that off.
     *
     * @return array{0: bool, 1: string|null}
     */
    public function reboot(?Identity $actor, ?Request $request, bool $cancel = false): array
    {
        if (!$cancel && $this->busy() !== null) {
            return [false, 'Operations.updates_busy'];
        }

        try {
            $reply = $this->agent->call('updates.reboot', ['cancel' => $cancel], id: Protocol::newId(), timeout: 60.0);
        } catch (AgentUnavailable) {
            return [false, 'Operations.error_unreachable'];
        }

        $ok = $reply->done();
        $why = $ok ? null : (string) ($reply->errorMessage ?? 'Operations.error_failed');
        $this->audit->record($cancel ? 'updates.reboot_cancel' : 'updates.reboot', $actor, $request, 'server', $ok ? AuditLog::OK : AuditLog::FAILED, $ok ? [] : ['error' => $why]);

        if ($ok) {
            $now = \gmdate('Y-m-d H:i:s');
            $this->table()->insert([
                'kind' => 'reboot', 'scope' => $cancel ? 'cancel' : '', 'state' => self::DONE, 'started_by' => self::who($actor),
                'started_at' => $now, 'finished_at' => $now, 'result' => \json_encode($reply->result, \JSON_THROW_ON_ERROR),
            ]);

            if (!$cancel) {
                $this->announce('reboot', 'the server reboots in a minute');
            }
        }

        return [$ok, $why];
    }

    /** Settle running runs with the agent; returns how many finished. */
    public function settle(): int
    {
        $finished = 0;

        foreach ($this->table()->whereIn('state', [self::RUNNING, self::INSTALLING])->orderBy('id')->get() as $run) {
            if ($run['state'] === self::INSTALLING) {
                $finished += $this->settleInstall($run) ? 1 : 0;

                continue;
            }

            try {
                $reply = $this->agent->status((string) $run['op_id']);
            } catch (AgentUnavailable) {
                continue;
            }

            if ($reply->status === 'accepted' || $reply->status === 'running') {
                continue;
            }

            if ($reply->done()) {
                $this->finished($run, $reply);
                $finished++;
            } elseif ($reply->errorCode === 'not_found') {
                if (\strtotime((string) $run['started_at'] . ' UTC') < \time() - self::LOST_AFTER) {
                    $this->fail($run, 'Operations.updates_error_lost');
                    $finished++;
                }
            } else {
                $this->fail($run, (string) ($reply->errorMessage ?? 'Operations.error_failed'));
                $finished++;
            }
        }

        return $finished;
    }

    /**
     * What is due now: the daily check (and one after each update), then in
     * the night's hour what the settings install by themselves. One thing per
     * call; the next minute's call does the next.
     *
     * @return string|null what was started (check, security, all, php, panel, reboot)
     */
    public function due(?\DateTimeImmutable $now = null): ?string
    {
        if ($this->busy() !== null) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $s = $this->settings->get();
        $lastCheck = $this->last('check');
        $goodCheck = $this->last('check', self::DONE);
        $lastChange = $this->table()->whereIn('kind', ['os', 'panel'])->where('state', self::DONE)->orderByDesc('id')->first();
        $ago = static fn(?array $run): int => $run === null ? \PHP_INT_MAX : \time() - (int) \strtotime((string) $run['started_at'] . ' UTC');

        $stale = $goodCheck === null || $ago($goodCheck) > 86400
            || ($lastChange !== null && (int) $lastChange['id'] > (int) $goodCheck['id']);

        if ($stale && ($lastCheck === null || $lastCheck['state'] !== self::FAILED || $ago($lastCheck) > self::RETRY_CHECK)) {
            [$run] = $this->check(null, null);

            return $run !== null ? 'check' : null;
        }

        if ((int) $now->format('G') !== $s['hour']) {
            return null;
        }

        $latest = $this->latest();
        $today = $now->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $doneToday = fn(string $kind, ?string $scope = null): bool => $this->scheduledSince($kind, $scope, $today);

        if ($latest === null) {
            return null;
        }

        if ($s['os'] !== 'off' && !$doneToday('os', $s['os'])) {
            $waiting = $s['os'] === 'all' ? \count($latest['packages']) : (int) $latest['security'];

            if ($waiting > 0) {
                [$run] = $this->apply(null, null, $s['os']);

                return $run !== null ? $s['os'] : null;
            }
        }

        if ($s['php'] && $s['os'] !== 'all' && $latest['php'] !== [] && !$doneToday('os', 'php')) {
            [$run] = $this->apply(null, null, 'php');

            return $run !== null ? 'php' : null;
        }

        if ($s['panel'] === 'install' && $latest['panel']['newer'] && !$doneToday('panel')) {
            [$run] = $this->upgrade(null, null, (string) $latest['panel']['latest']);

            return $run !== null ? 'panel' : null;
        }

        if ($s['reboot'] && $latest['reboot']['needed'] && !$latest['reboot']['scheduled'] && !$doneToday('reboot') && $this->scheduledSince('os', null, $today)) {
            [$ok] = $this->reboot(null, null);

            return $ok ? 'reboot' : null;
        }

        return null;
    }

    /**
     * What the last good check found, with when.
     *
     * @return array{checked_at: string, kernel: string, packages: list<array<string, mixed>>, security: int, php: list<array<string, mixed>>, restart: list<string>, reboot: array{known: bool, needed: bool, why: list<string>, scheduled: bool}, panel: array{channel: bool, installed: string, latest: string, released: string, notes: string, newer: bool, error: string}}|null
     */
    public function latest(): ?array
    {
        $run = $this->last('check', self::DONE);

        if ($run === null) {
            return null;
        }

        $r = \json_decode((string) $run['result'], true);
        $r = \is_array($r) ? $r : [];
        $panel = \is_array($r['panel'] ?? null) ? $r['panel'] : [];
        $reboot = \is_array($r['reboot'] ?? null) ? $r['reboot'] : [];
        $installed = $this->installed();
        $latestVersion = \is_string($panel['latest'] ?? null) ? $panel['latest'] : '';

        return [
            'checked_at' => (string) $run['finished_at'],
            'kernel' => \is_string($r['kernel'] ?? null) ? $r['kernel'] : '',
            'packages' => \array_values(\array_filter((array) ($r['packages'] ?? []), 'is_array')),
            'security' => (int) ($r['security'] ?? 0),
            'php' => \array_values(\array_filter((array) ($r['php'] ?? []), 'is_array')),
            'restart' => \array_values(\array_filter((array) ($r['restart'] ?? []), 'is_string')),
            'reboot' => [
                'known' => (bool) ($reboot['known'] ?? false),
                'needed' => (bool) ($reboot['needed'] ?? false),
                'why' => \array_values(\array_filter((array) ($reboot['why'] ?? []), 'is_string')),
                'scheduled' => (bool) ($reboot['scheduled'] ?? false),
            ],
            'panel' => [
                'channel' => (bool) ($panel['channel'] ?? false),
                'installed' => $installed,
                'latest' => $latestVersion,
                'released' => \is_string($panel['released'] ?? null) ? $panel['released'] : '',
                'notes' => \is_string($panel['notes'] ?? null) ? $panel['notes'] : '',
                // Against the release running now: an upgrade since the check is taken into account.
                'newer' => $latestVersion !== '' && $installed !== '' && self::validVersion($latestVersion) && \version_compare($latestVersion, $installed, '>'),
                'error' => \is_string($panel['error'] ?? null) ? $panel['error'] : '',
            ],
        ];
    }

    /**
     * The latest runs, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(int $limit = 20): array
    {
        return \array_map(self::decode(...), \array_values($this->table()->orderByDesc('id')->limit($limit)->get()));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null the run going on now */
    public function busy(): ?array
    {
        $row = $this->table()->whereIn('state', [self::RUNNING, self::INSTALLING])->orderBy('id')->first();

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null the last update or upgrade, if it failed in the last day */
    public function recentFailure(): ?array
    {
        $row = $this->table()->whereIn('kind', ['os', 'panel'])->whereIn('state', [self::DONE, self::FAILED])->orderByDesc('id')->first();

        if ($row === null || $row['state'] !== self::FAILED || (string) $row['started_at'] < \gmdate('Y-m-d H:i:s', \time() - 86400)) {
            return null;
        }

        return self::decode($row);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function start(?Identity $actor, ?Request $request, string $kind, string $scope, string $op, array $args, string $target = ''): array
    {
        if ($this->busy() !== null) {
            return [null, 'Operations.updates_busy'];
        }

        $opId = Protocol::newId();
        $id = (int) $this->table()->insert([
            'kind' => $kind,
            'scope' => $scope,
            'state' => self::RUNNING,
            'op_id' => $opId,
            'target' => \mb_substr($target, 0, 64),
            'started_by' => self::who($actor),
            'started_at' => \gmdate('Y-m-d H:i:s'),
        ], 'id');

        try {
            $reply = $this->agent->call($op, $args, id: $opId, timeout: 30.0);
            $why = \in_array($reply->status, ['accepted', 'running', 'done'], true) ? null : (string) ($reply->errorMessage ?? 'Operations.error_failed');
        } catch (AgentUnavailable) {
            $reply = null;
            $why = 'Operations.error_unreachable';
        }

        $action = match ($kind) {
            'check' => 'updates.check',
            'panel' => 'updates.panel',
            default => 'updates.apply',
        };
        $details = \array_filter(['scope' => $scope, 'target' => $target], static fn(string $v): bool => $v !== '');

        if ($why !== null) {
            $this->table()->where('id', $id)->update(['state' => self::FAILED, 'error' => \mb_substr($why, 0, 500), 'finished_at' => \gmdate('Y-m-d H:i:s')]);
            $this->audit->record($action, $actor, $request, 'server', AuditLog::FAILED, $details + ['error' => $why]);

            return [$this->find($id), $why];
        }

        if ($kind !== 'check') {
            $this->audit->record($action, $actor, $request, 'server', AuditLog::OK, $details + ['state' => 'started']);
        }

        if ($reply !== null && $reply->done()) {
            $this->finished($this->table()->where('id', $id)->first() ?? [], $reply);
        }

        return [$this->find($id), null];
    }

    /** @param array<string, mixed> $run */
    private function finished(array $run, AgentReply $reply): void
    {
        $result = \json_encode($reply->result, \JSON_THROW_ON_ERROR);

        if ($run['kind'] === 'panel') {
            // The release is checked and its upgrade script started: now wait for the script.
            $this->table()->where('id', (int) $run['id'])->update(['state' => self::INSTALLING, 'result' => $result]);

            return;
        }

        $this->table()->where('id', (int) $run['id'])->update(['state' => self::DONE, 'result' => $result, 'finished_at' => \gmdate('Y-m-d H:i:s')]);

        if ($run['kind'] === 'check') {
            $this->announceFound($run, $reply->result);

            return;
        }

        $upgraded = \count((array) ($reply->result['upgraded'] ?? []));
        $reboot = (bool) (((array) ($reply->result['reboot'] ?? []))['needed'] ?? false);
        $this->audit->record('updates.applied', null, null, 'server', AuditLog::OK, ['scope' => (string) $run['scope'], 'packages' => $upgraded, 'reboot' => $reboot, 'started_by' => (string) $run['started_by']]);

        if ($upgraded > 0) {
            $this->announce('applied', $upgraded . ' packages updated (' . (string) $run['scope'] . ')' . ($reboot ? '; the server needs a reboot' : ''));
        }
    }

    /**
     * An upgrade the agent started: how its script is doing.
     *
     * @param array<string, mixed> $run
     */
    private function settleInstall(array $run): bool
    {
        $age = \time() - (int) \strtotime((string) $run['started_at'] . ' UTC');

        try {
            $reply = $this->agent->call('updates.panel_status', [], timeout: 15.0);
        } catch (AgentUnavailable) {
            // The installer restarts the agent: unreachable for a moment is expected.
            if ($age > self::LOST_AFTER) {
                $this->fail($run, 'Operations.updates_error_lost');

                return true;
            }

            return false;
        }

        $st = $reply->result;

        if (!$reply->done() || ($st['target'] ?? null) !== $run['target']) {
            if ($age > self::LOST_AFTER) {
                $this->fail($run, 'Operations.updates_error_lost');

                return true;
            }

            return false;
        }

        $state = (string) ($st['state'] ?? '');

        if ($state === 'done') {
            $this->table()->where('id', (int) $run['id'])->update(['state' => self::DONE, 'result' => \json_encode($st, \JSON_THROW_ON_ERROR), 'finished_at' => \gmdate('Y-m-d H:i:s')]);
            $this->audit->record('updates.upgraded', null, null, 'server', AuditLog::OK, ['from' => (string) ($st['from'] ?? ''), 'to' => (string) $run['target']]);
            $this->announce('upgraded', 'LPanel ' . (string) $run['target'] . ' is installed');

            return true;
        }

        if ($state === 'rolled_back' || $state === 'failed') {
            $this->table()->where('id', (int) $run['id'])->update(['result' => \json_encode($st, \JSON_THROW_ON_ERROR)]);
            $this->fail($this->table()->where('id', (int) $run['id'])->first() ?? $run, (string) ($st['error'] ?? 'Operations.error_failed'));

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $run */
    private function fail(array $run, string $why): void
    {
        $this->table()->where('id', (int) $run['id'])->update(['state' => self::FAILED, 'error' => \mb_substr($why, 0, 500), 'finished_at' => \gmdate('Y-m-d H:i:s')]);

        if ($run['kind'] !== 'check') {
            $this->audit->record($run['kind'] === 'panel' ? 'updates.upgraded' : 'updates.applied', null, null, 'server', AuditLog::FAILED, ['scope' => (string) $run['scope'], 'target' => (string) $run['target'], 'error' => $why]);
            $this->announce('failed', ($run['kind'] === 'panel' ? 'the upgrade to LPanel ' . (string) $run['target'] : 'the update (' . (string) $run['scope'] . ')') . ' failed: ' . $why);
        }
    }

    /**
     * A check found something the previous one did not: security updates, a
     * new release of LPanel, a reboot. Said once, not every day.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $found
     */
    private function announceFound(array $run, array $found): void
    {
        $before = $this->table()->where('kind', 'check')->where('state', self::DONE)->where('id', '<', (int) $run['id'])->orderByDesc('id')->first();
        $old = $before === null ? [] : (\json_decode((string) $before['result'], true) ?: []);
        $latest = (string) (((array) ($found['panel'] ?? []))['latest'] ?? '');
        $newer = (bool) (((array) ($found['panel'] ?? []))['newer'] ?? false);

        if ((int) ($found['security'] ?? 0) > 0 && (int) ($found['security'] ?? 0) !== (int) ($old['security'] ?? 0)) {
            $this->announce('available', (int) $found['security'] . ' security updates are waiting');
        }

        if ($newer && $latest !== (string) (((array) ($old['panel'] ?? []))['latest'] ?? '')) {
            $this->announce('available', 'LPanel ' . $latest . ' is out');
        }
    }

    private function announce(string $kind, string $detail): void
    {
        $this->hooks->do('ops.update_event', $kind, $detail);
    }

    /** @return array<string, mixed>|null */
    private function last(string $kind, ?string $state = null): ?array
    {
        $q = $this->table()->where('kind', $kind);

        if ($state !== null) {
            $q = $q->where('state', $state);
        }

        return $q->orderByDesc('id')->first();
    }

    private function scheduledSince(string $kind, ?string $scope, string $since): bool
    {
        $q = $this->table()->where('kind', $kind)->where('started_by', 'schedule')->where('started_at', '>=', $since);

        if ($scope !== null) {
            $q = $q->where('scope', $scope);
        }

        return $q->first() !== null;
    }

    private static function who(?Identity $actor): string
    {
        if ($actor === null) {
            return 'schedule';
        }

        $name = $actor->attribute('username');

        return \is_string($name) ? $name : (string) $actor->id;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $r = \json_decode((string) ($row['result'] ?? ''), true);
        $row['result'] = \is_array($r) ? $r : [];

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ops_update_runs');
    }
}
