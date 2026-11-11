<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;

/**
 * Keeps each account's sites on the server in step with the domains table.
 *
 * The agent takes an account's whole web state at once (web.apply), so a
 * change never has to be replayed step by step. touch() works out the state;
 * when it differs from the last one recorded, it raises the account's
 * revision and records the state with a new operation id before sending it.
 * If the agent cannot be reached, web:settle (every minute) sends the same
 * id and arguments again until it answers, and the agent's journal runs it
 * once.
 */
final class WebSync
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';

    /** Used when the agent cannot say which PHP versions are installed. */
    public const FALLBACK_PHP = '83';

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    /** @var array<string, mixed>|null|false web.info, once per request; false before asking */
    private array|null|false $info = false;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Domains $domains,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Bring an account's sites in step. Accounts that are being made or
     * removed are left alone: their operation in flight comes first.
     */
    public function touch(int $accountId): WebOutcome
    {
        $account = $this->accounts->byId($accountId);

        if ($account === null || !\in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
            return WebOutcome::skipped();
        }

        $this->domains->ensureMain($account, $this->defaultPhp());
        $args = \json_encode($this->domains->state($account), \JSON_THROW_ON_ERROR);
        $row = $this->row($accountId);

        if ($row['op_args'] === $args && $row['state'] === self::APPLIED) {
            return WebOutcome::done();
        }

        if ($row['op_args'] !== $args || $row['state'] === self::FAILED) {
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
     * Send every recorded state the agent has not answered yet, and bring in
     * accounts that have no web state at all (made before this module).
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

        $known = \array_map(static fn(array $r): int => (int) $r['account_id'], $this->table()->get());

        foreach ([Accounts::ACTIVE, Accounts::SUSPENDED] as $status) {
            foreach ($this->connections->connection()->table('accounts')->where('status', $status)->get() as $account) {
                if (!\in_array((int) $account['id'], $known, true)) {
                    $outcome = $this->touch((int) $account['id']);
                    $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /** @return array<string, mixed>|null the account's web state row */
    public function stateOf(int $accountId): ?array
    {
        return $this->table()->where('account_id', $accountId)->first();
    }

    /** Forget a terminated account; the agent removed its sites itself. */
    public function forget(int $accountId): void
    {
        $this->domains->deleteAccount($accountId);
        $this->table()->where('account_id', $accountId)->delete();
    }

    /**
     * The web server and its PHP versions, from the agent (once per request).
     *
     * @return array<string, mixed>|null null when the agent cannot be asked
     */
    public function info(): ?array
    {
        if ($this->info === false) {
            try {
                $reply = $this->agent->call('web.info', [], timeout: 10.0);
                $this->info = $reply->done() ? $reply->result : null;
            } catch (AgentUnavailable) {
                $this->info = null;
            }
        }

        return $this->info;
    }

    /**
     * The PHP versions sites may use: those installed, or, when the agent
     * cannot say, the ones already in use plus the fallback.
     *
     * @param list<string> $inUse
     *
     * @return list<string> two-digit versions, newest last
     */
    public function phpVersions(array $inUse = []): array
    {
        $versions = [];

        foreach (\is_array($this->info()['php'] ?? null) ? $this->info()['php'] : [] as $v) {
            if (\is_array($v) && \is_string($v['version'] ?? null)) {
                $versions[] = $v['version'];
            }
        }

        if ($versions === []) {
            $versions = [...$inUse, self::FALLBACK_PHP];
        }

        $versions = \array_values(\array_unique($versions));
        \sort($versions);

        return $versions;
    }

    /** The newest PHP version installed. */
    public function defaultPhp(): string
    {
        $versions = $this->phpVersions();

        return (string) \end($versions);
    }

    /** "83" → "8.3" */
    public static function phpLabel(string $version): string
    {
        return \strlen($version) === 2 ? $version[0] . '.' . $version[1] : $version;
    }

    /** @param array<string, mixed> $row */
    private function send(array $row): WebOutcome
    {
        // Decoded as objects so an empty map (error_pages, php settings) stays
        // {} on the wire; as an array it would become [] and the agent refuses it.
        $args = \json_decode((string) $row['op_args'], false);

        try {
            $reply = $this->agent->call('web.apply', \is_object($args) ? (array) $args : [], id: (string) $row['op_id'], timeout: 150.0);
        } catch (AgentUnavailable $e) {
            return WebOutcome::pending($e->getMessage());
        }

        if ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true)) {
            return WebOutcome::pending((string) $reply->errorMessage);
        }

        if ($reply->status === 'accepted' || $reply->status === 'running') {
            return WebOutcome::pending();
        }

        // A newer revision may have been recorded meanwhile; only the answer
        // for the current one changes the state.
        $current = $this->table()->where('id', (int) $row['id'])->where('op_id', (string) $row['op_id']);

        if (!$reply->done()) {
            $code = $reply->errorCode ?? 'failed';
            $message = $reply->errorMessage ?? 'The agent gave no reason.';
            $current->update(['state' => self::FAILED, 'error' => $code . ': ' . $message]);

            return WebOutcome::failed($code, $message);
        }

        $current->update([
            'state' => self::APPLIED,
            'applied_revision' => (int) $row['revision'],
            'error' => null,
            'applied_at' => Accounts::now(),
        ]);

        return WebOutcome::done((bool) ($reply->result['changed'] ?? false));
    }

    /** @return array<string, mixed> */
    private function row(int $accountId): array
    {
        $row = $this->stateOf($accountId);

        if ($row === null) {
            $this->table()->insert(['account_id' => $accountId, 'revision' => 0, 'applied_revision' => 0, 'state' => self::APPLIED]);
            $row = $this->stateOf($accountId) ?? throw new \LogicException('web state vanished');
        }

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('web_accounts');
    }
}
