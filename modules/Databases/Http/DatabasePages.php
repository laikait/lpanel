<?php

declare(strict_types=1);

namespace App\Modules\Databases\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Databases\Databases;
use App\Modules\Databases\DbActions;
use App\Modules\Databases\DbOutcome;
use App\Modules\Databases\DbSync;
use App\Modules\Databases\PhpMyAdmin;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * "Databases" in both panels.
 *
 * User panel: the account's databases, their users and what each may do,
 * the hosts users may connect from, and phpMyAdmin.
 *
 * Admin panel: MariaDB's state, its connections (an account's can be
 * ended), and every account's databases.
 */
final class DatabasePages
{
    public function __construct(
        private readonly Databases $databases,
        private readonly DbActions $actions,
        private readonly DbSync $sync,
        private readonly PhpMyAdmin $pma,
        private readonly Packages $packages,
        private readonly Subusers $subusers,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // User panel

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->user($request, $session, $identity);
    }

    public function store(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $name = (string) $request->input('name', '');
        $outcome = $this->actions->createDatabase($identity, $request, $account, $name);

        return $this->after($request, $session, $identity, $outcome, 'Databases.created', ['db_name' => $name], 'db');
    }

    public function rename(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $db = $this->database($account, $id);
        $outcome = $this->actions->renameDatabase($identity, $request, $account, $db, (string) $request->input('name', ''));

        return $this->after($request, $session, $identity, $outcome, 'Databases.renamed');
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->dropDatabase($identity, $request, $account, $this->database($account, $id));

        return $this->after($request, $session, $identity, $outcome, 'Databases.dropped');
    }

    public function storeUser(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $name = (string) $request->input('name', '');
        $grants = [];
        $db = (string) $request->input('database', '');

        if ($db !== '') {
            $found = \ctype_digit($db) ? $this->databases->database((int) $account['id'], (int) $db) : null;

            if ($found !== null) {
                $grants[(string) $found['name']] = ['ALL'];
            }
        }

        $outcome = $this->actions->createUser($identity, $request, $account, $name, (string) $request->input('password', ''), $grants);

        return $this->after($request, $session, $identity, $outcome, 'Databases.user_created', ['user_name' => $name, 'user_database' => $db], 'user');
    }

    public function password(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->setPassword($identity, $request, $account, $this->findUser($account, $id), (string) $request->input('password', ''));

        return $this->after($request, $session, $identity, $outcome, 'Databases.password_changed');
    }

    public function deleteUser(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->dropUser($identity, $request, $account, $this->findUser($account, $id));

        return $this->after($request, $session, $identity, $outcome, 'Databases.user_dropped');
    }

    public function grant(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $user = $this->findUser($account, $id);
        $db = $this->database($account, (string) $request->input('database', ''));
        $privileges = $request->input('revoke') === '1' ? null : $request->input('privileges', []);
        $outcome = $this->actions->setGrant($identity, $request, $account, $user, $db, $privileges);

        return $this->after($request, $session, $identity, $outcome, $privileges === null ? 'Databases.revoked' : 'Databases.granted');
    }

    public function addHost(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $host = (string) $request->input('host', '');
        $outcome = $this->actions->addHost($identity, $request, $account, $host, (string) $request->input('note', ''));

        return $this->after($request, $session, $identity, $outcome, 'Databases.host_added', ['host' => $host, 'note' => (string) $request->input('note', '')], 'host');
    }

    public function removeHost(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $host = \ctype_digit($id) ? $this->databases->host((int) $account['id'], (int) $id) : null;

        if ($host === null) {
            throw HttpException::notFound();
        }

        return $this->after($request, $session, $identity, $this->actions->removeHost($identity, $request, $account, $host), 'Databases.host_removed');
    }

    public function phpMyAdmin(Request $request, Session $session, Identity $identity): Response
    {
        [$url, $why] = $this->pma->signOn($identity, $request, $this->account($identity));

        if ($url === null) {
            PanelPage::notice($session, 'error', (string) $why);

            return $this->toUser();
        }

        return new RedirectResponse($url, 303);
    }

    /**
     * Back to the page with a notice; a refused form shows again with its
     * error beside the field and what was typed.
     *
     * @param array<string, string> $old
     */
    private function after(Request $request, Session $session, Identity $identity, DbOutcome $outcome, string $done, array $old = [], string $form = ''): Response
    {
        if ($outcome->status === DbOutcome::REFUSED && $form !== '' && $outcome->code !== '') {
            return $this->user($request, $session, $identity, [
                'errors' => [$form . '_' . $outcome->code => (string) $outcome->message],
                'old' => $old,
            ], 422);
        }

        match ($outcome->status) {
            DbOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            DbOutcome::FAILED => PanelPage::notice($session, 'error', 'Databases.refused'),
            DbOutcome::PENDING => PanelPage::notice($session, 'warning', 'Databases.pending'),
            default => PanelPage::notice($session, 'success', $done),
        };

        return $this->toUser();
    }

