<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Database\ConnectionManager;
use App\Modules\Operations\Catalogue\Definition;
use App\Modules\Operations\Catalogue\Shape;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\Store\Operation;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\OperationsTestCase;

/**
 * The agent's side of operations (AGENT_PROTOCOL.md §6-7, ADR-0006): claim,
 * lease and result, and what the sweeper does when an agent goes quiet.
 * The M4 exit criteria: lease expiry, duplicate delivery, an offline agent
 * and a protocol mismatch.
 */
final class OperationsAgentTest extends OperationsTestCase
{
    private function future(int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('+%d seconds', $seconds), new \DateTimeZone('UTC'));
    }

    public function test_a_claim_hands_out_the_operation_signed_once(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');

        $claimed = $this->claimed($agent->claim());
        self::assertCount(1, $claimed);
        $wire = $claimed[0]['op'];

        // Exactly the fields the agent decodes strictly, in its order.
        self::assertSame(['v', 'operation_id', 'agent_id', 'op', 'op_version', 'target', 'idempotency_key', 'attempt', 'issued_at', 'not_after', 'lease_seconds', 'timeout_seconds', 'payload'], \array_keys($wire));
        self::assertSame(1, $wire['v']);
        self::assertSame($op->uid, $wire['operation_id']);
        self::assertSame($agent->agentId, $wire['agent_id']);
        self::assertSame(['system.info', 1, 1], [$wire['op'], $wire['op_version'], $wire['attempt']]);
        self::assertSame(['server_id' => $this->server()->uid], $wire['target']);
        self::assertNull($wire['idempotency_key'], 'read-only work carries no key');
        self::assertStringEndsWith('"payload":{}}', $claimed[0]['raw'], 'an empty payload is an object, never []');
        self::assertSame([10, 40], [$wire['timeout_seconds'], $wire['lease_seconds']]);
        self::assertGreaterThan(\time() + 500, $wire['not_after']);

        self::assertSame(Operation::CLAIMED, $this->reload($op)->status);
        self::assertSame([], $this->claimed($agent->claim()), 'a claimed operation is not handed out twice');
    }

    public function test_claims_go_oldest_first_and_only_to_their_own_server(): void
    {
        $a = $this->onlineAgent('agent-a');
        $b = $this->onlineAgent('agent-c');
        $servers = $this->service(ServerStore::class)->all();
        self::assertCount(2, $servers);
        $serverOfB = $servers[0]->agentId === $b->agentId ? $servers[0] : $servers[1];
        $serverOfA = $servers[0]->agentId === $a->agentId ? $servers[0] : $servers[1];

        $first = $this->queue('system.info', $serverOfA);
        $second = $this->queue('system.metrics', $serverOfA);
        $theirs = $this->queue('system.info', $serverOfB);

        $claimed = $this->claimed($a->claim(5));
        self::assertSame([$first->uid, $second->uid], \array_map(static fn(array $c): mixed => $c['op']['operation_id'], $claimed));

        // B cannot touch A's work, and is told nothing about it.
        self::assertSame(404, $b->lease($first->uid, 1)->status());
        self::assertSame('operation_not_found', FakeAgent::code($b->result($first->uid, 1, 'succeeded', self::example('system.info'))));
        self::assertSame(Operation::CLAIMED, $this->reload($first)->status);
        self::assertSame([$theirs->uid], \array_map(static fn(array $c): mixed => $c['op']['operation_id'], $this->claimed($b->claim())));
    }

    public function test_a_result_is_stored_once_and_a_resent_copy_is_accepted_without_change(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');
        $this->claimed($agent->claim());

        $response = $agent->result($op->uid, 1, 'succeeded', self::example('system.info'));
        self::assertSame(200, $response->status(), $response->body());
        self::assertSame('{"accepted":true}', $response->body());

        $done = $this->reload($op);
        self::assertSame(Operation::SUCCEEDED, $done->status);
        self::assertSame(1, $done->resultAttempt);
        self::assertSame('trixie', $done->resultArray()['os']['version_codename'] ?? null);
        self::assertSame('0.1.0', $done->agentVersion);
        $this->assertHistory($done, ['->queued', 'queued>claimed', 'claimed>succeeded']);

        // system.info refreshes the server's facts.
        $server = $this->server();
        self::assertSame(['debian', '13', '6.12.0-test-amd64'], [$server->osId, $server->osVersion, $server->kernel]);

        // The agent's outbox resends when an answer is lost: the same attempt is accepted again, and changes nothing.
        self::assertSame(200, $agent->result($op->uid, 1, 'failed', null, 'internal')->status());
        self::assertSame(Operation::SUCCEEDED, $this->reload($op)->status);
        self::assertCount(3, $this->store()->events($op));

        // An attempt that was never delivered is refused for good (4xx, so the agent drops it).
        self::assertSame('result_conflict', FakeAgent::code($agent->result($op->uid, 2, 'succeeded', self::example('system.info'))));
    }

    public function test_a_result_that_does_not_fit_its_schema_is_refused_and_audited(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');
        $this->claimed($agent->claim());

        $forged = self::example('system.info');
        $forged->shell = '/bin/sh';
        self::assertSame(200, $agent->result($op->uid, 1, 'succeeded', $forged)->status());

        $done = $this->reload($op);
        self::assertSame([Operation::FAILED, 'invalid_result'], [$done->status, $done->errorCode]);
        self::assertNull($done->result, 'nothing of a refused result is kept');
        self::assertStringContainsString('unknown field "shell"', (string) $done->message);
        self::assertSame('web-01', $this->server()->hostname, 'a refused result changes no facts');

        $audit = $this->service(ConnectionManager::class)->connection()->table('audit_events')->where('action', 'operation.invalid_result')->first();
        self::assertNotNull($audit);
        self::assertSame(['agent', 'failed', $op->uid], [$audit['actor_type'], $audit['outcome'], $audit['target_id']]);
    }

    public function test_malformed_results_are_bad_requests(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');
        $this->claimed($agent->claim());
        $base = ['operation_id' => $op->uid, 'attempt' => 1, 'status' => 'succeeded', 'error_code' => null, 'message' => '', 'result' => new \stdClass(), 'started_at' => 1, 'finished_at' => 2, 'agent_version' => '0.1.0', 'truncated' => false];

        foreach ([
            'another operation_id' => ['operation_id' => '1b2c3d4e-5f60-4718-8a9b-0c1d2e3f4a5b'],
            'an unknown status' => ['status' => 'done'],
            'success with an error code' => ['error_code' => 'internal'],
            'failure without one' => ['status' => 'failed'],
            'a long message' => ['status' => 'failed', 'error_code' => 'internal', 'message' => \str_repeat('x', 501)],
            'an extra field' => ['stdout' => 'root:x:0:0'],
        ] as $why => $change) {
            self::assertSame('bad_request', FakeAgent::code($agent->send('/agent/v1/operations/' . $op->uid . '/result', $change + $base)), $why);
        }

        self::assertSame(Operation::CLAIMED, $this->reload($op)->status);
    }

    public function test_a_lease_keeps_it_running_and_carries_a_cancel(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.metrics');
        $this->claimed($agent->claim());

        $lease = \json_decode($agent->lease($op->uid, 1)->body(), true);
        self::assertSame(false, $lease['cancel_requested'] ?? null);
        self::assertGreaterThanOrEqual(\time() + 40, $lease['lease_until']);
        self::assertSame(Operation::RUNNING, $this->reload($op)->status);

        self::assertSame('requested', $this->store()->cancel($this->reload($op), 'user'));
        self::assertSame(true, \json_decode($agent->lease($op->uid, 1)->body(), true)['cancel_requested'] ?? null);

        // The agent stops and says so: that is the cancel taking effect.
        self::assertSame(200, $agent->result($op->uid, 1, 'failed', null, 'cancelled', 'the panel cancelled the operation')->status());
        self::assertSame(Operation::CANCELLED, $this->reload($op)->status);
        $this->assertHistory($op, ['->queued', 'queued>claimed', 'claimed>running', 'running>running', 'running>cancelled']);
    }

    public function test_a_queued_operation_is_cancelled_at_once(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');

        self::assertSame('cancelled', $this->store()->cancel($op, 'user'));
        self::assertSame([], $this->claimed($agent->claim()));
        self::assertSame('final', $this->store()->cancel($this->reload($op), 'user'));
    }

    public function test_a_lapsed_lease_is_retried_and_the_first_result_wins(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');
        $this->claimed($agent->claim());

        // The agent went quiet: past the lease, the sweeper puts it back.
        $swept = $this->store()->sweep($this->future(41));
        self::assertSame(1, $swept['requeued']);
        self::assertSame([Operation::QUEUED, 1], [$this->reload($op)->status, $this->reload($op)->attempt]);

        $again = $this->claimed($agent->claim());
        self::assertSame(2, $again[0]['op']['attempt'], 'a redelivery is a new attempt, so the agent journal does not take it for a duplicate');

        // Attempt 1 was only slow: its result is real, and settles the operation.
        self::assertSame(200, $agent->result($op->uid, 1, 'succeeded', self::example('system.info'))->status());
        $done = $this->reload($op);
        self::assertSame([Operation::SUCCEEDED, 1], [$done->status, $done->resultAttempt]);

        // Attempt 2 is told to stop, and its result is refused.
        self::assertSame(true, \json_decode($agent->lease($op->uid, 2)->body(), true)['cancel_requested'] ?? null);
        self::assertSame('result_conflict', FakeAgent::code($agent->result($op->uid, 2, 'succeeded', self::example('system.info'))));
        $this->assertHistory($op, ['->queued', 'queued>claimed', 'claimed>queued', 'queued>claimed', 'claimed>succeeded']);
    }

    public function test_read_only_work_fails_after_its_last_attempt(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('agent.health');

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertSame($attempt, $this->claimed($agent->claim())[0]['op']['attempt']);
            $this->store()->sweep($this->future(36));
        }

        $done = $this->reload($op);
        self::assertSame([Operation::FAILED, 'lease_expired', 3], [$done->status, $done->errorCode, $done->attempt]);
        self::assertSame([], $this->claimed($agent->claim()));
    }

    public function test_a_lapsed_mutation_needs_a_person(): void
    {
        $agent = $this->onlineAgent();
        // No mutating operation exists yet; the store is what decides, so give it one.
        foreach ([[true, 3], [false, 1]] as [$idempotent, $attempts]) {
            $definition = new Definition('website.reload', 1, 'Reload', '', true, $idempotent, 'servers.manage', 'server', 30, 1024, $attempts, Definition::AUDIT_NOTICE, Shape::object(), Shape::object());
            $op = $this->store()->queue($this->server(), $definition, '{}', null, null, 'system', 600);
            self::assertNotNull($op->idempotencyKey, 'a mutation always carries an idempotency key');

            for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
                $claimed = $this->claimed($agent->claim());
                self::assertSame($op->idempotencyKey, $claimed[0]['op']['idempotency_key'], 'every attempt carries the same key');
                $this->store()->sweep($this->future(61));
            }

            $done = $this->reload($op);
            self::assertSame([Operation::NEEDS_ATTENTION, 'lease_expired'], [$done->status, $done->errorCode], $idempotent ? 'idempotent' : 'not idempotent');
        }
    }

    public function test_unclaimed_work_expires(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');

        self::assertSame(['expired' => 1, 'requeued' => 0, 'failed' => 0, 'needs_attention' => 0, 'cancelled' => 0], $this->store()->sweep($this->future(601)));
        self::assertSame([Operation::EXPIRED, 'operation_expired'], [$this->reload($op)->status, $this->reload($op)->errorCode]);
        self::assertSame([], $this->claimed($agent->claim()));
    }

    public function test_revoking_the_agent_cancels_its_work(): void
    {
        $agent = $this->onlineAgent();
        $queued = $this->queue('system.info');
        $running = $this->queue('system.metrics');
        $this->claimed($agent->claim());

        $this->service(ServerStore::class)->revoke($this->server());

        foreach ([$queued, $running] as $op) {
            self::assertSame([Operation::CANCELLED, 'agent_revoked'], [$this->reload($op)->status, $this->reload($op)->errorCode]);
        }
        self::assertSame('agent_revoked', FakeAgent::code($agent->result($running->uid, 1, 'succeeded', self::example('system.metrics'))));
    }

    public function test_nothing_is_queued_for_an_agent_that_is_not_online_or_cannot_run_it(): void
    {
        $this->panel();
        $servers = $this->service(ServerStore::class);
        $refused = function (string $op, string $code, ?string $agentId = null, ?Server $server = null): void {
            try {
                $this->queue($op, $server ?? $this->service(ServerStore::class)->byAgentId((string) $agentId));
                self::fail('queued ' . $op);
            } catch (OperationException $e) {
                self::assertSame($code, $e->errorCode, $op);
            }
        };

        [$pending] = $this->pendingServer('not-paired');
        $refused('system.info', 'server_not_paired', null, $pending);
        $servers->revoke($pending);
        $refused('system.info', 'agent_revoked', null, $servers->byUid($pending->uid));

        $agent = $this->onlineAgent('agent-a', ['system.info' => [2], 'agent.health' => [1]]);
        $refused('system.metrics', 'unknown_operation', $agent->agentId);
        $refused('system.info', 'unsupported_op_version', $agent->agentId);
        $refused('no.such_op', 'unknown_operation', $agent->agentId);

        self::assertSame(200, $agent->heartbeat([], ['protocols' => [2], 'capabilities' => FakeAgent::ALL_OPS])->status());
        $refused('system.info', 'unsupported_protocol', $agent->agentId);

        // Three heartbeats missed.
        $this->service(ConnectionManager::class)->connection()->table('servers')->where('agent_id', $agent->agentId)
            ->update(['last_seen_at' => new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')), 'protocols' => '[1]']);
        $refused('system.info', 'server_offline', $agent->agentId);

        self::assertSame([], $this->store()->page(), 'nothing was queued');
    }

    public function test_an_identical_read_only_request_joins_the_one_under_way(): void
    {
        $agent = $this->onlineAgent();
        $first = $this->queue('system.metrics');
        self::assertSame($first->uid, $this->queue('system.metrics')->uid);

        $this->claimed($agent->claim());
        self::assertSame($first->uid, $this->queue('system.metrics')->uid, 'still joined while the agent runs it');

        $agent->result($first->uid, 1, 'succeeded', self::example('system.metrics'));
        self::assertNotSame($first->uid, $this->queue('system.metrics')->uid, 'once it is done, a new request is a new run');
    }

    public function test_nothing_is_claimed_when_the_panel_cannot_sign(): void
    {
        $agent = $this->onlineAgent();
        $op = $this->queue('system.info');
        \unlink($this->service(OperationKey::class)->path());

        $response = $agent->claim();
        self::assertSame([503, 'internal'], [$response->status(), FakeAgent::code($response)]);
        self::assertSame(Operation::QUEUED, $this->reload($op)->status);
    }
}
