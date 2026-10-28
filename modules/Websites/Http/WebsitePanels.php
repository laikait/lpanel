<?php

declare(strict_types=1);

namespace App\Modules\Websites\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Store\Server;
use App\Modules\Shared\Ui\Pages;
use App\Modules\Websites\Sites\Website;
use App\Modules\Websites\Sites\WebsiteStore;

/**
 * The cards Websites adds to other pages: an account's websites (the
 * hosting.account.panels filter), and a server's website configuration
 * check (servers.detail.panels).
 */
final class WebsitePanels
{
    public function __construct(
        private readonly WebsiteStore $websites,
        private readonly WebsiteViews $views,
        private readonly OperationStore $operations,
        private readonly AgentSettings $agents,
        private readonly Authorizer $authorizer,
        private readonly Pages $pages,
    ) {}

    /**
     * @param array<mixed> $panels
     *
     * @return array<mixed>
     */
    public function account(array $panels, Account $account, Identity $identity): array
    {
        if (!$this->authorizer->allows($identity, 'websites.own') && !$this->authorizer->allows($identity, 'websites.read')) {
            return $panels;
        }

        $panels[] = ['template' => '@Websites/account_panel.twig', 'column' => 'main', 'context' => [
            'site_rows' => $this->views->rows($this->websites->page(null, 'current', accountId: $account->id)),
            'site_new_url' => $this->authorizer->allows($identity, 'websites.manage') && \in_array($account->status, [Account::ACTIVE, Account::SUSPENDED], true)
                ? $this->pages->url('websites.create') . '?account=' . $account->uid : null,
            'site_account_suspended' => $account->status === Account::SUSPENDED,
        ]];

        return $panels;
    }

    /**
     * @param array<mixed> $panels
     *
     * @return array<mixed>
     */
    public function server(array $panels, Server $server, Identity $identity): array
    {
        if ($server->state === Server::PENDING || !$this->authorizer->allows($identity, 'websites.read')) {
            return $panels;
        }

        $last = $this->operations->latestSucceeded($server, 'websites.check');
        $result = $last?->resultArray();
        $sites = $this->websites->page(null, 'current', $server->id);
        $edited = \array_values(\array_filter($sites, static fn(Website $w): bool => $w->isEdited()));

        $panels[] = ['template' => '@Websites/server_panel.twig', 'column' => 'side', 'context' => [
            'site_server' => $server,
            'site_count' => \count($sites),
            'site_edited' => $this->views->rows($edited),
            'site_check' => $last === null ? null : [
                'at' => $last->completedAt,
                'url' => $this->pages->url('operations.show', ['id' => $last->uid]),
                'checked' => (int) ($result['checked'] ?? 0),
                'unknown' => \is_array($result['unknown_files'] ?? null) ? $result['unknown_files'] : [],
                'include' => (string) ($result['include'] ?? 'none'),
                'nginx_test' => (string) ($result['nginx_test'] ?? 'not_run'),
                'reloaded' => ($result['reloaded'] ?? false) === true,
            ],
            'site_can_check' => $this->authorizer->allows($identity, 'websites.manage')
                && $server->status($this->agents->offlineAfter()) === 'online'
                && \in_array(1, $server->capabilities['websites.check'] ?? [], true),
            'site_check_url' => $this->pages->url('websites.check'),
            'site_index_url' => $this->pages->url('websites.index'),
        ]];

        return $panels;
    }
}
