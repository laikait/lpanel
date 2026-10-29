<?php

declare(strict_types=1);

use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\MCP\CustomerResource;
use App\Tests\Fixtures\MCP\GreetTool;

return static function (ModuleContext $module): void {
    $module->name('Zulu')->version('1.2.0');

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->tool('zulu.greet', GreetTool::class, 'Say hello.')
            ->resource('customer://{id}', CustomerResource::class);
    });
};
