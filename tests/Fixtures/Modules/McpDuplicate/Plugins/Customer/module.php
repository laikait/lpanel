<?php

declare(strict_types=1);

use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\MCP\GreetTool;

return static function (ModuleContext $module): void {
    $module->name('Customer');

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('customer.greet', GreetTool::class);
    });
};
