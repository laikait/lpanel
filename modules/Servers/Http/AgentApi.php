<?php

declare(strict_types=1);

namespace App\Modules\Servers\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Audit\AuditLog;
use App\Modules\Servers\Agent\AgentException;
use App\Modules\Servers\Agent\AgentSettings;
use App\Modules\Servers\Agent\ClientCertificate;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Agent\Protocol;
use App\Modules\Servers\Agent\RequestVerifier;
use App\Modules\Servers\Agent\SignedRequest;
use App\Modules\Servers\Agent\StrictJson;
use App\Modules\Servers\Store\AgentCredential;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * POST /agent/v1/{enroll, heartbeat, keys/rotate} (AGENT_PROTOCOL.md §4-5).
 * The operations endpoints belong to the Operations module.
 *
 * Answers are bare JSON objects, as the agent decodes them, and refusals are
 * LPHP's error shape plus the protocol's "code". These routes have CSRF off,
 * so the session cookie identifies nobody: the signature and the client
 * certificate are the only credentials.
 */
final class AgentApi
{
    private const FACTS = ['os_id', 'os_version', 'kernel', 'hostname', 'machine_id_sha256', 'agent_version'];

    private const VERSION = '/^[0-9A-Za-z.+~_-]{1,32}$/';

    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly ServerStore $servers,
        private readonly ClientCertificate $certificates,
        private readonly OperationKey $operationKey,
        private readonly AuditLog $audit,
        private readonly AgentSettings $settings,
    ) {}

    public function enroll(Request $request): Response
    {
        return $this->guard($request, function () use ($request): Response {
            $signed = SignedRequest::from($request);

            if ($signed->agentId !== Protocol::ENROLL_AGENT) {
                throw AgentException::badRequest('malformed enrollment request');
            }

            // Parsed before the signature, by necessity: the key to check it
            // with is in the body. The signature proves the agent holds the
            // key it registers; the token is the authority.
            $body = StrictJson::object($request->body(), ['token', 'agent_public_key', 'tls_cert_sha256', 'facts'], 'enrollment');
            $token = StrictJson::string($body['token'], '/^[A-Za-z0-9_-]{20,128}$/', 'token');
            $publicKey = Protocol::unb64(StrictJson::string($body['agent_public_key'], '/^[A-Za-z0-9_-]{43}$/', 'agent_public_key')) ?? '';
            $certSha = StrictJson::string($body['tls_cert_sha256'], Protocol::HEX64, 'tls_cert_sha256');
            $facts = $this->facts($body['facts']);

            if (\strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || Protocol::keyId($publicKey) !== $signed->kid) {
                return $this->refuseEnrollment(null, 'key does not match LPA-Key');
            }

            $this->verifier->checkSigned($signed, $publicKey, $request->body());

            $presented = $this->certificates->fingerprint($request);

            if ($presented === null || !\hash_equals($certSha, $presented)) {
                return $this->refuseEnrollment(null, 'client certificate is not the one named in the request');
            }

            $enrollment = $this->servers->enrollmentByToken($token);
            $server = $enrollment === null ? null : $this->servers->byId($enrollment['server_id']);

            if ($enrollment === null || $server === null) {
                return $this->refuseEnrollment(null, 'unknown token');
            }

            $reason = match (true) {
                $enrollment['consumed_at'] !== null => 'token already used',
                $enrollment['revoked_at'] !== null => 'token revoked',
                new \DateTimeImmutable($enrollment['expires_at'], new \DateTimeZone('UTC')) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC')) => 'token expired',
                $server->state === Server::ACTIVE => 'server already has an agent',
                default => null,
            };

            if ($reason !== null) {
                return $this->refuseEnrollment($server, $reason);
            }

            $agentId = $this->servers->enroll($enrollment['id'], $server, $request->ip(), $publicKey, $certSha, $facts);

            if ($agentId === null) {
                return $this->refuseEnrollment($server, 'token already used');
            }

            $this->audit->record('agent.enroll', 'succeeded', $this->agent($agentId, $server), 'server', $server->uid, [
                'agent_id' => $agentId,
                'hostname' => $facts['hostname'],
                'os' => \trim($facts['os_id'] . ' ' . $facts['os_version']),
                'agent_version' => $facts['agent_version'],
            ]);

            return new JsonResponse([
                'agent_id' => $agentId,
                'panel_operation_key' => $this->operationKey->publicKey(),
                'poll' => $this->settings->poll(),
            ]);
        });
    }

    public function heartbeat(Request $request): Response
    {
        return $this->guard($request, function () use ($request): Response {
            [$server] = $this->verifier->verifyAgent($request);

            $body = StrictJson::object($request->body(), ['agent_version', 'protocols', 'capabilities', 'health'], 'heartbeat');
            $version = StrictJson::string($body['agent_version'], self::VERSION, 'agent_version');
            $protocols = StrictJson::ints($body['protocols'], 1, 1000, 16, 'protocols');
            $capabilities = $this->capabilities($body['capabilities']);
            $health = $this->health($body['health']);

            $this->servers->recordHeartbeat($server, $request->ip(), $version, $protocols, $capabilities, $health);

            return new JsonResponse([
                'server_time' => \time(),
                'poll' => $this->settings->poll(),
                'rotate_requested' => $server->rotateRequested,
            ]);
        });
    }

    /**
     * The agent registers a new key, signed with its current one, and proves
     * it holds the new key too. The old key keeps working for the overlap, so
     * requests already in flight are not refused.
     */
    public function rotate(Request $request): Response
    {
        return $this->guard($request, function () use ($request): Response {
            [$server, $credential] = $this->verifier->verifyAgent($request);

            if ($credential->state !== AgentCredential::ACTIVE) {
                throw new AgentException('bad_request', 'only the current key can register its successor', 409);
            }

            $body = StrictJson::object($request->body(), ['new_public_key', 'new_tls_cert_sha256', 'proof'], 'rotation');
            $publicKey = Protocol::unb64(StrictJson::string($body['new_public_key'], '/^[A-Za-z0-9_-]{43}$/', 'new_public_key')) ?? '';
            $certSha = StrictJson::string($body['new_tls_cert_sha256'], Protocol::HEX64, 'new_tls_cert_sha256');
            $proof = StrictJson::string($body['proof'], '/^[A-Za-z0-9_-]{86}$/', 'proof');

            if (\strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw AgentException::badRequest('new_public_key is not an Ed25519 key');
            }

            $kid = Protocol::keyId($publicKey);

            if ($this->servers->kidExists($kid)) {
                throw new AgentException('bad_request', 'that key is already registered', 409);
            }

            if (!Protocol::verify($publicKey, Protocol::rotationProofString((string) $server->agentId, $kid, $certSha), $proof)) {
                throw new AgentException('bad_signature', 'the proof is not signed by the new key', 401);
            }

            $this->servers->rotate($server, $credential, $publicKey, $certSha, $this->settings->rotationOverlap());
            $this->audit->record('agent.key_rotated', 'succeeded', $this->agent((string) $server->agentId, $server), 'server', $server->uid, [
                'from' => $credential->kid,
                'to' => $kid,
            ]);

            return new JsonResponse(['kid' => $kid]);
        });
    }

    /** Size limit, then the handler; a protocol refusal becomes its error document. */
    private function guard(Request $request, \Closure $handler): Response
    {
        if (\strlen($request->body()) > Protocol::MAX_BODY) {
            return (new AgentException('bad_request', 'body too large', 413))->toResponse();
        }

        try {
            return $handler();
        } catch (AgentException $e) {
            return $e->toResponse();
        }
    }

    /** One answer for every reason; the reason goes to the audit log (THREAT_MODEL test 7). */
    private function refuseEnrollment(?Server $server, string $reason): Response
    {
        $this->audit->record('agent.enroll', 'denied', null, 'server', $server?->uid, ['reason' => $reason]);

        return (new AgentException('enrollment_invalid', 'enrollment refused', 401))->toResponse();
    }

    /** The agent, as the audit log names an actor. */
    private function agent(string $agentId, Server $server): Identity
    {
        return new Identity($agentId, 'Agent on ' . $server->name, [], ['agent' => true]);
    }

    /**
     * @return array<string, string>
     *
     * @throws AgentException
     */
    private function facts(mixed $value): array
    {
        $facts = StrictJson::shape($value, self::FACTS, 'facts');
        $patterns = [
            'os_id' => '/^[a-z0-9._-]{0,64}$/',
            'os_version' => '/^[A-Za-z0-9._ -]{0,64}$/',
            'kernel' => '/^[\x20-\x7e]{0,128}$/',
            'hostname' => '/^[A-Za-z0-9._-]{0,253}$/',
            'machine_id_sha256' => '/^([0-9a-f]{64})?$/',
            'agent_version' => '/^[0-9A-Za-z.+~_-]{0,32}$/',
        ];
        $clean = [];

        foreach ($patterns as $key => $pattern) {
            $clean[$key] = StrictJson::string($facts[$key], $pattern, 'facts.' . $key);
        }

        return $clean;
    }

    /**
     * op => versions. Go sends a nil map as null.
     *
     * @return array<string, list<int>>
     *
     * @throws AgentException
     */
    private function capabilities(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (!\is_array($value) || \array_is_list($value) || \count($value) > 200) {
            throw AgentException::badRequest('capabilities must be an object of at most 200 operations');
        }

        $clean = [];

        foreach ($value as $op => $versions) {
            $name = StrictJson::string((string) $op, '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', 'capability name');
            $clean[\substr($name, 0, 100)] = StrictJson::ints($versions, 1, 1000, 20, 'capability versions');
        }

        \ksort($clean);

        return $clean;
    }

    /**
     * @return array{started_at: int, pending_results: int, last_errors: list<array{code: string, at: int}>}
     *
     * @throws AgentException
     */
    private function health(mixed $value): array
    {
        $health = StrictJson::shape($value, ['started_at', 'pending_results', 'last_errors'], 'health');
        // Go sends an empty list as null.
        $errors = $health['last_errors'] ?? [];

        if (!\is_array($errors) || !\array_is_list($errors) || \count($errors) > 50) {
            throw AgentException::badRequest('health.last_errors must be a list of at most 50');
        }

        return [
            'started_at' => StrictJson::int($health['started_at'], 0, \PHP_INT_MAX, 'health.started_at'),
            'pending_results' => StrictJson::int($health['pending_results'], 0, 1000000, 'health.pending_results'),
            'last_errors' => \array_map(static function (mixed $e): array {
                $e = StrictJson::shape($e, ['code', 'at'], 'health.last_errors[]');

                return [
                    'code' => StrictJson::string($e['code'], '/^[a-z_]{1,64}$/', 'error code'),
                    'at' => StrictJson::int($e['at'], 0, \PHP_INT_MAX, 'error time'),
                ];
            }, $errors),
        ];
    }
}
