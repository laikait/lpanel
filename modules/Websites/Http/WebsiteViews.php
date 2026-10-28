<?php

declare(strict_types=1);

namespace App\Modules\Websites\Http;

use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\Http\OperationViews;
use App\Modules\Operations\Store\Operation;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;
use App\Modules\Websites\Sites\Website;

/** What the website screens and the API show: a website with its account, server and owner by name. */
final class WebsiteViews
{
    /** What each status means, on screen. */
    public const STATUSES = [
        Website::PROVISIONING => 'Being set up: its Nginx file is staged, tested, then served.',
        Website::ACTIVE => 'Served by Nginx, from the settings below.',
        Website::UPDATING => 'New settings are being tested and applied.',
        Website::SUSPENDING => 'Being suspended.',
        Website::SUSPENDED => 'Every request is answered "temporarily unavailable". Its files are kept.',
        Website::RESUMING => 'Being unsuspended.',
        Website::DELETING => 'Its Nginx configuration is being removed.',
        Website::DELETED => 'No longer served. Its files stayed in the account\'s web folder.',
        Website::FAILED => 'Setting it up did not finish.',
    ];

    /** @var array<int, ?Account> */
    private array $accountCache = [];

    /** @var array<int, ?array{name: string, url: string, uid: string}> */
    private array $serverCache = [];

    /** @var array<string, ?array{name: string, login: string}> */
    private array $ownerCache = [];

    public function __construct(
        private readonly AccountStore $accounts,
        private readonly ServerStore $servers,
        private readonly UserStore $users,
        private readonly OperationViews $operations,
        private readonly Pages $pages,
    ) {}

    /**
     * @param list<Website> $websites
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $websites): array
    {
        return \array_map($this->row(...), $websites);
    }

    /** @return array<string, mixed> */
    public function row(Website $w): array
    {
        $account = $this->account($w->accountId);
        $accountSuspended = $account !== null && \in_array($account->status, [Account::SUSPENDED, Account::SUSPENDING], true);

        return [
            'website' => $w,
            'url' => $this->pages->url('websites.show', ['id' => $w->uid]),
            'account' => $account === null ? null : [
                'label' => $account->label,
                'system_user' => $account->systemUser,
                'status' => $account->status,
                'php_version' => $account->phpVersion,
                'url' => $this->pages->url('accounts.show', ['id' => $account->uid]),
            ],
            'server' => $this->server($w->serverId),
            'owner' => $this->owner($w->ownerUid),
            'meaning' => self::STATUSES[$w->status] ?? '',
            // Served as 503 because its account is suspended, whatever its own status.
            'account_suspended' => $accountSuspended && $w->status !== Website::DELETED,
            'aliases' => \array_slice($w->domains(), 1),
            'document_root' => $account === null ? null : $w->documentRoot($account->systemUser),
            'php' => ($w->settings['php'] ?? false) === true,
        ];
    }

    /** @return array<string, mixed> the API's website */
    public function present(Website $w): array
    {
        $account = $this->account($w->accountId);
        $server = $this->server($w->serverId);
        $owner = $this->owner($w->ownerUid);

        return $w->toArray() + [
            'document_root' => $account === null ? null : $w->documentRoot($account->systemUser),
            'account' => $account === null ? null : ['id' => $account->uid, 'label' => $account->label, 'system_user' => $account->systemUser, 'status' => $account->status],
            'server' => $server === null ? null : ['id' => $server['uid'], 'name' => $server['name']],
            'owner' => $owner === null ? null : ['id' => $w->ownerUid, 'login' => $owner['login'], 'name' => $owner['name']],
        ];
    }

    /**
     * @param list<Operation> $ops
     *
     * @return list<array<string, mixed>>
     */
    public function history(array $ops): array
    {
        return \array_map(fn(Operation $op): array => $this->operations->row($op, null), $ops);
    }

    public function account(int $id): ?Account
    {
        if (!\array_key_exists($id, $this->accountCache)) {
            $this->accountCache[$id] = $this->accounts->byId($id);
        }

        return $this->accountCache[$id];
    }

    /** @return ?array{name: string, url: string, uid: string} */
    private function server(int $id): ?array
    {
        if (!\array_key_exists($id, $this->serverCache)) {
            $s = $this->servers->byId($id);
            $this->serverCache[$id] = $s === null ? null : ['name' => $s->name, 'uid' => $s->uid, 'url' => $this->pages->url('servers.show', ['id' => $s->uid])];
        }

        return $this->serverCache[$id];
    }

    /** @return ?array{name: string, login: string} */
    private function owner(string $uid): ?array
    {
        if (!\array_key_exists($uid, $this->ownerCache)) {
            $u = $this->users->byUid($uid);
            $this->ownerCache[$uid] = $u === null ? null : ['name' => $u->name, 'login' => $u->login];
        }

        return $this->ownerCache[$uid];
    }
}
