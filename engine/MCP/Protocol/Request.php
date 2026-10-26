<?php

declare(strict_types=1);

namespace App\Engine\MCP\Protocol;

/** A JSON-RPC request: a method, named parameters, and an id the answer must carry. */
final class Request
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string|int $id,
        public readonly string $method,
        public readonly array $params = [],
    ) {}
}
