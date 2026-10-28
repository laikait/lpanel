<?php

declare(strict_types=1);

namespace App\Modules\Hosting\Accounts;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Users\User;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\Operation;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * Creating, suspending, unsuspending and deleting hosting accounts
 * (ADR-0013), and settling each when its operation finishes.
 *
 * The account's new status and its operation are written in one transaction
 * (ARCHITECTURE.md §2): if the operation is refused (the server is offline,
 * its agent too old), nothing is kept. Only accounts.manage may ask, which
 * root and administrators have and customers do not.
 */
final class AccountActions
{
    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

    public function __construct(
        private readonly AccountStore $accounts,
        private readonly OperationRequests $requests,
        private readonly ServerStore $servers,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
        private readonly FilterEngine $filters,
    ) {}

    /** @throws OperationException */
    public function create(Identity $by, Server $server, User $owner, string $label): Account
    {
        $this->mayManage($by, 'create', null);
        $label = \trim($label);

        if (\preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &\'.,_-]{0,99}$/u', $label) !== 1) {
            throw new OperationException('label_invalid', 'Name the account with up to 100 letters, digits, spaces and . , & \' _ -.', 422);
        }

        if ($owner->isRoot() || !$owner->isActive() || !\in_array('customer', $owner->roles, true)) {
            throw new OperationException('owner_invalid', 'An account belongs to an active customer. Give the person the customer role first.', 422);
        }

        if ($server->state !== Server::ACTIVE) {
            throw new OperationException('server_not_paired', 'Accounts are made on a server whose agent is paired.', 409);
        }

        $account = $this->accounts->transaction(function () use ($by, $server, $owner, $label): Account {
            $account = $this->accounts->create($server->id, $owner->uid, $label, $this->systemUser($server), self::isConsole($by) ? null : $by->id);
            $this->start($by, $server, $account, 'create');

            return $account;
        });

