<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Authorizer;
use App\Engine\MCP\McpAuthorizer;

/** MCP authorizers for tests that are not about authorization. */
final class Authorizers
{
    /** Lets any caller, a guest included, use anything without a permission. */
    public static function open(): McpAuthorizer
    {
        return new McpAuthorizer(new Authorizer(new AccessRegistry()), allowGuests: true);
    }
}
