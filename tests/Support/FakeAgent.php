<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Servers\Agent\Protocol;

/**
 * An LPA agent, in PHP, signing exactly as the Go one does (the golden
 * vectors keep the two in step). What panel Nginx would add -- the client
 * certificate as SSL_CLIENT_ESCAPED_CERT -- is set as a server variable.
 *
 * A test bends one thing at a time through $tamper: an old issued_at, a
 * reused request id, another certificate, a body changed after signing.
 */
final class FakeAgent
{
    public const CERTS = ['agent-a', 'agent-b', 'agent-c'];

    public ?string $agentId = null;

    /** @var non-empty-string */
    private string $secret;

    public string $publicKey;

    public string $certPem;

    public function __construct(private readonly Application $app, string $cert = 'agent-a', private readonly string $ip = '198.51.100.20')
    {
        [$this->secret, $this->publicKey] = self::keypair();
        $this->certPem = self::cert($cert);
    }

    /** @return array{0: non-empty-string, 1: string} */
    public static function keypair(): array
    {
        $pair = \sodium_crypto_sign_keypair();

        return [\sodium_crypto_sign_secretkey($pair), \sodium_crypto_sign_publickey($pair)];
    }

    public static function cert(string $name): string
    {
        return (string) \file_get_contents(\dirname(__DIR__) . '/Fixtures/lpa/certs/' . $name . '.pem');
    }

    public static function certSha(string $pem): string
    {
        \preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m);

