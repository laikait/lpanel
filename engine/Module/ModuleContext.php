<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\MCP\McpCollector;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Template\TemplateHelperCollector;

/**
 * The entire API a module author touches.
 *
 * A module.php returns a closure taking one of these:
 *
 *     return static function (ModuleContext $module): void {
 *         $module->name('Customers')->version('1.0.0');
 *
 *         $module->services(static function (ServiceRegistrar $services): void {
 *             $services->singleton(CustomerCatalog::class);
 *         });
 *
 *         $module->routes(static function (RouteCollector $routes): void {
 *             $routes->get('/customers', ListCustomers::class)->name('customers.index');
 *         });
 *
 *         $module->hook('customer.created', [CustomerHooks::class, 'onCreated'], priority: 20);
 *     };
 *
 * Three properties of that shape are deliberate.
 *
 * One argument is the whole API. There is nothing to extend and nothing to
 * implement: a module is a file that describes itself, and this class's method
 * list is both the documentation and what the IDE completes.
 *
 * Declarations are RECORDED, not executed. The closure runs during Load and
 * only writes down intentions; nothing is bound, routed or hooked while it is
 * running. That is what makes ordering deterministic instead of dependent on
 * the order the filesystem happened to hand back directories.
 *
 * Stage legality is enforced by construction. Every method asserts the current
 * stage and throws naming the module and the method, so a mistake surfaces at
 * the line that made it.
 *
 * This is not a service provider: there is no class to subclass, the Register
 * stage sees only a write-only ServiceRegistrar, and boot callbacks receive
 * their dependencies as injected parameters rather than a container handle.
 */
final class ModuleContext
{
    /** The one filter a module may not attach to. See filter(). */
    public const ASSET_RESPONSE_FILTER = 'asset.response';

    private ModuleStage $stage = ModuleStage::Discovered;

    private string $name;

    private string $version = '0.0.0';

    private string $description = '';

    /**
     * Whether version() was called, as distinct from the default.
     *
     * Only for the error message: "requires ^1.0 but it declares no version at
     * all" is a different fix from "requires ^1.0 but it is 0.4.0".
     */
    private bool $versionDeclared = false;

    /** @var array<string, Dependency> keyed by module id */
    private array $dependencies = [];

    /** @var list<\Closure(ServiceRegistrar): void> */
    private array $serviceRegistrars = [];

    /** @var list<\Closure(RouteCollector): void> */
    private array $routeRegistrars = [];

    /** @var list<\Closure(CommandCollector): void> */
    private array $commandRegistrars = [];

    /** @var list<\Closure(ScheduleCollector): void> */
    private array $scheduleRegistrars = [];

    /** @var list<\Closure(AccessCollector): void> */
    private array $accessRegistrars = [];

    /** @var list<\Closure(McpCollector): void> */
    private array $mcpRegistrars = [];

    /** @var list<\Closure(TemplateHelperCollector): void> */
    private array $templateRegistrars = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    private array $hooks = [];

    /** @var list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    private array $filters = [];

    /** @var list<\Closure> */
    private array $bootCallbacks = [];

    public function __construct(public readonly ModuleDefinition $definition)
    {
        $this->name = $definition->id;
    }

    // ---- metadata ---------------------------------------------------------

    public function name(string $name): self
    {
        return $this->declaring('name', function () use ($name): void {
            $this->name = $name;
        });
    }

    /**
     * MAJOR.MINOR.PATCH, checked here.
     *
     * Decorative until modules could depend on each other; compared from then
     * on, so a version that cannot be compared is refused at the line that
     * wrote it rather than when another module first asks. See Version.
     */
    public function version(string $version): self
    {
        return $this->declaring('version', function () use ($version): void {
            Version::parse($version, $this->definition->id);

            $this->version = $version;
            $this->versionDeclared = true;
        });
    }

    public function description(string $description): self
    {
        return $this->declaring('description', function () use ($description): void {
            $this->description = $description;
        });
    }

    // ---- dependencies -----------------------------------------------------

    /**
     * This module cannot work without that one.
     *
     * ```php
     * $module->requires('Customer', '^1.0');
     * ```
     *
     * The application refuses to boot if the other module is missing, disabled
     * or the wrong version, and this module registers after it. Declared here,
     * in the module's own file, for the reason everything else is: installing a
     * module brings its requirements with it, and a reviewer sees them next to
     * the routes that depend on them.
     *
     * Depending on `shared` is allowed and worth doing when the version matters;
     * it is not needed for ordering, because shared always registers first.
     */
    public function requires(string $id, string $constraint = VersionConstraint::ANY): self
    {
        return $this->declaring('requires', function () use ($id, $constraint): void {
            $this->recordDependency($id, $constraint, optional: false);
        });
    }

