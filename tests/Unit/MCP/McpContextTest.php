<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\Auth\Identity;
use App\Engine\Container\Container;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpContext;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\Protocol\ProtocolVersion;
use App\Engine\MCP\Tool\ToolRunner;
use App\Tests\Fixtures\MCP\Authorizers;
use App\Tests\Fixtures\MCP\Contexts;
use App\Tests\Fixtures\MCP\GreetTool;
use App\Tests\Support\TestCase;

final class McpContextTest extends TestCase
{
    public function test_a_context_carries_the_request_the_caller_and_the_client(): void
    {
        $context = new McpContext('req-1', new Identity('7', 'ada', ['operator']), 'http', ProtocolVersion::LATEST, 'Claude Desktop', '1.2.0');

        self::assertSame('req-1', $context->requestId);
        self::assertSame('7', $context->identity->id);
        self::assertSame('http', $context->transport);
        self::assertSame(
            ['request_id' => 'req-1', 'transport' => 'http', 'client' => 'Claude Desktop 1.2.0', 'principal' => '7'],
            $context->forLog(),
        );
    }

    public function test_a_guest_has_no_principal_in_the_log(): void
    {
        self::assertNull(Contexts::make()->forLog()['principal']);
    }

    public function test_a_transport_that_does_not_exist_is_refused(): void
    {
        $this->expectException(McpContractException::class);
        $this->expectExceptionMessage('"websocket" is not an MCP transport');

        new McpContext('req-1', Identity::guest(), 'websocket', ProtocolVersion::LATEST);
    }

    public function test_a_context_needs_a_request_id(): void
    {
        $this->expectException(McpContractException::class);

        new McpContext('', Identity::guest(), 'stdio', ProtocolVersion::LATEST);
    }

    /**
     * Two requests through one runner -- as a long-running server would make
     * them -- each see only their own caller.
     */
    public function test_each_call_sees_only_its_own_context(): void
    {
        $registry = new McpRegistry();
        (new McpCollector($registry, 'Demo'))->tool('greet', GreetTool::class);
        $runner = new ToolRunner($registry, new Container(), Authorizers::open());

        $ada = $runner->call('greet', ['name' => 'x'], Contexts::make(new Identity('7', 'ada'), 'req-ada'))->toArray();
        $bob = $runner->call('greet', ['name' => 'x'], Contexts::make(new Identity('8', 'bob'), 'req-bob'))->toArray();

        self::assertSame(['7', 'req-ada'], [$ada['structuredContent']['asked_by'] ?? null, $ada['structuredContent']['request'] ?? null]);
        self::assertSame(['8', 'req-bob'], [$bob['structuredContent']['asked_by'] ?? null, $bob['structuredContent']['request'] ?? null]);
    }

    /** Values only: nothing in a context can hand a handler a service. */
    public function test_a_context_holds_no_services(): void
    {
        foreach ((new \ReflectionClass(McpContext::class))->getProperties() as $property) {
            $type = $property->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertContains($type->getName(), ['string', Identity::class], $property->getName() . ' is not a plain value');
        }
    }
}
