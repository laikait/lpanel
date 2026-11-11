<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Engine\Auth\Identity;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Security\Secret;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * Carries account changes out on the server through the agent.
 *
 * Every change follows the same steps:
 *  1. The operation, its arguments and a new operation id are written to the
 *     account's row before anything is sent.
 *  2. The agent is asked. Its journal runs an operation once per id.
 *  3. The answer is applied to the row. If the agent could not be reached,
 *     the row keeps the operation, and `accounts:settle` (every minute) asks
 *     again with the same id and arguments until there is an answer. A crash
 *     of the panel between 2 and 3 is therefore harmless: the retry gets the
 *     first outcome instead of doing the work twice.
 *
 * One change at a time per account: a new one is refused while another is in
 * flight.
 *
 * When an operation is done, the accounts.changed hook (string $op, array
 * $row) tells other modules, with the account's row as it now is (as it was,
 * for account.terminate, whose row is gone).
 */
final class Provisioner
{
    /** Agent answers that mean "not now"; the same request is sent again later. */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    /** A create that failed with these may have left something on the server, so the row is kept. */
    private const UNCERTAIN = ['interrupted', 'timeout'];

    public function __construct(
        private readonly Accounts $accounts,
        private readonly Packages $packages,
        private readonly PanelUsers $users,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly AccountRules $rules,
        private readonly HookEngine $hooks,
    ) {}

    /**
     * Create an account from AccountRules::validateNew() values. On a definite
     * failure nothing is left behind, in the panel or on the server.
     *
     * @param array<string, mixed> $clean
     */
    public function create(Identity $actor, ?Request $request, array $clean): Outcome
    {
        $package = $this->packages->byId((int) $clean['package_id']) ?? throw new \LogicException('validated package vanished');
        $password = $clean['password'] instanceof Secret ? $clean['password'] : new Secret((string) $clean['password']);
        $loginId = $this->users->create(Kind::User, $clean['username'], $clean['name'], $clean['email'], $password, $clean['owner_id']);
        $id = $this->accounts->insert([
            'login_id' => (int) $loginId,
            'username' => $clean['username'],
            'domain' => $clean['domain'],
            'package_id' => (int) $package['id'],
            'owner_id' => $clean['owner_id'],
            'status' => Accounts::CREATING,
        ]);

        $outcome = $this->start($id, 'account.create', ['username' => $clean['username']] + self::limits($package));

        if ($outcome->status === Outcome::FAILED && !\in_array($outcome->code, self::UNCERTAIN, true)) {
            // The agent undid its own steps; undo ours.
            $this->users->delete($loginId);
            $this->accounts->delete($id);
        }

        $this->audit->record('account.create', $actor, $request, $clean['username'], self::auditOutcome($outcome), [
            'domain' => $clean['domain'], 'package' => $package['name'], 'owner_id' => $clean['owner_id'],
        ] + self::details($outcome));

        return $outcome;
    }