        return $this->accounts->byUid($account->uid) ?? $account;
    }

    /**
     * suspend, unsuspend, delete, or create again after it failed. Deleting
     * asks for the account's user name, typed: there is no undo.
     *
     * @throws OperationException
     */
    public function act(Identity $by, Account $account, string $action, ?string $confirm = null): Account
    {
        $this->mayManage($by, $action, $account);

        if (!isset(Account::ACTIONS[$action])) {
            throw new OperationException('unknown_action', 'Accounts can be suspended, unsuspended, deleted, or created again.', 422);
        }

        if (!$account->allows($action)) {
            throw new OperationException('account_busy', $account->isBusy()
                ? 'Something is already under way for this account. Wait for it to finish.'
                : \sprintf('An account that is %s cannot be %s.', $account->status, ['create' => 'created again', 'suspend' => 'suspended', 'unsuspend' => 'unsuspended', 'delete' => 'deleted'][$action]), 409);
        }

        if ($action === 'delete' && $confirm !== $account->systemUser) {
            throw new OperationException('confirmation_required', \sprintf('Type the account\'s user name, %s, to delete it and every file in it.', $account->systemUser), 422);
        }

        // Other modules' reasons not to do it yet (Websites: websites still
        // served from it stop a delete; a hand-edited one stops a suspension).
        $blockers = $this->filters->apply('hosting.account.blockers', [], $account, $action);

        if (\is_array($blockers) && $blockers !== []) {
            throw new OperationException('account_in_use', \implode(' ', \array_map('strval', $blockers)), 409);
        }

        $server = $this->servers->byId($account->serverId) ?? throw new \LogicException('An account without its server.');

        $this->accounts->transaction(function () use ($by, $server, $account, $action): void {
            $this->start($by, $server, $account, $action);
        });

        return $this->accounts->byUid($account->uid) ?? $account;
    }

    /**
     * An account's operation finished (the operations.finished hook, in the
     * operation's own transaction). Success moves the account on; anything
     * else puts it back where it was, or marks a failed creation, with the
     * reason kept for the screen.
     */
    public function settle(Operation $op): void
    {
        if ($op->targetType !== 'account') {
            return;
        }

        $account = $this->accounts->byUid($op->targetUid);

        if ($account === null || $account->operationUid !== $op->uid || !$account->isBusy()) {
            return;
        }

        $action = $account->lastAction;
        $result = $op->resultArray() ?? [];
        // A result about another user is not this account's, whatever its
        // status says (THREAT_MODEL T5): it settles nothing as done.
        $forged = $op->status === Operation::SUCCEEDED && ($result['system_user'] ?? null) !== $account->systemUser;

        if ($op->status === Operation::SUCCEEDED && !$forged) {
            $changes = $action === 'create' ? [
                'system_uid' => (int) ($result['uid'] ?? 0),
                'system_gid' => (int) ($result['gid'] ?? 0),
                'php_version' => (string) ($result['php_version'] ?? ''),
            ] : [];
            $to = ['create' => Account::ACTIVE, 'suspend' => Account::SUSPENDED, 'unsuspend' => Account::ACTIVE, 'delete' => Account::DELETED][$action];

            if ($this->accounts->settle($account, $op->uid, $to, $changes)) {
                $this->audit->record('account.' . $action, 'succeeded', null, 'account', $account->uid, ['operation' => $op->uid]);
            }

            return;
        }

        $back = $action === 'create' ? Account::FAILED : ($account->previousStatus ?? Account::FAILED);
        $message = $forged ? 'The agent reported on another account. Nothing was recorded; check the server, then try again.' : match ($op->status) {
            Operation::NEEDS_ATTENTION => 'The server may have changed part-way: ' . ($op->message ?? 'the agent stopped reporting.') . ' Trying again is safe.',
            Operation::EXPIRED => 'The server\'s agent did not pick it up in time. Is the server online?',
            Operation::CANCELLED => $op->errorCode === 'agent_revoked' ? 'The server\'s agent was revoked before it finished.' : 'Cancelled.',
            default => $op->message ?? 'The operation failed.',
        };
        $code = $forged ? 'invalid_result' : ($op->errorCode ?? $op->status);

        if ($this->accounts->settle($account, $op->uid, $back, ['last_error_code' => $code, 'last_error' => \mb_substr($message, 0, 500)])) {
            $this->audit->record('account.' . $action, 'failed', null, 'account', $account->uid, ['operation' => $op->uid, 'error_code' => $code]);
        }
    }

    /** @throws OperationException */
    private function start(Identity $by, Server $server, Account $account, string $action): void
    {
        [$op] = $this->requests->request(
            $by,
            $server,
            Account::ACTIONS[$action]['op'],
            null,
            (object) ['system_user' => $account->systemUser],
            ['type' => 'account', 'uid' => $account->uid],
        );

        if (!$this->accounts->begin($account, $action, $op->uid)) {
            throw new OperationException('account_busy', 'The account changed meanwhile. Look again, then try.', 409);
        }

        $this->audit->record('account.' . $action, 'allowed', $by, 'account', $account->uid, [
            'server' => $server->uid,
            'system_user' => $account->systemUser,
            'operation' => $op->uid,
        ]);
    }

    /** @throws OperationException */
    private function mayManage(Identity $by, string $action, ?Account $account): void
    {
        if (self::isConsole($by) || $this->authorizer->allows($by, 'accounts.manage')) {
            return;
        }

        $this->audit->record('account.' . $action, 'denied', $by, 'account', $account?->uid, ['reason' => 'accounts.manage']);

        throw new OperationException('forbidden', 'Only administrators manage hosting accounts.', 403);
    }

    /** lp and 8 random characters of base32, not yet used on $server. */
    private function systemUser(Server $server): string
    {
        for ($try = 0; $try < 20; ++$try) {
            $name = 'lp';

            for ($i = 0; $i < 8; ++$i) {
                $name .= self::BASE32[\random_int(0, 31)];
            }

            if (!$this->accounts->systemUserTaken($server->id, $name)) {
                return $name;
            }
        }

        throw new \RuntimeException('No free account user name after 20 tries.');
    }

    private static function isConsole(Identity $identity): bool
    {
        return $identity->attribute('system') === true;
    }
}
