<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\Auth\Identity;
use App\Engine\MCP\McpContext;
use App\Engine\MCP\Protocol\ProtocolVersion;

/** An MCP context for tests that are not about the context itself. */
final class Contexts
{
    public static function make(?Identity $identity = null, string $requestId = 'test-request'): McpContext
    {
        return new McpContext($requestId, $identity ?? Identity::guest(), 'stdio', ProtocolVersion::LATEST, 'phpunit', '11');
    }
}
