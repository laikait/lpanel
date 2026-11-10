<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Secret;
use App\Engine\Session\Session;
use App\Modules\Accounts\AccountRules;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\AccountView;
use App\Modules\Accounts\Outcome;
use App\Modules\Accounts\Provisioner;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\Kind;
use App\Modules\Identity\LoginAs;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;

/**
 * Hosting accounts in the Admin panel.
 */
final class AccountPages
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly AccountRules $rules,
        private readonly AccountView $view,
        private readonly Provisioner $provisioner,
        private readonly Packages $packages,
        private readonly PanelUsers $users,
        private readonly PanelPage $pages,
        private readonly Router $router,
        private readonly Authorizer $authorizer,
        private readonly LoginAs $loginAs,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $search = \trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $rows = [];

        foreach ($this->accounts->visibleTo($identity, $search, $status) as $row) {
            $row = $this->view->enrich($row);
            $row['url'] = $this->router->url('admin.accounts.show', ['id' => $row['id']]);
            $rows[] = $row;
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Accounts/index', [
            'accounts' => $rows,
            'counts' => $this->accounts->counts($identity),
            'search' => $search,
            'status' => $status,
            'statuses' => Accounts::STATUSES,
            'show_owner' => $this->accounts->scopeOf($identity) === null,
            'new_url' => $this->router->url('admin.accounts.new'),
            'base_url' => $this->router->url('admin.accounts'),
            'can_create' => $this->authorizer->allows($identity, 'accounts.create'),
        ], title: 'Accounts.accounts', active: 'accounts');
    }

    public function new(Request $request, Session $session, Identity $identity): Response
    {
        return $this->form($request, $session, $identity);
    }

    public function store(Request $request, Session $session, Identity $identity): Response
    {
        $input = [];

        foreach (['username', 'domain', 'password', 'email', 'name', 'package', 'owner'] as $field) {
            $input[$field] = (string) $request->input($field, '');
        }

        [$clean, $errors] = $this->rules->validateNew($input, $identity);

        if ($errors !== []) {
            unset($input['password']);

            return $this->form($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        $outcome = $this->provisioner->create($identity, $request, $clean);
        $row = $outcome->accountId === null ? null : $this->accounts->byId($outcome->accountId);

        // A definite failure leaves nothing behind: show the form again.
        if ($row === null) {
            unset($input['password']);

            return $this->form($request, $session, $identity, ['old' => $input, 'agent_error' => self::agentMessage($outcome)], 422);
        }

        self::notify($session, $outcome, 'Accounts.created');

        return $this->back($row);
    }

    public function show(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->account($identity, $id);

        // A quick look for news: the operation in flight, and fresh disk use.
        if ($row['op_id'] !== null) {
            $this->provisioner->settle((int) $row['id']);
        } elseif ($row['status'] === Accounts::ACTIVE || $row['status'] === Accounts::SUSPENDED) {
            $this->provisioner->refreshUsage([(string) $row['username']]);
        }

        $row = $this->accounts->byId((int) $row['id']);

        if ($row === null) {
            PanelPage::notice($session, 'success', 'Accounts.terminated');

            return new RedirectResponse($this->router->url('admin.accounts'), 303);
        }

        $row = $this->view->enrich($row);
        $actions = [];

        foreach (['package', 'suspend', 'unsuspend', 'password', 'terminate'] as $action) {
            $actions[$action] = $this->router->url('admin.accounts.' . $action, ['id' => $row['id']]);
        }

        $login = $this->users->row((string) $row['login_id']);

        return $this->pages->render($request, $session, $identity, 'admin', '@Accounts/show', [
            'account' => $row,
            'login' => $login,
            'can' => $this->abilities($identity) + ['login_as' => $login !== null && $this->loginAs->may($identity, $login)],
            'login_as_url' => $this->router->url('admin.login_as', ['id' => $row['login_id']]),
            'subusers' => $this->users->children((string) $row['login_id']),
            'packages' => $this->packages->usableFor($identity, $row['owner_id']),
            'limits' => Packages::LIMITS,
            'urls' => $actions + ['back' => $this->router->url('admin.accounts')],
            'min_password' => SecurityPage::MIN_PASSWORD,
        ], title: (string) $row['username'], active: 'accounts');
    }

    public function package(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->account($identity, $id);
        $package = $this->rules->package($identity, $row['owner_id'], (string) $request->input('package', ''));

        if ($package === null) {
            PanelPage::notice($session, 'error', 'Accounts.error_package');
        } elseif ((int) $package['id'] === (int) $row['package_id']) {
            PanelPage::notice($session, 'info', 'Accounts.package_same');
        } else {
            self::notify($session, $this->provisioner->changePackage($identity, $request, (int) $row['id'], (int) $package['id']), 'Accounts.package_changed');
        }

        return $this->back($row);
    }

    public function suspend(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->account($identity, $id);
        self::notify($session, $this->provisioner->suspend($identity, $request, (int) $row['id'], (string) $request->input('reason', '')), 'Accounts.suspended');

        return $this->back($row);
    }

    public function unsuspend(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->account($identity, $id);
        self::notify($session, $this->provisioner->unsuspend($identity, $request, (int) $row['id']), 'Accounts.unsuspended');

        return $this->back($row);
    }

    public function password(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->account($identity, $id);
        $password = (string) $request->input('password', '');

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            PanelPage::notice($session, 'error', 'Accounts.error_password');
        } else {
            $this->provisioner->setPassword($identity, $request, (int) $row['id'], new Secret($password));
            PanelPage::notice($session, 'success', 'Accounts.password_changed');
        }

        return $this->back($row);
    }

    public function terminate(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->account($identity, $id);

        if (\strtolower(\trim((string) $request->input('confirm', ''))) !== $row['username']) {
            PanelPage::notice($session, 'error', 'Accounts.error_confirm');

            return $this->back($row);
        }

        $outcome = $this->provisioner->terminate($identity, $request, (int) $row['id']);

        if ($outcome->status === Outcome::DONE) {
            PanelPage::notice($session, 'success', 'Accounts.terminated');

            return new RedirectResponse($this->router->url('admin.accounts'), 303);
        }

        self::notify($session, $outcome, 'Accounts.terminated');

        return $this->back($row);
    }

    /** One notice for any outcome. */
    public static function notify(Session $session, Outcome $outcome, string $doneMessage): void
    {
        match ($outcome->status) {
            Outcome::DONE => PanelPage::notice($session, 'success', $doneMessage),
            Outcome::PENDING => PanelPage::notice($session, 'info', $outcome->message !== '' ? 'Accounts.pending_unreachable' : 'Accounts.pending_working'),
            Outcome::BUSY => PanelPage::notice($session, 'warning', 'Accounts.busy'),
            Outcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->code),
            default => PanelPage::notice($session, 'error', self::agentMessage($outcome)),
        };
    }

    /** The lang key for an agent failure, by its code. */
    public static function agentMessage(Outcome $outcome): string
    {
        return match ($outcome->code) {
            'exists' => 'Accounts.agent_exists',
            'quota' => 'Accounts.agent_quota',
            'not_ours' => 'Accounts.agent_not_ours',
            'interrupted', 'timeout' => 'Accounts.agent_uncertain',
            default => 'Accounts.agent_failed',
        };
    }

    /** @param array<string, mixed> $extra */
    private function form(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $all = $this->accounts->scopeOf($identity) === null;
        $old = \is_array($extra['old'] ?? null) ? $extra['old'] : [];
        $owner = $all ? (($old['owner'] ?? '') !== '' ? (int) $old['owner'] : null) : (int) $identity->id;
        $packages = [];

        // Every package the form could offer; the browser shows the ones for
        // the chosen owner (panel.js), and the server checks again.
        foreach ($all ? $this->packages->visibleTo($identity) : $this->packages->usableFor($identity, $owner) as $package) {
            $packages[] = $package;
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Accounts/new', $extra + [
            'errors' => [],
            'old' => [],
            'agent_error' => null,
            'packages' => $packages,
            'resellers' => $all ? $this->users->ofKinds(Kind::Reseller) : [],
            'choose_owner' => $all,
            'action' => $this->router->url('admin.accounts.store'),
            'back' => $this->router->url('admin.accounts'),
            'packages_url' => $this->router->url('admin.packages'),
            'min_password' => SecurityPage::MIN_PASSWORD,
        ], title: 'Accounts.new', active: 'accounts', status: $status);
    }

    /** @return array<string, bool> what this login may do to accounts it can see */
    private function abilities(Identity $identity): array
    {
        $can = [];

        foreach (['modify', 'suspend', 'terminate'] as $action) {
            $can[$action] = $this->authorizer->allows($identity, 'accounts.' . $action);
        }

        return $can;
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $id): array
    {
        return $this->accounts->find($identity, $id) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $row */
    private function back(array $row): Response
    {
        return new RedirectResponse($this->router->url('admin.accounts.show', ['id' => $row['id']]), 303);
    }
}
