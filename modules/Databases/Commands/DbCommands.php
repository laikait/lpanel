<?php

declare(strict_types=1);

namespace App\Modules\Databases\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Databases\Databases;
use App\Modules\Databases\DbActions;
use App\Modules\Databases\DbOutcome;
use App\Modules\Databases\DbSync;
use App\Modules\Databases\DbUsage;
use App\Modules\Databases\PhpMyAdmin;
use App\Modules\Identity\PanelUsers;

/**
 * db:* from the shell, as root: the same actions as the panels and the API.
 * Passwords are read from standard input, never taken as arguments (which
 * any user on the server could see in the process list).
 */
final class DbCommands
{
    public function __construct(
        private readonly Databases $databases,
        private readonly DbActions $actions,
        private readonly DbSync $sync,
        private readonly DbUsage $usage,
        private readonly PhpMyAdmin $pma,
        private readonly Accounts $accounts,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    /** An account's databases, users and remote hosts. */
    public function list(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $id = (int) $account['id'];
        $state = $this->sync->stateOf($id);
        $output->line(\sprintf('%-40s %12s', 'DATABASE', 'SIZE'));

        foreach ($this->databases->databases($id) as $db) {
            $output->line(\sprintf('%-40s %12s', (string) $db['name'], self::bytes((int) $db['size_bytes'])));
        }

        $output->line();
        $output->line(\sprintf('%-32s %s', 'USER', 'PRIVILEGES'));

        foreach ($this->databases->users($id) as $user) {
            $grants = [];

            foreach ($user['grants'] as $db => $privileges) {
                $grants[] = $db . ' (' . \implode(', ', $privileges) . ')';
            }

            $output->line(\sprintf('%-32s %s', (string) $user['name'], $grants === [] ? '-' : \implode('; ', $grants)));
        }

        $hosts = \array_map(static fn(array $h): string => (string) $h['host'], $this->databases->hosts($id));
        $output->line();
        $output->line('Remote hosts: ' . ($hosts === [] ? 'none' : \implode(', ', $hosts)));
        $output->line('On the server: ' . ($state === null ? 'nothing yet' : $state['state'] . ($state['error'] ? ' (' . $state['error'] . ')' : '')));

        return 0;
    }

    public function create(Output $output, string $username, string $name): int
    {
        $account = $this->account($output, $username);

        return $account === null ? 1 : $this->outcome($output, $this->actions->createDatabase($this->root(), null, $account, $name), 'Database created.');
    }

    public function drop(Output $output, string $username, string $name): int
    {
        $account = $this->account($output, $username);
        $db = $account === null ? null : $this->database($output, $account, $name);

        return $db === null ? 1 : $this->outcome($output, $this->actions->dropDatabase($this->root(), null, $account, $db), \sprintf('Database %s dropped.', $db['name']));
    }

    public function rename(Output $output, string $username, string $from, string $to): int
    {
        $account = $this->account($output, $username);
        $db = $account === null ? null : $this->database($output, $account, $from);

        return $db === null ? 1 : $this->outcome($output, $this->actions->renameDatabase($this->root(), null, $account, $db, $to), 'Database renamed.');
    }

    /** A new user; its password comes on standard input. --grant gives it every privilege on a database. */
    public function userCreate(Output $output, string $username, string $name, ?string $grant = null): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $grants = [];

        if ($grant !== null && $grant !== '') {
            foreach (\explode(',', $grant) as $db) {
                $db = \strtolower(\trim($db));
                $grants[\str_starts_with($db, Databases::prefix($account)) ? $db : Databases::prefix($account) . $db] = ['ALL'];
            }
        }

        return $this->outcome($output, $this->actions->createUser($this->root(), null, $account, $name, self::password(), $grants), 'Database user created.');
    }

    /** A user's new password, from standard input. */
    public function userPassword(Output $output, string $username, string $name): int
    {
        $account = $this->account($output, $username);
        $user = $account === null ? null : $this->user($output, $account, $name);

        return $user === null ? 1 : $this->outcome($output, $this->actions->setPassword($this->root(), null, $account, $user, self::password()), 'Password changed.');
    }

