<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetSource;
use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthGuard;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Localization\TranslationCatalog;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpRegistry;
use App\Engine\Routing\RouteCollector;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleCollector;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Template\TemplateHelperCollector;
use App\Engine\Template\TemplateHelpers;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;

/**
 * Drives the module lifecycle: Discover, Load, Resolve, Register, Boot, Ready.
 *
 * What is legal in each stage:
 *
 *   Discover   No user code runs at all. The filesystem is scanned for
 *              module.php files under the configured roots, each candidate is
 *              checked for containment, and definitions are built from plain
 *              scalars. This is the stage a cache can replace wholesale.
 *              A request for an asset stops here -- see prepareAssets().
 *
 *   Load       Each module.php closure runs and RECORDS its declarations.
 *              Nothing is bound, routed or hooked yet, so a module cannot
 *              observe whether it happened to load before or after another.
 *              A module named in modules.disabled is skipped here: its
 *              module.php never runs at all.
 *
 *   Resolve    Dependencies are checked across every module at once -- missing,
 *              disabled, wrong version, circular -- and the registration order
 *              is fixed. It is the only moment every declaration is known and
 *              none has taken effect. See DependencyResolver.
 *
 *   Register   Declarations are replayed across every module, BY CATEGORY:
 *              config, then assets and templates, then services, then
 *              routes, then commands, then schedules, then access, then MCP
 *              capabilities, then hooks, then filters.
 *              This is the important detail. Replaying per module instead
 *              would mean one module's route registration could run before
 *              another's service bindings, which is precisely the ordering
 *              bug service providers are famous for. By category, all config
 *              exists before any factory is defined, and every service is
 *              bound before any route is registered.
 *
 *   Boot       onBoot callbacks run in module order, with their parameters
 *              injected. Everything is registered by now, so cross-module work
 *              is safe here and only here.
 *
 *   Ready      Nothing further happens; the contexts are frozen.
 */
final class ModuleManager
{
    private ModuleStage $stage = ModuleStage::Discovered;

    private bool $ran = false;

    private bool $resolved = false;

    private bool $discovered = false;

    private bool $fromCache = false;

    private bool $disabledApplied = false;

    private bool $assetsPublished = false;

