<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountActions;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Hosting\Http\AccountViews;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\OperationException;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * accounts:create, accounts:list, accounts:suspend, accounts:unsuspend,
 * accounts:delete -- for an administrator with a shell on the panel host, and
 * for the package test. Audited as the console.
 */
final class AccountCommands
{
    public function __construct(
        private readonly AccountActions $actions,
        private readonly AccountStore $accounts,
        private readonly AccountViews $views,
        private readonly ServerStore $servers,
        private readonly UserStore $users,
    ) {}

    public function create(Output $output, string $server, string $owner, string $label, bool $wait = false, int $timeout = 120): int
    {
        $found = $this->server($output, $server);
        $user = $this->users->byLogin($owner) ?? $this->users->byUid($owner);

        if ($found === null) {
            return 1;
        }

        if ($user === null) {
            $output->line(\sprintf('No panel user "%s".', $owner));

            return 1;
        }

        try {
            $account = $this->actions->create(self::console(), $found, $user, $label);
        } catch (OperationException $e) {
            $output->line(\sprintf('%s: %s', $e->errorCode, $e->getMessage()));

            return 1;
        }

        return $this->finish($output, $account, $wait, $timeout, Account::ACTIVE);
    }

    public function list(Output $output, bool $json = false, string $status = 'current'): int
    {
        if (!isset(AccountStore::GROUPS[$status])) {
            $output->line('--status is one of ' . \implode(', ', \array_keys(AccountStore::GROUPS)) . '.');

            return 1;
        }

        $accounts = $this->accounts->page(null, $status);

        if ($json) {
            $output->line(\json_encode(\array_map($this->views->present(...), $accounts), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return 0;
        }

        foreach ($accounts as $a) {
            $output->line(\sprintf('%s  %-10s  %-12s  %s', $a->uid, $a->systemUser, $a->status, $a->label));
        }

        return 0;
    }

    public function suspend(Output $output, string $account, bool $wait = false, int $timeout = 120): int
    {
        return $this->act($output, $account, 'suspend', null, $wait, $timeout, Account::SUSPENDED);
    }

    public function unsuspend(Output $output, string $account, bool $wait = false, int $timeout = 120): int
    {
        return $this->act($output, $account, 'unsuspend', null, $wait, $timeout, Account::ACTIVE);
    }

    public function delete(Output $output, string $account, ?string $confirm = null, bool $wait = false, int $timeout = 300): int
    {
        return $this->act($output, $account, 'delete', $confirm, $wait, $timeout, Account::DELETED);
    }

    private function act(Output $output, string $id, string $action, ?string $confirm, bool $wait, int $timeout, string $goal): int
    {
        $account = $this->find($id);

        if ($account === null) {
            $output->line(\sprintf('No account "%s" (give its id or its system user).', $id));

            return 1;
        }

        try {
            $account = $this->actions->act(self::console(), $account, $action, $confirm);
        } catch (OperationException $e) {
            $output->line(\sprintf('%s: %s', $e->errorCode, $e->getMessage()));

            return 1;
        }

        return $this->finish($output, $account, $wait, $timeout, $goal);
    }

    /** Print it; with $wait, once it settles (exit 0 only if it reached $goal). */
    private function finish(Output $output, Account $account, bool $wait, int $timeout, string $goal): int
    {
        $deadline = \time() + \max(1, \min(3600, $timeout));

        while ($wait && $account->isBusy() && \time() < $deadline) {
            \usleep(250000);
            $account = $this->accounts->byUid($account->uid) ?? $account;
        }

        $output->line(\sprintf('%s  %s  %s%s', $account->uid, $account->systemUser, $account->status, $account->lastError === null ? '' : '  ' . $account->lastError));

        return !$wait || $account->status === $goal ? 0 : 1;
    }

    private function find(string $id): ?Account
    {
        $account = $this->accounts->byUid($id);

        if ($account !== null) {
            return $account;
        }

        foreach ($this->accounts->page(null, 'current') as $a) {
            if ($a->systemUser === $id) {
                return $a;
            }
        }

        return null;
    }

    private function server(Output $output, string $id): ?Server
    {
        $server = $this->servers->byUid($id);

        foreach ($server === null ? $this->servers->all() : [] as $s) {
            if ($s->name === $id) {
                $server = $s;

                break;
            }
        }

        if ($server === null) {
            $output->line(\sprintf('No server "%s".', $id));
        }

        return $server;
    }

    private static function console(): Identity
    {
        return new Identity('console', 'Panel console', [], ['system' => true]);
    }
}
