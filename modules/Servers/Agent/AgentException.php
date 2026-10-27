<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

use App\Engine\Http\JsonResponse;

/**
 * A refusal on /agent/v1, with the protocol's error code (AGENT_PROTOCOL.md
 * §8). Answered in LPHP's error shape plus "code", which the agent reads to
 * tell, say, replayed_request from bad_signature.
 */
final class AgentException extends \RuntimeException
{
    private const TITLES = [400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 409 => 'Conflict', 413 => 'Payload Too Large', 429 => 'Too Many Requests', 503 => 'Service Unavailable'];

    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function badRequest(string $message = 'missing or malformed LPA headers or body'): self
    {
        return new self('bad_request', $message, 400);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(['error' => [
            'status' => $this->status,
            'title' => self::TITLES[$this->status] ?? 'Error',
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ]], $this->status, $this->status === 429 ? ['Retry-After' => '60'] : []);
    }
}