    public function userDrop(Output $output, string $username, string $name): int
    {
        $account = $this->account($output, $username);
        $user = $account === null ? null : $this->user($output, $account, $name);

        return $user === null ? 1 : $this->outcome($output, $this->actions->dropUser($this->root(), null, $account, $user), 'Database user removed.');
    }

    /** What a user may do in a database: ALL (default) or a list of privileges. */
    public function grant(Output $output, string $username, string $user, string $database, ?string $privileges = null): int
    {
        $account = $this->account($output, $username);
        $u = $account === null ? null : $this->user($output, $account, $user);
        $db = $u === null ? null : $this->database($output, $account, $database);

        return $db === null ? 1 : $this->outcome($output, $this->actions->setGrant($this->root(), null, $account, $u, $db, $privileges ?? 'ALL'), 'Privileges set.');
    }

    public function revoke(Output $output, string $username, string $user, string $database): int
    {
        $account = $this->account($output, $username);
        $u = $account === null ? null : $this->user($output, $account, $user);
        $db = $u === null ? null : $this->database($output, $account, $database);

        return $db === null ? 1 : $this->outcome($output, $this->actions->setGrant($this->root(), null, $account, $u, $db, null), 'Privileges taken away.');
    }

    public function hostAdd(Output $output, string $username, string $host, ?string $note = null): int
    {
        $account = $this->account($output, $username);

        return $account === null ? 1 : $this->outcome($output, $this->actions->addHost($this->root(), null, $account, $host, $note ?? ''), 'Remote host added.');
    }

    public function hostRemove(Output $output, string $username, string $host): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        [$clean] = Databases::checkHost($host);

        foreach ($this->databases->hosts((int) $account['id']) as $row) {
            if ($row['host'] === $clean || $row['host'] === \strtolower(\trim($host))) {
                return $this->outcome($output, $this->actions->removeHost($this->root(), null, $account, $row), 'Remote host removed.');
            }
        }

        $output->error(\sprintf('%s has no remote host %s.', $username, $host));

