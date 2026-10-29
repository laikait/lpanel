<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\MCP\CapabilityKind;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\RegistryException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class McpRegistryTest extends TestCase
{
    private McpRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new McpRegistry();
    }

    private function module(string $id): McpCollector
    {
        return new McpCollector($this->registry, $id);
    }

    public function test_a_fresh_registry_offers_nothing(): void
    {
        self::assertSame([], $this->registry->everything());
        self::assertNull($this->registry->find(CapabilityKind::Tool, 'customer.get'));
    }

    public function test_capabilities_are_found_by_kind_and_name_and_know_their_module(): void
    {
        $this->module('Customer')
            ->tool('customer.get', \ArrayObject::class, 'Retrieve a customer by id.')
            ->resource('customer://{id}', \ArrayIterator::class)
            ->prompt('customer.support', \SplStack::class);

        $tool = $this->registry->find(CapabilityKind::Tool, 'customer.get');

        self::assertNotNull($tool);
        self::assertSame('Customer', $tool->module);
        self::assertSame('Retrieve a customer by id.', $tool->description);
        self::assertSame(CapabilityKind::Resource, $this->registry->find(CapabilityKind::Resource, 'customer://{id}')?->kind);
        self::assertNull($this->registry->find(CapabilityKind::Prompt, 'customer.get'), 'kinds are separate namespaces');
    }

    /** Registration order, which is module load order: the same listing everywhere. */
    public function test_listing_is_in_registration_order(): void
    {
        $this->module('shared')->tool('zeta', \SplQueue::class)->tool('alpha', \SplQueue::class);
        $this->module('Billing')->tool('mid', \SplQueue::class)->prompt('billing.explain', \SplQueue::class);

        self::assertSame(['zeta', 'alpha', 'mid'], \array_map(static fn($c) => $c->name, $this->registry->all(CapabilityKind::Tool)));
        self::assertSame(['zeta', 'alpha', 'mid', 'billing.explain'], \array_map(static fn($c) => $c->name, $this->registry->everything()));
    }

    public function test_a_name_belongs_to_one_module(): void
    {
        $this->module('Customer')->tool('customer.get', \ArrayObject::class);

        try {
            $this->module('Crm')->tool('customer.get', \ArrayIterator::class);
            self::fail('a duplicate was registered');
        } catch (RegistryException $e) {
            self::assertStringContainsString('registered by Customer and again by Crm', $e->getMessage());
        }

        self::assertSame(\ArrayObject::class, $this->registry->find(CapabilityKind::Tool, 'customer.get')?->handler);
    }

    public function test_the_same_name_may_be_a_tool_and_a_prompt(): void
    {
        $this->module('Customer')->tool('customer.support', \ArrayObject::class)->prompt('customer.support', \ArrayObject::class);

        self::assertCount(2, $this->registry->everything());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'capitals' => ['Customer.Get'];
        yield 'space' => ['customer get'];
        yield 'slash' => ['customer/get'];
        yield 'leading digit' => ['1customer'];
        yield 'too long' => ['a' . \str_repeat('b', 128)];
    }

    #[DataProvider('invalidNames')]
    public function test_an_invalid_tool_name_is_refused(string $name): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('is invalid');

        $this->module('Customer')->tool($name, \ArrayObject::class);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTemplates(): iterable
    {
        yield 'no scheme' => ['customers/{id}', 'scheme://path'];
        yield 'the filesystem' => ['file:///etc/{name}', 'filesystem'];
        yield 'unbalanced' => ['customer://{id', 'placeholders'];
        yield 'a bad placeholder' => ['customer://{ID-x}', 'placeholders'];
        yield 'spaces' => ['customer://a b', 'scheme://path'];
    }

    #[DataProvider('invalidTemplates')]
    public function test_an_invalid_resource_template_is_refused(string $template, string $why): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage($why);

        $this->module('Customer')->resource($template, \ArrayObject::class);
    }

    public function test_a_capability_without_a_handler_is_refused(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('has no handler class');

        // @phpstan-ignore argument.type (the point of the test is an empty handler)
        $this->module('Customer')->prompt('customer.support', ' ');
    }
}
