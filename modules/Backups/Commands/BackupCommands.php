<?php

declare(strict_types=1);

namespace App\Modules\Backups\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Backups\BackupRestores;
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\BackupSettings;
use App\Modules\Backups\Destinations;
use App\Modules\Identity\PanelUsers;

/**
 * backup:* from the shell, as root: the same actions as the Admin panel and
 * the API. Secrets (an S3 or B2 key) are read from standard input, never
 * taken as arguments.
 */
final class BackupCommands
{
    public function __construct(
        private readonly Destinations $destinations,
        private readonly BackupSettings $settings,
        private readonly BackupRuns $runs,
        private readonly BackupRestores $restores,
        private readonly Accounts $accounts,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    public function destinations(Output $output): int
    {
        $output->line(\sprintf('%-4s %-20s %-6s %-48s %s', 'ID', 'NAME', 'TYPE', 'WHERE', 'SNAPSHOTS'));

        foreach ($this->destinations->all() as $d) {
            $output->line(\sprintf('%-4d %-20s %-6s %-48s %d', (int) $d['id'], (string) $d['name'], (string) $d['type'], Destinations::describe($d), (int) $d['snapshots']));

            if ($d['public_key'] !== null) {
                $output->line('     key to authorize on the server: ' . $d['public_key']);
            }
        }

        return 0;
    }

    /**
     * Add a destination. For s3 and b2, standard input holds the access key
     * and the secret on two lines.
     */
    public function add(Output $output, string $name, string $type, ?string $path = null, ?string $host = null, ?string $port = null, ?string $user = null,
        ?string $endpoint = null, ?string $scheme = null, ?string $region = null, ?string $bucket = null, ?string $prefix = null, bool $existing = false): int
    {
        $input = \array_filter(\compact('name', 'type', 'path', 'host', 'port', 'user', 'endpoint', 'scheme', 'region', 'bucket', 'prefix'), static fn(?string $v): bool => $v !== null);
        $lines = \in_array($type, Destinations::SECRET_TYPES, true) || $existing ? self::stdinLines() : [];

        if (\in_array($type, Destinations::SECRET_TYPES, true)) {
            $input['access_key'] = \array_shift($lines) ?? '';
            $input['secret_key'] = \array_shift($lines) ?? '';
        }

        if ($existing) {
            $input['password'] = \array_shift($lines) ?? '';

            if ($input['password'] === '') {
                return $this->fail($output, 'Give the repository\'s password on standard input (lpa backup-password on the server that made it).');
            }
        }

        [$d, $errors, $why] = $this->destinations->save($this->root(), null, $input);

        return $this->report($output, $d, $errors, $why, 'Destination %s added.');
    }

    /** Change a destination; with --secrets, new keys on standard input. */
    public function change(Output $output, string $destination, ?string $name = null, ?string $path = null, ?string $host = null, ?string $port = null, ?string $user = null,
        ?string $endpoint = null, ?string $scheme = null, ?string $region = null, ?string $bucket = null, ?string $prefix = null, bool $secrets = false): int
    {
        $current = $this->destinations->find($destination);

        if ($current === null) {
            return $this->fail($output, \sprintf('There is no destination %s.', $destination));
        }

        $input = \array_filter(\compact('name', 'path', 'host', 'port', 'user', 'endpoint', 'scheme', 'region', 'bucket', 'prefix'), static fn(?string $v): bool => $v !== null);

        if ($secrets) {
            [$input['access_key'], $input['secret_key']] = self::secrets();
        }

        [$d, $errors, $why] = $this->destinations->save($this->root(), null, $input, $current);

        return $this->report($output, $d, $errors, $why, 'Destination %s changed.');
    }

    public function remove(Output $output, string $destination, bool $yes = false): int
    {
        $d = $this->destinations->find($destination);

        if ($d === null) {
            return $this->fail($output, \sprintf('There is no destination %s.', $destination));
        }

        if ($this->settings->get()['destination_id'] === (int) $d['id']) {
            return $this->fail($output, $this->localization->get('Backups.error_in_use'));
        }

        if (!$yes) {
            return $this->fail($output, 'The repository password goes with it: keep it first (lpa backup-password d' . $d['id'] . '), then add --yes.');
        }

        $why = $this->destinations->remove($this->root(), null, $d);

        return $why === null ? $this->ok($output, 'Destination removed; what was backed up there stays there.') : $this->fail($output, $this->text($why));
    }

    public function settings(Output $output, ?string $enabled = null, ?string $time = null, ?string $destination = null,
        ?string $daily = null, ?string $weekly = null, ?string $monthly = null, ?string $pruneDay = null): int
    {
        $input = \array_filter([
            'enabled' => $enabled, 'time' => $time, 'keep_daily' => $daily, 'keep_weekly' => $weekly, 'keep_monthly' => $monthly, 'prune_day' => $pruneDay,
        ], static fn(?string $v): bool => $v !== null);

        if ($destination !== null) {
            $d = $this->destinations->find($destination);
            $input['destination_id'] = $d === null ? 'x' : (string) $d['id'];
        }

        if ($input !== []) {
            $errors = $this->settings->save($this->root(), null, $input, $this->destinations->ids());

            if ($errors !== []) {
                foreach ($errors as $field => $key) {
                    $output->error($field . ': ' . $this->localization->get($key));
                }

                return 1;
            }
        }

        $s = $this->settings->get();
        $d = $this->destinations->find($s['destination_id']);
        $output->line(\sprintf('%-12s %s', 'schedule', $s['enabled'] ? 'every day at ' . $s['time'] : 'off'));
        $output->line(\sprintf('%-12s %s', 'destination', $d === null ? '-' : $d['name']));
        $output->line(\sprintf('%-12s %d daily, %d weekly, %d monthly', 'keep', $s['keep_daily'], $s['keep_weekly'], $s['keep_monthly']));

        return 0;
    }

    /** Back up now: every account, or those named. */
    public function run(Output $output, ?string $accounts = null, ?string $destination = null, bool $wait = false): int
    {
        $d = null;

        if ($destination !== null && ($d = $this->destinations->find($destination)) === null) {
            return $this->fail($output, \sprintf('There is no destination %s.', $destination));
        }

        $names = $accounts === null ? null : \array_values(\array_filter(\array_map('trim', \explode(',', $accounts))));
        [$run, $why] = $this->runs->start($this->root(), null, 'manual', $d === null ? null : (int) $d['id'], $names);

        if ($run === null || $why !== null) {
            return $this->fail($output, $this->text((string) $why));
        }

        $output->line(\sprintf('Run %d started: %d account(s).', (int) $run['id'], (int) $run['accounts']));

        if (!$wait) {
            return 0;
        }

        for ($i = 0; $i < 86400; $i += 2) {
            $this->runs->settle();
            $run = $this->runs->find((int) $run['id']) ?? $run;

            if ($run['state'] !== BackupRuns::RUNNING) {
                break;
            }

            \sleep(2);
        }

        return $this->showRun($output, $run);
    }

    public function runs(Output $output): int
    {
        $output->line(\sprintf('%-5s %-10s %-8s %-20s %8s %8s', 'RUN', 'KIND', 'STATE', 'STARTED (UTC)', 'OK', 'FAILED'));

        foreach ($this->runs->recent() as $r) {
            $output->line(\sprintf('%-5d %-10s %-8s %-20s %8d %8d', (int) $r['id'], (string) $r['kind'], (string) $r['state'], (string) $r['started_at'], (int) $r['ok'], (int) $r['failed']));
        }

        return 0;
    }

    public function snapshots(Output $output, string $username, ?string $destination = null): int
    {
        $d = $this->destinations->find($destination ?? $this->settings->get()['destination_id']);

        if ($d === null) {
            return $this->fail($output, 'Name a destination (--destination), or set the schedule\'s.');
        }

        $list = $this->runs->snapshots((int) $d['id'], $username);

        if ($list === null) {
            return $this->fail($output, $this->localization->get('Backups.error_snapshots'));
        }

        $output->line(\sprintf('%-10s %-26s %10s %14s', 'SNAPSHOT', 'TIME', 'FILES', 'SIZE'));

        foreach ($list as $s) {
            $output->line(\sprintf('%-10s %-26s %10d %14s', (string) ($s['short_id'] ?? ''), (string) ($s['time'] ?? ''), (int) ($s['files'] ?? 0), self::size((int) ($s['bytes'] ?? 0))));
        }

        return 0;
    }

    public function accounts(Output $output, ?string $destination = null): int
    {
        $d = $this->destinations->find($destination ?? $this->settings->get()['destination_id']);

        if ($d === null) {
            return $this->fail($output, 'Name a destination (--destination), or set the schedule\'s.');
        }

        $list = $this->runs->snapshots((int) $d['id']);

        if ($list === null) {
            return $this->fail($output, $this->localization->get('Backups.error_snapshots'));
        }

        $seen = [];

        foreach ($list as $s) {
            $name = (string) ($s['username'] ?? '');
            $seen[$name] ??= ['latest' => $s, 'count' => 0];
            $seen[$name]['count']++;
        }

        \ksort($seen);
        $output->line(\sprintf('%-16s %-10s %-26s %9s  %s', 'ACCOUNT', 'LATEST', 'TIME', 'SNAPSHOTS', 'HERE'));

        foreach ($seen as $name => $a) {
            $output->line(\sprintf('%-16s %-10s %-26s %9d  %s', $name, (string) $a['latest']['short_id'], (string) $a['latest']['time'], $a['count'], $this->accounts->usernameTaken($name) ? 'yes' : 'no'));
        }

        return 0;
    }

    public function files(Output $output, string $username, string $snapshot, ?string $path = null, ?string $destination = null): int
    {
        $d = $this->destinations->find($destination ?? $this->settings->get()['destination_id']);

        if ($d === null) {
            return $this->fail($output, 'Name a destination (--destination), or set the schedule\'s.');
        }

        [$listing, $why] = $this->restores->files((int) $d['id'], self::snapshotId($snapshot, $this->runs->snapshots((int) $d['id'], $username)), $username, $path ?? '');

        if ($listing === null) {
            return $this->fail($output, $this->text((string) $why));
        }

        foreach (\is_array($listing['entries'] ?? null) ? $listing['entries'] : [] as $e) {
            $output->line(\sprintf('%-8s %12s  %-20s %s', (string) $e['type'], $e['type'] === 'file' ? self::size((int) $e['size']) : '', \substr((string) $e['mtime'], 0, 19), (string) $e['name'] . ($e['type'] === 'dir' ? '/' : '')));
        }

        foreach (\is_array($listing['databases'] ?? null) ? $listing['databases'] : [] as $db) {
            $output->line(\sprintf('%-8s %12s  %-20s %s', 'database', '', '', (string) $db));
        }

        return 0;
    }

    public function restore(Output $output, string $username, string $snapshot, ?string $paths = null, ?string $mailbox = null, ?string $databases = null,
        bool $all = false, bool $folder = false, ?string $destination = null, bool $wait = false): int
    {
        $account = $this->accounts->findByUsername($this->root(), $username);
        $d = $this->destinations->find($destination ?? $this->settings->get()['destination_id']);

        if ($account === null || $d === null) {
            return $this->fail($output, $account === null ? \sprintf('There is no account %s.', $username) : 'Name a destination (--destination), or set the schedule\'s.');
        }

        $list = static fn(?string $s): array => $s === null ? [] : \array_values(\array_filter(\array_map('trim', \explode(',', $s)), static fn(string $v): bool => $v !== ''));
        $p = $list($paths);
        $dbs = $list($databases);
        $kind = match (true) {
            $all => 'account',
            $mailbox !== null => 'mailbox',
            $p === [] && $dbs !== [] => 'database',
            default => 'files',
        };

        if ($mailbox !== null) {
            $box = \array_values(\array_filter($this->restores->mailboxes((int) $account['id']), static fn(array $b): bool => $b['address'] === \strtolower($mailbox)));

            if ($box === []) {
                return $this->fail($output, \sprintf('%s has no mailbox %s.', $username, $mailbox));
            }

            $p = [$box[0]['path']];
        }

        [$row, $why] = $this->restores->restore($this->root(), null, $account, (int) $d['id'], self::snapshotId($snapshot, $this->runs->snapshots((int) $d['id'], $username)), $kind, $p, $dbs, $folder);

        return $this->followRestore($output, $row, $why, $wait);
    }

    public function recreate(Output $output, string $snapshot, ?string $destination = null, ?string $package = null, bool $dryRun = false, bool $wait = false): int
    {
        $d = $this->destinations->find($destination ?? $this->settings->get()['destination_id']);

        if ($d === null) {
            return $this->fail($output, 'Name a destination (--destination), or set the schedule\'s.');
        }

        $snapshot = self::snapshotId($snapshot, $this->runs->snapshots((int) $d['id']));
        $packageId = $package !== null && \ctype_digit($package) ? (int) $package : null;
        [$plan, $why, $problems] = $this->restores->planAccount((int) $d['id'], $snapshot, $packageId);

        if ($plan === null) {
            foreach ($problems as [$key, $values]) {
                $output->error($this->localization->get($key, $values));
            }

            return $this->fail($output, $this->text((string) $why));
        }

        $output->line(\sprintf(
            'Account %s (%s), package %s%s%s.',
            (string) $plan['username'],
            (string) $plan['domain'],
            (string) $plan['package_name'],
            $plan['make_package'] ? ' (made from the snapshot)' : '',
            $plan['owner_username'] !== null ? ', reseller ' . $plan['owner_username'] : '',
        ));

        if ($dryRun) {
            return 0;
        }

        [$row, $why] = $this->restores->recreate($this->root(), null, (int) $d['id'], $snapshot, $packageId);

        return $this->followRestore($output, $row, $why, $wait);
    }

    public function download(Output $output, string $username, string $snapshot, ?string $path = null, ?string $database = null, ?string $destination = null, bool $wait = false): int
    {
        $account = $this->accounts->findByUsername($this->root(), $username);
        $d = $this->destinations->find($destination ?? $this->settings->get()['destination_id']);

        if ($account === null || $d === null) {
            return $this->fail($output, $account === null ? \sprintf('There is no account %s.', $username) : 'Name a destination (--destination), or set the schedule\'s.');
        }

        [$row, $why] = $this->restores->download($this->root(), null, $account, (int) $d['id'], self::snapshotId($snapshot, $this->runs->snapshots((int) $d['id'], $username)), $path ?? '', $database ?? '');

        return $this->followRestore($output, $row, $why, $wait);
    }

    public function restores(Output $output, ?string $account = null): int
    {
        $this->restores->settle();
        $output->line(\sprintf('%-5s %-16s %-9s %-9s %-10s %-20s %s', 'ID', 'ACCOUNT', 'KIND', 'STATE', 'SNAPSHOT', 'ASKED (UTC)', 'OUTCOME'));

        foreach ($this->restores->recent(null, 30, $account) as $r) {
            $output->line(\sprintf('%-5d %-16s %-9s %-9s %-10s %-20s %s', (int) $r['id'], (string) $r['username'], (string) $r['kind'], (string) $r['state'], \substr((string) $r['snapshot'], 0, 8), (string) $r['created_at'], $this->outcome($r)));
        }

        return 0;
    }

    /** @param array<string, mixed>|null $row */
    private function followRestore(Output $output, ?array $row, ?string $why, bool $wait): int
    {
        if ($row === null) {
            return $this->fail($output, $this->text((string) $why));
        }

        $output->line(\sprintf('Restore %d: %s.', (int) $row['id'], (string) $row['state']));

        for ($i = 0; $wait && $i < 43200 && \in_array($row['state'], [BackupRestores::CREATING, BackupRestores::RUNNING], true); $i += 2) {
            \sleep(2);
            $this->restores->settle();
            $row = $this->restores->find((int) $row['id']) ?? $row;
        }

        if ($row['state'] === BackupRestores::FAILED) {
            return $this->fail($output, $this->text((string) $row['error']));
        }

        if ($row['state'] === BackupRestores::DONE) {
            $output->success(\sprintf('Restore %d: done. %s', (int) $row['id'], $this->outcome($row)));
        }

        return 0;
    }

    /** @param array<string, mixed> $r */
    private function outcome(array $r): string
    {
        $res = \is_array($r['result'] ?? null) ? $r['result'] : [];

        return match (true) {
            $r['state'] === BackupRestores::FAILED => $this->text((string) $r['error']),
            isset($res['file']) => 'In the home: ' . $res['file'],
            $res !== [] => \sprintf(
                '%d file(s)%s%s',
                (int) ($res['files'] ?? 0),
                ($res['folder'] ?? '') !== '' ? ' into ' . $res['folder'] : '',
                \is_array($res['databases'] ?? null) && $res['databases'] !== [] ? ', databases: ' . \implode(', ', $res['databases']) : '',
            ),
            default => '',
        };
    }

    /**
     * A snapshot's full id from its short one, when the destination lists it.
     *
     * @param list<array<string, mixed>>|null $list
     */
    private static function snapshotId(string $snapshot, ?array $list): string
    {
        foreach ($list ?? [] as $s) {
            if (($s['short_id'] ?? null) === $snapshot || ($s['id'] ?? null) === $snapshot) {
                return (string) $s['id'];
            }
        }

        return $snapshot;
    }

    public function settle(Output $output): int
    {
        $n = $this->runs->settle();
        $r = $this->restores->settle();
        $output->line($n + $r === 0 ? 'Nothing finished.' : \sprintf('%d run(s) and %d restore(s) finished.', $n, $r));

        return 0;
    }

    public function due(Output $output): int
    {
        $output->line($this->runs->due() ? 'The scheduled backup started.' : 'Nothing due.');

        return 0;
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $run */
    private function showRun(Output $output, array $run): int
    {
        foreach ($run['results'] as $r) {
            $output->line(\sprintf('%-16s %s', (string) ($r['username'] ?? '?'), ($r['ok'] ?? false) ? 'ok ' . ($r['snapshot'] ?? '') . (($r['warning'] ?? '') !== '' ? ' (' . $r['warning'] . ')' : '') : 'FAILED: ' . ($r['error'] ?? '')));
        }

        $output->line(\sprintf('Run %d: %s.', (int) $run['id'], (string) $run['state']));

        return $run['state'] === BackupRuns::DONE ? 0 : 1;
    }

    /** @return list<string> */
    private static function stdinLines(): array
    {
        return \array_map('trim', \explode("\n", (string) \stream_get_contents(\STDIN, 8192)));
    }

    /** @return array{0: string, 1: string} */
    private static function secrets(): array
    {
        $lines = \explode("\n", (string) \stream_get_contents(\STDIN, 4096));

        return [\trim($lines[0]), \trim($lines[1] ?? '')];
    }

    /**
     * @param array<string, mixed>|null $d
     * @param array<string, string>     $errors
     */
    private function report(Output $output, ?array $d, array $errors, ?string $why, string $done): int
    {
        if ($errors !== []) {
            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key));
            }

            return 1;
        }

        if ($d === null) {
            return $this->fail($output, $this->text((string) $why));
        }

        $output->success(\sprintf($done, $d['name']));

        if ($d['public_key'] !== null) {
            $output->line('Authorize this key on the SFTP server: ' . $d['public_key']);
        }

        $output->line('Keep the repository password somewhere safe: lpa backup-password d' . $d['id'] . ' (as root).');

        return 0;
    }

    private function text(string $why): string
    {
        return \str_starts_with($why, 'Backups.') ? $this->localization->get($why) : 'The server refused it: ' . $why;
    }

    public static function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;

        while ($n >= 1024 && $i < 4) {
            $n /= 1024;
            $i++;
        }

        return $i === 0 ? $bytes . ' B' : \sprintf('%.1f %s', $n, $units[$i]);
    }

    private function ok(Output $output, string $message): int
    {
        $output->success($message);

        return 0;
    }

    private function fail(Output $output, string $message): int
    {
        $output->error($message);

        return 1;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