    /** @var (\Closure(string, ?string, int): void)|null */
    private ?\Closure $observer = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Router $router,
        private readonly HookEngine $hooks,
        private readonly FilterEngine $filters,
        private readonly AssetRegistry $assets = new AssetRegistry(),
        private readonly TemplateRegistry $templates = new TemplateRegistry(),
        private readonly CommandRegistry $commands = new CommandRegistry(),
        private readonly ScheduleRegistry $schedules = new ScheduleRegistry(),
        private readonly AccessRegistry $access = new AccessRegistry(),
        private readonly ModuleRegistry $registry = new ModuleRegistry(),
        private readonly string $basePath = '',
        private readonly McpRegistry $mcp = new McpRegistry(),
        private readonly TranslationCatalog $translations = new TranslationCatalog(),
        private readonly TemplateHelpers $templateHelpers = new TemplateHelpers(),
    ) {}

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    public function stage(): ModuleStage
    {
        return $this->stage;
    }

    /** Discover, load, register and boot. Idempotent. */
    public function run(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        // Load and boot are timed per module inside their loops, because those
        // are the two stages that run a module's own code. The other three are
        // the framework's work, and a total is the useful number for them.
        $this->timed('discover', null, $this->discover(...));
        $this->load();
        $this->timed('resolve', null, $this->resolve(...));
        $this->timed('register', null, $this->register(...));
        $this->boot();

        $this->stage = ModuleStage::Ready;
    }

    /**
     * Be told how long each stage took, and for load and boot, each module.
     *
     * The observer receives the stage name, the module id where the stage is
     * per-module (null otherwise), and nanoseconds. It is the seam module timing
     * attaches to; this class does not know what observes it.
     *
     * Only run() is timed. A caller driving the stages one by one is a test or a
     * tool, and is timing things itself.
     *
     * @param (\Closure(string, ?string, int): void)|null $observer
     */
    public function observe(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    /** @param \Closure(): void $work */
    private function timed(string $stage, ?string $module, \Closure $work): void
    {
        $observer = $this->observer;

        if ($observer === null || !$this->ran) {
            $work();

            return;
        }

        $started = \hrtime(true);

        try {
            $work();
        } finally {
            $observer($stage, $module, \hrtime(true) - $started);
        }
    }

    // ---- discover ---------------------------------------------------------

    /**
     * Find the installed modules: from the discovery cache when there is one
     * and this is not a debug process, otherwise by scanning. Idempotent.
     *
     * No module code runs here, which is what makes this stage cacheable and
     * what makes a broken module fail at Load with a clear message rather than
     * during a filesystem walk. See ModuleDiscovery for the walk itself.
     *
     * Nothing here WRITES the cache. It used to be written by the first request
     * that found it missing, behind a setting, and that is the version of a
     * cache that gets built on a developer's laptop halfway through adding a
     * module. It is a deployment artefact now, built by cache:warm.
     */
    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;
        $this->stage = ModuleStage::Discovered;

        $discovery = ModuleDiscovery::fromConfig($this->config, $this->basePath);

        if ($this->readDiscoveryCache($discovery)) {
            $this->fromCache = true;

            return;
        }

        foreach ($discovery->scan() as $definition) {
            $this->registry->add($definition);
        }
    }

    /** Whether discovery was answered by the cache rather than by a scan. */
    public function discoveredFromCache(): bool
    {
        return $this->fromCache;
    }

    // ---- the asset path ---------------------------------------------------

    /**
     * Everything a request for /assets/... needs from the modules, and nothing
     * else: which are installed, which are switched off, and which have an
     * assets/ directory. No module.php runs, no service is bound, nothing boots.
     *
     * This is the specification's "/assets/... should not initialize billing"
     * taken literally, and the reason it can be is that publishing a module's
     * assets was never a declaration -- having the directory is the declaration
     * (see publishAssets()), so discovery already knows the whole answer.
     *
     * The cost is that module code cannot take part in delivering an asset.
     * That was already true wherever the web server serves assets itself, which
     * is the recommended production setup, and a hook that only works when PHP
     * happens to be serving is worse than one that is refused. ModuleContext
     * refuses a module filter on asset.response for that reason.
     *
     * Safe to follow with run(): nothing here is repeated.
     */
    public function prepareAssets(): void
    {
        $this->discover();
        $this->applyDisabled();
        $this->publishAssets();
    }

    // ---- load -------------------------------------------------------------

    /**
     * Run each module.php.
     *
     * The closure only records; see ModuleContext. A module.php that returns
     * anything else fails here, naming the file, rather than producing a
     * TypeError deeper in the manager.
     */
    public function load(): void
    {
        $this->stage = ModuleStage::Loading;

        // Before anything runs, so a disabled module's code is never executed:
        // not its module.php, not a class it would have autoloaded. "Disabled"
        // that still ran the declarations would be a module half on.
        $this->applyDisabled();

        foreach ($this->registry->definitions() as $definition) {
            if ($this->registry->context($definition->id) !== null) {
                continue;
            }

            $context = new ModuleContext($definition);
            $context->enterStage(ModuleStage::Loading);

            $this->timed('load', $definition->id, static function () use ($definition, $context): void {
                /** @var mixed $entry */
                $entry = require $definition->entryFile;

                if (!$entry instanceof \Closure) {
                    throw ModuleException::entryMustReturnClosure(
                        $definition->id,
                        $definition->entryFile,
                        \get_debug_type($entry),
                    );
                }

                $entry($context);
            });

            $this->registry->setContext($context);
        }
    }

    /**
     * Switch off what modules.disabled names.
     *
     * Two refusals. An id that is not installed, because a typo here would
     * leave the module running while the configuration says it is off. And
     * shared, because every other module may rely on it without saying so --
     * disabling it would break modules that never declared a dependency.
     */
    private function applyDisabled(): void
    {
        if ($this->disabledApplied) {
            return;
        }

        $this->disabledApplied = true;

        /** @var mixed $configured */
        $configured = $this->config->get('modules.disabled', []);

        if (!\is_array($configured)) {
            return;
        }

        foreach ($configured as $id) {
            if (!\is_string($id) || $id === '') {
                continue;
            }

            if ($id === ModuleDefinition::SHARED) {
                throw ModuleException::sharedCannotBeDisabled();
            }

            $this->registry->disable($id);
        }
    }

    // ---- resolve ----------------------------------------------------------

    /**
     * Check every dependency and fix the registration order.
     *
     * Idempotent, and called by register() if nobody called it first, so code
     * that drives the stages one at a time cannot register in an order nobody
     * checked.
     */
    public function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->stage = ModuleStage::Resolving;

        $this->registry->setOrder(
            (new DependencyResolver())->resolve($this->registry->contexts(), $this->registry->disabledIds()),
        );

        $this->resolved = true;
    }

    // ---- register ---------------------------------------------------------

    /**
     * Replay every module's declarations, by category rather than per module.
     *
     * See the class docblock: this ordering is the point. "Per category" means
     * within each category the modules go in resolved order, so a module's
     * services are bound after the services of everything it requires.
     */
    public function register(): void
    {
        $this->resolve();

        $this->stage = ModuleStage::Registering;

        $this->shareCollaborators();

        $contexts = $this->registry->contexts();

        foreach ($contexts as $context) {
            $context->enterStage(ModuleStage::Registering);
        }

        foreach ($contexts as $context) {
            $values = $context->declaredConfig();

            if ($values !== []) {
                // defaults(), not merge(): a module declares defaults for its
                // own settings and the application's config/Billing.php
                // -- read long before this runs -- outranks them.
                $this->config->defaults($context->id(), $values);
            }
        }

        $this->publishAssets();
        $this->publishTemplates();
        $this->publishTranslations();

        $registrar = new ServiceRegistrar($this->container);

        foreach ($contexts as $context) {
            foreach ($context->declaredServices() as $declare) {
                $declare($registrar);
            }
        }

        foreach ($contexts as $context) {
            $collector = new RouteCollector($this->router, $context->id());

            foreach ($context->declaredRoutes() as $declare) {
                $declare($collector);
            }
        }

        // After routes, and like them: a helper names a callback that is only
        // built when a template first calls it, so nothing here reads the
        // container.
        foreach ($contexts as $context) {
            $collector = new TemplateHelperCollector($this->templateHelpers, $context->id());

            foreach ($context->declaredTemplates() as $declare) {
                $declare($collector);
            }
        }

        foreach ($contexts as $context) {
            $collector = new CommandCollector($this->commands, $context->id());

            foreach ($context->declaredCommands() as $declare) {
                $declare($collector);
            }
        }

        // After commands, never before: a scheduled command is checked against
        // the registry as it is declared, so every module's commands have to
        // exist by the time any module's schedules are read.
        foreach ($contexts as $context) {
            $collector = new ScheduleCollector($this->schedules, $this->commands, $context->id());

            foreach ($context->declaredSchedules() as $declare) {
                $declare($collector);
            }
        }

        // Now, and not inside the loop: ->identify() may still have been about
        // to change an id while the closure above was running.
        $this->schedules->assertUnique();

        foreach ($contexts as $context) {
            $collector = new AccessCollector($this->access, $context->id());

            foreach ($context->declaredAccess() as $declare) {
                $declare($collector);
            }
        }

        // Both checks wait until every module has spoken, because either could
        // legitimately be satisfied by a module that has not registered yet: a
        // role may inherit one the shared module declares, and a route may ask
        // for a capability the module that enforces it defines.
        $this->access->assertConsistent();
        $this->assertRoutesAskForDeclaredCapabilities();

        // After access: a capability's permission is checked against the
        // declared capabilities, so every module's must exist first.
        foreach ($contexts as $context) {
            $collector = new McpCollector($this->mcp, $context->id());

            foreach ($context->declaredMcp() as $declare) {
                $declare($collector);
            }
        }

        // The rule a route follows: asking for a capability nobody declared is a
        // mistake to find at boot, not a refusal to debug from a client.
        foreach ($this->mcp->everything() as $capability) {
            if ($capability->permission !== null && !$this->access->hasPermission($capability->permission)) {
                throw AuthException::undeclaredCapability(
                    $capability->permission,
                    \sprintf('MCP %s "%s" in %s', $capability->kind->value, $capability->name, $capability->module),
                );
            }
        }

        foreach ($contexts as $context) {
            foreach ($context->declaredHooks() as $hook) {
                $this->hooks->add(
                    $hook['name'],
                    $hook['callback'],
                    $hook['priority'],
                    $context->id(),
                    $hook['acceptedArgs'],
                );
            }
        }

        foreach ($contexts as $context) {
            foreach ($context->declaredFilters() as $filter) {
                $this->filters->add(
                    $filter['name'],
                    $filter['callback'],
                    $filter['priority'],
                    $context->id(),
                    $filter['acceptedArgs'],
                );
            }
        }

        // Only now can anything be listening, which is why this is not emitted
        // inside the loops above.
        foreach ($contexts as $context) {
            $this->hooks->do('module.registered', $context->definition);
        }
    }

    /**
     * Every capability a route asks for has to be one some module declared.
     *
     * The failure this prevents is a quiet one. A route asking for
     * "invoice.viod" refuses everybody -- including the administrator with
     * every role -- and looks like a routing fault or a broken login rather
     * than a typo, because the one thing it never says is "that capability
     * does not exist". Here it is a boot failure naming the route.
     *
     * The same reason the scheduler checks its commands exist as they are
     * declared: a mistake in a declaration should be found by whoever wrote it,
     * not by whoever was wrongly refused at two in the morning.
     */
    private function assertRoutesAskForDeclaredCapabilities(): void
    {
        foreach ($this->router->routes() as $route) {
            foreach (AuthGuard::capabilitiesFor($route) as $capability) {
                if ($this->access->hasPermission($capability)) {
                    continue;
                }

                throw AuthException::undeclaredCapability(
                    $capability,
                    \sprintf('route %s %s', $route->method(), $route->path()),
                );
            }
        }
    }

    /**
     * Publish every enabled module that has an assets/ directory.
     *
     * A module does not declare this and cannot opt out of it, which is a
     * deliberate asymmetry with everything else in module.php. Publishing is
     * not a decision a module gets to make differently from its neighbours: the
     * URL space is /assets/module/<name>/ for every module, so a declaration
     * could only ever say "yes" or be wrong. Having the directory is the "yes".
     * A disabled module publishes nothing -- its files stay unreachable.
     *
     * Whether the directory exists was answered by discovery, so this asks the
     * filesystem nothing. Idempotent, because the asset path runs it before a
     * full boot might.
     */
    private function publishAssets(): void
    {
        if ($this->assetsPublished) {
            return;
        }

        $this->assetsPublished = true;

        foreach ($this->registry->definitions() as $definition) {
            if ($definition->hasAssets) {
                $this->assets->register(new AssetSource(
                    AssetKind::Module,
                    $definition->id,
                    $definition->file(ModuleDefinition::ASSETS),
                ));
            }
        }
    }

    /**
     * Register every module that has a Templates/ directory.
     *
     * Same bargain as assets, and for the same reason: having the directory is
     * the declaration. The namespace is the module's id, so modules/Billing's
     * Templates/invoice.twig is "@Billing/invoice", and a theme replaces it with
     * templates/Billing/invoice.twig.
     */
    private function publishTemplates(): void
    {
        foreach ($this->registry->definitions() as $definition) {
            if ($definition->hasTemplates) {
                $this->templates->add($definition->id, $definition->file(ModuleDefinition::TEMPLATES), TemplateSource::MODULE);
            }
        }
    }

    /**
     * Register every module that has a lang/ directory.
     *
     * The same bargain again: the directory is the declaration, and the name
     * is the module's id, so 'Billing.invoice_created' is found in
     * modules/Billing/lang/ exactly as '@Billing/invoice' is found in its
     * Templates/. A module that is removed or disabled is not here, and its
     * keys stop resolving without anything else being edited.
     */
    private function publishTranslations(): void
    {
        foreach ($this->registry->definitions() as $definition) {
            if ($definition->hasLang) {
                $this->translations->add($definition->id, $definition->file(ModuleDefinition::LANG));
            }
        }
    }

    /**
     * Make sure the container hands out the same engines this manager uses.
     *
     * An onBoot callback declares its dependencies and the container injects
     * them. If the container did not already know these instances it would
     * autowire brand-new ones, and a module's listeners would quietly land on
     * engines nothing ever fires. That failure is silent and extremely
     * confusing, so the guarantee is made structural here rather than left to
     * whoever assembled the container.
     */
    private function shareCollaborators(): void
    {
        foreach ([
            Config::class => $this->config,
            Router::class => $this->router,
            HookEngine::class => $this->hooks,
            FilterEngine::class => $this->filters,
            // So an onBoot callback can ask whether an optional dependency is
            // enabled, by injection rather than by reaching for a manager.
            ModuleRegistry::class => $this->registry,
        ] as $id => $instance) {
            if (!$this->container->resolved($id)) {
                $this->container->instance($id, $instance);
            }
        }
    }

    // ---- boot -------------------------------------------------------------

    public function boot(): void
    {
        $this->stage = ModuleStage::Booting;

        foreach ($this->registry->contexts() as $context) {
            $context->enterStage(ModuleStage::Booting);

            $this->timed('boot', $context->id(), function () use ($context): void {
                foreach ($context->declaredBootCallbacks() as $callback) {
                    $this->container->call($callback);
                }
            });

            $this->hooks->do('module.booted', $context->definition);
        }

        foreach ($this->registry->contexts() as $context) {
            $context->enterStage(ModuleStage::Ready);
        }
    }

    // ---- discovery cache --------------------------------------------------

    /**
     * Use the cache if cache:warm built one -- unless this is a debug process.
     *
     * The file existing is the switch, the same way it is for the configuration
     * cache: there is no setting to forget in either direction. Debug is the
     * exception, and it is the specification's own line between the two modes
     * -- "uncached module discovery" in development. A developer who warmed the
     * cache once to try it and then added a module would otherwise spend an
     * afternoon finding out why the module does not exist.
     *
     * A cache built under other roots is ignored as well; see
     * ModuleRegistry::readCache().
     */
    private function readDiscoveryCache(ModuleDiscovery $discovery): bool
    {
        if ((bool) $this->config->get('app.debug', false)) {
            return false;
        }

        return $this->registry->readCache(ModuleRegistry::cacheFile($this->basePath), $discovery->roots());
    }
}
