<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\Outcome;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Restores (M8b), carried out by the agent in the background and followed
 * here (backup:settle, every minute), like runs:
 *
 *  - files, mailbox, database: part of an account on this server put back
 *    from one of its snapshots, in place or (files) into restored-<time>/;
 *    by the administrator, or by the account's owner in the User panel;
 *  - account: all of an account on this server put back (its home and its
 *    databases), or an account that is not here made again from its
 *    snapshot (AccountImport) and then filled;
 *  - download: an archive of a snapshot, a folder or a database, put in
 *    the home's lpanel-backups/ for the owner to take.
 *
 * One at a time per account; the agent does one at a time on the server.
 */
final class BackupRestores
{
    public const CREATING = 'creating';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public const KINDS = ['files', 'mailbox', 'database', 'account', 'download'];

    /** A restore the agent has not heard of after this long is given up. */
    private const LOST_AFTER = 600;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Destinations $destinations,
        private readonly BackupSettings $settings,
        private readonly AccountImport $import,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly HookEngine $hooks,
    ) {}

    /**
     * The destination restores come from when none is chosen: the schedule's.
     *
     * @return array<string, mixed>|null
     */
    public function defaultDestination(): ?array
    {
        $id = $this->settings->get()['destination_id'];

        return $id === 0 ? null : $this->destinations->find($id);
    }

    // -----------------------------------------------------------------------
    // What a snapshot holds

    /**
     * One folder of an account's snapshot, with the databases it holds at
     * the top.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} the listing, or why not
     */
    public function files(int $destinationId, string $snapshot, string $username, string $path = ''): array
    {
        $path = self::path($path);

        if ($path === null) {
            return [null, 'Backups.error_path_in_home'];
        }

        return $this->ask('backup.files', ['destination' => Destinations::agentId($destinationId), 'snapshot' => $snapshot, 'username' => $username, 'path' => $path]);
    }

    /**
     * The panel's description of the account in a snapshot.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function meta(int $destinationId, string $snapshot): array
    {
        return $this->ask('backup.meta', ['destination' => Destinations::agentId($destinationId), 'snapshot' => $snapshot]);
    }

    /**
     * The account's mailboxes, as the paths their mail is kept under.
     *
     * @return list<array{address: string, path: string}>
     */
    public function mailboxes(int $accountId): array
    {
        $db = $this->connections->connection();
        $domains = [];

        foreach ($db->table('mail_domains')->where('account_id', $accountId)->get() as $d) {
            $domains[(int) $d['id']] = (string) $d['name'];
        }

        $out = [];

        foreach ($db->table('mail_boxes')->where('account_id', $accountId)->orderBy('local')->get() as $box) {
            $domain = $domains[(int) $box['mail_domain_id']] ?? null;

            if ($domain !== null) {
                $out[] = ['address' => $box['local'] . '@' . $domain, 'path' => 'mail/' . $domain . '/' . $box['local']];
            }
        }

        \usort($out, static fn(array $a, array $b): int => \strcmp($a['address'], $b['address']));

        return $out;
    }

    /**
     * The account's databases, as the panel has them.
     *
     * @return list<string>
     */
    public function databases(int $accountId): array
    {
        return \array_values(\array_map(
            static fn(array $r): string => (string) $r['name'],
            $this->connections->connection()->table('db_databases')->where('account_id', $accountId)->orderBy('name')->get(),
        ));
    }

    // -----------------------------------------------------------------------
    // Putting things back

    /**
     * Put part (or all) of an account on this server back from one of its
     * snapshots.
     *
     * @param array<string, mixed> $account
     * @param list<string>         $paths     in the home ('' is all of it)
     * @param list<string>         $databases the account's, by name
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} the restore, or why not
     */
    public function restore(?Identity $actor, ?Request $request, array $account, int $destinationId, string $snapshot, string $kind, array $paths, array $databases, bool $folder = false, bool $byOwner = false): array
    {
        $accountId = (int) $account['id'];
        $destination = $this->destinations->find($destinationId);
        $clean = [];

        foreach ($paths as $p) {
            $c = self::path($p);

            if ($c === null) {
                return [null, 'Backups.error_path_in_home'];
            }

            $clean[] = $c;
        }

        $clean = \array_values(\array_unique($clean));
        $databases = \array_values(\array_unique($databases));
        $why = match (true) {
            $destination === null => 'Backups.error_destination',
            \preg_match('/^[0-9a-f]{8,64}$/', $snapshot) !== 1 => 'Backups.error_snapshot',
            !\in_array($kind, ['files', 'mailbox', 'database', 'account'], true) => 'Backups.error_failed',
            !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true) => 'Backups.error_account_status',
            $kind === 'files' && ($clean === [] || $databases !== []) => 'Backups.error_nothing',
            $kind === 'mailbox' && ($clean === [] || $databases !== [] || \array_diff($clean, \array_column($this->mailboxes($accountId), 'path')) !== []) => 'Backups.error_mailbox',
            $kind === 'database' && ($databases === [] || $clean !== []) => 'Backups.error_nothing',
            \array_diff($databases, $this->databases($accountId)) !== [] => 'Backups.error_database',
            \count($clean) > 100 || \count($databases) > 100 => 'Backups.error_too_many',
            $this->active($accountId) !== null => 'Backups.error_restore_busy',
            default => null,
        };

        if ($why === null && $kind === 'account') {
            // All of it: the home, and each of its databases the snapshot has.
            [$listing, $why] = $this->files($destinationId, $snapshot, (string) $account['username']);
            $clean = [''];
            $databases = \array_values(\array_intersect($this->databases($accountId), \is_array($listing['databases'] ?? null) ? $listing['databases'] : []));
            $folder = false;
        }

        if ($why !== null || $destination === null) {
            $this->audit->record('backup.restore', $actor, $request, (string) $account['username'], AuditLog::DENIED, ['kind' => $kind, 'snapshot' => $snapshot, 'reason' => $why]);

            return [null, $why];
        }

        $what = ['paths' => $clean, 'databases' => $databases, 'folder' => $folder];
        $id = $this->insert($actor, $accountId, (string) $account['username'], $destinationId, $snapshot, $kind, $what, self::RUNNING, $byOwner);
        $this->audit->record('backup.restore', $actor, $request, (string) $account['username'], AuditLog::OK, [
            'kind' => $kind, 'snapshot' => $snapshot, 'destination' => $destination['name'], 'paths' => \implode(', ', \array_map(static fn(string $p): string => $p === '' ? '(all)' : $p, $clean)),
            'databases' => \implode(', ', $databases), 'folder' => $folder, 'by_owner' => $byOwner,
        ]);

        return $this->send($id);
    }

    /**
     * Put an archive of a snapshot, a folder of it, or one of its databases
     * in the home's lpanel-backups/.
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function download(?Identity $actor, ?Request $request, array $account, int $destinationId, string $snapshot, string $path = '', string $database = '', bool $byOwner = false): array
    {
        $accountId = (int) $account['id'];
        $destination = $this->destinations->find($destinationId);
        $clean = self::path($path);
        $why = match (true) {
            $destination === null => 'Backups.error_destination',
            \preg_match('/^[0-9a-f]{8,64}$/', $snapshot) !== 1 => 'Backups.error_snapshot',
            !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true) => 'Backups.error_account_status',
            $clean === null => 'Backups.error_path_in_home',
            $database !== '' && ($clean !== '' || !\in_array($database, $this->databases($accountId), true)) => 'Backups.error_database',
            $this->active($accountId) !== null => 'Backups.error_restore_busy',
            default => null,
        };

        if ($why !== null || $destination === null || $clean === null) {
            $this->audit->record('backup.download', $actor, $request, (string) $account['username'], AuditLog::DENIED, ['snapshot' => $snapshot, 'reason' => $why]);

            return [null, $why];
        }

        $what = ['path' => $clean, 'database' => $database];
        $id = $this->insert($actor, $accountId, (string) $account['username'], $destinationId, $snapshot, 'download', $what, self::RUNNING, $byOwner);
        $this->audit->record('backup.download', $actor, $request, (string) $account['username'], AuditLog::OK, [
            'snapshot' => $snapshot, 'destination' => $destination['name'], 'path' => $clean === '' ? ($database === '' ? '(all)' : '') : $clean,
            'database' => $database, 'by_owner' => $byOwner,
        ]);

        return $this->send($id);
    }

    /**
     * Whether the account in a snapshot can be made again here, and how.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: list<array{0: string, 1: array<string, string>}>} the plan with the snapshot and its description, or why not, and what stands in the way
     */
    public function planAccount(int $destinationId, string $snapshot, ?int $packageId = null): array
    {
        [$meta, $why] = $this->meta($destinationId, $snapshot);

        if ($meta === null || !\is_array($meta['meta'] ?? null)) {
            return [null, $why ?? 'Backups.import_unreadable', []];
        }

        $planned = $this->import->plan($meta['meta'], $packageId);

        if ($planned['plan'] === null) {
            return [null, 'Backups.import_problems', $planned['problems']];
        }

        return [$planned['plan'] + ['snapshot' => $meta['snapshot'] ?? [], 'meta' => $meta['meta']], null, []];
    }

    /**
     * Make an account that is not on this server again from one of its
     * snapshots, then put its files and databases back.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: list<array{0: string, 1: array<string, string>}>}
     */
    public function recreate(?Identity $actor, ?Request $request, int $destinationId, string $snapshot, ?int $packageId = null): array
    {
        $destination = $this->destinations->find($destinationId);

        if ($destination === null || \preg_match('/^[0-9a-f]{8,64}$/', $snapshot) !== 1) {
            return [null, $destination === null ? 'Backups.error_destination' : 'Backups.error_snapshot', []];
        }

        [$plan, $why, $problems] = $this->planAccount($destinationId, $snapshot, $packageId);

        if ($plan === null) {
            $this->audit->record('backup.account_restore', $actor, $request, $snapshot, AuditLog::DENIED, ['destination' => $destination['name'], 'reason' => $why] + self::problemDetails($problems));

            return [null, $why, $problems];
        }

        $username = (string) $plan['username'];
        $full = (string) (\is_array($plan['snapshot']) ? ($plan['snapshot']['id'] ?? $snapshot) : $snapshot);
        $id = $this->insert($actor, null, $username, $destinationId, $full, 'account', ['paths' => [''], 'databases' => [], 'folder' => false, 'recreate' => true], self::CREATING, false);
        $this->audit->record('backup.account_restore', $actor, $request, $username, AuditLog::OK, [
            'snapshot' => $full, 'destination' => $destination['name'], 'domain' => $plan['domain'], 'package' => $plan['package_name'],
            'package_made' => $plan['make_package'], 'reseller' => $plan['owner_username'],
        ]);

        [$accountId, $outcome] = $this->import->create($actor, $request, \is_array($plan['meta']) ? $plan['meta'] : [], $plan, ['snapshot' => $full]);

        if ($outcome->status === Outcome::FAILED) {
            $this->finish($this->find($id) ?? [], [], 'Backups.error_create: ' . $outcome->code . ' ' . $outcome->message);

            return [$this->find($id), 'Backups.error_create', []];
        }

        $this->table()->where('id', $id)->update(['account_id' => $accountId]);

        if ($outcome->status === Outcome::DONE) {
            $this->filled($id);
        }

        return [$this->find($id), null, []];
    }

    /**
     * Ask about restores in flight: accounts being made, and the agent's
     * work. Returns how many finished.
     */
    public function settle(): int
    {
        $finished = 0;

        foreach ($this->table()->whereIn('state', [self::CREATING, self::RUNNING])->get() as $row) {
            if ($row['state'] === self::CREATING) {
                $account = $row['account_id'] === null ? null : $this->accounts->byId((int) $row['account_id']);

                if ($account !== null && $account['status'] === Accounts::ACTIVE) {
                    $this->filled((int) $row['id']);
                } elseif ($account === null || $account['status'] === Accounts::FAILED) {
                    $this->finish($row, [], 'Backups.error_create' . ($account !== null && $account['op_error'] !== null ? ': ' . $account['op_error'] : ''));
                    $finished++;
                }

                continue;
            }

            try {
                $reply = $this->agent->status((string) $row['op_id']);
            } catch (AgentUnavailable) {
                continue;
            }

            if ($reply->status === 'accepted' || $reply->status === 'running') {
                continue;
            }

            if ($reply->done()) {
                $this->finish($row, $reply->result, null);
                $finished++;
            } elseif ($reply->errorCode === 'not_found') {
                // Never reached the agent: sent again (the same id runs once), then given up.
                if (\strtotime((string) $row['created_at'] . ' UTC') < \time() - self::LOST_AFTER) {
                    $this->finish($row, [], 'Backups.error_lost');
                    $finished++;
                } else {
                    $this->send((int) $row['id']);
                }
            } else {
                $this->finish($row, [], self::why($reply));
                $finished++;
            }
        }

        return $finished;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : self::decode($row);
    }

    /**
     * The newest restores and downloads: of one account, or all.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?int $accountId = null, int $limit = 20, ?string $username = null): array
    {
        $query = $this->table();

        if ($accountId !== null) {
            $query = $query->where('account_id', $accountId);
        } elseif ($username !== null) {
            $query = $query->where('username', $username);
        }

        return \array_map(self::decode(...), $query->orderBy('id', 'desc')->limit($limit)->get());
    }

    /** @return array<string, mixed>|null the account's restore in flight */
    public function active(int $accountId): ?array
    {
        $row = $this->table()->where('account_id', $accountId)->whereIn('state', [self::CREATING, self::RUNNING])->first();

        return $row === null ? null : self::decode($row);
    }

    /**
     * A path in a home as the panel takes it: no way out, nothing restic
     * would read as a pattern. '' is all of it; null is not a path.
     */
    public static function path(string $path): ?string
    {
        $p = \trim(\trim($path), '/');

        if ($p === '' || $p === '.') {
            return '';
        }

        if (\strlen($p) > 1024 || \preg_match('/[*?\[\]\\\\\x00-\x1f]/', $p) === 1) {
            return null;
        }

        $parts = [];

        foreach (\explode('/', $p) as $part) {
            if ($part === '..') {
                return null;
            }

            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return \implode('/', $parts);
    }

    // -----------------------------------------------------------------------

    /**
     * The account made again is on the server: each module applies its
     * rows, then the files and databases go back.
     */
    private function filled(int $id): void
    {
        $row = $this->find($id);
        $account = $row === null || $row['account_id'] === null ? null : $this->accounts->byId((int) $row['account_id']);

        if ($row === null || $account === null) {
            return;
        }

        $this->hooks->do('accounts.changed', 'account.restore', $account);
        $what = $row['what'];
        $what['databases'] = $this->databases((int) $account['id']);
        [$listing] = $this->files((int) $row['destination_id'], (string) $row['snapshot'], (string) $account['username']);

        if (\is_array($listing['databases'] ?? null)) {
            $what['databases'] = \array_values(\array_intersect($what['databases'], $listing['databases']));
        }

        $this->table()->where('id', $id)->update([
            'state' => self::RUNNING,
            'what' => \json_encode($what, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'op_id' => Protocol::newId(),
        ]);
        $this->send($id);
    }

    /**
     * Ask the agent to do a restore or download recorded as running.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function send(int $id): array
    {
        $row = $this->find($id) ?? throw new \LogicException('restore vanished');
        $what = $row['what'];
        $base = ['destination' => Destinations::agentId((int) $row['destination_id']), 'snapshot' => (string) $row['snapshot'], 'username' => (string) $row['username']];
        [$op, $args] = $row['kind'] === 'download'
            ? ['backup.download', $base + ['path' => (string) ($what['path'] ?? ''), 'database' => (string) ($what['database'] ?? '')]]
            : ['backup.restore', $base + ['paths' => $what['paths'] ?? [], 'databases' => $what['databases'] ?? [], 'folder' => (bool) ($what['folder'] ?? false)]];

        try {
            $reply = $this->agent->call($op, $args, id: (string) $row['op_id'], timeout: 60.0);
        } catch (AgentUnavailable) {
            // backup:settle asks again; the agent says not_found until it is reached.
            return [$this->find($id), null];
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return [$this->find($id), null];
        }

        if ($reply->done()) {
            $this->finish($row, $reply->result, null);

            return [$this->find($id), null];
        }

        $why = self::why($reply);
        $this->finish($row, [], $why);

        return [$this->find($id), $why];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $result
     */
    private function finish(array $row, array $result, ?string $error): void
    {
        if (!isset($row['id'])) {
            return;
        }

        $state = $error === null ? self::DONE : self::FAILED;
        $this->table()->where('id', (int) $row['id'])->update([
            'state' => $state,
            'result' => $result === [] ? null : \json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'error' => $error,
            'finished_at' => Accounts::now(),
        ]);

        $details = ['restore' => (int) $row['id'], 'kind' => $row['kind'], 'snapshot' => $row['snapshot'], 'state' => $state]
            + ($error === null ? [] : ['error' => \mb_substr($error, 0, 300)])
            + \array_filter([
                'files' => $result['files'] ?? null,
                'databases' => \is_array($result['databases'] ?? null) ? \implode(', ', $result['databases']) : null,
                'folder' => $result['folder'] ?? null,
                'file' => $result['file'] ?? null,
            ], static fn(mixed $v): bool => $v !== null && $v !== '');
        $this->audit->record($row['kind'] === 'download' ? 'backup.download_ready' : 'backup.restored', null, null, (string) $row['username'], $state === self::DONE ? AuditLog::OK : AuditLog::FAILED, $details);

        // Files back in the home: the modules write what they keep there
        // again (an account made again already did this).
        if ($state === self::DONE && $row['kind'] === 'account' && $row['account_id'] !== null && !((self::decode($row)['what']['recreate'] ?? false))) {
            $account = $this->accounts->byId((int) $row['account_id']);

            if ($account !== null) {
                $this->hooks->do('accounts.changed', 'account.restore', $account);
            }
        }
    }

    /**
     * @param array<string, mixed> $what
     */
    private function insert(?Identity $actor, ?int $accountId, string $username, int $destinationId, string $snapshot, string $kind, array $what, string $state, bool $byOwner): int
    {
        return (int) $this->table()->insert([
            'account_id' => $accountId,
            'username' => $username,
            'destination_id' => $destinationId,
            'snapshot' => $snapshot,
            'kind' => $kind,
            'what' => \json_encode($what, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'state' => $state,
            'op_id' => $state === self::RUNNING ? Protocol::newId() : null,
            'requested_by' => $actor === null ? 'cli' : (string) ($actor->attribute('username') ?? $actor->id),
            'by_owner' => $byOwner,
            'created_at' => Accounts::now(),
        ], 'id');
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     *
     * @param array<string, mixed> $args
     */
    private function ask(string $op, array $args): array
    {
        try {
            $reply = $this->agent->call($op, $args, timeout: 130.0);
        } catch (AgentUnavailable) {
            return [null, 'Backups.error_unreachable'];
        }

        return $reply->done() ? [$reply->result, null] : [null, self::why($reply)];
    }

    private static function why(AgentReply $reply): string
    {
        return match ($reply->errorCode) {
            'backup_off' => 'Backups.error_off',
            'backup_busy' => 'Backups.error_restore_busy',
            'backup_not_in_snapshot' => 'Backups.error_not_in_snapshot',
            'backup_no_destination' => 'Backups.error_destination',
            'backup_wrong_password' => 'Backups.error_wrong_password',
            default => (string) ($reply->errorMessage ?? 'Backups.error_failed'),
        };
    }

    /**
     * @param list<array{0: string, 1: array<string, string>}> $problems
     *
     * @return array<string, string>
     */
    private static function problemDetails(array $problems): array
    {
        $out = [];

        foreach ($problems as $i => [$key, $values]) {
            $out['problem_' . ($i + 1)] = $key . ($values === [] ? '' : ' ' . \implode(', ', $values));
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
        if (\is_array($row['what'] ?? null)) {
            return $row;
        }

        $what = \json_decode((string) ($row['what'] ?? ''), true);
        $result = \json_decode((string) ($row['result'] ?? ''), true);
        $row['what'] = \is_array($what) ? $what : [];
        $row['result'] = \is_array($result) ? $result : [];

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('backup_restores');
    }
}
