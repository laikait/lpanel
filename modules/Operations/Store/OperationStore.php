<?php

declare(strict_types=1);

namespace App\Modules\Operations\Store;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Hook\HookEngine;
use App\Modules\Operations\Catalogue\Definition;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Store\Server;

/**
 * operations and operation_events: the only code that touches them.
 *
 * Every change of status is a guarded UPDATE that names the status (and
 * attempt) it expects to change, so a claim, a lease, a result, a cancel and
 * the sweeper can race and exactly one of them wins. Each change also writes
 * an operation_events row in the same transaction.
 *
 * Reaching a final status fires `operations.finished` with the operation as
 * stored, inside that same transaction: whatever another module keeps about
 * the operation's target (an account's state) changes with it or not at all.
 *
 * The query builder is immutable: every condition is chained or assigned back.
 */
final class OperationStore
{
    public const ACTORS = ['user', 'agent', 'sweeper', 'system'];

    /** List filters, as the screens and the API name them. */
    public const GROUPS = [
        'active' => Operation::LIVE,
        'succeeded' => [Operation::SUCCEEDED],
        'failed' => [Operation::FAILED, Operation::EXPIRED],
        'attention' => [Operation::NEEDS_ATTENTION],
        'cancelled' => [Operation::CANCELLED],
    ];