    /**
     * This module works better alongside that one, and works without it.
     *
     * When the other module is present and enabled it is held to the same rules
     * as a required one -- its version must fit, and this module registers after
     * it. When it is absent or disabled, nothing happens.
     *
     * Two ways to act on "is it there": listen for its hooks, which simply never
     * fire without it and need no check at all; or inject ModuleRegistry in an
     * onBoot callback and ask isEnabled(). The first is almost always the right
     * one.
     */
    public function optionally(string $id, string $constraint = VersionConstraint::ANY): self
    {
        return $this->declaring('optionally', function () use ($id, $constraint): void {
            $this->recordDependency($id, $constraint, optional: true);
        });
    }

    // ---- declarations -----------------------------------------------------

    /**
     * Declare services.
     *
     * The closure receives a ServiceRegistrar, which can write to the container
     * and cannot read from it. Service location during registration is
     * therefore impossible rather than merely discouraged.
     *
     * @param \Closure(ServiceRegistrar): void $registrar
     */
    public function services(\Closure $registrar): self
    {
        return $this->declaring('services', function () use ($registrar): void {
            $this->serviceRegistrars[] = $registrar;
        });
    }

    /**
     * Declare routes.
     *
     * There is no global route file; a module owns the routes for the
     * capability it provides.
     *
     * @param \Closure(RouteCollector): void $registrar
     */
    public function routes(\Closure $registrar): self
    {
        return $this->declaring('routes', function () use ($registrar): void {
            $this->routeRegistrars[] = $registrar;
        });
    }

    /**
     * Declare console commands.
     *
     * The same shape as routes, deliberately: a module owns the commands for
     * the capability it provides, and a command handler is resolved through the
     * container exactly as a route handler is. There is no Commands/ directory
     * scanned by reflection -- a file's existence should not change what an
     * application does, and what a module contributes stays readable here.
     *
     * @param \Closure(CommandCollector): void $registrar
     */
    public function commands(\Closure $registrar): self
    {
        return $this->declaring('commands', function () use ($registrar): void {
            $this->commandRegistrars[] = $registrar;
        });
    }

    /**
     * Declare scheduled work.
     *
     * The third registrar with this shape, after routes and commands, and the
     * argument is the same one: a module owns a capability, and when that
     * capability runs unattended is part of it. The alternative is a machine's
     * crontab, which is not in version control, is not installed with the
     * module and is invisible to everyone who did not ssh in.
     *
     * @param \Closure(ScheduleCollector): void $registrar
     */
    public function schedules(\Closure $registrar): self
    {
        return $this->declaring('schedules', function () use ($registrar): void {
            $this->scheduleRegistrars[] = $registrar;
        });
    }

    /**
     * Declare what this module lets people do, and who may do it.
     *
     * The fourth registrar, and the argument for it is the one that applies to
     * all of them: the module that ENFORCES a capability is the only place that
     * knows what it means, so that is where it is defined. Installing the
     * module brings its permissions with it and removing it takes them away --
     * whereas a central list of permission strings goes stale the first time
     * somebody deletes a feature and nobody remembers to prune it.
     *
     * @param \Closure(AccessCollector): void $registrar
     */
    public function access(\Closure $registrar): self
    {
        return $this->declaring('access', function () use ($registrar): void {
            $this->accessRegistrars[] = $registrar;
        });
    }

    /**
     * Declare the MCP tools, resources and prompts this module offers.
     *
     * The same shape as routes and commands, for the same reason: what a module
     * exposes to an AI client is part of the module, installed and removed with
     * it, and visible in one file. Nothing is exposed that is not named here --
     * no directory is scanned, and no model or route becomes a tool on its own.
     *
     * @param \Closure(McpCollector): void $registrar
     */
    public function mcp(\Closure $registrar): self
    {
        return $this->declaring('mcp', function () use ($registrar): void {
            $this->mcpRegistrars[] = $registrar;
        });
    }

    /**
     * Declare the filters and functions this module offers to templates.
     *
     * ```php
     * $module->templates(static function (TemplateHelperCollector $templates): void {
     *     $templates->filter('money', [Money::class, 'format']);
     *     $templates->function('route', [Links::class, 'route']);
     * });
     * ```
     *
     * Its Templates/ directory needs no declaration -- having it is enough.
     * What does need one is anything a template calls, because a template can
     * call only what some module chose to offer, and the module that offers it
     * is where a reviewer looks. `[Money::class, 'format']` is built through the
     * container the first time a template calls it, as a route handler is.
     *
     * @param \Closure(TemplateHelperCollector): void $registrar
     */
    public function templates(\Closure $registrar): self
    {
        return $this->declaring('templates', function () use ($registrar): void {
            $this->templateRegistrars[] = $registrar;
        });
    }

    /**
     * Contribute configuration, merged under this module's id.
     *
     * @param array<string, mixed> $values
     */
    public function config(array $values): self
    {
        return $this->declaring('config', function () use ($values): void {
            $this->config = [...$this->config, ...$values];
        });
    }

