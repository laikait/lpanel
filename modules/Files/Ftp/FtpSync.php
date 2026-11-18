<?php

declare(strict_types=1);

namespace App\Modules\Files\Ftp;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;

/**
 * Keeps each account's FTP logins on the server in step with the panel, as
 * DbSync does for databases: the agent takes the whole list at once
 * (ftp.apply); a changed list gets a new revision and operation id,
 * recorded before it is sent; ftp:settle sends it again until the agent
 * answers, and the agent's journal runs it once.
 */
final class FtpSync
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly FtpUsers $users,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Bring an account's logins in step. Accounts being made or removed are
     * left alone: their operation in flight comes first.
     */
    public function touch(int $accountId, bool $force = false): FtpOutcome
    {
        $account = $this->accounts->byId($accountId);

        if ($account === null || !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return FtpOutcome::skipped();
        }

        $row = $this->row($accountId);
        $args = \json_encode($this->users->state($account), \JSON_THROW_ON_ERROR);

        if ($row['op_args'] === $args && $row['state'] === self::APPLIED && !$force) {
            return FtpOutcome::done();
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

    /** Forget a terminated account; the agent took its logins away itself. */
    public function forget(int $accountId): void
    {
        $this->users->deleteAccount($accountId);
        $this->table()->where('account_id', $accountId)->delete();
    }

    /** @param array<string, mixed> $row */
    private function send(array $row): FtpOutcome
    {
        $args = \json_decode((string) $row['op_args'], true);

        try {
            $reply = $this->agent->call('ftp.apply', \is_array($args) ? $args : [], id: (string) $row['op_id'], timeout: 70.0);
        } catch (AgentUnavailable $e) {
            return FtpOutcome::pending($e->getMessage());
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return FtpOutcome::pending((string) $reply->errorMessage);
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return FtpOutcome::pending();
        }

        $current = $this->table()->where('id', (int) $row['id'])->where('op_id', (string) $row['op_id']);

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $current->update(['state' => self::FAILED, 'error' => $code . ': ' . $message]);

            return FtpOutcome::failed($code, $message);
        }

        $current->update([
            'state' => self::APPLIED,
            'applied_revision' => (int) $row['revision'],
            'error' => null,
            'applied_at' => Accounts::now(),
        ]);

        return FtpOutcome::done($reply->result);
    }

    /** @return array<string, mixed> */
    private function row(int $accountId): array
    {
        $row = $this->stateOf($accountId);

        if ($row === null) {
            $this->table()->insert(['account_id' => $accountId, 'revision' => 0, 'applied_revision' => 0, 'state' => self::APPLIED]);
            $row = $this->stateOf($accountId) ?? throw new \LogicException('FTP state vanished');
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ftp_accounts');
    }
}