    public const FINAL = [Operation::SUCCEEDED, Operation::FAILED, Operation::CANCELLED, Operation::NEEDS_ATTENTION, Operation::EXPIRED];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly HookEngine $hooks,
    ) {}

    private function db(): Connection
    {
        return $this->connections->connection();
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        return $this->db()->transaction(static fn(): mixed => $work());
    }

    // ---- creating and reading -------------------------------------------------

    /**
     * A new queued operation for $server's agent, valid until $ttlSeconds from
     * now. $target names what it acts on when that is not the server itself.
     *
     * @param array{type: string, uid: string}|null $target
     */
    public function queue(Server $server, Definition $definition, string $payloadJson, ?string $requestedBy, ?string $requestedByName, string $actor, int $ttlSeconds, ?array $target = null): Operation
    {
        $uid = Protocol::uuid();
        $now = self::now();

        $this->db()->transaction(static function (Connection $db) use ($server, $definition, $payloadJson, $requestedBy, $requestedByName, $actor, $ttlSeconds, $uid, $now, $target): void {
            $id = $db->table('operations')->insert([
                'uid' => $uid,
                'server_id' => $server->id,
                'op' => $definition->op,
                'op_version' => $definition->version,
                'target_type' => $target['type'] ?? 'server',
                'target_uid' => $target['uid'] ?? $server->uid,
                'payload' => $payloadJson,
                // A mutation is retried only with its key, so the agent's journal can recognise it.
                'idempotency_key' => $definition->mutating ? Protocol::uuid() : null,
                'mutating' => $definition->mutating,
                'idempotent' => $definition->idempotent,
                'status' => Operation::QUEUED,
                'attempt' => 0,
                'max_attempts' => $definition->maxAttempts,
                'cancel_requested' => false,
                'lease_seconds' => $definition->leaseSeconds(),
                'timeout_seconds' => $definition->timeoutSeconds,
                'not_after' => $now->modify(\sprintf('+%d seconds', $ttlSeconds)),
                'requested_by' => $requestedBy,
                'requested_by_name' => $requestedByName === null ? null : \mb_substr($requestedByName, 0, 190),
                'queued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id');

            self::event($db, (int) $id, null, Operation::QUEUED, 0, $actor, $now);
        });

        return $this->byUid($uid) ?? throw new \LogicException('An operation just queued cannot be read back.');
    }

    public function byUid(string $uid): ?Operation
    {
        return \preg_match(Protocol::UUID, $uid) === 1 ? self::hydrate($this->db()->table('operations')->where('uid', $uid)->first()) : null;
    }

    /** $uid, but only if it is $server's: an agent never learns of another server's work. */
    public function forServer(Server $server, string $uid): ?Operation
    {
        $op = $this->byUid($uid);

        return $op !== null && $op->serverId === $server->id ? $op : null;
    }

    /** The same request still queued or running, so a second click joins it instead of queueing another. */
    public function liveFor(Server $server, Definition $definition): ?Operation
    {
        return self::hydrate($this->db()->table('operations')
            ->where('server_id', $server->id)
            ->where('op', $definition->op)
            ->where('op_version', $definition->version)
            ->whereIn('status', Operation::LIVE)
            ->orderByDesc('id')
            ->first());
    }

    /**
     * The newest operations on one target (an account), newest first.
     *
     * @return list<Operation>
     */
    public function forTarget(string $type, string $uid, int $limit = 20): array
    {
        $rows = $this->db()->table('operations')
            ->where('target_type', $type)
            ->where('target_uid', $uid)
            ->orderByDesc('id')
            ->limit(\max(1, \min(100, $limit)))
            ->get();

        return \array_values(\array_filter(\array_map(self::hydrate(...), $rows)));
    }

    /** The newest successful run of $op on $server, whose result the server screen shows. */
    public function latestSucceeded(Server $server, string $op): ?Operation
    {
        return self::hydrate($this->db()->table('operations')
            ->where('server_id', $server->id)
            ->where('op', $op)
            ->where('status', Operation::SUCCEEDED)
            ->orderByDesc('id')
            ->first());
    }

    /**
     * Newest first, after $beforeId when paging.
     *
     * @return list<Operation>
     */
    public function page(int $limit = 50, ?int $beforeId = null, ?string $group = null, ?int $serverId = null): array
    {
        $query = $this->db()->table('operations')->orderByDesc('id')->limit($limit);

        if ($beforeId !== null) {
            $query = $query->where('id', '<', $beforeId);
        }

        if ($group !== null && isset(self::GROUPS[$group])) {
            $query = $query->whereIn('status', self::GROUPS[$group]);
        }

        if ($serverId !== null) {
            $query = $query->where('server_id', $serverId);
        }

        $list = [];

        foreach ($query->get() as $row) {
            $list[] = self::hydrate($row) ?? throw new \LogicException('unreachable');
        }

        return $list;
    }

    /** @return array{active: int, attention: int, failed: int} failed counts since $since */
    public function counts(\DateTimeImmutable $since): array
    {
        $table = fn() => $this->db()->table('operations');

        return [
            'active' => $table()->whereIn('status', Operation::LIVE)->count(),
            'attention' => $table()->where('status', Operation::NEEDS_ATTENTION)->count(),
            'failed' => $table()->whereIn('status', self::GROUPS['failed'])->where('completed_at', '>=', $since)->count(),
        ];
    }

    /** @return list<array{occurred_at: string, from_status: ?string, to_status: string, attempt: int, actor: string, detail: array<string, mixed>}> oldest first */
    public function events(Operation $op): array
    {
        $events = [];

        foreach ($this->db()->table('operation_events')->where('operation_id', $op->id)->orderBy('id')->get() as $row) {
            $detail = isset($row['detail']) ? \json_decode((string) $row['detail'], true) : null;
            $events[] = [
                'occurred_at' => (string) $row['occurred_at'],
                'from_status' => isset($row['from_status']) ? (string) $row['from_status'] : null,
                'to_status' => (string) $row['to_status'],
                'attempt' => (int) $row['attempt'],
                'actor' => (string) $row['actor'],
                'detail' => \is_array($detail) ? $detail : [],
            ];
        }

        return $events;
    }

    // ---- the agent's side ---------------------------------------------------------

    /**
     * Lease up to $max queued operations to $server's agent, oldest first.
     * $envelope signs an operation for the attempt about to be delivered; it
     * runs before the claim, so nothing is claimed that cannot be sent.
     *
     * @template T
     *
     * @param \Closure(Operation, int, int): T $envelope (operation, attempt, issued_at)
     *
     * @return list<T>
     */
    public function claim(Server $server, int $max, \Closure $envelope): array
    {
        $now = self::now();
        $rows = $this->db()->table('operations')
            ->where('server_id', $server->id)
            ->where('status', Operation::QUEUED)
            ->where('not_after', '>', $now)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit($max)
            ->get();
        $claimed = [];

        foreach ($rows as $row) {
            $op = self::hydrate($row) ?? throw new \LogicException('unreachable');
            $attempt = $op->attempt + 1;
            $signed = $envelope($op, $attempt, $now->getTimestamp());

            $won = $this->db()->transaction(static function (Connection $db) use ($op, $attempt, $now): bool {
                $taken = $db->table('operations')
                    ->where('id', $op->id)
                    ->where('status', Operation::QUEUED)
                    ->where('attempt', $op->attempt)
                    ->update([
                        'status' => Operation::CLAIMED,
                        'attempt' => $attempt,
                        'claimed_at' => $now,
                        'lease_until' => $now->modify(\sprintf('+%d seconds', $op->leaseSeconds)),
                        'updated_at' => $now,
                    ]);

                if ($taken !== 1) {
                    return false;
                }

                self::event($db, $op->id, Operation::QUEUED, Operation::CLAIMED, $attempt, 'agent', $now);

                return true;
            });

            if ($won) {
                $claimed[] = $signed;
            }
        }

        return $claimed;
    }

    /**
     * The agent is still at it: extend the lease of $attempt. An attempt that
     * no longer counts (finished, cancelled, or superseded after its lease
     * lapsed) is told to stop.
     *
     * @return array{lease_until: int, cancel_requested: bool}
     */
    public function lease(Operation $op, int $attempt): array
    {
        $now = self::now();
        $stop = ['lease_until' => $now->getTimestamp(), 'cancel_requested' => true];

        if (!\in_array($op->status, Operation::LEASED, true) || $op->attempt !== $attempt) {
            return $stop;
        }

        $until = $now->modify(\sprintf('+%d seconds', $op->leaseSeconds));
        $extended = $this->db()->transaction(static function (Connection $db) use ($op, $until, $now): bool {
            $done = $db->table('operations')
                ->where('id', $op->id)
                ->where('status', $op->status)
                ->where('attempt', $op->attempt)
                ->update(['status' => Operation::RUNNING, 'lease_until' => $until, 'updated_at' => $now]);

            if ($done === 1 && $op->status === Operation::CLAIMED) {
                self::event($db, $op->id, Operation::CLAIMED, Operation::RUNNING, $op->attempt, 'agent', $now);
            }

            return $done === 1;
        });

        if (!$extended) {
            return $stop;
        }

        // Read again: a cancel may have been asked for since $op was read.
        $fresh = $this->byUid($op->uid);

        return ['lease_until' => $until->getTimestamp(), 'cancel_requested' => $fresh === null || $fresh->cancelRequested];
    }

    /**
     * Take the agent's result for $attempt.
     *
     * Returns 'accepted' when it is stored, 'duplicate' for a resent copy of
     * the result already taken, 'conflict' when the operation will not take it
     * (another attempt's result was taken, or it was cancelled or expired).
     * The result of an earlier attempt is taken while the operation is still
     * live: that work did happen, and the first result wins.
     *
     * $then runs in the same transaction, with the operation as stored.
     *
     * @param \Closure(Operation): void $then
     */
    public function finish(
        Operation $op,
        int $attempt,
        string $status,
        ?string $errorCode,
        ?string $message,
        ?string $resultJson,
        ?string $agentVersion,
        int $startedAt,
        int $finishedAt,
        \Closure $then,
    ): string {
        for ($try = 0; $try < 3; ++$try) {
            if (!$op->isLive()) {
                return $op->resultAttempt === $attempt ? 'duplicate' : 'conflict';
            }

            if ($attempt < 1 || $attempt > $op->attempt) {
                return 'conflict';
            }

            // Asked to stop and then failed: that is the cancel taking effect.
            $final = $status === Operation::FAILED && $op->cancelRequested ? Operation::CANCELLED : $status;
            $now = self::now();
            $taken = $this->db()->transaction(function (Connection $db) use ($op, $attempt, $final, $errorCode, $message, $resultJson, $agentVersion, $startedAt, $finishedAt, $now, $then): bool {
                $done = $db->table('operations')
                    ->where('id', $op->id)
                    ->where('status', $op->status)
                    ->where('attempt', $op->attempt)
                    ->update([
                        'status' => $final,
                        'error_code' => $errorCode,
                        'message' => $message,
                        'result' => $resultJson,
                        'result_attempt' => $attempt,
                        'agent_version' => $agentVersion,
                        'started_at' => self::fromUnix($startedAt),
                        'finished_at' => self::fromUnix($finishedAt),
                        'completed_at' => $now,
                        'lease_until' => null,
                        'updated_at' => $now,
                    ]);

                if ($done !== 1) {
                    return false;
                }

                self::event($db, $op->id, $op->status, $final, $attempt, 'agent', $now, $errorCode === null ? [] : ['error_code' => $errorCode]);
                $stored = $this->byUid($op->uid) ?? throw new \LogicException('unreachable');
                $then($stored);
                $this->hooks->do('operations.finished', $stored);

                return true;
            });

            if ($taken) {
                return 'accepted';
            }

            // Something else changed it first; decide again on what it is now.
            $op = $this->byUid($op->uid) ?? throw new \LogicException('unreachable');
        }

        return 'conflict';
    }

    // ---- cancelling ---------------------------------------------------------------

    /**
     * 'cancelled' when no agent had it, 'requested' when its agent is told at
     * its next lease extension, or 'final' when there is nothing to cancel.
     */
    public function cancel(Operation $op, string $actor): string
    {
        $now = self::now();

        return $this->db()->transaction(function (Connection $db) use ($op, $actor, $now): string {
            $cancelled = $db->table('operations')->where('id', $op->id)->where('status', Operation::QUEUED)->update([
                'status' => Operation::CANCELLED,
                'error_code' => 'cancelled',
                'message' => 'Cancelled before an agent took it.',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            if ($cancelled === 1) {
                self::event($db, $op->id, Operation::QUEUED, Operation::CANCELLED, $op->attempt, $actor, $now);
                $this->hooks->do('operations.finished', $this->byUid($op->uid) ?? throw new \LogicException('unreachable'));

                return 'cancelled';
            }

            $asked = $db->table('operations')->where('id', $op->id)->whereIn('status', Operation::LEASED)->where('cancel_requested', false)
                ->update(['cancel_requested' => true, 'updated_at' => $now]);

            if ($asked === 1) {
                $status = (string) ($db->table('operations')->where('id', $op->id)->first()['status'] ?? $op->status);
                self::event($db, $op->id, $status, $status, $op->attempt, $actor, $now, ['cancel_requested' => true]);

                return 'requested';
            }

            return $db->table('operations')->where('id', $op->id)->whereIn('status', Operation::LEASED)->exists() ? 'requested' : 'final';
        });
    }

    /**
     * Everything still to happen on $server ends now: its agent is revoked
     * (AGENT_PROTOCOL.md §5) and could not report a result anyway.
     */
    public function cancelForServer(Server $server, string $why): int
    {
        $now = self::now();
        $count = 0;

        foreach ($this->db()->table('operations')->where('server_id', $server->id)->whereIn('status', Operation::LIVE)->get() as $row) {
            $op = self::hydrate($row) ?? throw new \LogicException('unreachable');
            $count += $this->transition($op, Operation::CANCELLED, 'system', $now, 'agent_revoked', $why);
        }

        return $count;
    }

    // ---- the sweeper -----------------------------------------------------------------

    /**
     * Every minute (ADR-0006): queued work past not_after expires, and a
     * lapsed lease is retried if the operation is idempotent and has attempts
     * left. Otherwise a read-only operation fails, and one that may have
     * changed the server needs a person to look.
     *
     * @return array{expired: int, requeued: int, failed: int, needs_attention: int, cancelled: int}
     */
    public function sweep(?\DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $done = ['expired' => 0, 'requeued' => 0, 'failed' => 0, 'needs_attention' => 0, 'cancelled' => 0];

        foreach ($this->db()->table('operations')->where('status', Operation::QUEUED)->where('not_after', '<=', $now)->get() as $row) {
            $op = self::hydrate($row) ?? throw new \LogicException('unreachable');
            $done['expired'] += $this->transition($op, Operation::EXPIRED, 'sweeper', $now, 'operation_expired', 'No agent took it before it expired. Is the server online?');
        }

        foreach ($this->db()->table('operations')->whereIn('status', Operation::LEASED)->where('lease_until', '<', $now)->get() as $row) {
            $op = self::hydrate($row) ?? throw new \LogicException('unreachable');
            $notAfter = new \DateTimeImmutable($op->notAfter, new \DateTimeZone('UTC'));

            [$to, $key, $code, $message] = match (true) {
                $op->cancelRequested => [Operation::CANCELLED, 'cancelled', 'cancelled', 'Cancelled. The agent stopped reporting before it confirmed.'],
                $op->idempotent && $op->attempt < $op->maxAttempts && $notAfter > $now => [Operation::QUEUED, 'requeued', null, null],
                $op->mutating => [Operation::NEEDS_ATTENTION, 'needs_attention', 'lease_expired', 'The agent stopped reporting while it ran, so whether it changed the server is not known. Check the server before trying again.'],
                default => [Operation::FAILED, 'failed', 'lease_expired', \sprintf('The agent stopped reporting while it ran, %d %s.', $op->attempt, $op->attempt === 1 ? 'time' : 'times')],
            };

            $done[$key] += $this->transition($op, $to, 'sweeper', $now, $code, $message);
        }

        return $done;
    }

    // ---- rows ----------------------------------------------------------------------------

    /**
     * Move $op from the status it was read with to $to, if nothing beat us to it.
     *
     * @return int 1 if it moved, else 0
     */
    private function transition(Operation $op, string $to, string $actor, \DateTimeImmutable $now, ?string $code, ?string $message): int
    {
        return $this->db()->transaction(function (Connection $db) use ($op, $to, $actor, $now, $code, $message): int {
            $changes = $to === Operation::QUEUED
                ? ['status' => $to, 'lease_until' => null, 'updated_at' => $now]
                : ['status' => $to, 'error_code' => $code, 'message' => $message, 'lease_until' => null, 'completed_at' => $now, 'updated_at' => $now];

            $moved = $db->table('operations')->where('id', $op->id)->where('status', $op->status)->where('attempt', $op->attempt)->update($changes);

            if ($moved === 1) {
                self::event($db, $op->id, $op->status, $to, $op->attempt, $actor, $now, $code === null ? [] : ['error_code' => $code]);

                if (\in_array($to, self::FINAL, true)) {
                    $this->hooks->do('operations.finished', $this->byUid($op->uid) ?? throw new \LogicException('unreachable'));
                }
            }

            return $moved;
        });
    }

    /** @param array<string, mixed> $detail */
    private static function event(Connection $db, int $operationId, ?string $from, string $to, int $attempt, string $actor, \DateTimeImmutable $now, array $detail = []): void
    {
        if (!\in_array($actor, self::ACTORS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown operation actor "%s".', $actor));
        }

        $db->table('operation_events')->insert([
            'operation_id' => $operationId,
            'occurred_at' => $now,
            'from_status' => $from,
            'to_status' => $to,
            'attempt' => $attempt,
            'actor' => $actor,
            'detail' => $detail === [] ? null : \json_encode($detail, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function fromUnix(int $seconds): ?\DateTimeImmutable
    {
        return $seconds > 0 ? (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC')) : null;
    }

    /** @param array<string, mixed>|null $row */
    private static function hydrate(?array $row): ?Operation
    {
        if ($row === null) {
            return null;
        }

        $text = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        return new Operation(
            id: (int) $row['id'],
            uid: (string) $row['uid'],
            serverId: (int) $row['server_id'],
            op: (string) $row['op'],
            opVersion: (int) $row['op_version'],
            targetType: (string) $row['target_type'],
            targetUid: (string) $row['target_uid'],
            payload: (string) $row['payload'],
            idempotencyKey: $text($row['idempotency_key'] ?? null),
            mutating: (bool) $row['mutating'],
            idempotent: (bool) $row['idempotent'],
            status: (string) $row['status'],
            attempt: (int) $row['attempt'],
            maxAttempts: (int) $row['max_attempts'],
            cancelRequested: (bool) $row['cancel_requested'],
            leaseSeconds: (int) $row['lease_seconds'],
            timeoutSeconds: (int) $row['timeout_seconds'],
            leaseUntil: $text($row['lease_until'] ?? null),
            notAfter: (string) $row['not_after'],
            requestedBy: $text($row['requested_by'] ?? null),
            requestedByName: $text($row['requested_by_name'] ?? null),
            errorCode: $text($row['error_code'] ?? null),
            message: $text($row['message'] ?? null),
            result: $text($row['result'] ?? null),
            resultAttempt: isset($row['result_attempt']) ? (int) $row['result_attempt'] : null,
            agentVersion: $text($row['agent_version'] ?? null),
            startedAt: $text($row['started_at'] ?? null),
            finishedAt: $text($row['finished_at'] ?? null),
            queuedAt: (string) $row['queued_at'],
            claimedAt: $text($row['claimed_at'] ?? null),
            completedAt: $text($row['completed_at'] ?? null),
        );
    }
}
