<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * The command registration API a module receives.
 *
 * Exactly what RouteCollector is for routes: the module declares what it owns,
 * and the collector stamps each command with that module so the console can say
 * where a command came from.
 *
 *     $module->commands(static function (CommandCollector $commands): void {
 *         $commands->add('customer:sync', SyncCustomers::class)
 *             ->describe('Pull customer records from the upstream system.');
 *     });
 *
 * There is no group() here, unlike routes. A route group composes a path
 * prefix, a name prefix and metadata all at once, which is worth a wrapper; a
 * command name is one string, and "customer:" written twice is clearer than a
 * closure that puts it there invisibly.
 */
final class CommandCollector
{
    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly ?string $module = null,
    ) {}

    /**
     * Declare a command.
     *
     * The handler takes the same three forms a route handler does -- an
     * invokable class name, [Class::class, 'method'], or a closure -- and is
     * resolved through the container, so its constructor dependencies are
     * injected exactly as a route handler's are.
     */
    public function add(string $name, mixed $handler): Command
    {
        return $this->registry->add(new Command($name, $handler, $this->module));
    }
}
