<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Http\Response;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Store\ServerStore;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\ServersTestCase;

/**
 * Signed agent requests (AGENT_PROTOCOL.md §3) and THREAT_MODEL test 2:
 * replayed, expired, wrong-signature, wrong-cert, wrong-agent and malformed
 * requests are refused, each with its own code. Then rotation and revocation.
 */
final class AgentRequestTest extends ServersTestCase
{
    /** @return array<string, mixed> */
    private static function json(Response $response): array
    {
        $data = \json_decode($response->body(), true);
        self::assertIsArray($data, $response->body());

        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function assertRefused(int $status, string $code, Response $response, string $case = ''): void
    {
        self::assertSame($code, FakeAgent::code($response), $case . ': ' . $response->body());
        self::assertSame($status, $response->status(), $case);
    }

    public function test_a_heartbeat_records_the_agent_and_answers_the_agent_its_way(): void
    {
        $agent = $this->enrolledAgent();
        $response = $agent->heartbeat();

        self::assertSame(200, $response->status(), $response->body());
        $data = self::json($response);
        self::assertEqualsWithDelta(\time(), $data['server_time'], 5);
        self::assertSame(['idle' => 5, 'max' => 300], $data['poll']);
        self::assertFalse($data['rotate_requested']);

        $server = $this->server();
        self::assertSame('0.1.0', $server->agentVersion);
        self::assertSame([1], $server->protocols);
        self::assertSame(['agent.health' => [1], 'system.info' => [1], 'system.metrics' => [1]], $server->capabilities);
        self::assertSame('198.51.100.20', $server->lastSeenIp);

        self::assertSame(['operations' => []], self::json($agent->send('/agent/v1/operations/claim', ['max' => 1])));
    }

    public function test_every_bad_request_is_refused_with_its_own_code(): void
    {
        $agent = $this->enrolledAgent();
        [$otherKey] = FakeAgent::keypair();
        $now = \time();
        $id = Protocol::uuid();
        self::assertSame(200, $agent->heartbeat(['request_id' => $id])->status());

        $cases = [
            'replayed' => [409, 'replayed_request', ['request_id' => $id]],
            'expired' => [401, 'request_expired', ['issued_at' => $now - 50, 'expires_at' => $now - 1]],
            'from the future' => [401, 'clock_skew', ['issued_at' => $now + 120, 'expires_at' => $now + 180]],
            'from the past' => [401, 'clock_skew', ['issued_at' => $now - 100, 'expires_at' => $now + 10]],
            'too long-lived' => [400, 'bad_request', ['issued_at' => $now, 'expires_at' => $now + 600]],
            'wrong signature' => [401, 'bad_signature', ['secret' => $otherKey]],
            'garbled signature' => [401, 'bad_signature', ['signature' => 'not-a-signature']],
            'body changed after signing' => [400, 'content_hash_mismatch', ['body' => '{"agent_version":"9.9.9","protocols":[1],"capabilities":null,"health":{"started_at":1,"pending_results":0,"last_errors":null}}']],
            'another certificate' => [401, 'client_cert_mismatch', ['cert' => FakeAgent::cert('agent-b')]],
            'no certificate' => [401, 'client_cert_mismatch', ['cert' => '']],
            'unknown agent' => [401, 'unknown_agent', ['agent_id' => Protocol::uuid()]],
            'unknown key' => [401, 'unknown_agent', ['kid' => 'AAAAAAAAAAAAAAAA']],
            'agent id not a uuid' => [400, 'bad_request', ['agent_id' => 'agent-1']],
            'request id not a uuid' => [400, 'bad_request', ['request_id' => '12345']],
            'the enrollment id' => [400, 'bad_request', ['agent_id' => Protocol::ENROLL_AGENT]],
        ];

        foreach ($cases as $case => [$status, $code, $tamper]) {
            self::assertRefused($status, $code, $agent->heartbeat($tamper), $case);
        }

        // Nothing above was recorded as the agent having been seen with a bad body.
        self::assertSame('0.1.0', $this->service(ServerStore::class)->byAgentId((string) $agent->agentId)?->agentVersion);
    }

    public function test_a_body_is_decoded_strictly_after_the_signature(): void
    {
        $agent = $this->enrolledAgent();
        $extra = ['agent_version' => '0.1.0', 'protocols' => [1], 'capabilities' => [], 'health' => ['started_at' => 1, 'pending_results' => 0, 'last_errors' => []], 'root' => true];

        self::assertRefused(400, 'bad_request', $agent->send('/agent/v1/heartbeat', $extra), 'an unknown field');
        self::assertRefused(400, 'bad_request', $agent->send('/agent/v1/heartbeat', 'not json'), 'not JSON');
        self::assertRefused(400, 'bad_request', $agent->send('/agent/v1/operations/claim', ['max' => 500]), 'claim too many');
        self::assertRefused(400, 'bad_request', $agent->send('/agent/v1/heartbeat', ['agent_version' => '0.1.0', 'protocols' => [1], 'capabilities' => ['rm -rf /' => [1]], 'health' => ['started_at' => 1, 'pending_results' => 0, 'last_errors' => []]]), 'a capability name that is not an op');
    }

    public function test_an_agent_has_its_own_rate_limit(): void
    {
        $this->panel(['agent_rate_limit' => '3/1m']);
        [, $token] = $this->pendingServer();
        $agent = $this->agent();
        $agent->enroll($token);

        $statuses = \array_map(static fn(int $i): int => $agent->heartbeat()->status(), \range(1, 4));

        self::assertSame([200, 200, 200, 429], $statuses);
        self::assertSame('rate_limited', FakeAgent::code($agent->heartbeat()));
    }

    public function test_a_revoked_agent_is_refused_and_can_pair_again(): void
    {
        $agent = $this->enrolledAgent();
        $store = $this->service(ServerStore::class);
        $server = $this->server();

        $store->revoke($server);
        self::assertRefused(401, 'agent_revoked', $agent->heartbeat(), 'revoked');

        // A new token and a new identity bring it back, under a new agent id.
        $token = $store->createEnrollment($this->server(), null, 15);
        $again = $this->agent('agent-c');
        self::assertSame(200, $again->enroll($token)->status());
        self::assertNotSame($agent->agentId, $again->agentId);
        self::assertSame(200, $again->heartbeat()->status());
        self::assertRefused(401, 'unknown_agent', $agent->heartbeat(), 'the old identity');
    }

    public function test_a_key_rotates_with_a_day_of_overlap(): void
    {
        $agent = $this->enrolledAgent('agent-a');
        $store = $this->service(ServerStore::class);
        $oldKid = $agent->kid();

        $store->requestRotation($this->server());
        self::assertTrue(self::json($agent->heartbeat())['rotate_requested']);

        // A proof not made by the new key is refused, and nothing changes.
        self::assertRefused(401, 'bad_signature', $agent->rotate('agent-b', ['proof' => Protocol::b64(\random_bytes(64))]), 'bad proof');

        $rotated = $agent->rotate('agent-b');
        self::assertSame(200, $rotated->status(), $rotated->body());
        self::assertSame($agent->kid(), self::json($rotated)['kid']);
        self::assertFalse(self::json($agent->heartbeat())['rotate_requested'], 'the new key and certificate work, and the request is cleared');

        $credentials = $store->credentials($this->server());
        self::assertSame(['active', 'rotating'], \array_map(static fn($c): string => $c->state, $credentials));
        self::assertSame($oldKid, $credentials[1]->kid);

        // The sweep retires the old key once its day is over.
        self::assertSame(0, $store->sweep()['keys']);
        self::assertSame(1, $store->sweep(new \DateTimeImmutable('+25 hours', new \DateTimeZone('UTC')))['keys']);
        self::assertSame('revoked', $store->credentials($this->server())[1]->state);
    }

    public function test_only_the_current_key_registers_a_successor(): void
    {
        $agent = $this->enrolledAgent('agent-a');
        $old = clone $agent;
        self::assertSame(200, $agent->rotate('agent-b')->status());

        // The old key is still live for the overlap, but it cannot rotate again.
        self::assertSame(200, $old->heartbeat()->status());
        self::assertRefused(409, 'bad_request', $old->rotate('agent-c'), 'rotating key');
    }

    public function test_the_session_cookie_is_not_a_credential_here(): void
    {
        $this->panel();
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        $response = $browser->get('/agent/v1/heartbeat', ['Accept' => 'application/json']);
        self::assertContains($response->status(), [404, 405], 'only POST, and only signed');
    }
}
