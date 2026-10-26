<?php

declare(strict_types=1);

namespace App\Engine\MCP\Protocol;

/**
 * A JSON-RPC notification: a method with no id, which is never answered --
 * not even with an error, because there would be nothing to answer it with.
 */
final class Notification
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string $method,
        public readonly array $params = [],
    ) {}
}
