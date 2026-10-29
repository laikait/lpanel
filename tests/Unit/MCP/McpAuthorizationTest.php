<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Capability as AuthCapability;
use App\Engine\Auth\Identity;
use App\Engine\Container\Container;
use App\Engine\Filter\FilterEngine;
use App\Engine\MCP\Capability;
use App\Engine\MCP\McpAuthorizer;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\Prompt\PromptException;
use App\Engine\MCP\Prompt\PromptProvider;
use App\Engine\MCP\RegistryException;
use App\Engine\MCP\Resource\ResourceException;
use App\Engine\MCP\Resource\ResourceReader;
use App\Engine\MCP\Tool\ToolException;
use App\Engine\MCP\Tool\ToolRunner;
use App\Tests\Fixtures\MCP\Contexts;
use App\Tests\Fixtures\MCP\CustomerResource;
use App\Tests\Fixtures\MCP\GreetTool;
use App\Tests\Fixtures\MCP\SupportPrompt;
use App\Tests\Support\TestCase;

final class McpAuthorizationTest extends TestCase
{
    private McpRegistry $registry;

    private FilterEngine $filters;

    private McpAuthorizer $authorizer;

    protected function setUp(): void
    {
        $access = new AccessRegistry();
        (new AccessCollector($access, 'Customer'))
            ->capability('customer.view')
            ->capability('customer.support')
            ->role('agent', ['customer.view', 'customer.support'])
            ->role('viewer', ['customer.view']);

        $this->registry = new McpRegistry();
        (new McpCollector($this->registry, 'Customer'))
            ->tool('greet', GreetTool::class, 'Open to any authenticated caller.')
            ->tool('customer.get', GreetTool::class, permission: 'customer.view')
            ->resource('customer://{id}', CustomerResource::class, permission: 'customer.view')
            ->prompt('customer.support', SupportPrompt::class, permission: 'customer.support');

        $this->filters = new FilterEngine();
        $this->authorizer = new McpAuthorizer(new Authorizer($access, $this->filters));
    }

    private function tools(McpAuthorizer $authorizer): ToolRunner
    {
        return new ToolRunner($this->registry, new Container(), $authorizer);
    }

    /** @return list<string> */
    private function visibleTools(?Identity $identity, ?McpAuthorizer $authorizer = null): array
    {
        return \array_column($this->tools($authorizer ?? $this->authorizer)->list(Contexts::make($identity)), 'name');
    }

    public function test_a_guest_is_refused_everything_by_default(): void
    {
        $guest = Contexts::make();

        self::assertSame([], $this->visibleTools(null));
        self::assertSame([], (new ResourceReader($this->registry, new Container(), $this->authorizer))->templates($guest));
        self::assertSame([], (new PromptProvider($this->registry, new Container(), $this->authorizer))->list($guest));

        $this->expectException(ToolException::class);

        $this->tools($this->authorizer)->call('greet', ['name' => 'x'], $guest);
    }

    public function test_the_caller_sees_and_calls_exactly_what_their_roles_allow(): void
    {
        self::assertSame(['greet'], $this->visibleTools(new Identity('1', 'nobody')));
        self::assertSame(['greet', 'customer.get'], $this->visibleTools(new Identity('2', 'vera', ['viewer'])));

        $result = $this->tools($this->authorizer)->call('customer.get', ['name' => 'x'], Contexts::make(new Identity('2', 'vera', ['viewer'])));
        self::assertFalse($result->isError());
    }

    /** Refused looks like missing: a client cannot map what it may not use. */
    public function test_a_refused_capability_answers_exactly_as_one_that_does_not_exist(): void
    {
        $nobody = Contexts::make(new Identity('1', 'nobody'));

        foreach (['customer.get', 'no.such.tool'] as $name) {
            try {
                $this->tools($this->authorizer)->call($name, [], $nobody);
                self::fail($name . ' was called');
            } catch (ToolException $e) {
                $errors[] = [$e->error()->code, $e->error()->message];
            }
        }

        self::assertSame($errors[0], $errors[1]);

        $reader = new ResourceReader($this->registry, new Container(), $this->authorizer);

        try {
            $reader->read('customer://42', $nobody);
            self::fail('a resource was read without permission');
        } catch (ResourceException $e) {
            self::assertSame('Resource not found.', $e->error()->message);
        }

        $this->expectException(PromptException::class);
        $this->expectExceptionMessage('Unknown prompt.');

        (new PromptProvider($this->registry, new Container(), $this->authorizer))->get('customer.support', ['customer' => '1'], Contexts::make(new Identity('2', 'vera', ['viewer'])));
    }

    public function test_nothing_is_built_for_a_refused_call(): void
    {
        CustomerResource::$reads = [];

        try {
            (new ResourceReader($this->registry, new Container(), $this->authorizer))->read('customer://42', Contexts::make(new Identity('1', 'nobody')));
        } catch (ResourceException) {
        }

        self::assertSame([], CustomerResource::$reads);
    }

    /** A module narrows by capability, through the ordinary decision filter, and cannot widen. */
    public function test_the_decision_filter_can_narrow_by_capability_and_not_widen(): void
    {
        $this->filters->add(Authorizer::DECISION_FILTER, static fn(bool $allowed, AuthCapability $required, Identity $who, mixed $subject): bool => $subject instanceof Capability && $subject->name === 'customer.get' ? false : $allowed);
        $this->filters->add(Authorizer::DECISION_FILTER, static fn(): bool => true, 5);

        self::assertSame(['greet'], $this->visibleTools(new Identity('2', 'vera', ['viewer'])));
    }

    public function test_guests_can_be_let_in_to_what_needs_no_permission_only(): void
    {
        $access = new AccessRegistry();
        (new AccessCollector($access))->capability('customer.view')->capability('customer.support');
        $open = new McpAuthorizer(new Authorizer($access), allowGuests: true);

        self::assertSame(['greet'], $this->visibleTools(null, $open));
    }

    public function test_a_permission_that_is_not_a_capability_name_is_refused_at_registration(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('asks for permission "Customer View"');

        (new McpCollector(new McpRegistry()))->tool('x', GreetTool::class, permission: 'Customer View');
    }

    public function test_a_permission_nobody_declared_stops_boot(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('leaky.use');

        $this->shippedApplication(['modules' => ['paths' => ['modules/Shared', 'tests/Fixtures/Modules/McpUndeclared/Plugins']]])->boot();
    }
}
