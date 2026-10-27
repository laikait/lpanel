<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

use App\Engine\Http\Request;
use App\Engine\Security\RateLimiter;
use App\Modules\Servers\Store\AgentCredential;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * The checks of AGENT_PROTOCOL.md §3, in its order, each with its own code:
 *
 *   1. headers well-formed; the agent exists, is not revoked, the key is live
 *   2. the TLS client certificate is the one that goes with that key
 *   3. issued recently, not expired, lifetime within bounds
 *   4. the body is the one that was signed
 *   5. the signature verifies
 *   6. the request id has not been used
 *   7. the agent is within its rate limit
 *
 * The body is not parsed before step 5. The agent's testpanel
 * (internal/testpanel) is the reference this follows.
 */
final class RequestVerifier
{
    public function __construct(
        private readonly ServerStore $servers,
        private readonly ClientCertificate $certificates,
        private readonly RateLimiter $limiter,
        private readonly string $agentLimit = '120/1m',
    ) {}

    /**
     * An enrolled agent's request, verified.
     *
     * @return array{Server, AgentCredential, SignedRequest}
     *
     * @throws AgentException
     */
    public function verifyAgent(Request $request): array
    {
        $signed = SignedRequest::from($request);

        if ($signed->agentId === Protocol::ENROLL_AGENT) {
            throw AgentException::badRequest();
        }

        // 1. a known agent, not revoked, signing with a live key
        $server = $this->servers->byAgentId($signed->agentId);

        if ($server === null) {
            throw new AgentException('unknown_agent', 'unknown agent or key', 401);
        }

        if ($server->state === Server::REVOKED) {
            throw new AgentException('agent_revoked', 'agent revoked', 401);
        }

        $credential = $this->servers->credentialByKid($server, $signed->kid);

        if ($credential === null || !$credential->isLive()) {
            throw new AgentException('unknown_agent', 'unknown agent or key', 401);
        }

        // 2. the TLS client certificate belongs to that key
        $presented = $this->certificates->fingerprint($request);

        if ($presented === null || !\hash_equals($credential->tlsCertSha256, $presented)) {
            throw new AgentException('client_cert_mismatch', 'client certificate does not match the agent', 401);
        }

        // 3-6
        $this->checkSigned($signed, $credential->publicKey, $request->body());

        // 7. per agent, whatever address it comes from
        if (!$this->limiter->attempt('agent:' . $signed->agentId, $this->agentLimit)->allowed) {
            throw new AgentException('rate_limited', 'too many requests from this agent', 429);
        }

        return [$server, $credential, $signed];
    }

    /**
     * Checks 3 to 6, once the public key is known. Enrollment calls this with
     * the key from its body: proof of possession, while the token is the authority.
     *
     * @throws AgentException
     */
    public function checkSigned(SignedRequest $signed, string $publicKey, string $body, ?int $now = null): void
    {
        $now ??= \time();

        // 3. fresh
        if ($signed->expiresAt <= $signed->issuedAt || $signed->expiresAt - $signed->issuedAt > Protocol::MAX_LIFETIME) {
            throw AgentException::badRequest('request lifetime is out of range');
        }

        if (\abs($now - $signed->issuedAt) > Protocol::MAX_CLOCK_SKEW) {
            throw new AgentException('clock_skew', 'issued_at is too far from the panel\'s clock', 401);
        }

        if ($now >= $signed->expiresAt) {
            throw new AgentException('request_expired', 'request has expired', 401);
        }

        // 4. the body that was signed
        if (!\hash_equals($signed->contentSha256, Protocol::sha256($body))) {
            throw new AgentException('content_hash_mismatch', 'body does not match its hash', 400);
        }

        // 5. signed by that key
        if (!Protocol::verify($publicKey, $signed->signingString(), $signed->signature)) {
            throw new AgentException('bad_signature', 'signature does not verify', 401);
        }

        // 6. once only
        if (!$this->servers->rememberRequest($signed->agentId, $signed->requestId, $signed->expiresAt + Protocol::REPLAY_MARGIN)) {
            throw new AgentException('replayed_request', 'request id already used', 409);
        }
    }
}
