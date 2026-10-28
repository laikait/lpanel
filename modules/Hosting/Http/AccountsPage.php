<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountAccess;
use App\Modules\Hosting\Accounts\AccountActions;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/**
 * /accounts: hosting accounts. Administrators see and manage all of them;
 * a customer sees its own, read-only. Every account id goes through
 * AccountAccess, so another customer's id is an audited 404.
 */
final class AccountsPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly AccountStore $accounts,
        private readonly AccountAccess $access,
        private readonly AccountActions $actions,
        private readonly AccountViews $views,
        private readonly ServerStore $servers,
        private readonly UserStore $users,
        private readonly OperationStore $operations,
        private readonly AgentSettings $settings,
        private readonly Authorizer $authorizer,
        private readonly FilterEngine $filters,
    ) {}

    public function index(Request $request, Identity $identity): Response
    {
        $group = $request->query('status');
        $group = \is_string($group) && isset(AccountStore::GROUPS[$group]) ? $group : 'current';
        $owner = $this->access->ownerScope($identity);

        return $this->pages->render($request, '@Hosting/index', [
            'rows' => $this->views->rows($this->accounts->page($owner, $group)),
            'counts' => $this->accounts->counts($owner),
            'group' => $group,
            'admin' => $owner === null,
            'can_manage' => $this->authorizer->allows($identity, 'accounts.manage'),
            'create_url' => $this->pages->url('accounts.create'),
            'index_url' => $this->pages->url('accounts.index'),
        ], identity: $identity, section: 'accounts');
    }

    public function create(Request $request, Identity $identity): Response
    {
        return $this->form($request, $identity, ['label' => '', 'server' => '', 'owner' => (string) ($request->query('owner') ?? '')], null);
    }

    public function store(Request $request, Identity $identity): Response
    {
        $input = [
            'label' => self::text($request->input('label')),
            'server' => self::text($request->input('server')),
            'owner' => self::text($request->input('owner')),
        ];
        $server = $this->servers->byUid($input['server']);
        $owner = $this->users->byUid($input['owner']);

        if ($server === null || $owner === null) {
            return $this->form($request, $identity, $input, 'Choose a server and the customer the account is for.', 422);
        }

        try {
            $account = $this->actions->create($identity, $server, $owner, $input['label']);
        } catch (OperationException $e) {
            return $this->form($request, $identity, $input, $e->getMessage(), $e->status);
        }

        $this->pages->flash('success', \sprintf('Setting up %s on %s. This page updates by itself.', $account->label, $server->name));

        return new RedirectResponse($this->pages->url('accounts.show', ['id' => $account->uid]), 303);
    }

    public function show(Request $request, Identity $identity, string $id): Response
    {
        $account = $this->access->find($identity, $id);
        $server = $this->servers->byId($account->serverId);
        $url = fn(string $name): string => $this->pages->url($name, ['id' => $account->uid]);
        $seesOperations = $this->authorizer->allows($identity, 'servers.read');

        return $this->pages->render($request, '@Hosting/show', [
            'a' => $account,
            'info' => $this->views->row($account),
            'server_status' => $server?->status($this->settings->offlineAfter()),
            'owner' => $this->users->byUid($account->ownerUid),
            'admin' => $this->access->seesAll($identity),
            'can_manage' => $this->authorizer->allows($identity, 'accounts.manage'),
            'history' => $seesOperations ? $this->views->history($this->operations->forTarget('account', $account->uid, 10)) : [],
            'sees_operations' => $seesOperations,
            'watch' => $account->isBusy() ? $this->pages->url('api.v1.accounts.show', ['id' => $account->uid]) : null,
            'panels' => self::panels($this->filters->apply('hosting.account.panels', [], $account, $identity)),
            'index_url' => $this->pages->url('accounts.index'),
            'actions' => [
                'suspend' => $url('accounts.suspend'),
                'unsuspend' => $url('accounts.unsuspend'),
                'delete' => $url('accounts.delete'),
                'retry' => $url('accounts.retry'),
            ],
        ], identity: $identity, section: 'accounts');
    }

    public function suspend(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'suspend', null, 'Suspending %s: logins, PHP and its processes stop. Its files stay.');
    }

    public function unsuspend(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'unsuspend', null, 'Unsuspending %s.');
    }

    public function retry(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'create', null, 'Setting up %s again.');
    }

    public function delete(Request $request, Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'delete', self::text($request->input('confirm')), 'Deleting %s and every file in it.');
    }

    private function act(Identity $identity, string $id, string $action, ?string $confirm, string $done): Response
    {
        $account = $this->access->find($identity, $id, $action);

        try {
            $account = $this->actions->act($identity, $account, $action, $confirm);
            $this->pages->flash($action === 'delete' ? 'warning' : 'success', \sprintf($done, $account->label));
        } catch (OperationException $e) {
            if ($e->status === 403) {
                throw HttpException::forbidden($e->getMessage());
            }

            $this->pages->flash('danger', $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('accounts.show', ['id' => $account->uid]), 303);
    }

    /** @param array{label: string, server: string, owner: string} $input */
    private function form(Request $request, Identity $identity, array $input, ?string $error, int $status = 200): Response
    {
        $offline = $this->settings->offlineAfter();
        $servers = [];

        foreach ($this->servers->all() as $s) {
            if ($s->state !== 'active') {
                continue;
            }

            $online = $s->status($offline) === 'online';
            $capable = isset($s->capabilities['account.create']);
            $servers[] = [
                'id' => $s->uid,
                'name' => $s->name,
                'detail' => $s->hostname ?: ($s->osLabel() ?? ''),
                'usable' => $online && $capable,
                'note' => !$online ? 'offline' : (!$capable ? 'agent too old' : ''),
            ];
        }

        $customers = [];

        foreach ($this->users->all() as $u) {
            if ($u->isActive() && \in_array('customer', $u->roles, true)) {
                $customers[] = ['id' => $u->uid, 'name' => $u->name, 'login' => $u->login];
            }
        }

        return $this->pages->render($request, '@Hosting/create', [
            'input' => $input,
            'error' => $error,
            'servers' => $servers,
            'customers' => $customers,
            'store_url' => $this->pages->url('accounts.store'),
            'index_url' => $this->pages->url('accounts.index'),
            'users_url' => $this->pages->url('users.index'),
            'servers_url' => $this->pages->url('servers.index'),
        ], $status, $identity, 'accounts');
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? \trim($value) : '';
    }

    /**
     * Cards other modules add to an account's page (hosting.account.panels):
     * ['template' => '@Module/x.twig', 'column' => 'main'|'side', 'context' => [...]].
     *
     * @return array{main: list<array{template: string, context: array<mixed>}>, side: list<array{template: string, context: array<mixed>}>}
     */
    private static function panels(mixed $given): array
    {
        $panels = ['main' => [], 'side' => []];

        foreach (\is_array($given) ? $given : [] as $panel) {
            if (\is_array($panel) && \is_string($panel['template'] ?? null) && \in_array($panel['column'] ?? null, ['main', 'side'], true)) {
                $panels[$panel['column']][] = ['template' => $panel['template'], 'context' => \is_array($panel['context'] ?? null) ? $panel['context'] : []];
            }
        }

        return $panels;
    }
}
