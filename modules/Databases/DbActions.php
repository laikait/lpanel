<?php

declare(strict_types=1);

namespace App\Modules\Databases;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * Changing an account's databases, users, grants and remote hosts: the one
 * place the User panel, the API and the CLI all go through.
 *
 * Most changes are saved, then the account's whole state goes to the server
 * (DbSync); if the server refuses it, the change is undone here too. Two
 * changes touch data and so are asked of the server first, and saved only
 * once it has done them: dropping a database and renaming one. They wait
 * until any earlier change has reached the server, so a late db.apply can
 * never bring back a database just dropped.
 */
final class DbActions
{
    public function __construct(
        private readonly Databases $databases,
        private readonly DbSync $sync,
        private readonly Packages $packages,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
    ) {}

    /** @param array<string, mixed> $account */
    public function createDatabase(?Identity $actor, ?Request $request, array $account, string $input): DbOutcome
    {
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $limit = (int) ($package['databases'] ?? 0);

        if ($limit > 0 && $this->databases->count((int) $account['id']) >= $limit) {
            return DbOutcome::refused('Databases.error_limit', 'name');
        }

        [$name, $why] = $this->databases->checkDatabaseName($account, $input);

        if ($why !== null) {
            return DbOutcome::refused($why, 'name');
        }

        $id = 0;

        return $this->attempt(
            $actor,
            $request,
            $account,
            'database.create',
            $name,
            [],
            function () use (&$id, $account, $name): void {
                $id = $this->databases->addDatabase((int) $account['id'], $name);
            },
            function () use (&$id): void {
                $this->databases->deleteDatabase($id);
            },
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $database
     */
    public function dropDatabase(?Identity $actor, ?Request $request, array $account, array $database): DbOutcome
    {
        $outcome = $this->direct($account, 'db.drop', ['username' => $account['username'], 'name' => $database['name']]);

        if ($outcome->status === DbOutcome::DONE) {
            $this->databases->deleteDatabase((int) $database['id']);
            $this->sync->touch((int) $account['id']);
        }

        return $this->record('database.drop', $actor, $request, $account, (string) $database['name'], [], $outcome);
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $database
     */
    public function renameDatabase(?Identity $actor, ?Request $request, array $account, array $database, string $input): DbOutcome
    {
        [$name, $why] = $this->databases->checkDatabaseName($account, $input, (int) $database['id']);

        if ($why === null && $name === $database['name']) {
            return DbOutcome::done();
        }

        if ($why !== null) {
            return DbOutcome::refused($why, 'name');
        }

        $outcome = $this->direct($account, 'db.rename', ['username' => $account['username'], 'from' => $database['name'], 'to' => $name]);

        if ($outcome->status === DbOutcome::DONE) {
            $this->databases->renameDatabase((int) $database['id'], $name);
            $this->sync->touch((int) $account['id']);
        }

        return $this->record('database.rename', $actor, $request, $account, (string) $database['name'], ['to' => $name], $outcome);
    }

    /**
     * A new user, with privileges on some databases.
     *
     * @param array<string, mixed>        $account
     * @param array<string, list<string>> $grants  database name => privileges
     */
    public function createUser(?Identity $actor, ?Request $request, array $account, string $input, string $password, array $grants = []): DbOutcome
    {
        [$name, $why] = $this->databases->checkUserName($account, $input);

        if ($why !== null) {
            return DbOutcome::refused($why, 'name');
        }

        if (($why = Databases::passwordError($password)) !== null) {
            return DbOutcome::refused($why, 'password');
        }

        $targets = [];

        foreach ($grants as $dbName => $privileges) {
            $db = $this->databases->database((int) $account['id'], (string) $dbName);

            if ($db === null) {
                return DbOutcome::refused('Databases.error_no_database', 'grants');
            }

            [$clean, $why] = Databases::checkPrivileges($privileges);

            if ($why !== null) {
                return DbOutcome::refused($why, 'grants');
            }

            $targets[(int) $db['id']] = $clean;
        }

        $id = 0;

        return $this->attempt(
            $actor,
            $request,
            $account,
            'database.user_create',
            $name,
            ['databases' => \implode(',', \array_keys($grants))],
            function () use (&$id, $account, $name, $password, $targets): void {
                $id = $this->databases->addUser((int) $account['id'], $name, Databases::hash($password));

                foreach ($targets as $dbId => $privileges) {
                    $this->databases->setGrant($id, $dbId, $privileges);
                }
            },
            function () use (&$id): void {
                $this->databases->deleteUser($id);
            },
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     */
    public function setPassword(?Identity $actor, ?Request $request, array $account, array $user, string $password): DbOutcome
    {
        if (($why = Databases::passwordError($password)) !== null) {
            return DbOutcome::refused($why, 'password');
        }

        $before = (string) $user['hash'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'database.user_password',
            (string) $user['name'],
            [],
            fn() => $this->databases->setHash((int) $user['id'], Databases::hash($password)),
            fn() => $this->databases->setHash((int) $user['id'], $before),
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     */
    public function dropUser(?Identity $actor, ?Request $request, array $account, array $user): DbOutcome
    {
        $grants = [];

        foreach ($this->databases->databases((int) $account['id']) as $db) {
            $privileges = $this->databases->grant((int) $user['id'], (int) $db['id']);

            if ($privileges !== null) {
                $grants[(int) $db['id']] = $privileges;
            }
        }

        $id = (int) $user['id'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'database.user_drop',
            (string) $user['name'],
            [],
            fn() => $this->databases->deleteUser($id),
            function () use (&$id, $account, $user, $grants): void {
                $id = $this->databases->addUser((int) $account['id'], (string) $user['name'], (string) $user['hash']);

                foreach ($grants as $dbId => $privileges) {
                    $this->databases->setGrant($id, $dbId, $privileges);
                }
            },
        );
    }

    /**
     * What a user may do in a database; null privileges take them all away.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     * @param array<string, mixed> $database
     */
    public function setGrant(?Identity $actor, ?Request $request, array $account, array $user, array $database, mixed $privileges): DbOutcome
    {
        $clean = null;

        if ($privileges !== null) {
            [$clean, $why] = Databases::checkPrivileges($privileges);

            if ($why !== null) {
                return DbOutcome::refused($why, 'privileges');
            }
        }

        $before = $this->databases->grant((int) $user['id'], (int) $database['id']);

        if ($before === $clean) {
            return DbOutcome::done();
        }

        return $this->attempt(
            $actor,
            $request,
            $account,
            $clean === null ? 'database.revoke' : 'database.grant',
            (string) $user['name'],
            ['database' => $database['name'], 'privileges' => $clean === null ? '' : \implode(',', $clean)],
            fn() => $this->databases->setGrant((int) $user['id'], (int) $database['id'], $clean),
            fn() => $this->databases->setGrant((int) $user['id'], (int) $database['id'], $before),
        );
    }

    /** @param array<string, mixed> $account */
    public function addHost(?Identity $actor, ?Request $request, array $account, string $input, string $note = ''): DbOutcome
    {
        [$host, $why] = Databases::checkHost($input);

        if ($why === null && $this->databases->hasHost((int) $account['id'], $host)) {
            $why = 'Databases.error_host_taken';
        }

        if ($why !== null) {
            return DbOutcome::refused($why, 'host');
        }

        $note = \mb_substr(\trim($note), 0, 100);
        $id = 0;

        return $this->attempt(
            $actor,
            $request,
            $account,
            'database.host_add',
            $host,
            [],
            function () use (&$id, $account, $host, $note): void {
                $id = $this->databases->addHost((int) $account['id'], $host, $note === '' ? null : $note);
            },
            function () use (&$id): void {
                $this->databases->deleteHost($id);
            },
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $host
     */
    public function removeHost(?Identity $actor, ?Request $request, array $account, array $host): DbOutcome
    {
        $id = (int) $host['id'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'database.host_remove',
            (string) $host['host'],
            [],
            fn() => $this->databases->deleteHost($id),
            function () use (&$id, $account, $host): void {
                $id = $this->databases->addHost((int) $account['id'], (string) $host['host'], $host['note'] === null ? null : (string) $host['note']);
            },
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Save, send, and undo both if the server refuses; audit either way.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function attempt(?Identity $actor, ?Request $request, array $account, string $action, string $subject, array $details, \Closure $do, \Closure $undo): DbOutcome
    {
        $do();
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === DbOutcome::FAILED) {
            $undo();
            $this->sync->touch((int) $account['id']);
        }

        return $this->record($action, $actor, $request, $account, $subject, $details, $outcome);
    }

    /**
     * Ask the server for a change to data, once earlier changes are there.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $args
     */
    private function direct(array $account, string $op, array $args): DbOutcome
    {
        if ($this->sync->touch((int) $account['id'])->status === DbOutcome::PENDING) {
            return DbOutcome::refused('Databases.error_pending');
        }

        try {
            $reply = $this->agent->call($op, $args, id: Protocol::newId(), timeout: 300.0);
        } catch (AgentUnavailable) {
            return DbOutcome::refused('Databases.error_unreachable');
        }

        if (!$reply->done()) {
            return DbOutcome::failed($reply->errorCode ?? 'failed', $reply->errorMessage ?? 'The agent gave no reason.');
        }

        return DbOutcome::done($reply->result);
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function record(string $action, ?Identity $actor, ?Request $request, array $account, string $subject, array $details, DbOutcome $outcome): DbOutcome
    {
        if ($outcome->status === DbOutcome::REFUSED) {
            return $outcome;
        }

        $details = ['account' => $account['username']] + $details;

        if ($outcome->status === DbOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === DbOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record($action, $actor, $request, $subject, $outcome->status === DbOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);

        return $outcome;
    }
}
