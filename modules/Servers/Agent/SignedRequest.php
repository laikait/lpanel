<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

use App\Engine\Http\Request;

/** The LPA-* headers of a request, checked for form only (AGENT_PROTOCOL.md §3, check 1's first half). */
final class SignedRequest
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $agentId,
        public readonly string $kid,
        public readonly string $requestId,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly string $contentSha256,
        public readonly string $signature,
    ) {}

    /** @throws AgentException bad_request */
    public static function from(Request $request): self
    {
        $h = static fn(string $name): string => (string) $request->header($name, '');
        $issued = $h(Protocol::HEADER_ISSUED_AT);
        $expires = $h(Protocol::HEADER_EXPIRES_AT);
        $agentId = $h(Protocol::HEADER_AGENT);
        $signature = $h(Protocol::HEADER_SIGNATURE);

        if ($request->method() !== 'POST'
            || \preg_match(Protocol::PATH, $request->path()) !== 1
            || ($agentId !== Protocol::ENROLL_AGENT && \preg_match(Protocol::UUID, $agentId) !== 1)
            || \preg_match(Protocol::KID, $h(Protocol::HEADER_KEY)) !== 1
            || \preg_match(Protocol::UUID, $h(Protocol::HEADER_REQUEST_ID)) !== 1
            || \preg_match('/^\d{1,12}$/', $issued) !== 1
            || \preg_match('/^\d{1,12}$/', $expires) !== 1
            || \preg_match(Protocol::HEX64, $h(Protocol::HEADER_CONTENT_SHA256)) !== 1
            || $signature === '' || \strlen($signature) > 128) {
            throw AgentException::badRequest();
        }

        return new self(
            $request->method(),
            $request->path(),
            $agentId,
            $h(Protocol::HEADER_KEY),
            $h(Protocol::HEADER_REQUEST_ID),
            (int) $issued,
            (int) $expires,
            $h(Protocol::HEADER_CONTENT_SHA256),
            $signature,
        );
    }

    public function signingString(): string
    {
        return Protocol::signingString($this->method, $this->path, $this->agentId, $this->kid, $this->requestId, $this->issuedAt, $this->expiresAt, $this->contentSha256);
    }
}