        return \hash('sha256', (string) \base64_decode((string) \preg_replace('/\s+/', '', $m[1] ?? ''), true));
    }

    public function kid(): string
    {
        return Protocol::keyId($this->publicKey);
    }

    /**
     * @param array<string, string> $facts
     * @param array<string, mixed>  $tamper
     */
    public function enroll(string $token, array $facts = [], array $tamper = []): Response
    {
        $response = $this->send('/agent/v1/enroll', [
            'token' => $token,
            'agent_public_key' => Protocol::b64($this->publicKey),
            'tls_cert_sha256' => self::certSha($this->certPem),
            'facts' => $facts + [
                'os_id' => 'ubuntu',
                'os_version' => '24.04',
                'kernel' => '6.8.0-45-generic',
                'hostname' => 'web-01',
                'machine_id_sha256' => \hash('sha256', 'machine'),
                'agent_version' => '0.1.0',
            ],
        ], $tamper + ['agent_id' => Protocol::ENROLL_AGENT]);

        $data = \json_decode($response->body(), true);

        if ($response->status() === 200 && \is_array($data) && \is_string($data['agent_id'] ?? null)) {
            $this->agentId = $data['agent_id'];
        }

        return $response;
    }

    /** Everything this release's agent can do, as its heartbeat says it. */
    public const ALL_OPS = ['account.create' => [1], 'account.delete' => [1], 'account.suspend' => [1], 'account.unsuspend' => [1], 'agent.capabilities' => [1], 'agent.health' => [1], 'service.list_status' => [1], 'system.info' => [1], 'system.metrics' => [1], 'website.create' => [1], 'website.delete' => [1], 'website.suspend' => [1], 'website.unsuspend' => [1], 'website.update' => [1], 'websites.check' => [1]];

    /**
     * @param array<string, mixed> $tamper
     * @param array<string, mixed> $body   fields to send instead of the defaults
     */
    public function heartbeat(array $tamper = [], array $body = []): Response
    {
        return $this->send('/agent/v1/heartbeat', $body + [
            'agent_version' => '0.1.0',
            'protocols' => [1],
            'capabilities' => ['agent.health' => [1], 'system.info' => [1], 'system.metrics' => [1]],
            'health' => ['started_at' => \time() - 30, 'pending_results' => 0, 'last_errors' => null],
        ], $tamper);
    }

    /** POST /agent/v1/operations/claim */
    public function claim(int $max = 1): Response
    {
        return $this->send('/agent/v1/operations/claim', ['max' => $max]);
    }

    /** POST /agent/v1/operations/{id}/lease */
    public function lease(string $id, int $attempt): Response
    {
        return $this->send('/agent/v1/operations/' . $id . '/lease', ['attempt' => $attempt]);
    }

    /**
     * POST /agent/v1/operations/{id}/result, shaped as the Go agent sends it.
     *
     * @param mixed $result encoded as given: pass a \stdClass for an object
     */
    public function result(string $id, int $attempt, string $status = 'succeeded', mixed $result = null, ?string $code = null, string $message = ''): Response
    {
        $now = \time();

        return $this->send('/agent/v1/operations/' . $id . '/result', [
            'operation_id' => $id,
            'attempt' => $attempt,
            'status' => $status,
            'error_code' => $code,
            'message' => $message,
            'result' => $result ?? new \stdClass(),
            'started_at' => $now - 1,
            'finished_at' => $now,
            'agent_version' => '0.1.0',
            'truncated' => false,
        ]);
    }

    /**
     * Register a new key and certificate, signed with the current ones, then switch to them.
     *
     * @param array<string, mixed> $tamper
     */
    public function rotate(string $newCert = 'agent-b', array $tamper = []): Response
    {
        [$secret, $public] = self::keypair();
        $pem = self::cert($newCert);
        $kid = Protocol::keyId($public);
        $proof = Protocol::b64(\sodium_crypto_sign_detached(Protocol::rotationProofString((string) $this->agentId, $kid, self::certSha($pem)), $secret));

        $response = $this->send('/agent/v1/keys/rotate', [
            'new_public_key' => Protocol::b64($public),
            'new_tls_cert_sha256' => self::certSha($pem),
            'proof' => $tamper['proof'] ?? $proof,
        ], $tamper);

        if ($response->status() === 200) {
            [$this->secret, $this->publicKey, $this->certPem] = [$secret, $public, $pem];
        }

        return $response;
    }

    /**
     * A signed POST. $tamper keys: agent_id, kid, request_id, issued_at,
     * expires_at, signature, secret (sign with another key), cert (another
     * PEM, or '' for none), body (sent instead of what was signed).
     *
     * @param array<string, mixed>|string $body
     * @param array<string, mixed>        $tamper
     */
    public function send(string $path, array|string $body, array $tamper = []): Response
    {
        $raw = \is_string($body) ? $body : \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $issued = (int) ($tamper['issued_at'] ?? \time());
        $expires = (int) ($tamper['expires_at'] ?? $issued + 60);
        $agentId = (string) ($tamper['agent_id'] ?? $this->agentId ?? '');
        $kid = (string) ($tamper['kid'] ?? $this->kid());
        $requestId = (string) ($tamper['request_id'] ?? Protocol::uuid());
        $sha = Protocol::sha256($raw);
        $string = Protocol::signingString('POST', $path, $agentId, $kid, $requestId, $issued, $expires, $sha);
        $secret = \is_string($tamper['secret'] ?? null) && $tamper['secret'] !== '' ? $tamper['secret'] : $this->secret;
        $signature = (string) ($tamper['signature'] ?? Protocol::b64(\sodium_crypto_sign_detached($string, $secret)));
        $cert = \array_key_exists('cert', $tamper) ? (string) $tamper['cert'] : $this->certPem;
        $server = ['REMOTE_ADDR' => $this->ip];

        if ($cert !== '') {
            $server['SSL_CLIENT_ESCAPED_CERT'] = \rawurlencode($cert);
        }

        return $this->app->handle(Request::create('POST', $path, [
            'body' => (string) ($tamper['body'] ?? $raw),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                Protocol::HEADER_AGENT => $agentId,
                Protocol::HEADER_KEY => $kid,
                Protocol::HEADER_REQUEST_ID => $requestId,
                Protocol::HEADER_ISSUED_AT => (string) $issued,
                Protocol::HEADER_EXPIRES_AT => (string) $expires,
                Protocol::HEADER_CONTENT_SHA256 => $sha,
                Protocol::HEADER_SIGNATURE => $signature,
            ],
            'server' => $server,
        ]));
    }

    /** The protocol error code of a refusal, or null. */
    public static function code(Response $response): ?string
    {
        $data = \json_decode($response->body(), true);
        $code = \is_array($data) && \is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;

        return \is_string($code) ? $code : null;
    }
}
