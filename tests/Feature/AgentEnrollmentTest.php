<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Database\ConnectionManager;
use App\Modules\Servers\Agent\ClientCertificate;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\PanelEndpoint;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Store\ServerStore;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\ServersTestCase;

/** Pairing (AGENT_PROTOCOL.md §5) and THREAT_MODEL test 7. */
final class AgentEnrollmentTest extends ServersTestCase
{
    public function test_an_agent_pairs_with_a_token_once(): void
    {
        [$server, $token] = $this->pendingServer();
        $agent = $this->agent();

        $response = $agent->enroll($token);
        self::assertSame(200, $response->status());
        $data = \json_decode($response->body(), true);
        self::assertIsArray($data);
        self::assertMatchesRegularExpression(Protocol::UUID, (string) $data['agent_id']);
        self::assertSame($this->service(OperationKey::class)->publicKey(), $data['panel_operation_key']);
        self::assertSame(['idle' => 5, 'max' => 300], $data['poll']);
        self::assertArrayNotHasKey('data', $data, 'the agent reads a bare object');

        $paired = $this->service(ServerStore::class)->byUid($server->uid);
        self::assertNotNull($paired);
        self::assertSame('active', $paired->state);
        self::assertSame($data['agent_id'], $paired->agentId);
        self::assertSame('web-01', $paired->hostname);
        self::assertSame('Ubuntu 24.04', $paired->osLabel());
        self::assertSame('online', $paired->status(180));

        $credentials = $this->service(ServerStore::class)->credentials($paired);
        self::assertCount(1, $credentials);
        self::assertSame($agent->kid(), $credentials[0]->kid);
        self::assertSame(FakeAgent::certSha($agent->certPem), $credentials[0]->tlsCertSha256);

        $event = $this->service(ConnectionManager::class)->connection()->table('audit_events')->where('action', 'agent.enroll')->first();
        self::assertSame('agent', $event['actor_type'] ?? null);
        self::assertSame('succeeded', $event['outcome'] ?? null);
    }

    /** THREAT_MODEL test 7: one answer to the caller, a different reason in the audit log. */
    public function test_reused_expired_revoked_and_wrong_tokens_get_the_same_answer(): void
    {
        [$server, $token] = $this->pendingServer();
        $store = $this->service(ServerStore::class);
        self::assertSame(200, $this->agent()->enroll($token)->status());

        $expired = $store->createEnrollment($store->create('web-02', null), null, 15);
        $this->service(ConnectionManager::class)->connection()->table('agent_enrollments')
            ->where('token_hash', Protocol::sha256($expired))
            ->update(['expires_at' => new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'))]);

        $third = $store->create('web-03', null);
        $replaced = $store->createEnrollment($third, null, 15);
        $store->createEnrollment($third, null, 15);

        $answers = [];

        foreach (['reused' => $token, 'expired' => $expired, 'replaced' => $replaced, 'wrong' => Protocol::b64(\random_bytes(32))] as $case => $t) {
            $response = $this->agent(FakeAgent::CERTS[\count($answers) % 3])->enroll($t);
            self::assertSame(401, $response->status(), $case);
            self::assertSame('enrollment_invalid', FakeAgent::code($response), $case);
            $answers[] = \json_decode($response->body(), true);
        }

        self::assertCount(1, \array_unique(\array_map('serialize', $answers)), 'every refusal looks the same');

        $reasons = [];

        foreach ($this->service(ConnectionManager::class)->connection()->table('audit_events')->where('action', 'agent.enroll')->where('outcome', 'denied')->orderBy('id')->get() as $row) {
            $reasons[] = \json_decode((string) $row['metadata'], true)['reason'] ?? null;
        }

        self::assertSame(['token already used', 'token expired', 'token revoked', 'unknown token'], $reasons);
        self::assertSame('active', $store->byUid($server->uid)?->state, 'the first pairing stands');
    }

    public function test_the_certificate_and_key_must_be_the_ones_named(): void
    {
        [, $token] = $this->pendingServer();
        $agent = $this->agent();

        // The certificate presented is not the one in the body.
        self::assertSame('enrollment_invalid', FakeAgent::code($agent->enroll($token, tamper: ['cert' => FakeAgent::cert('agent-b')])));
        // None at all: panel Nginx passed nothing.
        self::assertSame('enrollment_invalid', FakeAgent::code($agent->enroll($token, tamper: ['cert' => ''])));
        // LPA-Key names another key than the one registered.
        self::assertSame('enrollment_invalid', FakeAgent::code($agent->enroll($token, tamper: ['kid' => 'AAAAAAAAAAAAAAAA'])));
        // Signed by a key other than the one registered.
        [$other] = FakeAgent::keypair();
        self::assertSame('bad_signature', FakeAgent::code($agent->enroll($token, tamper: ['secret' => $other])));

        // None of that spent the token.
        self::assertSame(200, $agent->enroll($token)->status());
    }

    public function test_a_body_with_an_unknown_field_is_refused(): void
    {
        [, $token] = $this->pendingServer();
        $response = $this->agent()->enroll($token, ['os_id' => 'ubuntu', 'admin' => 'yes']);

        self::assertSame(400, $response->status());
        self::assertSame('bad_request', FakeAgent::code($response));
    }

    public function test_the_pin_and_fingerprints_match_openssl(): void
    {
        $this->panel();

        self::assertSame(self::PANEL_PIN, $this->service(PanelEndpoint::class)->pin());
        self::assertSame('9e5961cde433e8704ecc4f34cc439ec331ed22b6fdffe2e0ee84c62d67749396', ClientCertificate::fingerprintOfPem(FakeAgent::cert('agent-a')));
        self::assertNull(ClientCertificate::fingerprintOfPem('not a certificate'));
    }

    public function test_the_operation_key_is_never_replaced(): void
    {
        $this->panel();
        $key = $this->service(OperationKey::class);
        $before = $key->publicKey();

        try {
            OperationKey::generate($key->path());
            self::fail('An existing operation key was replaced.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        self::assertSame($before, (new OperationKey($key->path()))->publicKey());
    }
}