    /** @param array<string, mixed> $extra */
    private function user(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $id = (int) $account['id'];
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $dbs = $this->databases->databases($id);
        $users = $this->databases->users($id);
        $usersOf = [];

        foreach ($users as $u) {
            foreach (\array_keys($u['grants']) as $dbName) {
                $usersOf[$dbName][] = (string) $u['name'];
            }
        }

        foreach ($dbs as &$db) {
            $db['users'] = $usersOf[$db['name']] ?? [];
            $db['rename_url'] = $this->router->url('user.databases.rename', ['id' => $db['id']]);
            $db['delete_url'] = $this->router->url('user.databases.delete', ['id' => $db['id']]);
        }
        unset($db);

        foreach ($users as &$u) {
            $u['password_url'] = $this->router->url('user.databases.users.password', ['id' => $u['id']]);
            $u['delete_url'] = $this->router->url('user.databases.users.delete', ['id' => $u['id']]);
            $u['grant_url'] = $this->router->url('user.databases.users.grant', ['id' => $u['id']]);
        }
        unset($u);

        $hosts = \array_map(fn(array $h): array => $h + ['delete_url' => $this->router->url('user.databases.hosts.delete', ['id' => $h['id']])], $this->databases->hosts($id));
        $limit = (int) ($package['databases'] ?? 0);

        return $this->pages->render($request, $session, $identity, 'user', '@Databases/databases', $extra + [
            'account' => $account,
            'prefix' => Databases::prefix($account),
            'max_db' => Databases::MAX_DATABASE - \strlen(Databases::prefix($account)),
            'max_user' => Databases::MAX_USER - \strlen(Databases::prefix($account)),
            'password_min' => Databases::PASSWORD_MIN,
            'databases' => $dbs,
            'users' => $users,
            'hosts' => $hosts,
            'privileges' => Databases::PRIVILEGES,
            'limit' => $limit,
            'at_limit' => $limit > 0 && \count($dbs) >= $limit,
            'total_bytes' => \array_sum(\array_map(static fn(array $d): int => (int) $d['size_bytes'], $dbs)),
            'state' => $this->sync->stateOf($id),
            'errors' => [],
            'old' => [],
            'store_url' => $this->router->url('user.databases.store'),
            'user_url' => $this->router->url('user.databases.users'),
            'host_url' => $this->router->url('user.databases.hosts'),
            'pma_url' => $this->router->url('user.databases.phpmyadmin'),
        ], title: 'Databases.title', active: 'databases', status: $status);
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function database(array $account, string $id): array
    {
        return (\ctype_digit($id) ? $this->databases->database((int) $account['id'], (int) $id) : null) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function findUser(array $account, string $id): array
    {
        return (\ctype_digit($id) ? $this->databases->user((int) $account['id'], (int) $id) : null) ?? throw HttpException::notFound();
    }

    private function toUser(): Response
    {
        return new RedirectResponse($this->router->url('user.databases'), 303);
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function admin(Request $request, Session $session, Identity $identity): Response
    {
        $info = null;
        $processes = null;

        try {
            $reply = $this->agent->call('db.info', [], timeout: 10.0);
            $info = $reply->done() ? $reply->result : null;

            if ($info !== null && ($info['running'] ?? false) === true) {
                $reply = $this->agent->call('db.processes', [], timeout: 10.0);
                $processes = $reply->done() && \is_array($reply->result['processes'] ?? null) ? $reply->result['processes'] : null;
            }
        } catch (AgentUnavailable) {
        }

        $all = $this->databases->all();

        return $this->pages->render($request, $session, $identity, 'admin', '@Databases/admin', [
            'info' => $info,
            'processes' => \array_map(fn(array $p): array => $p + ['kill_url' => $this->router->url('admin.databases.kill', ['id' => (int) ($p['id'] ?? 0)])], $processes ?? []),
            'processes_known' => $processes !== null,
            'databases' => $all,
            'total_bytes' => \array_sum(\array_map(static fn(array $d): int => (int) $d['size_bytes'], $all)),
        ], title: 'Databases.title', active: 'databases');
    }

    public function kill(Request $request, Session $session, Identity $identity, string $id): Response
    {
        try {
            $reply = $this->agent->call('db.kill', ['id' => (int) $id], id: Protocol::newId(), timeout: 10.0);
            $ok = $reply->done();
            $this->audit->record('database.kill', $identity, $request, $id, $ok ? AuditLog::OK : AuditLog::FAILED, $ok ? [] : ['reason' => $reply->errorCode, 'error' => $reply->errorMessage]);
            PanelPage::notice($session, $ok ? 'success' : 'error', $ok ? 'Databases.killed' : 'Databases.kill_refused');
        } catch (AgentUnavailable) {
            PanelPage::notice($session, 'error', 'Databases.error_unreachable');
        }

        return new RedirectResponse($this->router->url('admin.databases'), 303);
    }
}
