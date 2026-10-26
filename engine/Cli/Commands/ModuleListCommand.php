<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\Output;
use App\Engine\Module\Dependency;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleManager;
use App\Engine\Routing\Router;

/**
 * Every discovered module, in the order it registers.
 *
 * The order is the interesting column. Registration order decides which
 * module's hook runs first at equal priority, and "why did my listener run
 * after theirs" is answered by reading this listing top to bottom. Since
 * modules could depend on each other it is the resolved order, which differs
 * from plain kind-then-name order only where a dependency forced it -- and the
 * REQUIRES column says which one did.
 *
 * Disabled modules are listed underneath rather than left out. A module that is
 * installed and switched off is exactly the thing somebody is looking for when
 * a feature has gone missing.
 */
final class ModuleListCommand
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly Router $router,
        private readonly CommandRegistry $commands,
    ) {}

    public function __invoke(Output $output): int
    {
        $registry = $this->modules->registry();
        $contexts = $registry->contexts();

        if ($contexts === [] && $registry->disabledIds() === []) {
            $output->line('No modules were discovered.');

            return 0;
        }

        if ($contexts !== []) {
            $output->table(
                ['ID', 'KIND', 'NAME', 'VERSION', 'ROUTES', 'COMMANDS', 'HOOKS', 'FILTERS', 'REQUIRES'],
                \array_map(
                    fn(ModuleContext $context): array => [
                        $context->id(),
                        $context->kind()->value,
                        $context->moduleName(),
                        $context->moduleVersion(),
                        (string) $this->routesOwnedBy($context->id()),
                        (string) $this->commandsOwnedBy($context->id()),
                        (string) \count($context->declaredHooks()),
                        (string) \count($context->declaredFilters()),
                        self::describeDependencies($context, fn(string $id): bool => $registry->isEnabled($id)),
                    ],
                    $contexts,
                ),
            );
        }

        $disabled = $registry->disabledIds();

        if ($disabled !== []) {
            $output->line();
            $output->line('Disabled (installed, switched off in modules.disabled): ' . \implode(', ', $disabled));
        }

        return 0;
    }

    /**
     * `Shared ^0.1, Crm? (absent)`.
     *
     * An optional dependency carries a question mark, and one that is not there
     * says so -- otherwise the listing would show an integration as if it were
     * active.
     *
     * @param \Closure(string): bool $isEnabled
     */
    private static function describeDependencies(ModuleContext $context, \Closure $isEnabled): string
    {
        $parts = \array_map(
            static fn(Dependency $dependency): string => $dependency->id
                . ($dependency->optional ? '?' : '')
                . ($dependency->constraint->isAny() ? '' : ' ' . $dependency->constraint)
                . ($dependency->optional && !$isEnabled($dependency->id) ? ' (absent)' : ''),
            $context->declaredDependencies(),
        );

        return $parts === [] ? '-' : \implode(', ', $parts);
    }

    private function routesOwnedBy(string $module): int
    {
        $count = 0;

        foreach ($this->router->routes() as $route) {
            if ($route->module() === $module) {
                ++$count;
            }
        }

        return $count;
    }

    private function commandsOwnedBy(string $module): int
    {
        $count = 0;

        foreach ($this->commands->all() as $command) {
            if ($command->module === $module) {
                ++$count;
            }
        }

        return $count;
    }
}