    /**
     * Listen for an event.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function hook(string $hook, mixed $callback, int $priority = 10, ?int $acceptedArgs = null): self
    {
        return $this->declaring('hook', function () use ($hook, $callback, $priority, $acceptedArgs): void {
            $this->hooks[] = [
                'name' => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'acceptedArgs' => $acceptedArgs,
            ];
        });
    }

    /**
     * Transform a value.
     *
     * @param \Closure|callable-string|array{0: object|class-string, 1: string} $callback
     */
    public function filter(string $filter, mixed $callback, int $priority = 10, ?int $acceptedArgs = null): self
    {
        // Refused rather than recorded. An asset request is answered before any
        // module loads (see ModuleManager::prepareAssets()), and in production
        // the web server usually answers it without PHP at all, so this
        // listener would run in a test that booted the application first and
        // never anywhere that matters. A hook that works only by accident is
        // worse than one that says no while its author is still writing it.
        if ($filter === self::ASSET_RESPONSE_FILTER) {
            throw ModuleException::assetFilterNeverRuns($this->definition->id);
        }

        return $this->declaring('filter', function () use ($filter, $callback, $priority, $acceptedArgs): void {
            $this->filters[] = [
                'name' => $filter,
                'callback' => $callback,
                'priority' => $priority,
                'acceptedArgs' => $acceptedArgs,
            ];
        });
    }

    /**
     * Run once every module has registered.
     *
     * The callback is invoked through the container, so its parameters are
     * injected. There is no $app or $container handle to reach for, which is
     * what keeps service location from creeping back in at boot time.
     */
    public function onBoot(\Closure $callback): self
    {
        return $this->declaring('onBoot', function () use ($callback): void {
            $this->bootCallbacks[] = $callback;
        });
    }

    // ---- introspection ----------------------------------------------------

    public function id(): string
    {
        return $this->definition->id;
    }

    public function kind(): ModuleKind
    {
        return $this->definition->kind;
    }

    public function path(string $relative = ''): string
    {
        return $this->definition->file($relative);
    }

    public function moduleName(): string
    {
        return $this->name;
    }

    public function moduleVersion(): string
    {
        return $this->version;
    }

    public function moduleDescription(): string
    {
        return $this->description;
    }

    public function declaresVersion(): bool
    {
        return $this->versionDeclared;
    }

    public function stage(): ModuleStage
    {
        return $this->stage;
    }

    // ---- used by the manager ----------------------------------------------

    public function enterStage(ModuleStage $stage): void
    {
        $this->stage = $stage;
    }

    /** @return list<Dependency> in the order they were declared */
    public function declaredDependencies(): array
    {
        return \array_values($this->dependencies);
    }

    /** @return list<\Closure(ServiceRegistrar): void> */
    public function declaredServices(): array
    {
        return $this->serviceRegistrars;
    }

    /** @return list<\Closure(RouteCollector): void> */
    public function declaredRoutes(): array
    {
        return $this->routeRegistrars;
    }

    /** @return list<\Closure(CommandCollector): void> */
    public function declaredCommands(): array
    {
        return $this->commandRegistrars;
    }

    /** @return list<\Closure(ScheduleCollector): void> */
    public function declaredSchedules(): array
    {
        return $this->scheduleRegistrars;
    }

    /** @return list<\Closure(AccessCollector): void> */
    public function declaredAccess(): array
    {
        return $this->accessRegistrars;
    }

    /** @return list<\Closure(McpCollector): void> */
    public function declaredMcp(): array
    {
        return $this->mcpRegistrars;
    }

    /** @return list<\Closure(TemplateHelperCollector): void> */
    public function declaredTemplates(): array
    {
        return $this->templateRegistrars;
    }

    /** @return array<string, mixed> */
    public function declaredConfig(): array
    {
        return $this->config;
    }

    /** @return list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    public function declaredHooks(): array
    {
        return $this->hooks;
    }

    /** @return list<array{name: string, callback: mixed, priority: int, acceptedArgs: int|null}> */
    public function declaredFilters(): array
    {
        return $this->filters;
    }

    /** @return list<\Closure> */
    public function declaredBootCallbacks(): array
    {
        return $this->bootCallbacks;
    }

    /**
     * Check the id and the constraint where they were written.
     *
     * Only the shape is checked here. Whether the other module exists, is
     * enabled and fits is a question about every module at once, so it waits
     * for DependencyResolver -- a module may name one that simply has not been
     * loaded yet.
     */
    private function recordDependency(string $id, string $constraint, bool $optional): void
    {
        if (!Dependency::isValidId($id)) {
            throw ModuleException::invalidDependencyId($this->definition->id, $id);
        }

        if (isset($this->dependencies[$id])) {
            throw ModuleException::duplicateDependency($this->definition->id, $id);
        }

        $this->dependencies[$id] = new Dependency(
            $id,
            VersionConstraint::parse($constraint, $this->definition->id),
            $optional,
        );
    }

    /**
     * Every declaration goes through here, so the stage rule is stated once.
     *
     * @param \Closure(): void $record
     */
    private function declaring(string $method, \Closure $record): self
    {
        if ($this->stage !== ModuleStage::Loading) {
            throw ModuleException::wrongStage(
                $this->definition->id,
                $method,
                $this->stage,
                'Everything a module declares must be declared while module.php is running. '
                . 'By the time registration starts, the set of declarations is fixed.',
            );
        }

        $record();

        return $this;
    }
}