    /** Put an account on another package: new quota and shell, now. */
    public function changePackage(Identity $actor, ?Request $request, int $id, int $packageId): Outcome
    {
        $row = $this->row($id);
        $package = $this->packages->byId($packageId) ?? throw new \LogicException('validated package vanished');

        $limit = $this->rules->overLimit($row['owner_id'] === null ? null : (int) $row['owner_id'], $package, $row);
        $outcome = match (true) {
            $row['op_id'] !== null => Outcome::busy($id),
            !\in_array($row['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true) => Outcome::refused($id, 'Accounts.refused_status'),
            $limit !== null => Outcome::refused($id, $limit),
            default => $this->start($id, 'account.modify', ['username' => $row['username']] + self::limits($package), ['package_id' => $packageId]),
        };
        $this->audit->record('account.package', $actor, $request, (string) $row['username'], self::auditOutcome($outcome), ['package' => $package['name']] + self::details($outcome));

        return $outcome;
    }

    /**
     * Apply a package's changed limits to every account on it (the
     * packages.updated hook). Accounts busy with another change keep their
     * old limits until the package is saved again.
     *
     * @param array<string, mixed> $package
     */
    public function applyPackage(array $package): void
    {
        foreach ($this->accounts->onPackage((int) $package['id']) as $row) {
            if ($row['op_id'] === null && \in_array($row['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
                $this->start((int) $row['id'], 'account.modify', ['username' => $row['username']] + self::limits($package), ['package_id' => (int) $package['id']]);
            }
        }
    }

    public function suspend(Identity $actor, ?Request $request, int $id, string $reason): Outcome
    {
        $row = $this->row($id);
        $reason = \mb_substr(\trim($reason), 0, 255);
        $outcome = match (true) {
            $row['op_id'] !== null => Outcome::busy($id),
            $row['status'] !== Accounts::ACTIVE => Outcome::refused($id, 'Accounts.refused_status'),
            default => $this->start($id, 'account.suspend', ['username' => $row['username']], ['reason' => $reason]),
        };
        $this->audit->record('account.suspend', $actor, $request, (string) $row['username'], self::auditOutcome($outcome), ['reason' => $reason] + self::details($outcome));

        return $outcome;
    }

    public function unsuspend(Identity $actor, ?Request $request, int $id): Outcome
    {
        $row = $this->row($id);
        $outcome = match (true) {
            $row['op_id'] !== null => Outcome::busy($id),
            $row['status'] !== Accounts::SUSPENDED => Outcome::refused($id, 'Accounts.refused_status'),
            default => $this->start($id, 'account.unsuspend', ['username' => $row['username']]),
        };
        $this->audit->record('account.unsuspend', $actor, $request, (string) $row['username'], self::auditOutcome($outcome), self::details($outcome));

        return $outcome;
    }

    /**
     * Remove the account from the server and the panel. The agent removes
     * the home in the background; the rows go when it reports done.
     */
    public function terminate(Identity $actor, ?Request $request, int $id): Outcome
    {
        $row = $this->row($id);
        $outcome = match (true) {
            $row['op_id'] !== null => Outcome::busy($id),
            $row['status'] === Accounts::TERMINATING => Outcome::refused($id, 'Accounts.refused_status'),
            default => $this->start($id, 'account.terminate', ['username' => $row['username']], ['previous' => $row['status']]),
        };
        $this->audit->record('account.terminate', $actor, $request, (string) $row['username'], self::auditOutcome($outcome), ['domain' => $row['domain']] + self::details($outcome));

        return $outcome;
    }

    public function setPassword(Identity $actor, ?Request $request, int $id, Secret $password): void
    {
        $row = $this->row($id);
        $this->users->setPassword((string) $row['login_id'], $password);
        $this->audit->record('account.password', $actor, $request, (string) $row['username']);
    }

    /**
     * Ask again about an account's operation in flight, if it has one.
     */
    public function settle(int $id): ?Outcome
    {
        $row = $this->accounts->byId($id);

        return $row === null || $row['op_id'] === null ? null : $this->send($row);
    }

    /**
     * Settle every account with an operation in flight.
     *
     * @return array<string, int> outcome status => count
     */
    public function settleAll(): array
    {
        $counts = [];

        foreach ($this->accounts->pending() as $row) {
            $outcome = $this->send($row);
            $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;

            if ($outcome->status === Outcome::FAILED) {
                $this->audit->record((string) $row['op'], null, null, (string) $row['username'], AuditLog::FAILED, ['settled' => true] + self::details($outcome));
            }
        }

        return $counts;
    }

    /**
     * Read disk use from the kernel's quotas for these accounts (or all).
     *
     * @param list<string> $usernames
     *
     * @return bool false when the agent could not be asked
     */
    public function refreshUsage(array $usernames = []): bool
    {
        try {
            $reply = $this->agent->call('account.info', $usernames === [] ? [] : ['usernames' => \array_values($usernames)], timeout: 30.0);
        } catch (AgentUnavailable) {
            return false;
        }

        if (!$reply->done()) {
            return false;
        }

        foreach (\is_array($reply->result['accounts'] ?? null) ? $reply->result['accounts'] : [] as $info) {
            $row = \is_array($info) ? $this->accounts->findByUsernameUnscoped((string) ($info['username'] ?? '')) : null;

            if ($row !== null) {
                $this->accounts->update((int) $row['id'], self::usage($info));
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------

    /**
     * Record an operation on the row, then send it.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $meta what the panel needs when the answer comes
     */
    private function start(int $id, string $op, array $args, array $meta = []): Outcome
    {
        $this->accounts->update($id, [
            'op' => $op,
            'op_id' => Protocol::newId(),
            'op_args' => \json_encode($args, \JSON_THROW_ON_ERROR),
            'op_meta' => \json_encode($meta, \JSON_THROW_ON_ERROR),
            'op_started_at' => Accounts::now(),
            'op_error' => null,
        ]);

        return $this->send($this->row($id));
    }

    /** @param array<string, mixed> $row */
    private function send(array $row): Outcome
    {
        $id = (int) $row['id'];
        $args = \json_decode((string) $row['op_args'], true);

        try {
            $reply = $this->agent->call((string) $row['op'], \is_array($args) ? $args : [], id: (string) $row['op_id'], timeout: 120.0);
        } catch (AgentUnavailable $e) {
            return Outcome::pending($id, $e->getMessage());
        }

        return $this->apply($row, $reply);
    }

    /** @param array<string, mixed> $row */
    private function apply(array $row, AgentReply $reply): Outcome
    {
        $id = (int) $row['id'];
        $op = (string) $row['op'];
        $meta = \json_decode((string) $row['op_meta'], true);
        $meta = \is_array($meta) ? $meta : [];

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            if ($op === 'account.terminate' && $row['status'] !== Accounts::TERMINATING) {
                $this->accounts->update($id, ['status' => Accounts::TERMINATING]);
            }

            return Outcome::pending($id);
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return Outcome::pending($id, (string) $reply->errorMessage);
        }

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $changes = self::clearOp() + ['op_error' => $code . ': ' . $message];

            if ($op === 'account.create') {
                $changes['status'] = Accounts::FAILED;
            } elseif ($op === 'account.terminate') {
                $changes['status'] = \is_string($meta['previous'] ?? null) ? $meta['previous'] : Accounts::FAILED;
            }

            $this->accounts->update($id, $changes);

            return Outcome::failed($id, $code, $message);
        }

        $result = $reply->result;

        switch ($op) {
            case 'account.create':
                $this->accounts->update($id, self::clearOp() + self::usage($result) + [
                    'status' => Accounts::ACTIVE,
                    'uid' => isset($result['uid']) ? (int) $result['uid'] : null,
                    'home' => isset($result['home']) ? (string) $result['home'] : null,
                ]);
                break;
            case 'account.modify':
                $this->accounts->update($id, self::clearOp() + self::usage($result) + (isset($meta['package_id']) ? ['package_id' => (int) $meta['package_id']] : []));
                break;
            case 'account.suspend':
                $this->accounts->update($id, self::clearOp() + ['status' => Accounts::SUSPENDED, 'suspend_reason' => ($meta['reason'] ?? '') !== '' ? $meta['reason'] : null]);
                $this->users->setSuspended($this->logins($row), true);
                break;
            case 'account.unsuspend':
                $this->accounts->update($id, self::clearOp() + ['status' => Accounts::ACTIVE, 'suspend_reason' => null]);
                $this->users->setSuspended($this->logins($row), false);
                break;
            case 'account.terminate':
                $this->users->delete((string) $row['login_id']);
                $this->accounts->delete($id);
                break;
        }

        $this->hooks->do('accounts.changed', $op, $this->accounts->byId($id) ?? $row);

        return Outcome::done($id);
    }

    /**
     * The owner's login and its sub-users'.
     *
     * @param array<string, mixed> $row
     *
     * @return list<int>
     */
    private function logins(array $row): array
    {
        $ids = [(int) $row['login_id']];

        foreach ($this->users->children((string) $row['login_id']) as $child) {
            $ids[] = (int) $child['id'];
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->accounts->byId($id) ?? throw new \LogicException('No account ' . $id);
    }

    /**
     * The agent arguments a package sets: disk and inode quota, and the shell.
     *
     * @param array<string, mixed> $package
     *
     * @return array{shell: string, quota: array{blocks_kib: int, inodes: int}}
     */
    public static function limits(array $package): array
    {
        return [
            'shell' => Packages::has($package, 'ssh') ? 'bash' : 'none',
            'quota' => ['blocks_kib' => (int) $package['disk_mb'] * 1024, 'inodes' => (int) $package['inodes']],
        ];
    }

    /**
     * @param array<mixed> $result an agent account description
     *
     * @return array<string, mixed>
     */
    private static function usage(array $result): array
    {
        $usage = $result['usage'] ?? null;

        if (!\is_array($usage)) {
            return [];
        }

        return [
            'disk_used_bytes' => (int) ($usage['used_bytes'] ?? 0),
            'inodes_used' => (int) ($usage['used_inodes'] ?? 0),
            'usage_at' => Accounts::now(),
        ];
    }

    /** @return array<string, null> */
    private static function clearOp(): array
    {
        return ['op' => null, 'op_id' => null, 'op_args' => null, 'op_meta' => null, 'op_started_at' => null, 'op_error' => null];
    }

    private static function auditOutcome(Outcome $outcome): string
    {
        return match ($outcome->status) {
            Outcome::DONE, Outcome::PENDING => AuditLog::OK,
            Outcome::FAILED => AuditLog::FAILED,
            default => AuditLog::DENIED,
        };
    }

    /** @return array<string, mixed> */
    private static function details(Outcome $outcome): array
    {
        return match ($outcome->status) {
            Outcome::DONE => [],
            Outcome::PENDING => ['pending' => true],
            default => ['reason' => $outcome->code, 'error' => $outcome->message],
        };
    }
}
