<?php

declare(strict_types=1);

namespace App\Tests\Unit\Container;

use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Tests\Fixtures\Container\EnglishGreeter;
use App\Tests\Fixtures\Container\Greeter;
use App\Tests\Fixtures\Container\Leaf;
use App\Tests\Support\TestCase;

final class ServiceRegistrarTest extends TestCase
{
    private Container $container;

    private ServiceRegistrar $registrar;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->registrar = new ServiceRegistrar($this->container);
    }

    public function test_bind_delegates_to_the_container(): void
    {
        $this->registrar->bind(Greeter::class, EnglishGreeter::class);

        self::assertInstanceOf(EnglishGreeter::class, $this->container->get(Greeter::class));
    }

    public function test_singleton_delegates_and_is_shared(): void
    {
        $this->registrar->singleton(Leaf::class);

        self::assertSame($this->container->get(Leaf::class), $this->container->get(Leaf::class));
    }

    public function test_instance_delegates(): void
    {
        $leaf = new Leaf();
        $this->registrar->instance('leaf', $leaf);

        self::assertSame($leaf, $this->container->get('leaf'));
    }

    public function test_factory_registers_a_shared_closure_backed_service(): void
    {
        $this->registrar->factory('thing', static fn(Container $c): object => $c->get(Leaf::class));

        self::assertSame($this->container->get('thing'), $this->container->get('thing'));
    }

    /**
     * The structural guarantee that keeps modules from becoming service
     * providers: registration can write to the container and can never read
     * from it. If this test ever fails, service location has become possible
     * during the Register stage and the invariant is gone.
     */
    public function test_it_exposes_no_way_to_read_from_the_container(): void
    {
        $reflection = new \ReflectionClass(ServiceRegistrar::class);

        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[] = $method->getName();
        }

        \sort($methods);

        self::assertSame(
            ['__construct', 'bind', 'factory', 'instance', 'singleton'],
            $methods,
            'ServiceRegistrar gained a method. If it can read, modules can service-locate.',
        );

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }

            self::assertSame(
                'void',
                (string) $method->getReturnType(),
                \sprintf('%s() returns something; registration must be write-only.', $method->getName()),
            );
        }
    }
}
