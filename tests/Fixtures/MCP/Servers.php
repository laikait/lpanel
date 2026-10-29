<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Authorizer;
use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\MCP\McpAuthorizer;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\Prompt\PromptProvider;
use App\Engine\MCP\Protocol\MessageParser;
use App\Engine\MCP\Resource\ResourceReader;
use App\Engine\MCP\Tool\ToolRunner;

/**
 * A server over the MCP fixtures: greet and broken tools, a customer resource,
 * a support prompt. customer.get needs the "agent" role.
 */
final class Servers
{
    /** @param list<\Throwable> $reported */
    public static function make(array &$reported = [], ?HookEngine $hooks = null, ?FilterEngine $filters = null): McpServer
    {
        $access = new AccessRegistry();
        (new AccessCollector($access, 'Demo'))->capability('customer.view')->role('agent', ['customer.view']);

        $registry = new McpRegistry();
        (new McpCollector($registry, 'Demo'))
            ->tool('greet', GreetTool::class, 'Say hello.')
            ->tool('broken', BrokenTool::class)
            ->tool('customer.get', GreetTool::class, permission: 'customer.view')
            ->resource('customer://{id}', CustomerResource::class)
            ->prompt('customer.support', SupportPrompt::class);

        $report = static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e;
        };

        $authorizer = new McpAuthorizer(new Authorizer($access));
        $container = new Container();

        return new McpServer(
            new MessageParser(),
            new ToolRunner($registry, $container, $authorizer, $report, $hooks, $filters),
            new ResourceReader($registry, $container, $authorizer, $report, $hooks, $filters),
            new PromptProvider($registry, $container, $authorizer, $report, $hooks, $filters),
            $registry,
            'test-server',
            '9.9.9',
            $report,
            $hooks,
        );
    }
}
