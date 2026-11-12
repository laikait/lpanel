<?php

declare(strict_types=1);

namespace App\Modules\Databases;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;

/**
 * Keeps each account's databases and users on the server in step with the
 * panel's tables, the way WebSync does for sites: the agent takes the whole
 * state at once (db.apply); a changed state gets a new revision and
 * operation id, recorded before it is sent; db:settle sends it again until
 * the agent answers, and the agent's journal runs it once.
 */
final class DbSync
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Databases $databases,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Bring an account's databases in step. Accounts being made or removed
     * are left alone: their operation in flight comes first.
     */
    public function touch(int $accountId, bool $force = false): DbOutcome
    {
        $account = $this->accounts->byId($accountId);

        if ($account === null || !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return DbOutcome::skipped();
        }

        $row = $this->row($accountId);
        $args = \json_encode($this->databases->state($account), \JSON_THROW_ON_ERROR);

        if ($row['op_args'] === $args && $row['state'] === self::APPLIED && !$force) {
            return DbOutcome::done();
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
     * Send every state the agent has not answered yet.
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

    /** True when the server has the account's latest state (or it has none to have). */
    public function settled(int $accountId): bool
    {
        $row = $this->stateOf($accountId);

        return $row === null || $row['state'] !== self::PENDING;
    }

    /** @return array<string, mixed>|null */
    public function stateOf(int $accountId): ?array
    {
        return $this->table()->where('account_id', $accountId)->first();
    }

    /** @param array<string, mixed> $changes */
    public function mark(int $accountId, array $changes): void
    {
        $this->row($accountId);
        $this->table()->where('account_id', $accountId)->update($changes);
    }

    /** @return list<array<string, mixed>> state rows matching a column test */
    public function rowsWhere(string $column, string $operator, mixed $value): array
    {
        return $this->table()->where($column, $operator, $value)->get();
    }

    /** Forget a terminated account; the agent dropped its databases itself. */
    public function forget(int $accountId): void
    {
        $this->databases->deleteAccount($accountId);
        $this->table()->where('account_id', $accountId)->delete();
    }

    /** @param array<string, mixed> $row */
    private function send(array $row): DbOutcome
    {
        // Decoded as objects so an empty grants map stays {} on the wire.
        $args = \json_decode((string) $row['op_args'], false);

        try {
            $reply = $this->agent->call('db.apply', \is_object($args) ? (array) $args : [], id: (string) $row['op_id'], timeout: 150.0);
        } catch (AgentUnavailable $e) {
            return DbOutcome::pending($e->getMessage());
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return DbOutcome::pending((string) $reply->errorMessage);
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return DbOutcome::pending();
        }

        $current = $this->table()->where('id', (int) $row['id'])->where('op_id', (string) $row['op_id']);

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $current->update(['state' => self::FAILED, 'error' => $code . ': ' . $message]);

            return DbOutcome::failed($code, $message);
        }

        $current->update([
            'state' => self::APPLIED,
            'applied_revision' => (int) $row['revision'],
            'error' => null,
            'applied_at' => Accounts::now(),
        ]);

        return DbOutcome::done($reply->result);
    }

    /** @return array<string, mixed> */
    private function row(int $accountId): array
    {
        $row = $this->stateOf($accountId);

        if ($row === null) {
            $this->table()->insert(['account_id' => $accountId, 'revision' => 0, 'applied_revision' => 0, 'state' => self::APPLIED, 'quota_kib' => 0]);
            $row = $this->stateOf($accountId) ?? throw new \LogicException('database state vanished');
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('db_accounts');
    }
}
