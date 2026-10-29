<?php

declare(strict_types=1);

use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\MCP\GreetTool;

/** Guards a tool with a permission nobody declared: boot must stop. */
return static function (ModuleContext $module): void {
    $module->name('Leaky');

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('leaky.greet', GreetTool::class, permission: 'leaky.use');
    });
};
