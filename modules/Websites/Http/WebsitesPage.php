<?php

declare(strict_types=1);

namespace App\Modules\Websites\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountAccess;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;
use App\Modules\Websites\Sites\Website;
use App\Modules\Websites\Sites\WebsiteAccess;
use App\Modules\Websites\Sites\WebsiteActions;
use App\Modules\Websites\Sites\WebsiteSettings;
use App\Modules\Websites\Sites\WebsiteStore;

/**
 * /websites: administrators see and manage every website; a customer sees
 * its own and may change their managed settings. Every website id goes
 * through WebsiteAccess, so another customer's id is an audited 404.
 */
final class WebsitesPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly WebsiteStore $websites,
        private readonly WebsiteAccess $access,
        private readonly WebsiteActions $actions,
        private readonly WebsiteViews $views,
        private readonly AccountStore $accounts,
        private readonly AccountAccess $accountAccess,
        private readonly ServerStore $servers,
        private readonly OperationStore $operations,
        private readonly AgentSettings $agents,
        private readonly Authorizer $authorizer,
    ) {}

    public function index(Request $request, Identity $identity): Response
    {
        $group = $request->query('status');
        $edited = $group === 'edited';
        $group = \is_string($group) && isset(WebsiteStore::GROUPS[$group]) ? $group : 'current';
        $owner = $this->access->ownerScope($identity);

        return $this->pages->render($request, '@Websites/index', [
            'rows' => $this->views->rows($this->websites->page($owner, $group, editedOnly: $edited)),
            'counts' => $this->websites->counts($owner),
            'group' => $edited ? 'edited' : $group,
            'admin' => $owner === null,
            'can_manage' => $this->authorizer->allows($identity, 'websites.manage'),
            'create_url' => $this->pages->url('websites.create'),
            'index_url' => $this->pages->url('websites.index'),
        ], identity: $identity, section: 'websites');
    }

    public function create(Request $request, Identity $identity): Response
    {
        $account = $request->query('account');

        return $this->form($request, $identity, ['account' => \is_string($account) ? $account : '', 'domains' => '', 'document_root' => '', 'php' => true, 'https_redirect' => true], [], null);
    }

    public function store(Request $request, Identity $identity): Response
    {
        $input = [
            'account' => self::text($request->input('account')),
            'domains' => self::text($request->input('domains')),
            'document_root' => self::text($request->input('document_root')),
            'php' => $request->input('php') !== null,
            'https_redirect' => $request->input('https_redirect') !== '0',
        ];
        $account = $this->accounts->byUid($input['account']);

        if ($account === null || $account->status === Account::DELETED) {
            return $this->form($request, $identity, $input, ['account' => 'Choose the hosting account the website belongs to.'], null, 422);
        }

        $checked = WebsiteSettings::normalize($input);

        if ($checked['errors'] !== []) {
            return $this->form($request, $identity, $input, $checked['errors'], null, 422);
        }

        try {
            $website = $this->actions->create($identity, $account, $checked['settings']);
        } catch (OperationException $e) {
            return $this->form($request, $identity, $input, [], $e->getMessage(), $e->status);
        }

        $this->pages->flash('success', \sprintf('Setting up %s. Its configuration is tested before Nginx serves it; this page updates by itself.', $website->domain));

        return new RedirectResponse($this->pages->url('websites.show', ['id' => $website->uid]), 303);
    }

    public function show(Request $request, Identity $identity, string $id): Response
    {
        $website = $this->access->find($identity, $id);

        return $this->page($request, $identity, $website, null, [], 200);
    }

    /** POST /websites/{id}/settings: the managed settings form. */
    public function settings(Request $request, Identity $identity, string $id): Response
    {
        $website = $this->access->find($identity, $id, 'update');
        $manager = $this->authorizer->allows($identity, 'websites.manage');
        $raw = [
            // Only an administrator changes the domains and the folder.
            'domains' => $manager ? self::text($request->input('domains')) : $website->domains(),
            'document_root' => $manager ? self::text($request->input('document_root')) : (string) ($website->settings['document_root'] ?? ''),
            'php' => $request->input('php') !== null,
            'index' => self::text($request->input('index')),
            'redirects' => self::rows($request->input('redirects')),
            'headers' => self::rows($request->input('headers')),
            'https_redirect' => $request->input('https_redirect') !== '0',
        ];
        $checked = WebsiteSettings::normalize($raw);

        if ($checked['errors'] !== []) {
            return $this->page($request, $identity, $website, $raw, $checked['errors'], 422);
        }

        try {
            $website = $this->actions->update($identity, $website, $checked['settings']);
            $this->pages->flash('success', 'Applying the new settings. Nginx tests them first; if they do not take, the previous ones stay.');
        } catch (OperationException $e) {
            if ($e->status === 403) {
                throw HttpException::forbidden($e->getMessage());
            }

            return $this->page($request, $identity, $website, $raw, [], $e->status, $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('websites.show', ['id' => $website->uid]), 303);
    }

    public function restore(Identity $identity, string $id): Response
    {
        $website = $this->access->find($identity, $id, 'restore');

        try {
            $website = $this->actions->update($identity, $website, $website->settings, true);
            $this->pages->flash('warning', \sprintf('Restoring LPanel\'s version of %s. The hand edit is replaced.', $website->domain));
        } catch (OperationException $e) {
            if ($e->status === 403) {
                throw HttpException::forbidden($e->getMessage());
            }

            $this->pages->flash('danger', $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('websites.show', ['id' => $website->uid]), 303);
    }

    /** POST /websites/{id}/certificate: get one now, or renew it. */
    public function certify(Identity $identity, string $id): Response
    {
        return $this->certificateAction($identity, $id, 'issue', 'Asking Let\'s Encrypt for a certificate for %s. It checks each domain over HTTP; this usually takes under a minute.');
    }

    /** POST /websites/{id}/certificate/remove */
    public function uncertify(Identity $identity, string $id): Response
    {
        return $this->certificateAction($identity, $id, 'remove', 'Removing the certificate of %s. It is served over HTTP only once that is done.');
    }

    private function certificateAction(Identity $identity, string $id, string $what, string $done): Response
    {
        $website = $this->access->find($identity, $id, $what === 'issue' ? 'certify' : 'uncertify');

        try {
            $website = $this->actions->certificate($identity, $website, $what);
            $this->pages->flash($what === 'remove' ? 'warning' : 'success', \sprintf($done, $website->domain));
        } catch (OperationException $e) {
            if ($e->status === 403) {
                throw HttpException::forbidden($e->getMessage());
            }

            $this->pages->flash('danger', $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('websites.show', ['id' => $website->uid]) . '#https', 303);
    }

    public function suspend(Identity $identity, string $id): Response
    {
        return $this->act($identity, $id, 'suspend', null, 'Suspending %s: every request gets "temporarily unavailable".');
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
        return $this->act($identity, $id, 'delete', self::text($request->input('confirm')), 'Deleting %s. Its files stay in the account\'s web folder.');
    }

    /** POST /websites/check {server}: compare a server's website files with LPanel's. */
    public function check(Request $request, Identity $identity): Response
    {
        $server = $this->servers->byUid(self::text($request->input('server'))) ?? throw HttpException::notFound();

        try {
            $op = $this->actions->check($identity, $server);
            $this->pages->flash('success', 'Checking the websites\' Nginx files on ' . $server->name . '.');
        } catch (OperationException $e) {
            if ($e->status === 403) {
                throw HttpException::forbidden($e->getMessage());
            }

            $this->pages->flash('danger', $e->getMessage());

            return new RedirectResponse($this->pages->url('servers.show', ['id' => $server->uid]), 303);
        }

        return new RedirectResponse($this->pages->url('operations.show', ['id' => $op->uid]), 303);
    }

    private function act(Identity $identity, string $id, string $action, ?string $confirm, string $done): Response
    {
        $website = $this->access->find($identity, $id, $action);

        try {
            $website = $this->actions->act($identity, $website, $action, $confirm);
            $this->pages->flash($action === 'delete' ? 'warning' : 'success', \sprintf($done, $website->domain));
        } catch (OperationException $e) {
            if ($e->status === 403) {
                throw HttpException::forbidden($e->getMessage());
            }

            $this->pages->flash('danger', $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('websites.show', ['id' => $website->uid]), 303);
    }

    /**
     * The website's page; with $form, the settings form as it was sent.
     *
     * @param array<string, mixed>|null $form
     * @param array<string, string>     $errors
     */
    private function page(Request $request, Identity $identity, Website $website, ?array $form, array $errors, int $status, ?string $error = null): Response
    {
        $info = $this->views->row($website);
        $account = $this->views->account($website->accountId);
        $server = $this->servers->byId($website->serverId);
        $manager = $this->authorizer->allows($identity, 'websites.manage');
        $configures = $manager || ($this->authorizer->allows($identity, 'websites.configure') && $website->ownerUid === $identity->id);
        $url = fn(string $name): string => $this->pages->url($name, ['id' => $website->uid]);
        $seesOperations = $this->authorizer->allows($identity, 'servers.read');
        $settings = $form === null ? $website->settings : WebsiteSettings::normalize($form)['settings'] + $website->settings;

        return $this->pages->render($request, '@Websites/show', [
            'w' => $website,
            'info' => $info,
            'settings' => $settings,
            'errors' => $errors,
            'error' => $error,
            'codes' => WebsiteSettings::REDIRECT_CODES,
            'limits' => ['redirects' => WebsiteSettings::MAX_REDIRECTS, 'headers' => WebsiteSettings::MAX_HEADERS],
            'server_status' => $server?->status($this->agents->offlineAfter()),
            'server' => $server,
            'admin' => $this->access->seesAll($identity),
            'can_manage' => $manager,
            'can_configure' => $configures && $website->allows('update') && !$website->isEdited(),
            'configures' => $configures,
            'certificate' => $website->status === Website::DELETED ? null : $this->views->certificate($website),
            'can_certify' => $configures && $website->allows('certify') && !$website->isEdited(),
            'account_url' => $account !== null && ($this->accountAccess->seesAll($identity) || $account->ownerUid === $identity->id) ? $info['account']['url'] ?? null : null,
            'history' => $seesOperations ? $this->views->history($this->operations->forTarget('website', $website->uid, 10)) : [],
            'sees_operations' => $seesOperations,
            'watch' => $website->isBusy() ? $this->pages->url('api.v1.websites.show', ['id' => $website->uid]) : null,
            'index_url' => $this->pages->url('websites.index'),
            'actions' => [
                'settings' => $url('websites.settings'),
                'suspend' => $url('websites.suspend'),
                'unsuspend' => $url('websites.unsuspend'),
                'delete' => $url('websites.delete'),
                'retry' => $url('websites.retry'),
                'restore' => $url('websites.restore'),
                'certify' => $url('websites.certify'),
                'uncertify' => $url('websites.uncertify'),
                'check' => $this->pages->url('websites.check'),
            ],
        ], $status, $identity, 'websites');
    }

    /**
     * @param array{account: string, domains: string, document_root: string, php: bool, https_redirect: bool} $input
     * @param array<string, string> $errors
     */
    private function form(Request $request, Identity $identity, array $input, array $errors, ?string $error, int $status = 200): Response
    {
        $offline = $this->agents->offlineAfter();
        $accounts = [];

        foreach ($this->accounts->page(null, 'current') as $a) {
            if (!\in_array($a->status, [Account::ACTIVE, Account::SUSPENDED], true)) {
                continue;
            }

            $server = $this->servers->byId($a->serverId);
            $online = $server !== null && $server->status($offline) === 'online';
            $capable = $server !== null && isset($server->capabilities['website.create']);
            $accounts[] = [
                'id' => $a->uid,
                'label' => $a->label,
                'system_user' => $a->systemUser,
                'server' => $server !== null ? $server->name : '—',
                'suspended' => $a->status === Account::SUSPENDED,
                'usable' => $online && $capable,
                'note' => !$online ? 'server offline' : (!$capable ? 'agent too old' : ''),
            ];
        }

        return $this->pages->render($request, '@Websites/create', [
            'input' => $input,
            'errors' => $errors,
            'error' => $error,
            'accounts' => $accounts,
            'store_url' => $this->pages->url('websites.store'),
            'index_url' => $this->pages->url('websites.index'),
            'accounts_url' => $this->pages->url('accounts.index'),
        ], $status, $identity, 'websites');
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? \trim($value) : '';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return \is_array($value) ? \array_values(\array_filter($value, 'is_array')) : [];
    }
}
