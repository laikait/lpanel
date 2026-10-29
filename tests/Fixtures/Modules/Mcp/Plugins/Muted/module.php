<?php

declare(strict_types=1);

use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\MCP\GreetTool;

/** Offers a tool, and is disabled by the tests that use it: the tool must not appear. */
return static function (ModuleContext $module): void {
    $module->name('Muted');

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('muted.greet', GreetTool::class);
    });
};
