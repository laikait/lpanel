<?php

declare(strict_types=1);

use App\Engine\MCP\McpCollector;
use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\MCP\SupportPrompt;

/**
 * Alphabetically first, and required to register after Zulu, whose tool it
 * builds a prompt around.
 */
return static function (ModuleContext $module): void {
    $module->name('Alpha')->version('1.0.0');
    $module->requires('Zulu', '^1.0');

    $module->mcp(static function (McpCollector $mcp): void {
        $mcp->prompt('alpha.support', SupportPrompt::class, 'Talk a customer through their invoices.');
    });
};