        return 1;
    }

    /** Measure every account's databases and fold them into the quotas. */
    public function usage(Output $output): int
    {
        $result = $this->usage->refresh();

        if ($result === null) {
            $output->error('The agent could not be asked.');

            return 1;
        }

        $output->success(\sprintf('%d databases measured; %d quotas changed.', $result['measured'], $result['quotas']));

        return 0;
    }

    /** Send database changes the agent has not answered yet, and remove stale phpMyAdmin logins. */
    public function settle(Output $output): int
    {
        $counts = $this->sync->settleAll();
        $expired = $this->pma->expire();
        $parts = [];

        foreach ($counts as $status => $n) {
            $parts[] = $n . ' ' . $status;
        }

        $output->line(($parts === [] ? 'Nothing waiting.' : 'Sent: ' . \implode(', ', $parts) . '.') . ($expired > 0 ? \sprintf(' %d phpMyAdmin logins removed.', $expired) : ''));

        return 0;
    }

    /** Send accounts' databases and users to the server again, as the panel has them. */
    public function sync(Output $output, ?string $username = null, bool $all = false): int
    {
        $rows = [];

        if ($all) {
            $rows = $this->accounts->withSites();
        } elseif ($username !== null) {
            $row = $this->account($output, $username);

            if ($row === null) {
                return 1;
            }

            $rows[] = $row;
        } else {
            $output->error('Name an account, or --all.');

            return 2;
        }

        $failed = 0;

        foreach ($rows as $row) {
            $outcome = $this->sync->touch((int) $row['id'], force: true);
            $output->line(\sprintf('%-16s %s%s', (string) $row['username'], $outcome->status, $outcome->message !== null ? ': ' . $outcome->message : ''));
            $failed += $outcome->status === DbOutcome::FAILED ? 1 : 0;
        }

        return $failed > 0 ? 1 : 0;
    }

    /** MariaDB's state and its connections. */
    public function info(Output $output): int
    {
        try {
            $info = $this->agent->call('db.info', [], timeout: 10.0);
            $procs = $this->agent->call('db.processes', [], timeout: 10.0);
        } catch (AgentUnavailable $e) {
            $output->error('The agent could not be asked: ' . $e->getMessage());

            return 1;
        }

        $i = $info->result;
        $output->pairs([
            'Installed' => ($i['installed'] ?? false) ? 'yes' : 'no',
            'Running' => ($i['running'] ?? false) ? 'yes' : 'no',
            'Version' => (string) ($i['version'] ?? '-'),
            'Uptime' => (string) ($i['uptime'] ?? 0) . 's',
            'Connections' => (string) ($i['threads'] ?? 0),
        ]);

        if ($procs->done()) {
            $output->line();
            $output->line(\sprintf('%-8s %-24s %-24s %-8s %s', 'ID', 'USER', 'DATABASE', 'TIME', 'QUERY'));

            foreach ((array) ($procs->result['processes'] ?? []) as $p) {
                $output->line(\sprintf('%-8d %-24s %-24s %-8s %s', (int) $p['id'], (string) $p['user'], (string) ($p['db'] ?: '-'), (int) $p['time'] . 's', \mb_substr((string) ($p['info'] ?: '-'), 0, 60)));
            }
        }

        return 0;
    }

    /** End one of an account's connections. */
    public function kill(Output $output, string $id): int
    {
        if (!\ctype_digit($id)) {
            $output->error('A connection id is a number.');

            return 2;
        }

        try {
            $reply = $this->agent->call('db.kill', ['id' => (int) $id], id: Protocol::newId(), timeout: 10.0);
        } catch (AgentUnavailable $e) {
            $output->error('The agent could not be asked: ' . $e->getMessage());

            return 1;
        }

        $this->audit->record('database.kill', $this->root(), null, $id, $reply->done() ? AuditLog::OK : AuditLog::FAILED, $reply->done() ? [] : ['reason' => $reply->errorCode]);

        if (!$reply->done()) {
            $output->error((string) $reply->errorMessage);

            return 1;
        }

        $output->success(($reply->result['killed'] ?? false) ? 'Connection ended.' : 'No such connection (it may have ended already).');

        return 0;
    }

    // -------------------------------------------------------------------------

    private function outcome(Output $output, DbOutcome $outcome, string $done): int
    {
        return match ($outcome->status) {
            DbOutcome::REFUSED => $this->fail($output, $this->localization->get((string) $outcome->message)),
            DbOutcome::FAILED => $this->fail($output, 'The server refused it: ' . $outcome->message),
            DbOutcome::PENDING => $this->ok($output, 'Saved; the server has not answered yet (db:settle sends it).'),
            DbOutcome::SKIPPED => $this->fail($output, 'The account is being set up or removed; try again in a moment.'),
            default => $this->ok($output, $done),
        };
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

    /** @return array<string, mixed>|null */
    private function account(Output $output, string $username): ?array
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $account;
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>|null
     */
    private function database(Output $output, array $account, string $name): ?array
    {
        $name = \strtolower(\trim($name));
        $full = \str_starts_with($name, Databases::prefix($account)) ? $name : Databases::prefix($account) . $name;
        $db = $this->databases->database((int) $account['id'], $full);

        if ($db === null) {
            $output->error(\sprintf('%s has no database %s.', $account['username'], $full));
        }

        return $db;
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>|null
     */
    private function user(Output $output, array $account, string $name): ?array
    {
        $name = \strtolower(\trim($name));
        $full = \str_starts_with($name, Databases::prefix($account)) ? $name : Databases::prefix($account) . $name;
        $user = $this->databases->user((int) $account['id'], $full);

        if ($user === null) {
            $output->error(\sprintf('%s has no database user %s.', $account['username'], $full));
        }

        return $user;
    }

    private static function password(): string
    {
        return \rtrim((string) \fgets(\STDIN), "\r\n");
    }

    private static function bytes(int $bytes): string
    {
        $n = (float) $bytes;

        foreach (['KB', 'MB', 'GB'] as $unit) {
            $n /= 1024;

            if ($n < 1024) {
                return $bytes < 1024 ? $bytes . ' B' : \sprintf('%.1f %s', $n, $unit);
            }
        }

        return \sprintf('%.1f TB', $n / 1024);
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
