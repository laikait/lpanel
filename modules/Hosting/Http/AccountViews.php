<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Http;

use App\Modules\Hosting\Accounts\Account;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\Http\OperationViews;
use App\Modules\Operations\Store\Operation;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Shared\Ui\Pages;

/** What the account screens and the API show about an account: its server and owner by name. */
final class AccountViews
{
    /** What each status means, on screen. */
    public const STATUSES = [
        Account::PROVISIONING => 'Being set up on the server.',
        Account::ACTIVE => 'Ready: its user, home and PHP pool exist.',
        Account::SUSPENDING => 'Being suspended.',
        Account::SUSPENDED => 'No logins, no PHP. Its files are kept.',
        Account::RESUMING => 'Being unsuspended.',
        Account::DELETING => 'Being deleted, with every file in it.',
        Account::DELETED => 'Deleted from the server.',
        Account::FAILED => 'Setting it up did not finish.',
    ];

    /** @var array<int, ?array{name: string, url: string, uid: string}> */
    private array $serverCache = [];

    /** @var array<string, ?array{name: string, login: string}> */
    private array $ownerCache = [];

    public function __construct(
        private readonly ServerStore $servers,
        private readonly UserStore $users,
        private readonly OperationViews $operations,
        private readonly Pages $pages,
    ) {}

    /**
     * @param list<Account> $accounts
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $accounts): array
    {
        return \array_map($this->row(...), $accounts);
    }

    /** @return array<string, mixed> */
    public function row(Account $a): array
    {
        return [
            'account' => $a,
            'url' => $this->pages->url('accounts.show', ['id' => $a->uid]),
            'server' => $this->server($a->serverId),
            'owner' => $this->owner($a->ownerUid),
            'meaning' => self::STATUSES[$a->status] ?? '',
        ];
    }

    /** @return array<string, mixed> the API's account: its fields, server and owner */
    public function present(Account $a): array
    {
        $server = $this->server($a->serverId);
        $owner = $this->owner($a->ownerUid);

        return $a->toArray() + [
            'server' => $server === null ? null : ['id' => $server['uid'], 'name' => $server['name']],
            'owner' => $owner === null ? null : ['id' => $a->ownerUid, 'login' => $owner['login'], 'name' => $owner['name']],
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
