<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Resources;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Resource\Resource;
use App\Engine\MCP\Resource\ResourceContents;
use App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\Services\StatusService;

/** status://app: the same status as demo.status, as something a client reads. */
final class StatusResource implements Resource
{
    public function __construct(private readonly StatusService $status) {}

    public function read(string $uri, array $parameters, McpContext $context): ResourceContents
    {
        return ResourceContents::json($uri, $this->status->status());
    }
}
