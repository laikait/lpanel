<?php

declare(strict_types=1);

namespace App\Modules\Files\Ssh;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;

/**
 * Keeps each account's SSH keys on the server in step with the panel, as
 * DbSync does for databases: the agent takes the whole list at once
 * (ssh.apply); a changed list gets a new revision and operation id,
 * recorded before it is sent; ssh:settle sends it again until the agent
 * answers, and the agent's journal runs it once.
 */
final class SshSync
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly SshKeys $keys,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Bring an account's keys in step. Accounts being made or removed are
     * left alone: their operation in flight comes first.
     */
    public function touch(int $accountId, bool $force = false): SshOutcome
    {
        $account = $this->accounts->byId($accountId);

        if ($account === null || !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return SshOutcome::skipped();
        }

        $row = $this->row($accountId);
        $args = \json_encode($this->keys->state($account), \JSON_THROW_ON_ERROR);

        if ($row['op_args'] === $args && $row['state'] === self::APPLIED && !$force) {
            return SshOutcome::done();
        }

        if ($row['op_args'] !== $args || $row['state'] === self::FAILED || $force) {
            $this->table()->where('id', (int) $row['id'])->update([
                'revision' => (int) $row['revision'] + 1,
                'op_id' => Protocol::newId(),
                'op_args' => $args,
                'state' => self::PENDING,
                'error' => null,
                'changed_at' => Accounts::now(),
            ]);
            $row = $this->row($accountId);
        }

        return $this->send($row);
    }

    /**
     * Send every list the agent has not answered yet.
     *
     * @return array<string, int> outcome => count
     */
    public function settleAll(): array
    {
        $counts = [];

        foreach ($this->table()->where('state', self::PENDING)->get() as $row) {
            $outcome = $this->send($row);
            $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function stateOf(int $accountId): ?array
    {
        return $this->table()->where('account_id', $accountId)->first();
    }

    /** @return list<int> accounts the server has a list for */
    public function accountIds(): array
    {
        return \array_map(static fn(array $r): int => (int) $r['account_id'], $this->table()->get());
    }

    /** Forget a terminated account; the agent took its keys away itself. */
    public function forget(int $accountId): void
    {
        $this->keys->deleteAccount($accountId);
        $this->table()->where('account_id', $accountId)->delete();
    }

    /** @param array<string, mixed> $row */
    private function send(array $row): SshOutcome
    {
        $args = \json_decode((string) $row['op_args'], true);

        try {
            $reply = $this->agent->call('ssh.apply', \is_array($args) ? $args : [], id: (string) $row['op_id'], timeout: 40.0);
        } catch (AgentUnavailable $e) {
            return SshOutcome::pending($e->getMessage());
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return SshOutcome::pending((string) $reply->errorMessage);
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return SshOutcome::pending();
        }

        $current = $this->table()->where('id', (int) $row['id'])->where('op_id', (string) $row['op_id']);

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $current->update(['state' => self::FAILED, 'error' => $code . ': ' . $message]);

            return SshOutcome::failed($code, $message);
        }

        $current->update([
            'state' => self::APPLIED,
            'applied_revision' => (int) $row['revision'],
            'error' => null,
            'applied_at' => Accounts::now(),
        ]);

        return SshOutcome::done($reply->result);
    }

    /** @return array<string, mixed> */
    private function row(int $accountId): array
    {
        $row = $this->stateOf($accountId);

        if ($row === null) {
            $this->table()->insert(['account_id' => $accountId, 'revision' => 0, 'applied_revision' => 0, 'state' => self::APPLIED]);
            $row = $this->stateOf($accountId) ?? throw new \LogicException('SSH key state vanished');
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ssh_accounts');
    }
}
