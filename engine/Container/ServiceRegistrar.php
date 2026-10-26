<?php

declare(strict_types=1);

namespace App\Engine\Container;

/**
 * The write-only view of the container handed to modules at registration time.
 *
 * This class is the structural reason modules are not service providers. It can
 * bind, and it cannot read: there is no get(), no make(), no has(), and no way
 * to reach the underlying container from it. Service location during
 * registration is therefore impossible by construction rather than by
 * convention, which is what makes "prefer injected services" enforceable.
 *
 * Boot-time code does not get one of these at all; boot callbacks are invoked
 * through Container::call() so their dependencies arrive as parameters.
 */
final class ServiceRegistrar
{
    public function __construct(private readonly Container $container) {}

    public function bind(string $id, \Closure|string|null $factory = null): void
    {
        $this->container->bind($id, $factory);
    }

    public function singleton(string $id, \Closure|string|null $factory = null): void
    {
        $this->container->singleton($id, $factory);
    }

    public function instance(string $id, object $instance): void
    {
        $this->container->instance($id, $instance);
    }

    /**
     * A shared service built by a closure. Sugar for the most common binding
     * shape, and named so that module.php reads as a declaration.
     *
     * @param \Closure(Container): mixed $factory
     */
    public function factory(string $id, \Closure $factory): void
    {
        $this->container->singleton($id, $factory);
    }
}
